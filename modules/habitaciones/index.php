<?php
// Módulo: Habitaciones — CRUD completo + cambio de estado
$pageTitle = 'Habitaciones';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/validacion.php';   // validarMonto, validarEntero (montos que se teclean)
require_once __DIR__ . '/../../includes/tarifas.php';
require_once __DIR__ . '/../../includes/disponibilidad.php';   // bloquearHabitacion()
require_once __DIR__ . '/../../includes/fotos_habitacion.php';   // la foto de cada habitación que sube el hotel
// Aquí se fijan las tarifas y la capacidad de TODO el hotel, y se crean y retiran
// habitaciones. El menú (modules/menu.php, grupo «El hotel») ya la reserva al
// administrador; con requireLogin() a secas, recepción entraba escribiendo la URL
// y podía cambiarle el precio a todo el hotel. Ver includes/tarifas.php.
requireAdmin();

$pdo = getDB();
$msg = '';
$msgType = '';

// Cargar tipos de habitación para los selectores de los formularios
$tipos = $pdo->query("select id, nombre, capacidad_max, tarifa_base, tarifa_1, tarifa_2, tarifa_extra, descripcion from tipos_habitacion order by nombre")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
    // Una foto más grande que post_max_size llega SIN ningún campo (ni la acción ni el testigo): sin esto la pantalla recargaba muda.
    $msg = 'La foto pesa más de lo que el servidor deja subir. Elige una más liviana (hasta 8 MB).';
    $msgType = 'danger';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validateCsrf()) {
        $msg = 'Token de seguridad inválido o expirado. Intente de nuevo.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        // Capacidad y tarifa son propiedades compartidas del tipo.
        if ($action === 'crear_tipo' || $action === 'editar_tipo') {
            $tipoId = (int)($_POST['tipo_id'] ?? 0);
            $nombre = trim($_POST['tipo_nombre'] ?? '');
            // La capacidad admite 0, que significa SIN TOPE (ver includes/tarifas.php).
            // 4.2.4: enteros y nada más. Antes (int): una capacidad con letras o negativa se volvía 0 = SIN TOPE, y «40.000» de tarifa se guardaba
            // como 40 y se copiaba a todas las habitaciones del tipo.
            $capacidad   = validarEntero($_POST['tipo_capacidad'] ?? '', 0, 99);
            $descripcion = trim($_POST['tipo_descripcion'] ?? '');

            // Tres precios: una persona, dos personas, y el recargo por cada
            // persona a partir de la segunda. Vacío no es cero: un precio vacío
            // significa «este tipo no se vende a esa ocupación», y un cero sí
            // sería un precio, de regalo.
            $t1Crudo = trim((string)($_POST['tipo_tarifa_1'] ?? ''));
            $t2Crudo = trim((string)($_POST['tipo_tarifa_2'] ?? ''));
            $exCrudo = trim((string)($_POST['tipo_tarifa_extra'] ?? ''));
            $tarifa1 = $t1Crudo === '' ? null : validarMonto($t1Crudo, 0);
            $tarifa2 = $t2Crudo === '' ? null : validarMonto($t2Crudo, 0);
            $tarifaExtra = $exCrudo === '' ? 0 : validarMonto($exCrudo, 0);
            $tarifasInvalidas = ($t1Crudo !== '' && $tarifa1 === null) || ($t2Crudo !== '' && $tarifa2 === null) || ($exCrudo !== '' && $tarifaExtra === null);

            // `tarifa_base` ya no se cobra, pero hay consultas que aún la leen:
            // se mantiene como espejo del precio de entrada para que no mienta.
            $tarifaBase = $tarifa1 ?? $tarifa2;

            $tipoDup = $pdo->prepare("select id from tipos_habitacion where lower(nombre)=lower(?) and id<>? limit 1");
            $tipoDup->execute([$nombre, $action === 'editar_tipo' ? $tipoId : 0]);

            if ($nombre === '' || ($action === 'editar_tipo' && $tipoId < 1)) {
                $msg = 'Indica el nombre del tipo.';
                $msgType = 'danger';
            } elseif ($capacidad === null) {
                $msg = 'La capacidad tiene que ser un número entero (0 para dejarla sin tope), sin letras ni signos.';
                $msgType = 'danger';
            } elseif ($tarifasInvalidas) {
                $msg = 'Las tarifas y el recargo deben ser números enteros de pesos, sin letras ni puntos de decimales (por ejemplo 40000).';
                $msgType = 'danger';
            } elseif (($tarifa1 === null || $tarifa1 < 1) && ($tarifa2 === null || $tarifa2 < 1)) {
                $msg = 'Pon al menos una tarifa mayor que cero: la de una persona, la de dos, o las dos.';
                $msgType = 'danger';
            } elseif (($tarifa1 !== null && $tarifa1 < 1) || ($tarifa2 !== null && $tarifa2 < 1)) {
                $msg = 'Una tarifa no puede ser cero. Déjala vacía si ese tipo no se vende a esa ocupación.';
                $msgType = 'danger';
            } elseif ($capacidad !== 0 && $capacidad < 1) {
                $msg = 'La capacidad tiene que ser 1 o más, o 0 para dejarla sin tope.';
                $msgType = 'danger';
            } elseif ($capacidad !== 0 && $capacidad > 2 && $tarifaExtra < 1) {
                // Sin recargo no se puede cobrar a partir de la tercera persona,
                // y el tipo se quedaría sin poder vender su propia capacidad.
                $msg = 'Si el tipo admite más de dos personas, indica el recargo por persona adicional.';
                $msgType = 'danger';
            } elseif ($capacidad === 0 && $tarifaExtra < 1) {
                $msg = 'Un tipo sin tope de personas necesita un recargo por persona adicional.';
                $msgType = 'danger';
            } elseif ($tipoDup->fetchColumn()) {
                $msg = 'Ya existe un tipo de habitación con ese nombre.';
                $msgType = 'warning';
            } else {
                // Cómo estaba el tipo antes (solo si se edita), para la auditoría.
                $antesTipo = $action === 'editar_tipo' ? auditoriaFila($pdo, 'tipos_habitacion', $tipoId) : null;
                $pdo->beginTransaction();
                try {
                    if ($action === 'crear_tipo') {
                        $pdo->prepare("insert into tipos_habitacion (nombre, descripcion, capacidad_max, tarifa_base, tarifa_1, tarifa_2, tarifa_extra) values (?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$nombre, $descripcion ?: null, $capacidad, $tarifaBase, $tarifa1, $tarifa2, $tarifaExtra]);
                        $tipoId = (int)$pdo->lastInsertId();
                        $accionAuditoria = 'crear_tipo';
                    } else {
                        // 4.3.2: el tipo tiene que existir. Con un id falso el `update` no tocaba nada y la pantalla decía «guardado» y dejaba una
                        // auditoría de algo que no pasó. Se lee con `for update`, así tampoco puede desaparecer entre la comprobación y la escritura.
                        $stTipoExiste = $pdo->prepare("select id from tipos_habitacion where id = ? for update");
                        $stTipoExiste->execute([$tipoId]);
                        if (!$stTipoExiste->fetchColumn()) {
                            $pdo->rollBack();
                            $msg = 'Ese tipo de habitación no existe.';
                            $msgType = 'danger';
                            throw new RuntimeException('tipo_inexistente');
                        }
                        $pdo->prepare("update tipos_habitacion set nombre=?, descripcion=?, capacidad_max=?, tarifa_base=?, tarifa_1=?, tarifa_2=?, tarifa_extra=? where id=?")
                            ->execute([$nombre, $descripcion ?: null, $capacidad, $tarifaBase, $tarifa1, $tarifa2, $tarifaExtra, $tipoId]);
                        $accionAuditoria = 'editar_tipo';
                    }
                    // La copia de respaldo de cada habitación se pone al día con
                    // el precio de entrada y la capacidad del tipo.
                    $pdo->prepare("update habitaciones set capacidad_max=?, tarifa=? where tipo_id=?")
                        ->execute([$capacidad, $tarifaBase, $tipoId]);
                    $pdo->commit();

                    $detalleTarifas = ($tarifa1 !== null ? '1 pers. ' . number_format($tarifa1, 0, ',', '.') : '')
                                    . ($tarifa1 !== null && $tarifa2 !== null ? ' · ' : '')
                                    . ($tarifa2 !== null ? '2 pers. ' . number_format($tarifa2, 0, ',', '.') : '')
                                    . ($tarifaExtra > 0 ? ' · adicional ' . number_format($tarifaExtra, 0, ',', '.') : '');
                    registrarAuditoria($pdo, 'tipos_habitacion', $tipoId, $accionAuditoria,
                        "Tipo $nombre: " . ($capacidad === 0 ? 'sin tope de personas' : "$capacidad persona(s)") . " · $detalleTarifas",
                        $antesTipo, auditoriaFila($pdo, 'tipos_habitacion', $tipoId));
                    $msg = 'Tipo de habitación guardado. Las tarifas y la capacidad se aplicaron a todas sus habitaciones.';
                    $msgType = 'success';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if ($e->getMessage() !== 'tipo_inexistente') {   // ese caso ya dejó su propio mensaje arriba
                        $msg = 'No se pudo guardar el tipo. Verifica que el nombre no esté repetido.';
                        $msgType = 'danger';
                    }
                }
            }
        }

        // CREAR HABITACIÓN
        if ($action === 'crear') {
            $numero    = trim($_POST['numero']);
            $piso      = (int)$_POST['piso'];
            $tipoId    = (int)$_POST['tipo_id'];
            $notas     = trim($_POST['notas'] ?? '');

            $tipo = $pdo->prepare("select capacidad_max, coalesce(tarifa_1, tarifa_2, tarifa_base, 0) as tarifa_base from tipos_habitacion where id=?");
            $tipo->execute([$tipoId]);
            $tipoData = $tipo->fetch();
            if (empty($numero) || $piso < 1 || !$tipoData) {
                $msg = 'Todos los campos obligatorios deben estar completos.';
                $msgType = 'danger';
            } else {
                $check = $pdo->prepare("select id from habitaciones where numero = ?");
                $check->execute([$numero]);
                if ($check->fetch()) {
                    $msg = "Ya existe una habitación registrada con el número \"$numero\".";
                    $msgType = 'warning';
                } else {
                    $stmt = $pdo->prepare("insert into habitaciones (numero, piso, tipo_id, capacidad_max, tarifa, notas) values (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$numero, $piso, $tipoId, $tipoData['capacidad_max'], $tipoData['tarifa_base'], $notas ?: null]);
                    $nuevoId = $pdo->lastInsertId();
                    registrarAuditoria($pdo, 'habitaciones', $nuevoId, 'crear', "Habitación $numero creada (Piso $piso)",
                        null, auditoriaFila($pdo, 'habitaciones', (int) $nuevoId));
                    $msg = "Habitación \"$numero\" creada exitosamente.";
                    $msgType = 'success';
                }
            }
        }

        // EDITAR HABITACIÓN
        if ($action === 'editar') {
            $habId     = (int)$_POST['habitacion_id'];
            $numero    = trim($_POST['numero']);
            $piso      = (int)$_POST['piso'];
            $tipoId    = (int)$_POST['tipo_id'];
            $notas     = trim($_POST['notas'] ?? '');

            $tipo = $pdo->prepare("select capacidad_max, coalesce(tarifa_1, tarifa_2, tarifa_base, 0) as tarifa_base from tipos_habitacion where id=?");
            $tipo->execute([$tipoId]);
            $tipoData = $tipo->fetch();
            if (empty($numero) || $piso < 1 || !$tipoData) {
                $msg = 'Todos los campos obligatorios deben estar completos.';
                $msgType = 'danger';
            } else {
                $dupCheck = $pdo->prepare("select id from habitaciones where numero = ? and id != ?");
                $dupCheck->execute([$numero, $habId]);
                if ($dupCheck->fetch()) {
                    $msg = "Ya existe otra habitación configurada con el número \"$numero\".";
                    $msgType = 'warning';
                } else {
                    // 4.3.2: con transacción y bloqueo de la habitación (como el cambio de estado, el check-in y las reservas). Antes: la habitación
                    // podía no existir y la pantalla decía «actualizada» con su auditoría (un `update` sin filas no es un error); y se podía cambiar
                    // el TIPO de una habitación con un huésped dentro, que le cambia la capacidad y el recargo por persona adicional en plena estadía.
                    try {
                        $pdo->beginTransaction();
                        $habEditar = bloquearHabitacion($pdo, $habId);
                        if ($habEditar === null) {
                            $pdo->rollBack();
                            $msg = 'La habitación solicitada no existe.';
                            $msgType = 'danger';
                        } else {
                            $stTipoAct = $pdo->prepare("select tipo_id from habitaciones where id = ?");
                            $stTipoAct->execute([$habId]);
                            $tipoActual = (int) $stTipoAct->fetchColumn();
                            $stHosp = $pdo->prepare("select count(*) from estancias where habitacion_id = ? and estado = 'activa'");
                            $stHosp->execute([$habId]);
                            if ($tipoActual !== $tipoId && (int) $stHosp->fetchColumn() > 0) {
                                $pdo->rollBack();
                                $msg = 'Esa habitación tiene un huésped hospedado: no se le puede cambiar el tipo hasta el check-out. Puedes cambiar el número, el piso y las notas.';
                                $msgType = 'danger';
                            } else {
                                $antesHab = auditoriaFila($pdo, 'habitaciones', $habId);
                                $stmt = $pdo->prepare("update habitaciones set numero = ?, piso = ?, tipo_id = ?, capacidad_max = ?, tarifa = ?, notas = ? where id = ?");
                                $stmt->execute([$numero, $piso, $tipoId, $tipoData['capacidad_max'], $tipoData['tarifa_base'], $notas ?: null, $habId]);
                                $pdo->commit();
                                registrarAuditoria($pdo, 'habitaciones', $habId, 'editar', "Datos actualizados: Hab. $numero",
                                    $antesHab, auditoriaFila($pdo, 'habitaciones', $habId));
                                $msg = "Habitación \"$numero\" actualizada correctamente.";
                                $msgType = 'success';
                            }
                        }
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        error_log('Habitaciones: no se pudo editar: ' . $e->getMessage());
                        $msg = 'No se pudo guardar la habitación. Vuelve a intentarlo.';
                        $msgType = 'danger';
                    }
                }
            }
        }

        // ELIMINAR HABITACIÓN
        /**
         * ¿La habitación tiene estadías o reservas registradas?
         *
         * Es lo que decide si se puede borrar de verdad: HCB_RF_003 manda
         * conservar el historial, así que una habitación con pasado no se
         * elimina nunca, solo se retira del servicio.
         */
        if (!function_exists('habitacionTieneHistorial')) {
            function habitacionTieneHistorial(PDO $pdo, int $habId): bool {
                $s = $pdo->prepare("
                    select (select count(*) from estancias where habitacion_id = ?)
                         + (select count(*) from reservas  where habitacion_id = ?)
                ");
                $s->execute([$habId, $habId]);
                return (int) $s->fetchColumn() > 0;
            }
        }

        // RETIRAR DEL SERVICIO (pasa a 'inactiva')
        //
        // Acción propia, separada de "Eliminar". Retirar una habitación del
        // inventario y borrarla del sistema son cosas distintas y ahora cada
        // una tiene su botón, con el nombre de lo que hace (HCB_RNF_001).
        if ($action === 'retirar') {
            if (!isAdmin()) {
                $msg = 'Acceso restringido: Solo los administradores pueden retirar habitaciones.';
                $msgType = 'danger';
            } else {
                $habId = (int)($_POST['habitacion_id'] ?? 0);
                $stmtH = $pdo->prepare("select numero, estado from habitaciones where id = ?");
                $stmtH->execute([$habId]);
                $hab = $stmtH->fetch();

                if (!$hab) {
                    $msg = 'La habitación solicitada no existe.';
                    $msgType = 'danger';
                } elseif ($hab['estado'] === 'ocupada') {
                    // Con un huésped dentro no se retira: primero el check-out.
                    $msg = "La habitación {$hab['numero']} tiene un huésped hospedado. Haz primero el check-out.";
                    $msgType = 'danger';
                } elseif ($hab['estado'] === 'inactiva') {
                    $msg = "La habitación {$hab['numero']} ya estaba fuera de servicio.";
                    $msgType = 'warning';
                } else {
                    $antesHab = auditoriaFila($pdo, 'habitaciones', $habId);
                    $pdo->prepare("update habitaciones set estado = 'inactiva' where id = ?")->execute([$habId]);
                    registrarAuditoria($pdo, 'habitaciones', $habId, 'retirar',
                        "Habitación {$hab['numero']} retirada del servicio; deja de ofrecerse para reservas",
                        $antesHab, auditoriaFila($pdo, 'habitaciones', $habId));
                    $msg = "Habitación {$hab['numero']} retirada del servicio. Ya no se ofrece para reservas y su historial se conserva.";
                    $msgType = 'success';
                }
            }
        }

        if ($action === 'eliminar') {
            if (!isAdmin()) {
                $msg = 'Acceso restringido: Solo los administradores pueden remover habitaciones.';
                $msgType = 'danger';
            } else {
                $habId = (int)$_POST['habitacion_id'];

                $stmtCheck = $pdo->prepare("select h.numero, h.estado from habitaciones h where h.id = ?");
                $stmtCheck->execute([$habId]);
                $habData = $stmtCheck->fetch();

                if (!$habData) {
                    $msg = 'La habitación solicitada no existe.';
                    $msgType = 'danger';
                } elseif ($habData['estado'] === 'ocupada') {
                    $msg = 'Acción denegada: No es posible eliminar una habitación ocupada.';
                    $msgType = 'danger';
                } elseif (habitacionTieneHistorial($pdo, $habId)) {
                    // Antes, en este caso, "Eliminar" inactivaba la habitación
                    // en silencio. Eran dos resultados muy distintos —borrar
                    // para siempre o retirar del servicio— detrás del mismo
                    // botón, y sin forma de saber cuál iba a ocurrir antes de
                    // pulsarlo. Ahora se explica y se ofrece la acción correcta.
                    $msg = "La habitación {$habData['numero']} no se puede eliminar porque tiene estadías o reservas en su historial, y HCB_RF_003 manda conservarlo. Si quieres dejar de ofrecerla, usa «Retirar de servicio».";
                    $msgType = 'warning';
                } else {
                    $antesHab = auditoriaFila($pdo, 'habitaciones', $habId);   // después del delete ya no existe
                    $pdo->prepare("delete from habitaciones where id = ?")->execute([$habId]);
                    registrarAuditoria($pdo, 'habitaciones', $habId, 'eliminar', "Habitación {$habData['numero']} eliminada",
                        $antesHab, null);
                    $msg = "Habitación \"{$habData['numero']}\" removida del sistema.";
                    $msgType = 'success';
                }
            }
        }

        // LA FOTO DE UNA HABITACIÓN (4.3.0): la sube el hotel; ver includes/fotos_habitacion.php, que es quien la valida y la guarda.
        if ($action === 'subir_foto' || $action === 'quitar_foto') {
            $habId = (int) ($_POST['habitacion_id'] ?? 0);
            $stmtF = $pdo->prepare("select numero from habitaciones where id = ?");
            $stmtF->execute([$habId]);
            $numeroF = $stmtF->fetchColumn();
            if (!isAdmin()) {
                $msg = 'Acceso restringido: solo los administradores pueden cambiar las fotos.';
                $msgType = 'danger';
            } elseif ($numeroF === false) {
                $msg = 'La habitación solicitada no existe.';
                $msgType = 'danger';
            } elseif ($action === 'subir_foto') {
                $res = fotoHabitacionGuardar($habId, $_FILES['foto'] ?? []);
                $msg = $res['ok'] ? "Foto de la habitación $numeroF guardada." : $res['mensaje'];
                $msgType = $res['ok'] ? 'success' : 'danger';
                if ($res['ok']) registrarAuditoria($pdo, 'habitaciones', $habId, 'foto_subir', "Foto de la habitación $numeroF actualizada");
            } else {
                if (fotoHabitacionQuitar($habId)) {
                    registrarAuditoria($pdo, 'habitaciones', $habId, 'foto_quitar', "Foto propia de la habitación $numeroF quitada; vuelve la de siempre");
                    $msg = "Se quitó la foto de la habitación $numeroF: vuelve a verse la de siempre.";
                    $msgType = 'success';
                } else {
                    $msg = "La habitación $numeroF no tenía una foto propia.";
                    $msgType = 'warning';
                }
            }
        }

        // CAMBIAR ESTADO OPERATIVO
        // 4.3.2: con transacción y bloqueo de la habitación, como el check-in y las reservas (includes/disponibilidad.php). Antes se leía si había una
        // estancia activa y se actualizaba DESPUÉS, sin bloquear nada: un check-in que entrara en medio dejaba la habitación 'disponible' con un huésped
        // dentro. Tampoco se comprobaba que la habitación existiera: con un id falso decía «actualizado» y dejaba la auditoría de algo que no pasó.
        if ($action === 'cambiar_estado') {
            $habId          = (int)($_POST['habitacion_id'] ?? 0);
            $nuevoEstado    = (string) ($_POST['nuevo_estado'] ?? '');
            // 4.3.2: «ocupada» NO se elige a mano. Una habitación está ocupada porque hay una estancia activa (la pone el check-in y la quita el
            // check-out); ponerla a mano la dejaba bloqueada sin que nadie estuviera dentro, y la pantalla no ofrece esa opción: solo un POST escrito
            // a mano la mandaba. Salir de «ocupada» sin estancia (una habitación que quedó mal) sí se puede: eso es destrabar, no ocupar.
            $estadosValidos = ['disponible', 'limpieza', 'mantenimiento'];

            if ($nuevoEstado === 'ocupada') {
                $msg = 'Una habitación pasa a ocupada con un check-in, no a mano.';
                $msgType = 'danger';
            } elseif (!in_array($nuevoEstado, $estadosValidos, true)) {
                $msg = 'Ese estado no es válido.';
                $msgType = 'danger';
            } else {
                try {
                    $pdo->beginTransaction();
                    $habBloq = bloquearHabitacion($pdo, $habId);   // devuelve el estado YA bloqueado, o null si no existe

                    if ($habBloq === null) {
                        $pdo->rollBack();
                        $msg = 'La habitación solicitada no existe.';
                        $msgType = 'danger';
                    } else {
                        // Integridad: si la habitación tiene un huésped hospedado, no se puede cambiar el estado a mano aquí (se libera con el
                        // check-out). Se mira con la habitación ya bloqueada.
                        $stmtAct = $pdo->prepare("select count(*) from estancias where habitacion_id = ? and estado = 'activa'");
                        $stmtAct->execute([$habId]);
                        $oldEstado = (string) $habBloq['estado'];

                        if ((int) $stmtAct->fetchColumn() > 0) {
                            $pdo->rollBack();
                            $msg = 'Esa habitación tiene un huésped hospedado. Para liberarla haz el Check-out.';
                            $msgType = 'danger';
                        } elseif ($oldEstado === $nuevoEstado) {
                            $pdo->rollBack();
                            $msg = 'La habitación ya estaba en ese estado.';
                            $msgType = 'warning';
                        } else {
                            $antesHab = auditoriaFila($pdo, 'habitaciones', $habId);
                            // Con el estado esperado en el `where` y rowCount: si cambió entre el bloqueo y el update, no se escribe nada.
                            $stmtUp = $pdo->prepare("update habitaciones set estado = ? where id = ? and estado = ?");
                            $stmtUp->execute([$nuevoEstado, $habId, $oldEstado]);
                            if ($stmtUp->rowCount() !== 1) {
                                $pdo->rollBack();
                                $msg = 'La habitación cambió mientras se actualizaba. Recarga la página e inténtalo de nuevo.';
                                $msgType = 'danger';
                            } else {
                                $pdo->commit();
                                // La auditoría va DESPUÉS del commit: es un registro, no parte del cambio, y no debe alargar el bloqueo.
                                registrarAuditoria($pdo, 'habitaciones', $habId, 'cambio_estado', "Estado: $oldEstado → $nuevoEstado",
                                    $antesHab, auditoriaFila($pdo, 'habitaciones', $habId));
                                $msg = "Estado de la habitación actualizado con éxito.";
                                $msgType = 'success';
                            }
                        }
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Habitaciones: no se pudo cambiar el estado: ' . $e->getMessage());
                    $msg = 'No se pudo cambiar el estado. Vuelve a intentarlo.';
                    $msgType = 'danger';
                }
            }
        }
    }
}

// Configuración de Filtros y Búsqueda
$filtroEstado = $_GET['estado'] ?? '';
$where = "where 1=1";
$params = [];

if ($filtroEstado && in_array($filtroEstado, ['disponible', 'ocupada', 'limpieza', 'mantenimiento', 'inactiva'])) {
    $where .= " and h.estado = ?";
    $params[] = $filtroEstado;
}

$stmt = $pdo->prepare("
    select h.id, h.numero, h.piso, h.estado, h.notas,
           " . SQL_TARIFA . " as tarifa,
           " . SQL_CAPACIDAD . " as capacidad_max,
           t.id as tipo_id, t.nombre as tipo,
           e.id as estancia_id, hu.nombres as h_nombres, hu.apellidos as h_apellidos,
           -- Estadías o reservas registradas. Decide si se puede eliminar de
           -- verdad: con historial no se borra nunca (HCB_RF_003), solo se
           -- retira del servicio. Se calcula aquí para no lanzar una consulta
           -- por cada tarjeta al pintar el listado.
           (select count(*) from estancias es where es.habitacion_id = h.id)
         + (select count(*) from reservas  rs where rs.habitacion_id = h.id) as historial
    from habitaciones h
    left join tipos_habitacion t on h.tipo_id = t.id
    left join estancias e on e.habitacion_id = h.id and e.estado = 'activa'
    left join huespedes hu on hu.id = e.huesped_id
    $where
    order by cast(h.numero as unsigned), h.numero
");
$stmt->execute($params);
$habitaciones = $stmt->fetchAll();

// Conteos globales por estado (para el resumen de un vistazo, independiente del filtro)
$conteos = ['disponible' => 0, 'ocupada' => 0, 'limpieza' => 0, 'mantenimiento' => 0, 'inactiva' => 0];
foreach ($pdo->query("select estado, count(*) c from habitaciones group by estado") as $rc) {
    if (isset($conteos[$rc['estado']])) { $conteos[$rc['estado']] = (int)$rc['c']; }
}
$totalHab = array_sum($conteos);

// Refrescar los tipos después de una creación o edición recibida por POST.
$tipos = $pdo->query("select id, nombre, capacidad_max, tarifa_base, tarifa_1, tarifa_2, tarifa_extra, descripcion from tipos_habitacion order by nombre")->fetchAll();

// Fotos asignadas por tipo según la disposición visible de camas en las imágenes.
//
// La clave es el NOMBRE DEL TIPO en minúsculas, así que renombrar un tipo deja
// sus habitaciones sin foto. Por eso están los nombres del hotel («cama doble»,
// «cama sencilla», «dos camas») y también los antiguos, que siguen vivos
// mientras queden habitaciones retiradas apuntando a ellos.
$fotosPorTipo = [
    'cama sencilla' => [4],
    'cama doble'    => [5],
    'dos camas'     => [2, 5],
    // Los de ejemplo, de antes del catálogo real:
    'sencilla' => [4],
    'doble' => [5],
    'familiar' => [2],
    'matrimonial' => [1, 3, 6],
];

// Modo Edición Activo
$editId = (int)($_GET['editar'] ?? 0);
$editData = null;
if ($editId) {
    $stmtEdit = $pdo->prepare("
        select h.*, t.nombre as tipo_nombre, t.capacidad_max, t.tarifa_base
        from habitaciones h
        left join tipos_habitacion t on h.tipo_id = t.id
        where h.id = ?
    ");
    $stmtEdit->execute([$editId]);
    $editData = $stmtEdit->fetch();
}

require_once __DIR__ . '/../../includes/header.php';
?>
<style>
/* ============================================================
   Habitaciones — Rediseño limpio (Casa Bosque)
   ============================================================ */
.hb-wrap { padding-bottom: 90px; }
.hb-gallery { margin: 0 0 18px; }
.hb-gallery h2 { margin: 0 0 4px; color: var(--cb-forest,#3E2412); font-size: 1rem; }
.hb-gallery p { margin: 0 0 11px; color: var(--cb-muted,#9A8778); font-size: .78rem; }
.hb-gallery-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 9px; }
@media (min-width: 700px) { .hb-gallery-grid { grid-template-columns: repeat(3,minmax(0,1fr)); } }
.hb-gallery figure { margin: 0; overflow: hidden; border-radius: 14px; background: #e8eee6; aspect-ratio: 4/3; }
.hb-gallery img { display: block; width: 100%; height: 100%; object-fit: cover; }

.hb-msg { display: flex; align-items: center; gap: 11px; padding: 13px 15px; border-radius: 14px; margin-bottom: 16px; font-size: .9rem; font-weight: 500; }
.hb-msg.success { background: #e6f7f2; color: #047857; border: 1px solid rgba(31,122,77,.25); }
.hb-msg.danger  { background: #fdeded; color: #b91c1c; border: 1px solid rgba(239,68,68,.25); }
.hb-msg.warning { background: #fff3e8; color: #b45309; border: 1px solid rgba(249,115,22,.25); }

.hb-card { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); margin-bottom: 18px; overflow: hidden; }
.hb-card-h { padding: 16px 16px 0; display: flex; align-items: center; justify-content: space-between; }
.hb-card-h h3 { font-size: 1rem; font-weight: 700; color: var(--cb-forest,#3E2412); display: flex; align-items: center; gap: 8px; margin: 0; }
.hb-card-h .cancel { font-size: .78rem; color: var(--cb-muted,#9A8778); text-decoration: none; font-weight: 600; }
.hb-card-b { padding: 16px; }

.hb-field { margin-bottom: 13px; }
.hb-field label { display: flex; align-items: center; gap: 7px; margin-bottom: 7px; font-size: .8rem; font-weight: 600; color: var(--cb-forest,#3E2412); }
.hb-field label i { color: var(--cb-muted,#9A8778); }
.hb-input, .hb-select, .hb-textarea { width: 100%; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; border-radius: 12px; padding: 12px 14px; font-family: inherit; font-size: .88rem; color: var(--cb-text,#2A1709); }
.hb-select { appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236b7b69' d='M6 8 0 0h12z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; padding-right: 38px; }
.hb-textarea { resize: vertical; min-height: 52px; }
.hb-input:focus, .hb-select:focus, .hb-textarea:focus { outline: none; border-color: var(--cb-primary,#6B4420); box-shadow: 0 0 0 3px rgba(107,68,32,.1); }
.hb-row3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
.hb-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 11px; }
/* Siete columnas: nombre · personas · 1 pers. · 2 pers. · adicional · descripción · botón */
.hb-type-form { display:grid; grid-template-columns: 1.3fr 92px 104px 104px 104px 1.4fr 110px; gap:8px; align-items:center; padding:10px 0; border-bottom:1px solid var(--cb-border,#EDE3D2); }
/* Cabecera de la rejilla: sin ella, cinco casillas numéricas seguidas no se
   distinguen y nadie sabe cuál es cuál. */
.hb-type-head { display:grid; grid-template-columns: 1.3fr 92px 104px 104px 104px 1.4fr 110px; gap:8px; padding:0 0 6px; }
.hb-type-head span { font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:var(--cb-forest,#3E2412); display:flex; flex-direction:column; line-height:1.3; }
.hb-type-head small { font-size:.63rem; font-weight:500; text-transform:none; letter-spacing:0; color:#A2907C; }
@media(max-width:900px) { .hb-type-head { display:none; } }
.hb-type-new { border:0; margin-top:8px; }
.hb-type-help { display:block; margin-top:10px; color:var(--cb-muted,#9A8778); font-size:.78rem; }
@media(max-width:900px) { .hb-type-form { grid-template-columns:1fr 1fr; } .hb-type-form button { grid-column:span 2; } }
/* 4.2.0: rótulo por campo. En escritorio lo da .hb-type-head; en el celular esa cabecera se oculta y los campos se veían
   sin nombre (¿cuál es el precio de una persona y cuál el de dos?). Un <input> no admite ::before, por eso cada uno va
   dentro de su <label data-label>. En escritorio el label es display:contents: el input sigue siendo una celda de la grilla. */
.hb-tf { display:contents; }
.hb-tf::before { display:none; }
@media(max-width:900px) {
    .hb-type-form { align-items:start; }   /* un rótulo de dos líneas no desalinea el campo de al lado */
    .hb-tf { display:block; min-width:0; }
    .hb-tf::before { content:attr(data-label); display:block; margin-bottom:4px; font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:var(--cb-forest,#3E2412); line-height:1.3; }
}
.hb-submit { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: none; cursor: pointer; font-family: inherit; font-size: .92rem; font-weight: 700; color: #fff; padding: 14px; border-radius: 14px; margin-top: 4px; }
.hb-submit.crear { background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); box-shadow: 0 8px 18px rgba(107,68,32,.28); }
.hb-submit.editar { background: linear-gradient(150deg,#f59e0b,#d97706); box-shadow: 0 8px 18px rgba(217,119,6,.28); }
.hb-submit:active { transform: scale(.98); }

/* Filtros */
.hb-filters { display: flex; gap: 8px; margin-bottom: 14px; overflow-x: auto; padding-bottom: 2px; }
.hb-filters::-webkit-scrollbar { display: none; }
.hb-filter { flex: none; font-size: .78rem; font-weight: 600; padding: 8px 14px; border-radius: 99px; text-decoration: none; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; color: var(--cb-muted,#9A8778); }
.hb-filter.on { background: var(--cb-primary,#6B4420); color: #fff; border-color: var(--cb-primary,#6B4420); }

/* Tarjeta de habitación */
.hb-room { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 16px; padding: 14px; margin-bottom: 11px; box-shadow: 0 4px 12px rgba(42,23,9,.04); border-left: 4px solid var(--cb-border,#EDE3D2); }
.hb-room.disponible { border-left-color: #1F7A4D; }
.hb-room.ocupada { border-left-color: #f59e0b; }
.hb-room.limpieza { border-left-color: #3b82f6; }
.hb-room.mantenimiento { border-left-color: #ef4444; }
.hb-room.inactiva { border-left-color: #64748b; opacity: .76; }
.hb-room-top { display: flex; align-items: center; gap: 12px; }
.hb-room-photo { flex: none; width: 112px; height: 84px; margin: 0; overflow: hidden; border-radius: 12px; background: #e8eee6; }
.hb-room-photo img { display: block; width: 100%; height: 100%; object-fit: cover; }
@media (max-width: 560px) { .hb-room-photo { width: 82px; height: 72px; } }
.hb-num { flex: none; width: 50px; height: 50px; border-radius: 13px; background: var(--cb-primary-light,#F5EBDC); color: var(--cb-primary,#6B4420); display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1; }
.hb-num b { font-size: 1.05rem; font-weight: 800; }
.hb-num small { font-size: .54rem; font-weight: 700; opacity: .8; margin-top: 2px; }
.hb-room-info { flex: 1; min-width: 0; }
.hb-room-tipo { font-size: .92rem; font-weight: 700; color: var(--cb-forest,#3E2412); }
.hb-room-meta { font-size: .74rem; color: var(--cb-muted,#9A8778); margin-top: 2px; }
.hb-estado { flex: none; font-size: .64rem; font-weight: 700; padding: 4px 11px; border-radius: 99px; text-transform: capitalize; }
.hb-estado.disponible { background: rgba(31,122,77,.14); color: #047857; }
.hb-estado.ocupada { background: rgba(249,115,22,.14); color: #b45309; }
.hb-estado.limpieza { background: rgba(59,130,246,.14); color: #1e40af; }
.hb-estado.mantenimiento { background: rgba(239,68,68,.12); color: #b91c1c; }
.hb-estado.inactiva { background: rgba(100,116,139,.14); color: #475569; }
.hb-room-body { display: flex; align-items: center; gap: 14px; margin-top: 13px; padding-top: 12px; border-top: 1px solid var(--gray-100,#F1EADF); }
.hb-d { flex: 1; }
.hb-d .dk { font-size: .62rem; color: var(--cb-muted,#9A8778); text-transform: uppercase; }
.hb-d .dv { font-size: .85rem; font-weight: 700; color: var(--cb-forest,#3E2412); margin-top: 2px; }
.hb-notas { font-size: .74rem; color: var(--cb-muted,#9A8778); margin-top: 10px; display: flex; gap: 6px; align-items: flex-start; }
.hb-notas i { color: var(--soft,#A2907C); margin-top: 2px; }
.hb-room-foot { display: flex; align-items: center; gap: 9px; margin-top: 12px; }
.hb-estado-form { flex: 1; }
.hb-estado-sel { width: 100%; min-height: 44px; border: 1px solid var(--cb-border,#EDE3D2); background: #f8faf7; border-radius: 10px; padding: 9px 30px 9px 12px; font-family: inherit; font-size: .78rem; font-weight: 600; color: var(--cb-forest,#3E2412); appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236b7b69' d='M6 8 0 0h12z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 11px center; }
.hb-act { width: 44px; height: 44px; border-radius: 11px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; cursor: pointer; text-decoration: none; font-size: .82rem; flex: none; }
.hb-act.edit { color: var(--cb-primary,#6B4420); }
.hb-act.del { color: var(--danger,#ef4444); border-color: rgba(239,68,68,.2); }
/* Retirar de servicio es reversible, así que va en gris y no en rojo: el
   rojo queda para Eliminar, que sí es definitivo. */
.hb-act.retirar { color: #64748b; }
.hb-act.retirar:hover { color: #334155; border-color: #cbd5e1; background: #f8fafc; }

/* Botón de reactivar, solo en las habitaciones inactivas. Tiene la misma
   forma que el de check-out pero en gris: devuelve algo al inventario, no
   es una acción del día a día, así que no compite con los verdes. */
.hb-reactivar { display: inline-flex; align-items: center; justify-content: center; gap: 7px; flex: 1; font-family: inherit; font-size: .82rem; font-weight: 700; color: #fff; background: linear-gradient(150deg,#64748b,#475569); border: none; border-radius: 11px; padding: 10px 14px; cursor: pointer; box-shadow: 0 6px 14px rgba(71,85,105,.22); }
.hb-reactivar:hover { background: linear-gradient(150deg,#475569,#334155); }
/* La frase que explica por qué está inactiva. Sin ella, el usuario ve una
   habitación apagada y no sabe si la borró o si se rompió algo. */
.hb-inactiva-nota { font-size: .76rem; color: #64748b; line-height: 1.45; margin-top: 8px; }

/* Resumen de un vistazo (tiles clicables que filtran) */
.hb-summary { display: grid; grid-template-columns: repeat(2,1fr); gap: 9px; margin-bottom: 12px; }
@media (min-width: 560px) { .hb-summary { grid-template-columns: repeat(5,1fr); } }
.hb-stat { display: flex; align-items: center; gap: 11px; padding: 12px 13px; border-radius: 14px; text-decoration: none; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; box-shadow: 0 4px 12px rgba(42,23,9,.04); }
.hb-stat.on { border-color: var(--cb-primary,#6B4420); box-shadow: 0 0 0 2px rgba(107,68,32,.14); }
.hb-stat .hb-dot { width: 11px; height: 11px; border-radius: 50%; flex: none; }
.hb-stat.d .hb-dot { background: #1F7A4D; } .hb-stat.o .hb-dot { background: #f59e0b; }
.hb-stat.l .hb-dot { background: #3b82f6; } .hb-stat.m .hb-dot { background: #ef4444; }
.hb-stat .hb-n { font-size: 1.4rem; font-weight: 800; color: var(--cb-forest,#3E2412); line-height: 1; }
.hb-stat .hb-l { font-size: .68rem; color: var(--cb-muted,#9A8778); font-weight: 600; margin-top: 2px; }
.hb-toolbar { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
.hb-new-btn { display: inline-flex; align-items: center; gap: 7px; font-family: inherit; font-size: .82rem; font-weight: 700; border: none; border-radius: 11px; padding: 9px 15px; cursor: pointer; text-decoration: none; }
.hb-new-btn.pri { color: var(--cb-primary,#6B4420); background: var(--cb-primary-light,#F5EBDC); }
.hb-new-btn.ghost { color: var(--cb-muted,#9A8778); background: var(--gray-100,#F1EADF); }

/* Huésped hospedado + check-out en la tarjeta */
.hb-guest { display: flex; align-items: center; gap: 8px; margin-top: 11px; padding: 9px 11px; border-radius: 11px; background: rgba(249,115,22,.08); font-size: .82rem; color: #b45309; font-weight: 700; }
.hb-co { display: inline-flex; align-items: center; justify-content: center; gap: 6px; flex: 1; font-family: inherit; font-size: .82rem; font-weight: 700; color: #fff; background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); border-radius: 11px; padding: 10px 14px; text-decoration: none; box-shadow: 0 6px 14px rgba(42,23,9,.22); }

.hb-empty { text-align: center; padding: 30px 12px; color: var(--cb-muted,#9A8778); }
.hb-empty i { font-size: 2rem; color: var(--cb-primary,#6B4420); opacity: .4; margin-bottom: 10px; display: block; }
.hb-empty p { font-size: .9rem; margin: 0; }

/* Modal */
.modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,.4); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 9999; opacity: 0; pointer-events: none; transition: opacity .25s; padding: 16px; }
.modal-overlay.active { opacity: 1; pointer-events: auto; }
.confirm-card { background: #fff; width: 100%; max-width: 420px; border-radius: 18px; box-shadow: 0 20px 40px rgba(0,0,0,.18); overflow: hidden; transform: scale(.92); transition: transform .25s cubic-bezier(.34,1.56,.64,1); }
.modal-overlay.active .confirm-card { transform: scale(1); }
.confirm-header { padding: 20px 20px 8px; display: flex; align-items: center; gap: 12px; }
.icon-container-delete { width: 42px; height: 42px; background: rgba(239,68,68,.1); color: var(--danger,#ef4444); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
/* Ámbar y no rojo: retirar del servicio es reversible, eliminar no. */
.icon-container-retirar { width: 42px; height: 42px; background: rgba(245,158,11,.12); color: var(--warning,#f59e0b); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.confirm-title { font-size: 1.05rem; font-weight: 700; color: var(--cb-forest,#3E2412); margin: 0; }
.confirm-body { padding: 0 20px 16px; color: #475569; font-size: .86rem; line-height: 1.5; }
.confirm-actions { padding: 14px 20px 18px; display: flex; justify-content: flex-end; gap: 10px; }
.btn-x { padding: 11px 16px; border-radius: 11px; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; color: #475569; font-weight: 600; cursor: pointer; font-family: inherit; }
.btn-d { padding: 11px 16px; border-radius: 11px; border: none; background: var(--danger,#ef4444); color: #fff; font-weight: 700; cursor: pointer; font-family: inherit; }
.btn-w { padding: 11px 16px; border-radius: 11px; border: none; background: var(--warning,#f59e0b); color: #fff; font-weight: 700; cursor: pointer; font-family: inherit; }
</style>

<div class="hb-wrap">



<?php if ($msg): ?>
    <div class="hb-msg <?= $msgType ?>">
        <i class="fas fa-<?= $msgType === 'success' ? 'circle-check' : ($msgType === 'warning' ? 'triangle-exclamation' : 'circle-xmark') ?>"></i>
        <span><?= htmlspecialchars($msg) ?></span>
    </div>
<?php endif; ?>

<!-- Resumen de un vistazo (clic para filtrar) -->
<div class="hb-summary">
    <?php
    $tiles = [
        'disponible'    => ['d', 'Libres'],
        'ocupada'       => ['o', 'Ocupadas'],
        'limpieza'      => ['l', 'Limpieza'],
        'mantenimiento' => ['m', 'Mant.'],
        'inactiva'      => ['m', 'Inactivas'],
    ];
    foreach ($tiles as $est => $t): ?>
        <a href="?estado=<?= $filtroEstado === $est ? '' : $est ?>" class="hb-stat <?= $t[0] ?> <?= $filtroEstado === $est ? 'on' : '' ?>">
            <span class="hb-dot"></span>
            <div><div class="hb-n"><?= $conteos[$est] ?></div><div class="hb-l"><?= $t[1] ?></div></div>
        </a>
    <?php endforeach; ?>
</div>
<div class="hb-toolbar">
    <?php if ($filtroEstado): ?><a href="?" class="hb-new-btn ghost"><i class="fas fa-list"></i> Ver todas (<?= $totalHab ?>)</a><?php endif; ?>
    <?php if (!$editData): ?><button type="button" class="hb-new-btn pri" style="margin-left:auto;" onclick="toggleNueva()"><i class="fas fa-plus"></i> Nueva habitación</button><?php endif; ?>
</div>

<div class="hb-card">
    <div class="hb-card-h"><h3><i class="fas fa-tags"></i> Tipos, capacidad y tarifas</h3></div>
    <div class="hb-card-b">
        <div class="hb-type-head">
            <span>Tipo</span>
            <span>Personas<small>0 = sin tope</small></span>
            <span>1 persona<small>vacío = no se vende</small></span>
            <span>2 personas<small>vacío = no admite</small></span>
            <span>Adicional<small>por persona de más</small></span>
            <span>Descripción</span>
            <span></span>
        </div>
        <?php foreach ($tipos as $tipo): ?>
            <form method="POST" class="hb-type-form">
                <?= csrfField() ?><input type="hidden" name="action" value="editar_tipo"><input type="hidden" name="tipo_id" value="<?= (int)$tipo['id'] ?>">
                <label class="hb-tf" data-label="Tipo *"><input class="hb-input" name="tipo_nombre" required aria-label="Nombre del tipo" value="<?= htmlspecialchars($tipo['nombre']) ?>"></label>
                <label class="hb-tf" data-label="Personas (0 = sin tope) *"><input class="hb-input" name="tipo_capacidad" type="number" min="0" required data-solo="digitos" aria-label="Capacidad (0 = sin tope)" value="<?= (int)$tipo['capacidad_max'] ?>"></label>
                <label class="hb-tf" data-label="1 persona (vacío = no se vende)"><input class="hb-input" name="tipo_tarifa_1" type="text" inputmode="numeric" autocomplete="off" min="1" data-solo="moneda" aria-label="Tarifa para una persona" value="<?= $tipo['tarifa_1'] !== null ? (int)$tipo['tarifa_1'] : '' ?>" placeholder="—"></label>
                <label class="hb-tf" data-label="2 personas (vacío = no admite)"><input class="hb-input" name="tipo_tarifa_2" type="text" inputmode="numeric" autocomplete="off" min="1" data-solo="moneda" aria-label="Tarifa para dos personas" value="<?= $tipo['tarifa_2'] !== null ? (int)$tipo['tarifa_2'] : '' ?>" placeholder="—"></label>
                <label class="hb-tf" data-label="Adicional (por persona de más)"><input class="hb-input" name="tipo_tarifa_extra" type="text" inputmode="numeric" autocomplete="off" min="0" data-solo="moneda" aria-label="Recargo por persona adicional" value="<?= (int)($tipo['tarifa_extra'] ?? 0) ?>"></label>
                <label class="hb-tf" data-label="Descripción"><input class="hb-input" name="tipo_descripcion" aria-label="Descripción" value="<?= htmlspecialchars($tipo['descripcion'] ?? '') ?>"></label>
                <button class="hb-submit editar" type="submit" style="margin:0;padding:10px"><i class="fas fa-floppy-disk"></i> Guardar</button>
            </form>
        <?php endforeach; ?>
        <form method="POST" class="hb-type-form hb-type-new">
            <?= csrfField() ?><input type="hidden" name="action" value="crear_tipo">
            <label class="hb-tf" data-label="Nuevo tipo *"><input class="hb-input" name="tipo_nombre" required aria-label="Nombre del nuevo tipo" placeholder="Nuevo tipo"></label>
            <label class="hb-tf" data-label="Personas (0 = sin tope) *"><input class="hb-input" name="tipo_capacidad" type="number" min="0" required data-solo="digitos" aria-label="Capacidad (0 = sin tope)" placeholder="Personas"></label>
            <label class="hb-tf" data-label="1 persona (vacío = no se vende)"><input class="hb-input" name="tipo_tarifa_1" type="text" inputmode="numeric" autocomplete="off" min="1" data-solo="moneda" aria-label="Tarifa para una persona" placeholder="1 pers."></label>
            <label class="hb-tf" data-label="2 personas (vacío = no admite)"><input class="hb-input" name="tipo_tarifa_2" type="text" inputmode="numeric" autocomplete="off" min="1" data-solo="moneda" aria-label="Tarifa para dos personas" placeholder="2 pers."></label>
            <label class="hb-tf" data-label="Adicional (por persona de más)"><input class="hb-input" name="tipo_tarifa_extra" type="text" inputmode="numeric" autocomplete="off" min="0" data-solo="moneda" aria-label="Recargo por persona adicional" placeholder="Adicional" value="0"></label>
            <label class="hb-tf" data-label="Descripción"><input class="hb-input" name="tipo_descripcion" aria-label="Descripción" placeholder="Descripción"></label>
            <button class="hb-submit crear" type="submit" style="margin:0;padding:10px"><i class="fas fa-plus"></i> Agregar</button>
        </form>
        <small class="hb-type-help">
            El precio de una noche depende del tipo y de cuántas personas duerman.
            Deja una tarifa <b>vacía</b> si el tipo no se vende a esa ocupación: una
            cama sencilla no admite dos personas, y en una de dos camas la persona
            sola paga igual el precio de dos. El <b>adicional</b> se cobra por cada
            persona a partir de la segunda. Pon la capacidad en <b>0</b> para que no
            tenga tope.
            <br>Al guardar, las tarifas y la capacidad se aplican a todas sus
            habitaciones. Las estancias ya cerradas conservan su tarifa histórica.
        </small>
    </div>
</div>

<!-- Formulario crear / editar (colapsado; se abre al crear o editar) -->
<div class="hb-card" id="hb-form-card" style="display:<?= $editData ? 'block' : 'none' ?>;">
    <div class="hb-card-h">
        <h3>
            <?php if ($editData): ?><i class="fas fa-pen-to-square" style="color:#f59e0b;"></i> Editar habitación
            <?php else: ?><i class="fas fa-plus-circle" style="color:var(--cb-primary,#6B4420);"></i> Nueva habitación<?php endif; ?>
        </h3>
        <?php if ($editData): ?><a href="?" class="cancel"><i class="fas fa-times"></i> Cancelar</a><?php endif; ?>
    </div>
    <div class="hb-card-b">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $editData ? 'editar' : 'crear' ?>">
            <?php if ($editData): ?><input type="hidden" name="habitacion_id" value="<?= $editData['id'] ?>"><?php endif; ?>

            <div class="hb-row2">
                <div class="hb-field">
                    <label><i class="fas fa-hashtag"></i> Número *</label>
                    <input type="text" name="numero" class="hb-input" required value="<?= htmlspecialchars($editData['numero'] ?? '') ?>">
                </div>
                <div class="hb-field">
                    <label><i class="fas fa-layer-group"></i> Piso *</label>
                    <input type="number" name="piso" class="hb-input" required min="1" value="<?= htmlspecialchars($editData['piso'] ?? '') ?>">
                </div>
            </div>

            <div class="hb-field">
                <label><i class="fas fa-bed"></i> Tipo *</label>
                <select name="tipo_id" class="hb-select" required>
                    <option value="">Seleccionar tipo...</option>
                    <?php foreach ($tipos as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= (isset($editData['tipo_id']) && (int)$editData['tipo_id'] === (int)$t['id']) ? 'selected' : '' ?>><?= htmlspecialchars($t['nombre']) ?> · <?= textoCapacidad((int)$t['capacidad_max']) ?> · desde $<?= number_format((float)(tarifaPorPersonas($t, 1) ?? 0), 0, ',', '.') ?>/noche</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <p class="hb-type-help">La capacidad y la tarifa se toman del tipo elegido. Puedes cambiarlas arriba, en Tipos, capacidad y tarifa.</p>

            <div class="hb-field">
                <label><i class="fas fa-sticky-note"></i> Notas</label>
                <textarea name="notas" class="hb-textarea" rows="2" placeholder="Observaciones de la habitación..."><?= htmlspecialchars($editData['notas'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="hb-submit <?= $editData ? 'editar' : 'crear' ?>">
                <i class="fas fa-<?= $editData ? 'floppy-disk' : 'plus' ?>"></i>
                <?= $editData ? 'Guardar cambios' : 'Crear habitación' ?>
            </button>
        </form>
    </div>
</div>

<?php if ($editData): ?>
<?php
// La foto que se ve ahora de esta habitación: la propia, o la de siempre del tipo.
$fotoPropia = fotoHabitacionUrl((int) $editData['id']);
$tipoKeyE = strtolower(trim($editData['tipo_nombre'] ?? ''));
$fotosTipoE = $fotosPorTipo[$tipoKeyE] ?? [1, 2, 3, 4, 5, 6];
$fotoDeSiempre = BASE_URL . '/assets/habitaciones/habitacion-' . str_pad((string) $fotosTipoE[((int) $editData['id'] - 1) % count($fotosTipoE)], 2, '0', STR_PAD_LEFT) . '.webp';
?>
<!-- La foto de esta habitación (4.3.0): la sube el hotel. Va en su propio formulario, porque lleva un archivo. -->
<div class="hb-card" id="hb-foto-card">
    <div class="hb-card-h"><h3><i class="fas fa-camera" style="color:var(--cb-primary,#6B4420);"></i> Foto de la habitación <?= htmlspecialchars($editData['numero']) ?></h3></div>
    <div class="hb-card-b">
        <div class="hb-foto-actual"><img id="hb-foto-vista" src="<?= htmlspecialchars($fotoPropia ?? $fotoDeSiempre) ?>" alt="Foto actual de la habitación <?= htmlspecialchars($editData['numero']) ?>"></div>
        <p class="hb-type-help"><?= $fotoPropia ? 'Esta es la foto que subió el hotel.' : 'Esta es la foto de siempre del tipo. Sube la tuya para reemplazarla.' ?> Formatos JPG, PNG o WebP, hasta 8 MB: el sistema la reduce y la guarda sola.</p>
        <form method="POST" enctype="multipart/form-data" id="hb-foto-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="subir_foto">
            <input type="hidden" name="habitacion_id" value="<?= (int) $editData['id'] ?>">
            <div class="hb-field">
                <label for="hb-foto-archivo"><i class="fas fa-image"></i> Elegir foto *</label>
                <input type="file" id="hb-foto-archivo" name="foto" class="hb-input" accept="image/jpeg,image/png,image/webp" required>
            </div>
            <button type="submit" class="hb-submit editar"><i class="fas fa-upload"></i> Subir foto</button>
        </form>
        <?php if ($fotoPropia): ?>
        <form method="POST" style="margin-top:10px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="quitar_foto">
            <input type="hidden" name="habitacion_id" value="<?= (int) $editData['id'] ?>">
            <button type="submit" class="hb-foto-quitar"><i class="fas fa-rotate-left"></i> Quitar mi foto y volver a la de siempre</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<style>
.hb-foto-actual{border-radius:14px;overflow:hidden;background:#f3ede2;margin-bottom:10px;max-height:260px}
.hb-foto-actual img{display:block;width:100%;max-height:260px;object-fit:cover}
.hb-foto-quitar{width:100%;border:1px solid var(--cb-border,#EDE3D2);background:#fff;color:var(--cb-forest,#3E2412);font:inherit;font-weight:700;padding:11px;border-radius:12px;cursor:pointer}
</style>
<script>
// Al elegir el archivo se ve cómo quedará, y se avisa de una vez si pesa de más (el servidor lo vuelve a comprobar).
(function () {
    var entrada = document.getElementById('hb-foto-archivo');
    var vista = document.getElementById('hb-foto-vista');
    if (!entrada || !vista) return;
    entrada.addEventListener('change', function () {
        var f = entrada.files && entrada.files[0];
        entrada.setCustomValidity(f && f.size > 8 * 1024 * 1024 ? 'Esa foto pesa más de 8 MB. Elige una más liviana.' : '');
        // FileReader (data:) y no createObjectURL (blob:): la política de contenido (auth.php) deja `data:` en img-src y no `blob:`.
        if (f && f.type.indexOf('image/') === 0 && f.size <= 8 * 1024 * 1024) {
            var lector = new FileReader();
            lector.onload = function () { vista.src = lector.result; };
            lector.readAsDataURL(f);
        }
    });
})();
</script>
<?php endif; ?>

<?php if (empty($habitaciones)): ?>
    <div class="hb-empty"><i class="fas fa-door-closed"></i><p>No hay habitaciones para este filtro.</p></div>
<?php else: ?>
    <?php foreach ($habitaciones as $hab): ?>
        <?php
        $tipoKey = strtolower(trim($hab['tipo'] ?? ''));
        // Las fotos se sirven en .webp: las .jpeg gemelas se borraron del
        // servidor por duplicadas. No volver a pedir .jpeg aqui.
        $fotosTipo = $fotosPorTipo[$tipoKey] ?? [1, 2, 3, 4, 5, 6];
        $fotoHabitacion = $fotosTipo[((int)$hab['id'] - 1) % count($fotosTipo)];
        // Si el hotel subió una foto de ESTA habitación, esa; si no, la de siempre (fotos_habitacion.php).
        $urlFoto = fotoHabitacionUrl((int) $hab['id']) ?? (BASE_URL . '/assets/habitaciones/habitacion-' . str_pad((string)$fotoHabitacion, 2, '0', STR_PAD_LEFT) . '.webp');
        ?>
        <div class="hb-room <?= $hab['estado'] ?>">
            <div class="hb-room-top">
                <figure class="hb-room-photo"><img src="<?= htmlspecialchars($urlFoto) ?>" alt="Habitación tipo <?= htmlspecialchars($hab['tipo'] ?? 'disponible') ?>" loading="lazy"></figure>
                <div class="hb-num"><b><?= htmlspecialchars($hab['numero']) ?></b><small>HAB</small></div>
                <div class="hb-room-info">
                    <div class="hb-room-tipo"><?= htmlspecialchars($hab['tipo'] ?? '—') ?></div>
                    <div class="hb-room-meta">Piso <?= $hab['piso'] ?> · <i class="fas fa-user" style="font-size:10px;"></i> <?= textoCapacidad((int)$hab['capacidad_max']) ?></div>
                </div>
                <span class="hb-estado <?= $hab['estado'] ?>"><?= ucfirst($hab['estado']) ?></span>
            </div>

            <div class="hb-room-body">
                <div class="hb-d"><div class="dk">Desde / noche</div><div class="dv">$<?= number_format($hab['tarifa'], 0, ',', '.') ?></div></div>
            </div>

            <?php if ($hab['estado'] === 'ocupada' && !empty($hab['h_nombres'])): ?>
                <div class="hb-guest"><i class="fas fa-user"></i> <?= htmlspecialchars($hab['h_nombres'] . ' ' . $hab['h_apellidos']) ?></div>
            <?php endif; ?>

            <?php if (!empty($hab['notas'])): ?>
                <div class="hb-notas"><i class="fas fa-sticky-note"></i> <?= htmlspecialchars($hab['notas']) ?></div>
            <?php endif; ?>

            <?php if ($hab['estado'] === 'inactiva'): ?>
                <div class="hb-inactiva-nota">
                    <i class="fas fa-box-archive"></i>
                    Fuera de servicio: no se ofrece para reservas. A diferencia de
                    Mantenimiento, es un retiro del inventario, no una pausa.
                    Su historial de estadías se conserva.
                </div>
            <?php endif; ?>

            <div class="hb-room-foot">
                <?php if ($hab['estado'] === 'ocupada'): ?>
                    <a href="<?= BASE_URL ?>/modules/checkout/index.php<?= !empty($hab['estancia_id']) ? '?estancia='.(int)$hab['estancia_id'] : '' ?>" class="hb-co"><i class="fas fa-door-closed"></i> Hacer check-out</a>
                    <a href="?editar=<?= $hab['id'] ?><?= $filtroEstado ? '&estado='.$filtroEstado : '' ?>" class="hb-act edit" title="Editar"><i class="fas fa-pen"></i></a>
                <?php elseif ($hab['estado'] === 'inactiva'): ?>
                    <?php
                    /*
                     * "Inactiva" significa fuera de servicio: la habitación no
                     * se ofrece para reservas. Todas las pantallas que asignan
                     * habitación —obtener_disponibilidad.php, reservar.php, el
                     * check-in, reservas y recepción— filtran por
                     * `estado = 'disponible'`, así que una inactiva no aparece
                     * en ninguna.
                     *
                     * No confundir con "ocupada", que es tener un huésped
                     * dentro, ni con "mantenimiento", que es una pausa. Esta es
                     * un retiro del inventario.
                     *
                     * Se reactiva con un botón que lo dice, no eligiendo
                     * "Disponible" en un desplegable llamado "Cambiar a…".
                     * Técnicamente era lo mismo, pero nadie adivina que ese
                     * menú es también el que la devuelve al servicio
                     * (HCB_RNF_001: los nombres de las acciones deben ser
                     * claros y descriptivos).
                     *
                     * Tampoco se ofrece "Eliminar": ya está retirada, y
                     * borrarla de verdad se llevaría el historial de estadías
                     * que es justo lo que HCB_RF_003 manda conservar.
                     */
                    ?>
                    <form method="POST" style="margin:0; flex:1;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="cambiar_estado">
                        <input type="hidden" name="habitacion_id" value="<?= $hab['id'] ?>">
                        <input type="hidden" name="nuevo_estado" value="disponible">
                        <button type="submit" class="hb-reactivar">
                            <i class="fas fa-rotate-left"></i> Reactivar
                        </button>
                    </form>
                    <a href="?editar=<?= $hab['id'] ?><?= $filtroEstado ? '&estado='.$filtroEstado : '' ?>" class="hb-act edit" title="Editar"><i class="fas fa-pen"></i></a>

                <?php else: ?>
                    <form method="POST" class="hb-estado-form" style="margin:0;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="cambiar_estado">
                        <input type="hidden" name="habitacion_id" value="<?= $hab['id'] ?>">
                        <select name="nuevo_estado" class="hb-estado-sel" onchange="this.form.submit()">
                            <option value="" selected disabled>Cambiar a…</option>
                            <?php foreach (['disponible','limpieza','mantenimiento'] as $est): ?>
                                <?php if ($est !== $hab['estado']): ?>
                                    <option value="<?= $est ?>"><?= ucfirst($est) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <a href="?editar=<?= $hab['id'] ?><?= $filtroEstado ? '&estado='.$filtroEstado : '' ?>" class="hb-act edit" title="Editar"><i class="fas fa-pen"></i></a>

                    <?php
                    /*
                     * Retirar del servicio y eliminar son cosas distintas y
                     * tienen botones distintos.
                     *
                     * "Eliminar" solo aparece cuando la habitación no tiene
                     * estadías ni reservas, que es el único caso en que borrar
                     * es seguro. Antes el mismo botón borraba o inactivaba
                     * según el historial, y no había forma de saber cuál de las
                     * dos cosas iba a pasar antes de pulsarlo.
                     */
                    ?>
                    <?php
                    /* El número va con json_encode y no interpolado dentro del
                       literal de JavaScript. Escaparlo solo con htmlspecialchars
                       no sirve: el navegador decodifica las entidades del
                       atributo ANTES de que lo lea el motor de JS, así que una
                       comilla volvería a cerrar la cadena. */
                    ?>
                    <button type="button" class="hb-act retirar" title="Retirar de servicio: deja de ofrecerse para reservas"
                            onclick="confirmarRetiro(<?= (int)$hab['id'] ?>, <?= htmlspecialchars(json_encode($hab['numero']), ENT_QUOTES) ?>)">
                        <i class="fas fa-box-archive"></i>
                    </button>

                    <?php if ((int)($hab['historial'] ?? 0) === 0): ?>
                        <button type="button" class="hb-act del" title="Eliminar definitivamente" onclick="confirmarEliminacion(<?= (int)$hab['id'] ?>, <?= htmlspecialchars(json_encode($hab['numero']), ENT_QUOTES) ?>)"><i class="fas fa-trash-alt"></i></button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</div><!-- /.hb-wrap -->

<!-- Modal retirar de servicio: misma tarjeta que eliminar, no el aviso del navegador -->
<div id="retirar-modal-overlay" class="modal-overlay">
    <div class="confirm-card">
        <div class="confirm-header">
            <div class="icon-container-retirar"><i class="fas fa-box-archive"></i></div>
            <h4 class="confirm-title">¿Retirar del servicio?</h4>
        </div>
        <div class="confirm-body">
            La <strong id="retirar-room-text" style="color:var(--cb-forest,#3E2412);"></strong> dejará de ofrecerse para reservas.<br><br>
            No se borra nada: su historial de estadías se conserva y <strong>puedes reactivarla cuando quieras</strong>.
            A diferencia de Mantenimiento, esto es un retiro del inventario, no una pausa.
        </div>
        <div class="confirm-actions">
            <button type="button" class="btn-x" onclick="cerrarRetiro()">Cancelar</button>
            <form method="POST" id="form-retirar-confirmado" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="retirar">
                <input type="hidden" name="habitacion_id" id="retirar-hab-id" value="">
                <button type="submit" class="btn-w">Retirar de servicio</button>
            </form>
        </div>
    </div>
</div>

<!-- Modal eliminar (lógica conservada) -->
<div id="delete-modal-overlay" class="modal-overlay">
    <div class="confirm-card">
        <div class="confirm-header">
            <div class="icon-container-delete"><i class="fas fa-trash-alt"></i></div>
            <h4 class="confirm-title">¿Eliminar habitación?</h4>
        </div>
        <div class="confirm-body">
            Vas a remover la <strong id="delete-room-text" style="color:var(--cb-forest,#3E2412);"></strong> del inventario.<br><br>
            <span style="color:var(--danger,#ef4444); font-weight:600;">Es irreversible y solo procede si no tiene estancias ni reservas activas.</span>
        </div>
        <div class="confirm-actions">
            <button type="button" class="btn-x" onclick="cerrarModal()">Cancelar</button>
            <form method="POST" id="form-eliminar-confirmado" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="eliminar">
                <input type="hidden" name="habitacion_id" id="delete-hab-id" value="">
                <button type="submit" class="btn-d">Eliminar</button>
            </form>
        </div>
    </div>
</div>

<script>
const overlay = document.getElementById('delete-modal-overlay');
const deleteIdInput = document.getElementById('delete-hab-id');
const roomText = document.getElementById('delete-room-text');
function confirmarEliminacion(id, numeroHabitacion) {
    deleteIdInput.value = id;
    roomText.textContent = "Habitación " + numeroHabitacion;
    overlay.classList.add('active');
}
function cerrarModal() { overlay.classList.remove('active'); }
overlay.addEventListener('click', function(e) { if (e.target === overlay) cerrarModal(); });

// ── Retirar de servicio ──
// Antes usaba el confirm() del navegador, que se ve como un aviso del
// sistema operativo y no como el resto de la aplicación.
const retirarOverlay = document.getElementById('retirar-modal-overlay');
function confirmarRetiro(id, numeroHabitacion) {
    document.getElementById('retirar-hab-id').value = id;
    document.getElementById('retirar-room-text').textContent = "habitación " + numeroHabitacion;
    retirarOverlay.classList.add('active');
}
function cerrarRetiro() { retirarOverlay.classList.remove('active'); }
retirarOverlay.addEventListener('click', function(e) { if (e.target === retirarOverlay) cerrarRetiro(); });

function toggleNueva() {
    var c = document.getElementById('hb-form-card');
    var visible = c.style.display !== 'none' && c.style.display !== '';
    c.style.display = visible ? 'none' : 'block';
    if (!visible) c.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
