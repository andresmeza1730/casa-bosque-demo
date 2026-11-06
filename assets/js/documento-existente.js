/* ============================================================
   assets/js/documento-existente.js
   Avisar de una vez que el documento ya está registrado (4.3.0)
   ============================================================

   Se engancha a los campos marcados con `data-verifica-documento="<url>"` (el número de documento de «Nuevo huésped» y el de «Nuevo» en
   Check-in). Mientras se escribe, pregunta al servidor (modules/huespedes/existe.php) si ese documento ya es de alguien y, si lo es, lo dice
   debajo del campo, con el nombre, dónde está alojado si lo está y un enlace a su ficha. Antes se enteraba uno al ENVIAR el formulario.

   Atributos del campo:
     data-verifica-documento   la URL de existe.php
     data-tipo-en              el `name` del <select> del tipo de documento, en el mismo formulario (lo comparte con campos-numericos.js)
     data-ficha-url            la URL de la ficha, para el enlace (se le añade ?id=)
     data-bloquea="1"          mientras el documento esté repetido el campo no deja enviar el formulario (en Check-in NO: allí se reutiliza)
     data-aviso-extra          texto que sigue al aviso (lo que pasará al enviar)

   Lo que NO hace: el servidor sigue siendo quien decide al guardar. Esto solo avisa. Y lo que llega puede venir de lo que alguien tecleó un
   día, así que se escribe con `textContent`, nunca con `innerHTML`.
*/

(function () {
    'use strict';

    var MIN_CARACTERES = 5;    // por debajo de esto no se pregunta: un documento de 3 o 4 cifras no es una búsqueda, es teclear
    var ESPERA_MS = 400;       // lo que se espera a que deje de teclear

    document.querySelectorAll('input[data-verifica-documento]').forEach(function (campo) {
        var url = campo.getAttribute('data-verifica-documento');
        if (!url) return;
        var form = campo.form;
        var bloquea = campo.getAttribute('data-bloquea') === '1';
        var fichaUrl = campo.getAttribute('data-ficha-url') || '';
        var extra = campo.getAttribute('data-aviso-extra') || '';
        var selTipo = form && campo.getAttribute('data-tipo-en') ? form.querySelector('[name="' + campo.getAttribute('data-tipo-en') + '"]') : null;

        var aviso = document.createElement('div');
        aviso.className = 'de-aviso';
        aviso.setAttribute('role', 'status');
        aviso.hidden = true;
        campo.insertAdjacentElement('afterend', aviso);

        var temporizador = null;
        var peticion = null;

        function limpiar() {
            aviso.hidden = true;
            while (aviso.firstChild) aviso.removeChild(aviso.firstChild);
            campo.setCustomValidity('');
        }

        function mostrar(r) {
            while (aviso.firstChild) aviso.removeChild(aviso.firstChild);

            var icono = document.createElement('i');
            icono.className = 'fas fa-circle-exclamation';
            aviso.appendChild(icono);

            var texto = document.createElement('span');
            var frase = 'Este documento ya está registrado a nombre de ' + r.nombre;
            if (r.habitacion) frase += ' (hospedado ahora en la Hab. ' + r.habitacion + ')';
            frase += '.';
            if (extra) frase += ' ' + extra;
            texto.textContent = ' ' + frase + ' ';
            aviso.appendChild(texto);

            if (fichaUrl) {
                var enlace = document.createElement('a');
                enlace.href = fichaUrl + (fichaUrl.indexOf('?') === -1 ? '?' : '&') + 'id=' + encodeURIComponent(r.id);
                enlace.textContent = 'Ver su ficha';
                enlace.target = '_blank';
                enlace.rel = 'noopener';
                aviso.appendChild(enlace);
            }
            aviso.hidden = false;
            if (bloquea) campo.setCustomValidity('Ese documento ya está registrado: no se puede registrar dos veces.');
        }

        function consultar() {
            var doc = campo.value.trim();
            var tipo = selTipo ? selTipo.value : '';
            // Sin espacios ni puntos ni guiones para contar: «1.234.567» son siete cifras.
            if (doc.replace(/[\s.\-]/g, '').length < MIN_CARACTERES) { limpiar(); return; }

            if (peticion) peticion.abort();
            peticion = new AbortController();
            var mia = peticion;

            var cuerpo = new FormData();
            cuerpo.append('tipo', tipo);
            cuerpo.append('documento', doc);
            var testigo = form ? form.querySelector('input[name="csrf_token"]') : null;
            if (testigo) cuerpo.append('csrf_token', testigo.value);

            fetch(url, { method: 'POST', body: cuerpo, signal: peticion.signal, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) {
                    // 401 sesión vencida, 403 testigo caducado: no hay nada que avisar; la página se encarga al enviar.
                    if (r.status === 401 || r.status === 403) { limpiar(); return null; }
                    return r.ok ? r.json() : null;
                })
                .then(function (datos) {
                    if (!datos || peticion !== mia) return;
                    if (campo.value.trim() !== doc) return;      // cambió el texto mientras llegaba
                    if (datos.existe) mostrar(datos); else limpiar();
                })
                .catch(function (e) { if (e.name !== 'AbortError') limpiar(); });
        }

        function programar() {
            clearTimeout(temporizador);
            // Al empezar a cambiar se quita el bloqueo anterior: si ya no es el mismo documento, no puede seguir estorbando.
            campo.setCustomValidity('');
            temporizador = setTimeout(consultar, ESPERA_MS);
        }

        campo.addEventListener('input', programar);
        if (selTipo) selTipo.addEventListener('change', programar);
    });
})();
