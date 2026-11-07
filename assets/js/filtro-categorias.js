/* ============================================================
   FILTRO POR CATEGORÍA — Casa Bosque (5.0.0)
   Archivo: assets/js/filtro-categorias.js
   ------------------------------------------------------------
   En Consumos, una fila de botones (Todas, Bebidas, Snack…) deja ver en el selector de productos solo los de una categoría.

   Cómo se declara: un contenedor `<div data-filtro-para="ID_DEL_SELECT">` con botones `<button data-cat="clave">` (el de «Todas» lleva
   `data-cat=""`), y cada producto del selector lleva `data-categoria="clave"`. Las opciones sin `data-categoria` (el «Seleccionar…» y el
   «Otro concepto (registro manual)») se quedan siempre.

   Por qué se RECONSTRUYE el selector y no se esconden opciones: `option.hidden` no funciona en Safari (iPhone), y un filtro que no filtra en
   el celular de recepción no sirve. Se guardan copias de las opciones al empezar y se vuelven a poner las que corresponden. Los grupos
   (`<optgroup>`) de la venta de mostrador se conservan.

   Si la opción que estaba elegida ya no está en la lista filtrada, el selector vuelve a su primera opción y se avisa con el evento `change`
   (Consumos recalcula el precio y el subtotal con él). No se escribe HTML: solo se mueven nodos que ya existían.

   ESTO ES AYUDA, NO CONTROL: el servidor vuelve a mirar el producto elegido (activo, su categoría y el horario del restaurante).
   ============================================================ */
(function () {
    'use strict';

    function iniciar(caja) {
        var select = document.getElementById(caja.getAttribute('data-filtro-para'));
        if (!select) return;

        // Copias de lo que había: las fijas (sin categoría) y cada producto con el nombre de su grupo, en el orden original.
        var fijas = [];
        var productos = [];
        [].forEach.call(select.children, function (hijo) {
            if (hijo.tagName === 'OPTGROUP') {
                [].forEach.call(hijo.children, function (op) { productos.push({ op: op.cloneNode(true), grupo: hijo.label }); });
            } else if (hijo.hasAttribute('data-categoria')) {
                productos.push({ op: hijo.cloneNode(true), grupo: null });
            } else {
                fijas.push(hijo.cloneNode(true));
            }
        });
        var botones = [].slice.call(caja.querySelectorAll('button[data-cat]'));

        function pintar(cat) {
            var antes = select.value;
            while (select.firstChild) select.removeChild(select.firstChild);
            fijas.forEach(function (op) { select.appendChild(op.cloneNode(true)); });
            var grupoActual = null, nodoGrupo = null;
            productos.forEach(function (p) {
                if (cat !== '' && p.op.getAttribute('data-categoria') !== cat) return;
                var op = p.op.cloneNode(true);
                if (p.grupo === null) { select.appendChild(op); return; }
                if (nodoGrupo === null || grupoActual !== p.grupo) {
                    nodoGrupo = document.createElement('optgroup');
                    nodoGrupo.label = p.grupo;
                    select.appendChild(nodoGrupo);
                    grupoActual = p.grupo;
                }
                nodoGrupo.appendChild(op);
            });
            botones.forEach(function (b) {
                var activo = b.getAttribute('data-cat') === cat;
                b.classList.toggle('on', activo);
                b.setAttribute('aria-pressed', activo ? 'true' : 'false');
            });
            // Lo que estaba elegido se conserva si sigue en la lista; si no, vuelve a la primera opción y se avisa.
            var sigue = [].some.call(select.options, function (o) { return o.value === antes; });
            select.value = sigue ? antes : (select.options.length ? select.options[0].value : '');
            if (!sigue && antes !== select.value) select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        botones.forEach(function (b) {
            b.addEventListener('click', function () { pintar(b.getAttribute('data-cat') || ''); });
        });
    }

    [].forEach.call(document.querySelectorAll('[data-filtro-para]'), iniciar);
}());
