<?php
// ============================================================
// modules/reportes/ingresos.php
// Reportes de Ingresos — Casa Bosque (solo administrador)
// ============================================================
// Ingresos históricos por periodo (mes o año), desglosados en
// hospedaje y consumos, con detalle por día/mes y por
// recepcionista. Fuente: estancias cerradas (fecha_checkout).
//
// Y las VENTAS DIRECTAS DE MOSTRADOR, que antes no estaban en ninguna cifra de
// este reporte. Van en un bloque propio, no mezcladas: ver el comentario junto
// a cajaVentasDelPeriodo() más abajo.
// ------------------------------------------------------------

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/caja.php';   // las ventas de mostrador, en un solo sitio
requireAdmin();   // exclusivo del administrador

$pdo = getDB();

// ── Periodo seleccionado ──
$vista = ($_GET['vista'] ?? 'mes') === 'anio' ? 'anio' : 'mes';

if ($vista === 'mes') {
    $mes = $_GET['mes'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');
    $desde = $mes . '-01';
    $hasta = date('Y-m-d', strtotime($desde . ' +1 month'));
    $tituloPeriodo = ucfirst(strftime('%B %Y', strtotime($desde)) ?: date('m/Y', strtotime($desde)));
    // strftime puede no estar en PHP 8+: fallback manual
    $mesesEs = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $tituloPeriodo = $mesesEs[(int)date('n', strtotime($desde))] . ' ' . date('Y', strtotime($desde));
} else {
    $anio = (int)($_GET['anio'] ?? date('Y'));
    if ($anio < 2020 || $anio > 2100) $anio = (int)date('Y');
    $desde = $anio . '-01-01';
    $hasta = ($anio + 1) . '-01-01';
    $tituloPeriodo = 'Año ' . $anio;
}

// ── Totales del periodo ──
$stmt = $pdo->prepare("
    select count(*) as checkouts,
           coalesce(sum(subtotal_noches), 0) as hospedaje,
           coalesce(sum(total_consumos), 0) as consumos,
           coalesce(sum(total_pagar), 0) as total
    from estancias
    where estado = 'cerrada'
      and fecha_checkout >= ?
      and fecha_checkout < ?
");
$stmt->execute([$desde, $hasta]);
$tot = $stmt->fetch(PDO::FETCH_ASSOC);


// ── Ventas directas de mostrador ──
// Faltaban ENTERAS en este reporte: `ventas_mostrador` se leía en un solo sitio
// de todo el proyecto (el resumen por día de Consumos), así que la mercancía que
// se vende en el mostrador no aparecía en ninguna cifra de ingresos.
//
// Van en su propio bloque y NO se suman a `$tot`: las cifras de arriba
// —checkouts, hospedaje, consumos, total— siguen significando lo mismo que
// antes, que es el valor de las estancias cerradas. Mezclarlas cambiaría el
// número que el hotel viene comparando mes a mes sin avisar. El total de todo
// va aparte, en `$totalGeneral`.
//
// `$hasta` ya es el primer día EXCLUIDO —así lo calcula este archivo y así
// consulta las estancias—, y cajaVentasDelPeriodo() lo recibe tal cual.
$ventas = cajaVentasDelPeriodo($pdo, $desde, $hasta, $vista === 'mes' ? 'dia' : 'mes');
$totalGeneral = (float) $tot['total'] + $ventas['total'];

// ── Periodo anterior (para comparativa) ──
if ($vista === 'mes') {
    $desdePrev = date('Y-m-d', strtotime($desde . ' -1 month'));
    $hastaPrev = $desde;
    $etiquetaPrev = 'mes anterior';
} else {
    $desdePrev = ($anio - 1) . '-01-01';
    $hastaPrev = $anio . '-01-01';
    $etiquetaPrev = 'año anterior';
}
$stmt = $pdo->prepare("
    select count(*) as checkouts,
           coalesce(sum(subtotal_noches), 0) as hospedaje,
           coalesce(sum(total_consumos), 0) as consumos,
           coalesce(sum(total_pagar), 0) as total
    from estancias
    where estado = 'cerrada'
      and fecha_checkout >= ?
      and fecha_checkout < ?
");
$stmt->execute([$desdePrev, $hastaPrev]);
$totPrev = $stmt->fetch(PDO::FETCH_ASSOC);

// Calcula variación porcentual entre actual y anterior.
// Devuelve null si el anterior es 0 (no se puede comparar contra cero).
function variacion($actual, $anterior) {
    $actual = (float)$actual; $anterior = (float)$anterior;
    if ($anterior == 0) return null;
    return round(($actual - $anterior) / $anterior * 100, 1);
}
$varTotal = variacion($tot['total'], $totPrev['total']);
$varCheckouts = variacion($tot['checkouts'], $totPrev['checkouts']);
if ($vista === 'mes') {
    $stmt = $pdo->prepare("
        select date(fecha_checkout) as periodo,
               coalesce(sum(subtotal_noches), 0) as hospedaje,
               coalesce(sum(total_consumos), 0) as consumos,
               coalesce(sum(total_pagar), 0) as total,
               count(*) as checkouts
        from estancias
        where estado = 'cerrada'
          and fecha_checkout >= ?
          and fecha_checkout < ?
        group by date(fecha_checkout)
        order by periodo
    ");
} else {
    $stmt = $pdo->prepare("
        select date_format(fecha_checkout, '%Y-%m') as periodo,
               coalesce(sum(subtotal_noches), 0) as hospedaje,
               coalesce(sum(total_consumos), 0) as consumos,
               coalesce(sum(total_pagar), 0) as total,
               count(*) as checkouts
        from estancias
        where estado = 'cerrada'
          and fecha_checkout >= ?
          and fecha_checkout < ?
        group by date_format(fecha_checkout, '%Y-%m')
        order by periodo
    ");
}
$stmt->execute([$desde, $hasta]);
$desglose = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Máximo para escalar las barras del gráfico
$maxTotal = 0;
foreach ($desglose as $d) { if ($d['total'] > $maxTotal) $maxTotal = (float)$d['total']; }

// ── Ingresos por recepcionista (quién cerró cada check-out) ──
$stmt = $pdo->prepare("
    select u.nombre,
           count(*) as checkouts,
           coalesce(sum(e.subtotal_noches), 0) as hospedaje,
           coalesce(sum(e.total_consumos), 0) as consumos,
           coalesce(sum(e.total_pagar), 0) as total
    from estancias e, usuarios u
    where e.usuario_checkout = u.id
      and e.estado = 'cerrada'
      and e.fecha_checkout >= ?
      and e.fecha_checkout < ?
    group by u.id, u.nombre
    order by total desc
");
$stmt->execute([$desde, $hasta]);
$porUsuario = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Ocupación del periodo (% de noches vendidas sobre la capacidad) ──
// Capacidad = habitaciones EN SERVICIO x días del periodo.
//
// Se excluyen las 'inactiva', que segun el esquema estan "retiradas del
// inventario". Contarlas inflaba la capacidad y hundía el porcentaje de
// ocupación: con 5 de 25 retiradas y las otras 20 llenas, salía 80% cuando
// el hotel estuvo al 100%.
//
// Es una aproximación: si una habitación se retira a mitad del periodo, esto
// la cuenta como retirada todo el periodo. Para repartirlo bien haría falta
// guardar desde cuándo lo está, y esa columna no existe.
$totalHab = $pdo->query("select count(*) as t from habitaciones where estado <> 'inactiva'")
    ->fetch(PDO::FETCH_ASSOC)['t'] ?? 0;

// Noches vendidas en el periodo.
//
// Antes esto cruzaba obligatoriamente contra `reservas` y sumaba los días de
// la RESERVA. Dos fallos en una línea: las estancias de mostrador no aportaban
// ni una noche —no tienen reserva con la que cruzar—, y las que sí la tenían
// contaban las noches pactadas en vez de las dormidas. Con nueve check-outs en
// octubre la ocupación marcaba 0,3%.
//
// Ahora se cuenta sobre las estancias y por fecha, con el mismo criterio que
// cobra el sistema: cambios de fecha, mínimo una noche. Es el equivalente en
// SQL de nochesEstancia() (includes/noches.php); si allí cambia la regla, aquí
// hay que cambiarla también.
$stmt = $pdo->prepare("
    select coalesce(sum(greatest(datediff(date(e.fecha_checkout), date(e.fecha_checkin)), 1)), 0) as noches
    from estancias e
    where e.estado = 'cerrada'
      and e.fecha_checkout >= ?
      and e.fecha_checkout < ?
");
$stmt->execute([$desde, $hasta]);
$nochesVendidas = (int)($stmt->fetch(PDO::FETCH_ASSOC)['noches'] ?? 0);

// Días del periodo (para la capacidad total)
$diasPeriodo = (int)round((strtotime($hasta) - strtotime($desde)) / 86400);
$capacidadNoches = $totalHab * $diasPeriodo;
$ocupacionPct = $capacidadNoches > 0 ? round($nochesVendidas / $capacidadNoches * 100, 1) : 0;

// Etiqueta legible para el desglose
$mesesCortos = [1=>'Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
function etiquetaPeriodo($p, $vista, $mesesCortos) {
    if ($vista === 'mes') return date('d/m', strtotime($p));
    $partes = explode('-', $p);
    return $mesesCortos[(int)$partes[1]] . ' ' . $partes[0];
}

// Genera el HTML del badge de variación (flecha + %) para una tarjeta.
function badgeVariacion($var, $etiquetaPrev) {
    if ($var === null) {
        return '<div class="var-badge var-neutral"><i class="fas fa-minus"></i> Sin dato previo</div>';
    }
    if ($var > 0) {
        return '<div class="var-badge var-up"><i class="fas fa-arrow-up"></i> ' . number_format($var, 1, ',', '.') . '% vs ' . $etiquetaPrev . '</div>';
    }
    if ($var < 0) {
        return '<div class="var-badge var-down"><i class="fas fa-arrow-down"></i> ' . number_format(abs($var), 1, ',', '.') . '% vs ' . $etiquetaPrev . '</div>';
    }
    return '<div class="var-badge var-neutral"><i class="fas fa-equals"></i> Igual que ' . $etiquetaPrev . '</div>';
}

$currentDir = 'reportes';
$currentPage = 'ingresos';
$pageTitle = 'Reportes de Ingresos';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
/* ============================================================
   Reportes de Ingresos — Rediseño móvil (Casa Bosque)
   ============================================================ */
.rp-wrap { padding-bottom: 90px; }

/* Selector de periodo */
.rp-period { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); padding: 14px; margin-bottom: 16px; }
.rp-toggle { display: flex; gap: 8px; margin-bottom: 12px; }
.rp-toggle a { flex: 1; text-align: center; font-size: .84rem; font-weight: 700; padding: 9px; border-radius: 11px; text-decoration: none; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; color: var(--cb-muted,#9A8778); }
.rp-toggle a.on { background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color: #fff; border: none; }
.rp-period-title { text-align: center; font-size: 1rem; font-weight: 800; color: var(--cb-forest,#3E2412); margin-bottom: 10px; }
.rp-pinput { width: 100%; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 11px; padding: 11px 14px; font-family: inherit; font-size: .9rem; text-align: center; color: var(--cb-text,#2A1709); }
.rp-pinput:focus { outline: none; border-color: var(--cb-primary,#6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }

/* Tarjeta total */
.rp-total { background: linear-gradient(150deg,#6B4420,#9C6728); border-radius: 20px; padding: 20px; color: #fff; box-shadow: 0 10px 26px rgba(107,68,32,.22); margin-bottom: 13px; position: relative; overflow: hidden; }
.rp-total::after { content: ''; position: absolute; inset: 0; background: radial-gradient(100% 80% at 100% 0, rgba(201,162,94,.2), transparent 60%); }
.rp-total .lbl { font-size: .8rem; opacity: .9; position: relative; }
.rp-total .amt { font-size: 2.1rem; font-weight: 800; margin: 4px 0 8px; letter-spacing: -.5px; position: relative; }
.rp-total .sub { font-size: .76rem; opacity: .85; position: relative; }

/* badge variación (usado por badgeVariacion()) */
.var-badge { display: inline-flex; align-items: center; gap: 6px; font-size: .74rem; font-weight: 700; padding: 5px 11px; border-radius: 99px; }
.var-up { background: rgba(255,255,255,.18); color: #fff; }
.var-down { background: rgba(255,255,255,.18); color: #ffd9d2; }
.var-neutral { background: rgba(255,255,255,.14); color: rgba(255,255,255,.8); }

/* mini tarjetas */
.rp-mini { display: grid; grid-template-columns: 1fr 1fr; gap: 11px; margin-bottom: 16px; }
.rp-mc { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 16px; padding: 15px; box-shadow: 0 4px 14px rgba(42,23,9,.05); }
.rp-mc .mn { font-size: 1.6rem; font-weight: 800; color: var(--cb-forest,#3E2412); line-height: 1; }
.rp-mc .ml { font-size: .72rem; color: var(--cb-muted,#9A8778); margin-top: 6px; }
.rp-mc.occ .mn { color: var(--cb-primary,#6B4420); }

/* desglose hospedaje/consumos */
.rp-split { display: flex; gap: 11px; margin-bottom: 16px; }
.rp-split .s { flex: 1; background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 14px; padding: 13px; text-align: center; }
.rp-split .s .sv { font-size: 1.1rem; font-weight: 800; }
.rp-split .s.h .sv { color: var(--cb-secondary,#9C6728); }
.rp-split .s.c .sv { color: #b45309; }
.rp-split .s .sl { font-size: .68rem; color: var(--cb-muted,#9A8778); margin-top: 3px; }

/* Ventas directas de mostrador */
.rp-vent { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 16px; padding: 15px; margin-bottom: 11px; box-shadow: 0 4px 12px rgba(42,23,9,.04); }
.rp-vent .vh { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.rp-vent .vh h3 { font-size: .9rem; font-weight: 700; color: var(--cb-forest,#3E2412); margin: 0; display: flex; align-items: center; gap: 7px; }
.rp-vent .vh h3 i { color: #b45309; }
.rp-vent .vn { font-size: .72rem; color: var(--cb-muted,#9A8778); font-weight: 600; }
.rp-vent .vt { font-size: 1.5rem; font-weight: 800; color: #b45309; margin: 7px 0 0; letter-spacing: -.3px; }
.rp-vent .vm { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 9px; }
.rp-vent .vm span { font-size: .72rem; color: var(--cb-ink-2,#6E6054); background: var(--cb-primary-light,#F5EBDC); border-radius: 7px; padding: 4px 9px; }
.rp-vent .vm b { font-weight: 700; }
.rp-vent .vnota { font-size: .71rem; color: var(--cb-muted,#9A8778); line-height: 1.5; margin-top: 10px; }

/* Total general */
.rp-gran { background: var(--cb-primary-light,#F5EBDC); border: 1px solid #EBDCC2; border-radius: 16px; padding: 14px 16px; margin-bottom: 16px; }
.rp-gran .gl { font-size: .74rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--cb-secondary,#9C6728); }
.rp-gran .gv { font-size: 1.6rem; font-weight: 800; color: var(--cb-forest,#3E2412); margin: 3px 0 2px; letter-spacing: -.4px; }
.rp-gran .gs { font-size: .72rem; color: var(--cb-muted,#9A8778); }

/* card genérica */
.rp-card { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); margin-bottom: 16px; overflow: hidden; }
.rp-card-h { padding: 16px 16px 0; }
.rp-card-h h3 { font-size: 1rem; font-weight: 700; color: var(--cb-forest,#3E2412); display: flex; align-items: center; gap: 8px; margin: 0; }
.rp-card-b { padding: 16px; }

/* gráfico de barras */
.rp-chart { display: flex; align-items: flex-end; gap: 3px; height: 120px; overflow-x: auto; padding-bottom: 4px; }
.rp-chart::-webkit-scrollbar { height: 4px; }
.rp-bar-col { flex: 1; min-width: 9px; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; gap: 5px; }
.rp-bar { width: 100%; min-height: 2px; border-radius: 4px 4px 2px 2px; background: linear-gradient(180deg,#6B4420,#6B4420); }
.rp-bar-lbl { font-size: .54rem; color: var(--cb-muted,#9A8778); white-space: nowrap; }

/* por recepcionista */
.rp-user { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--gray-100,#F1EADF); }
.rp-user:last-child { border-bottom: none; }
.rp-user .av { flex: none; width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: .82rem; }
.rp-user .info { flex: 1; min-width: 0; }
.rp-user .nm { font-size: .88rem; font-weight: 700; color: var(--cb-forest,#3E2412); }
.rp-user .ck { font-size: .72rem; color: var(--cb-muted,#9A8778); margin-top: 1px; }
.rp-user .tt { font-size: .92rem; font-weight: 800; color: var(--cb-forest,#3E2412); }

.rp-empty { text-align: center; padding: 28px 12px; color: var(--cb-muted,#9A8778); }
.rp-empty i { font-size: 1.9rem; color: var(--cb-primary,#6B4420); opacity: .4; margin-bottom: 10px; display: block; }
.rp-empty p { font-size: .88rem; margin: 0; }

/* Acciones de descarga / impresión */
.rp-actions { display: flex; flex-wrap: wrap; gap: 9px; justify-content: flex-end; margin-bottom: 16px; }
.rp-act { display: inline-flex; align-items: center; gap: 8px; font-family: inherit; font-size: .82rem; font-weight: 700; border: none; border-radius: 11px; padding: 10px 15px; cursor: pointer; text-decoration: none; }
.rp-act.csv { color: #1e6b34; background: #e6f7ec; }
.rp-act.pdf { color: var(--cb-primary,#6B4420); background: var(--cb-primary-light,#F5EBDC); }

/* Vista de impresión (Guardar como PDF desde el navegador) */
@media print {
    .sidebar, #sidebar, .top-bar, .bottom-nav, nav, .more-overlay,
    #cbHelpBtn, #cbChat, #cbTour, .cb-tour-card,
    .rp-actions, .rp-period .rp-toggle, #rp-form { display: none !important; }
    html, body { background: #fff !important; height: auto !important; overflow: visible !important; }
    .main-content { margin-left: 0 !important; min-height: 0 !important; }
    .content-area { padding: 0 !important; }
    .rp-total, .rp-mc, .rp-bar-fill { -webkit-print-color-adjust: exact; print-color-adjust: exact; box-shadow: none !important; }
}
</style>

<div class="rp-wrap">

<!-- Selector de periodo -->
<div class="rp-period">
    <div class="rp-toggle">
        <a href="?vista=mes" class="<?= $vista==='mes' ? 'on' : '' ?>">Por mes</a>
        <a href="?vista=anio" class="<?= $vista==='anio' ? 'on' : '' ?>">Por año</a>
    </div>
    <div class="rp-period-title"><?= htmlspecialchars($tituloPeriodo) ?></div>
    <form method="GET" id="rp-form">
        <input type="hidden" name="vista" value="<?= $vista ?>">
        <?php if ($vista === 'mes'): ?>
            <input type="month" name="mes" class="rp-pinput" value="<?= substr($desde,0,7) ?>" onchange="document.getElementById('rp-form').submit()">
        <?php else: ?>
            <input type="number" name="anio" class="rp-pinput" value="<?= $anio ?>" min="2020" max="2100" onchange="document.getElementById('rp-form').submit()">
        <?php endif; ?>
    </form>
</div>

<?php $expQS = 'vista=' . $vista . ($vista === 'mes' ? '&mes=' . urlencode(substr($desde,0,7)) : '&anio=' . (int)$anio); ?>
<div class="rp-actions">
    <a href="exportar_ingresos.php?<?= $expQS ?>" class="rp-act csv"><i class="fas fa-file-excel"></i> Descargar Excel</a>
    <a href="ingresos_pdf.php?<?= $expQS ?>" class="rp-act pdf"><i class="fas fa-file-pdf"></i> Descargar PDF</a>
</div>

<!-- Total -->
<div class="rp-total">
    <div class="lbl">Ingresos totales · <?= htmlspecialchars($tituloPeriodo) ?></div>
    <div class="amt">$<?= number_format($tot['total'], 0, ',', '.') ?></div>
    <?= badgeVariacion($varTotal, $etiquetaPrev) ?>
</div>

<!-- Desglose hospedaje / consumos -->
<div class="rp-split">
    <div class="s h"><div class="sv">$<?= number_format($tot['hospedaje'], 0, ',', '.') ?></div><div class="sl">Hospedaje</div></div>
    <div class="s c"><div class="sv">$<?= number_format($tot['consumos'], 0, ',', '.') ?></div><div class="sl">Consumos</div></div>
</div>

<!-- Ventas directas de mostrador -->
<div class="rp-vent">
    <div class="vh">
        <h3><i class="fas fa-mug-hot"></i> Ventas directas de mostrador</h3>
        <span class="vn"><?= (int) $ventas['ventas'] ?> venta(s) · <?= (int) $ventas['unidades'] ?> unidad(es)</span>
    </div>
    <div class="vt"><?= cajaCop($ventas['total']) ?></div>
    <?php if (!empty($ventas['por_metodo'])): ?>
        <div class="vm">
            <?php foreach ($ventas['por_metodo'] as $met => $val): ?>
                <span><b><?= htmlspecialchars(cajaMetodoNombre($met)) ?></b> <?= cajaCop($val) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="vnota">
        No se suman a los ingresos de arriba, que son el valor de las estancias cerradas.
        El total de todo está en la línea siguiente.
    </div>
</div>

<!-- Total general -->
<div class="rp-gran">
    <div class="gl">Total general del periodo</div>
    <div class="gv"><?= cajaCop($totalGeneral) ?></div>
    <div class="gs">Estancias cerradas <?= cajaCop((float) $tot['total']) ?> + mostrador <?= cajaCop($ventas['total']) ?></div>
</div>

<!-- Mini métricas -->
<div class="rp-mini">
    <div class="rp-mc"><div class="mn"><?= (int)$tot['checkouts'] ?></div><div class="ml">Check-outs cerrados</div></div>
    <div class="rp-mc occ"><div class="mn"><?= number_format($ocupacionPct, 1, ',', '.') ?>%</div><div class="ml">Ocupación del periodo</div></div>
</div>

<!-- Gráfico de evolución -->
<div class="rp-card">
    <div class="rp-card-h"><h3><i class="fas fa-chart-column" style="color:var(--cb-primary,#6B4420);"></i> Evolución de ingresos</h3></div>
    <div class="rp-card-b">
        <?php if (empty($desglose)): ?>
            <div class="rp-empty"><i class="fas fa-chart-simple"></i><p>Sin check-outs cerrados en este periodo.</p></div>
        <?php else: ?>
            <div class="rp-chart">
                <?php foreach ($desglose as $d):
                    $h = $maxTotal > 0 ? max(2, round($d['total'] / $maxTotal * 100)) : 2;
                ?>
                    <div class="rp-bar-col" title="<?= etiquetaPeriodo($d['periodo'], $vista, $mesesCortos) ?>: $<?= number_format($d['total'], 0, ',', '.') ?>">
                        <div class="rp-bar" style="height:<?= $h ?>%;"></div>
                        <div class="rp-bar-lbl"><?= etiquetaPeriodo($d['periodo'], $vista, $mesesCortos) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Por recepcionista -->
<div class="rp-card">
    <div class="rp-card-h"><h3><i class="fas fa-user-tie" style="color:var(--cb-primary,#6B4420);"></i> Ingresos por recepcionista</h3></div>
    <div class="rp-card-b" style="padding-top:8px;">
        <?php if (empty($porUsuario)): ?>
            <div class="rp-empty"><i class="fas fa-users"></i><p>Sin datos de recepcionistas en el periodo.</p></div>
        <?php else: ?>
            <?php foreach ($porUsuario as $u): ?>
                <div class="rp-user">
                    <div class="av"><?= strtoupper(mb_substr($u['nombre'],0,1,'UTF-8')) ?></div>
                    <div class="info">
                        <div class="nm"><?= htmlspecialchars($u['nombre']) ?></div>
                        <div class="ck"><?= (int)$u['checkouts'] ?> check-out(s)</div>
                    </div>
                    <div class="tt">$<?= number_format($u['total'], 0, ',', '.') ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

</div><!-- /.rp-wrap -->

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>