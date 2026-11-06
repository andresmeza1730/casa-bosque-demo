<?php
// ============================================================
// includes/selector_busqueda.php
// El campo «escribe y elige» que reemplaza a un menú con todos los registros (4.3.0)
// ============================================================
//
// Reservas y Facturación ofrecían un <select> con TODOS los huéspedes (o las últimas 200 cuentas cerradas). Con cien clientes ya hay que
// recorrer una lista larguísima; con mil, la página pesa y tarda. Y esos menús llevaban en el HTML el nombre y el DOCUMENTO de todos, que
// son datos personales que no tienen por qué viajar en cada visita.
//
// Esto es un campo de texto que pregunta al servidor mientras se escribe (assets/js/selector-busqueda.js) y deja elegido a uno: el id va
// en un campo oculto con el nombre que la pantalla ya usaba (`huesped_id`, `estancia_id`), así que el POST no cambia y el servidor sigue
// validando lo de siempre. Quien busca no ve documentos completos: salen enmascarados (documentoOculto) como en el buscador de recepción.
//
// Lo que se pregunta lo contesta un endpoint propio de cada pantalla: modules/huespedes/buscar.php (huéspedes) y
// modules/facturacion/buscar.php (cuentas cerradas sin factura). Los dos devuelven {id, nombre, documento, habitacion, detalle?, conocido?}.

/**
 * Pinta el selector. Opciones:
 *  - nombre    nombre del campo oculto que viaja en el POST (p. ej. `huesped_id`)
 *  - id        id del campo oculto (por defecto, el nombre)
 *  - url       el endpoint que contesta las búsquedas
 *  - minimo    letras antes de preguntar (0: al abrir ya muestra las más recientes)
 *  - etiqueta  texto accesible del campo
 *  - ayuda     placeholder del campo de texto
 *  - modo      'evento' (al elegir avisa a la pantalla con un `change` del campo oculto) o 'recargar' (al elegir recarga con ?{param}=id)
 *  - param     para 'recargar': el nombre del parámetro de la URL
 *  - inicial   null, o ['id' => , 'titulo' => , 'detalle' => ] cuando ya hay uno elegido (p. ej. tras recargar)
 *  - requerido true: hay que elegir uno para enviar el formulario
 *  - claseCampo clase del campo de texto, para que se vea como los de la pantalla
 *  - idCampo   id del campo de texto: la etiqueta de la pantalla lo señala con `for=`, y así el aviso de «campos obligatorios» (campos-obligatorios.js) lo llama por su nombre
 */
function selectorBusqueda(array $o): void {
    static $estilosPuestos = false;
    $nombre   = (string) ($o['nombre'] ?? '');
    $id       = (string) ($o['id'] ?? $nombre);
    $inicial  = $o['inicial'] ?? null;
    $elegido  = is_array($inicial) && !empty($inicial['id']);
    $requerido = !empty($o['requerido']);
    $minimo   = max(0, (int) ($o['minimo'] ?? 2));
    $modo     = (($o['modo'] ?? 'evento') === 'recargar') ? 'recargar' : 'evento';
    if (!$estilosPuestos) {
        $estilosPuestos = true;
        ?>
<style>
.sb{position:relative}
.sb [hidden]{display:none!important}
.sb-campo{width:100%;box-sizing:border-box}
.sb-elegido{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--cb-primary,#6B4420);border-radius:12px;background:var(--cb-primary-light,#F5EBDC);color:var(--cb-forest,#3E2412)}
.sb-info{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.sb-tit{font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sb-det{font-size:.78rem;color:var(--cb-muted,#9A8778);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sb-cambiar{flex:none;border:0;background:none;color:var(--cb-primary,#6B4420);font:inherit;font-weight:700;cursor:pointer;padding:6px 4px}
.sb-panel{position:absolute;z-index:40;left:0;right:0;top:100%;margin-top:4px;background:#fff;border:1px solid var(--cb-border,#EDE3D2);border-radius:12px;box-shadow:0 12px 28px rgba(42,23,9,.16);max-height:300px;overflow-y:auto}
.sb-item{display:flex;flex-direction:column;gap:2px;padding:10px 13px;cursor:pointer;border-top:1px solid #f3ede2}
.sb-item:first-child{border-top:0}
.sb-item.on,.sb-item:hover{background:var(--cb-primary-light,#F5EBDC)}
.sb-nombre{font-weight:700;color:var(--cb-forest,#3E2412)}
.sb-meta{font-size:.78rem;color:var(--cb-muted,#9A8778)}
.sb-vacio{cursor:default;color:var(--cb-muted,#9A8778);font-size:.86rem}
.sb-vacio:hover{background:#fff}
</style>
        <?php
    }
    ?>
<div class="sb" data-selector-busqueda data-url="<?= htmlspecialchars((string) ($o['url'] ?? '')) ?>" data-min="<?= $minimo ?>" data-modo="<?= $modo ?>" data-param="<?= htmlspecialchars((string) ($o['param'] ?? '')) ?>">
    <input type="hidden" name="<?= htmlspecialchars($nombre) ?>" id="<?= htmlspecialchars($id) ?>" value="<?= $elegido ? (int) $inicial['id'] : '' ?>">
    <div class="sb-elegido" <?= $elegido ? '' : 'hidden' ?>>
        <div class="sb-info"><span class="sb-tit"><?= $elegido ? htmlspecialchars((string) ($inicial['titulo'] ?? '')) : '' ?></span><span class="sb-det"><?= $elegido ? htmlspecialchars((string) ($inicial['detalle'] ?? '')) : '' ?></span></div>
        <button type="button" class="sb-cambiar">Cambiar</button>
    </div>
    <input type="text" <?= !empty($o['idCampo']) ? 'id="' . htmlspecialchars((string) $o['idCampo']) . '"' : '' ?> class="sb-campo <?= htmlspecialchars((string) ($o['claseCampo'] ?? '')) ?>" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false"
           aria-label="<?= htmlspecialchars((string) ($o['etiqueta'] ?? 'Buscar')) ?>" placeholder="<?= htmlspecialchars((string) ($o['ayuda'] ?? 'Escribe para buscar…')) ?>"
           <?= $requerido ? 'data-requerido="1"' : '' ?> <?= ($requerido && !$elegido) ? 'required' : '' ?> <?= $elegido ? 'hidden' : '' ?>>
    <div class="sb-panel" role="listbox" hidden></div>
</div>
    <?php
}
