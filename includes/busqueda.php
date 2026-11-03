<?php
// ============================================================
// includes/busqueda.php
// El texto que se busca no viaja por la URL — Casa Bosque
// ============================================================
// Los cinco buscadores del sistema mandaban el formulario por GET, así que lo
// escrito acababa en `?q=...`. Cuando se busca a alguien por documento, ESE es
// el documento del huésped, y una URL se queda en sitios que nadie limpia:
// el historial del navegador, la lista de sugerencias, los registros de acceso
// del hosting, el encabezado Referer si la página enlaza a otro dominio.
//
// En la base nunca se compara en claro —se consulta el índice ciego, un HMAC
// (modules/huespedes/buscar.php)— y la respuesta sale enmascarada. Lo que
// quedaba expuesto era el dato de ENTRADA, y para eso basta mandarlo por POST.
//
// El patrón es POST → redirección → GET:
//
//   1. El formulario manda `q` por POST, con su testigo CSRF.
//   2. La página guarda el texto en la SESIÓN y redirige a sí misma.
//   3. El GET siguiente lee el texto de la sesión.
//
// Guardarlo en la sesión y no en la URL no es un adorno del patrón, es lo que
// hace que todo lo demás siga funcionando sin arrastrar el texto a ninguna
// parte: recargar no reenvía el formulario (ni sale el aviso del navegador),
// el botón de atrás funciona, y los enlaces de la propia página —«editar»,
// abrir una ficha— no tienen que llevar el documento pegado para no perder la
// búsqueda.
//
// El texto se olvida al cerrar la sesión, porque vive en ella.
// ------------------------------------------------------------

/** Lo más largo que se acepta. Un documento o un nombre caben de sobra. */
const BUSQUEDA_MAX = 120;

/**
 * Guarda (o borra) el texto buscado en una pantalla.
 *
 * Se llama DENTRO de la rama que ya validó el testigo CSRF de la página, para
 * que no haya dos sitios decidiendo lo mismo.
 *
 * Un texto vacío borra la búsqueda: es lo que hace el botón de limpiar y
 * también mandar el campo en blanco.
 */
function busquedaGuardar(string $clave, string $termino): void
{
    $termino = trim($termino);
    if (mb_strlen($termino) > BUSQUEDA_MAX) {
        $termino = mb_substr($termino, 0, BUSQUEDA_MAX);
    }
    if (!isset($_SESSION['cb_busqueda']) || !is_array($_SESSION['cb_busqueda'])) {
        $_SESSION['cb_busqueda'] = [];
    }
    if ($termino === '') {
        unset($_SESSION['cb_busqueda'][$clave]);
    } else {
        $_SESSION['cb_busqueda'][$clave] = $termino;
    }
}

/** El texto buscado en esa pantalla, o '' si no hay ninguno. */
function busquedaRecordada(string $clave): string
{
    $g = $_SESSION['cb_busqueda'][$clave] ?? '';
    return is_string($g) ? $g : '';
}

/**
 * Atiende el enlace de limpiar: `?limpiar=1`.
 *
 * Borra la búsqueda y redirige a la página sin parámetros, para que el enlace
 * no se quede en la barra de direcciones. No hace nada si no se pidió.
 */
function busquedaLimpiarSiPiden(string $clave): void
{
    if (($_GET['limpiar'] ?? '') !== '1') return;
    busquedaGuardar($clave, '');
    busquedaRedirigir();
}

/**
 * Vuelve a esta misma página por GET. Siempre termina en exit.
 *
 * El destino sale de `SCRIPT_NAME`, que lo pone el servidor: no se construye
 * con nada que venga del cliente, para que esto no pueda convertirse en una
 * redirección abierta.
 *
 * @param array $conservar Parámetros que SÍ pueden ir en la URL —fechas, un
 *        número de página—. Nunca el texto buscado.
 */
function busquedaRedirigir(array $conservar = []): void
{
    $destino = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
    $limpios = [];
    foreach ($conservar as $campo => $valor) {
        $valor = trim((string) $valor);
        if ($valor !== '') $limpios[$campo] = $valor;
    }
    if ($limpios) {
        $destino .= '?' . http_build_query($limpios);
    }
    header('Location: ' . $destino, true, 303);
    exit;
}
