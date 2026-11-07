<?php
// Header incluido en todas las páginas autenticadas
// Requiere: $pageTitle (string) definido antes de incluir
require_once __DIR__ . '/auth.php';
// Lo usan los enlaces de «Legal y documentos» de la barra lateral: cada uno
// lleva la pagina actual como origen, para que el boton Volver de la politica
// devuelva a donde se pulso y no a un sitio fijo.
require_once __DIR__ . '/volver.php';
requireLogin();
$__cbTransition = $_SESSION['cb_transition'] ?? '';
unset($_SESSION['cb_transition']);

// ── Aviso del proveedor ──
// Se consulta el servicio externo de avisos. Si no está configurado, o está
// caído, avisoVigente() devuelve null sin ruido y aquí no pasa nada: el panel
// no puede quedarse esperando a un servicio ajeno.
require_once __DIR__ . '/avisos.php';
$__cbAviso = null;
try {
    $a = avisoVigente();
    if ($a !== null && avisoDebeMostrarse($a)) {
        $__cbAviso = $a;
        // Se marca visto AQUÍ, al pintarlo, y no al cerrarlo. Así no hace
        // falta un endpoint ni un token para el botón de cerrar, y el
        // recordatorio por horas sigue funcionando igual.
        avisoMarcarVisto($a['id']);
    }
} catch (Throwable $e) {
    error_log('Avisos: fallo al preparar el aviso: ' . $e->getMessage());
}

// Qué entrada del menú se resalta.
//
// OJO: estas dos líneas ASIGNAN, no completan. Este archivo se incluye
// DESPUÉS de la cabecera de cada módulo, así que PISAN cualquier valor que el
// módulo hubiera declarado. No sirve que un módulo ponga
// $currentPage = 'facturacion' esperando que el menú lo lea: al llegar aquí
// vuelve a valer 'index'. Las condiciones de abajo tienen que mirar el nombre
// real del archivo y de su carpeta, nunca un nombre inventado por el módulo.
$currentPage = basename($_SERVER['SCRIPT_FILENAME'], '.php');
$currentDir  = basename(dirname($_SERVER['SCRIPT_FILENAME']));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Sistema de Gestión Casa Bosque — Panel de Control">
    <title><?= htmlspecialchars($pageTitle ?? 'Casa Bosque') ?> — Casa Bosque</title>
    
    <!-- Iconos directos en la raíz de htdocs -->
    <link rel="icon" href="<?= BASE_URL ?>/favicon.svg?v=3" type="image/svg+xml">
    <link rel="alternate icon" href="<?= BASE_URL ?>/favicon.png" type="image/png">

    <!-- App instalable (PWA) — panel de recepción -->
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
    <meta name="theme-color" content="#3E2412">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/icon-192.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Casa Bosque">
    <meta name="mobile-web-app-capable" content="yes">

    <!-- Hojas de Estilo del Sistema -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile-responsive.css?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bottom-nav-mobile.css?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/dashboard-mobile.css?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/modules-mobile.css?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bottom-sheets.css?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/nav-inmersiva.css?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/avisos-ui.css?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>">
    

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous">  
</head>
<body>
<?php if ($__cbAviso !== null): ?>
<?php
/* ── La ventana del aviso del proveedor ──
   ESTE BLOQUE ES EL ESPEJO de la vista previa del panel
   (avisos/publico/index.php). Los dos tienen que usar las MISMAS clases
   `cb-av-*` y los MISMOS tres tipos, o la vista previa le estaría enseñando a
   Andrés una ventana distinta de la que va a salir. Hay una comprobación que
   compara los dos juegos de clases: si alguien cambia uno y no el otro, lo
   dice. Es el mismo trato que llevan includes/tarifas.php y su espejo
   assets/js/tarifa-personas.js.

   DOS FORMAS, SEGÚN EL TIPO. Antes los tres interrumpían igual: «hay versión
   nueva» tapaba la pantalla con el mismo peso que «el sábado se cae el
   sistema», y eso enseña a cerrar sin leer — con lo que el aviso que de verdad
   importa tampoco se lee.

     información          → tarjeta que NO bloquea, bajo la barra superior
     aviso, mantenimiento → siguen tapando la pantalla

   EL TEXTO VIENE DE FUERA DEL SISTEMA, así que va escapado sin excepción:
   htmlspecialchars en título y mensaje, y nl2br sobre el texto YA escapado,
   para respetar los saltos de línea sin dejar pasar una sola etiqueta. */
$__cbAvisoBloquea = $__cbAviso['tipo'] !== 'info';
$__cbAvisoIcono = $__cbAviso['tipo'] === 'mantenimiento' ? 'screwdriver-wrench'
                : ($__cbAviso['tipo'] === 'aviso' ? 'triangle-exclamation' : 'circle-info');
?>
<style>
/* La tarjeta y el modal comparten todo el interior; solo cambia el envoltorio. */
.cb-av{z-index:100000}
/* Por encima de la transición de entrada (99999). Iba por debajo, y en el
   primer acceso la animación tapaba el aviso justo cuando tiene que salir
   «de primero». Peor aún: se marca como visto al dibujarlo, así que quedaba
   marcado sin que nadie lo hubiera llegado a ver. */
.cb-av.cb-av-espera{opacity:0;visibility:hidden}
.cb-av.cb-av-out{opacity:0;visibility:hidden;pointer-events:none;transition:opacity .2s ease}

/* ── Modal: aviso y mantenimiento ── */
.cb-av--modal{position:fixed;inset:0;display:grid;place-items:center;padding:20px;
     background:rgba(42,23,9,.55);backdrop-filter:blur(2px)}
.cb-av--modal .cb-av-card{width:min(430px,100%);box-shadow:0 24px 50px rgba(0,0,0,.26)}

/* ── Tarjeta: información ──
   Va FIJA y centrada, pero por DEBAJO de la barra superior (--header-height,
   65 px, sticky en z-index 50): una tarjeta a top:0 taparía el título de la
   página y el menú. Y va arriba a propósito, no abajo, porque en móvil la
   barra inferior es fixed y le quitaría el sitio. No lleva fondo oscurecido:
   el sistema se sigue usando con ella abierta. */
.cb-av--tarjeta{position:fixed;top:calc(var(--header-height,65px) + 12px);right:18px;
     width:min(400px,calc(100% - 24px));pointer-events:none}
/* A la derecha y no centrada: `left:50%` centra sobre la VENTANA, y con la
   barra lateral de por medio la tarjeta quedaba descentrada respecto al
   contenido, mirando a la izquierda. Arriba a la derecha es además donde se
   espera algo que no bloquea. En móvil no hay barra lateral y ocupa casi todo
   el ancho, así que el problema no existe. */
@media(max-width:600px){.cb-av--tarjeta{right:12px;left:12px;width:auto}}
.cb-av--tarjeta .cb-av-card{pointer-events:auto;box-shadow:0 14px 34px rgba(42,23,9,.20);
     border:1px solid #EDE3D2}

.cb-av-card{background:#fff;border-radius:16px;overflow:hidden}
.cb-av-top{display:flex;align-items:flex-start;gap:11px;padding:16px 20px 12px}
.cb-av-ic{display:grid;place-items:center;width:38px;height:38px;border-radius:11px;flex:none;
     font-size:1.05rem}
.cb-av-t{margin:0;flex:1;font-size:1.04rem;font-weight:700;color:#3E2412;line-height:1.3}
.cb-av-x{flex:none;border:none;background:none;cursor:pointer;color:#9A8778;font-size:1rem;
     line-height:1;padding:4px;margin:-4px -6px 0 0;border-radius:7px}
.cb-av-x:hover{background:#F5EBDC;color:#6B4420}
.cb-av-b{padding:0 20px 16px;color:#5E4A34;font-size:.93rem;line-height:1.6;
     white-space:normal;word-break:break-word}
.cb-av-nota{padding:0 20px 16px;font-size:.72rem;color:#9A8778}
.cb-av-f{display:flex;justify-content:flex-end;gap:9px;padding:0 20px 18px}
.cb-av-btn{border:none;cursor:pointer;font:inherit;font-weight:700;font-size:.9rem;
     padding:11px 20px;border-radius:10px;background:#6B4420;color:#fff}
.cb-av-btn:active{transform:scale(.97)}
/* Un color por tipo, para que se distinga de un vistazo qué clase de aviso es. */
.cb-av.t-info          .cb-av-ic{background:rgba(59,130,246,.13);color:#1d4ed8}
.cb-av.t-aviso         .cb-av-ic{background:rgba(245,158,11,.16);color:#b45309}
.cb-av.t-mantenimiento .cb-av-ic{background:rgba(239,68,68,.12);color:#b91c1c}
/* La raya de color del borde superior, que en la tarjeta es lo que la ata al
   tipo sin necesidad de fondo oscurecido. */
.cb-av--tarjeta.t-info          .cb-av-card{border-top:3px solid #1d4ed8}
.cb-av--tarjeta.t-aviso         .cb-av-card{border-top:3px solid #b45309}
.cb-av--tarjeta.t-mantenimiento .cb-av-card{border-top:3px solid #b91c1c}
@media(prefers-reduced-motion:reduce){.cb-av.cb-av-out{transition:none}}
@media print{.cb-av{display:none !important}}
</style>
<div id="cbAviso"
     class="cb-av <?= $__cbAvisoBloquea ? 'cb-av--modal' : 'cb-av--tarjeta' ?> t-<?= htmlspecialchars($__cbAviso['tipo'], ENT_QUOTES) ?>"
     <?= $__cbAvisoBloquea ? 'role="dialog" aria-modal="true"' : 'role="status" aria-live="polite"' ?>
     aria-labelledby="cbAvisoT">
  <div class="cb-av-card">
    <div class="cb-av-top">
      <span class="cb-av-ic" aria-hidden="true"><i class="fas fa-<?= $__cbAvisoIcono ?>"></i></span>
      <h2 class="cb-av-t" id="cbAvisoT"><?= htmlspecialchars(
            $__cbAviso['titulo'] !== '' ? $__cbAviso['titulo'] : 'Aviso del proveedor', ENT_QUOTES) ?></h2>
      <?php if (!$__cbAvisoBloquea): ?>
        <button type="button" class="cb-av-x" id="cbAvisoX" aria-label="Cerrar el aviso">
          <i class="fas fa-xmark"></i>
        </button>
      <?php endif; ?>
    </div>
    <div class="cb-av-b"><?= nl2br(htmlspecialchars($__cbAviso['mensaje'], ENT_QUOTES)) ?></div>
    <?php /* La nota NO promete un número de horas. Decía «volverá a aparecer en
             N h si sigue vigente», y eso no es cierto: el «visto» vive en
             $_SESSION (includes/avisos.php) y la sesión muere a las 12 horas
             fijas y a los 30 minutos de inactividad, así que el aviso reaparece
             al volver a entrar, no cuando se cumplan las horas. Mientras eso no
             se arregle, aquí se dice lo que de verdad pasa. */ ?>
    <div class="cb-av-nota">Volverá a salir cada vez que entres, mientras siga publicado.</div>
    <?php if ($__cbAvisoBloquea): ?>
      <div class="cb-av-f">
        <button type="button" class="cb-av-btn" id="cbAvisoOk">Entendido</button>
      </div>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  var c = document.getElementById('cbAviso');
  if (!c) return;
  var b = document.getElementById('cbAvisoOk') || document.getElementById('cbAvisoX');
  var bloquea = c.classList.contains('cb-av--modal');

  // Si hay transición de entrada, el aviso espera a que termine: así no se
  // superpone a la animación y, sobre todo, aparece cuando hay alguien
  // mirando la pantalla.
  //
  // La comprobación va en DOMContentLoaded y no aquí mismo porque este
  // bloque se emite ANTES que el de la transición: en este punto
  // #cbPageTransition todavía no existe en el documento.
  function arrancar() {
    if (!document.getElementById('cbPageTransition')) { enfocar(); return; }
    c.classList.add('cb-av-espera');
    var revelar = function () { c.classList.remove('cb-av-espera'); enfocar(); };
    // Dos vías por si una falla: avisar cuando la transición desaparece del
    // DOM, y un tope de tiempo por si nunca lo hiciera.
    if (window.MutationObserver) {
      var obs = new MutationObserver(function () {
        if (!document.getElementById('cbPageTransition')) { obs.disconnect(); revelar(); }
      });
      obs.observe(document.body, { childList: true, subtree: true });
    }
    setTimeout(revelar, 2200);
  }

  // La tarjeta NO roba el foco: no bloquea nada, así que quien esté
  // escribiendo en un campo tiene que poder seguir. El modal sí lo toma,
  // porque hasta que no se cierre no se puede hacer otra cosa.
  function enfocar() { if (bloquea && b) b.focus(); }

  function cerrar() { c.classList.add('cb-av-out'); setTimeout(function () { c.remove(); }, 220); }
  if (b) b.addEventListener('click', cerrar);
  // Esc cierra las dos formas; el clic fuera solo tiene sentido en el modal,
  // que es el único que tiene un «fuera». Es un aviso, no una trampa.
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrar(); });
  if (bloquea) {
    c.addEventListener('click', function (e) { if (e.target === c) cerrar(); });
  }
  // Va al final y no mas arriba: enfocar el boton antes de engancharle el
  // oyente del clic deja el Enter sin efecto.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', arrancar);
  } else {
    arrancar();
  }
})();
</script>
<?php endif; ?>
<?php if ($__cbTransition === 'entrada'): ?>
<style>
#cbPageTransition{position:fixed;inset:0;z-index:99999;display:grid;place-items:center;padding:24px;background:linear-gradient(145deg,#241607,#3E2412 58%,#5A3618);color:#fff;opacity:1;visibility:visible;transition:opacity .35s ease,visibility .35s ease}
#cbPageTransition.cb-transition-out{opacity:0;visibility:hidden;pointer-events:none}
#cbPageTransition .cb-transition-card{width:min(340px,100%);text-align:center}
#cbPageTransition .cb-transition-mark{display:grid;place-items:center;width:68px;height:68px;margin:0 auto 18px;border:1px solid rgba(255,255,255,.24);border-radius:22px;background:rgba(255,255,255,.1);box-shadow:0 14px 36px rgba(0,0,0,.16);font-size:1.7rem}
#cbPageTransition .cb-transition-brand{margin:0;font-family:Georgia,serif;font-size:1.55rem;letter-spacing:-.03em}
#cbPageTransition .cb-transition-message{margin:8px 0 22px;color:rgba(255,255,255,.76);font-size:.9rem}
#cbPageTransition .cb-transition-track{height:3px;overflow:hidden;border-radius:99px;background:rgba(255,255,255,.18)}
#cbPageTransition .cb-transition-progress{width:38%;height:100%;border-radius:inherit;background:#C8963C;animation:cbTransitionProgress 1.1s ease-in-out infinite}
@keyframes cbTransitionProgress{from{transform:translateX(-110%)}to{transform:translateX(270%)}}
@media(prefers-reduced-motion:reduce){#cbPageTransition,#cbPageTransition .cb-transition-progress{animation:none;transition:none}}
</style>
<div id="cbPageTransition" role="status" aria-live="polite" aria-label="Preparando el sistema">
    <div class="cb-transition-card">
        <div class="cb-transition-mark"><img src="<?= BASE_URL ?>/assets/img/logo-96.png?v=3" alt="" width="44" height="44" style="display:block"></div>
        <p class="cb-transition-brand">Casa Bosque</p>
        <p class="cb-transition-message">Preparando tu espacio de trabajo…</p>
        <div class="cb-transition-track"><div class="cb-transition-progress"></div></div>
    </div>
</div>
<script>
(function(){
    var screen=document.getElementById('cbPageTransition');
    if(!screen)return;
    // 4.2.2: la cortina se quita cuando la página TERMINA de cargar (evento `load`), no a los 850 ms de un temporizador. Antes se iba
    // aunque las fuentes y los íconos siguieran bajando, o se quedaba de más si ya estaba todo: era una transición puesta a ojo.
    var quitada=false;
    function quitar(){if(quitada)return;quitada=true;screen.classList.add('cb-transition-out');window.setTimeout(function(){screen.remove();},400);}
    if(document.readyState==='complete'){quitar();}else{window.addEventListener('load',quitar);}
    // Solo por seguridad: si un recurso externo (las fuentes, Font Awesome) no responde, `load` tarda y no se deja a nadie mirando la cortina.
    window.setTimeout(quitar,8000);
})();
</script>
<?php endif; ?>
<div class="app-layout">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <!-- Brand Casa Bosque -->
        <div class="sidebar-brand">
            <h2><img src="<?= BASE_URL ?>/assets/img/emblema-64.png?v=3" alt="" width="30" height="30" style="display:block;flex:none">Casa Bosque</h2>
            <span>Sistema de Gestión</span>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section">Principal</div>
            <?php if (isAdmin()): ?>
                <a href="<?= BASE_URL ?>/dashboard.php" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="fas fa-chart-pie"></i></span> Dashboard
                </a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>/modules/recepcion/index.php" class="<?= $currentDir === 'recepcion' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="fas fa-house"></i></span> Inicio
                </a>
            <?php endif; ?>

            <div class="nav-section">Recepción</div>
            <a href="<?= BASE_URL ?>/modules/huespedes/ficha.php" class="<?= ($currentPage ?? '') === 'ficha' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-magnifying-glass-dollar"></i></span> Estado de cuenta
            </a>
            <?php if (isAdmin()): ?>
                <a href="<?= BASE_URL ?>/modules/habitaciones/index.php" class="<?= $currentDir === 'habitaciones' ? 'active' : '' ?>">
                    <span class="nav-icon"><i class="fas fa-bed"></i></span> Habitaciones
                </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/modules/huespedes/index.php" class="<?= ($currentDir === 'huespedes' && ($currentPage ?? '') === 'index') ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-users"></i></span> Huéspedes
            </a>
            <a href="<?= BASE_URL ?>/modules/huespedes/historial.php" class="<?= ($currentPage ?? '') === 'historial' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-clock-rotate-left"></i></span> Historial de huéspedes
            </a>
            <a href="<?= BASE_URL ?>/modules/reservas/index.php" class="<?= $currentDir === 'reservas' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-calendar-check"></i></span> Reservas
            </a>
            <a href="<?= BASE_URL ?>/modules/calendario/index.php" class="<?= $currentDir === 'calendario' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-calendar-alt"></i></span> Calendario
            </a>

            <a href="<?= BASE_URL ?>/modules/checkin/index.php" class="<?= $currentDir === 'checkin' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-door-open"></i></span> Check-in
            </a>
            <a href="<?= BASE_URL ?>/modules/consumos/index.php" class="<?= $currentDir === 'consumos' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-receipt"></i></span> Consumos
            </a>
            <a href="<?= BASE_URL ?>/modules/productos/index.php" class="<?= $currentDir === 'productos' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-utensils"></i></span> Carta y Restaurante
            </a>
            <a href="<?= BASE_URL ?>/modules/checkout/index.php" class="<?= $currentDir === 'checkout' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-door-closed"></i></span> Check-out
            </a>
            <a href="<?= BASE_URL ?>/modules/deudores/index.php" class="<?= $currentDir === 'deudores' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-hand-holding-dollar"></i></span> Deudores
            </a>

            <a href="<?= BASE_URL ?>/modules/usuarios/seguridad.php" class="<?= ($currentPage ?? '') === 'seguridad' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-shield-halved"></i></span> Seguridad (2FA)
            </a>

            <div class="nav-section">Legal y documentos</div>
            <a href="<?= BASE_URL ?>/politica-datos.php<?= volverDesdeAqui() ?>">
                <span class="nav-icon"><i class="fas fa-user-shield"></i></span> Política de datos
            </a>
            <a href="<?= BASE_URL ?>/politica-menores.php<?= volverDesdeAqui() ?>">
                <span class="nav-icon"><i class="fas fa-child"></i></span> Política de menores
            </a>
            <a href="<?= BASE_URL ?>/autorizacion-menor.php<?= volverDesdeAqui() ?>">
                <span class="nav-icon"><i class="fas fa-file-signature"></i></span> Carta autorización
            </a>

            <?php if (isAdmin()): ?>
            <div class="nav-section">Administración</div>
            <a href="<?= BASE_URL ?>/modules/facturacion/index.php" class="<?= $currentDir === 'facturacion' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-file-invoice-dollar"></i></span> Facturación
            </a>
            <a href="<?= BASE_URL ?>/modules/reportes/caja.php" class="<?= ($currentPage ?? '') === 'caja' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-cash-register"></i></span> Arqueo de Caja
            </a>
            <a href="<?= BASE_URL ?>/modules/reportes/ingresos.php" class="<?= ($currentPage ?? '') === 'ingresos' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-chart-line"></i></span> Reportes
            </a>
            <a href="<?= BASE_URL ?>/modules/reportes/comprobantes.php" class="<?= ($currentPage ?? '') === 'comprobantes' ? 'active' : '' ?>">
    <span class="nav-icon"><i class="fas fa-file-invoice"></i></span> Comprobantes
</a>

            <a href="<?= BASE_URL ?>/modules/reportes/dian.php" class="<?= ($currentPage ?? '') === 'dian' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-file-invoice-dollar"></i></span> Estado DIAN
            </a>


            <a href="<?= BASE_URL ?>/modules/auditoria/index.php" class="<?= $currentDir === 'auditoria' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span> Auditoría
            </a>
            <a href="<?= BASE_URL ?>/modules/usuarios/index.php" class="<?= ($currentDir === 'usuarios' && ($currentPage ?? '') === 'index') ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-users-cog"></i></span> Usuarios
            </a>
            <a href="<?= BASE_URL ?>/modules/reportes/respaldo.php">
                <span class="nav-icon"><i class="fas fa-database"></i></span> Respaldo BD
            </a>
            <a href="<?= BASE_URL ?>/modules/reportes/reiniciar.php" class="<?= $currentPage === 'reiniciar' ? 'active' : '' ?>" style="color:#fca5a5;">
                <span class="nav-icon"><i class="fas fa-triangle-exclamation"></i></span> Reiniciar datos
            </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-user">
            <div class="user-avatar"><?= strtoupper(substr(getCurrentUserName(), 0, 1)) ?></div>
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars(getCurrentUserName()) ?></div>
                <div class="user-role"><?= htmlspecialchars(getCurrentUserRole()) ?></div>
            </div>
            <a href="<?= BASE_URL ?>/modules/auth/logout.php" title="Cerrar sesión" style="color:var(--gray-400);">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="top-bar">
            <h1><?= htmlspecialchars($pageTitle ?? 'Panel') ?></h1>
            <div class="top-bar-actions">
                <?php if (esEntornoDemostracion()): ?>
                    <span title="Copia de demostración: datos ficticios" style="font-size:.68rem;font-weight:800;letter-spacing:.8px;color:#fff;background:#b45309;border-radius:99px;padding:4px 11px;margin-right:10px;white-space:nowrap;">DEMOSTRACIÓN</span>
                <?php endif; ?>
                <span class="text-muted" style="font-size:var(--font-size-sm);">
                    <i class="fas fa-calendar"></i>
                    <?= date('d/m/Y') ?>
                </span>
            </div>
        </div>
        <div class="content-area">
