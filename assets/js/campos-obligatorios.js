/* ============================================================
   CAMPOS OBLIGATORIOS — 4.2.0
   Archivo: assets/js/campos-obligatorios.js
   ------------------------------------------------------------
   Una sola pieza para TODOS los formularios, sin tocar cada pantalla:
     1. MARCA los obligatorios con un asterisco rojo en su etiqueta (si la etiqueta ya trae «*», no lo repite).
     2. Cuando falta algo, ya no sale la burbuja del navegador: el campo se pinta de rojo con su mensaje debajo, el primero
        recibe el foco y una notificación dice CUÁLES faltan («Faltan 3 campos obligatorios: Huésped, Habitación y Salida»).
     3. El rojo se quita en cuanto el campo queda bien.

   Cómo: escucha el evento `invalid` en captura (el navegador lo dispara en cada campo que no pasa su validación) y cancela
   la burbuja. No usa `novalidate`: si este archivo no carga, el navegador sigue validando como siempre.
   Lee lo que ya está en el HTML (`required`, `pattern`, `min`…), así que una pantalla nueva queda cubierta sola.

   Opt-out: `data-sin-validar` en el <form>. Las reglas que solo conoce el servidor (fechas, capacidad, solapamientos)
   siguen respondiendo desde el servidor, y ahora salen también como notificación.
   ============================================================ */
(function () {
    'use strict';

    var CONTROLES = 'input, select, textarea';
    var cola = [];
    var temporizador = null;

    function esCampo(el) { return !!(el && el.matches && el.matches(CONTROLES)); }
    function exento(form) { return !form || form.hasAttribute('data-sin-validar'); }
    function aplica(el) { return el.required && !el.disabled && el.type !== 'hidden'; }

    /* El texto de una etiqueta, sin el campo que lleva dentro, sin íconos ni notas pequeñas. */
    function textoDe(etiqueta) {
        var c = etiqueta.cloneNode(true);
        c.querySelectorAll('input, select, textarea, i, svg, small, .cb-req, .cb-falta-msg').forEach(function (n) { n.remove(); });
        return c.textContent.replace(/\s+/g, ' ').trim();
    }
    function conTexto(etiqueta) { return (etiqueta.dataset.label || '').trim() !== '' || textoDe(etiqueta) !== ''; }

    /* La etiqueta de un campo: la asociada (for= o envolvente); si no tiene, la del mismo bloque. */
    function etiqueta(el) {
        var ls = el.labels ? Array.prototype.slice.call(el.labels) : [];
        for (var i = 0; i < ls.length; i++) { if (conTexto(ls[i])) return ls[i]; }
        if (!ls.length) {
            var bloque = el.closest('div');
            var l = bloque && bloque.querySelector(':scope > label');
            if (l && !l.querySelector(CONTROLES) && conTexto(l)) return l;
        }
        return null;
    }

    /* Cómo se llama el campo en el aviso: su etiqueta; si no, su aria-label, su placeholder o su nombre. */
    function nombre(el) {
        var l = etiqueta(el), t = '';
        if (l) t = (l.dataset.label || '') || textoDe(l);
        if (!t) t = el.getAttribute('aria-label') || el.getAttribute('placeholder') || el.name || '';
        t = t.replace(/\*/g, '').replace(/\s*\([^)]*\)\s*/g, ' ').replace(/\s+/g, ' ').trim();
        return t || 'Campo';
    }

    /* ── El asterisco ─────────────────────────────────────── */
    function marcar(el) {
        if (!aplica(el)) { desmarcar(el); return; }
        var l = etiqueta(el);
        if (!l) {   // sin etiqueta (solo placeholder): el asterisco va en el placeholder
            var p = el.getAttribute('placeholder');
            if (p && p.indexOf('*') < 0 && el.dataset.cbPh === undefined) { el.dataset.cbPh = p; el.setAttribute('placeholder', p + ' *'); }
            return;
        }
        if (l.dataset.cbReq === '1' || l.querySelector(':scope > .cb-req')) return;
        if ((l.dataset.label || '').indexOf('*') >= 0 || l.textContent.indexOf('*') >= 0) { l.dataset.cbReq = '1'; return; }   // ya lo trae escrito
        var s = document.createElement('span');
        s.className = 'cb-req';
        s.setAttribute('aria-hidden', 'true');
        s.textContent = ' *';
        l.appendChild(s);
        l.dataset.cbReq = '1';
    }
    /* Un campo que dejó de ser obligatorio (hay formularios que lo cambian según lo que se elija) pierde el asterisco que puso este archivo. */
    function desmarcar(el) {
        var l = etiqueta(el);
        if (l && l.dataset.cbReq === '1') {
            var s = l.querySelector(':scope > .cb-req');
            if (s) { s.remove(); delete l.dataset.cbReq; }
        }
        if (el.dataset.cbPh !== undefined) { el.setAttribute('placeholder', el.dataset.cbPh); delete el.dataset.cbPh; }
    }
    function refrescar() {
        document.querySelectorAll(CONTROLES).forEach(function (el) { if (!exento(el.form)) marcar(el); });
    }

    /* ── Lo que falta ─────────────────────────────────────── */
    function mensajeDe(el) {
        var v = el.validity;
        if (v.valueMissing) {
            if (el.tagName === 'SELECT') return 'Selecciona una opción';
            if (el.type === 'checkbox' || el.type === 'radio') return 'Marca esta casilla para continuar';
            if (el.type === 'date') return 'Indica la fecha';
            return 'Completa este campo';
        }
        if (v.typeMismatch) return el.type === 'email' ? 'Escribe un correo válido' : 'Revisa el valor';
        if (v.tooShort) return 'Escribe al menos ' + el.minLength + ' caracteres';
        if (v.patternMismatch) return 'Revisa el formato';
        if (v.rangeUnderflow) return 'El valor mínimo es ' + el.min;
        if (v.rangeOverflow) return 'El valor máximo es ' + el.max;
        if (v.stepMismatch) return 'Escribe un número entero';
        return el.validationMessage || 'Revisa este campo';
    }
    function anfitrion(el) {
        var l = (el.type === 'checkbox' || el.type === 'radio') ? el.closest('label') : null;
        return l || el;
    }
    function marcarFalta(el) {
        el.classList.add('cb-falta');
        el.setAttribute('aria-invalid', 'true');
        var a = anfitrion(el), m = a.nextElementSibling;
        if (!(m && m.classList.contains('cb-falta-msg'))) {
            m = document.createElement('div');
            m.className = 'cb-falta-msg';
            m.setAttribute('role', 'alert');
            a.parentNode.insertBefore(m, a.nextSibling);
        }
        m.textContent = mensajeDe(el);
    }
    function limpiarFalta(el) {
        el.classList.remove('cb-falta');
        el.removeAttribute('aria-invalid');
        var m = anfitrion(el).nextElementSibling;
        if (m && m.classList.contains('cb-falta-msg')) m.remove();
    }
    function unir(nombres) {
        var n = nombres.slice(0, 5), mas = nombres.length - n.length;
        if (mas > 0) return n.join(', ') + ' y ' + mas + ' más';
        return n.length > 1 ? n.slice(0, -1).join(', ') + ' y ' + n[n.length - 1] : n[0];
    }

    function procesar() {
        var malos = cola.filter(function (el) { return document.contains(el); });
        cola = [];
        if (!malos.length) return;
        malos.forEach(marcarFalta);

        var nombres = [];
        malos.forEach(function (el) { var n = nombre(el); if (nombres.indexOf(n) < 0) nombres.push(n); });
        var vacios = malos.every(function (el) { return el.validity.valueMissing; });
        var n = nombres.length;
        var titulo = vacios ? (n === 1 ? 'Falta 1 campo obligatorio' : 'Faltan ' + n + ' campos obligatorios')
                            : (n === 1 ? 'Revisa 1 campo' : 'Revisa ' + n + ' campos');
        if (window.cbAviso) window.cbAviso({ tipo: vacios ? 'danger' : 'warning', titulo: titulo, texto: unir(nombres) + '.' });

        try { malos[0].focus({ preventScroll: true }); malos[0].scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) { /* un campo oculto no se puede enfocar */ }
    }

    /* El navegador dispara `invalid` en cada campo que no pasa al intentar enviar. Se cancela su burbuja y se junta todo. */
    document.addEventListener('invalid', function (ev) {
        var el = ev.target;
        if (!esCampo(el) || exento(el.form)) return;
        ev.preventDefault();
        if (cola.indexOf(el) < 0) cola.push(el);
        clearTimeout(temporizador);
        temporizador = setTimeout(procesar, 0);
    }, true);

    /* El rojo se quita cuando el campo queda bien (lo escribió o lo eligió). */
    function alCambiar(ev) {
        var el = ev.target;
        if (esCampo(el) && el.classList.contains('cb-falta') && el.validity.valid) limpiarFalta(el);
    }
    document.addEventListener('input', alCambiar, true);
    document.addEventListener('change', alCambiar, true);

    /* Los formularios que cambian qué es obligatorio (check-in, usuarios, facturación) o abren un modal: se vuelve a marcar. */
    function iniciar() {
        refrescar();
        var pendiente = false;
        new MutationObserver(function () {
            if (pendiente) return;
            pendiente = true;
            requestAnimationFrame(function () { pendiente = false; refrescar(); });
        }).observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['required', 'disabled'] });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})();
