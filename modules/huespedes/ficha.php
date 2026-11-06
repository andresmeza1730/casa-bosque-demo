<?php
// ============================================================
// Módulo: Estado de cuenta del huésped (ficha rápida de recepción)
// ------------------------------------------------------------
// El cliente lo pidió así: buscar un huésped y ver DE UNA VEZ su
// situación — habitación, cuántas noches debe, lo que ha consumido
// y el TOTAL a pagar. Todo en una sola pantalla.
//   - Sin parámetros  → quién está hospedado AHORA (estancias activas).
//   - ?id=<huesped>   → ficha completa con estado de cuenta.
// La búsqueda se manda por POST y se recuerda en la sesión, no en la URL: el
// documento de un huésped no tiene por qué quedar en el historial ni en los
// registros del hosting. Ver includes/busqueda.php.
// ============================================================
$pageTitle = 'Estado de cuenta';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/validacion.php';   // validarMonto, validarEntero (montos que se teclean)
require_once __DIR__ . '/../../includes/cifrado.php';
require_once __DIR__ . '/../../includes/noches.php';
require_once __DIR__ . '/../../includes/tarifas.php';   // tarifaPorPersonas()
require_once __DIR__ . '/../../includes/busqueda.php';  // el texto buscado no va por la URL
requireLogin();

busquedaLimpiarSiPiden('ficha');

$pdo = getDB();

/** Noches transcurridas desde el check-in hasta hoy (mínimo 1). */
function nochesTranscurridas(string $fechaCheckin): int {
    // Se cuentan por fecha, no por periodos de 24 horas: ver includes/noches.php.
    return nochesEstancia($fechaCheckin);
}

$msg = ''; $msgType = '';

// ── Buscar ──
// Rama propia, con su testigo: las demás acciones de esta página validan el
// suyo por separado, así que esta no se cruza con ninguna.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buscar') {
    if (validateCsrf()) {
        busquedaGuardar('ficha', (string) ($_POST['q'] ?? ''));
        busquedaRedirigir();
    }
    $msg = 'Token de seguridad inválido. Intente de nuevo.'; $msgType = 'danger';
}

// ── Anular un consumo mal cargado ───────────────────────────────────
// No se borra: queda registrado y marcado, pero deja de sumar. Así el
// comprobante se puede corregir sin que desaparezca lo que pasó.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'anular_consumo') {
    if (!validateCsrf()) {
        $msg = 'Token de seguridad inválido. Intente de nuevo.'; $msgType = 'danger';
    } else {
        $consumoId = (int) ($_POST['consumo_id'] ?? 0);
        $motivo    = trim($_POST['motivo'] ?? '');

        $c = $pdo->prepare("select c.id, c.subtotal, c.estancia_id, c.anulado_en, e.estado as est_estado,
                                   coalesce(c.descripcion_manual, p.nombre, 'Consumo') as nombre
                            from consumos c
                            join estancias e on e.id = c.estancia_id
                            left join productos p on p.id = c.producto_id
                            where c.id = ?");
        $c->execute([$consumoId]);
        $con = $c->fetch(PDO::FETCH_ASSOC);

        if (mb_strlen($motivo) < 5) {
            $msg = 'Escribe el motivo de la anulación (al menos 5 caracteres).'; $msgType = 'danger';
        } elseif (!$con) {
            $msg = 'No se encontró ese consumo.'; $msgType = 'danger';
        } elseif ($con['anulado_en'] !== null) {
            $msg = 'Ese consumo ya estaba anulado.'; $msgType = 'warning';
        } elseif ($con['est_estado'] !== 'activa') {
            // Con la cuenta cerrada el total ya está liquidado. Primero se
            // reabre, luego se corrige.
            $msg = 'La cuenta está cerrada. Reábrela primero para poder corregirla.'; $msgType = 'danger';
        } else {
            try {
                $pdo->beginTransaction();

                // La estancia se bloquea antes de tocar su acumulado, y se
                // revalida dentro: con la cuenta cerrada el total ya está
                // liquidado, y descontar ahora lo descuadraria.
                $pdo->prepare("select id from estancias where id = ? for update")->execute([$con['estancia_id']]);
                $re = $pdo->prepare("select estado from estancias where id = ?");
                $re->execute([$con['estancia_id']]);

                $anul = $pdo->prepare("update consumos set anulado_en = now(), anulado_por = ?, motivo_anulacion = ?
                               where id = ? and anulado_en is null");
                $anul->execute([getCurrentUserId(), mb_substr($motivo, 0, 300), $consumoId]);

                // Sin esta comprobación, dos anulaciones simultáneas del mismo
                // cargo anulaban UNA fila y descontaban DOS veces del folio,
                // a favor del huésped. La condición del `where` ya impedía la
                // doble anulación; lo que faltaba era mirar si surtió efecto.
                if ($anul->rowCount() !== 1 || $re->fetchColumn() !== 'activa') {
                    $pdo->rollBack();
                    $msg = 'Ese cargo ya fue anulado, o la cuenta acaba de cerrarse.'; $msgType = 'warning';
                } else {
                // El total de la estancia es un acumulado: hay que restarle
                // lo que deja de contar.
                $pdo->prepare("update estancias set total_consumos = greatest(0, total_consumos - ?) where id = ?")
                    ->execute([$con['subtotal'], $con['estancia_id']]);
                $pdo->commit();

                registrarAuditoria($pdo, 'consumos', $consumoId, 'anular_consumo',
                    'Anuló el cargo "' . $con['nombre'] . '" de $' . number_format((float) $con['subtotal'], 0, ',', '.')
                    . ' en la estancia #' . $con['estancia_id'] . '. Motivo: ' . mb_substr($motivo, 0, 180));

                $msg = 'Cargo anulado. El total de la cuenta se recalculó.'; $msgType = 'success';
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Anular consumo: ' . $ex->getMessage());
                $msg = 'No se pudo anular el cargo.'; $msgType = 'danger';
            }
        }
    }
}

// ── Registrar pago / abono contra una estancia activa ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'registrar_pago') {
    if (!validateCsrf()) {
        $msg = 'Token de seguridad inválido. Intente de nuevo.';
        $msgType = 'danger';
    } else {
        $estId  = (int)($_POST['estancia_id'] ?? 0);
        // 4.2.4: un entero de pesos. Antes (float): «12abc» registraba 12, «1e6» un millón y «1.000» un peso.
        $monto  = validarMonto($_POST['monto'] ?? '', 1);
        $metodo = $_POST['metodo'] ?? 'efectivo';
        $nota   = trim($_POST['nota'] ?? '');
        if (!in_array($metodo, ['efectivo', 'tarjeta', 'transferencia', 'nequi', 'otro'], true)) {
            $metodo = 'efectivo';
        }
        if ($estId < 1) {
            $msg = 'Estancia inválida.'; $msgType = 'danger';
        } elseif ($monto === null) {
            $msg = 'El monto debe ser un número entero de pesos mayor a cero, sin letras (por ejemplo 50000).'; $msgType = 'danger';
        } else {
            // Mismo patrón que el resto: bloquear la estancia y revalidar
            // dentro, para que un pago no entre justo mientras se cierra la
            // cuenta y acabe fuera de lo liquidado.
            try {
                $pdo->beginTransaction();
                $pdo->prepare("select id from estancias where id = ? for update")->execute([$estId]);
                $chk = $pdo->prepare("select estado from estancias where id = ?");
                $chk->execute([$estId]);

                if ($chk->fetchColumn() !== 'activa') {
                    $pdo->rollBack();
                    $msg = 'La estancia seleccionada no está activa.'; $msgType = 'danger';
                } else {
                    $pdo->prepare("insert into pagos (estancia_id, monto, metodo, nota, usuario_id) values (?, ?, ?, ?, ?)")
                        ->execute([$estId, $monto, $metodo, ($nota !== '' ? $nota : null), getCurrentUserId()]);
                    $pagoId = $pdo->lastInsertId();
                    $pdo->commit();

                    registrarAuditoria($pdo, 'pagos', $pagoId, 'registrar', 'Pago $' . number_format($monto, 0, ',', '.') . " ($metodo) a estancia #$estId");
                    $msg = 'Pago registrado: $' . number_format($monto, 0, ',', '.') . " COP ($metodo).";
                    $msgType = 'success';
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Registrar pago: ' . $ex->getMessage());
                $msg = 'No se pudo registrar el pago.'; $msgType = 'danger';
            }
        }
    }
}

$huespedId = (int)($_GET['id'] ?? 0);
$buscar    = busquedaRecordada('ficha');

// ── Datos que se cargan según el modo ───────────────────────────────
$ficha        = null;   // ['huesped','estancia','consumos','reservas','historial']
$resultados   = [];     // búsqueda
$enCasa       = [];     // estancias activas (vista por defecto)

if ($huespedId > 0) {
    // ---- Ficha completa ------------------------------------------------
    $stmt = $pdo->prepare("select * from huespedes where id = ?");
    $stmt->execute([$huespedId]);
    $hu = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($hu) {
        $hu['documento_num'] = descifrar($hu['documento_num']);
        $hu['telefono']      = descifrar($hu['telefono']);

        // Estancia activa (si está hospedado ahora)
        $stmtE = $pdo->prepare("
            select e.id, e.reserva_id, e.fecha_checkin, e.tarifa_noche, e.total_consumos, e.iva_exento,
                   h.numero, th.nombre as tipo_nombre,
                   r.fecha_salida as salida_prevista, r.num_personas
            from estancias e
            join habitaciones h    on h.id  = e.habitacion_id
            join tipos_habitacion th on th.id = h.tipo_id
            left join reservas r   on r.id  = e.reserva_id
            where e.huesped_id = ? and e.estado = 'activa'
            order by e.fecha_checkin desc
            limit 1");
        $stmtE->execute([$huespedId]);
        $estancia = $stmtE->fetch(PDO::FETCH_ASSOC) ?: null;

        // Consumos y pagos de la estancia activa
        $consumos = []; $pagos = []; $pagado = 0.0;
        if ($estancia) {
            /* Aquí SÍ se traen los anulados: esta es la pantalla de
               trabajo del personal y tiene que verse qué se anuló, por
               quién y por qué. En la factura y en la vista del huésped se
               ocultan, porque allí solo cuenta lo que se cobra. */
            $stmtC = $pdo->prepare("
                select c.id, c.cantidad, c.precio_unit, c.subtotal, c.fecha,
                       c.anulado_en, c.motivo_anulacion, ua.nombre as anulado_por_nombre,
                       coalesce(c.descripcion_manual, p.nombre, 'Consumo') as nombre
                from consumos c
                left join productos p on p.id = c.producto_id
                left join usuarios ua on ua.id = c.anulado_por
                where c.estancia_id = ?
                order by c.fecha desc");
            $stmtC->execute([$estancia['id']]);
            $consumos = $stmtC->fetchAll(PDO::FETCH_ASSOC);
        }

        // Pagos/abonos ya cobrados de la estancia activa
        $pagos = []; $pagado = 0.0;
        if ($estancia) {
            $stmtP = $pdo->prepare("select monto, metodo, nota, fecha from pagos where estancia_id = ? order by fecha desc");
            $stmtP->execute([$estancia['id']]);
            $pagos = $stmtP->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pagos as $p) { $pagado += (float)$p['monto']; }
        }

        // ── Quién le acompaña ──
        // Los acompañantes se registran en el check-in
        // (modules/checkin/acompanantes.php) y no había ninguna pantalla donde
        // consultarlos después: nadie podía responder «¿quién viene con esta
        // persona?». Con los menores eso no es un detalle: se registran por Ley
        // 1098 y el sistema obliga a confirmar que se vieron los documentos;
        // registrar sin poder consultar deja ese control a medias.
        //
        // Se cuelgan de la reserva, así que una estancia abierta en mostrador
        // (sin reserva) no puede tener ninguno.
        $acompanantes = [];
        if ($estancia && $estancia['reserva_id']) {
            $stmtAc = $pdo->prepare("
                select documento_tipo, documento_num, nombres, apellidos,
                       es_menor, parentesco, nacionalidad, docs_ok
                from acompanantes
                where reserva_id = ?
                order by es_menor desc, apellidos, nombres");
            $stmtAc->execute([(int) $estancia['reserva_id']]);
            $acompanantes = $stmtAc->fetchAll(PDO::FETCH_ASSOC);
            // El documento va cifrado en la base, como el del titular.
            foreach ($acompanantes as &$_ac) {
                $_ac['documento_num'] = descifrar($_ac['documento_num']);
            }
            unset($_ac);
        }

        // Reservas futuras/pendientes (confirmadas y aún sin check-in)
        $stmtR = $pdo->prepare("
            select r.id, r.fecha_entrada, r.fecha_salida, r.num_personas,
 h.numero, th.nombre as tipo_nombre,
                   -- El precio lo manda el tipo; ver includes/tarifas.php. Aquí el
                   -- alias del tipo es th, no t, así que el coalesce va escrito.
                   -- Ojo: el alias `tarifa_base` NO puede repetirse abajo con
                   -- th.tarifa_base, porque PDO se queda con la última columna
                   -- del mismo nombre y este coalesce se perdería.
                   coalesce(th.tarifa_1, th.tarifa_2, th.tarifa_base, h.tarifa) as tarifa_base,
                   th.tarifa_1, th.tarifa_2, th.tarifa_extra
            from reservas r
            join habitaciones h     on h.id  = r.habitacion_id
            join tipos_habitacion th on th.id = h.tipo_id
            where r.huesped_id = ? and r.estado = 'confirmada'
              and not exists (select 1 from estancias e where e.reserva_id = r.id)
            order by r.fecha_entrada asc");
        $stmtR->execute([$huespedId]);
        $reservas = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        // Historial (estancias cerradas)
        $stmtH = $pdo->prepare("select count(*) c, coalesce(sum(total_pagar),0) t from estancias where huesped_id = ? and estado = 'cerrada'");
        $stmtH->execute([$huespedId]);
        $historial = $stmtH->fetch(PDO::FETCH_ASSOC);

        $ficha = compact('hu', 'estancia', 'consumos', 'pagos', 'pagado', 'reservas', 'historial', 'acompanantes');
    }
} elseif ($buscar !== '') {
    // ---- Búsqueda ------------------------------------------------------
    $stmt = $pdo->prepare("
        select id, documento_tipo, documento_num, nombres, apellidos
        from huespedes
        where documento_hash = ? or nombres like ? or apellidos like ?
        order by created_at desc
        limit 50");
    $stmt->execute([indiceCiego($buscar), "%$buscar%", "%$buscar%"]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($resultados as &$_r) { $_r['documento_num'] = descifrar($_r['documento_num']); }
    unset($_r);
} else {
    // ---- Vista por defecto: quién está hospedado ahora -----------------
    $enCasa = $pdo->query("
        select e.huesped_id, hu.nombres, hu.apellidos, h.numero,
               e.fecha_checkin, e.tarifa_noche, e.total_consumos
        from estancias e
        join huespedes hu    on hu.id = e.huesped_id
        join habitaciones h  on h.id  = e.habitacion_id
        where e.estado = 'activa'
        order by cast(h.numero as unsigned), h.numero
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$cop = fn($n) => '$' . number_format((float)$n, 0, ',', '.');

require_once __DIR__ . '/../../includes/header.php';
?>
<style>
/* ============================================================
   Estado de cuenta — Casa Bosque
   ============================================================ */
.fc-wrap { padding-bottom: 96px; }

.fc-card { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); margin-bottom: 16px; overflow: hidden; }
.fc-card-b { padding: 16px; }

/* Búsqueda */
.fc-search { position: relative; margin-bottom: 4px; }
.fc-search i.s { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: var(--cb-muted,#9A8778); }
.fc-search input { width: 100%; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; border-radius: 13px; padding: 14px 14px 14px 42px; font-family: inherit; font-size: .95rem; }
.fc-search input:focus { outline: none; border-color: var(--cb-primary,#6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }
.fc-search .clear { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: var(--cb-muted,#9A8778); text-decoration: none; }
.fc-hint { font-size: .74rem; color: var(--cb-muted,#9A8778); margin: 8px 2px 0; }

.fc-sec-title { font-size: .72rem; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: #A2907C; margin: 4px 4px 12px; display:flex; align-items:center; gap:7px; }

/* Fila de huésped (búsqueda / en casa) */
.fc-row { display: flex; align-items: center; gap: 12px; text-decoration: none; background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 15px; padding: 13px 14px; margin-bottom: 10px; box-shadow: 0 3px 10px rgba(42,23,9,.04); }
.fc-row:active { transform: scale(.99); }
.fc-av { flex: none; width: 44px; height: 44px; border-radius: 13px; background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: .9rem; }
.fc-row-info { flex: 1; min-width: 0; }
.fc-row-name { font-size: .95rem; font-weight: 700; color: var(--cb-forest,#3E2412); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fc-row-sub { font-size: .76rem; color: var(--cb-muted,#9A8778); margin-top: 2px; }
.fc-row-sub .tipo { display: inline-block; background: var(--cb-primary-light,#F5EBDC); color: var(--cb-primary,#6B4420); font-weight: 700; font-size: .62rem; padding: 1px 6px; border-radius: 5px; margin-right: 4px; }
.fc-room { flex: none; text-align: right; }
.fc-room .n { font-size: .72rem; color: var(--cb-muted,#9A8778); }
.fc-room .v { font-size: 1rem; font-weight: 800; color: var(--cb-forest,#3E2412); }
.fc-chev { flex: none; color: var(--cb-muted,#BCA88E); }

/* Cabecera de ficha */
.fc-head { display: flex; align-items: center; gap: 14px; padding: 18px 16px; background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color: #fff; }
.fc-head .fc-av { width: 52px; height: 52px; font-size: 1.1rem; background: rgba(255,255,255,.18); }
.fc-head .n { font-size: 1.15rem; font-weight: 800; }
.fc-head .d { font-size: .8rem; opacity: .9; margin-top: 3px; }
.fc-back { display:inline-flex; align-items:center; gap:7px; color: var(--cb-primary,#6B4420); text-decoration:none; font-weight:600; font-size:.85rem; margin-bottom:12px; }

/* Estado / totales */
.fc-status { display:inline-flex; align-items:center; gap:7px; font-size:.75rem; font-weight:800; padding:6px 12px; border-radius:99px; }
.fc-status.activa { background:#e6f7ec; color:#1F7A4D; }
.fc-status.reserva { background:#eef3ff; color:#3b5bdb; }
.fc-status.libre { background:#f1f5ef; color:#9A8778; }

.fc-grid { display:grid; grid-template-columns: repeat(2,1fr); gap:11px; margin: 4px 0 2px; }
.fc-cell { background:#FBF8F2; border:1px solid #F3EADD; border-radius:13px; padding:12px 13px; }
.fc-cell .k { font-size:.7rem; color:var(--cb-muted,#9A8778); font-weight:600; display:flex; align-items:center; gap:6px; }
.fc-cell .v { font-size:1.05rem; font-weight:800; color:var(--cb-forest,#3E2412); margin-top:5px; }
.fc-cell.wide { grid-column: 1 / -1; }

.fc-line { display:flex; justify-content:space-between; align-items:center; padding:11px 0; border-bottom:1px solid #F1EADF; font-size:.9rem; }
.fc-line:last-child { border-bottom:none; }
.fc-line .lbl { color:var(--cb-muted,#9A8778); display:flex; align-items:center; gap:8px; }
.fc-line .val { font-weight:700; color:var(--cb-forest,#3E2412); }

/* Acompañantes de la estancia */
.fc-acomp { display:flex; align-items:center; gap:12px; padding:11px 0; border-bottom:1px solid #F1EADF; }
.fc-acomp:last-of-type { border-bottom:none; }
.fc-acomp .ai { flex:none; width:38px; height:38px; border-radius:12px; display:flex; align-items:center; justify-content:center; background:var(--cb-primary-light,#F5EBDC); color:var(--cb-primary,#6B4420); }
.fc-acomp .an { min-width:0; }
.fc-acomp .nm { font-size:.9rem; font-weight:700; color:var(--cb-forest,#3E2412); display:flex; align-items:center; flex-wrap:wrap; gap:7px; }
.fc-acomp .mt { font-size:.76rem; color:var(--cb-muted,#9A8778); margin-top:2px; }
.fc-acomp .tag { font-size:.62rem; font-weight:800; padding:2px 8px; border-radius:99px; letter-spacing:.03em; }
.fc-acomp .tag.menor { background:#FAF0DC; color:#A8701A; }
.fc-acomp .tag.falta { background:#fdeaea; color:#b91c1c; }

.fc-total { display:flex; justify-content:space-between; align-items:center; margin-top:8px; padding:16px; background: var(--cb-primary-light,#F5EBDC); border:1px solid rgba(107,68,32,.2); border-radius:15px; }
.fc-total .tl { font-size:.8rem; font-weight:800; text-transform:uppercase; letter-spacing:.4px; color:var(--cb-forest,#3E2412); }
.fc-total .tv { font-size:1.7rem; font-weight:900; color:var(--cb-primary,#6B4420); }

.fc-actions { display:grid; grid-template-columns:1fr 1fr; gap:11px; margin-top:14px; }
.fc-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; text-decoration:none; font-weight:700; font-size:.9rem; padding:14px; border-radius:14px; border:none; cursor:pointer; font-family:inherit; width:100%; }
.fc-btn.primary { color:#fff; background:linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); box-shadow:0 8px 18px rgba(107,68,32,.25); }
.fc-btn.ghost { color:var(--cb-forest,#3E2412); background:#fff; border:1px solid var(--cb-border,#EDE3D2); }
.fc-btn:active { transform: scale(.98); }

.fc-consumo { display:flex; align-items:center; gap:12px; padding:11px 0; border-bottom:1px solid #F1EADF; }
.fc-consumo:last-child { border-bottom:none; }
.fc-consumo .q { flex:none; width:34px; height:34px; border-radius:10px; background:var(--cb-primary-light,#F5EBDC); color:var(--cb-primary,#6B4420); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:.82rem; }
.fc-consumo .ci { flex:1; min-width:0; }
.fc-consumo .cn { font-size:.86rem; font-weight:700; color:var(--cb-forest,#3E2412); }
.fc-consumo .cm { font-size:.7rem; color:var(--cb-muted,#9A8778); }
.fc-consumo .ca { flex:none; font-weight:700; color:var(--cb-forest,#3E2412); font-size:.88rem; }
/* Cargo anulado: se ve, tachado, con quién y por qué. No desaparece. */
.fc-consumo.anulado .cn, .fc-consumo.anulado .ca { text-decoration:line-through; opacity:.6; }
.fc-consumo.anulado .q { background:#fdeaea; color:#b91c1c; }
.fc-anulado-tag { display:inline-block; margin-left:6px; padding:1px 7px; border-radius:99px; background:#fdeaea; color:#b91c1c; font-size:.6rem; font-weight:800; letter-spacing:.04em; vertical-align:middle; }
.fc-anular-c { flex:none; width:32px; height:32px; border-radius:9px; border:1px solid rgba(239,68,68,.25); background:#fff; color:#b91c1c; cursor:pointer; font-size:.78rem; }
.fc-anular-c:hover { background:#fdeded; }

.fc-empty { text-align:center; padding:30px 12px; color:var(--cb-muted,#9A8778); }
.fc-empty i { font-size:2rem; color:var(--cb-primary,#6B4420); opacity:.4; margin-bottom:10px; display:block; }
.fc-empty p { font-size:.9rem; margin:0; }


/* Pago: método + modal */
.fc-pago { display:flex; align-items:center; gap:12px; padding:11px 0; border-bottom:1px solid #F1EADF; }
.fc-pago:last-child { border-bottom:none; }
.fc-pago .pi { flex:none; width:34px; height:34px; border-radius:10px; background:#e6f7ec; color:#1F7A4D; display:flex; align-items:center; justify-content:center; font-size:.85rem; }
.fc-pago .pd { flex:1; min-width:0; }
.fc-pago .pn { font-size:.86rem; font-weight:700; color:var(--cb-forest,#3E2412); text-transform:capitalize; }
.fc-pago .pm { font-size:.7rem; color:var(--cb-muted,#9A8778); }
.fc-pago .pv { flex:none; font-weight:800; color:#1F7A4D; font-size:.9rem; }

.pg-overlay { position:fixed; inset:0; background:rgba(15,23,42,.45); backdrop-filter:blur(3px); display:flex; align-items:flex-end; justify-content:center; z-index:9999; opacity:0; pointer-events:none; transition:opacity .22s ease; }
.pg-overlay.active { opacity:1; pointer-events:auto; }
.pg-card { background:#fff; width:100%; max-width:460px; border-radius:22px 22px 0 0; padding:18px 18px calc(22px + env(safe-area-inset-bottom)); transform:translateY(100%); transition:transform .28s cubic-bezier(.34,1.4,.5,1); }
.pg-overlay.active .pg-card { transform:translateY(0); }
/* El pago es una hoja que sube desde abajo, pensada para el celular. En una pantalla ancha quedaba pegada al borde de abajo y la
   ventana del navegador la cortaba (se veían los botones a medias): ahí se CENTRA como una tarjeta, y en cualquier pantalla, si no
   cabe, se desplaza por dentro en vez de salirse. */
.pg-card { max-height:94vh; max-height:94dvh; overflow-y:auto; }
@media (min-width: 769px) {
    .pg-overlay { align-items:center; padding:16px; }
    .pg-card { border-radius:22px; padding:22px; transform:scale(.94); box-shadow:0 24px 60px rgba(42,23,9,.28); }
    .pg-overlay.active .pg-card { transform:scale(1); }
}
.pg-title { font-size:1.05rem; font-weight:800; color:var(--cb-forest,#3E2412); display:flex; align-items:center; gap:9px; margin:0 0 4px; }
.pg-sub { font-size:.8rem; color:var(--cb-muted,#9A8778); margin-bottom:14px; }
.pg-field { margin-bottom:12px; }
.pg-field label { display:block; font-size:.8rem; font-weight:600; color:var(--cb-forest,#3E2412); margin-bottom:6px; }
.pg-input, .pg-select { width:100%; border:1px solid var(--cb-border,#EDE3D2); border-radius:12px; padding:13px 14px; font-family:inherit; font-size:.95rem; }
.pg-input:focus, .pg-select:focus { outline:none; border-color:var(--cb-primary,#6B4420); box-shadow:0 0 0 3px rgba(107,68,32,.1); }
.pg-methods { display:grid; grid-template-columns:repeat(2,1fr); gap:9px; }
.pg-m { position:relative; }
.pg-m input { position:absolute; opacity:0; pointer-events:none; }
.pg-m span { display:flex; align-items:center; justify-content:center; gap:7px; padding:12px; border:1px solid var(--cb-border,#EDE3D2); border-radius:12px; font-size:.85rem; font-weight:600; color:var(--cb-forest,#3E2412); cursor:pointer; }
.pg-m input:checked + span { border-color:var(--cb-primary,#6B4420); background:var(--cb-primary-light,#F5EBDC); color:var(--cb-primary,#6B4420); }
.pg-actions { display:flex; gap:10px; margin-top:16px; }
.pg-btn { flex:1; border:none; cursor:pointer; font-family:inherit; font-weight:700; font-size:.92rem; padding:14px; border-radius:13px; }
.pg-btn.cancel { background:#f1f5ef; color:#556; }
.pg-btn.ok { color:#fff; background:linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); box-shadow:0 8px 18px rgba(107,68,32,.25); }

/* ── Tarjeta de confirmación ──
   Mismos nombres y medidas que en habitaciones, reservas y seguridad, para
   que las confirmaciones se vean iguales en todo el sistema. */
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.4);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:10000;opacity:0;pointer-events:none;transition:opacity .25s;padding:16px;}
.modal-overlay.active{opacity:1;pointer-events:auto;}
.confirm-card{background:#fff;width:100%;max-width:420px;border-radius:18px;box-shadow:0 20px 40px rgba(0,0,0,.18);overflow:hidden;transform:scale(.92);transition:transform .25s cubic-bezier(.34,1.56,.64,1);}
.modal-overlay.active .confirm-card{transform:scale(1);}
.confirm-header{padding:20px 20px 8px;display:flex;align-items:center;gap:12px;}
.confirm-title{font-size:1.05rem;font-weight:700;color:var(--cb-forest,#3E2412);margin:0;}
.confirm-body{padding:0 20px 16px;color:#475569;font-size:.86rem;line-height:1.5;}
.confirm-actions{padding:14px 20px 18px;display:flex;justify-content:flex-end;gap:10px;}
.icon-container-warn{width:42px;height:42px;background:rgba(245,158,11,.12);color:var(--warning,#f59e0b);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.btn-x{padding:11px 16px;border-radius:11px;border:1px solid var(--cb-border,#EDE3D2);background:#fff;color:#475569;font-weight:600;cursor:pointer;font-family:inherit;}
.btn-w{padding:11px 16px;border-radius:11px;border:none;background:var(--warning,#f59e0b);color:#fff;font-weight:700;cursor:pointer;font-family:inherit;}
</style>

<div class="fc-wrap">

<?php if ($msg): ?>
    <div class="cb-msg-inline" style="display:flex; align-items:center; gap:10px; padding:13px 15px; border-radius:14px; margin-bottom:16px; font-size:.9rem; font-weight:500; <?= $msgType === 'success' ? 'background:#e6f7f2; color:#047857; border:1px solid rgba(31,122,77,.25);' : 'background:#fdeded; color:#b91c1c; border:1px solid rgba(239,68,68,.25);' ?>">
        <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
        <span><?= htmlspecialchars($msg) ?></span>
    </div>
<?php endif; ?>

<?php if ($ficha): ?>
    <?php
        $hu = $ficha['hu']; $estancia = $ficha['estancia'];
        $consumos = $ficha['consumos']; $pagos = $ficha['pagos']; $pagado = $ficha['pagado'];
        $reservas = $ficha['reservas']; $historial = $ficha['historial'];
        $acompanantes = $ficha['acompanantes'] ?? [];
        $iniciales = strtoupper(mb_substr($hu['nombres'],0,1,'UTF-8') . mb_substr($hu['apellidos'],0,1,'UTF-8'));
    ?>
    <a href="<?= BASE_URL ?>/modules/huespedes/ficha.php" class="fc-back"><i class="fas fa-arrow-left"></i> Volver a la búsqueda</a>

    <!-- Cabecera del huésped -->
    <div class="fc-card">
        <div class="fc-head">
            <div class="fc-av"><?= $iniciales ?></div>
            <div style="flex:1; min-width:0;">
                <div class="n"><?= htmlspecialchars($hu['nombres'] . ' ' . $hu['apellidos']) ?></div>
                <div class="d"><?= htmlspecialchars($hu['documento_tipo'] . ' ' . $hu['documento_num']) ?><?= $hu['telefono'] ? ' · ' . htmlspecialchars($hu['telefono']) : '' ?></div>
            </div>
        </div>
        <div class="fc-card-b" style="padding-top:14px;">
            <?php if ($estancia): ?>
                <span class="fc-status activa"><i class="fas fa-circle" style="font-size:.5rem;"></i> Hospedado ahora · Hab. <?= htmlspecialchars($estancia['numero']) ?></span>
            <?php elseif (!empty($reservas)): ?>
                <span class="fc-status reserva"><i class="fas fa-calendar-check"></i> Con reserva pendiente</span>
            <?php else: ?>
                <span class="fc-status libre"><i class="fas fa-user"></i> Sin estancia ni reserva activa</span>
            <?php endif; ?>
            <?php if ((int)$historial['c'] > 0): ?>
                <span style="font-size:.76rem; color:var(--cb-muted,#9A8778); margin-left:8px;"><i class="fas fa-clock-rotate-left"></i> <?= (int)$historial['c'] ?> estancia(s) previa(s)</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($estancia): ?>
        <?php
            $noches   = nochesTranscurridas($estancia['fecha_checkin']);
            $subNoches = $noches * (float)$estancia['tarifa_noche'];
            $extras    = (float)$estancia['total_consumos'];
            $totalHoy  = $subNoches + $extras;
            $saldo     = $totalHoy - (float)$pagado;
        ?>
        <!-- Estado de cuenta de la estancia activa -->
        <div class="fc-card">
            <div class="fc-card-b">
                <div class="fc-sec-title"><i class="fas fa-file-invoice-dollar"></i> Estado de cuenta (hoy)</div>

                <div class="fc-grid">
                    <div class="fc-cell">
                        <div class="k"><i class="fas fa-moon"></i> Noches</div>
                        <div class="v"><?= $noches ?></div>
                    </div>
                    <div class="fc-cell">
                        <div class="k"><i class="fas fa-tag"></i> Tarifa / noche</div>
                        <div class="v"><?= $cop($estancia['tarifa_noche']) ?></div>
                    </div>
                    <div class="fc-cell">
                        <div class="k"><i class="fas fa-door-open"></i> Entrada</div>
                        <div class="v" style="font-size:.9rem;"><?= date('d/m/Y', strtotime($estancia['fecha_checkin'])) ?></div>
                    </div>
                    <div class="fc-cell">
                        <div class="k"><i class="fas fa-door-closed"></i> Salida prevista</div>
                        <div class="v" style="font-size:.9rem;"><?= $estancia['salida_prevista'] ? date('d/m/Y', strtotime($estancia['salida_prevista'])) : '—' ?></div>
                    </div>
                </div>

                <div style="margin-top:14px;">
                    <div class="fc-line"><span class="lbl"><i class="fas fa-bed"></i> Hospedaje (<?= $noches ?> × <?= $cop($estancia['tarifa_noche']) ?>)</span><span class="val"><?= $cop($subNoches) ?></span></div>
                    <div class="fc-line"><span class="lbl"><i class="fas fa-receipt"></i> Consumos y extras</span><span class="val"><?= $cop($extras) ?></span></div>
                    <div class="fc-line"><span class="lbl"><i class="fas fa-file-invoice-dollar"></i> Total de la cuenta</span><span class="val"><?= $cop($totalHoy) ?></span></div>
                    <?php if ($pagado > 0): ?>
                    <div class="fc-line"><span class="lbl"><i class="fas fa-hand-holding-dollar"></i> Pagado</span><span class="val" style="color:#1F7A4D;">− <?= $cop($pagado) ?></span></div>
                    <?php endif; ?>
                </div>

                <?php
                    if ($saldo > 0.5)       { $tTl = 'Saldo pendiente'; $tVal = $cop($saldo);  $tBg = ''; $tCol = ''; }
                    elseif ($saldo < -0.5)  { $tTl = 'Saldo a favor';    $tVal = $cop(-$saldo); $tBg = 'background:#eef3ff; border-color:rgba(59,91,219,.25);'; $tCol = 'color:#3b5bdb;'; }
                    else                    { $tTl = 'Cuenta al día';    $tVal = '$0';          $tBg = 'background:#e6f7ec; border-color:rgba(15,122,53,.25);'; $tCol = 'color:#1F7A4D;'; }
                ?>
                <div class="fc-total" style="<?= $tBg ?>">
                    <span class="tl"><?= $tTl ?></span>
                    <span class="tv" style="<?= $tCol ?>"><?= $tVal ?></span>
                </div>

                <div class="fc-actions">
                    <a href="<?= BASE_URL ?>/modules/consumos/index.php?estancia=<?= $estancia['id'] ?>" class="fc-btn ghost"><i class="fas fa-plus"></i> Cargar consumo</a>
                    <button type="button" class="fc-btn primary" onclick="abrirPago(<?= (int)$estancia['id'] ?>, <?= (int)round($saldo) ?>)"><i class="fas fa-cash-register"></i> Registrar pago</button>
                </div>
                <a href="<?= BASE_URL ?>/modules/checkout/index.php" class="fc-btn ghost" style="margin-top:11px;"><i class="fas fa-right-from-bracket"></i> Check-out y cierre</a>
            </div>
        </div>

        <!-- Quién le acompaña -->
        <div class="fc-card">
            <div class="fc-card-b">
                <div class="fc-sec-title"><i class="fas fa-user-group"></i> Acompañantes</div>
                <?php if (!$estancia['reserva_id']): ?>
                    <div class="fc-empty"><i class="fas fa-user-group"></i><p>La estancia se abrió en mostrador, sin reserva. Los acompañantes se registran sobre la reserva, así que aquí no hay ninguno.</p></div>
                <?php elseif (empty($acompanantes)): ?>
                    <div class="fc-empty"><i class="fas fa-user-group"></i><p>Viaja solo: no se registró ningún acompañante.</p></div>
                <?php else: ?>
                    <?php foreach ($acompanantes as $a): $acMenor = (int) $a['es_menor'] === 1; ?>
                        <div class="fc-acomp">
                            <div class="ai"><i class="fas fa-<?= $acMenor ? 'child' : 'user' ?>"></i></div>
                            <div class="an">
                                <div class="nm"><?= htmlspecialchars($a['nombres'] . ' ' . $a['apellidos']) ?>
                                    <?php if ($acMenor): ?><span class="tag menor">Menor</span><?php endif; ?>
                                    <?php /* Ley 1098: en un menor hay que haber visto los documentos.
                                             Si no se confirmó, se dice aquí. */ ?>
                                    <?php if ($acMenor && (int) $a['docs_ok'] !== 1): ?><span class="tag falta">Documentos sin verificar</span><?php endif; ?>
                                </div>
                                <div class="mt"><?= htmlspecialchars((string) $a['documento_tipo']) ?> <?= htmlspecialchars((string) ($a['documento_num'] ?? '—')) ?><?php if (!empty($a['parentesco'])): ?> · <?= htmlspecialchars((string) $a['parentesco']) ?><?php endif; ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <a href="<?= BASE_URL ?>/modules/checkin/acompanantes.php?reserva=<?= (int) $estancia['reserva_id'] ?>&amp;volver=ficha&amp;vid=<?= (int) $huespedId ?>" class="fc-btn ghost" style="margin-top:11px;"><i class="fas fa-user-group"></i> Ver el registro completo</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Detalle de consumos -->
        <div class="fc-card">
            <div class="fc-card-b">
                <div class="fc-sec-title"><i class="fas fa-list-ul"></i> Consumos cargados</div>
                <?php if (empty($consumos)): ?>
                    <div class="fc-empty"><i class="fas fa-receipt"></i><p>Sin consumos registrados todavía.</p></div>
                <?php else: ?>
                    <?php foreach ($consumos as $c): $cAnulado = !empty($c['anulado_en']); ?>
                        <div class="fc-consumo <?= $cAnulado ? 'anulado' : '' ?>">
                            <div class="q"><?= (int)$c['cantidad'] ?>×</div>
                            <div class="ci">
                                <div class="cn">
                                    <?= htmlspecialchars($c['nombre']) ?>
                                    <?php if ($cAnulado): ?><span class="fc-anulado-tag">ANULADO</span><?php endif; ?>
                                </div>
                                <div class="cm"><?= $cop($c['precio_unit']) ?> c/u · <?= date('d/m H:i', strtotime($c['fecha'])) ?></div>
                                <?php if ($cAnulado): ?>
                                    <div class="cm" style="color:#b91c1c;">
                                        Anulado el <?= date('d/m/Y H:i', strtotime($c['anulado_en'])) ?>
                                        <?= $c['anulado_por_nombre'] ? 'por ' . htmlspecialchars($c['anulado_por_nombre']) : '' ?>.
                                        <?= htmlspecialchars((string) $c['motivo_anulacion']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="ca"><?= $cop($c['subtotal']) ?></div>
                            <?php if (!$cAnulado): ?>
                                <?php /* El número va con json_encode: htmlspecialchars solo no basta dentro de un literal de JS. */ ?>
                                <button type="button" class="fc-anular-c" title="Anular este cargo"
                                        onclick="pedirAnularConsumo(<?= (int)$c['id'] ?>, <?= htmlspecialchars(json_encode($c['nombre']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($cop($c['subtotal'])), ENT_QUOTES) ?>)">
                                    <i class="fas fa-ban"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($pagos)): ?>
        <!-- Pagos registrados -->
        <div class="fc-card">
            <div class="fc-card-b">
                <div class="fc-sec-title"><i class="fas fa-hand-holding-dollar"></i> Pagos registrados</div>
                <?php foreach ($pagos as $p): ?>
                    <div class="fc-pago">
                        <div class="pi"><i class="fas fa-<?= $p['metodo'] === 'tarjeta' ? 'credit-card' : ($p['metodo'] === 'transferencia' ? 'building-columns' : ($p['metodo'] === 'efectivo' ? 'money-bill-wave' : 'wallet')) ?>"></i></div>
                        <div class="pd">
                            <div class="pn"><?= htmlspecialchars($p['metodo']) ?></div>
                            <div class="pm"><?= date('d/m H:i', strtotime($p['fecha'])) ?><?= $p['nota'] ? ' · ' . htmlspecialchars($p['nota']) : '' ?></div>
                        </div>
                        <div class="pv"><?= $cop($p['monto']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($reservas)): ?>
        <!-- Reservas pendientes -->
        <div class="fc-card">
            <div class="fc-card-b">
                <div class="fc-sec-title"><i class="fas fa-calendar-check"></i> Reservas pendientes</div>
                <?php foreach ($reservas as $r):
                    $n = max(1, (int)(new DateTime($r['fecha_entrada']))->diff(new DateTime($r['fecha_salida']))->days);
                    // Según las personas de la reserva, no un precio único.
                $estimado = $n * (float)(tarifaPorPersonas($r, (int)$r['num_personas']) ?? $r['tarifa_base']);
                ?>
                    <div style="border:1px solid #F3EADD; border-radius:14px; padding:13px; margin-bottom:11px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <span style="font-weight:800; color:var(--cb-forest,#3E2412);">Hab. <?= htmlspecialchars($r['numero']) ?> · <?= htmlspecialchars($r['tipo_nombre']) ?></span>
                        </div>
                        <div class="fc-line"><span class="lbl"><i class="fas fa-calendar-day"></i> Fechas</span><span class="val"><?= date('d/m/Y', strtotime($r['fecha_entrada'])) ?> → <?= date('d/m/Y', strtotime($r['fecha_salida'])) ?></span></div>
                        <div class="fc-line"><span class="lbl"><i class="fas fa-moon"></i> Noches</span><span class="val"><?= $n ?></span></div>
                        <div class="fc-line"><span class="lbl"><i class="fas fa-users"></i> Personas</span><span class="val"><?= (int)$r['num_personas'] ?></span></div>
                        <div class="fc-line"><span class="lbl"><i class="fas fa-coins"></i> Total estimado</span><span class="val"><?= $cop($estimado) ?></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$estancia && empty($reservas)): ?>
        <div class="fc-card"><div class="fc-card-b">
            <div class="fc-empty"><i class="fas fa-moon"></i><p>Este huésped no está hospedado y no tiene reservas pendientes.</p></div>
        </div></div>
    <?php endif; ?>

<?php else: ?>
    <!-- ================= Búsqueda + hospedados ahora ================= -->
    <div class="fc-card"><div class="fc-card-b">
        <form method="POST" class="fc-search">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="buscar">
            <i class="fas fa-search s"></i>
            <input type="text" name="q" placeholder="Buscar por documento o nombre..." value="<?= htmlspecialchars($buscar) ?>" autofocus autocomplete="off">
            <?php if ($buscar): ?><a href="?limpiar=1" class="clear"><i class="fas fa-times-circle"></i></a><?php endif; ?>
        </form>
        <div class="fc-hint"><i class="fas fa-circle-info"></i> Escribe el documento completo o parte del nombre y toca al huésped para ver su cuenta.</div>
    </div></div>

    <?php if ($buscar !== ''): ?>
        <div class="fc-sec-title"><i class="fas fa-magnifying-glass"></i> Resultados (<?= count($resultados) ?>)</div>
        <?php if (empty($resultados)): ?>
            <div class="fc-card"><div class="fc-card-b"><div class="fc-empty"><i class="fas fa-user-slash"></i><p>No se encontró ningún huésped.</p></div></div></div>
        <?php else: ?>
            <?php foreach ($resultados as $r): ?>
                <a href="<?= BASE_URL ?>/modules/huespedes/ficha.php?id=<?= $r['id'] ?>" class="fc-row">
                    <div class="fc-av"><?= strtoupper(mb_substr($r['nombres'],0,1,'UTF-8') . mb_substr($r['apellidos'],0,1,'UTF-8')) ?></div>
                    <div class="fc-row-info">
                        <div class="fc-row-name"><?= htmlspecialchars($r['nombres'] . ' ' . $r['apellidos']) ?></div>
                        <div class="fc-row-sub"><span class="tipo"><?= $r['documento_tipo'] ?></span><?= htmlspecialchars($r['documento_num']) ?></div>
                    </div>
                    <i class="fas fa-chevron-right fc-chev"></i>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php else: ?>
        <div class="fc-sec-title"><i class="fas fa-bed"></i> Hospedados ahora (<?= count($enCasa) ?>)</div>
        <?php if (empty($enCasa)): ?>
            <div class="fc-card"><div class="fc-card-b"><div class="fc-empty"><i class="fas fa-bed"></i><p>No hay huéspedes hospedados en este momento.</p></div></div></div>
        <?php else: ?>
            <?php foreach ($enCasa as $e):
                $n = nochesTranscurridas($e['fecha_checkin']);
                $tot = $n * (float)$e['tarifa_noche'] + (float)$e['total_consumos'];
            ?>
                <a href="<?= BASE_URL ?>/modules/huespedes/ficha.php?id=<?= $e['huesped_id'] ?>" class="fc-row">
                    <div class="fc-av"><?= strtoupper(mb_substr($e['nombres'],0,1,'UTF-8') . mb_substr($e['apellidos'],0,1,'UTF-8')) ?></div>
                    <div class="fc-row-info">
                        <div class="fc-row-name"><?= htmlspecialchars($e['nombres'] . ' ' . $e['apellidos']) ?></div>
                        <div class="fc-row-sub"><i class="fas fa-moon" style="font-size:.7rem;"></i> <?= $n ?> noche(s) · debe <?= $cop($tot) ?></div>
                    </div>
                    <div class="fc-room">
                        <div class="n">Hab.</div>
                        <div class="v"><?= htmlspecialchars($e['numero']) ?></div>
                    </div>
                    <i class="fas fa-chevron-right fc-chev"></i>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

<?php endif; ?>

</div><!-- /.fc-wrap -->

<?php if ($ficha && $ficha['estancia']): ?>
<!-- Modal: registrar pago -->
<div class="pg-overlay" id="pgOverlay">
    <div class="pg-card">
        <div class="pg-title"><i class="fas fa-cash-register" style="color:var(--cb-primary,#6B4420);"></i> Registrar pago</div>
        <div class="pg-sub">Abono a la cuenta de <?= htmlspecialchars($ficha['hu']['nombres'] . ' ' . $ficha['hu']['apellidos']) ?> · Hab. <?= htmlspecialchars($ficha['estancia']['numero']) ?></div>
        <form method="POST" action="<?= BASE_URL ?>/modules/huespedes/ficha.php?id=<?= (int)$huespedId ?>" id="pgForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="registrar_pago">
            <input type="hidden" name="estancia_id" id="pgEstancia" value="">
            <div class="pg-field">
                <label for="pgMonto">Monto a pagar (COP)</label>
                <input type="number" name="monto" id="pgMonto" class="pg-input" min="1" step="1" inputmode="numeric" data-solo="digitos" placeholder="0" required oninput="pgChequear()">
                <div id="pgWarn" style="display:none; margin-top:8px; font-size:.78rem; color:#b45309; background:#fff3e8; border:1px solid rgba(249,115,22,.28); border-radius:10px; padding:9px 11px;">
                    <i class="fas fa-triangle-exclamation"></i> <span id="pgWarnTxt"></span>
                </div>
            </div>
            <div class="pg-field">
                <label>Método de pago</label>
                <div class="pg-methods">
                    <label class="pg-m"><input type="radio" name="metodo" value="efectivo" checked><span><i class="fas fa-money-bill-wave"></i> Efectivo</span></label>
                    <label class="pg-m"><input type="radio" name="metodo" value="tarjeta"><span><i class="fas fa-credit-card"></i> Tarjeta</span></label>
                    <label class="pg-m"><input type="radio" name="metodo" value="transferencia"><span><i class="fas fa-building-columns"></i> Transfer.</span></label>
                    <label class="pg-m"><input type="radio" name="metodo" value="nequi"><span><i class="fas fa-mobile-screen-button"></i> Nequi</span></label>
                    <label class="pg-m"><input type="radio" name="metodo" value="otro"><span><i class="fas fa-wallet"></i> Otro</span></label>
                </div>
            </div>
            <div class="pg-field">
                <label for="pgNota">Nota (opcional)</label>
                <input type="text" name="nota" id="pgNota" class="pg-input" maxlength="200" placeholder="Ej: abono, referencia...">
            </div>
            <div class="pg-actions">
                <button type="button" class="pg-btn cancel" onclick="cerrarPago()">Cancelar</button>
                <button type="submit" class="pg-btn ok"><i class="fas fa-check"></i> Confirmar pago</button>
            </div>
        </form>
    </div>
</div>

<?php /* ── Anular un cargo ── */ ?>
<div id="anularc-overlay" class="modal-overlay">
    <div class="confirm-card">
        <div class="confirm-header">
            <div class="icon-container-warn" style="background:rgba(239,68,68,.1);color:var(--danger,#ef4444);"><i class="fas fa-ban"></i></div>
            <h4 class="confirm-title">¿Anular este cargo?</h4>
        </div>
        <form method="POST" action="<?= BASE_URL ?>/modules/huespedes/ficha.php?id=<?= (int)$huespedId ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="anular_consumo">
            <input type="hidden" name="consumo_id" id="anularc-id" value="">
            <div class="confirm-body">
                Vas a anular <strong id="anularc-nombre" style="color:var(--cb-forest,#3E2412);"></strong>
                por <strong id="anularc-monto" style="color:var(--cb-forest,#3E2412);"></strong>.<br><br>
                <strong>El cargo no se borra:</strong> queda registrado y tachado, con tu nombre y el motivo,
                pero deja de sumar al total de la cuenta. La factura y lo que ve el huésped no lo incluyen.
                <label for="anularc-motivo" style="display:block;margin-top:14px;font-size:.8rem;font-weight:700;color:#475569;">
                    Motivo
                </label>
                <textarea name="motivo" id="anularc-motivo" required minlength="5"
                          style="width:100%;margin-top:6px;padding:10px 12px;border:1.5px solid var(--cb-border,#EDE3D2);border-radius:10px;font-family:inherit;font-size:.86rem;resize:vertical;min-height:64px;"
                          placeholder="Ej: El huésped no consumió este desayuno."></textarea>
            </div>
            <div class="confirm-actions">
                <button type="button" class="btn-x" onclick="cerrarAnularC()">Cancelar</button>
                <button type="submit" class="btn-w" style="background:var(--danger,#ef4444);">Anular cargo</button>
            </div>
        </form>
    </div>
</div>

<?php /* Tarjeta de aviso cuando el monto supera el saldo, en vez del confirm() del navegador */ ?>
<div id="pgConfirmOverlay" class="modal-overlay">
    <div class="confirm-card">
        <div class="confirm-header">
            <div class="icon-container-warn"><i class="fas fa-circle-exclamation"></i></div>
            <h4 class="confirm-title" id="pgcTitulo">El monto supera el saldo</h4>
        </div>
        <div class="confirm-body" id="pgcCuerpo"></div>
        <div class="confirm-actions">
            <button type="button" class="btn-x" onclick="pgcCerrar()">Revisar el monto</button>
            <button type="button" class="btn-w" onclick="pgcConfirmar()">Registrar igual</button>
        </div>
    </div>
</div>

<script>
var __pgSaldo = 0;
function abrirPago(estanciaId, saldo) {
    __pgSaldo = saldo || 0;
    document.getElementById('pgEstancia').value = estanciaId;
    var m = document.getElementById('pgMonto');
    m.value = (__pgSaldo > 0) ? __pgSaldo : '';
    pgChequear();
    document.getElementById('pgOverlay').classList.add('active');
    setTimeout(function(){ try { m.focus(); m.select(); } catch(e){} }, 260);
}
function pgChequear() {
    var monto = parseInt(document.getElementById('pgMonto').value, 10) || 0;
    var w = document.getElementById('pgWarn');
    var t = document.getElementById('pgWarnTxt');
    if (monto > 0 && monto > __pgSaldo) {
        if (__pgSaldo > 0) {
            var exceso = monto - __pgSaldo;
            t.textContent = 'Estás cobrando $' + exceso.toLocaleString('es-CO') + ' más que el saldo pendiente ($' + __pgSaldo.toLocaleString('es-CO') + '). Quedará saldo a favor.';
        } else {
            t.textContent = 'La cuenta no tiene saldo pendiente; este pago quedará como saldo a favor.';
        }
        w.style.display = 'block';
    } else {
        w.style.display = 'none';
    }
}
function cerrarPago() { document.getElementById('pgOverlay').classList.remove('active'); }
document.getElementById('pgOverlay').addEventListener('click', function(e){ if (e.target === this) cerrarPago(); });
// ── Anular un cargo ──
(function () {
    var ov = document.getElementById('anularc-overlay');
    if (!ov) return;
    window.pedirAnularConsumo = function (id, nombre, monto) {
        document.getElementById('anularc-id').value = id;
        document.getElementById('anularc-nombre').textContent = nombre;
        document.getElementById('anularc-monto').textContent = monto;
        document.getElementById('anularc-motivo').value = '';
        ov.classList.add('active');
        setTimeout(function () { document.getElementById('anularc-motivo').focus(); }, 250);
    };
    window.cerrarAnularC = function () { ov.classList.remove('active'); };
    ov.addEventListener('click', function (e) { if (e.target === ov) window.cerrarAnularC(); });
})();

// ── Aviso cuando el monto supera el saldo ──
// Va enganchado al submit y no al clic del botón, para que el navegador
// valide primero el campo del monto. Antes usaba el confirm() del
// navegador, que se ve como un aviso del sistema y no como la aplicación.
(function () {
    var form = document.getElementById('pgForm');
    var ov   = document.getElementById('pgConfirmOverlay');
    if (!form || !ov) return;

    var pesos = function (n) { return '$' + (n || 0).toLocaleString('es-CO'); };

    form.addEventListener('submit', function (e) {
        if (form.dataset.confirmado === '1') return;        // ya se confirmó
        var monto = parseInt(document.getElementById('pgMonto').value, 10) || 0;
        if (monto <= 0 || monto <= __pgSaldo) return;        // nada que advertir

        e.preventDefault();
        var aFavor = monto - __pgSaldo;
        document.getElementById('pgcTitulo').textContent = __pgSaldo > 0
            ? 'El monto supera el saldo'
            : 'La cuenta no tiene saldo pendiente';
        document.getElementById('pgcCuerpo').innerHTML = __pgSaldo > 0
            ? 'Vas a registrar <strong>' + pesos(monto) + '</strong> cuando el saldo pendiente es de '
              + '<strong>' + pesos(__pgSaldo) + '</strong>.<br><br>La diferencia de <strong>'
              + pesos(aFavor) + '</strong> quedará como saldo a favor del huésped.'
            : 'Esta cuenta está al día. El pago de <strong>' + pesos(monto)
              + '</strong> quedará completo como <strong>saldo a favor</strong> del huésped.';
        ov.classList.add('active');
    });

    window.pgcCerrar = function () { ov.classList.remove('active'); };
    window.pgcConfirmar = function () {
        form.dataset.confirmado = '1';
        ov.classList.remove('active');
        form.submit();
    };
    ov.addEventListener('click', function (e) { if (e.target === ov) window.pgcCerrar(); });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
