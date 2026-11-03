<?php
// Módulo: Generador de Reporte de Facturación (Solución Definitiva Cliente-Side)
// Ubicación exacta: modules/checkout/factura_pdf.php

// La base ANTES de auth.php, y el orden importa: auth.php solo revalida el
// rol, la cuenta, la sesión cerrada y el tope de 12 h si getDB() ya existe.
// Al revés no falla —se salta los cuatro controles en silencio—.
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

// Control de sesión seguro
if (!isset($_SESSION['usuario_id'])) {
    die("Error de seguridad: Acceso denegado. Por favor, inicie sesion en el sistema.");
}
requireAdmin();

require_once __DIR__ . '/../../includes/cifrado.php';
require_once __DIR__ . '/../../includes/dian.php';
require_once __DIR__ . '/../../includes/volver.php';
require_once __DIR__ . '/../../includes/establecimiento.php';
require_once __DIR__ . '/../../includes/tarifas.php';   // detalleOcupacion()

// A dónde vuelve esta página. Es autónoma —no lleva el menú—, así que el botón
// «Volver» es la única salida y tiene que devolver al módulo de origen.
$__volver = volverDestino();

$pdo = getDB();
$id_factura = (int)($_GET['factura'] ?? 0);
if ($id_factura < 1) {
    http_response_code(404);
    exit('No existe una factura emitida para mostrar.');
}
$stmtF = $pdo->prepare("select * from facturas where id=?");
$stmtF->execute([$id_factura]);
$facturaEmitida = $stmtF->fetch();
if (!$facturaEmitida) {
    http_response_code(404);
    exit('Factura no encontrada.');
}
$id_estancia = (int)$facturaEmitida['estancia_id'];

// Datos institucionales del hotel. Ya no se escriben aquí: salen de
// config/establecimiento.php por medio de datosEstablecimiento(), que es el
// único sitio del que salen. Estaban duplicados con el correo de la factura
// (retirado en 3.13.0), y cambiar el teléfono obligaba a acordarse de ambos.
$hotel_info = datosEstablecimiento();
// El bloque de marca de esta página muestra el subtítulo en minúsculas.
$hotel_info['slogan'] = ucfirst(mb_strtolower($hotel_info['subtitulo'], 'UTF-8'));

// 1. Obtención de datos maestros usando join implícito clásico (en minúsculas)
$stmtE = $pdo->prepare("select e.id, e.fecha_checkin, e.fecha_checkout, e.tarifa_noche, e.num_personas,
                               e.subtotal_noches, e.total_consumos, e.total_pagar, e.total_abonado,
                               e.iva_exento, e.pasaporte_num, e.sello_migratorio,
                               h.numero, t.nombre as tipo, t.tarifa_extra,
                               hu.nombres, hu.apellidos, hu.documento_num
                        from estancias e
                        join habitaciones h on h.id = e.habitacion_id
                        join huespedes hu on hu.id = e.huesped_id
                        left join tipos_habitacion t on t.id = h.tipo_id
                        where e.id = ?");
$stmtE->execute([$id_estancia]);
$factura = $stmtE->fetch();

if (!$factura) {
    die("No se encontro el registro de facturacion de esta estancia.");
}

// Descifrar campos sensibles para mostrarlos en la factura.
$factura['documento_num'] = descifrar($factura['documento_num']);
$factura['pasaporte_num'] = descifrar($factura['pasaporte_num']);
$facturaEmitida['documento_num'] = descifrar($facturaEmitida['documento_num']);
$facturaEmitida['direccion'] = descifrar($facturaEmitida['direccion']);
$facturaEmitida['telefono'] = descifrar($facturaEmitida['telefono']);
$facturaEmitida['correo'] = descifrar($facturaEmitida['correo']);

// Factura electrónica DIAN (null si DIAN está desactivado o no hay emisión).
$facturaDian = dianFacturaDeEstancia($pdo, $id_estancia);

// 2. Carga de consumos anexos (con join a productos en una sola consulta)
$stmtC = $pdo->prepare("select c.producto_id, c.cantidad, c.precio_unit, c.subtotal,
                               c.descripcion_manual, p.nombre as nombre_producto
                        from consumos c
                        left join productos p on c.producto_id = p.id
                        where c.estancia_id = ? and c.anulado_en is null");
$stmtC->execute([$id_estancia]);
$consumos = $stmtC->fetchAll();

// Procesar nombres para pasarlos limpios a JS
$tabla_items = [];
foreach ($consumos as $c) {
    if (!empty($c['producto_id'])) {
        $detalleItem = $c['nombre_producto'] ?? 'Producto no encontrado';
    } else {
        $detalleItem = !empty($c['descripcion_manual']) ? $c['descripcion_manual'] : 'Consumo Extra';
    }
    $tabla_items[] = [
        'descripcion' => $detalleItem,
        'cantidad' => $c['cantidad'],
        'precio' => (int)$c['precio_unit'],
        'subtotal' => (int)$c['subtotal']
    ];
}
?>
<?php
// Helpers de presentación
function cop($n){ return '$' . number_format((float)$n, 0, ',', '.'); }

// Noches (a partir del subtotal y la tarifa)
$tarifaN = (int)($factura['tarifa_noche'] ?? 0);
$noches  = $tarifaN > 0 ? (int)round((float)$factura['subtotal_noches'] / $tarifaN) : 1;
if ($noches < 1) $noches = 1;

$folio = $facturaEmitida['numero'];
$receptorNombre = $facturaEmitida['razon_social'];
$receptorDocumento = $facturaEmitida['tipo_receptor'] === 'juridica'
    ? 'NIT ' . $facturaEmitida['nit'] . '-' . $facturaEmitida['nit_dv']
    : $facturaEmitida['documento_tipo'] . ' ' . $facturaEmitida['documento_num'];

// ¿Hay datos de contacto del hotel?
$h_dir  = trim($hotel_info['direccion'] ?? '');
$h_ciu  = trim($hotel_info['ciudad'] ?? '');
$h_tel  = trim($hotel_info['telefono'] ?? '');
$h_mail = trim($hotel_info['email'] ?? '');
$h_nit  = trim($hotel_info['nit_completo'] ?? '');
$tieneContacto = ($h_dir || $h_ciu || $h_tel || $h_mail);

// ────────────────────────────────────────────────────────────────────────
//  Datos para el PDF vectorial
// ────────────────────────────────────────────────────────────────────────
// El PDF ya no se saca fotografiando esta página: se dibuja con primitivas,
// en milímetros, a partir de este contrato de datos
// (assets/js/factura-vectorial.js). Antes se capturaba el HTML con
// html2canvas y el documento salía con el ancho de la PANTALLA de quien lo
// descargaba: desde un celular, columnas descuadradas y el pie partido en una
// segunda hoja.
//
// Lo que va aquí tiene que decir lo MISMO que la factura en pantalla. Si se
// cambia una cifra en una, hay que cambiarla en la otra.

$itemsFactura = [[
    'concepto'      => 'Hospedaje',
    'detalle'       => 'Habitación ' . $factura['numero'] . ' · ' . detalleOcupacion((int) $factura['num_personas'], $factura) . ' · tarifa por noche',
    'cantidad'      => $noches,
    'valorUnitario' => (int) round((float) $factura['tarifa_noche']),
    'importe'       => (int) round((float) $factura['subtotal_noches']),
]];
foreach ($tabla_items as $it) {
    $itemsFactura[] = [
        'concepto'      => $it['descripcion'],
        'detalle'       => 'Consumo',
        'cantidad'      => (int) $it['cantidad'],
        'valorUnitario' => (int) $it['precio'],
        'importe'       => (int) $it['subtotal'],
    ];
}

// El hotel emite con consecutivo interno y no cobra IVA. La fila del IVA se
// imprime igualmente, en cero: es lo que deja claro bajo qué régimen se emitió
// el documento. El motor admite IVA de verdad si algún día hiciera falta.
// La redacción vive en includes/establecimiento.php, compartida con el tiquete
// y con el correo: tres documentos, una sola redacción.
$ivaExento   = !empty($factura['iva_exento']);
$notaRegimen = notaRegimenIva($ivaExento, $factura['pasaporte_num'] ?? null, $factura['sello_migratorio'] ?? null);

$abonadoTot = (float) ($factura['total_abonado'] ?? 0);
$saldoTot   = (float) $factura['total_pagar'] - $abonadoTot;
$esJuridica = ($facturaEmitida['tipo_receptor'] === 'juridica');

$datosFactura = [
    'folio'           => $folio,
    'fechaEmision'    => date('d/m/Y', strtotime($facturaEmitida['emitida_en'])),
    'horaEmision'     => date('H:i', strtotime($facturaEmitida['emitida_en'])),
    'anulada'         => (($facturaEmitida['estado'] ?? 'emitida') === 'anulada'),
    'fechaAnulacion'  => !empty($facturaEmitida['anulada_en'])
                            ? date('d/m/Y H:i', strtotime($facturaEmitida['anulada_en'])) : null,
    'motivoAnulacion' => $facturaEmitida['motivo_anulacion'] ?: null,
    'emisor' => [
        'nombre'    => $hotel_info['nombre'],
        'subtitulo' => $hotel_info['subtitulo'],
        'sistema'   => $hotel_info['sistema'],
        'direccion' => $h_dir,
        'ciudad'    => $h_ciu,
        'telefono'  => $h_tel,
        'email'     => $h_mail,
        'nit'       => $h_nit,
    ],
    'cliente' => [
        'nombre'          => $receptorNombre,
        'tipoDocumento'   => $esJuridica ? 'NIT' : (string) $facturaEmitida['documento_tipo'],
        'numeroDocumento' => $esJuridica
                                ? $facturaEmitida['nit'] . '-' . $facturaEmitida['nit_dv']
                                : (string) $facturaEmitida['documento_num'],
        'tipoPersona'     => $esJuridica ? 'Persona jurídica' : 'Persona natural',
        'direccion'       => (string) $facturaEmitida['direccion'],
        'telefono'        => (string) $facturaEmitida['telefono'],
        'email'           => (string) $facturaEmitida['correo'],
    ],
    'estadia' => [
        'habitacion' => 'Habitación ' . $factura['numero'],
        'checkIn'    => date('d/m/Y', strtotime($factura['fecha_checkin'])),
        'checkOut'   => !empty($factura['fecha_checkout'])
                            ? date('d/m/Y', strtotime($factura['fecha_checkout'])) : '—',
        'noches'     => $noches,
    ],
    'items' => $itemsFactura,
    'impuestos' => [
        'aplicaIva'            => false,
        'porcentajeIva'        => 0,
        'ivaIncluidoEnTarifas' => false,
        'conceptoLegalIva'     => conceptoIva($ivaExento),
        'notaRegimen'          => $notaRegimen,
    ],
    'totales' => [
        'hospedaje' => (int) round((float) $factura['subtotal_noches']),
        'consumos'  => (int) round((float) $factura['total_consumos']),
        'subtotal'  => (int) round((float) $factura['subtotal_noches'] + (float) $factura['total_consumos']),
        'iva'       => 0,
        'total'     => (int) round((float) $facturaEmitida['total']),
        'abonado'   => (int) round($abonadoTot),
        'saldo'     => $saldoTot > 0.5 ? (int) round($saldoTot) : 0,
    ],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Factura <?= $folio ?> — Casa Bosque</title>
<link rel="icon" href="/favicon.svg?v=3" type="image/svg+xml">
<link rel="alternate icon" href="/favicon.png" type="image/png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous">
<script src="/assets/js/jspdf.umd.js"></script>
<style>
  :root{
    --verde:#6B4420; --verde2:#9C6728; --forest:#3E2412; --bg:#e9efe7;
    --tinta:#2A1709; --muted:#86715A; --line:#EDE3D2; --light:#F7F2EA; --gold:#C8963C;
  }
  *{ box-sizing:border-box; margin:0; padding:0; }
  body{ font-family:'Segoe UI',system-ui,sans-serif; background:var(--bg); padding:20px 12px 40px; color:var(--tinta); }

  .controls{ width:760px; max-width:100%; margin:0 auto 16px; display:flex; gap:10px; flex-wrap:wrap; justify-content:center; }
  .btn{ background:var(--verde); color:#fff; border:none; padding:12px 22px; font-family:inherit; font-size:.92rem; font-weight:700;
    border-radius:11px; cursor:pointer; box-shadow:0 8px 18px rgba(0,110,37,.28); display:inline-flex; align-items:center; gap:8px; text-decoration:none; }
  .btn.alt{ background:#fff; color:var(--forest); border:1px solid rgba(27,67,50,.18); box-shadow:0 6px 14px rgba(0,0,0,.06); }
  .btn:active{ transform:scale(.97); }
  .btn:disabled{ opacity:.6; cursor:default; }

  .sheet{ width:760px; max-width:100%; margin:0 auto; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 20px 60px rgba(20,54,31,.18); }

  .head{ position:relative; background:linear-gradient(135deg,#241607,#6B4420 55%,#9C6728); color:#fff; padding:34px 40px 30px; overflow:hidden; }
  .head::after{ content:''; position:absolute; width:300px; height:300px; border-radius:50%; top:-150px; right:-90px; background:radial-gradient(circle, rgba(201,162,94,.34), transparent 68%); }
  .head-row{ position:relative; display:flex; justify-content:space-between; align-items:flex-start; gap:16px; }
  .brand{ display:flex; align-items:center; gap:14px; }
  .logo{ width:54px; height:54px; border-radius:15px; background:rgba(255,255,255,.16); display:flex; align-items:center; justify-content:center; }
  .logo svg{ width:30px; height:30px; }
  .brand .nm{ font-size:1.5rem; font-weight:800; letter-spacing:.5px; }
  .brand .sl{ font-size:.72rem; opacity:.85; letter-spacing:2px; text-transform:uppercase; margin-top:2px; }
  .doc{ text-align:right; }
  .doc .t{ font-size:1.7rem; font-weight:800; letter-spacing:3px; }
  .doc .f{ font-size:.84rem; opacity:.9; margin-top:4px; }
  .doc .badge{ display:inline-block; margin-top:8px; background:rgba(255,255,255,.18); padding:5px 13px; border-radius:99px; font-size:.74rem; font-weight:700; }

  .parties{ display:grid; grid-template-columns:1fr 1fr; }
  .party{ padding:24px 40px; }
  .party + .party{ border-left:1px solid var(--line); }
  .party .lbl{ font-size:.68rem; font-weight:800; letter-spacing:1.5px; text-transform:uppercase; color:var(--verde); margin-bottom:9px; }
  .party .big{ font-size:1.05rem; font-weight:700; color:var(--forest); }
  .party p{ font-size:.84rem; color:var(--muted); margin-top:3px; line-height:1.5; }
  .party .pend{ color:#A2907C; font-style:italic; }
  .party .chip{ display:inline-block; background:var(--light); color:var(--verde); font-weight:700; font-size:.74rem; padding:3px 10px; border-radius:7px; margin-top:8px; }

  .stay{ display:flex; border-top:1px solid var(--line); border-bottom:1px solid var(--line); background:#fafdf9; flex-wrap:wrap; }
  .stay .cell{ flex:1; min-width:120px; padding:16px 40px; }
  .stay .cell + .cell{ border-left:1px solid var(--line); }
  .stay .k{ font-size:.66rem; text-transform:uppercase; letter-spacing:1px; color:var(--muted); }
  .stay .v{ font-size:1rem; font-weight:700; color:var(--forest); margin-top:3px; }

  .body{ padding:26px 40px 10px; }
  table{ width:100%; border-collapse:collapse; }
  thead th{ background:var(--light); color:var(--forest); font-size:.72rem; text-transform:uppercase; letter-spacing:.6px; padding:11px 12px; text-align:left; }
  thead th:last-child, tbody td:last-child{ text-align:right; }
  thead th:nth-child(2), tbody td:nth-child(2){ text-align:center; }
  thead th:nth-child(3), tbody td:nth-child(3){ text-align:right; }
  thead th:first-child{ border-radius:9px 0 0 9px; }
  thead th:last-child{ border-radius:0 9px 9px 0; }
  tbody td{ padding:13px 12px; font-size:.86rem; border-bottom:1px solid var(--line); color:var(--tinta); }
  tbody tr td:first-child{ font-weight:600; }
  .item-sub{ display:block; font-size:.72rem; color:var(--muted); font-weight:400; margin-top:2px; }

  .totals{ display:flex; justify-content:flex-end; padding:18px 40px 0; }
  .totbox{ width:300px; }
  .totline{ display:flex; justify-content:space-between; padding:8px 0; font-size:.9rem; color:var(--muted); }
  .totline span:last-child{ color:var(--forest); font-weight:700; }
  .grand{ display:flex; justify-content:space-between; align-items:center; margin-top:8px; padding:16px 18px; background:linear-gradient(135deg,#6B4420,#9C6728); color:#fff; border-radius:13px; box-shadow:0 10px 24px rgba(0,110,37,.28); }
  .grand .gl{ font-size:.82rem; opacity:.9; letter-spacing:.5px; }
  .grand .gv{ font-size:1.5rem; font-weight:800; }

  .foot{ margin-top:26px; padding:24px 40px 30px; border-top:1px solid var(--line); display:flex; justify-content:space-between; align-items:flex-end; gap:20px; }
  .foot .thanks{ font-size:1.05rem; font-weight:800; color:var(--verde); }
  .foot .note{ font-size:.78rem; color:var(--muted); margin-top:5px; max-width:330px; line-height:1.5; }
  .foot .stamp{ text-align:center; }
  .foot .stamp .ring{ width:74px; height:74px; border:2px dashed var(--gold); border-radius:50%; display:flex; align-items:center; justify-content:center; color:var(--verde); font-weight:800; font-size:.66rem; text-align:center; line-height:1.2; }
  .foot .stamp .cap{ font-size:.64rem; color:var(--muted); margin-top:6px; }
  .strip{ height:8px; background:linear-gradient(90deg,#6B4420,#9C6728,#C8963C); }

  @media (max-width:680px){
    .head{ padding:22px 18px; } .head-row{ flex-direction:column; gap:14px; } .doc{ text-align:left; }
    .doc .t{ font-size:1.4rem; } .brand .nm{ font-size:1.25rem; }
    .parties{ grid-template-columns:1fr; } .party{ padding:18px; }
    .party + .party{ border-left:none; border-top:1px solid var(--line); }
    .stay .cell{ flex:1 1 50%; padding:13px 18px; border-top:1px solid var(--line); }
    .body{ padding:18px; } thead th, tbody td{ padding:9px 7px; font-size:.78rem; }
    .totals{ padding:14px 18px 0; } .totbox{ width:100%; } .grand .gv{ font-size:1.25rem; }
    .foot{ flex-direction:column; align-items:flex-start; gap:16px; padding:20px 18px; }
  }

  /* Aviso de factura anulada */
  .anulada-aviso{
    background:#b91c1c; color:#fff; padding:14px 20px; text-align:center;
    border-bottom:3px solid #7f1d1d;
  }
  .anulada-aviso .t{ font-size:1.05rem; font-weight:800; letter-spacing:.06em; }
  .anulada-aviso .s{ margin-top:5px; font-size:.78rem; opacity:.92; line-height:1.5; }

  @media print{
    body{ background:#fff !important; padding:0 !important; }
    .controls{ display:none !important; }
    .sheet{ width:100% !important; max-width:100% !important; margin:0 !important; box-shadow:none !important; border-radius:0 !important; }
    .head{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .grand, .strip, thead th, .party .chip{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    /* El aviso de anulada DEBE imprimirse con su color: es lo que impide
       que una factura sin validez circule como si lo fuera. */
    .anulada-aviso{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    @page{ size:letter; margin:12mm; }
  }
</style>
</head>
<body>

<div class="controls">
  <button class="btn" id="btnPdf" onclick="descargarPDF()"><i class="fas fa-file-pdf"></i> Descargar PDF</button>
  <button class="btn alt" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
  <a class="btn alt" href="<?= htmlspecialchars($__volver['url']) ?>"><i class="fas fa-arrow-left"></i> Volver a <?= htmlspecialchars($__volver['etiqueta']) ?></a>
</div>
<div id="pdfAviso" role="alert" style="display:none; margin:0 auto 14px; max-width:760px; padding:11px 14px; border-radius:10px; background:#fdeaea; color:#b3261e; border:1px solid rgba(179,38,30,.25); font-size:.88rem; font-weight:600;"></div>

<div class="sheet" id="factura-sheet">
  <?php if (($facturaEmitida['estado'] ?? 'emitida') === 'anulada'): ?>
    <?php
    /* Una factura anulada impresa no puede pasar por válida: el aviso va
       arriba del todo, en rojo, y se imprime (print-color-adjust). */
    ?>
    <div class="anulada-aviso">
      <div class="t"><i class="fas fa-ban"></i> FACTURA ANULADA — SIN VALIDEZ</div>
      <div class="s">
        Anulada el <?= date('d/m/Y H:i', strtotime((string) $facturaEmitida['anulada_en'])) ?>.
        <?php if (!empty($facturaEmitida['motivo_anulacion'])): ?>
          Motivo: <?= htmlspecialchars((string) $facturaEmitida['motivo_anulacion']) ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
  <div class="head">
    <div class="head-row">
      <div class="brand">
        <div class="logo"><img src="<?= BASE_URL ?>/assets/img/logo-96.png?v=3" alt="" width="38" height="38" style="display:block"></div>
        <div>
          <div class="nm"><?= htmlspecialchars($hotel_info['nombre']) ?></div>
          <?php if (!empty($hotel_info['slogan'])): ?><div class="sl"><?= htmlspecialchars($hotel_info['slogan']) ?></div><?php endif; ?>
        </div>
      </div>
      <div class="doc">
        <div class="t">FACTURA</div>
        <div class="f">Folio #<?= $folio ?></div>
        <div class="badge">Consecutivo interno · no es factura electrónica DIAN</div>
      </div>
    </div>
  </div>

  <div class="parties">
    <div class="party">
      <div class="lbl">Emitida por</div>
      <div class="big"><?= htmlspecialchars($hotel_info['nombre']) ?></div>
      <?php if ($tieneContacto): ?>
        <p>
          <?= $h_dir ? htmlspecialchars($h_dir) . '<br>' : '' ?>
          <?= $h_ciu ? htmlspecialchars($h_ciu) . '<br>' : '' ?>
          <?= $h_tel ? 'Tel: ' . htmlspecialchars($h_tel) . '<br>' : '' ?>
          <?= $h_mail ? htmlspecialchars($h_mail) : '' ?>
        </p>
        <?php if ($h_nit): ?><p>NIT: <?= htmlspecialchars($h_nit) ?></p><?php endif; ?>
      <?php else: ?>
        <p class="pend">— Dirección, ciudad y contacto<br>por configurar —</p>
      <?php endif; ?>
    </div>
    <div class="party">
      <div class="lbl">Facturar a</div>
      <div class="big"><?= htmlspecialchars($receptorNombre) ?></div>
      <p><?= htmlspecialchars($receptorDocumento) ?><br><?= htmlspecialchars($facturaEmitida['direccion']) ?><br>Tel: <?= htmlspecialchars($facturaEmitida['telefono']) ?><br><?= htmlspecialchars($facturaEmitida['correo']) ?></p>
      <span class="chip"><?= $facturaEmitida['tipo_receptor'] === 'juridica' ? 'Persona jurídica' : 'Persona natural' ?> · Habitación <?= htmlspecialchars($factura['numero']) ?></span>
    </div>
  </div>

  <div class="stay">
    <div class="cell"><div class="k">Check-in</div><div class="v"><?= date('d/m/Y', strtotime($factura['fecha_checkin'])) ?></div></div>
    <div class="cell"><div class="k">Check-out</div><div class="v"><?= date('d/m/Y', strtotime($factura['fecha_checkout'])) ?></div></div>
    <div class="cell"><div class="k">Noches</div><div class="v"><?= $noches ?></div></div>
    <div class="cell"><div class="k">Emisión</div><div class="v"><?= date('d/m/Y H:i', strtotime($facturaEmitida['emitida_en'])) ?></div></div>
  </div>

  <div class="body">
    <table>
      <thead>
        <tr><th>Concepto</th><th>Cant.</th><th>Valor unit.</th><th>Importe</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>Hospedaje<span class="item-sub">Habitación <?= htmlspecialchars($factura['numero']) ?> · tarifa por noche</span></td>
          <td><?= $noches ?></td>
          <td><?= cop($factura['tarifa_noche']) ?></td>
          <td><?= cop($factura['subtotal_noches']) ?></td>
        </tr>
        <?php foreach ($tabla_items as $it): ?>
        <tr>
          <td><?= htmlspecialchars($it['descripcion']) ?><span class="item-sub">Consumo</span></td>
          <td><?= (int)$it['cantidad'] ?></td>
          <td><?= cop($it['precio']) ?></td>
          <td><?= cop($it['subtotal']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="totals">
    <div class="totbox">
      <div class="totline"><span>Hospedaje</span><span><?= cop($factura['subtotal_noches']) ?></span></div>
      <div class="totline"><span>Consumos</span><span><?= cop($factura['total_consumos']) ?></span></div>
      <?php if (!empty($factura['iva_exento'])): ?>
        <div class="totline"><span>IVA (Art. 481 E.T.)</span><span>EXENTO</span></div>
      <?php endif; ?>
      <div class="grand"><span class="gl">TOTAL FACTURADO</span><span class="gv"><?= cop($facturaEmitida['total']) ?></span></div>
      <?php
        $abon = (float)($factura['total_abonado'] ?? 0);
        $saldoPend = (float)$factura['total_pagar'] - $abon;
      ?>
      <?php if ($abon > 0 && $saldoPend > 0.5): ?>
        <div class="totline" style="margin-top:8px;"><span>Abonado</span><span><?= cop($abon) ?></span></div>
        <div class="totline"><span style="color:#b91c1c;">Saldo por cobrar</span><span style="color:#b91c1c;"><?= cop($saldoPend) ?></span></div>
      <?php endif; ?>
      <?php if ($abon <= 0 && $saldoPend > 0.5): ?>
        <div class="totline"><span style="color:#b91c1c;">Saldo por cobrar</span><span style="color:#b91c1c;"><?= cop($saldoPend) ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($factura['iva_exento'])): ?>
  <div class="note" style="margin:14px 40px 0; padding:10px 14px; border:1px solid #EADFCE; border-radius:8px; background:#fbfdf9;">
    <strong>Servicio exento de IVA</strong> — Prestación de servicios de hospedaje a turista extranjero no residente (Art. 481 lit. e) del Estatuto Tributario).
    Pasaporte: <strong><?= htmlspecialchars($factura['pasaporte_num'] ?? '') ?></strong> ·
    Sello migratorio (PT): <strong><?= htmlspecialchars($factura['sello_migratorio'] ?? '') ?></strong>
  </div>
  <?php endif; ?>

  <?php if ($facturaDian && !empty($facturaDian['cufe'])): ?>
  <!-- Factura Electrónica DIAN (visible solo cuando DIAN está activo y emitida) -->
  <div class="note" style="margin:14px 40px 0; padding:12px 14px; border:1px solid #EADFCE; border-radius:8px; display:flex; gap:14px; align-items:center;">
    <div id="dianqr"></div>
    <div style="font-size:11px; line-height:1.5;">
      <div><strong>Factura Electrónica DIAN</strong> <?= $facturaDian['numero'] ? '· ' . htmlspecialchars($facturaDian['numero']) : '' ?></div>
      <div><strong>CUFE:</strong> <span style="word-break:break-all;"><?= htmlspecialchars($facturaDian['cufe']) ?></span></div>
    </div>
  </div>
  <?php if (!empty($facturaDian['qr_data'])): ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous"></script>
  <script>new QRCode(document.getElementById('dianqr'), { text: <?= json_encode($facturaDian['qr_data']) ?>, width: 90, height: 90 });</script>
  <?php endif; ?>
  <?php endif; ?>

  <div class="foot">
    <div>
      <div class="thanks">¡Gracias por hospedarte con nosotros!</div>
      <div class="note">Factura interna consecutiva <?= htmlspecialchars($folio) ?> de <?= htmlspecialchars($hotel_info['nombre']) ?>. No corresponde a una factura electrónica DIAN. Consérvala para cualquier aclaración.</div>
    </div>
    <div class="stamp">
      <div class="ring">CASA<br>BOSQUE</div>
      <div class="cap">SIGH-CB</div>
    </div>
  </div>

  <div class="strip"></div>
</div>

<?php /* El emblema del PDF. Va oculto en la página porque su único uso es
         entrar en el documento: jsPDF necesita la imagen ya cargada por el
         navegador. Si no llegara a cargar, el generador dibuja un anillo con
         las iniciales y la factura sale igual. */ ?>
<img id="cb-logo-pdf" src="<?= BASE_URL ?>/assets/img/emblema-96.png?v=<?= defined('APP_VERSION') ? htmlspecialchars(APP_VERSION) : '1' ?>"
     alt="" width="96" height="96" style="display:none" aria-hidden="true">

<script src="<?= BASE_URL ?>/assets/js/factura-vectorial.js?v=<?= defined('APP_VERSION') ? htmlspecialchars(APP_VERSION) : '1' ?>"></script>
<script>
/* Los datos del documento, tal como los calculó el servidor.
   Se escapan las etiquetas al serializar: aquí dentro vienen nombres de
   huéspedes y direcciones, que son datos de fuera y no deben poder cerrar
   este <script>. */
var FACTURA = <?= json_encode($datosFactura, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function descargarPDF() {
  var btn = document.getElementById('btnPdf');
  var aviso = document.getElementById('pdfAviso');
  if (aviso) { aviso.style.display = 'none'; aviso.textContent = ''; }
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Generando…';

  // El dibujado es sincrónico y bloquea el hilo; este respiro deja que el
  // botón se repinte antes de empezar, para que se vea que algo pasa.
  window.setTimeout(function () {
    try {
      var doc = generarFacturaCasaBosquePDF(FACTURA, {
        logo: document.getElementById('cb-logo-pdf')
      });
      doc.save('Factura_' + FACTURA.folio + '.pdf');
    } catch (e) {
      console.error(e);
      // Tarjeta en la propia página, no un cuadro del navegador.
      if (aviso) {
        aviso.textContent = 'No se pudo generar el PDF. Recarga la página e inténtalo de nuevo.';
        aviso.style.display = 'block';
      }
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-file-pdf"></i> Descargar PDF';
    }
  }, 30);
}
</script>
</body>
</html>
