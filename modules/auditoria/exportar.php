<?php
// ============================================================
// modules/auditoria/exportar.php
// Exporta la pista de auditoría: CSV (Excel) o JSON (de ahí sale el PDF)
// ============================================================
// Solo administrador. Recibe los mismos filtros que la pantalla y los lee con la
// MISMA función (auditoriaFiltros): lo que se exporta es lo que se estaba viendo.
//
//   ?formato=csv    datos tabulares limpios, para análisis en Excel o BI.
//   ?formato=json   lo que dibuja assets/js/auditoria-pdf.js: el PDF se arma en el
//                   navegador, con las mismas filas y el mismo sello.
//
// Las dos salidas llevan el SELLO de integridad (SHA-256 de las filas, en orden de
// número) y el mismo FOLIO. El sello no es una firma digital con certificado: dice
// que el contenido no se alteró, no quién lo emitió.
//
// Exportar la auditoría también queda en la auditoría ('exportar_auditoria'): quién
// se llevó la pista de qué, y cuándo. Se escribe DESPUÉS de calcular el sello, así
// que esa fila no entra en el archivo que acaba de salir.
//
// Compatible con el hosting compartido: sin librerías.
// ------------------------------------------------------------

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

const AUDITORIA_EXPORT_TOPE = 5000;

$pdo = getDB();
$formato = ($_GET['formato'] ?? 'csv') === 'json' ? 'json' : 'csv';

$f = auditoriaFiltros($pdo, $_GET);
$consulta = auditoriaConsulta($pdo, $f, AUDITORIA_EXPORT_TOPE);
$filas = $consulta['filas'];
$sello = auditoriaSello($filas);
$folio = 'AUD-' . str_replace('-', '', $f['desde']) . '-' . str_replace('-', '', $f['hasta']) . '-' . strtoupper(substr($sello, 0, 8));
$periodo = date('d/m/Y', strtotime($f['desde'])) . ' a ' . date('d/m/Y', strtotime($f['hasta']));

// Los filtros activos, en palabras, para que el documento diga de qué es.
$textoFiltros = [];
if ($f['usuario'] > 0) {
    $nombreUsuario = '';
    foreach (auditoriaUsuarios($pdo) as $u) { if ((int) $u['id'] === $f['usuario']) $nombreUsuario = $u['nombre']; }
    $textoFiltros[] = 'usuario ' . ($nombreUsuario !== '' ? $nombreUsuario : '#' . $f['usuario']);
}
if ($f['modulo'] !== '')    $textoFiltros[] = 'módulo ' . $f['modulo'];
if ($f['severidad'] !== '') $textoFiltros[] = 'gravedad ' . $f['severidad'];
if ($f['accion'] !== '')    $textoFiltros[] = 'acción ' . $f['accion'];
if ($f['ip'] !== '')        $textoFiltros[] = 'IP ' . $f['ip'];

// Constancia de la exportación (antes de mandar cabeceras: después ya no se puede escribir).
registrarAuditoria($pdo, 'auditoria', null, 'exportar_auditoria',
    'Exportó la pista de auditoría (' . strtoupper($formato) . '): ' . count($filas) . ' de ' . $consulta['total']
    . ' registros, ' . $periodo . ', folio ' . $folio);

if ($formato === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'hotel'       => 'Casa Bosque',
        'folio'       => $folio,
        'sello'       => $sello,
        'generado'    => date('d/m/Y H:i:s') . ' (-05)',
        'responsable' => getCurrentUserName(),
        'periodo'     => $periodo,
        'filtros'     => implode(' · ', $textoFiltros),
        'total'       => $consulta['total'],
        'mostradas'   => count($filas),
        'filas'       => $filas,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

// celdaCsv() —una celda que empieza con = + - @ la ejecuta Excel como fórmula, y el detalle de la auditoría lleva texto que escribió otra persona—
// vive desde 4.3.2 en includes/csv.php, que también usan la exportación de huéspedes y la de ingresos.
require_once __DIR__ . '/../../includes/csv.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $folio . '.csv"');
header('Cache-Control: no-store');

$salida = fopen('php://output', 'w');
fwrite($salida, "\xEF\xBB\xBF");   // para que Excel lea bien las tildes
fputcsv($salida, ['Fecha y hora (Bogotá, -05)', 'Usuario', 'Acción', 'Categoría', 'Módulo', 'Tabla', 'Registro',
                  'Gravedad', 'Detalle', 'IP', 'Navegador', 'Antes', 'Después'], ';');
foreach ($filas as $r) {
    fputcsv($salida, array_map('celdaCsv', [
        $r['fecha'], $r['usuario'], $r['accion'], $r['categoria'], $r['modulo'], $r['tabla'], $r['registro'] ?? '',
        $r['severidad'], $r['detalle'], $r['ip'], $r['agente'],
        $r['antes'] ? json_encode($r['antes'], JSON_UNESCAPED_UNICODE) : '',
        $r['despues'] ? json_encode($r['despues'], JSON_UNESCAPED_UNICODE) : '',
    ]), ';');
}
fputcsv($salida, [], ';');
fputcsv($salida, ['Folio', $folio], ';');
fputcsv($salida, ['Sello SHA-256 (de los registros, en orden de número)', $sello], ';');
fputcsv($salida, ['Periodo', $periodo], ';');
fputcsv($salida, ['Registros', count($filas) . ' de ' . $consulta['total']], ';');
fclose($salida);
exit;
