</div><!-- /.content-area -->
    </main>
</div><!-- /.app-layout -->

<?php
// La barra inferior se adapta al rol del usuario.
$__esAdmin = isAdmin();
?>

<!-- =================================================== -->
<!-- BARRA DE NAVEGACIÓN INFERIOR (BOTTOM NAV) — MÓVIL  -->
<!-- Adaptada por rol + diseño inmersivo                -->
<!-- =================================================== -->
<nav class="bottom-nav <?= $__esAdmin ? 'bn-admin' : 'bn-recepcion' ?>">

<?php if ($__esAdmin): ?>
    <!-- ===== BARRA PARA ADMINISTRADOR ===== -->
    <a href="<?= BASE_URL ?>/dashboard.php" class="bottom-nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
        <i class="fas fa-chart-pie"></i><span>Panel</span>
    </a>
    <a href="<?= BASE_URL ?>/modules/habitaciones/index.php" class="bottom-nav-item <?= $currentDir === 'habitaciones' ? 'active' : '' ?>">
        <i class="fas fa-bed"></i><span>Habitaciones</span>
    </a>
    <a href="<?= BASE_URL ?>/modules/reportes/caja.php" class="bottom-nav-item <?= ($currentPage ?? '') === 'caja' ? 'active' : '' ?>">
        <i class="fas fa-cash-register"></i><span>Caja</span>
    </a>
    <a href="<?= BASE_URL ?>/modules/reservas/index.php" class="bottom-nav-item <?= $currentDir === 'reservas' ? 'active' : '' ?>">
        <i class="fas fa-calendar-check"></i><span>Reservas</span>
    </a>
    <a href="<?= BASE_URL ?>/modules/menu.php" class="bottom-nav-item <?= $currentPage === 'menu' ? 'active' : '' ?>">
        <i class="fas fa-ellipsis-h"></i><span>Más</span>
    </a>

<?php else: ?>
    <!-- ===== BARRA PARA RECEPCIONISTA (inmersiva) ===== -->
    <a href="<?= BASE_URL ?>/modules/recepcion/index.php" class="bottom-nav-item <?= $currentDir === 'recepcion' ? 'active' : '' ?>">
        <i class="fas fa-house"></i><span>Inicio</span>
    </a>
    <a href="<?= BASE_URL ?>/modules/checkin/index.php" class="bottom-nav-item <?= $currentDir === 'checkin' ? 'active' : '' ?>">
        <i class="fas fa-door-open"></i><span>Check-in</span>
    </a>

    <a href="<?= BASE_URL ?>/modules/consumos/index.php" class="bottom-nav-item <?= $currentDir === 'consumos' ? 'active' : '' ?>">
        <i class="fas fa-receipt"></i><span>Consumos</span>
    </a>

    <a href="<?= BASE_URL ?>/modules/checkout/index.php" class="bottom-nav-item <?= $currentDir === 'checkout' ? 'active' : '' ?>">
        <i class="fas fa-door-closed"></i><span>Check-out</span>
    </a>
    <a href="<?= BASE_URL ?>/modules/menu.php" class="bottom-nav-item <?= $currentPage === 'menu' ? 'active' : '' ?>">
        <i class="fas fa-ellipsis-h"></i><span>Más</span>
    </a>
<?php endif; ?>

</nav>

<!-- ===== Panel "Más" del administrador: bottom sheet con accesos en cuadrícula =====
     Estilos EN LÍNEA (no dependen de bottom-nav-mobile.css) para que el menú
     funcione aunque falte algún CSS externo. Solo aplica en móvil. -->
<style>
/* Barra inferior: reglas base EN LÍNEA (respaldo por si falta bottom-nav-mobile.css) */
.app-version { text-align:center; padding:14px 16px 20px; font-size:.72rem; color:rgba(0,0,0,.38); }
@media (min-width: 769px) { .bottom-nav { display: none; } }
/* Bloquear el scroll del fondo mientras el panel "Más" está abierto.
   Se bloquea html Y body porque el elemento que scrollea es el <html>. */
html.ms-lock, body.ms-lock { overflow: hidden; height: 100%; }
@media (max-width: 768px) {
    .sidebar, .mobile-menu-toggle, .sidebar-overlay { display: none !important; }
    .main-content { margin-left: 0 !important; padding-bottom: 12px; }
    .app-version { padding-bottom: calc(70px + 18px + env(safe-area-inset-bottom)); }
    .bottom-nav { position: fixed; left: 0; right: 0; bottom: 0; height: 70px; display: flex; justify-content: space-around;
                  align-items: center; z-index: 1360; background: #fff; border-top: 1px solid #F3EADD; box-shadow: 0 -2px 12px rgba(0,0,0,.08); }
    .bottom-nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px;
                       flex: 1; text-decoration: none; color: #9A8778; font-size: .72rem; font-weight: 600; }
    .bottom-nav-item.active { color: var(--cb-primary,#6B4420); }
    .bottom-nav-item i { font-size: 1.15rem; }

    .more-overlay.active { position: fixed; inset: 0; background: rgba(62,36,18,.55); z-index: 1350; display: block; }
    .more-menu.ms-sheet {
        position: fixed; left: 0; right: 0; bottom: 0; top: auto;
        width: 100%; max-width: 100%; max-height: 85vh; max-height: 85dvh;
        background: #fff; border-radius: 22px 22px 0 0;
        box-shadow: 0 -12px 40px rgba(0,0,0,.28);
        transform: translateY(110%); transition: transform .28s cubic-bezier(.4,0,.2,1);
        display: block; padding: 0; overflow: hidden; z-index: 1400;
    }
    .more-menu.ms-sheet.active { transform: translateY(0); }
    .ms-handle { width: 42px; height: 4px; border-radius: 99px; background: #E4D9C6; margin: 10px auto 4px; }
    .ms-head { display: flex; align-items: center; justify-content: space-between; padding: 4px 18px 12px; border-bottom: 1px solid #eef2ec; }
    .ms-head span { font-weight: 800; color: var(--cb-forest,#3E2412); font-size: 1rem; display: inline-flex; align-items: center; gap: 8px; }
    .ms-close { border: none; background: #f1f5ef; width: 32px; height: 32px; border-radius: 50%; font-size: 1.25rem; color: #556; cursor: pointer; line-height: 1; }
    .ms-body { padding: 14px 16px calc(24px + env(safe-area-inset-bottom)); overflow-y: auto; max-height: calc(85vh - 62px); max-height: calc(85dvh - 62px); -webkit-overflow-scrolling: touch; overscroll-behavior: contain; touch-action: pan-y; }
    .ms-group { margin-bottom: 18px; }
    .ms-title { font-size: .7rem; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: #A2907C; margin: 2px 4px 10px; }
    .ms-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
    .ms-tile { display: flex; flex-direction: column; align-items: center; gap: 7px; text-align: center; text-decoration: none;
               background: #f7faf6; border: 1px solid #F3EADD; border-radius: 14px; padding: 13px 6px; font-size: .72rem; font-weight: 600; color: var(--cb-forest,#3E2412); line-height: 1.2; }
    .ms-tile:active { transform: scale(.95); background: #F7F0E4; }
    .ms-ico { width: 42px; height: 42px; border-radius: 12px; background: #F5EBDC; color: var(--cb-primary,#6B4420);
              display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
    .ms-tile.ms-danger { color: #b91c1c; }
    .ms-tile.ms-danger .ms-ico { background: #fdeaea; color: #dc2626; }
}
</style>
<script>
function cerrarMas(){
    var m = document.getElementById('moreMenuDropdown');
    var o = document.getElementById('moreMenuOverlay');
    if (m) m.classList.remove('active');
    if (o) o.classList.remove('active');
    document.body.classList.remove('ms-lock');
    document.documentElement.classList.remove('ms-lock');
}
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const moreBtn = document.getElementById('moreNavButton');
    const moreMenu = document.getElementById('moreMenuDropdown');
    const moreOverlay = document.getElementById('moreMenuOverlay');
    if (moreBtn && moreMenu && moreOverlay) {
        moreBtn.addEventListener('click', function(e) {
            if (e.target.closest('.more-menu')) return;
            var on = moreMenu.classList.toggle('active');
            moreOverlay.classList.toggle('active', on);
            document.body.classList.toggle('ms-lock', on);
            document.documentElement.classList.toggle('ms-lock', on);
        });
        moreOverlay.addEventListener('click', function() {
            moreMenu.classList.remove('active');
            moreOverlay.classList.remove('active');
            document.body.classList.remove('ms-lock');
            document.documentElement.classList.remove('ms-lock');
        });

        // Navegación robusta de las tarjetas/enlaces del panel "Más".
        // BUG corregido: un toque en una tarjeta podía burbujear hasta
        // #moreNavButton y disparar el toggle, que CERRABA el panel en vez
        // de dejar navegar el enlace. Aquí detenemos la propagación (para que
        // nunca llegue al toggle) y navegamos de forma explícita, de modo que
        // el toque SIEMPRE abre la sección, aunque el <a> por defecto se pierda.
        moreMenu.querySelectorAll('a[href]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.stopPropagation();
                var href = link.getAttribute('href');
                if (href) {
                    document.body.classList.remove('ms-lock');
                    document.documentElement.classList.remove('ms-lock');
                    window.location.assign(href);
                }
            });
        });
    }
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebar-toggle');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
    }
});
</script>

<script src="<?= BASE_URL ?>/assets/js/mobile-menu.js?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>"></script>
<!-- bottom-nav-mobile.js DESACTIVADO: la barra ya viene en el HTML del footer.
     Cargarlo creaba una segunda barra y un overlay extra que congelaba el scroll. -->
<script src="<?= BASE_URL ?>/assets/js/bottom-sheets.js?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>"></script>
<!-- 4.2.0: la notificación (avisos-ui.js), los campos obligatorios y el botón «Procesando…» (toast-spinner.js). El orden importa:
     campos-obligatorios.js usa la notificación, y el botón de envío debe ir después para ver si el envío se detuvo. -->
<script src="<?= BASE_URL ?>/assets/js/avisos-ui.js?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/campos-obligatorios.js?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>"></script>
<?php /* 4.2.3: documento y teléfono de las personas solo aceptan lo que les corresponde (los campos con data-solo). */ ?>
<script src="<?= BASE_URL ?>/assets/js/campos-numericos.js?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/toast-spinner.js?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>"></script>
<?php
// Los mensajes que la pantalla dejó en $msg/$msgType, $mensaje o $flash salen como notificación (includes/mensajes.php decide cómo).
require_once __DIR__ . '/mensajes.php';
echo mensajesScript(mensajesDePagina(get_defined_vars()));
?>
<!-- Sugerencias del buscador de huéspedes. Se carga en todas las páginas pero
     no hace nada donde no hay un formulario marcado, que es casi ninguna. -->
<script src="<?= BASE_URL ?>/assets/js/buscador-huespedes.js?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>"></script>
<!-- El campo «escribe y elige» de Reservas, Check-in y Facturación (4.3.0): igual, no hace nada donde no hay `data-selector-busqueda`. -->
<script src="<?= BASE_URL ?>/assets/js/selector-busqueda.js?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>"></script>
<!-- El aviso de «este documento ya está registrado» mientras se escribe (4.3.0): igual, no hace nada donde no hay `data-verifica-documento`. -->
<script src="<?= BASE_URL ?>/assets/js/documento-existente.js?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>"></script>
<script>if ('serviceWorker' in navigator) { window.addEventListener('load', function(){ navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js', {scope:'<?= BASE_URL ?>/'}).catch(function(error){ console.warn('No se pudo iniciar el modo instalable:',error); }); }); }</script>

<?php
// Asistente de ayuda: tour de bienvenida + chat guiado (todas las páginas internas)
require_once __DIR__ . '/asistente.php';
?>

<script>
// Auto-logout por inactividad (cliente): cierra la sesión tras el mismo
// límite del servidor si no hay interacción. El servidor es el respaldo
// autoritativo en la siguiente navegación.
(function(){
    var LIMITE = <?= defined('SESION_INACTIVIDAD_MIN') ? (int)SESION_INACTIVIDAD_MIN : 30 ?> * 60 * 1000;
    var t;
    function salir(){ window.location.href = '<?= BASE_URL ?>/modules/auth/logout.php'; }
    function reiniciar(){ clearTimeout(t); t = setTimeout(salir, LIMITE); }
    ['mousemove','keydown','click','scroll','touchstart'].forEach(function(ev){
        document.addEventListener(ev, reiniciar, {passive:true});
    });
    reiniciar();
})();
</script>

<div class="app-version">
    Casa Bosque v<?= defined('APP_VERSION') ? htmlspecialchars(APP_VERSION) : '?' ?>
</div>
</body>
</html>
