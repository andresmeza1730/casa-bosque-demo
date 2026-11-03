<?php
// ============================================================
// modules/reportes/gubernamentales.php
// Reportes gubernamentales: SIRE (Migración) y TRA (MinCIT)
// ============================================================
// Formulario para elegir un rango de fechas y descargar los archivos
// planos .txt. Solo administrador.
// ------------------------------------------------------------

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

// ── Fuera del alcance (HCB_RF_001 a HCB_RF_010) ──
// Los reportes SIRE (Migración Colombia) y TRA (MinCIT) no figuran en
// ningún requerimiento levantado en la entrevista. Para reactivarlo,
// borra estas dos líneas; el mismo guard está en sire_exportar.php y
// tra_exportar.php, que solo se alcanzan desde aquí.
require_once __DIR__ . '/../../includes/modulo_retirado.php';
moduloRetirado('Reportes SIRE / TRA', 'Los reportes a Migración Colombia y al MinCIT no están entre los requerimientos definidos para el sistema.');

$pdo = getDB();
$establecimiento = require __DIR__ . '/../../config/establecimiento.php';

// Rango por defecto: mes en curso.
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$dDesde = DateTime::createFromFormat('Y-m-d', $desde);
$dHasta = DateTime::createFromFormat('Y-m-d', $hasta);
if (!$dDesde || $dDesde->format('Y-m-d') !== $desde) { $desde = date('Y-m-01'); }
if (!$dHasta || $dHasta->format('Y-m-d') !== $hasta) { $hasta = date('Y-m-d'); }

// Conteos para vista previa.
$qTra = $pdo->prepare("select count(*) from estancias e where date(e.fecha_checkin) between ? and ?");
$qTra->execute([$desde, $hasta]);
$totalTra = (int)$qTra->fetchColumn();

$qSire = $pdo->prepare("
    select count(*) from estancias e, huespedes hu
    where e.huesped_id = hu.id and hu.documento_tipo in ('CE','PAS')
      and ((date(e.fecha_checkin) between ? and ?) or (e.fecha_checkout is not null and date(e.fecha_checkout) between ? and ?))
");
$qSire->execute([$desde, $hasta, $desde, $hasta]);
$totalSire = (int)$qSire->fetchColumn();

$rntPendiente  = strpos($establecimiento['rnt'], '[') !== false;
$sirePendiente = strpos($establecimiento['codigo_sire'], '[') !== false;

$pageTitle = 'Reportes gubernamentales';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
.gov-wrap { padding-bottom: 90px; max-width: 760px; }
.gov-card { background:#fff; border:1px solid var(--cb-border,#EDE3D2); border-radius:16px; padding:20px 22px; margin-bottom:18px; box-shadow:0 4px 12px rgba(42,23,9,.04); }
.gov-card h3 { margin:0 0 4px; font-size:1.05rem; color:var(--cb-text,#2A1709); }
.gov-card p.sub { margin:0 0 14px; font-size:.85rem; color:var(--cb-muted,#9A8778); }
.gov-range { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.gov-field { flex:1; min-width:150px; }
.gov-field label { display:block; font-size:.8rem; font-weight:600; margin-bottom:5px; color:var(--cb-text,#2A1709); }
.gov-field input { width:100%; border:1px solid var(--cb-border,#EDE3D2); border-radius:10px; padding:10px 12px; font-family:inherit; font-size:.9rem; }
.gov-btn { display:inline-flex; align-items:center; gap:8px; border:none; border-radius:11px; padding:11px 16px; font-family:inherit; font-size:.88rem; font-weight:700; cursor:pointer; text-decoration:none; }
.gov-btn.apply { background:linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color:#fff; }
.gov-dl { display:flex; gap:12px; flex-wrap:wrap; margin-top:14px; }
.gov-dl a { flex:1; min-width:220px; justify-content:center; background:#fff; border:2px solid var(--cb-primary,#6B4420); color:var(--cb-primary,#6B4420); }
.gov-dl a.disabled { opacity:.5; pointer-events:none; border-color:#ccc; color:#999; }
.gov-count { font-size:.82rem; color:var(--cb-muted,#9A8778); margin-top:6px; }
.gov-warn { background:#fff6d6; border:1px solid #d9b400; border-radius:10px; padding:10px 14px; font-size:.82rem; margin-top:12px; color:#7a5b00; }
.gov-note { background:#F7F0E4; border-left:4px solid var(--cb-primary,#6B4420); border-radius:8px; padding:12px 16px; font-size:.82rem; color:#3E2412; }
</style>

<div class="gov-wrap">

    <!-- Selector de rango -->
    <div class="gov-card">
        <h3><i class="fas fa-calendar-range"></i> Periodo del reporte</h3>
        <p class="sub">Elige el rango de fechas y descarga los archivos planos.</p>
        <form method="GET" class="gov-range">
            <div class="gov-field"><label>Desde</label><input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>"></div>
            <div class="gov-field"><label>Hasta</label><input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>"></div>
            <button type="submit" class="gov-btn apply"><i class="fas fa-rotate"></i> Actualizar</button>
        </form>
    </div>

    <!-- SIRE -->
    <div class="gov-card">
        <h3><i class="fas fa-passport"></i> SIRE — Migración Colombia</h3>
        <p class="sub">Reporte de huéspedes extranjeros (documento CE o PAS) con sus movimientos de entrada y salida.</p>
        <div class="gov-count"><i class="fas fa-users"></i> <?= $totalSire ?> registro(s) de extranjeros en el periodo.</div>
        <?php if ($sirePendiente): ?>
            <div class="gov-warn"><i class="fas fa-triangle-exclamation"></i> Falta configurar el <b>Código SIRE</b> en <code>config/establecimiento.php</code>.</div>
        <?php endif; ?>
        <div class="gov-dl">
            <a class="gov-btn <?= $totalSire === 0 ? 'disabled' : '' ?>" href="<?= BASE_URL ?>/modules/reportes/sire_exportar.php?desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>">
                <i class="fas fa-file-lines"></i> Descargar SIRE (.txt)
            </a>
        </div>
    </div>

    <!-- TRA -->
    <div class="gov-card">
        <h3><i class="fas fa-id-card"></i> TRA — Tarjeta de Registro de Alojamiento (MinCIT)</h3>
        <p class="sub">Registro de todos los huéspedes alojados (nacionales y extranjeros) en el periodo.</p>
        <div class="gov-count"><i class="fas fa-users"></i> <?= $totalTra ?> estancia(s) en el periodo.</div>
        <?php if ($rntPendiente): ?>
            <div class="gov-warn"><i class="fas fa-triangle-exclamation"></i> Falta configurar el número de <b>RNT</b> en <code>config/establecimiento.php</code>.</div>
        <?php endif; ?>
        <div class="gov-dl">
            <a class="gov-btn <?= $totalTra === 0 ? 'disabled' : '' ?>" href="<?= BASE_URL ?>/modules/reportes/tra_exportar.php?desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>">
                <i class="fas fa-file-lines"></i> Descargar TRA (.txt)
            </a>
        </div>
    </div>

    <div class="gov-note">
        <i class="fas fa-circle-info"></i> Los archivos se generan con los datos disponibles en el sistema, separados por <code>|</code>.
        <b>Antes de enviarlos oficialmente</b>, valida el orden y formato exacto de columnas contra la especificación vigente de
        Migración Colombia (SIRE) y del MinCIT (TRA), y completa los datos del establecimiento en <code>config/establecimiento.php</code>.
    </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
