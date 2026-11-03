<?php
// ============================================================
// includes/modulo_retirado.php
// Pantalla "Módulo no disponible" — Casa Bosque
// ============================================================
// Corta la ejecución de una pantalla que quedó fuera del alcance
// definido en los requerimientos (HCB_RF_001 a HCB_RF_010).
//
// ── Por qué cortar y no borrar ──
// Quitar el enlace del menú no basta: la URL sigue respondiendo y
// cualquiera que la tenga guardada, o que la escriba, entra igual. Esto
// cierra la puerta de verdad.
//
// Y no se borra el archivo porque el módulo puede volver: cuando se
// implemente, se quita el require de su primera línea y queda como
// estaba. El código, sus consultas y su maquetación siguen intactos.
//
// ── Dónde va ──
// Después de requireLogin() o requireAdmin(), nunca antes: así quien no
// tiene sesión sigue yendo al acceso en vez de ver esta pantalla, y el
// control de permisos de cada módulo se mantiene tal cual.
//
// ── Uso ──
//   require_once __DIR__ . '/../../includes/modulo_retirado.php';
//   moduloRetirado('Plan de contingencia', 'La facturación electrónica…');
// ------------------------------------------------------------

/**
 * Muestra la pantalla y termina la petición.
 *
 * @param string $nombre  Cómo se llamaba el módulo en el menú.
 * @param string $motivo  Una frase explicando por qué no está. Se le
 *                        enseña al usuario, así que se escribe en su
 *                        idioma, no en términos del código.
 */
function moduloRetirado(string $nombre, string $motivo): void
{
    // 404 y no 403: para quien llega sin saber, el módulo sencillamente
    // no existe en este sistema. Un 403 sugeriría que existe y que le
    // falta permiso, que no es el caso.
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');

    $inicio = (defined('BASE_URL') ? BASE_URL : '') . '/dashboard.php';
    ?>
    <!doctype html>
    <html lang="es">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($nombre) ?> — Casa Bosque</title>
    <link rel="icon" href="<?= defined('BASE_URL') ? BASE_URL : '' ?>/favicon.svg?v=3" type="image/svg+xml">
    <style>
      /* Nombres heredados del tema verde. Los valores ya son los del logo (marron/oro). */
      :root{ --pine-deep:#3E2412; --pine:#6B4420; --bg:#FBF9F4; --card:#FFFFFF;
             --line:#E7E2D8; --ink:#2A1709; --soft:#6E6054; --muted:#9A8778; }
      *{ box-sizing:border-box; margin:0; padding:0; }
      body{ font-family:'Segoe UI',system-ui,-apple-system,sans-serif; background:var(--bg);
            color:var(--ink); min-height:100vh; display:flex; align-items:center;
            justify-content:center; padding:24px; -webkit-font-smoothing:antialiased; }
      .mr-card{ width:100%; max-width:470px; background:var(--card); border:1px solid var(--line);
                border-radius:18px; box-shadow:0 18px 44px rgba(62,36,18,.10); overflow:hidden; }
      .mr-head{ background:linear-gradient(135deg,var(--pine-deep),var(--pine));
                color:#fff; padding:22px 26px; }
      .mr-head .kick{ font-size:.64rem; letter-spacing:.17em; text-transform:uppercase;
                      color:rgba(255,255,255,.72); font-weight:700; }
      .mr-head h1{ font-size:1.22rem; font-weight:600; margin-top:3px; }
      .mr-body{ padding:24px 26px 26px; }
      .mr-body p{ color:var(--soft); font-size:.93rem; line-height:1.6; }
      .mr-body p + p{ margin-top:11px; }
      .mr-nota{ margin-top:18px; padding:12px 14px; background:#F5F3EE;
                border-radius:11px; font-size:.83rem; color:var(--muted); line-height:1.55; }
      .mr-btn{ display:inline-flex; align-items:center; gap:8px; margin-top:20px;
               background:var(--pine); color:#fff; text-decoration:none; font-weight:600;
               font-size:.92rem; padding:12px 20px; border-radius:11px; }
      .mr-btn:hover{ background:var(--pine-deep); }
    </style>
    </head>
    <body>
      <div class="mr-card">
        <div class="mr-head">
          <div class="kick">Módulo no disponible</div>
          <h1><?= htmlspecialchars($nombre) ?></h1>
        </div>
        <div class="mr-body">
          <p><?= htmlspecialchars($motivo) ?></p>
          <p>No forma parte del alcance definido para esta versión del sistema, así que
             no aparece en el menú ni está operativo.</p>
          <div class="mr-nota">
            Si más adelante se decide incluirlo, el módulo vuelve sin rehacer nada:
            el código sigue en el sistema, solo está desactivado.
          </div>
          <a class="mr-btn" href="<?= htmlspecialchars($inicio) ?>">Volver al inicio</a>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}
