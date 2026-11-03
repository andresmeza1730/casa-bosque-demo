<?php
// ============================================================
// modules/reportes/dian.php
// Facturación Electrónica DIAN — estado y registro (solo admin)
// ============================================================
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/dian.php';
requireAdmin();

$pdo = getDB();
$activo = dianHabilitado();

// Lista de facturas emitidas (solo si DIAN está activo; la tabla podría
// no existir aún mientras esté deshabilitado).
$facturas = [];
if ($activo) {
    try {
        $facturas = $pdo->query("select f.numero, f.cufe, f.estado, f.created_at, h.numero as habitacion
                                 from facturas_electronicas f
                                 left join estancias e on e.id = f.estancia_id
                                 left join habitaciones h on h.id = e.habitacion_id
                                 order by f.created_at desc limit 200")->fetchAll();
    } catch (Throwable $e) {
        $facturas = [];
    }
}

$pageTitle = 'Facturación DIAN';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
.dian-wrap{padding-bottom:90px;max-width:760px;}
.dian-card{background:#fff;border:1px solid var(--cb-border,#EDE3D2);border-radius:16px;padding:20px 22px;margin-bottom:16px;box-shadow:0 4px 12px rgba(42,23,9,.04);}
.dian-badge{display:inline-flex;align-items:center;gap:7px;font-size:.8rem;font-weight:700;padding:6px 12px;border-radius:999px;}
.dian-badge.off{background:#fff3e8;color:#b45309;border:1px solid rgba(249,115,22,.25);}
.dian-badge.on{background:#e6f7ec;color:#1F7A4D;border:1px solid rgba(31,122,77,.25);}
.dian-note{background:#F7F0E4;border-left:4px solid var(--cb-primary,#6B4420);border-radius:8px;padding:12px 16px;font-size:.85rem;color:#3E2412;line-height:1.6;}
/* La tabla se desplaza DENTRO de su contenedor. El armazón móvil recorta con
   `overflow-x: hidden` todo lo que se salga de .content-area, y antes la tabla
   —600px fijos por una regla global que ya no existe— dejaba la columna CUFE
   fuera de pantalla y sin forma de alcanzarla. El ancho mínimo vive aquí y no en
   una hoja compartida: es de ESTA tabla. */
.dian-scroll{overflow-x:auto;max-width:100%;-webkit-overflow-scrolling:touch;overscroll-behavior-x:contain;}
.dian-table{width:100%;min-width:520px;border-collapse:collapse;font-size:.84rem;}
.dian-table th,.dian-table td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--gray-100,#F1EADF);}
.dian-table th{font-size:.72rem;text-transform:uppercase;color:var(--cb-muted,#9A8778);}
</style>

<div class="dian-wrap">
    <div class="dian-card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <h3 style="margin:0;font-size:1.05rem;"><i class="fas fa-file-invoice-dollar"></i> Facturación Electrónica DIAN</h3>
            <span class="dian-badge <?= $activo ? 'on' : 'off' ?>">
                <i class="fas fa-<?= $activo ? 'circle-check' : 'circle-pause' ?>"></i> <?= $activo ? 'Activa' : 'Deshabilitada' ?>
            </span>
        </div>
    </div>

    <?php if (!$activo): ?>
    <div class="dian-card">
        <div class="dian-note">
            <b>El módulo está preparado pero desactivado.</b> La facturación electrónica requiere:
            <ol style="margin:8px 0 0 18px;padding:0;">
                <li>Hosting con <b>conexiones salientes</b> (un hosting gratuito no las permite).</li>
                <li>Contrato con un <b>Proveedor Tecnológico (PT)</b> autorizado por la DIAN.</li>
                <li>Tu <b>resolución de numeración</b> de la DIAN.</li>
            </ol>
            <p style="margin:10px 0 0;">Para activar: completa <code>config/dian.php</code>, pon <code>'habilitado' =&gt; true</code>,
            implementa la llamada al proveedor en <code>includes/dian.php</code>
            (función <code>dianLlamarProveedor()</code>). Mientras tanto, el sistema sigue usando su comprobante interno.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="dian-card">
        <h3 style="margin:0 0 12px;font-size:.95rem;"><i class="fas fa-receipt"></i> Facturas emitidas</h3>
        <?php if (empty($facturas)): ?>
            <p style="font-size:.88rem;color:var(--cb-muted,#9A8778);">Aún no hay facturas electrónicas registradas.</p>
        <?php else: ?>
            <?php /* tabindex="0": una región que se desplaza tiene que poder alcanzarse con el teclado. */ ?>
            <div class="dian-scroll" role="region" aria-label="Facturas emitidas (se desplaza hacia los lados)" tabindex="0">
            <table class="dian-table">
                <thead><tr><th>Fecha</th><th>Número</th><th>Hab.</th><th>Estado</th><th>CUFE</th></tr></thead>
                <tbody>
                <?php foreach ($facturas as $f): ?>
                    <tr>
                        <td><?= htmlspecialchars(substr((string)$f['created_at'],0,16)) ?></td>
                        <td><?= htmlspecialchars($f['numero'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($f['habitacion'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($f['estado']) ?></td>
                        <td style="font-family:monospace;font-size:.7rem;word-break:break-all;"><?= htmlspecialchars(substr((string)$f['cufe'],0,28)) ?>…</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
