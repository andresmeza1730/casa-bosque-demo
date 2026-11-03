<?php
// ============================================================
// includes/mantenimiento_pantalla.php
// Pantalla de mantenimiento — Casa Bosque
// ============================================================
// La carga includes/mantenimiento.php cuando el flag está activo, con un 503 y
// `Retry-After`. No debe llamarse directamente. Es autocontenida (a prueba de
// fallos): sin librerías ni iconos de fuera, sin base de datos. Los tipos de
// letra vienen de Google Fonts, con respaldo (Georgia y la fuente del sistema).
//
// REDISEÑO (3.10.1). La anterior era la tarjeta centrada de siempre, y repetía el
// mensaje del motor de reservas en línea («¿Ya tienes una reserva confirmada?
// … código CB-XXXXXX») que este sistema no tiene desde 3.7.1. Ahora:
//   - LA LLAVE. Una llave de habitación sobre una banda marrón: la llave está
//     «en el taller». Es el mismo mundo del letrero de puerta de caido.html y
//     de las barras de señal de offline.html, pero cada pantalla dice una cosa
//     distinta: «en pausa» (falló algo), «sin señal» (no hay red) y «en
//     mantenimiento» (lo hicimos a propósito).
//   - DICE LA VERDAD: el mantenimiento es TOTAL, nadie entra mientras dure —ni el
//     administrador—. Quien está en recepción lo necesita saber.
//   - NO ES SOLO UN DIBUJO: cada 30 segundos revisa si el sistema ya volvió y
//     entonces recarga sola. Mientras dura el mantenimiento toda página responde
//     503, y la prueba solo da por bueno un 200.
if (!defined('MANTENIMIENTO_FLAG')) {
    http_response_code(403);
    exit('Acceso no permitido.');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>En mantenimiento — Casa Bosque</title>
    <link rel="icon" href="/favicon.svg?v=3" type="image/svg+xml">
    <meta name="theme-color" content="#3E2412">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Newsreader:opsz,wght@6..72,400..600&family=Outfit:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
      *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
      :root{--tinta:#2A1709;--corteza:#3E2412;--cafe:#6B4420;--oro:#C8963C;--oro-claro:#E8CA8A;--crema:#FBF8F2;--arena:#EDE3D2;--apagado:#6F5F4E}
      html{-webkit-text-size-adjust:100%}
      body{min-height:100vh;min-height:100dvh;display:grid;grid-template-columns:minmax(260px,38%) minmax(0,1fr);font-family:'Outfit',system-ui,-apple-system,'Segoe UI',sans-serif;color:var(--tinta);line-height:1.6;background:var(--crema)}

      /* la banda de la llave */
      .banda{position:relative;overflow:hidden;color:var(--oro-claro);display:flex;flex-direction:column;justify-content:space-between;padding:clamp(22px,4vw,40px);
             background:radial-gradient(420px 320px at 30% 24%,rgba(200,150,60,.28),transparent 68%),linear-gradient(170deg,var(--corteza),#2A1709)}
      .marca{display:flex;align-items:center;gap:10px;color:#fff}
      .marca img{width:36px;height:36px;border-radius:10px;background:#ffffff14}
      .marca span{font-family:'Newsreader',Georgia,serif;font-size:1.25rem}
      .llave{align-self:center;width:min(250px,60%);margin:18px 0;animation:cuelga 6s ease-in-out infinite;transform-origin:20% 30%}
      .llave svg{display:block;width:100%;height:auto;filter:drop-shadow(0 18px 20px rgba(0,0,0,.35))}
      @keyframes cuelga{0%,100%{transform:rotate(-3deg)}50%{transform:rotate(3deg)}}
      .banda small{font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;color:#CDB894}

      /* el mensaje */
      main{display:flex;flex-direction:column;justify-content:center;padding:clamp(28px,6vw,72px) clamp(22px,6vw,72px);max-width:44rem}
      .eyebrow{display:inline-flex;align-items:center;gap:10px;font-size:.78rem;letter-spacing:.1em;text-transform:uppercase;font-weight:600;color:var(--cafe)}
      .eyebrow::before{content:'';width:28px;height:2px;background:var(--oro)}
      h1{font-family:'Newsreader',Georgia,serif;font-weight:500;font-size:clamp(2.1rem,5.6vw,3.3rem);line-height:1.05;letter-spacing:-.02em;color:var(--corteza);margin:14px 0 16px}
      .lead{font-size:1.08rem;color:var(--apagado);max-width:34rem;margin-bottom:12px}
      .total{display:flex;gap:12px;align-items:flex-start;background:#F6EFE2;border-left:4px solid var(--cafe);border-radius:12px;padding:13px 16px;margin:6px 0 26px;font-size:.96rem;max-width:34rem}
      .total b{color:var(--corteza);font-weight:600}
      .estado{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-bottom:30px}
      .vivo{display:inline-flex;align-items:center;gap:10px;font-size:.95rem;background:#fff;border:1px solid var(--arena);border-radius:99px;padding:8px 16px}
      .punto{width:10px;height:10px;border-radius:50%;background:var(--oro);animation:pulso 1.6s ease-in-out infinite}
      .vivo.ok .punto{background:#2D7A4D;animation:none}
      @keyframes pulso{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.35;transform:scale(.7)}}
      .btn{min-height:46px;padding:0 22px;border-radius:12px;border:1px solid var(--corteza);background:var(--corteza);color:#fff;font:600 .95rem 'Outfit',system-ui,sans-serif;cursor:pointer}
      .btn:hover{background:var(--cafe)}
      .btn[disabled]{opacity:.7;cursor:progress}
      :focus-visible{outline:3px solid var(--oro);outline-offset:3px}
      .mientras{border-top:1px solid var(--arena);padding-top:20px;max-width:34rem}
      .mientras h2{font-size:.78rem;letter-spacing:.1em;text-transform:uppercase;color:var(--apagado);font-weight:600;margin-bottom:10px}
      .mientras ol{list-style:none;counter-reset:m;display:flex;flex-direction:column;gap:10px}
      .mientras li{counter-increment:m;position:relative;padding-left:38px;font-size:.96rem}
      .mientras li::before{content:counter(m);position:absolute;left:0;top:1px;width:26px;height:26px;border-radius:50%;background:var(--arena);color:var(--corteza);font:600 .85rem 'Outfit',system-ui,sans-serif;display:flex;align-items:center;justify-content:center}
      .mientras b{font-weight:600;color:var(--corteza)}

      @media(max-width:820px){
        body{grid-template-columns:1fr;grid-template-rows:auto 1fr}
        .banda{flex-direction:row;align-items:center;padding:16px 22px;gap:14px}
        .llave{width:76px;margin:0 0 0 auto}
        .banda small{display:none}
        main{padding-top:30px}
      }
      @media(prefers-reduced-motion:reduce){.llave,.punto{animation:none}}
    </style>
</head>
<body>
    <aside class="banda">
        <div class="marca"><img src="/assets/img/emblema-48.png?v=3" alt="" width="36" height="36"><span>Casa Bosque</span></div>
        <div class="llave" aria-hidden="true">
            <svg viewBox="0 0 260 260" xmlns="http://www.w3.org/2000/svg">
                <g transform="rotate(38 130 130)">
                    <circle cx="56" cy="130" r="34" fill="none" stroke="#C8963C" stroke-width="10"/>
                    <circle cx="56" cy="130" r="10" fill="#C8963C"/>
                    <rect x="88" y="124" width="150" height="12" rx="6" fill="#C8963C"/>
                    <path d="M196 136 v26 h14 v-26 M220 136 v18 h14 v-18" fill="#C8963C"/>
                    <circle cx="56" cy="130" r="48" fill="none" stroke="#C8963C" stroke-opacity=".25" stroke-width="2"/>
                </g>
            </svg>
        </div>
        <small>Sistema de gestión</small>
    </aside>

    <main>
        <span class="eyebrow">Mantenimiento en curso</span>
        <h1>El sistema está en mantenimiento</h1>
        <p class="lead">Estamos haciendo ajustes y refuerzos de seguridad. Cuando terminemos, el sistema vuelve a abrir. La información guardada sigue ahí, protegida y cifrada.</p>
        <div class="total"><span><b>Mientras dure, nadie puede entrar</b>, tampoco el administrador: es un mantenimiento total.</span></div>

        <div class="estado">
            <span class="vivo" id="vivo" role="status" aria-live="polite"><span class="punto" aria-hidden="true"></span><span id="txt">Revisando si ya volvimos…</span></span>
            <button class="btn" type="button" id="otra">Revisar ahora</button>
        </div>

        <section class="mientras" aria-labelledby="mientras-t">
            <h2 id="mientras-t">Si estás en recepción</h2>
            <ol>
                <li><b>Sigue atendiendo.</b> Anota a mano las entradas, salidas, consumos y pagos.</li>
                <li><b>Cuando el sistema vuelva,</b> registra lo que anotaste.</li>
                <li><b>Esta página se actualiza sola</b> en cuanto volvamos; no hace falta que la recargues.</li>
            </ol>
        </section>
    </main>

<script>
(function () {
  var vivo = document.getElementById('vivo'), txt = document.getElementById('txt'), boton = document.getElementById('otra');
  var ocupado = false;

  /* ¿Ya volvió? Mientras dura el mantenimiento TODA página responde 503, así que solo un 200 cuenta.
     Número distinto cada vez y sin caché, para que nada conteste por el sitio. */
  function yaVolvio() {
    return fetch('/acceso.php?ping=' + Date.now(), { cache: 'no-store', credentials: 'omit' }).then(function (r) { return r.ok; }, function () { return false; });
  }
  function hora() { var d = new Date(); return d.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' }); }
  function revisar(manual) {
    if (ocupado) return;
    ocupado = true;
    if (manual) { boton.disabled = true; txt.textContent = 'Revisando…'; }
    yaVolvio().then(function (ok) {
      ocupado = false; boton.disabled = false;
      if (ok) {
        vivo.className = 'vivo ok';
        txt.textContent = '¡Ya volvimos! Abriendo el sistema…';
        setTimeout(function () { location.reload(); }, 700);
      } else {
        txt.textContent = 'Todavía en mantenimiento · revisado a las ' + hora();
      }
    });
  }
  boton.addEventListener('click', function () { revisar(true); });
  setInterval(function () { revisar(false); }, 30000);
})();
</script>
</body>
</html>
