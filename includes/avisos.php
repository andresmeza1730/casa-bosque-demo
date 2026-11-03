<?php
/**
 * Avisos del proveedor — lado del hotel (consumidor)
 * ============================================================
 *
 * Qué hace: trae un aviso publicado en el servicio externo de avisos y lo
 * deja listo para que el header lo pinte al personal al entrar.
 *
 * TRES REGLAS QUE NO SE NEGOCIAN, porque este archivo mete una dependencia
 * externa dentro de un sistema que funciona:
 *
 *  1. FALLA ABIERTO. Si el servicio está caído, lento, devuelve basura o no
 *     está configurado, aquí se devuelve null y el panel sigue exactamente
 *     igual. El sistema del hotel no puede depender de que mi servicio viva.
 *
 *  2. LA PETICIÓN LA HACE PHP, NO EL NAVEGADOR. La CSP del sistema declara
 *     `connect-src 'self'`, así que un fetch() del navegador a otro dominio
 *     lo bloquearía el propio navegador. Y además así el token no viaja al
 *     cliente.
 *
 *  3. EL TEXTO ES DATO, NUNCA HTML. Viene de fuera del sistema. Si se
 *     pintara sin escapar sería una puerta de XSS directa al panel, abierta
 *     por el propio proveedor. Se valida aquí y se escapa al pintar.
 *
 * Si no hay AVISOS_URL en el .env, la función devuelve null y no se hace ni
 * una petición: la característica está apagada y no cuesta nada.
 */

define('AVISO_CACHE',        dirname(__DIR__, 2) . '/casabosque-private/aviso_cache.json');
define('AVISO_CACHE_SEG',    600);   // 10 min: una llamada cada 10 min, no una por página
define('AVISO_TIMEOUT_SEG',  2);     // tope duro: el panel no espera más que esto
define('AVISO_MAX_BYTES',    8192);  // un aviso es texto corto; más es sospechoso

/**
 * Lee AVISOS_URL y AVISOS_TOKEN del .env privado.
 * Mismo patrón que correoConfig() y _clavesCifrado().
 */
function avisosConfig(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $envFile = dirname(__DIR__, 2) . '/casabosque-private/.env';
    $env = [];
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $p = strpos($line, '=');
            if ($p === false) continue;
            $env[trim(substr($line, 0, $p))] = trim(substr($line, $p + 1));
        }
    }
    $url = trim($env['AVISOS_URL'] ?? '');
    // Solo https y con dominio: una URL mal escrita no debe convertirse en
    // una petición a cualquier parte, y un aviso no viaja en claro.
    //
    // Única excepción: http contra 127.0.0.1 o localhost, para poder probar
    // el servicio en una máquina de desarrollo sin montar un certificado.
    // No es un agujero: esa URL la escribe el operador en el .env privado, y
    // una petición a 127.0.0.1 no sale de la máquina.
    $valida = preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,}(?::\d+)?(/|$)#i', $url)
           || preg_match('#^http://(127\.0\.0\.1|localhost)(?::\d+)?(/|$)#i', $url);
    if ($url !== '' && !$valida) {
        error_log('Avisos: AVISOS_URL no tiene un formato válido, se ignora: ' . $url);
        $url = '';
    }
    return $cfg = [
        'url'   => $url,
        'token' => trim($env['AVISOS_TOKEN'] ?? ''),
    ];
}

function avisosHabilitado(): bool
{
    return avisosConfig()['url'] !== '';
}

/**
 * Deja un aviso en su forma mínima y segura, o null si no sirve.
 *
 * Todo lo que no se reconoce se descarta. No se confía en que el servicio
 * mande lo que promete: puede estar en otra versión, o alguien puede haber
 * puesto otra cosa en esa URL.
 */
function _avisoSanear($dato): ?array
{
    if (!is_array($dato)) return null;
    if (empty($dato['activo'])) return null;

    $texto = function ($v, int $max): string {
        if (!is_scalar($v)) return '';
        // Se quitan los caracteres de control: un aviso es una línea de texto.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $v);
        return mb_substr(trim((string) $s), 0, $max);
    };

    $titulo  = $texto($dato['titulo']  ?? '', 120);
    $mensaje = $texto($dato['mensaje'] ?? '', 600);
    if ($mensaje === '') return null;           // sin mensaje no hay aviso

    $tipo = strtolower((string) ($dato['tipo'] ?? 'info'));
    if (!in_array($tipo, ['info', 'aviso', 'mantenimiento'], true)) $tipo = 'info';

    // ¿Caducó? Se compara en el servidor del hotel, no se confía en el reloj
    // del otro lado.
    $caduca = $texto($dato['caduca'] ?? '', 25);
    if ($caduca !== '') {
        $ts = strtotime($caduca);
        if ($ts === false || $ts < time()) return null;
    }

    $horas = (int) ($dato['recordar_horas'] ?? 12);
    if ($horas < 1)    $horas = 1;
    if ($horas > 720)  $horas = 720;            // un mes es el techo razonable

    // El id identifica la versión del aviso: si cambia el texto, cambia el
    // id, y vuelve a salir aunque ya lo hubieran cerrado.
    $id = $texto($dato['id'] ?? '', 64);
    if ($id === '') $id = substr(hash('sha256', $tipo . '|' . $titulo . '|' . $mensaje), 0, 16);

    return [
        'id'             => $id,
        'tipo'           => $tipo,
        'titulo'         => $titulo,
        'mensaje'        => $mensaje,
        'recordar_horas' => $horas,
        // La caducidad VIAJA a la caché. Sin ella, avisoVigente() no podría
        // volver a comprobarla sobre el valor cacheado, y un aviso caducado
        // seguiría saliendo. Ver la comprobación en esa función.
        'caduca'         => $caduca,
    ];
}

/**
 * ¿Este aviso ya caducó?
 *
 * Se pregunta DOS veces por cosas distintas: al sanear lo que llega del
 * servicio, y otra vez sobre lo que hay en caché. La segunda hace falta porque
 * la caché dura 10 minutos y, si el servicio de avisos está caído,
 * avisoVigente() sigue devolviendo «lo último que se supo» SIN TOPE DE EDAD. Un
 * aviso de mantenimiento que caducó el sábado seguiría saliendo el jueves.
 */
function _avisoCaducado(?array $aviso): bool
{
    if ($aviso === null) return false;
    $caduca = trim((string) ($aviso['caduca'] ?? ''));
    if ($caduca === '') return false;
    $ts = strtotime($caduca);
    return $ts === false || $ts < time();
}

/**
 * Lee la caché.
 *
 * Con $aunqueVieja = true devuelve el contenido sin mirar la edad: sirve para
 * seguir mostrando el último aviso conocido mientras se refresca por detrás.
 */
function _avisoDesdeCache(bool $aunqueVieja = false): ?array
{
    if (!is_readable(AVISO_CACHE)) return null;
    if (!$aunqueVieja) {
        $edad = time() - (int) @filemtime(AVISO_CACHE);
        if ($edad > AVISO_CACHE_SEG) return null;
    }
    $j = json_decode((string) @file_get_contents(AVISO_CACHE), true);
    if (!is_array($j)) return null;
    // La caché guarda también los "no hay aviso", para no preguntar cada vez.
    return array_key_exists('aviso', $j) ? $j : null;
}

/**
 * Guarda el resultado, incluso cuando es "no hay aviso".
 * Devuelve false si no se pudo escribir.
 */
function _avisoGuardarCache(?array $aviso): bool
{
    $dir = dirname(AVISO_CACHE);
    if (!is_dir($dir)) {
        error_log('Avisos: no existe ' . $dir . ', no se puede cachear.');
        return false;
    }
    $ok = @file_put_contents(AVISO_CACHE, json_encode(['aviso' => $aviso, 'en' => time()]));
    if ($ok === false) {
        error_log('Avisos: no se pudo escribir ' . AVISO_CACHE . ' (permisos de la carpeta).');
        return false;
    }
    return true;
}

/**
 * ¿Se puede escribir la caché?
 *
 * Importa porque sin caché el diseño normal no avanza nunca: `avisoVigente()`
 * lee la caché, no la encuentra, programa el refresco para después de la
 * página... y el refresco tampoco puede guardar nada. El aviso no aparecería
 * jamás, y sin ningún síntoma en pantalla.
 *
 * Detectarlo permite caer a la consulta en línea: pagar el tiempo de espera
 * es malo, pero no mostrar nunca el aviso es peor, y además queda anotado en
 * el registro para poder arreglar los permisos.
 */
function _avisoCachePosible(): bool
{
    $dir = dirname(AVISO_CACHE);
    if (is_file(AVISO_CACHE)) return is_writable(AVISO_CACHE);
    return is_dir($dir) && is_writable($dir);
}

/**
 * El aviso vigente, o null. Nunca lanza y NUNCA espera por la red.
 *
 * Lee solo la caché. Si está vencida, programa la actualización para
 * DESPUÉS de entregar la página y devuelve lo que tenga a mano.
 *
 * Esto es lo que hace que la característica sea gratis para el hotel: la
 * primera versión consultaba el servicio dentro de la petición, y con el
 * servicio caído el panel pasaba de 50 ms a 2.030 ms esperando el tope de
 * tiempo. Medido. Ahora nadie espera nunca: en el peor caso el aviso sale
 * una página más tarde.
 */
function avisoVigente(): ?array
{
    if (!avisosHabilitado()) return null;

    $cache = _avisoDesdeCache();
    if ($cache !== null) {
        // Aunque la caché esté al día, el aviso pudo caducar dentro de esos
        // 10 minutos: la caducidad se comprueba SIEMPRE aquí.
        return _avisoCaducado($cache['aviso']) ? null : $cache['aviso'];
    }

    // Sin caché escribible el refresco diferido no serviría de nada: no
    // podría guardar lo que trajera, y la siguiente página volvería a
    // encontrarse sin caché. Se consulta en línea, aunque cueste la espera.
    if (!_avisoCachePosible()) {
        return _avisoRefrescar();
    }

    // Caché vencida o inexistente: refrescar por detrás, no ahora.
    $diferido = __DIR__ . '/diferido.php';
    if (is_readable($diferido)) {
        require_once $diferido;
        if (function_exists('diferir')) {
            diferir(static function () { _avisoRefrescar(); }, 'refrescar avisos');
        }
    } else {
        // Sin el mecanismo de trabajo diferido tampoco hay forma de refrescar
        // por detrás: mejor en línea que nunca.
        return _avisoRefrescar();
    }

    // Mientras llega, se muestra lo último que se supo, aunque esté viejo.
    // Un aviso de mantenimiento con diez minutos de retraso sigue sirviendo;
    // frenar el panel por tenerlo al segundo, no.
    $viejo = _avisoDesdeCache(true);
    if ($viejo === null) return null;
    return _avisoCaducado($viejo['aviso']) ? null : $viejo['aviso'];
}

/**
 * Consulta el servicio y actualiza la caché. Se llama desde el trabajo
 * diferido, nunca desde el renderizado de una página.
 */
function _avisoRefrescar(): ?array
{
    if (!avisosHabilitado()) return null;

    $cfg = avisosConfig();
    $url = $cfg['url'];
    if ($cfg['token'] !== '') {
        $url .= (strpos($url, '?') === false ? '?' : '&') . 't=' . urlencode($cfg['token']);
    }

    $cuerpo = null;
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => AVISO_TIMEOUT_SEG,
                CURLOPT_CONNECTTIMEOUT => AVISO_TIMEOUT_SEG,
                CURLOPT_FOLLOWLOCATION => false,   // una redirección aquí es sospechosa
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'CasaBosque/avisos',
            ]);
            $cuerpo = curl_exec($ch);
            $http   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err    = curl_error($ch);
            curl_close($ch);
            if ($cuerpo === false || $http !== 200) {
                error_log('Avisos: no se pudo consultar (' . ($err !== '' ? $err : 'HTTP ' . $http) . ')');
                $cuerpo = null;
            }
        } else {
            // Sin curl: se intenta con el envoltorio de PHP, con el mismo tope.
            $ctx = stream_context_create(['http' => [
                'timeout'       => AVISO_TIMEOUT_SEG,
                'follow_location' => 0,
                'ignore_errors' => true,
            ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
            $cuerpo = @file_get_contents($url, false, $ctx, 0, AVISO_MAX_BYTES + 1);
            if ($cuerpo === false) $cuerpo = null;
        }
    } catch (Throwable $e) {
        error_log('Avisos: excepción al consultar: ' . $e->getMessage());
        $cuerpo = null;
    }

    if ($cuerpo === null) {
        // No se cachea el fallo tan largo como un éxito: se reintenta antes.
        // Pero tampoco se insiste en cada página: se guarda un "nada".
        _avisoGuardarCache(null);
        return null;
    }
    if (strlen($cuerpo) > AVISO_MAX_BYTES) {
        error_log('Avisos: respuesta demasiado grande, se descarta.');
        _avisoGuardarCache(null);
        return null;
    }

    $aviso = _avisoSanear(json_decode($cuerpo, true));
    _avisoGuardarCache($aviso);
    return $aviso;
}

/**
 * ¿Hay que mostrarlo a quien está mirando ahora?
 *
 * Sale una vez al entrar —es lo que se pidió— y vuelve a salir cuando pasan
 * las horas configuradas. Se guarda en la sesión: no hace falta tocar la base
 * de datos, y cada persona lleva su propia cuenta.
 */
function avisoDebeMostrarse(array $aviso): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) return true;
    $visto = $_SESSION['avisos_vistos'][$aviso['id']] ?? null;
    if ($visto === null) return true;
    return (time() - (int) $visto) > ($aviso['recordar_horas'] * 3600);
}

/**
 * Lo marca como visto para esta sesión.
 *
 * La lista se recorta porque desde que el id incluye el momento de
 * publicación, cada pulsación de "Publicar" crea un id nuevo: en una sesión
 * larga se acumularían sin tope. Solo hacen falta los recientes — los
 * antiguos no van a volver a aparecer nunca, porque ese aviso ya no existe.
 */
function avisoMarcarVisto(string $id): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    if (!isset($_SESSION['avisos_vistos']) || !is_array($_SESSION['avisos_vistos'])) {
        $_SESSION['avisos_vistos'] = [];
    }
    // Se quita y se vuelve a poner para que quede al final: así el orden del
    // array es el orden de visto, y recortar por el principio deja siempre
    // los más recientes. Ordenar por la marca de tiempo no serviría, porque
    // varios avisos vistos en el mismo segundo empatan y el recorte se
    // quedaría con cualquiera.
    unset($_SESSION['avisos_vistos'][$id]);
    $_SESSION['avisos_vistos'][$id] = time();
    if (count($_SESSION['avisos_vistos']) > 20) {
        $_SESSION['avisos_vistos'] = array_slice($_SESSION['avisos_vistos'], -20, 20, true);
    }
}
