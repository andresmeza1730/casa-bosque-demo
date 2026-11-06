<?php
// ============================================================
// modules/huespedes/existe.php
// ¿Ese número de documento ya está registrado? (JSON) — 4.3.0
// ============================================================
//
// El formulario de «Nuevo huésped» (y el de «Nuevo» en Check-in) dejaba escribir un documento que ya existía y solo lo decía DESPUÉS de enviar:
// «Ya existe un huésped con ese número de documento». Esto contesta mientras se escribe, para avisar de una vez (assets/js/documento-existente.js).
//
// Lo mismo que modules/huespedes/buscar.php, y por lo mismo:
//  - Solo por POST y con testigo (sin gastarlo): lo que se manda es un documento y no debe quedar en la URL ni en los registros del hosting.
//  - El documento solo se puede comparar EXACTO: va cifrado y lo que se consulta es su índice ciego (HMAC). Por eso el documento se normaliza
//    EXACTAMENTE como al guardarlo (validarDocumentoTipo: sin puntos ni espacios; en mayúsculas el pasaporte): si no, «1.234.567» y «1234567»
//    serían dos huéspedes distintos para este aviso y el mismo para la base.
//  - Lo que devuelve sale enmascarado (documentoOculto): quien escribe ya sabe el número; no hace falta repetirlo entero.
//
// Quien puede crear un huésped (cualquier persona con sesión) ya puede buscarlo por su documento en buscar.php: esto no entrega nada más que eso.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cifrado.php';
require_once __DIR__ . '/../../includes/validacion.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

// No se usa requireLogin(): redirige a una pantalla HTML y quien llama espera JSON.
if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'sesion']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'metodo']);
    exit;
}
if (!csrfTokenValido()) {
    http_response_code(403);
    echo json_encode(['error' => 'testigo']);
    exit;
}

$tipo = is_string($_POST['tipo'] ?? null) ? (string) $_POST['tipo'] : '';
$doc  = is_string($_POST['documento'] ?? null) ? (string) $_POST['documento'] : '';
$norm = validarDocumentoTipo($tipo, $doc);

// Algo que todavía no es un documento válido (muy corto, con letras donde no van…) no se busca: ya lo dirá la validación al enviar.
if ($norm === null) {
    echo json_encode(['existe' => false, 'valido' => false]);
    exit;
}

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("select id, nombres, apellidos, documento_num from huespedes where documento_hash = ? limit 1");
    $stmt->execute([indiceCiego($norm)]);
    $h = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$h) {
        echo json_encode(['existe' => false, 'valido' => true]);
        exit;
    }

    // ¿Está alojado ahora? Es lo primero que quiere saber recepción.
    $st = $pdo->prepare("select hb.numero from estancias e join habitaciones hb on hb.id = e.habitacion_id
                         where e.huesped_id = ? and e.estado = 'activa' limit 1");
    $st->execute([(int) $h['id']]);
    $hab = $st->fetchColumn();

    echo json_encode([
        'existe'     => true,
        'valido'     => true,
        'id'         => (int) $h['id'],
        'nombre'     => trim($h['nombres'] . ' ' . $h['apellidos']),
        'documento'  => documentoOculto(descifrar($h['documento_num'])),
        'habitacion' => $hab === false ? null : (string) $hab,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('Documento existente: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'fallo']);
}
