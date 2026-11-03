<?php
// Dashboard — Panel optimizado para MySQL (phpMyAdmin / InfinityFree)
// Modelo: UN SOLO HOTEL (sin admin_id)
$pageTitle = 'Dashboard';
// La base ANTES de auth.php, y el orden importa: auth.php solo revalida el
// rol, la cuenta, la sesión cerrada y el tope de 12 h si getDB() ya existe.
// Al revés no falla —se salta los cuatro controles en silencio—.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/cifrado.php';   // descifrar teléfono de deudores
define('CASA_BOSQUE', true);
require_once __DIR__ . '/includes/notificaciones.php';
require_once __DIR__ . '/includes/movimientos_dia.php';
require_once __DIR__ . '/includes/noches.php';
requireAdmin();   // dashboard completo: solo administradores

$pdo = getDB();

// ── Marcar reserva en línea como revisada ─────────────────────────────────────
// Va por POST con testigo, como el resto de las escrituras. Antes era un GET
// (`?revisar_reserva=`): un método que se da por idempotente escribía en la
// base, así que un pre-cargado del navegador, una extensión o un escáner de
// enlaces recorriendo el panel ya autenticado la disparaba sin un clic.
//
// Y el `where` se acota a las filas que de verdad están en el aviso de más
// abajo. Antes era solo `where id = ?`, de modo que un id cualquiera reescribía
// la nota de cualquier reserva —cancelada, incumplida, o tomada en recepción y
// que nunca fue web—, y las notas son el único sitio donde se anotan las
// observaciones de la reserva.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'revisar_reserva') {
    if (validateCsrf()) {
        $reservaId = (int) ($_POST['reserva_id'] ?? 0);
        if ($reservaId > 0) {
            $st = $pdo->prepare("
                update reservas
                set notas = replace(notas, 'Reserva en línea.', 'Reserva web [Revisada].')
                where id = ?
                  and estado = 'confirmada'
                  and usuario_id is null
                  and notas like 'Reserva en línea.%'
            ");
            $st->execute([$reservaId]);
            if ($st->rowCount() === 1 && function_exists('registrarAuditoria')) {
                registrarAuditoria($pdo, 'reservas', $reservaId, 'editar',
                    'Reserva en línea marcada como revisada');
            }
        }
    }
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// ── Registrar pago de una deuda (estancia YA cerrada) ────────────────────────
// Permite cobrar total o parcialmente a un deudor: inserta el pago y sube
// estancias.total_abonado (sin sobrepasar el total). Al saldar, sale de la lista.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'pagar_deuda') {
    if (validateCsrf()) {
        $estId  = (int)($_POST['estancia_id'] ?? 0);
        $monto  = round((float)($_POST['monto'] ?? 0));
        $metodo = in_array(($_POST['metodo'] ?? ''), ['efectivo','tarjeta','transferencia','nequi','otro'], true) ? $_POST['metodo'] : 'efectivo';
        if ($estId > 0 && $monto > 0) {
            // ── Transacción ANTES de leer, con la estancia bloqueada ──
            // Antes el saldo se calculaba fuera de la transacción y el
            // `update` no llevaba condición. Dos cobros simultáneos del mismo
            // saldo —basta un doble clic: el modal no lo impide— leían el
            // mismo `total_abonado`, calculaban el mismo saldo e insertaban
            // DOS filas en `pagos`. `total_abonado` acababa por encima de
            // `total_pagar`, la deuda quedaba negativa y la estancia
            // desaparecía de la lista de deudores sin rastro del sobrecobro.
            //
            // Es el mismo antipatrón que 3.0.5 quitó de reservas, check-in y
            // check-out: la transacción abría tarde.
            try {
                $pdo->beginTransaction();

                $pdo->prepare("select id from estancias where id = ? for update")->execute([$estId]);

                // La lectura que vale es la de DESPUÉS del bloqueo.
                $st = $pdo->prepare("select total_pagar, total_abonado from estancias where id = ? and estado = 'cerrada'");
                $st->execute([$estId]);
                $e = $st->fetch();

                $deuda = $e ? ((float)$e['total_pagar'] - (float)$e['total_abonado']) : 0.0;
                if (!$e || $deuda <= 0) {
                    $pdo->rollBack();
                } else {
                    $abono = min($monto, $deuda);   // no cobrar más que lo que debe

                    // Y el `update` lleva el tope en el propio `where`: aunque
                    // alguien llegara hasta aquí sin el bloqueo, no puede dejar
                    // lo abonado por encima de lo que se debe.
                    $upd = $pdo->prepare("update estancias
                                          set total_abonado = total_abonado + ?
                                          where id = ? and estado = 'cerrada'
                                            and total_abonado + ? <= total_pagar");
                    $upd->execute([$abono, $estId, $abono]);

                    if ($upd->rowCount() !== 1) {
                        $pdo->rollBack();
                    } else {
                        $pdo->prepare("insert into pagos (estancia_id, monto, metodo, nota, usuario_id) values (?, ?, ?, 'Pago de deuda posterior al check-out', ?)")
                            ->execute([$estId, $abono, $metodo, getCurrentUserId()]);
                        $pdo->commit();
                        if (function_exists('registrarAuditoria')) {
                            registrarAuditoria($pdo, 'estancias', $estId, 'pago_deuda', "Pago de deuda \$" . (int)$abono . " ($metodo)");
                        }
                    }
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Pago de deuda: ' . $ex->getMessage());
            }
        }
    }
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

require_once __DIR__ . '/includes/header.php';

// ── Huéspedes que quedaron debiendo (check-out con saldo pendiente) ──────────
// Deuda = total_pagar − total_abonado en estancias cerradas. "En mora" > 30 días.
$deudores = $pdo->query("
    select e.id, e.fecha_checkout, (e.total_pagar - e.total_abonado) as saldo,
           datediff(now(), e.fecha_checkout) as dias,
           h.numero as habitacion, hu.nombres, hu.apellidos, hu.telefono
    from estancias e
    join habitaciones h on h.id = e.habitacion_id
    join huespedes hu on hu.id = e.huesped_id
    where e.estado = 'cerrada' and e.total_pagar > e.total_abonado
    order by e.fecha_checkout asc
")->fetchAll();
$totalDeuda = 0.0;
foreach ($deudores as $dd) { $totalDeuda += (float)$dd['saldo']; }

// ── Nuevas reservas en línea sin revisar ─────────────────────────────────────
// Las reservas online se identifican porque usuario_id es NULL (las hace el
// cliente desde reservar.php, no un administrador) y la nota empieza con
// "Reserva en línea."
$stmtNuevas = $pdo->query("
    select count(*) as total
    from reservas
    where notas like 'Reserva en línea.%'
      and estado = 'confirmada'
      and usuario_id is null
");
$totalNuevasEnLinea = $stmtNuevas->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Detalles de reservas pendientes de revisión
$reservasPendientes = $pdo->query("
    select r.id, h.numero, hu.nombres, hu.apellidos, r.fecha_entrada, r.notas
    from reservas r, habitaciones h, huespedes hu
    where r.habitacion_id = h.id
      and r.huesped_id = hu.id
      and r.notas like 'Reserva en línea.%'
      and r.estado = 'confirmada'
      and r.usuario_id is null
    order by r.id desc
")->fetchAll(PDO::FETCH_ASSOC);

// ── Métricas de habitaciones ──────────────────────────────────────────────────
// `total` cuenta solo las habitaciones EN SERVICIO: deja fuera las
// 'inactiva', que segun el propio esquema estan "retiradas del inventario".
//
// Antes se contaban todas, y eso falseaba la ocupacion hacia abajo: con 25
// habitaciones, 5 retiradas y las otras 20 llenas, el panel decia 80% cuando
// el hotel estaba al 100%. Cuanto mas se retira, peor miente.
//
// 'mantenimiento' SI cuenta en el total, porque es una pausa temporal y la
// habitacion sigue siendo parte del inventario del hotel.
$habitaciones = $pdo->query("
    select
        coalesce(sum(case when estado <> 'inactiva'     then 1 else 0 end), 0) as total,
        coalesce(sum(case when estado = 'disponible'    then 1 else 0 end), 0) as disponibles,
        coalesce(sum(case when estado = 'ocupada'       then 1 else 0 end), 0) as ocupadas,
        coalesce(sum(case when estado = 'limpieza'      then 1 else 0 end), 0) as limpieza,
        coalesce(sum(case when estado = 'mantenimiento' then 1 else 0 end), 0) as mantenimiento,
        coalesce(sum(case when estado = 'inactiva'      then 1 else 0 end), 0) as inactivas
    from habitaciones
")->fetch(PDO::FETCH_ASSOC);

// (int): estos conteos salen de SUM(), que MariaDB entrega como DECIMAL y PDO como CADENA ("1"). Sin el cast, `=== 1` nunca se
// cumplía y el panel decía «1 libres» y «1 retiradas».
$totalHabitaciones = (int) ($habitaciones['total']         ?? 0);
$habInactivas      = (int) ($habitaciones['inactivas']     ?? 0);
$habDisponibles    = (int) ($habitaciones['disponibles']   ?? 0);
$habOcupadas       = (int) ($habitaciones['ocupadas']      ?? 0);
$habLimpieza       = (int) ($habitaciones['limpieza']      ?? 0);
$habMantenimiento  = (int) ($habitaciones['mantenimiento'] ?? 0);

$porcentajeOcupacion = $totalHabitaciones > 0 ? round(($habOcupadas / $totalHabitaciones) * 100) : 0;

// Resumen de una línea para la tarjeta grande.
//
// Antes decía solo "<porcentaje>% disponible", y eso se leía como un error:
// con 0 de 25 ocupadas se esperaba 100% disponible, pero salía 84%. El número
// era correcto —21 de 25 en estado 'disponible'— y lo que faltaba era decir
// dónde estaban las otras 4. Un panel que muestra dos cifras que no cuadran
// hace dudar de todas las demás.
//
// Ahora se enseñan las habitaciones que no están ni ocupadas ni libres, que
// además es información accionable: una habitación en limpieza se recupera
// llamando a servicio.
$habNiUnaNiOtra = max(0, $totalHabitaciones - $habOcupadas - $habDisponibles);
$resumenHab = $habDisponibles . ' ' . ($habDisponibles === 1 ? 'libre' : 'libres');
$partes = [];
if ($habLimpieza > 0)      $partes[] = $habLimpieza . ' en limpieza';
if ($habMantenimiento > 0) $partes[] = $habMantenimiento . ' en mantenimiento';
// Por si algún día se añade otro estado al enum y no se actualiza esto.
$sueltas = $habNiUnaNiOtra - $habLimpieza - $habMantenimiento;
if ($sueltas > 0) $partes[] = $sueltas . ' fuera de servicio';
// Las retiradas no entran en el total, así que se nombran aparte: si no, el
// recuento de la tarjeta no cuadraría con el de la pantalla de habitaciones.
if ($habInactivas > 0) {
    $partes[] = $habInactivas . ' ' . ($habInactivas === 1 ? 'retirada' : 'retiradas');
}
if ($partes) $resumenHab .= ' · ' . implode(' · ', $partes);

// ── Entradas y salidas de hoy ────────────────────────────────────────────────
// Las cuatro cifras salen de includes/movimientos_dia.php, que separa lo HECHO
// de lo PENDIENTE. Antes estas dos consultas vivían aquí copiadas y solo
// contaban lo pendiente: el número bajaba conforme avanzaba el turno y acababa
// en cero, como si no se hubiera trabajado.
$__mov = movimientosDelDia($pdo);
$entradasHechas = $__mov['entradas_hechas'];
$salidasHechas  = $__mov['salidas_hechas'];
$reservasHoy    = $__mov['entradas_pendientes'];   // llegadas que faltan
$checkoutsHoy   = $__mov['salidas_pendientes'];    // salidas que faltan por cerrar
// Notificar una sola vez al día si hay check-ins pendientes
if ($reservasHoy > 0) {
    $flagPath = sys_get_temp_dir() . '/casabosque_aviso_checkins_' . date('Ymd') . '.flag';
    if (!file_exists($flagPath)) {
        enviarNotificacionPush(
            '📅 Check-ins de hoy',
            "Tienes $reservasHoy check-in(s) programado(s) para hoy."
        );
        @file_put_contents($flagPath, '1');
    }
}


// Notificar una sola vez al día si hay check-outs pendientes
if ($checkoutsHoy > 0) {
    $flagPathCO = sys_get_temp_dir() . '/casabosque_aviso_checkouts_' . date('Ymd') . '.flag';
    if (!file_exists($flagPathCO)) {
        enviarNotificacionPush(
            '🚪 Check-outs de hoy',
            "Tienes $checkoutsHoy check-out(s) previsto(s) para hoy."
        );
        @file_put_contents($flagPathCO, '1');
    }
}

// ── Ingresos del día (estancias cerradas hoy: hospedaje + consumos) ──────────
$ingresosHoy = $pdo->query("
    select coalesce(sum(total_pagar), 0) as total
    from estancias
    where estado = 'cerrada'
      and date(fecha_checkout) = current_date()
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// ── Total huéspedes ───────────────────────────────────────────────────────────
$totalHuespedes = $pdo->query("
    select count(*) as total
    from huespedes
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
?>
<style>
/* ============================================================
   Dashboard (admin) — Rediseño móvil v2 "hero" (Casa Bosque)
   ============================================================ */
.dx-wrap { padding-bottom: 8px; } /* el hueco de la barra lo reserva .app-version */

/* Saludo */
.dx-greet { display: flex; justify-content: space-between; align-items: center; margin: 4px 2px 16px; }
.dx-greet .hi { font-size: .82rem; color: var(--cb-muted,#9A8778); }
.dx-greet .nm { font-size: 1.4rem; font-weight: 800; color: var(--cb-forest,#3E2412); line-height: 1.1; margin-top: 1px; }
/* Buscador de huéspedes. El panel de recepción lo tenía y este no, así que el
   administrador era el único que no podía buscar a nadie desde su inicio.
   Misma caja y mismo destino que .rc-search del panel de recepción. */
.dx-search { position: relative; margin: 0 2px 16px; }
.dx-search i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--cb-muted,#9A8778); font-size: .95rem; }
.dx-search input { width: 100%; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; border-radius: 14px; padding: 13px 16px 13px 42px; font-family: inherit; font-size: .9rem; color: var(--cb-text,#2A1709); }
.dx-search input:focus { outline: none; border-color: var(--cb-primary,#6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }

.dx-greet .av { width: 46px; height: 46px; border-radius: 14px; background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-moss,#9C6728)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.05rem; flex: none; box-shadow: 0 6px 16px rgba(107,68,32,.3); }

/* HERO de ocupación */
.dx-hero { position: relative; border-radius: 26px; padding: 22px; margin-bottom: 16px; overflow: hidden;
    background: linear-gradient(155deg,#241607 0%, #6B4420 45%, #9C6728 100%);
    box-shadow: 0 18px 40px rgba(42,23,9,.32); color: #fff; }
.dx-hero::before { content:''; position:absolute; inset:0; background-image: radial-gradient(rgba(255,255,255,.10) 1px, transparent 1px); background-size: 18px 18px; opacity:.5; }
.dx-hero::after { content:''; position:absolute; width:240px; height:240px; border-radius:50%; top:-110px; right:-70px; background: radial-gradient(circle, rgba(201,162,94,.35), transparent 68%); }
.dx-hero-top { position: relative; display: flex; align-items: center; gap: 18px; }
.dx-ring { flex: none; }
.dx-hero-side { flex: 1; min-width: 0; }
.dx-hero-side .k { font-size: .76rem; opacity: .85; letter-spacing: .3px; }
.dx-hero-side .big { font-size: 1.5rem; font-weight: 800; margin-top: 2px; line-height: 1.05; }
.dx-hero-side .big span { font-size: .9rem; font-weight: 500; opacity: .8; }
.dx-hero-stats { position: relative; display: flex; gap: 9px; margin-top: 18px; }
.dx-hs { flex: 1; background: rgba(255,255,255,.13); backdrop-filter: blur(6px); border-radius: 14px; padding: 11px 8px; text-align: center; }
.dx-hs .n { font-size: 1.25rem; font-weight: 800; line-height: 1; }
.dx-hs .l { font-size: .62rem; opacity: .88; margin-top: 4px; }
.dx-hs .s { font-size: .56rem; font-weight: 700; margin-top: 3px; color: #E8BA64; }

/* Banner reservas online */
.dx-online { background: #fff; border: 1px solid rgba(249,115,22,.3); border-radius: 18px; box-shadow: 0 8px 22px rgba(249,115,22,.1); margin-bottom: 16px; overflow: hidden; }
.dx-online-h { background: linear-gradient(150deg,#f97316,#ea580c); color: #fff; padding: 13px 16px; display: flex; align-items: center; gap: 10px; }
.dx-online-h .ic { font-size: 1.1rem; animation: dxBell 2.5s ease-in-out infinite; }
@keyframes dxBell { 0%,100%{transform:rotate(0);} 10%{transform:rotate(14deg);} 20%{transform:rotate(-12deg);} 30%{transform:rotate(8deg);} 40%{transform:rotate(0);} }
.dx-online-h .tt { font-size: .9rem; font-weight: 700; }
.dx-online-h .sb { font-size: .72rem; opacity: .92; }
.dx-online-list { padding: 8px 14px 12px; }
.dx-on-item { display: flex; align-items: center; gap: 11px; padding: 11px 0; border-bottom: 1px solid var(--gray-100,#F1EADF); }
.dx-on-item:last-child { border-bottom: none; }
.dx-on-room { flex: none; font-size: .76rem; font-weight: 800; color: #b45309; background: rgba(249,115,22,.12); padding: 5px 10px; border-radius: 9px; }
.dx-on-info { flex: 1; min-width: 0; }
.dx-on-name { font-size: .85rem; font-weight: 700; color: var(--cb-forest,#3E2412); }
.dx-on-date { font-size: .72rem; color: var(--cb-muted,#9A8778); }
/* «Revisar» es un botón de formulario, no un enlace: escribe en la base y eso
   va por POST. De ahí los reinicios de borde, cursor y tipografía, que un
   <button> no hereda. El formulario envuelve al botón, así que lleva él el
   flex: none que antes llevaba el enlace. */
.dx-on-form { flex: none; display: flex; margin: 0; }
.dx-on-btn { flex: none; font-size: .76rem; font-weight: 700; font-family: inherit; line-height: 1.25; color: #fff; background: linear-gradient(150deg,#6B4420,#9C6728); padding: 8px 13px; border: 0; border-radius: 10px; text-decoration: none; cursor: pointer; }

/* Ingresos destacado */
.dx-income { position: relative; overflow: hidden; background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.06); padding: 18px; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; }
.dx-income .l { font-size: .78rem; color: var(--cb-muted,#9A8778); }
.dx-income .a { font-size: 1.85rem; font-weight: 800; color: var(--cb-forest,#3E2412); margin-top: 3px; letter-spacing: -.6px; }
.dx-income .ic { width: 52px; height: 52px; border-radius: 15px; background: linear-gradient(150deg,rgba(201,162,94,.22),rgba(201,162,94,.1)); color: #b8860b; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; flex: none; }

/* Métricas en fila compacta */
.dx-quick { display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; margin-bottom: 18px; }
.dx-q { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 16px; padding: 14px 8px; text-align: center; box-shadow: 0 4px 12px rgba(42,23,9,.04); }
.dx-q .ic { width: 36px; height: 36px; border-radius: 11px; margin: 0 auto 8px; display: flex; align-items: center; justify-content: center; font-size: .95rem; }
.dx-q.cin .ic { background: rgba(249,115,22,.14); color: #ea580c; }
.dx-q.cout .ic { background: rgba(239,68,68,.12); color: #ef4444; }
.dx-q.user .ic { background: rgba(168,85,247,.12); color: #9333ea; }
.dx-q .n { font-size: 1.35rem; font-weight: 800; color: var(--cb-forest,#3E2412); line-height: 1; }
.dx-q .l { font-size: .64rem; color: var(--cb-muted,#9A8778); margin-top: 5px; }
.dx-q .s { font-size: .6rem; font-weight: 700; color: #B45309; margin-top: 3px; }

/* Estado operativo: barra segmentada */
.dx-sec { display: flex; align-items: center; justify-content: space-between; margin: 4px 2px 12px; }
.dx-sec h3 { font-size: 1rem; font-weight: 700; color: var(--cb-forest,#3E2412); display: flex; align-items: center; gap: 8px; margin: 0; }
/* El enlace («Gestionar», «Ver todas») medía 19px de alto. El padding le da zona de toque de 44px y el margen
   negativo lo compensa: crece el área que responde, no el hueco que se ve. */
.dx-sec a { font-size: .76rem; color: var(--cb-primary,#6B4420); font-weight: 600; text-decoration: none; padding: 13px 6px; margin: -13px -6px; }

/* ── Huéspedes que deben ──
   La fila se parte en dos: arriba nombre y saldo, debajo los botones. Con los botones a 44px la columna derecha
   medía ~200px y dejaba al nombre del deudor en ~80px a 360px de ancho. */
.dx-deb { padding: 11px 0; border-bottom: 1px solid #f3eee9; }
.dx-deb-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.dx-deb-info { flex: 1; min-width: 0; }
.dx-deb-name { font-weight: 700; color: #3E2412; font-size: .9rem; }
.dx-deb-mora { font-size: .6rem; font-weight: 800; color: #fff; background: #b91c1c; padding: 2px 8px; border-radius: 99px; vertical-align: middle; }
.dx-deb-sub { font-size: .74rem; color: #9A8778; margin-top: 2px; }
.dx-deb-monto { flex: none; font-weight: 800; color: #c0392b; font-size: .95rem; text-align: right; }
.dx-deb-acc { display: flex; gap: 8px; justify-content: flex-end; align-items: center; margin-top: 8px; }
.dx-deb-pagar { font-family: inherit; font-size: .78rem; font-weight: 700; color: #fff; background: #6B4420; border: none; border-radius: 10px; padding: 0 18px; min-height: 44px; cursor: pointer; }
.dx-deb-ic { flex: none; width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; text-decoration: none; }
.dx-deb-ic.tel { background: #F5EBDC; color: #6B4420; font-size: .9rem; }
.dx-deb-ic.wa  { background: #dcf8e6; color: #128C4B; font-size: 1rem; }

/* Fila de alerta: habitaciones que no se pueden ofrecer. Antes esa cifra solo se veia al llegar al grafico de
   «Estado operativo», con el panel ya recorrido casi entero. */
.dx-alert { display: flex; align-items: center; gap: 12px; min-height: 56px; padding: 11px 14px; margin-bottom: 16px; background: #fff;
            border: 1px solid rgba(239,68,68,.25); border-radius: 16px; text-decoration: none; color: var(--cb-forest,#3E2412);
            box-shadow: 0 4px 12px rgba(42,23,9,.04); }
.dx-alert:active { transform: scale(.99); }
.dx-alert .ic { flex: none; width: 36px; height: 36px; border-radius: 11px; background: rgba(239,68,68,.12); color: #b91c1c;
                display: flex; align-items: center; justify-content: center; font-size: .95rem; }
.dx-alert .tx { flex: 1; min-width: 0; font-size: .88rem; font-weight: 700; }
.dx-alert .tx small { display: block; font-size: .72rem; font-weight: 500; color: var(--cb-muted,#9A8778); margin-top: 2px; }
.dx-alert .go { flex: none; color: var(--cb-muted,#9A8778); font-size: .8rem; }
.dx-states { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); padding: 16px; margin-bottom: 18px; }
.dx-bar { display: flex; height: 12px; border-radius: 7px; overflow: hidden; background: #F1EADF; margin-bottom: 14px; }
.dx-bar i { display: block; height: 100%; }
.dx-bar i.ocup { background: #f59e0b; }
.dx-bar i.disp { background: #1F7A4D; }
.dx-bar i.limp { background: #3b82f6; }
.dx-bar i.mant { background: #ef4444; }
.dx-leg { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.dx-leg-i { display: flex; align-items: center; gap: 9px; }
.dx-leg-i .d { width: 11px; height: 11px; border-radius: 4px; flex: none; }
.dx-leg-i .d.ocup { background: #f59e0b; } .dx-leg-i .d.disp { background: #1F7A4D; }
.dx-leg-i .d.limp { background: #3b82f6; } .dx-leg-i .d.mant { background: #ef4444; }
.dx-leg-i .tx { font-size: .76rem; color: var(--cb-muted,#9A8778); }
.dx-leg-i .tx b { color: var(--cb-forest,#3E2412); font-size: .92rem; }

/* Monitoreo de habitaciones */
.dx-card { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); margin-bottom: 18px; overflow: hidden; }
.dx-card-b { padding: 6px 16px; }
.dx-rm { display: flex; align-items: center; gap: 11px; padding: 12px 0; border-bottom: 1px solid var(--gray-100,#F1EADF); }
.dx-rm:last-child { border-bottom: none; }
.dx-rm-dot { flex: none; width: 10px; height: 10px; border-radius: 50%; }
.dx-rm-dot.disponible { background: #1F7A4D; } .dx-rm-dot.ocupada { background: #f97316; }
.dx-rm-dot.limpieza { background: #3b82f6; } .dx-rm-dot.mantenimiento { background: #ef4444; }
.dx-rm-num { flex: none; font-size: .85rem; font-weight: 800; color: var(--cb-forest,#3E2412); width: 56px; }
.dx-rm-info { flex: 1; min-width: 0; }
.dx-rm-tipo { font-size: .8rem; font-weight: 600; color: var(--cb-forest,#3E2412); }
.dx-rm-guest { font-size: .72rem; color: var(--cb-muted,#9A8778); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dx-rm-est { flex: none; font-size: .62rem; font-weight: 700; padding: 3px 9px; border-radius: 99px; text-transform: capitalize; }
.dx-rm-est.disponible { background: rgba(31,122,77,.14); color: #047857; }
.dx-rm-est.ocupada { background: rgba(249,115,22,.14); color: #b45309; }
.dx-rm-est.limpieza { background: rgba(59,130,246,.14); color: #1e40af; }
.dx-rm-est.mantenimiento { background: rgba(239,68,68,.12); color: #b91c1c; }

/* Próximas reservas */
.dx-res { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 15px; padding: 13px 14px; margin-bottom: 10px; display: flex; align-items: center; gap: 12px; box-shadow: 0 4px 12px rgba(42,23,9,.04); }
.dx-res-av { flex: none; width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-moss,#9C6728)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: .8rem; }
.dx-res-info { flex: 1; min-width: 0; }
.dx-res-name { font-size: .86rem; font-weight: 700; color: var(--cb-forest,#3E2412); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dx-res-meta { font-size: .72rem; color: var(--cb-muted,#9A8778); margin-top: 1px; }
.dx-res-room { flex: none; font-size: .74rem; font-weight: 800; color: var(--cb-primary,#6B4420); background: var(--cb-primary-light,#F5EBDC); padding: 5px 10px; border-radius: 9px; }

.dx-empty { text-align: center; padding: 28px 12px; color: var(--cb-muted,#9A8778); }
.dx-empty i { font-size: 1.9rem; color: var(--cb-primary,#6B4420); opacity: .4; margin-bottom: 10px; display: block; }
.dx-empty p { font-size: .88rem; margin: 0; }
</style>

<?php
    $totOcupVis = max(1, $totalHabitaciones);
    $pOcup = $habOcupadas / $totOcupVis * 100;
    $pDisp = $habDisponibles / $totOcupVis * 100;
    $pLimp = $habLimpieza / $totOcupVis * 100;
    $pMant = $habMantenimiento / $totOcupVis * 100;
    $C = 2 * M_PI * 50; $fill = $C * $porcentajeOcupacion / 100;
?>

<div class="dx-wrap">

<!-- Saludo -->
<div class="dx-greet">
    <div>
        <div class="hi"><?= (date('H') < 12 ? 'Buenos días' : (date('H') < 19 ? 'Buenas tardes' : 'Buenas noches')) ?></div>
        <div class="nm"><?= htmlspecialchars(getCurrentUserName()) ?></div>
    </div>
    <div class="av"><?= strtoupper(mb_substr(getCurrentUserName(),0,1,'UTF-8')) ?></div>
</div>

<!-- Buscador de huéspedes (el mismo que tiene el panel de recepción) -->
<form class="dx-search" action="<?= BASE_URL ?>/modules/huespedes/index.php" method="POST" role="search"
      data-buscador-huespedes
      data-buscar-url="<?= BASE_URL ?>/modules/huespedes/buscar.php"
      data-ficha-url="<?= BASE_URL ?>/modules/huespedes/ficha.php">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="buscar">
    <i class="fas fa-search"></i>
    <input type="text" name="q" placeholder="Buscar huésped por nombre o documento" aria-label="Buscar huésped por nombre o documento">
</form>

<!-- Botón principal: Registrar ingreso (walk-in) -->
<a href="<?= BASE_URL ?>/modules/checkin/index.php" style="display:flex;align-items:center;gap:14px;text-decoration:none;background:linear-gradient(150deg,#3E2412,#9C6728);color:#fff;border-radius:18px;padding:16px 18px;margin-bottom:16px;box-shadow:0 10px 26px rgba(62,36,18,.20);">
    <span style="flex:none;width:48px;height:48px;border-radius:14px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:1.3rem;"><i class="fas fa-door-open"></i></span>
    <span style="flex:1;line-height:1.25;"><b style="font-size:1.08rem;font-weight:800;display:block;">Registrar ingreso</b><small style="font-size:.78rem;opacity:.82;">Recibe un huésped y asígnale habitación</small></span>
    <i class="fas fa-arrow-right" style="opacity:.85;"></i>
</a>

<!-- HERO ocupación -->
<div class="dx-hero">
    <div class="dx-hero-top">
        <div class="dx-ring">
            <svg width="120" height="120" viewBox="0 0 128 128">
                <circle cx="64" cy="64" r="50" fill="none" stroke="rgba(255,255,255,.2)" stroke-width="12"/>
                <circle cx="64" cy="64" r="50" fill="none" stroke="url(#dxg)" stroke-width="12" stroke-linecap="round" stroke-dasharray="<?= $fill ?> <?= $C ?>" transform="rotate(-90 64 64)"/>
                <defs><linearGradient id="dxg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#C8963C"/></linearGradient></defs>
                <text x="64" y="59" text-anchor="middle" fill="#fff" style="font-family:Plus Jakarta Sans;font-size:30px;font-weight:800;"><?= $porcentajeOcupacion ?>%</text>
                <text x="64" y="78" text-anchor="middle" fill="rgba(255,255,255,.8)" style="font-family:Plus Jakarta Sans;font-size:9px;font-weight:600;letter-spacing:1.5px;">OCUPACIÓN</text>
            </svg>
        </div>
        <div class="dx-hero-side">
            <div class="k">Casa Bosque · hoy</div>
            <div class="big"><?= $habOcupadas ?> <span>/ <?= $totalHabitaciones ?> ocupadas</span></div>
            <div class="k" style="margin-top:8px;"><i class="fas fa-door-open"></i> <?= htmlspecialchars($resumenHab) ?></div>
        </div>
    </div>
    <div class="dx-hero-stats">
        <div class="dx-hs"><div class="n"><?= $habOcupadas ?></div><div class="l">Ocupadas</div></div>
        <div class="dx-hs"><div class="n"><?= $habDisponibles ?></div><div class="l">Libres</div></div>
        <?php /* El número grande es lo HECHO hoy; debajo, lo que todavía falta.
                 Antes se mostraba solo lo pendiente con el rótulo «Check-in hoy»,
                 así que al trabajar el turno la cifra bajaba hasta cero. */ ?>
        <div class="dx-hs"><div class="n"><?= $entradasHechas ?></div><div class="l">Entradas hoy</div>
            <?php if ($pend = movimientosPendientesTexto($reservasHoy)): ?><div class="s"><?= $pend ?></div><?php endif; ?>
        </div>
        <div class="dx-hs"><div class="n"><?= $salidasHechas ?></div><div class="l">Salidas hoy</div>
            <?php if ($pend = movimientosPendientesTexto($checkoutsHoy)): ?><div class="s"><?= $pend ?></div><?php endif; ?>
        </div>
    </div>
</div>

<!-- Huéspedes que deben -->
<?php if (!empty($deudores)): ?>
<div style="background:#fff;border:1px solid #f4caca;border-radius:18px;box-shadow:0 6px 18px rgba(42,23,9,.05);margin-bottom:16px;overflow:hidden;">
    <div style="padding:13px 16px;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(150deg,#fdecec,#fff);">
        <span style="font-weight:800;color:#b91c1c;display:flex;align-items:center;gap:8px;font-size:.98rem;"><i class="fas fa-hand-holding-dollar"></i> Huéspedes que deben</span>
        <span style="font-size:.74rem;font-weight:700;color:#b91c1c;background:#fdeaea;padding:4px 11px;border-radius:99px;"><?= count($deudores) ?> · $<?= number_format($totalDeuda,0,',','.') ?></span>
    </div>
    <div style="padding:4px 16px 10px;">
        <?php foreach ($deudores as $d):
            $tel = descifrar($d['telefono']);
            $telDigits = preg_replace('/\D+/', '', (string)$tel);
            $enMora = (int)$d['dias'] > 30;
            $waNum = $telDigits !== '' ? (strlen($telDigits) <= 10 ? '57'.$telDigits : $telDigits) : '';
        ?>
        <div class="dx-deb">
            <div class="dx-deb-top">
                <div class="dx-deb-info">
                    <div class="dx-deb-name"><?= htmlspecialchars($d['nombres'].' '.$d['apellidos']) ?><?php if ($enMora): ?> <span class="dx-deb-mora">EN MORA</span><?php endif; ?></div>
                    <div class="dx-deb-sub">Hab. <?= htmlspecialchars($d['habitacion']) ?> · salió hace <?= (int)$d['dias'] ?> día(s)<?= $tel ? ' · Tel: '.htmlspecialchars($tel) : ' · sin teléfono' ?></div>
                </div>
                <div class="dx-deb-monto">$<?= number_format($d['saldo'],0,',','.') ?></div>
            </div>
            <div class="dx-deb-acc">
                <button type="button" class="dx-deb-pagar" onclick='abrirPago(<?= (int)$d['id'] ?>, <?= htmlspecialchars(json_encode($d['nombres'].' '.$d['apellidos']), ENT_QUOTES) ?>, <?= (int)$d['saldo'] ?>)'>Registrar pago</button>
                <?php if ($telDigits !== ''): ?>
                <a href="tel:<?= htmlspecialchars($telDigits) ?>" title="Llamar" aria-label="Llamar" class="dx-deb-ic tel"><i class="fas fa-phone"></i></a>
                <a href="https://wa.me/<?= htmlspecialchars($waNum) ?>" target="_blank" rel="noopener" title="WhatsApp" aria-label="WhatsApp" class="dx-deb-ic wa"><i class="fab fa-whatsapp"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Habitaciones en mantenimiento: solo si las hay -->
<?php if ($habMantenimiento > 0): ?>
<a href="<?= BASE_URL ?>/modules/habitaciones/index.php?estado=mantenimiento" class="dx-alert">
    <span class="ic"><i class="fas fa-screwdriver-wrench"></i></span>
    <span class="tx"><?= (int)$habMantenimiento ?> <?= (int)$habMantenimiento === 1 ? 'habitación' : 'habitaciones' ?> en mantenimiento<small>Toca para ver cuáles son</small></span>
    <i class="fas fa-chevron-right go"></i>
</a>
<?php endif; ?>

<!-- Modal: registrar pago de una deuda -->
<div id="pago-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.4);backdrop-filter:blur(5px);align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;width:100%;max-width:400px;border-radius:18px;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,.2);">
        <div style="padding:16px 20px;background:linear-gradient(150deg,#fdecec,#fff);border-bottom:1px solid #f4caca;">
            <div style="font-weight:800;color:#b91c1c;display:flex;align-items:center;gap:8px;"><i class="fas fa-hand-holding-dollar"></i> Registrar pago de deuda</div>
            <div id="pago-nombre" style="font-size:.82rem;color:#6E6054;margin-top:3px;"></div>
        </div>
        <form method="POST" action="dashboard.php" style="padding:18px 20px 20px;">
            <?= csrfField() ?>
            <input type="hidden" name="accion" value="pagar_deuda">
            <input type="hidden" name="estancia_id" id="pago-estancia">
            <div style="font-size:.85rem;color:#6E6054;margin-bottom:12px;">Debe <b id="pago-saldo-txt" style="color:#c0392b;"></b>. Puedes cobrar el total o un abono.</div>
            <label style="display:block;font-size:.74rem;font-weight:600;color:#6E6054;margin-bottom:6px;">Monto a registrar (COP)</label>
            <input type="number" name="monto" id="pago-monto" min="1" step="1" required style="width:100%;padding:12px 13px;border:1px solid #E7E2D8;border-radius:11px;font-family:inherit;font-size:1rem;margin-bottom:14px;box-sizing:border-box;">
            <label style="display:block;font-size:.74rem;font-weight:600;color:#6E6054;margin-bottom:8px;">Método</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:18px;">
                <label style="display:flex;align-items:center;justify-content:center;gap:7px;padding:11px;border:1px solid #E7E2D8;border-radius:11px;font-size:.85rem;font-weight:600;color:#334155;cursor:pointer;"><input type="radio" name="metodo" value="efectivo" checked style="accent-color:#6B4420;"> Efectivo</label>
                <label style="display:flex;align-items:center;justify-content:center;gap:7px;padding:11px;border:1px solid #E7E2D8;border-radius:11px;font-size:.85rem;font-weight:600;color:#334155;cursor:pointer;"><input type="radio" name="metodo" value="tarjeta" style="accent-color:#6B4420;"> Tarjeta</label>
                <label style="display:flex;align-items:center;justify-content:center;gap:7px;padding:11px;border:1px solid #E7E2D8;border-radius:11px;font-size:.85rem;font-weight:600;color:#334155;cursor:pointer;"><input type="radio" name="metodo" value="transferencia" style="accent-color:#6B4420;"> Transfer.</label>
                <label style="display:flex;align-items:center;justify-content:center;gap:7px;padding:11px;border:1px solid #E7E2D8;border-radius:11px;font-size:.85rem;font-weight:600;color:#334155;cursor:pointer;"><input type="radio" name="metodo" value="nequi" style="accent-color:#6B4420;"> Nequi</label>
                <label style="display:flex;align-items:center;justify-content:center;gap:7px;padding:11px;border:1px solid #E7E2D8;border-radius:11px;font-size:.85rem;font-weight:600;color:#334155;cursor:pointer;"><input type="radio" name="metodo" value="otro" style="accent-color:#6B4420;"> Otro</label>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" onclick="cerrarPago()" style="padding:11px 16px;border-radius:11px;border:1px solid #E7E2D8;background:#fff;color:#475569;font-weight:600;cursor:pointer;font-family:inherit;">Cancelar</button>
                <button type="submit" style="padding:11px 18px;border-radius:11px;border:none;background:#6B4420;color:#fff;font-weight:700;cursor:pointer;font-family:inherit;">Registrar pago</button>
            </div>
        </form>
    </div>
</div>
<script>
function abrirPago(id, nombre, saldo){
    document.getElementById('pago-estancia').value = id;
    document.getElementById('pago-nombre').textContent = nombre;
    document.getElementById('pago-saldo-txt').textContent = '$' + Number(saldo).toLocaleString('es-CO');
    var m = document.getElementById('pago-monto'); m.value = saldo; m.max = saldo;
    document.getElementById('pago-modal').style.display = 'flex';
}
function cerrarPago(){ document.getElementById('pago-modal').style.display = 'none'; }
(function(){ var ov = document.getElementById('pago-modal'); if(ov) ov.addEventListener('click', function(e){ if(e.target === ov) cerrarPago(); }); })();
</script>
<?php endif; ?>

<!-- Reservas en línea -->
<?php if ($totalNuevasEnLinea > 0): ?>
<div class="dx-online" id="alerta-nuevas-reservas">
    <div class="dx-online-h">
        <i class="fas fa-globe ic"></i>
        <div style="flex:1;">
            <div class="tt"><?= $totalNuevasEnLinea ?> reserva(s) en línea</div>
            <div class="sb">Sin revisar · confírmalas abajo</div>
        </div>
    </div>
    <div class="dx-online-list">
        <?php foreach ($reservasPendientes as $rp): ?>
            <div class="dx-on-item">
                <span class="dx-on-room">Hab. <?= htmlspecialchars($rp['numero']) ?></span>
                <div class="dx-on-info">
                    <div class="dx-on-name"><?= htmlspecialchars($rp['nombres'] . ' ' . $rp['apellidos']) ?></div>
                    <div class="dx-on-date">Entrada: <?= date('d/m/Y', strtotime($rp['fecha_entrada'])) ?></div>
                </div>
                <form method="post" action="<?= BASE_URL ?>/dashboard.php" class="dx-on-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="accion" value="revisar_reserva">
                    <input type="hidden" name="reserva_id" value="<?= (int) $rp['id'] ?>">
                    <button type="submit" class="dx-on-btn">Revisar</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php
// Aviso: llegadas de hoy que traen menores registrados. Sustituye al aviso
// del pre-check-in en línea, que se retiró. Este importa más: el check-in
// de esas reservas exige verificar documentos antes de dejar entrar.
$llegadasConMenores = (int) $pdo->query("
    select count(distinct r.id)
    from reservas r, acompanantes a
    where a.reserva_id = r.id
      and a.es_menor = 1
      and r.estado = 'confirmada'
      and r.fecha_entrada <= curdate()
")->fetchColumn();
if ($llegadasConMenores > 0):
?>
<a href="<?= BASE_URL ?>/modules/checkin/index.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; background:#fdeaea; border:1px solid rgba(220,38,38,.25); border-radius:14px; padding:13px 15px; margin-bottom:16px;">
    <i class="fas fa-child" style="color:#b91c1c; font-size:1.35rem;"></i>
    <div style="flex:1; min-width:0;">
        <div style="font-weight:800; color:#b91c1c; font-size:.95rem;"><?= $llegadasConMenores ?> llegada(s) con menores</div>
        <div style="font-size:.76rem; color:#8c3a3a;">Hay que verificar Registro Civil y autorización antes del ingreso</div>
    </div>
    <i class="fas fa-chevron-right" style="color:#d79a9a;"></i>
</a>
<?php endif; ?>

<!-- Ingresos -->
<div class="dx-income">
    <div>
        <div class="l">Ingresos de hoy</div>
        <div class="a">$<?= number_format($ingresosHoy, 0, ',', '.') ?></div>
        <div class="l" style="margin-top:3px;">Check-outs cerrados</div>
    </div>
    <div class="ic"><i class="fas fa-sack-dollar"></i></div>
</div>

<!-- Métricas rápidas -->
<div class="dx-quick">
    <div class="dx-q cin"><div class="ic"><i class="fas fa-calendar-day"></i></div><div class="n"><?= $entradasHechas ?></div><div class="l">Entradas hoy</div>
        <?php if ($pend = movimientosPendientesTexto($reservasHoy)): ?><div class="s"><?= $pend ?></div><?php endif; ?>
    </div>
    <div class="dx-q cout"><div class="ic"><i class="fas fa-door-closed"></i></div><div class="n"><?= $salidasHechas ?></div><div class="l">Salidas hoy</div>
        <?php if ($pend = movimientosPendientesTexto($checkoutsHoy)): ?><div class="s"><?= $pend ?></div><?php endif; ?>
    </div>
    <div class="dx-q user"><div class="ic"><i class="fas fa-users"></i></div><div class="n"><?= $totalHuespedes ?></div><div class="l">Clientes</div></div>
</div>

<!-- Estado operativo: barra segmentada -->
<div class="dx-sec"><h3><i class="fas fa-clipboard-list" style="color:var(--cb-primary,#6B4420);"></i> Estado operativo</h3><span style="font-size:.76rem;color:var(--cb-muted,#9A8778);font-weight:600;"><?= $totalHabitaciones ?> hab.</span></div>
<div class="dx-states">
    <div class="dx-bar">
        <i class="ocup" style="width:<?= $pOcup ?>%;"></i>
        <i class="disp" style="width:<?= $pDisp ?>%;"></i>
        <i class="limp" style="width:<?= $pLimp ?>%;"></i>
        <i class="mant" style="width:<?= $pMant ?>%;"></i>
    </div>
    <div class="dx-leg">
        <div class="dx-leg-i"><span class="d ocup"></span><span class="tx"><b><?= $habOcupadas ?></b> Ocupadas</span></div>
        <div class="dx-leg-i"><span class="d disp"></span><span class="tx"><b><?= $habDisponibles ?></b> Libres</span></div>
        <div class="dx-leg-i"><span class="d limp"></span><span class="tx"><b><?= $habLimpieza ?></b> En limpieza</span></div>
        <div class="dx-leg-i"><span class="d mant"></span><span class="tx"><b><?= $habMantenimiento ?></b> Mantenim.</span></div>
    </div>
</div>

<!-- Próximas reservas -->
<div class="dx-sec">
    <h3><i class="fas fa-calendar-check" style="color:var(--cb-primary,#6B4420);"></i> Próximas reservas</h3>
    <a href="<?= BASE_URL ?>/modules/reservas/index.php">Ver todas</a>
</div>
<?php
$reservas = $pdo->query("
    select r.id, r.fecha_entrada, r.fecha_salida, r.estado,
           h.numero, hu.nombres, hu.apellidos
    from reservas r, habitaciones h, huespedes hu
    where r.habitacion_id = h.id
      and r.huesped_id = hu.id
      and r.estado = 'confirmada'
      and r.fecha_entrada >= current_date()
    order by r.fecha_entrada asc
    limit 5
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($reservas)): ?>
    <div class="dx-empty" style="background:#fff;border:1px solid var(--cb-border,#EDE3D2);border-radius:18px;">
        <i class="fas fa-calendar-xmark"></i><p>No hay reservaciones futuras agendadas.</p>
    </div>
<?php else:
    foreach ($reservas as $r): ?>
    <div class="dx-res">
        <div class="dx-res-av"><?= strtoupper(mb_substr($r['nombres'],0,1,'UTF-8') . mb_substr($r['apellidos'],0,1,'UTF-8')) ?></div>
        <div class="dx-res-info">
            <div class="dx-res-name"><?= htmlspecialchars($r['nombres'] . ' ' . $r['apellidos']) ?></div>
            <div class="dx-res-meta"><?= date('d/m', strtotime($r['fecha_entrada'])) ?> → <?= date('d/m/Y', strtotime($r['fecha_salida'])) ?></div>
        </div>
        <span class="dx-res-room">Hab. <?= htmlspecialchars($r['numero']) ?></span>
    </div>
<?php endforeach; endif; ?>

<!-- Monitoreo de habitaciones -->
<div class="dx-sec">
    <h3><i class="fas fa-th-large" style="color:var(--cb-primary,#6B4420);"></i> Monitoreo en tiempo real</h3>
    <a href="<?= BASE_URL ?>/modules/habitaciones/index.php">Gestionar</a>
</div>
<div class="dx-card">
    <div class="dx-card-b">
        <?php
        $rooms = $pdo->query("
            select h.id, h.numero, h.piso, h.estado, t.nombre as tipo
            from habitaciones h, tipos_habitacion t
            where h.tipo_id = t.id
            order by cast(h.numero as unsigned), h.numero asc
        ")->fetchAll(PDO::FETCH_ASSOC);

        $activeGuests = $pdo->query("
            select e.id, e.habitacion_id, e.fecha_checkin, e.tarifa_noche, e.total_consumos,
                   (select coalesce(sum(p.monto),0) from pagos p where p.estancia_id=e.id) abonado,
                   hu.nombres, hu.apellidos
            from estancias e join huespedes hu on hu.id=e.huesped_id
            where e.estado = 'activa'
        ")->fetchAll(PDO::FETCH_ASSOC);

        $guestMap = [];
        $guestBalanceMap = [];
        foreach ($activeGuests as $g) {
            $roomId = (int)$g['habitacion_id'];
            $guestMap[$roomId] = $g['nombres'] . ' ' . $g['apellidos'];
            $noches = nochesEstancia($g['fecha_checkin']);
            $guestBalanceMap[$roomId] = max(0, $noches * (float)$g['tarifa_noche'] + (float)$g['total_consumos'] - (float)$g['abonado']);
        }

        if (empty($rooms)): ?>
            <div class="dx-empty"><i class="fas fa-door-closed"></i><p>No hay habitaciones registradas.</p></div>
        <?php else:
            foreach ($rooms as $room):
                $huespedActual = $guestMap[$room['id']] ?? null;
        ?>
            <div class="dx-rm">
                <span class="dx-rm-dot <?= $room['estado'] ?>"></span>
                <span class="dx-rm-num">Hab. <?= htmlspecialchars($room['numero']) ?></span>
                <div class="dx-rm-info">
                    <div class="dx-rm-tipo"><?= htmlspecialchars($room['tipo']) ?> · Piso <?= $room['piso'] ?></div>
                    <?php if ($huespedActual): ?>
                        <div class="dx-rm-guest"><i class="fas fa-user-check" style="font-size:10px;color:var(--cb-primary,#6B4420);"></i> <?= htmlspecialchars($huespedActual) ?></div>
                        <div class="dx-rm-guest" style="font-weight:700;color:#8a3b18;">Saldo actual: $<?= number_format($guestBalanceMap[$room['id']] ?? 0, 0, ',', '.') ?></div>
                    <?php endif; ?>
                </div>
                <span class="dx-rm-est <?= $room['estado'] ?>"><?= ucfirst($room['estado']) ?></span>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

</div><!-- /.dx-wrap -->

<script>
(function () {
    const hayAlertas = document.getElementById('alerta-nuevas-reservas');
    if (!hayAlertas) return;
    function tocarSonidoAlerta() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            function nota(freq, inicio, duracion, volumen) {
                const osc = ctx.createOscillator(); const gain = ctx.createGain();
                osc.connect(gain); gain.connect(ctx.destination);
                osc.type = 'sine'; osc.frequency.value = freq;
                gain.gain.setValueAtTime(0, ctx.currentTime + inicio);
                gain.gain.linearRampToValueAtTime(volumen, ctx.currentTime + inicio + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + inicio + duracion);
                osc.start(ctx.currentTime + inicio); osc.stop(ctx.currentTime + inicio + duracion + 0.05);
            }
            nota(523.25, 0.00, 0.25, 0.25); nota(659.25, 0.22, 0.25, 0.25); nota(783.99, 0.44, 0.40, 0.30);
        } catch (e) { console.warn('Web Audio API no disponible:', e); }
    }
    const CLAVE = 'dashboard_alerta_sonido_v1';
    if (!sessionStorage.getItem(CLAVE)) {
        function activar() {
            tocarSonidoAlerta(); sessionStorage.setItem(CLAVE, '1');
            document.removeEventListener('click', activar); document.removeEventListener('keydown', activar);
        }
        document.addEventListener('click', activar, { once: true });
        document.addEventListener('keydown', activar, { once: true });
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
