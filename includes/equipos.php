<?php
// ============================================================
// includes/equipos.php
// Equipos de confianza: el código del 2FA no se pide en cada ingreso
// ============================================================
// Lo que arregla: el 2FA pedía el código de 6 dígitos en CADA ingreso, y el
// administrador —la única cuenta que lo tiene, porque solo a los admins se
// les obliga— ingresa seguido: la cookie de sesión muere al cerrar el
// navegador y hay auto-logout a los 30 minutos. Teclear el código ya estaba
// optimizado (casillas, pegado, envío automático al sexto dígito); lo que
// molestaba era CUÁNTAS VECES se pedía.
//
// Ahora, al teclear el código, se puede marcar «Recordar este equipo por 14
// días». Ese navegador entra después solo con la contraseña.
//
// Qué NO es esto: no es quitar el 2FA. La contraseña se pide SIEMPRE, y el
// código se sigue pidiendo en un equipo nuevo, cada 14 días, y en las
// acciones más peligrosas aunque el equipo esté recordado (ver
// exigirCodigoEnAccionCritica, al final).
//
// Decisiones, y el motivo de cada una:
//
//  - La cookie lleva un token aleatorio de 32 bytes y en la base se guarda
//    solo su SHA-256. Un token en claro vale tanto como el código: quien lo
//    tenga, entra. Guardando el hash, leer la tabla no permite suplantar.
//    Mismo criterio que sesiones_activas.sid_hash.
//
//  - La fila se busca por token Y por usuario. La cookie del administrador no
//    sirve para saltarle el código a otro usuario que entre desde el mismo
//    navegador.
//
//  - Cada fila guarda un HMAC de la contraseña y del secreto del 2FA tal como
//    estaban al recordar el equipo (`estado_hash`), y se recalcula en cada
//    uso. Si cambia cualquiera de los dos, el equipo deja de valer. Esto cubre
//    lo que ningún gancho de código ve: una contraseña cambiada DIRECTO EN LA
//    BASE, sin pasar por la aplicación. Además se borran las filas de forma
//    explícita en los sitios donde ya se cierran sesiones (cinturón y
//    tirantes: cada camino falla de una manera distinta).
//
//  - FALLA CERRADO. Si faltan las claves del .env, si la base da error, si la
//    cookie está manipulada: se pide el código. Esta función existe para
//    pedirlo MENOS veces; ante cualquier duda tiene que pedirlo, nunca
//    saltarlo. Es lo contrario del registro de sesiones, que ante un error
//    NO echa a nadie: allí la duda favorece al usuario, aquí lo protege.
//
//  - Vence a los 14 días FIJOS, no se renueva al usarlo: el código se teclea
//    al menos cada dos semanas por equipo. Máximo 5 equipos por usuario.
//
//  - Si la tabla no existe (migración 025 sin ejecutar), TODO esto se apaga
//    solo y el ingreso funciona exactamente como antes. Se puede subir el
//    código antes de ejecutar la migración.
//
//  - Las claves del .env (APP_ENC_KEY, APP_IDX_KEY) se reutilizan, no se
//    tocan: el estado sale de indiceCiego(). Regenerarlas dejaría ilegibles
//    los datos cifrados; aquí solo anularía los equipos recordados.
// ------------------------------------------------------------

require_once __DIR__ . '/cifrado.php';

/** Nombre de la cookie del navegador. */
const EQUIPO_COOKIE = 'cb_equipo';

/** Cuántos días vale un equipo recordado, contados desde que se recuerda. */
const EQUIPO_DIAS = 14;

/** Cuántos equipos recordados puede tener un usuario a la vez. */
const EQUIPOS_MAX = 5;

/**
 * Las acciones críticas: las únicas páginas donde se pide el código aunque el
 * equipo esté recordado. Sin BASE_URL; se le antepone al comparar.
 *
 * Son las que, en manos de alguien con el equipo y la contraseña, causan más
 * daño: llevarse la base entera, borrarla, o crear/cambiar quién puede entrar.
 * Es también la lista blanca de adónde se puede volver tras confirmar el
 * código, para que el destino no sea una redirección abierta.
 */
const EQUIPO_RUTAS_CRITICAS = [
    '/modules/reportes/respaldo.php',
    '/modules/reportes/reiniciar.php',
    '/modules/usuarios/index.php',
    '/modules/huespedes/huespedes_exportar.php',
    // 4.3.2: pantallas que SIGUEN siendo de uso diario (la recepcionista también las usa), con acciones críticas de administrador dentro:
    // eliminar y anonimizar un huésped, y reabrir una cuenta. Ahí el código se pide al ejecutar la acción, no al abrir la pantalla; la ruta
    // está en esta lista para que, tras confirmar el código, se pueda VOLVER a ella (la lista es también la de destinos permitidos).
    '/modules/huespedes/index.php',
    '/modules/huespedes/historial.php',
];

/**
 * ¿Está instalada la tabla? Se comprueba una vez por petición.
 * Mientras devuelva false, ninguna otra función de este archivo toca la base.
 */
function equiposDisponible(PDO $pdo): bool {
    static $hay = null;
    if ($hay !== null) return $hay;
    try {
        $hay = (bool) $pdo->query("show tables like 'equipos_confiables'")->fetchColumn();
    } catch (Throwable $e) {
        $hay = false;
    }
    return $hay;
}

/** El token de la cookie, solo si tiene la forma exacta de uno nuestro. */
function _equipoCookieToken(): ?string {
    $v = $_COOKIE[EQUIPO_COOKIE] ?? '';
    return (is_string($v) && preg_match('/^[a-f0-9]{64}$/', $v) === 1) ? $v : null;
}

/** Atributos de la cookie: nada de JavaScript, nada entre sitios, y HTTPS si lo hay. */
function _equipoOpcionesCookie(int $expira): array {
    return [
        'expires'  => $expira,
        'path'     => '/',
        'secure'   => function_exists('esConexionSegura') && esConexionSegura(),
        'httponly' => true,
        'samesite' => 'Strict',
    ];
}

function _equipoPonerCookie(string $token): void {
    $_COOKIE[EQUIPO_COOKIE] = $token;
    if (headers_sent()) return;
    setcookie(EQUIPO_COOKIE, $token, _equipoOpcionesCookie(time() + EQUIPO_DIAS * 86400));
}

function _equipoBorrarCookie(): void {
    unset($_COOKIE[EQUIPO_COOKIE]);
    if (headers_sent()) return;
    setcookie(EQUIPO_COOKIE, '', _equipoOpcionesCookie(time() - 42000));
}

/**
 * El estado de las credenciales de un usuario, o null si no puede tener
 * equipos de confianza: cuenta desactivada, sin 2FA, o sin claves de cifrado.
 *
 * Lee de la base y NO del arreglo que traiga quien llama: los llamadores
 * seleccionan columnas distintas, y esto es demasiado importante para
 * depender de qué columnas pidió cada uno.
 *
 * Puede lanzar si faltan las claves del .env; quien llama lo captura y lo
 * trata como «no».
 */
function _equipoEstado(PDO $pdo, int $uid): ?string {
    $st = $pdo->prepare("select password_hash, twofa_secret, twofa_enabled, activo from usuarios where id = ?");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || !(int) $u['activo'] || !(int) $u['twofa_enabled']) return null;
    if ((string) $u['password_hash'] === '' || (string) $u['twofa_secret'] === '') return null;
    return indiceCiego($u['password_hash'] . '|' . $u['twofa_secret']);
}

/**
 * ¿Este navegador es un equipo recordado de este usuario?
 *
 * Se llama DESPUÉS de verificar la contraseña. Devuelve true solo si hay una
 * cookie válida, de este usuario, vigente, y con las credenciales tal como
 * estaban cuando se recordó. En cualquier otro caso, false.
 *
 * @param array $user Solo se usa `id`.
 */
function equipoConfiable(PDO $pdo, array $user): bool {
    if (!equiposDisponible($pdo)) return false;
    $token = _equipoCookieToken();
    if ($token === null) return false;
    $uid = (int) ($user['id'] ?? 0);
    if ($uid < 1) return false;

    try {
        $estado = _equipoEstado($pdo, $uid);
        if ($estado === null) return false;

        $st = $pdo->prepare("select id, estado_hash, (expira_en > now()) as vigente
                             from equipos_confiables
                             where token_hash = ? and usuario_id = ? limit 1");
        $st->execute([hash('sha256', $token), $uid]);
        $fila = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fila) return false;

        // Vencido, o la contraseña / el 2FA cambiaron desde que se recordó:
        // ya no vale, y se borra para que no quede basura ni cookie huérfana.
        if (!(int) $fila['vigente'] || !hash_equals((string) $fila['estado_hash'], $estado)) {
            $pdo->prepare("delete from equipos_confiables where id = ?")->execute([$fila['id']]);
            _equipoBorrarCookie();
            return false;
        }

        $pdo->prepare("update equipos_confiables set ultimo_uso = now() where id = ?")->execute([$fila['id']]);
        return true;
    } catch (Throwable $e) {
        error_log('Equipos: no se pudo comprobar el equipo: ' . $e->getMessage());
        return false;
    }
}

/**
 * Recuerda el navegador actual. Se llama justo después de validar el código
 * del 2FA y de establecer la sesión (la auditoría necesita el usuario de la
 * sesión).
 *
 * @return bool true si quedó recordado
 */
function equipoRecordar(PDO $pdo, array $user): bool {
    if (!equiposDisponible($pdo)) return false;
    $uid = (int) ($user['id'] ?? 0);
    if ($uid < 1) return false;

    try {
        $estado = _equipoEstado($pdo, $uid);
        if ($estado === null) return false;

        // Si este navegador ya estaba recordado, la fila vieja se reemplaza:
        // recordar dos veces el mismo equipo no debe gastar dos de los cinco
        // cupos.
        $viejo = _equipoCookieToken();
        if ($viejo !== null) {
            $pdo->prepare("delete from equipos_confiables where token_hash = ? and usuario_id = ?")
                ->execute([hash('sha256', $viejo), $uid]);
        }
        $pdo->prepare("delete from equipos_confiables where usuario_id = ? and expira_en <= now()")
            ->execute([$uid]);

        $token  = bin2hex(random_bytes(32));
        $agente = mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
        $pdo->prepare("insert into equipos_confiables
                       (usuario_id, token_hash, estado_hash, ip_address, agente, expira_en)
                       values (?, ?, ?, ?, ?, date_add(now(), interval " . (int) EQUIPO_DIAS . " day))")
            ->execute([$uid, hash('sha256', $token), $estado, (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), $agente]);

        // El tope: se quedan los más recientes. Se hace en PHP y no con un
        // `not in (select ... limit)`, que MariaDB no admite.
        $st = $pdo->prepare("select id from equipos_confiables where usuario_id = ? order by creado_en desc, id desc");
        $st->execute([$uid]);
        foreach (array_slice($st->fetchAll(PDO::FETCH_COLUMN), EQUIPOS_MAX) as $sobra) {
            $pdo->prepare("delete from equipos_confiables where id = ?")->execute([$sobra]);
        }

        _equipoPonerCookie($token);

        try {
            $nav = function_exists('sesionNavegador') ? sesionNavegador($agente) : 'navegador';
            registrarAuditoria($pdo, 'usuarios', $uid, 'recordar_equipo',
                'Recordó este equipo por ' . EQUIPO_DIAS . ' días (' . $nav . ')');
        } catch (Throwable $e) {
            error_log('Equipos: no se pudo auditar: ' . $e->getMessage());
        }
        return true;
    } catch (Throwable $e) {
        error_log('Equipos: no se pudo recordar el equipo: ' . $e->getMessage());
        return false;
    }
}

/**
 * Equipos recordados vigentes de un usuario, el más reciente primero. Para la
 * tarjeta de Seguridad. No devuelve ningún hash, ni siquiera el del token.
 */
function equiposDeUsuario(PDO $pdo, int $uid): array {
    if (!equiposDisponible($pdo)) return [];
    try {
        $st = $pdo->prepare("select id, token_hash, ip_address, agente, creado_en, expira_en, ultimo_uso
                             from equipos_confiables
                             where usuario_id = ? and expira_en > now()
                             order by creado_en desc, id desc");
        $st->execute([$uid]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);

        $token  = _equipoCookieToken();
        $actual = $token !== null ? hash('sha256', $token) : '';
        foreach ($filas as &$f) {
            $f['es_actual'] = ($actual !== '' && hash_equals($f['token_hash'], $actual));
            unset($f['token_hash']);
        }
        return $filas;
    } catch (Throwable $e) {
        error_log('Equipos: no se pudo listar: ' . $e->getMessage());
        return [];
    }
}

/**
 * Quita un equipo concreto. El usuario va en el where a propósito: así nadie
 * puede quitar el equipo de otra persona pasando un id ajeno.
 */
function equipoQuitar(PDO $pdo, int $uid, int $id): bool {
    if (!equiposDisponible($pdo)) return false;
    try {
        $esActual = false;
        $token = _equipoCookieToken();
        if ($token !== null) {
            $c = $pdo->prepare("select count(*) from equipos_confiables where id = ? and usuario_id = ? and token_hash = ?");
            $c->execute([$id, $uid, hash('sha256', $token)]);
            $esActual = ((int) $c->fetchColumn()) > 0;
        }
        $st = $pdo->prepare("delete from equipos_confiables where id = ? and usuario_id = ?");
        $st->execute([$id, $uid]);
        $ok = $st->rowCount() > 0;
        if ($ok && $esActual) _equipoBorrarCookie();
        return $ok;
    } catch (Throwable $e) {
        error_log('Equipos: no se pudo quitar el equipo: ' . $e->getMessage());
        return false;
    }
}

/**
 * Quita TODOS los equipos recordados de un usuario. Es lo que se llama cuando
 * cambian sus credenciales: contraseña, o el 2FA.
 *
 * Si el navegador actual era uno de ellos, también se borra su cookie.
 *
 * @return int cuántos se quitaron
 */
function equiposRevocarTodos(PDO $pdo, int $uid): int {
    if (!equiposDisponible($pdo)) return 0;
    try {
        $token = _equipoCookieToken();
        if ($token !== null) {
            $c = $pdo->prepare("select count(*) from equipos_confiables where usuario_id = ? and token_hash = ?");
            $c->execute([$uid, hash('sha256', $token)]);
            if (((int) $c->fetchColumn()) > 0) _equipoBorrarCookie();
        }
        $st = $pdo->prepare("delete from equipos_confiables where usuario_id = ?");
        $st->execute([$uid]);
        return $st->rowCount();
    } catch (Throwable $e) {
        error_log('Equipos: no se pudieron quitar los equipos del usuario: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Vuelve a firmar los equipos recordados de un usuario con la huella de AHORA.
 *
 * Lo usa claveRehashSiHaceFalta() (includes/claves.php) cuando reescribe el
 * hash de una contraseña que NO cambió, solo pasó a Argon2id: la huella de un
 * equipo incluye el hash, así que sin esto todos los equipos del usuario se
 * invalidarían sin que nadie haya cambiado nada.
 *
 * No es un atajo para saltarse la revocación: solo sirve para pasar de una
 * huella concreta —la que había antes— a la de ahora. Un equipo firmado con
 * cualquier otra huella no se toca.
 *
 * @param string $estadoViejo La huella tal como estaba ANTES del cambio.
 * @return int cuántos equipos quedaron refirmados
 */
function equiposReFirmar(PDO $pdo, int $uid, string $estadoViejo): int {
    if (!equiposDisponible($pdo) || $uid < 1 || $estadoViejo === '') return 0;
    try {
        $estadoNuevo = _equipoEstado($pdo, $uid);
        if ($estadoNuevo === null || hash_equals($estadoViejo, $estadoNuevo)) return 0;
        $st = $pdo->prepare("update equipos_confiables set estado_hash = ?
                             where usuario_id = ? and estado_hash = ?");
        $st->execute([$estadoNuevo, $uid, $estadoViejo]);
        return $st->rowCount();
    } catch (Throwable $e) {
        error_log('Equipos: no se pudieron refirmar los equipos: ' . $e->getMessage());
        return 0;
    }
}

/**
 * EL CANDADO de las acciones críticas.
 *
 * Se llama justo después de requireAdmin() en las páginas de
 * EQUIPO_RUTAS_CRITICAS. Una sesión que entró TECLEANDO el código ya lo
 * demostró; una que entró por un equipo recordado no, así que aquí se le pide
 * una vez, y vale hasta que la sesión termine.
 *
 * No hace nada si:
 *  - no hay sesión, o la cuenta no tiene 2FA: entonces el gate de auth.php ya
 *    obliga a activarlo, y en pruebas locales no debe estorbar;
 *  - ya se tecleó el código en esta sesión.
 *
 * El destino de regreso lo decide el servidor y SOLO puede ser una de las
 * rutas críticas (o el inicio): nunca sale de un parámetro de la URL, para que
 * esto no sea una redirección abierta.
 *
 * Una sesión abierta antes de instalar esto no trae la marca, así que se le
 * pedirá el código una vez. Es conservador, y está bien que lo sea.
 */
function exigirCodigoEnAccionCritica(): void {
    if (empty($_SESSION['usuario_id'])) return;

    if (empty($_SESSION['twofa_enabled'])) {
        // En la COPIA DE DEMOSTRACION el gate de auth.php no obliga a activar el 2FA,
        // asi que un administrador SIN 2FA llega hasta aqui. En produccion eso no pasa
        // —el gate lo manda a activarlo antes—, y por eso este «return» era inocuo.
        // En la demostracion no puede serlo: sin segundo factor no hay acciones
        // criticas (respaldo, reiniciar, exportar huespedes, usuarios).
        if (function_exists('esEntornoDemostracion') && esEntornoDemostracion()) {
            // Una PANTALLA que lo explica, y no una redireccion con setFlash(): solo
            // recepcion pinta esos avisos, asi que al administrador lo habrian devuelto
            // al panel sin decirle nada, y en una demostracion eso parece un fallo.
            require_once __DIR__ . '/modulo_retirado.php';
            moduloRetirado('Esta acción',
                'No está disponible en la demostración: sin verificación en dos pasos no se permiten el '
                . 'respaldo, el reinicio de datos, la exportación de huéspedes, la gestión de usuarios, el borrado de huéspedes ni la reapertura de cuentas.');
        }
        return;
    }
    if (!empty($_SESSION['2fa_verificado_en'])) return;

    $ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $permitidas = array_map(fn($r) => BASE_URL . $r, EQUIPO_RUTAS_CRITICAS);

    $_SESSION['2fa_escalar_destino'] = in_array($ruta, $permitidas, true) ? $ruta : getHomeUrl();
    $_SESSION['2fa_escalar_time']    = time();
    $_SESSION['2fa_intentos']        = 0;

    header('Location: ' . BASE_URL . '/verificar_2fa.php');
    exit;
}
