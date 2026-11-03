<?php
// ============================================================
// includes/tarifas.php
// De dónde sale el precio de una habitación
// ============================================================
//
// EL PRECIO LO MANDA EL TIPO, NO LA HABITACIÓN.
//
// La pantalla de Habitaciones lo dice con sus propias palabras: «La capacidad
// y la tarifa se toman del tipo elegido» y «al guardar un tipo, la tarifa y la
// capacidad se actualizan para todas sus habitaciones». No hay ningún
// formulario en el sistema que permita ponerle precio a UNA habitación suelta.
//
// Aun así la tabla `habitaciones` tiene sus propias columnas `tarifa` y
// `capacidad_max`. No son otro precio: son una COPIA del tipo, que se reescribe
// cada vez que alguien guarda el tipo (modules/habitaciones/index.php, el
// `update habitaciones set capacidad_max=?, tarifa=? where tipo_id=?`). Una
// copia que solo se actualiza en ese momento se queda vieja en cuanto algo
// escribe en la tabla por fuera de esa pantalla.
//
// POR QUÉ EXISTE ESTE ARCHIVO
//
// Eso es exactamente lo que pasó, y costó dinero. El 19 de octubre de 2026
// cinco habitaciones Doble —107, 108, 207, 208 y 308— tenían `h.tarifa = 0`
// mientras su tipo decía 130.000. Y como cada pantalla leía una columna
// distinta, nadie podía verlo:
//
//   - La página PÚBLICA de reservas las ofrecía a $0 la noche. Cualquiera
//     podía reservarlas gratis.
//   - El check-in copiaba ese 0 a `estancias.tarifa_noche`, así que un
//     walk-in a la 107 no pagaba hospedaje: toda la estadía a cero.
//   - La pantalla de Habitaciones mostraba $130.000 y se veía perfecta,
//     porque era la ÚNICA que leía `tipos_habitacion.tarifa_base`.
//
// Misma habitación, dos precios, según la pantalla. El fallo no se podía
// descubrir desde el sitio donde se administran los precios.
//
// LA REGLA, ENTONCES: el precio y la capacidad que se cobran y se ofrecen
// salen de `tipos_habitacion`. Las columnas de `habitaciones` quedan solo
// como respaldo por si una habitación apuntara a un tipo que ya no existe.
//
// ════════════════════════════════════════════════════════════
//  Y EL PRECIO DEPENDE DE CUÁNTAS PERSONAS DUERMAN (2.0.0)
// ════════════════════════════════════════════════════════════
//
// La tabla real del hotel, confirmada por ellos:
//
//   Cama doble     1 persona $40.000   2 personas $50.000
//   Cama sencilla  1 persona $37.000   (no admite dos)
//   Dos camas      2 personas $70.000  + $10.000 por persona adicional
//                  (y una persona sola paga igual $70.000: se paga la
//                   habitación, no la cama)
//
// Eso son tres números por tipo: `tarifa_1`, `tarifa_2` y `tarifa_extra`.
//
// LA IGUALDAD QUE NO SE PUEDE ROMPER
//
//     subtotal_noches = noches × tarifa_noche
//
// `estancias.tarifa_noche` sigue siendo UN número, pero ahora es el precio
// YA RESUELTO para la ocupación de esa estadía: una doble con dos personas
// congela 50.000; una de dos camas con cuatro congela 70.000 + 2×10.000 =
// 90.000. El recargo NO va aparte.
//
// Esto no es un detalle de estilo. Hay dos sitios que DIVIDEN para recuperar
// el número de noches:
//
//   - modules/checkout/factura_pdf.php — round($subtotal_noches / $tarifaN)
//   - mi-cuenta.php                    — lo mismo
//
// Si el recargo se guardara por separado, esa división daría un número de
// noches FALSO y la factura lo imprimiría sin avisar de nada. Resolviendo el
// precio antes de congelarlo, la igualdad se mantiene y con ella siguen
// funcionando sin tocarlos el check-out, el tiquete, la factura, el correo,
// la DIAN, el arqueo de caja, los reportes de ingresos y el panel.
//
// Si algún día hace falta desglosar el recargo en el documento, el dato está:
// `estancias.num_personas` dice cuánta gente durmió, y con el tipo se puede
// recomponer el desglose SIN tocar `tarifa_noche`.
//
// CAPACIDAD SIN TOPE
//
// En `tipos_habitacion.capacidad_max`, el valor **0 significa «sin tope»**.
// Lo necesita «Dos camas», que admite cuantas personas quepan pagando el
// recargo. La columna es `int not null` y usar NULL habría roto las
// comparaciones `capacidad_max >= ?` que ya existen, así que el centinela es
// el 0 y se traduce en un solo sitio: SQL_CAPACIDAD, abajo.
//
// CÓMO SE USA
//
// La consulta tiene que traer `habitaciones h` y su `tipos_habitacion t`
// (cualquiera de los dos joins vale, el implícito o el explícito):
//
//     $sql = "select h.id, h.numero, " . SQL_TARIFA . " as tarifa,
//                    " . SQL_CAPACIDAD . " as capacidad_max
//             from habitaciones h
//             join tipos_habitacion t on t.id = h.tipo_id";
//
// Si el alias del tipo no es `t`, no uses estas constantes a ciegas: o
// renombras el alias, o escribes el coalesce a mano. Un alias que no existe
// es un error de SQL, no un cobro silencioso de cero, así que el fallo se ve
// en seguida.
//
// Para COBRAR no vale la constante: hay que saber cuántas personas son, y eso
// no cabe en una expresión SQL fija. Se usa tarifaPorPersonas(), más abajo.

/**
 * El precio de ENTRADA de una habitación, en SQL. Para mostrar, no para cobrar.
 *
 * Es el precio más bajo al que se puede ocupar: el de una persona, o el de dos
 * si el tipo no se vende a una sola. Sirve para los listados —«desde $40.000»—
 * y para ordenar o comparar.
 *
 * Para el precio que se COBRA, usa tarifaPorPersonas(): depende de la
 * ocupación y esto no la conoce.
 *
 * Requiere `habitaciones h` y `tipos_habitacion t` en la consulta.
 */
const SQL_TARIFA = 'coalesce(t.tarifa_1, t.tarifa_2, t.tarifa_base, h.tarifa)';

/**
 * Cuántas personas admite una habitación, en SQL.
 *
 * Mismo criterio y mismo requisito que SQL_TARIFA: manda el tipo. El 0
 * significa «sin tope» y aquí se traduce a 9999 para que las comparaciones
 * `>= ?` de la página pública sigan valiendo sin cambiarlas.
 *
 * Ojo al `nullif` de la SEGUNDA rama, que no es decorativo: la habitación
 * guarda una copia de la capacidad de su tipo, así que en un tipo sin tope esa
 * copia también vale 0. Sin el segundo `nullif`, el coalesce se quedaría en ese
 * 0 en vez de llegar al 9999, la capacidad resuelta sería CERO y el sistema
 * rechazaría cualquier ocupación en las habitaciones de dos camas.
 */
const SQL_CAPACIDAD = 'coalesce(nullif(t.capacidad_max, 0), nullif(h.capacidad_max, 0), 9999)';

/**
 * Qué valor de `capacidad_max` significa «sin tope».
 */
const CAPACIDAD_SIN_TOPE = 0;

/**
 * EL PRECIO QUE SE COBRA. Única autoridad en PHP.
 *
 * @param array $tipo     Fila de `tipos_habitacion` con al menos `tarifa_1`,
 *                        `tarifa_2` y `tarifa_extra`. Acepta también
 *                        `tarifa_base` como último respaldo, para un tipo que
 *                        venga de antes de la migración 024.
 * @param int   $personas Cuántas personas duermen.
 * @return int|null       Pesos por noche, o NULL si ese tipo no admite esa
 *                        ocupación (una cama sencilla con dos personas). Quien
 *                        llama tiene que tratar el NULL: es un «no se puede
 *                        vender así», no un cero.
 */
function tarifaPorPersonas(array $tipo, int $personas): ?int
{
    if ($personas < 1) {
        return null;
    }

    $una = _tarifaValor($tipo['tarifa_1'] ?? null);
    $dos = _tarifaValor($tipo['tarifa_2'] ?? null);

    // Respaldo para un tipo anterior a la migración 024, al que nadie le haya
    // puesto todavía las tarifas nuevas: cobra lo que cobraba antes.
    if ($una === null && $dos === null) {
        $una = $dos = _tarifaValor($tipo['tarifa_base'] ?? null);
    }

    if ($personas === 1) {
        // Una sola persona paga su precio si el tipo lo tiene; si no, paga el
        // de dos. Es el caso de «Dos camas»: se paga la habitación, no la cama.
        return $una ?? $dos;
    }

    // De dos en adelante manda el precio de dos. Un tipo sin `tarifa_2` no
    // admite más de una persona y devuelve NULL, que es lo correcto: la
    // capacidad lo habrá rechazado antes, y si no, aquí se rechaza.
    $base = $dos;
    if ($base === null) {
        return null;
    }

    if ($personas === 2) {
        return $base;
    }

    $extra = _tarifaValor($tipo['tarifa_extra'] ?? null) ?? 0;
    if ($extra < 1) {
        // Más de dos personas en un tipo que no cobra adicionales: no se puede
        // vender así. Si se devolviera $base, se regalarían las personas de más.
        return null;
    }

    return $base + ($personas - 2) * $extra;
}

/**
 * ¿Este tipo admite esa ocupación?
 *
 * Junta las dos razones por las que puede no admitirla: la capacidad del tipo
 * y la falta de un precio aplicable. Devuelve el motivo en palabras, listo
 * para mostrar, o null si sí la admite.
 */
function motivoOcupacionInvalida(array $tipo, int $personas): ?string
{
    if ($personas < 1) {
        return 'Indica cuántas personas se van a hospedar.';
    }

    $tope = (int) ($tipo['capacidad_max'] ?? 0);
    if ($tope !== CAPACIDAD_SIN_TOPE && $personas > $tope) {
        $nombre = trim((string) ($tipo['nombre'] ?? 'Esa habitación'));
        return sprintf(
            '%s admite %d persona(s) como máximo, y se indicaron %d.',
            $nombre !== '' ? $nombre : 'Esa habitación',
            $tope,
            $personas
        );
    }

    if (tarifaPorPersonas($tipo, $personas) === null) {
        $nombre = trim((string) ($tipo['nombre'] ?? 'ese tipo de habitación'));
        return sprintf(
            'No hay una tarifa definida para %d persona(s) en %s. Revisa las tarifas del tipo en Habitaciones.',
            $personas,
            $nombre !== '' ? $nombre : 'ese tipo de habitación'
        );
    }

    return null;
}

/**
 * Cómo se explica un precio, en una línea. Para el tiquete y la factura.
 *
 * «2 personas» en los casos normales, y «2 personas + 2 adicionales» cuando hay
 * recargo, que es justo cuando el huésped pregunta por qué paga lo que paga.
 */
function detalleOcupacion(int $personas, ?array $tipo = null): string
{
    $personas = max(1, $personas);
    $txt = $personas . ' persona' . ($personas === 1 ? '' : 's');

    if ($personas > 2 && $tipo !== null) {
        $extra = _tarifaValor($tipo['tarifa_extra'] ?? null) ?? 0;
        if ($extra >= 1) {
            $adicionales = $personas - 2;
            $txt .= ' (2 + ' . $adicionales . ' adicional' . ($adicionales === 1 ? '' : 'es') . ')';
        }
    }

    return $txt;
}

/**
 * Cómo se escribe una capacidad en pantalla.
 *
 * Llega de dos maneras según de dónde se lea: el 0 crudo de la base, o el 9999
 * en que lo convierte SQL_CAPACIDAD. Las dos quieren decir lo mismo, y ninguna
 * se le puede enseñar al recepcionista: «sin tope» sí.
 */
function textoCapacidad(int $capacidad): string
{
    if ($capacidad === CAPACIDAD_SIN_TOPE || $capacidad >= 9999) {
        return 'sin tope';
    }
    return $capacidad . ' pers.';
}

/**
 * Cuántas personas dijo el formulario.
 *
 * El menú de personas ofrece las ocupaciones que el tipo admite y, en los tipos
 * SIN TOPE, una última opción «Más de 8…» que revela un campo numérico aparte.
 * Si se eligió esa opción, `num_personas` llega con el texto `mas` y el número
 * de verdad está en `num_personas_mas`.
 *
 * Sin esto, `(int) 'mas'` daría 0 y la ocupación caería a 1: una habitación con
 * doce personas se cobraría como si fuera una. Lo usan el check-in y las
 * reservas, que comparten el mismo control.
 *
 * @param int $porDefecto Qué devolver si el formulario no mandó nada.
 */
function personasDelFormulario(int $porDefecto = 1): int
{
    $elegido = $_POST['num_personas'] ?? '';

    if ($elegido === 'mas') {
        $n = (int) ($_POST['num_personas_mas'] ?? 0);
        return $n >= 1 ? $n : $porDefecto;
    }

    $n = (int) $elegido;
    return $n >= 1 ? $n : $porDefecto;
}

/**
 * ¿Es un precio con el que se puede cobrar?
 *
 * Último cortafuegos antes de congelar una tarifa en `estancias.tarifa_noche`,
 * que es el número que se cobra durante toda la estadía. Con el precio saliendo
 * del tipo esto ya no debería pasar nunca —el formulario del tipo exige una
 * tarifa mayor que cero—, pero una estadía cobrada a cero es un agujero
 * demasiado caro para dejarlo sin red.
 */
function tarifaCobrable($tarifa): bool
{
    return (float) $tarifa >= 1;
}

/**
 * Normaliza un precio venido de la base: NULL o vacío se quedan en NULL, y el
 * resto se redondea a pesos enteros.
 *
 * El sistema no maneja centavos: el formulario de tarifas siempre ha guardado
 * enteros, aunque las columnas sean `decimal(12,2)`.
 */
function _tarifaValor($valor): ?int
{
    if ($valor === null || $valor === '') {
        return null;
    }
    return (int) round((float) $valor);
}
