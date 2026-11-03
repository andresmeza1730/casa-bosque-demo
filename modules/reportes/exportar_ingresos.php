<?php
// ============================================================
// modules/reportes/exportar_ingresos.php
// Exporta el reporte de ingresos del periodo a CSV (Excel)
// ============================================================
// Recibe los mismos parámetros que ingresos.php (vista, mes, anio)
// y genera el desglose + el resumen por empleado en un CSV.
// Solo administrador.
// ------------------------------------------------------------

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/caja.php';   // las ventas de mostrador, en un solo sitio
requireAdmin();

$pdo = getDB();

// ── Mismo cálculo de periodo que ingresos.php ──
$vista = ($_GET['vista'] ?? 'mes') === 'anio' ? 'anio' : 'mes';
$mesesEs = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

if ($vista === 'mes') {
    $mes = $_GET['mes'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');
    $desde = $mes . '-01';
    $hasta = date('Y-m-d', strtotime($desde . ' +1 month'));
    $tituloPeriodo = $mesesEs[(int)date('n', strtotime($desde))] . '_' . date('Y', strtotime($desde));
} else {
    $anio = (int)($_GET['anio'] ?? date('Y'));
    if ($anio < 2020 || $anio > 2100) $anio = (int)date('Y');
    $desde = $anio . '-01-01';
    $hasta = ($anio + 1) . '-01-01';
    $tituloPeriodo = 'Anio_' . $anio;
}

// ── Desglose por día (mes) o por mes (año) ──
if ($vista === 'mes') {
    $stmt = $pdo->prepare("
        select date(fecha_checkout) as periodo,
               coalesce(sum(subtotal_noches), 0) as hospedaje,
               coalesce(sum(total_consumos), 0) as consumos,
               coalesce(sum(total_pagar), 0) as total,
               count(*) as checkouts
        from estancias
        where estado = 'cerrada' and fecha_checkout >= ? and fecha_checkout < ?
        group by date(fecha_checkout)
        order by periodo
    ");
} else {
    $stmt = $pdo->prepare("
        select date_format(fecha_checkout, '%Y-%m') as periodo,
               coalesce(sum(subtotal_noches), 0) as hospedaje,
               coalesce(sum(total_consumos), 0) as consumos,
               coalesce(sum(total_pagar), 0) as total,
               count(*) as checkouts
        from estancias
        where estado = 'cerrada' and fecha_checkout >= ? and fecha_checkout < ?
        group by date_format(fecha_checkout, '%Y-%m')
        order by periodo
    ");
}
$stmt->execute([$desde, $hasta]);
$desglose = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Por empleado ──
$stmt = $pdo->prepare("
    select u.nombre,
           count(*) as checkouts,
           coalesce(sum(e.subtotal_noches), 0) as hospedaje,
           coalesce(sum(e.total_consumos), 0) as consumos,
           coalesce(sum(e.total_pagar), 0) as total
    from estancias e, usuarios u
    where e.usuario_checkout = u.id
      and e.estado = 'cerrada'
      and e.fecha_checkout >= ? and e.fecha_checkout < ?
    group by u.id, u.nombre
    order by total desc
");
$stmt->execute([$desde, $hasta]);
$porUsuario = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Registro de la exportación (HCB_RNF_002) ──
// Son las cifras de ingresos del hotel saliendo a un archivo. No lleva
// datos personales, pero sí información del negocio, y debe quedar quién
// la descargó. Va ANTES de las cabeceras: después ya no se puede escribir.
registrarAuditoria($pdo, 'estancias', null, 'exportar_ingresos',
    'Exportó el informe de ingresos del periodo ' . $tituloPeriodo);

// ── Descarga CSV ──
$nombreArchivo = 'ingresos_' . $tituloPeriodo . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Pragma: no-cache');
header('Expires: 0');

$salida = fopen('php://output', 'w');
fprintf($salida, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8

// Sección 1: desglose
fputcsv($salida, [$vista === 'mes' ? 'Día' : 'Mes', 'Check-outs', 'Hospedaje', 'Consumos', 'Total'], ';');
$tH = $tC = $tT = $tCO = 0;
foreach ($desglose as $d) {
    fputcsv($salida, [$d['periodo'], $d['checkouts'], $d['hospedaje'], $d['consumos'], $d['total']], ';');
    $tH += $d['hospedaje']; $tC += $d['consumos']; $tT += $d['total']; $tCO += $d['checkouts'];
}
fputcsv($salida, ['TOTALES', $tCO, $tH, $tC, $tT], ';');

// Línea en blanco separadora
fputcsv($salida, [], ';');

// ── Sección 2: ventas directas de mostrador ──
// Faltaban enteras en este archivo. Van en su propia sección y NO dentro de los
// TOTALES de arriba, que siguen siendo el valor de las estancias cerradas: un
// archivo viejo y uno nuevo del mismo periodo tienen que seguir cuadrando en
// esa cifra. El total de todo está en la línea TOTAL GENERAL.
//
// `$hasta` ya es el primer día EXCLUIDO.
$ventas = cajaVentasDelPeriodo($pdo, $desde, $hasta, $vista === 'mes' ? 'dia' : 'mes');

fputcsv($salida, ['Ventas directas de mostrador'], ';');
fputcsv($salida, ['Método', 'Valor'], ';');
foreach ($ventas['por_metodo'] as $met => $val) {
    fputcsv($salida, [cajaMetodoNombre($met), $val], ';');
}
fputcsv($salida, ['TOTAL MOSTRADOR', $ventas['total']], ';');
fputcsv($salida, ['Ventas', $ventas['ventas']], ';');
fputcsv($salida, ['Unidades', $ventas['unidades']], ';');

fputcsv($salida, [], ';');
fputcsv($salida, ['TOTAL GENERAL (estancias + mostrador)', (float) $tT + $ventas['total']], ';');

fputcsv($salida, [], ';');

// Sección 3: por empleado
fputcsv($salida, ['Ingresos por empleado'], ';');
fputcsv($salida, ['Empleado', 'Check-outs', 'Hospedaje', 'Consumos', 'Total cerrado'], ';');
foreach ($porUsuario as $u) {
    fputcsv($salida, [$u['nombre'], $u['checkouts'], $u['hospedaje'], $u['consumos'], $u['total']], ';');
}

fclose($salida);
exit;
