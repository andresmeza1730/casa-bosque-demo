<?php
// Módulo: Check-out — Liquidación total en COP
$pageTitle = 'Check-out';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/noches.php';
require_once __DIR__ . '/../../includes/cifrado.php';
require_once __DIR__ . '/../../includes/dian.php';
require_once __DIR__ . '/../../includes/disponibilidad.php';   // bloquearHabitacion()
requireLogin();

$pdo = getDB();
$msg = '';
$msgType = '';
$factura = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'checkout') {
    if (!validateCsrf()) {
        $msg = 'Token de seguridad inválido. Intente de nuevo.';
        $msgType = 'danger';
    } else {
        $estanciaId = (int)$_POST['estancia_id'];
        $cerrado = false;   // ¿se llegó a cerrar la estancia de verdad?

        // La transacción abre ANTES de leer, y la estancia se bloquea de
        // entrada. Antes la lectura y todo el cálculo quedaban fuera: dos
        // recepcionistas pulsando «Check-out» sobre la misma estancia leían los
        // dos `estado='activa'` y el MISMO pagado previo, así que calculaban el
        // mismo saldo y lo cobraban DOS VECES —dos filas en `pagos`, el arqueo
        // cuadrando de más, y el folio dejando de cuadrar con los pagos—.
        $pdo->beginTransaction();
        try {
        // El bloqueo va estrecho, solo sobre la fila de la estancia: la
        // consulta de abajo junta tres tablas, y un `for update` sobre ella
        // bloquearía también la habitación y el huésped.
        $pdo->prepare("select id from estancias where id = ? for update")->execute([$estanciaId]);

        // Consulta en minúsculas y join implícito para verificar la estancia activa
        $stmtE = $pdo->prepare("select e.id, e.habitacion_id, e.huesped_id, e.fecha_checkin, e.tarifa_noche, e.num_personas, e.total_consumos, e.iva_exento, h.numero, hu.nombres, hu.apellidos, hu.documento_num
                                from estancias e, habitaciones h, huespedes hu
                                where e.id = ?
                                  and e.habitacion_id = h.id
                                  and e.huesped_id = hu.id
                                  and e.estado = 'activa'");
        $stmtE->execute([$estanciaId]);
        $estancia = $stmtE->fetch();

        if (!$estancia) {
            $msg = 'Estancia no encontrada o ya cerrada.';
            $msgType = 'danger';
        } else {
            $checkin  = new DateTime($estancia['fecha_checkin']);
            $checkout = new DateTime();
            // Por FECHA, no por periodos de 24 horas: ver includes/noches.php.
            // Este número multiplica la tarifa, así que el fallo se cobraba.
            $noches   = nochesEstancia($checkin, $checkout);

            $subtotalNoches = $noches * $estancia['tarifa_noche'];
            $totalConsumos  = $estancia['total_consumos'];
            $totalPagar     = $subtotalNoches + $totalConsumos;

            // Lo ya abonado antes del cierre (pagos registrados durante la estadía)
            $stmtPag = $pdo->prepare("select coalesce(sum(monto),0) from pagos where estancia_id = ?");
            $stmtPag->execute([$estanciaId]);
            $pagadoPrev = (float)$stmtPag->fetchColumn();

            // Saldo que falta por cobrar al momento del check-out
            $saldo = $totalPagar - $pagadoPrev;

            // Método con el que se cobra el saldo pendiente (si lo hay)
            $metodoSaldo = $_POST['metodo_saldo'] ?? 'efectivo';
            if (!in_array($metodoSaldo, ['efectivo', 'tarjeta', 'transferencia', 'nequi', 'otro'], true)) {
                $metodoSaldo = 'efectivo';
            }
            // ¿El huésped se va DEBIENDO el saldo? (no se cobra ahora)
            $dejarDebiendo = isset($_POST['dejar_debiendo']) && $_POST['dejar_debiendo'] === '1';
            $cobrarSaldo   = $saldo > 0 && !$dejarDebiendo;

            // Snapshot de lo pagado tras cerrar (si se cobra el saldo, queda en $0;
            // si queda debiendo, el abonado se queda en lo previo y la deuda persiste).
            $totalAbonado = $cobrarSaldo ? ($pagadoPrev + $saldo) : $pagadoPrev;

                // El folio se cierra PRIMERO, exigiendo que la estancia siga
                // activa. Si otro check-out se adelantó, esto afecta 0 filas y
                // no se cobra nada: es el compare-and-swap que faltaba, y la
                // segunda defensa por si algún día se escribe aquí sin pedir el
                // bloqueo de arriba.
                $updE = $pdo->prepare("update estancias
                               set fecha_checkout = now(), subtotal_noches = ?, total_consumos = ?, total_pagar = ?, total_abonado = ?, estado = 'cerrada', usuario_checkout = ?
                               where id = ? and estado = 'activa'");
                $updE->execute([$subtotalNoches, $totalConsumos, $totalPagar, $totalAbonado, getCurrentUserId(), $estanciaId]);

                if ($updE->rowCount() !== 1) {
                    $pdo->rollBack();
                    $msg = 'Esa estancia ya fue cerrada por otra persona.';
                    $msgType = 'danger';
                } else {
                    // Solo si se cobra el saldo se registra el pago final con su método
                    if ($cobrarSaldo) {
                        $pdo->prepare("insert into pagos (estancia_id, monto, metodo, nota, usuario_id) values (?, ?, ?, 'Saldo cobrado en el check-out', ?)")
                            ->execute([$estanciaId, $saldo, $metodoSaldo, getCurrentUserId()]);
                    }

                    // Cambiar el estado de la habitación para habilitar su mantenimiento/limpieza
                    $pdo->prepare("update habitaciones
                                   set estado = 'limpieza'
                                   where id = ?")->execute([$estancia['habitacion_id']]);

                    $pdo->commit();
                    $cerrado = true;

                    registrarAuditoria($pdo, 'estancias', $estanciaId, 'checkout', "Checkout: $noches noche(s), Total: \$$totalPagar; abonado \$$totalAbonado" . ($cobrarSaldo ? " (saldo \$" . (int)$saldo . " por $metodoSaldo)" : ($dejarDebiendo && $saldo > 0 ? " (QUEDÓ DEBIENDO \$" . (int)$saldo . ")" : "")));

                    $msg = "Check-out exitoso. Total: $" . number_format($totalPagar, 0, ',', '.') . ' COP'
                         . ($cobrarSaldo
                              ? ' · saldo cobrado $' . number_format($saldo, 0, ',', '.') . " ($metodoSaldo)"
                              : (($saldo > 0 && $dejarDebiendo) ? ' · QUEDÓ DEBIENDO $' . number_format($saldo, 0, ',', '.') : ' · ya estaba pagado'));
                    $msgType = 'success';
                }
            }

            // Ninguna rama de rechazo escribió: se suelta el bloqueo.
            if ($pdo->inTransaction()) $pdo->rollBack();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Check-out: ' . $e->getMessage());
            $msg = 'Error en check-out.';
            $msgType = 'danger';
        }

        // ══ Lo que viene DESPUÉS de cerrar y cobrar ══
        // Factura en pantalla y DIAN. Va en su propio try: antes todo
        // esto estaba dentro del try de la transacción, y su `catch` hacía
        // `$pdo->rollBack()` SIN comprobar `inTransaction()`. Como el commit ya
        // había ocurrido, un fallo de la factura o de lo que iba después lanzaba
        // «There is no active transaction» encima de un check-out ya cobrado.
        // El cierre está hecho y confirmado: nada de aquí puede deshacerlo.
        if ($cerrado) {
            try {
                // La factura se arma PRIMERO, antes de la DIAN: es solo
                // construir el arreglo, y así un fallo de la DIAN no deja al
                // recepcionista sin el comprobante en pantalla.
                // Estructura de la factura para renderizar en pantalla y pasar el ID al PDF
                $factura = [
                    'id'              => $estanciaId,
                    'estancia_id'     => $estanciaId,
                    'huesped'         => $estancia['nombres'] . ' ' . $estancia['apellidos'],
                    'documento'       => descifrar($estancia['documento_num']),
                    'habitacion'      => $estancia['numero'],
                    'checkin'         => $checkin->format('d/m/Y H:i'),
                    'checkout'        => $checkout->format('d/m/Y H:i'),
                    'noches'          => $noches,
                    'tarifa'          => $estancia['tarifa_noche'],
                    'subtotal_noches' => $subtotalNoches,
                    'total_consumos'  => $totalConsumos,
                    'total_pagar'     => $totalPagar,
                    'pagado_prev'     => $pagadoPrev,
                    'saldo_cobrado'   => $cobrarSaldo ? $saldo : 0,
                    'saldo_pendiente' => ($saldo > 0 && $dejarDebiendo) ? $saldo : 0,
                    'metodo_saldo'    => $metodoSaldo,
                    'total_abonado'   => $totalAbonado,
                ];

                // Facturación electrónica DIAN (solo si está habilitada; si no,
                // no hace nada y se conserva el comprobante interno de siempre).
                if (dianHabilitado()) {
                    dianEmitirFactura($pdo, (int) $estanciaId);
                }

                // Hasta 3.12.0 aquí se mandaba la factura al correo del huésped.
                // El cliente pidió quitarlo: el comprobante se entrega impreso
                // (ticket de 80 mm y factura interna), no por correo.
            } catch (Throwable $e) {
                // El check-out YA está cerrado y cobrado. Aquí no se revierte
                // nada ni se cambia el mensaje de éxito: solo queda el rastro
                // para poder mirarlo después.
                error_log('Check-out: falló algo posterior al cierre (factura o DIAN): ' . $e->getMessage());
            }
        }
    }
}

// Listar las habitaciones ocupadas actualmente usando consultas implícitas clásicas
$estancias = $pdo->query("select e.id, e.fecha_checkin, e.tarifa_noche, e.total_consumos, h.numero, hu.nombres, hu.apellidos,
                                 (select coalesce(sum(p.monto),0) from pagos p where p.estancia_id = e.id) as abonado
                          from estancias e, habitaciones h, huespedes hu
                          where e.habitacion_id = h.id
                            and e.huesped_id = hu.id
                            and e.estado = 'activa'
                          order by cast(h.numero as unsigned), h.numero")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<style>
/* ============================================================
   Check-out — Rediseño limpio (Casa Bosque)
   ============================================================ */
.co-wrap { padding-bottom: 90px; }

.co-msg { display: flex; align-items: center; gap: 11px; padding: 13px 15px; border-radius: 14px; margin-bottom: 18px; font-size: .9rem; font-weight: 500; }
.co-msg.success { background: #e6f7f2; color: #047857; border: 1px solid rgba(31,122,77,.25); }
.co-msg.danger  { background: #fdeded; color: #b91c1c; border: 1px solid rgba(239,68,68,.25); }

.co-sec { display: flex; align-items: center; justify-content: space-between; margin: 4px 2px 12px; }
.co-sec h3 { font-size: 1rem; font-weight: 700; color: var(--cb-forest, #3E2412); display: flex; align-items: center; gap: 8px; }
.co-badge { font-size: .72rem; font-weight: 700; padding: 3px 10px; border-radius: 99px; background: var(--cb-primary-light, #F5EBDC); color: var(--cb-primary, #6B4420); }

/* ── Factura / liquidación ── */
.co-invoice { background: #fff; border: 1px solid rgba(31,122,77,.3); border-radius: 20px; box-shadow: 0 10px 28px rgba(31,122,77,.12); margin-bottom: 22px; overflow: hidden; }
.co-inv-head { background: linear-gradient(150deg,#047857,#1F7A4D); color: #fff; padding: 16px; }
.co-inv-head h3 { font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: 9px; margin: 0; color: #fff; }
.co-inv-head p { font-size: .78rem; opacity: .9; margin: 4px 0 0; }
.co-inv-body { padding: 18px 16px; }
.co-inv-guest { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.co-inv-guest .co-av { flex: none; width: 46px; height: 46px; border-radius: 13px; background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; }
.co-inv-guest .co-gname { font-size: .95rem; font-weight: 700; color: var(--cb-forest,#3E2412); }
.co-inv-guest .co-gmeta { font-size: .74rem; color: var(--cb-muted,#9A8778); margin-top: 1px; }
.co-inv-guest .co-room { flex: none; margin-left: auto; font-size: .82rem; font-weight: 800; color: var(--cb-primary,#6B4420); background: var(--cb-primary-light,#F5EBDC); padding: 6px 12px; border-radius: 10px; }
.co-line { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; font-size: .86rem; color: var(--cb-text,#2A1709); }
.co-line .lbl { color: var(--cb-muted,#9A8778); display: flex; align-items: center; gap: 8px; }
.co-line.sub { border-top: 1px solid var(--gray-100,#F1EADF); }
.co-total { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; padding-top: 14px; border-top: 2px solid var(--gray-100,#F1EADF); }
.co-total .lbl { font-size: .92rem; font-weight: 700; color: var(--cb-forest,#3E2412); }
.co-total .amt { font-size: 1.5rem; font-weight: 800; color: #047857; }
.co-actions { display: flex; gap: 10px; margin-top: 18px; }
.co-act { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 12px; border-radius: 13px; font-size: .85rem; font-weight: 700; text-decoration: none; }
.co-act.pdf { background: #eff6ff; color: #2563eb; border: 1px solid rgba(59,130,246,.25); }
.co-act.ticket { background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color: #fff; }

/* ── Tarjetas de salida ── */
.co-card { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; padding: 15px; margin-bottom: 12px; box-shadow: 0 6px 18px rgba(42,23,9,.05); }
.co-card-top { display: flex; align-items: center; gap: 12px; }
.co-room-box { flex: none; width: 46px; height: 46px; border-radius: 13px; background: var(--cb-primary,#6B4420); color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1; }
.co-room-box b { font-size: 1rem; font-weight: 800; }
.co-room-box small { font-size: .5rem; font-weight: 700; opacity: .8; margin-top: 2px; letter-spacing: .5px; }
.co-info { flex: 1; min-width: 0; }
.co-name { font-size: .92rem; font-weight: 700; color: var(--cb-forest,#3E2412); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.co-sub { font-size: .74rem; color: var(--cb-muted,#9A8778); margin-top: 1px; }
.co-detail { display: flex; gap: 16px; margin: 13px 0; padding: 12px 0; border-top: 1px solid var(--gray-100,#F1EADF); border-bottom: 1px solid var(--gray-100,#F1EADF); }
.co-detail .d { flex: 1; }
.co-detail .d .dk { font-size: .64rem; color: var(--cb-muted,#9A8778); text-transform: uppercase; letter-spacing: .3px; }
.co-detail .d .dv { font-size: .9rem; font-weight: 700; color: var(--cb-forest,#3E2412); margin-top: 2px; }
.co-detail .d .dv.money { color: #047857; }
.co-foot { display: flex; align-items: center; justify-content: space-between; }
.co-est { font-size: .76rem; color: var(--cb-muted,#9A8778); }
.co-est b { font-size: 1.05rem; color: var(--cb-forest,#3E2412); font-weight: 800; display: block; }
.co-btn-out { display: inline-flex; align-items: center; gap: 7px; border: none; cursor: pointer; font-family: inherit; font-size: .84rem; font-weight: 700; color: #fff; background: linear-gradient(150deg,#f59e0b,#d97706); padding: 11px 16px; border-radius: 12px; box-shadow: 0 6px 14px rgba(217,119,6,.28); }
.co-btn-out:active { transform: scale(.96); }

.co-empty { text-align: center; padding: 30px 12px; color: var(--cb-muted,#9A8778); background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; }
.co-empty i { font-size: 2.2rem; color: var(--cb-primary,#6B4420); opacity: .5; margin-bottom: 10px; display: block; }
.co-empty p { font-size: .9rem; margin: 0; }
</style>

<div class="co-wrap">

<?php if ($factura): ?>
    <div class="co-invoice" id="factura-checkout">
        <div class="co-inv-head">
            <h3><i class="fas fa-file-invoice-dollar"></i> Liquidación de cuenta</h3>
            <p>Folio #<?= $factura['estancia_id'] ?> · check-out realizado</p>
        </div>
        <div class="co-inv-body">
            <div class="co-inv-guest">
                <div class="co-av"><?= strtoupper(mb_substr($factura['huesped'],0,1,'UTF-8')) ?></div>
                <div>
                    <div class="co-gname"><?= htmlspecialchars($factura['huesped']) ?></div>
                    <div class="co-gmeta">CC / Pasaporte: <?= htmlspecialchars($factura['documento']) ?></div>
                </div>
                <div class="co-room">Hab. <?= htmlspecialchars($factura['habitacion']) ?></div>
            </div>

            <div class="co-line"><span class="lbl"><i class="far fa-calendar-check"></i> Check-in</span><span><?= $factura['checkin'] ?></span></div>
            <div class="co-line"><span class="lbl"><i class="far fa-calendar-times"></i> Check-out</span><span><?= $factura['checkout'] ?></span></div>
            <div class="co-line"><span class="lbl"><i class="fas fa-moon"></i> Noches</span><span style="font-weight:700;"><?= $factura['noches'] ?></span></div>
            <div class="co-line"><span class="lbl"><i class="fas fa-tag"></i> Tarifa por noche</span><span>$<?= number_format($factura['tarifa'], 0, ',', '.') ?></span></div>
            <div class="co-line sub"><span class="lbl">Subtotal hospedaje</span><span style="font-weight:600;">$<?= number_format($factura['subtotal_noches'], 0, ',', '.') ?></span></div>
            <div class="co-line"><span class="lbl">Consumos / bar</span><span style="font-weight:600;">$<?= number_format($factura['total_consumos'], 0, ',', '.') ?></span></div>

            <div class="co-total">
                <span class="lbl">Total a pagar</span>
                <span class="amt">$<?= number_format($factura['total_pagar'], 0, ',', '.') ?></span>
            </div>

            <?php if (($factura['pagado_prev'] ?? 0) > 0 || ($factura['saldo_cobrado'] ?? 0) > 0): ?>
            <div style="margin-top:12px; padding-top:12px; border-top:1px dashed #EDE3D2;">
                <?php if (($factura['pagado_prev'] ?? 0) > 0): ?>
                <div class="co-line"><span class="lbl"><i class="fas fa-hand-holding-dollar"></i> Abonos previos</span><span style="font-weight:600;">$<?= number_format($factura['pagado_prev'], 0, ',', '.') ?></span></div>
                <?php endif; ?>
                <?php if (($factura['saldo_cobrado'] ?? 0) > 0): ?>
                <div class="co-line"><span class="lbl"><i class="fas fa-cash-register"></i> Cobrado ahora (<?= htmlspecialchars($factura['metodo_saldo']) ?>)</span><span style="font-weight:600;">$<?= number_format($factura['saldo_cobrado'], 0, ',', '.') ?></span></div>
                <?php endif; ?>
                <div class="co-line" style="color:#1F7A4D;"><span class="lbl" style="color:#1F7A4D;"><i class="fas fa-circle-check"></i> Pagado</span><span style="font-weight:800;">$<?= number_format($factura['total_abonado'], 0, ',', '.') ?></span></div>
            </div>
            <?php endif; ?>

            <?php if (($factura['saldo_pendiente'] ?? 0) > 0): ?>
            <div class="co-line" style="margin-top:12px; padding-top:12px; border-top:1px dashed #f4caca; color:#c0392b;">
                <span class="lbl" style="color:#c0392b;"><i class="fas fa-triangle-exclamation"></i> Saldo pendiente (queda debiendo)</span>
                <span style="font-weight:800;">$<?= number_format($factura['saldo_pendiente'], 0, ',', '.') ?></span>
            </div>
            <?php endif; ?>

            <div class="co-actions">
                <a href="<?= BASE_URL ?>/modules/facturacion/index.php?estancia=<?= (int)$factura['id'] ?>" class="co-act pdf"><i class="fas fa-file-invoice-dollar"></i> Emitir factura si la solicita</a>
                <a href="ticket.php?id=<?= $factura['id'] ?>&amp;volver=checkout" class="co-act ticket"><i class="fas fa-receipt"></i> Ticket 80mm</a>
            </div>
        </div>
    </div>
    <!-- Auto-impresión del ticket al cerrar el check-out -->
    <iframe src="ticket.php?id=<?= $factura['id'] ?>&auto=1" title="Impresión de ticket" style="position:fixed; right:0; bottom:0; width:0; height:0; border:0; opacity:0; pointer-events:none;" aria-hidden="true"></iframe>
<?php endif; ?>

<?php if ($msg): ?>
    <div class="co-msg <?= $msgType ?>">
        <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
        <span><?= htmlspecialchars($msg) ?></span>
    </div>
<?php endif; ?>

<!-- Salidas / ocupación actual -->
<div class="co-sec">
    <h3><i class="fas fa-door-closed" style="color:#f59e0b;"></i> Huéspedes en el hotel</h3>
    <?php if (!empty($estancias)): ?><span class="co-badge"><?= count($estancias) ?> ocupada(s)</span><?php endif; ?>
</div>

<?php if (empty($estancias)): ?>
    <div class="co-empty">
        <i class="fas fa-circle-check"></i>
        <p>No hay estancias activas pendientes de salida.</p>
    </div>
<?php else: ?>
    <?php foreach($estancias as $e):
        $nochesEst = nochesEstancia($e['fecha_checkin']);
        $estimado = ($nochesEst * $e['tarifa_noche']) + $e['total_consumos'];
        $abonado  = (float)$e['abonado'];
        $saldoEst = $estimado - $abonado;
    ?>
    <div class="co-card">
        <div class="co-card-top">
            <div class="co-room-box"><b><?= htmlspecialchars($e['numero']) ?></b><small>HAB</small></div>
            <div class="co-info">
                <div class="co-name"><?= htmlspecialchars($e['nombres'] . ' ' . $e['apellidos']) ?></div>
                <div class="co-sub">Ingreso: <?= date('d/m/Y H:i', strtotime($e['fecha_checkin'])) ?></div>
            </div>
        </div>
        <div class="co-detail">
            <div class="d"><div class="dk">Noches</div><div class="dv"><?= $nochesEst ?></div></div>
            <div class="d"><div class="dk">Tarifa</div><div class="dv">$<?= number_format($e['tarifa_noche'], 0, ',', '.') ?></div></div>
            <div class="d"><div class="dk">Consumos</div><div class="dv money">$<?= number_format($e['total_consumos'], 0, ',', '.') ?></div></div>
        </div>
        <div class="co-foot">
            <div class="co-est">
                <?php if ($abonado > 0): ?>
                    Saldo por cobrar <b style="color:<?= $saldoEst > 0 ? '#c0392b' : '#1F7A4D' ?>;">$<?= number_format(max(0, $saldoEst), 0, ',', '.') ?></b>
                    <span style="display:block; font-size:.68rem; color:#A2907C; font-weight:400;">Total $<?= number_format($estimado, 0, ',', '.') ?> · abonado $<?= number_format($abonado, 0, ',', '.') ?></span>
                <?php else: ?>
                    Total estimado <b>$<?= number_format($estimado, 0, ',', '.') ?></b>
                <?php endif; ?>
            </div>
            <form method="POST" class="form-checkout-action" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="checkout">
                <input type="hidden" name="estancia_id" value="<?= $e['id'] ?>">
                <input type="hidden" name="metodo_saldo" value="efectivo" class="input-metodo-saldo">
                <input type="hidden" name="dejar_debiendo" value="0" class="input-dejar-debiendo">
                <button type="button" class="co-btn-out btn-trigger-checkout" data-hab="<?= htmlspecialchars($e['numero']) ?>" data-saldo="<?= (int)round(max(0, $saldoEst)) ?>" data-estancia="<?= (int)$e['id'] ?>">
                    <i class="fas fa-door-closed"></i> Check-out
                </button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

</div><!-- /.co-wrap -->

<!-- Modal de confirmación (se conserva la lógica original) -->
<div id="custom-confirm-modal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(15, 23, 42, 0.3); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); align-items: center; justify-content: center; transition: all 0.2s ease;">
    <div class="card" style="width: calc(100% - 40px); max-width: 420px; border-radius: 18px; border-top: 4px solid var(--warning, #f59e0b); box-shadow: 0 20px 40px rgba(0,0,0,.2); background: #fff; transform: scale(0.95); transition: transform 0.2s ease-out; overflow: hidden;">
        <div class="card-body" style="padding: 22px;">
            <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
                <div style="background-color: #fef9c3; padding: 12px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i class="fas fa-triangle-exclamation" style="color: #ca8a04; font-size: 1.3rem;"></i>
                </div>
                <div>
                    <h4 style="margin: 0 0 6px 0; color: #0f172a; font-weight: 700; font-size: 1.1rem;">Confirmar check-out</h4>
                    <p id="confirm-modal-text" style="margin: 0; color: #475569; font-size: .9rem; line-height: 1.5;"></p>
                </div>
            </div>
            <div id="confirm-modo-wrap" style="display:none; margin:-4px 0 16px;">
                <div style="font-size:.82rem; font-weight:700; color:#0f172a; margin-bottom:9px;">¿El huésped paga el saldo ahora?</div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                    <label style="display:flex; align-items:center; justify-content:center; gap:7px; padding:11px; border:1px solid #e2e8f0; border-radius:11px; font-size:.85rem; font-weight:700; color:#334155; cursor:pointer;"><input type="radio" name="cm_modo" value="cobrar" checked style="accent-color:#6B4420;"> <i class="fas fa-circle-check"></i> Paga ahora</label>
                    <label style="display:flex; align-items:center; justify-content:center; gap:7px; padding:11px; border:1px solid #f4caca; border-radius:11px; font-size:.85rem; font-weight:700; color:#b91c1c; cursor:pointer;"><input type="radio" name="cm_modo" value="debe" style="accent-color:#c0392b;"> <i class="fas fa-hand-holding-dollar"></i> Queda debiendo</label>
                </div>
            </div>
            <div id="confirm-metodo-wrap" style="display:none; margin:-4px 0 18px;">
                <div style="font-size:.82rem; font-weight:700; color:#0f172a; margin-bottom:9px;">¿Con qué se cobra el saldo?</div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                    <label style="display:flex; align-items:center; justify-content:center; gap:7px; padding:11px; border:1px solid #e2e8f0; border-radius:11px; font-size:.85rem; font-weight:600; color:#334155; cursor:pointer;"><input type="radio" name="cm_metodo" value="efectivo" checked style="accent-color:#6B4420;"> <i class="fas fa-money-bill-wave"></i> Efectivo</label>
                    <label style="display:flex; align-items:center; justify-content:center; gap:7px; padding:11px; border:1px solid #e2e8f0; border-radius:11px; font-size:.85rem; font-weight:600; color:#334155; cursor:pointer;"><input type="radio" name="cm_metodo" value="tarjeta" style="accent-color:#6B4420;"> <i class="fas fa-credit-card"></i> Tarjeta</label>
                    <label style="display:flex; align-items:center; justify-content:center; gap:7px; padding:11px; border:1px solid #e2e8f0; border-radius:11px; font-size:.85rem; font-weight:600; color:#334155; cursor:pointer;"><input type="radio" name="cm_metodo" value="transferencia" style="accent-color:#6B4420;"> <i class="fas fa-building-columns"></i> Transfer.</label>
                    <label style="display:flex; align-items:center; justify-content:center; gap:7px; padding:11px; border:1px solid #e2e8f0; border-radius:11px; font-size:.85rem; font-weight:600; color:#334155; cursor:pointer;"><input type="radio" name="cm_metodo" value="nequi" style="accent-color:#6B4420;"> <i class="fas fa-mobile-screen-button"></i> Nequi</label>
                    <label style="display:flex; align-items:center; justify-content:center; gap:7px; padding:11px; border:1px solid #e2e8f0; border-radius:11px; font-size:.85rem; font-weight:600; color:#334155; cursor:pointer;"><input type="radio" name="cm_metodo" value="otro" style="accent-color:#6B4420;"> <i class="fas fa-wallet"></i> Otro</label>
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" id="btn-confirm-cancel" class="btn" style="background-color: #f1f5f9; color: #475569; border-radius: 10px; font-weight: 600; border: 1px solid #e2e8f0; cursor: pointer; padding: 10px 18px;">Cancelar</button>
                <button type="button" id="btn-confirm-accept" class="btn btn-warning" style="border-radius: 10px; font-weight: 600; padding: 10px 18px;">Confirmar salida</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('custom-confirm-modal');
    const modalCard = modal.querySelector('.card');
    const modalText = document.getElementById('confirm-modal-text');
    const btnAccept = document.getElementById('btn-confirm-accept');
    const btnCancel = document.getElementById('btn-confirm-cancel');
    const metodoWrap = document.getElementById('confirm-metodo-wrap');
    const modoWrap = document.getElementById('confirm-modo-wrap');
    let activeForm = null;

    const fmtCOP = n => '$' + Number(n).toLocaleString('es-CO');

    document.querySelectorAll('.btn-trigger-checkout').forEach(button => {
        button.addEventListener('click', function() {
            const numeroHab = this.getAttribute('data-hab');
            const saldo = parseInt(this.getAttribute('data-saldo'), 10) || 0;
            activeForm = this.closest('form');

            if (saldo > 0) {
                modalText.innerHTML = `Vas a cerrar la <strong>habitación ${numeroHab}</strong>. Queda un saldo de <strong style="color:#c0392b;">${fmtCOP(saldo)}</strong>.<br><span style="font-size:.82rem; color:#64748b; display:block; margin-top:6px;">Indica si el huésped paga ahora o queda debiendo. Al confirmar, la habitación pasa a limpieza.</span>`;
                modoWrap.style.display = 'block';
                var rc = document.querySelector('input[name="cm_modo"][value="cobrar"]'); if (rc) rc.checked = true;
                metodoWrap.style.display = 'block';
            } else {
                modalText.innerHTML = `¿Está seguro de procesar el check-out de la <strong>habitación ${numeroHab}</strong>?<br><span style="font-size:.82rem; color:#64748b; display:block; margin-top:6px;">La cuenta ya está pagada. El folio se cierra y la habitación pasa a limpieza.</span>`;
                modoWrap.style.display = 'none';
                metodoWrap.style.display = 'none';
            }

            modal.style.display = 'flex';
            setTimeout(() => { modalCard.style.transform = 'scale(1)'; }, 10);
        });
    });

    // Al elegir "queda debiendo" se oculta el método de pago; "paga ahora" lo muestra.
    document.querySelectorAll('input[name="cm_modo"]').forEach(function(r){
        r.addEventListener('change', function(){
            metodoWrap.style.display = (r.value === 'cobrar') ? 'block' : 'none';
        });
    });

    function closeModal() {
        modalCard.style.transform = 'scale(0.95)';
        setTimeout(() => { modal.style.display = 'none'; activeForm = null; }, 150);
    }

    btnAccept.addEventListener('click', function() {
        if (!activeForm) return;
        if (modoWrap.style.display !== 'none') {   // hay saldo por definir
            const modo = document.querySelector('input[name="cm_modo"]:checked');
            const esDebe = modo && modo.value === 'debe';
            const campoDebe = activeForm.querySelector('.input-dejar-debiendo');
            if (campoDebe) campoDebe.value = esDebe ? '1' : '0';
            if (!esDebe) {
                const sel = document.querySelector('input[name="cm_metodo"]:checked');
                const campo = activeForm.querySelector('.input-metodo-saldo');
                if (sel && campo) campo.value = sel.value;
            }
        }
        btnAccept.disabled = true;
        activeForm.submit();
    });
    btnCancel.addEventListener('click', closeModal);
    modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });

    // Si venimos del tablero con ?estancia=ID, ir directo a esa habitación y abrir su confirmación.
    var targetEst = new URLSearchParams(location.search).get('estancia');
    if (targetEst) {
        var btn = document.querySelector('.btn-trigger-checkout[data-estancia="' + targetEst + '"]');
        if (btn) {
            var card = btn.closest('.co-card');
            if (card) {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                card.style.boxShadow = '0 0 0 2px #f59e0b';
            }
            setTimeout(function() { btn.click(); }, 400);
        }
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
