<?php
$pageTitle = 'Facturación';
// No se declaran aquí $currentDir ni $currentPage: includes/header.php los
// recalcula a partir del archivo real y pisaría lo que pusiéramos.
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cifrado.php';
requireAdmin();

$pdo = getDB();
$msg = '';
$msgType = '';
$facturaCreada = 0;

// ── Anular una factura ──
// Una factura emitida NO se corrige: se anula y se emite otra. La
// original se conserva tal cual, marcada, con el motivo, quién la anuló
// y cuándo. Así queda la cadena completa de lo que pasó.
//
// Esto NO es una nota crédito formal, que es lo que pediría la DIAN para
// facturación electrónica. Es el control interno que permite rehacer un
// comprobante mal emitido sin borrar nada.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'anular') {
    if (!validateCsrf()) {
        $msg = 'La sesión del formulario venció. Recarga la página e inténtalo de nuevo.';
        $msgType = 'danger';
    } else {
        $facturaId = (int) ($_POST['factura_id'] ?? 0);
        $motivo    = trim($_POST['motivo'] ?? '');

        if (mb_strlen($motivo) < 10) {
            // El motivo es la justificación de por qué un documento dejó
            // de valer. Sin él la anulación no se puede defender.
            $msg = 'Escribe el motivo de la anulación (al menos 10 caracteres).';
            $msgType = 'danger';
        } else {
            $f = $pdo->prepare("select numero, estado, estancia_id from facturas where id = ?");
            $f->execute([$facturaId]);
            $factura = $f->fetch();

            if (!$factura) {
                $msg = 'No se encontró esa factura.'; $msgType = 'danger';
            } elseif ($factura['estado'] === 'anulada') {
                $msg = 'Esa factura ya estaba anulada.'; $msgType = 'warning';
            } else {
                try {
                    $antesFactura = auditoriaFila($pdo, 'facturas', $facturaId);
                    $pdo->prepare("update facturas
                                   set estado = 'anulada', anulada_en = now(),
                                       anulada_por = ?, motivo_anulacion = ?
                                   where id = ? and estado = 'emitida'")
                        ->execute([getCurrentUserId(), mb_substr($motivo, 0, 300), $facturaId]);

                    registrarAuditoria($pdo, 'facturas', $facturaId, 'anular',
                        "Factura {$factura['numero']} anulada (estancia #{$factura['estancia_id']}). Motivo: " . mb_substr($motivo, 0, 180),
                        $antesFactura, auditoriaFila($pdo, 'facturas', $facturaId));

                    $msg = "Factura {$factura['numero']} anulada. La cuenta vuelve a estar disponible para facturar.";
                    $msgType = 'success';
                } catch (Throwable $e) {
                    error_log('Anular factura: ' . $e->getMessage());
                    $msg = 'No se pudo anular la factura.'; $msgType = 'danger';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'emitir') {
    if (!validateCsrf()) {
        $msg = 'La sesión del formulario venció. Recarga la página e inténtalo de nuevo.';
        $msgType = 'danger';
    } else {
        $estanciaId = (int)($_POST['estancia_id'] ?? 0);
        $tipo = $_POST['tipo_receptor'] ?? '';
        $direccion = trim($_POST['direccion'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $documentoTipo = $_POST['documento_tipo'] ?? '';
        $documento = preg_replace('/\s+/', '', trim($_POST['documento_num'] ?? ''));
        $nombres = trim($_POST['nombres'] ?? '');
        $apellidos = trim($_POST['apellidos'] ?? '');
        $nit = preg_replace('/\D+/', '', $_POST['nit'] ?? '');
        $dv = trim($_POST['nit_dv'] ?? '');
        $razonSocial = trim($_POST['razon_social'] ?? '');
        $stay = $pdo->prepare("select estado, total_pagar from estancias where id=?");
        $stay->execute([$estanciaId]);
        $estancia = $stay->fetch();
        $errores = [];

        if (!$estancia || $estancia['estado'] !== 'cerrada') $errores[] = 'Solo se puede facturar una cuenta cerrada.';
        // Se bloquea solo si hay una factura VIGENTE. Si la anterior se
        // anuló, la cuenta vuelve a poder facturarse: esa es la forma
        // correcta de corregir una factura equivocada.
        $dup = $pdo->prepare("select id from facturas where estancia_id=? and estado='emitida'");
        $dup->execute([$estanciaId]);
        if ($dup->fetchColumn()) $errores[] = 'Esta cuenta ya tiene una factura vigente. Anúlala primero si necesitas corregirla.';
        if (!in_array($tipo, ['natural', 'juridica'], true)) $errores[] = 'Selecciona persona natural o jurídica.';
        if ($direccion === '' || $telefono === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) $errores[] = 'Completa dirección, teléfono y un correo válido.';

        if ($tipo === 'natural') {
            if (!in_array($documentoTipo, ['CC', 'CE', 'TI', 'RC', 'PAS'], true) || $documento === '' || $nombres === '' || $apellidos === '') {
                $errores[] = 'Para persona natural indica tipo y número de documento, nombres y apellidos.';
            }
            $razonSocial = trim($nombres . ' ' . $apellidos);
            $nit = ''; $dv = '';
        } elseif ($tipo === 'juridica') {
            if (!preg_match('/^\d{5,15}$/', $nit) || !preg_match('/^\d$/', $dv) || $razonSocial === '') {
                $errores[] = 'Para persona jurídica indica razón social, NIT y dígito de verificación.';
            }
            $documentoTipo = ''; $documento = '';
        }

        if ($errores) {
            $msg = implode(' ', array_unique($errores));
            $msgType = 'danger';
        } else {
            try {
                $pdo->beginTransaction();
                $seq = $pdo->query("select ultimo from secuencias_factura where id=1 for update");
                $consecutivo = (int)$seq->fetchColumn() + 1;
                if ($consecutivo < 1) throw new RuntimeException('No se encontró la secuencia de facturación.');
                $numero = 'CBF-' . str_pad((string)$consecutivo, 7, '0', STR_PAD_LEFT);
                $pdo->prepare("update secuencias_factura set ultimo=? where id=1")->execute([$consecutivo]);
                $stmt = $pdo->prepare("insert into facturas (estancia_id, numero, tipo_receptor, documento_tipo, documento_num, razon_social, nit, nit_dv, direccion, telefono, correo, total, usuario_id) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $estanciaId, $numero, $tipo, $tipo === 'natural' ? $documentoTipo : null,
                    $tipo === 'natural' ? cifrar($documento) : null, $razonSocial,
                    $tipo === 'juridica' ? $nit : null, $tipo === 'juridica' ? $dv : null,
                    cifrar($direccion), cifrar($telefono), cifrar($correo), $estancia['total_pagar'], getCurrentUserId()
                ]);
                $facturaCreada = (int)$pdo->lastInsertId();
                registrarAuditoria($pdo, 'facturas', $facturaCreada, 'emitir', "Factura interna $numero para estancia #$estanciaId ($tipo)");
                $pdo->commit();
                $msg = "Factura $numero emitida correctamente.";
                $msgType = 'success';
                // El enlace «Abrir factura» viaja con la notificación (includes/mensajes.php lo valida: solo caminos del sistema).
                $msgAccion = ['texto' => 'Abrir factura', 'href' => BASE_URL . '/modules/checkout/factura_pdf.php?factura=' . $facturaCreada . '&volver=facturacion'];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $msg = 'No se pudo emitir la factura. Revisa si la cuenta ya fue facturada.';
                $msgType = 'danger';
            }
        }
    }
}

// Pendientes: cuentas cerradas SIN factura vigente. Una cuenta cuya
// factura se anuló vuelve a aparecer aquí, que es justo lo que se busca.
$pendientes = $pdo->query("select e.id, e.fecha_checkout, e.total_pagar, h.numero habitacion, hu.nombres, hu.apellidos
    from estancias e join habitaciones h on h.id=e.habitacion_id join huespedes hu on hu.id=e.huesped_id
    where e.estado='cerrada'
      and not exists (select 1 from facturas f where f.estancia_id = e.id and f.estado = 'emitida')
    order by e.fecha_checkout desc limit 200")->fetchAll();

// Las anuladas siguen en la lista, marcadas: no se ocultan ni se borran.
$emitidas = $pdo->query("select f.id factura_id, f.numero, f.tipo_receptor, f.razon_social, f.total, f.emitida_en,
                                f.estado, f.anulada_en, f.motivo_anulacion, u.nombre anulada_por_nombre,
                                e.id estancia_id, h.numero habitacion
    from facturas f
    join estancias e on e.id=f.estancia_id
    join habitaciones h on h.id=e.habitacion_id
    left join usuarios u on u.id = f.anulada_por
    order by f.emitida_en desc limit 100")->fetchAll();
$seleccion = (int)($_GET['estancia'] ?? 0);

// ── Datos del huésped de la cuenta elegida ──
// El sistema ya sabe quién es: entrando con ?estancia= tenía que reescribirse
// a mano el documento, el nombre, el teléfono y el correo que ya están en su
// ficha. Ahora vienen puestos y solo hay que confirmarlos.
//
// Se cargan SOLO los de la cuenta seleccionada, no los de todas las
// pendientes: son datos personales y no tienen por qué viajar en la página
// los de gente que no se está facturando.
//
// La DIRECCIÓN no se precarga porque no se guarda: `huespedes` no tiene esa
// columna. Es el único campo que hay que preguntar.
$receptor = ['documento_tipo' => 'CC', 'documento_num' => '', 'nombres' => '',
             'apellidos' => '', 'telefono' => '', 'correo' => ''];

if ($seleccion > 0) {
    $stmtR = $pdo->prepare("select hu.documento_tipo, hu.documento_num, hu.nombres,
                                   hu.apellidos, hu.telefono, hu.email
                            from estancias e
                            join huespedes hu on hu.id = e.huesped_id
                            where e.id = ?");
    $stmtR->execute([$seleccion]);
    if ($datosR = $stmtR->fetch(PDO::FETCH_ASSOC)) {
        // El tipo solo se preselecciona si existe entre las opciones del
        // formulario: `huespedes` admite NIT, que aquí no es una de ellas.
        $tipo = (string) $datosR['documento_tipo'];
        $receptor['documento_tipo'] = in_array($tipo, ['CC', 'CE', 'TI', 'RC', 'PAS'], true) ? $tipo : 'CC';
        $receptor['documento_num']  = (string) descifrar($datosR['documento_num']);
        $receptor['nombres']        = (string) $datosR['nombres'];
        $receptor['apellidos']      = (string) $datosR['apellidos'];
        $receptor['telefono']       = (string) descifrar($datosR['telefono']);
        $receptor['correo']         = (string) $datosR['email'];
    }
}
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
.fc-wrap{max-width:1180px;margin:auto;padding-bottom:90px;display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:18px;align-items:start}.fc-hero{grid-column:1/-1;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:23px 28px;border-radius:20px;background:linear-gradient(120deg,#3E2412,#9C6728);color:#fff;box-shadow:0 12px 28px rgba(42,23,9,.13)}.fc-hero h2{font-size:1.3rem;color:#fff;margin:0 0 6px}.fc-hero p{margin:0;color:rgba(255,255,255,.82);font-size:.88rem;line-height:1.5}.fc-shortcuts{display:flex;gap:9px;flex:none}.fc-shortcuts a{display:inline-flex;align-items:center;gap:8px;padding:10px 13px;border-radius:10px;background:#fff;color:#6B4420;text-decoration:none;font-weight:700;font-size:.82rem}.fc-card{background:#fff;border:1px solid var(--cb-border,#EDE3D2);border-radius:18px;padding:20px;margin:0;box-shadow:0 5px 16px rgba(42,23,9,.04)}.fc-card:nth-of-type(1){grid-column:1}.fc-card:nth-of-type(2){grid-column:2}
/* Sin `grid-row:2`: fijaba las tarjetas a la segunda fila, así que el aviso,
   al cruzar las dos columnas, no cabía en esa fila y la rejilla lo mandaba a
   la tercera, debajo del formulario. Dejando la fila libre, el aviso ocupa la
   segunda y las tarjetas bajan a la tercera; sin aviso no queda hueco. */
.fc-card h2{font-size:1.05rem;color:var(--cb-forest,#3E2412);margin:0 0 8px}.fc-help{color:var(--cb-muted,#9A8778);font-size:.84rem;line-height:1.5;margin:0 0 16px}
.fc-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.fc-field label{display:block;font-size:.78rem;font-weight:700;color:var(--cb-forest,#3E2412);margin:0 0 6px}.fc-field input,.fc-field select{width:100%;padding:11px 12px;border:1px solid var(--cb-border,#EDE3D2);border-radius:10px;font:inherit}.fc-span{grid-column:1/-1}.fc-toggle{display:flex;gap:12px;margin:0 0 14px}.fc-toggle label{display:flex;gap:7px;align-items:center;padding:10px 14px;border:1px solid var(--cb-border,#EDE3D2);border-radius:10px;font-weight:700;cursor:pointer}
.fc-submit{border:0;border-radius:11px;background:var(--cb-primary,#6B4420);color:#fff;font:inherit;font-weight:700;padding:12px 18px;cursor:pointer;margin-top:14px}.fc-alert{grid-column:1/-1;padding:12px 14px;border-radius:12px;margin-bottom:14px}.fc-alert.success{background:#e6f7f2;color:#047857}.fc-alert.danger{background:#fdeded;color:#b91c1c}
.fc-list{display:grid;gap:10px}.fc-row{display:flex;align-items:center;gap:12px;border-top:1px solid #edf1eb;padding:12px 0}.fc-row:first-child{border:0}.fc-row .fc-info{flex:1;min-width:0}.fc-row small{display:block;color:var(--cb-muted,#9A8778);margin-top:3px}.fc-link{display:inline-flex;background:#F5EBDC;color:#6B4420;padding:9px 12px;border-radius:9px;text-decoration:none;font-size:.82rem;font-weight:700;white-space:nowrap}
/* ── Estado de la factura y anulación ── */
.fc-estado{display:inline-flex;align-items:center;gap:5px;font-size:.66rem;font-weight:800;padding:3px 9px;border-radius:99px;letter-spacing:.04em;vertical-align:middle;margin-left:7px}
.fc-estado.anulada{background:#fdeaea;color:#b91c1c}
.fc-row.anulada .fc-info strong{text-decoration:line-through;opacity:.72}
.fc-motivo{display:block;margin-top:4px;font-size:.74rem;color:#b91c1c;line-height:1.5}
.fc-anular{border:1px solid rgba(239,68,68,.3);background:#fff;color:#b91c1c;border-radius:9px;padding:7px 12px;font-family:inherit;font-size:.76rem;font-weight:700;cursor:pointer;white-space:nowrap}
.fc-anular:hover{background:#fdeded}
/* Tarjeta de confirmación, igual que en el resto del sistema */
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.4);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:9999;opacity:0;pointer-events:none;transition:opacity .25s;padding:16px}
.modal-overlay.active{opacity:1;pointer-events:auto}
.confirm-card{background:#fff;width:100%;max-width:440px;border-radius:18px;box-shadow:0 20px 40px rgba(0,0,0,.18);overflow:hidden;transform:scale(.92);transition:transform .25s cubic-bezier(.34,1.56,.64,1)}
.modal-overlay.active .confirm-card{transform:scale(1)}
.confirm-header{padding:20px 20px 8px;display:flex;align-items:center;gap:12px}
.confirm-title{font-size:1.05rem;font-weight:700;color:var(--cb-forest,#3E2412);margin:0}
.confirm-body{padding:0 20px 16px;color:#475569;font-size:.86rem;line-height:1.5}
.confirm-actions{padding:14px 20px 18px;display:flex;justify-content:flex-end;gap:10px}
.icon-container-danger{width:42px;height:42px;background:rgba(239,68,68,.1);color:var(--danger,#ef4444);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.btn-x{padding:11px 16px;border-radius:11px;border:1px solid var(--cb-border,#EDE3D2);background:#fff;color:#475569;font-weight:600;cursor:pointer;font-family:inherit}
.btn-d{padding:11px 16px;border-radius:11px;border:none;background:var(--danger,#ef4444);color:#fff;font-weight:700;cursor:pointer;font-family:inherit}
.fc-motivo-input{width:100%;margin-top:9px;padding:10px 12px;border:1.5px solid var(--cb-border,#EDE3D2);border-radius:10px;font-family:inherit;font-size:.86rem;resize:vertical;min-height:72px}
.fc-motivo-input:focus{outline:0;border-color:var(--danger,#ef4444)}
@media(max-width:900px){.fc-wrap{grid-template-columns:1fr}.fc-hero{grid-column:auto;align-items:flex-start;flex-direction:column}.fc-card:nth-of-type(1),.fc-card:nth-of-type(2){grid-column:auto;grid-row:auto}}@media(max-width:650px){.fc-card{padding:15px}.fc-grid{grid-template-columns:1fr}.fc-span{grid-column:auto}.fc-row{align-items:flex-start;flex-wrap:wrap}.fc-row .fc-info{flex-basis:calc(100% - 50px)}}
</style>
<div class="fc-wrap">
    <header class="fc-hero"><div><h2><i class="fas fa-file-invoice-dollar"></i> Facturación</h2><p>Emite facturas internas cuando el huésped las solicita y consulta su historial.</p></div><nav class="fc-shortcuts"><a href="<?= BASE_URL ?>/modules/reportes/comprobantes.php"><i class="fas fa-receipt"></i> Comprobantes</a><a href="<?= BASE_URL ?>/modules/checkout/index.php"><i class="fas fa-door-closed"></i> Check-out</a></nav></header>
    <?php if ($msg): ?><div class="fc-alert <?= $msgType ?>"><?= htmlspecialchars($msg) ?><?php if ($facturaCreada): ?> <a href="<?= BASE_URL ?>/modules/checkout/factura_pdf.php?factura=<?= $facturaCreada ?>&amp;volver=facturacion">Abrir factura</a><?php endif; ?></div><?php endif; ?>
    <section class="fc-card">
        <h2><i class="fas fa-file-invoice-dollar"></i> Emitir factura solicitada</h2>
        <p class="fc-help">Solo se numeran las facturas que el cliente pide. El consecutivo es interno del hotel y no corresponde a un rango autorizado por la DIAN. Cada cuenta admite una factura.</p>
        <form method="POST" id="fc-form">
            <?= csrfField() ?><input type="hidden" name="action" value="emitir">
            <div class="fc-grid">
                <div class="fc-field fc-span"><label for="estancia_id">Cuenta cerrada *</label><?php /* Al cambiar de cuenta se recarga con ?estancia=, que es lo que trae
         los datos del huésped nuevo. */ ?><select id="estancia_id" name="estancia_id" required onchange="if(this.value) location.search='?estancia='+encodeURIComponent(this.value);"><option value="">Selecciona una cuenta sin facturar...</option><?php foreach($pendientes as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $seleccion===(int)$p['id']?'selected':'' ?>>Estancia #<?= (int)$p['id'] ?> · Hab. <?= htmlspecialchars($p['habitacion']) ?> · <?= htmlspecialchars($p['nombres'].' '.$p['apellidos']) ?> · <?= date('d/m/Y',strtotime($p['fecha_checkout'])) ?> · $<?= number_format($p['total_pagar'],0,',','.') ?></option><?php endforeach; ?></select></div>
                <div class="fc-field fc-span"><label>Tipo de receptor *</label><div class="fc-toggle"><label><input type="radio" name="tipo_receptor" value="natural" checked> Persona natural</label><label><input type="radio" name="tipo_receptor" value="juridica"> Persona jurídica</label></div></div>
                <div class="fc-field natural"><label>Tipo de documento *</label><select name="documento_tipo"><?php foreach (['CC'=>'Cédula de ciudadanía','CE'=>'Cédula de extranjería','TI'=>'Tarjeta de identidad','RC'=>'Registro civil','PAS'=>'Pasaporte'] as $k=>$etiquetaDoc): ?><option value="<?= $k ?>"<?= $receptor['documento_tipo']===$k?' selected':'' ?>><?= $etiquetaDoc ?></option><?php endforeach; ?></select></div>
                <div class="fc-field natural"><label>Número de documento *</label><input name="documento_num" maxlength="40" autocomplete="off" value="<?= htmlspecialchars($receptor['documento_num']) ?>"></div>
                <div class="fc-field natural"><label>Nombres *</label><input name="nombres" maxlength="90" value="<?= htmlspecialchars($receptor['nombres']) ?>"></div>
                <div class="fc-field natural"><label>Apellidos *</label><input name="apellidos" maxlength="90" value="<?= htmlspecialchars($receptor['apellidos']) ?>"></div>
                <div class="fc-field juridica" hidden><label>NIT *</label><input name="nit" inputmode="numeric" maxlength="15" disabled></div>
                <div class="fc-field juridica" hidden><label>Dígito de verificación *</label><input name="nit_dv" inputmode="numeric" maxlength="1" disabled></div>
                <div class="fc-field fc-span juridica" hidden><label>Razón social *</label><input name="razon_social" maxlength="180" disabled></div>
                <div class="fc-field"><label>Dirección *</label><input name="direccion" maxlength="200" required></div>
                <div class="fc-field"><label>Teléfono *</label><input name="telefono" maxlength="40" required value="<?= htmlspecialchars($receptor['telefono']) ?>"></div>
                <div class="fc-field fc-span"><label>Correo electrónico *</label><input type="email" name="correo" maxlength="180" required value="<?= htmlspecialchars($receptor['correo']) ?>"></div>
            </div>
            <button class="fc-submit" type="submit"><i class="fas fa-stamp"></i> Emitir factura interna</button>
        </form>
    </section>
    <section class="fc-card"><h2>Facturas emitidas</h2><div class="fc-list">
        <?php if (!$emitidas): ?><p class="fc-help">Aún no se han emitido facturas.</p><?php endif; ?>
        <?php foreach($emitidas as $f): $anulada = ($f['estado'] ?? 'emitida') === 'anulada'; ?>
            <div class="fc-row <?= $anulada ? 'anulada' : '' ?>">
                <div class="fc-info">
                    <strong><?= htmlspecialchars($f['numero']) ?> · <?= htmlspecialchars($f['razon_social']) ?></strong>
                    <?php if ($anulada): ?><span class="fc-estado anulada"><i class="fas fa-ban"></i> ANULADA</span><?php endif; ?>
                    <small>
                        <?= $f['tipo_receptor']==='natural'?'Persona natural':'Persona jurídica' ?> ·
                        Hab. <?= htmlspecialchars($f['habitacion']) ?> ·
                        <?= date('d/m/Y H:i',strtotime($f['emitida_en'])) ?> ·
                        $<?= number_format($f['total'],0,',','.') ?>
                    </small>
                    <?php if ($anulada): ?>
                        <span class="fc-motivo">
                            Anulada el <?= date('d/m/Y H:i', strtotime($f['anulada_en'])) ?>
                            <?= $f['anulada_por_nombre'] ? 'por ' . htmlspecialchars($f['anulada_por_nombre']) : '' ?>.
                            Motivo: <?= htmlspecialchars((string) $f['motivo_anulacion']) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <a class="fc-link" href="<?= BASE_URL ?>/modules/checkout/factura_pdf.php?factura=<?= (int)$f['factura_id'] ?>&amp;volver=facturacion"><i class="fas fa-file-pdf"></i>&nbsp; Ver factura</a>
                <?php if (!$anulada): ?>
                    <?php
                    /* El número va con json_encode: escaparlo solo con
                       htmlspecialchars no sirve dentro de un literal de JS,
                       porque el navegador decodifica el atributo antes. */
                    ?>
                    <button type="button" class="fc-anular"
                            onclick="pedirAnular(<?= (int)$f['factura_id'] ?>, <?= htmlspecialchars(json_encode($f['numero']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($f['razon_social']), ENT_QUOTES) ?>)">
                        Anular
                    </button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div></section>
</div>

<?php /* ── Confirmación de anulación ── */ ?>
<div id="anular-modal-overlay" class="modal-overlay">
    <div class="confirm-card">
        <div class="confirm-header">
            <div class="icon-container-danger"><i class="fas fa-ban"></i></div>
            <h4 class="confirm-title">¿Anular esta factura?</h4>
        </div>
        <form method="POST" id="form-anular">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="anular">
            <input type="hidden" name="factura_id" id="anular-factura-id" value="">
            <div class="confirm-body">
                Vas a anular la <strong id="anular-numero" style="color:var(--cb-forest,#3E2412);"></strong>
                de <strong id="anular-razon" style="color:var(--cb-forest,#3E2412);"></strong>.<br><br>
                <strong>La factura no se borra ni se corrige:</strong> queda registrada como anulada,
                con tu nombre, la fecha y el motivo. La cuenta vuelve a quedar disponible para
                emitir una factura nueva con los datos correctos.<br><br>
                Si ya le entregaste esta factura al huésped, avísale que queda sin efecto.
                <label for="anular-motivo" style="display:block;margin-top:14px;font-size:.8rem;font-weight:700;color:#475569;">
                    Motivo de la anulación
                </label>
                <textarea name="motivo" id="anular-motivo" class="fc-motivo-input" required minlength="10"
                          placeholder="Ej: El NIT quedó mal digitado; se reemite a nombre de Transportes del Magdalena S.A.S."></textarea>
            </div>
            <div class="confirm-actions">
                <button type="button" class="btn-x" onclick="cerrarAnular()">Cancelar</button>
                <button type="submit" class="btn-d">Anular factura</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Anulación de facturas ──
(function () {
    var ov = document.getElementById('anular-modal-overlay');
    if (!ov) return;
    window.pedirAnular = function (id, numero, razon) {
        document.getElementById('anular-factura-id').value = id;
        document.getElementById('anular-numero').textContent = 'factura ' + numero;
        document.getElementById('anular-razon').textContent = razon;
        document.getElementById('anular-motivo').value = '';
        ov.classList.add('active');
        setTimeout(function () { document.getElementById('anular-motivo').focus(); }, 250);
    };
    window.cerrarAnular = function () { ov.classList.remove('active'); };
    ov.addEventListener('click', function (e) { if (e.target === ov) window.cerrarAnular(); });
})();

function actualizarTipoReceptor(){
 const natural=document.querySelector('[name="tipo_receptor"]:checked').value==='natural';
 document.querySelectorAll('.natural').forEach(w=>{w.hidden=!natural;w.querySelectorAll('input,select').forEach(x=>{x.disabled=!natural;x.required=natural})});
 document.querySelectorAll('.juridica').forEach(w=>{w.hidden=natural;w.querySelectorAll('input').forEach(x=>{x.disabled=natural;x.required=!natural})});
}
document.querySelectorAll('[name="tipo_receptor"]').forEach(r=>r.addEventListener('change',actualizarTipoReceptor));actualizarTipoReceptor();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
