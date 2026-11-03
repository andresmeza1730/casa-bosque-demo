<?php
// ============================================================
// modules/usuarios/seguridad.php
// Seguridad de la cuenta — Autenticación en dos pasos (2FA/TOTP)
// ============================================================
// Cada usuario activa/gestiona su propio 2FA. Obligatorio para admins.
// ------------------------------------------------------------

define('PAGINA_SIN_2FA_GATE', true);   // esta página debe poder verse aunque falte activar 2FA
$pageTitle = 'Seguridad (2FA)';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/login.php';
require_once __DIR__ . '/../../includes/totp.php';
require_once __DIR__ . '/../../includes/cifrado.php';
require_once __DIR__ . '/../../includes/sesiones.php';
require_once __DIR__ . '/../../includes/equipos.php';
requireLogin();

$pdo   = getDB();
$uid   = (int) getCurrentUserId();
$email = $_SESSION['usuario_email'] ?? getCurrentUserName();

function cargarEstado2FA(PDO $pdo, int $uid): array {
    $s = $pdo->prepare("select twofa_enabled, twofa_secret, twofa_backup from usuarios where id = ?");
    $s->execute([$uid]);
    return $s->fetch() ?: ['twofa_enabled' => 0, 'twofa_secret' => null, 'twofa_backup' => null];
}

$estado        = cargarEstado2FA($pdo, $uid);
$msg = ''; $msgType = '';
$mostrarQR = false; $secretSetup = null; $codigosNuevos = null;
$forzado = isset($_GET['forzar']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $msg = 'Token de seguridad inválido. Intente de nuevo.'; $msgType = 'danger';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'iniciar' && !$estado['twofa_enabled']) {
            $secretSetup = totpSecretoNuevo();
            $_SESSION['2fa_setup_secret'] = $secretSetup;
            $mostrarQR = true;

        } elseif ($accion === 'confirmar' && !$estado['twofa_enabled']) {
            $secret = $_SESSION['2fa_setup_secret'] ?? '';
            if ($secret !== '' && totpVerificar($secret, $_POST['codigo'] ?? '')) {
                $codigosNuevos = generarCodigosRespaldo();
                $upd = $pdo->prepare("update usuarios set twofa_secret = ?, twofa_enabled = 1, twofa_backup = ? where id = ?");
                $upd->execute([cifrar($secret), hashCodigosRespaldo($codigosNuevos), $uid]);
                unset($_SESSION['2fa_setup_secret']);
                $_SESSION['twofa_enabled'] = true;
                // Activar el 2FA protege los próximos inicios de sesión, pero
                // no echa a quien ya esté dentro. Si se activa justo porque
                // alguien se metió en la cuenta, su sesión seguiría viva. Se
                // cierran las demás; la de este navegador se conserva.
                $nCerradas = sesionCerrarTodas($pdo, $uid, 'cambio_2fa', true);
                registrarAuditoria($pdo, 'usuarios', $uid, 'activar_2fa',
                    "Activó la verificación en dos pasos (2FA); sesiones cerradas: $nCerradas");
                $msg = '¡2FA activado! Guarda tus códigos de respaldo en un lugar seguro.'
                     . ($nCerradas > 0 ? " Se cerraron $nCerradas sesión(es) tuyas en otros dispositivos." : '');
                $msgType = 'success';
            } else {
                $secretSetup = $secret; $mostrarQR = true;
                $msg = 'El código no coincide. Vuelve a intentarlo.'; $msgType = 'danger';
            }

        } elseif ($accion === 'regenerar' && $estado['twofa_enabled']) {
            $secret = descifrar($estado['twofa_secret']);
            if ($secret !== null && totpVerificar($secret, $_POST['codigo'] ?? '')) {
                $codigosNuevos = generarCodigosRespaldo();
                $pdo->prepare("update usuarios set twofa_backup = ? where id = ?")
                    ->execute([hashCodigosRespaldo($codigosNuevos), $uid]);
                // Si se regeneran los códigos es porque los anteriores pueden
                // estar comprometidos: se cierran las otras sesiones también.
                $nCerradas = sesionCerrarTodas($pdo, $uid, 'cambio_2fa', true);
                registrarAuditoria($pdo, 'usuarios', $uid, 'regenerar_backup_2fa',
                    "Regeneró sus códigos de respaldo 2FA; sesiones cerradas: $nCerradas");
                $msg = 'Códigos de respaldo regenerados. Los anteriores ya no sirven.'
                     . ($nCerradas > 0 ? " Se cerraron $nCerradas sesión(es) tuyas en otros dispositivos." : '');
                $msgType = 'success';
            } else {
                $msg = 'Código incorrecto: no se regeneraron los códigos.'; $msgType = 'danger';
            }

        } elseif ($accion === 'desactivar' && $estado['twofa_enabled']) {
            $secret = descifrar($estado['twofa_secret']);
            if ($secret !== null && totpVerificar($secret, $_POST['codigo'] ?? '')) {
                $pdo->prepare("update usuarios set twofa_secret = null, twofa_enabled = 0, twofa_backup = null where id = ?")
                    ->execute([$uid]);
                $_SESSION['twofa_enabled'] = false;
                // Quitar el segundo factor deja la cuenta protegida solo por
                // la contraseña: las sesiones abiertas en otros dispositivos
                // se cierran, para que el cambio no pase desapercibido.
                $nCerradas = sesionCerrarTodas($pdo, $uid, 'cambio_2fa', true);
                registrarAuditoria($pdo, 'usuarios', $uid, 'desactivar_2fa',
                    "Desactivó la verificación en dos pasos (2FA); sesiones cerradas: $nCerradas");
                $msg = '2FA desactivado.'
                     . ($nCerradas > 0 ? " Se cerraron $nCerradas sesión(es) tuyas en otros dispositivos." : '')
                     . (isAdmin() ? ' Como administrador, deberás activarlo de nuevo para continuar.' : '');
                $msgType = 'warning';
            } else {
                $msg = 'Código incorrecto: no se desactivó el 2FA.'; $msgType = 'danger';
            }
        }
        // ── Sesiones abiertas ──
        if ($accion === 'cerrar_sesion') {
            // El uid va dentro de sesionCerrarPorId, así que nadie puede
            // cerrar la sesión de otra persona mandando un id ajeno.
            $cerrada = sesionCerrarPorId($pdo, $uid, (int) ($_POST['sesion_id'] ?? 0));
            if ($cerrada) {
                registrarAuditoria($pdo, 'usuarios', $uid, 'cerrar_sesion', 'Cerró una de sus sesiones abiertas');
                $msg = 'Sesión cerrada. En ese navegador habrá que entrar de nuevo.'; $msgType = 'success';
            } else {
                $msg = 'Esa sesión ya no estaba abierta.'; $msgType = 'warning';
            }

        } elseif ($accion === 'cerrar_otras') {
            $n = sesionCerrarTodas($pdo, $uid, 'manual', true);   // true = deja la actual
            registrarAuditoria($pdo, 'usuarios', $uid, 'cerrar_sesiones', "Cerró $n sesión(es) suyas en otros dispositivos");
            $msg = $n > 0
                ? "Se cerraron $n sesión(es). La de este navegador sigue abierta."
                : 'No había otras sesiones abiertas.';
            $msgType = $n > 0 ? 'success' : 'warning';

        // ── Solo administrador: cerrar la sesión de OTRA persona ──
        // Se comprueba AQUÍ, en el servidor: que el botón no se dibuje para la recepción no protege nada, y
        // un POST armado a mano llegaría igual. Cierra con motivo «admin», nunca la sesión propia, y queda
        // auditado con quién fue. No pide el código del 2FA: no entrega ningún dato.
        } elseif ($accion === 'cerrar_sesion_ajena') {
            if (!isAdmin()) {
                $msg = 'Solo un administrador puede cerrar la sesión de otra persona.'; $msgType = 'danger';
            } else {
                $r = sesionCerrarAjena($pdo, (int) ($_POST['sesion_id'] ?? 0));
                if ($r['ok']) {
                    registrarAuditoria($pdo, 'usuarios', $r['usuario_id'], 'cerrar_sesion',
                        'Cerró la sesión de ' . $r['nombre'] . ' (' . $r['dispositivo'] . ', IP ' . $r['ip'] . ')');
                    $msg = 'Se cerró la sesión de ' . $r['nombre'] . '. En ese dispositivo tendrá que entrar de nuevo.'; $msgType = 'success';
                } elseif ($r['propia']) {
                    $msg = 'Esa es la sesión de este navegador: para cerrarla usa «Cerrar sesión» del menú.'; $msgType = 'warning';
                } else {
                    $msg = 'Esa sesión ya no estaba abierta.'; $msgType = 'warning';
                }
            }

        // ── Equipos de confianza ──
        // Quitar equipos NO pide código: reducir la confianza nunca es peligroso.
        } elseif ($accion === 'quitar_equipo') {
            // El uid va dentro de equipoQuitar, así que nadie puede quitar el
            // equipo de otra persona mandando un id ajeno.
            if (equipoQuitar($pdo, $uid, (int) ($_POST['equipo_id'] ?? 0))) {
                registrarAuditoria($pdo, 'usuarios', $uid, 'quitar_equipo', 'Quitó un equipo de confianza');
                $msg = 'Equipo quitado. Ahí volverá a pedirse el código al ingresar.'; $msgType = 'success';
            } else {
                $msg = 'Ese equipo ya no estaba recordado.'; $msgType = 'warning';
            }

        } elseif ($accion === 'quitar_equipos') {
            $n = equiposRevocarTodos($pdo, $uid);
            registrarAuditoria($pdo, 'usuarios', $uid, 'quitar_equipos', "Quitó $n equipo(s) de confianza");
            $msg = $n > 0
                ? "Se quitaron $n equipo(s). En todos volverá a pedirse el código al ingresar."
                : 'No había equipos recordados.';
            $msgType = $n > 0 ? 'success' : 'warning';
        }

        $estado = cargarEstado2FA($pdo, $uid);   // refrescar tras los cambios
    }
}

// Las sesiones que aparecen en una alerta de la auditoría llevan la marca «sospechosa». Es un aviso: si algo
// falla al calcularlo, la pantalla se ve igual, sin marcas.
$sospechosas = ['ids' => [], 'hashes' => []];
try { $sospechosas = auditoriaSesionesSospechosas(auditoriaAlertas($pdo)); }
catch (Throwable $e) { error_log('Seguridad: no se pudieron calcular las sesiones sospechosas: ' . $e->getMessage()); }

// Sesiones activas de este usuario, para la tarjeta de abajo.
$sesionesAbiertas = sesionesDeUsuario($pdo, $uid, $sospechosas);

// Su historial de conexiones: SOLO lo suyo. `$_GET` no puede pedir el de otro: el filtro de usuario va fijo.
$historial = auditoriaHistorialConexiones($pdo, $_GET, $uid, 40)['filas'];

// Solo para el administrador: las sesiones y las conexiones de todo el personal.
$esAdmin = isAdmin();
$sesionesPersonal = $esAdmin ? sesionesDelPersonal($pdo, $sospechosas) : [];
$histPersonal = $esAdmin ? auditoriaHistorialConexiones($pdo, $_GET, null, 100) : ['filas' => [], 'filtros' => []];
$usuariosAud = $esAdmin ? auditoriaUsuarios($pdo) : [];

// El historial de SESIONES (las que ya terminaron). Cada quien ve las suyas, con el usuario fijo desde el servidor
// (un `?s_usuario=` ajeno no le da nada); el administrador ve las de todos y puede filtrar por persona y por cómo terminaron.
$sesHistorial = sesionesHistorial($pdo, $uid, ['dias' => 30, 'limite' => 40], $sospechosas);
$sesHistPersonal = $esAdmin ? sesionesHistorial($pdo, (int) ($_GET['s_usuario'] ?? 0), ['dias' => 30, 'limite' => 100, 'estado' => (string) ($_GET['s_estado'] ?? '')], $sospechosas) : [];
$usuariosSes = $esAdmin ? sesionesUsuarios($pdo) : [];

/** El icono de Font Awesome de cada tipo de dispositivo. */
function secIcono(string $tipo): string {
    return ['Escritorio' => 'display', 'Móvil' => 'mobile-screen', 'Tableta' => 'tablet-screen-button', 'Robot' => 'robot'][$tipo] ?? 'circle-question';
}
/** «hace 5 min», «hace 3 h», «hace 2 días». */
function secHace(string $fecha): string {
    $s = max(0, time() - (int) strtotime($fecha));
    if ($s < 90) return 'hace un momento';
    if ($s < 3600 * 1.5) return 'hace ' . (int) round($s / 60) . ' min';
    if ($s < 86400 * 1.5) return 'hace ' . (int) round($s / 3600) . ' h';
    return 'hace ' . (int) round($s / 86400) . ' días';
}
/** La clase de color y el icono de cada resultado de una conexión. */
function secResultado(string $clave): array {
    return ['exito' => ['chip-ok', 'right-to-bracket'], 'salida' => ['chip-neutro', 'right-from-bracket'],
            'bloqueado' => ['chip-aviso', 'ban'], 'clave' => ['chip-mal', 'triangle-exclamation'],
            'desactivada' => ['chip-mal', 'user-slash'], '2fa' => ['chip-mal', 'mobile-screen'],
            'correo' => ['chip-mal', 'circle-question']][$clave] ?? ['chip-neutro', 'circle'];
}
/**
 * Cómo se llama el dispositivo en pantalla: SOLO su tipo. El navegador, su versión y el sistema no se muestran
 * (decisión de Andrés: no hacen falta); siguen en la auditoría. Así el texto del agente de usuario, que lo escribe
 * quien hace la petición, tampoco llega a esta pantalla.
 */
function secTipo(array $d): string {
    $t = (string) ($d['tipo'] ?? '');
    return ['Robot' => 'Programa', 'Desconocido' => 'Dispositivo desconocido'][$t] ?? $t;
}
/** El color de cada final de una sesión: aviso si la sacó un administrador, rojo si fue el sistema por seguridad. */
function secClaseEstado(string $clave): string {
    return ['cerrada_admin' => 'chip-aviso', 'cerrada_seguridad' => 'chip-mal'][$clave] ?? 'chip-neutro';
}
/** Cuándo terminó una sesión, en palabras: una que venció dice desde cuándo no tuvo actividad; las demás, cuándo se cerraron. */
function secFin(array $s): string {
    $f = static fn($x) => date('d/m/Y H:i', strtotime((string) $x));
    if ($s['estado']['clave'] === 'expirada') return 'sin actividad desde el ' . $f($s['ultima_actividad']);
    return 'cerrada el ' . $f($s['cerrada_en'] ?: $s['ultima_actividad']);
}
/** Los avisos de una sesión: este dispositivo, nuevo, sospechosa. Todo escapado aquí, una sola vez. */
function secMarcas(array $s): string {
    $h = '';
    if (!empty($s['es_actual']))  $h .= '<span class="aqui">este dispositivo</span>';
    if (!empty($s['nuevo']))      $h .= '<span class="chip chip-nuevo">NUEVO</span>';
    if (!empty($s['sospechosa'])) $h .= '<span class="chip chip-sosp">SOSPECHOSA</span>';
    return $h;
}

// Equipos donde se marcó «Recordar este equipo». Solo tiene sentido con 2FA.
$equiposConfiables = $estado['twofa_enabled'] ? equiposDeUsuario($pdo, $uid) : [];

$uriOtp = $secretSetup ? totpUri($secretSetup, $email, 'Casa Bosque') : '';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
.sec-wrap { padding-bottom: 90px; max-width: 760px; }
/* Marcas de una sesión y resultado de una conexión (4.1.0). */
.chip{display:inline-block;margin-left:7px;padding:2px 8px;border-radius:999px;font-size:.66rem;font-weight:800;letter-spacing:.03em;vertical-align:middle;}
.chip-nuevo{background:#fff3e8;color:#9A5208;border:1px solid rgba(249,115,22,.3);}
.chip-sosp{background:#fdeded;color:#b91c1c;border:1px solid rgba(239,68,68,.3);}
.chip-ok{background:#e6f7ec;color:#1F7A4D;}
.chip-mal{background:#fdeded;color:#b91c1c;}
.chip-aviso{background:#fff3e8;color:#9A5208;}
.chip-neutro{background:#f1eadf;color:#6e6054;}
.sec-ses .quien{font-size:.74rem;font-weight:700;color:var(--cb-primary,#6B4420);}
.sec-hist{display:flex;gap:12px;align-items:flex-start;padding:11px 0;border-bottom:1px solid #eef2ed;}
.sec-hist:last-of-type{border-bottom:none;}
.sec-hist .ic{flex:0 0 34px;height:34px;display:grid;place-items:center;border-radius:10px;background:#f1f6f0;color:var(--cb-primary,#6B4420);}
.sec-hist .d{flex:1;min-width:0;}
.sec-hist .t{font-size:.84rem;font-weight:700;color:#2A1709;overflow-wrap:anywhere;}
.sec-hist .s{font-size:.76rem;color:var(--cb-muted,#9A8778);line-height:1.55;overflow-wrap:anywhere;}
.sec-hist .chip{margin-left:0;margin-right:6px;}
.sec-filtros{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 12px;}
.sec-filtros select,.sec-filtros input{flex:1;min-width:120px;border:1px solid var(--cb-border,#EDE3D2);border-radius:10px;padding:9px 11px;font-family:inherit;font-size:.82rem;background:#fff;}
.sec-card { background:#fff; border:1px solid var(--cb-border,#EDE3D2); border-radius:16px; padding:22px; margin-bottom:16px; box-shadow:0 4px 12px rgba(42,23,9,.04); }
.sec-msg { display:flex; gap:10px; align-items:center; padding:12px 15px; border-radius:12px; margin-bottom:16px; font-size:.9rem; }
.sec-msg.success{background:#e6f7f2;color:#047857;border:1px solid rgba(31,122,77,.25);}
.sec-msg.danger{background:#fdeded;color:#b91c1c;border:1px solid rgba(239,68,68,.25);}
.sec-msg.warning{background:#fff3e8;color:#b45309;border:1px solid rgba(249,115,22,.25);}
.sec-badge{display:inline-flex;align-items:center;gap:7px;font-size:.78rem;font-weight:700;padding:6px 12px;border-radius:999px;}
.sec-badge.on{background:#e6f7ec;color:#1F7A4D;border:1px solid rgba(31,122,77,.25);}
.sec-badge.off{background:#fff3e8;color:#b45309;border:1px solid rgba(249,115,22,.25);}
.sec-ses{display:flex;gap:12px;align-items:flex-start;padding:13px 0;border-bottom:1px solid #eef2ed;}
.sec-ses:last-of-type{border-bottom:none;}
.sec-ses .ic{flex:0 0 36px;height:36px;display:grid;place-items:center;border-radius:10px;background:#f1f6f0;color:var(--cb-primary,#6B4420);}
.sec-ses .d{flex:1;min-width:0;}
.sec-ses .t{font-size:.88rem;font-weight:700;color:#2A1709;}
.sec-ses .s{font-size:.78rem;color:var(--cb-muted,#9A8778);line-height:1.6;word-break:break-word;}
.sec-ses .aqui{display:inline-block;margin-left:7px;padding:2px 8px;border-radius:999px;background:#e6f7ec;color:#1F7A4D;font-size:.68rem;font-weight:700;vertical-align:middle;}
.sec-btn{display:inline-flex;align-items:center;gap:8px;border:none;border-radius:11px;padding:11px 16px;font-family:inherit;font-size:.88rem;font-weight:700;cursor:pointer;}
.sec-btn.mini{padding:7px 12px;font-size:.78rem;border-radius:9px;background:#fdeded;color:#b91c1c;border:1px solid rgba(239,68,68,.25);}
.sec-btn.mini:hover{background:#fbdcdc;}
.sec-btn.primary{background:linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728));color:#fff;}
.sec-btn.danger{background:#fff;border:1px solid rgba(239,68,68,.3);color:#b91c1c;}
.sec-input{border:1px solid var(--cb-border,#EDE3D2);border-radius:10px;padding:11px 13px;font-family:inherit;font-size:1.1rem;text-align:center;letter-spacing:4px;font-weight:700;width:150px;}
.sec-secret{font-family:monospace;background:#f3f6f1;border:1px dashed var(--cb-border,#EDE3D2);border-radius:8px;padding:8px 12px;font-size:.95rem;letter-spacing:2px;word-break:break-all;}
.sec-qr{display:flex;justify-content:center;padding:14px;background:#fff;border:1px solid var(--cb-border,#EDE3D2);border-radius:12px;width:max-content;margin:0 auto;}
.sec-codes{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:12px 0;}
.sec-codes span{font-family:monospace;background:#241607;color:#E8BA64;text-align:center;padding:9px;border-radius:8px;letter-spacing:2px;font-weight:700;}
.sec-step{font-size:.9rem;color:#453520;line-height:1.6;}

/* ── Tarjeta de confirmación ──
   Mismos nombres y medidas que en habitaciones y reservas, para que las
   confirmaciones se vean iguales en todo el sistema y no como el aviso
   del navegador. */
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.4);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:9999;opacity:0;pointer-events:none;transition:opacity .25s;padding:16px;}
.modal-overlay.active{opacity:1;pointer-events:auto;}
.confirm-card{background:#fff;width:100%;max-width:420px;border-radius:18px;box-shadow:0 20px 40px rgba(0,0,0,.18);overflow:hidden;transform:scale(.92);transition:transform .25s cubic-bezier(.34,1.56,.64,1);}
.modal-overlay.active .confirm-card{transform:scale(1);}
.confirm-header{padding:20px 20px 8px;display:flex;align-items:center;gap:12px;}
.confirm-title{font-size:1.05rem;font-weight:700;color:var(--cb-forest,#3E2412);margin:0;}
.confirm-body{padding:0 20px 16px;color:#475569;font-size:.86rem;line-height:1.5;}
.confirm-actions{padding:14px 20px 18px;display:flex;justify-content:flex-end;gap:10px;}
.icon-container-danger{width:42px;height:42px;background:rgba(239,68,68,.1);color:var(--danger,#ef4444);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.icon-container-warn{width:42px;height:42px;background:rgba(245,158,11,.12);color:var(--warning,#f59e0b);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.btn-x{padding:11px 16px;border-radius:11px;border:1px solid var(--cb-border,#EDE3D2);background:#fff;color:#475569;font-weight:600;cursor:pointer;font-family:inherit;}
.btn-d{padding:11px 16px;border-radius:11px;border:none;background:var(--danger,#ef4444);color:#fff;font-weight:700;cursor:pointer;font-family:inherit;}
.btn-w{padding:11px 16px;border-radius:11px;border:none;background:var(--warning,#f59e0b);color:#fff;font-weight:700;cursor:pointer;font-family:inherit;}
</style>

<div class="sec-wrap">

    <?php if ($forzado && !$estado['twofa_enabled']): ?>
        <div class="sec-msg warning fijo"><i class="fas fa-triangle-exclamation"></i> Como administrador, debes activar la verificación en dos pasos para poder continuar usando el sistema.</div>
    <?php endif; ?>

    <?php if ($msg): ?>
        <div class="sec-msg <?= $msgType ?>"><i class="fas fa-<?= $msgType==='success'?'circle-check':($msgType==='warning'?'triangle-exclamation':'circle-xmark') ?>"></i> <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="sec-card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <h3 style="margin:0;font-size:1.05rem;"><i class="fas fa-shield-halved"></i> Verificación en dos pasos (2FA)</h3>
            <?php if ($estado['twofa_enabled']): ?>
                <span class="sec-badge on"><i class="fas fa-circle-check"></i> Activo</span>
            <?php else: ?>
                <span class="sec-badge off"><i class="fas fa-circle-exclamation"></i> Inactivo</span>
            <?php endif; ?>
        </div>
        <p style="font-size:.88rem;color:var(--cb-muted,#9A8778);margin:10px 0 0;">
            Añade una capa extra de seguridad: además de tu contraseña, pedirá un código temporal
            de una app como <b>Google Authenticator</b>, <b>Authy</b> o <b>Microsoft Authenticator</b>.
        </p>
    </div>

    <?php // Mostrar códigos de respaldo recién generados (una sola vez) ?>
    <?php if ($codigosNuevos): ?>
        <div class="sec-card" style="border-color:rgba(31,122,77,.4);">
            <h3 style="margin:0 0 6px;font-size:1rem;color:#1F7A4D;"><i class="fas fa-key"></i> Tus códigos de respaldo</h3>
            <p style="font-size:.85rem;color:#b45309;"><i class="fas fa-triangle-exclamation"></i> Guárdalos ahora. No se volverán a mostrar. Cada uno sirve una sola vez si pierdes tu teléfono.</p>
            <div class="sec-codes">
                <?php foreach ($codigosNuevos as $c): ?><span><?= htmlspecialchars($c) ?></span><?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$estado['twofa_enabled'] && !$mostrarQR): ?>
        <!-- Paso 0: activar -->
        <div class="sec-card">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="accion" value="iniciar">
                <button type="submit" class="sec-btn primary"><i class="fas fa-plus"></i> Activar 2FA</button>
            </form>
        </div>

    <?php elseif ($mostrarQR && $uriOtp): ?>
        <!-- Paso 1 y 2: escanear y confirmar -->
        <div class="sec-card">
            <p class="sec-step"><b>1.</b> Escanea este código QR con tu app de autenticación:</p>
            <div class="sec-qr"><div id="qr"></div></div>
            <p class="sec-step" style="margin-top:14px;"><b>¿No puedes escanear?</b> Ingresa esta clave manualmente:</p>
            <div class="sec-secret"><?= htmlspecialchars($secretSetup) ?></div>

            <form method="POST" style="margin-top:16px;">
                <?= csrfField() ?>
                <input type="hidden" name="accion" value="confirmar">
                <p class="sec-step"><b>2.</b> Escribe el código de 6 dígitos que muestra la app:</p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                    <input type="text" name="codigo" inputmode="numeric" pattern="\d{6}" maxlength="6" required autofocus placeholder="000000" class="sec-input">
                    <button type="submit" class="sec-btn primary"><i class="fas fa-check"></i> Confirmar y activar</button>
                </div>
            </form>
        </div>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous"></script>
        <script>
            new QRCode(document.getElementById('qr'), { text: <?= json_encode($uriOtp) ?>, width: 190, height: 190, correctLevel: QRCode.CorrectLevel.M });
        </script>

    <?php elseif ($estado['twofa_enabled']): ?>
        <!-- Gestión: regenerar / desactivar -->
        <div class="sec-card">
            <h3 style="margin:0 0 10px;font-size:.95rem;"><i class="fas fa-key"></i> Códigos de respaldo</h3>
            <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <?= csrfField() ?>
                <input type="hidden" name="accion" value="regenerar">
                <input type="text" name="codigo" inputmode="numeric" pattern="\d{6}" maxlength="6" required placeholder="Código app" class="sec-input">
                <button type="submit" class="sec-btn primary"><i class="fas fa-rotate"></i> Regenerar códigos</button>
            </form>
        </div>
        <div class="sec-card">
            <h3 style="margin:0 0 10px;font-size:.95rem;color:#b91c1c;"><i class="fas fa-shield-xmark"></i> Desactivar 2FA</h3>
            <?php if (isAdmin()): ?>
                <p style="font-size:.82rem;color:var(--cb-muted,#9A8778);margin:0 0 10px;">Eres administrador: si lo desactivas, el sistema te pedirá activarlo de nuevo para continuar.</p>
            <?php endif; ?>
            <?php
            /* La confirmación se engancha al evento submit y no al clic del
               botón: así el navegador valida primero el código de 6 dígitos y
               la tarjeta solo aparece cuando el formulario ya está completo.
               Al revés, se confirmaría algo que después iba a ser rechazado. */
            ?>
            <form method="POST" id="form-desactivar-2fa" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <?= csrfField() ?>
                <input type="hidden" name="accion" value="desactivar">
                <input type="text" name="codigo" inputmode="numeric" pattern="\d{6}" maxlength="6" required placeholder="Código app" class="sec-input">
                <button type="submit" class="sec-btn danger"><i class="fas fa-power-off"></i> Desactivar</button>
            </form>
        </div>
    <?php endif; ?>

    <?php /* ── Sesiones abiertas ── */ ?>
    <?php if (sesionesDisponible($pdo)): ?>
    <div class="sec-card">
        <h3 style="margin:0 0 4px;font-size:.95rem;"><i class="fas fa-laptop"></i> Sesiones abiertas</h3>
        <p style="font-size:.82rem;color:var(--cb-muted,#9A8778);margin:0 0 12px;">
            Dónde tienes la sesión iniciada ahora mismo. Si ves algo que no
            reconoces, ciérralo y cambia tu contraseña.
        </p>

        <?php if (empty($sesionesAbiertas)): ?>
            <p style="font-size:.85rem;color:var(--cb-muted,#9A8778);margin:0;">
                No hay sesiones registradas.
            </p>
        <?php else: ?>
            <?php foreach ($sesionesAbiertas as $s): ?>
                <div class="sec-ses">
                    <div class="ic"><i class="fas fa-<?= $s['es_actual'] ? 'circle-check' : secIcono($s['dispositivo']['tipo']) ?>"></i></div>
                    <div class="d">
                        <div class="t">
                            <?= htmlspecialchars(secTipo($s['dispositivo'])) ?>
                            <?= secMarcas($s) ?>
                        </div>
                        <div class="s">
                            IP <?= htmlspecialchars((string) $s['ip_address']) ?> ·
                            <?= htmlspecialchars($s['estado']['etiqueta']) ?>, activo <?= htmlspecialchars(secHace($s['ultima_actividad'])) ?><br>
                            iniciada el <?= htmlspecialchars(date('d/m/Y H:i', strtotime($s['creada_en']))) ?>
                        </div>
                    </div>
                    <?php if (!$s['es_actual']): ?>
                        <form method="POST" style="flex:0 0 auto;">
                            <?= csrfField() ?>
                            <input type="hidden" name="accion" value="cerrar_sesion">
                            <input type="hidden" name="sesion_id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" class="sec-btn mini">Cerrar</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (count($sesionesAbiertas) > 1): ?>
                <form method="POST" id="form-cerrar-otras" style="margin-top:14px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="accion" value="cerrar_otras">
                    <button type="button" class="sec-btn primary" onclick="pedirCerrarOtras(<?= count($sesionesAbiertas) - 1 ?>)">
                        <i class="fas fa-power-off"></i> Cerrar las demás
                    </button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <p style="font-size:.78rem;color:var(--cb-muted,#9A8778);margin:14px 0 0;line-height:1.6;">
            Al cambiar tu contraseña se cierran automáticamente todas tus
            sesiones. Las inactivas se cierran solas a los 30 minutos.
        </p>
    </div>
    <?php endif; ?>

    <?php /* ── Historial de sesiones: las que ya terminaron, SOLO las de esta cuenta ── */ ?>
    <?php if (sesionesDisponible($pdo)): ?>
    <div class="sec-card" id="historial-sesiones">
        <h3 style="margin:0 0 4px;font-size:.95rem;"><i class="fas fa-layer-group"></i> Historial de sesiones</h3>
        <p style="font-size:.82rem;color:var(--cb-muted,#9A8778);margin:0 0 10px;">
            Las sesiones de <strong>tu cuenta</strong> de los últimos 30 días que ya terminaron, y por qué: vencieron,
            las cerraste tú, las cerró un administrador o el sistema por seguridad.
        </p>
        <?php if (empty($sesHistorial)): ?>
            <p style="font-size:.85rem;color:var(--cb-muted,#9A8778);margin:0;">Todavía no hay sesiones terminadas.</p>
        <?php else: foreach ($sesHistorial as $s): ?>
            <div class="sec-hist">
                <div class="ic"><i class="fas fa-<?= secIcono($s['dispositivo']['tipo']) ?>"></i></div>
                <div class="d">
                    <div class="t"><span class="chip <?= secClaseEstado($s['estado']['clave']) ?>"><?= htmlspecialchars($s['estado']['etiqueta']) ?></span><?= htmlspecialchars(secTipo($s['dispositivo'])) ?></div>
                    <div class="s">
                        IP <?= htmlspecialchars((string) $s['ip_address']) ?><br>
                        iniciada el <?= htmlspecialchars(date('d/m/Y H:i', strtotime($s['creada_en']))) ?> · <?= htmlspecialchars(secFin($s)) ?>
                    </div>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
    <?php endif; ?>

    <?php /* ── Equipos de confianza ── */ ?>
    <?php if ($estado['twofa_enabled'] && equiposDisponible($pdo)): ?>
    <div class="sec-card">
        <h3 style="margin:0 0 4px;font-size:.95rem;"><i class="fas fa-desktop"></i> Equipos de confianza</h3>
        <p style="font-size:.82rem;color:var(--cb-muted,#9A8778);margin:0 0 12px;">
            Equipos donde marcaste «Recordar este equipo» al escribir tu código:
            en ellos entras con la contraseña sola durante <?= (int) EQUIPO_DIAS ?> días.
            Si ves uno que no reconoces, quítalo y cambia tu contraseña.
        </p>

        <?php if (empty($equiposConfiables)): ?>
            <p style="font-size:.85rem;color:var(--cb-muted,#9A8778);margin:0;">
                No hay equipos recordados. Para recordar uno, marca la casilla
                cuando escribas tu código al ingresar.
            </p>
        <?php else: ?>
            <?php foreach ($equiposConfiables as $eq): ?>
                <div class="sec-ses">
                    <div class="ic"><i class="fas fa-<?= $eq['es_actual'] ? 'circle-check' : 'shield-halved' ?>"></i></div>
                    <div class="d">
                        <div class="t">
                            <?= htmlspecialchars(sesionNavegador($eq['agente'])) ?>
                            <?php if ($eq['es_actual']): ?><span class="aqui">este equipo</span><?php endif; ?>
                        </div>
                        <div class="s">
                            IP <?= htmlspecialchars((string) $eq['ip_address']) ?> ·
                            recordado el <?= htmlspecialchars(date('d/m/Y H:i', strtotime($eq['creado_en']))) ?><br>
                            vence el <?= htmlspecialchars(date('d/m/Y', strtotime($eq['expira_en']))) ?>
                            <?php if ($eq['ultimo_uso']): ?>
                                · último uso <?= htmlspecialchars(date('d/m/Y H:i', strtotime($eq['ultimo_uso']))) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <form method="POST" style="flex:0 0 auto;">
                        <?= csrfField() ?>
                        <input type="hidden" name="accion" value="quitar_equipo">
                        <input type="hidden" name="equipo_id" value="<?= (int) $eq['id'] ?>">
                        <button type="submit" class="sec-btn mini">Quitar</button>
                    </form>
                </div>
            <?php endforeach; ?>

            <?php if (count($equiposConfiables) > 1): ?>
                <form method="POST" id="form-quitar-equipos" style="margin-top:14px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="accion" value="quitar_equipos">
                    <button type="button" class="sec-btn primary" onclick="pedirQuitarEquipos(<?= count($equiposConfiables) ?>)">
                        <i class="fas fa-trash"></i> Quitar todos
                    </button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <p style="font-size:.78rem;color:var(--cb-muted,#9A8778);margin:14px 0 0;line-height:1.6;">
            Se quitan solos si cambias tu contraseña o tu verificación en dos
            pasos.<?php if (isAdmin()): ?> Aun en un equipo recordado, el
            <strong>respaldo</strong>, <strong>reiniciar datos</strong> y
            <strong>usuarios</strong> piden el código una vez por sesión.<?php endif; ?>
        </p>
    </div>
    <?php endif; ?>

    <?php /* ── Historial de conexiones: SOLO lo de esta cuenta ── */ ?>
    <div class="sec-card" id="historial">
        <h3 style="margin:0 0 4px;font-size:.95rem;"><i class="fas fa-clock-rotate-left"></i> Historial de conexiones</h3>
        <p style="font-size:.82rem;color:var(--cb-muted,#9A8778);margin:0 0 10px;">
            Los accesos a <strong>tu cuenta</strong> de los últimos 30 días, con éxito o sin él. Si ves un intento que
            no hiciste, pídele al administrador que restablezca tu contraseña y cierra las demás sesiones.
        </p>
        <?php if (empty($historial)): ?>
            <p style="font-size:.85rem;color:var(--cb-muted,#9A8778);margin:0;">Todavía no hay conexiones registradas.</p>
        <?php else: foreach ($historial as $h): [$claseR, $iconoR] = secResultado($h['resultado'][0]); ?>
            <div class="sec-hist">
                <div class="ic"><i class="fas fa-<?= $iconoR ?>"></i></div>
                <div class="d">
                    <div class="t"><span class="chip <?= $claseR ?>"><?= htmlspecialchars($h['resultado'][1]) ?></span><?= htmlspecialchars($h['fecha_vista']) ?></div>
                    <div class="s">
                        <?= htmlspecialchars(secTipo($h['dispositivo'])) ?>
                        · IP <?= htmlspecialchars($h['ip']) ?>
                    </div>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <?php if ($esAdmin): ?>
    <?php /* ── Solo administrador: las sesiones de TODO el personal ── */ ?>
    <div class="sec-card" id="personal">
        <h3 style="margin:0 0 4px;font-size:.95rem;"><i class="fas fa-users"></i> Sesiones del personal</h3>
        <p style="font-size:.82rem;color:var(--cb-muted,#9A8778);margin:0 0 12px;">
            Quién tiene la sesión abierta ahora, en qué dispositivo y desde qué IP. Cerrar la de otra persona la saca
            de inmediato y queda en la auditoría.
        </p>
        <?php if (empty($sesionesPersonal)): ?>
            <p style="font-size:.85rem;color:var(--cb-muted,#9A8778);margin:0;">No hay sesiones activas.</p>
        <?php else: ?>
            <form method="POST" id="form-cerrar-ajena">
                <?= csrfField() ?>
                <input type="hidden" name="accion" value="cerrar_sesion_ajena">
                <input type="hidden" name="sesion_id" id="ajena-sesion-id" value="">
            </form>
            <?php foreach ($sesionesPersonal as $s): ?>
                <div class="sec-ses">
                    <div class="ic"><i class="fas fa-<?= secIcono($s['dispositivo']['tipo']) ?>"></i></div>
                    <div class="d">
                        <div class="t">
                            <?= htmlspecialchars($s['nombre']) ?> <span class="quien">(<?= $s['rol'] === 'admin' ? 'administrador' : 'recepción' ?>)</span>
                            <?= secMarcas($s) ?>
                        </div>
                        <div class="s">
                            <?= htmlspecialchars(secTipo($s['dispositivo'])) ?><br>
                            IP <?= htmlspecialchars((string) $s['ip_address']) ?> · activo <?= htmlspecialchars(secHace($s['ultima_actividad'])) ?>
                        </div>
                    </div>
                    <?php if (!$s['es_actual']): ?>
                        <button type="button" class="sec-btn mini" data-ajena="<?= (int) $s['id'] ?>"
                                data-quien="<?= htmlspecialchars($s['nombre'], ENT_QUOTES) ?>"
                                data-disp="<?= htmlspecialchars(secTipo($s['dispositivo']), ENT_QUOTES) ?>">Cerrar</button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php /* ── Solo administrador: el historial de SESIONES de TODO el personal ── */ ?>
    <div class="sec-card" id="historial-sesiones-personal">
        <h3 style="margin:0 0 4px;font-size:.95rem;"><i class="fas fa-layer-group"></i> Historial de sesiones del personal</h3>
        <p style="font-size:.82rem;color:var(--cb-muted,#9A8778);margin:0;">
            Las sesiones de todo el personal de los últimos 30 días que ya terminaron, con el motivo.
        </p>
        <form method="GET" class="sec-filtros" action="#historial-sesiones-personal">
            <select name="s_usuario" aria-label="Usuario">
                <option value="">Todos los usuarios</option>
                <?php foreach ($usuariosSes as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= (int) ($_GET['s_usuario'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="s_estado" aria-label="Cómo terminó">
                <option value="">Todos los finales</option>
                <?php foreach (SESION_ESTADOS_FINALES as $k => $e): ?>
                    <option value="<?= htmlspecialchars($k) ?>" <?= (($_GET['s_estado'] ?? '') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="sec-btn primary" style="flex:0 0 auto;"><i class="fas fa-filter"></i> Filtrar</button>
        </form>
        <?php if (empty($sesHistPersonal)): ?>
            <p style="font-size:.85rem;color:var(--cb-muted,#9A8778);margin:0;">No hay sesiones terminadas para esos filtros.</p>
        <?php else: foreach ($sesHistPersonal as $s): ?>
            <div class="sec-hist">
                <div class="ic"><i class="fas fa-<?= secIcono($s['dispositivo']['tipo']) ?>"></i></div>
                <div class="d">
                    <div class="t"><span class="chip <?= secClaseEstado($s['estado']['clave']) ?>"><?= htmlspecialchars($s['estado']['etiqueta']) ?></span><?= htmlspecialchars($s['nombre']) ?> <span class="quien">(<?= $s['rol'] === 'admin' ? 'administrador' : 'recepción' ?>)</span></div>
                    <div class="s">
                        <?= htmlspecialchars(secTipo($s['dispositivo'])) ?> · IP <?= htmlspecialchars((string) $s['ip_address']) ?><br>
                        iniciada el <?= htmlspecialchars(date('d/m/Y H:i', strtotime($s['creada_en']))) ?> · <?= htmlspecialchars(secFin($s)) ?>
                    </div>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <?php /* ── Solo administrador: el historial de conexiones de TODO el personal ── */ ?>
    <div class="sec-card" id="historial-personal">
        <h3 style="margin:0 0 4px;font-size:.95rem;"><i class="fas fa-clipboard-list"></i> Historial de conexiones del personal</h3>
        <p style="font-size:.82rem;color:var(--cb-muted,#9A8778);margin:0;">
            Incluye los intentos con un correo que no existe. El detalle completo y la exportación están en
            <a href="<?= BASE_URL ?>/modules/auditoria/index.php?modulo=<?= urlencode('Autenticación') ?>">Auditoría</a>.
        </p>
        <form method="GET" class="sec-filtros" action="#historial-personal">
            <select name="usuario" aria-label="Usuario">
                <option value="">Todos los usuarios</option>
                <?php foreach ($usuariosAud as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= (int) ($histPersonal['filtros']['usuario'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="resultado" aria-label="Resultado">
                <option value="">Todos los resultados</option>
                <?php foreach (['exito' => 'Éxito', 'clave' => 'Contraseña errónea', 'bloqueado' => 'Bloqueado', '2fa' => '2FA fallido', 'salida' => 'Cierre de sesión'] as $k => $e): ?>
                    <option value="<?= $k ?>" <?= (($_GET['resultado'] ?? '') === (string) $k) ? 'selected' : '' ?>><?= $e ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="desde" aria-label="Desde" value="<?= htmlspecialchars((string) ($histPersonal['filtros']['desde'] ?? '')) ?>">
            <input type="date" name="hasta" aria-label="Hasta" value="<?= htmlspecialchars((string) ($histPersonal['filtros']['hasta'] ?? '')) ?>">
            <button type="submit" class="sec-btn primary" style="flex:0 0 auto;"><i class="fas fa-filter"></i> Filtrar</button>
        </form>
        <?php if (empty($histPersonal['filas'])): ?>
            <p style="font-size:.85rem;color:var(--cb-muted,#9A8778);margin:0;">No hay conexiones para esos filtros.</p>
        <?php else: foreach ($histPersonal['filas'] as $h): [$claseR, $iconoR] = secResultado($h['resultado'][0]); ?>
            <div class="sec-hist">
                <div class="ic"><i class="fas fa-<?= $iconoR ?>"></i></div>
                <div class="d">
                    <div class="t"><span class="chip <?= $claseR ?>"><?= htmlspecialchars($h['resultado'][1]) ?></span><?= htmlspecialchars($h['fecha_vista']) ?></div>
                    <div class="s">
                        <?= $h['usuario_id'] !== null ? htmlspecialchars($h['usuario']) : '<em>sin usuario</em>' ?> ·
                        <?= htmlspecialchars(secTipo($h['dispositivo'])) ?> · IP <?= htmlspecialchars($h['ip']) ?>
                        <?php if ($h['usuario_id'] === null && $h['detalle'] !== ''): ?><br><?= htmlspecialchars($h['detalle']) ?><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
    <?php endif; ?>

</div>

<?php /* ── Tarjetas de confirmación, en vez del aviso del navegador ── */ ?>
<?php if ($estado['twofa_enabled']): ?>
<div id="desactivar-modal-overlay" class="modal-overlay">
    <div class="confirm-card">
        <div class="confirm-header">
            <div class="icon-container-danger"><i class="fas fa-shield-xmark"></i></div>
            <h4 class="confirm-title">¿Desactivar la verificación en dos pasos?</h4>
        </div>
        <div class="confirm-body">
            Tu cuenta quedará protegida <strong>solo por la contraseña</strong>. Quien
            la consiga podrá entrar sin necesitar tu teléfono.<br><br>
            Tus códigos de respaldo se borran, y por seguridad se cerrarán tus
            sesiones en otros dispositivos.
            <?php if (isAdmin()): ?>
                <br><br><span style="color:var(--danger,#ef4444);font-weight:600;">Eres
                administrador: el sistema te pedirá activarlo de nuevo para continuar.</span>
            <?php endif; ?>
        </div>
        <div class="confirm-actions">
            <button type="button" class="btn-x" onclick="cerrarDesactivar()">Mejor no</button>
            <button type="button" class="btn-d" onclick="confirmarDesactivar()">Sí, desactivar</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (sesionesDisponible($pdo) && count($sesionesAbiertas) > 1): ?>
<div id="otras-modal-overlay" class="modal-overlay">
    <div class="confirm-card">
        <div class="confirm-header">
            <div class="icon-container-warn"><i class="fas fa-power-off"></i></div>
            <h4 class="confirm-title">¿Cerrar las demás sesiones?</h4>
        </div>
        <div class="confirm-body">
            Se cerrarán <strong id="otras-cuenta-text"></strong>. En esos dispositivos
            habrá que iniciar sesión de nuevo.<br><br>
            <strong>La sesión de este navegador no se cierra</strong>: sigues trabajando normal.
        </div>
        <div class="confirm-actions">
            <button type="button" class="btn-x" onclick="cerrarOtrasModal()">Cancelar</button>
            <button type="button" class="btn-w" onclick="confirmarCerrarOtras()">Cerrar las demás</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($esAdmin && !empty($sesionesPersonal)): ?>
<div id="ajena-modal-overlay" class="modal-overlay">
    <div class="confirm-card">
        <div class="confirm-header">
            <div class="icon-container-warn"><i class="fas fa-power-off"></i></div>
            <h4 class="confirm-title">¿Cerrar la sesión de otra persona?</h4>
        </div>
        <div class="confirm-body">
            Vas a cerrar la sesión de <strong id="ajena-quien"></strong> en su dispositivo <strong id="ajena-disp"></strong>.
            En ese dispositivo tendrá que entrar de nuevo, y puede perder lo que estaba haciendo.<br><br>
            <strong>Queda registrado en la auditoría</strong> que fuiste tú.
        </div>
        <div class="confirm-actions">
            <button type="button" class="btn-x" onclick="cerrarAjenaModal()">Cancelar</button>
            <button type="button" class="btn-w" onclick="confirmarCerrarAjena()">Cerrar su sesión</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($estado['twofa_enabled'] && equiposDisponible($pdo) && count($equiposConfiables) > 1): ?>
<div id="equipos-modal-overlay" class="modal-overlay">
    <div class="confirm-card">
        <div class="confirm-header">
            <div class="icon-container-warn"><i class="fas fa-trash"></i></div>
            <h4 class="confirm-title">¿Quitar todos los equipos de confianza?</h4>
        </div>
        <div class="confirm-body">
            Se quitarán <strong id="equipos-cuenta-text"></strong>. En todos, la próxima
            vez que ingreses, se pedirá el código de tu app.<br><br>
            <strong>Tus sesiones abiertas no se cierran</strong>: sigues trabajando normal.
        </div>
        <div class="confirm-actions">
            <button type="button" class="btn-x" onclick="cerrarEquiposModal()">Cancelar</button>
            <button type="button" class="btn-w" onclick="confirmarQuitarEquipos()">Quitar todos</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// ── Desactivar 2FA ──
// Se engancha al submit y no al clic: el navegador valida el código de 6
// dígitos primero, y la tarjeta solo sale si el formulario está completo.
(function () {
    const form = document.getElementById('form-desactivar-2fa');
    const ov   = document.getElementById('desactivar-modal-overlay');
    if (!form || !ov) return;

    form.addEventListener('submit', function (e) {
        if (form.dataset.confirmado === '1') return;   // ya se confirmó: que siga
        e.preventDefault();
        ov.classList.add('active');
    });
    window.cerrarDesactivar = function () { ov.classList.remove('active'); };
    window.confirmarDesactivar = function () {
        form.dataset.confirmado = '1';
        ov.classList.remove('active');
        form.submit();
    };
    ov.addEventListener('click', function (e) { if (e.target === ov) window.cerrarDesactivar(); });
})();

// ── Cerrar las demás sesiones ──
(function () {
    const form = document.getElementById('form-cerrar-otras');
    const ov   = document.getElementById('otras-modal-overlay');
    if (!form || !ov) return;

    window.pedirCerrarOtras = function (cuantas) {
        document.getElementById('otras-cuenta-text').textContent =
            cuantas === 1 ? '1 sesión' : cuantas + ' sesiones';
        ov.classList.add('active');
    };
    window.cerrarOtrasModal = function () { ov.classList.remove('active'); };
    window.confirmarCerrarOtras = function () { ov.classList.remove('active'); form.submit(); };
    ov.addEventListener('click', function (e) { if (e.target === ov) window.cerrarOtrasModal(); });
})();

<?php if ($esAdmin): /* este bloque ni se envía a quien no es administrador */ ?>
// ── Cerrar la sesión de otra persona (solo administrador) ──
// Lo que llega de la base (el nombre, el dispositivo) se escribe como TEXTO, nunca como HTML.
(function () {
    const form = document.getElementById('form-cerrar-ajena');
    const ov   = document.getElementById('ajena-modal-overlay');
    if (!form || !ov) return;

    document.addEventListener('click', function (e) {
        const b = e.target.closest('[data-ajena]');
        if (!b) return;
        document.getElementById('ajena-sesion-id').value = b.dataset.ajena;
        document.getElementById('ajena-quien').textContent = b.dataset.quien;
        document.getElementById('ajena-disp').textContent = b.dataset.disp;
        ov.classList.add('active');
    });
    window.cerrarAjenaModal = function () { ov.classList.remove('active'); };
    window.confirmarCerrarAjena = function () { ov.classList.remove('active'); form.submit(); };
    ov.addEventListener('click', function (e) { if (e.target === ov) window.cerrarAjenaModal(); });
})();
<?php endif; ?>

// ── Quitar todos los equipos de confianza ──
(function () {
    const form = document.getElementById('form-quitar-equipos');
    const ov   = document.getElementById('equipos-modal-overlay');
    if (!form || !ov) return;

    window.pedirQuitarEquipos = function (cuantos) {
        document.getElementById('equipos-cuenta-text').textContent =
            cuantos === 1 ? '1 equipo' : cuantos + ' equipos';
        ov.classList.add('active');
    };
    window.cerrarEquiposModal = function () { ov.classList.remove('active'); };
    window.confirmarQuitarEquipos = function () { ov.classList.remove('active'); form.submit(); };
    ov.addEventListener('click', function (e) { if (e.target === ov) window.cerrarEquiposModal(); });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
