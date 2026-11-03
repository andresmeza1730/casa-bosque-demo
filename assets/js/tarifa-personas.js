/* ============================================================
   assets/js/tarifa-personas.js
   El precio según el tipo de habitación y cuántas personas duerman
   ============================================================

   ESTE ARCHIVO ES EL ESPEJO DE includes/tarifas.php.

   La misma fórmula tiene que existir en los dos sitios: en PHP porque es
   quien cobra —lo que diga el servidor es lo que se congela en
   `estancias.tarifa_noche`— y aquí porque el recepcionista tiene que ver el
   precio en el momento de elegir la habitación, sin recargar la página.

   **Si cambias la fórmula, cambia los DOS archivos.** Es el mismo riesgo que
   ya tienen las dos copias de la regla de las noches (`includes/noches.php` y
   el SQL de `modules/reportes/ingresos.php`), y por eso se avisa aquí arriba.

   Lo que se ve en pantalla NO es lo que se cobra: es un anticipo para que
   nadie registre una entrada a ciegas. El número de verdad lo vuelve a
   calcular el servidor al guardar.

   La tabla del hotel:

     Cama doble     1 persona $40.000   2 personas $50.000
     Cama sencilla  1 persona $37.000   (no admite dos)
     Dos camas      2 personas $70.000  + $10.000 por persona adicional
                    (una persona sola paga igual $70.000)

   Lo usan tres pantallas: el check-in, las reservas y la página pública.
*/

window.CBTarifas = (function () {
    'use strict';

    /** En `capacidad_max`, este valor significa «sin tope». */
    var SIN_TOPE = 0;

    /** Hasta cuántas personas se listan en el menú de un tipo sin tope. */
    var MAX_LISTADO = 8;

    function num(v) {
        if (v === null || v === undefined || v === '') return null;
        var n = Number(v);
        return isNaN(n) ? null : Math.round(n);
    }

    /**
     * El precio por noche. Devuelve null si ese tipo no admite esa ocupación.
     * Mismo orden de decisiones que tarifaPorPersonas() en PHP.
     */
    function porPersonas(tipo, personas) {
        personas = parseInt(personas, 10);
        if (!personas || personas < 1) return null;

        var una = num(tipo.tarifa1);
        var dos = num(tipo.tarifa2);

        // Respaldo para un tipo al que nadie le haya puesto las tarifas nuevas.
        if (una === null && dos === null) {
            una = dos = num(tipo.tarifaBase);
        }

        // Una sola persona paga su precio si el tipo lo tiene; si no, el de dos
        // (es «Dos camas»: se paga la habitación, no la cama).
        if (personas === 1) return una !== null ? una : dos;

        if (dos === null) return null;
        if (personas === 2) return dos;

        var extra = num(tipo.tarifaExtra) || 0;
        if (extra < 1) return null;   // sin recargo definido no se puede vender así

        return dos + (personas - 2) * extra;
    }

    /** ¿El tipo admite esa ocupación? Devuelve el motivo, o null si sí. */
    function motivoInvalido(tipo, personas) {
        personas = parseInt(personas, 10);
        if (!personas || personas < 1) return 'Indica cuántas personas se van a hospedar.';

        var tope = num(tipo.capacidad);
        if (tope !== null && tope !== SIN_TOPE && personas > tope) {
            return (tipo.nombre || 'Esa habitación') + ' admite ' + tope +
                   ' persona(s) como máximo.';
        }
        if (porPersonas(tipo, personas) === null) {
            return 'No hay tarifa definida para ' + personas + ' persona(s) en ' +
                   (tipo.nombre || 'ese tipo') + '.';
        }
        return null;
    }

    /** $40.000 */
    function pesos(n) {
        return '$' + Number(n || 0).toLocaleString('es-CO');
    }

    /**
     * Cómo se compone el precio, cuando hay recargo. Null cuando no hay nada
     * que explicar: si el precio es el de tarifa, la cuenta no necesita cuento.
     */
    function desglose(tipo, personas) {
        personas = parseInt(personas, 10);
        if (!personas || personas <= 2) return null;

        var dos = num(tipo.tarifa2);
        var extra = num(tipo.tarifaExtra) || 0;
        if (dos === null || extra < 1) return null;

        var adicionales = personas - 2;
        return pesos(dos) + ' + ' + adicionales + ' × ' + pesos(extra);
    }

    /**
     * Lee los `data-*` de un <option> (o de cualquier elemento) y arma el tipo.
     *
     * Los nombres de los atributos son los mismos en las tres pantallas:
     * data-tarifa1, data-tarifa2, data-extra, data-cap, data-tipo.
     */
    function desdeElemento(el) {
        if (!el || !el.dataset) return null;
        return {
            nombre:      el.dataset.tipo || '',
            tarifa1:     el.dataset.tarifa1,
            tarifa2:     el.dataset.tarifa2,
            tarifaExtra: el.dataset.extra,
            tarifaBase:  el.dataset.tarifaBase,
            capacidad:   el.dataset.cap
        };
    }

    /** El tipo de la opción elegida de un <select>, o null si no hay ninguna. */
    function desdeSelect(select) {
        if (!select || select.selectedIndex < 0) return null;
        var op = select.options[select.selectedIndex];
        if (!op || !op.value) return null;
        return desdeElemento(op);
    }

    /**
     * Rellena el menú de personas con las ocupaciones que ese tipo admite.
     *
     * Una cama sencilla queda con una sola opción; una doble con dos; una de
     * dos camas con ocho y la posibilidad de escribir más, porque no tiene
     * tope. Se conserva el valor que hubiera si sigue siendo válido.
     *
     * @return {number} la ocupación que quedó seleccionada (0 si ninguna)
     */
    function poblarPersonas(select, tipo, deseado) {
        if (!select) return 0;

        var previo = parseInt(deseado || select.value, 10) || 1;
        var tope   = num(tipo && tipo.capacidad);

        // «Sin tope» puede llegar de dos maneras y hay que reconocer las dos:
        // como el 0 que se guarda en la base, o como el 9999 en el que lo
        // convierte SQL_CAPACIDAD al leerlo. Y un tope más alto de lo que se
        // va a listar se trata igual: se listan los primeros y se deja escribir
        // el resto. Sin esta tercera condición, un tipo sin tope generaba
        // NUEVE MIL opciones en el menú y nunca aparecía la de «Más de 8…».
        var sinTope = (tope === null || tope === SIN_TOPE || tope > MAX_LISTADO);
        var hasta   = sinTope ? MAX_LISTADO : tope;

        select.innerHTML = '';

        for (var n = 1; n <= hasta; n++) {
            // No se ofrece una ocupación que el tipo no sepa cobrar.
            if (porPersonas(tipo, n) === null) continue;
            var op = document.createElement('option');
            op.value = String(n);
            op.textContent = n + (n === 1 ? ' persona' : ' personas');
            select.appendChild(op);
        }

        if (sinTope) {
            var mas = document.createElement('option');
            mas.value = 'mas';
            mas.textContent = 'Más de ' + MAX_LISTADO + '…';
            select.appendChild(mas);
        }

        // Se respeta lo que ya estaba elegido si sigue cabiendo.
        var hay = Array.prototype.some.call(select.options, function (o) {
            return o.value === String(previo);
        });
        select.value = hay ? String(previo) : (select.options.length ? select.options[0].value : '');

        return parseInt(select.value, 10) || 0;
    }

    return {
        SIN_TOPE: SIN_TOPE,
        MAX_LISTADO: MAX_LISTADO,
        porPersonas: porPersonas,
        motivoInvalido: motivoInvalido,
        pesos: pesos,
        desglose: desglose,
        desdeElemento: desdeElemento,
        desdeSelect: desdeSelect,
        poblarPersonas: poblarPersonas
    };
})();
