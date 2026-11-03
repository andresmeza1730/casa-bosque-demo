<?php
// ============================================================
// modules/checkout/ticket.php
// Comprobante térmico (80mm) de una estancia cerrada — Casa Bosque
// ============================================================
// Recibe ?id= (estancia cerrada) y arma el ticket con datos reales.
// Imprimible en impresora térmica o guardable como PDF desde el navegador.
// ------------------------------------------------------------

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cifrado.php';
require_once __DIR__ . '/../../includes/volver.php';
require_once __DIR__ . '/../../includes/establecimiento.php';
require_once __DIR__ . '/../../includes/noches.php';
require_once __DIR__ . '/../../includes/tarifas.php';   // detalleOcupacion()
requireLogin();

// A dónde vuelve esta página. Es autónoma —no lleva el menú—, así que el botón
// «Volver» es la única salida y tiene que devolver al módulo de origen.
$__volver = volverDestino();

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);

// Traer la estancia cerrada con sus datos de liquidación
$stmt = $pdo->prepare("
    select e.id, e.fecha_checkin, e.fecha_checkout, e.tarifa_noche, e.num_personas,
           e.subtotal_noches, e.total_consumos, e.total_pagar, e.total_abonado,
           e.iva_exento, e.pasaporte_num, e.sello_migratorio,
           h.numero, t.nombre as tipo, t.tarifa_extra,
           hu.nombres, hu.apellidos, hu.documento_num,
           u.nombre as atendido
    from estancias e
    join habitaciones h     on h.id = e.habitacion_id
    join tipos_habitacion t on t.id = h.tipo_id
    join huespedes hu       on hu.id = e.huesped_id
    -- `left join` a propósito: el cruce era obligatorio y una estancia cerrada
    -- cuyo `usuario_checkout` fuera NULL no devolvía ninguna fila, así que su
    -- tiquete daba 404 «Comprobante no disponible» siendo un comprobante
    -- legítimo. Sin quien atendió, abajo se imprime un guion.
    left join usuarios u    on u.id = e.usuario_checkout
    where e.id = ?
      and e.estado = 'cerrada'
");
$stmt->execute([$id]);
$e = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$e) {
    http_response_code(404);
    echo 'Comprobante no disponible.';
    exit;
}

// Descifrar campos sensibles para el comprobante.
$e['documento_num'] = descifrar($e['documento_num']);
$e['pasaporte_num'] = descifrar($e['pasaporte_num']);

// Calcular número de noches
$ci = new DateTime($e['fecha_checkin']);
$co = new DateTime($e['fecha_checkout']);
$noches = nochesEstancia($ci, $co);

function cop($n){ return '$' . number_format((float)$n, 0, ',', '.'); }

// Datos del emisor: los mismos que imprime la factura, del mismo sitio.
$est = datosEstablecimiento();

// Quién atendió. Puede no haber nadie registrado (ver el `left join` de arriba).
$atendido = trim((string) ($e['atendido'] ?? ''));
if ($atendido === '') { $atendido = '—'; }

$ivaExento = !empty($e['iva_exento']);
$subtotal  = (float) $e['subtotal_noches'] + (float) $e['total_consumos'];
$abon      = (float) ($e['total_abonado'] ?? 0);
$saldoP    = max(0, (float) $e['total_pagar'] - $abon);

// ── Con qué se pagó ──
// La FORMA sale de la propia cuenta: si queda saldo, el huésped se fue a
// crédito. El MEDIO, de los pagos que de verdad se registraron; si no hay
// ninguno, la línea no se pinta en vez de inventar un «efectivo».
$formaPago = $saldoP > 0.5 ? 'Crédito' : 'Contado';
$stmtP = $pdo->prepare("select metodo, sum(monto) as total
                        from pagos
                        where estancia_id = ?
                        group by metodo
                        order by total desc");
$stmtP->execute([$id]);
$mediosPago = [];
foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $mediosPago[] = ucfirst((string) $p['metodo']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Comprobante #<?= (int)$e['id'] ?> · Casa Bosque</title>
<link rel="icon" href="/favicon.svg?v=3" type="image/svg+xml">
<link rel="alternate icon" href="/favicon.png" type="image/png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous">
<style>
  /* Nombres heredados del tema verde. Los valores ya son los del logo (marron/oro). */
  :root{ --verde:#3E2412; --verde2:#6B4420; --tinta:#1a1a1a; }
  *{ box-sizing:border-box; }
  body{
    background:#FBF8F2; font-family:'Courier New', Courier, monospace;
    display:flex; flex-direction:column; align-items:center; padding:30px 16px; margin:0; color:var(--tinta);
  }
  .controls{ margin-bottom:20px; display:flex; gap:10px; flex-wrap:wrap; justify-content:center; }
  .btn{ background:var(--verde2); color:#fff; border:none; padding:12px 22px; font-family:system-ui,sans-serif;
        font-size:.95rem; font-weight:600; border-radius:10px; cursor:pointer; box-shadow:0 6px 16px rgba(62,36,18,.25);
        display:inline-flex; align-items:center; gap:8px; text-decoration:none; }
  .btn.alt{ background:#fff; color:var(--verde); border:1px solid rgba(62,36,18,.2); box-shadow:0 6px 16px rgba(0,0,0,.06); }
  .btn:active{ transform:scale(.96); }

  .ticket{ background:#fff; width:300px; max-width:100%; padding:24px 22px; box-shadow:0 14px 40px rgba(0,0,0,.16); position:relative; }
  .ticket::before, .ticket::after{ content:''; position:absolute; left:0; width:100%; height:10px;
    background-image:radial-gradient(circle at 6px -2px, transparent 6px, #fff 6px); background-size:12px 10px; background-repeat:repeat-x; }
  .ticket::before{ top:-10px; transform:rotate(180deg); } .ticket::after{ bottom:-10px; }

  .center{text-align:center;} .bold{font-weight:bold;}
  .brand-logo{ width:48px; height:48px; margin:0 auto 8px; border-radius:50%; background:var(--verde); color:#fff;
    display:flex; align-items:center; justify-content:center; font-size:1.4rem; }
  .logo-text{ font-size:1.4rem; font-weight:bold; letter-spacing:2px; margin-bottom:2px; }
  .logo-sub{ font-size:.64rem; letter-spacing:3px; text-transform:uppercase; color:var(--verde2); margin-bottom:10px; }
  .info-header p{ margin:1px 0; font-size:.72rem; color:#444; }
  .divider{ border-bottom:1px dashed #999; margin:12px 0; }
  .doc-type{ text-align:center; font-size:.7rem; letter-spacing:2px; text-transform:uppercase; background:var(--verde);
    color:#fff; padding:5px; margin:0 -22px 12px; font-weight:bold; }
  .info-grid{ font-size:.76rem; }
  .info-grid .row{ display:flex; justify-content:space-between; margin:3px 0; gap:8px; }
  .info-grid .row .k{ color:#666; flex-shrink:0; }
  .info-grid .row .v{ font-weight:bold; text-align:right; }
  table{ width:100%; border-collapse:collapse; font-size:.76rem; }
  thead th{ border-bottom:1px solid var(--tinta); padding-bottom:5px; font-weight:bold; font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; }
  tbody td{ padding:5px 0; vertical-align:top; }
  .col-cant{ width:14%; } .col-desc{ width:54%; } .col-total{ width:32%; text-align:right; }
  .item-sub{ font-size:.66rem; color:#888; }
  .totals{ width:100%; font-size:.8rem; margin-top:4px; }
  .totals td{ padding:3px 0; } .totals .lbl{ color:#555; }
  .grand{ font-size:1rem; font-weight:bold; background:#F5EBDC; } .grand td{ padding:8px 6px; }
  .footer{ font-size:.74rem; margin-top:18px; text-align:center; color:#444; }
  .footer .thanks{ font-weight:bold; font-size:.92rem; color:var(--verde); margin-bottom:4px; }
  .footer .stars{ margin-top:12px; letter-spacing:3px; color:#bbb; }

  @media print{
    html, body{ width:80mm; height:auto; }
    body{ background:#fff; padding:0; margin:0; display:block; }
    .controls{ display:none; }
    .ticket{ width:80mm; box-shadow:none; padding:4mm; margin:0; box-sizing:border-box; }
    .ticket::before, .ticket::after{ display:none; }
    .doc-type, .grand, .brand-logo{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  }
</style>
<style id="dyn-page">@page { size: 80mm 297mm; margin: 0; }</style>
</head>
<body>
  <div class="controls">
    <button class="btn" onclick="prepImpresion(); window.print()"><i class="fas fa-print"></i> Imprimir</button>
    <button class="btn alt" onclick="prepImpresion(); window.print()"><i class="fas fa-file-pdf"></i> Guardar PDF</button>
    <a class="btn alt" href="<?= htmlspecialchars($__volver['url']) ?>"><i class="fas fa-arrow-left"></i> Volver a <?= htmlspecialchars($__volver['etiqueta']) ?></a>
  </div>

  <div class="ticket">
    <div class="center info-header">
      <div class="brand-logo"><img src="<?= BASE_URL ?>/assets/img/logo-96.png?v=3" alt="" width="44" height="44" style="display:block"></div>
      <div class="logo-text"><?= htmlspecialchars(mb_strtoupper($est['nombre'], 'UTF-8')) ?></div>
      <div class="logo-sub"><?= htmlspecialchars($est['subtitulo']) ?> · <?= htmlspecialchars($est['sistema']) ?></div>
      <?php /* Los datos del emisor. Antes el tiquete solo decía «Sistema de
               Gestión Hotelera»: ni NIT, ni dirección, ni teléfono. */ ?>
      <?php if ($est['nit_completo'] !== ''): ?>
        <p class="bold">NIT <?= htmlspecialchars($est['nit_completo']) ?></p>
      <?php endif; ?>
      <?php if ($est['direccion'] !== '' || $est['ciudad'] !== ''): ?>
        <p><?= htmlspecialchars(trim($est['direccion'] . ($est['direccion'] !== '' && $est['ciudad'] !== '' ? ' · ' : '') . $est['ciudad'])) ?></p>
      <?php endif; ?>
      <?php if ($est['telefono'] !== ''): ?>
        <p>Tel. <?= htmlspecialchars($est['telefono']) ?></p>
      <?php endif; ?>
    </div>

    <div class="doc-type">Comprobante de estadía</div>

    <div class="info-grid">
      <div class="row"><span class="k">Comprobante</span><span class="v">#CB-<?= str_pad((int)$e['id'], 6, '0', STR_PAD_LEFT) ?></span></div>
      <div class="row"><span class="k">Fecha</span><span class="v"><?= $co->format('d/m/Y H:i') ?></span></div>
      <div class="row"><span class="k">Atendió</span><span class="v"><?= htmlspecialchars($atendido) ?></span></div>
    </div>
    <div class="divider"></div>
    <div class="info-grid">
      <div class="row"><span class="k">Huésped</span><span class="v"><?= htmlspecialchars($e['nombres'] . ' ' . $e['apellidos']) ?></span></div>
      <div class="row"><span class="k">Documento</span><span class="v"><?= htmlspecialchars($e['documento_num']) ?></span></div>
      <div class="row"><span class="k">Habitación</span><span class="v"><?= htmlspecialchars($e['numero'] . ' · ' . $e['tipo']) ?></span></div>
      <div class="row"><span class="k">Estadía</span><span class="v"><?= $ci->format('d/m') ?> → <?= $co->format('d/m') ?> (<?= $noches ?> <?= $noches == 1 ? 'noche' : 'noches' ?>)</span></div>
    </div>

    <div class="divider"></div>

    <table>
      <thead>
        <tr><th class="col-cant" style="text-align:left;">Cant</th><th class="col-desc" style="text-align:left;">Detalle</th><th class="col-total">Valor</th></tr>
      </thead>
      <tbody>
        <tr>
          <td style="text-align:center;"><?= $noches ?></td>
          <td>Hospedaje<div class="item-sub"><?= cop($e['tarifa_noche']) ?> / noche · <?= detalleOcupacion((int)$e['num_personas'], $e) ?></div></td>
          <td style="text-align:right;"><?= cop($e['subtotal_noches']) ?></td>
        </tr>
        <?php if ((float)$e['total_consumos'] > 0): ?>
        <tr>
          <td style="text-align:center;">—</td>
          <td>Consumos<div class="item-sub">Cargos de la estadía</div></td>
          <td style="text-align:right;"><?= cop($e['total_consumos']) ?></td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="divider"></div>

    <table class="totals">
      <tr><td class="lbl" style="text-align:left;">Hospedaje</td><td style="text-align:right;"><?= cop($e['subtotal_noches']) ?></td></tr>
      <tr><td class="lbl" style="text-align:left;">Consumos</td><td style="text-align:right;"><?= cop($e['total_consumos']) ?></td></tr>
      <tr><td class="lbl" style="text-align:left;">Subtotal</td><td style="text-align:right;"><?= cop($subtotal) ?></td></tr>
      <?php /* La fila del IVA se imprime SIEMPRE, aunque valga cero: es lo que
               dice bajo qué régimen se emitió. Antes solo aparecía cuando había
               exención, así que lo normal era no verla nunca. En forma corta:
               la cita del artículo va en la nota de régimen, más abajo. */ ?>
      <tr><td class="lbl" style="text-align:left;"><?= htmlspecialchars(conceptoIva($ivaExento, true)) ?></td><td style="text-align:right;"><?= cop(0) ?></td></tr>
      <tr class="grand"><td style="text-align:left;">TOTAL DE LA CUENTA</td><td style="text-align:right;"><?= cop($e['total_pagar']) ?></td></tr>
      <?php if ($abon > 0 && $saldoP > 0.5): ?>
      <tr><td class="lbl" style="text-align:left;">Abonado</td><td style="text-align:right;"><?= cop($abon) ?></td></tr>
      <tr><td class="lbl" style="text-align:left; color:#b91c1c;">Saldo por cobrar</td><td style="text-align:right; color:#b91c1c;"><?= cop($saldoP) ?></td></tr>
      <?php endif; ?>
      <?php if ($abon <= 0 && $saldoP > 0.5): ?>
      <tr><td class="lbl" style="text-align:left; color:#b91c1c;">Saldo pendiente</td><td style="text-align:right; color:#b91c1c;"><?= cop($saldoP) ?></td></tr>
      <?php endif; ?>
    </table>

    <div class="divider"></div>
    <div class="info-grid">
      <div class="row"><span class="k">Forma de pago</span><span class="v"><?= htmlspecialchars($formaPago) ?></span></div>
      <?php if ($mediosPago): ?>
      <div class="row"><span class="k">Medio de pago</span><span class="v"><?= htmlspecialchars(implode(', ', $mediosPago)) ?></span></div>
      <?php endif; ?>
    </div>

    <div class="divider"></div>
    <?php /* La nota de régimen sale de includes/establecimiento.php, la misma
             que imprime la factura. */ ?>
    <p style="font-size:.62rem; color:#444; line-height:1.4;">
      <?= htmlspecialchars(notaRegimenIva($ivaExento, $e['pasaporte_num'] ?? null, $e['sello_migratorio'] ?? null)) ?>
    </p>

    <div class="divider"></div>

    <div class="footer">
      <div class="thanks">¡Gracias por tu estadía!</div>
      <p>Conserva este comprobante.<br>Te esperamos pronto.</p>
      <p class="bold" style="margin-top:10px; font-size:.66rem; color:#666;">Casa Bosque · SIGH-CB</p>
      <div class="stars">· · · · · · · · · ·</div>
    </div>
  </div>

<?php if (isset($_GET['auto'])): ?>
<script>
  window.addEventListener('load', function () {
    setTimeout(function () { prepImpresion(); window.print(); }, 500);
  });
</script>
<?php endif; ?>
<script>
  // Ajusta el tamaño de pagina al alto real del ticket (forma de ticket, no carta)
  function prepImpresion() {
    var t = document.querySelector('.ticket');
    if (!t) return;
    var hmm = Math.ceil(t.getBoundingClientRect().height * 25.4 / 96) + 8;
    var tag = document.getElementById('dyn-page');
    if (tag) tag.textContent = '@page { size: 80mm ' + hmm + 'mm; margin: 0; }';
  }
</script>
</body>
</html>