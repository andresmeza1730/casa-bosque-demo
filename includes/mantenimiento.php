<?php
// ============================================================
// includes/mantenimiento.php
// Modo mantenimiento total — Sistema Casa Bosque
// ============================================================
// Si existe el archivo flag, TODO el sistema muestra la pantalla
// de mantenimiento (incluidos administradores).
//
// ── CÓMO ACTIVAR ──
//   Crea el archivo:  config_privado/maintenance.flag
//   (puede estar vacío; basta con que exista)
//
// ── CÓMO DESACTIVAR ──
//   Borra el archivo: config_privado/maintenance.flag
//
// Se controla desde el File Manager / FTP, NO desde la app,
// para que el interruptor funcione aunque la base de datos o
// el login estén caídos. Es a prueba de fallos.
// ------------------------------------------------------------

define('MANTENIMIENTO_FLAG', __DIR__ . '/../config_privado/maintenance.flag');

// ── Bypass secreto OPCIONAL (desactivado por defecto) ──
// Permite que SOLO tú verifiques el sitio durante el mantenimiento,
// visitando cualquier página con ?acceso=TU_CLAVE_SECRETA
// Para habilitarlo: descomenta el bloque y cambia la clave.
// Mientras esté comentado, el mantenimiento es TOTAL (nadie entra).
/*
const MANTENIMIENTO_CLAVE_BYPASS = 'cambia_esta_clave_larga_y_secreta';
if (isset($_GET['acceso']) && hash_equals(MANTENIMIENTO_CLAVE_BYPASS, $_GET['acceso'])) {
    setcookie('mant_bypass', MANTENIMIENTO_CLAVE_BYPASS, time() + 3600, '/', '', true, true);
    $_COOKIE['mant_bypass'] = MANTENIMIENTO_CLAVE_BYPASS;
}
$tieneBypass = isset($_COOKIE['mant_bypass'])
    && hash_equals(MANTENIMIENTO_CLAVE_BYPASS, $_COOKIE['mant_bypass']);
*/
$tieneBypass = false;

if (file_exists(MANTENIMIENTO_FLAG) && !$tieneBypass) {
    http_response_code(503);
    header('Retry-After: 3600');
    header('Content-Type: text/html; charset=utf-8');
    // Evita que se cacheen las páginas durante el mantenimiento
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $rutaPantalla = __DIR__ . '/mantenimiento_pantalla.php';
    if (file_exists($rutaPantalla)) {
        require $rutaPantalla;
    } else {
        echo '<h1>Sitio en mantenimiento</h1><p>Volvemos pronto.</p>';
    }
    exit;
}
