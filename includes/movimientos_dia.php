<?php
// ============================================================
// includes/movimientos_dia.php
// Entradas y salidas del día: lo hecho y lo que falta
// ============================================================
//
// Por qué existe este archivo:
//
// Las cifras de «Check-in hoy» y «Salidas hoy» se calculaban con el mismo SQL
// copiado cuatro veces —dos en dashboard.php y dos en
// modules/recepcion/index.php—, y las cuatro copias contaban LO PENDIENTE
// creyendo contar lo hecho:
//
//   - una reserva deja de estar 'confirmada' al registrar la entrada
//     (pasa a 'completada'), así que abandona la cuenta;
//   - una estancia deja de estar 'activa' al cerrar la salida,
//     así que abandona la suya.
//
// El resultado era que los números BAJABAN conforme avanzaba el turno y
// acababan en cero, dando a entender que no se había hecho nada.
//
// Aquí se calculan las dos caras, cada una de donde corresponde:
//
//   HECHAS  → de `estancias`, por la fecha del MOVIMIENTO REAL
//             (fecha_checkin / fecha_checkout) y SIN exigir reserva. Esto
//             último importa: una estancia de mostrador (walk-in) no tiene
//             reserva, y el cruce obligatorio contra `reservas` dejaba sus
//             salidas sin contar nunca, ni como hechas ni como previstas.
//
//   PENDIENTES → lo que sigue esperando: reservas de hoy sin entrada
//             registrada, y estancias en curso cuya salida estaba prevista
//             para hoy. Aquí el cruce contra `reservas` SÍ hace falta, porque
//             la fecha de salida prevista solo existe en la reserva: una
//             estancia de mostrador no la tiene en ningún sitio.
//
// La forma `date(columna) = current_date()` es la que ya usaban
// modules/reportes/caja.php y los ingresos del dashboard. Se mantiene igual
// para que las tres pantallas no puedan discrepar entre sí.

/**
 * Entradas y salidas de hoy, hechas y pendientes.
 *
 * @return array{entradas_hechas:int, entradas_pendientes:int,
 *               salidas_hechas:int, salidas_pendientes:int}
 */
function movimientosDelDia(PDO $pdo): array
{
    // Entradas registradas hoy. Incluye las de mostrador, que no vienen de
    // ninguna reserva pero son entradas igual.
    $entradasHechas = (int) $pdo->query("
        select count(*)
        from estancias
        where date(fecha_checkin) = current_date()
    ")->fetchColumn();

    // Entradas que siguen esperando: la reserva entra hoy y nadie ha
    // registrado todavía su llegada.
    $entradasPendientes = (int) $pdo->query("
        select count(*)
        from reservas
        where estado = 'confirmada'
          and fecha_entrada = current_date()
    ")->fetchColumn();

    // Salidas cerradas hoy. Sin cruzar con `reservas`: si se cruza, las
    // salidas de mostrador desaparecen de la cuenta.
    $salidasHechas = (int) $pdo->query("
        select count(*)
        from estancias
        where estado = 'cerrada'
          and date(fecha_checkout) = current_date()
    ")->fetchColumn();

    // Salidas previstas que aún no se han cerrado. La fecha prevista vive en
    // la reserva, así que el cruce es obligado; una estancia de mostrador no
    // aparece aquí porque no hay fecha de salida pactada en ninguna parte.
    $salidasPendientes = (int) $pdo->query("
        select count(*)
        from estancias e
        join reservas r on r.id = e.reserva_id
        where e.estado = 'activa'
          and r.fecha_salida = current_date()
    ")->fetchColumn();

    return [
        'entradas_hechas'     => $entradasHechas,
        'entradas_pendientes' => $entradasPendientes,
        'salidas_hechas'      => $salidasHechas,
        'salidas_pendientes'  => $salidasPendientes,
    ];
}

/**
 * Texto corto para la línea de apoyo de las tarjetas: «2 pendientes».
 * Devuelve cadena vacía cuando no queda nada, para no pintar la línea.
 */
function movimientosPendientesTexto(int $pendientes): string
{
    if ($pendientes < 1) {
        return '';
    }
    return $pendientes === 1 ? '1 pendiente' : $pendientes . ' pendientes';
}
