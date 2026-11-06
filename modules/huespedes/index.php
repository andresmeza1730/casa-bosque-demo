<?php
// Módulo: Huéspedes — CRUD de clientes con función de eliminación controlada
$pageTitle = 'Huéspedes';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/validacion.php';
require_once __DIR__ . '/../../includes/cifrado.php';
require_once __DIR__ . '/../../includes/retencion.php';
require_once __DIR__ . '/../../includes/equipos.php';   // exigirCodigoEnAccionCritica(): eliminar y anonimizar piden el código (4.3.2)
require_once __DIR__ . '/../../includes/busqueda.php';   // el texto buscado no va por la URL
requireLogin();

// El enlace de limpiar. Redirige, así que va antes de cualquier otra cosa.
busquedaLimpiarSiPiden('huespedes');

$pdo = getDB();
$msg = '';
$msgType = '';

/** ¿Se puede editar a este huésped? 'ok', 'no_existe' o 'anonimizado' (borrado seguro: ya no se le escribe nada). */
function huespedParaEditar(PDO $pdo, int $id): string {
    $st = $pdo->prepare("select anonimizado_en from huespedes where id = ?");
    $st->execute([$id]);
    $fila = $st->fetch(PDO::FETCH_ASSOC);
    if ($fila === false) return 'no_existe';
    return $fila['anonimizado_en'] === null ? 'ok' : 'anonimizado';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $msg = 'Token de seguridad inválido. Intente de nuevo.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Buscar ──
        // El texto se guarda en la sesión y se vuelve por GET: así no queda en
        // la URL ni en el historial. Lo manda este mismo buscador y también
        // los del panel y de recepción, que apuntan aquí.
        if ($action === 'buscar') {
            busquedaGuardar('huespedes', (string) ($_POST['q'] ?? ''));
            busquedaRedirigir();
        }

        if ($action === 'crear') {
            $docTipo   = $_POST['documento_tipo'] ?? '';
            $docNum    = trim($_POST['documento_num'] ?? '');
            $nombres   = trim($_POST['nombres'] ?? '');
            $apellidos = trim($_POST['apellidos'] ?? '');
            $telefono  = trim($_POST['telefono'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $ciudad    = trim($_POST['ciudad_origen'] ?? '');
            $nacionalidad = trim($_POST['nacionalidad'] ?? '');
            if ($nacionalidad === '') { $nacionalidad = 'COLOMBIA'; }   // requerido para SIRE/TRA
            // Consentimiento Habeas Data (Ley 1581): el recepcionista confirma
            // que el huésped autorizó el tratamiento de sus datos (form físico).
            $habeasData = isset($_POST['habeas_data']) && $_POST['habeas_data'] === '1';

            // El documento y el teléfono se validan EN EL SERVIDOR (el filtro de la pantalla es solo ayuda): una cédula con letras ya
            // no se guarda. CC, CE y NIT llevan solo números; el pasaporte, letras y números.
            $docNorm = validarDocumentoTipo($docTipo, $docNum);
            $telNorm = $telefono === '' ? '' : validarTelefono($telefono);

            if (!in_array($docTipo, ['CC', 'CE', 'PAS', 'NIT'], true) || $docNum === '' || empty($nombres) || empty($apellidos)) {
                $msg = 'Documento, nombres y apellidos son obligatorios.';
                $msgType = 'danger';
            } elseif ($docNorm === null) {
                $msg = $docTipo === 'PAS'
                    ? 'El pasaporte solo puede llevar letras y números (entre 3 y 30 caracteres).'
                    : 'El número de documento solo puede llevar números (entre 3 y 20), sin letras.';
                $msgType = 'danger';
            } elseif ($telNorm === null) {
                $msg = 'El teléfono no es válido: solo números (mínimo 7), y puede llevar +, - y paréntesis.';
                $msgType = 'danger';
            } elseif (!$habeasData) {
                $msg = 'Debes confirmar que el huésped autorizó el Tratamiento de Datos Personales (Ley 1581).';
                $msgType = 'danger';
            } else {
                $check = $pdo->prepare("select id from huespedes where documento_hash = ?");
                $docNum = $docNorm;   // lo que se guarda es el documento ya validado y normalizado
                $telefono = $telNorm;
                $check->execute([indiceCiego($docNum)]);
                if ($check->fetch()) {
                    $msg = 'Ya existe un huésped con ese número de documento.';
                    $msgType = 'warning';
                } else {
                    $stmt = $pdo->prepare("insert into huespedes (documento_tipo, documento_num, documento_hash, nombres, apellidos, telefono, email, ciudad_origen, nacionalidad, habeas_data, habeas_data_fecha, habeas_data_origen) values (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, now(), 'recepcion')");
                    $stmt->execute([$docTipo, cifrar($docNum), indiceCiego($docNum), $nombres, $apellidos, cifrar($telefono), $email, $ciudad, $nacionalidad]);
                    $nuevoId = $pdo->lastInsertId();
                    // El documento va enmascarado: la auditoría no se puede
                    // editar ni borrar (ver documentoOculto en cifrado.php). El
                    // id queda para poder llegar al dato entero por la ficha.
                    registrarAuditoria($pdo, 'huespedes', $nuevoId, 'crear', "Huésped #$nuevoId: $nombres $apellidos ($docTipo: " . documentoOculto($docNum) . ")");
                    $msg = 'Huésped registrado exitosamente.';
                    $msgType = 'success';
                }
            }
        }

        if ($action === 'editar') {
            $id        = (int)($_POST['id'] ?? 0);
            $telefono  = trim($_POST['telefono'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $ciudad    = trim($_POST['ciudad_origen'] ?? '');

            $telNorm = $telefono === '' ? '' : validarTelefono($telefono);
            if ($id > 0 && $telNorm === null) {
                $msg = 'El teléfono no es válido: solo números (mínimo 7), y puede llevar +, - y paréntesis. No se guardó nada.';
                $msgType = 'danger';
            } elseif ($id > 0 && ($estadoHu = huespedParaEditar($pdo, $id)) !== 'ok') {
                // 4.3.1: ni uno que no existe ni uno ANONIMIZADO. Antes el servidor no miraba (la interfaz solo escondía el lápiz) y quien mandara
                // el POST a mano le reescribía el teléfono y el correo a alguien cuyos datos personales ya se habían suprimido: lo contrario del
                // borrado seguro (Ley 1581, derecho de supresión).
                $msg = $estadoHu === 'anonimizado'
                    ? 'Ese huésped fue anonimizado (borrado seguro): sus datos personales ya no se pueden editar. No se guardó nada.'
                    : 'El huésped no existe. No se guardó nada.';
                $msgType = 'danger';
            } elseif ($id > 0) {
                $telefono = $telNorm;
                // De un huésped la auditoría guarda QUÉ campos cambiaron, nunca sus
                // valores (ver includes/auditoria.php): la fila no se puede borrar y
                // el huésped tiene derecho a que se le anonimice. Se compara lo
                // descifrado, porque el teléfono cifrado cambia en cada guardado.
                $stPrevio = $pdo->prepare("select telefono, email, ciudad_origen from huespedes where id = ?");
                $stPrevio->execute([$id]);
                $previo = $stPrevio->fetch(PDO::FETCH_ASSOC) ?: [];
                $camposCambiados = auditoriaCambiaCampos(
                    ['telefono' => (string) descifrar($previo['telefono'] ?? null), 'email' => (string) ($previo['email'] ?? ''), 'ciudad_origen' => (string) ($previo['ciudad_origen'] ?? '')],
                    ['telefono' => $telefono, 'email' => $email, 'ciudad_origen' => $ciudad]
                );
                // `anonimizado_en is null` también en el `where`: si lo anonimizaron entre la lectura y este update, no se escribe nada.
                $stmt = $pdo->prepare("update huespedes set telefono = ?, email = ?, ciudad_origen = ? where id = ? and anonimizado_en is null");
                $stmt->execute([cifrar($telefono), $email, $ciudad, $id]);
                if ($stmt->rowCount() === 0 && huespedParaEditar($pdo, $id) !== 'ok') {
                    $msg = 'Ese huésped fue anonimizado mientras se editaba: no se guardó nada.';
                    $msgType = 'danger';
                } else {
                    registrarAuditoria($pdo, 'huespedes', $id, 'editar', "Datos de contacto actualizados", null, $camposCambiados);
                    $msg = 'Datos del huésped actualizados correctamente.';
                    $msgType = 'success';
                }
            }
        }

        if ($action === 'eliminar') {
            if (!isAdmin()) {
                $msg = 'Acceso denegado: Solo los administradores pueden eliminar huéspedes.';
                $msgType = 'danger';
            } else {
            // 4.3.2: borrar a un huésped es irreversible: aunque el equipo esté recordado, aquí se pide el código (includes/equipos.php). Si
            // hay que pedirlo, se vuelve a esta pantalla y se repite la acción.
            exigirCodigoEnAccionCritica();
            $id = (int)($_POST['id'] ?? 0);

            $checkRes = $pdo->prepare("select count(*) from reservas where huesped_id = ?");
            $checkRes->execute([$id]);
            $numReservas = $checkRes->fetchColumn();

            $checkEst = $pdo->prepare("select count(*) from estancias where huesped_id = ?");
            $checkEst->execute([$id]);
            $numEstancias = $checkEst->fetchColumn();

            if ($numReservas > 0 || $numEstancias > 0) {
                $msg = 'No se puede eliminar el huésped porque cuenta con un historial de reservas o estancias vinculadas en el sistema.';
                $msgType = 'danger';
            } else {
                $info = $pdo->prepare("select nombres, apellidos, documento_num from huespedes where id = ?");
                $info->execute([$id]);
                $huesped = $info->fetch();

                if ($huesped) {
                    try {
                        // Enmascarado por el mismo motivo que al crear: esta
                        // fila de auditoría ya no se puede tocar nunca más.
                        $detallesAudit = "Huésped eliminado #$id: " . $huesped['nombres'] . " " . $huesped['apellidos']
                                       . " (" . documentoOculto(descifrar($huesped['documento_num'])) . ")";
                        registrarAuditoria($pdo, 'huespedes', $id, 'eliminar', $detallesAudit);
                        $stmt = $pdo->prepare("delete from huespedes where id = ?");
                        $stmt->execute([$id]);
                        $msg = 'Huésped removido del sistema correctamente.';
                        $msgType = 'success';
                    } catch (PDOException $e) {
                        $msg = 'Error de base de datos al intentar eliminar: El registro está siendo utilizado como referencia.';
                        $msgType = 'danger';
                    }
                }
            }
            }
        }

        // Borrado seguro (anonimización) de datos personales — derecho de
        // supresión (Ley 1581) e ISO 27701. Conserva el historial pero
        // suprime la identidad. Solo administrador.
        if ($action === 'anonimizar') {
            if (!isAdmin()) {
                $msg = 'Acceso denegado: solo los administradores pueden ejecutar el borrado seguro.';
                $msgType = 'danger';
            } else {
                // 4.3.2: el borrado seguro tampoco se hace sin el código (aunque el equipo esté recordado): suprime la identidad para siempre.
                exigirCodigoEnAccionCritica();
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0 && huespedAlojadoAhora($pdo, $id)) {
                    // 4.3.2: no se anonimiza a quien está hospedado: el check-out, el folio y la factura necesitan su identidad.
                    $msg = 'Ese huésped está hospedado ahora. Haz primero el check-out y después el borrado seguro.';
                    $msgType = 'danger';
                } elseif ($id > 0 && anonimizarHuesped($pdo, $id)) {
                    registrarAuditoria($pdo, 'huespedes', $id, 'anonimizar', 'Borrado seguro de datos personales (derecho de supresión / retención)');
                    $msg = 'Datos personales del huésped anonimizados de forma segura. El historial se conservó sin identidad.';
                    $msgType = 'success';
                } else {
                    $msg = 'No se pudo anonimizar (el huésped no existe o ya estaba anonimizado).';
                    $msgType = 'warning';
                }
            }
        }
    }
}

$buscar = busquedaRecordada('huespedes');

// La búsqueda va en DOS PASOS a propósito, no en un solo `where A or B`.
//
// El documento está cifrado, así que se busca por índice ciego: igualdad
// exacta contra `documento_hash`, que tiene índice único. Eso resuelve en
// microsegundos. El nombre, en cambio, se busca con `like '%texto%'`, y un
// comodín al principio NO puede usar ningún índice: obliga a recorrer la
// tabla entera, siempre.
//
// Juntar las dos con `or` en una sola consulta contagiaba lo malo a lo
// bueno: el motor no podía usar el índice del hash porque el `or` lo
// forzaba a mirar todas las filas de todos modos. Medido sobre 20.000
// huéspedes, buscar por documento tardaba 20,07 ms. Separado, 0,14 ms.
//
// Buscar por nombre sigue costando lo mismo (~9 ms): eso es inherente al
// comodín inicial y no lo arregla ningún índice.
if ($buscar === '') {
    $stmt = $pdo->prepare("select * from huespedes order by created_at desc limit 100");
    $stmt->execute();
    $huespedes = $stmt->fetchAll();
} else {
    // Paso 1: ¿es un documento? Igualdad exacta sobre el índice ciego.
    $stmt = $pdo->prepare("select * from huespedes where documento_hash = ?
                           order by created_at desc limit 100");
    $stmt->execute([indiceCiego($buscar)]);
    $huespedes = $stmt->fetchAll();

    // Paso 2: si no era un documento, buscar por nombre o apellido.
    if (!$huespedes) {
        $stmt = $pdo->prepare("select * from huespedes
                               where nombres like ? or apellidos like ?
                               order by created_at desc limit 100");
        $stmt->execute(["%$buscar%", "%$buscar%"]);
        $huespedes = $stmt->fetchAll();
    }
}
// Descifrar campos sensibles para mostrarlos en la vista.
foreach ($huespedes as &$_h) {
    $_h['documento_num'] = descifrar($_h['documento_num']);
    $_h['telefono']      = descifrar($_h['telefono']);
}
unset($_h);

$editId = (int)($_GET['editar'] ?? 0);
$editData = null;
if ($editId > 0) {
    $stmtEdit = $pdo->prepare("select * from huespedes where id = ?");
    $stmtEdit->execute([$editId]);
    $editData = $stmtEdit->fetch(PDO::FETCH_ASSOC);
    if ($editData && $editData['anonimizado_en'] !== null) {
        // Un anonimizado no se abre para editar (4.3.1): el formulario le dejaba escribir un teléfono y un correo nuevos.
        $editData = null;
        $msg = 'Ese huésped fue anonimizado (borrado seguro): sus datos personales ya no se pueden editar.';
        $msgType = 'warning';
    } elseif ($editData) {
        $editData['documento_num'] = descifrar($editData['documento_num']);
        $editData['telefono']      = descifrar($editData['telefono']);
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<style>
/* ============================================================
   Huéspedes — Rediseño limpio (Casa Bosque)
   ============================================================ */
.hu-wrap { padding-bottom: 90px; }

.hu-msg { display: flex; align-items: center; gap: 11px; padding: 13px 15px; border-radius: 14px; margin-bottom: 16px; font-size: .9rem; font-weight: 500; }
.hu-msg.success { background: #e6f7f2; color: #047857; border: 1px solid rgba(31,122,77,.25); }
.hu-msg.danger  { background: #fdeded; color: #b91c1c; border: 1px solid rgba(239,68,68,.25); }
.hu-msg.warning { background: #fff3e8; color: #b45309; border: 1px solid rgba(249,115,22,.25); }

.hu-card { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); margin-bottom: 18px; overflow: hidden; }
.hu-card-h { padding: 16px 16px 0; display: flex; align-items: center; justify-content: space-between; }
.hu-card-h h3 { font-size: 1rem; font-weight: 700; color: var(--cb-forest,#3E2412); display: flex; align-items: center; gap: 8px; margin: 0; }
.hu-card-h .cancel { font-size: .78rem; color: var(--cb-muted,#9A8778); text-decoration: none; font-weight: 600; }
.hu-card-b { padding: 16px; }

/* Formulario */
.hu-field { margin-bottom: 13px; }
.hu-field label { display: flex; align-items: center; gap: 7px; margin-bottom: 7px; font-size: .8rem; font-weight: 600; color: var(--cb-forest,#3E2412); }
.hu-field label i { color: var(--cb-muted,#9A8778); }
.hu-input, .hu-select { width: 100%; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; border-radius: 12px; padding: 12px 14px; font-family: inherit; font-size: .88rem; color: var(--cb-text,#2A1709); }
.hu-select { appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236b7b69' d='M6 8 0 0h12z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; padding-right: 38px; }
.hu-input:focus, .hu-select:focus { outline: none; border-color: var(--cb-primary,#6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }
.hu-input:read-only, .hu-select:disabled { background: #f3f6f1; color: var(--cb-muted,#9A8778); }
.hu-row2 { display: grid; grid-template-columns: 1fr 1.2fr; gap: 11px; }
.hu-note { font-size: .74rem; color: #b45309; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; }
.hu-submit { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: none; cursor: pointer; font-family: inherit; font-size: .92rem; font-weight: 700; color: #fff; padding: 14px; border-radius: 14px; margin-top: 4px; }
.hu-submit.crear { background: linear-gradient(150deg, var(--cb-primary,#6B4420), var(--cb-secondary,#9C6728)); box-shadow: 0 8px 18px rgba(107,68,32,.28); }
.hu-submit.editar { background: linear-gradient(150deg,#f59e0b,#d97706); box-shadow: 0 8px 18px rgba(217,119,6,.28); }
.hu-submit:active { transform: scale(.98); }

/* Búsqueda */
.hu-search { position: relative; margin-bottom: 14px; }
.hu-search i.s { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: var(--cb-muted,#9A8778); }
.hu-search input { width: 100%; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; border-radius: 13px; padding: 12px 14px 12px 40px; font-family: inherit; font-size: .88rem; }
.hu-search input:focus { outline: none; border-color: var(--cb-primary,#6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }
.hu-search .clear { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: var(--cb-muted,#9A8778); text-decoration: none; }
.hu-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.hu-count { font-size: .78rem; color: var(--cb-muted,#9A8778); font-weight: 600; }
.hu-export { display: inline-flex; align-items: center; gap: 6px; font-size: .78rem; font-weight: 600; color: #1F7A4D; text-decoration: none; background: #e6f7ec; border: 1px solid rgba(31,122,77,.2); padding: 7px 12px; border-radius: 10px; }

/* Tarjeta de huésped */
.hu-guest { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 16px; padding: 14px; margin-bottom: 11px; box-shadow: 0 4px 12px rgba(42,23,9,.04); }
.hu-guest.editing { border-color: #f59e0b; background: #fffdf7; }
.hu-g-top { display: flex; align-items: center; gap: 12px; }
.hu-g-av { flex: none; width: 44px; height: 44px; border-radius: 13px; background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: .9rem; }
.hu-g-info { flex: 1; min-width: 0; }
.hu-g-name { font-size: .92rem; font-weight: 700; color: var(--cb-forest,#3E2412); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hu-g-doc { font-size: .73rem; color: var(--cb-muted,#9A8778); margin-top: 2px; }
.hu-g-doc .tipo { display: inline-block; background: var(--cb-primary-light,#F5EBDC); color: var(--cb-primary,#6B4420); font-weight: 700; font-size: .62rem; padding: 1px 6px; border-radius: 5px; margin-right: 4px; }
.hu-g-actions { flex: none; display: flex; gap: 7px; }
.hu-act { width: 36px; height: 36px; border-radius: 11px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; cursor: pointer; text-decoration: none; font-size: .85rem; }
.hu-act.edit { color: var(--cb-primary,#6B4420); }
.hu-act.anon { color: #7c3aed; border-color: rgba(124,58,237,.25); }
.hu-act.del { color: var(--danger,#ef4444); border-color: rgba(239,68,68,.2); }
.hu-anon-badge { display: inline-flex; align-items: center; gap: 5px; font-size: .68rem; font-weight: 700; color: #6d28d9; background: #f3e8ff; border: 1px solid rgba(124,58,237,.25); padding: 5px 9px; border-radius: 9px; }
.hu-g-meta { display: flex; flex-wrap: wrap; gap: 6px 16px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--gray-100,#F1EADF); }
.hu-g-meta span { font-size: .76rem; color: var(--cb-muted,#9A8778); display: inline-flex; align-items: center; gap: 6px; }
.hu-g-meta span i { color: var(--soft,#A2907C); width: 13px; }

.hu-empty { text-align: center; padding: 30px 12px; color: var(--cb-muted,#9A8778); }
.hu-empty i { font-size: 2rem; color: var(--cb-primary,#6B4420); opacity: .4; margin-bottom: 10px; display: block; }
.hu-empty p { font-size: .9rem; margin: 0; }

/* Modal eliminar */
.modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,.4); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 9999; opacity: 0; pointer-events: none; transition: opacity .25s ease; padding: 20px; }
.modal-overlay.active { opacity: 1; pointer-events: auto; }
.confirm-card { background: #fff; width: 100%; max-width: 400px; border-radius: 18px; box-shadow: 0 20px 40px rgba(0,0,0,.2); overflow: hidden; transform: scale(.92); transition: transform .25s cubic-bezier(.34,1.56,.64,1); }
.modal-overlay.active .confirm-card { transform: scale(1); }
.confirm-header { padding: 20px 20px 8px; display: flex; align-items: center; gap: 12px; }
.icon-container { width: 42px; height: 42px; background: rgba(239,68,68,.1); color: var(--danger,#ef4444); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.confirm-title { font-size: 1.05rem; font-weight: 700; color: var(--cb-forest,#3E2412); margin: 0; }
.confirm-body { padding: 0 20px 16px; color: #475569; font-size: .86rem; line-height: 1.5; }
.confirm-actions { padding: 14px 20px 18px; display: flex; justify-content: flex-end; gap: 10px; }
.btn-cancel { padding: 10px 16px; border-radius: 10px; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; color: #475569; font-weight: 600; cursor: pointer; font-family: inherit; }
.btn-del { padding: 10px 16px; border-radius: 10px; border: none; background: var(--danger,#ef4444); color: #fff; font-weight: 700; cursor: pointer; font-family: inherit; }
</style>

<div class="hu-wrap">

<?php if ($msg): ?>
    <div class="hu-msg <?= $msgType ?>">
        <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : ($msgType === 'warning' ? 'triangle-exclamation' : 'circle-xmark') ?>"></i>
        <span><?= htmlspecialchars($msg) ?></span>
    </div>
<?php endif; ?>

<?php
// 4.2.5: si el huésped NO se pudo registrar, el formulario vuelve con lo que se tecleó (antes salía vacío y había que escribir todo otra
// vez). Solo al crear y solo si falló; siempre escapado al pintarlo.
$huConservar = !$editData && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crear' && $msgType !== 'success';
$huPrev = function (string $campo, string $porDefecto = '') use ($huConservar): string {
    return $huConservar && is_string($_POST[$campo] ?? null) ? $_POST[$campo] : $porDefecto;
};
?>
<!-- Formulario crear / editar -->
<div class="hu-card">
    <div class="hu-card-h">
        <h3>
            <?php if ($editData): ?><i class="fas fa-user-pen" style="color:#f59e0b;"></i> Editar contacto
            <?php else: ?><i class="fas fa-user-plus" style="color:var(--cb-primary,#6B4420);"></i> Nuevo huésped<?php endif; ?>
        </h3>
        <?php if ($editData): ?><a href="?" class="cancel"><i class="fas fa-times"></i> Cancelar</a><?php endif; ?>
    </div>
    <div class="hu-card-b">
        <form method="POST" id="form-huesped">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $editData ? 'editar' : 'crear' ?>">
            <?php if ($editData): ?><input type="hidden" name="id" value="<?= $editData['id'] ?>"><?php endif; ?>

            <div class="hu-row2">
                <div class="hu-field">
                    <label><i class="fas fa-id-card"></i> Tipo</label>
                    <select name="documento_tipo" class="hu-select" required <?= $editData ? 'disabled' : '' ?>>
                        <?php foreach (['CC', 'CE', 'PAS', 'NIT'] as $tipoDoc): ?>
                            <option value="<?= $tipoDoc ?>" <?= (($editData['documento_tipo'] ?? $huPrev('documento_tipo')) === $tipoDoc) ? 'selected' : '' ?>><?= $tipoDoc ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hu-field">
                    <label><i class="fas fa-hashtag"></i> N° Documento</label>
                    <input type="text" name="documento_num" class="hu-input" required maxlength="30" inputmode="numeric" data-solo="documento" data-tipo-en="documento_tipo" <?= $editData ? '' : 'data-verifica-documento="' . BASE_URL . '/modules/huespedes/existe.php" data-ficha-url="' . BASE_URL . '/modules/huespedes/ficha.php" data-bloquea="1"' ?> placeholder="1234567890" value="<?= htmlspecialchars($editData['documento_num'] ?? $huPrev('documento_num')) ?>" <?= $editData ? 'readonly' : '' ?>>
                </div>
            </div>

            <div class="hu-row2" style="grid-template-columns:1fr 1fr;">
                <div class="hu-field">
                    <label><i class="fas fa-user"></i> Nombres</label>
                    <input type="text" name="nombres" class="hu-input" required placeholder="Juan Carlos" value="<?= htmlspecialchars($editData['nombres'] ?? $huPrev('nombres')) ?>" <?= $editData ? 'readonly' : '' ?>>
                </div>
                <div class="hu-field">
                    <label><i class="fas fa-user"></i> Apellidos</label>
                    <input type="text" name="apellidos" class="hu-input" required placeholder="García López" value="<?= htmlspecialchars($editData['apellidos'] ?? $huPrev('apellidos')) ?>" <?= $editData ? 'readonly' : '' ?>>
                </div>
            </div>

            <?php if ($editData): ?>
                <div class="hu-note"><i class="fas fa-circle-info"></i> Solo los datos de contacto son editables.</div>
            <?php endif; ?>

            <div class="hu-field">
                <label><i class="fas fa-phone"></i> Teléfono</label>
                <input type="tel" name="telefono" class="hu-input" maxlength="20" data-solo="telefono" placeholder="300 123 4567" value="<?= htmlspecialchars($editData['telefono'] ?? $huPrev('telefono')) ?>">
            </div>
            <div class="hu-field">
                <label><i class="fas fa-envelope"></i> Correo electrónico</label>
                <input type="email" name="email" class="hu-input" placeholder="correo@ejemplo.com" value="<?= htmlspecialchars($editData['email'] ?? $huPrev('email')) ?>">
            </div>
            <div class="hu-field">
                <label><i class="fas fa-map-marker-alt"></i> Ciudad de origen</label>
                <input type="text" name="ciudad_origen" class="hu-input" placeholder="Ej: Bucaramanga" value="<?= htmlspecialchars($editData['ciudad_origen'] ?? $huPrev('ciudad_origen')) ?>">
            </div>

            <?php if (!$editData): ?>
            <div class="hu-field">
                <label><i class="fas fa-flag"></i> Nacionalidad</label>
                <input type="text" name="nacionalidad" class="hu-input" placeholder="Colombia" value="<?= htmlspecialchars($huPrev('nacionalidad', 'Colombia')) ?>">
            </div>
            <?php endif; ?>

            <?php if (!$editData): ?>
            <!-- Consentimiento Habeas Data (Ley 1581) — obligatorio, desmarcado por defecto -->
            <label style="display:flex; gap:10px; align-items:flex-start; margin:14px 0 4px; font-size:.86rem; line-height:1.5; cursor:pointer; color:var(--cb-text,#2A1709);">
                <input type="checkbox" name="habeas_data" value="1" required <?= $huPrev('habeas_data') === '1' ? 'checked' : '' ?> style="margin-top:3px; width:17px; height:17px; flex-shrink:0;">
                <span>El huésped autorizó el Tratamiento de sus Datos Personales conforme a la
                    <a href="<?= BASE_URL ?>/politica-datos.php<?= volverDesdeAqui() ?>" target="_blank" rel="noopener" style="color:var(--cb-primary,#6B4420); font-weight:600;">Política de Tratamiento de Datos</a> (Ley 1581 de 2012).</span>
            </label>
            <?php endif; ?>

            <button type="submit" class="hu-submit <?= $editData ? 'editar' : 'crear' ?>">
                <i class="fas fa-<?= $editData ? 'floppy-disk' : 'user-plus' ?>"></i>
                <?= $editData ? 'Guardar cambios' : 'Registrar huésped' ?>
            </button>
        </form>
    </div>
</div>

<!-- Listado -->
<div class="hu-card">
    <div class="hu-card-b">
        <form method="POST" class="hu-search">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="buscar">
            <i class="fas fa-search s"></i>
            <input type="text" name="q" placeholder="Buscar por documento o nombre..." value="<?= htmlspecialchars($buscar) ?>">
            <?php if ($buscar): ?><a href="?limpiar=1" class="clear"><i class="fas fa-times-circle"></i></a><?php endif; ?>
        </form>

        <div class="hu-toolbar">
            <span class="hu-count"><i class="fas fa-users" style="margin-right:5px;"></i> <?= count($huespedes) ?> registro(s)</span>
            <?php if (isAdmin()): ?>
                <a href="<?= BASE_URL ?>/modules/huespedes/huespedes_exportar.php" class="hu-export"><i class="fas fa-file-excel"></i> Exportar</a>
            <?php endif; ?>
        </div>

        <?php if (empty($huespedes)): ?>
            <div class="hu-empty"><i class="fas fa-user-slash"></i><p>No se encontraron huéspedes.</p></div>
        <?php else: ?>
            <?php foreach ($huespedes as $h): ?>
                <div class="hu-guest <?= ($editId == $h['id']) ? 'editing' : '' ?>">
                    <div class="hu-g-top">
                        <div class="hu-g-av"><?= strtoupper(mb_substr($h['nombres'],0,1,'UTF-8') . mb_substr($h['apellidos'],0,1,'UTF-8')) ?></div>
                        <div class="hu-g-info">
                            <div class="hu-g-name"><?= htmlspecialchars($h['nombres'] . ' ' . $h['apellidos']) ?></div>
                            <div class="hu-g-doc"><span class="tipo"><?= $h['documento_tipo'] ?></span><?= htmlspecialchars($h['documento_num']) ?></div>
                        </div>
                        <div class="hu-g-actions">
                            <?php if (!empty($h['anonimizado_en'])): ?>
                                <span class="hu-anon-badge" title="Datos personales anonimizados el <?= htmlspecialchars($h['anonimizado_en']) ?>"><i class="fas fa-user-shield"></i> Anonimizado</span>
                            <?php else: ?>
                                <a href="?editar=<?= $h['id'] ?>" class="hu-act edit" title="Editar"><i class="fas fa-pen"></i></a>
                                <?php if (isAdmin()): ?>
                                <button type="button" class="hu-act anon" title="Borrado seguro de datos personales" onclick="confirmarAnonimizar(<?= (int)$h['id'] ?>, <?= htmlspecialchars(json_encode($h['nombres'] . ' ' . $h['apellidos']), ENT_QUOTES) ?>)"><i class="fas fa-user-shield"></i></button>
                                <?php endif; ?>
                                <button type="button" class="hu-act del" title="Eliminar" onclick="confirmarEliminacion(<?= (int)$h['id'] ?>, <?= htmlspecialchars(json_encode($h['nombres'] . ' ' . $h['apellidos']), ENT_QUOTES) ?>)"><i class="fas fa-trash-alt"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($h['telefono'] || $h['email'] || $h['ciudad_origen']): ?>
                    <div class="hu-g-meta">
                        <?php if ($h['telefono']): ?><span><i class="fas fa-phone"></i> <?= htmlspecialchars($h['telefono']) ?></span><?php endif; ?>
                        <?php if ($h['email']): ?><span><i class="fas fa-envelope"></i> <?= htmlspecialchars($h['email']) ?></span><?php endif; ?>
                        <?php if ($h['ciudad_origen']): ?><span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($h['ciudad_origen']) ?></span><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

</div><!-- /.hu-wrap -->

<!-- Modal eliminar (lógica conservada) -->
<div id="delete-modal-overlay" class="modal-overlay">
    <div class="confirm-card">
        <div class="confirm-header">
            <div class="icon-container"><i class="fas fa-triangle-exclamation"></i></div>
            <h4 class="confirm-title">¿Eliminar huésped?</h4>
        </div>
        <div class="confirm-body">
            ¿Seguro que deseas eliminar a <strong id="delete-huesped-name" style="color:var(--cb-forest,#3E2412);"></strong>? Quedará registrado en la bitácora de auditoría.
        </div>
        <div class="confirm-actions">
            <button type="button" class="btn-cancel" onclick="cerrarModal()">Cancelar</button>
            <form method="POST" id="form-eliminar-confirmado" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="eliminar">
                <input type="hidden" name="id" id="delete-huesped-id" value="">
                <button type="submit" class="btn-del">Eliminar</button>
            </form>
        </div>
    </div>
</div>

<!-- Modal borrado seguro (anonimización) -->
<div id="anon-modal-overlay" class="modal-overlay">
    <div class="confirm-card">
        <div class="confirm-header">
            <div class="icon-container" style="background:rgba(124,58,237,.12); color:#7c3aed;"><i class="fas fa-user-shield"></i></div>
            <h4 class="confirm-title">¿Borrado seguro de datos personales?</h4>
        </div>
        <div class="confirm-body">
            Se anonimizarán de forma <strong>irreversible</strong> los datos personales de
            <strong id="anon-huesped-name" style="color:var(--cb-forest,#3E2412);"></strong>
            (documento, nombre, teléfono, correo). El historial de reservas y estancias se
            conserva sin identidad, por retención legal. Esta acción no se puede deshacer.
        </div>
        <div class="confirm-actions">
            <button type="button" class="btn-cancel" onclick="cerrarAnon()">Cancelar</button>
            <form method="POST" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="anonimizar">
                <input type="hidden" name="id" id="anon-huesped-id" value="">
                <button type="submit" class="btn-del" style="background:#7c3aed;">Anonimizar</button>
            </form>
        </div>
    </div>
</div>

<script>
const overlay = document.getElementById('delete-modal-overlay');
const deleteIdInput = document.getElementById('delete-huesped-id');
const deleteNameSpan = document.getElementById('delete-huesped-name');

function confirmarEliminacion(id, nombreCompleto) {
    deleteIdInput.value = id;
    deleteNameSpan.textContent = nombreCompleto;
    overlay.classList.add('active');
}
function cerrarModal() { overlay.classList.remove('active'); }
overlay.addEventListener('click', function(e) { if (e.target === overlay) cerrarModal(); });

const anonOverlay = document.getElementById('anon-modal-overlay');
function confirmarAnonimizar(id, nombreCompleto) {
    document.getElementById('anon-huesped-id').value = id;
    document.getElementById('anon-huesped-name').textContent = nombreCompleto;
    anonOverlay.classList.add('active');
}
function cerrarAnon() { anonOverlay.classList.remove('active'); }
anonOverlay.addEventListener('click', function(e) { if (e.target === anonOverlay) cerrarAnon(); });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>