<?php
define('PAGINA_SIN_2FA_GATE', true);   // permitir cerrar sesión aunque falte activar 2FA
require_once __DIR__ . '/../../includes/auth.php';

// Invalidar la cookie de sesión
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

// Marcar esta sesion como cerrada en el registro antes de destruirla.
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/sesiones.php';
try { sesionCerrarActual(getDB(), 'logout'); } catch (Throwable $e) {}
// La constancia va ANTES de destruir la sesión: después ya no se sabría quién salió.
// Solo si de verdad había alguien dentro (esta página se puede abrir sin sesión).
if (getCurrentUserId() !== null) {
    try { registrarAuditoria(getDB(), 'usuarios', getCurrentUserId(), 'logout', 'Cierre de sesión'); } catch (Throwable $e) {}
}

session_unset();
session_destroy();

// La sesión ya quedó invalidada en el servidor: no queda nada que esperar, así que se vuelve al acceso de una vez. Hasta 4.2.1 aquí había una
// pantalla «Cerrando tu sesión de forma segura…» con una barra de 1,2 s y un temporizador: el cierre ya había terminado antes de pintarla y la
// espera no era de nada (Andrés: «no quiero transiciones falsas»).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . BASE_URL . '/acceso.php');
exit;
