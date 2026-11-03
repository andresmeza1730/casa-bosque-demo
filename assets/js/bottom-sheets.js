/* ============================================================
   BOTTOM SHEETS — Casa Bosque (versión corregida)
   Convierte formularios laterales en gavetas móviles.
   Arreglos:
   - El bloqueo de scroll del body SIEMPRE se restaura
     (recordando y devolviendo la posición exacta).
   - Se libera el bloqueo al enviar el formulario (antes de recargar).
   - Solo auto-abre si la alerta está DENTRO del formulario del módulo,
     no por una alerta cualquiera de la página (ej. mensaje flash).
   Archivo: assets/js/bottom-sheets.js
   ============================================================ */

(function () {
    'use strict';

    if (window.innerWidth > 768) return;

    const formCards = document.querySelectorAll('.module-grid > .card:first-child');
    if (!formCards.length) return;

    // ── Manejo robusto del bloqueo de scroll ──
    // Recuerda la posición y la restaura al desbloquear, para que la
    // página no se quede "congelada arriba".
    let _scrollY = 0;
    function bloquearScroll() {
        _scrollY = window.scrollY || window.pageYOffset || 0;
        document.body.classList.add('bs-open');
        //document.body.style.overflow = 'hidden';
    }
    function liberarScroll() {
        document.body.classList.remove('bs-open');
        document.body.style.overflow = '';
        window.scrollTo(0, _scrollY);
    }

    // Red de seguridad: si la página se oculta o se descarga con el
    // sheet abierto, liberamos el scroll igualmente.
    window.addEventListener('pagehide', liberarScroll);
    window.addEventListener('beforeunload', liberarScroll);

    formCards.forEach(card => {
        const form = card.querySelector('form');
        if (!form) return;

        card.style.display = 'none';

        const fab = document.createElement('button');
        fab.type = 'button';
        fab.className = 'bs-fab';
        fab.innerHTML = '<i class="fas fa-plus"></i>';
        document.body.appendChild(fab);

        const overlay = document.createElement('div');
        overlay.className = 'bs-overlay';
        document.body.appendChild(overlay);

        const sheet = document.createElement('div');
        sheet.className = 'bs-sheet';
        sheet.innerHTML = `
            <div class="bs-handle"></div>
            <div class="bs-header">
                <span class="bs-title">${card.querySelector('h3')?.innerHTML || 'Formulario'}</span>
                <button type="button" class="bs-close"><i class="fas fa-times"></i></button>
            </div>
            <div class="bs-body"></div>
        `;
        sheet.querySelector('.bs-body').appendChild(form);
        document.body.appendChild(sheet);

        const submitBtn = form.querySelector('[type="submit"]');
        if (submitBtn) {
            submitBtn.style.width = '100%';
            submitBtn.style.justifyContent = 'center';
        }

        function open() {
            sheet.classList.add('active');
            overlay.classList.add('active');
            fab.querySelector('i').className = 'fas fa-times';
            bloquearScroll();
        }

        function close() {
            sheet.classList.remove('active');
            overlay.classList.remove('active');
            fab.querySelector('i').className = 'fas fa-plus';
            liberarScroll();
        }

        fab.addEventListener('click', () => sheet.classList.contains('active') ? close() : open());
        overlay.addEventListener('click', close);
        sheet.querySelector('.bs-close').addEventListener('click', close);

        // Al enviar el formulario, liberar el scroll ANTES de que la
        // página recargue (evita que quede bloqueado tras el submit).
        form.addEventListener('submit', liberarScroll);

        // Auto-abrir SOLO si la alerta está dentro de este formulario
        // (un error de validación del propio form), no por mensajes
        // flash u otras alertas de la página.
        const alertaEnForm = form.querySelector('.alert');
        if (alertaEnForm) open();
    });
})();