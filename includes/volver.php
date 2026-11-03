<?php
// ============================================================
// includes/volver.php
// A dónde lleva el botón «Volver» de las páginas autónomas
// ============================================================
//
// Los comprobantes imprimibles (modules/checkout/factura_pdf.php y
// modules/checkout/ticket.php) y las páginas legales (politica-datos.php,
// politica-menores.php, autorizacion-menor.php) son páginas autónomas: no
// incluyen el menú del sistema, así que ese botón es su ÚNICA salida.
//
// Tenían el destino escrito a mano, `href="index.php"`, que desde la carpeta
// checkout siempre resolvía a Check-out. Quien abría una factura desde
// Comprobantes o desde Facturación acababa en un módulo que no era el suyo —
// el «comprobantes me manda a check out».
//
// Las páginas legales tenían algo peor: `href="javascript:history.back()"`.
// Se abren TODAS con `target="_blank"`, y una pestaña nueva no tiene historial,
// así que ese botón no hacía absolutamente nada. Ahora llevan el origen en la
// URL como el resto.
//
// Ahora el origen viaja en la URL, en `?volver=`, y aquí se traduce contra una
// lista blanca. El valor recibido NO se usa nunca para construir la ruta: un
// parámetro de la URL lo escribe cualquiera, y concatenarlo sería una
// redirección abierta. Tampoco se mira HTTP_REFERER, que el navegador puede
// no enviar y que tampoco es de fiar.
//
// Si el parámetro falta o no está en la lista, se usa el valor por defecto.
// Por eso las llamadas que no lo pasan —la auto-impresión del ticket en el
// iframe del check-out, o el acceso a los acompañantes desde el check-in—
// siguen funcionando igual que antes.
//
// Algunos destinos no son una página sola, sino la de ALGUIEN: el estado de
// cuenta es el de un huésped concreto, los acompañantes son los de una reserva.
// Esos admiten además `?vid=`, que se castea a entero antes de pegarlo. La RUTA
// sale siempre de la lista; del parámetro solo sale un número. Eso es lo que
// impide una redirección abierta.

/**
 * Destino del botón «Volver», ya resuelto y seguro de imprimir.
 *
 * @param  string $porDefecto Clave de la lista blanca que se usa cuando no
 *                            llega `?volver=` o llega algo desconocido.
 * @return array{url:string,etiqueta:string} Ruta lista para el href —con el
 *                            identificador ya puesto si el destino lo admite— y
 *                            nombre del módulo para el texto del botón.
 */
function volverDestino(string $porDefecto = 'checkout'): array
{
    // El tercer valor es el NOMBRE del parámetro con el que ese destino recibe
    // un identificador, o null si no admite ninguno. Antes era un booleano y el
    // parámetro estaba fijado a `id`, así que un destino que usara otro nombre
    // —como los acompañantes, que van por `reserva`— no se podía expresar y
    // había que mandar a la pantalla de al lado.
    $destinos = [
        'checkout'     => ['/modules/checkout/index.php',        'Check-out',             null],
        'comprobantes' => ['/modules/reportes/comprobantes.php', 'Comprobantes',          null],
        'facturacion'  => ['/modules/facturacion/index.php',     'Facturación',           null],
        'checkin'      => ['/modules/checkin/index.php',         'Check-in',              null],
        'ficha'        => ['/modules/huespedes/ficha.php',       'Estado de cuenta',      'id'],

        // Destinos de las páginas legales, que se abren desde muchos sitios.
        // `reservar` y `aviso` eran PÚBLICAS y están cerradas desde 3.7.1. Las
        // claves se quedan porque hay enlaces publicados que las llevan; apuntan
        // a la raíz, que desvía según la sesión. Quitarlas las haría caer en el
        // destino por defecto —Check-out—, que a un anónimo no le sirve de nada.
        'reservar'     => ['/',                                  'el inicio',             null],
        'aviso'        => ['/',                                  'el inicio',             null],
        'menu'         => ['/modules/menu.php',                  'el menú',               null],
        'panel'        => ['/dashboard.php',                     'el panel',              null],
        'recepcion'    => ['/modules/recepcion/index.php',       'Recepción',             null],
        'huespedes'    => ['/modules/huespedes/index.php',       'Huéspedes',             null],
        'acompanantes' => ['/modules/checkin/acompanantes.php',  'Acompañantes',          'reserva'],
    ];

    $clave = (string) ($_GET['volver'] ?? '');
    if (!isset($destinos[$clave])) {
        $clave = isset($destinos[$porDefecto]) ? $porDefecto : 'checkout';
    }

    [$ruta, $etiqueta, $param] = $destinos[$clave];
    $url = (defined('BASE_URL') ? BASE_URL : '') . $ruta;

    if ($param !== null) {
        $id = (int) ($_GET['vid'] ?? 0);
        if ($id > 0) {
            $url .= '?' . $param . '=' . $id;
        }
    }

    return [
        'url'      => $url,
        'etiqueta' => $etiqueta,
        // La clave YA VALIDADA, para que una pagina pueda pasarsela a otra sin
        // reenviar lo que llego por la URL tal cual.
        'clave'    => $clave,
    ];
}

/**
 * La clave de lista blanca que corresponde a LA PAGINA ACTUAL, o cadena vacia
 * si esta pagina no es un destino conocido.
 *
 * Sirve para que un enlace a una pagina autonoma —una politica, una factura—
 * le diga de donde viene sin que cada plantilla tenga que acordarse de escribir
 * la clave a mano. La barra lateral esta en TODOS los modulos, asi que ahi es
 * la unica forma de que el boton Volver devuelva a donde se pulso.
 *
 * El segundo valor de cada fila es el parametro del que sale el identificador
 * de esa pagina, cuando la pagina es la de alguien concreto.
 */
function volverClaveDeAqui(): array
{
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $mapa = [
        '/modules/checkin/acompanantes.php' => ['acompanantes', 'reserva'],
        '/modules/checkin/index.php'        => ['checkin',      null],
        '/modules/huespedes/ficha.php'      => ['ficha',        'id'],
        '/modules/huespedes/index.php'      => ['huespedes',    null],
        '/modules/checkout/index.php'       => ['checkout',     null],
        '/modules/recepcion/index.php'      => ['recepcion',    null],
        '/modules/facturacion/index.php'    => ['facturacion',  null],
        '/modules/reportes/comprobantes.php'=> ['comprobantes', null],
        '/modules/menu.php'                 => ['menu',         null],
        '/dashboard.php'                    => ['panel',        null],
        // /reservar.php y /aviso.php ya no se sirven: nunca son la página actual.
    ];

    foreach ($mapa as $sufijo => $fila) {
        if (substr($script, -strlen($sufijo)) === $sufijo) {
            return $fila;
        }
    }
    return ['', null];
}

/**
 * El `?volver=...` listo para pegar a un enlace, apuntando a la pagina actual.
 * Devuelve cadena vacia si esta pagina no es un destino conocido, y entonces el
 * boton Volver usara su valor por defecto.
 */
function volverDesdeAqui(): string
{
    [$clave, $param] = volverClaveDeAqui();
    if ($clave === '') {
        return '';
    }
    $qs = '?volver=' . rawurlencode($clave);
    if ($param !== null) {
        $id = (int) ($_GET[$param] ?? 0);
        if ($id > 0) {
            $qs .= '&amp;vid=' . $id;
        }
    }
    return $qs;
}
