<?php
// Módulo: Reservas — Creación con validación estricta anti-solapamiento
$pageTitle = 'Reservas';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cifrado.php';
require_once __DIR__ . '/../../includes/disponibilidad.php';
require_once __DIR__ . '/../../includes/tarifas.php';        // el precio lo manda el tipo
require_once __DIR__ . '/../../includes/validacion.php';     // validarMonto: el abono es un entero de pesos
require_once __DIR__ . '/../../includes/selector_busqueda.php';   // el campo «escribe y elige» del huésped titular
requireLogin();

$pdo = getDB();
$msg = '';
$msgType = '';

// Procesar nueva reserva
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $msg = 'Token de seguridad inválido. Intente de nuevo.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'crear') {
            $habitacionId = (int)$_POST['habitacion_id'];
            $huespedId    = (int)$_POST['huesped_id'];
            $fechaEntrada = validarFecha($_POST['fecha_entrada'] ?? '');   // 4.3.1: una fecha real o null (antes se comparaba como texto)
            $fechaSalida  = validarFecha($_POST['fecha_salida'] ?? '');
            $numPersonas  = (int)$_POST['num_personas'];
            $horaLlegada  = trim($_POST['hora_llegada'] ?? '');
            $horaVal      = preg_match('/^\d{2}:\d{2}$/', $horaLlegada) ? $horaLlegada : null;
            $notas        = trim($_POST['notas'] ?? '');
            // 4.2.4: un entero de pesos (0 si no hay abono). Antes (float): un texto daba 0 en silencio y «1e6» un millón.
            $abonoCrudo  = trim((string) ($_POST['abono_reserva'] ?? ''));
            $abonoReserva = $abonoCrudo === '' ? 0 : validarMonto($abonoCrudo, 0);
            $metodoAbono  = $_POST['metodo_abono'] ?? '';

            // Validar fechas
            if ($abonoReserva === null) {
                $msg = 'El abono debe ser un número entero de pesos (0 si no hay), sin letras ni decimales.';
                $msgType = 'danger';
            } elseif ($fechaEntrada === null || $fechaSalida === null) {
                $msg = 'Las fechas de entrada y de salida deben ser fechas válidas (año-mes-día).';
                $msgType = 'danger';
            } elseif ($fechaEntrada >= $fechaSalida) {
                $msg = 'La fecha de salida debe ser posterior a la de entrada.';
                $msgType = 'danger';
            } elseif ($fechaEntrada < date('Y-m-d')) {
                $msg = 'La fecha de entrada no puede ser anterior a hoy.';
                $msgType = 'danger';
            } elseif ($fechaSalida <= date('Y-m-d')) {
                $msg = 'La fecha de salida no puede ser hoy ni una fecha pasada.';
                $msgType = 'danger';
            } else {
                // Validar capacidad
                // Sin filtrar por estado: de eso se encarga habitacionReservable(),
                // que además explica POR QUÉ no se puede. Filtrando aquí, una
                // habitación retirada daba el mensaje «no existe», que es falso.
                $stmtCap = $pdo->prepare("select " . SQL_CAPACIDAD . " as capacidad_max,
                                                 t.nombre, t.tarifa_1, t.tarifa_2, t.tarifa_extra, t.tarifa_base
                                          from habitaciones h
                                          left join tipos_habitacion t on t.id = h.tipo_id
                                          where h.id = ?");
                $stmtCap->execute([$habitacionId]);
                $roomData = $stmtCap->fetch(PDO::FETCH_ASSOC);

                if (!$roomData) {
                    $msg = 'La habitación seleccionada no existe.';
                    $msgType = 'danger';
                } else {
                    // El abono mínimo es el de la PRIMERA NOCHE, y esa noche
                    // cuesta según cuánta gente venga: una doble para dos son
                    // 50.000, no 40.000. Antes se tomaba un precio único.
                    $tarifaPrimeraNoche = (float) (tarifaPorPersonas($roomData, $numPersonas) ?? 0);
                    $motivoOcupacion    = motivoOcupacionInvalida($roomData, $numPersonas);
                    $stmtGuest = $pdo->prepare('select count(*) from huespedes where id=?');
                    $stmtGuest->execute([$huespedId]);
                    $guestExists = (int)$stmtGuest->fetchColumn() > 0;
                    $stmtKnown = $pdo->prepare('select count(*) from estancias where huesped_id=?');
                    $stmtKnown->execute([$huespedId]);
                    $huespedConocido = (int)$stmtKnown->fetchColumn() > 0;

                    if (!$guestExists) {
                        $msg = 'Selecciona un huésped registrado.';
                        $msgType = 'danger';
                    } elseif ($motivoOcupacion !== null) {
                        // Capacidad o falta de tarifa para esa ocupación, con el
                        // motivo concreto en vez de un «entre 1 y N» genérico.
                        $msg = $motivoOcupacion;
                        $msgType = 'danger';
                    } elseif (!$huespedConocido && $abonoReserva < $tarifaPrimeraNoche) {
                        $msg = 'Para un huésped nuevo, registra un abono igual o superior a la primera noche ($' . number_format($tarifaPrimeraNoche, 0, ',', '.') . ').';
                        $msgType = 'danger';
                    } elseif ($abonoReserva > 0 && !in_array($metodoAbono, ['efectivo', 'transferencia', 'nequi'], true)) {
                        $msg = 'Selecciona el medio usado para registrar el abono.';
                        $msgType = 'danger';
                    } else {
                        // Transacción con bloqueo de la fila de la habitación.
                        // Antes esto iba en autocommit: se comprobaba la
                        // disponibilidad, y entre esa comprobación y el insert
                        // cabía otra reserva —incluida una de la página pública,
                        // que sí bloquea—. Dos recepcionistas pulsando a la vez,
                        // o uno a la vez que un huésped reserva en línea,
                        // confirmaban DOS reservas para la misma habitación y
                        // fechas. Ver bloquearHabitacion() en
                        // includes/disponibilidad.php.
                        try {
                            $pdo->beginTransaction();

                            $habBloqueada = bloquearHabitacion($pdo, $habitacionId);
                            if ($habBloqueada === null) {
                                $pdo->rollBack();
                                $msg = 'Habitación no válida.';
                                $msgType = 'danger';
                            } else {
                                // La comprobación que vale es la de DESPUÉS del
                                // bloqueo. Una sola para todo el sistema, en
                                // includes/disponibilidad.php.
                                $reservable = habitacionReservable($pdo, $habitacionId, $fechaEntrada, $fechaSalida);

                                if (!$reservable['ok']) {
                                    $pdo->rollBack();
                                    $msg = trim($reservable['mensaje'] . ' ' . disponibilidadTextoLibres($reservable['libres']));
                                    $msgType = 'danger';
                                } else {
                                    $stmt = $pdo->prepare("insert into reservas (habitacion_id, huesped_id, fecha_entrada, fecha_salida, num_personas, hora_llegada, notas, abono_reserva, metodo_abono, usuario_id) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                    $stmt->execute([$habitacionId, $huespedId, $fechaEntrada, $fechaSalida, $numPersonas, $horaVal, $notas, $abonoReserva, $abonoReserva > 0 ? $metodoAbono : null, getCurrentUserId()]);
                                    $reservaId = $pdo->lastInsertId();
                                    $pdo->commit();

                                    // La auditoría va DESPUÉS del commit: es un
                                    // registro, no parte de la reserva, y no debe
                                    // alargar el bloqueo de la habitación.
                                    registrarAuditoria($pdo, 'reservas', $reservaId, 'crear', "Reserva Hab. $habitacionId: $fechaEntrada → $fechaSalida; abono $" . number_format($abonoReserva, 0, ',', '.'),
                                        null, auditoriaFila($pdo, 'reservas', (int) $reservaId));
                                    $msg = "Reserva #$reservaId creada" . ($abonoReserva > 0 ? ' · abono $' . number_format($abonoReserva, 0, ',', '.') : '');
                                    $msgType = 'success';
                                }
                            }
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            error_log('Reservas: no se pudo crear la reserva: ' . $e->getMessage());
                            $msg = 'No se pudo crear la reserva. Vuelve a intentarlo.';
                            $msgType = 'danger';
                        }
                    }
                }
            }
        }

        if ($action === 'marcar_incumplida') {
            $reservaId = (int)($_POST['reserva_id'] ?? 0);
            $stmt = $pdo->prepare("select r.huesped_id, r.fecha_entrada, r.abono_reserva, " . SQL_TARIFA . " as tarifa from reservas r join habitaciones h on h.id=r.habitacion_id left join tipos_habitacion t on t.id = h.tipo_id where r.id=? and r.estado='confirmada' and r.fecha_entrada<=curdate() and not exists(select 1 from estancias e where e.reserva_id=r.id)");
            $stmt->execute([$reservaId]);
            $reservaNoShow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$reservaNoShow) {
                $msg = 'Solo puedes marcar como inasistencia una reserva vencida que aún no tenga check-in.';
                $msgType = 'danger';
            } else {
                $pendienteNoche = max(0, (float)$reservaNoShow['tarifa'] - (float)$reservaNoShow['abono_reserva']);
                $upd = $pdo->prepare("update reservas set estado='incumplida', pendiente_noche=? where id=? and estado='confirmada'");
                $upd->execute([$pendienteNoche, $reservaId]);
                // La condición del `where` ya impedía marcarla dos veces, pero
                // sin mirar el resultado se anotaba en auditoría y se decía
                // «registrada» igual aunque no se hubiera tocado ninguna fila.
                if ($upd->rowCount() !== 1) {
                    $msg = 'Esa reserva ya no estaba confirmada; no se registró la inasistencia.';
                    $msgType = 'warning';
                } else {
                    registrarAuditoria($pdo, 'reservas', $reservaId, 'inasistencia', "Reserva incumplida; saldo de primera noche $" . number_format($pendienteNoche, 0, ',', '.'));
                    $msg = $pendienteNoche > 0
                        ? 'Inasistencia registrada. Se cobrarán $' . number_format($pendienteNoche, 0, ',', '.') . ' en la próxima estadía.'
                        : 'Inasistencia registrada; el abono cubre la primera noche.';
                    $msgType = 'warning';
                }
            }
        }

        if ($action === 'cancelar') {
            if (!isAdmin()) {
                $msg = 'Solo los administradores pueden cancelar reservas.';
                $msgType = 'danger';
            } else {
            $reservaId = (int)$_POST['reserva_id'];
            // Las cuatro sentencias van en UNA transacción, con la habitación
            // bloqueada. Antes iban en autocommit: una reserva creada por otro
            // usuario entre el recuento y la liberación quedaba confirmada sobre
            // una habitación que acababa de pasar a 'disponible'. Y el mensaje
            // decía «cancelada correctamente» aunque el update no hubiera
            // tocado ninguna fila porque otro ya la había cancelado.
            try {
                $pdo->beginTransaction();

                // Habitación de la reserva (antes de cancelar) para poder liberarla.
                $stmtHab = $pdo->prepare("select habitacion_id from reservas where id = ? and estado = 'confirmada'");
                $stmtHab->execute([$reservaId]);
                $habId = $stmtHab->fetchColumn();

                // El bloqueo va antes de decidir, y solo si hay habitación que
                // liberar. Si no hay, no hay nada que serializar.
                if ($habId) bloquearHabitacion($pdo, (int) $habId);

                $antesReserva = auditoriaFila($pdo, 'reservas', $reservaId);
                $stmt = $pdo->prepare("update reservas set estado = 'cancelada' where id = ? and estado = 'confirmada'");
                $stmt->execute([$reservaId]);

                if ($stmt->rowCount() !== 1) {
                    // Otro la canceló, o ya no estaba confirmada. No es un error,
                    // pero no se puede decir que la cancelamos nosotros.
                    $pdo->rollBack();
                    $msg = "La reserva #$reservaId ya no estaba confirmada; no se canceló nada.";
                    $msgType = 'warning';
                } else {
                    // Liberar la habitación SOLO si ya no le queda una estancia activa ni
                    // otra reserva confirmada, y estaba marcada 'ocupada' por esta reserva.
                    // (No toca 'limpieza'/'mantenimiento', que son estados deliberados.)
                    if ($habId) {
                        $chk = $pdo->prepare("select
                            (select count(*) from estancias where habitacion_id = ? and estado = 'activa')
                          + (select count(*) from reservas  where habitacion_id = ? and estado = 'confirmada')");
                        $chk->execute([$habId, $habId]);
                        if ((int)$chk->fetchColumn() === 0) {
                            $pdo->prepare("update habitaciones set estado = 'disponible' where id = ? and estado = 'ocupada'")->execute([$habId]);
                        }
                    }

                    $pdo->commit();

                    registrarAuditoria($pdo, 'reservas', $reservaId, 'cancelar', "Reserva cancelada" . ($habId ? " (Hab. #$habId liberada si aplicaba)" : ''),
                        $antesReserva, auditoriaFila($pdo, 'reservas', $reservaId));
                    $msg = "Reserva #$reservaId cancelada correctamente.";
                    $msgType = 'warning';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Reservas: no se pudo cancelar la reserva: ' . $e->getMessage());
                $msg = 'No se pudo cancelar la reserva. Vuelve a intentarlo.';
                $msgType = 'danger';
            }
            }
        }

        // ── Guardar/editar la nota de una reserva (admin y recepción) ──
        if ($action === 'guardar_nota') {
            $reservaId = (int)($_POST['reserva_id'] ?? 0);
            $nuevaNota = trim($_POST['notas'] ?? '');
            // 4.3.2: la reserva tiene que existir. Antes el update no tocaba nada si el id era falso, pero igual se registraba la auditoría y se
            // decía «nota actualizada»: un mensaje falso y una fila de auditoría de algo que no pasó.
            $stExiste = $pdo->prepare("select count(*) from reservas where id = ?");
            $stExiste->execute([$reservaId]);
            if (mb_strlen($nuevaNota) > 1000) {
                $msg = 'La nota es demasiado larga (máx. 1000 caracteres).';
                $msgType = 'danger';
            } elseif ((int) $stExiste->fetchColumn() === 0) {
                $msg = 'La reserva no existe.';
                $msgType = 'danger';
            } else {
                $stmt = $pdo->prepare("update reservas set notas = ? where id = ?");
                $stmt->execute([$nuevaNota === '' ? null : $nuevaNota, $reservaId]);
                registrarAuditoria($pdo, 'reservas', $reservaId, 'editar', "Nota actualizada");
                $msg = "Nota de la reserva #$reservaId actualizada.";
                $msgType = 'success';
            }
        }

        // ── Editar una reserva confirmada (habitación, fechas, hora, personas) ──
        // 4.3.1: con transacción y bloqueo, como crear. Antes se leía el estado y la disponibilidad y se escribía DESPUÉS, sin bloquear nada: entre
        // una cosa y otra cabía otra reserva en la misma habitación (la carrera que 3.0.5 cerró para `crear`), o un check-in que ya había cerrado
        // la reserva. Y las fechas se comparaban como texto: «abc» pasaba, y una entrada en el pasado se aceptaba. Ahora, en este orden:
        //   fechas válidas → beginTransaction → reserva bloqueada y revalidada → habitación bloqueada → disponibilidad → update con el estado
        //   esperado en el `where` y rowCount → commit. Ver includes/disponibilidad.php.
        if ($action === 'editar') {
            $reservaId    = (int)($_POST['reserva_id'] ?? 0);
            $habitacionId = (int)($_POST['habitacion_id'] ?? 0);
            $fechaEntrada = validarFecha($_POST['fecha_entrada'] ?? '');
            $fechaSalida  = validarFecha($_POST['fecha_salida'] ?? '');
            $numPersonas  = (int)($_POST['num_personas'] ?? 0);
            $horaLlegada  = trim($_POST['hora_llegada'] ?? '');
            $horaVal      = preg_match('/^\d{2}:\d{2}$/', $horaLlegada) ? $horaLlegada : null;
            $hoyEditar    = date('Y-m-d');

            if ($fechaEntrada === null || $fechaSalida === null) {
                $msg = 'Las fechas de entrada y de salida deben ser fechas válidas (año-mes-día).'; $msgType = 'danger';
            } elseif ($fechaEntrada >= $fechaSalida) {
                $msg = 'La fecha de salida debe ser posterior a la de entrada.'; $msgType = 'danger';
            } elseif ($fechaSalida <= $hoyEditar) {
                $msg = 'La fecha de salida no puede ser hoy ni una fecha pasada.'; $msgType = 'danger';
            } else {
                // Cualquier salida de la transacción con un mensaje: deshace lo hecho y lo dice.
                $fallarEditar = function (string $texto) use ($pdo, &$msg, &$msgType): void {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $msg = $texto; $msgType = 'danger';
                };
                try {
                    $pdo->beginTransaction();

                    // La reserva, bloqueada y leída DESPUÉS del bloqueo: solo se modifican las CONFIRMADAS (sin check-in ni canceladas).
                    $stmtEst = $pdo->prepare("select estado, fecha_entrada from reservas where id = ? for update");
                    $stmtEst->execute([$reservaId]);
                    $actual = $stmtEst->fetch(PDO::FETCH_ASSOC);

                    if ($actual === false) {
                        $fallarEditar('La reserva no existe.');
                    } elseif ($actual['estado'] !== 'confirmada') {
                        $fallarEditar('Solo se pueden modificar reservas confirmadas (sin check-in).');
                    } elseif ($fechaEntrada !== $actual['fecha_entrada'] && $fechaEntrada < $hoyEditar) {
                        // Quien llega tarde puede alargar su salida sin tocar la entrada (que ya pasó); lo que no se puede es MOVER la entrada al pasado.
                        $fallarEditar('La fecha de entrada no puede ser anterior a hoy. Solo puede quedarse la que la reserva ya tenía.');
                    } elseif (bloquearHabitacion($pdo, $habitacionId) === null) {
                        $fallarEditar('La habitación seleccionada no existe.');
                    } else {
                        $stmtCap = $pdo->prepare("select " . SQL_CAPACIDAD . " as capacidad_max,
                                                         t.nombre, t.tarifa_1, t.tarifa_2, t.tarifa_extra, t.tarifa_base
                                                  from habitaciones h
                                                  left join tipos_habitacion t on t.id = h.tipo_id
                                                  where h.id = ?");
                        $stmtCap->execute([$habitacionId]);
                        $roomEdit = $stmtCap->fetch(PDO::FETCH_ASSOC);

                        if (($motivoOcupacion = motivoOcupacionInvalida($roomEdit, $numPersonas)) !== null) {
                            $fallarEditar($motivoOcupacion);
                        } else {
                            // La misma comprobación que al crear, excluyendo esta reserva para que no choque consigo misma, y con la
                            // habitación ya bloqueada: la que vale es la de DESPUÉS del bloqueo.
                            $reservable = habitacionReservable($pdo, $habitacionId, $fechaEntrada, $fechaSalida, $reservaId);

                            if (!$reservable['ok']) {
                                $fallarEditar(trim($reservable['mensaje'] . ' ' . disponibilidadTextoLibres($reservable['libres'])));
                            } else {
                                $antesReserva = auditoriaFila($pdo, 'reservas', $reservaId);
                                $stmt = $pdo->prepare("update reservas set habitacion_id = ?, fecha_entrada = ?, fecha_salida = ?, num_personas = ?, hora_llegada = ? where id = ? and estado = 'confirmada'");
                                $stmt->execute([$habitacionId, $fechaEntrada, $fechaSalida, $numPersonas, $horaVal, $reservaId]);
                                if ($stmt->rowCount() !== 1) {
                                    $fallarEditar('La reserva cambió mientras se editaba. Recarga la página y revisa su estado.');
                                } else {
                                    $pdo->commit();
                                    // La auditoría va DESPUÉS del commit: es un registro, no parte de la reserva, y no debe alargar el bloqueo.
                                    registrarAuditoria($pdo, 'reservas', $reservaId, 'editar', "Reserva modificada: Hab. $habitacionId, $fechaEntrada → $fechaSalida, $numPersonas pers." . ($horaVal ? ", llega $horaVal" : ''),
                                        $antesReserva, auditoriaFila($pdo, 'reservas', $reservaId));
                                    $msg = "Reserva #$reservaId modificada correctamente.";
                                    $msgType = 'success';
                                }
                            }
                        }
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Reservas: no se pudo editar la reserva: ' . $e->getMessage());
                    $msg = 'No se pudo modificar la reserva. Vuelve a intentarlo.';
                    $msgType = 'danger';
                }
            }
        }
    }
}

// Datos para formulario
// Las tres tarifas viajan a los selectores para calcular el precio al vuelo.
// `capacidad_max` sale por SQL_CAPACIDAD para que el «sin tope» llegue
// traducido y no como el 0 crudo de la base.
$habitaciones = $pdo->query("select h.id, h.numero, h.notas, t.nombre as tipo,
                                    " . SQL_CAPACIDAD . " as capacidad_max,
                                    t.tarifa_1, t.tarifa_2, t.tarifa_extra, t.tarifa_base,
                                    " . SQL_TARIFA . " as tarifa_desde
                             from habitaciones h
                             join tipos_habitacion t on t.id=h.tipo_id
                             where h.estado <> 'inactiva'
                             order by cast(h.numero as unsigned), h.numero")->fetchAll();

/** Los data-* de una habitación, para que el navegador calcule su precio. */
function rsDatosTarifa(array $hab): string
{
    return ' data-tipo="' . htmlspecialchars($hab['tipo'] ?? '', ENT_QUOTES) . '"'
         . ' data-tarifa1="' . ($hab['tarifa_1'] !== null ? (int)$hab['tarifa_1'] : '') . '"'
         . ' data-tarifa2="' . ($hab['tarifa_2'] !== null ? (int)$hab['tarifa_2'] : '') . '"'
         . ' data-extra="' . (int)($hab['tarifa_extra'] ?? 0) . '"'
         . ' data-tarifa-base="' . ($hab['tarifa_base'] !== null ? (int)$hab['tarifa_base'] : '') . '"'
         . ' data-cap="' . (int)$hab['capacidad_max'] . '"'
         . ' data-notas="' . htmlspecialchars((string) ($hab['notas'] ?? ''), ENT_QUOTES) . '"';   // lo que el hotel anotó de la habitación (4.3.0): ventilador, aire acondicionado…
}


// Listado de reservas
$filtro = $_GET['filtro'] ?? 'activas';
$estadoBuscado = match($filtro) {
    'todas'      => null,
    'canceladas' => 'cancelada',
    'incumplidas' => 'incumplida',
    default      => 'confirmada',
};

$whereReserva = $estadoBuscado ? "and r.estado = ?" : "";
$paramsReserva = $estadoBuscado ? [$estadoBuscado] : [];

$stmtReservas = $pdo->prepare("
    select r.id, r.fecha_entrada, r.fecha_salida, r.num_personas, r.hora_llegada, r.estado, r.notas, r.abono_reserva, r.pendiente_noche, r.created_at,
           r.habitacion_id, h.numero, hu.nombres, hu.apellidos, hu.documento_num
    from reservas r, habitaciones h, huespedes hu
    where r.habitacion_id = h.id
      and r.huesped_id = hu.id
      $whereReserva
    order by r.fecha_entrada desc
    limit 50
");
$stmtReservas->execute($paramsReserva);
$reservas = $stmtReservas->fetchAll();
foreach ($reservas as &$_rv) { $_rv['documento_num'] = descifrar($_rv['documento_num']); }
unset($_rv);

require_once __DIR__ . '/../../includes/header.php';
?>
<style>
/* ============================================================
   Reservas — Rediseño limpio (Casa Bosque)
   ============================================================ */
.rs-wrap { padding-bottom: 90px; }

.rs-msg { display: flex; align-items: center; gap: 11px; padding: 13px 15px; border-radius: 14px; margin-bottom: 16px; font-size: .9rem; font-weight: 500; }
.rs-msg.success { background: #e6f7f2; color: #047857; border: 1px solid rgba(31,122,77,.25); }
.rs-msg.danger  { background: #fdeded; color: #b91c1c; border: 1px solid rgba(239,68,68,.25); }
.rs-msg.warning { background: #fff3e8; color: #b45309; border: 1px solid rgba(249,115,22,.25); }

/* Tarjeta del precio en vivo. Mismo lenguaje que .cs-preview de Consumos. */
.rs-precio { display: none; align-items: center; justify-content: space-between; gap: 12px;
             background: var(--cb-primary-light,#F5EBDC); border: 1px solid rgba(107,68,32,.2);
             border-radius: 13px; padding: 13px 15px; margin-bottom: 16px; }
.rs-precio .pl { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.rs-precio .pl span { font-size: .84rem; font-weight: 700; color: var(--cb-forest,#3E2412); }
.rs-precio .pl small { font-size: .76rem; color: #8A7761; }
.rs-precio .pv { font-size: 1.18rem; font-weight: 800; color: var(--cb-primary,#6B4420); white-space: nowrap; }

.rs-card { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); margin-bottom: 18px; overflow: hidden; }
.rs-card-h { padding: 16px 16px 0; }
.rs-card-h h3 { font-size: 1rem; font-weight: 700; color: var(--cb-forest,#3E2412); display: flex; align-items: center; gap: 8px; margin: 0; }
.rs-card-b { padding: 16px; }

/* Formulario */
.rs-field { margin-bottom: 13px; }
.rs-field label { display: flex; align-items: center; gap: 7px; margin-bottom: 7px; font-size: .8rem; font-weight: 600; color: var(--cb-forest,#3E2412); }
.rs-field label i { color: var(--cb-muted,#9A8778); }
.rs-input, .rs-select, .rs-textarea { width: 100%; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; border-radius: 12px; padding: 12px 14px; font-family: inherit; font-size: .88rem; color: var(--cb-text,#2A1709); }
.rs-select { appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236b7b69' d='M6 8 0 0h12z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; padding-right: 38px; }
.rs-textarea { resize: vertical; min-height: 60px; }
.rs-input:focus, .rs-select:focus, .rs-textarea:focus { outline: none; border-color: var(--cb-primary,#6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }
.rs-newlink { display: inline-flex; align-items: center; gap: 6px; margin-top: 7px; color: var(--cb-primary,#6B4420); font-weight: 600; text-decoration: none; font-size: .8rem; }
.rs-help { margin:-6px 0 14px; color:var(--cb-muted,#9A8778); font-size:.75rem; line-height:1.45; }
.rs-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 11px; }
.rs-submit { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: none; cursor: pointer; font-family: inherit; font-size: .92rem; font-weight: 700; color: #fff; background: linear-gradient(150deg, var(--cb-primary,#6B4420), var(--cb-secondary,#9C6728)); padding: 14px; border-radius: 14px; margin-top: 4px; box-shadow: 0 8px 18px rgba(107,68,32,.28); }
.rs-submit:active { transform: scale(.98); }

/* Filtros */
.rs-filters { display: flex; gap: 8px; margin-bottom: 16px; overflow-x: auto; padding-bottom: 2px; }
.rs-filters::-webkit-scrollbar { display: none; }
.rs-filter { flex: none; font-size: .8rem; font-weight: 600; padding: 8px 15px; border-radius: 99px; text-decoration: none; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; color: var(--cb-muted,#9A8778); }
.rs-filter.on { background: var(--cb-primary,#6B4420); color: #fff; border-color: var(--cb-primary,#6B4420); }

.rs-count { font-size: .78rem; color: var(--cb-muted,#9A8778); font-weight: 600; margin: 0 2px 12px; display: block; }

/* Tarjeta de reserva */
.rs-res { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 16px; padding: 14px; margin-bottom: 11px; box-shadow: 0 4px 12px rgba(42,23,9,.04); }
.rs-res-top { display: flex; align-items: center; gap: 12px; }
.rs-res-av { flex: none; width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: .85rem; }
.rs-res-info { flex: 1; min-width: 0; }
.rs-res-name { font-size: .9rem; font-weight: 700; color: var(--cb-forest,#3E2412); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rs-res-doc { font-size: .72rem; color: var(--cb-muted,#9A8778); margin-top: 1px; }
.rs-st { flex: none; font-size: .64rem; font-weight: 700; padding: 4px 10px; border-radius: 99px; }
.rs-st.confirmada { background: rgba(31,122,77,.14); color: #047857; }
.rs-st.cancelada  { background: rgba(239,68,68,.12); color: #b91c1c; }
.rs-st.completada { background: var(--cb-primary-light,#F5EBDC); color: var(--cb-primary,#6B4420); }
.rs-st.incumplida { background: rgba(245,158,11,.16); color: #9a5b00; }
.rs-res-body { display: flex; align-items: center; gap: 14px; margin-top: 13px; padding-top: 12px; border-top: 1px solid var(--gray-100,#F1EADF); }
.rs-dates { flex: 1; display: flex; align-items: center; gap: 8px; }
.rs-date { text-align: center; }
.rs-date .dk { font-size: .6rem; color: var(--cb-muted,#9A8778); text-transform: uppercase; }
.rs-date .dv { font-size: .82rem; font-weight: 700; color: var(--cb-forest,#3E2412); }
.rs-arrow { color: var(--soft,#A2907C); font-size: .75rem; }
.rs-room { flex: none; font-size: .78rem; font-weight: 800; color: var(--cb-primary,#6B4420); background: var(--cb-primary-light,#F5EBDC); padding: 5px 11px; border-radius: 9px; }
.rs-pers { flex: none; font-size: .76rem; color: var(--cb-muted,#9A8778); }
.rs-res-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 11px; gap: 8px; flex-wrap: wrap; }
.rs-nota-chip { display: inline-flex; align-items: center; gap: 5px; font-size: .72rem; color: #b45309; background: rgba(245,158,11,.12); padding: 4px 9px; border-radius: 8px; max-width: 200px; cursor: pointer; border: none; font-family: inherit; }
.rs-nota-chip span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rs-nota-add { display: inline-flex; align-items: center; gap: 5px; font-size: .72rem; color: var(--cb-muted,#9A8778); background: none; border: 1px dashed var(--cb-border,#EDE3D2); padding: 4px 9px; border-radius: 8px; cursor: pointer; font-family: inherit; }
.rs-cancel-btn { display: inline-flex; align-items: center; gap: 6px; font-size: .78rem; font-weight: 600; color: var(--danger,#ef4444); background: #fff; border: 1px solid rgba(239,68,68,.2); padding: 7px 13px; border-radius: 10px; cursor: pointer; font-family: inherit; margin-left: auto; }
.rs-cancel-btn:active { transform: scale(.96); }
.rs-edit-btn { display: inline-flex; align-items: center; gap: 6px; font-size: .78rem; font-weight: 600; color: var(--cb-primary,#6B4420); background: #fff; border: 1px solid rgba(107,68,32,.25); padding: 7px 13px; border-radius: 10px; cursor: pointer; font-family: inherit; }
.rs-edit-btn:active { transform: scale(.96); }
.rs-actions { display: flex; gap: 8px; margin-left: auto; }

.rs-empty { text-align: center; padding: 30px 12px; color: var(--cb-muted,#9A8778); }
.rs-empty i { font-size: 2rem; color: var(--cb-primary,#6B4420); opacity: .4; margin-bottom: 10px; display: block; }
.rs-empty p { font-size: .9rem; margin: 0; }

/* Modales */
.modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,.4); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 9999; opacity: 0; pointer-events: none; transition: opacity .25s ease; padding: 20px; }
.modal-overlay.active { opacity: 1; pointer-events: auto; }
.confirm-card { background: #fff; width: 100%; max-width: 420px; border-radius: 18px; box-shadow: 0 20px 40px rgba(0,0,0,.2); overflow: hidden; transform: scale(.92); transition: transform .25s cubic-bezier(.34,1.56,.64,1); }
.modal-overlay.active .confirm-card { transform: scale(1); }
.confirm-header { padding: 20px 20px 8px; display: flex; align-items: center; gap: 12px; }
.icon-container-cancel { width: 42px; height: 42px; background: rgba(245,158,11,.12); color: var(--warning,#f59e0b); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.icon-container-note { width: 42px; height: 42px; background: var(--cb-primary-light,#F5EBDC); color: var(--cb-primary,#6B4420); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.confirm-title { font-size: 1.05rem; font-weight: 700; color: var(--cb-forest,#3E2412); margin: 0; }
.confirm-body { padding: 0 20px 16px; color: #475569; font-size: .86rem; line-height: 1.5; }
.confirm-actions { padding: 14px 20px 18px; display: flex; justify-content: flex-end; gap: 10px; }
.btn-keep { padding: 10px 16px; border-radius: 10px; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; color: #475569; font-weight: 600; cursor: pointer; font-family: inherit; }
.btn-warn { padding: 10px 16px; border-radius: 10px; border: none; background: var(--warning,#f59e0b); color: #fff; font-weight: 700; cursor: pointer; font-family: inherit; }
.btn-save { padding: 10px 16px; border-radius: 10px; border: none; background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color: #fff; font-weight: 700; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 7px; }
</style>

<div class="rs-wrap">

<?php if ($msg): ?>
    <div class="rs-msg <?= $msgType ?>">
        <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : ($msgType === 'warning' ? 'triangle-exclamation' : 'circle-xmark') ?>"></i>
        <span><?= htmlspecialchars($msg) ?></span>
    </div>
<?php endif; ?>

<style>
.rs-notas-hab{margin-top:8px;padding:9px 12px;border:1px solid #E6C37A;border-radius:10px;background:#FFF7E6;color:#6B4420;font-size:.84rem;line-height:1.4;white-space:pre-line}
.rs-notas-hab[hidden]{display:none}
.rs-notas-hab i{margin-right:4px;color:#B7791F}
</style>
<!-- Nueva reserva -->
<div class="rs-card">
    <div class="rs-card-h"><h3><i class="fas fa-calendar-plus" style="color:var(--cb-primary,#6B4420);"></i> Nueva reserva</h3></div>
    <div class="rs-card-b">
        <form method="POST" id="form-reserva">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="crear">

            <div class="rs-field">
                <label for="huesped_buscar"><i class="fas fa-user"></i> Huésped titular *</label>
                <?php /* 4.3.0: un campo donde se escribe el nombre o la cédula y se elige, en vez de un menú con TODOS los huéspedes (con cien clientes
                         ya era una lista larguísima, y llevaba el documento de cada uno en la página). Ver includes/selector_busqueda.php. */
                selectorBusqueda([
                    'nombre' => 'huesped_id', 'url' => BASE_URL . '/modules/huespedes/buscar.php', 'minimo' => 2, 'requerido' => true,
                    'etiqueta' => 'Buscar huésped por nombre o cédula', 'ayuda' => 'Escribe el nombre o la cédula completa…', 'claseCampo' => 'rs-input', 'idCampo' => 'huesped_buscar',
                ]); ?>
                <a href="<?= BASE_URL ?>/modules/huespedes/index.php" class="rs-newlink"><i class="fas fa-plus-circle"></i> Registrar nuevo huésped</a>
            </div>

            <div class="rs-field">
                <label><i class="fas fa-bed"></i> Habitación *</label>
                <select name="habitacion_id" id="habitacion_id" class="rs-select" required onchange="rsHabitacionCambiada()">
                    <option value="">Seleccionar habitación...</option>
                    <?php foreach ($habitaciones as $hab): ?>
                        <option value="<?= $hab['id'] ?>"<?= rsDatosTarifa($hab) ?>>Hab. <?= htmlspecialchars($hab['numero'] . ' — ' . $hab['tipo'] . ' (' . textoCapacidad((int)$hab['capacidad_max']) . ')') ?></option>
                    <?php endforeach; ?>
                </select>
                <?php /* Las notas de la habitación elegida (4.3.0): no todas tienen aire acondicionado, unas tienen ventilador. Las pone rsPintarNotas(). */ ?>
                <div class="rs-notas-hab" id="rs-notas-hab" hidden><i class="fas fa-sticky-note"></i> <span id="rs-notas-hab-txt"></span></div>
            </div>

            <div class="rs-row2">
                <div class="rs-field">
                    <label for="abono_reserva"><i class="fas fa-hand-holding-dollar"></i> Abono de reserva (COP)</label>
                    <input type="text" name="abono_reserva" id="abono_reserva" class="rs-input" min="0" value="0" inputmode="numeric" autocomplete="off" data-solo="moneda">
                </div>
                <div class="rs-field">
                    <label for="metodo_abono"><i class="fas fa-wallet"></i> Medio del abono</label>
                    <select name="metodo_abono" id="metodo_abono" class="rs-select">
                        <option value="">Sin abono</option><option value="efectivo">Efectivo</option><option value="transferencia">Transferencia</option><option value="nequi">Nequi</option>
                    </select>
                </div>
            </div>
            <div id="abono-ayuda" class="rs-help">Para huéspedes nuevos, el abono debe cubrir la primera noche. Si no se presenta, la noche pendiente se cargará en su próxima estadía.</div>

            <div class="rs-row2">
                <div class="rs-field">
                    <label><i class="far fa-calendar-alt"></i> Entrada *</label>
                    <input type="date" name="fecha_entrada" id="fecha_entrada" class="rs-input" required min="<?= date('Y-m-d') ?>">
                </div>
                <div class="rs-field">
                    <label><i class="far fa-calendar-check"></i> Salida *</label>
                    <input type="date" name="fecha_salida" id="fecha_salida" class="rs-input" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                </div>
            </div>

            <div class="rs-row2">
                <div class="rs-field">
                    <label><i class="fas fa-clock"></i> Hora de llegada</label>
                    <input type="time" name="hora_llegada" id="hora_llegada" class="rs-input" value="14:00">
                </div>
                <div class="rs-field">
                    <label><i class="fas fa-users"></i> N° de personas *</label>
                    <?php /* Antes era un número libre con max="20" escrito a mano, que
                             ignoraba la capacidad real de la habitación. Ahora el menú
                             lo arma el navegador con las ocupaciones que ese tipo
                             admite, y el precio se calcula al elegir. */ ?>
                    <select name="num_personas" id="num_personas" class="rs-select" required onchange="rsPaxCambiado()">
                        <option value="1">1 persona</option>
                    </select>
                    <input type="number" name="num_personas_mas" id="num_personas_mas" class="rs-input"
                           min="3" step="1" placeholder="¿Cuántas personas?"
                           style="display:none; margin-top:8px;" oninput="rsPintarPrecio()">
                </div>
            </div>

            <!-- El precio de la reserva, al vuelo. Es un estimado: lo que se
                 cobra se congela en el check-in, con la gente que llegue. -->
            <div id="rs-precio" class="rs-precio">
                <div class="pl">
                    <span id="rs-precio-tipo">—</span>
                    <small id="rs-precio-desglose"></small>
                </div>
                <span id="rs-precio-valor" class="pv">$0</span>
            </div>
            <div id="rs-precio-aviso" class="rs-msg danger" style="display:none;">
                <i class="fas fa-circle-exclamation"></i> <span id="rs-precio-aviso-txt"></span>
            </div>

            <div class="rs-field">
                <label><i class="fas fa-sticky-note"></i> Notas / requerimientos</label>
                <textarea name="notas" id="notas_reserva" class="rs-textarea" rows="2" placeholder="Ej: camas separadas, llegada tardía..."></textarea>
            </div>

            <button type="submit" class="rs-submit" id="btn-crear-reserva"><i class="fas fa-calendar-check"></i> Confirmar reserva</button>
        </form>
    </div>
</div>

<!-- Filtros -->
<div class="rs-filters">
    <a href="?filtro=activas" class="rs-filter <?= $filtro==='activas' ? 'on' : '' ?>">Activas</a>
    <a href="?filtro=canceladas" class="rs-filter <?= $filtro==='canceladas' ? 'on' : '' ?>">Canceladas</a>
    <a href="?filtro=incumplidas" class="rs-filter <?= $filtro==='incumplidas' ? 'on' : '' ?>">Inasistencias</a>
    <a href="?filtro=todas" class="rs-filter <?= $filtro==='todas' ? 'on' : '' ?>">Todas</a>
</div>

<span class="rs-count"><i class="fas fa-list" style="margin-right:5px;"></i> <?= count($reservas) ?> reserva(s)</span>

<?php if (empty($reservas)): ?>
    <div class="rs-empty"><i class="fas fa-calendar-xmark"></i><p>No hay reservas para este filtro.</p></div>
<?php else: ?>
    <?php foreach ($reservas as $r):
        $notaActual = trim((string)($r['notas'] ?? ''));
    ?>
    <div class="rs-res">
        <div class="rs-res-top">
            <div class="rs-res-av"><?= strtoupper(mb_substr($r['nombres'],0,1,'UTF-8') . mb_substr($r['apellidos'],0,1,'UTF-8')) ?></div>
            <div class="rs-res-info">
                <div class="rs-res-name"><?= htmlspecialchars($r['nombres'] . ' ' . $r['apellidos']) ?></div>
                <div class="rs-res-doc">#<?= $r['id'] ?> · <?= htmlspecialchars($r['documento_num']) ?></div>
            </div>
            <span class="rs-st <?= $r['estado'] ?>"><?= ucfirst($r['estado']) ?></span>
        </div>
            <div class="rs-res-body">
            <div class="rs-dates">
                <div class="rs-date"><div class="dk">Entra</div><div class="dv"><?= date('d/m', strtotime($r['fecha_entrada'])) ?></div></div>
                <i class="fas fa-arrow-right rs-arrow"></i>
                <div class="rs-date"><div class="dk">Sale</div><div class="dv"><?= date('d/m', strtotime($r['fecha_salida'])) ?></div></div>
            </div>
            <span class="rs-pers"><i class="fas fa-user"></i> <?= $r['num_personas'] ?></span>
            <span class="rs-room">Hab. <?= htmlspecialchars($r['numero']) ?></span>
            <?php if ((float)$r['abono_reserva'] > 0): ?><span class="rs-room">Abono $<?= number_format($r['abono_reserva'],0,',','.') ?></span><?php endif; ?>
            <?php if ((float)$r['pendiente_noche'] > 0): ?><span class="rs-room" style="color:#b91c1c;background:#fdeaea;">Noche pendiente $<?= number_format($r['pendiente_noche'],0,',','.') ?></span><?php endif; ?>
        </div>
        <div class="rs-res-foot">
            <?php if ($notaActual !== ''): ?>
                <button type="button" class="rs-nota-chip" onclick="editarNota(<?= $r['id'] ?>, <?= htmlspecialchars(json_encode($notaActual), ENT_QUOTES) ?>)">
                    <i class="fas fa-sticky-note"></i><span><?= htmlspecialchars($notaActual) ?></span>
                </button>
            <?php else: ?>
                <button type="button" class="rs-nota-add" onclick="editarNota(<?= $r['id'] ?>, '')"><i class="fas fa-plus" style="font-size:9px;"></i> Agregar nota</button>
            <?php endif; ?>
            <?php if ($r['estado'] === 'confirmada'): ?>
                <div class="rs-actions">
                    <button type="button" class="rs-edit-btn" onclick='editarReserva(<?= htmlspecialchars(json_encode([
                        "id"            => (int)$r["id"],
                        "habitacion_id" => (int)$r["habitacion_id"],
                        "fecha_entrada" => $r["fecha_entrada"],
                        "fecha_salida"  => $r["fecha_salida"],
                        "num_personas"  => (int)$r["num_personas"],
                        "hora_llegada"  => $r["hora_llegada"],
                    ]), ENT_QUOTES) ?>)'>
                        <i class="fas fa-pen"></i> Editar
                    </button>
                    <button type="button" class="rs-cancel-btn" style="margin-left:0;" onclick="confirmarCancelacion(<?= (int)$r['id'] ?>, <?= htmlspecialchars(json_encode($r['nombres'] . ' ' . $r['apellidos']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($r['numero']), ENT_QUOTES) ?>)">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <?php if ($r['fecha_entrada'] <= date('Y-m-d')): ?>
                    <?php
                    /* El nombre va con json_encode y no interpolado dentro del
                       literal de JavaScript. Escaparlo solo con htmlspecialchars
                       no sirve: el navegador decodifica las entidades del
                       atributo ANTES de que lo lea el motor de JS, así que un
                       apellido con apóstrofo volvería a cerrar la cadena. */
                    ?>
                    <button type="button" class="rs-cancel-btn" style="margin-left:0;color:#9a5b00;"
                            onclick="confirmarInasistencia(<?= (int)$r['id'] ?>, <?= htmlspecialchars(json_encode($r['nombres'] . ' ' . $r['apellidos']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($r['numero']), ENT_QUOTES) ?>)">
                        Marcar inasistencia
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

</div><!-- /.rs-wrap -->

<!-- Modal cancelar (lógica conservada) -->
<div id="cancel-modal-overlay" class="modal-overlay">
    <div class="confirm-card">
        <div class="confirm-header">
            <div class="icon-container-cancel"><i class="fas fa-circle-exclamation"></i></div>
            <h4 class="confirm-title">¿Cancelar reserva?</h4>
        </div>
        <div class="confirm-body">
            Vas a cancelar la <strong id="cancel-folio-text" style="color:var(--cb-forest,#3E2412);"></strong> de <strong id="cancel-guest-text" style="color:var(--cb-forest,#3E2412);"></strong> (<strong id="cancel-room-text" style="color:var(--cb-forest,#3E2412);"></strong>).<br><br>
            <span style="color:var(--danger,#ef4444); font-weight:600;">Esto liberará la disponibilidad de la habitación en esas fechas.</span>
        </div>
        <div class="confirm-actions">
            <button type="button" class="btn-keep" onclick="cerrarModal()">Mantener</button>
            <form method="POST" id="form-cancelar-confirmado" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="cancelar">
                <input type="hidden" name="reserva_id" id="cancel-reserva-id" value="">
                <button type="submit" class="btn-warn">Confirmar cancelación</button>
            </form>
        </div>
    </div>
</div>

<!-- Modal inasistencia: misma tarjeta que los demás, no el aviso del navegador -->
<div id="inasistencia-modal-overlay" class="modal-overlay">
    <div class="confirm-card">
        <div class="confirm-header">
            <div class="icon-container-cancel"><i class="fas fa-user-slash"></i></div>
            <h4 class="confirm-title">¿El huésped no se presentó?</h4>
        </div>
        <div class="confirm-body">
            Vas a registrar la inasistencia de <strong id="inas-guest-text" style="color:var(--cb-forest,#3E2412);"></strong>
            (<strong id="inas-room-text" style="color:var(--cb-forest,#3E2412);"></strong>).<br><br>
            La reserva queda marcada como incumplida y <strong>el saldo pendiente se aplicará a su próxima estadía</strong>.
            La habitación vuelve a quedar disponible en esas fechas.
        </div>
        <div class="confirm-actions">
            <button type="button" class="btn-keep" onclick="cerrarInasistencia()">Volver</button>
            <form method="POST" id="form-inasistencia-confirmada" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="marcar_incumplida">
                <input type="hidden" name="reserva_id" id="inas-reserva-id" value="">
                <button type="submit" class="btn-warn">Registrar inasistencia</button>
            </form>
        </div>
    </div>
</div>

<!-- Modal nota interna (lógica conservada) -->
<div id="nota-modal-overlay" class="modal-overlay">
    <div class="confirm-card">
        <form method="POST" style="margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="guardar_nota">
            <input type="hidden" name="reserva_id" id="nota-reserva-id" value="">
            <div class="confirm-header">
                <div class="icon-container-note"><i class="fas fa-sticky-note"></i></div>
                <h4 class="confirm-title">Nota de la reserva <span id="nota-folio-text"></span></h4>
            </div>
            <div class="confirm-body">
                <p style="margin:0 0 10px;">Observaciones de la estadía (cama extra, cliente VIP, llega tarde).</p>
                <textarea name="notas" id="nota-textarea" class="rs-textarea" rows="4" maxlength="1000" placeholder="Escribe la nota..."></textarea>
            </div>
            <div class="confirm-actions">
                <button type="button" class="btn-keep" onclick="cerrarNota()">Cancelar</button>
                <button type="submit" class="btn-save"><i class="fas fa-floppy-disk"></i> Guardar nota</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal editar reserva -->
<div id="edit-modal-overlay" class="modal-overlay">
    <div class="confirm-card" style="max-height:92vh; overflow-y:auto;">
        <form method="POST" style="margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="editar">
            <input type="hidden" name="reserva_id" id="edit-reserva-id" value="">
            <div class="confirm-header">
                <div class="icon-container-note"><i class="fas fa-pen"></i></div>
                <h4 class="confirm-title">Modificar reserva <span id="edit-folio-text"></span></h4>
            </div>
            <div class="confirm-body">
                <div class="rs-field">
                    <label><i class="fas fa-bed"></i> Habitación</label>
                    <select name="habitacion_id" id="edit-habitacion" class="rs-select" required onchange="rsEditHabitacionCambiada()">
                        <?php foreach ($habitaciones as $hab): ?>
                            <option value="<?= $hab['id'] ?>"<?= rsDatosTarifa($hab) ?>>Hab. <?= htmlspecialchars($hab['numero'] . ' — ' . $hab['tipo'] . ' (' . textoCapacidad((int)$hab['capacidad_max']) . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="rs-notas-hab" id="rs-edit-notas-hab" hidden><i class="fas fa-sticky-note"></i> <span id="rs-edit-notas-hab-txt"></span></div>
                </div>
                <div class="rs-row2">
                    <div class="rs-field">
                        <label><i class="far fa-calendar-alt"></i> Entrada</label>
                        <input type="date" name="fecha_entrada" id="edit-entrada" class="rs-input" required>
                    </div>
                    <div class="rs-field">
                        <label><i class="far fa-calendar-check"></i> Salida</label>
                        <input type="date" name="fecha_salida" id="edit-salida" class="rs-input" required>
                    </div>
                </div>
                <div class="rs-row2">
                    <div class="rs-field">
                        <label><i class="fas fa-clock"></i> Hora llegada</label>
                        <input type="time" name="hora_llegada" id="edit-hora" class="rs-input">
                    </div>
                    <div class="rs-field">
                        <label><i class="fas fa-users"></i> Personas</label>
                        <select name="num_personas" id="edit-personas" class="rs-select" required onchange="rsEditPaxCambiado()"></select>
                        <input type="number" name="num_personas_mas" id="edit-personas-mas" class="rs-input"
                               min="3" step="1" placeholder="¿Cuántas personas?"
                               style="display:none; margin-top:8px;" oninput="rsEditPrecio()">
                    </div>
                </div>
                <div id="rs-edit-precio" class="rs-precio" style="margin-top:4px;">
                    <div class="pl"><span id="rs-edit-precio-tipo">—</span><small id="rs-edit-precio-desglose"></small></div>
                    <span id="rs-edit-precio-valor" class="pv">$0</span>
                </div>
            </div>
            <div class="confirm-actions">
                <button type="button" class="btn-keep" onclick="cerrarEdit()">Cancelar</button>
                <button type="submit" class="btn-save"><i class="fas fa-floppy-disk"></i> Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/tarifa-personas.js?v=<?= APP_VERSION ?>"></script>
<script>
const overlay = document.getElementById('cancel-modal-overlay');
const cancelIdInput = document.getElementById('cancel-reserva-id');
const folioText = document.getElementById('cancel-folio-text');
const guestText = document.getElementById('cancel-guest-text');
const roomText = document.getElementById('cancel-room-text');

function confirmarCancelacion(id, nombreHuesped, numeroHabitacion) {
    cancelIdInput.value = id;
    folioText.textContent = "Folio #" + id;
    guestText.textContent = nombreHuesped;
    roomText.textContent = "Habitación " + numeroHabitacion;
    overlay.classList.add('active');
}
function cerrarModal() { overlay.classList.remove('active'); }
overlay.addEventListener('click', function(e) { if (e.target === overlay) cerrarModal(); });

// ── Inasistencia ──
// Antes esto usaba el confirm() del navegador, que se ve como un aviso del
// sistema operativo y no como el resto de la aplicación.
const inasOverlay = document.getElementById('inasistencia-modal-overlay');
function confirmarInasistencia(id, nombreHuesped, numeroHabitacion) {
    document.getElementById('inas-reserva-id').value = id;
    document.getElementById('inas-guest-text').textContent = nombreHuesped;
    document.getElementById('inas-room-text').textContent = "Habitación " + numeroHabitacion;
    inasOverlay.classList.add('active');
}
function cerrarInasistencia() { inasOverlay.classList.remove('active'); }
inasOverlay.addEventListener('click', function(e) { if (e.target === inasOverlay) cerrarInasistencia(); });

// ── Notas internas ──
const notaOverlay = document.getElementById('nota-modal-overlay');
function editarNota(id, textoActual) {
    document.getElementById('nota-reserva-id').value = id;
    document.getElementById('nota-folio-text').textContent = "#" + id;
    document.getElementById('nota-textarea').value = textoActual || '';
    notaOverlay.classList.add('active');
    setTimeout(function(){ document.getElementById('nota-textarea').focus(); }, 250);
}
function cerrarNota() { notaOverlay.classList.remove('active'); }
notaOverlay.addEventListener('click', function(e) { if (e.target === notaOverlay) cerrarNota(); });

// ── Editar reserva ──
const editOverlay = document.getElementById('edit-modal-overlay');
function editarReserva(d) {
    document.getElementById('edit-reserva-id').value = d.id;
    document.getElementById('edit-folio-text').textContent = '#' + d.id;
    document.getElementById('edit-habitacion').value = d.habitacion_id;
    document.getElementById('edit-entrada').value = d.fecha_entrada;
    document.getElementById('edit-salida').value = d.fecha_salida;
    document.getElementById('edit-hora').value = d.hora_llegada ? String(d.hora_llegada).slice(0, 5) : '';
    // El menú de personas se arma con lo que admite la habitación de la
    // reserva, conservando la ocupación que tenía.
    rsEditHabitacionCambiada(d.num_personas);
    editOverlay.classList.add('active');
}
function cerrarEdit() { editOverlay.classList.remove('active'); }
editOverlay.addEventListener('click', function(e) { if (e.target === editOverlay) cerrarEdit(); });

// ── Personas y precio ──
// La fórmula vive en assets/js/tarifa-personas.js, espejo de includes/tarifas.php.
const roomSelect = document.getElementById('habitacion_id');

function rsPaxActual() {
    const sel = document.getElementById('num_personas');
    const mas = document.getElementById('num_personas_mas');
    if (sel.value === 'mas') return parseInt(mas.value, 10) || 0;
    return parseInt(sel.value, 10) || 0;
}

// Las notas de la habitación elegida (ventilador o aire acondicionado, lo que el hotel anotó): se escriben como TEXTO, nunca como HTML.
function rsPintarNotas(select, caja, texto) {
    const opcion = select.options[select.selectedIndex];
    const notas = (opcion && opcion.value) ? (opcion.dataset.notas || '').trim() : '';
    texto.textContent = notas;
    caja.hidden = notas === '';
}

function rsHabitacionCambiada() {
    rsPintarNotas(roomSelect, document.getElementById('rs-notas-hab'), document.getElementById('rs-notas-hab-txt'));
    const tipo = CBTarifas.desdeSelect(roomSelect);
    const sel  = document.getElementById('num_personas');
    const mas  = document.getElementById('num_personas_mas');
    mas.style.display = 'none'; mas.value = ''; mas.required = false;
    if (!tipo) { sel.innerHTML = '<option value="1">1 persona</option>'; rsPintarPrecio(); return; }
    CBTarifas.poblarPersonas(sel, tipo, rsPaxActual());
    rsPaxCambiado();
}

function rsPaxCambiado() {
    const sel = document.getElementById('num_personas');
    const mas = document.getElementById('num_personas_mas');
    const esMas = (sel.value === 'mas');
    mas.style.display = esMas ? 'block' : 'none';
    mas.required = esMas;
    if (esMas && !mas.value) mas.value = String(CBTarifas.MAX_LISTADO + 1);
    rsPintarPrecio();
}

function rsPintarPrecio() {
    const tipo = CBTarifas.desdeSelect(roomSelect);
    const pax  = rsPaxActual();
    const caja = document.getElementById('rs-precio');
    const aviso = document.getElementById('rs-precio-aviso');

    if (!tipo || !pax) { caja.style.display = 'none'; aviso.style.display = 'none'; actualizarAbonoReserva(); return; }

    const motivo = CBTarifas.motivoInvalido(tipo, pax);
    if (motivo) {
        caja.style.display = 'none';
        aviso.style.display = 'flex';
        document.getElementById('rs-precio-aviso-txt').textContent = motivo;
        actualizarAbonoReserva();
        return;
    }
    aviso.style.display = 'none';

    const precio = CBTarifas.porPersonas(tipo, pax);
    const desg   = CBTarifas.desglose(tipo, pax);
    document.getElementById('rs-precio-tipo').textContent =
        tipo.nombre + ' · ' + pax + (pax === 1 ? ' persona' : ' personas');
    document.getElementById('rs-precio-desglose').textContent = desg || '';
    document.getElementById('rs-precio-valor').textContent = CBTarifas.pesos(precio) + ' / noche';
    caja.style.display = 'flex';

    actualizarAbonoReserva();
}

// ── El mismo control, en el modal de edición ──
function rsEditHabitacionCambiada(paxDeseado) {
    const sel  = document.getElementById('edit-habitacion');
    rsPintarNotas(sel, document.getElementById('rs-edit-notas-hab'), document.getElementById('rs-edit-notas-hab-txt'));
    const pax  = document.getElementById('edit-personas');
    const mas  = document.getElementById('edit-personas-mas');
    const tipo = CBTarifas.desdeSelect(sel);
    mas.style.display = 'none'; mas.value = ''; mas.required = false;
    if (!tipo) return;
    CBTarifas.poblarPersonas(pax, tipo, paxDeseado || pax.value);
    rsEditPaxCambiado();
}

function rsEditPaxCambiado() {
    const pax = document.getElementById('edit-personas');
    const mas = document.getElementById('edit-personas-mas');
    const esMas = (pax.value === 'mas');
    mas.style.display = esMas ? 'block' : 'none';
    mas.required = esMas;
    if (esMas && !mas.value) mas.value = String(CBTarifas.MAX_LISTADO + 1);
    rsEditPrecio();
}

function rsEditPrecio() {
    const sel  = document.getElementById('edit-habitacion');
    const pax  = document.getElementById('edit-personas');
    const mas  = document.getElementById('edit-personas-mas');
    const tipo = CBTarifas.desdeSelect(sel);
    const n    = (pax.value === 'mas') ? (parseInt(mas.value, 10) || 0) : (parseInt(pax.value, 10) || 0);
    const caja = document.getElementById('rs-edit-precio');
    if (!tipo || !n) { caja.style.display = 'none'; return; }

    const precio = CBTarifas.porPersonas(tipo, n);
    if (precio === null) {
        document.getElementById('rs-edit-precio-tipo').textContent = CBTarifas.motivoInvalido(tipo, n) || '';
        document.getElementById('rs-edit-precio-desglose').textContent = '';
        document.getElementById('rs-edit-precio-valor').textContent = '—';
        caja.style.display = 'flex';
        return;
    }
    document.getElementById('rs-edit-precio-tipo').textContent =
        tipo.nombre + ' · ' + n + (n === 1 ? ' persona' : ' personas');
    document.getElementById('rs-edit-precio-desglose').textContent = CBTarifas.desglose(tipo, n) || '';
    document.getElementById('rs-edit-precio-valor').textContent = CBTarifas.pesos(precio) + ' / noche';
    caja.style.display = 'flex';
}

// El sistema exige el abono de la primera noche a quien se hospeda por primera vez.
// Esa noche cuesta según cuánta gente venga: una doble para dos son 50.000, no
// 40.000. Antes se tomaba un precio único de `data-tarifa` y el abono mínimo se
// quedaba corto en cuanto había dos personas.
const guestSelect = document.getElementById('huesped_id');
const depositInput = document.getElementById('abono_reserva');
const depositMethod = document.getElementById('metodo_abono');
const depositHint = document.getElementById('abono-ayuda');
function actualizarAbonoReserva() {
    const room = roomSelect.options[roomSelect.selectedIndex];
    // `guestSelect` es el campo oculto del selector de huésped (4.3.0): trae el id elegido y, en data-conocido, si ya se había hospedado.
    const nuevo = guestSelect.value !== '' && guestSelect.dataset.conocido === '0';
    const tarifa = (room && room.value)
        ? (CBTarifas.porPersonas(CBTarifas.desdeSelect(roomSelect), rsPaxActual()) || 0)
        : 0;
    if (nuevo) {
        depositInput.min = String(tarifa);
        depositInput.required = true;
        if (CBDinero.leer(depositInput) < tarifa) CBDinero.poner(depositInput, tarifa);
        CBDinero.revalidar(depositInput);   // el campo es de texto: el mínimo lo exige campos-numericos.js, y hay que volver a mirarlo al cambiarlo
        depositHint.textContent = 'Huésped nuevo: abono mínimo de primera noche ' + new Intl.NumberFormat('es-CO',{style:'currency',currency:'COP',maximumFractionDigits:0}).format(tarifa) + '. Si no se presenta, el abono cubre ese cargo.';
    } else {
        depositInput.min = '0';
        depositInput.required = false;
        CBDinero.revalidar(depositInput);
        if (CBDinero.leer(depositInput) === 0) depositMethod.value = '';
        depositHint.textContent = 'Los huéspedes conocidos pueden reservar sin abono. Si registras uno, selecciona el medio de pago.';
    }
    depositMethod.required = nuevo || CBDinero.leer(depositInput) > 0;
}
guestSelect.addEventListener('change', actualizarAbonoReserva);
// La habitación ya dispara rsHabitacionCambiada() por su onchange, y esa llama
// a rsPintarPrecio(), que termina en actualizarAbonoReserva(). No se engancha
// aquí otra vez para no calcular el abono dos veces por cada cambio.
depositInput.addEventListener('input', actualizarAbonoReserva);
depositMethod.addEventListener('change', actualizarAbonoReserva);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
