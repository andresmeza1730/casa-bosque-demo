/* ============================================================
   CAMPOS NUMÉRICOS — Casa Bosque
   Archivo: assets/js/campos-numericos.js
   ------------------------------------------------------------
   Un campo donde se escribe un número (un documento, un teléfono, un monto, una tarifa) NO debe dejar escribir letras. Antes cada
   pantalla dependía de lo que el navegador hiciera con su `type`: `type="number"` deja pasar «e», «+», «-» y «.», y en Firefox hasta
   las letras; `type="text"` con `inputmode="numeric"` solo cambia el teclado del celular, no filtra nada.

   Esta pieza filtra SOLO los campos que se marcan con `data-solo` (no toca ningún otro campo del sistema):
     · data-solo="digitos"     solo dígitos (un monto en pesos, una tarifa, una capacidad, un NIT).
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
        if (m === 'digitos') {
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
        if (!m || el.type === 'number' || permitido(m, el.value)) return;
        el.value = limpiar(m, el.value);
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
}());
