<?php
// ============================================================
// verificar_2fa.php — Segundo factor de autenticación (TOTP)
// ============================================================
// Segunda etapa del login: se llega aquí cuando la contraseña fue
// correcta y el usuario tiene 2FA activo. Pide el código de 6 dígitos
// (o un código de respaldo) para completar la sesión.
//
// Sirve para DOS cosas:
//  - el ingreso (`2fa_pending`): termina de iniciar la sesión, y deja
//    «recordar este equipo» para no pedir el código en cada ingreso;
//  - confirmar el código para una acción crítica, estando ya dentro
//    (`2fa_escalar_destino`): lo pide exigirCodigoEnAccionCritica() a quien
//    entró por un equipo recordado y no tecleó el código. Ver
//    includes/equipos.php.
// ------------------------------------------------------------

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/login.php';
require_once __DIR__ . '/includes/totp.php';
require_once __DIR__ . '/includes/cifrado.php';
require_once __DIR__ . '/includes/equipos.php';

// Debe existir un login pendiente de 2FA o una confirmación pedida por el
// servidor para una acción crítica; si no, al inicio. El modo de confirmación
// NUNCA se activa por un parámetro de la URL: solo por la marca de sesión que
// pone exigirCodigoEnAccionCritica(), y solo estando ya dentro.
$modoEscalar = false;
if (empty($_SESSION['2fa_pending'])) {
    if (!empty($_SESSION['usuario_id']) && !empty($_SESSION['twofa_enabled'])
        && !empty($_SESSION['2fa_escalar_destino'])) {
        $modoEscalar = true;
    } else {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

// Cancelar la confirmación: se vuelve al inicio sin cerrar la sesión.
if ($modoEscalar && isset($_GET['cancelar'])) {
    unset($_SESSION['2fa_escalar_destino'], $_SESSION['2fa_escalar_time'], $_SESSION['2fa_intentos']);
    header('Location: ' . getHomeUrl());
    exit;
}

// El desafío expira a los 5 minutos.
$claveTiempo = $modoEscalar ? '2fa_escalar_time' : '2fa_pending_time';
if (time() - ($_SESSION[$claveTiempo] ?? 0) > 300) {
    if ($modoEscalar) {
        unset($_SESSION['2fa_escalar_destino'], $_SESSION['2fa_escalar_time'], $_SESSION['2fa_intentos']);
        header('Location: ' . getHomeUrl());
    } else {
        unset($_SESSION['2fa_pending'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_intentos']);
        header('Location: ' . BASE_URL . '/index.php?expiro=1');
    }
    exit;
}

$pdo = getDB();
$error = '';
$usarBackup = isset($_GET['backup']) || (($_POST['modo'] ?? '') === 'backup');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $error = 'Token de seguridad inválido. Intente de nuevo.';
    } else {
        $userId = $modoEscalar ? (int) $_SESSION['usuario_id'] : (int) $_SESSION['2fa_pending'];
        $stmt = $pdo->prepare("select id, nombre, email, rol, activo, twofa_enabled, twofa_secret from usuarios where id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !$user['activo'] || !$user['twofa_enabled']) {
            if ($modoEscalar) {
                // La cuenta cambió mientras tanto: se deja que auth.php decida.
                unset($_SESSION['2fa_escalar_destino'], $_SESSION['2fa_escalar_time'], $_SESSION['2fa_intentos']);
                header('Location: ' . getHomeUrl());
                exit;
            }
            unset($_SESSION['2fa_pending'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_intentos']);
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        }

        // Límite de intentos del código (anti fuerza bruta del 2FA).
        $_SESSION['2fa_intentos'] = ($_SESSION['2fa_intentos'] ?? 0) + 1;
        if ($_SESSION['2fa_intentos'] > 5) {
            // Queda en la auditoría como acceso BLOQUEADO (con tope por IP), a nombre de la cuenta.
            try {
                auditoriaRegistrarEventoAcceso($pdo, 'login_bloqueado',
                    'Segundo factor bloqueado tras 5 intentos' . ($modoEscalar ? ' (confirmación de una acción crítica)' : ''),
                    $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', $userId);
            } catch (Throwable $e) {
                error_log('Auditoría: no se pudo registrar el bloqueo del 2FA: ' . $e->getMessage());
            }
            if ($modoEscalar) {
                // Alguien con la sesión abierta no acertó el código en 5
                // intentos: se cierra la sesión entera, no solo el desafío.
                header('Location: ' . BASE_URL . '/modules/auth/logout.php');
                exit;
            }
            unset($_SESSION['2fa_pending'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_intentos']);
            header('Location: ' . BASE_URL . '/index.php?bloqueo2fa=1');
            exit;
        }

        $ok = false;
        if (($_POST['modo'] ?? '') === 'backup') {
            $ok = verificarYConsumirBackup($pdo, $userId, $_POST['backup_codigo'] ?? '');
        } else {
            $secret = descifrar($user['twofa_secret']);
            $ok = $secret !== null && totpVerificar($secret, $_POST['codigo'] ?? '');
        }

        if ($ok) {
            if ($modoEscalar) {
                $destino = (string) $_SESSION['2fa_escalar_destino'];
                $_SESSION['2fa_verificado_en'] = time();
                unset($_SESSION['2fa_escalar_destino'], $_SESSION['2fa_escalar_time'], $_SESSION['2fa_intentos']);
                registrarAuditoria($pdo, 'usuarios', $userId, 'codigo_accion_critica',
                    'Confirmó el código del 2FA para una acción crítica: ' . $destino);
                // El destino lo puso el servidor desde una lista blanca, pero
                // una redirección abierta es de lo que no se discute: se
                // comprueba otra vez que sea una ruta local.
                if ($destino === '' || $destino[0] !== '/' || strpos($destino, '//') === 0) {
                    $destino = getHomeUrl();
                }
                header('Location: ' . $destino);
                exit;
            }

            establecerSesionUsuario($user, true);
            registrarAuditoria($pdo, 'usuarios', $userId, 'login_2fa', 'Inicio de sesión con verificación 2FA');

            // «Recordar este equipo»: solo con el código de la app. Con un
            // código de respaldo NO se ofrece: perder el teléfono es justo
            // cuando no hay que dejar nada recordado.
            if (($_POST['modo'] ?? '') !== 'backup' && ($_POST['recordar_equipo'] ?? '') === '1') {
                equipoRecordar($pdo, $user);
            }
            header('Location: ' . getHomeUrl());
            exit;
        }
        // Un código equivocado también queda en la auditoría (el historial de conexiones lo muestra como «2FA fallido»).
        try {
            auditoriaRegistrar2faFallido($pdo, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', $userId,
                'Código del segundo factor incorrecto' . ($usarBackup ? ' (código de respaldo)' : '')
                . ($modoEscalar ? ' (confirmación de una acción crítica)' : ''));
        } catch (Throwable $e) {
            error_log('Auditoría: no se pudo registrar el 2FA fallido: ' . $e->getMessage());
        }
        $error = 'Código incorrecto. Te quedan ' . max(0, 6 - $_SESSION['2fa_intentos']) . ' intento(s).';
    }
}
$rem = max(0, 300 - (time() - ($_SESSION[$claveTiempo] ?? 0)));   // segundos restantes del desafío
// La casilla de «recordar» solo se ofrece con el código de la app, en el
// ingreso, y si la tabla existe: sin ella no haría nada.
$puedeRecordar = !$modoEscalar && !$usarBackup && equiposDisponible($pdo);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación en dos pasos — Casa Bosque</title>
    <link rel="icon" href="/favicon.svg?v=3" type="image/svg+xml">
    <link rel="preload" as="image" href="<?= BASE_URL ?>/bg-login.webp" fetchpriority="high">
    <meta name="theme-color" content="#6B4420">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Newsreader:opsz,wght@6..72,400..600&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined&amp;icon_names=arrow_back,arrow_forward,error,lock,logout,schedule,verified_user&amp;display=block" rel="stylesheet">
    <style>
      :root{ --pine-deep:#3E2412; --pine:#6B4420; --pine-light:#9C6728; --primary:#241607;
             --bg:#FBF9F4; --card:#FFFFFF; --low:#F7F2EA; --line:#EDE3D2; --text:#2A1709; --soft:#6E6054; --muted:#9A8778; }
      *{box-sizing:border-box;}
      body{font-family:'Outfit',system-ui,sans-serif;margin:0;min-height:100vh;min-height:100svh;display:flex;align-items:center;justify-content:center;padding:16px;color:var(--text);
           position:relative;isolation:isolate;background:#241607;-webkit-font-smoothing:antialiased;}
      /* FONDO. Es la foto que hasta ahora estaba en el login (bg-login.webp; la
         404 la sigue usando, por eso no se renombró).
         Es vertical y de 800 px de ancho: en un celular o una tableta en vertical
         llena la pantalla sin ampliarse y se ve nítida, pero en un monitor
         horizontal habría que estirarla más de dos veces y se vería pixelada. Ahí
         se desenfoca a propósito, para que la suavidad parezca decisión y no
         defecto, y la tarjeta —que sí es nítida— queda como lo único enfocado.
         `inset` negativo: el desenfoque deja un borde transparente en las orillas
         y así queda fuera de la pantalla. */
      .v2-fondo{position:fixed;inset:-28px;z-index:-2;background:#241607 url('<?= BASE_URL ?>/bg-login.webp') center 38%/cover no-repeat;}
      @media(min-width:800px){.v2-fondo{filter:blur(4px) saturate(.92);}}
      @media(min-width:1400px){.v2-fondo{filter:blur(7px) saturate(.92);}}
      /* El velo oscuro: sin él, el azul de la pared compite con la tarjeta. Es
         más denso hacia las orillas, así la mirada cae en el centro. */
      .v2-velo{position:fixed;inset:0;z-index:-1;background:radial-gradient(ellipse 85% 75% at 50% 46%, rgba(42,23,9,.46) 0%, rgba(36,22,7,.82) 100%);}
      /* En pantallas angostas la tarjeta tapa casi todo y solo asoman franjas
         arriba y abajo, justo donde un velo con las orillas oscuras se las come.
         Ahí la foto sí está nítida (no se amplía), así que se deja ver más. */
      @media(max-width:799px){.v2-velo{background:linear-gradient(180deg, rgba(42,23,9,.40) 0%, rgba(36,22,7,.66) 100%);}}
      .material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;vertical-align:middle;line-height:1;}
      .v2-card{position:relative;z-index:1;width:100%;max-width:420px;background:var(--card);border:1px solid rgba(255,255,255,.14);border-radius:18px;box-shadow:0 30px 80px rgba(0,0,0,.45), 0 2px 0 rgba(255,255,255,.04) inset;overflow:hidden;}
      .v2-banner{background:linear-gradient(135deg,var(--pine-deep),var(--pine-light));color:#fff;padding:18px 22px;display:flex;align-items:center;justify-content:space-between;}
      .v2-banner .l{display:flex;align-items:center;gap:12px;}
      .v2-banner .sh{width:40px;height:40px;border-radius:12px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;}
      .v2-banner .kick{font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;color:rgba(255,255,255,.7);font-weight:700;}
      .v2-banner .ttl{font-family:'Newsreader',serif;font-size:1.05rem;font-weight:600;margin-top:1px;}
      .v2-banner .pill{display:inline-flex;align-items:center;gap:5px;font-size:.68rem;font-weight:600;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);padding:4px 10px;border-radius:999px;}
      .v2-banner .pill .material-symbols-outlined{font-size:14px;}
      .v2-body{padding:26px 24px 24px;}
      .v2-body h1{font-family:'Newsreader',serif;font-size:1.4rem;font-weight:500;color:var(--text);margin:0 0 4px;letter-spacing:-.3px;}
      .v2-body .sub{color:var(--soft);font-size:.88rem;line-height:1.5;margin:0 0 20px;}
      .v2-err{display:flex;gap:9px;align-items:center;background:#fdecea;border:1px solid rgba(184,66,57,.2);color:#B84239;font-size:.85rem;padding:11px 13px;border-radius:11px;margin-bottom:16px;}
      .v2-err .material-symbols-outlined{font-size:18px;}
      .lbl-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
      .lbl-row .lbl{font-size:.66rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:700;}
      .lbl-row .exp{display:inline-flex;align-items:center;gap:4px;font-size:.76rem;color:var(--soft);}
      .lbl-row .exp .material-symbols-outlined{font-size:15px;color:var(--pine-light);}
      .boxes{display:grid;grid-template-columns:repeat(6,1fr);gap:9px;}
      .box{width:100%;aspect-ratio:1/1.15;text-align:center;font-size:1.5rem;font-weight:700;color:var(--text);border:1.5px solid var(--line);border-radius:12px;background:var(--low);outline:none;font-family:inherit;transition:border-color .15s, box-shadow .15s, background .15s;}
      .box:focus{border-color:var(--pine);background:#fff;box-shadow:0 0 0 3px rgba(107,68,32,.12);}
      .v2-code{width:100%;text-align:center;font-size:1.2rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase;padding:14px;background:var(--low);border:1.5px solid var(--line);border-radius:12px;color:var(--text);outline:none;font-family:inherit;}
      .v2-code:focus{border-color:var(--pine);background:#fff;box-shadow:0 0 0 3px rgba(107,68,32,.12);}
      .v2-btn{width:100%;margin-top:18px;padding:14px;background:var(--primary);color:#fff;font-weight:500;font-size:.95rem;border:none;border-radius:12px;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:7px;transition:background .2s;}
      .v2-btn:hover{background:var(--pine);}
      .v2-btn .material-symbols-outlined{font-size:18px;}
      .v2-link{display:inline-flex;align-items:center;gap:5px;justify-content:center;width:100%;margin-top:16px;color:var(--soft);font-size:.8rem;text-decoration:none;}
      .v2-link:hover{color:var(--pine);}
      .v2-link .material-symbols-outlined{font-size:16px;}
      .v2-foot{margin-top:20px;padding-top:16px;border-top:1px solid var(--line);text-align:center;}
      /* «Recordar este equipo». Va ARRIBA de las casillas del código: el formulario
         se envía solo al teclear el sexto dígito, así que una casilla puesta después
         no se alcanzaría a marcar. */
      .v2-rec{display:flex;gap:11px;align-items:flex-start;background:var(--low);border:1.5px solid var(--line);border-radius:12px;padding:12px 14px;margin:0 0 18px;cursor:pointer;transition:border-color .15s, background .15s;}
      .v2-rec:hover{border-color:var(--pine-light);}
      .v2-rec input{margin-top:3px;width:18px;height:18px;flex-shrink:0;accent-color:var(--pine);cursor:pointer;}
      .v2-rec b{display:block;font-size:.88rem;font-weight:600;color:var(--text);}
      .v2-rec small{display:block;margin-top:3px;font-size:.76rem;line-height:1.45;color:var(--soft);}
    </style>
</head>
<body>
    <div class="v2-fondo" aria-hidden="true"></div>
    <div class="v2-velo" aria-hidden="true"></div>
    <div class="v2-card">
        <div class="v2-banner">
            <div class="l">
                <span class="sh"><span class="material-symbols-outlined">verified_user</span></span>
                <div>
                    <div class="kick"><?= $modoEscalar ? 'Confirmación de seguridad' : 'Verificación en dos pasos' ?></div>
                    <div class="ttl"><?= $modoEscalar ? 'Acción protegida' : 'Paso 2 de 2 · Acceso del personal' ?></div>
                </div>
            </div>
            <span class="pill"><span class="material-symbols-outlined">lock</span> Seguro</span>
        </div>
        <div class="v2-body">
            <h1><?= $modoEscalar ? 'Confirma que eres tú' : 'Introduce el código de verificación' ?></h1>
            <p class="sub"><?php
                if ($usarBackup) {
                    echo 'Ingresa uno de tus códigos de respaldo de un solo uso.';
                } elseif ($modoEscalar) {
                    echo 'Esta sección es delicada: escribe el código de 6 dígitos de tu app de autenticación para continuar.';
                } else {
                    echo 'Escribe el código de 6 dígitos que muestra tu app de autenticación.';
                } ?></p>

            <?php if ($error): ?>
                <div class="v2-err"><span class="material-symbols-outlined">error</span> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!$usarBackup): ?>
            <?php if ($puedeRecordar): ?>
            <?php /* Fuera del <form> a propósito, para que quede encima de la etiqueta del
                     código; el atributo form="f2fa" la asocia al formulario igual, así que
                     viaja en el envío automático del sexto dígito. */ ?>
            <label class="v2-rec">
                <input type="checkbox" name="recordar_equipo" value="1" form="f2fa">
                <span>
                    <b>Recordar este equipo por <?= (int) EQUIPO_DIAS ?> días</b>
                    <small>Aquí no volverá a pedir el código, solo la contraseña. No lo marques en un equipo compartido o público.</small>
                </span>
            </label>
            <?php endif; ?>
            <div class="lbl-row">
                <span class="lbl">Código de 6 dígitos</span>
                <span class="exp"><span class="material-symbols-outlined">schedule</span> Expira en <span id="cd">--:--</span></span>
            </div>
            <form method="POST" id="f2fa">
                <?= csrfField() ?>
                <input type="hidden" name="modo" value="totp">
                <input type="hidden" name="codigo" id="codigo">
                <div class="boxes" id="boxes">
                    <input class="box" inputmode="numeric" maxlength="1" autocomplete="one-time-code" autofocus>
                    <input class="box" inputmode="numeric" maxlength="1">
                    <input class="box" inputmode="numeric" maxlength="1">
                    <input class="box" inputmode="numeric" maxlength="1">
                    <input class="box" inputmode="numeric" maxlength="1">
                    <input class="box" inputmode="numeric" maxlength="1">
                </div>
                <button type="submit" class="v2-btn">Verificar y entrar <span class="material-symbols-outlined">arrow_forward</span></button>
            </form>
            <a href="?backup=1" class="v2-link">¿Perdiste tu teléfono? Usa un código de respaldo</a>
            <?php else: ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="modo" value="backup">
                <input type="text" name="backup_codigo" required autofocus placeholder="Código de respaldo" class="v2-code">
                <button type="submit" class="v2-btn">Usar código de respaldo <span class="material-symbols-outlined">arrow_forward</span></button>
            </form>
            <a href="verificar_2fa.php" class="v2-link"><span class="material-symbols-outlined">arrow_back</span> Volver al código de la app</a>
            <?php endif; ?>

            <div class="v2-foot">
                <?php if ($modoEscalar): ?>
                <a href="verificar_2fa.php?cancelar=1" class="v2-link" style="margin-top:0;"><span class="material-symbols-outlined">arrow_back</span> Cancelar y volver al inicio</a>
                <?php else: ?>
                <a href="<?= BASE_URL ?>/modules/auth/logout.php" class="v2-link" style="margin-top:0;"><span class="material-symbols-outlined">logout</span> Cancelar e iniciar de nuevo</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php /* 4.2.2: «Procesando…» en el botón mientras el servidor revisa el código. */ ?>
    <script src="<?= BASE_URL ?>/assets/js/toast-spinner.js?v=<?= defined("APP_VERSION") ? htmlspecialchars(APP_VERSION) : "1" ?>"></script>
    <script>
    // Casillas de código: auto-avance, retroceso y pegado; combinan en #codigo.
    (function(){
      var boxes = Array.prototype.slice.call(document.querySelectorAll('.box'));
      if(!boxes.length) return;
      var hidden = document.getElementById('codigo'), form = document.getElementById('f2fa');
      function sync(){ hidden.value = boxes.map(function(b){return b.value;}).join(''); }
      boxes.forEach(function(b, i){
        b.addEventListener('input', function(){
          b.value = b.value.replace(/\D/g,'').slice(0,1);
          sync();
          if(b.value && i < boxes.length-1) boxes[i+1].focus();
          if(hidden.value.length === 6) form.requestSubmit ? form.requestSubmit() : form.submit();
        });
        b.addEventListener('keydown', function(e){
          if(e.key === 'Backspace' && !b.value && i > 0){ boxes[i-1].focus(); }
        });
        b.addEventListener('paste', function(e){
          e.preventDefault();
          var d = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
          for(var j=0;j<6;j++){ boxes[j].value = d[j] || ''; }
          sync(); (boxes[Math.min(d.length,5)]||boxes[5]).focus();
          if(hidden.value.length === 6) form.requestSubmit ? form.requestSubmit() : form.submit();
        });
      });
    })();
    // Cuenta regresiva del desafío (5 min). Al llegar a 0, reinicia el login.
    (function(){
      var el = document.getElementById('cd'); if(!el) return;
      var s = <?= (int)$rem ?>;
      function tick(){
        if(s <= 0){ window.location.href = '<?= $modoEscalar ? 'verificar_2fa.php?cancelar=1' : BASE_URL . '/index.php?expiro=1' ?>'; return; }
        var m = Math.floor(s/60), r = s%60;
        el.textContent = m + ':' + (r<10?'0':'') + r;
        s--; setTimeout(tick, 1000);
      }
      tick();
    })();
    </script>
</body>
</html>
