// ============================================================
// assets/js/auditoria.js — La pantalla de Auditoría
// ============================================================
// Dos cosas: la tarjeta «Ver detalle» (antes y después, lado a lado) y el botón
// que descarga el PDF.
//
// Todo lo que se escribe en la tarjeta se escribe como TEXTO (textContent): el
// detalle y el navegador son datos que escribió otra persona o otro programa, y no
// pueden interpretarse como HTML. La tarjeta solo informa; no ofrece acciones. Es
// una tarjeta del sistema, no un diálogo del navegador.
(function () {
    'use strict';

    var crudo = document.getElementById('au-datos');
    var modal = document.getElementById('au-modal');
    if (!crudo || !modal) { return; }

    var filas = {};
    try {
        JSON.parse(crudo.textContent).forEach(function (f) { filas[f.id] = f; });
    } catch (e) { return; }

    var tarjeta = modal.querySelector('.au-card');
    var campo = function (id) { return document.getElementById(id); };
    var origen = null;

    /** Llena un <dl> con «clave: valor». Devuelve false si no hay nada que mostrar. */
    function llenar(dl, datos) {
        dl.textContent = '';
        var claves = datos ? Object.keys(datos) : [];
        claves.forEach(function (k) {
            var dt = document.createElement('dt');
            var dd = document.createElement('dd');
            dt.textContent = k;
            dd.textContent = String(datos[k]);
            dl.appendChild(dt);
            dl.appendChild(dd);
        });
        return claves.length > 0;
    }

    function abrir(boton) {
        var f = filas[boton.dataset.id];
        if (!f) { return; }

        campo('au-card-fecha').textContent = f.fecha_vista;
        campo('au-card-titulo').textContent = f.accion + ' · ' + f.modulo;
        var chip = campo('au-card-grav');
        chip.textContent = f.severidad;
        chip.className = 'au-grav au-grav-' + f.severidad.toLowerCase();

        campo('au-card-usuario').textContent = f.usuario;
        campo('au-card-categoria').textContent = f.categoria;
        campo('au-card-tabla').textContent = f.tabla + (f.registro !== null ? ' #' + f.registro : '');
        campo('au-card-ip').textContent = f.ip || '—';
        campo('au-card-agente').textContent = f.agente || '—';
        campo('au-card-detalle').textContent = f.detalle || '—';

        var hayAntes = llenar(campo('au-card-antes'), f.antes);
        var hayDespues = llenar(campo('au-card-despues'), f.despues);
        campo('au-card-cambio').hidden = !(hayAntes || hayDespues);
        campo('au-card-antes-w').hidden = !hayAntes;
        campo('au-card-despues-w').hidden = !hayDespues;

        origen = boton;
        modal.hidden = false;
        tarjeta.focus();
    }

    function cerrar() {
        modal.hidden = true;
        if (origen) { origen.focus(); origen = null; }
    }

    document.addEventListener('click', function (ev) {
        var boton = ev.target.closest('[data-au-det]');
        if (boton) { abrir(boton); return; }
        if (ev.target.closest('[data-au-close]')) { cerrar(); }
    });

    document.addEventListener('keydown', function (ev) {
        if (modal.hidden) { return; }
        if (ev.key === 'Escape') { ev.preventDefault(); cerrar(); return; }
        if (ev.key !== 'Tab') { return; }
        // El foco no se escapa de la tarjeta mientras está abierta.
        var botones = tarjeta.querySelectorAll('button');
        var primero = botones[0], ultimo = botones[botones.length - 1];
        if (ev.shiftKey && (document.activeElement === primero || document.activeElement === tarjeta)) {
            ev.preventDefault(); ultimo.focus();
        } else if (!ev.shiftKey && document.activeElement === ultimo) {
            ev.preventDefault(); primero.focus();
        }
    });

    // ── El PDF ──
    var btnPdf = document.getElementById('au-pdf');
    var aviso = document.getElementById('au-aviso');
    function avisar(texto) {
        if (!aviso) { return; }
        aviso.textContent = texto;
        aviso.hidden = !texto;
    }
    if (btnPdf) {
        btnPdf.addEventListener('click', function () {
            avisar('');
            // Mientras el servidor arma los datos y el navegador dibuja el PDF el botón lo dice: antes solo se deshabilitaba y no se
            // sabía si había pasado algo. `original` son los nodos que puso el servidor (el ícono y el texto): se guardan y se devuelven
            // como nodos, sin `innerHTML` —este archivo solo escribe texto—.
            var original = Array.prototype.map.call(btnPdf.childNodes, function (n) { return n.cloneNode(true); });
            btnPdf.disabled = true;
            btnPdf.setAttribute('aria-busy', 'true');
            btnPdf.textContent = 'Generando PDF…';
            fetch(btnPdf.dataset.url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) {
                    if (!r.ok) { throw new Error('El servidor respondió ' + r.status); }
                    return r.json();
                })
                .then(function (datos) { window.AuditoriaPDF.descargar(datos); })
                .catch(function (e) {
                    avisar('No se pudo generar el PDF. ' + (e && e.message ? e.message : '') + ' Vuelve a intentarlo.');
                })
                .then(function () { btnPdf.disabled = false; btnPdf.removeAttribute('aria-busy'); btnPdf.replaceChildren.apply(btnPdf, original); });
        });
    }
}());
