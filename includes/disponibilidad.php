<?php
// ============================================================
// includes/disponibilidad.php
// ¿Se puede reservar esta habitación en estas fechas?
// ============================================================
//
// Por qué existe este archivo:
//
// Las dos vías de reserva aplicaban criterios distintos. La página pública
// (obtener_disponibilidad.php) solo ofrecía habitaciones con el estado físico
// 'disponible'; el módulo interno (modules/reservas/index.php) solo descartaba
// las 'inactiva'. Resultado: por dentro se dejaba reservar una habitación que
// estaba en limpieza, ocupada o mantenimiento.
//
// La regla, decidida para este hotel, es POR FECHA:
//
//   - El estado físico de una habitación es de HOY. Una reserva es para el
//     FUTURO. Bloquear por estar sucia hoy haría perder reservas que sí se
//     pueden cumplir, así que para fechas futuras el estado físico no manda.
//   - Si la entrada es HOY y la habitación está ocupada, en limpieza o en
//     mantenimiento, se bloquea: nadie va a entrar hoy ahí.
//   - 'inactiva' se bloquea siempre: está retirada del inventario.
//   - Para cualquier fecha, lo que manda es que no haya SOLAPE con otra
//     reserva confirmada ni con una estancia en curso.
//
// Límite conocido, a la vista: una estancia de mostrador (walk-in) no tiene
// fecha de salida pactada en ninguna parte de la base, así que no se puede
// saber hasta cuándo ocupa. Mientras siga abierta, la habitación figura como
// 'ocupada', y eso ya bloquea las entradas de hoy; para fechas futuras no hay
// dato con el que decidir y no se inventa uno.
//
// Todo el que compruebe disponibilidad debe pasar por aquí. Dos
// comprobaciones de solape escritas por separado acabarían discrepando, y es
// justo lo que causó este fallo.

/** Estados físicos que impiden entrar HOY (pero no en una fecha futura). */
const DISPONIBILIDAD_ESTADOS_HOY = ['ocupada', 'limpieza', 'mantenimiento'];

/**
 * Bloquea la fila de una habitación hasta que termine la transacción en curso,
 * y devuelve su estado ya bloqueado (o null si la habitación no existe).
 *
 * **Hay que llamarla DENTRO de una transacción, y antes de comprobar la
 * disponibilidad.** `habitacionReservable()` es un lector de instantánea: dice
 * la verdad del momento en que se ejecuta y se desentiende. Entre ese «sí» y la
 * escritura, otra sesión puede haber reservado la misma habitación. El bloqueo
 * es lo que cierra esa ventana: la segunda sesión que pida la MISMA fila espera
 * a que la primera confirme, y entonces vuelve a comprobar y ve el mundo nuevo.
 *
 * El orden es, siempre:
 *
 *   beginTransaction → bloquearHabitacion → habitacionReservable → escribir
 *   con el estado esperado en el `where` y comprobar rowCount → commit
 *
 * **Un bloqueo solo sirve si TODOS los que escriben lo piden.** `for update`
 * serializa únicamente a quien lo pide: si una vía de reserva bloquea y otra no,
 * la que bloquea cree estar protegida y no lo está. Por eso esto vive aquí, con
 * el resto de la regla, y no copiado en cada módulo.
 *
 * No se bloquea la fila del TIPO de habitación a propósito: pondría en fila, una
 * detrás de otra, todas las reservas de habitaciones del mismo tipo.
 */
function bloquearHabitacion(PDO $pdo, int $habitacionId): ?array
{
    $st = $pdo->prepare("select id, estado from habitaciones where id = ? for update");
    $st->execute([$habitacionId]);
    $fila = $st->fetch(PDO::FETCH_ASSOC);
    return $fila === false ? null : $fila;
}

/** Cómo se nombra cada estado en un mensaje para el recepcionista. */
function disponibilidadNombreEstado(string $estado): string
{
    return [
        'ocupada'       => 'ocupada',
        'limpieza'      => 'en limpieza',
        'mantenimiento' => 'en mantenimiento',
        'inactiva'      => 'retirada del inventario',
        'disponible'    => 'disponible',
    ][$estado] ?? $estado;
}

/** dd/mm/aaaa, como se lee en el resto del sistema. */
function disponibilidadFecha(string $iso): string
{
    $t = strtotime($iso);
    return $t ? date('d/m/Y', $t) : $iso;
}

/**
 * Comprueba si una habitación admite una reserva en un rango de fechas.
 *
 * @param string   $entrada         fecha de entrada (Y-m-d)
 * @param string   $salida          fecha de salida  (Y-m-d)
 * @param int|null $excluirReserva  id de la reserva que se está editando, para
 *                                  que no choque consigo misma
 *
 * @return array{ok:bool, motivo:string, mensaje:string, libres:array}
 *         `ok` true cuando se puede reservar. Si no, `mensaje` ya está escrito
 *         para mostrarlo tal cual, y `libres` trae las habitaciones que sí
 *         admiten esas fechas (puede venir vacío).
 */
function habitacionReservable(
    PDO $pdo,
    int $habitacionId,
    string $entrada,
    string $salida,
    ?int $excluirReserva = null
): array {
    $hoy      = date('Y-m-d');
    $esHoy    = ($entrada <= $hoy);   // entrar "hoy" incluye cualquier fecha ya pasada

    $stmt = $pdo->prepare("select numero, estado from habitaciones where id = ?");
    $stmt->execute([$habitacionId]);
    $hab = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$hab) {
        return ['ok' => false, 'motivo' => 'no_existe',
                'mensaje' => 'La habitación seleccionada no existe.', 'libres' => []];
    }

    $numero = $hab['numero'];
    $estado = (string) $hab['estado'];

    // Retirada del inventario: no se reserva ni hoy ni dentro de un año.
    if ($estado === 'inactiva') {
        return ['ok' => false, 'motivo' => 'inactiva',
                'mensaje' => "La habitación $numero está retirada del inventario y no se puede reservar.",
                'libres'  => habitacionesLibresEnRango($pdo, $entrada, $salida, $excluirReserva)];
    }

    // Estado físico: solo manda cuando la entrada es hoy.
    if ($esHoy && in_array($estado, DISPONIBILIDAD_ESTADOS_HOY, true)) {
        $comoSeLlama = disponibilidadNombreEstado($estado);
        return ['ok' => false, 'motivo' => 'estado_hoy',
                'mensaje' => "La habitación $numero está $comoSeLlama ahora mismo, así que no admite una entrada hoy. "
                           . "Para una fecha posterior sí se puede reservar.",
                'libres'  => habitacionesLibresEnRango($pdo, $entrada, $salida, $excluirReserva)];
    }

    // Solape con otra reserva confirmada.
    $sql = "select fecha_entrada, fecha_salida
            from reservas
            where habitacion_id = ?
              and estado = 'confirmada'
              and fecha_entrada < ?
              and fecha_salida  > ?";
    $params = [$habitacionId, $salida, $entrada];
    if ($excluirReserva !== null) {
        $sql .= " and id <> ?";
        $params[] = $excluirReserva;
    }
    $sql .= " order by fecha_entrada limit 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $choque = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($choque) {
        $desde = disponibilidadFecha($choque['fecha_entrada']);
        $hasta = disponibilidadFecha($choque['fecha_salida']);
        return ['ok' => false, 'motivo' => 'solape_reserva',
                'mensaje' => "La habitación $numero ya está reservada del $desde al $hasta. "
                           . "Prueba con fechas posteriores al $hasta, o asígnale otra habitación.",
                'libres'  => habitacionesLibresEnRango($pdo, $entrada, $salida, $excluirReserva)];
    }

    // Solape con una estancia en curso. La fecha hasta la que ocupa sale de su
    // reserva; una estancia de mostrador no la tiene (ver nota de arriba).
    $stmt = $pdo->prepare("
        select date(e.fecha_checkin) as desde, r.fecha_salida as hasta
        from estancias e
        join reservas r on r.id = e.reserva_id
        where e.habitacion_id = ?
          and e.estado = 'activa'
          and date(e.fecha_checkin) < ?
          and r.fecha_salida > ?
        order by r.fecha_salida desc
        limit 1
    ");
    $stmt->execute([$habitacionId, $salida, $entrada]);
    $enCurso = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($enCurso) {
        $hasta = disponibilidadFecha($enCurso['hasta']);
        return ['ok' => false, 'motivo' => 'solape_estancia',
                'mensaje' => "La habitación $numero está ocupada por un huésped que sale el $hasta. "
                           . "Prueba con fechas posteriores al $hasta, o asígnale otra habitación.",
                'libres'  => habitacionesLibresEnRango($pdo, $entrada, $salida, $excluirReserva)];
    }

    return ['ok' => true, 'motivo' => '', 'mensaje' => '', 'libres' => []];
}

/**
 * Habitaciones que sí admiten una reserva en ese rango, con el mismo criterio
 * que habitacionReservable(). Es lo que el recepcionista iba a buscar a mano
 * en cuanto le dijeran que la habitación elegida no sirve.
 *
 * @return array<int,array{numero:string,tipo:string}>
 */
function habitacionesLibresEnRango(
    PDO $pdo,
    string $entrada,
    string $salida,
    ?int $excluirReserva = null,
    int $limite = 8
): array {
    // Si la entrada es hoy, el estado físico sí manda: solo las 'disponible'.
    $exigirDisponible = ($entrada <= date('Y-m-d')) ? 1 : 0;

    $sqlExcluir = $excluirReserva !== null ? ' and r.id <> ?' : '';

    $sql = "
        select h.numero, t.nombre as tipo
        from habitaciones h
        join tipos_habitacion t on t.id = h.tipo_id
        where h.estado <> 'inactiva'
          and (? = 0 or h.estado = 'disponible')
          and not exists (
                select 1 from reservas r
                where r.habitacion_id = h.id
                  and r.estado = 'confirmada'
                  and r.fecha_entrada < ?
                  and r.fecha_salida  > ?
                  $sqlExcluir
          )
          and not exists (
                select 1 from estancias e
                join reservas r2 on r2.id = e.reserva_id
                where e.habitacion_id = h.id
                  and e.estado = 'activa'
                  and date(e.fecha_checkin) < ?
                  and r2.fecha_salida > ?
          )
        order by cast(h.numero as unsigned), h.numero
        limit " . max(1, $limite);

    $params = [$exigirDisponible, $salida, $entrada];
    if ($excluirReserva !== null) {
        $params[] = $excluirReserva;
    }
    $params[] = $salida;
    $params[] = $entrada;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Las habitaciones libres, ya en una frase: «Libres para esas fechas: 102
 * (Doble), 205 (Sencilla).» Cadena vacía si no queda ninguna.
 */
function disponibilidadTextoLibres(array $libres): string
{
    if (!$libres) {
        return '';
    }
    $partes = [];
    foreach ($libres as $h) {
        $partes[] = $h['numero'] . ' (' . $h['tipo'] . ')';
    }
    return 'Libres para esas fechas: ' . implode(', ', $partes) . '.';
}
