<?php
// Módulo: Consumos — productos de carta/tienda y cargos manuales a estancias activas
$pageTitle = 'Consumos';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/validacion.php';   // validarMonto, validarEntero (montos que se teclean)
requireLogin();

$pdo = getDB();
$msg = '';
$msgType = '';

// Venta de restaurante para clientes que no están hospedados.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'venta_mostrador') {
    if (!validateCsrf()) {
        $msg = 'Token de seguridad inválido. Intente de nuevo.';
        $msgType = 'danger';
    } else {
        $productoId = (int)($_POST['producto_id'] ?? 0);
        $cantidad = (int)($_POST['cantidad'] ?? 0);
        $metodo = $_POST['metodo'] ?? '';
        $actual = date('H:i');
        $stmt = $pdo->prepare('select id,nombre,precio from productos where id=? and activo=1');
        $stmt->execute([$productoId]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($actual < '08:00' || $actual >= '20:00') {
            $msg = 'El restaurante atiende de 8:00 a 20:00.';
            $msgType = 'danger';
        } elseif (!$producto) {
            $msg = 'Selecciona un producto activo del restaurante.';
            $msgType = 'danger';
        } elseif ($cantidad < 1 || $cantidad > 99) {
            $msg = 'La cantidad debe estar entre 1 y 99.';
            $msgType = 'danger';
        } elseif (!in_array($metodo, ['efectivo','transferencia','nequi'], true)) {
            $msg = 'Selecciona efectivo, transferencia o Nequi.';
            $msgType = 'danger';
        } else {
            $subtotal = round((float)$producto['precio'] * $cantidad, 2);
            $pdo->prepare('insert into ventas_mostrador (producto_id,producto_nombre,cantidad,precio_unit,subtotal,metodo,usuario_id) values (?,?,?,?,?,?,?)')
                ->execute([$productoId,$producto['nombre'],$cantidad,$producto['precio'],$subtotal,$metodo,getCurrentUserId()]);
            $ventaId = (int)$pdo->lastInsertId();
            registrarAuditoria($pdo, 'ventas_mostrador', $ventaId, 'venta', "{$producto['nombre']} x$cantidad = $" . number_format($subtotal,0,',','.') . " ($metodo)");
            $msg = 'Venta pagada registrada: $' . number_format($subtotal,0,',','.') . ' COP.';
            $msgType = 'success';
        }
    }
}

// ── Procesar registro de consumo ANTES de imprimir HTML ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'agregar') {
    if (!validateCsrf()) {
        $msg = 'Token de seguridad inválido. Intente de nuevo.';
        $msgType = 'danger';
    } else {
        $estanciaId = (int)($_POST['estancia_id'] ?? 0);
        $productoId = (int)($_POST['producto_id'] ?? 0);
        $descripcion = trim($_POST['descripcion'] ?? '');
        // 4.2.4: un entero de pesos, o null. Antes (float): «12abc» se cobraba como 12 y «1.500» como 1,5. Con un producto de la carta el
        // precio sale de la base y esto se ignora.
        $precio = validarMonto($_POST['precio'] ?? '', 1);
        $cantidad = (int)($_POST['cantidad'] ?? 0);

        if ($productoId > 0) {
            $stmtP = $pdo->prepare('select nombre, precio, categoria from productos where id = ? and activo = 1');
            $stmtP->execute([$productoId]);
            $producto = $stmtP->fetch(PDO::FETCH_ASSOC);
            if (!$producto) {
                $msg = 'El producto seleccionado ya no está disponible.';
                $msgType = 'danger';
            } else {
                $descripcion = $producto['nombre'];
                $precio = (float)$producto['precio'];
                $categoriaProducto = $producto['categoria'];
            }
        }

        if ($msg === '') {
            if ($estanciaId < 1) {
                $msg = 'Seleccione una estancia válida.';
                $msgType = 'danger';
            } elseif ($descripcion === '') {
                $msg = 'El nombre del producto o servicio es obligatorio.';
                $msgType = 'danger';
            } elseif ($precio === null || $precio <= 0) {
                $msg = 'El precio debe ser un número entero de pesos mayor a cero, sin letras (por ejemplo 15000).';
                $msgType = 'danger';
            } elseif ($cantidad < 1 || $cantidad > 99) {
                $msg = 'La cantidad debe estar entre 1 y 99.';
                $msgType = 'danger';
            } else {
                // ── Transacción con la estancia bloqueada ──────────────────
                // Antes esto iba en autocommit: se comprobaba que la estancia
                // estuviera activa y, varias sentencias después, se insertaba.
                // Si el check-out entraba en medio, el consumo quedaba cargado
                // a una estancia YA CERRADA. El `+ ?` es atómico, así que no se
                // perdía la fila — lo que se perdía era el COBRO: el check-out
                // congela el folio (`total_pagar = subtotal_noches +
                // total_consumos`, ver modules/checkout/index.php), así que el
                // consumo subía `total_consumos` por encima de lo liquidado,
                // no aparecía como deuda y ya no se podía anular desde la ficha
                // («Reábrela primero»). Mercancía que sale y no se factura.
                //
                // Mismo patrón que 3.0.5: bloquear, revalidar dentro del
                // bloqueo, escribir con el estado esperado y mirar rowCount().
                try {
                    $pdo->beginTransaction();

                    // El bloqueo va estrecho, solo sobre la fila de la estancia.
                    $pdo->prepare('select id from estancias where id = ? for update')->execute([$estanciaId]);

                    // La lectura que vale es la de DESPUÉS del bloqueo.
                    $stmtE = $pdo->prepare('select estado from estancias where id = ?');
                    $stmtE->execute([$estanciaId]);
                    $estadoEstancia = $stmtE->fetchColumn();

                    if ($estadoEstancia !== 'activa') {
                        $pdo->rollBack();
                        $msg = 'La estancia seleccionada no está activa.';
                        $msgType = 'danger';
                    } elseif (($categoriaProducto ?? '') === 'restaurante' && (date('H:i') < '08:00' || date('H:i') >= '20:00')) {
                        $pdo->rollBack();
                        $msg = 'El restaurante atiende de 8:00 a 20:00. No se pueden registrar consumos fuera del horario.';
                        $msgType = 'danger';
                    } else {
                        $subtotal = round($precio * $cantidad, 2);
                        $productoIdDb = $productoId > 0 ? $productoId : null;
                        $descripcionManual = $productoId > 0 ? null : $descripcion;

                        // El folio se actualiza PRIMERO, exigiendo que la
                        // estancia siga activa. Si otro cerró la cuenta, esto
                        // afecta 0 filas y no se inserta ningún consumo: es la
                        // segunda defensa, por si algún día se escribe aquí sin
                        // pedir el bloqueo de arriba.
                        $updE = $pdo->prepare("update estancias
                                               set total_consumos = total_consumos + ?
                                               where id = ? and estado = 'activa'");
                        $updE->execute([$subtotal, $estanciaId]);

                        if ($updE->rowCount() !== 1) {
                            $pdo->rollBack();
                            $msg = 'Esa cuenta acaba de cerrarse; el consumo no se registró.';
                            $msgType = 'danger';
                        } else {
                            $pdo->prepare('insert into consumos (estancia_id, producto_id, cantidad, precio_unit, subtotal, descripcion_manual, usuario_id) values (?, ?, ?, ?, ?, ?, ?)')
                                ->execute([$estanciaId, $productoIdDb, $cantidad, $precio, $subtotal, $descripcionManual, getCurrentUserId()]);
                            $consumoId = $pdo->lastInsertId();
                            $pdo->commit();

                            registrarAuditoria($pdo, 'consumos', $consumoId, 'agregar', "\"$descripcion\" x$cantidad = \$$subtotal a estancia #$estanciaId");
                            $msg = "Consumo registrado con éxito: $descripcion — $" . number_format($subtotal, 0, ',', '.') . ' COP';
                            $msgType = 'success';
                        }
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Consumos: no se pudo registrar el consumo: ' . $e->getMessage());
                    $msg = 'No se pudo registrar el consumo. Vuelve a intentarlo.';
                    $msgType = 'danger';
                }
            }
        }
    }
}

$estancias = $pdo->query("
    select e.id, h.numero, hu.nombres, hu.apellidos, e.total_consumos
    from estancias e, habitaciones h, huespedes hu
    where e.habitacion_id = h.id
      and e.huesped_id = hu.id
      and e.estado = 'activa'
    order by cast(h.numero as unsigned), h.numero
")->fetchAll();

$categoriasCarta = ['snack' => 'Tienda · Mecato', 'bebida' => 'Tienda · Bebidas', 'servicio' => 'Servicios', 'restaurante' => 'Restaurante', 'otro' => 'Otros'];
$productosCarta = $pdo->query('select id, nombre, categoria, precio from productos where activo = 1 order by categoria, nombre')->fetchAll(PDO::FETCH_ASSOC);
$productosMostrador = $productosCarta;
$restauranteAbierto = date('H:i') >= '08:00' && date('H:i') < '20:00';
// Cuándo vuelve a abrir, para poder decirlo en el aviso. Antes de las 8:00 abre
// hoy mismo; a partir de las 20:00 ya no, así que toca mañana.
$mostradorAbreEn = date('H:i') < '08:00' ? 'hoy a las 8:00' : 'mañana a las 8:00';
// ── Ventas directas de un día ────────────────────────────────────────────────
// Antes esto solo miraba HOY y cortaba a 30 filas. Para cuadrar la caja hace
// falta poder consultar cualquier día, y ver el total, cuántas ventas fueron y
// cuántas unidades salieron, no solo el listado.
//
// El día llega por la URL, así que se valida: `createFromFormat` sola acepta
// cosas como "2026-13-45" y las corrige sin avisar, por eso se compara el
// resultado con lo que entró. Si no cuadra, se usa hoy.
$diaVentas = (string) ($_GET['dia'] ?? '');
$fechaDia  = DateTime::createFromFormat('Y-m-d', $diaVentas);
if (!$fechaDia || $fechaDia->format('Y-m-d') !== $diaVentas) {
    $diaVentas = date('Y-m-d');
}

$stmtV = $pdo->prepare("select producto_nombre, cantidad, subtotal, metodo, fecha
                        from ventas_mostrador
                        where date(fecha) = ?
                        order by fecha desc");
$stmtV->execute([$diaVentas]);
$ventasMostrador = $stmtV->fetchAll(PDO::FETCH_ASSOC);

// Los totales se suman de las MISMAS filas que se pintan, no en otra consulta:
// así el total y el detalle no pueden discrepar.
$ventasTotal    = 0.0;
$ventasUnidades = 0;
foreach ($ventasMostrador as $venta) {
    $ventasTotal    += (float) $venta['subtotal'];
    $ventasUnidades += (int) $venta['cantidad'];
}
$ventasCuantas = count($ventasMostrador);
$estanciaSeleccionada = (int)($_GET['estancia'] ?? 0);
$consumosEstancia = [];

if ($estanciaSeleccionada) {
    $stmtC = $pdo->prepare("
        select c.id, c.cantidad, c.precio_unit, c.subtotal, c.fecha,
               coalesce(c.descripcion_manual, p.nombre, 'Sin descripción') as nombre
        from consumos c
        left join productos p on c.producto_id = p.id
        where c.estancia_id = ? and c.anulado_en is null
        order by c.fecha desc
    ");
    $stmtC->execute([$estanciaSeleccionada]);
    $consumosEstancia = $stmtC->fetchAll();
}

require_once __DIR__ . '/../../includes/header.php';
?>
<style>
/* ============================================================
   Consumos — Rediseño limpio (Casa Bosque)
   ============================================================ */
.cs-wrap { padding-bottom: 90px; }

.cs-msg { display: flex; align-items: center; gap: 11px; padding: 13px 15px; border-radius: 14px; margin-bottom: 16px; font-size: .9rem; font-weight: 500; }
.cs-msg.success { background: #e6f7f2; color: #047857; border: 1px solid rgba(31,122,77,.25); }
.cs-msg.danger  { background: #fdeded; color: #b91c1c; border: 1px solid rgba(239,68,68,.25); }
/* Ámbar, no rojo: el mostrador cerrado no es un error de nadie, es un estado
   del negocio. En rojo parecería que algo se ha hecho mal. */
.cs-msg.aviso   { background: var(--sun-orange-light, #FAF0DC); color: var(--sun-orange, #A8701A); border: 1px solid rgba(168,112,26,.28); }

.cs-card { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); margin-bottom: 18px; overflow: hidden; }
.cs-card-h { padding: 16px 16px 0; }
.cs-card-h h3 { font-size: 1rem; font-weight: 700; color: var(--cb-forest,#3E2412); display: flex; align-items: center; gap: 8px; margin: 0; }
.cs-card-b { padding: 16px; }

/* Formulario */
.cs-field { margin-bottom: 14px; }
.cs-field label { display: flex; align-items: center; gap: 7px; margin-bottom: 7px; font-size: .82rem; font-weight: 600; color: var(--cb-forest,#3E2412); }
.cs-field label i { color: var(--cb-muted,#9A8778); }
.cs-input, .cs-select { width: 100%; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; border-radius: 12px; padding: 13px 14px; font-family: inherit; font-size: .88rem; color: var(--cb-text,#2A1709); }
.cs-select { appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236b7b69' d='M6 8 0 0h12z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; padding-right: 38px; }
.cs-input:focus, .cs-select:focus { outline: none; border-color: var(--cb-primary,#6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }
.cs-help { font-size: .72rem; color: var(--cb-muted,#9A8778); margin-top: 6px; }
.cs-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
/* 4.2.0: el producto (y el botón) a todo el ancho. En dos columnas el selector medía ~150 px y se leía «Seleccionar prod…». */
.cs-row2 > .cs-span2 { grid-column: 1 / -1; }
.cs-row2 > .cs-field { min-width: 0; }

.cs-preview { display: none; margin-top: 4px; margin-bottom: 14px; background: var(--cb-primary-light,#F5EBDC); border: 1px solid rgba(107,68,32,.2); border-radius: 13px; padding: 13px 15px; align-items: center; justify-content: space-between; }
.cs-preview .pl { font-size: .82rem; font-weight: 600; color: var(--cb-forest,#3E2412); }
.cs-preview .pv { font-size: 1.25rem; font-weight: 800; color: var(--cb-primary,#6B4420); }

.cs-submit { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: none; cursor: pointer; font-family: inherit; font-size: .92rem; font-weight: 700; color: #fff; background: linear-gradient(150deg, var(--cb-primary,#6B4420), var(--cb-secondary,#9C6728)); padding: 14px; border-radius: 14px; box-shadow: 0 8px 18px rgba(107,68,32,.28); }
.cs-submit:active { transform: scale(.98); }

/* Desglose */
.cs-consumo { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--gray-100,#F1EADF); }
.cs-consumo:last-of-type { border-bottom: none; }
.cs-consumo .cs-qty { flex: none; width: 38px; height: 38px; border-radius: 11px; background: var(--cb-primary-light,#F5EBDC); color: var(--cb-primary,#6B4420); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: .9rem; }
.cs-consumo .cs-cinfo { flex: 1; min-width: 0; }
.cs-consumo .cs-cname { font-size: .88rem; font-weight: 700; color: var(--cb-forest,#3E2412); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cs-consumo .cs-cmeta { font-size: .72rem; color: var(--cb-muted,#9A8778); margin-top: 1px; }
.cs-consumo .cs-camt { flex: none; font-size: .9rem; font-weight: 700; color: var(--cb-forest,#3E2412); }
.cs-total { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; padding-top: 14px; border-top: 2px solid var(--gray-100,#F1EADF); }
.cs-total .tl { font-size: .82rem; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; color: var(--cb-muted,#9A8778); }
.cs-total .tv { font-size: 1.3rem; font-weight: 800; color: var(--cb-primary,#6B4420); }

/* Resumen de ventas directas: selector de día + tres cifras */
.cs-dia { display: grid; grid-template-columns: 1fr; gap: 10px; margin-bottom: 14px; }
.cs-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 6px; }
.cs-stat { background: var(--gray-50, #FAF7F2); border-radius: 14px; padding: 13px 14px; }
/* El total va destacado porque es la cifra que se busca al cuadrar la caja;
   las otras dos la explican. */
.cs-stat.destacado { background: var(--cb-primary-light, #F5EBDC); }
.cs-stat .sl { font-size: .76rem; color: var(--cb-muted,#9A8778); }
.cs-stat .sv { font-size: 1.45rem; font-weight: 800; color: var(--cb-forest,#3E2412); margin-top: 2px; letter-spacing: -.5px; }
.cs-stat.destacado .sv { color: var(--cb-primary,#6B4420); }
.cs-detalle-tit { font-size: .76rem; color: var(--cb-muted,#9A8778); margin: 14px 0 2px; }

.cs-empty { text-align: center; padding: 26px 12px; color: var(--cb-muted,#9A8778); }
.cs-empty i { font-size: 2rem; color: var(--cb-primary,#6B4420); opacity: .5; margin-bottom: 10px; display: block; }
.cs-empty p { font-size: .88rem; margin: 0; }
</style>

<div class="cs-wrap">

<?php if ($msg): ?>
    <div class="cs-msg <?= $msgType ?>">
        <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
        <span><?= htmlspecialchars($msg) ?></span>
    </div>
<?php endif; ?>

<!-- Venta directa de restaurante a clientes sin habitación; los consumos de huéspedes siguen abajo. -->
<div class="cs-card">
    <div class="cs-card-h"><h3><i class="fas fa-cash-register" style="color:var(--cb-primary,#6B4420);"></i> Venta de restaurante en mostrador</h3></div>
    <div class="cs-card-b">
        <p class="cs-help" style="margin-top:0;">Atención de 8:00 a 20:00 · productos activos de Carta y Restaurante, venta pagada al momento y sin asociarla a una habitación. Estado: <b style="color:<?= $restauranteAbierto ? '#6B4420' : '#a34132' ?>;">Mostrador <?= $restauranteAbierto ? 'abierto' : 'cerrado' ?></b>.</p>
        <?php if (!$restauranteAbierto): ?>
            <?php /* El botón de enviar ya venía deshabilitado fuera de horario, pero
                     nada lo explicaba: el mensaje del POST no podía salir nunca,
                     porque el POST no se puede enviar. Esta tarjeta es lo que
                     faltaba. Tarjeta del sistema, no un diálogo del navegador. */ ?>
            <div class="cs-msg aviso">
                <i class="fas fa-clock"></i>
                <span><b>El mostrador está cerrado ahora.</b> La venta directa se registra de 8:00 a 20:00;
                      vuelve a abrir <?= $mostradorAbreEn ?>. Los consumos de huéspedes alojados siguen disponibles más abajo.</span>
            </div>
        <?php endif; ?>
        <?php if (empty($productosMostrador)): ?>
            <div class="cs-empty"><i class="fas fa-utensils"></i><p>Agrega productos en Carta y Restaurante para registrar ventas directas.</p></div>
        <?php else: ?>
            <form method="post" class="cs-row2">
                <?= csrfField() ?><input type="hidden" name="action" value="venta_mostrador">
                <div class="cs-field cs-span2">
                    <label for="venta-producto">Producto</label>
                    <select class="cs-select" id="venta-producto" name="producto_id" required>
                        <option value="">Seleccionar producto...</option>
                        <?php /* Agrupado por categoría (la consulta ya viene ordenada por ella): así cada opción es «nombre · $precio» y no se corta. */ ?>
                        <?php $catActual = null; foreach ($productosMostrador as $producto):
                            if ($producto['categoria'] !== $catActual):
                                if ($catActual !== null) echo '</optgroup>';
                                $catActual = $producto['categoria'];
                                echo '<optgroup label="' . htmlspecialchars($categoriasCarta[$catActual] ?? 'Otros') . '">';
                            endif; ?>
                            <option value="<?= (int)$producto['id'] ?>"><?= htmlspecialchars($producto['nombre']) ?> · $<?= number_format((float)$producto['precio'],0,',','.') ?></option>
                        <?php endforeach; if ($catActual !== null) echo '</optgroup>'; ?>
                    </select>
                </div>
                <div class="cs-field">
                    <label for="venta-cantidad">Cantidad</label>
                    <input class="cs-input" id="venta-cantidad" type="number" name="cantidad" value="1" min="1" max="99" required>
                </div>
                <div class="cs-field">
                    <label for="venta-metodo">Medio de pago</label>
                    <select class="cs-select" id="venta-metodo" name="metodo" required>
                        <option value="efectivo">Efectivo</option><option value="transferencia">Transferencia</option><option value="nequi">Nequi</option>
                    </select>
                </div>
                <div class="cs-field cs-span2" style="align-self:end;"><button class="cs-submit" type="submit" <?= !$restauranteAbierto ? 'disabled title="Disponible de 8:00 a 20:00"' : '' ?>><i class="fas fa-check"></i> Registrar venta pagada</button></div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="cs-card">
    <div class="cs-card-h"><h3><i class="fas fa-receipt" style="color:var(--cb-primary,#6B4420);"></i> Resumen de ventas directas</h3></div>
    <div class="cs-card-b">
        <?php /* Va por GET a propósito: consultar un día no cambia nada, y así el
                 enlace se puede guardar o compartir. */ ?>
        <form method="get" class="cs-dia">
            <div class="cs-field" style="margin-bottom:0;">
                <label for="dia">Día de operación</label>
                <input class="cs-input" type="date" id="dia" name="dia"
                       value="<?= htmlspecialchars($diaVentas) ?>" max="<?= date('Y-m-d') ?>">
            </div>
            <button class="cs-submit" type="submit"><i class="fas fa-filter"></i> Consultar día</button>
        </form>

        <div class="cs-stats">
            <div class="cs-stat destacado">
                <div class="sl">Total vendido</div>
                <div class="sv">$<?= number_format($ventasTotal, 0, ',', '.') ?></div>
            </div>
            <div class="cs-stat">
                <div class="sl">Ventas</div>
                <div class="sv"><?= (int) $ventasCuantas ?></div>
            </div>
            <div class="cs-stat">
                <div class="sl">Unidades</div>
                <div class="sv"><?= (int) $ventasUnidades ?></div>
            </div>
        </div>

        <div class="cs-detalle-tit">Detalle del <?= date('d/m/Y', strtotime($diaVentas)) ?></div>

        <?php if (empty($ventasMostrador)): ?><div class="cs-empty"><p>No hay ventas directas registradas para este día.</p></div>
        <?php else: foreach ($ventasMostrador as $venta): ?>
            <div class="cs-consumo"><div class="cs-qty"><?= (int)$venta['cantidad'] ?>×</div><div class="cs-cinfo"><div class="cs-cname"><?= htmlspecialchars($venta['producto_nombre']) ?></div><div class="cs-cmeta"><?= date('H:i',strtotime($venta['fecha'])) ?> · <?= htmlspecialchars(ucfirst($venta['metodo'])) ?></div></div><div class="cs-camt">$<?= number_format((float)$venta['subtotal'],0,',','.') ?></div></div>
        <?php endforeach; endif; ?>
    </div>
</div>

<!-- Registrar consumo -->
<div class="cs-card">
    <div class="cs-card-h"><h3><i class="fas fa-plus-circle" style="color:var(--cb-primary,#6B4420);"></i> Registrar consumo</h3></div>
    <div class="cs-card-b">
        <?php if (empty($estancias)): ?>
            <div class="cs-empty"><i class="fas fa-bed"></i><p>No hay habitaciones ocupadas en este momento.</p></div>
        <?php else: ?>
            <form method="POST" id="form-consumo">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="agregar">

                <div class="cs-field">
                    <label for="estancia_id"><i class="fas fa-door-open"></i> Habitación</label>
                    <select name="estancia_id" id="estancia_id" class="cs-select" required>
                        <option value="">Seleccionar habitación...</option>
                        <?php foreach ($estancias as $e): ?>
                            <option value="<?= $e['id'] ?>" <?= $estanciaSeleccionada == $e['id'] ? 'selected' : '' ?>>
                                Hab. <?= htmlspecialchars($e['numero']) ?> — <?= htmlspecialchars($e['nombres'] . ' ' . $e['apellidos']) ?> (extras: $<?= number_format($e['total_consumos'], 0, ',', '.') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="cs-field">
                    <label for="producto_id"><i class="fas fa-utensils"></i> Carta y Restaurante</label>
                    <select name="producto_id" id="producto_id" class="cs-select" onchange="actualizarProducto()">
                        <option value="0">Otro concepto (registro manual)</option>
                        <?php foreach ($productosCarta as $producto): ?>
                            <option value="<?= (int)$producto['id'] ?>" data-nombre="<?= htmlspecialchars($producto['nombre'], ENT_QUOTES, 'UTF-8') ?>" data-precio="<?= number_format((float)$producto['precio'], 2, '.', '') ?>">
                                <?= htmlspecialchars($producto['nombre']) ?> · <?= htmlspecialchars($categoriasCarta[$producto['categoria']] ?? 'Otros') ?> · $<?= number_format((float)$producto['precio'], 0, ',', '.') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="cs-help">Elige un producto de la carta para cargar su precio registrado a la estancia.</div>
                </div>
                <div class="cs-field">
                    <label for="descripcion"><i class="fas fa-tag"></i> Producto o servicio</label>
                    <input type="text" name="descripcion" id="descripcion" class="cs-input" placeholder="Ej: Agua, Lavandería, Minibar..." maxlength="200" required autocomplete="off">
                    <div class="cs-help">Puedes registrar un concepto manual si no está en la carta.</div>
                </div>

                <div class="cs-row2">
                    <div class="cs-field">
                        <label for="precio"><i class="fas fa-coins"></i> Precio (COP)</label>
                        <input type="number" name="precio" id="precio" class="cs-input" placeholder="0" min="1" step="1" data-solo="digitos" required oninput="calcularSubtotal()">
                    </div>
                    <div class="cs-field">
                        <label for="cantidad"><i class="fas fa-hashtag"></i> Cantidad</label>
                        <input type="number" name="cantidad" id="cantidad" class="cs-input" value="1" min="1" max="99" required oninput="calcularSubtotal()">
                    </div>
                </div>

                <div id="subtotal-preview" class="cs-preview">
                    <span class="pl">Subtotal a cargar</span>
                    <span id="subtotal-valor" class="pv">$0</span>
                </div>

                <button type="submit" class="cs-submit"><i class="fas fa-receipt"></i> Registrar y cargar consumo</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Desglose por folio -->
<div class="cs-card">
    <div class="cs-card-h"><h3><i class="fas fa-list-ul" style="color:var(--cb-primary,#6B4420);"></i> Consumos por habitación</h3></div>
    <div class="cs-card-b">
        <form method="get" id="form-filtro-estancia" style="margin-bottom:16px;">
            <?php /* Conserva el día que se está consultando arriba: este filtro
                     también va por GET, y sin esto elegir una habitación
                     devolvería el resumen a hoy. */ ?>
            <input type="hidden" name="dia" value="<?= htmlspecialchars($diaVentas) ?>">
            <select name="estancia" class="cs-select" onchange="this.form.submit()">
                <option value="">Ver historial de una habitación...</option>
                <?php foreach ($estancias as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= $estanciaSeleccionada == $e['id'] ? 'selected' : '' ?>>
                        Hab. <?= htmlspecialchars($e['numero']) ?> — <?= htmlspecialchars($e['nombres'] . ' ' . $e['apellidos']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($estanciaSeleccionada && !empty($consumosEstancia)): ?>
            <?php $totalAcumulado = 0; foreach ($consumosEstancia as $c): $totalAcumulado += $c['subtotal']; ?>
                <div class="cs-consumo">
                    <div class="cs-qty"><?= $c['cantidad'] ?>×</div>
                    <div class="cs-cinfo">
                        <div class="cs-cname"><?= htmlspecialchars($c['nombre']) ?></div>
                        <div class="cs-cmeta">$<?= number_format($c['precio_unit'], 0, ',', '.') ?> c/u · <?= date('d/m H:i', strtotime($c['fecha'])) ?></div>
                    </div>
                    <div class="cs-camt">$<?= number_format($c['subtotal'], 0, ',', '.') ?></div>
                </div>
            <?php endforeach; ?>
            <div class="cs-total">
                <span class="tl">Total acumulado</span>
                <span class="tv">$<?= number_format($totalAcumulado, 0, ',', '.') ?></span>
            </div>
        <?php elseif ($estanciaSeleccionada): ?>
            <div class="cs-empty"><i class="fas fa-receipt"></i><p>Esta habitación no tiene consumos registrados.</p></div>
        <?php else: ?>
            <div class="cs-empty"><i class="fas fa-hand-pointer"></i><p>Selecciona una habitación para ver sus consumos.</p></div>
        <?php endif; ?>
    </div>
</div>

</div><!-- /.cs-wrap -->

<script>
function actualizarProducto() {
    const selector = document.getElementById('producto_id');
    const opcion = selector.options[selector.selectedIndex];
    const descripcion = document.getElementById('descripcion');
    const precio = document.getElementById('precio');
    const manual = selector.value === '0';
    descripcion.readOnly = !manual;
    precio.readOnly = !manual;
    descripcion.value = manual ? '' : (opcion.dataset.nombre || '');
    precio.value = manual ? '' : (opcion.dataset.precio || '');
    calcularSubtotal();
}

function calcularSubtotal() {
    const precio = parseFloat(document.getElementById('precio').value) || 0;
    const cantidad = parseInt(document.getElementById('cantidad').value) || 0;
    const subtotal = precio * cantidad;
    const preview = document.getElementById('subtotal-preview');
    const valor = document.getElementById('subtotal-valor');

    if (subtotal > 0) {
        valor.textContent = '$' + subtotal.toLocaleString('es-CO', { minimumFractionDigits: 0 });
        preview.style.display = 'flex';
    } else {
        preview.style.display = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
