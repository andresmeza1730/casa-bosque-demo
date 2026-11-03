<?php
// ============================================================
// modules/auditoria/index.php
// Auditoría — la pista de lo que pasa en el sistema (solo admin)
// ============================================================
// De arriba abajo: cuatro números (acciones, alertas críticas activas, usuarios
// operando, intentos fallidos), la gráfica de actividad con los fallos de acceso,
// las alertas recientes, los filtros, la exportación y la lista.
//
// Lo decide includes/auditoria.php: la gravedad, la categoría, el módulo, las
// alertas y los filtros. Aquí solo se pinta. Los mismos filtros los leen las dos
// exportaciones (exportar.php), así que lo que se exporta es lo que se ve.
//
// SOLO LECTURA. Los registros no se pueden editar ni borrar: dos disparadores de la
// base lo impiden. Esta pantalla no tiene ningún botón que cambie nada.
// ------------------------------------------------------------
$pageTitle = 'Auditoría';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

// Control de acceso ANTES de emitir cualquier HTML, para que la
// redirección (header Location) de requireAdmin() funcione y no se
// filtre nada de la vista a un usuario sin permiso. (OWASP A01)
requireAdmin();   // exige sesión + rol admin; recepcionista es redirigido

require_once __DIR__ . '/../../includes/header.php';

$pdo = getDB();

$f         = auditoriaFiltros($pdo, $_GET);
$cons      = auditoriaConsulta($pdo, $f);
$filas     = $cons['filas'];
$ind       = auditoriaIndicadores($pdo, $f);
$serie     = auditoriaSerie($pdo, $f);
$alertas   = auditoriaAlertas($pdo);
$criticas  = auditoriaAlertasCriticasActivas($alertas);
$usuarios  = auditoriaUsuarios($pdo);
$acciones  = $pdo->query("select distinct accion from auditoria order by accion")->fetchAll(PDO::FETCH_COLUMN);
$migrada   = auditoriaColumnas($pdo);

$esc = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// La consulta de los filtros, para los enlaces de exportación.
$qs = http_build_query(array_filter([
    'desde' => $f['desde'], 'hasta' => $f['hasta'], 'usuario' => $f['usuario'] ?: null, 'modulo' => $f['modulo'],
    'severidad' => $f['severidad'], 'ip' => $f['ip'], 'accion' => $f['accion'],
], fn($v) => $v !== '' && $v !== null));
$urlCsv  = BASE_URL . '/modules/auditoria/exportar.php?formato=csv&' . $qs;
$urlJson = BASE_URL . '/modules/auditoria/exportar.php?formato=json&' . $qs;
?>

<style>
/* ============================================================
   Auditoría — indicadores, gráfica, alertas y lista (Casa Bosque)
   ============================================================ */
.au-wrap { padding-bottom: 90px; }
.au-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

.au-aviso-mig { background: #FFF7E0; border: 1px solid #F0DCB4; color: #7A5410; border-radius: 14px; padding: 12px 14px; font-size: .84rem; line-height: 1.5; margin-bottom: 14px; }
.au-aviso-mig b { color: #5E3F08; }
.au-aviso-pdf { background: #FBE9E7; border: 1px solid #F0CFCB; color: #8A2E27; border-radius: 14px; padding: 12px 14px; font-size: .84rem; margin-bottom: 14px; }
.au-aviso-pdf[hidden] { display: none; }

.au-kpis { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 16px; }
@media (min-width: 900px) { .au-kpis { grid-template-columns: repeat(4, 1fr); } }
.au-kpi { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 16px; padding: 14px 14px 12px; box-shadow: 0 4px 12px rgba(42,23,9,.04); }
.au-kpi-n { font-size: 1.9rem; font-weight: 800; color: var(--cb-forest,#3E2412); line-height: 1.1; }
.au-kpi-l { font-size: .78rem; font-weight: 700; color: var(--cb-text,#2A1709); margin-top: 4px; }
.au-kpi-s { font-size: .7rem; color: var(--cb-muted,#9A8778); margin-top: 2px; }
.au-kpi.alerta .au-kpi-n { color: #B3261E; }

.au-sec { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); padding: 14px; margin-bottom: 16px; }
.au-sec h2 { margin: 0 0 10px; font-size: .95rem; font-weight: 800; color: var(--cb-forest,#3E2412); display: flex; align-items: center; gap: 8px; }
.au-leyenda { display: flex; flex-wrap: wrap; gap: 14px; font-size: .74rem; color: var(--cb-muted,#9A8778); margin-bottom: 6px; }
.au-leyenda i { display: inline-block; width: 18px; height: 4px; border-radius: 2px; margin-right: 6px; vertical-align: middle; }
.au-grafico { display: block; width: 100%; height: auto; }
.au-eje { font-size: 11px; fill: #9A8778; }
@media (max-width: 600px) { .au-eje { font-size: 22px; } }

.au-alerta { display: flex; gap: 10px; padding: 10px 0; border-top: 1px solid var(--gray-100,#F1EADF); }
.au-alerta:first-of-type { border-top: 0; }
.au-alerta-t { font-size: .84rem; font-weight: 700; color: var(--cb-text,#2A1709); }
.au-alerta-d { font-size: .78rem; color: var(--cb-muted,#9A8778); margin-top: 2px; line-height: 1.4; overflow-wrap: anywhere; }
.au-alerta-m { font-size: .7rem; color: var(--soft,#A2907C); margin-top: 3px; }
.au-vacio-a { font-size: .84rem; color: var(--cb-muted,#9A8778); margin: 0; }

.au-grav { display: inline-block; font-size: .64rem; font-weight: 800; letter-spacing: .04em; padding: 3px 9px; border-radius: 99px; white-space: nowrap; }
.au-grav-info { background: var(--gray-100,#F1EADF); color: var(--cb-muted,#9A8778); }
.au-grav-warning { background: rgba(249,115,22,.14); color: #9A5208; }
.au-grav-critical { background: rgba(179,38,30,.12); color: #B3261E; }
.au-activa { background: #B3261E; color: #fff; font-size: .6rem; font-weight: 800; padding: 2px 7px; border-radius: 99px; margin-left: 6px; vertical-align: middle; }

.au-filters { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); padding: 14px; margin-bottom: 12px; }
.au-filters form { display: flex; flex-wrap: wrap; gap: 9px; }
.au-sel, .au-in { flex: 1; min-width: 130px; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; border-radius: 11px; padding: 11px 13px; font-family: inherit; font-size: .84rem; color: var(--cb-text,#2A1709); }
.au-sel { padding-right: 32px; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236b7b69' d='M6 8 0 0h12z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; }
.au-sel:focus, .au-in:focus { outline: none; border-color: var(--cb-primary,#6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }
.au-fbtns { display: flex; gap: 9px; width: 100%; }
.au-btn { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 7px; font-size: .84rem; font-weight: 700; border-radius: 11px; padding: 11px; text-decoration: none; cursor: pointer; font-family: inherit; border: none; }
.au-btn.apply { background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color: #fff; }
.au-btn.clear { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); color: var(--cb-muted,#9A8778); }
.au-btn[disabled] { opacity: .6; cursor: default; }
.au-export { display: flex; gap: 9px; margin-bottom: 14px; flex-wrap: wrap; }
.au-export .au-btn { flex: 0 1 auto; padding: 10px 16px; background: #fff; border: 1px solid var(--cb-border,#EDE3D2); color: var(--cb-forest,#3E2412); }

.au-count { font-size: .78rem; color: var(--cb-muted,#9A8778); font-weight: 600; margin: 0 2px 12px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

.au-log { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 15px; padding: 13px 14px; margin-bottom: 10px; box-shadow: 0 4px 12px rgba(42,23,9,.04); }
.au-log-top { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
.au-action { font-size: .66rem; font-weight: 700; padding: 4px 10px; border-radius: 99px; text-transform: capitalize; }
.au-action.crear { background: rgba(31,122,77,.14); color: #047857; }
.au-action.editar { background: rgba(249,115,22,.14); color: #b45309; }
.au-action.eliminar { background: rgba(239,68,68,.12); color: #b91c1c; }
.au-action.otro { background: var(--gray-100,#F1EADF); color: var(--cb-muted,#9A8778); }
.au-tabla { font-size: .68rem; font-weight: 700; padding: 4px 9px; border-radius: 7px; background: var(--cb-primary-light,#F5EBDC); color: var(--cb-primary,#6B4420); }
.au-id { font-size: .72rem; color: var(--soft,#A2907C); font-weight: 600; }
.au-time { margin-left: auto; font-size: .7rem; color: var(--cb-muted,#9A8778); white-space: nowrap; }
.au-detail { font-size: .84rem; color: var(--cb-text,#2A1709); margin-top: 9px; line-height: 1.4; overflow-wrap: anywhere; }
.au-foot { display: flex; align-items: center; gap: 12px; margin-top: 9px; padding-top: 9px; border-top: 1px solid var(--gray-100,#F1EADF); flex-wrap: wrap; }
.au-user { font-size: .74rem; color: var(--cb-forest,#3E2412); font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
.au-user i { color: var(--soft,#A2907C); }
.au-ip { font-size: .72rem; color: var(--cb-muted,#9A8778); font-family: monospace; display: inline-flex; align-items: center; gap: 5px; }
.au-ip i { color: var(--soft,#A2907C); }
.au-ver { margin-left: auto; min-height: 44px; padding: 0 14px; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; border-radius: 11px; font: inherit; font-size: .78rem; font-weight: 700; color: var(--cb-primary,#6B4420); cursor: pointer; }

/* En escritorio, la tabla; en el celular, las tarjetas. */
/* width:0 + min-width:100%: el contenedor no aporta SU contenido al ancho mínimo de la página. Sin esto la tabla
   (1040 px a propósito) empuja a .main-content, que es un elemento flex de ancho mínimo automático, y la página
   entera se desborda a la derecha en vez de desplazarse la tabla. El armazón no se toca. */
/* position: relative: el texto oculto de la última columna (.au-sr) es absolute, y sin esto su bloque de posición era
   la PÁGINA: quedaba fuera del contenedor, no se recortaba ni se desplazaba con la tabla y empujaba el documento entero
   (scrollWidth 1362 a 1280 px). Se vio a mano, no con las pruebas: medir solo a 375 y 1280 no lo pillaba. */
.au-tabla-wrap { position: relative; display: none; width: 0; min-width: 100%; overflow-x: auto; background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 15px; }
/* La tabla es más ancha que la pantalla a propósito (min-width): lo que no cabe se desplaza dentro de SU
   contenedor, en vez de partir «login_fallido» o «Autenticación» a mitad de palabra. */
.au-tabla-wrap table { width: 100%; min-width: 1040px; border-collapse: collapse; font-size: .8rem; }
.au-tabla-wrap th { text-align: left; font-size: .68rem; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: var(--cb-forest,#3E2412); background: var(--cb-primary-light,#F5EBDC); padding: 10px 11px; white-space: nowrap; }
.au-tabla-wrap td { padding: 10px 11px; border-top: 1px solid var(--gray-100,#F1EADF); vertical-align: top; }
.au-tabla-wrap td.nw { white-space: nowrap; }
.au-tabla-wrap td.det { overflow-wrap: anywhere; min-width: 240px; }
@media (min-width: 900px) { .au-tabla-wrap { display: block; } .au-cards { display: none; } }

.au-empty { text-align: center; padding: 36px 12px; color: var(--cb-muted,#9A8778); }
.au-empty i { font-size: 2.2rem; color: var(--cb-primary,#6B4420); opacity: .4; margin-bottom: 12px; display: block; }
.au-empty p { font-size: .9rem; margin: 0; }

/* La tarjeta de detalle */
.au-modal { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 16px; }
.au-modal[hidden] { display: none; }
.au-modal-bg { position: absolute; inset: 0; background: rgba(62,36,18,.55); }
.au-card { position: relative; width: 100%; max-width: 640px; max-height: 90vh; overflow-y: auto; background: #fff; border-radius: 18px; padding: 20px 18px 18px; box-shadow: 0 20px 50px rgba(42,23,9,.35); }
.au-card:focus { outline: none; }
.au-card-x { position: absolute; top: 8px; right: 8px; width: 44px; height: 44px; border: 0; background: transparent; font-size: 1.6rem; line-height: 1; color: var(--cb-muted,#9A8778); cursor: pointer; border-radius: 12px; }
.au-card-fecha { font-size: .76rem; font-weight: 700; color: var(--cb-muted,#9A8778); padding-right: 40px; }
.au-card h2 { margin: 4px 0 10px; font-size: 1.1rem; font-weight: 800; color: var(--cb-forest,#3E2412); padding-right: 40px; overflow-wrap: anywhere; }
.au-card dl { margin: 0 0 12px; display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; font-size: .88rem; }
.au-card dt { color: var(--cb-muted,#9A8778); }
.au-card dd { margin: 0; font-weight: 700; color: var(--cb-forest,#3E2412); overflow-wrap: anywhere; }
.au-cambio[hidden], .au-cambio > div[hidden] { display: none; }
.au-cambio { display: grid; grid-template-columns: 1fr; gap: 10px; margin-bottom: 14px; }
@media (min-width: 560px) { .au-cambio { grid-template-columns: 1fr 1fr; } }
.au-cambio h3 { margin: 0 0 6px; font-size: .74rem; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; color: var(--cb-muted,#9A8778); }
.au-cambio > div { background: var(--cb-primary-light,#F5EBDC); border-radius: 12px; padding: 10px 12px; }
.au-cambio dl { margin: 0; font-size: .82rem; }
.au-card-cerrar { width: 100%; height: 46px; border: 0; border-radius: 12px; font: inherit; font-weight: 800; color: #fff; cursor: pointer; background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); }
.au-card button:focus-visible, .au-ver:focus-visible { outline: 3px solid #f59e0b; outline-offset: 2px; }
</style>

<div class="au-wrap">

<?php if (!$migrada): ?>
<div class="au-aviso-mig" role="status">
    <b>Falta ejecutar un archivo en la base de datos.</b> Esta pantalla ya puede mostrar la gravedad, el navegador
    y el antes/después de cada cambio, pero la base todavía no tiene esas columnas: se ven solo los registros de
    siempre. Ejecuta <code>sql/migration_026_auditoria_ampliada.sql</code> en phpMyAdmin.
</div>
<?php endif; ?>
<?php if ($f['severidad_ignorada']): ?>
<div class="au-aviso-mig" role="status">El filtro de gravedad se aplicará cuando se ejecute el archivo de base de datos de arriba.</div>
<?php endif; ?>

<!-- Indicadores -->
<div class="au-kpis">
    <div class="au-kpi">
        <div class="au-kpi-n"><?= number_format($ind['total'], 0, ',', '.') ?></div>
        <div class="au-kpi-l">Acciones registradas</div>
        <div class="au-kpi-s"><?= $esc(date('d/m', strtotime($f['desde']))) ?> a <?= $esc(date('d/m', strtotime($f['hasta']))) ?></div>
    </div>
    <div class="au-kpi<?= $criticas > 0 ? ' alerta' : '' ?>">
        <div class="au-kpi-n"><?= $criticas ?></div>
        <div class="au-kpi-l">Alertas críticas activas</div>
        <div class="au-kpi-s">últimas <?= AUDITORIA_ALERTA_ACTIVA_HORAS ?> horas</div>
    </div>
    <div class="au-kpi">
        <div class="au-kpi-n"><?= $ind['usuarios'] ?></div>
        <div class="au-kpi-l">Usuarios operando</div>
        <div class="au-kpi-s">distintos en el rango</div>
    </div>
    <div class="au-kpi<?= $ind['fallos'] > 0 ? ' alerta' : '' ?>">
        <div class="au-kpi-n"><?= $ind['fallos'] ?></div>
        <div class="au-kpi-l">Intentos de acceso fallidos</div>
        <div class="au-kpi-s">en el rango</div>
    </div>
</div>

<!-- Actividad -->
<section class="au-sec" aria-label="Actividad">
    <h2><i class="fas fa-chart-line"></i> Actividad <?= $serie['por_hora'] ? 'por hora' : 'por día' ?></h2>
    <div class="au-leyenda">
        <span><i style="background:#6B4420"></i>Acciones</span>
        <span><i style="background:#B84239"></i>Intentos fallidos de acceso</span>
    </div>
    <?= auditoriaGraficoSvg($serie) ?>
</section>

<!-- Alertas -->
<section class="au-sec" aria-label="Alertas recientes">
    <h2><i class="fas fa-triangle-exclamation"></i> Alertas recientes <span class="au-kpi-s" style="font-weight:600">últimos <?= AUDITORIA_ALERTAS_DIAS ?> días · solo avisan</span></h2>
    <?php if (!$alertas): ?>
        <p class="au-vacio-a">Sin alertas en los últimos <?= AUDITORIA_ALERTAS_DIAS ?> días.</p>
    <?php else: foreach (array_slice($alertas, 0, 8) as $a): ?>
        <div class="au-alerta">
            <div><span class="au-grav au-grav-<?= strtolower($a['nivel']) ?>"><?= $esc($a['nivel']) ?></span></div>
            <div>
                <div class="au-alerta-t"><?= $esc($a['titulo']) ?><?php if ($a['activa']): ?><span class="au-activa">ACTIVA</span><?php endif; ?></div>
                <div class="au-alerta-d"><?= $esc($a['detalle']) ?></div>
                <div class="au-alerta-m"><?= $esc(date('d/m/Y H:i', $a['ts'])) ?><?= $a['usuario'] !== '' ? ' · ' . $esc($a['usuario']) : '' ?><?= $a['ip'] !== '' ? ' · ' . $esc($a['ip']) : '' ?></div>
            </div>
        </div>
    <?php endforeach; endif; ?>
</section>

<!-- Filtros -->
<div class="au-filters">
    <form method="GET">
        <label class="au-sr" for="f-desde">Desde</label>
        <input type="date" id="f-desde" name="desde" class="au-in" value="<?= $esc($f['desde']) ?>" max="<?= $esc(date('Y-m-d')) ?>">
        <label class="au-sr" for="f-hasta">Hasta</label>
        <input type="date" id="f-hasta" name="hasta" class="au-in" value="<?= $esc($f['hasta']) ?>" max="<?= $esc(date('Y-m-d')) ?>">
        <label class="au-sr" for="f-usuario">Usuario</label>
        <select id="f-usuario" name="usuario" class="au-sel">
            <option value="">Todos los usuarios</option>
            <?php foreach ($usuarios as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= $f['usuario'] === (int) $u['id'] ? 'selected' : '' ?>><?= $esc($u['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="au-sr" for="f-modulo">Módulo</label>
        <select id="f-modulo" name="modulo" class="au-sel">
            <option value="">Todos los módulos</option>
            <?php foreach (array_keys(auditoriaModulos()) as $m): ?>
                <option value="<?= $esc($m) ?>" <?= $f['modulo'] === $m ? 'selected' : '' ?>><?= $esc($m) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="au-sr" for="f-sev">Gravedad</label>
        <select id="f-sev" name="severidad" class="au-sel">
            <option value="">Toda gravedad</option>
            <?php foreach (['INFO', 'WARNING', 'CRITICAL'] as $s): ?>
                <option value="<?= $s ?>" <?= $f['severidad'] === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <label class="au-sr" for="f-accion">Acción</label>
        <select id="f-accion" name="accion" class="au-sel">
            <option value="">Todas las acciones</option>
            <?php foreach ($acciones as $a): ?>
                <option value="<?= $esc($a) ?>" <?= $f['accion'] === $a ? 'selected' : '' ?>><?= $esc($a) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="au-sr" for="f-ip">IP</label>
        <input type="text" id="f-ip" name="ip" class="au-in" placeholder="IP (o el comienzo)" maxlength="45" value="<?= $esc($f['ip']) ?>" inputmode="text" autocomplete="off">
        <div class="au-fbtns">
            <button class="au-btn apply"><i class="fas fa-filter"></i> Filtrar</button>
            <a href="?" class="au-btn clear">Limpiar</a>
        </div>
    </form>
</div>

<div class="au-export">
    <a class="au-btn" href="<?= $esc($urlCsv) ?>"><i class="fas fa-file-csv"></i> Exportar CSV</a>
    <button type="button" class="au-btn" id="au-pdf" data-url="<?= $esc($urlJson) ?>"><i class="fas fa-file-pdf"></i> Exportar PDF</button>
</div>
<div class="au-aviso-pdf" id="au-aviso" role="alert" hidden></div>

<div class="au-count">
    <i class="fas fa-clipboard-list"></i>
    <?= count($filas) ?> registro(s) de auditoría<?= $cons['total'] > count($filas) ? ' · mostrando los más recientes de ' . number_format($cons['total'], 0, ',', '.') . ': acota el rango para ver el resto' : '' ?>
</div>

<?php if (empty($filas)): ?>
    <div class="au-empty"><i class="fas fa-clipboard"></i><p>No hay registros para los filtros seleccionados.</p></div>
<?php else: ?>

    <!-- Celular: tarjetas -->
    <div class="au-cards">
    <?php foreach ($filas as $l):
        $cat = $l['categoria'];
        $claseAccion = $cat === 'CREATE' ? 'crear' : ($cat === 'DELETE' ? 'eliminar' : ($cat === 'UPDATE' ? 'editar' : 'otro'));
    ?>
        <div class="au-log">
            <div class="au-log-top">
                <span class="au-action <?= $claseAccion ?>"><?= $esc($l['accion']) ?></span>
                <span class="au-tabla"><?= $esc($l['modulo']) ?></span>
                <span class="au-grav au-grav-<?= strtolower($l['severidad']) ?>"><?= $esc($l['severidad']) ?></span>
                <span class="au-time"><?= $esc($l['fecha_vista']) ?></span>
            </div>
            <?php if ($l['detalle'] !== ''): ?><div class="au-detail"><?= $esc($l['detalle']) ?></div><?php endif; ?>
            <div class="au-foot">
                <span class="au-user"><i class="fas fa-user"></i> <?= $esc($l['usuario']) ?></span>
                <?php if ($l['ip'] !== ''): ?><span class="au-ip"><i class="fas fa-network-wired"></i> <?= $esc($l['ip']) ?></span><?php endif; ?>
                <button type="button" class="au-ver" data-au-det data-id="<?= $l['id'] ?>">Ver detalle</button>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <!-- Escritorio: tabla, en su propio contenedor desplazable -->
    <div class="au-tabla-wrap">
        <table>
            <thead><tr>
                <th>Fecha y hora</th><th>Usuario</th><th>Acción</th><th>Módulo</th><th>Gravedad</th><th>Detalle del cambio</th><th>Conexión</th><th><span class="au-sr">Detalle</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($filas as $l): ?>
                <tr>
                    <td class="nw"><?= $esc($l['fecha_vista']) ?></td>
                    <td class="nw"><?= $esc($l['usuario']) ?></td>
                    <td class="nw"><?= $esc($l['accion']) ?><div class="au-id"><?= $esc($l['categoria']) ?></div></td>
                    <td class="nw"><?= $esc($l['modulo']) ?></td>
                    <td><span class="au-grav au-grav-<?= strtolower($l['severidad']) ?>"><?= $esc($l['severidad']) ?></span></td>
                    <td class="det"><?= $esc($l['detalle']) ?></td>
                    <td class="nw"><span class="au-ip"><?= $esc($l['ip']) ?></span><?php if ($l['agente'] !== ''): ?><div class="au-id"><?= $esc($l['agente']) ?></div><?php endif; ?></td>
                    <td><button type="button" class="au-ver" data-au-det data-id="<?= $l['id'] ?>">Ver det.</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

</div><!-- /.au-wrap -->

<!-- La tarjeta de detalle -->
<div class="au-modal" id="au-modal" hidden>
    <div class="au-modal-bg" data-au-close></div>
    <div class="au-card" role="dialog" aria-modal="true" aria-labelledby="au-card-titulo" tabindex="-1">
        <button type="button" class="au-card-x" data-au-close aria-label="Cerrar">&times;</button>
        <div class="au-card-fecha" id="au-card-fecha"></div>
        <h2 id="au-card-titulo"></h2>
        <span class="au-grav" id="au-card-grav"></span>
        <dl style="margin-top:12px">
            <dt>Usuario</dt><dd id="au-card-usuario"></dd>
            <dt>Categoría</dt><dd id="au-card-categoria"></dd>
            <dt>Registro</dt><dd id="au-card-tabla"></dd>
            <dt>IP</dt><dd id="au-card-ip"></dd>
            <dt>Navegador</dt><dd id="au-card-agente"></dd>
            <dt>Detalle</dt><dd id="au-card-detalle"></dd>
        </dl>
        <div class="au-cambio" id="au-card-cambio" hidden>
            <div id="au-card-antes-w"><h3>Antes</h3><dl id="au-card-antes"></dl></div>
            <div id="au-card-despues-w"><h3>Después</h3><dl id="au-card-despues"></dl></div>
        </div>
        <button type="button" class="au-card-cerrar" data-au-close>Cerrar</button>
    </div>
</div>

<!-- Los registros que se ven, para la tarjeta. Se serializa con las marcas escapadas: nada puede cerrar este <script>. -->
<script type="application/json" id="au-datos"><?= json_encode($filas, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= BASE_URL ?>/assets/js/jspdf.umd.js"></script>
<script src="<?= BASE_URL ?>/assets/js/pdf-casabosque.js?v=<?= APP_VERSION ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/auditoria-pdf.js?v=<?= APP_VERSION ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/auditoria.js?v=<?= APP_VERSION ?>"></script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
