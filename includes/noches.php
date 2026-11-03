<?php
// ============================================================
// includes/noches.php
// Cuántas noches lleva, o llevó, una estancia
// ============================================================
//
// LA REGLA DEL HOTEL, explicada por ellos (19 de octubre de 2026):
//
//   «si la persona llega de día o de noche y se queda durmiendo ahí, se cobra
//    esa noche, así se vaya en la madrugada al día siguiente. Se van a cobrar
//    los días que la persona se quede, NO EL DÍA COMPLETO.»
//
// O sea: la unidad es **la noche dormida**, y la hora de llegada no influye.
//
//   - Entra el 17 y se va el 19 por la mañana → 2: las noches del 17 y del 18.
//     La del 19 no, porque esa no la durmió.
//   - Entra el 17 a las 23:00 y se va el 18 a las 04:00 → 1: durmió la noche
//     del 17. No se le cobra el 18 por haber estado unas horas; eso es lo que
//     quiere decir «no el día completo».
//   - La hora de llegada da igual: lo que cuenta es si se queda a dormir.
//
// **No intuir aquí.** En dos días esta regla se entendió de tres maneras
// distintas y cada versión se lleva una noche de diferencia en cada estadía:
// se cobró de menos durante meses por contar con relojes, y luego se cobró de
// más por sumar el día de llegada. Si alguien cree que hay que cambiarla, que
// lo confirme con el hotel antes de tocar esta línea.
//
// POR QUÉ EXISTE ESTE ARCHIVO
//
// El cálculo estaba escrito a mano en seis sitios y los seis hacían
// `$entrada->diff($salida)->days`, que cuenta periodos de 24 horas COMPLETOS.
// Entre el 17 a las 15:10 y el 19 a las 09:25 hay 1 día y 18 horas, así que
// devolvía 1 donde son 2. Fallaba justo en el caso normal de un hotel: salir
// más temprano en el día de lo que se entró.
//
// Aquí las dos marcas se llevan a medianoche antes de restar, así que lo que
// se cuenta son los cambios de FECHA. Cada cambio de fecha es una noche
// dormida, que es lo que se cobra.
//
// NO sirve para los rangos de una RESERVA (`fecha_entrada` y `fecha_salida`).
// Esas columnas son de tipo `date`, sin hora, y su resta ya da el número
// exacto de noches; ahí nunca hubo nada que arreglar.

/**
 * Noches dormidas entre dos momentos, contadas por fecha.
 *
 * @param string|DateTimeInterface      $entrada Marca del check-in.
 * @param string|DateTimeInterface|null $salida  Marca de la salida. Si no se
 *                                               pasa, se cuenta hasta ahora,
 *                                               que es lo que necesita una
 *                                               estancia todavía abierta.
 */
function nochesEstancia($entrada, $salida = null): int
{
    $desde = _nocheAMedianoche($entrada);
    $hasta = _nocheAMedianoche($salida ?? 'now');

    // `%r%a` da los días con signo: si por un error de datos la salida fuera
    // anterior a la entrada, sale negativo y el mínimo lo deja en 1 en vez de
    // devolver un número absurdo.
    $dias = (int) $desde->diff($hasta)->format('%r%a');

    // El mínimo de 1 cubre la entrada y salida el mismo día, que el sistema
    // siempre cobró como una noche. Este hotel no tiene tarifa por horas.
    return max(1, $dias);
}

/** Lleva una marca de tiempo al comienzo de su día. */
function _nocheAMedianoche($valor): DateTimeImmutable
{
    if ($valor instanceof DateTimeInterface) {
        $valor = $valor->format('Y-m-d H:i:s');
    }

    $valor = trim((string) $valor);
    if ($valor === '') {
        $valor = 'now';
    }

    return (new DateTimeImmutable($valor))->setTime(0, 0);
}
