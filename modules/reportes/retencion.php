<?php
// ============================================================
// modules/reportes/retencion.php
// Retención y borrado seguro de datos personales (ISO 27701 / Ley 1581)
// ============================================================
// Lista los huéspedes cuya última actividad supera el periodo de
// retención y permite anonimizar (borrado seguro) sus datos personales,
// conservando el historial transaccional. Solo administrador.
// ------------------------------------------------------------

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cifrado.php';
require_once __DIR__ . '/../../includes/retencion.php';
requireAdmin();

// ── Fuera del alcance (HCB_RF_001 a HCB_RF_010) ──
// La política de retención y el borrado por antigüedad no figuran en los
// requerimientos. OJO: se retira SOLO esta pantalla. El archivo
// includes/retencion.php se queda, porque lo carga
// modules/huespedes/index.php, que sí es requerimiento (HCB_RF_004).
// Para reactivarlo, borra estas dos líneas.
require_once __DIR__ . '/../../includes/modulo_retirado.php';
moduloRetirado('Retención de datos', 'La depuración de datos personales por antigüedad no está entre los requerimientos definidos para el sistema.');

$pdo = getDB();
$cfg = require __DIR__ . '/../../config/retencion.php';
$aniosRet = (int)($cfg['anios'] ?? 5);
$msg = ''; $msgType = '';

// ── Procesar borrado seguro (individual o masivo) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $msg = 'Token de seguridad inválido. Intente de nuevo.';
        $msgType = 'danger';
    } else {
        $ids = [];
        if (($_POST['accion'] ?? '') === 'anonimizar_todos') {
            foreach (huespedesParaRetencion($pdo) as $h) { $ids[] = (int)$h['id']; }
        } else {
            $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        }
        $n = 0;
        foreach ($ids as $id) {
            if ($id > 0 && anonimizarHuesped($pdo, $id)) {
                registrarAuditoria($pdo, 'huespedes', $id, 'anonimizar', 'Borrado seguro por política de retención');
                $n++;
            }
        }
        $msg = $n > 0 ? "Se anonimizaron $n huésped(es) de forma segura." : 'No se anonimizó ningún registro.';
        $msgType = $n > 0 ? 'success' : 'warning';
    }
}

$pendientes = huespedesParaRetencion($pdo);

$pageTitle = 'Retención de datos';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
.ret-wrap { padding-bottom: 90px; max-width: 820px; }
.ret-card { background:#fff; border:1px solid var(--cb-border,#EDE3D2); border-radius:16px; padding:20px 22px; margin-bottom:18px; box-shadow:0 4px 12px rgba(42,23,9,.04); }
.ret-policy { background:#F7F0E4; border-left:4px solid var(--cb-primary,#6B4420); border-radius:8px; padding:12px 16px; font-size:.85rem; color:#3E2412; margin-bottom:18px; }
.ret-msg { display:flex; align-items:center; gap:10px; padding:12px 15px; border-radius:12px; margin-bottom:16px; font-size:.9rem; }
.ret-msg.success { background:#e6f7f2; color:#047857; border:1px solid rgba(31,122,77,.25); }
.ret-msg.warning { background:#fff3e8; color:#b45309; border:1px solid rgba(249,115,22,.25); }
.ret-msg.danger  { background:#fdeded; color:#b91c1c; border:1px solid rgba(239,68,68,.25); }
.ret-table { width:100%; border-collapse:collapse; font-size:.84rem; }
.ret-table th, .ret-table td { text-align:left; padding:9px 8px; border-bottom:1px solid var(--gray-100,#F1EADF); }
.ret-table th { font-size:.72rem; text-transform:uppercase; color:var(--cb-muted,#9A8778); }
.ret-btn { display:inline-flex; align-items:center; gap:8px; border:none; border-radius:11px; padding:11px 16px; font-family:inherit; font-size:.86rem; font-weight:700; cursor:pointer; }
.ret-btn.danger { background:#7c3aed; color:#fff; }
.ret-empty { text-align:center; padding:26px; color:var(--cb-muted,#9A8778); }
.ret-empty i { font-size:2rem; color:var(--cb-primary,#6B4420); opacity:.4; display:block; margin-bottom:8px; }
</style>

<div class="ret-wrap">

    <div class="ret-policy">
        <i class="fas fa-shield-halved"></i> <b>Política de retención:</b> los datos personales se conservan hasta
        <b><?= $aniosRet ?> años</b> desde la última actividad del huésped. Superado ese plazo, deben anonimizarse
        (borrado seguro), conservando solo los registros transaccionales exigidos por la ley. Configurable en
        <code>config/retencion.php</code>.
    </div>

    <?php if ($msg): ?>
        <div class="ret-msg <?= $msgType ?>"><i class="fas fa-<?= $msgType==='success'?'circle-check':($msgType==='warning'?'triangle-exclamation':'circle-xmark') ?>"></i> <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="ret-card">
        <h3 style="margin:0 0 4px;"><i class="fas fa-clock-rotate-left"></i> Datos que superan la retención</h3>
        <p style="font-size:.85rem; color:var(--cb-muted,#9A8778); margin:0 0 14px;">
            <?= count($pendientes) ?> huésped(es) con última actividad anterior a <?= htmlspecialchars(substr(fechaCorteRetencion(),0,10)) ?>.
        </p>

        <?php if (empty($pendientes)): ?>
            <div class="ret-empty"><i class="fas fa-circle-check"></i><p>No hay datos personales pendientes de anonimizar.</p></div>
        <?php else: ?>
            <form method="POST">
                <?= csrfField() ?>
                <table class="ret-table">
                    <thead>
                        <tr>
                            <th style="width:34px;"><input type="checkbox" onclick="document.querySelectorAll('.ret-chk').forEach(c=>c.checked=this.checked)"></th>
                            <th>Huésped</th><th>Documento</th><th>Última actividad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendientes as $h): $ultima = $h['ultima_salida'] ?: $h['created_at']; ?>
                        <tr>
                            <td><input type="checkbox" class="ret-chk" name="ids[]" value="<?= (int)$h['id'] ?>"></td>
                            <td><?= htmlspecialchars($h['nombres'].' '.$h['apellidos']) ?></td>
                            <td><?= htmlspecialchars($h['documento_tipo'].' '.descifrar($h['documento_num'])) ?></td>
                            <td><?= htmlspecialchars(substr((string)$ultima,0,10)) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:16px;">
                    <button type="submit" class="ret-btn danger" onclick="return confirm('¿Anonimizar de forma segura los huéspedes SELECCIONADOS? Es irreversible.')">
                        <i class="fas fa-user-shield"></i> Anonimizar seleccionados
                    </button>
                    <button type="submit" name="accion" value="anonimizar_todos" class="ret-btn danger" style="background:#b91c1c;"
                            onclick="return confirm('¿Anonimizar TODOS los <?= count($pendientes) ?> registros que superan la retención? Es irreversible.')">
                        <i class="fas fa-broom"></i> Anonimizar todos
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
