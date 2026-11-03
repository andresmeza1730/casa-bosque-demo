<?php
// ============================================================
// modules/reportes/respaldo.php
// Respaldo de base de datos bajo demanda — Casa Bosque
// ============================================================
// Genera un volcado .sql de todas las tablas y lo envía como
// descarga. SOLO accesible por administradores.
//
// Nota: en hosting compartido (InfinityFree) no hay acceso a
// mysqldump por shell, así que el volcado se construye con PHP
// recorriendo las tablas vía PDO. Apto para bases pequeñas/medianas
// como la de un hotel. Para bases muy grandes, usar el Export de
// phpMyAdmin.
// ------------------------------------------------------------

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();   // ← solo administradores
// Llevarse la base entera es lo más grave que puede hacer quien tenga el equipo
// y la contraseña: aunque el equipo esté recordado, aquí se pide el código.
// (Es una descarga directa por GET: al confirmar, el navegador vuelve aquí y
// la descarga arranca.) Ver includes/equipos.php.
require_once __DIR__ . '/../../includes/equipos.php';
exigirCodigoEnAccionCritica();

$pdo = getDB();

// Registrar en auditoría que se generó un respaldo
registrarAuditoria($pdo, 'sistema', null, 'respaldo', 'Generó respaldo de base de datos');

// Nombre del archivo con fecha y hora
$nombreArchivo = 'casabosque_respaldo_' . date('Y-m-d_His') . '.sql';

// Cabeceras de descarga
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// ── Encabezado del volcado ──
echo "-- ============================================================\n";
echo "-- Respaldo Casa Bosque\n";
echo "-- Generado: " . date('Y-m-d H:i:s') . "\n";
echo "-- ============================================================\n\n";
echo "set foreign_key_checks = 0;\n\n";

// ── Listar todas las tablas ──
$tablas = $pdo->query("show tables")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tablas as $tabla) {
    // Estructura
    echo "-- ----------------------------------------------------------\n";
    echo "-- Tabla: $tabla\n";
    echo "-- ----------------------------------------------------------\n";
    echo "drop table if exists `$tabla`;\n";

    $row = $pdo->query("show create table `$tabla`")->fetch(PDO::FETCH_ASSOC);
    echo $row['Create Table'] . ";\n\n";

    // Datos
    $datos = $pdo->query("select * from `$tabla`");
    $filas = $datos->fetchAll(PDO::FETCH_ASSOC);

    if (count($filas) > 0) {
        foreach ($filas as $fila) {
            $columnas = array_map(fn($c) => "`$c`", array_keys($fila));
            $valores = array_map(function ($v) use ($pdo) {
                if ($v === null) return 'null';
                return $pdo->quote($v);
            }, array_values($fila));

            echo "insert into `$tabla` (" . implode(', ', $columnas) . ") values (" . implode(', ', $valores) . ");\n";
        }
        echo "\n";
    }
}

echo "set foreign_key_checks = 1;\n";
exit;
