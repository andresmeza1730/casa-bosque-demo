<?php
// ============================================================
// modules/reportes/comprobantes.php
// Historial de comprobantes — Casa Bosque
// ============================================================
// Lista los check-outs cerrados para que el administrador pueda
// reimprimir el ticket (80mm) o la factura PDF de cualquier
// estancia pasada (cliente que la perdió o la pide de nuevo).
// Solo administrador.
// ------------------------------------------------------------

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cifrado.php';
require_once __DIR__ . '/../../includes/busqueda.php';  // el texto buscado no va por la URL
requireAdmin();

$pdo = getDB();

$msgFiltro = '';

busquedaLimpiarSiPiden('comprobantes');

// ── Aplicar los filtros ──
// El texto va por POST y se recuerda en la sesión; las FECHAS sí pueden ir en
// la URL —no dicen nada de nadie— y así el enlace sigue sirviendo para volver
// al mismo rango. Ver includes/busqueda.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buscar') {
    if (validateCsrf()) {
        busquedaGuardar('comprobantes', (string) ($_POST['q'] ?? ''));
        busquedaRedirigir([
            'desde' => (string) ($_POST['desde'] ?? ''),
            'hasta' => (string) ($_POST['hasta'] ?? ''),
        ]);
    }
    $msgFiltro = 'Token de seguridad inválido. Vuelve a intentarlo.';
}

// Filtros
$buscar = busquedaRecordada('comprobantes');
$desde  = $_GET['desde'] ?? '';
$hasta  = $_GET['hasta'] ?? '';

// Validar fechas
$dDesde = $desde ? DateTime::createFromFormat('Y-m-d', $desde) : null;
$dHasta = $hasta ? DateTime::createFromFormat('Y-m-d', $hasta) : null;
if (!$dDesde || $dDesde->format('Y-m-d') !== $desde) { $desde = ''; }
if (!$dHasta || $dHasta->format('Y-m-d') !== $hasta) { $hasta = ''; }

// Construir consulta de estancias cerradas con filtros
$sql = "
    select e.id, e.subtotal_noches, e.total_consumos, e.total_pagar, e.fecha_checkout,
           h.numero as habitacion, hu.nombres, hu.apellidos, hu.documento_num,
           f.id as factura_id, f.numero as factura_numero
    from estancias e
    join habitaciones h on e.habitacion_id = h.id
    join huespedes hu on e.huesped_id = hu.id
    left join facturas f on f.estancia_id=e.id
    where e.estado = 'cerrada'
";
$params = [];

if ($buscar !== '') {
    // documento_num está cifrado: se busca por índice ciego (documento
    // completo exacto). Nombre, apellido y habitación siguen con LIKE.
    $sql .= " and (hu.nombres like ? or hu.apellidos like ? or hu.documento_hash = ? or h.numero like ?)";
    $like = '%' . $buscar . '%';
    $params[] = $like; $params[] = $like; $params[] = indiceCiego($buscar); $params[] = $like;
}
if ($desde !== '') { $sql .= " and date(e.fecha_checkout) >= ?"; $params[] = $desde; }
if ($hasta !== '') { $sql .= " and date(e.fecha_checkout) <= ?"; $params[] = $hasta; }

$sql .= " order by e.fecha_checkout desc limit 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$comprobantes = $stmt->fetchAll();
foreach ($comprobantes as &$_c) { $_c['documento_num'] = descifrar($_c['documento_num']); }
unset($_c);

$totalListado = 0;
foreach ($comprobantes as $c) { $totalListado += (float) $c['total_pagar']; }

$currentDir = 'reportes';
$pageTitle = 'Comprobantes';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
/* ============================================================
   Historial de comprobantes — Casa Bosque
   ============================================================ */
.cp-wrap { padding-bottom: 90px; }

.cp-intro { font-size: .82rem; color: var(--cb-muted,#9A8778); margin: 2px 2px 14px; line-height: 1.45; }

/* Filtros */
.cp-filters { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); padding: 14px; margin-bottom: 16px; }
.cp-search { position: relative; margin-bottom: 11px; }
.cp-search i.s { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--cb-muted,#9A8778); }
.cp-search input { width: 100%; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 12px; padding: 12px 14px 12px 40px; font-family: inherit; font-size: .88rem; }
.cp-search input:focus { outline: none; border-color: var(--cb-primary,#6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }
.cp-dates { display: flex; gap: 9px; margin-bottom: 11px; }
.cp-dates .f { flex: 1; }
.cp-dates label { display: block; font-size: .68rem; color: var(--cb-muted,#9A8778); font-weight: 600; margin-bottom: 5px; }
.cp-dates input { width: 100%; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 11px; padding: 10px; font-family: inherit; font-size: .82rem; }
.cp-dates input:focus { outline: none; border-color: var(--cb-primary,#6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }
.cp-fbtns { display: flex; gap: 9px; }
.cp-btn { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 7px; font-size: .84rem; font-weight: 700; border-radius: 11px; padding: 11px; text-decoration: none; cursor: pointer; font-family: inherit; border: none; }
.cp-btn.apply { background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color: #fff; }
.cp-btn.clear { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); color: var(--cb-muted,#9A8778); }

.cp-summary { display: flex; align-items: center; justify-content: space-between; margin: 0 2px 12px; }
.cp-summary .c { font-size: .78rem; color: var(--cb-muted,#9A8778); font-weight: 600; }
.cp-summary .t { font-size: .82rem; color: var(--cb-forest,#3E2412); font-weight: 700; }

/* Tarjeta de comprobante */
.cp-card { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 16px; padding: 14px; margin-bottom: 11px; box-shadow: 0 4px 12px rgba(42,23,9,.04); }
.cp-top { display: flex; align-items: center; gap: 12px; }
.cp-room { flex: none; width: 46px; height: 46px; border-radius: 13px; background: var(--cb-primary-light,#F5EBDC); color: var(--cb-primary,#6B4420); display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1; }
.cp-room b { font-size: .95rem; font-weight: 800; }
.cp-room small { font-size: .5rem; font-weight: 700; opacity: .8; margin-top: 2px; }
.cp-info { flex: 1; min-width: 0; }
.cp-name { font-size: .9rem; font-weight: 700; color: var(--cb-forest,#3E2412); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cp-meta { font-size: .72rem; color: var(--cb-muted,#9A8778); margin-top: 2px; }
.cp-total { flex: none; text-align: right; }
.cp-total .v { font-size: .95rem; font-weight: 800; color: var(--cb-forest,#3E2412); }
.cp-total .l { font-size: .6rem; color: var(--cb-muted,#9A8778); }
.cp-actions { display: flex; gap: 9px; margin-top: 13px; padding-top: 12px; border-top: 1px solid var(--gray-100,#F1EADF); }
.cp-act { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 7px; font-size: .8rem; font-weight: 700; padding: 10px; border-radius: 11px; text-decoration: none; }
.cp-act.factura { background: var(--cb-primary-light,#F5EBDC); color: var(--cb-primary,#6B4420); border: 1px solid rgba(107,68,32,.18); }
.cp-act.ticket { background: #fff; color: var(--cb-forest,#3E2412); border: 1px solid var(--cb-border,#EDE3D2); }
.cp-act:active { transform: scale(.97); }

.cp-empty { text-align: center; padding: 40px 16px; color: var(--cb-muted,#9A8778); }
.cp-empty i { font-size: 2.4rem; color: var(--cb-primary,#6B4420); opacity: .35; margin-bottom: 14px; display: block; }
.cp-empty p { font-size: .92rem; margin: 0 0 4px; }
.cp-empty small { font-size: .78rem; opacity: .8; }
</style>

<div class="cp-wrap">

<p class="cp-intro">Consulta y reimprime el ticket o la factura de cualquier check-out cerrado. Útil si un huésped pierde su comprobante o lo solicita de nuevo.</p>

<!-- Filtros -->
<div class="cp-filters">
    <?php if ($msgFiltro !== ''): ?>
        <div class="cp-msg" style="margin-bottom:10px;color:#B84239;font-size:.85rem;"><?= htmlspecialchars($msgFiltro) ?></div>
    <?php endif; ?>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="buscar">
        <div class="cp-search">
            <i class="fas fa-search s"></i>
            <input type="text" name="q" placeholder="Buscar por nombre, documento o habitación..." value="<?= htmlspecialchars($buscar) ?>">
        </div>
        <div class="cp-dates">
            <div class="f"><label>Desde</label><input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>"></div>
            <div class="f"><label>Hasta</label><input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>"></div>
        </div>
        <div class="cp-fbtns">
            <button type="submit" class="cp-btn apply"><i class="fas fa-filter"></i> Buscar</button>
            <a href="?limpiar=1" class="cp-btn clear">Limpiar</a>
        </div>
    </form>
</div>

<div class="cp-summary">
    <span class="c"><i class="fas fa-receipt" style="margin-right:5px;"></i> <?= count($comprobantes) ?> comprobante(s)</span>
    <?php if (!empty($comprobantes)): ?><span class="t">Total: $<?= number_format($totalListado, 0, ',', '.') ?></span><?php endif; ?>
</div>

<?php if (empty($comprobantes)): ?>
    <div class="cp-empty">
        <i class="fas fa-folder-open"></i>
        <p>No se encontraron comprobantes.</p>
        <small><?= ($buscar !== '' || $desde !== '' || $hasta !== '') ? 'Prueba con otros filtros de búsqueda.' : 'Aún no hay check-outs cerrados.' ?></small>
    </div>
<?php else: ?>
    <?php foreach ($comprobantes as $c): ?>
        <div class="cp-card">
            <div class="cp-top">
                <div class="cp-room"><b><?= htmlspecialchars($c['habitacion']) ?></b><small>HAB</small></div>
                <div class="cp-info">
                    <div class="cp-name"><?= htmlspecialchars($c['nombres'] . ' ' . $c['apellidos']) ?></div>
                    <div class="cp-meta">
                        <i class="fas fa-id-card" style="font-size:10px;"></i> <?= htmlspecialchars($c['documento_num']) ?>
                        &nbsp;·&nbsp; <i class="far fa-calendar" style="font-size:10px;"></i> <?= date('d/m/Y H:i', strtotime($c['fecha_checkout'])) ?>
                    </div>
                </div>
                <div class="cp-total">
                    <div class="v">$<?= number_format($c['total_pagar'], 0, ',', '.') ?></div>
                    <div class="l"><?= htmlspecialchars($c['factura_numero'] ?: 'Sin factura emitida') ?></div>
                </div>
            </div>
            <div class="cp-actions">
                <?php if (!empty($c['factura_id'])): ?>
                    <a href="<?= BASE_URL ?>/modules/checkout/factura_pdf.php?factura=<?= (int)$c['factura_id'] ?>&amp;volver=comprobantes" class="cp-act factura"><i class="fas fa-file-pdf"></i> Factura interna</a>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/modules/facturacion/index.php?estancia=<?= (int)$c['id'] ?>" class="cp-act factura"><i class="fas fa-file-circle-plus"></i> Emitir si la solicita</a>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>/modules/checkout/ticket.php?id=<?= (int)$c['id'] ?>&amp;volver=comprobantes" class="cp-act ticket"><i class="fas fa-receipt"></i> Ticket 80mm</a>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</div><!-- /.cp-wrap -->

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
