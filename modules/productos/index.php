<?php
// Carta y Restaurante — catálogo administrable y visible para recepción.
$pageTitle = 'Carta y Restaurante';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/validacion.php';   // validarMonto, validarEntero (montos que se teclean)
require_once __DIR__ . '/../../includes/categorias.php';   // las categorías de la Carta (5.0.0): una sola fuente, la comparte Consumos
requireLogin();

$pdo = getDB();
$mensaje = '';
$mensajeTipo = 'success';   // el tipo del mensaje (4.2.0): la notificación lo necesita; cada error de abajo lo cambia a «danger»

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isAdmin()) {
        $mensaje = 'Solo un administrador puede modificar la carta.'; $mensajeTipo = 'danger';
    } elseif (!validateCsrf()) {
        $mensaje = 'La sesión de seguridad venció. Recarga la página e intenta de nuevo.'; $mensajeTipo = 'danger';
    } else {
        $accion = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        if ($accion === 'guardar') {
            $nombre = trim($_POST['nombre'] ?? '');
            // 4.2.4: un entero de pesos (0 si es gratis). Antes (float): «abc» guardaba el producto a $0 y «1.500» a 1,5.
            $precio = validarMonto($_POST['precio'] ?? '', 0);
            $categoria = is_string($_POST['categoria'] ?? '') ? (string) ($_POST['categoria'] ?? '') : '';
            $descripcion = trim($_POST['descripcion'] ?? '');
            // 5.0.0: la categoría tiene que existir y estar activa; la excepción es la que el producto YA tiene (editar un precio no puede fallar porque
            // alguien desactivó su categoría).
            $categoriaActual = null;
            if ($id > 0) {
                $stCat = $pdo->prepare('select categoria from productos where id = ?');
                $stCat->execute([$id]);
                $categoriaActual = $stCat->fetchColumn() ?: null;
            }
            if ($nombre === '' || !categoriaPermitida($pdo, $categoria, $categoriaActual)) {
                $mensaje = 'Completa el nombre y una categoría.'; $mensajeTipo = 'danger';
            } elseif ($precio === null) {
                $mensaje = 'El precio debe ser un número entero de pesos (0 si es gratis), sin letras ni decimales, por ejemplo 15000.'; $mensajeTipo = 'danger';
            } elseif ($id > 0) {
                $antesProd = auditoriaFila($pdo, 'productos', $id);
                $stmt = $pdo->prepare('update productos set nombre = ?, descripcion = ?, precio = ?, categoria = ? where id = ?');
                $stmt->execute([$nombre, $descripcion ?: null, $precio, $categoria, $id]);
                registrarAuditoria($pdo, 'productos', $id, 'editar', "Producto actualizado: $nombre",
                    $antesProd, auditoriaFila($pdo, 'productos', $id));
                $mensaje = 'Producto actualizado.';
            } else {
                $stmt = $pdo->prepare('insert into productos (nombre, descripcion, precio, categoria) values (?, ?, ?, ?)');
                $stmt->execute([$nombre, $descripcion ?: null, $precio, $categoria]);
                $nuevoProd = (int)$pdo->lastInsertId();
                registrarAuditoria($pdo, 'productos', $nuevoProd, 'crear', "Producto agregado: $nombre",
                    null, auditoriaFila($pdo, 'productos', $nuevoProd));
                $mensaje = 'Producto agregado a la carta.';
            }
        } elseif ($accion === 'categoria_crear') {
            // 5.0.0: una categoría nueva, escrita por el administrador. El nombre se valida y la clave se saca de él (includes/categorias.php).
            $r = categoriaCrear($pdo, $_POST['nombre_categoria'] ?? '');
            if ($r['ok']) {
                registrarAuditoria($pdo, 'categorias_producto', $r['id'], 'crear', 'Categoría de la carta creada (clave ' . $r['clave'] . ')',
                    null, auditoriaFila($pdo, 'categorias_producto', $r['id']));
            }
            $mensaje = $r['mensaje']; $mensajeTipo = $r['tipo'];
        } elseif ($accion === 'categoria_renombrar') {
            $clave = is_string($_POST['clave'] ?? '') ? (string) ($_POST['clave'] ?? '') : '';
            $cat = categoriaPorClave($pdo, $clave);
            $antesCat = $cat ? auditoriaFila($pdo, 'categorias_producto', $cat['id']) : null;
            $r = categoriaRenombrar($pdo, $clave, $_POST['nombre_categoria'] ?? '');
            if ($r['ok']) {
                registrarAuditoria($pdo, 'categorias_producto', $r['id'], 'editar', 'Categoría de la carta renombrada (clave ' . $clave . ')',
                    $antesCat, auditoriaFila($pdo, 'categorias_producto', $r['id']));
            }
            $mensaje = $r['mensaje']; $mensajeTipo = $r['tipo'];
        } elseif ($accion === 'categoria_estado') {
            $clave = is_string($_POST['clave'] ?? '') ? (string) ($_POST['clave'] ?? '') : '';
            $cat = categoriaPorClave($pdo, $clave);
            $antesCat = $cat ? auditoriaFila($pdo, 'categorias_producto', $cat['id']) : null;
            $r = categoriaCambiarActiva($pdo, $clave, ($_POST['activa'] ?? '') === '1');
            if ($r['ok']) {
                registrarAuditoria($pdo, 'categorias_producto', $r['id'], 'cambiar_estado', 'Disponibilidad de la categoría de la carta modificada (clave ' . $clave . ')',
                    $antesCat, auditoriaFila($pdo, 'categorias_producto', $r['id']));
            }
            $mensaje = $r['mensaje']; $mensajeTipo = $r['tipo'];
        } elseif ($accion === 'estado' && $id > 0) {
            $antesProd = auditoriaFila($pdo, 'productos', $id);
            $pdo->prepare('update productos set activo = if(activo = 1, 0, 1) where id = ?')->execute([$id]);
            registrarAuditoria($pdo, 'productos', $id, 'cambiar_estado', 'Disponibilidad de producto modificada',
                $antesProd, auditoriaFila($pdo, 'productos', $id));
            $mensaje = 'Estado del producto actualizado.';
        }
    }
}

$editar = null;
if (isAdmin() && isset($_GET['editar'])) {
    $stmt = $pdo->prepare('select * from productos where id = ?');
    $stmt->execute([(int)$_GET['editar']]);
    $editar = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
// 5.0.0: las categorías vienen de includes/categorias.php. El menú del formulario ofrece solo las ACTIVAS (más la que el producto que se edita ya tiene);
// los rótulos usan todas, también las desactivadas.
$tipos = categoriasMapa($pdo);
$categoriasLista = categoriasLista($pdo, false);
$categoriasTabla = categoriasTabla($pdo);
$opcionesCategoria = categoriasLista($pdo, true);
if ($editar && !in_array($editar['categoria'], array_column($opcionesCategoria, 'clave'), true)) {
    $opcionesCategoria[] = ['clave' => $editar['categoria'], 'nombre' => categoriaEtiqueta($tipos, $editar['categoria']) . ' (desactivada)'];
}
$sqlProductos = 'select id, nombre, descripcion, precio, categoria, activo from productos';
if (!isAdmin()) { $sqlProductos .= ' where activo = 1'; }
$sqlProductos .= ' order by activo desc, categoria, nombre';
$productos = $pdo->query($sqlProductos)->fetchAll(PDO::FETCH_ASSOC);
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
.pr-wrap{max-width:1180px;margin:0 auto;padding:0 0 90px}.pr-hero{display:flex;align-items:center;justify-content:space-between;gap:22px;padding:24px 28px;margin:0 0 20px;border-radius:20px;background:linear-gradient(120deg,#3E2412,#9C6728);color:#fff;box-shadow:0 12px 28px rgba(42,23,9,.13)}.pr-hero h2{font-size:1.35rem;margin:0 0 6px;color:#fff}.pr-hero p{margin:0;color:rgba(255,255,255,.82);font-size:.9rem;line-height:1.5}.pr-hero a{flex:none;display:inline-flex;align-items:center;gap:8px;padding:11px 15px;border-radius:11px;background:#fff;color:#6B4420;text-decoration:none;font-weight:700}.pr-card{background:#fff;border:1px solid var(--cb-border,#EDE3D2);border-radius:16px;padding:18px;margin:0 0 16px;box-shadow:0 5px 16px rgba(42,23,9,.04)}.pr-card h2{font-size:1rem;margin:0 0 12px;color:var(--cb-forest,#3E2412)}.pr-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.pr-grid .wide{grid-column:1/-1}.pr-field label{display:block;font-size:.78rem;font-weight:700;margin:0 0 5px}.pr-field input,.pr-field select,.pr-field textarea{width:100%;padding:11px;border:1px solid var(--cb-border,#EDE3D2);border-radius:10px;font:inherit}.pr-save{border:0;border-radius:10px;padding:11px 16px;background:var(--cb-primary,#6B4420);color:#fff;font-weight:700}.pr-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-top:1px solid #edf1ea}.pr-row:first-of-type{border-top:0}.pr-info{flex:1;min-width:0}.pr-name{font-weight:700;color:var(--cb-forest,#3E2412)}.pr-meta{font-size:.78rem;color:var(--cb-muted,#9A8778)}.pr-price{white-space:nowrap;font-weight:800;color:var(--cb-forest,#3E2412);font-size:.95rem}.pr-actions{display:flex;gap:8px;align-items:center}.pr-actions a,.pr-actions button{border:0;background:none;color:var(--cb-primary,#6B4420);font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.pr-off{opacity:.52}.pr-aviso{margin:0 0 12px;padding:10px 13px;border:1px solid #E6C37A;border-radius:10px;background:#FFF7E6;color:#6B4420;font-size:.84rem;line-height:1.45}.pr-catnew{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 6px}.pr-catnew input{flex:1 1 220px;min-width:0;padding:11px;border:1px solid var(--cb-border,#EDE3D2);border-radius:10px;font:inherit}.pr-cat{display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid #edf1ea;flex-wrap:wrap}.pr-cat form{margin:0}.pr-cat .pr-catren{display:flex;gap:8px;flex:1 1 280px;min-width:0}.pr-cat .pr-catren input{flex:1;min-width:0;padding:9px 11px;border:1px solid var(--cb-border,#EDE3D2);border-radius:10px;font:inherit}.pr-cat .pr-catn{flex:none;font-size:.78rem;color:var(--cb-muted,#9A8778);min-width:84px}.pr-mini{border:1px solid rgba(107,68,32,.25);background:#fff;color:var(--cb-primary,#6B4420);font:inherit;font-weight:700;font-size:.82rem;border-radius:10px;padding:9px 13px;min-height:40px;cursor:pointer}.pr-mini.pr-ok{background:var(--cb-primary,#6B4420);color:#fff;border-color:transparent}.pr-catoff .pr-catren input{opacity:.6}@media(max-width:700px){.pr-hero{align-items:flex-start;flex-direction:column;padding:20px}.pr-grid{grid-template-columns:1fr}.pr-grid .wide{grid-column:auto}}@media(max-width:480px){.pr-row{flex-wrap:wrap}.pr-actions{width:100%;justify-content:flex-end}}
</style>
<div class="pr-wrap">
    <header class="pr-hero"><div><h2><i class="fas fa-utensils"></i> Carta y Restaurante</h2><p>Administra el menú, los precios y la disponibilidad. Los productos activos se cargan desde Consumos a la cuenta de la estancia.</p></div><a href="<?= BASE_URL ?>/modules/consumos/index.php"><i class="fas fa-receipt"></i> Abrir consumos</a></header>
    <?php if ($mensaje): ?><div class="pr-card" role="status"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>

    <?php if (isAdmin()): ?>
    <section class="pr-card">
        <h2><?= $editar ? 'Editar producto' : 'Agregar a la carta' ?></h2>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="guardar">
            <input type="hidden" name="id" value="<?= (int)($editar['id'] ?? 0) ?>">
            <div class="pr-grid">
                <div class="pr-field"><label for="nombre">Nombre</label><input id="nombre" name="nombre" required maxlength="100" value="<?= htmlspecialchars($editar['nombre'] ?? '') ?>"></div>
                <div class="pr-field"><label for="precio">Precio (COP)</label><input id="precio" name="precio" type="text" inputmode="numeric" autocomplete="off" required min="0" data-solo="moneda" value="<?= isset($editar['precio']) ? (int) round((float) $editar['precio']) : '' ?>"></div>
                <div class="pr-field"><label for="categoria">Categoría</label><select id="categoria" name="categoria" required><?php foreach ($opcionesCategoria as $opc): ?><option value="<?= htmlspecialchars($opc['clave']) ?>" <?= ($editar['categoria'] ?? '') === $opc['clave'] ? 'selected' : '' ?>><?= htmlspecialchars($opc['nombre']) ?></option><?php endforeach; ?></select></div>
                <div class="pr-field wide"><label for="descripcion">Descripción (opcional)</label><textarea id="descripcion" name="descripcion" rows="2" maxlength="200"><?= htmlspecialchars($editar['descripcion'] ?? '') ?></textarea></div>
                <div class="wide"><button class="pr-save" type="submit"><?= $editar ? 'Guardar cambios' : 'Agregar producto' ?></button><?php if ($editar): ?> <a href="<?= BASE_URL ?>/modules/productos/index.php">Cancelar</a><?php endif; ?></div>
            </div>
        </form>
    </section>
    <section class="pr-card" id="categorias">
        <h2><i class="fas fa-tags"></i> Categorías de la carta</h2>
        <?php if (!$categoriasTabla): ?>
            <div class="pr-aviso" role="status"><i class="fas fa-triangle-exclamation"></i> Para crear, renombrar o desactivar categorías falta ejecutar el SQL de la versión 5.0.0 (<code>sql/migration_027_categorias_producto.sql</code>, en phpMyAdmin). Mientras tanto se usan las cinco de siempre.</div>
        <?php else: ?>
        <form method="post" class="pr-catnew">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="categoria_crear">
            <input name="nombre_categoria" required maxlength="<?= CATEGORIA_NOMBRE_MAX ?>" placeholder="Nueva categoría (por ejemplo: Postres)" aria-label="Nombre de la nueva categoría" autocomplete="off">
            <button class="pr-save" type="submit"><i class="fas fa-plus"></i> Crear categoría</button>
        </form>
        <?php endif; ?>
        <?php foreach ($categoriasLista as $cat): ?>
            <div class="pr-cat <?= !$cat['activa'] ? 'pr-catoff' : '' ?>">
                <?php if ($categoriasTabla): ?>
                    <form method="post" class="pr-catren">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="categoria_renombrar">
                        <input type="hidden" name="clave" value="<?= htmlspecialchars($cat['clave']) ?>">
                        <input name="nombre_categoria" required maxlength="<?= CATEGORIA_NOMBRE_MAX ?>" value="<?= htmlspecialchars($cat['nombre']) ?>" aria-label="Nombre de la categoría <?= htmlspecialchars($cat['nombre']) ?>" autocomplete="off">
                        <button class="pr-mini" type="submit">Guardar nombre</button>
                    </form>
                <?php else: ?>
                    <div class="pr-catren"><strong><?= htmlspecialchars($cat['nombre']) ?></strong></div>
                <?php endif; ?>
                <span class="pr-catn"><?= (int) $cat['productos'] ?> producto<?= (int) $cat['productos'] === 1 ? '' : 's' ?><?= !$cat['activa'] ? ' · desactivada' : '' ?></span>
                <?php if ($categoriasTabla && $cat['clave'] !== CATEGORIA_POR_DEFECTO): ?>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="categoria_estado">
                        <input type="hidden" name="clave" value="<?= htmlspecialchars($cat['clave']) ?>">
                        <input type="hidden" name="activa" value="<?= $cat['activa'] ? '0' : '1' ?>">
                        <button class="pr-mini" type="submit"><?= $cat['activa'] ? 'Desactivar' : 'Activar' ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <p class="pr-meta" style="margin:10px 0 0;">Desactivar una categoría no borra nada: deja de ofrecerse para productos nuevos y los que ya la tienen siguen igual. «Otros» siempre está activa.</p>
    </section>
    <?php endif; ?>
    <section class="pr-card"><h2>Carta y Restaurante</h2>
        <?php if (!$productos): ?><p>Aún no hay productos registrados.</p><?php endif; ?>
        <?php foreach ($productos as $producto): ?>
            <article class="pr-row <?= !$producto['activo'] ? 'pr-off' : '' ?>">
                <div class="pr-info"><div class="pr-name"><?= htmlspecialchars($producto['nombre']) ?></div><div class="pr-meta"><?= htmlspecialchars($tipos[$producto['categoria']] ?? 'Otros') ?><?= $producto['descripcion'] ? ' · ' . htmlspecialchars($producto['descripcion']) : '' ?> · <?= $producto['activo'] ? 'Disponible' : 'Inactivo' ?></div></div>
                <div class="pr-price">$<?= number_format((float)$producto['precio'], 0, ',', '.') ?></div>
                <?php if (isAdmin()): ?><div class="pr-actions"><a href="?editar=<?= (int)$producto['id'] ?>">Editar</a><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="estado"><input type="hidden" name="id" value="<?= (int)$producto['id'] ?>"><button type="submit"><?= $producto['activo'] ? 'Desactivar' : 'Activar' ?></button></form></div><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
