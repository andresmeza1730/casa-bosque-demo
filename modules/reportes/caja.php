<?php
// ============================================================
// modules/reportes/caja.php
// Arqueo de caja diario — Casa Bosque
// ============================================================
// Dos cosas distintas, en este orden:
//
//   1. DINERO RECIBIDO ese día — pagos de estancias + ventas de mostrador,
//      desglosado por método. Es lo que se cuenta en el cajón.
//   2. CUENTAS CERRADAS ese día — el valor facturado por los check-outs.
//
// Antes esta pantalla mostraba SOLO lo segundo, con el título «Total
// recaudado». No era recaudado: era facturado, y con eso no se puede cuadrar la
// caja. Un abono cobrado al reservar aparecía el día del check-out; un pago de
// deuda posterior no aparecía en ningún día; una cuenta cerrada a deber se
// contaba completa; y las ventas de mostrador no estaban en ninguna cifra.
//
// Los números los calcula includes/caja.php, que es también de donde los lee el
// PDF: antes la consulta estaba copiada en los dos archivos y podían discrepar.
//
// Solo administrador.
// ------------------------------------------------------------

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/caja.php';
requireAdmin();   // el arqueo de caja es exclusivo del administrador

$pdo = getDB();

// Fecha seleccionada (por defecto hoy), validada como fecha real.
$fecha = cajaFechaValida($_GET['fecha'] ?? null);

$recibido  = cajaRecibidoDelDia($pdo, $fecha);
$facturado = cajaFacturadoDelDia($pdo, $fecha);
$descuadre = cajaDescuadre($recibido, $facturado);

$currentDir = 'reportes';
$pageTitle = 'Arqueo de Caja';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
/* ============================================================
   Arqueo de Caja — Rediseño móvil (Casa Bosque)
   ============================================================ */
.cj-wrap { padding-bottom: 90px; }

/* Selector de fecha */
.cj-datebar { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); padding: 14px; margin-bottom: 16px; }
.cj-datebar .lbl { font-size: .78rem; color: var(--cb-muted,#9A8778); font-weight: 600; margin-bottom: 8px; display: flex; align-items: center; gap: 7px; }
.cj-dnav { display: flex; gap: 8px; align-items: center; }
.cj-dnav a.arrow { flex: none; width: 42px; height: 44px; border-radius: 11px; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; color: var(--cb-forest,#3E2412); display: flex; align-items: center; justify-content: center; text-decoration: none; }
.cj-dnav input { flex: 1; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 11px; padding: 12px; font-family: inherit; font-size: .9rem; text-align: center; color: var(--cb-text,#2A1709); }
.cj-dnav input:focus { outline: none; border-color: var(--cb-primary,#6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }

/* Total */
.cj-total { background: linear-gradient(150deg,#6B4420,#9C6728); border-radius: 20px; padding: 20px; color: #fff; box-shadow: 0 10px 26px rgba(107,68,32,.22); margin-bottom: 13px; position: relative; overflow: hidden; }
.cj-total::after { content:''; position:absolute; inset:0; background: radial-gradient(100% 80% at 100% 0, rgba(201,162,94,.18), transparent 60%); }
.cj-total .lbl { font-size: .8rem; opacity: .9; position: relative; }
.cj-total .amt { font-size: 2.2rem; font-weight: 800; margin: 4px 0 6px; letter-spacing: -.5px; position: relative; }
.cj-total .sub { font-size: .78rem; opacity: .85; position: relative; display: inline-flex; align-items: center; gap: 6px; }

/* Desglose por método de pago */
.cj-met { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; padding: 6px 4px; margin-bottom: 16px; box-shadow: 0 4px 12px rgba(42,23,9,.04); }
.cj-met .mrow { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-bottom: 1px solid #F6F1E8; }
.cj-met .mrow:last-child { border-bottom: 0; }
.cj-met .mic { flex: none; width: 34px; height: 34px; border-radius: 10px; background: var(--cb-primary-light,#F5EBDC); color: var(--cb-primary,#6B4420); display: flex; align-items: center; justify-content: center; font-size: .88rem; }
.cj-met .mnm { flex: 1; font-size: .87rem; font-weight: 600; color: var(--cb-forest,#3E2412); }
.cj-met .mnm small { display: block; font-size: .7rem; font-weight: 500; color: var(--cb-muted,#9A8778); margin-top: 1px; }
.cj-met .mvl { flex: none; font-size: .95rem; font-weight: 800; color: var(--cb-forest,#3E2412); }

/* Desglose facturado */
.cj-split { display: flex; gap: 11px; margin-bottom: 16px; }
.cj-split .s { flex: 1; background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 15px; padding: 15px; text-align: center; box-shadow: 0 4px 12px rgba(42,23,9,.04); }
.cj-split .s .sv { font-size: 1.3rem; font-weight: 800; }
.cj-split .s.h .sv { color: var(--cb-secondary,#9C6728); }
.cj-split .s.c .sv { color: #b45309; }
.cj-split .s .sl { font-size: .72rem; color: var(--cb-muted,#9A8778); margin-top: 4px; }

/* Tarjeta de lo facturado */
.cj-fact { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; padding: 16px; margin-bottom: 13px; box-shadow: 0 4px 12px rgba(42,23,9,.04); }
.cj-fact .lbl { font-size: .78rem; color: var(--cb-muted,#9A8778); font-weight: 600; display: flex; align-items: center; gap: 7px; }
.cj-fact .amt { font-size: 1.55rem; font-weight: 800; color: var(--cb-forest,#3E2412); margin: 5px 0 2px; letter-spacing: -.3px; }
.cj-fact .sub { font-size: .75rem; color: var(--cb-muted,#9A8778); }

/* Aviso de descuadre: informativo, no una alarma */
.cj-dif { background: #FFFBF2; border: 1px solid #F0DCB4; border-radius: 16px; padding: 14px 16px; margin-bottom: 16px; }
.cj-dif .t { font-size: .85rem; font-weight: 700; color: #8A5A12; display: flex; align-items: flex-start; gap: 9px; }
.cj-dif .t i { margin-top: 2px; }
.cj-dif ul { margin: 9px 0 0 30px; padding: 0; }
.cj-dif li { font-size: .78rem; color: #7A6A52; line-height: 1.55; }
.cj-dif .nota { font-size: .74rem; color: #9A8778; margin: 9px 0 0 30px; }

/* Secciones */
.cj-sec { display: flex; align-items: center; justify-content: space-between; margin: 4px 2px 12px; }
.cj-sec h3 { font-size: 1rem; font-weight: 700; color: var(--cb-forest,#3E2412); display: flex; align-items: center; gap: 8px; margin: 0; }
.cj-sec .cnt { font-size: .76rem; color: var(--cb-muted,#9A8778); font-weight: 600; }
.cj-note { font-size: .74rem; color: var(--cb-muted,#9A8778); margin: -6px 2px 12px; line-height: 1.5; }

.cj-row { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 15px; padding: 13px 14px; margin-bottom: 10px; display: flex; align-items: center; gap: 12px; box-shadow: 0 4px 12px rgba(42,23,9,.04); }
.cj-room { flex: none; width: 44px; height: 44px; border-radius: 12px; background: var(--cb-primary,#6B4420); color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1; }
.cj-room b { font-size: .95rem; font-weight: 800; }
.cj-room small { font-size: .5rem; font-weight: 700; opacity: .8; margin-top: 2px; }
.cj-room.venta { background: #b45309; }
.cj-room.venta i { font-size: 1rem; }
.cj-info { flex: 1; min-width: 0; }
/* El concepto puede ocupar DOS líneas. En una sola se cortaba justo donde
   importa: «Pago de deuda posterior al che…» no dice si fue una deuda o un
   abono, que es lo único que explica el descuadre de arriba. */
.cj-name { font-size: .88rem; font-weight: 700; color: var(--cb-forest,#3E2412); line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.cj-time { font-size: .72rem; color: var(--cb-muted,#9A8778); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cj-amt { flex: none; text-align: right; }
.cj-amt .tt { font-size: .95rem; font-weight: 800; color: var(--cb-forest,#3E2412); }
.cj-amt .br { font-size: .64rem; color: var(--cb-muted,#9A8778); margin-top: 1px; }
.cj-chip { display: inline-block; font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--cb-primary,#6B4420); background: var(--cb-primary-light,#F5EBDC); border-radius: 5px; padding: 2px 6px; }

.cj-empty { text-align: center; padding: 36px 16px; color: var(--cb-muted,#9A8778); background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; margin-bottom: 20px; }
.cj-empty i { font-size: 2.3rem; color: var(--cb-primary,#6B4420); opacity: .4; margin-bottom: 12px; display: block; }
.cj-empty p { font-size: .9rem; margin: 0; }

/* Botón imprimir / PDF */
.cj-print-actions { display: flex; justify-content: flex-end; margin-bottom: 12px; }
.cj-print-btn { display: inline-flex; align-items: center; gap: 8px; font-family: inherit; font-size: .84rem; font-weight: 700; color: var(--cb-primary,#6B4420); background: var(--cb-primary-light,#F5EBDC); border: none; border-radius: 11px; padding: 10px 16px; cursor: pointer; }

/* Vista de impresión (Guardar como PDF desde el navegador) */
@media print {
    .sidebar, #sidebar, .top-bar, .bottom-nav, nav, .more-overlay,
    #cbHelpBtn, #cbChat, #cbTour, .cb-tour-card,
    .cj-print-actions, .cj-datebar { display: none !important; }
    html, body { background: #fff !important; height: auto !important; overflow: visible !important; }
    .main-content { margin-left: 0 !important; min-height: 0 !important; }
    .content-area { padding: 0 !important; }
    .cj-wrap { padding: 0 !important; }
    .cj-total, .cj-room { -webkit-print-color-adjust: exact; print-color-adjust: exact; box-shadow: none !important; }
}
</style>

<?php
    $fechaAnt = date('Y-m-d', strtotime($fecha . ' -1 day'));
    $fechaSig = date('Y-m-d', strtotime($fecha . ' +1 day'));
    $esHoy = ($fecha === date('Y-m-d'));

    // Un icono por método, para que el desglose se lea de un vistazo.
    $iconoMetodo = [
        'efectivo'      => 'fa-money-bill-wave',
        'tarjeta'       => 'fa-credit-card',
        'transferencia' => 'fa-building-columns',
        'nequi'         => 'fa-mobile-screen-button',
        'otro'          => 'fa-ellipsis',
    ];
?>

<div class="cj-wrap">

<div class="cj-print-actions">
    <a class="cj-print-btn" href="caja_pdf.php?fecha=<?= htmlspecialchars($fecha) ?>"><i class="fas fa-file-pdf"></i> Descargar PDF</a>
</div>

<!-- Selector de fecha -->
<div class="cj-datebar">
    <div class="lbl"><i class="fas fa-calendar-day"></i> Día del arqueo<?= $esHoy ? ' · hoy' : '' ?></div>
    <form method="GET" id="cj-form">
        <div class="cj-dnav">
            <a href="?fecha=<?= $fechaAnt ?>" class="arrow"><i class="fas fa-chevron-left"></i></a>
            <input type="date" name="fecha" value="<?= htmlspecialchars($fecha) ?>" max="<?= date('Y-m-d') ?>" onchange="document.getElementById('cj-form').submit()">
            <a href="?fecha=<?= $fechaSig ?>" class="arrow"><i class="fas fa-chevron-right"></i></a>
        </div>
    </form>
</div>

<!-- ══════════ 1. DINERO RECIBIDO ══════════ -->
<div class="cj-total">
    <div class="lbl">Dinero recibido · <?= date('d/m/Y', strtotime($fecha)) ?></div>
    <div class="amt"><?= cajaCop($recibido['total']) ?></div>
    <div class="sub">
        <i class="fas fa-hand-holding-dollar"></i>
        <?= $recibido['num_pagos'] ?> pago(s) de estancias · <?= $recibido['num_ventas'] ?> venta(s) de mostrador
    </div>
</div>

<?php if (!empty($recibido['por_metodo'])): ?>
    <div class="cj-met">
        <?php foreach ($recibido['por_metodo'] as $met => $d): ?>
            <div class="mrow">
                <div class="mic"><i class="fas <?= $iconoMetodo[$met] ?? 'fa-ellipsis' ?>"></i></div>
                <div class="mnm">
                    <?= htmlspecialchars(cajaMetodoNombre($met)) ?>
                    <small><?= $d['movimientos'] ?> movimiento(s)</small>
                </div>
                <div class="mvl"><?= cajaCop($d['total']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="cj-note">
        <i class="fas fa-circle-info"></i> Cuente el efectivo del cajón y compárelo con la línea
        «Efectivo». Lo demás tiene que aparecer en el datáfono o en los extractos.
    </div>
<?php endif; ?>

<!-- ══════════ 2. CUENTAS CERRADAS — valor facturado ══════════ -->
<div class="cj-fact">
    <div class="lbl"><i class="fas fa-file-invoice-dollar"></i> Cuentas cerradas hoy · valor facturado</div>
    <div class="amt"><?= cajaCop($facturado['total']) ?></div>
    <div class="sub"><?= $facturado['num_cierres'] ?> check-out(s) cerrados</div>
</div>

<div class="cj-split">
    <div class="s h"><div class="sv"><?= cajaCop($facturado['hospedaje']) ?></div><div class="sl">Hospedaje</div></div>
    <div class="s c"><div class="sv"><?= cajaCop($facturado['consumos']) ?></div><div class="sl">Consumos</div></div>
</div>

<!-- ══════════ 3. Si no cuadran, por qué ══════════ -->
<?php if ($descuadre !== null): ?>
    <div class="cj-dif">
        <div class="t">
            <i class="fas fa-circle-exclamation"></i>
            <span><?= 'Facturado y recibido no coinciden: ' . cajaCop($descuadre['diferencia'])
                      . ' de más en lo ' . ($descuadre['sentido'] === 'recibido' ? 'recibido' : 'facturado') . '.' ?></span>
        </div>
        <ul>
            <?php foreach ($descuadre['razones'] as $r): ?>
                <li><?= htmlspecialchars($r) ?></li>
            <?php endforeach; ?>
        </ul>
        <div class="nota">
            Casi ningún día cuadra exactamente, y eso es normal: el dinero entra cuando entra
            y la cuenta se factura cuando se cierra. Esta línea está para que no haya que
            salir a buscar un error que no existe.
        </div>
    </div>
<?php endif; ?>

<!-- Detalle del dinero recibido -->
<div class="cj-sec">
    <h3><i class="fas fa-list-ul" style="color:var(--cb-primary,#6B4420);"></i> Movimientos del día</h3>
    <?php if (!empty($recibido['movimientos'])): ?>
        <span class="cnt"><?= count($recibido['movimientos']) ?> movimiento(s)</span>
    <?php endif; ?>
</div>

<?php if (empty($recibido['movimientos'])): ?>
    <div class="cj-empty">
        <i class="fas fa-cash-register"></i>
        <p>No entró dinero en esta fecha.</p>
    </div>
<?php else: ?>
    <?php foreach ($recibido['movimientos'] as $m): ?>
        <div class="cj-row">
            <?php if ($m['tipo'] === 'venta'): ?>
                <div class="cj-room venta"><i class="fas fa-mug-hot"></i></div>
            <?php else: ?>
                <div class="cj-room"><b><?= htmlspecialchars((string) $m['habitacion']) ?></b><small>HAB</small></div>
            <?php endif; ?>
            <div class="cj-info">
                <div class="cj-name"><?= htmlspecialchars($m['concepto']) ?></div>
                <div class="cj-time">
                    <i class="far fa-clock"></i> <?= date('H:i', strtotime((string) $m['fecha'])) ?>
                    · <?= htmlspecialchars($m['quien']) ?>
                </div>
            </div>
            <div class="cj-amt">
                <div class="tt"><?= cajaCop($m['monto']) ?></div>
                <div class="br"><span class="cj-chip"><?= htmlspecialchars(cajaMetodoNombre($m['metodo'])) ?></span></div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Detalle de los cierres -->
<div class="cj-sec">
    <h3><i class="fas fa-receipt" style="color:var(--cb-primary,#6B4420);"></i> Cierres del día</h3>
    <?php if ($facturado['num_cierres'] > 0): ?><span class="cnt"><?= $facturado['num_cierres'] ?> registro(s)</span><?php endif; ?>
</div>

<?php if (empty($facturado['cierres'])): ?>
    <div class="cj-empty">
        <i class="fas fa-door-closed"></i>
        <p>No hubo check-outs cerrados en esta fecha.</p>
    </div>
<?php else: ?>
    <?php foreach ($facturado['cierres'] as $e): ?>
        <?php $debe = max(0.0, (float) $e['total_pagar'] - (float) $e['total_abonado']); ?>
        <div class="cj-row">
            <div class="cj-room"><b><?= htmlspecialchars($e['habitacion']) ?></b><small>HAB</small></div>
            <div class="cj-info">
                <div class="cj-name"><?= htmlspecialchars($e['nombres'] . ' ' . $e['apellidos']) ?></div>
                <div class="cj-time"><i class="far fa-clock"></i> <?= date('H:i', strtotime($e['fecha_checkout'])) ?></div>
            </div>
            <div class="cj-amt">
                <div class="tt"><?= cajaCop((float) $e['total_pagar']) ?></div>
                <div class="br">
                    Hosp. <?= cajaCop((float) $e['subtotal_noches']) ?> · Cons. <?= cajaCop((float) $e['total_consumos']) ?>
                    <?php if ($debe > 0): ?>
                        <br><span style="color:#B84239;font-weight:700;">Debe ahora <?= cajaCop($debe) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</div><!-- /.cj-wrap -->

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
