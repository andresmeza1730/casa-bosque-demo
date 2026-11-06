<?php
// ============================================================
// modules/facturacion/buscar.php
// Las cuentas cerradas SIN factura que coinciden con lo que se escribe (JSON) — 4.3.0
// ============================================================
//
// Lo usa el campo «escribe y elige» de Facturación (includes/selector_busqueda.php), que reemplazó al menú con las últimas 200 cuentas.
// Se busca por el nombre del huésped, por su documento, por el número de habitación o por el número de la cuenta («Estancia #12»).
//
// Igual que modules/huespedes/buscar.php, y por lo mismo:
//  - Solo por POST y con testigo (sin gastarlo): lo escrito puede ser un documento y no debe quedar en la URL ni en los registros del hosting.
//  - El documento solo se puede buscar EXACTO: va cifrado y se consulta su índice ciego. El nombre sí busca por partes.
//  - La búsqueda va en dos pasos y no en un `where A or B`: el `or` contagia al índice la lentitud del comodín.
//  - El documento sale enmascarado (documentoOculto): para elegir basta con los últimos dígitos.
//
// A diferencia de aquel, esto SOLO lo contesta un administrador: Facturación es una pantalla de administrador (requireAdmin).
// Y con la caja vacía devuelve las cuentas más recientes, para que al abrir el campo ya haya algo que elegir.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cifrado.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

// No se usa requireLogin()/requireAdmin(): redirigen a una pantalla HTML y quien llama espera JSON.
if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'sesion']);
    exit;
}
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'permiso']);
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

/** Cuántas cuentas se devuelven como mucho. */
const FACT_BUSCAR_MAX_FILAS = 8;

$q = trim((string) ($_POST['q'] ?? ''));

// Una cuenta cerrada a la que no le queda una factura vigente: la misma regla de la lista de pendientes de index.php (una cuyo factura
// se anuló vuelve a aparecer, que es justo lo que se busca).
$base = "select e.id, e.fecha_checkout, e.total_pagar, h.numero habitacion, hu.nombres, hu.apellidos, hu.documento_num
         from estancias e
         join habitaciones h on h.id = e.habitacion_id
         join huespedes hu on hu.id = e.huesped_id
         where e.estado = 'cerrada'
           and not exists (select 1 from facturas f where f.estancia_id = e.id and f.estado = 'emitida')";
$orden = ' order by e.fecha_checkout desc limit ' . FACT_BUSCAR_MAX_FILAS;

try {
    $pdo = getDB();

    if ($q === '') {
        $filas = $pdo->query($base . $orden)->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Paso 1: igualdades, que usan índice: el documento (índice ciego), la habitación o el número de la cuenta.
        $soloNumeros = preg_match('/^\d{1,9}$/', $q) === 1;
        $stmt = $pdo->prepare($base . ' and (hu.documento_hash = ? or h.numero = ?' . ($soloNumeros ? ' or e.id = ?' : '') . ')' . $orden);
        $stmt->execute($soloNumeros ? [indiceCiego($q), $q, (int) $q] : [indiceCiego($q), $q]);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Paso 2: si no era nada de eso, por el nombre (completo, en los dos órdenes, o solo nombre o apellido).
        if (!$filas) {
            $comodin = '%' . $q . '%';
            $stmt = $pdo->prepare($base . " and (hu.nombres like ? or hu.apellidos like ?
                                             or concat_ws(' ', hu.nombres, hu.apellidos) like ?
                                             or concat_ws(' ', hu.apellidos, hu.nombres) like ?)" . $orden);
            $stmt->execute([$comodin, $comodin, $comodin, $comodin]);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    $resultados = [];
    foreach ($filas as $f) {
        $resultados[] = [
            'id'         => (int) $f['id'],
            'nombre'     => trim($f['nombres'] . ' ' . $f['apellidos']),
            'documento'  => documentoOculto(descifrar($f['documento_num'])),
            'habitacion' => (string) $f['habitacion'],
            'detalle'    => 'Cuenta #' . (int) $f['id'] . ' · salida ' . date('d/m/Y', strtotime((string) $f['fecha_checkout']))
                          . ' · $' . number_format((float) $f['total_pagar'], 0, ',', '.'),
        ];
    }
    echo json_encode(['resultados' => $resultados], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('Buscar cuentas por facturar: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'fallo']);
}
