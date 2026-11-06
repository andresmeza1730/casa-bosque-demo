<?php
// ============================================================
// modules/huespedes/exportar.php
// Exporta el directorio de huéspedes a CSV (abre en Excel)
// ============================================================
// Genera un archivo descargable con todos los huéspedes.
// Solo administrador. Sin librerías externas.
// ------------------------------------------------------------

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cifrado.php';
requireAdmin();   // exportar la base de clientes es exclusivo del admin
// Esto se lleva el documento y el teléfono de TODOS los huéspedes, descifrados,
// en un archivo. Es tan grave como el respaldo de la base, así que aunque el
// equipo esté recordado aquí se pide el código.
// (Descarga directa por GET: al confirmar, el navegador vuelve aquí y la
// descarga arranca.) Ver includes/equipos.php.
require_once __DIR__ . '/../../includes/equipos.php';
require_once __DIR__ . '/../../includes/csv.php';   // celdaCsv: el nombre, el correo y la ciudad los tecleó una persona
exigirCodigoEnAccionCritica();

$pdo = getDB();

// Traer todos los huéspedes ordenados por apellido
$huespedes = $pdo->query("
    select documento_tipo, documento_num, nombres, apellidos, telefono, email, ciudad_origen
    from huespedes
    order by apellidos, nombres
")->fetchAll(PDO::FETCH_ASSOC);

// ── Registro de la exportación (Ley 1581 / HCB_RNF_002) ──
// Esto se lleva la base de clientes completa, con documentos y teléfonos
// descifrados, a un archivo fuera del sistema. Tiene que quedar constancia
// de quién lo hizo y cuándo: sin esto no hay forma de responder quién
// accedió a los datos personales, que es justo lo que exige la ley.
// Va ANTES de mandar las cabeceras, porque después ya no se puede escribir.
registrarAuditoria($pdo, 'huespedes', null, 'exportar_csv',
    'Exportó la lista completa de huéspedes (' . count($huespedes) . ' registros) con documentos y teléfonos');

// Nombre del archivo con la fecha de hoy
$nombreArchivo = 'huespedes_casa_bosque_' . date('Y-m-d') . '.csv';

// Cabeceras para forzar la descarga como CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Abrir el flujo de salida
$salida = fopen('php://output', 'w');

// BOM UTF-8: hace que Excel reconozca las tildes y la ñ correctamente
fprintf($salida, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Fila de encabezados
fputcsv($salida, ['Tipo Doc.', 'Documento', 'Nombres', 'Apellidos', 'Teléfono', 'Correo', 'Ciudad de origen'], ';');

// Filas de datos
foreach ($huespedes as $h) {
    fputcsv($salida, [
        $h['documento_tipo'],
        descifrar($h['documento_num']),
        // 4.3.2: lo que tecleó una persona pasa por celdaCsv(): un nombre «=HYPERLINK(...)» se ejecutaba como fórmula al abrir el archivo en Excel.
        // El documento y el teléfono ya vienen validados (solo números y + - paréntesis), y el tipo es fijo.
        celdaCsv($h['nombres']),
        celdaCsv($h['apellidos']),
        descifrar($h['telefono']),
        celdaCsv($h['email']),
        celdaCsv($h['ciudad_origen']),
    ], ';');
}

fclose($salida);
exit;
