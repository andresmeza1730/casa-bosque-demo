<?php
/**
 * Endpoint del asistente con IA
 * ============================================================
 *
 * El navegador manda aquí la pregunta; aquí se llama a Google y se devuelve
 * la respuesta en JSON. La clave de la API no sale nunca de este lado.
 *
 * Responde SIEMPRE con JSON, también cuando falla. Si algo va mal devuelve
 * {"ok":false} sin explicar por qué: el motivo real queda en el registro de
 * errores del servidor, no en la pantalla de un recepcionista. El navegador,
 * al ver ok:false, vuelve a la búsqueda por palabras clave de siempre.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/asistente_ia.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Respuesta y salida, en una línea. */
function iaSalir(array $datos, int $http = 200): void
{
    http_response_code($http);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Sesión ──
// No se usa requireLogin() a propósito: esa función redirige al login, y una
// redirección HTML en un endpoint JSON deja al navegador intentando leer una
// página como si fuera JSON. Aquí se responde 401 y se acabó.
if (!isset($_SESSION['usuario_id'])) {
    iaSalir(['ok' => false], 401);
}

// ── Método ──
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    iaSalir(['ok' => false], 405);
}

// ── Mismo origen ──
// La petición gasta cuota de la API, así que no debe poder dispararla otra
// página desde el navegador de un empleado con sesión abierta.
//
// Se compara con hash_equals contra el token de la sesión, pero SIN gastarlo:
// validateCsrf() de auth.php lo borra tras validarlo —correcto para un
// formulario que se envía una vez— y aquí el chat manda varios mensajes
// seguidos; el segundo fallaría siempre.
$token = $_POST['csrf_token'] ?? '';
if ($token === '' || empty($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $token)) {
    iaSalir(['ok' => false], 403);
}

// ── La pregunta ──
$pregunta = (string) ($_POST['q'] ?? '');
if (trim($pregunta) === '') {
    iaSalir(['ok' => false], 400);
}

$r = iaPreguntar($pregunta, isAdmin());

iaSalir($r['ok'] ? ['ok' => true, 'texto' => $r['texto']] : ['ok' => false]);
