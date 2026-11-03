<?php
// Módulo: Check-in
$pageTitle = 'Check-in';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/validacion.php';
require_once __DIR__ . '/../../includes/cifrado.php';
require_once __DIR__ . '/../../includes/tarifas.php';   // el precio lo manda el tipo
require_once __DIR__ . '/../../includes/disponibilidad.php';   // bloquearHabitacion()
requireLogin();

$pdo = getDB();
$msg = '';
$msgType = '';

/** Adds any unpaid no-show night charges to the guest's next stay. */
function aplicarPendientesInasistencia(PDO $pdo, int $huespedId, int $estanciaId): void {
    $stmt = $pdo->prepare("select id, pendiente_noche from reservas where huesped_id=? and estado='incumplida' and pendiente_noche>0 order by fecha_entrada for update");
    $stmt->execute([$huespedId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $reservaPendiente) {
        $monto = (float)$reservaPendiente['pendiente_noche'];
        if ($monto <= 0) continue;
        $detalle = 'Noche pendiente por inasistencia · reserva #' . (int)$reservaPendiente['id'];
        $pdo->prepare('insert into consumos (estancia_id, producto_id, descripcion_manual, cantidad, precio_unit, subtotal, usuario_id) values (?, null, ?, 1, ?, ?, ?)')
            ->execute([$estanciaId, $detalle, $monto, $monto, getCurrentUserId()]);
        $pdo->prepare('update estancias set total_consumos=total_consumos+? where id=?')->execute([$monto, $estanciaId]);
        $pdo->prepare('update reservas set pendiente_noche=0 where id=?')->execute([(int)$reservaPendiente['id']]);
        registrarAuditoria($pdo, 'reservas', (int)$reservaPendiente['id'], 'cobro_inasistencia_aplicado', "Se cargó $" . number_format($monto, 0, ',', '.') . " a la estancia #$estanciaId");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'checkin') {
    if (!validateCsrf()) {
        $msg = 'Token de seguridad inválido. Intente de nuevo.';
        $msgType = 'danger';
    } else {
        $tipo = $_POST['tipo_checkin'];

        // ── Exención de IVA a extranjeros (Art. 481 lit. e) E.T.) ──
        // Casilla opcional; si se marca, exige pasaporte y sello migratorio.
        $ivaExento       = isset($_POST['iva_exento']) && $_POST['iva_exento'] === '1';
        $pasaporteNum    = limpiarTexto($_POST['pasaporte_num'] ?? '', 40);
        $selloMigratorio = limpiarTexto($_POST['sello_migratorio'] ?? '', 40);
        $pasaporteVal    = $ivaExento ? cifrar($pasaporteNum) : null;   // cifrado en reposo
        $selloVal        = $ivaExento ? $selloMigratorio : null;

        if ($ivaExento && ($pasaporteNum === '' || $selloMigratorio === '')) {
            $msg = 'Para aplicar la exención de IVA (Art. 481) debes registrar el número de pasaporte y el código del sello migratorio (PT).';
            $msgType = 'danger';
        } elseif ($tipo === 'con_reserva') {
            $reservaId = (int)$_POST['reserva_id'];

            // La transacción abre ANTES de leer, y la habitación se bloquea en
            // cuanto se sabe cuál es. Antes la transacción empezaba después de
            // todas las comprobaciones, así que dos recepcionistas registrando
            // la entrada de la MISMA reserva leían los dos «sin estancia
            // activa» y los dos insertaban: dos estancias para una reserva, el
            // abono cobrado dos veces y la habitación liquidable dos veces en
            // el check-out. Ver bloquearHabitacion() en
            // includes/disponibilidad.php.
            //
            // Las ramas de rechazo no escriben nada, así que el rollBack va una
            // sola vez al final; el commit del camino bueno deja la transacción
            // cerrada y ese rollBack no se ejecuta.
            $pdo->beginTransaction();
            try {
                // Se traen las tres tarifas del tipo, no un precio ya hecho: el que
                // se cobra depende de cuánta gente duerma, y eso se resuelve abajo
                // con tarifaPorPersonas().
                $stmtR = $pdo->prepare("select r.id, r.habitacion_id, r.huesped_id, r.abono_reserva,
                                               r.metodo_abono, r.num_personas,
                                               t.nombre, t.tarifa_1, t.tarifa_2, t.tarifa_extra, t.tarifa_base,
                                               " . SQL_CAPACIDAD . " as capacidad_max
                                        from reservas r
                                        join habitaciones h on h.id = r.habitacion_id
                                        left join tipos_habitacion t on t.id = h.tipo_id
                                        where r.id = ? and r.estado = 'confirmada'");
                $stmtR->execute([$reservaId]);
                $reserva = $stmtR->fetch();

                // El bloqueo va aquí: esta consulta lleva un join con
                // tipos_habitacion, y un `for update` sobre ella bloquearía
                // también la fila del tipo, poniendo en fila todas las entradas
                // de habitaciones del mismo tipo.
                if ($reserva) bloquearHabitacion($pdo, (int) $reserva['habitacion_id']);

                // Recepción puede corregir la ocupación al registrar la entrada: si
                // la reserva decía dos y llegaron tres, lo que vale es lo que llegó.
                // Si no manda el campo, se queda con lo que dijo la reserva.
                $personasReserva = personasDelFormulario(max(1, (int) ($reserva['num_personas'] ?? 1)));
                $tarifaReserva   = $reserva ? tarifaPorPersonas($reserva, $personasReserva) : null;
                $motivoReserva   = $reserva ? motivoOcupacionInvalida($reserva, $personasReserva) : null;

                if (!$reserva) {
                    $msg = 'Reserva no encontrada o ya procesada.';
                    $msgType = 'danger';
                } else {
                    // Estas dos lecturas son las que importan, y van DESPUÉS del
                    // bloqueo: quien llegue segundo espera aquí y entonces ve el
                    // mundo que dejó el primero.
                    $stmtH = $pdo->prepare("select estado from habitaciones where id = ?");
                    $stmtH->execute([$reserva['habitacion_id']]);
                    $estadoHab = $stmtH->fetchColumn();
                    // Una reserva online deja la habitación en 'ocupada' (regla de
                    // negocio para retirarla del listado público). Por eso el check-in
                    // CON reserva no exige 'disponible': solo se bloquea si la
                    // habitación está en mantenimiento o si ya hay un huésped físico
                    // hospedado (estancia activa).
                    $stmtAct = $pdo->prepare("select count(*) from estancias where habitacion_id = ? and estado = 'activa'");
                    $stmtAct->execute([$reserva['habitacion_id']]);
                    $tieneEstanciaActiva = $stmtAct->fetchColumn() > 0;

                    // Menores registrados para esta reserva (exigen verificación de documentos)
                    $stmtMen = $pdo->prepare("select count(*) from acompanantes where reserva_id = ? and es_menor = 1");
                    $stmtMen->execute([$reservaId]);
                    $numMenores = (int) $stmtMen->fetchColumn();

                    if ($estadoHab === 'mantenimiento') {
                        $msg = 'La habitación está en mantenimiento y no puede recibir huéspedes.';
                        $msgType = 'danger';
                    } elseif ($tieneEstanciaActiva) {
                        $msg = 'La habitación ya tiene un huésped hospedado actualmente.';
                        $msgType = 'danger';
                    } elseif ($motivoReserva !== null) {
                        // La ocupación no cabe en esa habitación, o el tipo no tiene
                        // tarifa para ese número de personas.
                        $msg = $motivoReserva;
                        $msgType = 'danger';
                    } elseif (!tarifaCobrable($tarifaReserva)) {
                        // Una estancia abierta con tarifa 0 cobra 0 hospedaje toda la
                        // estadía: el número se congela aquí en estancias.tarifa_noche.
                        // Ver includes/tarifas.php.
                        $msg = 'Esa habitación no tiene una tarifa válida, así que la estancia se abriría cobrando cero. Pídele al administrador que fije la tarifa del tipo en Habitaciones antes de registrar la entrada.';
                        $msgType = 'danger';
                    } elseif ($numMenores > 0 && empty($_POST['docs_menores_ok'])) {
                        $msg = 'Antes de registrar la entrada debes confirmar que verificaste los documentos de los menores (Registro Civil y, si aplica, la autorización notarial).';
                        $msgType = 'danger';
                    } else {
                        // La reserva se cierra PRIMERO, exigiendo que siga
                        // confirmada. Si otra entrada se adelantó, esto afecta 0
                        // filas y no se abre ninguna estancia: es la segunda
                        // defensa, por si algún día alguien escribe aquí sin
                        // pedir el bloqueo.
                        $updR = $pdo->prepare("update reservas set estado = 'completada', num_personas = ? where id = ? and estado = 'confirmada'");
                        $updR->execute([$personasReserva, $reservaId]);

                        if ($updR->rowCount() !== 1) {
                            $msg = 'Esa reserva ya fue procesada por otra persona.';
                            $msgType = 'danger';
                        } else {
                            // `tarifa_noche` guarda el precio YA RESUELTO para esta
                            // ocupación, y `num_personas` deja constancia de cuál
                            // era, para que el cobro se pueda explicar después.
                            $pdo->prepare("insert into estancias (reserva_id, habitacion_id, huesped_id, num_personas, tarifa_noche, usuario_checkin, iva_exento, pasaporte_num, sello_migratorio) values (?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([$reserva['id'], $reserva['habitacion_id'], $reserva['huesped_id'], $personasReserva, $tarifaReserva, getCurrentUserId(), $ivaExento ? 1 : 0, $pasaporteVal, $selloVal]);
                            $estanciaId = $pdo->lastInsertId();
                            if ((float)$reserva['abono_reserva'] > 0) {
                                $pdo->prepare("insert into pagos (estancia_id,monto,metodo,nota,usuario_id) values (?,?,?,'Abono recibido al reservar',?)")
                                    ->execute([$estanciaId, $reserva['abono_reserva'], $reserva['metodo_abono'] ?: 'efectivo', getCurrentUserId()]);
                            }
                            aplicarPendientesInasistencia($pdo, (int)$reserva['huesped_id'], (int)$estanciaId);
                            // Enlazar los acompañantes registrados a esta estancia
                            $pdo->prepare("update acompanantes set estancia_id = ? where reserva_id = ?")->execute([$estanciaId, $reservaId]);
                            // Registrar que se verificaron los documentos de los menores
                            if ($numMenores > 0) {
                                $pdo->prepare("update acompanantes set docs_ok = 1, docs_ok_por = ?, docs_ok_en = now() where reserva_id = ? and es_menor = 1")
                                    ->execute([getCurrentUserId(), $reservaId]);
                            }
                            $pdo->prepare("update habitaciones set estado = 'ocupada' where id = ?")->execute([$reserva['habitacion_id']]);
                            $pdo->commit();
                            registrarAuditoria($pdo, 'estancias', $estanciaId, 'checkin', "Check-in reserva #$reservaId · " . detalleOcupacion($personasReserva, $reserva) . ' · ' . number_format((float) $tarifaReserva, 0, ',', '.') . '/noche' . ($numMenores > 0 ? " (con $numMenores menor(es), documentos verificados)" : ""));
                            $msg = "Check-in exitoso. Estancia #$estanciaId";
                            $msgType = 'success';
                        }
                    }
                }

                // Ninguna rama de rechazo escribió: se suelta el bloqueo.
                if ($pdo->inTransaction()) $pdo->rollBack();

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Check-in con reserva: ' . $e->getMessage());
                $msg = 'Error en check-in.';
                $msgType = 'danger';
            }
        }
        elseif ($tipo === 'sin_reserva') {
            $habitacionId = (int)$_POST['habitacion_id'];
            $modo         = ($_POST['huesped_modo'] ?? 'existente') === 'nuevo' ? 'nuevo' : 'existente';

            // Igual que el camino con reserva: transacción y bloqueo antes de
            // leer el estado. Aquí la habitación llega del formulario, así que se
            // puede bloquear de entrada. Antes, dos entradas de mostrador
            // simultáneas sobre la misma habitación leían las dos 'disponible' y
            // abrían DOS estancias activas con huéspedes distintos: una
            // habitación con dos huéspedes, cada uno con su tarifa y su factura.
            $pdo->beginTransaction();
            try {
            bloquearHabitacion($pdo, $habitacionId);

            $stmtH = $pdo->prepare("select h.estado,
                                           t.nombre, t.tarifa_1, t.tarifa_2, t.tarifa_extra, t.tarifa_base,
                                           " . SQL_CAPACIDAD . " as capacidad_max
                                    from habitaciones h
                                    left join tipos_habitacion t on t.id = h.tipo_id
                                    where h.id = ?");
            $stmtH->execute([$habitacionId]);
            $habData = $stmtH->fetch();

            // Cuántas personas se van a hospedar. En el mostrador no hay reserva
            // de la que deducirlo, así que el formulario lo pregunta; si no viene,
            // se asume una.
            $personasWalkin = personasDelFormulario(1);
            $tarifaWalkin   = $habData ? tarifaPorPersonas($habData, $personasWalkin) : null;
            $motivoWalkin   = $habData ? motivoOcupacionInvalida($habData, $personasWalkin) : null;

            // Modo "nuevo": registrar al huésped en el mismo paso del ingreso (walk-in).
            $huespedId = 0; $errNuevo = '';
            if ($modo === 'nuevo') {
                $docTipo   = in_array(($_POST['n_documento_tipo'] ?? ''), ['CC','CE','PAS','NIT'], true) ? $_POST['n_documento_tipo'] : 'CC';
                $docNum    = validarDocumento($_POST['n_documento_num'] ?? '');
                $nombres   = validarNombre($_POST['n_nombres'] ?? '');
                $apellidos = validarNombre($_POST['n_apellidos'] ?? '');
                $telRaw    = trim($_POST['n_telefono'] ?? '');
                $telefono  = $telRaw === '' ? '' : validarTelefono($telRaw);
                // El correo faltaba en este formulario, así que un huésped
                // registrado aquí nunca podía recibir su factura: el sistema le
                // pedía el consentimiento de datos y luego no tenía a dónde
                // mandársela. Opcional, como el teléfono.
                $mailRaw   = trim($_POST['n_email'] ?? '');
                $email     = $mailRaw === '' ? '' : validarEmail($mailRaw);
                $habeas    = isset($_POST['n_habeas_data']) && $_POST['n_habeas_data'] === '1';

                if ($docNum === null)                             $errNuevo = 'El documento del nuevo huésped no es válido.';
                elseif ($nombres === null || $apellidos === null) $errNuevo = 'Nombres y apellidos del nuevo huésped no son válidos.';
                elseif ($telRaw !== '' && $telefono === null)     $errNuevo = 'El teléfono del nuevo huésped no es válido.';
                elseif ($mailRaw !== '' && $email === null)       $errNuevo = 'El correo del nuevo huésped no es válido.';
                elseif (!$habeas)                                 $errNuevo = 'Debes registrar el consentimiento de datos (Habeas Data) del huésped.';
            } else {
                $huespedId = (int)($_POST['huesped_id'] ?? 0);
            }

            if (!$habData || $habData['estado'] !== 'disponible') {
                $msg = 'Habitación no disponible.'; $msgType = 'danger';
            } elseif ($motivoWalkin !== null) {
                $msg = $motivoWalkin; $msgType = 'danger';
            } elseif (!tarifaCobrable($tarifaWalkin)) {
                $msg = 'Esa habitación no tiene una tarifa válida, así que la estancia se abriría cobrando cero. Pídele al administrador que fije la tarifa del tipo en Habitaciones antes de registrar la entrada.'; $msgType = 'danger';
            } elseif ($errNuevo !== '') {
                $msg = $errNuevo; $msgType = 'danger';
            } elseif ($modo === 'existente' && $huespedId < 1) {
                $msg = 'Selecciona el huésped o registra uno nuevo.'; $msgType = 'danger';
            } else {
                {
                    // Crear (o reutilizar por documento) el huésped nuevo, en la misma transacción
                    if ($modo === 'nuevo') {
                        $st = $pdo->prepare("select id from huespedes where documento_hash = ?");
                        $st->execute([indiceCiego($docNum)]);
                        $huespedId = (int)$st->fetchColumn();
                        if (!$huespedId) {
                            $pdo->prepare("insert into huespedes (documento_tipo, documento_num, documento_hash, nombres, apellidos, telefono, email, habeas_data, habeas_data_fecha, habeas_data_origen) values (?,?,?,?,?,?,?,1,now(),'checkin')")
                                ->execute([$docTipo, cifrar($docNum), indiceCiego($docNum), $nombres, $apellidos, ($telefono !== '' && $telefono !== null ? cifrar($telefono) : null), ($email !== '' && $email !== null ? $email : null)]);
                            $huespedId = (int)$pdo->lastInsertId();
                        } else {
                            // Conductor recurrente: reafirma el consentimiento, no duplica.
                            $pdo->prepare("update huespedes set habeas_data=1, habeas_data_fecha=now(), habeas_data_origen='checkin' where id=?")->execute([$huespedId]);
                            // Si vuelve y ahora deja su correo, se guarda SOLO si
                            // no tenía ninguno. No se pisa el que ya estuviera:
                            // ese pudo darlo él mismo o venir de otro trámite.
                            if ($email !== '' && $email !== null) {
                                $pdo->prepare("update huespedes set email = ?
                                               where id = ? and (email is null or email = '')")
                                    ->execute([$email, $huespedId]);
                            }
                        }
                    }
                    // Precio ya resuelto para esta ocupación, y la ocupación
                    // guardada al lado para que el cobro sea explicable.
                    $pdo->prepare("insert into estancias (habitacion_id, huesped_id, num_personas, tarifa_noche, usuario_checkin, iva_exento, pasaporte_num, sello_migratorio) values (?, ?, ?, ?, ?, ?, ?, ?)")->execute([$habitacionId, $huespedId, $personasWalkin, $tarifaWalkin, getCurrentUserId(), $ivaExento ? 1 : 0, $pasaporteVal, $selloVal]);
                    $estanciaId = $pdo->lastInsertId();
                    aplicarPendientesInasistencia($pdo, (int)$huespedId, (int)$estanciaId);
                    $pdo->prepare("update habitaciones set estado = 'ocupada' where id = ?")->execute([$habitacionId]);
                    $pdo->commit();
                    registrarAuditoria($pdo, 'estancias', $estanciaId, 'checkin', 'Check-in directo (walk-in) · ' . detalleOcupacion($personasWalkin, $habData) . ' · ' . number_format((float) $tarifaWalkin, 0, ',', '.') . '/noche' . ($modo === 'nuevo' ? ', huésped registrado en el ingreso' : ''));
                    $msg = "Check-in exitoso. Estancia #$estanciaId";
                    $msgType = 'success';
                }
            }

            // Ninguna rama de rechazo escribió: se suelta el bloqueo.
            if ($pdo->inTransaction()) $pdo->rollBack();

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Check-in de mostrador: ' . $e->getMessage());
                $msg = 'Error en check-in.';
                $msgType = 'danger';
            }
        }
    }
}

// `num_personas` y las tarifas del tipo hacían falta aquí: la tarjeta de
// llegada tiene que decir a cuánto sale la noche, y dejar corregir la
// ocupación antes de registrar la entrada. Recepción es el último sitio donde
// se sabe cuánta gente llegó de verdad, y de eso depende el cobro.
$reservasHoy = $pdo->query("select r.id, r.fecha_entrada, r.fecha_salida, r.num_personas,
                                   h.numero, hu.nombres, hu.apellidos,
                                   t.nombre as tipo, t.tarifa_1, t.tarifa_2, t.tarifa_extra, t.tarifa_base,
                                   " . SQL_CAPACIDAD . " as capacidad_max,
                                   (select count(*) from acompanantes a where a.reserva_id = r.id) as num_acomp,
                                   (select count(*) from acompanantes a where a.reserva_id = r.id and a.es_menor = 1) as num_menores
                            from reservas r
                            join habitaciones h on h.id = r.habitacion_id
                            join huespedes hu on hu.id = r.huesped_id
                            left join tipos_habitacion t on t.id = h.tipo_id
                            where r.estado = 'confirmada' and r.fecha_entrada <= curdate()
                            order by r.fecha_entrada")->fetchAll();
// Las tres tarifas y la capacidad viajan a la pantalla para que el precio se
// calcule al elegir la habitación, sin recargar. El que se cobra lo vuelve a
// calcular el servidor al guardar; esto es solo para que nadie registre una
// entrada a ciegas. Ver includes/tarifas.php.
$habDisponibles = $pdo->query("select h.id, h.numero, t.nombre as tipo,
                                      t.tarifa_1, t.tarifa_2, t.tarifa_extra, t.tarifa_base,
                                      " . SQL_CAPACIDAD . " as capacidad_max
                               from habitaciones h, tipos_habitacion t
                               where h.tipo_id = t.id and h.estado = 'disponible'
                               order by cast(h.numero as unsigned), h.numero")->fetchAll();
$huespedes = $pdo->query("select id, documento_num, nombres, apellidos from huespedes order by apellidos")->fetchAll();
$estanciasActivas = $pdo->query("select e.id, e.fecha_checkin, e.tarifa_noche, h.numero, hu.nombres, hu.apellidos from estancias e, habitaciones h, huespedes hu where e.habitacion_id = h.id and e.huesped_id = hu.id and e.estado = 'activa' order by e.fecha_checkin desc")->fetchAll();

/**
 * Renderiza el bloque de captura de "Exención de IVA a extranjeros"
 * (Art. 481 E.T.). Se incrusta dentro de un <form> de check-in.
 * Al marcar la casilla se revelan (y se vuelven obligatorios) los
 * campos de pasaporte y sello migratorio. La validación real está en
 * el backend; aquí es solo apoyo de UX.
 */
function renderIvaExento(): void {
    ?>
    <div class="ci-iva">
        <label class="ci-iva-check">
            <input type="checkbox" name="iva_exento" value="1"
                   onchange="var f=this.closest('.ci-iva').querySelector('.ci-iva-fields');f.style.display=this.checked?'grid':'none';f.querySelectorAll('input').forEach(function(i){i.required=this.checked}.bind(this));">
            <span><i class="fas fa-passport"></i> Extranjero exento de IVA (Art. 481 E.T.)</span>
        </label>
        <div class="ci-iva-fields" style="display:none;">
            <input type="text" name="pasaporte_num" maxlength="40" placeholder="N° de pasaporte">
            <input type="text" name="sello_migratorio" maxlength="40" placeholder="Código sello migratorio (PT)">
        </div>
    </div>
    <?php
}

require_once __DIR__ . '/../../includes/header.php';
?>
<style>
/* ============================================================
   Check-in — Rediseño limpio (Casa Bosque)
   ============================================================ */
.ci-wrap { padding-bottom: 90px; }

/* Exención de IVA a extranjeros (Art. 481) */
.ci-iva { margin: 10px 0; padding: 10px 12px; border: 1px dashed var(--cb-border,#EDE3D2); border-radius: 12px; background: #fbfdf9; }
.ci-iva-check { display: flex; align-items: center; gap: 8px; font-size: .84rem; font-weight: 600; color: var(--cb-text,#2A1709); cursor: pointer; }
.ci-iva-check input { width: 16px; height: 16px; flex-shrink: 0; }
.ci-iva-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
.ci-iva-fields input { border: 1px solid var(--cb-border,#EDE3D2); border-radius: 9px; padding: 9px 11px; font-family: inherit; font-size: .84rem; }
.ci-iva-fields input:focus { outline: none; border-color: var(--cb-primary,#6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }
@media (max-width: 520px) { .ci-iva-fields { grid-template-columns: 1fr; } }

/* Mensaje */
.ci-msg { display: flex; align-items: center; gap: 11px; padding: 13px 15px; border-radius: 14px; margin-bottom: 18px; font-size: .9rem; font-weight: 500; }
.ci-msg.success { background: #e6f7f2; color: #047857; border: 1px solid rgba(31,122,77,.25); }
.ci-msg.danger  { background: #fdeded; color: #b91c1c; border: 1px solid rgba(239,68,68,.25); }

/* Tarjeta del precio en vivo. Mismo lenguaje que .cs-preview de Consumos:
   fondo arena, borde marrón suave y la cifra en grande a la derecha. */
.ci-precio { display: none; align-items: center; justify-content: space-between; gap: 12px;
             background: var(--cb-primary-light,#F5EBDC); border: 1px solid rgba(107,68,32,.2);
             border-radius: 13px; padding: 13px 15px; margin-bottom: 16px; }
.ci-precio .pl { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.ci-precio .pl span { font-size: .84rem; font-weight: 700; color: var(--cb-forest,#3E2412); }
.ci-precio .pl small { font-size: .76rem; color: #8A7761; }
.ci-precio .pv { font-size: 1.18rem; font-weight: 800; color: var(--cb-primary,#6B4420); white-space: nowrap; }

/* Personas y precio dentro de una tarjeta de llegada. */
.ci-ar-pax { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; margin-bottom: 10px;
             background: var(--cb-primary-light,#F5EBDC); border: 1px solid rgba(107,68,32,.18);
             border-radius: 11px; padding: 9px 11px; }
.ci-ar-pax label { font-size: .74rem; font-weight: 700; color: var(--cb-forest,#3E2412);
                   text-transform: uppercase; letter-spacing: .04em; margin: 0; }
.ci-ar-pax-sel { border: 1px solid rgba(107,68,32,.3); border-radius: 8px; padding: 5px 9px;
                 font-size: .86rem; font-weight: 700; color: var(--cb-forest,#3E2412);
                 background: #fff; min-width: 58px; }
.ci-ar-precio { font-size: .84rem; font-weight: 800; color: var(--cb-primary,#6B4420); margin-left: auto; }

/* Títulos de sección */
.ci-sec { display: flex; align-items: center; justify-content: space-between; margin: 4px 2px 12px; }
.ci-sec h3 { font-size: 1rem; font-weight: 700; color: var(--cb-forest, #3E2412); display: flex; align-items: center; gap: 8px; }
.ci-badge { font-size: .72rem; font-weight: 700; padding: 3px 10px; border-radius: 99px; background: rgba(249,115,22,.14); color: #b45309; }

/* Tarjeta de llegada */
.ci-arrival { background: #fff; border: 1px solid var(--cb-border, #EDE3D2); border-radius: 18px; padding: 15px; margin-bottom: 12px; box-shadow: 0 6px 18px rgba(42,23,9,.05); }
.ci-ar-top { display: flex; align-items: center; gap: 12px; }
.ci-avatar { flex: none; width: 44px; height: 44px; border-radius: 13px; background: linear-gradient(150deg, var(--cb-primary, #6B4420), var(--cb-secondary, #9C6728)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: .9rem; }
.ci-ar-info { flex: 1; min-width: 0; }
.ci-ar-name { font-size: .92rem; font-weight: 700; color: var(--cb-forest, #3E2412); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ci-ar-meta { font-size: .74rem; color: var(--cb-muted, #9A8778); margin-top: 1px; }
.ci-room-badge { flex: none; font-size: .82rem; font-weight: 800; color: var(--cb-primary, #6B4420); background: var(--cb-primary-light, #F5EBDC); padding: 6px 12px; border-radius: 10px; }
/* El `gap` es lo que de verdad separa la fecha del botón de acompañantes:
   `space-between` reparte el hueco SOBRANTE, y con el formulario de entrada
   ocupando casi toda la fila no sobraba nada. El `flex-wrap` es para que en
   pantalla estrecha los tres bloques bajen de línea en vez de apretarse. */
.ci-ar-foot { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; row-gap: 10px; margin-top: 13px; padding-top: 13px; border-top: 1px solid var(--gray-100, #F1EADF); }
.ci-ar-date { font-size: .76rem; color: var(--cb-muted, #9A8778); }
.ci-ar-date b { color: var(--cb-forest, #3E2412); font-weight: 700; }
.ci-btn-in { display: inline-flex; align-items: center; justify-content: center; gap: 7px; border: none; cursor: pointer; font-family: inherit; font-size: .84rem; font-weight: 700; color: #fff; background: linear-gradient(150deg, var(--cb-primary, #6B4420), var(--cb-secondary, #9C6728)); padding: 10px 16px; border-radius: 12px; box-shadow: 0 6px 14px rgba(107,68,32,.25); }
.ci-btn-in:active { transform: scale(.96); }

/* Card genérica */
.ci-card { background: #fff; border: 1px solid var(--cb-border, #EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); margin-bottom: 18px; overflow: hidden; }
.ci-card-h { padding: 16px 16px 0; }
.ci-card-h h3 { font-size: 1rem; font-weight: 700; color: var(--cb-forest, #3E2412); display: flex; align-items: center; gap: 8px; }
.ci-card-b { padding: 16px; }

/* Formulario */
.ci-field { margin-bottom: 14px; }
.ci-field label { display: block; margin-bottom: 7px; font-size: .82rem; font-weight: 600; color: var(--cb-forest, #3E2412); }
.ci-select { width: 100%; border: 1px solid var(--cb-border, #EDE3D2); background: #fff; border-radius: 12px; padding: 13px 14px; font-family: inherit; font-size: .88rem; color: var(--cb-text, #2A1709); appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236b7b69' d='M6 8 0 0h12z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; }
.ci-select:focus { outline: none; border-color: var(--cb-primary, #6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }
.ci-newlink { display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; color: var(--cb-primary, #6B4420); font-weight: 600; text-decoration: none; font-size: .82rem; }
.ci-submit { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: none; cursor: pointer; font-family: inherit; font-size: .92rem; font-weight: 700; color: #fff; background: linear-gradient(150deg, var(--cb-primary, #6B4420), var(--cb-secondary, #9C6728)); padding: 14px; border-radius: 14px; margin-top: 6px; box-shadow: 0 8px 18px rgba(107,68,32,.28); }
.ci-submit:active { transform: scale(.98); }

/* Lista estancias activas */
.ci-stay { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--gray-100, #F1EADF); }
.ci-stay:last-child { border-bottom: none; }
.ci-stay .ci-room { flex: none; width: 46px; height: 46px; border-radius: 13px; background: var(--cb-primary, #6B4420); color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1; }
.ci-stay .ci-room b { font-size: 1rem; font-weight: 800; }
.ci-stay .ci-room small { font-size: .5rem; font-weight: 700; opacity: .8; margin-top: 2px; letter-spacing: .5px; }
.ci-stay-info { flex: 1; min-width: 0; }
.ci-stay-name { font-size: .9rem; font-weight: 700; color: var(--cb-forest, #3E2412); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ci-stay-sub { font-size: .72rem; color: var(--cb-muted, #9A8778); margin-top: 1px; }
.ci-stay-rate { flex: none; font-size: .84rem; font-weight: 700; color: var(--cb-forest, #3E2412); }
.ci-empty { text-align: center; padding: 24px 12px; color: var(--cb-muted, #9A8778); }
.ci-empty i { font-size: 2rem; color: var(--cb-primary, #6B4420); opacity: .5; margin-bottom: 10px; display: block; }
.ci-empty p { font-size: .88rem; margin: 0; }

/* Selector Nuevo / Ya registrado + campos del huésped nuevo */
.ci-tabs { display: flex; gap: 6px; background: var(--gray-100,#F1EADF); padding: 5px; border-radius: 12px; margin-bottom: 14px; }
.ci-tab { flex: 1; border: none; background: transparent; font-family: inherit; font-size: .84rem; font-weight: 700; color: var(--cb-muted,#9A8778); padding: 10px; border-radius: 9px; cursor: pointer; }
.ci-tab.on { background: #fff; color: var(--cb-primary,#6B4420); box-shadow: 0 2px 6px rgba(42,23,9,.08); }
.ci-input { width: 100%; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; border-radius: 12px; padding: 13px 14px; font-family: inherit; font-size: .88rem; color: var(--cb-text,#2A1709); }
.ci-input:focus { outline: none; border-color: var(--cb-primary,#6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }
.ci-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
.ci-row-doc { display: grid; grid-template-columns: 90px 1fr; gap: 10px; margin-bottom: 10px; }
.ci-hd { display: flex; gap: 9px; align-items: flex-start; font-size: .78rem; color: var(--cb-muted,#9A8778); line-height: 1.45; margin-top: 4px; cursor: pointer; }
.ci-hd input { margin-top: 2px; flex: none; width: 16px; height: 16px; }
.ci-hd a { color: var(--cb-primary,#6B4420); font-weight: 600; }
</style>

<div class="ci-wrap">

<?php if ($msg): ?>
    <div class="ci-msg <?= $msgType ?>">
        <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
        <span><?= htmlspecialchars($msg) ?></span>
    </div>
<?php endif; ?>

<!-- Llegadas de hoy (reservas pendientes de check-in) -->
<div class="ci-sec">
    <h3><i class="fas fa-clock" style="color:#f59e0b;"></i> Llegadas de hoy</h3>
    <?php if (!empty($reservasHoy)): ?><span class="ci-badge"><?= count($reservasHoy) ?> por entrar</span><?php endif; ?>
    <?php $conMenores = count(array_filter($reservasHoy, fn($x) => (int)$x['num_menores'] > 0)); ?>
    <?php if ($conMenores > 0): ?><span class="ci-badge" style="background:#fdeaea; color:#b91c1c;"><i class="fas fa-child"></i> <?= $conMenores ?> con menores</span><?php endif; ?>
</div>

<?php if (empty($reservasHoy)): ?>
    <div class="ci-card"><div class="ci-empty">
        <i class="fas fa-circle-check"></i>
        <p>No hay llegadas pendientes por ahora.</p>
    </div></div>
<?php else: ?>
    <?php foreach ($reservasHoy as $r): ?>
        <div class="ci-arrival">
            <div class="ci-ar-top">
                <div class="ci-avatar"><?= strtoupper(mb_substr($r['nombres'],0,1) . mb_substr($r['apellidos'],0,1)) ?></div>
                <div class="ci-ar-info">
                    <div class="ci-ar-name"><?= htmlspecialchars($r['nombres'] . ' ' . $r['apellidos']) ?></div>
                    <div class="ci-ar-meta">Reserva #<?= $r['id'] ?></div>
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:5px;">
                        <?php if ((int)$r['num_menores'] > 0): ?>
                            <span style="font-size:.64rem; font-weight:700; padding:3px 8px; border-radius:99px; background:#fdeaea; color:#b91c1c;"><i class="fas fa-child"></i> <?= (int)$r['num_menores'] ?> menor(es)</span>
                        <?php elseif ((int)$r['num_acomp'] > 0): ?>
                            <span style="font-size:.64rem; font-weight:700; padding:3px 8px; border-radius:99px; background:#eef3ff; color:#3b5bdb;"><i class="fas fa-users"></i> <?= (int)$r['num_acomp'] ?> acomp.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ci-room-badge">Hab. <?= htmlspecialchars($r['numero']) ?></div>
            </div>
            <div class="ci-ar-foot">
                <div class="ci-ar-date">Entrada: <b><?= date('d/m/Y', strtotime($r['fecha_entrada'])) ?></b></div>
                <?php
                /* Siempre visible: es donde el recepcionista registra a los
                   acompañantes y, sobre todo, a los menores. Antes solo salía
                   si el huésped había hecho el pre-check-in en línea, y esa
                   página ya no existe. */
                ?>
                <a href="<?= BASE_URL ?>/modules/checkin/acompanantes.php?reserva=<?= (int)$r['id'] ?>" style="display:inline-flex; align-items:center; gap:6px; font-size:.78rem; font-weight:600; color:var(--cb-primary,#6B4420); text-decoration:none; border:1px solid rgba(107,68,32,.25); padding:7px 12px; border-radius:10px; margin-right:auto;"><i class="fas fa-users"></i> Acompañantes<?= (int)$r['num_acomp'] > 0 ? ' (' . (int)$r['num_acomp'] . ')' : '' ?></a>
                <form method="POST" style="margin:0;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="checkin">
                    <input type="hidden" name="tipo_checkin" value="con_reserva">
                    <input type="hidden" name="reserva_id" value="<?= $r['id'] ?>">
                    <?php
                    /* La ocupación y el precio, editables aquí mismo. La reserva
                       dijo un número hace días; recepción es el último sitio donde
                       se sabe cuánta gente llegó de verdad, y de eso depende lo
                       que se cobra. El precio se resuelve otra vez en el servidor
                       al guardar: esto es lo que se ve, no lo que se cobra. */
                    $paxR    = max(1, (int) $r['num_personas']);
                    $tarifaR = tarifaPorPersonas($r, $paxR);
                    $topeR   = (int) $r['capacidad_max'];
                    $sinTope = ($topeR >= 9999);
                    $hastaR  = $sinTope ? 8 : $topeR;
                    ?>
                    <div class="ci-ar-pax">
                        <label for="pax_r<?= (int)$r['id'] ?>">Personas</label>
                        <select name="num_personas" id="pax_r<?= (int)$r['id'] ?>" class="ci-ar-pax-sel"
                                data-tarifa1="<?= $r['tarifa_1'] !== null ? (int)$r['tarifa_1'] : '' ?>"
                                data-tarifa2="<?= $r['tarifa_2'] !== null ? (int)$r['tarifa_2'] : '' ?>"
                                data-extra="<?= (int)($r['tarifa_extra'] ?? 0) ?>"
                                data-tarifa-base="<?= $r['tarifa_base'] !== null ? (int)$r['tarifa_base'] : '' ?>"
                                data-tipo="<?= htmlspecialchars($r['tipo'] ?? '', ENT_QUOTES) ?>"
                                onchange="ciArPrecio(this)">
                            <?php for ($n = 1; $n <= max($hastaR, $paxR); $n++):
                                if (tarifaPorPersonas($r, $n) === null) { continue; } ?>
                                <option value="<?= $n ?>" <?= $n === $paxR ? 'selected' : '' ?>><?= $n ?></option>
                            <?php endfor; ?>
                        </select>
                        <span class="ci-ar-precio" id="precio_r<?= (int)$r['id'] ?>">
                            <?= $tarifaR !== null ? '$' . number_format($tarifaR, 0, ',', '.') . ' / noche' : 'sin tarifa' ?>
                        </span>
                    </div>
                    <?php if ((int)$r['num_menores'] > 0): ?>
                        <label style="display:flex; gap:9px; align-items:flex-start; font-size:.75rem; color:#b91c1c; background:#fdeaea; border:1px solid rgba(220,38,38,.25); border-radius:10px; padding:9px 11px; margin-bottom:9px; cursor:pointer; line-height:1.45;">
                            <input type="checkbox" name="docs_menores_ok" value="1" required style="margin-top:2px; flex-shrink:0; width:16px; height:16px;">
                            <span>Verifiqué los documentos de los <b><?= (int)$r['num_menores'] ?> menor(es)</b>: Registro Civil y, si no viaja con ambos padres, la autorización notarial (Ley 1098/2068 · Ley 679 - ESCNNA).</span>
                        </label>
                    <?php endif; ?>
                    <?php renderIvaExento(); ?>
                    <button class="ci-btn-in" type="submit"><i class="fas fa-door-open"></i> Registrar entrada</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Check-in directo (Walk-in) -->
<div class="ci-card">
    <div class="ci-card-h"><h3><i class="fas fa-person-walking-luggage" style="color:var(--cb-primary,#6B4420);"></i> Check-in directo</h3></div>
    <div class="ci-card-b">
        <form method="POST" id="form-checkin-directo">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="checkin">
            <input type="hidden" name="tipo_checkin" value="sin_reserva">
            <input type="hidden" name="huesped_modo" id="huesped_modo" value="nuevo">

            <div class="ci-field">
                <label>Huésped</label>
                <div class="ci-tabs">
                    <button type="button" class="ci-tab on" id="tab-nu" onclick="setModo('nuevo')">Nuevo</button>
                    <button type="button" class="ci-tab" id="tab-ex" onclick="setModo('existente')">Ya registrado</button>
                </div>

                <!-- Nuevo huésped: se registra en el mismo ingreso -->
                <div id="ci-nuevo">
                    <div class="ci-row-doc">
                        <select name="n_documento_tipo" class="ci-select">
                            <option value="CC">CC</option><option value="CE">CE</option><option value="PAS">PAS</option><option value="NIT">NIT</option>
                        </select>
                        <input type="text" name="n_documento_num" class="ci-input" data-req maxlength="30" placeholder="N° de documento" autocomplete="off">
                    </div>
                    <div class="ci-row2">
                        <input type="text" name="n_nombres" class="ci-input" data-req maxlength="100" placeholder="Nombres" autocomplete="off">
                        <input type="text" name="n_apellidos" class="ci-input" data-req maxlength="100" placeholder="Apellidos" autocomplete="off">
                    </div>
                    <div class="ci-row2" style="margin-bottom:4px;">
                        <input type="tel" name="n_telefono" class="ci-input" maxlength="20" placeholder="Teléfono (opcional)" autocomplete="off">
                        <?php /* El correo es un dato de contacto del huésped. Hasta 3.12.0
                                 además servía para mandarle la factura; el sistema ya no
                                 manda correos (3.13.0), pero el dato se sigue pidiendo. */ ?>
                        <input type="email" name="n_email" class="ci-input" maxlength="150" placeholder="Correo (opcional)" autocomplete="off">
                    </div>
                    <label class="ci-hd">
                        <input type="checkbox" name="n_habeas_data" value="1" data-req>
                        <span>El huésped autoriza el tratamiento de sus datos conforme a la <a href="<?= BASE_URL ?>/politica-datos.php<?= volverDesdeAqui() ?>" target="_blank" rel="noopener">Política de Datos</a> (Ley 1581 de 2012).</span>
                    </label>
                </div>

                <!-- Ya registrado: elegir de la lista -->
                <div id="ci-existente" style="display:none;">
                    <select name="huesped_id" id="huesped_ci" class="ci-select">
                        <option value="">Seleccionar huésped...</option>
                        <?php foreach($huespedes as $h): ?>
                            <option value="<?= $h['id'] ?>"><?= htmlspecialchars($h['apellidos'] . ', ' . $h['nombres'] . ' (' . descifrar($h['documento_num']) . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="ci-field">
                <label>Habitación disponible</label>
                <select name="habitacion_id" id="hab_ci" class="ci-select" required onchange="ciHabitacionCambiada()">
                    <option value="">Seleccionar habitación...</option>
                    <?php foreach($habDisponibles as $hab): ?>
                        <option value="<?= $hab['id'] ?>"
                                data-tipo="<?= htmlspecialchars($hab['tipo'] ?? '', ENT_QUOTES) ?>"
                                data-tarifa1="<?= $hab['tarifa_1'] !== null ? (int)$hab['tarifa_1'] : '' ?>"
                                data-tarifa2="<?= $hab['tarifa_2'] !== null ? (int)$hab['tarifa_2'] : '' ?>"
                                data-extra="<?= (int)($hab['tarifa_extra'] ?? 0) ?>"
                                data-tarifa-base="<?= $hab['tarifa_base'] !== null ? (int)$hab['tarifa_base'] : '' ?>"
                                data-cap="<?= (int)$hab['capacidad_max'] ?>"><?= htmlspecialchars('Habitación ' . $hab['numero'] . ' — ' . $hab['tipo']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Personas y precio. Aparecen al elegir la habitación: el menú se
                 arma con las ocupaciones que ese tipo admite, y el precio se
                 calcula ahí mismo. El que se cobra lo recalcula el servidor. -->
            <div class="ci-field" id="ci-pax-campo" style="display:none;">
                <label>¿Cuántas personas se hospedan?</label>
                <select name="num_personas" id="ci_pax" class="ci-select" onchange="ciPaxCambiado()"></select>
                <input type="number" name="num_personas_mas" id="ci_pax_mas" class="ci-select"
                       min="3" step="1" placeholder="Escribe el número de personas"
                       style="display:none; margin-top:8px;" oninput="ciPintarPrecio()">
            </div>

            <div id="ci-precio" class="ci-precio">
                <div class="pl">
                    <span id="ci-precio-tipo">—</span>
                    <small id="ci-precio-desglose"></small>
                </div>
                <span id="ci-precio-valor" class="pv">$0</span>
            </div>
            <div id="ci-precio-aviso" class="ci-msg danger" style="display:none; margin-top:10px;">
                <i class="fas fa-circle-exclamation"></i> <span id="ci-precio-aviso-txt"></span>
            </div>

            <?php renderIvaExento(); ?>

            <button type="submit" class="ci-submit"><i class="fas fa-door-open"></i> Registrar entrada</button>
        </form>
        <script src="<?= BASE_URL ?>/assets/js/tarifa-personas.js?v=<?= APP_VERSION ?>"></script>
        <script>
        // ── El precio en vivo del ingreso de mostrador ──
        // La fórmula no está aquí: vive en assets/js/tarifa-personas.js, que es
        // el espejo de includes/tarifas.php. Esto solo pinta.
        function ciTipoElegido(){
            return CBTarifas.desdeSelect(document.getElementById('hab_ci'));
        }

        function ciPaxActual(){
            var sel = document.getElementById('ci_pax');
            var mas = document.getElementById('ci_pax_mas');
            if (sel.value === 'mas') return parseInt(mas.value, 10) || 0;
            return parseInt(sel.value, 10) || 0;
        }

        function ciHabitacionCambiada(){
            var tipo   = ciTipoElegido();
            var campo  = document.getElementById('ci-pax-campo');
            var sel    = document.getElementById('ci_pax');
            var mas    = document.getElementById('ci_pax_mas');

            mas.style.display = 'none';
            mas.value = '';

            if (!tipo) { campo.style.display = 'none'; sel.innerHTML = ''; ciPintarPrecio(); return; }

            // Se pasa la ocupación RESUELTA, no sel.value: si venía de «Más
            // de 8…», sel.value es el texto 'mas' y se perdería el número.
            // Si la ocupación anterior no cabe en la habitación nueva, el menú
            // cae a la más baja: nunca sube el precio por su cuenta.
            CBTarifas.poblarPersonas(sel, tipo, ciPaxActual());
            campo.style.display = 'block';
            ciPaxCambiado();
        }

        function ciPaxCambiado(){
            var sel = document.getElementById('ci_pax');
            var mas = document.getElementById('ci_pax_mas');
            var esMas = (sel.value === 'mas');
            mas.style.display = esMas ? 'block' : 'none';
            mas.required = esMas;
            if (esMas && !mas.value) mas.value = String(CBTarifas.MAX_LISTADO + 1);
            ciPintarPrecio();
        }

        function ciPintarPrecio(){
            var tipo  = ciTipoElegido();
            var pax   = ciPaxActual();
            var caja  = document.getElementById('ci-precio');
            var aviso = document.getElementById('ci-precio-aviso');

            if (!tipo || !pax) { caja.style.display = 'none'; aviso.style.display = 'none'; return; }

            var motivo = CBTarifas.motivoInvalido(tipo, pax);
            if (motivo) {
                caja.style.display = 'none';
                aviso.style.display = 'flex';
                document.getElementById('ci-precio-aviso-txt').textContent = motivo;
                return;
            }
            aviso.style.display = 'none';

            var precio = CBTarifas.porPersonas(tipo, pax);
            var desg   = CBTarifas.desglose(tipo, pax);

            document.getElementById('ci-precio-tipo').textContent =
                tipo.nombre + ' · ' + pax + (pax === 1 ? ' persona' : ' personas');
            document.getElementById('ci-precio-desglose').textContent = desg ? desg : '';
            document.getElementById('ci-precio-valor').textContent = CBTarifas.pesos(precio) + ' / noche';
            caja.style.display = 'flex';
        }

        // ── El precio de una tarjeta de llegada, al corregir la ocupación ──
        // El <select> lleva las tarifas en sus propios data-*, así que cada
        // tarjeta se calcula sola sin saber nada de las demás.
        function ciArPrecio(sel){
            var tipo = CBTarifas.desdeElemento(sel);
            var pax  = parseInt(sel.value, 10) || 0;
            var dest = document.getElementById('precio_r' + sel.id.replace('pax_r',''));
            if (!dest) return;

            var precio = CBTarifas.porPersonas(tipo, pax);
            if (precio === null) { dest.textContent = 'sin tarifa'; return; }

            var desg = CBTarifas.desglose(tipo, pax);
            dest.textContent = CBTarifas.pesos(precio) + ' / noche' + (desg ? '  (' + desg + ')' : '');
        }

        function setModo(m){
            document.getElementById('huesped_modo').value = m;
            document.getElementById('ci-nuevo').style.display     = (m === 'nuevo') ? 'block' : 'none';
            document.getElementById('ci-existente').style.display = (m === 'existente') ? 'block' : 'none';
            // required solo en los campos visibles (evita bloquear el envío)
            document.querySelectorAll('#ci-nuevo [data-req]').forEach(function(el){ el.required = (m === 'nuevo'); });
            var sel = document.getElementById('huesped_ci'); if (sel) sel.required = (m === 'existente');
            document.getElementById('tab-nu').classList.toggle('on', m === 'nuevo');
            document.getElementById('tab-ex').classList.toggle('on', m === 'existente');
        }
        setModo('nuevo');
        </script>
    </div>
</div>

<!-- Estancias activas -->
<div class="ci-card">
    <div class="ci-card-h"><h3><i class="fas fa-bed" style="color:var(--cb-primary,#6B4420);"></i> Estancias activas <span style="font-size:.8rem; color:var(--cb-muted,#9A8778); font-weight:600;">(<?= count($estanciasActivas) ?>)</span></h3></div>
    <div class="ci-card-b" style="padding-top:8px;">
        <?php if (empty($estanciasActivas)): ?>
            <div class="ci-empty"><i class="fas fa-circle-check"></i><p>No hay estancias activas en el hotel.</p></div>
        <?php else: ?>
            <?php foreach($estanciasActivas as $e): ?>
                <div class="ci-stay">
                    <div class="ci-room"><b><?= htmlspecialchars($e['numero']) ?></b><small>HAB</small></div>
                    <div class="ci-stay-info">
                        <div class="ci-stay-name"><?= htmlspecialchars($e['nombres'] . ' ' . $e['apellidos']) ?></div>
                        <div class="ci-stay-sub">Ingreso: <?= date('d/m/Y H:i', strtotime($e['fecha_checkin'])) ?></div>
                    </div>
                    <div class="ci-stay-rate">$<?= number_format($e['tarifa_noche'], 0, ',', '.') ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

</div><!-- /.ci-wrap -->

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
