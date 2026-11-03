/* ============================================================
   BOTÓN «PROCESANDO…» — Módulo 5 (Casa Hotel)
   Archivo: assets/js/toast-spinner.js
   ------------------------------------------------------------
   Al enviar un formulario el botón se bloquea y dice «Procesando…». Está conectado al envío REAL: se enciende con el
   evento `submit` y se apaga solo cuando llega la respuesta, porque la página se recarga entera.

   4.2.1 — lo que cambió:
   · Antes el botón volvía a su estado a los 8 s aunque el servidor siguiera trabajando: con una operación lenta (hosting lento,
     un check-out pesado) quedaba activo con la petición en vuelo y se podía enviar dos veces. Ahora se queda bloqueado hasta que
     la página cambie. Solo si pasan 45 s se libera, y lo dice: «Está tardando más de lo normal».
   · Si el navegador restaura la página desde su memoria al volver con «Atrás» (evento `pageshow` con `persisted`), el botón se libera.
   · Un envío que OTRO código detenga DESPUÉS de este oyente ya no deja el botón bloqueado: se mira `defaultPrevented` cuando todos los
     oyentes ya corrieron. Es una defensa: hoy ninguna pantalla lo necesita (la tarjeta «El monto supera el saldo» de la ficha y la de
     desactivar el 2FA registran su oyente ANTES, porque su script va antes del pie de página, y ya se veían), pero un oyente nuevo
     registrado más tarde habría dejado el botón en «Procesando…» sin enviar nada.
   · Un formulario cuya respuesta NO recarga la página (devuelve un archivo, o abre otra pestaña) se marca con `data-descarga`
     (o lleva `target`): el botón se libera a los 4 s. Hoy ninguno lo necesita —las descargas son enlaces—, y una prueba
     (probar_espera_421.php) avisa si alguien agrega un formulario así sin la marca.

   El toast que vivía aquí pasó a assets/js/avisos-ui.js en 4.2.0.
   ============================================================ */

(function () {
    'use strict';

    var MAX_ESPERA_MS = 45000;    // pasado esto se libera el botón y se avisa
    var DESCARGA_MS   = 4000;     // un formulario que no recarga la página: lo que se espera antes de liberarlo
    var bloqueados = [];          // {btn, original, t}: lo que este archivo bloqueó y aún no liberó

    function liberar(b) {
        clearTimeout(b.t);
        b.btn.disabled = false;
        b.btn.removeAttribute('aria-busy');
        b.btn.innerHTML = b.original;   // el HTML que tenía el botón cuando se bloqueó: lo puso el servidor, no algo tecleado
        var i = bloqueados.indexOf(b);
        if (i >= 0) bloqueados.splice(i, 1);
    }

    function bloquear(btn, ms, avisar) {
        var b = { btn: btn, original: btn.innerHTML, t: null };
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        btn.innerHTML = `<span style="display:inline-flex;align-items:center;gap:8px;">
            <span style="width:16px;height:16px;border:2px solid rgba(255,255,255,0.4);border-top-color:white;border-radius:50%;animation:bs-spin 0.7s linear infinite;display:inline-block;"></span>
            Procesando...
        </span>`;
        b.t = setTimeout(function () {
            liberar(b);
            if (avisar && window.cbAviso) {
                window.cbAviso({ tipo: 'warning', titulo: 'Está tardando más de lo normal',
                                 texto: 'Revisa si la acción ya se hizo antes de volver a intentarla.' });
            }
        }, ms);
        bloqueados.push(b);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function (ev) {
                // Otro oyente registrado DESPUÉS de este puede detener el envío (una tarjeta de confirmación nueva). Se mira cuando ya
                // corrieron todos: si alguien lo detuvo, el botón no se toca.
                setTimeout(function () {
                    if (ev.defaultPrevented) return;
                    const btn = form.querySelector('[type="submit"]');
                    if (!btn || btn.disabled) return;
                    const sinRecarga = form.hasAttribute('data-descarga') || (form.target && form.target !== '_self');
                    bloquear(btn, sinRecarga ? DESCARGA_MS : MAX_ESPERA_MS, !sinRecarga);
                }, 0);
            });
        });

        // Inyectar keyframe de spin si no existe
        if (!document.getElementById('bs-spin-style')) {
            const style = document.createElement('style');
            style.id = 'bs-spin-style';
            style.textContent = '@keyframes bs-spin { to { transform: rotate(360deg); } }';
            document.head.appendChild(style);
        }
    });

    // Volver con «Atrás»: el navegador puede devolver la página tal como quedó, con el botón en «Procesando…».
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) bloqueados.slice().forEach(liberar);
    });

    // Compatibilidad: el toast ahora es la notificación de avisos-ui.js (si ese archivo no cargó, no hace nada).
    if (!window.showToast) {
        window.showToast = function (texto, tipo) { return window.cbAviso ? window.cbAviso({ tipo: tipo, texto: texto }) : undefined; };
    }
})();
