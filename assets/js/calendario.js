// ============================================================
// assets/js/calendario.js — La tarjeta del calendario
// ============================================================
// Al tocar una celda (o Enter / Espacio) sale una tarjeta con el huésped
// y las fechas, igual en el celular y en el computador. Cierra con el botón,
// con Esc o tocando fuera. Es una tarjeta del sistema, no un diálogo del
// navegador.
//
// Todo lo que se escribe en la tarjeta se escribe como TEXTO: el nombre del
// huésped es un dato que viene de afuera y no puede interpretarse como HTML.
// La tarjeta solo informa; no ofrece acciones.
(function () {
    'use strict';

    var cal = document.getElementById('cal');
    var modal = document.getElementById('cal-modal');
    if (!cal || !modal) { return; }

    var tarjeta = modal.querySelector('.cal-card');
    var campo = function (id) { return document.getElementById(id); };
    var celdaAbierta = null;

    function abrir(celda) {
        var d = celda.dataset;
        var libre = d.estado === 'libre';

        campo('cal-card-fecha').textContent = d.fecha;
        campo('cal-card-titulo').textContent = 'Hab. ' + d.hab + ' · ' + d.tipo;

        var chip = campo('cal-card-estado');
        chip.textContent = d.estadoTxt;
        chip.className = 'cal-card-estado cal-' + d.estado;

        campo('cal-card-huesped').textContent = d.huesped;
        campo('cal-card-entrada').textContent = d.entrada;
        campo('cal-card-salida').textContent = d.salida;
        campo('cal-card-datos').hidden = libre;
        campo('cal-card-libre').hidden = !libre;

        celdaAbierta = celda;
        modal.hidden = false;
        tarjeta.focus();
    }

    function cerrar() {
        modal.hidden = true;
        if (celdaAbierta) {
            celdaAbierta.focus();
            celdaAbierta = null;
        }
    }

    cal.addEventListener('click', function (ev) {
        var celda = ev.target.closest('.cal-cell');
        if (celda) { abrir(celda); }
    });

    modal.addEventListener('click', function (ev) {
        if (ev.target.closest('[data-cal-close]')) { cerrar(); }
    });

    document.addEventListener('keydown', function (ev) {
        if (modal.hidden) { return; }
        if (ev.key === 'Escape') {
            ev.preventDefault();
            cerrar();
            return;
        }
        if (ev.key !== 'Tab') { return; }
        // El foco no se escapa de la tarjeta mientras está abierta.
        var botones = tarjeta.querySelectorAll('button');
        var primero = botones[0];
        var ultimo = botones[botones.length - 1];
        if (ev.shiftKey && (document.activeElement === primero || document.activeElement === tarjeta)) {
            ev.preventDefault();
            ultimo.focus();
        } else if (!ev.shiftKey && document.activeElement === ultimo) {
            ev.preventDefault();
            primero.focus();
        }
    });
}());
