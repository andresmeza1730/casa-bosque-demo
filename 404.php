<?php
// Página pública de error. No inicia sesión ni aplica restricciones de acceso.
http_response_code(404);

// Conserva el soporte para instalaciones en la raíz o en una subcarpeta.
$scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/404.php'));
$baseUrl = rtrim(dirname($scriptPath), '/');
if ($baseUrl === '.' || $baseUrl === '/') {
    $baseUrl = '';
}
$baseUrlHtml = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#3E2412">
    <title>404 — Página no encontrada | Casa Bosque</title>
    <link rel="icon" href="<?= $baseUrlHtml ?>/favicon.svg?v=3" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Newsreader:opsz,wght@6..72,400..600&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Nombres heredados del tema verde. Los valores ya son los del logo (marron/oro). */
        :root{--pine-deep:#3E2412;--pine:#6B4420;--pine-light:#9C6728;--surface:#f4f1e9;--ink:#2A1709;--ink-2:#6E6054;--gold:#C8963C}
        *{box-sizing:border-box;margin:0;padding:0}
        html,body{width:100%;height:100%;min-height:0;overflow:hidden}
        body{font-family:'Outfit',system-ui,-apple-system,'Segoe UI',sans-serif;color:var(--ink);background:var(--surface);-webkit-font-smoothing:antialiased}
        .error-shell{display:grid;grid-template-columns:minmax(390px,.82fr) minmax(0,1.18fr);width:100%;height:100vh;height:100svh;min-height:0;overflow:hidden;background:#fff}
        .error-content{position:relative;z-index:2;display:flex;min-width:0;min-height:0;flex-direction:column;align-items:flex-start;justify-content:center;padding:clamp(36px,7vw,104px);background:radial-gradient(ellipse at 10% 8%,#fff 0,transparent 52%),#fff}
        .brand{display:inline-flex;align-items:center;gap:10px;margin-bottom:clamp(38px,7vh,72px);color:var(--pine-deep)}
        .brand-mark{display:grid;place-items:center;width:42px;height:42px;border-radius:14px;background:#eaf0e9;color:var(--pine)}
        .brand-mark svg{width:22px;height:22px;fill:currentColor}
        .brand-name{font-family:'Newsreader',Georgia,serif;font-size:1.4rem;font-weight:600;letter-spacing:-.03em}
        .brand-caption{margin-top:2px;color:#9A8778;font-size:.62rem;font-weight:700;letter-spacing:.13em;text-transform:uppercase}
        .error-kicker{margin-bottom:12px;color:var(--pine-light);font-size:.74rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase}
        .error-code{color:var(--pine-deep);font-family:'Newsreader',Georgia,serif;font-size:clamp(6rem,12vw,10rem);font-weight:500;letter-spacing:-.075em;line-height:.82}
        .error-rule{width:52px;height:3px;margin:24px 0;background:var(--gold);border-radius:99px}
        .error-title{max-width:480px;color:var(--pine-deep);font-family:'Newsreader',Georgia,serif;font-size:clamp(1.9rem,3.2vw,3rem);font-weight:500;letter-spacing:-.035em;line-height:1.08}
        .error-copy{max-width:400px;margin-top:14px;color:var(--ink-2);font-size:.97rem;line-height:1.7}
        .error-actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:30px}
        .btn-home{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-height:50px;padding:0 22px;border:1px solid var(--pine);border-radius:12px;background:var(--pine);color:#fff;font-size:.9rem;font-weight:600;text-decoration:none;box-shadow:0 8px 20px rgba(62,36,18,.14);transition:transform .18s,background .18s,box-shadow .18s}
        .btn-home svg,.image-caption svg{width:18px;height:18px;flex:none;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
        .btn-home:hover{transform:translateY(-2px);background:var(--pine-light);box-shadow:0 12px 24px rgba(62,36,18,.2)}
        .btn-home:focus-visible{outline:3px solid rgba(156,103,40,.32);outline-offset:3px}
        .error-image{position:relative;min-width:0;min-height:0;height:100%;background:linear-gradient(180deg,rgba(42,23,9,.04),rgba(42,23,9,.2)),url('<?= $baseUrlHtml ?>/bg-login.webp') center 46%/cover no-repeat}
        .error-image:before{position:absolute;inset:22px;content:'';border:1px solid rgba(255,255,255,.3);border-radius:22px;pointer-events:none}
        .image-caption{position:absolute;right:clamp(38px,6vw,84px);bottom:clamp(38px,6vw,76px);left:clamp(38px,6vw,84px);max-width:420px;color:#fff;text-shadow:0 2px 18px rgba(0,0,0,.28)}
        .image-caption span{display:inline-flex;align-items:center;gap:8px;margin-bottom:10px;font-size:.7rem;font-weight:600;letter-spacing:.15em;text-transform:uppercase;color:rgba(255,255,255,.88)}
        .image-caption p{font-family:'Newsreader',Georgia,serif;font-size:clamp(1.6rem,2.6vw,2.5rem);line-height:1.12}
        @media(max-width:820px){
            .error-shell{grid-template-columns:minmax(0,1fr);grid-template-rows:minmax(0,65svh) minmax(0,35svh);overflow:hidden}
            .error-content{grid-row:1;padding:clamp(20px,4.5vh,38px) clamp(24px,8vw,72px)}
            .brand{margin-bottom:clamp(18px,3.5vh,32px)}
            .error-code{font-size:clamp(5rem,16vw,7rem)}
            .error-image{grid-row:2;height:100%;min-height:0}
            .error-image:before{inset:12px}
            .image-caption{right:28px;bottom:28px;left:28px}
        }
        @media(max-width:480px){
            .error-shell{grid-template-rows:minmax(0,67svh) minmax(0,33svh)}
            .error-content{padding:18px 24px}
            .brand{margin-bottom:16px}
            .brand-mark{width:36px;height:36px;border-radius:12px}
            .error-kicker{margin-bottom:8px;font-size:.68rem}
            .error-code{font-size:4.8rem}
            .error-rule{margin:14px 0}
            .error-title{font-size:clamp(1.65rem,7vw,2rem)}
            .error-copy{font-size:.9rem}
            .error-actions{width:100%;margin-top:16px}
            .btn-home{width:100%;min-height:46px}
            .error-image{background-position:center 46%}
            .image-caption p{font-size:1.5rem}
        }
        @media(max-height:700px) and (max-width:480px){
            .error-shell{grid-template-rows:minmax(0,70svh) minmax(0,30svh)}
            .error-content{padding-top:12px;padding-bottom:12px}
            .brand{margin-bottom:10px}
            .error-code{font-size:4rem}
            .error-rule{margin:10px 0}
            .error-title{font-size:1.55rem}
            .error-copy{margin-top:8px;font-size:.84rem;line-height:1.5}
            .error-actions{margin-top:12px}
            .image-caption{right:22px;bottom:20px;left:22px}
            .image-caption p{font-size:1.25rem}
        }
        @media(max-height:620px) and (min-width:821px){.error-content{padding-top:26px;padding-bottom:26px}.brand{margin-bottom:22px}.error-code{font-size:6rem}.error-rule{margin:16px 0}.error-actions{margin-top:18px}}
        @media(prefers-reduced-motion:reduce){.btn-home{transition:none}}
    </style>
</head>
<body>
    <div class="error-shell">
        <main class="error-content">
            <div class="brand" aria-label="Casa Bosque, sistema de gestión">
                <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2 5.5 10h3L4 16h6v5h4v-5h6l-4.5-6h3L12 2z"/></svg></span>
                <span><span class="brand-name">Casa Bosque</span><span class="brand-caption" style="display:block">Sistema de gestión</span></span>
            </div>
            <p class="error-kicker">Parece que nos desviamos</p>
            <h1 class="error-code">404</h1>
            <div class="error-rule" aria-hidden="true"></div>
            <h2 class="error-title">Esta página no está disponible</h2>
            <p class="error-copy">El enlace puede haber cambiado o la dirección no existe. Regresa al inicio y continúa desde allí.</p>
            <div class="error-actions">
                <a class="btn-home" href="<?= $baseUrlHtml ?>/index.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 19-7-7 7-7M5 12h14"/></svg> Volver al inicio</a>
            </div>
        </main>
        <aside class="error-image" role="img" aria-label="Exterior de Casa Bosque">
            <div class="image-caption"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 4c-9 0-14 4-14 11a5 5 0 0 0 5 5c7 0 11-5 9-16zM4 21c3-5 6-8 11-11"/></svg> Hospedaje Casa Bosque</span><p>Un espacio tranquilo para descansar y sentirse en casa.</p></div>
        </aside>
    </div>
</body>
</html>
