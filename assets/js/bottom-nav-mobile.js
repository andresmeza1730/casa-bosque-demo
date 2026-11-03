/* ============================================================
   BOTTOM NAVIGATION — JavaScript
   Sistema Casa Hotel - Menú inferior para móviles
   ============================================================ */

document.addEventListener('DOMContentLoaded', function() {
    // Solo crear menú inferior en móviles (≤768px)
    if (window.innerWidth <= 768) {
        createBottomNav();
    }

    // Recrear al cambiar tamaño de ventana
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            const bottomNav = document.querySelector('.bottom-nav');
            
            if (window.innerWidth <= 768 && !bottomNav) {
                createBottomNav();
            } else if (window.innerWidth > 768 && bottomNav) {
                bottomNav.remove();
                document.querySelector('.more-overlay')?.remove();
            }
        }, 250);
    });
});

function createBottomNav() {
    // Evitar duplicados
    if (document.querySelector('.bottom-nav')) return;

    // Detectar página actual
    const currentPage = window.location.pathname;
    const isActive = (path) => currentPage.includes(path) ? 'active' : '';

    // Crear overlay para cerrar menú "Más"
    const overlay = document.createElement('div');
    overlay.className = 'more-overlay';
    document.body.appendChild(overlay);

    // Crear barra de navegación inferior
    const nav = document.createElement('nav');
    nav.className = 'bottom-nav';
    nav.innerHTML = `
        <a href="${BASE_URL}/dashboard.php" class="bottom-nav-item ${isActive('dashboard')}">
            <i class="fas fa-chart-pie"></i>
            <span>Inicio</span>
        </a>

        <a href="${BASE_URL}/modules/habitaciones/index.php" class="bottom-nav-item ${isActive('habitaciones')}">
            <i class="fas fa-bed"></i>
            <span>Rooms</span>
        </a>

        <a href="${BASE_URL}/modules/checkin/index.php" class="bottom-nav-item ${isActive('checkin')}">
            <i class="fas fa-door-open"></i>
            <span>Check-in</span>
        </a>

        <a href="${BASE_URL}/modules/checkout/index.php" class="bottom-nav-item ${isActive('checkout')}">
            <i class="fas fa-door-closed"></i>
            <span>Check-out</span>
        </a>

        <div class="bottom-nav-item bottom-nav-more" id="moreBtn">
            <i class="fas fa-ellipsis-h"></i>
            <span>Más</span>
        </div>
    `;

    // Crear menú desplegable "Más"
    const moreMenu = document.createElement('div');
    moreMenu.className = 'more-menu';
    moreMenu.id = 'moreMenu';
    
    // Verificar si el usuario es admin
    const userRole = document.querySelector('.user-profile')?.textContent.toLowerCase();
    const isAdmin = userRole?.includes('admin');

    moreMenu.innerHTML = `
        <div class="nav-section">Operaciones</div>
        <a href="${BASE_URL}/modules/huespedes/index.php">
            <i class="fas fa-users"></i> Huéspedes
        </a>
        <a href="${BASE_URL}/modules/reservas/index.php">
            <i class="fas fa-calendar-check"></i> Reservas
        </a>
        <a href="${BASE_URL}/modules/consumos/index.php">
            <i class="fas fa-receipt"></i> Consumos
        </a>

        ${isAdmin ? `
        <div class="nav-section">Administración</div>
        <a href="${BASE_URL}/modules/auditoria/index.php">
            <i class="fas fa-clipboard-list"></i> Auditoría
        </a>
        ` : ''}

        <div class="nav-section">Cuenta</div>
        <a href="${BASE_URL}/modules/auth/logout.php">
            <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
        </a>
    `;

    // Agregar menú "Más" al botón
    document.body.appendChild(nav);
    nav.querySelector('.bottom-nav-more').appendChild(moreMenu);

    // Toggle del menú "Más"
    const moreBtn = document.getElementById('moreBtn');
    const moreMenuEl = document.getElementById('moreMenu');

    moreBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        const isActive = moreMenuEl.classList.toggle('active');
        overlay.classList.toggle('active', isActive);
    });

    // Cerrar menú al tocar overlay
    overlay.addEventListener('click', function() {
        moreMenuEl.classList.remove('active');
        overlay.classList.remove('active');
    });

    // Cerrar menú al tocar un enlace
    moreMenuEl.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', function() {
            moreMenuEl.classList.remove('active');
            overlay.classList.remove('active');
        });
    });

    // Cerrar menú al presionar ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && moreMenuEl.classList.contains('active')) {
            moreMenuEl.classList.remove('active');
            overlay.classList.remove('active');
        }
    });

    // Cerrar menú al hacer scroll (opcional)
    let scrollTimer;
    window.addEventListener('scroll', function() {
        if (moreMenuEl.classList.contains('active')) {
            clearTimeout(scrollTimer);
            scrollTimer = setTimeout(function() {
                moreMenuEl.classList.remove('active');
                overlay.classList.remove('active');
            }, 150);
        }
    });
}
