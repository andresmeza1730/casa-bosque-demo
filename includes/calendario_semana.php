<?php
// ============================================================
// includes/calendario_semana.php — Lo que muestra el calendario
// ============================================================
// Una semana (lunes a domingo): habitaciones × 7 días. Cada celda es
// libre, reservada u ocupada. Solo lectura; no escribe nada.
//
// Por qué vive aquí y no dentro de la pantalla:
//   · El calendario del mes repetía a mano el solape de fechas dentro de
//     la vista y nadie lo podía probar sin pintar HTML.
//   · NO es disponibilidad.php: esa decide si una reserva CABE. Esto solo
//     MUESTRA lo que ya hay.
//
// Reglas que se conservan del calendario del mes:
//   · La noche de salida no cuenta: una reserva del 28 al 30 ocupa el 28
//     y el 29.
//   · Solo reservas «confirmada» y estancias «activa». Sin historial.
//   · Una estancia pisa a la reserva del mismo día.
//
// Reglas que cambian (3.11.0):
//   · Las habitaciones «inactiva» no salen: están retiradas del
//     inventario y no se ofrecen (ver 1.2.3).
//   · La estancia de mostrador, sin reserva, SÍ sale. Antes el calendario
//     hacía un join con reservas y no la veía, así que una habitación
//     ocupada se leía como libre. No sabemos hasta cuándo se queda (no hay
//     salida pactada), así que se marca ocupada desde su check-in hasta
//     HOY y no se inventan los días que vienen.
//   · Una estancia que ya pasó la salida de su reserva y sigue activa
//     (el huésped no ha hecho check-out) se ve ocupada hasta hoy.
// ------------------------------------------------------------

/**
 * El lunes de la semana pedida. Una fecha que no existe, o que no viene
 * escrita como AAAA-MM-DD, cae en la semana de hoy, sin avisar (igual que
 * el calendario del mes con ?mes=).
 */
function calendarioLunes(?string $param, DateTimeImmutable $hoy): DateTimeImmutable
{
    $base = $hoy;
    if ($param !== null) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $param);
        if ($d !== false && $d->format('Y-m-d') === $param) {
            $base = $d;
        }
    }
    $atras = (int) $base->format('N') - 1; // lunes = 1
    return $base->modify('-' . $atras . ' days');
}

/** «28 oct» o, con el día de la semana, «mié 28 oct». */
function calendarioFechaCorta(string $ymd, bool $conDia = false): string
{
    static $meses = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    static $dias  = [1 => 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    if ($d === false) {
        return $ymd;
    }
    $txt = (int) $d->format('j') . ' ' . $meses[(int) $d->format('n')];
    return $conDia ? $dias[(int) $d->format('N')] . ' ' . $txt : $txt;
}

/** «26 oct – 1 nov 2026»; si la semana cruza de año, el año va en los dos lados. */
function calendarioTituloSemana(DateTimeImmutable $lunes): string
{
    $dom = $lunes->modify('+6 days');
    $a = calendarioFechaCorta($lunes->format('Y-m-d'));
    $b = calendarioFechaCorta($dom->format('Y-m-d'));
    if ($lunes->format('Y') !== $dom->format('Y')) {
        return $a . ' ' . $lunes->format('Y') . ' – ' . $b . ' ' . $dom->format('Y');
    }
    return $a . ' – ' . $b . ' ' . $dom->format('Y');
}

/**
 * La semana completa.
 *
 * Devuelve:
 *   'dias'          7 × ['fecha', 'letra', 'num', 'hoy', 'texto']
 *   'habitaciones'  [ ['id', 'numero', 'tipo', 'celdas' => 7 × [
 *                       'fecha', 'estado' (libre|reservada|ocupada),
 *                       'huesped', 'entrada', 'salida' ] ] ]
 * 'entrada' y 'salida' ya vienen escritas para la tarjeta («28 oct»).
 */
function calendarioSemana(PDO $pdo, DateTimeImmutable $lunes, DateTimeImmutable $hoy): array
{
    $letras = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
    $hoyS    = $hoy->format('Y-m-d');
    $mananaS = $hoy->modify('+1 day')->format('Y-m-d');

    $dias = [];
    for ($i = 0; $i < 7; $i++) {
        $f = $lunes->modify('+' . $i . ' days')->format('Y-m-d');
        $dias[] = [
            'fecha' => $f,
            'letra' => $letras[$i],
            'num'   => (int) substr($f, 8, 2),
            'hoy'   => $f === $hoyS,
            'texto' => calendarioFechaCorta($f, true),
        ];
    }
    $ini = $dias[0]['fecha'];
    $fin = $dias[6]['fecha'];

    $habs = $pdo->query("
        select h.id, h.numero, t.nombre as tipo
        from habitaciones h
        join tipos_habitacion t on t.id = h.tipo_id
        where h.estado <> 'inactiva'
        order by cast(h.numero as unsigned), h.numero asc
    ")->fetchAll();

    $ocupacion = []; // [habitacion_id][fecha] = celda

    // Reservas: la noche de salida no cuenta, así que se pide salida > lunes.
    $st = $pdo->prepare("
        select r.habitacion_id, r.fecha_entrada, r.fecha_salida, hu.nombres, hu.apellidos
        from reservas r
        join huespedes hu on hu.id = r.huesped_id
        where r.estado = 'confirmada'
          and r.fecha_entrada <= ?
          and r.fecha_salida > ?
    ");
    $st->execute([$fin, $ini]);
    foreach ($st->fetchAll() as $r) {
        $entrada = substr($r['fecha_entrada'], 0, 10);
        $salida  = substr($r['fecha_salida'], 0, 10);
        foreach ($dias as $d) {
            if ($d['fecha'] >= $entrada && $d['fecha'] < $salida) {
                $ocupacion[$r['habitacion_id']][$d['fecha']] = [
                    'estado'  => 'reservada',
                    'huesped' => trim($r['nombres'] . ' ' . $r['apellidos']),
                    'entrada' => calendarioFechaCorta($entrada),
                    'salida'  => calendarioFechaCorta($salida),
                ];
            }
        }
    }

    // Estancias activas, con o sin reserva (left join): ver la cabecera.
    $st = $pdo->prepare("
        select e.habitacion_id, date(e.fecha_checkin) as entrada, r.fecha_salida as salida,
               hu.nombres, hu.apellidos
        from estancias e
        join huespedes hu on hu.id = e.huesped_id
        left join reservas r on r.id = e.reserva_id
        where e.estado = 'activa'
          and date(e.fecha_checkin) <= ?
    ");
    $st->execute([$fin]);
    foreach ($st->fetchAll() as $e) {
        $entrada = substr($e['entrada'], 0, 10);
        $salida  = $e['salida'] !== null ? substr($e['salida'], 0, 10) : null;
        // Exclusivo: la estancia ocupa hasta la víspera de la salida. Sin
        // salida pactada, o con la salida ya vencida, ocupa hasta hoy.
        $hasta = $salida ?? $mananaS;
        if ($hasta <= $hoyS) {
            $hasta = $mananaS;
        }
        if ($salida === null) {
            $txtSalida = 'Sin salida pactada';
        } elseif ($salida <= $hoyS) {
            $txtSalida = calendarioFechaCorta($salida) . ' (ya pasó, sin check-out)';
        } else {
            $txtSalida = calendarioFechaCorta($salida);
        }
        foreach ($dias as $d) {
            if ($d['fecha'] >= $entrada && $d['fecha'] < $hasta) {
                $ocupacion[$e['habitacion_id']][$d['fecha']] = [
                    'estado'  => 'ocupada',
                    'huesped' => trim($e['nombres'] . ' ' . $e['apellidos']),
                    'entrada' => calendarioFechaCorta($entrada),
                    'salida'  => $txtSalida,
                ];
            }
        }
    }

    $filas = [];
    foreach ($habs as $h) {
        $celdas = [];
        foreach ($dias as $d) {
            $c = $ocupacion[$h['id']][$d['fecha']] ?? [
                'estado' => 'libre', 'huesped' => '', 'entrada' => '', 'salida' => '',
            ];
            $c['fecha'] = $d['fecha'];
            $celdas[] = $c;
        }
        $filas[] = ['id' => (int) $h['id'], 'numero' => $h['numero'], 'tipo' => $h['tipo'], 'celdas' => $celdas];
    }

    return ['lunes' => $ini, 'domingo' => $fin, 'dias' => $dias, 'habitaciones' => $filas];
}
