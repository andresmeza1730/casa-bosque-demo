<?php
// ============================================================
// includes/fallos.php
// Captura de fallos no controlados — Casa Bosque
// ============================================================
// El problema que resuelve:
//
// Cuando PHP lanza una excepción que nadie captura, devuelve un 500 con
// el cuerpo vacío. El `ErrorDocument 500` del .htaccess NO lo reemplaza,
// porque esa directiva solo actúa sobre errores que genera el servidor,
// no sobre una respuesta que ya produjo un script. Las páginas de error
// del panel del hosting tienen la misma limitación. Resultado: el
// navegador muestra su propio "Esta página no funciona" y no queda
// ninguna pista de qué pasó.
//
// Esto lo arregla desde PHP, que es el único sitio donde se puede:
//   - Muestra la página caido.html, que ya existe y está diseñada.
//   - Añade un CÓDIGO DE REFERENCIA corto, visible en pantalla.
//   - Escribe ese mismo código en logs/fallos.log junto al detalle real,
//     para poder emparejar lo que vio el usuario con lo que pasó.
//   - Si es un fallo de CONFIGURACIÓN (claves de cifrado ausentes, base
//     de datos inalcanzable) lo dice, porque eso lo arregla el
//     administrador y necesita saber dónde mirar.
//
// Lo que NO hace:
//   - No muestra el mensaje de la excepción, ni rutas, ni líneas, ni
//     consultas. Todo eso va al log.
//   - No se activa en local: ahí conviene seguir viendo el error de PHP
//     tal cual para poder programar.
// ------------------------------------------------------------

/** Ruta del registro de fallos. El .htaccess de producción bloquea *.log. */
define('FALLOS_LOG', __DIR__ . '/../logs/fallos.log');

/**
 * ¿Estamos en local? Lo decide APP_URL del .env, igual que esEntornoLocal()
 * en auth.php. Se usa esa misma función cuando está cargada, para que las dos
 * no puedan discrepar; el guard permite que este archivo se cargue antes.
 *
 * Antes se miraba la cabecera Host por subcadena, y eso era manipulable: con
 * un "Host: localhost.atacante.com" el modo local se activaba desde fuera y
 * los fallos dejaban de registrarse en logs/fallos.log. No filtraba trazas
 * —este archivo nunca las imprime—, pero sí servía para hacer invisible en el
 * registro lo que se estuviera intentando.
 *
 * Sin APP_URL se asume producción: se registra el fallo. Es el lado seguro de
 * equivocarse, porque lo único que se pierde es ver el error de PHP en crudo.
 */
function _falloEsLocal(): bool {
    if (PHP_SAPI === 'cli') return true;
    if (function_exists('esEntornoLocal')) {
        return esEntornoLocal();
    }
    if (!function_exists('appUrlConfigurada')) {
        return false;   // sin forma de saberlo: se registra
    }
    $appUrl = appUrlConfigurada();
    if ($appUrl === null) return false;
    $host = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
    foreach (['localhost', '127.0.0.1', '::1', '.local', '.test'] as $aguja) {
        if (strpos($host, $aguja) !== false) return true;
    }
    return false;
}

/**
 * ¿El fallo es de configuración del servidor?
 * Son los que lanzan cifrado.php y database.php cuando el .env está
 * incompleto: no es un error de programación, lo resuelve quien
 * administra el servidor.
 */
function _falloEsConfiguracion(string $mensaje): bool {
    return stripos($mensaje, 'configuraci') !== false
        || stripos($mensaje, 'conexión con la base') !== false
        || stripos($mensaje, 'conexion con la base') !== false;
}

/**
 * Apunta el fallo en logs/fallos.log y devuelve el código de referencia
 * que se le enseña al usuario.
 */
function _falloRegistrar(string $tipo, string $mensaje, string $archivo, int $linea, string $traza = ''): string {
    $ref = substr(bin2hex(random_bytes(6)), 0, 8);

    $entrada = sprintf(
        "[%s] ref=%s %s\n  mensaje: %s\n  en: %s:%d\n  url: %s %s\n  usuario: %s\n%s\n",
        date('Y-m-d H:i:s'),
        $ref,
        $tipo,
        $mensaje,
        $archivo,
        $linea,
        $_SERVER['REQUEST_METHOD'] ?? '-',
        // Solo la RUTA, sin la cadena de consulta. El `?q=` de los buscadores
        // lleva el documento del huesped cuando se busca por documento, y este
        // archivo no es sitio para eso: lo lee quien tenga el gestor de
        // archivos del hosting. Es el mismo recorte que ya hacen
        // avisos/publico/index.php (strtok) e includes/equipos.php (parse_url).
        strtok((string) ($_SERVER['REQUEST_URI'] ?? '-'), '?'),
        isset($_SESSION['usuario_id']) ? ('id ' . (int) $_SESSION['usuario_id']) : 'sin sesión',
        $traza !== '' ? "  traza:\n" . preg_replace('/^/m', '    ', $traza) : ''
    );

    // Al log del proyecto, que se puede abrir desde el gestor de archivos
    // del hosting. Si la carpeta no existe o no se puede escribir, no se
    // insiste: el error_log del servidor queda como respaldo.
    $dir = dirname(FALLOS_LOG);
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents(FALLOS_LOG, $entrada, FILE_APPEND | LOCK_EX);
    }
    error_log('Casa Bosque ref=' . $ref . ' ' . $tipo . ': ' . $mensaje . ' en ' . $archivo . ':' . $linea);

    return $ref;
}

/**
 * Pinta la página de fallo reutilizando caido.html.
 *
 * Si la respuesta ya había empezado a salir, no se puede servir una
 * página entera sin dejar un documento a medias: en ese caso solo se
 * añade un aviso corto al final.
 */
function _falloMostrar(string $ref, bool $esConfiguracion): void {
    $yaSalioAlgo = headers_sent();

    if (!$yaSalioAlgo) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        // Que no se cachee una página de error.
        header('Cache-Control: no-store');
        // Vaciar lo que hubiera en buffers sin enviarlo.
        while (ob_get_level() > 0) { @ob_end_clean(); }
    }

    $nota = '<div style="max-width:620px;margin:22px auto;padding:14px 18px;border-radius:12px;'
          . 'background:#FBF9F4;border:1px solid #EDE3D2;font-family:Outfit,Arial,sans-serif;'
          . 'font-size:14px;line-height:1.6;color:#6E6054;text-align:center">';
    if ($esConfiguracion) {
        $nota .= '<b style="color:#2A1709">Falta configuración en el servidor.</b><br>'
               . 'Avise al administrador: debe revisar el archivo <code>.env</code> '
               . 'y el registro <code>logs/fallos.log</code>.<br>';
    }
    $nota .= 'Código de referencia: <code style="background:#F5EBDC;padding:2px 7px;border-radius:5px;'
           . 'font-weight:600;color:#3E2412">' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '</code>'
           . '</div>';

    if ($yaSalioAlgo) {
        echo $nota;
        return;
    }

    $plantilla = __DIR__ . '/../caido.html';
    $html = is_readable($plantilla) ? (string) file_get_contents($plantilla) : '';

    if ($html !== '' && strpos($html, '</body>') !== false) {
        echo str_replace('</body>', $nota . '</body>', $html);
        return;
    }

    // Respaldo mínimo, por si caido.html no estuviera.
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Casa Bosque — Servicio no disponible</title></head>'
       . '<body style="margin:0;padding:40px 16px;background:#FBF9F4;'
       . 'font-family:Arial,sans-serif;color:#2A1709;text-align:center">'
       . '<h1 style="font-size:20px">Servicio no disponible</h1>'
       . '<p style="color:#6E6054">No pudimos completar la operación. Intente de nuevo en unos minutos.</p>'
       . $nota . '</body></html>';
}

/**
 * Deja instalados los dos capturadores. Llamar una sola vez, al principio
 * de la petición.
 *
 * Se necesitan los dos porque cubren casos distintos:
 *   - set_exception_handler: excepciones que nadie capturó.
 *   - register_shutdown_function: errores fatales (E_ERROR), que no pasan
 *     por el manejador de excepciones.
 */
function activarCapturaDeFallos(): void {
    static $instalado = false;
    if ($instalado) return;
    $instalado = true;

    // En local no se toca nada: hay que poder ver el error de PHP.
    if (_falloEsLocal() || PHP_SAPI === 'cli') return;

    set_exception_handler(function (Throwable $e): void {
        try {
            $ref = _falloRegistrar(
                'excepción ' . get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            );
            _falloMostrar($ref, _falloEsConfiguracion($e->getMessage()));
        } catch (Throwable $otro) {
            // El capturador no debe ser la causa de otro fallo.
            http_response_code(500);
            echo 'Servicio no disponible.';
        }
    });

    register_shutdown_function(function (): void {
        $e = error_get_last();
        if ($e === null) return;
        if (!in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
        try {
            $ref = _falloRegistrar('error fatal', $e['message'], $e['file'], $e['line']);
            _falloMostrar($ref, _falloEsConfiguracion($e['message']));
        } catch (Throwable $otro) {
            http_response_code(500);
            echo 'Servicio no disponible.';
        }
    });
}
