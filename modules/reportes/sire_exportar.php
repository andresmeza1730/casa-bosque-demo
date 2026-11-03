<?php
// ============================================================
// modules/reportes/sire_exportar.php
// Reporte SIRE (Migración Colombia) — archivo plano .txt
// ============================================================
// Genera el reporte de EXTRANJEROS alojados (documento CE o PAS) con
// sus movimientos de Entrada (E) y Salida (S) dentro de un rango de
// fechas. Solo administrador.
//
// Formato: una línea por movimiento, campos separados por "|":
//   codigo_sire | tipo_mov(E/S) | fecha(YYYY-MM-DD) | tipo_doc |
//   num_doc | nombres | apellidos | nacionalidad | habitacion
//
// ⚠️  El layout EXACTO (orden de campos, separador, cabecera) debe
//     validarse contra la especificación vigente de Migración
//     Colombia. Aquí el orden de columnas está centralizado y es
//     fácil de ajustar.
// ------------------------------------------------------------

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cifrado.php';
requireAdmin();

// ── Fuera del alcance ── Se retira con gubernamentales.php, la única
// pantalla desde la que se llega aquí. Para reactivarlo, borra estas dos
// líneas y las equivalentes en gubernamentales.php.
require_once __DIR__ . '/../../includes/modulo_retirado.php';
moduloRetirado('Reporte SIRE', 'El reporte a Migración Colombia no está entre los requerimientos definidos para el sistema.');

$establecimiento = require __DIR__ . '/../../config/establecimiento.php';
$codigoSire = $establecimiento['codigo_sire'];

$pdo = getDB();

// ── Rango de fechas (validado) ──
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
$dDesde = $desde ? DateTime::createFromFormat('Y-m-d', $desde) : null;
$dHasta = $hasta ? DateTime::createFromFormat('Y-m-d', $hasta) : null;
if (!$dDesde || $dDesde->format('Y-m-d') !== $desde) { $desde = date('Y-m-01'); }
if (!$dHasta || $dHasta->format('Y-m-d') !== $hasta) { $hasta = date('Y-m-d'); }

// Extranjeros: documento CE o PAS. Se traen las estancias cuyo check-in
// o check-out cae dentro del rango.
$stmt = $pdo->prepare("
    select e.fecha_checkin, e.fecha_checkout,
           hu.documento_tipo, hu.documento_num, hu.nombres, hu.apellidos, hu.nacionalidad,
           h.numero as habitacion
    from estancias e, huespedes hu, habitaciones h
    where e.huesped_id = hu.id
      and e.habitacion_id = h.id
      and hu.documento_tipo in ('CE','PAS')
      and (
            (date(e.fecha_checkin)  between ? and ?)
         or (e.fecha_checkout is not null and date(e.fecha_checkout) between ? and ?)
      )
    order by e.fecha_checkin
");
$stmt->execute([$desde, $hasta, $desde, $hasta]);
$filas = $stmt->fetchAll();

// ── Construir las líneas del archivo plano ──
$lineas = [];
$sep = '|';
foreach ($filas as $f) {
    $doc  = descifrar($f['documento_num']);
    $base = [
        $codigoSire,
        '',                        // tipo_mov (se rellena por movimiento)
        '',                        // fecha (se rellena por movimiento)
        $f['documento_tipo'],
        $doc,
        mb_strtoupper($f['nombres']),
        mb_strtoupper($f['apellidos']),
        mb_strtoupper($f['nacionalidad']),
        $f['habitacion'],
    ];

    // Movimiento de ENTRADA si el check-in cae en el rango.
    $fchIn = substr($f['fecha_checkin'], 0, 10);
    if ($fchIn >= $desde && $fchIn <= $hasta) {
        $linea = $base; $linea[1] = 'E'; $linea[2] = $fchIn;
        $lineas[] = implode($sep, $linea);
    }
    // Movimiento de SALIDA si el check-out cae en el rango.
    if (!empty($f['fecha_checkout'])) {
        $fchOut = substr($f['fecha_checkout'], 0, 10);
        if ($fchOut >= $desde && $fchOut <= $hasta) {
            $linea = $base; $linea[1] = 'S'; $linea[2] = $fchOut;
            $lineas[] = implode($sep, $linea);
        }
    }
}

// ── Descargar como .txt ──
$nombre = 'SIRE_' . $desde . '_a_' . $hasta . '.txt';
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Fin de línea CRLF (habitual en archivos oficiales de Windows).
echo implode("\r\n", $lineas);
if ($lineas) echo "\r\n";
exit;
