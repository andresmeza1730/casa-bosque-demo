<?php
// ============================================================
// modules/usuarios/index.php
// Gestión de Usuarios — Casa Bosque (solo administrador)
// ============================================================
// Permite al admin crear, editar, activar/desactivar y resetear
// la contraseña de usuarios (recepcionistas y administradores).
// Compatible con el login: el algoritmo lo decide claveHash() (includes/claves.php).
// ------------------------------------------------------------

$pageTitle = 'Usuarios';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/validacion.php';
requireLogin();
requireAdmin();   // exclusivo del administrador
// Crear usuarios o cambiarles la contraseña es entregar las llaves del
// sistema: aunque el equipo esté recordado, aquí se pide el código.
// Ver includes/equipos.php.
require_once __DIR__ . '/../../includes/equipos.php';
require_once __DIR__ . '/../../includes/claves.php';    // claveHash(): el algoritmo se decide en un solo sitio
require_once __DIR__ . '/../../includes/sesiones.php';   // cerrar sesiones al cambiar rol o estado
exigirCodigoEnAccionCritica();

$pdo = getDB();
$msg = '';
$msgType = '';
$miId = (int)getCurrentUserId();

/** ¿Existe ese usuario? (4.3.2) */
function usuarioExiste(PDO $pdo, int $id): bool {
    $st = $pdo->prepare("select count(*) from usuarios where id = ?");
    $st->execute([$id]);
    return (int) $st->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $msg = 'Token de seguridad inválido. Intente de nuevo.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Crear usuario ──
        if ($action === 'crear') {
            $nombre   = trim($_POST['nombre'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $rol      = ($_POST['rol'] ?? 'recepcionista') === 'admin' ? 'admin' : 'recepcionista';

            $errorPass = validarPassword($password);
            if ($nombre === '' || $email === '' || $password === '') {
                $msg = 'Nombre, correo y contraseña son obligatorios.';
                $msgType = 'danger';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $msg = 'El correo no tiene un formato válido.';
                $msgType = 'danger';
            } elseif ($errorPass !== null) {
                $msg = $errorPass;
                $msgType = 'danger';
            } else {
                // Verificar que el correo no exista
                $check = $pdo->prepare("select id from usuarios where email = ?");
                $check->execute([$email]);
                if ($check->fetch()) {
                    $msg = 'Ya existe un usuario con ese correo.';
                    $msgType = 'danger';
                } else {
                    $hash = claveHash($password);
                    $stmt = $pdo->prepare("insert into usuarios (nombre, email, password_hash, rol, activo) values (?, ?, ?, ?, ?)");
                    $stmt->execute([$nombre, $email, $hash, $rol, 1]);
                    $nuevoId = $pdo->lastInsertId();
                    // El correo del empleado sí va entero, a diferencia del
                    // documento de un huésped (ver documentoOculto en
                    // cifrado.php): es el dato de la cuenta, no de un cliente,
                    // y sin él la auditoría no dice a quién se le dio acceso.
                    registrarAuditoria($pdo, 'usuarios', $nuevoId, 'crear', "Usuario creado: $nombre ($email) — rol: $rol",
                        null, auditoriaFila($pdo, 'usuarios', (int) $nuevoId));
                    $msg = "Usuario \"$nombre\" creado correctamente.";
                    $msgType = 'success';
                }
            }
        }

        // ── Editar datos (nombre, email, rol) ──
        // 4.3.2: el usuario tiene que EXISTIR. Con un id falso `editar`, `toggle_activo` y `reset_pass` decían «actualizado» o «restablecida»
        // sin haber tocado a nadie, y dejaban su fila en la auditoría.
        if ($action === 'editar') {
            $id     = (int)($_POST['usuario_id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $email  = trim($_POST['email'] ?? '');
            $rol    = ($_POST['rol'] ?? 'recepcionista') === 'admin' ? 'admin' : 'recepcionista';

            if ($id <= 0 || $nombre === '' || $email === '') {
                $msg = 'Datos incompletos para editar.';
                $msgType = 'danger';
            } elseif (!usuarioExiste($pdo, $id)) {
                $msg = 'Ese usuario no existe.';
                $msgType = 'danger';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $msg = 'El correo no tiene un formato válido.';
                $msgType = 'danger';
            } else {
                // El correo no puede chocar con OTRO usuario
                $check = $pdo->prepare("select id from usuarios where email = ? and id <> ?");
                $check->execute([$email, $id]);
                if ($check->fetch()) {
                    $msg = 'Otro usuario ya usa ese correo.';
                    $msgType = 'danger';
                } else {
                    // Evitar que el admin se quite a sí mismo el rol de admin (se dejaría sin acceso)
                    if ($id === $miId && $rol !== 'admin') {
                        $msg = 'No puedes quitarte a ti mismo el rol de administrador.';
                        $msgType = 'danger';
                    } else {
                        // El rol de antes, para saber si de verdad cambió.
                        $stRolAnt = $pdo->prepare("select rol from usuarios where id = ?");
                        $stRolAnt->execute([$id]);
                        $rolAnterior = (string) $stRolAnt->fetchColumn();
                        $antesUsuario = auditoriaFila($pdo, 'usuarios', $id);   // solo rol y activo: la lista blanca

                        $stmt = $pdo->prepare("update usuarios set nombre = ?, email = ?, rol = ? where id = ?");
                        $stmt->execute([$nombre, $email, $rol, $id]);

                        // Un cambio de rol no puede esperar a que esa persona
                        // cierre su sesión: hasta entonces seguiría trabajando
                        // con los permisos de antes. auth.php también lo corrige
                        // en cada petición, pero aquí se cierra de una vez, que
                        // es lo que el administrador espera al cambiarlo.
                        $cerradas = 0;
                        if ($rolAnterior !== '' && $rolAnterior !== $rol) {
                            $cerradas = sesionCerrarTodas($pdo, $id, 'rol_cambiado');
                        }

                        registrarAuditoria($pdo, 'usuarios', $id, 'editar',
                            "Usuario actualizado: $nombre ($email) — rol: $rol"
                            . ($cerradas > 0 ? "; cambió de rol ($rolAnterior → $rol), sesiones cerradas: $cerradas" : ''),
                            $antesUsuario, auditoriaFila($pdo, 'usuarios', $id));
                        $msg = "Usuario actualizado correctamente."
                             . ($cerradas > 0 ? " Cambió de rol, así que se cerraron sus $cerradas sesión(es) abierta(s)." : '');
                        $msgType = 'success';
                    }
                }
            }
        }

        // ── Activar / Desactivar ──
        if ($action === 'toggle_activo') {
            $id = (int)($_POST['usuario_id'] ?? 0);
            if ($id === $miId) {
                $msg = 'No puedes desactivar tu propia cuenta.';
                $msgType = 'danger';
            } elseif (!usuarioExiste($pdo, $id)) {
                $msg = 'Ese usuario no existe.';
                $msgType = 'danger';
            } else {
                $antesUsuario = auditoriaFila($pdo, 'usuarios', $id);
                $stmt = $pdo->prepare("update usuarios set activo = case when activo = 1 then 0 else 1 end where id = ?");
                $stmt->execute([$id]);

                $stAct = $pdo->prepare("select activo from usuarios where id = ?");
                $stAct->execute([$id]);
                $quedoActivo = ((int) $stAct->fetchColumn()) === 1;

                // Desactivar una cuenta tiene que surtir efecto AHORA. Sin esto,
                // quien tuviera la sesión abierta seguía dentro hasta cerrarla o
                // hasta los 30 minutos de inactividad: justo lo contrario de lo
                // que se pide al desactivar a alguien que ya no debe entrar.
                // Esto además revoca sus equipos de confianza, porque
                // sesionCerrarTodas() lo hace para todo motivo que no sea
                // 'manual' (ver includes/sesiones.php).
                $cerradas = $quedoActivo ? 0 : sesionCerrarTodas($pdo, $id, 'desactivada');

                registrarAuditoria($pdo, 'usuarios', $id, 'editar',
                    'Estado de acceso cambiado: ' . ($quedoActivo ? 'activada' : 'desactivada')
                    . ($cerradas > 0 ? "; sesiones cerradas: $cerradas" : ''),
                    $antesUsuario, auditoriaFila($pdo, 'usuarios', $id));
                $msg = $quedoActivo
                    ? 'Usuario activado. Ya puede entrar al sistema.'
                    : 'Usuario desactivado. No podrá entrar'
                      . ($cerradas > 0 ? ", y se cerraron sus $cerradas sesión(es) abierta(s)." : '.');
                $msgType = 'success';
            }
        }

        // ── Resetear contraseña ──
        if ($action === 'reset_pass') {
            $id      = (int)($_POST['usuario_id'] ?? 0);
            $nueva   = $_POST['password'] ?? '';
            $errorPass = validarPassword($nueva);
            if ($id <= 0 || !usuarioExiste($pdo, $id)) {
                $msg = $id <= 0 ? 'Usuario inválido.' : 'Ese usuario no existe.';
                $msgType = 'danger';
            } elseif ($errorPass !== null) {
                $msg = $errorPass;
                $msgType = 'danger';
            } else {
                $hash = claveHash($nueva);
                $stmt = $pdo->prepare("update usuarios set password_hash = ? where id = ?");
                $stmt->execute([$hash, $id]);
                // Cerrar las sesiones abiertas de ese usuario: si se le
                // cambia la contraseña, no debe seguir dentro con la vieja.
                $cerradas = sesionCerrarTodas($pdo, $id, 'admin');
                registrarAuditoria($pdo, 'usuarios', $id, 'editar',
                    "Contraseña restablecida; sesiones cerradas: $cerradas");
                $msg = "Contraseña restablecida correctamente."
                     . ($cerradas > 0 ? " Se cerraron $cerradas sesión(es) que tenía abiertas." : '');
                $msgType = 'success';
            }
        }
    }
}

// Listado de usuarios
$usuarios = $pdo->query("select id, nombre, email, rol, activo from usuarios order by rol, nombre")->fetchAll();

// ── Si la creación falló, no se pierde lo escrito ──
// El formulario vive en una ventana modal, así que al recargar la página el
// error salía por detrás y la ventana se cerraba vacía: había que reescribirlo
// todo. Ahora se reabre con el nombre, el correo y el rol ya puestos.
// La contraseña NO se repuebla: esa se vuelve a escribir, pero es la única.
$reabrirCrear = ($_SERVER['REQUEST_METHOD'] === 'POST'
                 && ($_POST['action'] ?? '') === 'crear'
                 && $msgType === 'danger');
$formCrear = [
    'nombre' => $reabrirCrear ? trim($_POST['nombre'] ?? '') : '',
    'email'  => $reabrirCrear ? trim($_POST['email'] ?? '')  : '',
    'rol'    => ($reabrirCrear && ($_POST['rol'] ?? '') === 'admin') ? 'admin' : 'recepcionista',
];

require_once __DIR__ . '/../../includes/header.php';
?>
<style>
/* ============================================================
   Usuarios — Rediseño limpio (Casa Bosque)
   ============================================================ */
.us-wrap { padding-bottom: 90px; }

.us-msg { display: flex; align-items: center; gap: 11px; padding: 13px 15px; border-radius: 14px; margin-bottom: 16px; font-size: .9rem; font-weight: 500; }
.us-msg.success { background: #e6f7f2; color: #047857; border: 1px solid rgba(31,122,77,.25); }
.us-msg.danger  { background: #fdeded; color: #b91c1c; border: 1px solid rgba(239,68,68,.25); }

.us-head { display: flex; align-items: center; justify-content: space-between; margin: 4px 2px 14px; }
.us-head h3 { font-size: 1.05rem; font-weight: 800; color: var(--cb-forest,#3E2412); display: flex; align-items: center; gap: 8px; margin: 0; }
.us-new { display: inline-flex; align-items: center; gap: 7px; font-size: .82rem; font-weight: 700; color: #fff; background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); border: none; cursor: pointer; font-family: inherit; padding: 10px 14px; border-radius: 12px; box-shadow: 0 6px 14px rgba(107,68,32,.25); }
.us-new:active { transform: scale(.96); }

/* Tarjeta de usuario */
.us-card { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 16px; padding: 14px; margin-bottom: 11px; box-shadow: 0 4px 12px rgba(42,23,9,.04); }
.us-card.inactivo { opacity: .62; }
.us-top { display: flex; align-items: center; gap: 12px; }
.us-av { flex: none; width: 46px; height: 46px; border-radius: 13px; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: .95rem; }
.us-av.admin { background: linear-gradient(150deg,#6B4420,#9C6728); }
.us-av.recep { background: linear-gradient(150deg,#2563eb,#1e40af); }
.us-info { flex: 1; min-width: 0; }
.us-name { font-size: .95rem; font-weight: 700; color: var(--cb-forest,#3E2412); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.us-name .yo { font-size: .68rem; color: var(--cb-primary,#6B4420); font-weight: 700; margin-left: 4px; }
.us-email { font-size: .76rem; color: var(--cb-muted,#9A8778); margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.us-rol { flex: none; font-size: .64rem; font-weight: 700; padding: 4px 11px; border-radius: 99px; }
.us-rol.admin { background: rgba(107,68,32,.12); color: #1F7A4D; }
.us-rol.recep { background: rgba(59,130,246,.12); color: #1e40af; }
.us-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 13px; padding-top: 12px; border-top: 1px solid var(--gray-100,#F1EADF); }
.us-estado { font-size: .76rem; font-weight: 600; display: inline-flex; align-items: center; gap: 7px; color: var(--cb-muted,#9A8778); }
.us-dot { width: 9px; height: 9px; border-radius: 50%; }
.us-dot.on { background: #22c55e; box-shadow: 0 0 7px rgba(34,197,94,.5); }
.us-dot.off { background: #cbd5e1; }
.us-actions { display: flex; gap: 7px; }
.us-act { width: 36px; height: 36px; border-radius: 11px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; cursor: pointer; font-size: .82rem; font-family: inherit; }
.us-act.edit { color: var(--cb-primary,#6B4420); }
.us-act.key { color: #b45309; border-color: rgba(245,158,11,.25); }
.us-act.off { color: var(--danger,#ef4444); border-color: rgba(239,68,68,.2); }
.us-act.on { color: #1F7A4D; border-color: rgba(21,128,61,.25); }
.us-act form { margin: 0; display: inline; }

/* Modales */
.modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,.4); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 9999; opacity: 0; pointer-events: none; transition: opacity .25s; padding: 16px; }
.modal-overlay.active { opacity: 1; pointer-events: auto; }
.confirm-card { background: #fff; width: 100%; max-width: 420px; border-radius: 18px; box-shadow: 0 20px 40px rgba(0,0,0,.18); overflow: hidden; transform: scale(.92); transition: transform .25s cubic-bezier(.34,1.56,.64,1); max-height: 92vh; overflow-y: auto; }
.modal-overlay.active .confirm-card { transform: scale(1); }
.confirm-header { padding: 20px 20px 6px; display: flex; align-items: center; gap: 12px; }
.icon-circle { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; background: var(--cb-primary-light,#F5EBDC); color: var(--cb-primary,#6B4420); }
.confirm-title { font-size: 1.05rem; font-weight: 700; color: var(--cb-forest,#3E2412); margin: 0; }
.confirm-body { padding: 14px 20px 4px; }
.us-field { margin-bottom: 13px; }
.us-field label { display: block; margin-bottom: 7px; font-size: .8rem; font-weight: 600; color: var(--cb-forest,#3E2412); }
.us-input, .us-select { width: 100%; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; border-radius: 12px; padding: 12px 14px; font-family: inherit; font-size: .88rem; color: var(--cb-text,#2A1709); }
.us-select { appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236b7b69' d='M6 8 0 0h12z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; padding-right: 38px; }
.us-input:focus, .us-select:focus { outline: none; border-color: var(--cb-primary,#6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }

/* Lista de condiciones de la contraseña, que se van marcando al teclear. */
.us-reglas { list-style: none; margin: 8px 0 0; padding: 0; display: grid; gap: 4px; }
.us-reglas li { display: flex; align-items: center; gap: 7px; font-size: .72rem; color: var(--cb-muted,#9A8778); transition: color .15s ease; }
.us-reglas li i { font-size: .6rem; width: 11px; text-align: center; }
.us-reglas li.ok { color: #047857; font-weight: 600; }
.confirm-actions { padding: 14px 20px 18px; display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
.btn-x { padding: 11px 16px; border-radius: 11px; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; color: #475569; font-weight: 600; cursor: pointer; font-family: inherit; }
.btn-g { padding: 11px 16px; border-radius: 11px; border: none; background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color: #fff; font-weight: 700; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 7px; }
.btn-w { padding: 11px 16px; border-radius: 11px; border: none; background: var(--warning,#f59e0b); color: #fff; font-weight: 700; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 7px; }
</style>

<div class="us-wrap">

<?php if ($msg): ?>
    <div class="us-msg <?= $msgType ?>">
        <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : 'circle-xmark' ?>"></i>
        <span><?= htmlspecialchars($msg) ?></span>
    </div>
<?php endif; ?>

<div class="us-head">
    <h3><i class="fas fa-users-gear" style="color:var(--cb-primary,#6B4420);"></i> Cuentas de usuario</h3>
    <button type="button" class="us-new" onclick="abrirCrear()"><i class="fas fa-user-plus"></i> Nuevo</button>
</div>

<?php foreach ($usuarios as $u):
    $esAdmin = $u['rol'] === 'admin';
    $ini = strtoupper(mb_substr($u['nombre'],0,1,'UTF-8'));
?>
    <div class="us-card <?= $u['activo'] ? '' : 'inactivo' ?>">
        <div class="us-top">
            <div class="us-av <?= $esAdmin ? 'admin' : 'recep' ?>"><?= $ini ?></div>
            <div class="us-info">
                <div class="us-name"><?= htmlspecialchars($u['nombre']) ?><?php if ((int)$u['id'] === $miId): ?><span class="yo">(tú)</span><?php endif; ?></div>
                <div class="us-email"><?= htmlspecialchars($u['email']) ?></div>
            </div>
            <span class="us-rol <?= $esAdmin ? 'admin' : 'recep' ?>"><?= $esAdmin ? 'Admin' : 'Recepción' ?></span>
        </div>
        <div class="us-foot">
            <span class="us-estado"><span class="us-dot <?= $u['activo'] ? 'on' : 'off' ?>"></span><?= $u['activo'] ? 'Activo' : 'Inactivo' ?></span>
            <div class="us-actions">
                <button type="button" class="us-act edit" title="Editar"
                    onclick='abrirEditar(<?= json_encode(["id"=>(int)$u["id"],"nombre"=>$u["nombre"],"email"=>$u["email"],"rol"=>$u["rol"]], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                    <i class="fas fa-pen"></i>
                </button>
                <button type="button" class="us-act key" title="Resetear contraseña"
                    onclick="abrirReset(<?= (int)$u['id'] ?>, <?= htmlspecialchars(json_encode($u['nombre']), ENT_QUOTES) ?>)">
                    <i class="fas fa-key"></i>
                </button>
                <?php if ((int)$u['id'] !== $miId): ?>
                    <span class="us-act <?= $u['activo'] ? 'off' : 'on' ?>" title="<?= $u['activo'] ? 'Desactivar' : 'Activar' ?>">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_activo">
                            <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" style="background:none;border:none;cursor:pointer;color:inherit;padding:0;font-size:.82rem;">
                                <i class="fas fa-<?= $u['activo'] ? 'ban' : 'check' ?>"></i>
                            </button>
                        </form>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>

</div><!-- /.us-wrap -->

<!-- Modal Crear/Editar -->
<div id="modal-form" class="modal-overlay">
    <div class="confirm-card">
        <form method="POST" style="margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="form-action" value="crear">
            <input type="hidden" name="usuario_id" id="form-id" value="">
            <div class="confirm-header">
                <div class="icon-circle"><i class="fas fa-user" id="form-icon"></i></div>
                <h4 class="confirm-title" id="form-title">Nuevo usuario</h4>
            </div>
            <div class="confirm-body">
                <div class="us-field">
                    <label>Nombre completo *</label>
                    <input type="text" name="nombre" id="form-nombre" class="us-input" value="<?= htmlspecialchars($formCrear['nombre']) ?>" required>
                </div>
                <div class="us-field">
                    <label>Correo electrónico *</label>
                    <input type="email" name="email" id="form-email" class="us-input" value="<?= htmlspecialchars($formCrear['email']) ?>" required>
                </div>
                <div class="us-field" id="form-pass-group">
                    <label>Contraseña *</label>
                    <input type="password" name="password" id="form-pass" class="us-input" minlength="<?= PASSWORD_MIN_LEN ?>" placeholder="Mínimo <?= PASSWORD_MIN_LEN ?> caracteres">
                    <?php /* Las condiciones salen de reglasPassword() (includes/validacion.php),
                             la misma lista que valida el servidor. Se marcan al teclear para
                             verlas ANTES de enviar, en vez de enterarse al rebotar. */ ?>
                    <ul class="us-reglas" id="us-reglas" aria-live="polite">
                        <?php foreach (reglasPassword() as $i => $regla): ?>
                        <li data-regla="<?= (int)$i ?>"><i class="fas fa-circle"></i><span><?= htmlspecialchars($regla['texto']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="us-field">
                    <label>Rol *</label>
                    <select name="rol" id="form-rol" class="us-select" required>
                        <option value="recepcionista"<?= $formCrear['rol'] === 'recepcionista' ? ' selected' : '' ?>>Recepcionista</option>
                        <option value="admin"<?= $formCrear['rol'] === 'admin' ? ' selected' : '' ?>>Administrador</option>
                    </select>
                </div>
            </div>
            <div class="confirm-actions">
                <button type="button" class="btn-x" onclick="cerrar('modal-form')">Cancelar</button>
                <button type="submit" class="btn-g"><i class="fas fa-floppy-disk"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Reset Contraseña -->
<div id="modal-reset" class="modal-overlay">
    <div class="confirm-card">
        <form method="POST" style="margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reset_pass">
            <input type="hidden" name="usuario_id" id="reset-id" value="">
            <div class="confirm-header">
                <div class="icon-circle" style="background:rgba(245,158,11,.12);color:var(--warning,#f59e0b);"><i class="fas fa-key"></i></div>
                <h4 class="confirm-title">Restablecer contraseña</h4>
            </div>
            <div class="confirm-body">
                <p style="margin:0 0 14px;color:var(--cb-muted,#9A8778);font-size:.84rem;">
                    Nueva contraseña para <strong id="reset-nombre" style="color:var(--cb-forest,#3E2412);"></strong>. La anterior dejará de funcionar.
                </p>
                <div class="us-field">
                    <label>Nueva contraseña *</label>
                    <input type="password" name="password" class="us-input" minlength="<?= PASSWORD_MIN_LEN ?>" required placeholder="Mínimo <?= PASSWORD_MIN_LEN ?> caracteres">
                    <small style="display:block; margin-top:5px; font-size:.72rem; color:#9A8778;"><i class="fas fa-shield-halved"></i> Mínimo <?= PASSWORD_MIN_LEN ?> caracteres, con mayúscula, minúscula, número y carácter especial.</small>
                </div>
            </div>
            <div class="confirm-actions">
                <button type="button" class="btn-x" onclick="cerrar('modal-reset')">Cancelar</button>
                <button type="submit" class="btn-w"><i class="fas fa-key"></i> Restablecer</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirCrear(){
    document.getElementById('form-action').value = 'crear';
    document.getElementById('form-id').value = '';
    document.getElementById('form-title').textContent = 'Nuevo usuario';
    document.getElementById('form-nombre').value = '';
    document.getElementById('form-email').value = '';
    document.getElementById('form-pass').value = '';
    document.getElementById('form-rol').value = 'recepcionista';
    document.getElementById('form-pass-group').style.display = 'block';
    document.getElementById('form-pass').required = true;
    // Vaciar el campo por código no dispara 'input', así que las condiciones
    // se quedarían marcadas en verde de la vez anterior.
    document.getElementById('form-pass').dispatchEvent(new Event('input'));
    abrir('modal-form');
}
function abrirEditar(u){
    document.getElementById('form-action').value = 'editar';
    document.getElementById('form-id').value = u.id;
    document.getElementById('form-title').textContent = 'Editar usuario';
    document.getElementById('form-nombre').value = u.nombre;
    document.getElementById('form-email').value = u.email;
    document.getElementById('form-rol').value = u.rol;
    document.getElementById('form-pass-group').style.display = 'none';
    document.getElementById('form-pass').required = false;
    abrir('modal-form');
}
function abrirReset(id, nombre){
    document.getElementById('reset-id').value = id;
    document.getElementById('reset-nombre').textContent = nombre;
    abrir('modal-reset');
}
function abrir(id){ document.getElementById(id).classList.add('active'); }
function cerrar(id){ document.getElementById(id).classList.remove('active'); }

// ── Condiciones de la contraseña, en vivo ──
// Los patrones vienen de reglasPassword() en el servidor: aquí no se
// reescribe ninguna regla, solo se pintan. El control de verdad sigue siendo
// el del servidor.
(function(){
    var patrones = <?= json_encode(array_map(fn($r) => $r['js'], reglasPassword()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var campo = document.getElementById('form-pass');
    var lista = document.getElementById('us-reglas');
    if (!campo || !lista) return;

    var puntos = patrones.map(function(p){
        // La bandera 'u' hace que la cuenta de caracteres sea por punto de
        // código, igual que el mb_strlen del servidor.
        try { return new RegExp(p, 'u'); } catch (e) { return new RegExp(p); }
    });

    function revisar(){
        var v = campo.value;
        lista.querySelectorAll('li[data-regla]').forEach(function(li){
            var i = parseInt(li.getAttribute('data-regla'), 10);
            var cumple = v !== '' && puntos[i].test(v);
            li.classList.toggle('ok', cumple);
            var ic = li.querySelector('i');
            if (ic) ic.className = cumple ? 'fas fa-circle-check' : 'fas fa-circle';
        });
    }
    campo.addEventListener('input', revisar);
    revisar();
})();
<?php if ($reabrirCrear): ?>
// La creación falló: se reabre el formulario tal cual quedó. No se pasa por
// abrirCrear(), que es justo la que vacía los campos.
abrir('modal-form');
document.getElementById('form-pass').focus();
<?php endif; ?>
document.querySelectorAll('.modal-overlay').forEach(function(o){
    o.addEventListener('click', function(e){ if(e.target === o) o.classList.remove('active'); });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
