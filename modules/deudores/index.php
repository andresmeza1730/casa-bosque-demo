<?php
// ============================================================
// modules/deudores/index.php
// Deudores — Casa Bosque (4.4.0)
// ============================================================
// Quién quedó debiendo al hacer el check-out, y los pagos de deuda ya recibidos.
//
//  · Lo VEN el administrador y la recepcionista (la deuda de cada huésped ya la
//    ve la recepción en el Historial y en el check-out; esto la junta).
//  · Solo el administrador COBRA, como en el inicio: el POST `pagar_deuda` se
//    corta en el servidor para quien no lo es. El botón de cobrar tampoco se
//    ofrece a la recepcionista, pero eso es solo cortesía.
//  · Un pago se fecha el DÍA EN QUE SE PAGA (no el del check-out) y es ese día
//    el que lo cuenta el Arqueo de Caja. Aquí cada pago sale con su día y hora.
//
// La lista y el cobro viven en includes/deudas.php, y la tarjeta de pago en
// includes/deuda_modal.php: las comparte con el inicio (dashboard.php).
// El texto buscado va en la sesión y no en la URL (includes/busqueda.php).
// ------------------------------------------------------------

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cifrado.php';    // el teléfono se guarda cifrado
require_once __DIR__ . '/../../includes/busqueda.php';   // el texto buscado no va por la URL
require_once __DIR__ . '/../../includes/caja.php';       // cajaMetodoNombre(): cómo se llama cada método
require_once __DIR__ . '/../../includes/deudas.php';     // la lista, los pagos recibidos y el cobro
requireStaff();

busquedaLimpiarSiPiden('deudores');

$pdo = getDB();

// ── Buscar (POST → sesión → redirección) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'buscar') {
    if (validateCsrf()) {
        busquedaGuardar('deudores', (string) ($_POST['q'] ?? ''));
        busquedaRedirigir();
    }
    setFlash('Token de seguridad inválido. Intente de nuevo.', 'danger');
    busquedaRedirigir();
}

// ── Cobrar una deuda: solo el administrador ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'pagar_deuda') {
    if (!validateCsrf()) {
        setFlash('Token de seguridad inválido. Intente de nuevo.', 'danger');
    } elseif (!isAdmin()) {
        setFlash('Solo un administrador puede registrar el pago de una deuda.', 'danger');
    } else {
        [$msgPago, $tipoPago] = deudaRegistrarPago($pdo, (int) ($_POST['estancia_id'] ?? 0), $_POST['monto'] ?? '', $_POST['metodo'] ?? '');
        setFlash($msgPago, $tipoPago);
    }
    busquedaRedirigir();   // vuelve a esta pantalla (el destino sale del servidor) y conserva la búsqueda, que vive en la sesión
}

$buscar   = busquedaRecordada('deudores');
$esAdmin  = isAdmin();
$deudores = deudasLista($pdo, $buscar);
$pagos    = deudasPagosRecibidos($pdo, $buscar);

$totalDeuda = 0.0;
$enMora = 0;
foreach ($deudores as $d) {
    $totalDeuda += (float) $d['saldo'];
    if ((int) $d['dias'] > DEUDA_DIAS_MORA) $enMora++;
}

// Los pagos agrupados por el día EN QUE SE PAGARON, con el total de cada día.
$dias = [];
foreach ($pagos as $p) {
    $dia = date('Y-m-d', strtotime($p['fecha']));
    if (!isset($dias[$dia])) $dias[$dia] = ['total' => 0.0, 'pagos' => []];
    $dias[$dia]['total'] += (float) $p['monto'];
    $dias[$dia]['pagos'][] = $p;
}
$totalPagos = array_sum(array_column($dias, 'total'));

$nombresDia = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$etiquetaDia = static function (string $dia) use ($nombresDia): string {
    $ts = strtotime($dia);
    $hoy = date('Y-m-d');
    $pref = $dia === $hoy ? 'Hoy' : ($dia === date('Y-m-d', strtotime('-1 day')) ? 'Ayer' : $nombresDia[(int) date('w', $ts)]);
    return $pref . ' ' . date('d/m/Y', $ts);
};

$cop = static fn($n): string => '$' . number_format((float) $n, 0, ',', '.');
$esc = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$currentDir  = 'deudores';
$currentPage = 'deudores';
$pageTitle   = 'Deudores';
$flash = getFlash();   // el resultado del pago (lo muestra el pie de página como notificación)
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* ============================================================
   Deudores (Casa Bosque, 4.4.0)
   Tarjetas y no tabla: en móvil una tabla de seis columnas pierde las últimas
   (el armazón recorta lo que se sale de .content-area; se mide con barridos de ancho).
   ============================================================ */
.dd-wrap { max-width: 860px; padding-bottom: 24px; }
.dd-card { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); margin-bottom: 16px; overflow: hidden; }
.dd-head { padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; background: linear-gradient(150deg,#fdecec,#fff); border-bottom: 1px solid #f4caca; }
.dd-head.ok { background: linear-gradient(150deg,#e9f6ee,#fff); border-bottom-color: #cfe8d9; }
.dd-ttl { font-weight: 800; color: #b91c1c; display: flex; align-items: center; gap: 8px; font-size: .98rem; }
.dd-head.ok .dd-ttl { color: #1F7A4D; }
.dd-chip { font-size: .74rem; font-weight: 700; color: #b91c1c; background: #fdeaea; padding: 4px 11px; border-radius: 99px; }
.dd-head.ok .dd-chip { color: #1F7A4D; background: #e1f3e8; }
.dd-body { padding: 4px 16px 10px; }

.dd-find { padding: 12px 16px; display: flex; gap: 8px; flex-wrap: wrap; }
.dd-find input[type=search] { flex: 1 1 200px; min-width: 0; min-height: 44px; padding: 0 13px; border: 1px solid #E7E2D8; border-radius: 11px; font: inherit; font-size: .95rem; box-sizing: border-box; }
.dd-find button, .dd-find a { min-height: 44px; padding: 0 18px; border-radius: 11px; font: inherit; font-weight: 700; font-size: .85rem; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; cursor: pointer; box-sizing: border-box; }
.dd-find button { border: 0; color: #fff; background: #6B4420; }
.dd-find a { border: 1px solid #E7E2D8; color: #475569; background: #fff; }
.dd-filtro { padding: 0 16px 12px; font-size: .8rem; color: #6E6054; }

.dd-deb { padding: 11px 0; border-bottom: 1px solid #f3eee9; }
.dd-deb:last-child { border-bottom: 0; }
.dd-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.dd-info { flex: 1; min-width: 0; }
.dd-name { font-weight: 700; color: #3E2412; font-size: .92rem; overflow-wrap: anywhere; }
.dd-mora { font-size: .6rem; font-weight: 800; color: #fff; background: #b91c1c; padding: 2px 8px; border-radius: 99px; vertical-align: middle; }
.dd-sub { font-size: .76rem; color: #9A8778; margin-top: 2px; overflow-wrap: anywhere; }
.dd-monto { flex: none; font-weight: 800; color: #c0392b; font-size: .98rem; text-align: right; }
.dd-acc { display: flex; gap: 8px; justify-content: flex-end; align-items: center; margin-top: 8px; flex-wrap: wrap; }
.dd-pagar { font: inherit; font-size: .8rem; font-weight: 700; color: #fff; background: #6B4420; border: none; border-radius: 10px; padding: 0 18px; min-height: 44px; cursor: pointer; }
.dd-ic { flex: none; width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; text-decoration: none; }
.dd-ic.tel { background: #F5EBDC; color: #6B4420; font-size: .9rem; }
.dd-ic.wa  { background: #dcf8e6; color: #128C4B; font-size: 1rem; }
.dd-solo-admin { font-size: .74rem; color: #9A8778; margin-right: auto; }

.dd-dia { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 16px; background: #faf6ef; border-top: 1px solid #f0e8da; border-bottom: 1px solid #f0e8da; font-size: .8rem; font-weight: 800; color: #3E2412; }
.dd-dia:first-child { border-top: 0; }
.dd-dia b { color: #1F7A4D; }
.dd-pag { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 10px 16px; border-bottom: 1px solid #f3eee9; }
.dd-pag:last-child { border-bottom: 0; }
.dd-pag .dd-monto { color: #1F7A4D; }
.dd-vacio { padding: 22px 16px; text-align: center; color: #6E6054; font-size: .9rem; }
.dd-nota { padding: 0 16px 14px; font-size: .76rem; color: #9A8778; }
</style>

<div class="dd-wrap">

<div class="dd-card">
    <form method="POST" class="dd-find" role="search">
        <?= csrfField() ?>
        <input type="hidden" name="accion" value="buscar">
        <input type="search" name="q" value="<?= $esc($buscar) ?>" maxlength="<?= BUSQUEDA_MAX ?>" placeholder="Nombre, apellido o habitación" aria-label="Buscar por nombre, apellido o habitación" autocomplete="off">
        <button type="submit"><i class="fas fa-magnifying-glass" style="margin-right:7px;"></i> Buscar</button>
        <?php if ($buscar !== ''): ?>
            <a href="?limpiar=1">Limpiar</a>
        <?php endif; ?>
    </form>
    <?php if ($buscar !== ''): ?>
        <div class="dd-filtro">Mostrando solo lo que coincide con «<b><?= $esc($buscar) ?></b>».</div>
    <?php endif; ?>
</div>

<div class="dd-card">
    <div class="dd-head">
        <span class="dd-ttl"><i class="fas fa-hand-holding-dollar"></i> Huéspedes que deben</span>
        <span class="dd-chip"><?= count($deudores) ?> · <?= $cop($totalDeuda) ?><?= $enMora > 0 ? ' · ' . $enMora . ' en mora' : '' ?></span>
    </div>
    <?php if (!$deudores): ?>
        <div class="dd-vacio"><?= $buscar !== '' ? 'Nadie que deba coincide con esa búsqueda.' : 'Nadie debe nada. Todas las cuentas cerradas están saldadas.' ?></div>
    <?php else: ?>
    <div class="dd-body">
        <?php foreach ($deudores as $d):
            $tel = descifrar($d['telefono']);
            $telDigits = preg_replace('/\D+/', '', (string) $tel);
            $mora = (int) $d['dias'] > DEUDA_DIAS_MORA;
            $waNum = $telDigits !== '' ? (strlen($telDigits) <= 10 ? '57' . $telDigits : $telDigits) : '';
            $nombre = trim($d['nombres'] . ' ' . $d['apellidos']);
        ?>
        <div class="dd-deb">
            <div class="dd-top">
                <div class="dd-info">
                    <div class="dd-name"><?= $esc($nombre) ?><?php if ($mora): ?> <span class="dd-mora">EN MORA</span><?php endif; ?></div>
                    <div class="dd-sub">Hab. <?= $esc($d['habitacion']) ?> · salió el <?= $esc(date('d/m/Y', strtotime($d['fecha_checkout']))) ?> (hace <?= (int) $d['dias'] ?> día(s))<?= $tel ? ' · Tel: ' . $esc($tel) : ' · sin teléfono' ?></div>
                </div>
                <div class="dd-monto"><?= $cop($d['saldo']) ?></div>
            </div>
            <div class="dd-acc">
                <?php if (!$esAdmin): ?><span class="dd-solo-admin">Lo cobra el administrador</span><?php endif; ?>
                <?php if ($esAdmin): ?>
                    <button type="button" class="dd-pagar" onclick='abrirPago(<?= (int) $d['id'] ?>, <?= htmlspecialchars(json_encode($nombre), ENT_QUOTES) ?>, <?= (int) $d['saldo'] ?>)'>Registrar pago</button>
                <?php endif; ?>
                <?php if ($telDigits !== ''): ?>
                    <a href="tel:<?= $esc($telDigits) ?>" title="Llamar" aria-label="Llamar" class="dd-ic tel"><i class="fas fa-phone"></i></a>
                    <a href="https://wa.me/<?= $esc($waNum) ?>" target="_blank" rel="noopener" title="WhatsApp" aria-label="WhatsApp" class="dd-ic wa"><i class="fab fa-whatsapp"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="dd-card">
    <div class="dd-head ok">
        <span class="dd-ttl"><i class="fas fa-circle-check"></i> Pagos de deuda recibidos</span>
        <span class="dd-chip">Últimos <?= (int) DEUDA_DIAS_PAGOS ?> días · <?= $cop($totalPagos) ?></span>
    </div>
    <?php if (!$dias): ?>
        <div class="dd-vacio"><?= $buscar !== '' ? 'Ningún pago de deuda coincide con esa búsqueda.' : 'Todavía no se ha recibido ningún pago de deuda en estos días.' ?></div>
    <?php else: ?>
        <?php foreach ($dias as $dia => $g): ?>
            <div class="dd-dia"><span><?= $esc($etiquetaDia($dia)) ?></span><b><?= $cop($g['total']) ?></b></div>
            <?php foreach ($g['pagos'] as $p): ?>
                <div class="dd-pag">
                    <div class="dd-info">
                        <div class="dd-name"><?= $esc(trim($p['nombres'] . ' ' . $p['apellidos'])) ?></div>
                        <div class="dd-sub"><?= $esc(date('H:i', strtotime($p['fecha']))) ?> · Hab. <?= $esc($p['habitacion']) ?> · <?= $esc(cajaMetodoNombre((string) $p['metodo'])) ?><?= $p['usuario'] ? ' · recibió ' . $esc($p['usuario']) : '' ?></div>
                    </div>
                    <div class="dd-monto"><?= $cop($p['monto']) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <div class="dd-nota" style="padding-top:12px;">Cada pago cuenta en el Arqueo de Caja del día en que se recibió, no del día del check-out.</div>
    <?php endif; ?>
</div>

</div>

<?php if ($esAdmin): ?>
<?php $deudaModalAction = BASE_URL . '/modules/deudores/index.php'; require __DIR__ . '/../../includes/deuda_modal.php'; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
