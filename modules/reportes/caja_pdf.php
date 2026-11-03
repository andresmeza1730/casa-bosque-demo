<?php
// ============================================================
// modules/reportes/caja_pdf.php
// Arqueo de Caja — hoja para imprimir y PDF para descargar
// ============================================================
// Solo administrador. Recibe ?fecha=YYYY-MM-DD.
//
// DOS SALIDAS, UN SOLO JUEGO DE CIFRAS
//
// Esta página hace dos cosas: pinta una hoja HTML que se imprime con el botón
// Imprimir, y entrega un PDF que se DIBUJA en el navegador con
// assets/js/reportes-vectorial.js.
//
// Las dos salen de `$datos`, que se arma UNA vez más abajo. La hoja se pinta
// desde ese arreglo y el generador recibe `json_encode($datos)`. Así el papel
// que sale de la impresora y el PDF que se descarga no pueden decir cosas
// distintas del mismo día: no son dos lecturas, es el mismo dato. Es el mismo
// recurso que ya usa includes/caja.php un nivel más arriba, donde se acabó con
// la consulta copiada cinco veces.
//
// POR QUÉ EL PDF SE DIBUJA Y NO SE FOTOGRAFÍA
//
// Hasta 3.6.0 el PDF se generaba capturando este HTML con html2canvas. Era el
// último documento del sistema que lo hacía —la factura dejó de hacerlo en
// 1.7.0—, y el fallo es el mismo: el PDF salía con el ANCHO DE LA PANTALLA de
// quien lo descargaba, desde un celular con las columnas desacomodadas, como
// una imagen que no se puede seleccionar ni buscar y que se pixela al acercar.
// Y en un arqueo tiene una consecuencia propia: una foto no sabe saltar de
// página, así que una lista larga de movimientos se partía por donde cayera.
// ------------------------------------------------------------
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/caja.php';
require_once __DIR__ . '/../../includes/establecimiento.php';
requireAdmin();

$pdo = getDB();

// Fecha (por defecto hoy), validada como fecha real.
$fecha = cajaFechaValida($_GET['fecha'] ?? null);

$recibido  = cajaRecibidoDelDia($pdo, $fecha);
$facturado = cajaFacturadoDelDia($pdo, $fecha);
$descuadre = cajaDescuadre($recibido, $facturado);

// Facturas anuladas ese día. Es el equivalente de la «nota de crédito» de una
// hoja de arqueo contable: lo que se emitió y luego se dejó sin efecto.
$anuladas = 0;
try {
    $st = $pdo->prepare("select count(*) from facturas
                         where estado = 'anulada' and anulada_en >= ? and anulada_en < ?");
    $st->execute([$fecha, date('Y-m-d', strtotime($fecha . ' +1 day'))]);
    $anuladas = (int) $st->fetchColumn();
} catch (Throwable $e) {
    error_log('Arqueo: no se pudieron contar las facturas anuladas: ' . $e->getMessage());
}

$esta = datosEstablecimiento();
$mesesEs = [1=>'enero','febrero','marzo','abril','mayo','junio','julio','agosto',
            'septiembre','octubre','noviembre','diciembre'];
$ts = strtotime($fecha);

// ── EL ÚNICO JUEGO DE CIFRAS ──
$datos = [
    'hotel'       => $esta['nombre'] ?? 'Casa Bosque',
    'fecha'       => $fecha,
    'fechaCorta'  => date('d/m/Y', $ts),
    'fechaLarga'  => date('j', $ts) . ' de ' . $mesesEs[(int) date('n', $ts)] . ' de ' . date('Y', $ts),
    'responsable' => getCurrentUserName(),
    'generado'    => date('d/m/Y H:i'),
    'recibido'    => [
        'total'   => (float) $recibido['total'],
        'pagos'   => (int) $recibido['num_pagos'],
        'ventas'  => (int) $recibido['num_ventas'],
        'metodos' => [],
        'movimientos' => [],
    ],
    'facturado'   => [
        'total'     => (float) $facturado['total'],
        'hospedaje' => (float) $facturado['hospedaje'],
        'consumos'  => (float) $facturado['consumos'],
        'pendiente' => (float) $facturado['pendiente'],
        'cierres'   => (int) $facturado['num_cierres'],
        'anuladas'  => $anuladas,
        'filas'     => [],
    ],
    'descuadre'   => null,
];

foreach ($recibido['por_metodo'] as $met => $d) {
    $datos['recibido']['metodos'][] = [
        'clave'       => $met,
        'nombre'      => cajaMetodoNombre($met),
        'total'       => (float) $d['total'],
        'movimientos' => (int) $d['movimientos'],
    ];
}
foreach ($recibido['movimientos'] as $m) {
    $datos['recibido']['movimientos'][] = [
        'hora'     => date('H:i', strtotime((string) $m['fecha'])),
        'concepto' => (string) $m['concepto'],
        'origen'   => $m['habitacion'] !== null
                      ? 'Hab. ' . $m['habitacion'] . ' · ' . $m['quien']
                      : (string) $m['quien'],
        'metodo'   => cajaMetodoNombre((string) $m['metodo']),
        'monto'    => (float) $m['monto'],
    ];
}
foreach ($facturado['cierres'] as $c) {
    $datos['facturado']['filas'][] = [
        'hora'       => date('H:i', strtotime((string) $c['fecha_checkout'])),
        'habitacion' => (string) $c['habitacion'],
        'huesped'    => trim($c['nombres'] . ' ' . $c['apellidos']),
        'hospedaje'  => (float) $c['subtotal_noches'],
        'consumos'   => (float) $c['total_consumos'],
        'total'      => (float) $c['total_pagar'],
        'debe'       => max(0.0, (float) $c['total_pagar'] - (float) $c['total_abonado']),
    ];
}
if ($descuadre !== null) {
    $datos['descuadre'] = [
        'diferencia' => (float) $descuadre['diferencia'],
        'sentido'    => $descuadre['sentido'],
        'razones'    => $descuadre['razones'],
    ];
}

/** El mismo formato en la hoja y en el PDF. */
function cop($n) { return '$' . number_format((float) $n, 0, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Arqueo de Caja <?= $datos['fechaCorta'] ?> — Casa Bosque</title>
<link rel="icon" href="<?= BASE_URL ?>/favicon.svg?v=3" type="image/svg+xml">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous">
<script src="<?= BASE_URL ?>/assets/js/jspdf.umd.js"></script>
<style>
  :root{ --corteza:#3E2412; --bronce:#9C6728; --oro:#C8963C; --arena:#F5EBDC;
         --tinta:#2A1709; --gris:#6E6054; --linea:#EDE3D2; --grisc:#E8E4DD;
         --rojo:#B3261E; --ambar:#8A5A12; }
  *{ box-sizing:border-box; margin:0; padding:0; }
  body{ font-family:'Outfit',Arial,Helvetica,sans-serif; background:#EFEAE2; color:var(--tinta);
        padding:22px 14px 50px; -webkit-font-smoothing:antialiased; }

  .controls{ max-width:820px; margin:0 auto 16px; display:flex; gap:9px; justify-content:flex-end; flex-wrap:wrap; }
  .btn{ display:inline-flex; align-items:center; gap:8px; font:700 .86rem inherit; border:0; cursor:pointer;
        border-radius:11px; padding:11px 17px; background:var(--corteza); color:#fff; text-decoration:none; }
  .btn.alt{ background:#fff; color:var(--corteza); border:1px solid var(--linea); }
  .btn[disabled]{ opacity:.6; cursor:default; }

  /* Tarjeta de error. Nada de alert() del navegador: el sistema avisa con una
     tarjeta propia, y este era el último sitio que no lo hacía. */
  .aviso{ max-width:820px; margin:0 auto 14px; display:none; align-items:flex-start; gap:10px;
          padding:13px 15px; border-radius:12px; background:#FBE9E7; border:1px solid #F0CFCB;
          color:#8A2E27; font-size:.85rem; line-height:1.55; }
  .aviso.visible{ display:flex; }
  .aviso i{ margin-top:2px; }

  .sheet{ max-width:820px; margin:0 auto; background:#fff; border-radius:4px; overflow:hidden;
          box-shadow:0 14px 40px rgba(42,23,9,.16); }

  /* Cabecera: barra, bloque con el emblema, título grande. */
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
  .band{ background:var(--corteza); color:#fff; font-size:.76rem; font-weight:800; letter-spacing:.07em;
         text-transform:uppercase; padding:6px 11px; margin:16px 0 11px; }
  .band:first-child{ margin-top:0; }

  table{ width:100%; border-collapse:collapse; }
  thead th{ background:var(--grisc); color:var(--corteza); font-size:.68rem; font-weight:800;
            letter-spacing:.04em; text-align:left; padding:8px 9px; }
  tbody td{ padding:7px 9px; font-size:.76rem; border-bottom:1px solid var(--linea); }
  tbody tr:nth-child(even) td{ background:#FAF8F4; }
  .num{ text-align:right; } .ctr{ text-align:center; }
  tfoot td{ background:var(--arena); color:var(--corteza); font-size:.8rem; font-weight:800;
            padding:8px 9px; border-top:2px solid var(--corteza); border-bottom:3px double var(--corteza); }
  .empty{ text-align:center; color:var(--gris); font-style:italic; padding:16px 0 !important; }

  .lineas{ margin:2px 0 0; }
  .lineas .l{ display:flex; justify-content:space-between; padding:5px 2px; font-size:.8rem; }
  .lineas .l.tot{ border-top:2px solid var(--corteza); border-bottom:3px double var(--corteza);
                  margin-top:5px; padding:8px 2px; font-size:.92rem; font-weight:800; color:var(--corteza); }
  .rojo{ color:var(--rojo); font-weight:700; }
  .nota-g{ font-size:.72rem; color:var(--gris); font-style:italic; margin:9px 2px 0; line-height:1.55; }

  .dif{ background:#FFFBF2; border:1px solid #F0DCB4; border-radius:9px; padding:12px 14px; margin:14px 0 0; }
  .dif b{ display:block; color:var(--ambar); font-size:.82rem; margin-bottom:5px; }
  .dif ul{ margin:0 0 0 17px; } .dif li{ font-size:.74rem; color:var(--gris); line-height:1.6; }

  .signs{ display:grid; grid-template-columns:1fr 1fr; gap:46px; padding:40px 34px 18px; }
  .sign .line{ border-top:1px solid var(--gris); }
  .sign .cap{ font-size:.72rem; color:var(--gris); text-align:center; padding-top:5px; }
  .foot{ padding:12px 34px; font-size:.68rem; color:var(--gris); text-align:center; }
  .strip{ height:9px; background:var(--corteza); border-top:3px solid var(--oro); }

  @media (max-width:680px){
    .head{ padding:18px; } .head-row{ flex-direction:column; } .meta{ grid-template-columns:1fr; margin:14px 18px 0; }
    .body{ padding:14px 18px 4px; } .signs{ grid-template-columns:1fr; gap:30px; padding:28px 18px 14px; }
    .foot{ padding:12px 18px; } thead th, tbody td, tfoot td{ padding:6px 5px; font-size:.7rem; }
    .doc-t{ font-size:1.6rem; }
  }
  @media print{
    body{ background:#fff !important; padding:0 !important; }
    .controls, .aviso{ display:none !important; }
    .sheet{ max-width:100% !important; box-shadow:none !important; border-radius:0 !important; }
    .topbar, .strip, .band, thead th, tfoot td, .brand, .meta{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    @page{ size:A4; margin:10mm; }
  }
</style>
</head>
<body>

<div class="controls">
  <button class="btn" id="btnPdf" onclick="descargarPDF()"><i class="fas fa-file-pdf"></i> Descargar PDF</button>
  <button class="btn alt" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
  <a class="btn alt" href="caja.php?fecha=<?= htmlspecialchars($fecha) ?>"><i class="fas fa-arrow-left"></i> Volver</a>
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
    <div class="doc-t">Arqueo de Caja</div>
    <div class="doc-s">Dinero recibido y cuentas cerradas del día</div>
  </div>

  <div class="meta">
    <div class="m"><div class="lbl">Día del arqueo</div><div class="val"><?= htmlspecialchars($datos['fechaLarga']) ?></div></div>
    <div class="m"><div class="lbl">Responsable</div><div class="val"><?= htmlspecialchars($datos['responsable']) ?></div></div>
    <div class="m"><div class="lbl">Generado</div><div class="val"><?= htmlspecialchars($datos['generado']) ?></div></div>
  </div>

  <div class="body">

    <div class="band">1 · Dinero recibido</div>
    <table>
      <thead><tr><th>Método de pago</th><th class="ctr">Movimientos</th><th class="num">Valor</th></tr></thead>
      <tbody>
        <?php if (empty($datos['recibido']['metodos'])): ?>
          <tr><td colspan="3" class="empty">No entró dinero en esta fecha.</td></tr>
        <?php else: foreach ($datos['recibido']['metodos'] as $m): ?>
          <tr>
            <td><?= htmlspecialchars($m['nombre']) ?></td>
            <td class="ctr"><?= $m['movimientos'] ?></td>
            <td class="num"><?= cop($m['total']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <tfoot><tr>
        <td>TOTAL RECIBIDO</td><td></td>
        <td class="num"><?= cop($datos['recibido']['total']) ?></td>
      </tr></tfoot>
    </table>
    <p class="nota-g">Cuente el efectivo del cajón y compárelo con la línea «Efectivo».
       Lo demás debe aparecer en el datáfono o en los extractos.</p>

    <div class="band">2 · Cuentas cerradas · valor facturado</div>
    <div class="lineas">
      <div class="l"><span>Hospedaje</span><span><?= cop($datos['facturado']['hospedaje']) ?></span></div>
      <div class="l"><span>Consumos</span><span><?= cop($datos['facturado']['consumos']) ?></span></div>
      <?php if ($datos['facturado']['anuladas']): ?>
        <div class="l"><span>Facturas anuladas en la fecha</span><span class="rojo"><?= $datos['facturado']['anuladas'] ?></span></div>
      <?php endif; ?>
      <?php if ($datos['facturado']['pendiente'] > 0): ?>
        <div class="l"><span>De esas cuentas, pendiente de cobro</span><span class="rojo"><?= cop($datos['facturado']['pendiente']) ?></span></div>
      <?php endif; ?>
      <div class="l tot">
        <span>TOTAL FACTURADO &nbsp;(<?= $datos['facturado']['cierres'] ?> check-out)</span>
        <span><?= cop($datos['facturado']['total']) ?></span>
      </div>
    </div>

    <?php if ($datos['descuadre'] !== null): ?>
      <div class="dif">
        <b>Facturado y recibido no coinciden: <?= cop($datos['descuadre']['diferencia']) ?>
           de más en lo <?= htmlspecialchars($datos['descuadre']['sentido']) ?>.</b>
        <ul><?php foreach ($datos['descuadre']['razones'] as $r): ?>
          <li><?= htmlspecialchars($r) ?></li>
        <?php endforeach; ?></ul>
      </div>
    <?php else: ?>
      <p class="nota-g">Lo facturado y lo recibido coinciden en esta fecha.</p>
    <?php endif; ?>

    <div class="band">3 · Movimientos del día</div>
    <table>
      <thead><tr>
        <th>Hora</th><th>Concepto</th><th>Origen</th><th>Método</th><th class="num">Valor</th>
      </tr></thead>
      <tbody>
        <?php if (empty($datos['recibido']['movimientos'])): ?>
          <tr><td colspan="5" class="empty">Sin movimientos en esta fecha.</td></tr>
        <?php else: foreach ($datos['recibido']['movimientos'] as $m): ?>
          <tr>
            <td><?= htmlspecialchars($m['hora']) ?></td>
            <td><?= htmlspecialchars($m['concepto']) ?></td>
            <td><?= htmlspecialchars($m['origen']) ?></td>
            <td><?= htmlspecialchars($m['metodo']) ?></td>
            <td class="num"><?= cop($m['monto']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

  </div>

  <div class="signs">
    <div class="sign"><div class="line">&nbsp;</div><div class="cap">Firma del responsable</div></div>
    <div class="sign"><div class="line">&nbsp;</div><div class="cap">Firma de revisión / administración</div></div>
  </div>

  <div class="foot">Casa Bosque · Documento generado automáticamente · <?= htmlspecialchars($datos['generado']) ?></div>
  <div class="strip"></div>
</div>

<script src="<?= BASE_URL ?>/assets/js/pdf-casabosque.js?v=<?= defined('APP_VERSION') ? htmlspecialchars(APP_VERSION) : '1' ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/reportes-vectorial.js?v=<?= defined('APP_VERSION') ? htmlspecialchars(APP_VERSION) : '1' ?>"></script>
<script>
// El MISMO arreglo que acaba de pintar la hoja de arriba. Ni una cifra se
// vuelve a calcular aquí: si se calculara, el papel y el PDF podrían
// discrepar, que es justo lo que se arregló en 3.5.0 un nivel más arriba.
var ARQUEO = <?= json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

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
        var doc = window.generarArqueoCasaBosquePDF(ARQUEO, {
            logo: document.getElementById('emblema')
        });
        doc.save('ArqueoCaja_' + ARQUEO.fecha + '.pdf');
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
