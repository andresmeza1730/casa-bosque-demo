<?php
// ============================================================
// modules/huespedes/buscar.php
// Sugerencias de huéspedes mientras se escribe (JSON)
// ============================================================
//
// El buscador de recepción y el del panel mandaban el formulario y recargaban
// en el directorio de Huéspedes. Para encontrar a alguien había que acertar y
// esperar. Esto devuelve coincidencias mientras se teclea, como un navegador.
//
// Dos cosas que conviene saber antes de tocar esto:
//
//  - **El documento solo se puede buscar EXACTO.** Va cifrado en la base y lo
//    que se consulta es su índice ciego (`documento_hash`, un HMAC). Un hash
//    no admite «empieza por» ni «contiene»: o coincide entero o no coincide.
//    No es una limitación del buscador, es lo que hace que un volcado de la
//    tabla no entregue los documentos de nadie.
//
//  - **El nombre sí busca por partes**, con `like '%texto%'`. Ese comodín
//    inicial no puede usar índice y obliga a recorrer la tabla, así que aquí
//    se exige un mínimo de letras y se devuelven pocas filas.
//
// La búsqueda va en dos pasos y no en un `where A or B`, por lo mismo que
// explica modules/huespedes/index.php: el `or` contagia al hash la lentitud
// del comodín y se pierde el índice.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cifrado.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
// Son datos de personas: que no se queden en ninguna caché intermedia.
header('X-Robots-Tag: noindex');

// No se usa requireLogin(): redirige a la pantalla de acceso, y una redirección
// devuelve HTML donde quien llama espera JSON. Mismo criterio que
// asistente_responder.php.
if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'sesion']);
    exit;
}

// ── Solo por POST, y con testigo ──
// Esto se consultaba con `?q=...`, así que el documento del huésped viajaba en
// la URL en CADA pulsación de tecla: no se queda en el historial del navegador
// —es una petición de fondo— pero sí en los registros de acceso del hosting,
// que los lee cualquiera que entre al panel de archivos.
//
// El testigo se comprueba SIN gastarlo (csrfTokenValido): aquí no se escribe
// nada, y gastarlo en la primera letra dejaría sin testigo a los formularios de
// la página. Lo que impide de verdad que esto se consulte desde otro sitio es
// la cookie de sesión, que va con SameSite=Strict.
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

/** Mínimo de letras antes de ir a la base. Menos que esto devuelve media tabla. */
const BUSCAR_MIN_LETRAS = 2;
/** Cuántas sugerencias se devuelven como mucho. */
const BUSCAR_MAX_FILAS  = 8;

$q = trim((string) ($_POST['q'] ?? ''));

if (mb_strlen($q) < BUSCAR_MIN_LETRAS) {
    echo json_encode(['resultados' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = getDB();

    // Paso 1: ¿es un documento? Igualdad exacta contra el índice ciego.
    $stmt = $pdo->prepare("select id, nombres, apellidos, documento_num
                           from huespedes
                           where documento_hash = ?
                           limit " . BUSCAR_MAX_FILAS);
    $stmt->execute([indiceCiego($q)]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Paso 2: si no era un documento, buscar por nombre o apellido. Desde 4.3.0 también por el nombre COMPLETO, en los dos órdenes
    // («Carlos Pineda» y «Pineda Carlos»): el selector de huésped de Reservas lo necesita, porque quien busca escribe nombre y apellido
    // juntos y `nombres like` solo no lo encontraba (el apellido está en otra columna).
    if (!$filas) {
        $comodin = '%' . $q . '%';
        $stmt = $pdo->prepare("select id, nombres, apellidos, documento_num
                               from huespedes
                               where nombres like ? or apellidos like ?
                                  or concat_ws(' ', nombres, apellidos) like ?
                                  or concat_ws(' ', apellidos, nombres) like ?
                               order by apellidos, nombres
                               limit " . BUSCAR_MAX_FILAS);
        $stmt->execute([$comodin, $comodin, $comodin, $comodin]);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ¿Alguno está alojado ahora? Es lo primero que quiere saber recepción, y
    // sale en una sola consulta para los que se hayan encontrado.
    $alojados = [];
    if ($filas) {
        $ids    = array_column($filas, 'id');
        $huecos = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("select e.huesped_id, h.numero
                               from estancias e
                               join habitaciones h on h.id = e.habitacion_id
                               where e.estado = 'activa'
                                 and e.huesped_id in ($huecos)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $alojados[(int) $f['huesped_id']] = (string) $f['numero'];
        }
    }

    // ¿Cuáles ya se han hospedado antes? El selector de huésped de Reservas lo usa para pedir (o no) el abono de la primera noche; el
    // servidor lo vuelve a decidir al guardar, esto solo avisa en pantalla.
    $conocidos = [];
    if ($filas) {
        $ids    = array_column($filas, 'id');
        $huecos = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("select distinct huesped_id from estancias where huesped_id in ($huecos)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $hid) { $conocidos[(int) $hid] = true; }
    }

    $resultados = [];
    foreach ($filas as $f) {
        $id = (int) $f['id'];
        $resultados[] = [
            'id'         => $id,
            'conocido'   => isset($conocidos[$id]),
            'nombre'     => trim($f['nombres'] . ' ' . $f['apellidos']),
            // Enmascarado a propósito: para elegir en una lista basta con los
            // últimos dígitos, y así el documento completo no viaja en cada
            // pulsación de tecla. Quien lo necesite entero abre la ficha.
            'documento'  => documentoOculto(descifrar($f['documento_num'])),
            'habitacion' => $alojados[$id] ?? null,
        ];
    }

    echo json_encode(['resultados' => $resultados], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('Buscar huéspedes: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'fallo']);
}

// El enmascarado vive en includes/cifrado.php (documentoOculto): lo usan este
// buscador y la auditoría de Huéspedes, y no pueden discrepar.
