<?php
// ============================================================
// includes/establecimiento.php
// Los datos del hotel, en un solo sitio
// ============================================================
//
// Estaban escritos a mano en dos archivos a la vez:
// modules/checkout/factura_pdf.php (el array $hotel_info) y
// includes/correo_factura.php (la función correoFacturaHotel(), retirada con el
// correo de la factura en 3.13.0), que ya avisaba en un comentario de que eso
// era un apaño y que lo suyo era leerlos de config/establecimiento.php. Cambiar
// el teléfono del hotel obligaba a acordarse de los dos, y el día que no se
// acuerde alguien, la factura y el correo de esa misma factura dirán cosas distintas.
//
// Ahora los dos leen de aquí, y aquí se lee del archivo de configuración.
//
// OJO con las claves: `ciudad`, `razon_social` y `nit` de
// config/establecimiento.php son para los reportes del Gobierno (SIRE, TRA) y
// vienen con marcadores sin rellenar del tipo «[CIUDAD]». No se tocan ni se
// reutilizan. Lo que se imprime en comprobantes usa claves propias
// (`nombre_comercial`, `ciudad_contacto`…), con valores por defecto para que
// una configuración a medio llenar nunca saque un corchete en una factura.

/**
 * Datos del hotel tal como salen impresos en facturas, tiquetes y correos.
 *
 * @return array{nombre:string,subtitulo:string,sistema:string,direccion:string,
 *               ciudad:string,telefono:string,email:string,nit:string,
 *               nit_dv:string,nit_completo:string}
 */
function datosEstablecimiento(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $config = [];
    $ruta = __DIR__ . '/../config/establecimiento.php';
    if (is_file($ruta)) {
        $leido = require $ruta;
        if (is_array($leido)) {
            $config = $leido;
        }
    }

    // Un valor que siga siendo el marcador de la plantilla («[CIUDAD]») vale
    // tanto como estar vacío: no se imprime.
    $valor = static function (string $clave, string $porDefecto) use ($config): string {
        $v = trim((string) ($config[$clave] ?? ''));
        if ($v === '' || ($v[0] === '[' && substr($v, -1) === ']')) {
            return $porDefecto;
        }
        return $v;
    };

    $nit   = $valor('nit', '');
    $nitDv = $valor('nit_dv', '');

    $cache = [
        'nombre'    => $valor('nombre_comercial', 'Casa Bosque'),
        'subtitulo' => $valor('subtitulo', 'HOSPEDAJE'),
        'sistema'   => $valor('sistema', 'SIGH-CB'),
        'direccion' => $valor('direccion', ''),
        'ciudad'    => $valor('ciudad_contacto', ''),
        'telefono'  => $valor('telefono', ''),
        'email'     => $valor('email', ''),
        'nit'       => $nit,
        'nit_dv'    => $nitDv,
        // El NIT tal como se imprime: con su dígito de verificación cuando lo
        // hay. Es lo que deben usar facturas, tiquetes y correos; `nit` a
        // secas queda para quien lo necesite sin el dígito.
        'nit_completo' => ($nit !== '' && $nitDv !== '') ? $nit . '-' . $nitDv : $nit,
    ];

    return $cache;
}

/**
 * Cómo se rotula la línea del IVA en los comprobantes.
 *
 * El hotel emite con consecutivo interno y no es responsable de IVA, así que
 * hoy siempre es cero; lo que cambia es el porqué. La fila se imprime SIEMPRE,
 * aunque valga cero: es lo que deja claro de un vistazo bajo qué régimen se
 * emitió el documento.
 */
function conceptoIva(bool $exento, bool $corto = false): string
{
    if (!$exento) {
        return 'IVA (0% · No aplica)';
    }
    // En el tiquete de 80 mm, con tipografía de máquina, la referencia al
    // artículo no cabe en la fila y se parte en dos renglones. La forma corta
    // es para ahí; la cita completa va igualmente en la nota de régimen, que
    // se imprime justo debajo.
    return $corto ? 'IVA (0% · Exento)' : 'IVA (0% · Exento Art. 481 ET)';
}

/**
 * El párrafo de régimen tributario que va al pie de los comprobantes.
 *
 * Vive aquí, y no dentro de cada documento, porque lo imprimen tres: la
 * factura en PDF, el tiquete de 80 mm y el correo. Copiado tres veces acabaría
 * diciendo tres cosas distintas, que es justo lo que pasó con los datos del
 * hotel y costó dos versiones arreglar.
 *
 * @param string|null $pasaporte Solo se usa cuando hay exención.
 * @param string|null $sello     Sello migratorio (PT), ídem.
 */
function notaRegimenIva(bool $exento, ?string $pasaporte = null, ?string $sello = null): string
{
    if (!$exento) {
        return 'Régimen: consecutivo interno · No responsable de IVA. '
             . 'Documento equivalente interno del hotel.';
    }

    $nota = 'Servicio exento de IVA — hospedaje a turista extranjero no residente '
          . '(Art. 481 lit. e) E.T.).';

    // El pasaporte y el sello solo se añaden si hay algo que añadir. El correo
    // no los pasa a propósito —viaja por un canal que no controlamos— y sin esta
    // comprobación la frase terminaba en 'Pasaporte: — · Sello migratorio: —.'
    $pasaporte = trim((string) $pasaporte);
    $sello     = trim((string) $sello);
    if ($pasaporte !== '' || $sello !== '') {
        $nota .= ' Pasaporte: ' . ($pasaporte !== '' ? $pasaporte : '—')
               . ' · Sello migratorio: ' . ($sello !== '' ? $sello : '—') . '.';
    }

    return $nota;
}
