<?php
// ============================================================
// modules/recepcion/index.php
// Panel de inicio del Recepcionista — Casa Bosque
// ============================================================
// Página de aterrizaje para el rol 'recepcionista'.
// Muestra solo información operativa del día y accesos directos
// a sus módulos permitidos. NO incluye datos financieros ni
// secciones de administración.
// ------------------------------------------------------------

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/movimientos_dia.php';
require_once __DIR__ . '/../../includes/tarifas.php';   // el precio lo manda el tipo
requireLogin();   // cualquier usuario con sesión válida

// Si por alguna razón entra un admin, lo mandamos a su dashboard.
if (isAdmin()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$pdo = getDB();

// ── Datos operativos del día (sin nada financiero) ──
// Entradas y salidas del día. El cálculo está en includes/movimientos_dia.php,
// compartido con el dashboard: aquí vivían las dos mismas consultas copiadas, y
// contaban lo PENDIENTE creyendo contar lo hecho, así que las cifras bajaban a
// cero conforme avanzaba el turno.
$__mov = movimientosDelDia($pdo);
$checkinsHechos  = $__mov['entradas_hechas'];
$salidasHechas   = $__mov['salidas_hechas'];
$checkinsHoy     = $__mov['entradas_pendientes'];   // llegadas que faltan
$checkoutsHoy    = $__mov['salidas_pendientes'];    // salidas por cerrar

$habDisponibles = $pdo->query("
    select count(*) as total
    from habitaciones
    where estado = 'disponible'
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Cuáles están libres, no solo cuántas. Sin esto el recepcionista veía un
// número y tenía que irse a otra pantalla para contestar "¿tiene una doble?".
$habLibres = $pdo->query("
    select h.numero, h.piso, t.nombre as tipo,
           " . SQL_CAPACIDAD . " as capacidad_max,
           " . SQL_TARIFA . " as tarifa
    from habitaciones h, tipos_habitacion t
    where h.tipo_id = t.id
      and h.estado = 'disponible'
    order by h.piso, cast(h.numero as unsigned), h.numero
")->fetchAll(PDO::FETCH_ASSOC);

// Las que no están libres ni ocupadas: limpieza y mantenimiento. El
// recepcionista necesita saber que existen, porque son las que puede
// recuperar llamando a servicio, no las que debe dar por perdidas.
$habEnEspera = $pdo->query("
    select h.numero, h.piso, h.estado, t.nombre as tipo
    from habitaciones h, tipos_habitacion t
    where h.tipo_id = t.id
      and h.estado in ('limpieza', 'mantenimiento')
    order by h.estado, h.piso, h.numero
")->fetchAll(PDO::FETCH_ASSOC);

$estanciasActivas = $pdo->query("
    select count(*) as total
    from estancias
    where estado = 'activa'
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// ── Alertas Operativas Dinámicas ──
// 1. Salidas pendientes (check-outs programados para hoy o ya atrasados).
//    Se trae fecha_salida para distinguir "hoy" de "atrasada".
$salidasPendientes = $pdo->query("
    select h.numero, hu.nombres, hu.apellidos, r.fecha_salida
    from estancias e, reservas r, habitaciones h, huespedes hu
    where e.reserva_id = r.id
      and e.habitacion_id = h.id
      and e.huesped_id = hu.id
      and e.estado = 'activa'
      and r.fecha_salida <= current_date()
    order by r.fecha_salida
")->fetchAll(PDO::FETCH_ASSOC);

// 2. Entradas pendientes (check-ins de hoy que aún no se han hecho,
//    e incluye reservas confirmadas de días anteriores que no llegaron).
$entradasPendientes = $pdo->query("
    select h.numero, hu.nombres, hu.apellidos, r.id as reserva_id, r.fecha_entrada
    from reservas r, huespedes hu, habitaciones h
    where r.huesped_id = hu.id
      and r.habitacion_id = h.id
      and r.estado = 'confirmada'
      and r.fecha_entrada <= current_date()
    order by r.fecha_entrada
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Hospedados ahora (estancias activas con su fecha de salida,
//    para ver de un vistazo quién está en el hotel y cuándo se va).
// La unión con `reservas` es LEFT a propósito. Un huésped que llega sin
// reservar entra por el check-in de mostrador, y ese inserta la estancia
// SIN reserva_id (checkin/index.php, rama 'sin_reserva'). Con una unión
// obligatoria esas estancias desaparecían de esta lista: el contador de
// arriba decía "2 huéspedes" y aquí se veía uno solo.
//
// `fecha_salida` queda NULL para esos casos, y es correcto que quede así:
// nadie dijo nunca cuándo se van. La vista lo muestra como "sin fecha".
$hospedadosAhora = $pdo->query("
    select h.numero, t.nombre as tipo, hu.nombres, hu.apellidos,
           r.fecha_salida, e.fecha_checkin
    from estancias e
    join habitaciones h      on h.id = e.habitacion_id
    join huespedes hu        on hu.id = e.huesped_id
    join tipos_habitacion t  on t.id = h.tipo_id
    left join reservas r     on r.id = e.reserva_id
    where e.estado = 'activa'
    order by r.fecha_salida is null, r.fecha_salida, h.numero
")->fetchAll(PDO::FETCH_ASSOC);

$hoy = date('Y-m-d');
$flash = getFlash();

$currentDir = 'recepcion';
$currentPage = 'recepcion';
$pageTitle = 'Panel de Recepción';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
/* ============================================================
   Inicio de Recepción — Rediseño limpio (Casa Bosque)
   ============================================================ */
.rc-wrap { max-width: 100%; }

/* ── Saludo ── */
.rc-hello { margin: 4px 2px 18px; }
.rc-hello .rc-hi { font-size: .85rem; color: var(--cb-muted, #9A8778); }
.rc-hello .rc-name { font-family: var(--font-serif, 'Newsreader', serif); font-size: 1.7rem; font-weight: 600; color: var(--cb-forest, #3E2412); line-height: 1.15; margin-top: 1px; letter-spacing: -.3px; }
.rc-search { position: relative; margin-top: 14px; }
.rc-search i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--cb-muted, #9A8778); font-size: .95rem; }
.rc-search input {
    width: 100%; border: 1px solid var(--cb-border, #EDE3D2); background: #fff;
    border-radius: 14px; padding: 13px 16px 13px 42px; font-family: inherit; font-size: .9rem; color: var(--cb-text, #2A1709);
}
.rc-search input:focus { outline: none; border-color: var(--cb-primary, #6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }

/* ── Botón principal: Registrar ingreso (walk-in) ── */
.rc-primary {
    display: flex; align-items: center; gap: 14px; text-decoration: none;
    background: linear-gradient(150deg, #3E2412, #9C6728); color: #fff;
    border-radius: 18px; padding: 16px 18px; margin-bottom: 20px;
    box-shadow: 0 10px 26px rgba(42,23,9,.20); transition: transform .15s ease, box-shadow .2s ease;
}
.rc-primary:active { transform: scale(.99); }
.rc-primary .rc-p-ic { flex: none; width: 48px; height: 48px; border-radius: 14px; background: rgba(255,255,255,.15); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
.rc-primary .rc-p-tx { flex: 1; line-height: 1.25; }
.rc-primary .rc-p-tx b { font-size: 1.08rem; font-weight: 800; display: block; }
.rc-primary .rc-p-tx small { font-size: .78rem; opacity: .82; }
.rc-primary .rc-p-arw { opacity: .85; font-size: 1rem; }

/* ── Banda Resumen de hoy ── */
.rc-today {
    background: linear-gradient(150deg, #3E2412, #9C6728);
    border-radius: 20px; padding: 18px 16px; color: #fff;
    box-shadow: 0 10px 26px rgba(42,23,9,.20); margin-bottom: 22px;
}
.rc-today .rc-today-h { font-size: .82rem; opacity: .9; margin-bottom: 14px; padding-left: 2px; }
.rc-metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.rc-metric {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    text-align: center; gap: 4px; padding: 8px 4px; border-radius: 13px;
    background: rgba(255,255,255,.12); text-decoration: none; color: #fff;
    transition: background .2s ease, transform .15s ease;
}
.rc-metric:active { transform: scale(.96); background: rgba(255,255,255,.2); }
.rc-metric .rc-mnum { font-size: 1.5rem; font-weight: 800; line-height: 1; }
.rc-metric .rc-mlbl { font-size: .6rem; opacity: .92; font-weight: 600; line-height: 1.2; }
/* Lo que todavía falta por hacer, debajo de lo ya hecho. */
.rc-metric .rc-msub { font-size: .55rem; font-weight: 700; color: #E8BA64; line-height: 1.2; }

/* ── Títulos de sección ── */
.rc-sec { display: flex; align-items: center; justify-content: space-between; margin: 0 2px 12px; }
.rc-sec h3 { font-size: 1rem; font-weight: 700; color: var(--cb-forest, #3E2412); display: flex; align-items: center; gap: 8px; }
.rc-sec h3 .rc-count { font-size: .78rem; color: var(--cb-muted, #9A8778); font-weight: 600; }
.rc-sec a { font-size: .78rem; color: var(--cb-primary, #6B4420); font-weight: 600; text-decoration: none; }

/* ── Alertas (Requiere atención) ── */
/* «Requiere atención» es una LISTA, no un carrusel. Antes cada tarjeta medía el 79 % del ancho y las demás
   quedaban a un deslizamiento lateral: con cuatro pendientes se veía una tarjeta y media. Lo urgente tiene que
   leerse sin buscarlo. Toda la tarjeta es el enlace; la etiqueta de acción dice qué va a pasar al tocarla. */
.rc-alerts { display: flex; flex-direction: column; gap: 10px; margin-bottom: 18px; }
.rc-alert {
    display: flex; gap: 12px; align-items: flex-start; min-height: 64px;
    background: #fff; border: 1px solid var(--cb-border, #EDE3D2); border-radius: 16px;
    padding: 14px; text-decoration: none; box-shadow: 0 4px 14px rgba(42,23,9,.05);
}
.rc-alert:active { transform: scale(.99); }
.rc-alert .rc-al-ic { flex: none; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1rem; }
.rc-alert.is-out .rc-al-ic { background: rgba(249,115,22,.12); color: #c2410c; }
.rc-alert.is-in  .rc-al-ic { background: rgba(59,130,246,.12); color: #2563eb; }
.rc-alert .rc-al-body { flex: 1; min-width: 0; }
.rc-alert .rc-al-title { font-size: .9rem; font-weight: 700; color: var(--cb-forest, #3E2412); }
.rc-alert .rc-al-sub { font-size: .8rem; color: var(--cb-muted, #9A8778); margin-top: 2px; overflow-wrap: anywhere; }
.rc-al-ref { white-space: nowrap; }
.rc-al-foot { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px 10px; margin-top: 8px; }
.rc-al-tag { display: inline-block; font-size: .66rem; font-weight: 700; padding: 3px 9px; border-radius: 99px; }
.rc-al-go { display: inline-flex; align-items: center; gap: 6px; font-size: .76rem; font-weight: 700; color: var(--cb-primary, #6B4420); }
.rc-al-tag.late { background: rgba(239,68,68,.12); color: #b91c1c; }
.rc-al-tag.today { background: rgba(249,115,22,.14); color: #b45309; }
.rc-empty { color: var(--cb-muted, #9A8778); font-size: .9rem; margin: 0 4px 22px; }

/* ── Hospedados ahora ── */
.rc-card { background: #fff; border: 1px solid var(--cb-border, #EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); margin-bottom: 18px; overflow: hidden; }
.rc-card-h { padding: 16px 16px 10px; }
.rc-card-b { padding: 0 16px 8px; }
.rc-guest { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--gray-100, #F1EADF); }
.rc-guest:last-child { border-bottom: none; }
.rc-guest .rc-room {
    flex: none; width: 46px; height: 46px; border-radius: 13px; background: var(--cb-primary, #6B4420);
    color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1;
}
.rc-guest .rc-room b { font-size: 1rem; font-weight: 800; }
.rc-guest .rc-room small { font-size: .5rem; font-weight: 700; opacity: .8; margin-top: 2px; letter-spacing: .5px; }
.rc-guest .rc-ginfo { flex: 1; min-width: 0; }
.rc-guest .rc-gname { font-size: .9rem; font-weight: 700; color: var(--cb-forest, #3E2412); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rc-guest .rc-gtipo { font-size: .74rem; color: var(--cb-muted, #9A8778); }
.rc-out { flex: none; font-size: .68rem; font-weight: 700; padding: 5px 10px; border-radius: 8px; }
.rc-out.normal { background: var(--gray-100, #F1EADF); color: var(--cb-muted, #9A8778); }
.rc-out.today { background: rgba(249,115,22,.14); color: #b45309; }
.rc-out.late { background: rgba(239,68,68,.12); color: #b91c1c; }

/* ── Habitaciones libres ── */
/* Rejilla que se adapta: en el móvil del mostrador caben dos o tres por
   fila; en una pantalla ancha, las que quepan. */
.rc-libres { display: grid; grid-template-columns: repeat(auto-fill, minmax(132px, 1fr)); gap: 9px; }
.rc-libre {
    display: flex; flex-direction: column; gap: 2px; padding: 11px 12px;
    background: rgba(31,122,77,.05); border: 1px solid rgba(31,122,77,.22);
    border-radius: 13px; text-decoration: none;
    transition: transform .15s ease, background .2s ease;
}
.rc-libre:active { transform: scale(.96); background: rgba(31,122,77,.11); }
.rc-libre .rc-libre-num  { font-size: 1.12rem; font-weight: 800; color: #1F7A4D; line-height: 1.1; }
.rc-libre .rc-libre-tipo { font-size: .78rem; font-weight: 700; color: var(--cb-forest, #3E2412); }
.rc-libre .rc-libre-meta { font-size: .68rem; color: var(--cb-muted, #9A8778); line-height: 1.35; }

/* Limpieza y mantenimiento: no se pueden dar ahora, pero se recuperan. */
.rc-espera { margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--gray-100, #F1EADF); }
.rc-espera-h { font-size: .7rem; font-weight: 700; letter-spacing: .3px; text-transform: uppercase;
               color: var(--cb-muted, #9A8778); margin-bottom: 8px; }
.rc-chip { display: inline-block; font-size: .7rem; font-weight: 700; padding: 5px 10px;
           border-radius: 8px; margin: 0 6px 6px 0; }
.rc-chip-limp { background: rgba(59,130,246,.12); color: #1d4ed8; }
.rc-chip-mant { background: rgba(245,158,11,.14); color: #b45309; }

/* ── Accesos rápidos ── */
.rc-qa { display: grid; grid-template-columns: repeat(3, 1fr); gap: 11px; }
.rc-qa-item {
    display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 9px;
    background: #fff; border: 1px solid var(--cb-border, #EDE3D2); border-radius: 16px; padding: 16px 8px;
    text-decoration: none; box-shadow: 0 4px 14px rgba(42,23,9,.04); transition: transform .15s ease, box-shadow .2s ease;
}
.rc-qa-item:active { transform: scale(.96); }
.rc-qa-item .rc-qa-ic { width: 44px; height: 44px; border-radius: 13px; background: var(--cb-primary-light, #F5EBDC); color: var(--cb-primary, #6B4420); display: flex; align-items: center; justify-content: center; font-size: 1.15rem; }
.rc-qa-item .rc-qa-t { font-size: .8rem; font-weight: 700; color: var(--cb-forest, #3E2412); }

.rc-flash { display: flex; align-items: center; gap: 10px; padding: 13px 15px; border-radius: 14px; margin-bottom: 16px; font-size: .88rem; background: var(--warning-bg, #fff3e8); color: #b45309; border: 1px solid rgba(249,115,22,.2); }
</style>

<div class="rc-wrap">

<?php if ($flash): ?>
    <div class="rc-flash"><i class="fas fa-circle-info"></i> <?= htmlspecialchars($flash['msg']) ?></div>
<?php endif; ?>

<!-- Saludo -->
<div class="rc-hello">
    <div class="rc-hi">Buen turno,</div>
    <div class="rc-name"><?= htmlspecialchars(getCurrentUserName()) ?></div>
    <?php /* Las sugerencias las pone assets/js/buscador-huespedes.js, que se
             engancha a los formularios con `data-buscador-huespedes`. Mandar el
             formulario sin elegir nada sigue llevando al directorio. */ ?>
    <form class="rc-search" action="<?= BASE_URL ?>/modules/huespedes/index.php" method="POST"
          data-buscador-huespedes
          data-buscar-url="<?= BASE_URL ?>/modules/huespedes/buscar.php"
          data-ficha-url="<?= BASE_URL ?>/modules/huespedes/ficha.php">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="buscar">
        <i class="fas fa-search"></i>
        <input type="text" name="q" placeholder="Buscar huésped por nombre o documento">
    </form>
</div>

<!-- Botón principal: Registrar ingreso -->
<a href="<?= BASE_URL ?>/modules/checkin/index.php" class="rc-primary">
    <span class="rc-p-ic"><i class="fas fa-door-open"></i></span>
    <span class="rc-p-tx"><b>Registrar ingreso</b><small>Recibe un huésped y asígnale habitación</small></span>
    <i class="fas fa-arrow-right rc-p-arw"></i>
</a>

<!-- Resumen de hoy -->
<div class="rc-today">
    <div class="rc-today-h"><i class="far fa-calendar-check"></i> Resumen de hoy · <?= date('d/m/Y') ?></div>
    <div class="rc-metrics">
        <a href="<?= BASE_URL ?>/modules/checkin/index.php" class="rc-metric">
            <?php /* El número grande es lo HECHO; debajo, lo que falta. */ ?>
            <span class="rc-mnum"><?= $checkinsHechos ?></span><span class="rc-mlbl">Entradas</span>
            <?php if ($pend = movimientosPendientesTexto($checkinsHoy)): ?><span class="rc-msub"><?= $pend ?></span><?php endif; ?>
        </a>
        <a href="<?= BASE_URL ?>/modules/checkout/index.php" class="rc-metric">
            <span class="rc-mnum"><?= $salidasHechas ?></span><span class="rc-mlbl">Salidas</span>
            <?php if ($pend = movimientosPendientesTexto($checkoutsHoy)): ?><span class="rc-msub"><?= $pend ?></span><?php endif; ?>
        </a>
        <a href="<?= BASE_URL ?>/modules/calendario/index.php" class="rc-metric">
            <span class="rc-mnum"><?= $habDisponibles ?></span><span class="rc-mlbl">Libres</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/consumos/index.php" class="rc-metric">
            <span class="rc-mnum"><?= $estanciasActivas ?></span><span class="rc-mlbl">Huéspedes</span>
        </a>
    </div>
</div>

<!-- Requiere atención -->
<div class="rc-sec"><h3><i class="fas fa-bell" style="color:#f59e0b;"></i> Requiere atención</h3></div>
<?php if (empty($salidasPendientes) && empty($entradasPendientes)): ?>
    <p class="rc-empty">Todo al día. No hay alertas pendientes.</p>
<?php else: ?>
    <div class="rc-alerts">
        <?php foreach ($salidasPendientes as $salida): ?>
            <a href="<?= BASE_URL ?>/modules/checkout/index.php" class="rc-alert is-out">
                <div class="rc-al-ic"><i class="fas fa-door-closed"></i></div>
                <div class="rc-al-body">
                    <div class="rc-al-title">Hab. <?= htmlspecialchars($salida['numero']) ?> · Salida</div>
                    <div class="rc-al-sub"><?= htmlspecialchars($salida['nombres'] . ' ' . $salida['apellidos']) ?></div>
                    <div class="rc-al-foot">
                        <?php if ($salida['fecha_salida'] < $hoy): ?>
                            <span class="rc-al-tag late">Atrasada · <?= date('d/m', strtotime($salida['fecha_salida'])) ?></span>
                        <?php else: ?>
                            <span class="rc-al-tag today">Sale hoy</span>
                        <?php endif; ?>
                        <span class="rc-al-go">Hacer check-out <i class="fas fa-arrow-right"></i></span>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
        <?php foreach ($entradasPendientes as $entrada): ?>
            <a href="<?= BASE_URL ?>/modules/checkin/index.php" class="rc-alert is-in">
                <div class="rc-al-ic"><i class="fas fa-door-open"></i></div>
                <div class="rc-al-body">
                    <div class="rc-al-title">Hab. <?= htmlspecialchars($entrada['numero']) ?> · Ingreso</div>
                    <div class="rc-al-sub"><?= htmlspecialchars($entrada['nombres'] . ' ' . $entrada['apellidos']) ?> <span class="rc-al-ref">· Reserva #<?= htmlspecialchars($entrada['reserva_id']) ?></span></div>
                    <div class="rc-al-foot">
                        <?php if ($entrada['fecha_entrada'] < $hoy): ?>
                            <span class="rc-al-tag late">No llegó · <?= date('d/m', strtotime($entrada['fecha_entrada'])) ?></span>
                        <?php else: ?>
                            <span class="rc-al-tag today">Check-in hoy</span>
                        <?php endif; ?>
                        <span class="rc-al-go">Hacer check-in <i class="fas fa-arrow-right"></i></span>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Hospedados ahora -->
<?php if (!empty($hospedadosAhora)): ?>
<div class="rc-card">
    <div class="rc-card-h">
        <div class="rc-sec" style="margin:0;">
            <h3><i class="fas fa-bed" style="color:var(--cb-primary,#6B4420);"></i> Hospedados ahora <span class="rc-count">(<?= count($hospedadosAhora) ?>)</span></h3>
        </div>
    </div>
    <div class="rc-card-b">
        <?php foreach ($hospedadosAhora as $g): ?>
            <div class="rc-guest">
                <div class="rc-room"><b><?= htmlspecialchars($g['numero']) ?></b><small>HAB</small></div>
                <div class="rc-ginfo">
                    <div class="rc-gname"><?= htmlspecialchars($g['nombres'] . ' ' . $g['apellidos']) ?></div>
                    <div class="rc-gtipo"><?= htmlspecialchars($g['tipo']) ?></div>
                </div>
                <?php if ($g['fecha_salida'] === null): ?>
                    <?php /* Entró sin reserva: no hay fecha de salida pactada. Se
                             muestra desde cuándo está, que es el dato que sí existe. */ ?>
                    <span class="rc-out normal" title="Entró sin reserva: no hay fecha de salida pactada">
                        Sin fecha · desde <?= date('d/m', strtotime($g['fecha_checkin'])) ?>
                    </span>
                <?php elseif ($g['fecha_salida'] < $hoy): ?>
                    <span class="rc-out late">Atrasada <?= date('d/m', strtotime($g['fecha_salida'])) ?></span>
                <?php elseif ($g['fecha_salida'] === $hoy): ?>
                    <span class="rc-out today">Sale hoy</span>
                <?php else: ?>
                    <span class="rc-out normal">Sale <?= date('d/m', strtotime($g['fecha_salida'])) ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Habitaciones libres -->
<?php /* El contador de arriba decía cuántas hay libres, pero no cuáles. Para
         contestar "¿tiene una doble para hoy?" había que irse a otra pantalla. */ ?>
<div class="rc-card">
    <div class="rc-card-h">
        <div class="rc-sec" style="margin:0;">
            <h3><i class="fas fa-door-open" style="color:#1F7A4D;"></i> Libres ahora
                <span class="rc-count">(<?= count($habLibres) ?>)</span></h3>
        </div>
    </div>
    <div class="rc-card-b">
        <?php if (empty($habLibres)): ?>
            <div class="rc-empty" style="padding:14px 4px;color:#64748b;font-size:.92rem;">
                No hay habitaciones libres en este momento.
            </div>
        <?php else: ?>
            <div class="rc-libres">
                <?php foreach ($habLibres as $h): ?>
                    <a class="rc-libre" href="<?= BASE_URL ?>/modules/checkin/index.php"
                       title="Registrar ingreso en la <?= htmlspecialchars($h['numero']) ?>">
                        <span class="rc-libre-num"><?= htmlspecialchars($h['numero']) ?></span>
                        <span class="rc-libre-tipo"><?= htmlspecialchars($h['tipo']) ?></span>
                        <span class="rc-libre-meta">
                            Piso <?= (int) $h['piso'] ?> ·
                            <?= (int) $h['capacidad_max'] ?> pers. ·
                            $<?= number_format((float) $h['tarifa'], 0, ',', '.') ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($habEnEspera)): ?>
            <div class="rc-espera">
                <div class="rc-espera-h">Se pueden recuperar (<?= count($habEnEspera) ?>)</div>
                <?php foreach ($habEnEspera as $h): ?>
                    <span class="rc-chip rc-chip-<?= $h['estado'] === 'limpieza' ? 'limp' : 'mant' ?>">
                        <?= htmlspecialchars($h['numero']) ?> ·
                        <?= $h['estado'] === 'limpieza' ? 'limpieza' : 'mantenimiento' ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Accesos rápidos -->
<div class="rc-sec"><h3><i class="fas fa-bolt" style="color:#f59e0b;"></i> Accesos rápidos</h3></div>
<div class="rc-qa">
    <a href="<?= BASE_URL ?>/modules/checkin/index.php" class="rc-qa-item">
        <div class="rc-qa-ic"><i class="fas fa-door-open"></i></div><div class="rc-qa-t">Check-in</div>
    </a>
    <a href="<?= BASE_URL ?>/modules/checkout/index.php" class="rc-qa-item">
        <div class="rc-qa-ic"><i class="fas fa-door-closed"></i></div><div class="rc-qa-t">Check-out</div>
    </a>
    <a href="<?= BASE_URL ?>/modules/consumos/index.php" class="rc-qa-item">
        <div class="rc-qa-ic"><i class="fas fa-receipt"></i></div><div class="rc-qa-t">Consumos</div>
    </a>
    <a href="<?= BASE_URL ?>/modules/huespedes/index.php" class="rc-qa-item">
        <div class="rc-qa-ic"><i class="fas fa-users"></i></div><div class="rc-qa-t">Huéspedes</div>
    </a>
    <a href="<?= BASE_URL ?>/modules/reservas/index.php" class="rc-qa-item">
        <div class="rc-qa-ic"><i class="fas fa-calendar-check"></i></div><div class="rc-qa-t">Reservas</div>
    </a>
    <a href="<?= BASE_URL ?>/modules/calendario/index.php" class="rc-qa-item">
        <div class="rc-qa-ic"><i class="fas fa-calendar-alt"></i></div><div class="rc-qa-t">Calendario</div>
    </a>
</div>

</div><!-- /.rc-wrap -->

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>