/* ============================================================
   AVISOS — 4.2.0
   Archivo: assets/js/avisos-ui.js
   ------------------------------------------------------------
   La notificación del sistema: baja desde la barra del módulo con el estilo de la barra (ver assets/css/avisos-ui.css).
   Reemplaza al toast de toast-spinner.js, que ahora solo se ocupa del botón «Procesando…».

   Quién la llama:
     - el pie de página (includes/footer.php → includes/mensajes.php) con lo que la pantalla puso en `$msg`, `$mensaje` o `$flash`;
     - campos-obligatorios.js, cuando falta un campo;
     - cualquier código viejo que llame `showToast(texto, tipo)`, que sigue funcionando.

   Todo se escribe como TEXTO (textContent), nunca como HTML: lo que llega aquí puede venir de lo que alguien tecleó.
   Los íconos son SVG fijos de este archivo, así que tampoco hace falta Font Awesome (acceso.php no lo carga).
   ============================================================ */
(function () {
    'use strict';

    var ICONOS = {
        success: '<path d="M20 6 9 17l-5-5"/>',
        danger:  '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.5"/>',
        warning: '<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17v.5"/>',
        info:    '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.5"/>',
        candado: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>'
    };
    var TITULOS = { success: 'Listo', danger: 'No se pudo completar', warning: 'Revisa esto', info: 'Aviso' };
    var MAX_VISIBLES = 3;
    var ultimo = {};   // texto → momento: el mismo aviso dos veces seguidas se muestra una sola

    function contenedor() {
        var c = document.getElementById('cb-avisos');
        if (!c) {
            c = document.createElement('div');
            c.id = 'cb-avisos';
            c.setAttribute('aria-live', 'polite');
            document.body.appendChild(c);
        }
        return c;
    }

    /* Un enlace solo puede ser un camino del propio sistema: «/algo», nunca «//otro-sitio» ni un esquema. */
    function hrefSeguro(href) {
        return typeof href === 'string' && href.charAt(0) === '/' && href.indexOf('//') !== 0 && !/[\\\u0000-\u001F]/.test(href);
    }

    function cerrar(el) {
        if (!el || el.classList.contains('cb-off')) return;
        el.classList.remove('cb-on');
        el.classList.add('cb-off');
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 300);
    }

    /**
     * Muestra un aviso.
     * @param {{tipo?:string, titulo?:string, texto?:string, icono?:string, accion?:{texto:string, href:string}}} o
     */
    function cbAviso(o) {
        if (!o || typeof o !== 'object') return;
        var tipo = TITULOS[o.tipo] ? o.tipo : 'info';
        var texto = String(o.texto == null ? '' : o.texto).trim();
        if (!texto && !o.titulo) return;
        var titulo = String(o.titulo || TITULOS[tipo]);

        var clave = tipo + '|' + titulo + '|' + texto, ahora = Date.now();
        if (ultimo[clave] && ahora - ultimo[clave] < 1500) return;
        ultimo[clave] = ahora;

        document.body.classList.add('cb-avisos');   // desde ahora el recuadro de la pantalla sobra (avisos-ui.css)

        var c = contenedor();
        while (c.children.length >= MAX_VISIBLES) c.removeChild(c.children[0]);   // el más viejo se va para dejar lugar

        var el = document.createElement('div');
        el.className = 'cb-aviso cb-aviso--' + tipo;
        el.setAttribute('role', tipo === 'danger' ? 'alert' : 'status');

        var ic = document.createElement('span');
        ic.className = 'cb-aviso__ic';
        ic.setAttribute('aria-hidden', 'true');
        // Solo estas cadenas fijas del archivo se escriben como HTML: nada de lo que viene en `o`.
        ic.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
                     + (ICONOS[o.icono] || ICONOS[tipo]) + '</svg>';
        el.appendChild(ic);

        var tx = document.createElement('div');
        tx.className = 'cb-aviso__tx';
        var tt = document.createElement('div');
        tt.className = 'cb-aviso__tt';
        tt.textContent = titulo;
        tx.appendChild(tt);
        if (texto) {
            var bd = document.createElement('div');
            bd.className = 'cb-aviso__bd';
            bd.textContent = texto;
            tx.appendChild(bd);
        }
        var conEnlace = o.accion && o.accion.texto && hrefSeguro(o.accion.href);
        if (conEnlace) {
            var ac = document.createElement('a');
            ac.className = 'cb-aviso__ac';
            ac.href = o.accion.href;
            ac.textContent = String(o.accion.texto);
            ac.addEventListener('click', function (ev) { ev.stopPropagation(); });
            tx.appendChild(ac);
        }
        el.appendChild(tx);

        var ms = conEnlace ? 8000 : (tipo === 'success' ? 4500 : 6500);
        var pg = document.createElement('span');
        pg.className = 'cb-aviso__pg';
        var barra = document.createElement('i');
        barra.style.animationDuration = ms + 'ms';
        pg.appendChild(barra);
        el.appendChild(pg);

        c.appendChild(el);
        void el.offsetWidth;                         // que la entrada se anime desde su posición de arriba
        el.classList.add('cb-on');

        var t = setTimeout(function () { cerrar(el); }, ms);
        var cierra = function () { clearTimeout(t); cerrar(el); };
        el.addEventListener('click', cierra);
        var y0 = null;                               // deslizar hacia arriba también lo cierra
        el.addEventListener('touchstart', function (e) { y0 = e.touches[0].clientY; }, { passive: true });
        el.addEventListener('touchmove', function (e) { if (y0 !== null && y0 - e.touches[0].clientY > 24) { y0 = null; cierra(); } }, { passive: true });
        return el;
    }

    /* Lo que antes mostraba el toast: showToast(texto, 'success' | 'danger' | 'warning'). */
    function showToast(texto, tipo) {
        return cbAviso({ tipo: tipo, texto: texto });
    }

    /* Los `.alert` que algún servidor pintó (hoy solo reiniciar.php) pasan a ser avisos. */
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.alert').forEach(function (alerta) {
            var tipo = alerta.classList.contains('alert-success') ? 'success' : alerta.classList.contains('alert-danger') ? 'danger' : 'warning';
            var texto = alerta.textContent.trim();
            if (texto) { cbAviso({ tipo: tipo, texto: texto }); alerta.style.display = 'none'; }
        });
    });

    window.cbAviso = cbAviso;
    window.showToast = showToast;
})();
