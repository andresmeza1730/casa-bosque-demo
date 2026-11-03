/* ============================================================
   MENÚ MÓVIL — Sistema Casa Hotel
   Manejo del sidebar colapsable en dispositivos móviles
   ============================================================ */

document.addEventListener('DOMContentLoaded', function() {
    // Crear botón hamburguesa
    const menuToggle = document.createElement('button');
    menuToggle.className = 'mobile-menu-toggle';
    menuToggle.innerHTML = '<i class="fas fa-bars"></i>';
    menuToggle.setAttribute('aria-label', 'Abrir menú');
    document.body.appendChild(menuToggle);

    // Crear overlay (fondo oscuro)
    const overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);

    const sidebar = document.getElementById('sidebar');

    // Abrir/cerrar menú al tocar hamburguesa
    menuToggle.addEventListener('click', function() {
        const isOpen = sidebar.classList.toggle('open');
        overlay.classList.toggle('active', isOpen);
        
        // Cambiar icono
        const icon = menuToggle.querySelector('i');
        icon.className = isOpen ? 'fas fa-times' : 'fas fa-bars';
        
        // Accesibilidad
        menuToggle.setAttribute('aria-label', isOpen ? 'Cerrar menú' : 'Abrir menú');
    });

    // Cerrar menú al tocar overlay
    overlay.addEventListener('click', function() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        menuToggle.querySelector('i').className = 'fas fa-bars';
        menuToggle.setAttribute('aria-label', 'Abrir menú');
    });

    // Cerrar menú al tocar un enlace (navegación)
    const navLinks = sidebar.querySelectorAll('.sidebar-nav a');
    navLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
                menuToggle.querySelector('i').className = 'fas fa-bars';
                menuToggle.setAttribute('aria-label', 'Abrir menú');
            }
        });
    });

    // Cerrar menú al presionar ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('open')) {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
            menuToggle.querySelector('i').className = 'fas fa-bars';
            menuToggle.setAttribute('aria-label', 'Abrir menú');
        }
    });

    // Ajustar al cambiar orientación o resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            // Si se pasa a desktop, resetear menú
            if (window.innerWidth > 768) {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
                menuToggle.querySelector('i').className = 'fas fa-bars';
            }
        }, 250);
    });
});
