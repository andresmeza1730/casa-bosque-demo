<?php
// ============================================================
// includes/caja.php
// El dinero: lo que entró y lo que se facturó — Casa Bosque
// ============================================================
// El problema que resuelve:
//
// La pantalla llamada «Arqueo de Caja» no servía para cuadrar la caja. Sumaba
// el valor FACTURADO de las cuentas cerradas ese día —`subtotal_noches`,
// `total_consumos`, `total_pagar` de `estancias`— y la tabla `pagos` no la leía
// NINGÚN reporte del sistema. Las consecuencias, todas comprobadas:
//
//   - Un abono cobrado al reservar (modules/checkin/index.php) entraba en caja
//     el día del check-out, no el día que llegó la plata.
//   - Un pago de deuda posterior al check-out (dashboard.php) NO aparecía en
//     ningún día: no es un check-out, así que ninguna fecha lo recogía.
//   - Una cuenta cerrada DEBIENDO se contaba completa, como si se hubiera
//     cobrado entera.
//   - No había desglose por método, así que no se podía separar el efectivo del
//     datáfono, de la transferencia y de Nequi. Eso es justamente lo que impide
//     hacer un arqueo: contar el cajón y comparar.
//
// Y las VENTAS DE MOSTRADOR no estaban en ningún reporte. `ventas_mostrador` se
// leía en un solo sitio de todo el proyecto: el resumen por día de Consumos.
// Mercancía que sale del inventario, dinero que entra, y los reportes no lo
// sabían.
//
// ------------------------------------------------------------
// POR QUÉ ESTE ARCHIVO EXISTE
//
// La misma consulta sobre `estancias` estaba copiada CINCO veces: la pantalla,
// el PDF y el CSV de Caja y de Ingresos. Cambiar las cifras en una y no en las
// otras deja tres documentos contradiciéndose sobre el mismo día, y eso no se
// detecta mirando una sola pantalla.
//
// Es el mismo motivo por el que existen includes/movimientos_dia.php —«el mismo
// SQL estaba copiado cuatro veces»— y conceptoIva() en
// includes/establecimiento.php. Aquí NO hay HTML: solo los números.
//
// ------------------------------------------------------------
// DOS CONCEPTOS QUE NO SON EL MISMO, Y NO HAY QUE MEZCLARLOS
//
//   RECIBIDO  = dinero que entró ese día: filas de `pagos` + `ventas_mostrador`
//               con esa fecha. Es lo que se cuenta en el cajón.
//   FACTURADO = valor de las cuentas que se cerraron ese día. Es lo que produjo
//               el check-out.
//
// Los dos son útiles y casi nunca son iguales. Que no cuadren NO es un error
// del sistema: es información. Por eso cajaDescuadre() lo explica en vez de
// esconderlo.
// ------------------------------------------------------------

/**
 * Los métodos de pago, en el orden en que se muestran, con su nombre legible.
 *
 * Es la UNIÓN de los dos enum del esquema, que no coinciden: `pagos` admite
 * efectivo|tarjeta|transferencia|nequi|otro y `ventas_mostrador` solo
 * efectivo|transferencia|nequi. Recorrer la unión es lo que hace que no se
 * pierda un método por venir de una tabla y no de la otra.
 *
 * Si mañana se añade un método a cualquiera de los dos enum, se añade aquí y
 * aparece en los tres documentos a la vez.
 */
function cajaMetodos(): array
{
    return [
        'efectivo'      => 'Efectivo',
        'tarjeta'       => 'Tarjeta',
        'transferencia' => 'Transferencia',
        'nequi'         => 'Nequi',
        'otro'          => 'Otro',
    ];
}

/** Nombre legible de un método, incluso si llegara uno que no está en la lista. */
function cajaMetodoNombre(string $metodo): string
{
    return cajaMetodos()[$metodo] ?? ucfirst($metodo);
}

/**
 * Valida una fecha `Y-m-d` y devuelve hoy si no lo es.
 *
 * La comparación de ida y vuelta es a propósito: `createFromFormat` acepta
 * «2026-02-31» y lo corre al 3 de marzo, así que sin volver a formatear una
 * fecha imposible pasaría como buena. Mismo criterio que el resumen por día de
 * Consumos.
 */
function cajaFechaValida(?string $fecha): string
{
    $fecha = trim((string) $fecha);
    if ($fecha === '') return date('Y-m-d');
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    return ($d && $d->format('Y-m-d') === $fecha) ? $fecha : date('Y-m-d');
}

/**
 * El día siguiente, para acotar un rango con `>= y <`.
 *
 * Las consultas NO usan `date(fecha) = ?`: una función sobre la columna impide
 * usar cualquier índice. Con `fecha >= ? and fecha < ?` el día queda igual de
 * acotado y la consulta sí podría aprovechar un índice si algún día se añade.
 *
 * Hoy `pagos` y `ventas_mostrador` no tienen índice por `fecha`, y no se añade:
 * sería un cambio de base de datos y la regla de versiones del proyecto lo
 * convertiría en una versión mayor. Con el volumen de un hotel pequeño no se
 * nota, pero la consulta ya queda escrita para cuando importe.
 */
function _cajaDiaSiguiente(string $fecha): string
{
    return date('Y-m-d', strtotime($fecha . ' +1 day'));
}

/**
 * DINERO RECIBIDO en una fecha: pagos de estancias + ventas de mostrador.
 *
 * @return array{
 *   total: float,
 *   por_metodo: array<string, array{total: float, movimientos: int}>,
 *   movimientos: list<array>,
 *   total_pagos: float, total_ventas: float,
 *   num_pagos: int, num_ventas: int
 * }
 */
function cajaRecibidoDelDia(PDO $pdo, string $fecha): array
{
    $desde = $fecha;
    $hasta = _cajaDiaSiguiente($fecha);

    // Los pagos llevan su `nota` a propósito. Cada sitio que inserta en `pagos`
    // deja escrito de dónde viene el dinero —«Abono recibido al reservar»,
    // «Saldo cobrado en el check-out», «Pago de deuda posterior al
    // check-out»—, y eso es lo que hace que un descuadre se explique solo sin
    // que nadie tenga que ir a investigarlo.
    $st = $pdo->prepare("
        select p.id, p.monto, p.metodo, p.nota, p.fecha,
               h.numero as habitacion,
               hu.nombres, hu.apellidos,
               u.nombre as usuario
        from pagos p
        join estancias e    on e.id = p.estancia_id
        join habitaciones h on h.id = e.habitacion_id
        join huespedes hu   on hu.id = e.huesped_id
        left join usuarios u on u.id = p.usuario_id
        where p.fecha >= ? and p.fecha < ?
        order by p.fecha asc, p.id asc
    ");
    $st->execute([$desde, $hasta]);
    $pagos = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("
        select v.id, v.subtotal as monto, v.metodo, v.fecha,
               v.producto_nombre, v.cantidad,
               u.nombre as usuario
        from ventas_mostrador v
        left join usuarios u on u.id = v.usuario_id
        where v.fecha >= ? and v.fecha < ?
        order by v.fecha asc, v.id asc
    ");
    $st->execute([$desde, $hasta]);
    $ventas = $st->fetchAll(PDO::FETCH_ASSOC);

    $movimientos = [];
    $totalPagos  = 0.0;
    $totalVentas = 0.0;

    foreach ($pagos as $p) {
        $totalPagos += (float) $p['monto'];
        $movimientos[] = [
            'tipo'       => 'pago',
            'fecha'      => $p['fecha'],
            'monto'      => (float) $p['monto'],
            'metodo'     => (string) $p['metodo'],
            'concepto'   => trim((string) $p['nota']) !== '' ? (string) $p['nota'] : 'Pago de estancia',
            'quien'      => trim($p['nombres'] . ' ' . $p['apellidos']),
            'habitacion' => (string) $p['habitacion'],
            'usuario'    => $p['usuario'],
        ];
    }

    foreach ($ventas as $v) {
        $totalVentas += (float) $v['monto'];
        $movimientos[] = [
            'tipo'       => 'venta',
            'fecha'      => $v['fecha'],
            'monto'      => (float) $v['monto'],
            'metodo'     => (string) $v['metodo'],
            'concepto'   => $v['producto_nombre'] . ' x' . (int) $v['cantidad'],
            'quien'      => 'Venta de mostrador',
            'habitacion' => null,
            'usuario'    => $v['usuario'],
        ];
    }

    // Las dos tablas vienen ordenadas por separado; juntas tienen que quedar en
    // orden de reloj, que es como se lee un arqueo.
    usort($movimientos, fn($a, $b) => strcmp((string) $a['fecha'], (string) $b['fecha']));

    // El desglose se arma SOBRE LAS MISMAS FILAS que se van a pintar, no con un
    // `group by` aparte. Así la suma de los métodos no puede diferir del total
    // ni del detalle: es imposible por construcción, no por cuidado.
    $porMetodo = [];
    foreach ($movimientos as $m) {
        $k = $m['metodo'];
        if (!isset($porMetodo[$k])) $porMetodo[$k] = ['total' => 0.0, 'movimientos' => 0];
        $porMetodo[$k]['total'] += $m['monto'];
        $porMetodo[$k]['movimientos']++;
    }

    // En el orden de cajaMetodos(), y sin los métodos que no tuvieron nada: una
    // fila en cero no dice nada y estorba al leer.
    $ordenado = [];
    foreach (array_keys(cajaMetodos()) as $k) {
        if (isset($porMetodo[$k])) { $ordenado[$k] = $porMetodo[$k]; unset($porMetodo[$k]); }
    }
    foreach ($porMetodo as $k => $v) { $ordenado[$k] = $v; }   // alguno fuera de la lista

    return [
        'total'        => $totalPagos + $totalVentas,
        'por_metodo'   => $ordenado,
        'movimientos'  => $movimientos,
        'total_pagos'  => $totalPagos,
        'total_ventas' => $totalVentas,
        'num_pagos'    => count($pagos),
        'num_ventas'   => count($ventas),
    ];
}

/**
 * FACTURADO en una fecha: las cuentas que se cerraron ese día.
 *
 * Es la consulta que estaba en caja.php y en caja_pdf.php, movida tal cual.
 *
 * @return array{total: float, hospedaje: float, consumos: float,
 *               cierres: list<array>, num_cierres: int, pendiente: float}
 */
function cajaFacturadoDelDia(PDO $pdo, string $fecha): array
{
    $st = $pdo->prepare("
        select e.subtotal_noches, e.total_consumos, e.total_pagar, e.total_abonado,
               e.fecha_checkout,
               h.numero as habitacion, hu.nombres, hu.apellidos
        from estancias e
        join habitaciones h on h.id = e.habitacion_id
        join huespedes hu   on hu.id = e.huesped_id
        where e.estado = 'cerrada'
          and e.fecha_checkout >= ? and e.fecha_checkout < ?
        order by e.fecha_checkout asc
    ");
    $st->execute([$fecha, _cajaDiaSiguiente($fecha)]);
    $cierres = $st->fetchAll(PDO::FETCH_ASSOC);

    $hospedaje = 0.0; $consumos = 0.0; $total = 0.0; $pendiente = 0.0;
    foreach ($cierres as $c) {
        $hospedaje += (float) $c['subtotal_noches'];
        $consumos  += (float) $c['total_consumos'];
        $total     += (float) $c['total_pagar'];
        // Lo que esa cuenta debe AHORA. Ojo: `total_abonado` se actualiza cuando
        // se paga la deuda (dashboard.php), así que esto NO es el saldo que
        // quedó en el check-out —ese no se guarda en ninguna parte—. Una cuenta
        // que cerró debiendo y ya pagó aparece aquí en cero, que es lo correcto:
        // hoy no debe nada.
        $pendiente += max(0.0, (float) $c['total_pagar'] - (float) $c['total_abonado']);
    }

    return [
        'total'       => $total,
        'hospedaje'   => $hospedaje,
        'consumos'    => $consumos,
        'cierres'     => $cierres,
        'num_cierres' => count($cierres),
        'pendiente'   => $pendiente,
    ];
}

/**
 * Explica por qué lo recibido y lo facturado no coinciden.
 *
 * Devuelve null cuando cuadran. No es una alarma: la mayoría de los días no
 * cuadran por razones normales, y lo que hace falta es que la pantalla las
 * nombre para que nadie salga a buscar un fallo que no existe.
 *
 * @return array{diferencia: float, sentido: string, razones: list<string>}|null
 */
function cajaDescuadre(array $recibido, array $facturado): ?array
{
    $dif = round($recibido['total'] - $facturado['total'], 2);
    if (abs($dif) < 0.01) return null;

    $razones = [];
    if ($dif > 0) {
        // Entró más de lo que se facturó hoy.
        if ($recibido['total_ventas'] > 0) {
            $razones[] = 'ventas de mostrador, que no pasan por ninguna cuenta';
        }
        $razones[] = 'un abono de una estancia todavía abierta, que se facturará al cerrarla';
        $razones[] = 'el pago de una deuda de un check-out anterior';
    } else {
        // Se facturó más de lo que entró.
        if ($facturado['pendiente'] > 0) {
            $razones[] = 'una cuenta cerrada que todavía debe $'
                       . number_format($facturado['pendiente'], 0, ',', '.');
        }
        $razones[] = 'un abono cobrado en un día anterior, que hoy solo se facturó';
        // La otra mitad del caso anterior: cerró debiendo y YA pagó, en otro
        // día. Hoy no figura como pendiente y sin esta línea el descuadre
        // quedaría sin explicar.
        $razones[] = 'una cuenta cerrada hoy cuyo saldo se cobró en otra fecha';
    }

    return [
        'diferencia' => abs($dif),
        'sentido'    => $dif > 0 ? 'recibido' : 'facturado',
        'razones'    => $razones,
    ];
}

/**
 * Ventas de mostrador de un periodo, para Reportes de Ingresos.
 *
 * **`$hastaExclusivo` NO se incluye.** Es el mismo convenio que ya usa
 * `modules/reportes/ingresos.php`, que calcula `$hasta` como el primer día del
 * mes o del año siguiente y consulta con `fecha_checkout < ?`. Recibirlo ya
 * abierto evita el error de sumarle un día a algo que ya estaba abierto y
 * contar un día de más.
 *
 * @param string $desde          primer día incluido, `Y-m-d`
 * @param string $hastaExclusivo primer día EXCLUIDO, `Y-m-d`
 *
 * @return array{total: float, unidades: int, ventas: int,
 *               por_metodo: array<string, float>,
 *               por_periodo: array<string, float>}
 */
function cajaVentasDelPeriodo(PDO $pdo, string $desde, string $hastaExclusivo, string $agrupar = 'dia'): array
{
    $hastaAbierto = $hastaExclusivo;
    $clave = $agrupar === 'mes' ? "date_format(fecha, '%Y-%m')" : "date(fecha)";

    $st = $pdo->prepare("
        select $clave as periodo, metodo,
               coalesce(sum(subtotal), 0) as total,
               coalesce(sum(cantidad), 0) as unidades,
               count(*) as ventas
        from ventas_mostrador
        where fecha >= ? and fecha < ?
        group by periodo, metodo
        order by periodo asc
    ");
    $st->execute([$desde, $hastaAbierto]);

    $total = 0.0; $unidades = 0; $ventas = 0;
    $porMetodo = []; $porPeriodo = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $total    += (float) $f['total'];
        $unidades += (int) $f['unidades'];
        $ventas   += (int) $f['ventas'];
        $porMetodo[(string) $f['metodo']] = ($porMetodo[(string) $f['metodo']] ?? 0.0) + (float) $f['total'];
        $porPeriodo[(string) $f['periodo']] = ($porPeriodo[(string) $f['periodo']] ?? 0.0) + (float) $f['total'];
    }

    return [
        'total'       => $total,
        'unidades'    => $unidades,
        'ventas'      => $ventas,
        'por_metodo'  => $porMetodo,
        'por_periodo' => $porPeriodo,
    ];
}

/** Formato de pesos del proyecto, para no repetirlo en cuatro plantillas. */
function cajaCop(float $n): string
{
    return '$' . number_format($n, 0, ',', '.');
}
