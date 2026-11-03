<?php
// ============================================================
// acceso.php — Acceso del personal (puerta de entrada del sistema)
// ============================================================
// El sistema es una herramienta de control interno: la raíz del
// sitio abre esta pantalla de login del personal (no el sitio
// público de reservas). Reutiliza el flujo de login + 2FA.
// ------------------------------------------------------------
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/login_throttle.php';
require_once __DIR__ . '/includes/login.php';

// Ya autenticado → a su panel según el rol.
if (isset($_SESSION['usuario_id'])) {
    header('Location: ' . getHomeUrl());
    exit;
}

$pdo = getDB();
$loginError = '';

// Avisos de por qué se cerró la sesión anterior.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $porQue = $_GET['sesion'] ?? '';
    if ($porQue === 'expirada') {
        $loginError = 'Tu sesión se cerró por inactividad. Inicia sesión de nuevo.';
    } elseif ($porQue === 'desactivada') {
        // Llega aquí cuando la cuenta se desactivó mientras tenía la sesión
        // abierta. No se dice más de la cuenta: quien lo lea ya sabe quién es.
        $loginError = 'Tu cuenta está desactivada. Habla con el administrador.';
    } elseif ($porQue === 'jornada') {
        // Tope absoluto de duración: la sesión llevaba abierta más de
        // SESION_MAXIMA_HORAS, aunque se estuviera usando.
        $loginError = 'Tu sesión alcanzó la duración máxima permitida. Inicia sesión de nuevo.';
    } elseif ($porQue === 'cerrada') {
        // Llega aquí cuando la sesión se invalidó desde otro sitio: por un
        // cambio de contraseña o porque se cerró a mano desde Seguridad.
        $loginError = 'Tu sesión se cerró. Si cambiaste tu contraseña, entra con la nueva.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $loginError = 'Token de seguridad inválido. Intente de nuevo.';
    } else {
        $email    = trim($_POST['login_email'] ?? '');
        $password = $_POST['login_password'] ?? '';
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($email === '' || $password === '') {
            $loginError = 'Correo y contraseña son obligatorios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $loginError = 'El correo no tiene un formato válido.';
        } else {
            $min = minutosBloqueoRestante($pdo, $email, $ip);
            if ($min > 0) {
                $loginError = "Demasiados intentos fallidos. Intente de nuevo en {$min} minuto(s).";
                // Un acceso frenado también queda en la auditoría (con tope por IP: quien insiste no la llena),
                // a nombre del dueño del correo si existe: es la señal que ve en su historial de conexiones.
                try {
                    $stDueno = $pdo->prepare("select id from usuarios where email = ?");
                    $stDueno->execute([$email]);
                    $duenoId = $stDueno->fetchColumn();
                    auditoriaRegistrarBloqueoLogin($pdo, $email, $ip, $duenoId !== false ? (int) $duenoId : null, $min);
                } catch (Throwable $e) {
                    error_log('Auditoría: no se pudo registrar el acceso bloqueado: ' . $e->getMessage());
                }
            } else {
                $stmt = $pdo->prepare("select id, nombre, email, password_hash, rol, activo, twofa_enabled from usuarios where email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                // ── Contra la enumeración de usuarios por tiempo de respuesta ──
                // Si el correo no existe, se verifica igual contra un hash
                // señuelo. Sin esto, un correo inexistente responde al instante
                // y uno registrado tarda lo que tarda el algoritmo (~60 ms con
                // bcrypt, más con Argon2id): midiendo la diferencia se puede
                // averiguar qué correos tienen cuenta, sin acertar ni una
                // contraseña.
                //
                // El señuelo se CALCULA, y solo cuando hace falta. Antes era un
                // bcrypt de coste 10 escrito a mano, y eso se quedó corto en
                // cuanto las contraseñas pasaron a Argon2id: un señuelo de
                // bcrypt responde más rápido que una contraseña real de
                // Argon2id, así que la diferencia de tiempo volvía a delatar
                // qué correos existen. Calcularlo con claveAlgoritmo() hace que
                // el señuelo cueste siempre lo mismo que lo real, cualquiera
                // que sea el algoritmo en vigor.
                //
                // Sobre 16 bytes aleatorios que nadie conoce: ninguna
                // contraseña puede coincidir con él.
                $hashSenuelo = $user ? '' : claveHash(bin2hex(random_bytes(16)));
                $claveCorrecta = password_verify($password, $user['password_hash'] ?? $hashSenuelo);

                if ($user && $user['activo'] && $claveCorrecta) {
                    registrarIntentoLogin($pdo, $email, $ip, true);
                    limpiarIntentosLogin($pdo, $email);
                    // Si la contraseña estaba guardada con un algoritmo
                    // anterior, se reescribe AHORA, que es el único momento en
                    // que se tiene en claro. Nadie tiene que cambiar nada.
                    // Va antes del 2FA porque también vuelve a firmar los
                    // equipos recordados, y equipoConfiable() los lee después.
                    require_once __DIR__ . '/includes/equipos.php';
                    claveRehashSiHaceFalta($pdo, (int) $user['id'], $password, (string) $user['password_hash']);
                    // Completa el login o exige el segundo factor (2FA). Hace exit.
                    iniciarSesionConPosible2FA($user);
                } else {
                    registrarIntentoLogin($pdo, $email, $ip, false);
                    // Además del control de fuerza bruta (intentos_login, que se borra al
                    // entrar bien), el fallo queda en la auditoría: es lo que permite ver
                    // los picos de intentos fallidos después. Con tope por IP.
                    try {
                        // Con la cuenta desactivada se distingue AQUÍ, en la auditoría; la pantalla dice lo mismo.
                        auditoriaRegistrarFalloLogin($pdo, $email, $ip, $user ? (int) $user['id'] : null, $user && !$user['activo']);
                    } catch (Throwable $e) {
                        error_log('Auditoría: no se pudo registrar el intento fallido: ' . $e->getMessage());
                    }
                    $loginError = 'Credenciales inválidas o cuenta desactivada.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Acceso del personal — Casa Bosque</title>
<link rel="icon" href="<?= BASE_URL ?>/favicon.svg?v=3" type="image/svg+xml">
<meta name="theme-color" content="#6B4420">
<link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
<link rel="apple-touch-icon" href="<?= BASE_URL ?>/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Casa Bosque">
<meta name="mobile-web-app-capable" content="yes">
<link rel="preload" as="image" href="<?= BASE_URL ?>/assets/img/fachada-login.webp" fetchpriority="high">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Newsreader:opsz,wght@6..72,400..600&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined&amp;icon_names=error,forest,lock,login,mail,shield&amp;display=block" rel="stylesheet">
<style>*{box-sizing:border-box;margin:0;padding:0}
/* Nombres heredados del tema verde. Los valores ya son los del logo (marron/oro). */
:root{--pine-deep:#3E2412;--pine:#6B4420;--pine-light:#9C6728;--primary:#3E2412;--surface:#F4F1E9;--line:#E7E2D8;--ink:#2A1709;--ink-2:#6E6054;--muted:#9A8778;--error:#B84239}
body{font-family:'Outfit',system-ui,sans-serif;min-height:100vh;min-height:100svh;display:grid;place-items:center;padding:16px;color:var(--ink);background:radial-gradient(ellipse at 15% 15%,#fff 0,transparent 48%),var(--surface);-webkit-font-smoothing:antialiased}
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;vertical-align:middle;line-height:1}
.shell{width:min(1160px,100%);height:min(760px,calc(100svh - 32px));min-height:0;display:grid;grid-template-columns:1.08fr .92fr;background:#fff;border:1px solid rgba(62,36,18,.08);border-radius:28px;overflow:hidden;box-shadow:0 30px 90px rgba(62,36,18,.18)}
.visual{position:relative;display:flex;align-items:flex-end;min-height:0;padding:clamp(28px,4vw,56px);color:#fff;background:linear-gradient(180deg,rgba(42,23,9,.04) 28%,rgba(42,23,9,.76) 100%),url('assets/img/fachada-login.webp') 68% 40%/cover no-repeat}
.visual:before{content:'';position:absolute;inset:20px;border:1px solid rgba(255,255,255,.27);border-radius:18px;pointer-events:none}
.visual-copy{position:relative;max-width:440px;padding-bottom:4px;text-shadow:0 2px 18px rgba(0,0,0,.24)}
.brand-mark{display:inline-flex;align-items:center;gap:9px;margin-bottom:20px;font-size:.72rem;font-weight:600;letter-spacing:.16em;text-transform:uppercase;color:rgba(255,255,255,.9)}
.brand-mark .material-symbols-outlined{font-size:21px}
.visual h1{font-family:'Newsreader',Georgia,serif;font-size:clamp(2.7rem,5vw,4.5rem);font-weight:500;letter-spacing:-.045em;line-height:.98}
.visual-copy p{max-width:340px;margin-top:16px;font-size:1rem;line-height:1.65;color:rgba(255,255,255,.88)}
.login-panel{display:flex;align-items:center;justify-content:center;padding:clamp(38px,6vw,82px) clamp(30px,5vw,72px);background:rgba(255,255,255,.98)}
.login-content{width:100%;max-width:390px}
.kicker{font-size:.72rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--pine-light)}
.login-content h2{margin-top:11px;font-family:'Newsreader',Georgia,serif;font-size:clamp(2rem,3vw,2.65rem);font-weight:500;letter-spacing:-.035em;color:var(--pine-deep)}
.intro{margin-top:9px;margin-bottom:30px;font-size:.94rem;line-height:1.6;color:var(--ink-2)}
.err{display:flex;gap:10px;align-items:flex-start;margin-bottom:18px;padding:12px 14px;border:1px solid rgba(184,66,57,.2);border-radius:12px;background:#fff3f1;color:var(--error);font-size:.85rem;line-height:1.45}
.err .material-symbols-outlined{flex:none;font-size:19px}
.field{margin-bottom:17px}
.field label{display:block;margin-bottom:7px;font-size:.8rem;font-weight:600;color:var(--ink-2)}
.wrap{position:relative}
.wrap .material-symbols-outlined{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#8A7A68;font-size:20px}
.wrap input{width:100%;height:52px;padding:0 15px 0 46px;border:1px solid #E4D9C6;border-radius:12px;background:#fff;color:var(--ink);font:400 .94rem 'Outfit',system-ui,sans-serif;outline:none;transition:border-color .18s,box-shadow .18s}
.wrap input::placeholder{color:#AE9F8C}
.wrap input:focus{border-color:var(--pine-light);box-shadow:0 0 0 4px rgba(156,103,40,.12)}
.btn{width:100%;min-height:52px;margin-top:7px;padding:13px 16px;border:0;border-radius:12px;background:var(--primary);color:#fff;font:600 .95rem 'Outfit',system-ui,sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:9px;box-shadow:0 8px 18px rgba(62,36,18,.16);transition:transform .18s,background .18s,box-shadow .18s}
.btn:hover{background:var(--pine-light);box-shadow:0 10px 22px rgba(62,36,18,.22);transform:translateY(-1px)}
.btn:focus-visible{outline:3px solid rgba(156,103,40,.3);outline-offset:3px}
.btn .material-symbols-outlined{font-size:20px}
.foot{display:flex;align-items:center;justify-content:center;gap:7px;margin-top:25px;padding-top:18px;border-top:1px solid #EDF0EB;text-align:center;font-size:.77rem;color:var(--muted)}
.foot .material-symbols-outlined{font-size:17px;color:var(--pine-light)}
@media(max-width:820px){body{padding:12px}.shell{grid-template-columns:1fr .95fr;height:min(700px,calc(100svh - 24px));min-height:0;border-radius:22px}.visual{min-height:0;padding:28px}.login-panel{min-height:0;padding:24px 24px}.intro{margin-bottom:20px}}
@media(max-width:640px){body{display:block;height:100svh;min-height:0;overflow:hidden;padding:0;background:#fff}.shell{width:100%;height:100%;min-height:0;display:flex;flex-direction:column;border:0;border-radius:0;box-shadow:none}.visual{flex:0 0 clamp(160px,31svh,260px);min-height:0;height:auto;max-height:none;padding:24px 28px;background-position:center 43%}.visual:before{inset:10px;border-radius:13px}.brand-mark{margin-bottom:8px;font-size:.64rem}.visual h1{font-size:2.5rem}.visual-copy p{margin-top:6px;font-size:.84rem}.login-panel{flex:1;min-height:0;align-items:center;overflow:hidden;padding:20px 27px}.login-content{max-width:440px;margin:0 auto}.login-content h2{font-size:2rem}.intro{margin:7px 0 18px}.field{margin-bottom:13px}.wrap input{height:48px}.btn{min-height:48px}.foot{margin-top:17px;padding-top:12px}}
@media(max-width:380px){.visual{flex-basis:clamp(145px,28svh,205px);min-height:0}.login-panel{padding:16px 21px}.visual h1{font-size:2.15rem}.intro{margin-bottom:14px}.field{margin-bottom:11px}.wrap input{height:45px}.btn{min-height:46px}.foot{margin-top:13px;padding-top:10px}}@media(max-height:620px) and (max-width:640px){.visual{flex-basis:145px;padding:18px 25px}.visual h1{font-size:1.9rem}.visual-copy p{display:none}.login-panel{padding:12px 22px}.login-content h2{margin-top:5px;font-size:1.75rem}.intro{margin:4px 0 10px;font-size:.85rem}.field{margin-bottom:8px}.field label{margin-bottom:4px}.wrap input{height:41px}.btn{min-height:43px;margin-top:3px}.foot{margin-top:9px;padding-top:7px;font-size:.7rem}}</style>
<style>
/* Las habitaciones forman el fondo exterior; la tarjeta conserva su diseño original. */
body{position:relative;isolation:isolate}
.page-mosaic{position:fixed;z-index:-2;inset:0;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));grid-template-rows:repeat(2,minmax(0,1fr));gap:5px;padding:5px;background:#241607}
.page-mosaic img{width:100%;height:100%;min-width:0;min-height:0;object-fit:cover;filter:saturate(.82)}
.page-mosaic-shade{position:fixed;z-index:-1;inset:0;background:rgba(42,23,9,.52)}
.shell{position:relative;z-index:1}
@media(max-width:640px){.page-mosaic{grid-template-columns:repeat(2,minmax(0,1fr));grid-template-rows:repeat(3,minmax(0,1fr));gap:4px;padding:4px}}
</style>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/avisos-ui.css?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>">
</head><body>
<div class="page-mosaic" aria-hidden="true">
  <img src="<?= BASE_URL ?>/assets/habitaciones/habitacion-01.webp" alt="">
  <img src="<?= BASE_URL ?>/assets/habitaciones/habitacion-02.webp" alt="">
  <img src="<?= BASE_URL ?>/assets/habitaciones/habitacion-03.webp" alt="">
  <img src="<?= BASE_URL ?>/assets/habitaciones/habitacion-04.webp" alt="">
  <img src="<?= BASE_URL ?>/assets/habitaciones/habitacion-05.webp" alt="">
  <img src="<?= BASE_URL ?>/assets/habitaciones/habitacion-06.webp" alt="">
</div>
<div class="page-mosaic-shade" aria-hidden="true"></div>
<main class="shell">
  <section class="visual" aria-label="Casa Bosque, vista exterior del hospedaje">
    <div class="visual-copy">
      <div class="brand-mark"><img src="/assets/img/emblema-48.png?v=3" alt="" width="22" height="22" style="display:block"> Hospedaje</div>
      <h1>Casa<br>Bosque</h1>
      <p>Un espacio tranquilo para descansar y sentirse en casa.</p>
    </div>
  </section>
  <section class="login-panel" aria-labelledby="login-title">
    <div class="login-content">
      <div class="kicker">Acceso del personal</div>
      <h2 id="login-title">Bienvenido de vuelta</h2>
      <p class="intro">Ingresa tus datos para continuar al sistema de gestión.</p>
      <?php if ($loginError): ?>
        <div class="err" role="alert"><span class="material-symbols-outlined" aria-hidden="true">error</span><span><?= htmlspecialchars($loginError) ?></span></div>
      <?php endif; ?>
      <form method="POST" action="acceso.php">
        <?= csrfField() ?>
        <input type="hidden" name="accion" value="login">
        <div class="field">
          <label for="login_email">Correo electrónico</label>
          <div class="wrap"><span class="material-symbols-outlined" aria-hidden="true">mail</span>
            <input type="email" name="login_email" id="login_email" required maxlength="150" placeholder="correo@ejemplo.com" autocomplete="username" value="<?= htmlspecialchars($_POST['login_email'] ?? '') ?>" autofocus>
          </div>
        </div>
        <div class="field">
          <label for="login_password">Contraseña</label>
          <div class="wrap"><span class="material-symbols-outlined" aria-hidden="true">lock</span>
            <input type="password" name="login_password" id="login_password" required placeholder="Tu contraseña" autocomplete="current-password">
          </div>
        </div>
        <button type="submit" class="btn"><span class="material-symbols-outlined" aria-hidden="true">login</span> Iniciar sesión</button>
      </form>
      <div class="foot"><span class="material-symbols-outlined" aria-hidden="true">shield</span> Acceso exclusivo del personal · Casa Bosque · v<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "?" ?><?php if (esEntornoDemostracion()): ?> · <b style="color:#b45309;">ENTORNO DE DEMOSTRACIÓN · datos ficticios</b><?php endif; ?></div>
    </div>
  </section>
</main>
<!-- 4.2.0: la notificación y los campos obligatorios (esta pantalla no carga el pie de página compartido). -->
<script src="<?= BASE_URL ?>/assets/js/avisos-ui.js?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/campos-obligatorios.js?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>"></script>
<?php /* 4.2.2: «Procesando…» en el botón mientras el servidor revisa la contraseña (la espera de verdad: Argon2id y la base). Va al final, como en el pie de página. */ ?>
<script src="<?= BASE_URL ?>/assets/js/toast-spinner.js?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>"></script>
</body>
</html>
