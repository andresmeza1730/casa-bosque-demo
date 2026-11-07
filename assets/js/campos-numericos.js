/* ============================================================
   CAMPOS NUMÉRICOS — Casa Bosque
   Archivo: assets/js/campos-numericos.js
   ------------------------------------------------------------
   Un campo donde se escribe un número (un documento, un teléfono, un monto, una tarifa) NO debe dejar escribir letras. Antes cada
   pantalla dependía de lo que el navegador hiciera con su `type`: `type="number"` deja pasar «e», «+», «-» y «.», y en Firefox hasta
   las letras; `type="text"` con `inputmode="numeric"` solo cambia el teclado del celular, no filtra nada.

   Esta pieza filtra SOLO los campos que se marcan con `data-solo` (no toca ningún otro campo del sistema):
     · data-solo="digitos"     solo dígitos (una capacidad, un NIT).
     · data-solo="moneda"      un monto en pesos (4.5.0): solo dígitos y, mientras se escribe, **los puntos de miles** (50000 se ve 50.000).
                               El campo es `type="text"` (un `type="number"` no puede mostrar puntos). Al ENVIAR se mandan los dígitos
                               sin puntos, y el servidor (validarMonto) acepta de todos modos «50.000» como cincuenta mil.
     · data-solo="telefono"    dígitos, «+», «-», paréntesis y espacio.
     · data-solo="alfanum"     letras, dígitos y «-» (un pasaporte).
     · data-solo="documento" + data-tipo-en="documento_tipo"   según el tipo de documento del MISMO formulario: CC, CE, TI, RC y
                               NIT, solo dígitos; PAS, letras y dígitos. Al cambiar el tipo, el número escrito se ajusta solo.
   Filtra al teclear, al pegar («40.000» pegado queda 40000) y con los teclados del celular.

   ESTO ES AYUDA, NO CONTROL. Quien manda es el servidor (includes/validacion.php: validarMonto, validarEntero, validarDocumentoTipo y
   validarTelefono), que rechaza con un mensaje claro cualquier cosa que no sea un número.
   ============================================================ */
(function () {
    'use strict';

    var PERMITIDO = {
        digitos:  /^[0-9]$/,
        moneda:   /^[0-9]$/,
        telefono: /^[0-9+\-() ]$/,
        alfanum:  /^[0-9A-Za-z\-]$/
    };
    var DOC_NUMERICO = { CC: 1, CE: 1, TI: 1, RC: 1, NIT: 1 };

    /** Qué modo le toca a este campo, o null si no lleva data-solo (entonces no se toca). */
    function modo(el) {
        if (!(el instanceof HTMLInputElement)) return null;
        var m = el.getAttribute('data-solo');
        if (m === 'documento') {
            var sel = el.form && el.form.elements[el.getAttribute('data-tipo-en')];
            return (sel && DOC_NUMERICO[String(sel.value).toUpperCase()]) ? 'digitos' : 'alfanum';
        }
        return (m && PERMITIDO[m]) ? m : null;
    }

    function permitido(m, texto) {
        for (var i = 0; i < texto.length; i++) { if (!PERMITIDO[m].test(texto.charAt(i))) return false; }
        return true;
    }

    /** Lo que queda de un texto pegado: en un número, «1.500» es mil quinientos y el resto de lo que no sea dígito se quita. */
    function limpiar(m, texto) {
        if (m === 'digitos' || m === 'moneda') {
            var t = texto.trim();
            if (/^\d{1,3}(?:[.,  ]\d{3})+$/.test(t)) t = t.replace(/[.,  ]/g, '');
            return t.replace(/\D/g, '');
        }
        return texto.split('').filter(function (c) { return PERMITIDO[m].test(c); }).join('');
    }

    function avisarCambio(el) { el.dispatchEvent(new Event('input', { bubbles: true })); }

    // Al teclear: una tecla que no es de este campo no entra.
    document.addEventListener('keydown', function (ev) {
        var m = modo(ev.target);
        if (!m || ev.defaultPrevented) return;
        if (ev.key && ev.key.length === 1 && !ev.ctrlKey && !ev.metaKey && !ev.altKey && !PERMITIDO[m].test(ev.key)) ev.preventDefault();
    }, true);

    // Teclados del celular y de composición, que no mandan una tecla por carácter.
    document.addEventListener('beforeinput', function (ev) {
        var m = modo(ev.target);
        if (!m) return;
        if ((ev.inputType === 'insertText' || ev.inputType === 'insertCompositionText') && ev.data && !permitido(m, ev.data)) ev.preventDefault();
    }, true);

    // Al pegar: se pega lo que sirve.
    document.addEventListener('paste', function (ev) {
        var el = ev.target, m = modo(el);
        if (!m) return;
        var texto = (ev.clipboardData || window.clipboardData).getData('text');
        var limpio = limpiar(m, texto);
        if (limpio === texto) return;
        ev.preventDefault();
        if (!limpio) return;
        if (el.type === 'number') {
            el.value = limpio;
        } else {
            el.setRangeText(limpio, el.selectionStart, el.selectionEnd, 'end');
            if (el.maxLength > 0 && el.value.length > el.maxLength) el.value = el.value.slice(0, el.maxLength);
        }
        avisarCambio(el);
    }, true);

    // Último recurso (arrastrar texto, autocompletar): si quedó algo que no es de este campo, se quita.
    document.addEventListener('input', function (ev) {
        var el = ev.target, m = modo(el);
        if (m === 'moneda') { formatear(el); limites(el); return; }   // el monto se reescribe con sus puntos de miles
        if (!m || el.type === 'number' || permitido(m, el.value)) return;
        el.value = limpiar(m, el.value);
    }, true);

    // «Suprimir» justo ANTES de un punto de miles: borrar el punto no sirve (el campo lo vuelve a poner) y el cursor se quedaba pegado sin dejar borrar el
    // dígito siguiente. Se salta el punto; la siguiente pulsación ya borra el dígito. «Borrar» (Backspace) no lo necesita: el cursor ya pasa por encima.
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Delete' || ev.defaultPrevented) return;
        var el = ev.target;
        if (modo(el) !== 'moneda' || el.selectionStart !== el.selectionEnd) return;
        if (el.value.charAt(el.selectionStart) === '.') {
            ev.preventDefault();
            el.setSelectionRange(el.selectionStart + 1, el.selectionStart + 1);
        }
    }, true);

    // Si cambia el tipo de documento, el número que ya estaba escrito se ajusta al nuevo tipo.
    document.addEventListener('change', function (ev) {
        var sel = ev.target;
        if (!(sel instanceof HTMLSelectElement) || !sel.form || !sel.name) return;
        var campos = sel.form.querySelectorAll('input[data-solo="documento"]');
        for (var i = 0; i < campos.length; i++) {
            if (campos[i].getAttribute('data-tipo-en') !== sel.name) continue;
            var m = modo(campos[i]);
            if (m && !permitido(m, campos[i].value)) { campos[i].value = limpiar(m, campos[i].value); avisarCambio(campos[i]); }
        }
    });

    // ───────────────────────── Dinero: los puntos de miles (4.5.0) ─────────────────────────
    function soloDigitos(t) { return String(t).replace(/\D/g, ''); }

    /** '1234567' → '1.234.567' (el punto es el separador de miles de Colombia). */
    function miles(digitos) { return digitos.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }

    /** El monto de un campo de dinero como entero (0 si está vacío). Es lo que tiene que leer cualquier cálculo de pantalla: el valor del campo lleva puntos. */
    function leerMonto(el) { return parseInt(soloDigitos(el.value), 10) || 0; }

    /** Pone un número en un campo de dinero ya con sus puntos ('' lo vacía) y avisa a quien escuche `input`. */
    function ponerMonto(el, n) {
        el.value = (n === '' || n === null || n === undefined) ? '' : miles(soloDigitos(Math.round(Number(n))));
        limites(el);
    }

    /** Reescribe el campo con sus puntos de miles sin mover el cursor: se cuentan los dígitos que había antes de él y se vuelve a ese mismo dígito. */
    function formatear(el) {
        var antes = el.value;
        var pos = el.selectionStart;
        var conCursor = (pos !== null && pos !== undefined && document.activeElement === el);
        var dig = soloDigitos(conCursor ? antes.slice(0, pos) : '').length;
        var d = soloDigitos(antes).replace(/^0+(?=\d)/, '');   // «007» → «7» (un solo cero sí se queda: el abono puede ser 0)
        var nuevo = miles(d);
        if (nuevo === antes) return;
        el.value = nuevo;
        if (!conCursor) return;
        var n = 0, i = 0;
        while (i < nuevo.length && n < dig) { if (nuevo.charAt(i) >= '0' && nuevo.charAt(i) <= '9') n++; i++; }
        try { el.setSelectionRange(i, i); } catch (e) { /* un campo sin selección: no importa */ }
    }

    /**
     * El mínimo y el máximo que antes daba `type="number"` (un campo de texto no los exige). Se lee de `min` y `max` del propio campo, que
     * algunas pantallas cambian al vuelo (el abono mínimo de un huésped nuevo, el saldo de una deuda): quien los cambia llama a revalidar().
     */
    function limites(el) {
        var msg = '';
        if (el.value !== '') {
            var n = leerMonto(el), mn = el.getAttribute('min'), mx = el.getAttribute('max');
            if (mn !== null && mn !== '' && n < Number(mn)) msg = 'El valor mínimo es $' + miles(String(Math.round(Number(mn))));
            else if (mx !== null && mx !== '' && n > Number(mx)) msg = 'El valor máximo es $' + miles(String(Math.round(Number(mx))));
        }
        el.setCustomValidity(msg);
    }

    window.CBDinero = { leer: leerMonto, poner: ponerMonto, revalidar: limites };

    function camposMoneda(raiz) { return (raiz || document).querySelectorAll('input[data-solo="moneda"]'); }

    // Lo que ya venía escrito (una tarifa guardada, un abono en 0) se pinta con sus puntos de una vez.
    function iniciar() {
        var cs = camposMoneda();
        for (var i = 0; i < cs.length; i++) {
            // Un valor que viene del servidor como decimal («12000.00», una columna decimal(12,2)) NO son 1.200.000: se redondea antes de dar formato.
            // Se distingue de un «1.500» de miles porque su punto va seguido de uno o dos dígitos, no de tres.
            if (/^\d+\.\d{1,2}$/.test(cs[i].value)) cs[i].value = String(Math.round(Number(cs[i].value)));
            formatear(cs[i]); limites(cs[i]);
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();

    // Al ENVIAR se mandan los dígitos limpios: el servidor recibe «50000» y no depende de que «50.000» se lea bien. Si otro código detiene el envío
    // (una tarjeta de confirmación, un campo que falta), los puntos vuelven en cuanto pasa el evento.
    document.addEventListener('submit', function (ev) {
        var cs = camposMoneda(ev.target);
        if (!cs.length) return;
        for (var i = 0; i < cs.length; i++) cs[i].value = soloDigitos(cs[i].value);
        setTimeout(function () { for (var j = 0; j < cs.length; j++) formatear(cs[j]); }, 0);
    }, true);
}());
