<?php
// ============================================================
// modules/checkin/acompanantes.php
// Acompañantes y menores de una reserva — registro en recepción
// ============================================================
// Antes estos datos los capturaba el huésped en el pre-check-in en línea.
// Esa página se retiró, así que ahora los toma el recepcionista en el
// mostrador, que además es donde ve los documentos físicos.
//
// Por qué esto no se podía perder: la tabla `acompanantes` es lo que
// dispara el control de menores del check-in. Si está vacía, el sistema
// deja entrar sin verificar nada. Y registrar a los menores que se
// hospedan, con quién viajan y con qué autorización, es una obligación
// legal del hotel (Ley 1098 de 2006 y Ley 679 de 2001 - ESCNNA), no una
// comodidad.
//
// El documento del menor y los de sus responsables se guardan CIFRADOS,
// igual que los del titular.
// ------------------------------------------------------------
$pageTitle = 'Acompañantes de la reserva';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cifrado.php';
require_once __DIR__ . '/../../includes/validacion.php';
require_once __DIR__ . '/../../includes/volver.php';
requireLogin();

// A dónde vuelve esta pantalla. Tiene DOS orígenes —el check-in y el estado de
// cuenta de un huésped— y el destino estaba escrito a mano, así que desde el
// estado de cuenta te dejaba en Check-in. Por defecto, Check-in: es el camino
// que ya existía y el que no lleva parámetro.
$__volver = volverDestino('checkin');

$pdo       = getDB();
$reservaId = (int) ($_GET['reserva'] ?? $_POST['reserva_id'] ?? 0);
$msg = ''; $msgType = '';

/** ¿La fecha de nacimiento corresponde a un menor de 18 años? */
function esMenorDeEdad(?string $fechaNacimiento): bool {
    if (!$fechaNacimiento) return false;
    try {
        $nac = new DateTime($fechaNacimiento);
    } catch (Throwable $e) {
        return false;
    }
    return $nac->diff(new DateTime())->y < 18;
}

// La reserva se consulta ANTES de atender el POST: hasta 3.0.4 esta consulta
// estaba más abajo, después del bloque, así que al insertar un acompañante el
// código todavía no sabía si la reserva existía ni en qué estado estaba. La
// pantalla escondía el formulario, pero la pantalla no es una validación: en una
// reserva cancelada o incumplida ni siquiera lo escondía.
$stmt = $pdo->prepare("
    select r.id, r.fecha_entrada, r.fecha_salida, r.num_personas, r.estado,
           h.numero, t.nombre as tipo,
           hu.documento_tipo, hu.documento_num, hu.nombres, hu.apellidos, hu.nacionalidad
    from reservas r
    join habitaciones h on h.id = r.habitacion_id
    join tipos_habitacion t on t.id = h.tipo_id
    join huespedes hu on hu.id = r.huesped_id
    where r.id = ?");
$stmt->execute([$reservaId]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
if ($r) $r['documento_num'] = descifrar($r['documento_num']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $msg = 'Token de seguridad inválido. Intente de nuevo.'; $msgType = 'danger';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'agregar' && !$r) {
            $msg = 'Esa reserva no existe.'; $msgType = 'danger';

        } elseif ($accion === 'agregar' && $r['estado'] !== 'confirmada') {
            // Un acompañante —y sobre todo un menor— deja un registro legal
            // (Ley 1098 y Ley 679) asociado a una estadía. Si la reserva está
            // cancelada, incumplida o ya completada, esa estadía no va a
            // ocurrir o ya terminó, y el registro no tendría a qué referirse.
            // Además, un menor añadido a una reserva ajena bloquea su check-in:
            // ver la exigencia de documentos en modules/checkin/index.php.
            $msg = 'No se pueden registrar acompañantes en una reserva '
                 . $r['estado'] . '. Solo en reservas confirmadas.';
            $msgType = 'danger';

        } elseif ($accion === 'agregar') {
            $nombres   = limpiarTexto($_POST['nombres']   ?? '', 100);
            $apellidos = limpiarTexto($_POST['apellidos'] ?? '', 100);
            $docTipo   = $_POST['documento_tipo'] ?? 'CC';
            $docNum    = trim($_POST['documento_num'] ?? '');
            $fechaNac  = trim($_POST['fecha_nacimiento'] ?? '');
            $nacion    = limpiarTexto($_POST['nacionalidad'] ?? 'COLOMBIA', 60) ?: 'COLOMBIA';
            $parent    = limpiarTexto($_POST['parentesco'] ?? '', 40);

            if (!in_array($docTipo, ['RC', 'TI', 'CC', 'CE', 'PAS'], true)) $docTipo = 'CC';

            // La minoría de edad se calcula, no se marca a mano: así no
            // depende de que el recepcionista acierte con la casilla.
            $esMenor = esMenorDeEdad($fechaNac);

            $viajaCon   = $_POST['viaja_con'] ?? null;
            if (!in_array($viajaCon, ['ambos_padres', 'un_padre', 'tercero'], true)) $viajaCon = null;
            $regCivil   = limpiarTexto($_POST['registro_civil'] ?? '', 60);
            $r1Nombre   = limpiarTexto($_POST['responsable1_nombre'] ?? '', 120);
            $r1Doc      = trim($_POST['responsable1_doc'] ?? '');
            $r2Nombre   = limpiarTexto($_POST['responsable2_nombre'] ?? '', 120);
            $r2Doc      = trim($_POST['responsable2_doc'] ?? '');
            $notaria    = limpiarTexto($_POST['autorizacion_notaria'] ?? '', 120);
            $opinion    = !empty($_POST['opinion_considerada']) ? 1 : 0;

            if ($nombres === '' || $apellidos === '') {
                $msg = 'El nombre y los apellidos son obligatorios.'; $msgType = 'danger';
            } elseif ($esMenor && $viajaCon === null) {
                $msg = 'Para un menor hay que indicar con quién viaja.'; $msgType = 'danger';
            } elseif ($esMenor && $viajaCon !== 'ambos_padres' && $notaria === '') {
                // Si no viaja con ambos padres, la autorización notarial no
                // es opcional: es el documento que exige la ley.
                $msg = 'Si el menor no viaja con ambos padres, debe registrarse la notaría de la autorización.'; $msgType = 'danger';
            } else {
                try {
                    $consTxt = null;
                    if ($esMenor) {
                        $acu = $r1Nombre ?: 'el acudiente';
                        $consTxt = 'Registrado en recepción por ' . getCurrentUserName()
                                 . '; acudiente declarado: ' . $acu . '. '
                                 . 'Política de menores y advertencia Ley 679 aceptadas a las '
                                 . date('H:i \d\e\l d/m/Y');
                    }
                    $pdo->prepare("insert into acompanantes
                        (reserva_id, documento_tipo, documento_num, documento_hash, nombres, apellidos,
                         fecha_nacimiento, es_menor, nacionalidad, parentesco, registro_civil, viaja_con,
                         responsable1_doc, responsable1_nombre, responsable2_doc, responsable2_nombre,
                         autorizacion_notarial, autorizacion_notaria, opinion_considerada, consentimiento_texto)
                        values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([
                            $reservaId, $docTipo,
                            $docNum !== '' ? cifrar($docNum) : null,
                            $docNum !== '' ? indiceCiego($docNum) : null,
                            $nombres, $apellidos,
                            $fechaNac ?: null, $esMenor ? 1 : 0, $nacion,
                            $parent ?: null, $regCivil ?: null, $esMenor ? $viajaCon : null,
                            $r1Doc !== '' ? cifrar($r1Doc) : null, $r1Nombre ?: null,
                            $r2Doc !== '' ? cifrar($r2Doc) : null, $r2Nombre ?: null,
                            $notaria !== '' ? 1 : 0, $notaria ?: null,
                            $opinion, $consTxt,
                        ]);

                    registrarAuditoria($pdo, 'acompanantes', $reservaId, 'registrar_acompanante',
                        ($esMenor ? 'MENOR' : 'Acompañante') . " registrado en recepción para la reserva #$reservaId: $nombres $apellidos");

                    $msg = ($esMenor ? 'Menor' : 'Acompañante') . ' registrado correctamente.'; $msgType = 'success';
                } catch (Throwable $e) {
                    error_log('Acompañantes: ' . $e->getMessage());
                    $msg = 'No se pudo registrar el acompañante.'; $msgType = 'danger';
                }
            }

        } elseif ($accion === 'quitar') {
            // Solo mientras la reserva no haya entrado: después forma parte
            // del registro de la estadía y no se toca.
            $st = $pdo->prepare("delete from acompanantes where id = ? and reserva_id = ? and estancia_id is null");
            $st->execute([(int) ($_POST['acomp_id'] ?? 0), $reservaId]);
            if ($st->rowCount() > 0) {
                registrarAuditoria($pdo, 'acompanantes', $reservaId, 'quitar_acompanante',
                    'Quitó un acompañante de la reserva #' . $reservaId);
                $msg = 'Acompañante retirado.'; $msgType = 'success';
            } else {
                $msg = 'No se pudo retirar: la estadía ya está registrada.'; $msgType = 'warning';
            }
        }
    }
}

// $r ya se consultó arriba, antes del POST. La lista de acompañantes, en
// cambio, se consulta AQUÍ a propósito: tiene que reflejar el alta o la baja
// que acaba de ocurrir.
$acomp = [];
if ($r) {
    $stmtA = $pdo->prepare("select * from acompanantes where reserva_id = ? order by es_menor desc, id");
    $stmtA->execute([$reservaId]);
    $acomp = $stmtA->fetchAll(PDO::FETCH_ASSOC);
    foreach ($acomp as &$a) {
        $a['documento_num']    = descifrar($a['documento_num']);
        $a['responsable1_doc'] = descifrar($a['responsable1_doc']);
        $a['responsable2_doc'] = descifrar($a['responsable2_doc']);
    }
    unset($a);
}

$viajaTxt = ['ambos_padres' => 'Con ambos padres', 'un_padre' => 'Con un solo padre/madre', 'tercero' => 'Con un tercero'];
$menores  = count(array_filter($acomp, fn($a) => (int) $a['es_menor'] === 1));

require_once __DIR__ . '/../../includes/header.php';
?>
<style>
.pc-wrap { padding-bottom: 90px; max-width: 760px; }
.pc-back { display:inline-flex; align-items:center; gap:7px; color:var(--cb-primary,#6B4420); text-decoration:none; font-weight:600; font-size:.85rem; margin-bottom:12px; }
.pc-card { background:#fff; border:1px solid var(--cb-border,#EDE3D2); border-radius:16px; box-shadow:0 6px 18px rgba(42,23,9,.05); margin-bottom:16px; overflow:hidden; }
.pc-card-b { padding:16px; }
.pc-title { font-size:.72rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:#A2907C; margin:0 0 12px; display:flex; align-items:center; gap:7px; }
.pc-head { display:flex; align-items:center; gap:13px; padding:16px; background:linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color:#fff; }
.pc-av { width:48px; height:48px; border-radius:13px; background:rgba(255,255,255,.18); display:flex; align-items:center; justify-content:center; font-weight:700; }
.pc-head .n { font-size:1.1rem; font-weight:800; }
.pc-head .d { font-size:.8rem; opacity:.9; margin-top:2px; }
.pc-kv { display:flex; justify-content:space-between; gap:12px; padding:8px 0; border-bottom:1px solid #F1EADF; font-size:.86rem; }
.pc-kv:last-child { border-bottom:none; }
.pc-kv .k { color:var(--cb-muted,#9A8778); } .pc-kv .v { font-weight:600; color:var(--cb-forest,#3E2412); text-align:right; }
.pc-badge { display:inline-flex; align-items:center; gap:6px; font-size:.72rem; font-weight:700; padding:4px 11px; border-radius:99px; }
.pc-badge.no { background:#fff3e8; color:#b45309; }
.pc-menor { border:1px solid #F3EADD; border-radius:14px; padding:13px; margin-bottom:11px; }
.pc-menor.menor { border-color:#f4caca; background:#fffafa; }
.pc-menor .top { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px; }
.pc-menor .nm { font-weight:800; color:var(--cb-forest,#3E2412); }
.pc-alert { background:#fdeaea; border:1px solid rgba(220,38,38,.25); color:#b91c1c; border-radius:10px; padding:9px 12px; font-size:.78rem; margin-top:8px; line-height:1.5; }
.pc-consent { background:#f6fbf4; border:1px dashed var(--cb-border,#EDE3D2); border-radius:10px; padding:9px 12px; font-size:.76rem; color:#556; margin-top:8px; font-style:italic; }
.pc-empty { text-align:center; padding:22px 12px; color:var(--cb-muted,#9A8778); }
.pc-empty i { font-size:2rem; color:var(--cb-primary,#6B4420); opacity:.4; margin-bottom:10px; display:block; }
.pc-msg { padding:12px 15px; border-radius:12px; margin-bottom:14px; font-size:.88rem; }
.pc-msg.success { background:#e6f7f2; color:#047857; border:1px solid rgba(31,122,77,.25); }
.pc-msg.danger  { background:#fdeded; color:#b91c1c; border:1px solid rgba(239,68,68,.25); }
.pc-msg.warning { background:#fff3e8; color:#b45309; border:1px solid rgba(249,115,22,.25); }
.pc-grid { display:grid; grid-template-columns:1fr 1fr; gap:11px; }
.pc-f { margin-bottom:11px; }
.pc-f label { display:block; font-size:.76rem; font-weight:700; color:var(--cb-muted,#9A8778); margin-bottom:5px; }
.pc-f input, .pc-f select { width:100%; padding:10px 12px; border:1.5px solid var(--cb-border,#EDE3D2); border-radius:10px; font-family:inherit; font-size:.88rem; background:#fff; }
.pc-f input:focus, .pc-f select:focus { outline:0; border-color:var(--cb-primary,#6B4420); }
.pc-sub { border:1px solid #f4caca; background:#fffafa; border-radius:12px; padding:13px; margin-top:4px; }
.pc-sub .t { font-size:.8rem; font-weight:800; color:#b91c1c; margin-bottom:10px; display:flex; align-items:center; gap:7px; }
.pc-btn { border:none; border-radius:11px; padding:12px 18px; font-family:inherit; font-weight:700; font-size:.88rem; cursor:pointer; color:#fff; background:linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); }
.pc-btn.mini { background:#fdeded; color:#b91c1c; border:1px solid rgba(239,68,68,.25); padding:6px 11px; font-size:.75rem; border-radius:9px; }
.pc-nota { font-size:.78rem; color:var(--cb-muted,#9A8778); line-height:1.6; }
@media(max-width:560px){ .pc-grid { grid-template-columns:1fr; } }
</style>

<div class="pc-wrap">
<a href="<?= htmlspecialchars($__volver['url']) ?>" class="pc-back"><i class="fas fa-arrow-left"></i> Volver a <?= htmlspecialchars($__volver['etiqueta']) ?></a>

<?php if ($msg !== ''): ?>
    <div class="pc-msg <?= htmlspecialchars($msgType) ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php if (!$r): ?>
    <div class="pc-card"><div class="pc-empty"><i class="fas fa-triangle-exclamation"></i>
        <p>No se encontró esa reserva.</p></div></div>
<?php else: ?>

    <div class="pc-card">
        <div class="pc-head">
            <div class="pc-av"><?= strtoupper(mb_substr($r['nombres'],0,1) . mb_substr($r['apellidos'],0,1)) ?></div>
            <div>
                <div class="n"><?= htmlspecialchars($r['nombres'] . ' ' . $r['apellidos']) ?></div>
                <div class="d">Reserva #<?= (int) $r['id'] ?> ·
                    Hab. <?= htmlspecialchars($r['numero']) ?> ·
                    <?= (int) $r['num_personas'] ?> persona(s)</div>
            </div>
        </div>
        <div class="pc-card-b">
            <div class="pc-kv"><span class="k">Titular</span><span class="v"><?= htmlspecialchars($r['documento_tipo'] . ' ' . ($r['documento_num'] ?: '—')) ?></span></div>
            <div class="pc-kv"><span class="k">Entrada</span><span class="v"><?= date('d/m/Y', strtotime($r['fecha_entrada'])) ?></span></div>
            <div class="pc-kv"><span class="k">Salida</span><span class="v"><?= date('d/m/Y', strtotime($r['fecha_salida'])) ?></span></div>
        </div>
    </div>

    <?php /* ── Los que ya están registrados ── */ ?>
    <div class="pc-card">
        <div class="pc-card-b">
            <div class="pc-title"><i class="fas fa-users"></i> Registrados<?= $acomp ? ' (' . count($acomp) . ')' : '' ?></div>

            <?php if (empty($acomp)): ?>
                <div class="pc-empty"><i class="fas fa-user"></i>
                    <p>Todavía no hay acompañantes registrados en esta reserva.</p></div>
            <?php else: ?>
                <?php foreach ($acomp as $a): $esMenor = (int) $a['es_menor'] === 1; ?>
                    <div class="pc-menor <?= $esMenor ? 'menor' : '' ?>">
                        <div class="top">
                            <span class="nm"><?= htmlspecialchars($a['nombres'] . ' ' . $a['apellidos']) ?></span>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <?php if ($esMenor): ?><span class="pc-badge no"><i class="fas fa-child"></i> MENOR</span><?php endif; ?>
                                <?php if ($a['estancia_id'] === null): ?>
                                    <form method="POST" style="margin:0;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="accion" value="quitar">
                                        <input type="hidden" name="reserva_id" value="<?= (int) $reservaId ?>">
                                        <input type="hidden" name="acomp_id" value="<?= (int) $a['id'] ?>">
                                        <button type="submit" class="pc-btn mini">Quitar</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="pc-kv"><span class="k">Documento</span><span class="v"><?= htmlspecialchars($a['documento_tipo'] . ' ' . ($a['documento_num'] ?: '—')) ?></span></div>
                        <?php if ($a['fecha_nacimiento']): ?><div class="pc-kv"><span class="k">Nacimiento</span><span class="v"><?= date('d/m/Y', strtotime($a['fecha_nacimiento'])) ?></span></div><?php endif; ?>
                        <?php if ($a['parentesco']): ?><div class="pc-kv"><span class="k">Parentesco</span><span class="v"><?= htmlspecialchars($a['parentesco']) ?></span></div><?php endif; ?>

                        <?php if ($esMenor): ?>
                            <div class="pc-kv"><span class="k">Registro Civil</span><span class="v"><?= htmlspecialchars($a['registro_civil'] ?: '⚠ Falta') ?></span></div>
                            <div class="pc-kv"><span class="k">Viaja</span><span class="v"><?= htmlspecialchars($viajaTxt[$a['viaja_con']] ?? '—') ?></span></div>
                            <?php if ($a['viaja_con'] === 'ambos_padres'): ?>
                                <div class="pc-kv"><span class="k">Padre/Madre 1</span><span class="v"><?= htmlspecialchars(($a['responsable1_nombre'] ?: '—') . ($a['responsable1_doc'] ? ' · ' . $a['responsable1_doc'] : '')) ?></span></div>
                                <div class="pc-kv"><span class="k">Padre/Madre 2</span><span class="v"><?= htmlspecialchars(($a['responsable2_nombre'] ?: '—') . ($a['responsable2_doc'] ? ' · ' . $a['responsable2_doc'] : '')) ?></span></div>
                            <?php else: ?>
                                <div class="pc-kv"><span class="k">Autorización notarial</span><span class="v"><?= (int) $a['autorizacion_notarial'] === 1 ? 'Sí' : '⚠ No' ?></span></div>
                                <?php if ($a['autorizacion_notaria']): ?><div class="pc-kv"><span class="k">Notaría</span><span class="v"><?= htmlspecialchars($a['autorizacion_notaria']) ?></span></div><?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($a['consentimiento_texto'])): ?>
                                <div class="pc-consent">"<?= htmlspecialchars($a['consentimiento_texto']) ?>"</div>
                            <?php endif; ?>
                            <div class="pc-alert"><i class="fas fa-triangle-exclamation"></i> Verifica físicamente el <b>Registro Civil</b><?= ($a['viaja_con'] !== 'ambos_padres' && $a['viaja_con'] !== null) ? ' y la <b>autorización notarial</b>' : '' ?> antes de permitir el ingreso (Ley 1098 de 2006 y advertencia Ley 679 - ESCNNA).</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if ($menores > 0): ?>
                    <p class="pc-nota" style="margin-top:10px;">
                        Al registrar la entrada, el sistema te va a exigir confirmar que
                        verificaste los documentos de <b><?= $menores ?> menor(es)</b>.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php /* ── Alta de un acompañante ── */ ?>
    <?php /* Solo en reservas confirmadas, lo MISMO que exige el servidor más
             arriba. Antes era `!== 'completada'`, que dejaba el formulario a la
             vista en reservas canceladas e incumplidas. La pantalla y el
             servidor tienen que decir lo mismo. */ ?>
    <?php if ($r['estado'] === 'confirmada'): ?>
    <div class="pc-card">
        <div class="pc-card-b">
            <div class="pc-title"><i class="fas fa-user-plus"></i> Agregar acompañante</div>

            <form method="POST" id="form-acomp">
                <?= csrfField() ?>
                <input type="hidden" name="accion" value="agregar">
                <input type="hidden" name="reserva_id" value="<?= (int) $reservaId ?>">

                <div class="pc-grid">
                    <div class="pc-f"><label for="ac-nom">Nombres</label>
                        <input type="text" id="ac-nom" name="nombres" required maxlength="100"></div>
                    <div class="pc-f"><label for="ac-ape">Apellidos</label>
                        <input type="text" id="ac-ape" name="apellidos" required maxlength="100"></div>
                </div>

                <div class="pc-grid">
                    <div class="pc-f"><label for="ac-dt">Tipo de documento</label>
                        <select id="ac-dt" name="documento_tipo">
                            <option value="CC">Cédula de ciudadanía</option>
                            <option value="TI">Tarjeta de identidad</option>
                            <option value="RC">Registro civil</option>
                            <option value="CE">Cédula de extranjería</option>
                            <option value="PAS">Pasaporte</option>
                        </select></div>
                    <div class="pc-f"><label for="ac-dn">Número de documento</label>
                        <input type="text" id="ac-dn" name="documento_num" maxlength="30"></div>
                </div>

                <div class="pc-grid">
                    <div class="pc-f"><label for="ac-fn">Fecha de nacimiento</label>
                        <input type="date" id="ac-fn" name="fecha_nacimiento" max="<?= date('Y-m-d') ?>">
                        <p class="pc-nota" style="margin:5px 0 0;">Si es menor de 18, el sistema lo detecta solo y pide los datos de ley.</p></div>
                    <div class="pc-f"><label for="ac-pa">Parentesco con el titular</label>
                        <input type="text" id="ac-pa" name="parentesco" maxlength="40" placeholder="Hijo, cónyuge, sobrino…"></div>
                </div>

                <div class="pc-f"><label for="ac-na">Nacionalidad</label>
                    <input type="text" id="ac-na" name="nacionalidad" maxlength="60" value="COLOMBIA"></div>

                <?php /* Este bloque aparece solo si la fecha indica un menor */ ?>
                <div class="pc-sub" id="bloque-menor" style="display:none;">
                    <div class="t"><i class="fas fa-child"></i> Es menor de edad — datos exigidos por la ley</div>

                    <div class="pc-f"><label for="ac-rc">Número de Registro Civil</label>
                        <input type="text" id="ac-rc" name="registro_civil" maxlength="60"></div>

                    <div class="pc-f"><label for="ac-vc">¿Con quién viaja?</label>
                        <select id="ac-vc" name="viaja_con">
                            <option value="">Seleccione…</option>
                            <option value="ambos_padres">Con ambos padres</option>
                            <option value="un_padre">Con un solo padre o madre</option>
                            <option value="tercero">Con un tercero</option>
                        </select></div>

                    <div class="pc-grid">
                        <div class="pc-f"><label for="ac-r1n">Responsable 1 — nombre</label>
                            <input type="text" id="ac-r1n" name="responsable1_nombre" maxlength="120"></div>
                        <div class="pc-f"><label for="ac-r1d">Responsable 1 — documento</label>
                            <input type="text" id="ac-r1d" name="responsable1_doc" maxlength="30"></div>
                    </div>

                    <div class="pc-grid" id="bloque-r2">
                        <div class="pc-f"><label for="ac-r2n">Responsable 2 — nombre</label>
                            <input type="text" id="ac-r2n" name="responsable2_nombre" maxlength="120"></div>
                        <div class="pc-f"><label for="ac-r2d">Responsable 2 — documento</label>
                            <input type="text" id="ac-r2d" name="responsable2_doc" maxlength="30"></div>
                    </div>

                    <div class="pc-f" id="bloque-notaria" style="display:none;">
                        <label for="ac-no">Notaría de la autorización</label>
                        <input type="text" id="ac-no" name="autorizacion_notaria" maxlength="120" placeholder="Notaría 3 de Cartagena, 12/10/2026">
                        <p class="pc-nota" style="margin:5px 0 0;">Obligatorio cuando el menor no viaja con ambos padres.</p>
                    </div>

                    <label style="display:flex; gap:9px; align-items:flex-start; font-size:.78rem; color:#556; cursor:pointer; line-height:1.5;">
                        <input type="checkbox" name="opinion_considerada" value="1" style="margin-top:2px; width:16px; height:16px; flex-shrink:0;">
                        <span>Se tuvo en cuenta la opinión del menor sobre su hospedaje (Código de Infancia y Adolescencia).</span>
                    </label>

                    <p class="pc-nota" style="margin-top:10px;">
                        Consulta la <a href="<?= BASE_URL ?>/politica-menores.php<?= volverDesdeAqui() ?>" target="_blank">política de menores</a>
                        y el <a href="<?= BASE_URL ?>/autorizacion-menor.php<?= volverDesdeAqui() ?>" target="_blank">modelo de autorización</a>.
                    </p>
                </div>

                <button type="submit" class="pc-btn"><i class="fas fa-plus"></i> Registrar acompañante</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>
</div>

<script>
// Muestra los campos de ley solo cuando la fecha de nacimiento indica un
// menor de 18, y pide la notaría solo si no viaja con ambos padres.
(function () {
    var fecha   = document.getElementById('ac-fn');
    var bloque  = document.getElementById('bloque-menor');
    var viaja   = document.getElementById('ac-vc');
    var notaria = document.getElementById('bloque-notaria');
    var r2      = document.getElementById('bloque-r2');
    if (!fecha || !bloque) return;

    function esMenor(v) {
        if (!v) return false;
        var n = new Date(v + 'T00:00:00');
        if (isNaN(n)) return false;
        var limite = new Date();
        limite.setFullYear(limite.getFullYear() - 18);
        return n > limite;
    }
    function refrescar() {
        var menor = esMenor(fecha.value);
        bloque.style.display = menor ? 'block' : 'none';
        if (viaja) viaja.required = menor;

        var conAmbos = viaja && viaja.value === 'ambos_padres';
        if (notaria) notaria.style.display = (menor && viaja && viaja.value && !conAmbos) ? 'block' : 'none';
        if (r2)      r2.style.display      = (menor && conAmbos) ? 'grid' : 'none';
        var campoNot = document.getElementById('ac-no');
        if (campoNot) campoNot.required = (menor && viaja && viaja.value !== '' && !conAmbos);
    }
    fecha.addEventListener('change', refrescar);
    fecha.addEventListener('input', refrescar);
    if (viaja) viaja.addEventListener('change', refrescar);
    refrescar();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
