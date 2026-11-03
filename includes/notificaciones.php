<?php
/**
 * Casa Bosque — Notificaciones Push (Firebase Cloud Messaging v1)
 *
 * Diseño de seguridad:
 *  - Las credenciales (private key) NO van en este archivo. Se leen desde un
 *    JSON externo ubicado fuera del directorio web público.
 *  - Acceso directo por navegador bloqueado (constante CASA_BOSQUE).
 *  - Errores aislados: si Firebase falla, la reserva o el check-in NO se rompen.
 *  - Timeouts cortos: un fallo de red no congela al usuario.
 *  - Token OAuth cacheado en archivo temporal para no pedirlo en cada envío.
 *  - Registro de envíos en log local para auditoría.
 */

if (!defined('CASA_BOSQUE')) {
    http_response_code(403);
    die('Acceso prohibido.');
}

/**
 * Ruta del JSON de credenciales de Firebase.
 *
 * IMPORTANTE: este archivo debe estar FUERA del directorio web (htdocs/public_html).
 * Si tu hosting no permite escribir fuera del docroot, ubícalo dentro de
 * una carpeta protegida con .htaccess (Deny from all).
 *
 * Ajusta la ruta según tu servidor. Ejemplos:
 *   InfinityFree:  /home/vol.../config_privado/casabosque-firebase.json
 *   Hostinger:     /home/usuario/private/casabosque-firebase.json
 *   Local (XAMPP): C:/xampp/config_privado/casabosque-firebase.json
 */
define('FIREBASE_CRED_PATH', dirname(__DIR__, 2) . '/casabosque-private/casabosque-firebase.json');

// Archivo de caché para el token OAuth (válido 1 hora).
//
// Va en la carpeta privada, NO en el temporal del sistema. En un hosting
// compartido ese temporal lo comparten todas las cuentas del servidor, y
// con un nombre fijo y predecible cualquiera de ellas podía leer el token
// —que da acceso a enviar notificaciones en nombre del hotel— o
// reemplazarlo por otro. La carpeta privada está fuera de la raíz web y
// solo la ve esta cuenta.
define('FIREBASE_TOKEN_CACHE', dirname(__DIR__, 2) . '/casabosque-private/fcm_token.json');

// Archivo de log de notificaciones (auditoría)
define('FIREBASE_LOG_PATH', __DIR__ . '/../logs/notificaciones.log');


// ─── Función pública principal ─────────────────────────────────────────────
/**
 * Envía una notificación push a todos los dispositivos suscritos al topic "all".
 *
 * @param string $titulo  Título corto que verá el usuario
 * @param string $mensaje Cuerpo del mensaje
 * @return bool           true si se envió, false si hubo error (no lanza excepción)
 */
function enviarNotificacionPush($titulo, $mensaje) {
    try {
        $credenciales = cargarCredencialesFirebase();
        if (!$credenciales) {
            registrarLogNotif('ERROR', 'No se pudieron cargar las credenciales', $titulo);
            return false;
        }

        $accessToken = obtenerAccessTokenFirebase($credenciales);
        if (!$accessToken) {
            registrarLogNotif('ERROR', 'No se pudo obtener access token', $titulo);
            return false;
        }

        $resultado = enviarMensajeFCM($credenciales['project_id'], $accessToken, $titulo, $mensaje);

        if ($resultado['ok']) {
            registrarLogNotif('OK', 'Notificación enviada', $titulo);
            return true;
        } else {
            registrarLogNotif('ERROR', 'FCM devolvió error: ' . $resultado['error'], $titulo);
            return false;
        }
    } catch (Exception $e) {
        // Captura cualquier error inesperado para que NO afecte la operación principal
        registrarLogNotif('EXCEPTION', $e->getMessage(), $titulo);
        return false;
    }
}


// ─── Helpers privados ──────────────────────────────────────────────────────

function cargarCredencialesFirebase() {
    if (!file_exists(FIREBASE_CRED_PATH)) {
        return null;
    }
    $raw = @file_get_contents(FIREBASE_CRED_PATH);
    if (!$raw) return null;

    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    if (empty($data['project_id']) || empty($data['client_email']) || empty($data['private_key'])) {
        return null;
    }
    return $data;
}

function obtenerAccessTokenFirebase($credenciales) {
    // Reutilizar token cacheado si todavía es válido
    if (file_exists(FIREBASE_TOKEN_CACHE)) {
        $cache = @json_decode(@file_get_contents(FIREBASE_TOKEN_CACHE), true);
        if (is_array($cache) && !empty($cache['token']) && !empty($cache['exp'])) {
            // Margen de 60s antes de la expiración
            if ($cache['exp'] > time() + 60) {
                return $cache['token'];
            }
        }
    }

    // Generar JWT para autenticación con Google
    $header = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $iat = time();
    $exp = $iat + 3600;
    $payload = base64UrlEncode(json_encode([
        'iss'   => $credenciales['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'exp'   => $exp,
        'iat'   => $iat,
    ]));

    $signature = '';
    $firmaOk = openssl_sign(
        $header . '.' . $payload,
        $signature,
        $credenciales['private_key'],
        OPENSSL_ALGO_SHA256
    );
    if (!$firmaOk) return null;

    $jwt = $header . '.' . $payload . '.' . base64UrlEncode($signature);

    // Intercambiar JWT por access_token
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => 'grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion=' . $jwt,
    ]);
    $respuesta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$respuesta) return null;

    $datos = json_decode($respuesta, true);
    if (empty($datos['access_token'])) return null;

    // Cachear token
    @file_put_contents(FIREBASE_TOKEN_CACHE, json_encode([
        'token' => $datos['access_token'],
        'exp'   => $exp,
    ]));

    return $datos['access_token'];
}

function enviarMensajeFCM($projectId, $accessToken, $titulo, $mensaje) {
    $url = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";

    $cuerpo = [
        'message' => [
            'topic'        => 'all',
            'notification' => [
                'title' => $titulo,
                'body'  => $mensaje,
            ],
            'android' => [
                'priority'     => 'high',
                'notification' => [
                    'channel_id' => 'reservas_channel',
                    'icon'       => 'ic_launcher',
                ],
            ],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($cuerpo),
    ]);
    $respuesta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200) {
        return ['ok' => true];
    }

    $msgError = $curlErr ?: "HTTP $httpCode: " . substr($respuesta, 0, 200);
    return ['ok' => false, 'error' => $msgError];
}

function base64UrlEncode($data) {
    return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
}

function registrarLogNotif($nivel, $detalle, $titulo) {
    try {
        $dir = dirname(FIREBASE_LOG_PATH);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $linea = sprintf(
            "[%s] %s | %s | %s%s",
            date('Y-m-d H:i:s'),
            $nivel,
            $titulo,
            $detalle,
            PHP_EOL
        );
        @file_put_contents(FIREBASE_LOG_PATH, $linea, FILE_APPEND);
    } catch (Exception $e) {
        // Si el log falla, no hacemos nada: la operación principal no se ve afectada
    }
}
