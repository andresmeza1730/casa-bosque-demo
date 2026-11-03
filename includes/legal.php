<?php
// ============================================================
// includes/legal.php
// Marco común de las páginas legales — Casa Bosque
// ============================================================
//
// Las tres páginas legales —política de datos, política de menores y carta de
// autorización— son PÚBLICAS y autónomas: no llevan el menú del sistema,
// porque a ellas llega también gente sin sesión desde la página de reservas.
//
// Antes cada una traía su propia hoja de estilos copiada, con un fondo
// verdoso (#f6f8f4) y una variable llamada `--verde` que en realidad guardaba
// un marrón: son de antes del repintado a la paleta del logo, y se quedaron
// fuera. Ahora las tres comparten este marco, así que cambiarlo una vez las
// cambia a las tres.
//
// Se incluye `auth.php` como hace `reservar.php`, que también es pública: de
// ahí salen BASE_URL, la cabecera `no-store` y el resto de cabeceras de
// seguridad. NO se llama a requireLogin(): estas páginas tienen que poder
// leerse sin sesión, que es justo el caso de un huésped que va a reservar.
//
// El botón «Volver» va por lista blanca (includes/volver.php). Antes era
// `javascript:history.back()`, y como todos los enlaces abren estas páginas
// con `target="_blank"`, la pestaña nueva no tenía historial y el botón no
// hacía nada.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/volver.php';

/** Datos de contacto del responsable, en un solo sitio para las tres páginas. */
const LEGAL_RESPONSABLE = [
    'nombre'    => 'Hotel y Restaurante Casa Bosque CR',
    'nit'       => '1047375577-9',
    'direccion' => 'Tv. 48 #20-26, El Bosque, Cartagena, Colombia',
    'correo'    => 'jorgeluiscas_23@hotmail.com',
    'telefono'  => '3117296803',
];

/**
 * Versión y fecha del CONJUNTO de documentos legales: política de datos, política
 * de menores y carta. La 2.0 (29 de octubre de 2026) es la reescritura de la
 * política de datos con la estructura de una política de tratamiento completa;
 * la 2.1 (1 de noviembre de 2026) quita lo del correo, porque el sistema dejó
 * de enviarlo; la 2.2 (el mismo día) describe lo que ahora guarda la auditoría
 * (navegador, sesión, antes y después, accesos fallidos). El historial de
 * versiones de esa política está escrito en ella.
 */
const LEGAL_VERSION = '2.2';
const LEGAL_FECHA   = '1 de noviembre de 2026';

/** Atajo para escapar, que en estas páginas se usa en cada línea. */
function legalEsc(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/**
 * El `?volver=` que debe llevar un enlace de una pagina legal a otra, para que
 * el boton Volver siga apuntando al mismo origen. Sale de la clave YA VALIDADA
 * contra la lista blanca, nunca de lo que llego por la URL.
 */
function legalVolverQS(): string
{
    $clave = $GLOBALS['__legalVolver']['clave'] ?? '';
    return $clave === '' ? '' : '?volver=' . rawurlencode($clave);
}

/**
 * Abre la página: cabecera HTML, estilos y encabezado del documento.
 *
 * @param string $titulo    Título del documento.
 * @param string $bajada    Una línea bajo el título (marco normativo, etc.).
 * @param bool   $imprimible Añade el botón de imprimir, para la carta que se
 *                           firma a mano.
 */
function legalInicio(string $titulo, string $bajada = '', bool $imprimible = false): void
{
    $volver = volverDestino('reservar');
    ?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= legalEsc($titulo) ?> — Casa Bosque</title>
<link rel="icon" href="<?= BASE_URL ?>/favicon.svg?v=3" type="image/svg+xml">
<style>
/* Paleta del logo (ver la nota de paleta del proyecto): el marrón sostiene y
   el oro acentúa. El oro da 2.5:1 sobre la crema, así que NUNCA va en texto. */
:root{
  --tinta:#2A1709; --corteza:#3E2412; --cafe:#6B4420; --bronce:#9C6728;
  --oro:#C8963C; --arena:#EDE3D2; --crema:#FBF8F2; --papel:#FFFFFF;
  --apagado:#7A6A58;
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{
  margin:0; background:var(--crema); color:var(--tinta);
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
  font-size:16px; line-height:1.7;
}

/* Banda superior: identifica el documento y de quién es, como la membrete de
   un papel. */
.lg-banda{background:var(--corteza); color:#F3E7D4; padding:18px 0; border-bottom:3px solid var(--oro)}
.lg-banda .lg-ancho{display:flex; align-items:baseline; justify-content:space-between; gap:16px; flex-wrap:wrap}
.lg-marca{font-family:Georgia,'Times New Roman',serif; font-size:1.05rem; letter-spacing:.01em; margin:0}
.lg-nit{font-size:.78rem; color:#C9B193; margin:0}

.lg-ancho{max-width:46rem; margin:0 auto; padding:0 20px}

main{padding:34px 0 72px}
.lg-doc h1{
  font-family:Georgia,'Times New Roman',serif; font-weight:600;
  font-size:1.72rem; line-height:1.25; color:var(--corteza); margin:0 0 10px;
}
.lg-bajada{color:var(--apagado); font-size:.92rem; margin:0 0 6px}
.lg-sello{
  display:flex; gap:18px; flex-wrap:wrap; font-size:.78rem; color:var(--apagado);
  border-top:1px solid var(--arena); border-bottom:1px solid var(--arena);
  padding:9px 0; margin:18px 0 30px;
}
.lg-sello b{color:var(--tinta); font-weight:600}

.lg-doc h2{
  font-family:Georgia,'Times New Roman',serif; font-weight:600;
  font-size:1.12rem; color:var(--corteza); margin:34px 0 10px;
  padding-bottom:6px; border-bottom:1px solid var(--arena);
}
.lg-doc h3{font-size:.95rem; font-weight:700; color:var(--cafe); margin:22px 0 6px}
.lg-doc p{margin:0 0 13px}
.lg-doc ul,.lg-doc ol{margin:0 0 15px; padding-left:22px}
.lg-doc li{margin-bottom:7px}
.lg-doc a{color:var(--bronce)}
.lg-doc strong{font-weight:600}

/* Ficha del responsable: el único bloque sobre papel blanco, porque es el dato
   que más se busca al abrir la política. */
.lg-ficha{
  background:var(--papel); border:1px solid var(--arena); border-left:3px solid var(--oro);
  border-radius:3px; padding:16px 20px; margin:0 0 8px;
}
.lg-ficha dl{display:grid; grid-template-columns:auto 1fr; gap:4px 16px; margin:0; font-size:.92rem}
.lg-ficha dt{color:var(--apagado)}
.lg-ficha dd{margin:0}

/* Nota al pie de una sección, para lo que matiza en vez de mandar. */
.lg-nota{
  background:#F6EFE2; border-left:3px solid var(--cafe); padding:12px 16px;
  margin:16px 0; font-size:.9rem;
}

/* Definiciones: término en negrita y su significado debajo. */
.lg-def{margin:0 0 15px}
.lg-def dt{font-weight:700; color:var(--cafe); margin-top:12px}
.lg-def dd{margin:2px 0 0}

/* Tablas de la política (plazos, cookies, versiones). Van dentro de su propio
   contenedor desplazable: en una pantalla angosta una tabla más ancha que la
   página perdería columnas sin forma de alcanzarlas. */
.lg-tabla{overflow-x:auto; margin:0 0 15px; -webkit-overflow-scrolling:touch}
.lg-tabla table{border-collapse:collapse; width:100%; min-width:30rem; font-size:.9rem; background:var(--papel)}
.lg-tabla th{background:var(--corteza); color:#F3E7D4; text-align:left; font-weight:600; padding:9px 12px}
.lg-tabla td{border-top:1px solid var(--arena); padding:9px 12px; vertical-align:top}
.lg-tabla tr:nth-child(even) td{background:#FBF8F2}
.lg-doc code{font-size:.88em; background:#F6EFE2; padding:1px 5px; border-radius:3px}
/* En el celular las tablas de la política son de tres columnas cortas: se
   comprimen para que quepan enteras en vez de obligar a desplazarlas, que no
   avisa de que hay más a la derecha. */
@media(max-width:560px){
  .lg-tabla table{min-width:0; font-size:.82rem}
  .lg-tabla th,.lg-tabla td{padding:7px 8px}
}

/* Líneas para rellenar a mano en la carta de autorización. */
.lg-campo{display:block; margin:0 0 15px}
.lg-campo span{display:block; font-size:.8rem; color:var(--apagado); margin-bottom:3px}
.lg-campo i{display:block; border-bottom:1px solid #B9A88E; height:1.6rem; font-style:normal}
.lg-firmas{display:grid; grid-template-columns:1fr; gap:26px; margin-top:8px}
@media(min-width:620px){.lg-firmas{grid-template-columns:1fr 1fr}}
.lg-firma{border-top:1px solid var(--tinta); padding-top:7px; font-size:.84rem; color:var(--apagado)}

.lg-pie{
  max-width:46rem; margin:0 auto; padding:26px 20px 0;
  border-top:1px solid var(--arena); color:var(--apagado); font-size:.82rem;
}
.lg-acciones{display:flex; gap:10px; flex-wrap:wrap; margin:34px 0 0}
.lg-btn{
  display:inline-flex; align-items:center; gap:8px; text-decoration:none;
  font-size:.88rem; font-weight:600; font-family:inherit; cursor:pointer;
  padding:11px 18px; border-radius:4px; border:1px solid var(--cafe);
  background:var(--cafe); color:#fff;
}
.lg-btn.ghost{background:transparent; color:var(--cafe)}
/* Los botones viven DENTRO de .lg-doc, y `.lg-doc a` (clase + elemento) gana
   por especificidad a `.lg-btn` (solo clase), sin importar el orden. Sin esto
   el texto del boton salia en bronce sobre marron: ilegible. */
.lg-doc a.lg-btn{color:#fff}
.lg-doc a.lg-btn.ghost{color:var(--cafe)}
.lg-doc a.lg-btn:hover{color:#fff}
.lg-btn:hover{background:var(--corteza); border-color:var(--corteza); color:#fff}

@media print{
  .lg-banda,.lg-acciones{display:none}
  body{background:#fff; font-size:11.5pt}
  .lg-doc h2{break-after:avoid}
  .lg-ficha,.lg-nota{background:#fff}
  .lg-tabla{overflow:visible}
  .lg-tabla table{min-width:0}
  .lg-tabla th{background:#fff; color:#000; border-bottom:1px solid #000}
  .lg-campo i,.lg-firma{border-color:#000}
}
</style>
</head>
<body>
<div class="lg-banda">
  <div class="lg-ancho">
    <p class="lg-marca"><?= legalEsc(LEGAL_RESPONSABLE['nombre']) ?></p>
    <p class="lg-nit">NIT <?= legalEsc(LEGAL_RESPONSABLE['nit']) ?></p>
  </div>
</div>

<main>
<div class="lg-ancho lg-doc">
  <h1><?= legalEsc($titulo) ?></h1>
  <?php if ($bajada !== ''): ?><p class="lg-bajada"><?= $bajada ?></p><?php endif; ?>
  <div class="lg-sello">
    <span>Versión <b><?= legalEsc(LEGAL_VERSION) ?></b></span>
    <span>Actualizada el <b><?= legalEsc(LEGAL_FECHA) ?></b></span>
  </div>
<?php
    // Se guarda para que legalFin() no tenga que volver a resolverlo.
    $GLOBALS['__legalVolver'] = $volver;
    $GLOBALS['__legalImprimible'] = $imprimible;
}

/** Cierra la página: botones y pie. */
function legalFin(): void
{
    // Sin `?volver=`, al inicio: la raíz desvía a donde toque según la sesión.
    $volver = $GLOBALS['__legalVolver'] ?? ['url' => BASE_URL . '/', 'etiqueta' => 'el inicio'];
    $imprimible = !empty($GLOBALS['__legalImprimible']);
    ?>
  <div class="lg-acciones">
    <a class="lg-btn" href="<?= legalEsc($volver['url']) ?>">&larr; Volver a <?= legalEsc($volver['etiqueta']) ?></a>
    <?php if ($imprimible): ?>
      <button class="lg-btn ghost" type="button" onclick="window.print()">Imprimir</button>
    <?php endif; ?>
  </div>
</div>
</main>

<footer class="lg-pie">
  <p>
    <?= legalEsc(LEGAL_RESPONSABLE['nombre']) ?> · <?= legalEsc(LEGAL_RESPONSABLE['direccion']) ?><br>
    <a href="mailto:<?= legalEsc(LEGAL_RESPONSABLE['correo']) ?>"><?= legalEsc(LEGAL_RESPONSABLE['correo']) ?></a>
    · Tel. <?= legalEsc(LEGAL_RESPONSABLE['telefono']) ?>
  </p>
</footer>
</body>
</html>
<?php
}
