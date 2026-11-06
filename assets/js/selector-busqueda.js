/* ============================================================
   assets/js/selector-busqueda.js
   El campo «escribe y elige» (4.3.0)
   ============================================================

   Se engancha a cada `[data-selector-busqueda]` que pinta includes/selector_busqueda.php: un campo de texto que pregunta al servidor mientras
   se escribe y deja ELEGIDO a uno. El id viaja en el campo oculto (el que el formulario ya enviaba), así que el POST no cambia.

   - Se pregunta por POST y con el testigo del formulario sin gastarlo, igual que el buscador de recepción (modules/huespedes/buscar.php):
     lo escrito puede ser un documento y no debe quedar en la URL.
   - Lo que llega puede venir de lo que alguien tecleó un día: se escribe con `textContent`, nunca con `innerHTML`.
   - Mientras haya texto y nadie elegido, el campo no deja enviar el formulario («Elige a alguien de la lista»): escribir un nombre y no
     pulsar sobre él no es elegirlo.
   - Al elegir (o al cambiar) se avisa a la pantalla con un evento `change` del campo oculto, que es lo que ya escuchaban.
*/

(function () {
    'use strict';

    var ESPERA_MS = 250;     // lo que se espera a que deje de teclear
    var AVISO_MS  = 400;     // «Buscando…» solo sale si la respuesta tarda más: una que llega antes lo haría parpadear

    document.querySelectorAll('[data-selector-busqueda]').forEach(function (caja) {
        var url    = caja.getAttribute('data-url');
        var minimo = parseInt(caja.getAttribute('data-min'), 10);
        if (isNaN(minimo)) minimo = 2;
        var modo   = caja.getAttribute('data-modo') || 'evento';
        var param  = caja.getAttribute('data-param') || '';
        var oculto = caja.querySelector('input[type="hidden"]');
        var campo  = caja.querySelector('.sb-campo');
        var chip   = caja.querySelector('.sb-elegido');
        var titulo = caja.querySelector('.sb-tit');
        var detalle = caja.querySelector('.sb-det');
        var cambiar = caja.querySelector('.sb-cambiar');
        var panel  = caja.querySelector('.sb-panel');
        var form   = caja.closest('form');
        if (!url || !oculto || !campo || !panel) return;

        var temporizador = null;
        var esperando = null;     // el temporizador del «Buscando…»
        var peticion = null;      // para descartar respuestas que llegan tarde
        var marcado = -1;         // cuál está resaltado con las flechas
        /* ¿Se puede enviar? Con texto escrito y nadie elegido, no. Un campo que no se ve (otra pestaña del formulario) no estorba: un campo
           inválido y oculto deja el formulario sin enviarse y sin decir por qué. */
        function validar() {
            var visible = caja.offsetParent !== null;
            var requerido = campo.getAttribute('data-requerido') === '1';
            if (visible && oculto.value === '' && campo.value.trim() !== '') {
                campo.setCustomValidity('Elige a alguien de la lista que aparece al escribir.');
            } else {
                campo.setCustomValidity('');
            }
            campo.required = visible && requerido && oculto.value === '';
        }

        function cerrar() {
            panel.hidden = true;
            while (panel.firstChild) panel.removeChild(panel.firstChild);
            marcado = -1;
            campo.setAttribute('aria-expanded', 'false');
        }

        function opciones() { return panel.querySelectorAll('.sb-item[data-id]'); }

        function resaltar(i) {
            var items = opciones();
            if (!items.length) return;
            if (i < 0) i = items.length - 1;
            if (i >= items.length) i = 0;
            items.forEach(function (el) { el.classList.remove('on'); });
            items[i].classList.add('on');
            items[i].scrollIntoView({ block: 'nearest' });
            marcado = i;
        }

        function mostrarElegido(r) {
            titulo.textContent = r.nombre || '';
            var partes = [];
            if (r.documento) partes.push(r.documento);
            if (r.habitacion) partes.push('Hab. ' + r.habitacion);
            if (r.detalle) partes.push(r.detalle);
            detalle.textContent = partes.join(' · ');
            chip.hidden = false;
            campo.hidden = true;
            campo.value = '';
        }

        function elegir(r) {
            oculto.value = String(r.id);
            oculto.setAttribute('data-conocido', r.conocido ? '1' : '0');
            cerrar();
            if (modo === 'recargar' && param) {
                window.location.assign(window.location.pathname + '?' + encodeURIComponent(param) + '=' + encodeURIComponent(r.id));
                return;
            }
            mostrarElegido(r);
            validar();
            oculto.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function quitar() {
            oculto.value = '';
            oculto.removeAttribute('data-conocido');
            chip.hidden = true;
            campo.hidden = false;
            campo.value = '';
            validar();
            oculto.dispatchEvent(new Event('change', { bubbles: true }));
            campo.focus();
        }

        function fila(texto, clase) {
            var f = document.createElement('div');
            f.className = 'sb-item ' + clase;
            f.textContent = texto;
            return f;
        }

        function pintar(resultados) {
            while (panel.firstChild) panel.removeChild(panel.firstChild);
            marcado = -1;
            if (!resultados.length) {
                panel.appendChild(fila('Sin coincidencias', 'sb-vacio'));
                panel.hidden = false;
                campo.setAttribute('aria-expanded', 'true');
                return;
            }
            resultados.forEach(function (r) {
                var f = document.createElement('div');
                f.className = 'sb-item';
                f.setAttribute('role', 'option');
                f.setAttribute('data-id', r.id);

                var n = document.createElement('span');
                n.className = 'sb-nombre';
                n.textContent = r.nombre || '';
                f.appendChild(n);

                var partes = [];
                if (r.documento) partes.push(r.documento);
                if (r.habitacion) partes.push('Hab. ' + r.habitacion);
                if (r.detalle) partes.push(r.detalle);
                if (partes.length) {
                    var m = document.createElement('span');
                    m.className = 'sb-meta';
                    m.textContent = partes.join(' · ');
                    f.appendChild(m);
                }

                // `mousedown` y no `click`: el clic llega después del blur del campo, y para entonces el panel ya se habría cerrado.
                f.addEventListener('mousedown', function (e) { e.preventDefault(); elegir(r); });
                panel.appendChild(f);
            });
            panel.hidden = false;
            campo.setAttribute('aria-expanded', 'true');
        }

        function avisarBusqueda() {
            while (panel.firstChild) panel.removeChild(panel.firstChild);
            marcado = -1;
            panel.appendChild(fila('Buscando…', 'sb-vacio'));
            panel.hidden = false;
        }

        function consultar() {
            var q = campo.value.trim();
            clearTimeout(esperando);
            if (q.length < minimo) { cerrar(); return; }

            // Si ya había una consulta en vuelo, se abandona: solo interesa la de lo último que se escribió.
            if (peticion) peticion.abort();
            peticion = new AbortController();
            var mia = peticion;
            esperando = setTimeout(function () { if (peticion === mia) avisarBusqueda(); }, AVISO_MS);

            var cuerpo = new FormData();
            cuerpo.append('q', q);
            var testigo = form ? form.querySelector('input[name="csrf_token"]') : null;
            if (testigo) cuerpo.append('csrf_token', testigo.value);

            fetch(url, { method: 'POST', body: cuerpo, signal: peticion.signal, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) {
                    clearTimeout(esperando);
                    // 401 sesión vencida, 403 testigo caducado o sin permiso: no hay nada que mostrar.
                    if (r.status === 401 || r.status === 403) { cerrar(); return null; }
                    return r.ok ? r.json() : null;
                })
                .then(function (datos) {
                    if (!datos) { if (peticion === mia) cerrar(); return; }
                    if (campo.value.trim() !== q) return;       // cambió el texto mientras llegaba
                    pintar(datos.resultados || []);
                })
                .catch(function (e) {
                    if (e.name !== 'AbortError') { clearTimeout(esperando); cerrar(); }
                });
        }

        campo.addEventListener('input', function () {
            validar();
            clearTimeout(temporizador);
            temporizador = setTimeout(consultar, ESPERA_MS);
        });

        campo.addEventListener('keydown', function (e) {
            if (panel.hidden) {
                // Enter con texto y sin lista abierta no envía el formulario a medias.
                if (e.key === 'Enter' && oculto.value === '' && campo.value.trim() !== '') e.preventDefault();
                return;
            }
            if (e.key === 'ArrowDown')    { e.preventDefault(); resaltar(marcado + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); resaltar(marcado - 1); }
            else if (e.key === 'Escape')  { cerrar(); }
            else if (e.key === 'Enter') {
                e.preventDefault();
                var el = opciones()[marcado >= 0 ? marcado : 0];     // sin resaltar ninguno, Enter elige el primero
                if (el) el.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
            }
        });

        campo.addEventListener('blur', function () { setTimeout(cerrar, 120); });
        campo.addEventListener('focus', function () { if (campo.value.trim().length >= minimo) consultar(); });

        if (cambiar) cambiar.addEventListener('click', quitar);

        // La pantalla que lo muestra u oculta (las pestañas de Check-in) o cambia si es obligatorio avisa con este evento.
        caja.addEventListener('sb:revalidar', validar);

        validar();
    });
})();
