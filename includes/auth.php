<?php
// ── Zona horaria: hora real de Colombia en toda la app ──
// (el servidor por defecto suele estar en UTC, ~5h adelante)
date_default_timezone_set('America/Bogota');

// ── Captura de fallos no controlados ──
// Sin esto, una excepción que nadie capture sale como un 500 de cuerpo
// vacío: el ErrorDocument del .htaccess no la reemplaza y el navegador
// acaba mostrando su propio error, sin dejar rastro de la causa.
// Ver includes/fallos.php. En local no se activa.
require_once __DIR__ . '/fallos.php';
activarCapturaDeFallos();

// ============================================================
// Seguridad de transporte: forzar HTTPS + HSTS (OWASP A04 / ISO 27001)
// ============================================================
// Complementa al .htaccess (capa Apache). Aquí se resuelve el caso del
// proxy del hosting, donde la conexión del cliente puede ser HTTPS
// aunque $_SERVER['HTTPS'] llegue vacío/"off".

/** ¿La conexión con el cliente es segura (HTTPS), considerando el proxy? */
function esConexionSegura(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    if (strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on') return true;
    return false;
}

/**
 * Dirección pública del sitio declarada en el .env (APP_URL), o null.
 *
 * Existe para que las decisiones de seguridad no dependan de la cabecera
 * Host, que la pone quien hace la petición.
 */
function appUrlConfigurada(): ?string {
    static $url = null;
    static $leido = false;
    if ($leido) return $url;
    $leido = true;

    $valor = envPrivadoValor('APP_URL');
    if ($valor !== null && preg_match('~^https?://[A-Za-z0-9.\-]+(:\d+)?/?$~', $valor)) {
        return $url = rtrim($valor, '/');
    }
    return $url = null;   // sin la linea, o mal escrita: se ignora
}

/**
 * Valor de UNA clave del .env privado (casabosque-private/.env), o null.
 *
 * Es el unico lector de ese archivo para las decisiones de seguridad que se toman
 * en auth.php: APP_URL y APP_DEMO. Gana la primera linea que la declara.
 */
function envPrivadoValor(string $clave): ?string {
    static $cache = [];
    if (array_key_exists($clave, $cache)) return $cache[$clave];

    $envFile = dirname(__DIR__, 2) . '/casabosque-private/.env';
    if (!is_readable($envFile)) return $cache[$clave] = null;

    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#') continue;
        $pos = strpos($linea, '=');
        if ($pos === false) continue;
        if (trim(substr($linea, 0, $pos)) !== $clave) continue;
        return $cache[$clave] = trim(substr($linea, $pos + 1));
    }
    return $cache[$clave] = null;
}

/** ¿El nombre de host dado es de desarrollo? */
function _hostEsLocal(string $host): bool {
    $host = strtolower(trim($host));
    if ($host === '') return true;
    foreach (['localhost', '127.0.0.1', '::1', '.local', '.test'] as $aguja) {
        if (strpos($host, $aguja) !== false) return true;
    }
    return false;
}

/**
 * Avisa una vez por petición de que el .env no declara APP_URL.
 *
 * No corta la ejecución a propósito: dejar el hotel sin sistema por una línea
 * que falta sería peor que seguir con el criterio estricto.
 */
function _avisarFaltaAppUrl(string $donde): void {
    static $avisado = false;
    if ($avisado) return;
    $avisado = true;
    error_log('Seguridad: el .env no declara APP_URL (' . $donde . '). Se asume entorno '
            . 'de producción: se fuerza HTTPS y no se relaja el 2FA. Para desarrollo local, '
            . 'declara APP_URL=http://localhost en el .env.');
}

/**
 * ¿Estamos en entorno local (XAMPP/desarrollo)? Ahí NO se fuerza HTTPS.
 *
 * **Solo lo decide APP_URL del .env.** La cabecera Host la pone quien hace la
 * petición, así que nunca puede relajar un control: bastaba con mandar
 * "Host: localhost" para que la aplicación se creyera en desarrollo y dejara
 * de forzar HTTPS.
 *
 * Y si APP_URL no está, se asume PRODUCCIÓN, que es el criterio estricto. El
 * entorno de desarrollo tiene que declararse: `APP_URL=http://localhost`. Es
 * la misma regla que .env.example ya explica junto a APP_URL, aplicada también
 * aquí y no solo a los enlaces de los correos.
 */
function esEntornoLocal(): bool {
    $appUrl = appUrlConfigurada();
    if ($appUrl === null) {
        _avisarFaltaAppUrl('esEntornoLocal');
        return false;
    }
    return _hostEsLocal((string) parse_url($appUrl, PHP_URL_HOST));
}

if (PHP_SAPI !== 'cli' && !esEntornoLocal()) {
    if (!esConexionSegura()) {
        // Redirección permanente a la versión HTTPS de la misma URL.
        $destino = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $destino, true, 301);
        exit;
    }
    // Ya vamos por HTTPS: instruir al navegador a no volver a HTTP (1 año).
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ── Content-Security-Policy (mitiga XSS y clickjacking) ──
// Ya NO se usa Tailwind Play CDN (por eso no hace falta 'unsafe-eval'). Se
// mantiene 'unsafe-inline' porque la app usa estilos/scripts en línea; aun
// así se restringe a cdnjs (con SRI) y se bloquean object/base/form hacia
// orígenes ajenos. Firebase (FCM) es server-side, no requiere reglas.
if (PHP_SAPI !== 'cli') {
    header("Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
        . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; "
        . "img-src 'self' data: https:; "
        . "connect-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'");
}

// ── Configuración segura de sesión ──
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    // Las páginas llevan datos de huéspedes: ni el navegador ni un proxy deben
    // guardarlas, o el botón "atrás" en un equipo compartido le mostraría a
    // alguien la cuenta de otro. PHP ya manda "no-store, no-cache,
    // must-revalidate" con este valor, pero por defecto lo toma del php.ini del
    // hosting: dejarlo escrito aquí es lo que hace que no dependa de eso.
    // Es la cabecera que .htaccess da por supuesta al excluir los .php del caché.
    ini_set('session.cache_limiter', 'nocache');
    if (esConexionSegura()) {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

require_once __DIR__ . '/mantenimiento.php';   // modo mantenimiento (si el flag existe, corta aquí)

// Version del sistema. Se sube a mano al publicar, y la etiqueta de git
// del mismo numero es la que manda: APP_VERSION solo sirve para poder
// preguntarle al hotel que version tiene abierta cuando reporte un fallo.
define('APP_VERSION', '4.3.0');

define('BASE_URL', '');

// ── Auto-logout por inactividad (personal) ──
// Cierra la sesión tras SESION_INACTIVIDAD_MIN minutos sin actividad. Las
// peticiones de fondo (sondeo de disponibilidad) definen PETICION_BACKGROUND
// para no mantener viva la sesión ni forzar redirecciones sobre respuestas JSON.
const SESION_INACTIVIDAD_MIN = 30;

// ── Duración máxima de una sesión ──
// Tope ABSOLUTO desde que se inició, se use o no. Sin esto, trabajar cada 29
// minutos mantenía una sesión viva para siempre, y el estándar pide las dos
// cosas. Doce horas cubre de sobra el turno más largo del hotel y obliga a
// volver a entrar al día siguiente. Lo aplica sesionVigente()
// (includes/sesiones.php) contra `sesiones_activas.creada_en`, que ya existía.
const SESION_MAXIMA_HORAS = 12;

// ── Registro de sesiones abiertas ──
// Se carga aquí porque lo usan los dos bloques siguientes. El archivo se
// desactiva solo mientras la tabla sesiones_activas no exista, y nunca
// echa a nadie por un error de base de datos.
// Va con guarda porque auth.php no carga config/database.php: hay
// páginas (logout, errores) que lo incluyen sin base de datos.
$pdoSesiones = null;
if (isset($_SESSION['usuario_id']) && function_exists('getDB')) {
    require_once __DIR__ . '/sesiones.php';
    try { $pdoSesiones = getDB(); } catch (Throwable $e) { $pdoSesiones = null; }
}

/**
 * Cierra la sesión del personal y manda al acceso con el motivo.
 *
 * Existe para que no haya tres copias del mismo ritual —marcar, vaciar, borrar
 * la cookie, destruir, redirigir—, que es como se acaba arreglando una y
 * olvidando las otras. Nunca vuelve: siempre termina en exit.
 *
 * @param string|null $motivo Para `sesiones_activas.motivo_cierre`. Null cuando
 *                            la fila ya quedó marcada por otro (p. ej. un cambio
 *                            de contraseña desde otro dispositivo).
 */
function authCerrarSesionY(?PDO $pdo, ?string $motivo, string $aviso): void {
    if ($pdo !== null && $motivo !== null && function_exists('sesionCerrarActual')) {
        sesionCerrarActual($pdo, $motivo);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ' . BASE_URL . '/acceso.php?sesion=' . $aviso);
    exit;
}

if (isset($_SESSION['usuario_id']) && !defined('PETICION_BACKGROUND')) {
    $ahora = time();
    if (isset($_SESSION['ultima_actividad'])
        && ($ahora - $_SESSION['ultima_actividad']) > SESION_INACTIVIDAD_MIN * 60) {
        // Dejar constancia de que esta sesión se cerró, para que no siga
        // apareciendo como abierta en la pantalla de seguridad.
        authCerrarSesionY($pdoSesiones, 'inactividad', 'expirada');
    }
    $_SESSION['ultima_actividad'] = $ahora;
}

// ── ¿Alguien cerró esta sesión? ──
// Al cambiar una contraseña, todas las sesiones de ese usuario quedan
// cerradas en la base. Aquí se detecta y se lo saca del sistema. También
// cubre el caso de cerrar una sesión a mano desde Seguridad.
if ($pdoSesiones !== null && !defined('PETICION_BACKGROUND') && isset($_SESSION['usuario_id'])) {
    $porQueSeCorto = null;
    if (!sesionVigente($pdoSesiones, (int) $_SESSION['usuario_id'], $porQueSeCorto)) {
        // Sin motivo: la fila ya la marcó quien cerró la sesión —o la marcó
        // sesionVigente() al ver que pasó de SESION_MAXIMA_HORAS, y entonces
        // el aviso tiene que decir eso y no «alguien cerró tu sesión».
        authCerrarSesionY($pdoSesiones, null, $porQueSeCorto === 'duracion_maxima' ? 'jornada' : 'cerrada');
    }
    sesionTocar($pdoSesiones);
}

// ── ¿La cuenta sigue siendo la que era? ──
// La autorización se resuelve con lo que hay EN LA SESIÓN: isAdmin() lee
// $_SESSION['usuario_rol'], que se fija al entrar. Hasta aquí no se volvía a
// mirar, así que a quien le bajaban el rol o le desactivaban la cuenta mientras
// tenía la sesión abierta seguía trabajando con los permisos de antes hasta
// cerrarla o hasta los 30 minutos de inactividad. Desactivar a alguien no
// surtía efecto inmediato, que es justo lo que promete ese botón.
//
// Se arregla aquí, en la raíz, y no solo en la pantalla de Usuarios: así cubre
// también el cambio hecho DIRECTO EN LA BASE, que ningún gancho de código ve.
// La pantalla de Usuarios además cierra las sesiones de forma explícita —
// cinturón y tirantes: cada camino falla de una manera distinta—.
//
// Es una consulta por petición y por clave primaria.
//
// MISMO CRITERIO QUE EL REGISTRO DE SESIONES: ante un error de base de datos no
// se echa a nadie; solo expulsa un dato concluyente. Un hipo de la base no debe
// sacar a la recepcionista en mitad de un check-in.
if ($pdoSesiones !== null && !defined('PETICION_BACKGROUND') && isset($_SESSION['usuario_id'])) {
    try {
        $stCuenta = $pdoSesiones->prepare("select rol, activo from usuarios where id = ?");
        $stCuenta->execute([(int) $_SESSION['usuario_id']]);
        $cuenta = $stCuenta->fetch();

        if ($cuenta === false || (int) $cuenta['activo'] !== 1) {
            // Cuenta desactivada, o borrada: fuera en el acto.
            authCerrarSesionY($pdoSesiones, 'desactivada', 'desactivada');
        }

        // El rol lo manda la base, no la sesión. Si lo bajaron, los permisos se
        // pierden en esta misma petición; si lo subieron, se ganan igual.
        if (($_SESSION['usuario_rol'] ?? '') !== $cuenta['rol']) {
            $_SESSION['usuario_rol'] = $cuenta['rol'];
        }
    } catch (Throwable $e) {
        error_log('Auth: no se pudo revalidar la cuenta: ' . $e->getMessage());
    }
}

// ── 2FA obligatorio para administradores ──
// En localhost se omite únicamente la obligación de configurarlo, para facilitar
// las pruebas. En hosts públicos sigue siendo obligatorio. Si ya está activado,
// la verificación al iniciar sesión sigue aplicando también en local.
function esHostLocalParaPruebas(): bool {
    // La decisión sale SOLO del .env, nunca de la cabecera Host. Importa más
    // aquí que en el salto a HTTPS: lo que se omite es la OBLIGACIÓN de
    // configurar el 2FA, así que si bastara con mandar "Host: localhost" se
    // podría saltar ese control desde fuera.
    //
    // Sin APP_URL se asume producción, igual que en esEntornoLocal(): el 2FA
    // sigue siendo obligatorio.
    $appUrl = appUrlConfigurada();
    if ($appUrl === null) {
        _avisarFaltaAppUrl('esHostLocalParaPruebas');
        return false;
    }

    $host = strtolower(trim((string) parse_url($appUrl, PHP_URL_HOST)));
    $host = trim($host, '[]');
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

/** El sitio de verdad. El modo demostracion NO puede activarse cuando APP_URL apunta aqui. */
const HOST_PRODUCCION = 'casabosque.online';

/**
 * ¿Es esta una COPIA DE DEMOSTRACION del sistema? (3.9.0)
 *
 * Existe para que, el dia de la sustentacion, la gente pruebe el sistema con una
 * cuenta de administrador SIN tener que activar el 2FA, que es lo que el gate de
 * mas abajo exige a todo administrador en un host publico.
 *
 * Se decide SOLO por el .env privado —nunca por la cabecera Host ni por nada que
 * mande quien hace la peticion— y exige TRES cosas a la vez:
 *
 *   1. APP_DEMO=1, exactamente: «true», «yes» o « 1 » no cuentan;
 *   2. que APP_URL este declarada: sin ella se asume produccion, como en todo auth.php;
 *   3. que el host de APP_URL NO sea el de produccion (casabosque.online ni www.).
 *
 * La tercera es el seguro: aunque alguien copie por error el .env de la demostracion
 * a produccion, el 2FA seguiria siendo obligatorio.
 *
 * Que sea demostracion NO apaga el 2FA de quien ya lo tiene activado: solo quita la
 * OBLIGACION de activarlo. Y las acciones criticas (respaldo, reiniciar, exportar
 * huespedes, usuarios) quedan CERRADAS a quien no lo tiene: ver
 * exigirCodigoEnAccionCritica() en includes/equipos.php.
 */
function esEntornoDemostracion(): bool {
    static $resultado = null;
    if ($resultado !== null) return $resultado;

    $appUrl = appUrlConfigurada();
    if ($appUrl === null) return $resultado = false;

    $host = strtolower(trim((string) parse_url($appUrl, PHP_URL_HOST)));
    if ($host === HOST_PRODUCCION || $host === 'www.' . HOST_PRODUCCION) return $resultado = false;

    return $resultado = (envPrivadoValor('APP_DEMO') === '1');
}

if (isset($_SESSION['usuario_id'])
    && ($_SESSION['usuario_rol'] ?? '') === 'admin'
    && empty($_SESSION['twofa_enabled'])
    && !esHostLocalParaPruebas()
    && !esEntornoDemostracion()
    && !defined('PAGINA_SIN_2FA_GATE')) {
    header('Location: ' . BASE_URL . '/modules/usuarios/seguridad.php?forzar=1');
    exit;
}

function requireLogin(): void {
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin(); // primero asegura sesión (si no, va al login)
    if (!isAdmin()) {
        // Un recepcionista NO puede entrar a secciones de admin.
        // En vez de un 403 seco, lo enviamos a SU panel con un aviso.
        setFlash('No tienes permiso para acceder a esa sección. Es exclusiva de administradores.');
        header('Location: ' . getHomeUrl());
        exit;
    }
}

// Exige sesión válida (recepcionista o admin). Para páginas operativas.
function requireStaff(): void {
    requireLogin();
}

function isAdmin(): bool {
    return isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'admin';
}

/**
 * Página de inicio según el rol.
 * Admin → dashboard completo. Recepcionista → su panel.
 */
function getHomeUrl(): string {
    return isAdmin()
        ? BASE_URL . '/dashboard.php'
        : BASE_URL . '/modules/recepcion/index.php';
}

/**
 * Guarda un mensaje temporal para mostrar en la siguiente página.
 */
function setFlash(string $mensaje, string $tipo = 'warning'): void {
    $_SESSION['flash_msg']  = $mensaje;
    $_SESSION['flash_tipo'] = $tipo;
}

/**
 * Devuelve y borra el mensaje flash (si existe). Llamar en la vista.
 * Retorna ['msg'=>..., 'tipo'=>...] o null.
 */
function getFlash(): ?array {
    if (empty($_SESSION['flash_msg'])) {
        return null;
    }
    $flash = [
        'msg'  => $_SESSION['flash_msg'],
        'tipo' => $_SESSION['flash_tipo'] ?? 'warning',
    ];
    unset($_SESSION['flash_msg'], $_SESSION['flash_tipo']);
    return $flash;
}

function getCurrentUserId(): ?int {
    return $_SESSION['usuario_id'] ?? null;
}

function getCurrentUserName(): string {
    return $_SESSION['usuario_nombre'] ?? 'Usuario';
}

function getCurrentUserRole(): string {
    return $_SESSION['usuario_rol'] ?? 'recepcionista';
}

require_once __DIR__ . '/auditoria.php';   // gravedad, lista blanca y alertas: ver su cabecera

/**
 * Escribe un registro de auditoría. Es la ÚNICA puerta: el resto del sistema no
 * hace `insert into auditoria` por su cuenta.
 *
 * Los tres últimos parámetros son opcionales y la firma de siempre sigue valiendo:
 *   $antes / $despues  el estado del registro antes y después del cambio. Pasan por
 *                      la lista blanca de includes/auditoria.php; lo que no esté ahí
 *                      (contraseñas, documentos, teléfonos…) se descarta.
 *   $usuarioId         quién lo hizo cuando aún no hay sesión (un acceso fallido).
 *
 * Mientras no se ejecute sql/migration_026_auditoria_ampliada.sql las columnas
 * nuevas no existen, y se escribe como antes.
 */
function registrarAuditoria(PDO $pdo, string $tabla, ?int $registroId, string $accion, string $detalle,
                            ?array $antes = null, ?array $despues = null, ?int $usuarioId = null): void {
    $uid = $usuarioId ?? getCurrentUserId();
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (!auditoriaColumnas($pdo)) {
        $pdo->prepare("INSERT INTO auditoria (tabla_afectada, registro_id, accion, detalle, usuario_id, ip_address)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$tabla, $registroId, $accion, $detalle, $uid, $ip]);
        return;
    }

    $pdo->prepare("INSERT INTO auditoria
                       (tabla_afectada, registro_id, accion, detalle, severidad, usuario_id, ip_address,
                        user_agent, sesion_hash, datos_antes, datos_despues)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $tabla, $registroId, $accion, $detalle, auditoriaSeveridad($tabla, $accion), $uid, $ip,
            auditoriaAgente() ?: null, auditoriaSesionHash(),
            auditoriaDatos($antes), auditoriaDatos($despues),
        ]);
}

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time'])
        || (time() - $_SESSION['csrf_token_time']) > 3600) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken()) . '">';
}

function validateCsrf(): bool {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    $valid = hash_equals($_SESSION['csrf_token'], $token);
    // Regenerar token después de validar para prevenir reutilización
    unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
    return $valid;
}

/**
 * Comprueba el testigo SIN gastarlo.
 *
 * Para los POST que no cambian nada y se repiten muchas veces con el mismo
 * testigo: hoy solo el buscador que sugiere nombres mientras se teclea
 * (modules/huespedes/buscar.php), que manda una petición por pulsación.
 * Con validateCsrf() la primera letra gastaría el testigo y dejaría sin él al
 * resto de los formularios de la página.
 *
 * NO sirve para una acción que escriba: ahí hay que gastarlo, porque es lo que
 * impide que el mismo envío se repita.
 */
function csrfTokenValido(): bool {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
