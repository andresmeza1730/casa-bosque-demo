<?php
// ============================================================
// modules/huespedes/historial.php
// Historial / trazabilidad completa de un huésped:
//   - Datos + "cliente desde".
//   - Resumen: visitas, reservas, gasto total, primera reserva, última visita.
//   - Todas sus RESERVAS (código, fechas, hora, habitación, estado).
//   - Todas sus ESTANCIAS/visitas (check-in/out, habitación, noches, total,
//     pagado y si QUEDÓ DEBIENDO al salir).
// Sin params → búsqueda; ?id= → historial completo.
// La búsqueda se manda por POST y se recuerda en la sesión, no en la URL.
// Ver includes/busqueda.php.
// ============================================================
$pageTitle = 'Historial de huéspedes';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cifrado.php';
require_once __DIR__ . '/../../includes/noches.php';
require_once __DIR__ . '/../../includes/busqueda.php';  // el texto buscado no va por la URL
requireLogin();

busquedaLimpiarSiPiden('historial');

$pdo = getDB();
$cop = fn($n) => '$' . number_format((float)$n, 0, ',', '.');

$msg = ''; $msgType = '';

// ── Reabrir una cuenta ya cerrada ───────────────────────────────────
// Un comprobante de check-out no se edita: si salió con un error —una
// noche de más, un consumo que no era—, se reabre la cuenta, se corrige
// con las pantallas de siempre y se vuelve a cerrar. Todo queda en
// auditoría, incluido el motivo.
//
// Lo reserva el administrador: deshacer un cierre mueve dinero y el
// estado de una habitación.
// ── Buscar ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buscar') {
    if (validateCsrf()) {
        busquedaGuardar('historial', (string) ($_POST['q'] ?? ''));
        busquedaRedirigir();
    }
    $msg = 'Token de seguridad inválido. Intente de nuevo.'; $msgType = 'danger';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reabrir_cuenta') {
    if (!validateCsrf()) {
        $msg = 'Token de seguridad inválido. Intente de nuevo.'; $msgType = 'danger';
    } elseif (!isAdmin()) {
        $msg = 'Solo un administrador puede reabrir una cuenta cerrada.'; $msgType = 'danger';
    } else {
        $estId  = (int) ($_POST['estancia_id'] ?? 0);
        $motivo = trim($_POST['motivo'] ?? '');

        $e = $pdo->prepare("select e.estado, e.habitacion_id, h.numero, h.estado as hab_estado
                            from estancias e join habitaciones h on h.id = e.habitacion_id
                            where e.id = ?");
        $e->execute([$estId]);
        $est = $e->fetch(PDO::FETCH_ASSOC);

        // Si hay factura vigente, primero hay que anularla: de otro modo
        // quedaría una factura emitida sobre una cuenta que va a cambiar.
        $fv = $pdo->prepare("select numero from facturas where estancia_id = ? and estado = 'emitida' limit 1");
        $fv->execute([$estId]);
        $facturaViva = $fv->fetchColumn();

        $otra = $pdo->prepare("select count(*) from estancias where habitacion_id = ? and estado = 'activa'");

        if (mb_strlen($motivo) < 10) {
            $msg = 'Escribe el motivo por el que se reabre la cuenta (al menos 10 caracteres).'; $msgType = 'danger';
        } elseif (!$est) {
            $msg = 'No se encontró esa estancia.'; $msgType = 'danger';
        } elseif ($est['estado'] !== 'cerrada') {
            $msg = 'Esa cuenta no está cerrada.'; $msgType = 'warning';
        } elseif ($facturaViva) {
            $msg = "Esta cuenta tiene la factura $facturaViva vigente. Anúlala primero en Facturación y vuelve a intentarlo.";
            $msgType = 'danger';
        } else {
            $otra->execute([$est['habitacion_id']]);
            if ((int) $otra->fetchColumn() > 0) {
                // Ya entró otro huésped a esa habitación: reabrir dejaría
                // dos estancias activas en el mismo cuarto.
                $msg = 'La habitación ' . $est['numero'] . ' ya tiene otro huésped hospedado. No se puede reabrir esta cuenta.';
                $msgType = 'danger';
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("update estancias set estado = 'activa', fecha_checkout = null,
                                   usuario_checkout = null where id = ? and estado = 'cerrada'")->execute([$estId]);
                    $pdo->prepare("update habitaciones set estado = 'ocupada' where id = ?")->execute([$est['habitacion_id']]);
                    $pdo->commit();

                    registrarAuditoria($pdo, 'estancias', $estId, 'reabrir_cuenta',
                        'Reabrió la cuenta cerrada (hab. ' . $est['numero'] . '). Motivo: ' . mb_substr($motivo, 0, 200));

                    $msg = 'Cuenta reabierta. Corrige lo que haga falta y vuelve a cerrarla en Check-out.';
                    $msgType = 'success';
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Reabrir cuenta: ' . $ex->getMessage());
                    $msg = 'No se pudo reabrir la cuenta.'; $msgType = 'danger';
                }
            }
        }
    }
}

$huespedId = (int)($_GET['id'] ?? 0);
$buscar    = busquedaRecordada('historial');

$hu = null; $reservas = []; $estancias = []; $resultados = [];
$stats = ['visitas' => 0, 'reservas' => 0, 'gastado' => 0.0, 'debe' => 0.0, 'primera' => null, 'ultima' => null];

if ($huespedId > 0) {
    $stmt = $pdo->prepare("select * from huespedes where id = ?");
    $stmt->execute([$huespedId]);
    $hu = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($hu) {
        $hu['documento_num'] = descifrar($hu['documento_num']);
        $hu['telefono']      = descifrar($hu['telefono']);

        // Reservas (orden cronológico ascendente para ver la primera)
        $stmtR = $pdo->prepare("
            select r.id, r.fecha_entrada, r.fecha_salida, r.hora_llegada, r.num_personas,
                   r.estado, r.created_at, h.numero, t.nombre as tipo
            from reservas r
            join habitaciones h on h.id = r.habitacion_id
            join tipos_habitacion t on t.id = h.tipo_id
            where r.huesped_id = ?
            order by r.created_at asc, r.id asc");
        $stmtR->execute([$huespedId]);
        $reservas = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        // Estancias / visitas (más reciente primero)
        $stmtE = $pdo->prepare("
            select e.id, e.reserva_id, e.fecha_checkin, e.fecha_checkout, e.tarifa_noche,
                   e.subtotal_noches, e.total_consumos, e.total_pagar, e.total_abonado, e.estado,
                   h.numero, t.nombre as tipo,
                   (select count(*) from consumos c where c.estancia_id = e.id and c.anulado_en is null) as n_consumos
            from estancias e
            join habitaciones h on h.id = e.habitacion_id
            join tipos_habitacion t on t.id = h.tipo_id
            where e.huesped_id = ?
            order by e.fecha_checkin desc");
        $stmtE->execute([$huespedId]);
        $estancias = $stmtE->fetchAll(PDO::FETCH_ASSOC);

        // Estadísticas de trazabilidad
        $stats['reservas'] = count($reservas);
        foreach ($estancias as $e) {
            if ($e['estado'] === 'cerrada') {
                $stats['visitas']++;
                $stats['gastado'] += (float)$e['total_pagar'];
                $saldo = (float)$e['total_pagar'] - (float)$e['total_abonado'];
                if ($saldo > 0.5) $stats['debe'] += $saldo;
            }
        }
        if (!empty($reservas)) $stats['primera'] = $reservas[0];
        if (!empty($estancias)) {
            // la última visita = check-in más reciente
            $stats['ultima'] = $estancias[0]['fecha_checkin'];
        }
    }
} elseif ($buscar !== '') {
    $stmt = $pdo->prepare("
        select id, documento_tipo, documento_num, nombres, apellidos
        from huespedes
        where documento_hash = ? or nombres like ? or apellidos like ?
        order by created_at desc
        limit 50");
    $stmt->execute([indiceCiego($buscar), "%$buscar%", "%$buscar%"]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($resultados as &$_r) { $_r['documento_num'] = descifrar($_r['documento_num']); }
    unset($_r);
}

require_once __DIR__ . '/../../includes/header.php';
?>
<style>
.hx-wrap { padding-bottom: 96px; }
.hx-back { display:inline-flex; align-items:center; gap:7px; color:var(--cb-primary,#6B4420); text-decoration:none; font-weight:600; font-size:.85rem; margin-bottom:12px; }
.hx-card { background:#fff; border:1px solid var(--cb-border,#EDE3D2); border-radius:16px; box-shadow:0 6px 18px rgba(42,23,9,.05); margin-bottom:16px; overflow:hidden; }
.hx-card-b { padding:16px; }
.hx-sec { font-size:.72rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:#A2907C; margin:0 2px 12px; display:flex; align-items:center; gap:7px; }
/* Búsqueda */
.hx-search { position:relative; margin-bottom:6px; }
.hx-search i.s { position:absolute; left:15px; top:50%; transform:translateY(-50%); color:var(--cb-muted,#9A8778); }
.hx-search input { width:100%; border:1px solid var(--cb-border,#EDE3D2); border-radius:13px; padding:14px 14px 14px 42px; font-family:inherit; font-size:.95rem; }
.hx-search input:focus { outline:none; border-color:var(--cb-primary,#6B4420); box-shadow:0 0 0 3px rgba(107,68,32,.1); }
.hx-search input { padding-right:48px; }
.hx-search .clear { position:absolute; right:6px; top:50%; transform:translateY(-50%); width:40px; height:40px; display:flex; align-items:center; justify-content:center; color:var(--cb-muted,#9A8778); text-decoration:none; font-size:1.05rem; }
.hx-row { display:flex; align-items:center; gap:12px; text-decoration:none; background:#fff; border:1px solid var(--cb-border,#EDE3D2); border-radius:15px; padding:13px 14px; margin-bottom:10px; box-shadow:0 3px 10px rgba(42,23,9,.04); }
.hx-av { flex:none; width:44px; height:44px; border-radius:13px; background:linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; }
.hx-row-info { flex:1; min-width:0; } .hx-row-name { font-weight:700; color:var(--cb-forest,#3E2412); } .hx-row-sub { font-size:.76rem; color:var(--cb-muted,#9A8778); margin-top:2px; }
.hx-chev { color:#BCA88E; }
/* Cabecera */
.hx-head { display:flex; align-items:center; gap:14px; padding:18px 16px; background:linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color:#fff; }
.hx-head .av { width:52px; height:52px; border-radius:14px; background:rgba(255,255,255,.18); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.1rem; }
.hx-head .n { font-size:1.15rem; font-weight:800; } .hx-head .d { font-size:.8rem; opacity:.9; margin-top:3px; }
/* Stats */
.hx-stats { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; }
@media(min-width:560px){ .hx-stats { grid-template-columns:repeat(4,1fr); } }
.hx-stat { background:#FBF8F2; border:1px solid #F3EADD; border-radius:13px; padding:12px; }
.hx-stat .k { font-size:.68rem; color:var(--cb-muted,#9A8778); font-weight:600; } .hx-stat .v { font-size:1.15rem; font-weight:800; color:var(--cb-forest,#3E2412); margin-top:4px; }
.hx-first { background:var(--cb-primary-light,#F5EBDC); border:1px solid rgba(107,68,32,.2); border-radius:12px; padding:12px 14px; margin-top:12px; font-size:.86rem; color:var(--cb-forest,#3E2412); }
/* Línea de tiempo */
.hx-item { border:1px solid #F3EADD; border-radius:14px; padding:13px; margin-bottom:11px; }
.hx-item .top { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; gap:8px; }
.hx-item .rm { font-weight:800; color:var(--cb-primary,#6B4420); background:var(--cb-primary-light,#F5EBDC); padding:3px 10px; border-radius:8px; font-size:.78rem; }
.hx-reabrir{display:inline-flex;align-items:center;gap:7px;margin-top:11px;border:1px solid rgba(245,158,11,.35);background:#fffaf2;color:#b45309;border-radius:10px;padding:9px 14px;font-family:inherit;font-size:.8rem;font-weight:700;cursor:pointer}
.hx-reabrir:hover{background:#fff3e2}
/* Tarjeta de confirmación, igual que en el resto del sistema */
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.4);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:9999;opacity:0;pointer-events:none;transition:opacity .25s;padding:16px}
.modal-overlay.active{opacity:1;pointer-events:auto}
.confirm-card{background:#fff;width:100%;max-width:440px;border-radius:18px;box-shadow:0 20px 40px rgba(0,0,0,.18);overflow:hidden;transform:scale(.92);transition:transform .25s cubic-bezier(.34,1.56,.64,1)}
.modal-overlay.active .confirm-card{transform:scale(1)}
.confirm-header{padding:20px 20px 8px;display:flex;align-items:center;gap:12px}
.confirm-title{font-size:1.05rem;font-weight:700;color:var(--cb-forest,#3E2412);margin:0}
.confirm-body{padding:0 20px 16px;color:#475569;font-size:.86rem;line-height:1.5}
.confirm-actions{padding:14px 20px 18px;display:flex;justify-content:flex-end;gap:10px}
.icon-container-warn{width:42px;height:42px;background:rgba(245,158,11,.12);color:var(--warning,#f59e0b);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.btn-x{padding:11px 16px;border-radius:11px;border:1px solid var(--cb-border,#EDE3D2);background:#fff;color:#475569;font-weight:600;cursor:pointer;font-family:inherit}
.btn-w{padding:11px 16px;border-radius:11px;border:none;background:var(--warning,#f59e0b);color:#fff;font-weight:700;cursor:pointer;font-family:inherit}
.hx-motivo{width:100%;margin-top:9px;padding:10px 12px;border:1.5px solid var(--cb-border,#EDE3D2);border-radius:10px;font-family:inherit;font-size:.86rem;resize:vertical;min-height:72px}
.hx-motivo:focus{outline:0;border-color:var(--warning,#f59e0b)}
.hx-kv { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #F1EADF; font-size:.84rem; }
.hx-kv:last-child { border-bottom:none; } .hx-kv .k { color:var(--cb-muted,#9A8778); } .hx-kv .v { font-weight:600; color:var(--cb-forest,#3E2412); }
.hx-pill { font-size:.66rem; font-weight:700; padding:3px 9px; border-radius:99px; }
.hx-pill.confirmada { background:#e6f7ec; color:#1F7A4D; } .hx-pill.cancelada { background:#fdeaea; color:#b91c1c; } .hx-pill.completada { background:#eef3ff; color:#3b5bdb; }
.hx-pill.activa { background:#e6f7ec; color:#1F7A4D; } .hx-pill.cerrada { background:#f1f5ef; color:#556; }
.hx-debe { background:#fdeaea; border:1px solid rgba(220,38,38,.25); color:#b91c1c; border-radius:9px; padding:7px 11px; font-size:.8rem; font-weight:700; margin-top:8px; }
.hx-ok { background:#e6f7ec; border:1px solid rgba(15,122,53,.2); color:#1F7A4D; border-radius:9px; padding:7px 11px; font-size:.8rem; font-weight:600; margin-top:8px; }
.hx-empty { text-align:center; padding:26px 12px; color:var(--cb-muted,#9A8778); }
.hx-empty i { font-size:2rem; color:var(--cb-primary,#6B4420); opacity:.4; margin-bottom:10px; display:block; }
</style>

<div class="hx-wrap">

<?php if ($msg !== ''): ?>
    <div class="cb-msg-inline" style="display:flex; align-items:center; gap:10px; padding:13px 15px; border-radius:14px; margin-bottom:16px; font-size:.88rem; line-height:1.5;
        <?= $msgType === 'success' ? 'background:#e6f7f2;color:#047857;border:1px solid rgba(31,122,77,.25);'
          : ($msgType === 'warning' ? 'background:#fff3e8;color:#b45309;border:1px solid rgba(249,115,22,.25);'
          : 'background:#fdeded;color:#b91c1c;border:1px solid rgba(239,68,68,.25);') ?>">
        <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
        <span><?= htmlspecialchars($msg) ?></span>
    </div>
<?php endif; ?>

<?php if ($hu): ?>
    <a href="<?= BASE_URL ?>/modules/huespedes/historial.php" class="hx-back"><i class="fas fa-arrow-left"></i> Volver a la búsqueda</a>

    <!-- Cabecera -->
    <div class="hx-card">
        <div class="hx-head">
            <div class="av"><?= strtoupper(mb_substr($hu['nombres'],0,1,'UTF-8') . mb_substr($hu['apellidos'],0,1,'UTF-8')) ?></div>
            <div style="flex:1; min-width:0;">
                <div class="n"><?= htmlspecialchars($hu['nombres'] . ' ' . $hu['apellidos']) ?></div>
                <div class="d"><?= htmlspecialchars($hu['documento_tipo'] . ' ' . $hu['documento_num']) ?><?= $hu['telefono'] ? ' · ' . htmlspecialchars($hu['telefono']) : '' ?></div>
            </div>
        </div>
        <div class="hx-card-b">
            <div class="hx-stats">
                <div class="hx-stat"><div class="k"><i class="fas fa-bed"></i> Visitas</div><div class="v"><?= $stats['visitas'] ?></div></div>
                <div class="hx-stat"><div class="k"><i class="fas fa-calendar-check"></i> Reservas</div><div class="v"><?= $stats['reservas'] ?></div></div>
                <div class="hx-stat"><div class="k"><i class="fas fa-coins"></i> Total gastado</div><div class="v" style="font-size:.98rem;"><?= $cop($stats['gastado']) ?></div></div>
                <div class="hx-stat"><div class="k"><i class="fas fa-triangle-exclamation"></i> Debe (histórico)</div><div class="v" style="font-size:.98rem; color:<?= $stats['debe'] > 0.5 ? '#b91c1c' : '#1F7A4D' ?>;"><?= $stats['debe'] > 0.5 ? $cop($stats['debe']) : '$0' ?></div></div>
            </div>

            <div style="margin-top:12px; display:flex; flex-wrap:wrap; gap:10px;">
                <?php if ($stats['primera']): $p = $stats['primera']; ?>
                    <div class="hx-first" style="flex:1; min-width:200px;">
                        <i class="fas fa-star" style="color:var(--cb-primary,#6B4420);"></i> <b>Primera reserva:</b>
                        <?= date('d/m/Y', strtotime($p['created_at'])) ?> · llega <?= $p['hora_llegada'] ? date('h:i A', strtotime($p['hora_llegada'])) : '—' ?>
                        · Hab. <?= htmlspecialchars($p['numero']) ?> (<?= htmlspecialchars($p['tipo']) ?>)
                    </div>
                <?php endif; ?>
                <div class="hx-first" style="flex:1; min-width:200px;">
                    <i class="fas fa-clock-rotate-left" style="color:var(--cb-primary,#6B4420);"></i> <b>Cliente desde:</b> <?= date('d/m/Y', strtotime($hu['created_at'])) ?>
                    <?php if ($stats['ultima']): ?> · <b>Última visita:</b> <?= date('d/m/Y', strtotime($stats['ultima'])) ?><?php endif; ?>
                </div>
            </div>
            <?php if (!empty($hu['anonimizado_en'])): ?>
                <div style="margin-top:10px; font-size:.78rem; color:#6d28d9; background:#f3e8ff; border:1px solid rgba(124,58,237,.25); border-radius:9px; padding:8px 11px;"><i class="fas fa-user-shield"></i> Datos personales anonimizados el <?= htmlspecialchars($hu['anonimizado_en']) ?> (se conserva el historial sin identidad).</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Visitas / estancias -->
    <div class="hx-card">
        <div class="hx-card-b">
            <div class="hx-sec"><i class="fas fa-suitcase-rolling"></i> Estancias / visitas (<?= count($estancias) ?>)</div>
            <?php if (empty($estancias)): ?>
                <div class="hx-empty"><i class="fas fa-bed"></i><p>Aún no se ha hospedado (sin check-in registrado).</p></div>
            <?php else: ?>
                <?php foreach ($estancias as $e):
                    $noches = nochesEstancia($e['fecha_checkin'], $e['fecha_checkout'] ?: null);
                    $saldo  = (float)$e['total_pagar'] - (float)$e['total_abonado'];
                ?>
                    <div class="hx-item">
                        <div class="top">
                            <span class="rm">Hab. <?= htmlspecialchars($e['numero']) ?> · <?= htmlspecialchars($e['tipo']) ?></span>
                            <span class="hx-pill <?= htmlspecialchars($e['estado']) ?>"><?= $e['estado'] === 'activa' ? 'En curso' : 'Finalizada' ?></span>
                        </div>
                        <div class="hx-kv"><span class="k">Entrada</span><span class="v"><?= date('d/m/Y h:i A', strtotime($e['fecha_checkin'])) ?></span></div>
                        <div class="hx-kv"><span class="k">Salida</span><span class="v"><?= $e['fecha_checkout'] ? date('d/m/Y h:i A', strtotime($e['fecha_checkout'])) : 'En curso' ?></span></div>
                        <div class="hx-kv"><span class="k">Noches</span><span class="v"><?= $noches ?></span></div>
                        <div class="hx-kv"><span class="k">Consumos</span><span class="v"><?= (int)$e['n_consumos'] ?> · <?= $cop($e['total_consumos']) ?></span></div>
                        <?php if ($e['estado'] === 'cerrada'): ?>
                            <div class="hx-kv"><span class="k">Total de la cuenta</span><span class="v"><?= $cop($e['total_pagar']) ?></span></div>
                            <div class="hx-kv"><span class="k">Pagado</span><span class="v"><?= $cop($e['total_abonado']) ?></span></div>
                            <?php if ($saldo > 0.5): ?>
                                <div class="hx-debe"><i class="fas fa-triangle-exclamation"></i> Quedó debiendo <?= $cop($saldo) ?> al hacer el check-out.</div>
                            <?php else: ?>
                                <div class="hx-ok"><i class="fas fa-circle-check"></i> Pagó completo. Sin saldo pendiente.</div>
                            <?php endif; ?>
                            <?php if (isAdmin()): ?>
                                <?php /* El comprobante no se edita: se reabre la cuenta, se corrige y se vuelve a cerrar. */ ?>
                                <button type="button" class="hx-reabrir"
                                        onclick="pedirReabrir(<?= (int)$e['id'] ?>, <?= htmlspecialchars(json_encode($e['numero']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(date('d/m/Y', strtotime($e['fecha_checkin']))), ENT_QUOTES) ?>)">
                                    <i class="fas fa-rotate-left"></i> Reabrir cuenta
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Reservas -->
    <div class="hx-card">
        <div class="hx-card-b">
            <div class="hx-sec"><i class="fas fa-calendar-check"></i> Reservas (<?= count($reservas) ?>)</div>
            <?php if (empty($reservas)): ?>
                <div class="hx-empty"><i class="fas fa-calendar-xmark"></i><p>Sin reservas registradas.</p></div>
            <?php else: ?>
                <?php foreach (array_reverse($reservas) as $r): ?>
                    <div class="hx-item">
                        <div class="top">
                            <span class="rm">Hab. <?= htmlspecialchars($r['numero']) ?></span>
                            <span class="hx-pill <?= htmlspecialchars($r['estado']) ?>"><?= ucfirst($r['estado']) ?></span>
                        </div>
                        <div class="hx-kv"><span class="k">Fechas</span><span class="v"><?= date('d/m/Y', strtotime($r['fecha_entrada'])) ?> → <?= date('d/m/Y', strtotime($r['fecha_salida'])) ?></span></div>
                        <div class="hx-kv"><span class="k">Hora de llegada</span><span class="v"><?= $r['hora_llegada'] ? date('h:i A', strtotime($r['hora_llegada'])) : '—' ?></span></div>
                        <div class="hx-kv"><span class="k">Personas</span><span class="v"><?= (int)$r['num_personas'] ?></span></div>
                        <div class="hx-kv"><span class="k">Reservada el</span><span class="v"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></span></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- Búsqueda -->
    <div class="hx-card"><div class="hx-card-b">
        <form method="POST" class="hx-search">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="buscar">
            <i class="fas fa-search s"></i>
            <input type="text" name="q" placeholder="Buscar huésped por documento o nombre..." value="<?= htmlspecialchars($buscar) ?>" autofocus autocomplete="off">
            <?php if ($buscar): ?><a href="?limpiar=1" class="clear" title="Limpiar"><i class="fas fa-times-circle"></i></a><?php endif; ?>
        </form>
        <div style="font-size:.74rem; color:var(--cb-muted,#9A8778); margin-top:8px;"><i class="fas fa-circle-info"></i> Busca un huésped y toca su nombre para ver todo su historial y trazabilidad.</div>
    </div></div>

    <?php if ($buscar !== ''): ?>
        <div class="hx-sec"><i class="fas fa-magnifying-glass"></i> Resultados (<?= count($resultados) ?>)</div>
        <?php if (empty($resultados)): ?>
            <div class="hx-card"><div class="hx-card-b"><div class="hx-empty"><i class="fas fa-user-slash"></i><p>No se encontró ningún huésped.</p></div></div></div>
        <?php else: ?>
            <?php foreach ($resultados as $r): ?>
                <a href="<?= BASE_URL ?>/modules/huespedes/historial.php?id=<?= $r['id'] ?>" class="hx-row">
                    <div class="hx-av"><?= strtoupper(mb_substr($r['nombres'],0,1,'UTF-8') . mb_substr($r['apellidos'],0,1,'UTF-8')) ?></div>
                    <div class="hx-row-info">
                        <div class="hx-row-name"><?= htmlspecialchars($r['nombres'] . ' ' . $r['apellidos']) ?></div>
                        <div class="hx-row-sub"><?= htmlspecialchars($r['documento_tipo'] . ' ' . $r['documento_num']) ?></div>
                    </div>
                    <i class="fas fa-chevron-right hx-chev"></i>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php else: ?>
        <div class="hx-empty"><i class="fas fa-clock-rotate-left"></i><p>Escribe arriba para buscar el historial de un huésped.</p></div>
    <?php endif; ?>
<?php endif; ?>

</div>

<?php if (isAdmin()): ?>
<div id="reabrir-modal-overlay" class="modal-overlay">
    <div class="confirm-card">
        <div class="confirm-header">
            <div class="icon-container-warn"><i class="fas fa-rotate-left"></i></div>
            <h4 class="confirm-title">¿Reabrir esta cuenta?</h4>
        </div>
        <form method="POST" id="form-reabrir">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reabrir_cuenta">
            <input type="hidden" name="estancia_id" id="reabrir-estancia-id" value="">
            <div class="confirm-body">
                Vas a reabrir la estadía de la <strong id="reabrir-hab" style="color:var(--cb-forest,#3E2412);"></strong>
                que entró el <strong id="reabrir-fecha" style="color:var(--cb-forest,#3E2412);"></strong>.<br><br>
                <strong>Un comprobante no se edita.</strong> La cuenta vuelve a quedar activa para que corrijas
                lo que haga falta —anular un cargo que no era, registrar un pago, ajustar las noches— y después
                la cierras de nuevo en Check-out. El total se recalcula solo.<br><br>
                La habitación vuelve a marcarse como <strong>ocupada</strong>, así que no la asignes a nadie más
                mientras tanto. Todo queda en auditoría con el motivo que escribas.
                <label for="reabrir-motivo" style="display:block;margin-top:14px;font-size:.8rem;font-weight:700;color:#475569;">
                    Motivo
                </label>
                <textarea name="motivo" id="reabrir-motivo" class="hx-motivo" required minlength="10"
                          placeholder="Ej: Se le cargó un desayuno que no consumió; se anula y se vuelve a cerrar."></textarea>
            </div>
            <div class="confirm-actions">
                <button type="button" class="btn-x" onclick="cerrarReabrir()">Cancelar</button>
                <button type="submit" class="btn-w">Reabrir cuenta</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var ov = document.getElementById('reabrir-modal-overlay');
    if (!ov) return;
    window.pedirReabrir = function (id, habitacion, fecha) {
        document.getElementById('reabrir-estancia-id').value = id;
        document.getElementById('reabrir-hab').textContent = 'habitación ' + habitacion;
        document.getElementById('reabrir-fecha').textContent = fecha;
        document.getElementById('reabrir-motivo').value = '';
        ov.classList.add('active');
        setTimeout(function () { document.getElementById('reabrir-motivo').focus(); }, 250);
    };
    window.cerrarReabrir = function () { ov.classList.remove('active'); };
    ov.addEventListener('click', function (e) { if (e.target === ov) window.cerrarReabrir(); });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
