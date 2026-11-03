<?php
/**
 * Cómo se PRESENTA un mensaje al usuario (4.2.0). Una sola fuente.
 *
 * Hasta 4.1.1 cada pantalla pintaba su propio recuadro (`.ci-msg`, `.hb-msg`, `.rs-msg`… diez clases distintas) a partir
 * de `$msg`/`$msgType`, y el aviso de «no tienes permiso» solo lo pintaba la pantalla de recepción. Ahora el pie de página
 * (footer.php) lee esas variables, las pasa por aquí y las entrega a `assets/js/avisos-ui.js`, que las muestra como
 * una notificación con el estilo de la barra del módulo. Las pantallas no cambian su forma de producir el mensaje.
 *
 * Esto decide el TIPO, el TÍTULO, el ÍCONO y el ENLACE de cada aviso; el JS solo lo dibuja. Lo vigila
 * `pruebas/probar_avisos_420.php`.
 */

const MENSAJE_TIPOS = ['success', 'danger', 'warning', 'info'];
const MENSAJE_MAX_LARGO = 300;    // un texto más largo se corta: una notificación no es una página

/** El tipo normalizado. Lo desconocido o vacío es «info»: nunca se inventa un éxito ni un error. */
function mensajeTipo(?string $tipo): string {
    $t = strtolower(trim((string) $tipo));
    $t = ['ok' => 'success', 'exito' => 'success', 'error' => 'danger', 'mal' => 'danger', 'warn' => 'warning', 'aviso' => 'warning'][$t] ?? $t;
    return in_array($t, MENSAJE_TIPOS, true) ? $t : 'info';
}

/** El texto tal como se muestra: sin etiquetas (el JS lo escribe como texto), con las entidades resueltas y recortado. */
function mensajeTexto(string $texto): string {
    $t = html_entity_decode(strip_tags($texto), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
    return mb_strlen($t) > MENSAJE_MAX_LARGO ? rtrim(mb_substr($t, 0, MENSAJE_MAX_LARGO - 1)) . '…' : $t;
}

/** ¿Es un «no puedes hacer esto»? Se dibuja distinto (candado y «Acción no permitida»), sea cual sea el tipo con que llegó. */
function mensajeEsPermiso(string $texto): bool {
    return (bool) preg_match('/^(No tienes permiso|Solo un administrador)|exclusiva de administradores/iu', $texto);
}

/** El título de cada tipo. Corto y sin signos de exclamación: el cuerpo dice qué pasó. */
function mensajeTitulo(string $tipo, string $texto): string {
    if (mensajeEsPermiso($texto)) return 'Acción no permitida';
    return ['success' => 'Listo', 'danger' => 'No se pudo completar', 'warning' => 'Revisa esto', 'info' => 'Aviso'][$tipo] ?? 'Aviso';
}

/**
 * Un enlace dentro del aviso («Abrir factura»). Solo caminos del propio sistema: una ruta que empiece con «/», nunca «//»
 * ni un esquema, para que un mensaje no pueda mandar a otro sitio.
 *
 * @return array{texto:string, href:string}|null
 */
function mensajeAccion($accion): ?array {
    if (!is_array($accion)) return null;
    $texto = mensajeTexto((string) ($accion['texto'] ?? ''));
    $href  = (string) ($accion['href'] ?? '');
    if ($texto === '' || $href === '' || $href[0] !== '/' || strpos($href, '//') === 0 || preg_match('/[\x00-\x1F\x7F\\\\]/', $href)) return null;
    return ['texto' => $texto, 'href' => $href];
}

/**
 * Un aviso listo para el JS, o null si no hay nada que decir.
 *
 * @return array{tipo:string, titulo:string, texto:string, icono:string, accion:array|null}|null
 */
function mensajeAviso(?string $texto, ?string $tipo = null, $accion = null): ?array {
    $t = mensajeTexto((string) $texto);
    if ($t === '') return null;
    $permiso = mensajeEsPermiso($t);
    $tipo = $permiso ? 'danger' : mensajeTipo($tipo);
    return ['tipo' => $tipo, 'titulo' => mensajeTitulo($tipo, $t), 'texto' => $t, 'icono' => $permiso ? 'candado' : '', 'accion' => mensajeAccion($accion)];
}

/**
 * Los avisos de una pantalla, a partir de las variables que ya usa: `$msg` + `$msgType` (14 pantallas), `$mensaje` +
 * `$mensajeTipo` (la carta) y `$flash` (el «no tienes permiso» de recepción). `$msgAccion` pone un enlace en el de `$msg`.
 *
 * @param array $v lo que `get_defined_vars()` devuelve en el pie de página
 */
function mensajesDePagina(array $v): array {
    $avisos = [];
    $texto = static fn($x) => is_string($x) ? $x : null;
    if ($a = mensajeAviso($texto($v['msg'] ?? null), $texto($v['msgType'] ?? null), $v['msgAccion'] ?? null)) $avisos[] = $a;
    if ($a = mensajeAviso($texto($v['mensaje'] ?? null), $texto($v['mensajeTipo'] ?? null))) $avisos[] = $a;
    $flash = $v['flash'] ?? null;
    if (is_array($flash) && ($a = mensajeAviso($texto($flash['msg'] ?? null), $texto($flash['tipo'] ?? null)))) $avisos[] = $a;
    return $avisos;   // como mucho tres, una por cada forma de mensaje: no hace falta otro tope aquí (avisos-ui.js limita lo que se ve)
}

/** El `<script>` que entrega los avisos al navegador. Vacío si no hay ninguno. El JSON no puede cerrar el script ni romper el HTML. */
function mensajesScript(array $avisos): string {
    if (!$avisos) return '';
    $json = json_encode($avisos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($json === false) return '';
    return "<script>(function(a){if(!window.cbAviso)return;a.forEach(function(x,i){setTimeout(function(){window.cbAviso(x);},i*350);});})($json);</script>\n";
}
