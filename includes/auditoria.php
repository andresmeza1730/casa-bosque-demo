<?php
// ============================================================
// includes/auditoria.php
// La auditoría: qué se guarda, con qué gravedad, qué se alerta y cómo se filtra
// ============================================================
// UNA SOLA FUENTE. Antes, cada pantalla que mostraba la auditoría decidía a su
// manera qué era «crear» o «editar»; ahora lo decide este archivo y nadie más:
//
//   - la GRAVEDAD de cada acción (INFO / WARNING / CRITICAL),
//   - la CATEGORÍA (CREATE / READ / UPDATE / DELETE / LOGIN / LOGIN_FAIL / LOGOUT),
//     que se DERIVA de la acción guardada: lo guardado no cambia,
//   - el MÓDULO al que pertenece cada registro,
//   - la LISTA BLANCA de campos que pueden guardarse como «antes» y «después»,
//   - las ALERTAS (ráfagas de fallos, cambio de IP, dispositivo anómalo),
//   - los FILTROS, que usan la pantalla y las dos exportaciones por igual.
//
// POR QUÉ LA LISTA BLANCA ES TAN ESTRICTA
//
// La auditoría no se puede corregir ni borrar (dos disparadores lo impiden), y
// la Ley 1581 obliga a poder anonimizar a un huésped. Si el «antes» de un cambio
// guardara el nombre o el teléfono, anonimizar al huésped dejaría su dato
// personal vivo para siempre en un registro que nadie puede tocar. Por eso aquí
// solo entran campos de negocio (tarifas, estados, fechas, montos, roles), y de
// un huésped solo se guarda QUÉ CAMPOS cambiaron, nunca su valor.
// (Esto vale para las columnas NUEVAS. El texto libre `detalle` de los registros
// de huéspedes —«Huésped #5: nombre apellido»— es de antes y no se tocó: queda
// anotado como pendiente.)
//
// POR QUÉ LAS ALERTAS NO GEOLOCALIZAN
//
// Una IP no es un lugar, y consultar un servicio de geolocalización es mandarle a
// un tercero las IP de quien entra al sistema. Las alertas se basan en lo que sí
// se sabe sin salir del servidor: la IP, el navegador y la hora. Por eso la de
// «viaje imposible» se llama «cambio de IP sospechoso»: dos IP distintas en pocos
// minutos pueden ser una persona de verdad en dos sitios o un teléfono que pasó
// del wifi a los datos. Solo avisa; no cierra ninguna sesión.
// ------------------------------------------------------------

require_once __DIR__ . '/login_throttle.php';   // LOGIN_MAX_INTENTOS y LOGIN_VENTANA_MINUTOS: la ráfaga es esa misma regla

const AUDITORIA_RANGO_DEFECTO_DIAS = 7;
const AUDITORIA_RANGO_MAXIMO_DIAS  = 365;
const AUDITORIA_ALERTA_ACTIVA_HORAS = 24;   // una alerta cuenta como «activa» ese tiempo
const AUDITORIA_ALERTAS_DIAS        = 7;    // y se lista mientras tenga menos de estos días
const AUDITORIA_TOPE_FALLOS_POR_IP  = 20;   // registros de login_fallido por IP en la ventana: nadie llena la tabla
const AUDITORIA_CAMBIO_IP_MINUTOS   = 30;
const AUDITORIA_TOPE_FILAS          = 200;

// ── Gravedad ─────────────────────────────────────────────────────────────

const AUDITORIA_ACCIONES_CRITICAS = [
    'respaldo', 'reiniciar', 'exportar_csv', 'anonimizar', 'eliminar',
    'desactivar_2fa', 'reabrir_cuenta', 'anular',
];
const AUDITORIA_ACCIONES_ADVERTENCIA = [
    'cambio_estado', 'retirar', 'cancelar', 'inasistencia', 'anular_consumo',
    'cerrar_sesion', 'cerrar_sesiones', 'quitar_equipo', 'quitar_equipos',
    'regenerar_backup_2fa', 'login_fallido', 'exportar_ingresos', 'exportar_auditoria',
    'editar_tipo',   // cambia las tarifas de todas las habitaciones del tipo
    'login_bloqueado', 'login_2fa_fallido',   // 4.1.0: un acceso frenado y un segundo factor equivocado
];

/** Los intentos de acceso que NO entraron. Los cuenta el indicador y la gráfica; la ráfaga de alertas sigue mirando solo login_fallido. */
const AUDITORIA_ACCIONES_FALLO = ['login_fallido', 'login_bloqueado', 'login_2fa_fallido'];

/** Lo que cuenta como «una conexión» en el historial de conexiones. login_2fa y login_equipo_confiable dicen CÓMO se entró: ya hay un login. */
const AUDITORIA_ACCIONES_CONEXION = ['login', 'login_fallido', 'login_bloqueado', 'login_2fa_fallido', 'logout'];

/** INFO, WARNING o CRITICAL para una acción ya conocida del sistema. */
function auditoriaSeveridad(string $tabla, string $accion): string {
    if (in_array($accion, AUDITORIA_ACCIONES_CRITICAS, true)) return 'CRITICAL';
    if (in_array($accion, AUDITORIA_ACCIONES_ADVERTENCIA, true)) return 'WARNING';
    // Tocar cuentas del personal nunca es una acción cualquiera.
    if ($tabla === 'usuarios' && in_array($accion, ['crear', 'editar'], true)) return 'WARNING';
    return 'INFO';
}

// ── Categoría (derivada, no guardada) ────────────────────────────────────

/** CREATE / READ / UPDATE / DELETE / LOGIN / LOGIN_FAIL / LOGOUT. */
function auditoriaCategoria(string $accion): string {
    if (in_array($accion, AUDITORIA_ACCIONES_FALLO, true)) return 'LOGIN_FAIL';
    if (in_array($accion, ['login', 'login_2fa', 'login_equipo_confiable'], true)) return 'LOGIN';
    if ($accion === 'logout') return 'LOGOUT';
    if (in_array($accion, ['eliminar', 'quitar_acompanante', 'quitar_equipo', 'quitar_equipos', 'reiniciar'], true)) return 'DELETE';
    // Lo que SACA información del sistema hacia un archivo. Las lecturas en pantalla
    // no se auditan: sería un registro por cada vista, y nadie lo pidió.
    if (in_array($accion, ['exportar_csv', 'exportar_ingresos', 'exportar_auditoria', 'respaldo'], true)) return 'READ';
    if (in_array($accion, ['crear', 'crear_tipo', 'agregar', 'venta', 'registrar', 'emitir', 'checkin',
                           'registrar_acompanante', 'activar_2fa', 'recordar_equipo'], true)) return 'CREATE';
    return 'UPDATE';
}

// ── Módulo ───────────────────────────────────────────────────────────────

const AUDITORIA_ACCIONES_AUTENTICACION = ['login', 'login_2fa', 'login_equipo_confiable', 'login_fallido', 'login_bloqueado',
                                          'login_2fa_fallido', 'logout', 'codigo_accion_critica'];
const AUDITORIA_ACCIONES_SEGURIDAD = ['activar_2fa', 'desactivar_2fa', 'regenerar_backup_2fa', 'cerrar_sesion',
                                      'cerrar_sesiones', 'quitar_equipo', 'quitar_equipos', 'recordar_equipo'];

/**
 * Los módulos del sistema y qué registros son de cada uno. Cada regla es
 * [tablas, acciones o null (cualquiera), acciones excluidas o null].
 * Las reglas no se pisan entre módulos, así que el orden no decide nada.
 */
function auditoriaModulos(): array {
    return [
        'Autenticación'        => [[['usuarios'], AUDITORIA_ACCIONES_AUTENTICACION, null]],
        'Seguridad (2FA y sesiones)' => [[['usuarios'], AUDITORIA_ACCIONES_SEGURIDAD, null]],
        'Usuarios'             => [[['usuarios'], null, array_merge(AUDITORIA_ACCIONES_AUTENTICACION, AUDITORIA_ACCIONES_SEGURIDAD)]],
        'Huéspedes'            => [[['huespedes'], null, null]],
        'Reservas'             => [[['reservas'], null, null]],
        'Check-in'             => [[['estancias'], ['checkin'], null], [['acompanantes'], null, null]],
        'Check-out'            => [[['estancias'], ['checkout', 'reabrir_cuenta'], null]],
        'Caja y pagos'         => [[['pagos'], null, null], [['estancias'], ['pago_deuda'], null]],
        'Consumos'             => [[['consumos', 'ventas_mostrador'], null, null]],
        'Carta y Restaurante'  => [[['productos', 'categorias_producto'], null, null]],   // 5.0.0: las categorías de la carta
        'Habitaciones'         => [[['habitaciones', 'tipos_habitacion'], null, null]],
        'Facturación'          => [[['facturas'], null, null]],
        'Reportes'             => [[['estancias'], ['exportar_ingresos'], null], [['auditoria'], ['exportar_auditoria'], null]],
        'Sistema'              => [[['sistema'], null, null]],
    ];
}

/** El nombre del módulo de un registro; si no es de ninguno, la tabla tal cual. */
function auditoriaModulo(string $tabla, string $accion): string {
    foreach (auditoriaModulos() as $nombre => $reglas) {
        foreach ($reglas as [$tablas, $acciones, $excluir]) {
            if (!in_array($tabla, $tablas, true)) continue;
            if ($acciones !== null && !in_array($accion, $acciones, true)) continue;
            if ($excluir !== null && in_array($accion, $excluir, true)) continue;
            return $nombre;
        }
    }
    return $tabla;
}

/** [sql, params] de un módulo, para filtrar; null si el módulo no existe. Alias de la tabla: a. */
function auditoriaModuloSql(string $nombre): ?array {
    $mods = auditoriaModulos();
    if (!isset($mods[$nombre])) return null;
    $partes = []; $params = [];
    foreach ($mods[$nombre] as [$tablas, $acciones, $excluir]) {
        $p = 'a.tabla_afectada in (' . implode(',', array_fill(0, count($tablas), '?')) . ')';
        array_push($params, ...$tablas);
        if ($acciones !== null) {
            $p .= ' and a.accion in (' . implode(',', array_fill(0, count($acciones), '?')) . ')';
            array_push($params, ...$acciones);
        }
        if ($excluir !== null) {
            $p .= ' and a.accion not in (' . implode(',', array_fill(0, count($excluir), '?')) . ')';
            array_push($params, ...$excluir);
        }
        $partes[] = '(' . $p . ')';
    }
    return ['(' . implode(' or ', $partes) . ')', $params];
}

// ── ¿Ya se ejecutó el SQL? ───────────────────────────────────────────────

/**
 * ¿Existen en `auditoria` las columnas de 4.0.0? Mientras no se ejecute
 * sql/migration_026_auditoria_ampliada.sql el sistema escribe la auditoría como
 * siempre y la pantalla avisa. Se pregunta una vez por petición.
 */
function auditoriaColumnas(PDO $pdo, bool $olvidar = false): bool {
    static $hay = null;
    if ($olvidar) { $hay = null; }
    if ($hay !== null) return $hay;
    try {
        $hay = (bool) $pdo->query("show columns from auditoria like 'severidad'")->fetchColumn();
    } catch (Throwable $e) {
        $hay = false;
    }
    return $hay;
}

// ── Lo que se guarda como antes / después ────────────────────────────────

/** Campos de negocio que pueden guardarse. De los huéspedes NO hay lista: ver auditoriaCambiaCampos(). */
const AUDITORIA_CAMPOS = [
    'habitaciones'     => ['numero', 'piso', 'tipo_id', 'capacidad_max', 'tarifa', 'estado'],
    'tipos_habitacion' => ['nombre', 'capacidad_max', 'tarifa_1', 'tarifa_2', 'tarifa_extra'],
    'productos'        => ['nombre', 'precio', 'categoria', 'activo'],
    // 5.0.0: las categorías de la carta. SIN `clave`: «clave» está en la lista de nombres que NUNCA se guardan (más abajo: es el nombre de una contraseña),
    // así que el código de la categoría va en el `detalle` del registro y aquí solo el nombre y si está activa.
    'categorias_producto' => ['nombre', 'activa'],
    'reservas'         => ['habitacion_id', 'fecha_entrada', 'fecha_salida', 'num_personas', 'estado', 'abono_reserva', 'metodo_abono'],
    'usuarios'         => ['rol', 'activo'],
    'facturas'         => ['estado', 'total'],
];

/** Nombres de campo que NUNCA se guardan, aunque alguien los ponga en una lista. */
const AUDITORIA_CAMPO_PROHIBIDO = '/pass|clave|hash|secret|token|twofa|backup|documento|email|correo|telefono|direccion|nombres|apellidos|firma|cookie|sid/i';

/**
 * El registro como está AHORA, solo con los campos de la lista blanca de su
 * tabla. Se llama antes del cambio (para el «antes») y después (para el
 * «después»). Null si la tabla no tiene lista o el registro no existe.
 */
function auditoriaFila(PDO $pdo, string $tabla, int $id): ?array {
    if (!isset(AUDITORIA_CAMPOS[$tabla])) return null;
    // La tabla y las columnas salen de la constante de arriba, nunca de una entrada.
    $st = $pdo->prepare('select ' . implode(', ', AUDITORIA_CAMPOS[$tabla]) . " from `$tabla` where id = ?");
    $st->execute([$id]);
    $fila = $st->fetch(PDO::FETCH_ASSOC);
    return $fila ?: null;
}

/**
 * De dos versiones COMPLETAS de un registro (que no se guardan), los nombres de
 * los campos que cambiaron. Es lo único que se guarda de un huésped.
 */
function auditoriaCambiaCampos(array $antes, array $despues): array {
    $cambian = [];
    foreach ($despues as $campo => $valor) {
        if (!array_key_exists($campo, $antes)) continue;
        if ((string) $antes[$campo] !== (string) $valor) $cambian[] = (string) $campo;
    }
    return ['campos_modificados' => $cambian];
}

/** Pasa un arreglo por la lista de prohibidos y lo vuelve JSON; null si no queda nada. */
function auditoriaDatos(?array $datos): ?string {
    if (!$datos) return null;
    $limpio = [];
    foreach ($datos as $campo => $valor) {
        $campo = (string) $campo;
        if (preg_match(AUDITORIA_CAMPO_PROHIBIDO, $campo)) continue;
        if (is_array($valor)) {
            $valor = implode(', ', array_map('strval', array_filter($valor, 'is_scalar')));
        } elseif ($valor !== null && !is_scalar($valor)) {
            continue;
        }
        $limpio[$campo] = is_string($valor) ? mb_substr($valor, 0, 200) : $valor;
        if (count($limpio) >= 30) break;
    }
    if (!$limpio) return null;
    return json_encode($limpio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** El navegador de quien pide, recortado. */
function auditoriaAgente(): string {
    return mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
}

/** SHA-256 del id de sesión (el mismo criterio que sesiones_activas.sid_hash), o null sin sesión. */
function auditoriaSesionHash(): ?string {
    $sid = session_id();
    return ($sid === '' || $sid === false) ? null : hash('sha256', $sid);
}

/**
 * Registra un intento de acceso que NO entró (clave mala, acceso frenado, segundo factor equivocado).
 * Como la auditoría no se puede borrar, escribe solo mientras esa IP tenga menos de
 * AUDITORIA_TOPE_FALLOS_POR_IP registros DE ESA ACCIÓN en la ventana: un desconocido que insiste no puede
 * llenar la tabla. Es la ÚNICA puerta de estos eventos, para que el tope no viva en tres sitios.
 * Devuelve si escribió.
 */
function auditoriaRegistrarEventoAcceso(PDO $pdo, string $accion, string $detalle, string $ip, ?int $usuarioId): bool {
    if (!in_array($accion, AUDITORIA_ACCIONES_FALLO, true)) return false;
    $st = $pdo->prepare("select count(*) from auditoria
                         where accion = ? and ip_address = ?
                           and created_at > (now() - interval ? minute)");
    $st->bindValue(1, $accion);
    $st->bindValue(2, $ip);
    $st->bindValue(3, LOGIN_VENTANA_MINUTOS, PDO::PARAM_INT);
    $st->execute();
    if ((int) $st->fetchColumn() >= AUDITORIA_TOPE_FALLOS_POR_IP) return false;

    registrarAuditoria($pdo, 'usuarios', $usuarioId, $accion, $detalle, null, null, $usuarioId);
    return true;
}

/** El correo que se tecleó: puede ser un error de dedo o un ataque. Sin caracteres de control y recortado. */
function auditoriaCorreoTecleado(string $email): string {
    return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $email) ?? '', 0, 150);
}

/**
 * Un intento fallido de acceso. $cuentaDesactivada deja constancia, SOLO en la auditoría, de que el correo
 * existe pero la cuenta está desactivada (la pantalla de acceso sigue diciendo lo mismo en los dos casos).
 */
function auditoriaRegistrarFalloLogin(PDO $pdo, string $email, string $ip, ?int $usuarioId, bool $cuentaDesactivada = false): bool {
    return auditoriaRegistrarEventoAcceso($pdo, 'login_fallido',
        'Intento fallido de acceso con el correo «' . auditoriaCorreoTecleado($email) . '»'
        . ($cuentaDesactivada ? ' (cuenta desactivada)' : ''), $ip, $usuarioId);
}

/** Un acceso rechazado por el bloqueo de intentos (login_throttle.php). */
function auditoriaRegistrarBloqueoLogin(PDO $pdo, string $email, string $ip, ?int $usuarioId, int $minutos): bool {
    return auditoriaRegistrarEventoAcceso($pdo, 'login_bloqueado',
        'Acceso bloqueado por intentos fallidos con el correo «' . auditoriaCorreoTecleado($email) . '»; quedan ' . max(1, $minutos) . ' min',
        $ip, $usuarioId);
}

/** Un código del segundo factor equivocado (o el segundo factor bloqueado tras 5 intentos). */
function auditoriaRegistrar2faFallido(PDO $pdo, string $ip, int $usuarioId, string $nota): bool {
    return auditoriaRegistrarEventoAcceso($pdo, 'login_2fa_fallido', $nota, $ip, $usuarioId);
}

// ── Filtros: los de la pantalla y los de las exportaciones ───────────────

/** Fecha Y-m-d real, o null. */
function auditoriaFechaValida($valor): ?string {
    if (!is_string($valor) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return ($d && $d->format('Y-m-d') === $valor) ? $valor : null;
}

/**
 * Lee los filtros de $_GET (o de lo que se pase), los valida UNO A UNO y arma el
 * where. Todo valor va como parámetro. Devuelve:
 *   desde, hasta, usuario, modulo, severidad, ip, accion  → los valores ya limpios
 *   where, params                                          → el SQL, con alias a
 *   severidad_ignorada                                     → true si se pidió gravedad y aún no hay columna
 */
function auditoriaFiltros(PDO $pdo, array $get): array {
    $hoy = date('Y-m-d');
    $hasta = auditoriaFechaValida($get['hasta'] ?? null) ?? $hoy;
    $desde = auditoriaFechaValida($get['desde'] ?? null)
          ?? date('Y-m-d', strtotime($hasta . ' -' . (AUDITORIA_RANGO_DEFECTO_DIAS - 1) . ' days'));
    if ($desde > $hasta) { [$desde, $hasta] = [$hasta, $desde]; }
    $minimo = date('Y-m-d', strtotime($hasta . ' -' . (AUDITORIA_RANGO_MAXIMO_DIAS - 1) . ' days'));
    if ($desde < $minimo) $desde = $minimo;

    $where  = ['a.created_at >= ?', 'a.created_at < ?'];
    $params = [$desde . ' 00:00:00', date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'];

    $usuario = (int) ($get['usuario'] ?? 0);
    if ($usuario > 0) { $where[] = 'a.usuario_id = ?'; $params[] = $usuario; } else { $usuario = 0; }

    $modulo = (string) ($get['modulo'] ?? '');
    $sqlMod = $modulo !== '' ? auditoriaModuloSql($modulo) : null;
    if ($sqlMod) { $where[] = $sqlMod[0]; array_push($params, ...$sqlMod[1]); } else { $modulo = ''; }

    $severidad = strtoupper((string) ($get['severidad'] ?? ''));
    $ignorada = false;
    if (!in_array($severidad, ['INFO', 'WARNING', 'CRITICAL'], true)) {
        $severidad = '';
    } elseif (auditoriaColumnas($pdo)) {
        $where[] = 'a.severidad = ?'; $params[] = $severidad;
    } else {
        $ignorada = true;
    }

    $ip = trim((string) ($get['ip'] ?? ''));
    if ($ip !== '' && preg_match('/^[0-9a-fA-F:.]{1,45}$/', $ip)) {
        $where[] = 'a.ip_address like ?'; $params[] = $ip . '%';
    } else { $ip = ''; }

    $accion = (string) ($get['accion'] ?? '');
    if ($accion !== '' && preg_match('/^[a-z0-9_]{1,30}$/', $accion)) {
        $where[] = 'a.accion = ?'; $params[] = $accion;
    } else { $accion = ''; }

    return [
        'desde' => $desde, 'hasta' => $hasta, 'usuario' => $usuario, 'modulo' => $modulo,
        'severidad' => $severidad, 'ip' => $ip, 'accion' => $accion,
        'where' => implode(' and ', $where), 'params' => $params, 'severidad_ignorada' => $ignorada,
    ];
}

/** Una fila de la base, lista para mostrar o exportar. */
function auditoriaFilaVista(array $r): array {
    require_once __DIR__ . '/sesiones.php';   // sesionNavegador()
    $accion = (string) $r['accion'];
    $tabla  = (string) $r['tabla_afectada'];
    $antes   = !empty($r['datos_antes'])   ? json_decode((string) $r['datos_antes'], true)   : null;
    $despues = !empty($r['datos_despues']) ? json_decode((string) $r['datos_despues'], true) : null;
    return [
        'id'        => (int) $r['id'],
        'fecha'     => (string) $r['created_at'],
        'fecha_vista' => date('d/m/Y H:i:s', strtotime((string) $r['created_at'])) . ' (-05)',
        'usuario'   => (string) ($r['usuario'] ?? '') !== '' ? (string) $r['usuario'] : 'Sistema',
        'accion'    => $accion,
        'categoria' => auditoriaCategoria($accion),
        'modulo'    => auditoriaModulo($tabla, $accion),
        'tabla'     => $tabla,
        'registro'  => $r['registro_id'] !== null ? (int) $r['registro_id'] : null,
        // La que se guardó; si es una fila de antes del SQL, la que le corresponde hoy.
        'severidad' => !empty($r['severidad']) ? (string) $r['severidad'] : auditoriaSeveridad($tabla, $accion),
        'detalle'   => (string) ($r['detalle'] ?? ''),
        'ip'        => (string) ($r['ip_address'] ?? ''),
        'agente'    => isset($r['user_agent']) && $r['user_agent'] !== null && $r['user_agent'] !== '' ? sesionNavegador((string) $r['user_agent']) : '',
        'antes'     => is_array($antes) ? $antes : null,
        'despues'   => is_array($despues) ? $despues : null,
    ];
}

/**
 * Los registros que piden los filtros, del más reciente al más antiguo, y cuántos
 * hay en total. Es la consulta de la pantalla y de las dos exportaciones.
 */
function auditoriaConsulta(PDO $pdo, array $f, int $limite = AUDITORIA_TOPE_FILAS): array {
    $nuevas = auditoriaColumnas($pdo);
    $cols = 'a.id, a.tabla_afectada, a.registro_id, a.accion, a.detalle, a.usuario_id, a.ip_address, a.created_at, u.nombre as usuario'
          . ($nuevas ? ', a.severidad, a.user_agent, a.datos_antes, a.datos_despues' : '');
    $st = $pdo->prepare("select $cols from auditoria a left join usuarios u on u.id = a.usuario_id
                         where {$f['where']} order by a.created_at desc, a.id desc limit " . (int) $limite);
    $st->execute($f['params']);
    $filas = array_map('auditoriaFilaVista', $st->fetchAll(PDO::FETCH_ASSOC));

    $ct = $pdo->prepare("select count(*) from auditoria a where {$f['where']}");
    $ct->execute($f['params']);
    return ['filas' => $filas, 'total' => (int) $ct->fetchColumn()];
}

// ── El historial de conexiones (Seguridad) ───────────────────────────────

/**
 * Cómo se llama el resultado de una conexión. «Contraseña errónea» y «correo desconocido» solo se distinguen
 * en la auditoría: la pantalla de acceso dice lo mismo en los dos casos, para no enumerar cuentas.
 *
 * @return array{0:string,1:string} [clave, etiqueta]
 */
function auditoriaResultadoConexion(string $accion, string $detalle, bool $conUsuario): array {
    switch ($accion) {
        case 'login':             return ['exito', 'ÉXITO (Login)'];
        case 'logout':            return ['salida', 'CIERRE DE SESIÓN'];
        case 'login_bloqueado':   return ['bloqueado', 'BLOQUEADO'];
        case 'login_2fa_fallido': return ['2fa', '2FA FALLIDO'];
        case 'login_fallido':
            // La marca va AL FINAL del detalle, puesta por el sistema: un correo tecleado no puede suplantarla.
            if (str_ends_with($detalle, '(cuenta desactivada)')) return ['desactivada', 'CUENTA DESACTIVADA'];
            return $conUsuario ? ['clave', 'CONTRASEÑA ERRÓNEA'] : ['correo', 'CORREO DESCONOCIDO'];
    }
    return ['otro', strtoupper($accion)];
}

/**
 * Las conexiones (éxitos, fallos, bloqueos, 2FA fallido y cierres) de un usuario o de todo el personal, con su
 * dispositivo y su resultado. Sale de la MISMA consulta y los mismos filtros que la pantalla de Auditoría.
 *
 * @param array    $get          lo que llegó por GET (desde, hasta y, solo para el administrador, usuario, resultado, ip)
 * @param int|null $soloUsuario  un id: SOLO lo de esa persona, y lo demás de $get se ignora (así quien manda
 *                               `?usuario=` ajeno no recibe nada de más); null = todo el personal (lo comprueba quien llama)
 */
function auditoriaHistorialConexiones(PDO $pdo, array $get, ?int $soloUsuario = null, int $limite = 60): array {
    require_once __DIR__ . '/sesiones.php';   // sesionDispositivo()
    $in = $get;
    if ($soloUsuario !== null) {
        $in = array_intersect_key($get, ['desde' => 1, 'hasta' => 1]);
        $in['usuario'] = $soloUsuario;
    }
    if (empty($in['desde'])) $in['desde'] = date('Y-m-d', strtotime('-29 days'));
    $f = auditoriaFiltros($pdo, $in);

    $porResultado = ['exito' => ['login'], 'clave' => ['login_fallido'], 'bloqueado' => ['login_bloqueado'],
                     '2fa' => ['login_2fa_fallido'], 'salida' => ['logout']];
    $res = $soloUsuario === null ? (string) ($get['resultado'] ?? '') : '';
    $acciones = ($res !== '' && isset($porResultado[$res])) ? $porResultado[$res] : AUDITORIA_ACCIONES_CONEXION;

    $nuevas = auditoriaColumnas($pdo);
    $cols = 'a.id, a.tabla_afectada, a.registro_id, a.accion, a.detalle, a.usuario_id, a.ip_address, a.created_at, u.nombre as usuario'
          . ($nuevas ? ', a.severidad, a.user_agent' : '');
    $st = $pdo->prepare("select $cols from auditoria a left join usuarios u on u.id = a.usuario_id
                         where {$f['where']} and a.accion in (" . implode(',', array_fill(0, count($acciones), '?')) . ")
                         order by a.created_at desc, a.id desc limit " . max(1, $limite));
    $st->execute(array_merge($f['params'], $acciones));

    $filas = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $v = auditoriaFilaVista($r);
        $v['resultado']   = auditoriaResultadoConexion((string) $r['accion'], (string) ($r['detalle'] ?? ''), $r['usuario_id'] !== null);
        $v['dispositivo'] = sesionDispositivo($r['user_agent'] ?? null);
        $v['usuario_id']  = $r['usuario_id'] !== null ? (int) $r['usuario_id'] : null;
        $filas[] = $v;
    }
    return ['filas' => $filas, 'filtros' => $f];
}

/** Una lista de acciones lista para ir dentro de un `in (...)`. Sale SOLO de las constantes de este archivo, nunca de una entrada. */
function _auditoriaListaSql(array $acciones): string {
    return "'" . implode("', '", $acciones) . "'";
}

/** Los cuatro números de arriba de la pantalla, con los mismos filtros. */
function auditoriaIndicadores(PDO $pdo, array $f): array {
    $fallos = _auditoriaListaSql(AUDITORIA_ACCIONES_FALLO);
    $st = $pdo->prepare("select count(*) as total,
                                count(distinct a.usuario_id) as usuarios,
                                coalesce(sum(a.accion in ($fallos)), 0) as fallos
                         from auditoria a where {$f['where']}");
    $st->execute($f['params']);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['total' => (int) ($r['total'] ?? 0), 'usuarios' => (int) ($r['usuarios'] ?? 0), 'fallos' => (int) ($r['fallos'] ?? 0)];
}

/** Los usuarios que aparecen en la auditoría, para el filtro. */
function auditoriaUsuarios(PDO $pdo): array {
    return $pdo->query("select distinct u.id, u.nombre from auditoria a join usuarios u on u.id = a.usuario_id order by u.nombre")
               ->fetchAll(PDO::FETCH_ASSOC);
}

// ── La serie de la gráfica ───────────────────────────────────────────────

/**
 * Acciones y fallos de acceso por día (por hora si el rango es de un solo día),
 * con los huecos en cero. Los dos eventos «de acompañamiento» del inicio de
 * sesión (login_2fa, login_equipo_confiable) no cuentan: ya hay un `login`, y
 * contarlos de nuevo duplicaría cada entrada en el pico de la mañana.
 */
function auditoriaSerie(PDO $pdo, array $f): array {
    $porHora = ($f['desde'] === $f['hasta']);
    $cubo = $porHora ? "date_format(a.created_at, '%Y-%m-%d %H:00:00')" : 'date(a.created_at)';
    $fallos = _auditoriaListaSql(AUDITORIA_ACCIONES_FALLO);
    $st = $pdo->prepare("select $cubo as cubo,
                                sum(a.accion not in ($fallos, 'login_2fa', 'login_equipo_confiable')) as acciones,
                                sum(a.accion in ($fallos)) as fallos
                         from auditoria a where {$f['where']} group by cubo");
    $st->execute($f['params']);
    $datos = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $datos[(string) $r['cubo']] = [(int) $r['acciones'], (int) $r['fallos']];
    }

    $etiquetas = []; $acciones = []; $fallos = [];
    if ($porHora) {
        for ($h = 0; $h < 24; $h++) {
            $clave = sprintf('%s %02d:00:00', $f['desde'], $h);
            $etiquetas[] = sprintf('%02d h', $h);
            $acciones[]  = $datos[$clave][0] ?? 0;
            $fallos[]    = $datos[$clave][1] ?? 0;
        }
    } else {
        for ($d = strtotime($f['desde']); $d <= strtotime($f['hasta']); $d += 86400) {
            $clave = date('Y-m-d', $d);
            $etiquetas[] = date('d/m', $d);
            $acciones[]  = $datos[$clave][0] ?? 0;
            $fallos[]    = $datos[$clave][1] ?? 0;
        }
    }
    return ['por_hora' => $porHora, 'etiquetas' => $etiquetas, 'acciones' => $acciones, 'fallos' => $fallos];
}

/** La gráfica como SVG, dibujada aquí: sin librería ni red. Ancho 100 %, con <title> y <desc>. */
function auditoriaGraficoSvg(array $serie): string {
    $n = count($serie['etiquetas']);
    $w = 720; $h = 240; $izq = 40; $der = 12; $arr = 14; $aba = 30;
    $max = max(1, max($serie['acciones'] ?: [0]), max($serie['fallos'] ?: [0]));
    $paso = $max <= 5 ? 1 : (int) ceil($max / 5);
    $tope = $paso * (int) ceil($max / $paso);
    $x = fn(int $i) => $n > 1 ? $izq + ($w - $izq - $der) * $i / ($n - 1) : ($izq + ($w - $izq - $der) / 2);
    $y = fn($v) => $arr + ($h - $arr - $aba) * (1 - $v / $tope);

    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $totA = array_sum($serie['acciones']); $totF = array_sum($serie['fallos']);
    $svg  = '<svg class="au-grafico" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-labelledby="au-gt au-gd" preserveAspectRatio="xMidYMid meet">';
    $svg .= '<title id="au-gt">Actividad y accesos fallidos</title>';
    $svg .= '<desc id="au-gd">' . $e($totA . ' acciones y ' . $totF . ' intentos fallidos de acceso, '
          . ($serie['por_hora'] ? 'por hora' : 'por día') . '.') . '</desc>';
    for ($g = 0; $g <= $tope; $g += $paso) {
        $yy = round($y($g), 1);
        $svg .= '<line x1="' . $izq . '" y1="' . $yy . '" x2="' . ($w - $der) . '" y2="' . $yy . '" stroke="#EDE3D2" stroke-width="1"/>';
        // El tamaño de la letra lo pone la hoja de estilos de la pantalla (clase au-eje): la gráfica se
        // encoge con el ancho, y a 375 px una letra de 11 unidades salía de 5 px y no se leía.
        $svg .= '<text class="au-eje" x="' . ($izq - 6) . '" y="' . ($yy + 4) . '" text-anchor="end">' . $g . '</text>';
    }
    $salto = max(1, (int) ceil($n / 8));
    foreach ($serie['etiquetas'] as $i => $et) {
        if ($i % $salto !== 0 && $i !== $n - 1) continue;
        // La última etiqueta se alinea a su borde derecho: centrada, la mitad queda fuera del dibujo.
        $svg .= '<text class="au-eje" x="' . round($x($i), 1) . '" y="' . ($h - 10) . '" text-anchor="' . ($i === $n - 1 && $n > 1 ? 'end' : 'middle') . '">' . $e($et) . '</text>';
    }
    foreach ([['acciones', '#6B4420'], ['fallos', '#B84239']] as [$clave, $color]) {
        $pts = [];
        foreach ($serie[$clave] as $i => $v) { $pts[] = round($x($i), 1) . ',' . round($y($v), 1); }
        if (!$pts) continue;
        $svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $color . '" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round"/>';
        foreach ($serie[$clave] as $i => $v) {
            if ($v > 0) $svg .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y($v), 1) . '" r="3.2" fill="' . $color . '"/>';
        }
    }
    return $svg . '</svg>';
}

// ── Alertas ──────────────────────────────────────────────────────────────

const AUDITORIA_AGENTE_HERRAMIENTA = '/python|curl|wget|postman|insomnia|httpclient|go-http|okhttp|libwww|axios|node-fetch|java\//i';

/**
 * Las alertas de los últimos AUDITORIA_ALERTAS_DIAS días, de la más reciente a la
 * más antigua. Cada una: nivel, tipo, titulo, detalle, ts (segundos), usuario, ip,
 * activa (menos de AUDITORIA_ALERTA_ACTIVA_HORAS). Solo AVISAN.
 */
function auditoriaAlertas(PDO $pdo): array {
    require_once __DIR__ . '/sesiones.php';
    $desde = date('Y-m-d H:i:s', time() - AUDITORIA_ALERTAS_DIAS * 86400);
    $limiteActiva = time() - AUDITORIA_ALERTA_ACTIVA_HORAS * 3600;
    $alertas = [];
    // $extra: de qué SESIÓN es la alerta (sesion_id o sesion_hash), para la marca «sospechosa» de Seguridad.
    $nueva = function (string $nivel, string $tipo, string $titulo, string $detalle, int $ts, string $usuario, string $ip, array $extra = []) use (&$alertas, $limiteActiva) {
        $alertas[] = ['nivel' => $nivel, 'tipo' => $tipo, 'titulo' => $titulo, 'detalle' => $detalle, 'ts' => $ts,
                      'usuario' => $usuario, 'ip' => $ip, 'activa' => $ts >= $limiteActiva] + $extra;
    };

    // 1) Ráfaga de intentos fallidos: la misma regla del bloqueo del acceso (login_throttle.php).
    try {
        $st = $pdo->prepare("select ip_address, created_at from auditoria
                             where accion = 'login_fallido' and created_at >= ? order by ip_address, created_at");
        $st->execute([$desde]);
        $porIp = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $porIp[(string) $r['ip_address']][] = strtotime((string) $r['created_at']); }
        foreach ($porIp as $ip => $tiempos) {
            $i = 0; $m = count($tiempos);
            while ($i < $m) {
                $j = $i;
                while ($j + 1 < $m && $tiempos[$j + 1] - $tiempos[$i] <= LOGIN_VENTANA_MINUTOS * 60) $j++;
                if ($j - $i + 1 >= LOGIN_MAX_INTENTOS) {
                    $nueva('CRITICAL', 'rafaga', 'Ráfaga de intentos fallidos de acceso',
                        ($j - $i + 1) . ' intentos fallidos desde la IP ' . $ip . ' en ' . max(1, (int) ceil(($tiempos[$j] - $tiempos[$i]) / 60)) . ' min',
                        $tiempos[$j], '', (string) $ip);
                    $i = $j + 1;
                } else { $i++; }
            }
        }
    } catch (Throwable $e) { error_log('Auditoría: alerta de ráfagas: ' . $e->getMessage()); }

    // 2) Cambio de IP sospechoso: el mismo usuario inicia sesión desde dos IP distintas en pocos minutos.
    try {
        if (sesionesDisponible($pdo)) {
            $st = $pdo->prepare("select s.id, s.usuario_id, u.nombre, s.ip_address, s.creada_en
                                 from sesiones_activas s join usuarios u on u.id = s.usuario_id
                                 where s.creada_en >= ? order by s.usuario_id, s.creada_en");
            $st->execute([$desde]);
            $previa = null;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = strtotime((string) $r['creada_en']);
                if ($previa && $previa['usuario_id'] === (int) $r['usuario_id']
                    && $previa['ip'] !== (string) $r['ip_address']
                    && $ts - $previa['ts'] <= AUDITORIA_CAMBIO_IP_MINUTOS * 60) {
                    $nueva('CRITICAL', 'cambio_ip', 'Cambio de IP sospechoso',
                        'Inició sesión desde ' . $previa['ip'] . ' y ' . max(1, (int) round(($ts - $previa['ts']) / 60))
                        . ' min después desde ' . $r['ip_address'] . '. Puede ser un cambio de red legítimo: revisa en Seguridad.',
                        $ts, (string) $r['nombre'], (string) $r['ip_address'], ['sesion_id' => (int) $r['id']]);
                }
                $previa = ['usuario_id' => (int) $r['usuario_id'], 'ip' => (string) $r['ip_address'], 'ts' => $ts];
            }
        }
    } catch (Throwable $e) { error_log('Auditoría: alerta de cambio de IP: ' . $e->getMessage()); }

    // 3) Dispositivo anómalo: una herramienta (no un navegador) que hace algo que no es rutina, o un
    //    navegador que ese usuario nunca había usado haciendo algo crítico. Pide las columnas nuevas.
    try {
        if (auditoriaColumnas($pdo)) {
            $st = $pdo->prepare("select a.usuario_id, u.nombre, a.accion, a.user_agent, a.sesion_hash, a.ip_address, a.severidad, a.created_at
                                 from auditoria a join usuarios u on u.id = a.usuario_id
                                 where a.created_at >= ? and a.severidad in ('WARNING', 'CRITICAL')
                                   and a.user_agent is not null and a.accion not in (" . _auditoriaListaSql(AUDITORIA_ACCIONES_FALLO) . ")
                                 order by a.created_at desc limit 300");
            $st->execute([$desde]);
            $vistos = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ua = (string) $r['user_agent'];
                $ts = strtotime((string) $r['created_at']);
                $clave = $r['usuario_id'] . '|' . $ua;
                if (isset($vistos[$clave])) continue;
                if ($ua === '' || preg_match(AUDITORIA_AGENTE_HERRAMIENTA, $ua)) {
                    $vistos[$clave] = true;
                    $nueva('CRITICAL', 'herramienta', 'Dispositivo anómalo: no es un navegador',
                        'La acción «' . $r['accion'] . '» salió de «' . ($ua === '' ? 'sin agente' : mb_substr($ua, 0, 60)) . '», no de un navegador.',
                        $ts, (string) $r['nombre'], (string) $r['ip_address'], ['sesion_hash' => (string) ($r['sesion_hash'] ?? '')]);
                    continue;
                }
                if ($r['severidad'] !== 'CRITICAL') continue;
                // ¿Había usado ese usuario esta familia de navegador y sistema ANTES de esta sesión?
                $familia = sesionNavegador($ua);
                $st2 = $pdo->prepare("select agente from sesiones_activas
                                      where usuario_id = ? and sid_hash <> ? and creada_en < ? and creada_en >= ?");
                $st2->execute([(int) $r['usuario_id'], (string) ($r['sesion_hash'] ?? ''), (string) $r['created_at'],
                               date('Y-m-d H:i:s', $ts - 90 * 86400)]);
                $conocida = false;
                foreach ($st2->fetchAll(PDO::FETCH_COLUMN) as $agente) {
                    if (sesionNavegador((string) $agente) === $familia) { $conocida = true; break; }
                }
                if (!$conocida) {
                    $vistos[$clave] = true;
                    $nueva('WARNING', 'dispositivo_nuevo', 'Dispositivo nunca visto en una acción crítica',
                        'La acción «' . $r['accion'] . '» salió de ' . $familia . ', que este usuario no había usado en los últimos 90 días.',
                        $ts, (string) $r['nombre'], (string) $r['ip_address'], ['sesion_hash' => (string) ($r['sesion_hash'] ?? '')]);
                }
            }
        }
    } catch (Throwable $e) { error_log('Auditoría: alerta de dispositivo: ' . $e->getMessage()); }

    usort($alertas, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return $alertas;
}

/**
 * Las sesiones que aparecen en una alerta, para la marca «sospechosa» de Seguridad:
 * ['ids' => ids de sesiones_activas, 'hashes' => SHA-256 de sesión]. Es una MARCA, no un estado.
 */
function auditoriaSesionesSospechosas(array $alertas): array {
    $ids = []; $hashes = [];
    foreach ($alertas as $a) {
        if (!empty($a['sesion_id'])) $ids[] = (int) $a['sesion_id'];
        if (!empty($a['sesion_hash'])) $hashes[] = (string) $a['sesion_hash'];
    }
    return ['ids' => array_values(array_unique($ids)), 'hashes' => array_values(array_unique($hashes))];
}

/** Cuántas de esas alertas son críticas y siguen activas. */
function auditoriaAlertasCriticasActivas(array $alertas): int {
    return count(array_filter($alertas, fn($a) => $a['nivel'] === 'CRITICAL' && $a['activa']));
}

// ── El sello de la exportación ───────────────────────────────────────────

/**
 * SHA-256 de las filas exportadas, en orden de id. Quien reciba el archivo puede
 * volver a calcularlo y ver que no le cambiaron nada. NO es una firma digital con
 * certificado: no dice quién lo firmó, solo que el contenido no se alteró.
 */
function auditoriaSello(array $filas): string {
    usort($filas, fn($a, $b) => $a['id'] <=> $b['id']);
    $canon = array_map(fn($r) => [$r['id'], $r['fecha'], $r['usuario'], $r['accion'], $r['tabla'], $r['registro'], $r['severidad'], $r['detalle'], $r['ip']], $filas);
    return hash('sha256', json_encode($canon, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
