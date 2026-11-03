<?php
// ============================================================
// modules/reportes/tra_exportar.php
// Tarjeta de Registro de Alojamiento (TRA / MinCIT) — archivo plano .txt
// ============================================================
// Genera el registro de TODOS los huéspedes alojados (nacionales y
// extranjeros) con estancia dentro de un rango de fechas. Solo admin.
//
// Formato: una línea por estancia, campos separados por "|":
//   rnt | tipo_doc | num_doc | nombres | apellidos | nacionalidad |
//   fecha_entrada(YYYY-MM-DD) | fecha_salida(YYYY-MM-DD o vacío) |
//   ciudad_procedencia | habitacion
//
// ⚠️  El layout EXACTO (orden, separador, cabecera, campos como
//     "motivo de viaje" o "ciudad destino") debe validarse contra la
//     especificación vigente de la TRA/RNT (MinCIT). El orden de
//     columnas está centralizado y es fácil de ajustar.
// ------------------------------------------------------------

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cifrado.php';
requireAdmin();

// ── Fuera del alcance ── Se retira con gubernamentales.php, la única
// pantalla desde la que se llega aquí. Para reactivarlo, borra estas dos
// líneas y las equivalentes en gubernamentales.php.
require_once __DIR__ . '/../../includes/modulo_retirado.php';
moduloRetirado('Reporte TRA', 'El reporte al MinCIT no está entre los requerimientos definidos para el sistema.');

$establecimiento = require __DIR__ . '/../../config/establecimiento.php';
$rnt = $establecimiento['rnt'];

$pdo = getDB();

// ── Rango de fechas (validado) ──
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
$dDesde = $desde ? DateTime::createFromFormat('Y-m-d', $desde) : null;
$dHasta = $hasta ? DateTime::createFromFormat('Y-m-d', $hasta) : null;
if (!$dDesde || $dDesde->format('Y-m-d') !== $desde) { $desde = date('Y-m-01'); }
if (!$dHasta || $dHasta->format('Y-m-d') !== $hasta) { $hasta = date('Y-m-d'); }

// Todas las estancias cuyo check-in cae dentro del rango.
$stmt = $pdo->prepare("
    select e.fecha_checkin, e.fecha_checkout,
           hu.documento_tipo, hu.documento_num, hu.nombres, hu.apellidos,
           hu.nacionalidad, hu.ciudad_origen,
           h.numero as habitacion
    from estancias e, huespedes hu, habitaciones h
    where e.huesped_id = hu.id
      and e.habitacion_id = h.id
      and date(e.fecha_checkin) between ? and ?
    order by e.fecha_checkin
");
$stmt->execute([$desde, $hasta]);
$filas = $stmt->fetchAll();

// ── Construir las líneas del archivo plano ──
$lineas = [];
$sep = '|';
foreach ($filas as $f) {
    $lineas[] = implode($sep, [
        $rnt,
        $f['documento_tipo'],
        descifrar($f['documento_num']),
        mb_strtoupper($f['nombres']),
        mb_strtoupper($f['apellidos']),
        mb_strtoupper($f['nacionalidad']),
        substr($f['fecha_checkin'], 0, 10),
        !empty($f['fecha_checkout']) ? substr($f['fecha_checkout'], 0, 10) : '',
        mb_strtoupper((string)$f['ciudad_origen']),
        $f['habitacion'],
    ]);
}

// ── Descargar como .txt ──
$nombre = 'TRA_' . $desde . '_a_' . $hasta . '.txt';
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo implode("\r\n", $lineas);
if ($lineas) echo "\r\n";
exit;
