<?php
// ============================================================
// includes/login.php
// Finalización de sesión + enrutamiento a 2FA — Casa Bosque
// ============================================================
// Centraliza el "completar login" para que index.php y el modal de
// reservar.php compartan exactamente el mismo flujo, incluida la
// verificación de segundo factor (2FA/TOTP).
// ------------------------------------------------------------

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/claves.php';   // claveHash() y el rehash al entrar

/**
 * Establece la sesión de usuario ya autenticado (login completo).
 *
 * @param bool $conSegundoFactor true si en ESTE ingreso se tecleó el código del
 *        2FA; false si se entró por un equipo recordado. Las acciones críticas
 *        (respaldo, reiniciar, usuarios) piden el código a quien no lo tecleó.
 *        Ver includes/equipos.php.
 */
function establecerSesionUsuario(array $user, bool $conSegundoFactor = true): void {
    session_regenerate_id(true);
    $_SESSION['usuario_id']     = (int) $user['id'];
    $_SESSION['usuario_nombre'] = $user['nombre'];
    $_SESSION['usuario_email']  = $user['email'];
    $_SESSION['usuario_rol']    = $user['rol'];
    $_SESSION['twofa_enabled']  = !empty($user['twofa_enabled']);
    // El panel consume esta marca una sola vez para mostrar la transición
    // visual al entrar, después de completar contraseña y 2FA si aplica.
    $_SESSION['cb_transition'] = 'entrada';
    unset($_SESSION['2fa_pending'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_intentos'],
          $_SESSION['2fa_escalar_destino'], $_SESSION['2fa_escalar_time']);

    // La marca se borra primero y se pone solo si corresponde: el id de sesión
    // se regenera pero los DATOS de la sesión se conservan, así que una marca
    // de un ingreso anterior no puede colarse en este.
    unset($_SESSION['2fa_verificado_en']);
    if ($conSegundoFactor && !empty($user['twofa_enabled'])) {
        $_SESSION['2fa_verificado_en'] = time();
    }

    // Deja registrada esta sesión para poder cerrarla a distancia: al
    // cambiar la contraseña, todas las sesiones del usuario se invalidan.
    // Va con guarda porque este archivo no carga config/database.php, y
    // si el registro falla el login debe completarse igual.
    if (function_exists('getDB')) {
        require_once __DIR__ . '/sesiones.php';
        try { sesionRegistrar(getDB(), (int) $user['id']); } catch (Throwable $e) {}

        // Y queda constancia de la entrada en la auditoría. Está aquí, y no en
        // acceso.php, porque este es el punto por el que pasan los DOS caminos
        // (con y sin 2FA, con equipo recordado o con código): un solo sitio
        // que no se pueda olvidar. login_2fa y login_equipo_confiable siguen
        // escribiéndose aparte porque dicen CÓMO se entró.
        try {
            registrarAuditoria(getDB(), 'usuarios', (int) $user['id'], 'login', 'Inicio de sesión');
        } catch (Throwable $e) {
            error_log('Auditoría: no se pudo registrar el inicio de sesión: ' . $e->getMessage());
        }
    }
}

/**
 * Se llama tras validar la contraseña. Si el usuario tiene 2FA activo,
 * NO completa la sesión: deja un estado "pendiente" y redirige a la
 * verificación del código. Si no, completa el login. Siempre hace exit.
 */
function iniciarSesionConPosible2FA(array $user): void {
    if (!empty($user['twofa_enabled'])) {
        // ¿Es un equipo que el usuario marcó como de confianza? Entonces la
        // contraseña (que ya se verificó) basta. Ver includes/equipos.php: la
        // contraseña se pide SIEMPRE, y ante cualquier duda se pide el código.
        // Va con guarda porque este archivo no carga config/database.php.
        if (function_exists('getDB')) {
            require_once __DIR__ . '/equipos.php';
            try {
                $pdoEquipo = getDB();
                if (equipoConfiable($pdoEquipo, $user)) {
                    establecerSesionUsuario($user, false);   // false: NO se tecleó el código
                    try {
                        registrarAuditoria($pdoEquipo, 'usuarios', (int) $user['id'], 'login_equipo_confiable',
                            'Inicio de sesión sin código: equipo recordado');
                    } catch (Throwable $e) {
                        error_log('Equipos: no se pudo auditar el ingreso: ' . $e->getMessage());
                    }
                    header('Location: ' . getHomeUrl());
                    exit;
                }
            } catch (Throwable $e) {
                // Cualquier fallo se resuelve pidiendo el código, nunca saltándolo.
                error_log('Equipos: ' . $e->getMessage());
            }
        }

        session_regenerate_id(true);
        $_SESSION['2fa_pending']      = (int) $user['id'];
        $_SESSION['2fa_pending_time'] = time();
        $_SESSION['2fa_intentos']     = 0;
        header('Location: ' . BASE_URL . '/verificar_2fa.php');
        exit;
    }
    establecerSesionUsuario($user);
    header('Location: ' . getHomeUrl());
    exit;
}

// ── Códigos de respaldo ──────────────────────────────────────────────

/** Genera N códigos de respaldo legibles (8 hex en mayúscula). */
function generarCodigosRespaldo(int $n = 8): array {
    $codes = [];
    for ($i = 0; $i < $n; $i++) {
        $codes[] = strtoupper(bin2hex(random_bytes(4)));
    }
    return $codes;
}

/** Serializa los códigos como hashes SHA-256 (JSON) para guardar en BD. */
function hashCodigosRespaldo(array $codes): string {
    return json_encode(array_map(
        fn($c) => hash('sha256', strtoupper(preg_replace('/\s+/', '', $c))),
        $codes
    ));
}

/**
 * Verifica un código de respaldo y lo CONSUME (un solo uso). Devuelve
 * true si era válido. Actualiza la columna twofa_backup del usuario.
 */
function verificarYConsumirBackup(PDO $pdo, int $userId, string $codigo): bool {
    $codigo = strtoupper(preg_replace('/\s+/', '', $codigo));
    if ($codigo === '') return false;
    $stmt = $pdo->prepare("select twofa_backup from usuarios where id = ?");
    $stmt->execute([$userId]);
    $json = $stmt->fetchColumn();
    if (!$json) return false;
    $hashes = json_decode($json, true) ?: [];
    $h = hash('sha256', $codigo);
    $idx = array_search($h, $hashes, true);
    if ($idx === false) return false;
    unset($hashes[$idx]);
    $upd = $pdo->prepare("update usuarios set twofa_backup = ? where id = ?");
    $upd->execute([json_encode(array_values($hashes)), $userId]);
    return true;
}
