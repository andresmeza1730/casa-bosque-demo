<?php
// ============================================================
// index.php — Punto de entrada
// ============================================================
// index.php queda como una simple redirección. El sistema es una
// herramienta de control interno, así que la raíz abre el acceso del
// personal (no el sitio público de reservas):
//   - Con sesión activa → al panel según el rol.
//   - Sin sesión         → a la pantalla de acceso del personal (acceso.php).
// Ya no renderiza HTML, por lo que no depende de ningún CDN.
// ------------------------------------------------------------

require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['usuario_id'])) {
    header('Location: ' . getHomeUrl());
} else {
    header('Location: ' . BASE_URL . '/acceso.php');
}
exit;
