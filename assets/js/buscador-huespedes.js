/* ============================================================
   assets/js/buscador-huespedes.js
   Sugerencias de huéspedes mientras se escribe
   ============================================================

   Se engancha solo a los formularios marcados con
   `data-buscador-huespedes` —hoy el del panel de recepción y el del
   dashboard— y va mostrando coincidencias debajo del campo, como la barra de
   un navegador. Antes había que mandar el formulario, recargar y aterrizar en
   el directorio para ver si se había acertado.

   Lo que NO cambia: mandar el formulario con Enter, sin elegir nada de la
   lista, sigue llevando al directorio de Huéspedes con la búsqueda hecha. Ese
   camino ya existía y hay gente acostumbrada a él.

   Un detalle que importa: los nombres salen de la base, así que se escriben
   con `textContent` y nunca con `innerHTML`. Un huésped llamado `<script>`
   no debe poder ejecutar nada en la pantalla de recepción.
*/

(function () {
    'use strict';

    var MIN_LETRAS = 2;      // por debajo de esto no se consulta
    var ESPERA_MS  = 250;    // lo que se espera a que deje de teclear
    var AVISO_MS   = 400;    // «Buscando…» solo sale si la respuesta tarda más que esto: una que llega antes lo haría parpadear

    document.querySelectorAll('form[data-buscador-huespedes]').forEach(function (form) {
        var campo = form.querySelector('input[name="q"]');
        if (!campo) return;

        var base = form.getAttribute('data-buscar-url');
        var ficha = form.getAttribute('data-ficha-url');
        if (!base || !ficha) return;

        var panel = document.createElement('div');
        panel.className = 'bh-panel';
        panel.setAttribute('role', 'listbox');
        panel.hidden = true;
        form.appendChild(panel);

        var temporizador = null;
        var esperando = null;    // el temporizador del «Buscando…»
        var peticion = null;     // para descartar respuestas que llegan tarde
        var marcado = -1;        // cuál está resaltado con las flechas

        function cerrar() {
            panel.hidden = true;
            panel.innerHTML = '';
            marcado = -1;
        }

        function opciones() {
            return panel.querySelectorAll('.bh-item[data-id]');
        }

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

        function abrirFicha(id) {
            window.location.assign(ficha + '?id=' + encodeURIComponent(id));
        }

        function pintar(resultados) {
            panel.innerHTML = '';
            marcado = -1;

            if (!resultados.length) {
                var vacio = document.createElement('div');
                vacio.className = 'bh-item bh-vacio';
                vacio.textContent = 'Sin coincidencias';
                panel.appendChild(vacio);
                panel.hidden = false;
                return;
            }

            resultados.forEach(function (r) {
                var fila = document.createElement('div');
                fila.className = 'bh-item';
                fila.setAttribute('role', 'option');
                fila.setAttribute('data-id', r.id);

                var nombre = document.createElement('span');
                nombre.className = 'bh-nombre';
                nombre.textContent = r.nombre;          // nunca innerHTML
                fila.appendChild(nombre);

                var meta = document.createElement('span');
                meta.className = 'bh-meta';
                meta.textContent = r.documento || '';
                fila.appendChild(meta);

                if (r.habitacion) {
                    var hab = document.createElement('span');
                    hab.className = 'bh-hab';
                    hab.textContent = 'Hab. ' + r.habitacion;
                    fila.appendChild(hab);
                }

                // `mousedown` y no `click`: el clic llega después del blur del
                // campo, y para entonces el panel ya se habría cerrado.
                fila.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    abrirFicha(r.id);
                });

                panel.appendChild(fila);
            });

            panel.hidden = false;
        }

        /* «Buscando…» mientras la consulta sigue en el servidor. Es una fila SIN `data-id`: las flechas y el clic la ignoran. */
        function avisarBusqueda() {
            panel.innerHTML = '';
            marcado = -1;
            var fila = document.createElement('div');
            fila.className = 'bh-item bh-vacio';
            fila.textContent = 'Buscando…';
            panel.appendChild(fila);
            panel.hidden = false;
        }

        function consultar() {
            var q = campo.value.trim();
            clearTimeout(esperando);
            if (q.length < MIN_LETRAS) { cerrar(); return; }

            // Si ya había una consulta en vuelo, se abandona: solo interesa la
            // respuesta de lo último que se escribió.
            if (peticion) peticion.abort();
            peticion = new AbortController();
            var mia = peticion;
            esperando = setTimeout(function () { if (peticion === mia) avisarBusqueda(); }, AVISO_MS);

            // POST y no GET: con `?q=` el documento del huesped quedaba en
            // los registros de acceso del hosting en cada pulsacion. El
            // testigo sale del campo oculto del propio formulario y no se
            // gasta (ver modules/huespedes/buscar.php).
            var cuerpo = new FormData();
            cuerpo.append('q', q);
            var campoTestigo = form.querySelector('input[name="csrf_token"]');
            if (campoTestigo) cuerpo.append('csrf_token', campoTestigo.value);

            fetch(base, {
                method: 'POST',
                body: cuerpo,
                signal: peticion.signal,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(function (r) {
                    clearTimeout(esperando);
                    // 401 sesión vencida, 403 testigo caducado: en los dos
                    // casos no hay nada que mostrar y la página se encarga
                    // cuando se mande el formulario.
                    if (r.status === 401 || r.status === 403) { cerrar(); return null; }
                    return r.ok ? r.json() : null;
                })
                .then(function (datos) {
                    // Una respuesta sin datos (error del servidor) no puede dejar el «Buscando…» pegado.
                    if (!datos) { if (peticion === mia) cerrar(); return; }
                    // Puede haber cambiado el texto mientras llegaba.
                    if (campo.value.trim() !== q) return;
                    pintar(datos.resultados || []);
                })
                .catch(function (e) {
                    if (e.name !== 'AbortError') { clearTimeout(esperando); cerrar(); }
                });
        }

        campo.setAttribute('autocomplete', 'off');

        campo.addEventListener('input', function () {
            clearTimeout(temporizador);
            temporizador = setTimeout(consultar, ESPERA_MS);
        });

        campo.addEventListener('keydown', function (e) {
            if (panel.hidden) return;

            if (e.key === 'ArrowDown')      { e.preventDefault(); resaltar(marcado + 1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); resaltar(marcado - 1); }
            else if (e.key === 'Escape')    { cerrar(); }
            else if (e.key === 'Enter' && marcado >= 0) {
                // Con uno resaltado, Enter abre ese. Sin nada resaltado se
                // manda el formulario como siempre.
                var el = opciones()[marcado];
                if (el) { e.preventDefault(); abrirFicha(el.getAttribute('data-id')); }
            }
        });

        campo.addEventListener('blur', function () { setTimeout(cerrar, 120); });
        campo.addEventListener('focus', function () {
            if (campo.value.trim().length >= MIN_LETRAS) consultar();
        });
    });
})();
