<?php
// ============================================================
// modules/reportes/reiniciar.php
// Reinicio de datos del sistema — Casa Bosque (SOLO ADMIN)
// ============================================================
// Borra TODOS los datos operativos EXCEPTO los usuarios (para no
// perder el acceso) y la AUDITORÍA, que desde la aplicación no se
// puede borrar nunca. Ver la nota junto a $tablasRastro: para la
// entrega al cliente se vacía con sql/preparar-entrega.sql.
//
// Lo que se borra está en tres listas, abajo: los datos, los intentos
// de acceso y sesiones, y el catálogo. Las dos primeras van en los dos
// modos; el catálogo solo en el total. El contador de facturas vuelve
// a cero aparte, porque es un número y no una tabla de datos.
//
// Protecciones:
//  - Solo administrador.
//  - Exige escribir la palabra BORRAR para confirmar.
//  - Recomienda y enlaza la descarga de respaldo antes de borrar.
//  - Registra la acción en auditoría: es la primera entrada de la
//    auditoría nueva, así que el rastro nunca queda en blanco.
//  - Respeta la tabla de usuarios.
//
// ⚠️  Esta acción es IRREVERSIBLE. Úsala con extrema precaución,
//     típicamente para limpiar datos de prueba antes de entregar
//     el sistema en producción.
// ------------------------------------------------------------

$pageTitle = 'Reiniciar datos';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireAdmin();   // exclusivo del administrador
// Borrar los datos del hotel es irreversible: aunque el equipo esté recordado,
// aquí se pide el código. Ver includes/equipos.php.
require_once __DIR__ . '/../../includes/equipos.php';
require_once __DIR__ . '/../../includes/categorias.php';   // categoriasRestablecer(): el reinicio total deja las categorías de la carta como de instalación (5.0.0)
exigirCodigoEnAccionCritica();

$pdo = getDB();
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $msg = 'Token de seguridad inválido. Intente de nuevo.';
        $msgType = 'danger';
    } else {
        $confirmacion = trim($_POST['confirmacion'] ?? '');
        // Dos modos: 'parcial' (solo datos de prueba, conserva el catálogo) y
        // 'total' (reset de fábrica, borra también habitaciones/tipos/productos).
        $modo = ($_POST['modo'] ?? 'total') === 'parcial' ? 'parcial' : 'total';

        if ($confirmacion !== 'BORRAR') {
            $msg = 'Debes escribir exactamente la palabra BORRAR para confirmar. No se eliminó nada.';
            $msgType = 'danger';
        } else {
            // Orden de borrado: de tablas "hijas" a "padres". `usuarios` NO se toca:
            // es lo único que se conserva, para no perder el acceso al sistema.
            //
            // Datos transaccionales (se borran en AMBOS modos). Aquí faltaban
            // `facturas`, `ventas_mostrador` y `correos_enviados`: el sistema se
            // entregaba con las facturas de prueba dentro, con las ventas de
            // mostrador, y con el registro de correos enviados, que lleva las
            // direcciones de los huéspedes de prueba (Ley 1581).
            $tablasDatos = ['pagos', 'consumos', 'acompanantes', 'facturas_electronicas',
                            'facturas', 'ventas_mostrador', 'correos_enviados',
                            'estancias', 'reservas', 'huespedes'];

            // Intentos de acceso y sesiones (se borran en AMBOS modos). Son rastro
            // operativo, no el registro de auditoría.
            //
            // Borrar `sesiones_activas` echa a TODAS las demás sesiones (a propósito: no hay
            // constancia de que sigan vigentes: sesionVigente() ya no «adopta» una sesión
            // que estuvo registrada y perdió su fila, 4.3.2) y NO a quien hace el reinicio:
            // más abajo se vuelve a registrar su sesión. Antes se confiaba en que
            // sesionVigente() la adoptara sola, y eso mismo dejaba volver a valer a una
            // sesión cerrada a mano o vencida.
            //
            // `equipos_confiables` son los equipos donde se marcó «recordar este
            // equipo» (includes/equipos.php). Quien tenga una de esas cookies entra
            // sin código: entregar el sistema con los equipos de las pruebas
            // recordados sería entregarlo con puertas abiertas. Borrarlos tampoco
            // echa a quien hace el reinicio; lo único que pasa es que la próxima
            // vez se le pide el código.
            $tablasRastro = ['intentos_login', 'intentos_huesped',
                             'restablecer_clave', 'sesiones_activas',
                             'equipos_confiables'];

            // `auditoria` NO VA AQUÍ, y no es un olvido.
            //
            // Un registro que el operador puede borrar no sirve como registro. La
            // tabla tiene dos disparadores —auditoria_no_update y
            // auditoria_no_delete (sql/schema.sql)— que hacen fallar cualquier
            // UPDATE o DELETE con el error 45000. Si se añadiera aquí, el `delete`
            // fallaría, acabaría en $errores y el rastro seguiría intacto: un
            // arreglo que parece funcionar y no funciona.
            //
            // Para la ENTREGA al cliente la auditoría sí hay que vaciarla, y se
            // hace a propósito desde fuera de la aplicación, con
            // `sql/preparar-entrega.sql` en phpMyAdmin: ese script usa TRUNCATE,
            // que en MySQL y MariaDB es una instrucción de estructura y no activa
            // los disparadores. Los disparadores siguen existiendo después, así que
            // la tabla queda vacía y vuelve a ser inmutable desde el primer día del
            // cliente.

            // Catálogo (SOLO en el reinicio total de fábrica):
            $tablasCatalogo = ['habitaciones', 'tipos_habitacion', 'productos'];

            $tablas = array_merge($tablasDatos, $tablasRastro);
            if ($modo !== 'parcial') {
                $tablas = array_merge($tablas, $tablasCatalogo);
            }

            // Solo intentar con las tablas que existen (p. ej. facturas_electronicas
            // solo existe si se activó DIAN) — evita falsos errores.
            try {
                $existentes = $pdo->query("select lower(table_name) from information_schema.tables where table_schema = database()")->fetchAll(PDO::FETCH_COLUMN);
                $tablas = array_values(array_filter($tablas, fn($t) => in_array(strtolower($t), $existentes, true)));
            } catch (Exception $e) { /* sin acceso a information_schema: seguimos con la lista completa */ }

            $errores = [];
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            } catch (Exception $e) { /* algunos hostings no lo permiten; seguimos igual */ }

            foreach ($tablas as $t) {
                try {
                    $pdo->exec("delete from $t");
                    try { $pdo->exec("alter table $t auto_increment = 1"); } catch (Exception $e) {}
                } catch (Exception $e) {
                    $errores[] = $t;
                }
            }

            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (Exception $e) {}

            // 5.0.0: en el reinicio TOTAL el catálogo vuelve a como se instala: las categorías que creó el hotel se borran y las cinco de siempre
            // recuperan su nombre y quedan activas (sin ellas un producto nuevo no tendría categoría que elegir). En el parcial no se tocan.
            if ($modo !== 'parcial') {
                try { categoriasRestablecer($pdo); } catch (Throwable $e) { $errores[] = 'categorias_producto'; }
            }

            // La sesión de quien acaba de reiniciar vuelve a quedar registrada (la tabla se vació arriba).
            if (function_exists('sesionRegistrar')) { sesionRegistrar($pdo, (int) getCurrentUserId()); }

            // Tras un reinicio PARCIAL no queda ninguna reserva/estancia, así que
            // ninguna habitación debería seguir 'ocupada'/'limpieza': las liberamos
            // todas para que el catálogo quede listo para usar. (En modo total las
            // habitaciones se borran, así que no aplica.)
            if ($modo === 'parcial') {
                try { $pdo->exec("update habitaciones set estado = 'disponible'"); } catch (Exception $e) {}
            }

            // El contador de facturas. No es una tabla de datos, es un número, así
            // que borrar la fila no vale: modules/facturacion/index.php lee
            // `where id=1` y sin esa fila no puede numerar. Se pone a cero, y se
            // crea si no estuviera.
            //
            // Sin esto el sistema se entregaba con el contador donde lo dejaron las
            // pruebas, así que la primera factura real del hotel habría salido con
            // el número siguiente al de la última factura inventada.
            try {
                $pdo->exec("insert into secuencias_factura (id, ultimo) values (1, 0)
                            on duplicate key update ultimo = 0");
            } catch (Exception $e) { $errores[] = 'secuencias_factura'; }

            $detalleAudit = $modo === 'parcial'
                ? 'Reinició los DATOS DE PRUEBA: reservas, estancias, consumos, pagos, huéspedes, acompañantes, facturas y ventas de mostrador. Se vaciaron también el registro de correos, los intentos de acceso y las sesiones, y el contador de facturas volvió a cero. Se conservó el catálogo de habitaciones, tipos y productos.'
                : 'Reinició TODOS los datos, incluido el catálogo de habitaciones, tipos y productos. Se vaciaron también el registro de correos, los intentos de acceso y las sesiones, y el contador de facturas volvió a cero. Se conservaron los usuarios.';
            registrarAuditoria($pdo, 'sistema', null, 'reiniciar', $detalleAudit);

            if (empty($errores)) {
                $msg = $modo === 'parcial'
                    ? 'Listo. Se borraron los datos de prueba, las facturas, las ventas de mostrador, el registro de correos y las sesiones. El contador de facturas quedó en cero. Tus habitaciones, tipos y productos se conservaron.'
                    : 'Listo. Todos los datos fueron eliminados, incluido el catálogo. El contador de facturas quedó en cero. Los usuarios y la auditoría se conservaron.';
                $msgType = 'success';
            } else {
                $msg = 'Se borraron los datos, pero hubo problemas con: ' . implode(', ', $errores) . '. Revisa esas tablas en phpMyAdmin.';
                $msgType = 'warning';
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<style>
.danger-zone{
    border:2px solid rgba(239,68,68,.35); border-radius:18px; background:rgba(239,68,68,.03);
    padding:0; overflow:hidden; max-width:680px;
}
.dz-head{ background:rgba(239,68,68,.1); padding:18px 24px; display:flex; align-items:center; gap:12px; border-bottom:1px solid rgba(239,68,68,.2); }
.dz-head i{ font-size:1.4rem; color:var(--danger,#ef4444); }
.dz-head h3{ margin:0; color:#b91c1c; font-size:1.15rem; }
.dz-body{ padding:24px; }
.dz-body p{ color:var(--gray-600,#475569); line-height:1.6; margin:0 0 var(--space-3); font-size:.95rem; }
.dz-list{ background:#fff; border:1px solid var(--gray-200,#e2e8f0); border-radius:12px; padding:14px 18px; margin:var(--space-3) 0; font-size:.9rem; }
.dz-list .keep{ color:#1F7A4D; font-weight:600; }
.dz-list .del{ color:#b91c1c; }
.dz-step{ display:flex; gap:10px; align-items:flex-start; margin:var(--space-2) 0; font-size:.92rem; color:var(--gray-700,#334155); }
.dz-step .num{ flex-shrink:0; width:22px; height:22px; border-radius:50%; background:var(--gray-200,#e2e8f0); color:var(--gray-700,#334155); display:flex; align-items:center; justify-content:center; font-size:.78rem; font-weight:700; }

.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.5);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:9999;opacity:0;pointer-events:none;transition:opacity .25s;padding:16px;}
.modal-overlay.active{opacity:1;pointer-events:auto;}
.confirm-card{background:#fff;width:100%;max-width:440px;border-radius:14px;box-shadow:0 20px 40px rgba(0,0,0,.2);overflow:hidden;transform:scale(.92);transition:transform .25s cubic-bezier(.34,1.56,.64,1);max-height:90vh;overflow-y:auto;}
.modal-overlay.active .confirm-card{transform:scale(1);}
.confirm-header{padding:20px 22px 8px;display:flex;align-items:center;gap:12px;}
.icon-danger{width:42px;height:42px;border-radius:50%;background:rgba(239,68,68,.12);color:#ef4444;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0;}
.confirm-title{font-size:1.15rem;font-weight:600;color:var(--gray-800,#1e293b);margin:0;}
.confirm-body{padding:8px 22px 16px;}
.confirm-body p{color:var(--gray-600,#475569);font-size:.92rem;line-height:1.55;margin:0 0 14px;}
.confirm-body input{width:100%;padding:12px 14px;border:2px solid var(--gray-200,#e2e8f0);border-radius:10px;font-family:inherit;font-size:1rem;text-align:center;letter-spacing:3px;font-weight:700;text-transform:uppercase;}
.confirm-body input:focus{outline:none;border-color:#ef4444;}
.confirm-actions{padding:14px 22px 22px;display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap;}
.btn-danger{background:#ef4444;color:#fff;border:none;}
.btn-danger:disabled{background:#fca5a5;cursor:not-allowed;}
</style>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>" style="max-width:680px;">
        <i class="fas fa-<?= $msgType === 'success' ? 'check-circle' : ($msgType === 'warning' ? 'exclamation-triangle' : 'times-circle') ?>"></i>
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<div class="danger-zone">
    <div class="dz-head">
        <i class="fas fa-triangle-exclamation"></i>
        <h3>Reiniciar datos del sistema</h3>
    </div>
    <div class="dz-body">
        <p>Elige qué quieres borrar. <strong>Ninguna de estas acciones se puede deshacer.</strong> Descarga un respaldo antes por si acaso.</p>

        <!-- Opción 1: solo datos de prueba -->
        <div class="dz-list" style="border-color:rgba(245,158,11,.4);">
            <div style="margin-bottom:6px;"><span style="color:#b45309;font-weight:700;"><i class="fas fa-broom"></i> Solo datos de prueba</span> — lo más común</div>
            <div><span class="del">Borra:</span> reservas, huéspedes, estancias, consumos, pagos, acompañantes, <strong>facturas</strong> y ventas de mostrador.</div>
            <div><span class="del">Borra también:</span> el registro de correos enviados, los intentos de acceso y las sesiones. El contador de facturas vuelve a <strong>cero</strong>, así que la siguiente factura será la número 1.</div>
            <div><span class="keep"><i class="fas fa-shield-halved"></i> Conserva:</span> tus <strong>habitaciones</strong>, tipos, productos, usuarios y la <strong>auditoría</strong>.</div>
            <button type="button" class="btn" style="margin-top:12px;background:#f59e0b;color:#fff;border:none;" onclick="abrirReset('parcial')">
                <i class="fas fa-broom"></i> Borrar solo datos de prueba
            </button>
        </div>

        <!-- Opción 2: reinicio total -->
        <div class="dz-list" style="border-color:rgba(239,68,68,.35);">
            <div style="margin-bottom:6px;"><span class="del" style="font-weight:700;"><i class="fas fa-triangle-exclamation"></i> Reinicio total (de fábrica)</span></div>
            <div><span class="del">Borra:</span> todo lo anterior <strong>+ las habitaciones, tipos y productos</strong>. Dejarás el sistema en blanco.</div>
            <div><span class="keep"><i class="fas fa-shield-halved"></i> Conserva:</span> solo los <strong>usuarios</strong> y la <strong>auditoría</strong>.</div>
            <div style="margin-top:8px;color:#6b5b4a;font-size:.86rem;">
                <i class="fas fa-circle-info"></i> La auditoría no se puede borrar desde aquí, a propósito: un registro
                que el operador puede borrar no sirve como registro. Para vaciarla
                al <strong>entregar el sistema</strong> se ejecuta una vez
                <code>sql/preparar-entrega.sql</code> en phpMyAdmin.
            </div>
            <button type="button" class="btn btn-danger" style="margin-top:12px;" onclick="abrirReset('total')">
                <i class="fas fa-trash"></i> Reinicio total
            </button>
        </div>

        <div style="margin-top:var(--space-4);">
            <a href="<?= BASE_URL ?>/modules/reportes/respaldo.php" class="btn btn-outline">
                <i class="fas fa-download"></i> Descargar respaldo primero
            </a>
        </div>
    </div>
</div>

<!-- Modal de confirmación (adaptable al modo) -->
<div id="modal-reiniciar" class="modal-overlay">
    <div class="confirm-card">
        <form method="POST" style="margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="modo" id="reset-modo" value="total">
            <div class="confirm-header">
                <div class="icon-danger"><i class="fas fa-triangle-exclamation"></i></div>
                <h4 class="confirm-title" id="reset-title">Confirmación final</h4>
            </div>
            <div class="confirm-body">
                <p id="reset-desc"></p>
                <p>Para confirmar, escribe la palabra <strong style="color:#b91c1c;">BORRAR</strong>:</p>
                <input type="text" name="confirmacion" id="input-confirmacion" autocomplete="off" placeholder="BORRAR"
                       oninput="document.getElementById('btn-confirmar').disabled = (this.value.trim().toUpperCase() !== 'BORRAR')">
            </div>
            <div class="confirm-actions">
                <button type="button" class="btn btn-outline btn-sm" onclick="cerrarReset()">Cancelar</button>
                <button type="submit" class="btn btn-danger btn-sm" id="btn-confirmar" disabled>
                    <i class="fas fa-trash"></i> Borrar definitivamente
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirReset(modo){
    document.getElementById('reset-modo').value = modo;
    document.getElementById('reset-title').textContent = modo === 'parcial' ? 'Borrar solo datos de prueba' : 'Reinicio total de fábrica';
    document.getElementById('reset-desc').innerHTML = modo === 'parcial'
        ? 'Vas a borrar <strong>reservas, huéspedes, estancias, consumos, pagos, acompañantes, facturas</strong> y ventas de mostrador. Tus <strong>habitaciones se conservan</strong>. No se puede deshacer.'
        : 'Vas a borrar <strong>TODO</strong>, incluidas las <strong>habitaciones, tipos y productos</strong> (solo se conservan los usuarios y la auditoría). No se puede deshacer.';
    var i = document.getElementById('input-confirmacion');
    i.value = ''; document.getElementById('btn-confirmar').disabled = true;
    document.getElementById('modal-reiniciar').classList.add('active');
    setTimeout(function(){ i.focus(); }, 250);
}
function cerrarReset(){ document.getElementById('modal-reiniciar').classList.remove('active'); }
document.getElementById('modal-reiniciar').addEventListener('click', function(e){ if(e.target === this){ cerrarReset(); } });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
