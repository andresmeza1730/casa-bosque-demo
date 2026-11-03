<?php
// ============================================================
// modules/reportes/ingresos_pdf.php
// Informe de Ingresos — hoja para imprimir y PDF para descargar
// ============================================================
// Mismos parámetros que ingresos.php (vista, mes, anio). Solo administrador.
//
// DOS SALIDAS, UN SOLO JUEGO DE CIFRAS
//
// La hoja HTML que se imprime y el PDF que se descarga salen de `$datos`, que
// se arma UNA vez más abajo: la hoja se pinta desde ese arreglo y el generador
// recibe `json_encode($datos)`. Así el papel y el archivo no pueden decir
// cosas distintas del mismo período. Es el mismo recurso que includes/caja.php
// un nivel más arriba.
//
// POR QUÉ EL PDF SE DIBUJA Y NO SE FOTOGRAFÍA
//
// Hasta 3.6.0 el PDF se generaba capturando este HTML con html2canvas, y salía
// con el ANCHO DE LA PANTALLA de quien lo descargaba: desde un celular, las
// columnas desacomodadas. Era una imagen, así que el texto no se podía
// seleccionar ni buscar y se pixelaba al acercar. Ahora lo dibuja
// assets/js/reportes-vectorial.js con primitivas de jsPDF, y las tablas largas
// saltan de página repitiendo la cabecera en vez de partirse por donde caiga.
// ------------------------------------------------------------
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/caja.php';   // las ventas de mostrador, en un solo sitio
require_once __DIR__ . '/../../includes/establecimiento.php';
requireAdmin();

$pdo = getDB();

// ── Periodo (igual que ingresos.php) ──
$vista = ($_GET['vista'] ?? 'mes') === 'anio' ? 'anio' : 'mes';
$mesesEs = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$mesesCortos = [1=>'Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

if ($vista === 'mes') {
    $mes = $_GET['mes'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');
    $desde = $mes . '-01';
    $hasta = date('Y-m-d', strtotime($desde . ' +1 month'));
    $tituloPeriodo = $mesesEs[(int)date('n', strtotime($desde))] . ' ' . date('Y', strtotime($desde));
    $colHead = 'Día';
} else {
    $anio = (int)($_GET['anio'] ?? date('Y'));
    if ($anio < 2020 || $anio > 2100) $anio = (int)date('Y');
    $desde = $anio . '-01-01';
    $hasta = ($anio + 1) . '-01-01';
    $tituloPeriodo = 'Año ' . $anio;
    $colHead = 'Mes';
}

// ── Desglose por día (mes) o por mes (año) ──
if ($vista === 'mes') {
    $stmt = $pdo->prepare("
        select date(fecha_checkout) as periodo,
               coalesce(sum(subtotal_noches),0) as hospedaje,
               coalesce(sum(total_consumos),0) as consumos,
               coalesce(sum(total_pagar),0) as total,
               count(*) as checkouts
        from estancias
        where estado = 'cerrada' and fecha_checkout >= ? and fecha_checkout < ?
        group by date(fecha_checkout) order by periodo");
} else {
    $stmt = $pdo->prepare("
        select date_format(fecha_checkout,'%Y-%m') as periodo,
               coalesce(sum(subtotal_noches),0) as hospedaje,
               coalesce(sum(total_consumos),0) as consumos,
               coalesce(sum(total_pagar),0) as total,
               count(*) as checkouts
        from estancias
        where estado = 'cerrada' and fecha_checkout >= ? and fecha_checkout < ?
        group by date_format(fecha_checkout,'%Y-%m') order by periodo");
}
$stmt->execute([$desde, $hasta]);
$desglose = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Por empleado ──
$stmt = $pdo->prepare("
    select u.nombre, count(*) as checkouts,
           coalesce(sum(e.subtotal_noches),0) as hospedaje,
           coalesce(sum(e.total_consumos),0) as consumos,
           coalesce(sum(e.total_pagar),0) as total
    from estancias e, usuarios u
    where e.usuario_checkout = u.id and e.estado = 'cerrada'
      and e.fecha_checkout >= ? and e.fecha_checkout < ?
    group by u.id, u.nombre order by total desc");
$stmt->execute([$desde, $hasta]);
$porUsuario = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Totales ──
$tH = $tC = $tT = $tCO = 0;
foreach ($desglose as $d) { $tH += $d['hospedaje']; $tC += $d['consumos']; $tT += $d['total']; $tCO += $d['checkouts']; }


// ── Ventas directas de mostrador ──
// No se suman a $tT: ese número sigue siendo el valor de las estancias
// cerradas, igual que antes y que en la pantalla. El total de todo va aparte.
// `$hasta` ya es el primer día EXCLUIDO.
$ventas = cajaVentasDelPeriodo($pdo, $desde, $hasta, $vista === 'mes' ? 'dia' : 'mes');
$totalGeneral = (float) $tT + $ventas['total'];

/** El mismo formato en la hoja y en el PDF. */
function cop($n) { return '$' . number_format((float) $n, 0, ',', '.'); }

function etiquetaPeriodo($p, $vista, $mc) {
    if ($vista === 'mes') return date('d/m', strtotime($p));
    $mm = (int) substr($p, 5, 2);
    return ($mc[$mm] ?? $p) . ' ' . substr($p, 0, 4);
}

$esta = datosEstablecimiento();

// ── EL ÚNICO JUEGO DE CIFRAS ──
$datos = [
    'hotel'       => $esta['nombre'] ?? 'Casa Bosque',
    'periodo'     => $tituloPeriodo,
    'vista'       => $vista,
    'colPeriodo'  => $vista === 'mes' ? 'Día' : 'Mes',
    'responsable' => getCurrentUserName(),
    'generado'    => date('d/m/Y H:i'),
    'estancias'   => [
        'total'     => (float) $tT,
        'hospedaje' => (float) $tH,
        'consumos'  => (float) $tC,
        'checkouts' => (int) $tCO,
        'filas'     => [],
    ],
    'ventas'      => [
        'total'    => (float) $ventas['total'],
        'ventas'   => (int) $ventas['ventas'],
        'unidades' => (int) $ventas['unidades'],
        'metodos'  => [],
    ],
    'totalGeneral' => (float) $totalGeneral,
    'porUsuario'   => [],
];

foreach ($desglose as $d) {
    $datos['estancias']['filas'][] = [
        'periodo'   => etiquetaPeriodo($d['periodo'], $vista, $mesesCortos),
        'checkouts' => (int) $d['checkouts'],
        'hospedaje' => (float) $d['hospedaje'],
        'consumos'  => (float) $d['consumos'],
        'total'     => (float) $d['total'],
    ];
}
foreach ($ventas['por_metodo'] as $met => $val) {
    $datos['ventas']['metodos'][] = [
        'clave'  => $met,
        'nombre' => cajaMetodoNombre($met),
        'total'  => (float) $val,
    ];
}
foreach ($porUsuario as $u) {
    $datos['porUsuario'][] = [
        'nombre'    => (string) $u['nombre'],
        'checkouts' => (int) $u['checkouts'],
        'hospedaje' => (float) $u['hospedaje'],
        'consumos'  => (float) $u['consumos'],
        'total'     => (float) $u['total'],
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Informe de Ingresos <?= htmlspecialchars($datos['periodo']) ?> — Casa Bosque</title>
<link rel="icon" href="<?= BASE_URL ?>/favicon.svg?v=3" type="image/svg+xml">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous">
<script src="<?= BASE_URL ?>/assets/js/jspdf.umd.js"></script>
<style>
  :root{ --corteza:#3E2412; --bronce:#9C6728; --oro:#C8963C; --arena:#F5EBDC;
         --tinta:#2A1709; --gris:#6E6054; --linea:#EDE3D2; --grisc:#E8E4DD; }
  *{ box-sizing:border-box; margin:0; padding:0; }
  body{ font-family:'Outfit',Arial,Helvetica,sans-serif; background:#EFEAE2; color:var(--tinta);
        padding:22px 14px 50px; -webkit-font-smoothing:antialiased; }

  .controls{ max-width:860px; margin:0 auto 16px; display:flex; gap:9px; justify-content:flex-end; flex-wrap:wrap; }
  .btn{ display:inline-flex; align-items:center; gap:8px; font:700 .86rem inherit; border:0; cursor:pointer;
        border-radius:11px; padding:11px 17px; background:var(--corteza); color:#fff; text-decoration:none; }
  .btn.alt{ background:#fff; color:var(--corteza); border:1px solid var(--linea); }
  .btn[disabled]{ opacity:.6; cursor:default; }

  .aviso{ max-width:860px; margin:0 auto 14px; display:none; align-items:flex-start; gap:10px;
          padding:13px 15px; border-radius:12px; background:#FBE9E7; border:1px solid #F0CFCB;
          color:#8A2E27; font-size:.85rem; line-height:1.55; }
  .aviso.visible{ display:flex; }
  .aviso i{ margin-top:2px; }

  .sheet{ max-width:860px; margin:0 auto; background:#fff; border-radius:4px; overflow:hidden;
          box-shadow:0 14px 40px rgba(42,23,9,.16); }
  .topbar{ height:9px; background:var(--corteza); }
  .head{ padding:24px 34px 8px; }
  .head-row{ display:flex; align-items:flex-start; justify-content:space-between; gap:16px; }
  .brand{ display:inline-flex; align-items:center; gap:11px; background:var(--corteza);
          border-radius:999px; padding:9px 20px 9px 10px; }
  .brand img{ display:block; width:30px; height:30px; border-radius:50%; background:#fff; }
  .brand .nm{ color:#fff; font-size:.95rem; font-weight:800; line-height:1.15; }
  .brand .sl{ color:#D6C6B0; font-size:.63rem; letter-spacing:.04em; }
  .dots{ display:grid; grid-template-columns:repeat(4,8px); gap:6px; padding-top:6px; }
  .dots i{ width:5px; height:5px; border-radius:50%; background:var(--oro); display:block; }
  .doc-t{ font-family:Georgia,'Times New Roman',serif; font-size:2.1rem; font-weight:700;
          color:var(--bronce); letter-spacing:-.02em; margin:16px 0 2px; }
  .doc-s{ font-size:.82rem; color:var(--gris); }

  .meta{ display:grid; grid-template-columns:repeat(3,1fr); background:#EEEBE6; margin:16px 34px 0; }
  .meta .m{ padding:11px 14px; }
  .meta .lbl{ font-size:.6rem; font-weight:800; letter-spacing:.09em; text-transform:uppercase; color:var(--gris); }
  .meta .val{ font-size:.88rem; font-weight:700; color:var(--corteza); margin-top:3px; }

  .body{ padding:18px 34px 6px; }
  .sec{ display:flex; align-items:center; gap:9px; margin:20px 0 10px; }
  .sec:first-child{ margin-top:0; }
  .sec .dot{ width:9px; height:9px; border-radius:50%; background:var(--oro); flex:none; }
  .sec h3{ font-size:.95rem; font-weight:800; color:var(--corteza); margin:0; }

  table{ width:100%; border-collapse:collapse; }
  thead th{ background:var(--grisc); color:var(--corteza); font-size:.68rem; font-weight:800;
            letter-spacing:.04em; text-align:left; padding:8px 9px; }
  tbody td{ padding:7px 9px; font-size:.76rem; border-bottom:1px solid var(--linea); }
  tbody tr:nth-child(even) td{ background:#FAF8F4; }
  .num{ text-align:right; } .ctr{ text-align:center; }
  tfoot td{ background:var(--arena); color:var(--corteza); font-size:.8rem; font-weight:800;
            padding:8px 9px; border-top:2px solid var(--corteza); border-bottom:3px double var(--corteza); }
  .empty{ text-align:center; color:var(--gris); font-style:italic; padding:16px 0 !important; }

  .gran{ background:var(--corteza); color:#fff; border-radius:3px; padding:15px 18px; margin:18px 0 0;
         display:flex; align-items:flex-end; justify-content:space-between; gap:14px; flex-wrap:wrap; }
  .gran .gl{ font-size:.62rem; font-weight:800; letter-spacing:.1em; text-transform:uppercase; color:#D6C6B0; }
  .gran .gs{ font-size:.7rem; color:#C4B096; margin-top:4px; }
  .gran .gv{ font-size:1.7rem; font-weight:800; letter-spacing:-.02em; }

  .nota{ background:var(--arena); border-radius:3px; padding:13px 16px; margin-top:8px;
         font-size:.74rem; color:var(--gris); line-height:1.7; }
  .foot{ padding:16px 34px; font-size:.68rem; color:var(--gris); text-align:center; }
  .strip{ height:9px; background:var(--corteza); border-top:3px solid var(--oro); }

  @media (max-width:680px){
    .head{ padding:18px; } .head-row{ flex-direction:column; } .meta{ grid-template-columns:1fr; margin:14px 18px 0; }
    .body{ padding:14px 18px 4px; } .foot{ padding:14px 18px; }
    thead th, tbody td, tfoot td{ padding:6px 5px; font-size:.7rem; } .doc-t{ font-size:1.6rem; }
  }
  @media print{
    body{ background:#fff !important; padding:0 !important; }
    .controls, .aviso{ display:none !important; }
    .sheet{ max-width:100% !important; box-shadow:none !important; border-radius:0 !important; }
    .topbar, .strip, thead th, tfoot td, .brand, .meta, .gran, .nota{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    @page{ size:A4; margin:10mm; }
  }
</style>
</head>
<body>

<div class="controls">
  <button class="btn" id="btnPdf" onclick="descargarPDF()"><i class="fas fa-file-pdf"></i> Descargar PDF</button>
  <button class="btn alt" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
  <a class="btn alt" href="ingresos.php?vista=<?= htmlspecialchars($vista) ?><?= $vista === 'mes' ? '&mes=' . htmlspecialchars(substr($desde, 0, 7)) : '&anio=' . (int) $anio ?>"><i class="fas fa-arrow-left"></i> Volver</a>
</div>

<div class="aviso" id="avisoPdf">
  <i class="fas fa-triangle-exclamation"></i>
  <span id="avisoTexto"></span>
</div>

<div class="sheet">
  <div class="topbar"></div>
  <div class="head">
    <div class="head-row">
      <div class="brand">
        <img src="<?= BASE_URL ?>/assets/img/logo-96.png?v=3" alt="" id="emblema" width="30" height="30">
        <div>
          <div class="nm"><?= htmlspecialchars($datos['hotel']) ?></div>
          <div class="sl">Sistema de Gestión Hotelera</div>
        </div>
      </div>
      <div class="dots"><?php for ($i = 0; $i < 12; $i++): ?><i></i><?php endfor; ?></div>
    </div>
    <div class="doc-t">Informe de Ingresos</div>
    <div class="doc-s"><?= htmlspecialchars($datos['periodo']) ?></div>
  </div>

  <div class="meta">
    <div class="m"><div class="lbl">Preparado por</div><div class="val"><?= htmlspecialchars($datos['responsable']) ?></div></div>
    <div class="m"><div class="lbl">Período</div><div class="val"><?= htmlspecialchars($datos['periodo']) ?></div></div>
    <div class="m"><div class="lbl">Generado</div><div class="val"><?= htmlspecialchars($datos['generado']) ?></div></div>
  </div>

  <div class="body">

    <div class="sec"><span class="dot"></span><h3>Ingresos por estancias cerradas</h3></div>
    <table>
      <thead><tr>
        <th><?= htmlspecialchars($datos['colPeriodo']) ?></th><th class="ctr">Check-outs</th>
        <th class="num">Hospedaje</th><th class="num">Consumos</th><th class="num">Total</th>
      </tr></thead>
      <tbody>
        <?php if (empty($datos['estancias']['filas'])): ?>
          <tr><td colspan="5" class="empty">Sin check-outs cerrados en el período.</td></tr>
        <?php else: foreach ($datos['estancias']['filas'] as $f): ?>
          <tr>
            <td><?= htmlspecialchars($f['periodo']) ?></td>
            <td class="ctr"><?= $f['checkouts'] ?></td>
            <td class="num"><?= cop($f['hospedaje']) ?></td>
            <td class="num"><?= cop($f['consumos']) ?></td>
            <td class="num"><?= cop($f['total']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <tfoot><tr>
        <td>TOTAL ESTANCIAS</td><td class="ctr"><?= $datos['estancias']['checkouts'] ?></td>
        <td class="num"><?= cop($datos['estancias']['hospedaje']) ?></td>
        <td class="num"><?= cop($datos['estancias']['consumos']) ?></td>
        <td class="num"><?= cop($datos['estancias']['total']) ?></td>
      </tr></tfoot>
    </table>

    <div class="sec"><span class="dot"></span><h3>Ventas directas de mostrador</h3></div>
    <table>
      <thead><tr><th>Método de pago</th><th class="num">Valor</th></tr></thead>
      <tbody>
        <?php if (empty($datos['ventas']['metodos'])): ?>
          <tr><td colspan="2" class="empty">Sin ventas de mostrador en el período.</td></tr>
        <?php else: foreach ($datos['ventas']['metodos'] as $m): ?>
          <tr><td><?= htmlspecialchars($m['nombre']) ?></td><td class="num"><?= cop($m['total']) ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody>
      <tfoot><tr>
        <td>TOTAL MOSTRADOR &nbsp;(<?= $datos['ventas']['ventas'] ?> <?= $datos['ventas']['ventas'] === 1 ? 'venta' : 'ventas' ?>
            / <?= $datos['ventas']['unidades'] ?> <?= $datos['ventas']['unidades'] === 1 ? 'unidad' : 'unidades' ?>)</td>
        <td class="num"><?= cop($datos['ventas']['total']) ?></td>
      </tr></tfoot>
    </table>

    <div class="gran">
      <div>
        <div class="gl">Total general del período</div>
        <div class="gs">Estancias <?= cop($datos['estancias']['total']) ?> + mostrador <?= cop($datos['ventas']['total']) ?></div>
      </div>
      <div class="gv"><?= cop($datos['totalGeneral']) ?></div>
    </div>

    <div class="sec"><span class="dot"></span><h3>Ingresos por recepcionista</h3></div>
    <table>
      <thead><tr>
        <th>Empleado</th><th class="ctr">Check-outs</th>
        <th class="num">Hospedaje</th><th class="num">Consumos</th><th class="num">Total cerrado</th>
      </tr></thead>
      <tbody>
        <?php if (empty($datos['porUsuario'])): ?>
          <tr><td colspan="5" class="empty">Sin datos de recepcionistas en el período.</td></tr>
        <?php else: foreach ($datos['porUsuario'] as $u): ?>
          <tr>
            <td><?= htmlspecialchars($u['nombre']) ?></td>
            <td class="ctr"><?= $u['checkouts'] ?></td>
            <td class="num"><?= cop($u['hospedaje']) ?></td>
            <td class="num"><?= cop($u['consumos']) ?></td>
            <td class="num"><?= cop($u['total']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

    <div class="sec"><span class="dot"></span><h3>Nota</h3></div>
    <div class="nota">
      Las cifras de estancias son el <b>valor facturado</b> de las cuentas cerradas en el período,
      no el dinero recibido: un abono puede haber entrado antes y una deuda cobrarse después.
      Las ventas directas de mostrador no pasan por ninguna cuenta de huésped, por eso van aparte
      y solo se suman en el total general. Para el dinero efectivamente recibido en una fecha,
      el documento es el <b>Arqueo de Caja</b>.
    </div>

  </div>

  <div class="foot">Casa Bosque · Documento generado automáticamente · <?= htmlspecialchars($datos['generado']) ?></div>
  <div class="strip"></div>
</div>

<script src="<?= BASE_URL ?>/assets/js/pdf-casabosque.js?v=<?= defined('APP_VERSION') ? htmlspecialchars(APP_VERSION) : '1' ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/reportes-vectorial.js?v=<?= defined('APP_VERSION') ? htmlspecialchars(APP_VERSION) : '1' ?>"></script>
<script>
// El MISMO arreglo que acaba de pintar la hoja de arriba. Ni una cifra se
// vuelve a calcular aquí.
var INGRESOS = <?= json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function avisar(texto) {
    var caja = document.getElementById('avisoPdf');
    document.getElementById('avisoTexto').textContent = texto;
    caja.classList.add('visible');
}

function descargarPDF() {
    var btn = document.getElementById('btnPdf');
    document.getElementById('avisoPdf').classList.remove('visible');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Generando...';
    try {
        var doc = window.generarIngresosCasaBosquePDF(INGRESOS, {
            logo: document.getElementById('emblema')
        });
        doc.save('Ingresos_' + INGRESOS.periodo.replace(/\s+/g, '_') + '.pdf');
    } catch (e) {
        avisar('No se pudo generar el PDF. Puedes usar el botón Imprimir y guardar como PDF desde el navegador.');
        if (window.console) console.error(e);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-file-pdf"></i> Descargar PDF';
    }
}
</script>
</body>
</html>
