<?php
// ============================================================
// includes/sesiones.php
// Registro de sesiones abiertas del personal — Casa Bosque
// ============================================================
// Lo que arregla: hasta ahora cambiar la contraseña no cerraba las
// sesiones ya iniciadas. Quien tuviera una sesión viva seguía dentro.
// Ahora un cambio de contraseña las cierra todas, y cada usuario puede
// ver dónde tiene sesión abierta y cerrarla.
//
// Decisiones, y el motivo de cada una:
//
//  - Del identificador de sesión se guarda solo su SHA-256. Un id de
//    sesión en claro vale tanto como la contraseña: quien lo tenga,
//    entra. Guardando el hash, leer la tabla no permite suplantar.
//
//  - Si la tabla no existe (migración 023 sin ejecutar), TODO esto se
//    desactiva solo y el sistema sigue funcionando igual. Una función de
//    seguridad a medio instalar no debe dejar a nadie fuera.
//
//  - Ante un error de base de datos NO se cierra la sesión del usuario:
//    se registra en el log y se sigue. Un hipo de la base no debe echar
//    a todo el personal en plena recepción. Lo que sí expulsa es un dato
//    concluyente: la fila dice que la sesión se cerró.
//
//  - Las sesiones que ya estaban abiertas antes de instalar esto se
//    adoptan al primer acceso, para que la función sirva desde el minuto
//    uno y no solo para quien vuelva a entrar.
//
//  - La marca de actividad se actualiza como máximo una vez por minuto,
//    para no escribir en la base en cada petición.
// ------------------------------------------------------------

// Los equipos recordados («no pedir el código del 2FA») se anulan junto con
// las sesiones cuando cambian las credenciales; ver sesionCerrarTodas().
require_once __DIR__ . '/equipos.php';

/** Cada cuánto, como máximo, se actualiza la última actividad. */
const SESIONES_TOCAR_CADA_SEG = 60;

/**
 * ¿Está instalada la tabla? Se comprueba una vez por petición.
 * Mientras devuelva false, ninguna otra función de este archivo toca la
 * base de datos.
 */
function sesionesDisponible(PDO $pdo): bool {
    static $hay = null;
    if ($hay !== null) return $hay;
    try {
        $hay = (bool) $pdo->query("show tables like 'sesiones_activas'")->fetchColumn();
    } catch (Throwable $e) {
        $hay = false;
    }
    return $hay;
}

/** SHA-256 del identificador de sesión actual, o '' si no hay sesión. */
function _sesionHashActual(): string {
    $sid = session_id();
    return ($sid === '' || $sid === false) ? '' : hash('sha256', $sid);
}

/** Navegador recortado, para que el usuario reconozca cada sesión. */
function _sesionAgente(): string {
    return mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
}

/** IP de quien pide. */
function _sesionIp(): string {
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Registra la sesión recién iniciada. Se llama desde
 * establecerSesionUsuario(), ya con el id de sesión definitivo.
 */
function sesionRegistrar(PDO $pdo, int $usuarioId): void {
    if (!sesionesDisponible($pdo)) return;
    $hash = _sesionHashActual();
    if ($hash === '') return;

    try {
        // Si el id se reutilizara, se reabre la fila en vez de duplicarla.
        $pdo->prepare("insert into sesiones_activas
                       (usuario_id, sid_hash, ip_address, agente)
                       values (?, ?, ?, ?)
                       on duplicate key update
                           usuario_id = values(usuario_id),
                           ip_address = values(ip_address),
                           agente = values(agente),
                           creada_en = now(),
                           ultima_actividad = now(),
                           cerrada_en = null,
                           motivo_cierre = null")
            ->execute([$usuarioId, $hash, _sesionIp(), _sesionAgente()]);
        $_SESSION['sesion_tocada'] = time();
    } catch (Throwable $e) {
        error_log('Sesiones: no se pudo registrar la sesión: ' . $e->getMessage());
    }
}

/**
 * ¿Sigue vigente la sesión actual?
 *
 * Devuelve false SOLO si hay constancia de que se cerró. Ante cualquier
 * duda —tabla ausente, error de base— devuelve true: no se echa a nadie
 * por un fallo de infraestructura.
 *
 * Si la sesión no estaba registrada (venía de antes de instalar esto), se
 * adopta aquí mismo.
 */
function sesionVigente(PDO $pdo, int $usuarioId, ?string &$motivo = null): bool {
    $motivo = null;
    if (!sesionesDisponible($pdo)) return true;
    $hash = _sesionHashActual();
    if ($hash === '') return true;

    try {
        // `creada_en` ya existía en la tabla y ya se mostraba en Seguridad
        // («iniciada el …»), pero no decidía nada: la sesión solo caducaba por
        // inactividad, así que navegando cada 29 minutos vivía indefinidamente.
        // El estándar pide las dos cosas: expiración por inactividad Y duración
        // máxima. Es el mismo criterio que los equipos de confianza, que vencen
        // «a los 14 días FIJOS, no se renueva al usarlo» (includes/equipos.php).
        $horas = defined('SESION_MAXIMA_HORAS') ? max(1, (int) SESION_MAXIMA_HORAS) : 12;
        $s = $pdo->prepare(sprintf(
            "select id, cerrada_en,
                    (creada_en <= (now() - interval %d hour)) as caducada
             from sesiones_activas where sid_hash = ? limit 1", $horas));
        $s->execute([$hash]);
        $fila = $s->fetch();

        if (!$fila) {
            // Sesión anterior a la instalación: se adopta.
            sesionRegistrar($pdo, $usuarioId);
            return true;
        }
        if ((int) $fila['caducada'] === 1) {
            $motivo = 'duracion_maxima';
            // Se marca cerrada para que no haya que recalcularlo en cada
            // petición y para que quede el motivo a la vista en Seguridad.
            try {
                $pdo->prepare("update sesiones_activas set cerrada_en = now(), motivo_cierre = 'duracion_maxima'
                               where id = ? and cerrada_en is null")->execute([(int) $fila['id']]);
            } catch (Throwable $e) { /* el corte no depende de poder anotarlo */ }
            return false;
        }
        return $fila['cerrada_en'] === null;

    } catch (Throwable $e) {
        error_log('Sesiones: no se pudo comprobar la sesión: ' . $e->getMessage());
        return true;
    }
}

/** Actualiza la última actividad, como máximo una vez por minuto. */
function sesionTocar(PDO $pdo): void {
    if (!sesionesDisponible($pdo)) return;
    $hash = _sesionHashActual();
    if ($hash === '') return;

    $ultima = (int) ($_SESSION['sesion_tocada'] ?? 0);
    if (time() - $ultima < SESIONES_TOCAR_CADA_SEG) return;

    try {
        $pdo->prepare("update sesiones_activas set ultima_actividad = now()
                       where sid_hash = ? and cerrada_en is null")
            ->execute([$hash]);
        $_SESSION['sesion_tocada'] = time();
    } catch (Throwable $e) {
        // Sin ruido: que no se pueda anotar la actividad no es grave.
    }
}

/** Marca la sesión actual como cerrada. */
function sesionCerrarActual(PDO $pdo, string $motivo = 'logout'): void {
    if (!sesionesDisponible($pdo)) return;
    $hash = _sesionHashActual();
    if ($hash === '') return;
    try {
        $pdo->prepare("update sesiones_activas
                       set cerrada_en = now(), motivo_cierre = ?
                       where sid_hash = ? and cerrada_en is null")
            ->execute([mb_substr($motivo, 0, 20), $hash]);
    } catch (Throwable $e) {
        error_log('Sesiones: no se pudo cerrar la sesión actual: ' . $e->getMessage());
    }
}

/**
 * Cierra TODAS las sesiones abiertas de un usuario. Es lo que se llama al
 * cambiar una contraseña.
 *
 * @param bool $exceptoActual true para dejar viva la sesión desde la que
 *        se hace el cambio (p. ej. al cambiar tu propia contraseña ya
 *        estando dentro).
 * @return int cuántas se cerraron
 */
function sesionCerrarTodas(PDO $pdo, int $usuarioId, string $motivo = 'cambio_clave', bool $exceptoActual = false): int {
    // Un cambio de credenciales —contraseña o 2FA— también anula los equipos
    // recordados: si no, quien tuviera un equipo recordado seguiría entrando sin
    // código después de que el dueño blindara la cuenta, que es justo el caso
    // para el que se cierran las sesiones. Va ANTES del `return` de abajo: los
    // equipos no dependen de que exista la tabla de sesiones.
    //
    // «manual» no cuenta: es el botón «cerrar mis otras sesiones» de Seguridad,
    // y eso no cambia ninguna credencial. Quitar equipos tiene su propio botón.
    if ($motivo !== 'manual') {
        equiposRevocarTodos($pdo, $usuarioId);
    }

    if (!sesionesDisponible($pdo)) return 0;
    try {
        $sql = "update sesiones_activas set cerrada_en = now(), motivo_cierre = ?
                where usuario_id = ? and cerrada_en is null";
        $args = [mb_substr($motivo, 0, 20), $usuarioId];

        if ($exceptoActual) {
            $hash = _sesionHashActual();
            if ($hash !== '') { $sql .= " and sid_hash <> ?"; $args[] = $hash; }
        }
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->rowCount();
    } catch (Throwable $e) {
        error_log('Sesiones: no se pudieron cerrar las sesiones del usuario: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Las sesiones ACTIVAS de un usuario, la más reciente primero.
 *
 * Antes salía toda fila sin `cerrada_en`, y una sesión que ya había vencido por
 * inactividad sin que nadie volviera quedaba ahí para siempre, mezclada con las
 * de verdad. Ahora se listan solo las activas (más la de este navegador, que
 * por definición lo está); las vencidas pasan al historial (sesionesRegistradas).
 *
 * @param array $sospechosas lo que entrega auditoriaSesionesSospechosas(), para la marca
 */
function sesionesDeUsuario(PDO $pdo, int $usuarioId, array $sospechosas = []): array {
    return array_values(array_filter(
        sesionesRegistradas($pdo, $usuarioId, ['solo_abiertas' => true], $sospechosas),
        fn($f) => $f['es_actual'] || $f['estado']['clave'] === 'activa'
    ));
}

/** Las sesiones activas de TODO el personal (solo la ve un administrador; lo comprueba quien llama). */
function sesionesDelPersonal(PDO $pdo, array $sospechosas = []): array {
    $filas = array_values(array_filter(
        sesionesRegistradas($pdo, 0, ['solo_abiertas' => true, 'limite' => 300], $sospechosas),
        fn($f) => $f['es_actual'] || $f['estado']['clave'] === 'activa'
    ));
    usort($filas, fn($a, $b) => [$a['nombre'], $b['ultima_actividad']] <=> [$b['nombre'], $a['ultima_actividad']]);
    return $filas;
}

/**
 * El HISTORIAL de sesiones: las que ya terminaron, no las abiertas. Una sesión pasa aquí cuando la cerró quien la
 * tenía, un administrador o el propio sistema por seguridad, o cuando venció (por inactividad, por las 12 h, o abierta
 * en la tabla pero ya vencida sin que nadie volviera). La que está vigente, y la de este navegador, NO son historial.
 *
 * Es otra cosa que el historial de CONEXIONES: aquel cuenta los accesos (con éxito o sin él, sale de la auditoría);
 * este cuenta las sesiones (cuándo empezó cada una, en qué dispositivo y POR QUÉ terminó).
 *
 * @param int   $usuarioId 0 = de todo el personal (solo lo pide quien ya comprobó que es administrador)
 * @param array $opc       dias (30), limite (40), estado (una clave de SESION_ESTADOS_FINALES; cualquier otra cosa = todas)
 */
function sesionesHistorial(PDO $pdo, int $usuarioId = 0, array $opc = [], array $sospechosas = []): array {
    $limite = max(1, (int) ($opc['limite'] ?? 40));
    $estado = (string) ($opc['estado'] ?? '');
    if (!isset(SESION_ESTADOS_FINALES[$estado])) $estado = '';
    // Se pide de más y se recorta aquí: las activas se descartan después y no deben quitarle lugar a las terminadas.
    $filas = sesionesRegistradas($pdo, $usuarioId, ['dias' => $opc['dias'] ?? 30, 'limite' => 500], $sospechosas);
    $salida = [];
    foreach ($filas as $f) {
        if ($f['es_actual'] || $f['estado']['clave'] === 'activa') continue;
        if ($estado !== '' && $f['estado']['clave'] !== $estado) continue;
        $salida[] = $f;
        if (count($salida) >= $limite) break;
    }
    return $salida;
}

/** Quién ha tenido sesiones, para el filtro del historial del personal (solo lo muestra a un administrador quien llama). */
function sesionesUsuarios(PDO $pdo): array {
    if (!sesionesDisponible($pdo)) return [];
    try {
        return $pdo->query("select distinct u.id, u.nombre from sesiones_activas s join usuarios u on u.id = s.usuario_id order by u.nombre")
                   ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Sesiones: no se pudo listar a quién tiene sesiones: ' . $e->getMessage());
        return [];
    }
}

/**
 * Cierra una sesión concreta de un usuario.
 * El usuario_id va en el where a propósito: así nadie puede cerrar la
 * sesión de otra persona pasando un id ajeno.
 */
function sesionCerrarPorId(PDO $pdo, int $usuarioId, int $sesionId, string $motivo = 'manual'): bool {
    if (!sesionesDisponible($pdo)) return false;
    try {
        $st = $pdo->prepare("update sesiones_activas
                             set cerrada_en = now(), motivo_cierre = ?
                             where id = ? and usuario_id = ? and cerrada_en is null");
        $st->execute([mb_substr($motivo, 0, 20), $sesionId, $usuarioId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('Sesiones: no se pudo cerrar la sesión indicada: ' . $e->getMessage());
        return false;
    }
}

/**
 * Describe el navegador de forma legible, para que el usuario reconozca
 * cada sesión sin leer la cadena completa del agente.
 */
function sesionNavegador(?string $agente): string {
    $a = (string) $agente;
    if ($a === '') return 'Desconocido';

    $nav = 'Navegador';
    foreach ([
        'Edg'     => 'Edge',
        'OPR'     => 'Opera',
        'Chrome'  => 'Chrome',
        'Firefox' => 'Firefox',
        'Safari'  => 'Safari',
    ] as $aguja => $nombre) {
        if (stripos($a, $aguja) !== false) { $nav = $nombre; break; }
    }

    $so = '';
    foreach ([
        'Android'  => 'Android',
        'iPhone'   => 'iPhone',
        'iPad'     => 'iPad',
        'Windows'  => 'Windows',
        'Mac OS'   => 'Mac',
        'Linux'    => 'Linux',
    ] as $aguja => $nombre) {
        if (stripos($a, $aguja) !== false) { $so = $nombre; break; }
    }

    return $so === '' ? $nav : "$nav · $so";
}

// ============================================================
// 4.1.0 — El dispositivo, el estado y el historial de las sesiones
// ============================================================
// Todo se DEDUCE de lo que sesiones_activas ya guardaba (IP, agente, hora, motivo
// de cierre): no hay columnas nuevas ni SQL. Un agente de usuario es una
// DESCRIPCIÓN que el navegador da de sí mismo, no una identidad: se puede
// falsificar y no dice todo. Windows 11 se presenta como «10.0», y macOS congela
// su versión en «10_15_7» desde hace años: esas versiones NO se inventan.

/**
 * Cómo termina una sesión y cómo se llama. Lo usan sesionEstado() y el filtro del historial de sesiones:
 * escritos una sola vez, para que la pantalla y el filtro no se separen.
 */
const SESION_ESTADOS_FINALES = [
    'expirada'          => 'Expirada',
    'cerrada_usuario'   => 'Cerrada por el usuario',
    'cerrada_admin'     => 'Cerrada por un administrador',
    'cerrada_seguridad' => 'Cerrada por seguridad',
    'cerrada'           => 'Cerrada',
];

/** Minutos de inactividad tras los que una sesión vence: la constante de auth.php, sin repetir el número. */
function _sesionInactividadMin(): int {
    return defined('SESION_INACTIVIDAD_MIN') ? max(1, (int) SESION_INACTIVIDAD_MIN) : 30;
}

/** Horas de duración máxima: la de auth.php (ver también sesionVigente()). */
function _sesionMaximaHoras(): int {
    return defined('SESION_MAXIMA_HORAS') ? max(1, (int) SESION_MAXIMA_HORAS) : 12;
}

/**
 * Qué se puede decir de un agente de usuario.
 *
 * @return array{tipo:string, sistema:string, so_version:string, navegador:string, nav_version:string, etiqueta:string, clave:string}
 *   tipo: Escritorio | Móvil | Tableta | Robot | Desconocido.
 *   clave: tipo + sistema + navegador SIN versiones; es lo que decide si un dispositivo
 *          es «nuevo» para un usuario (una actualización de Chrome no es otro dispositivo).
 */
function sesionDispositivo(?string $agente): array {
    $a = trim((string) $agente);
    $vacio = ['tipo' => 'Desconocido', 'sistema' => '', 'so_version' => '', 'navegador' => '', 'nav_version' => '',
              'etiqueta' => '—', 'clave' => ''];
    if ($a === '') return $vacio;

    // Un programa, no un navegador. («HeadlessChrome» SÍ es Chrome y no entra aquí.)
    if (preg_match('/bot\b|crawl|spider|curl\/|wget|python|httpclient|go-http|okhttp|postman|insomnia|libwww|java\/|axios|node-fetch/i', $a)) {
        $nombre = mb_substr(preg_replace('/[^\x20-\x7E]/', '', $a) ?? '', 0, 40);
        return ['tipo' => 'Robot', 'sistema' => '', 'so_version' => '', 'navegador' => 'Programa', 'nav_version' => '',
                'etiqueta' => 'Programa (' . $nombre . ')', 'clave' => 'Robot||Programa'];
    }

    // Sistema y su versión, solo cuando el agente la da de verdad.
    $sistema = ''; $soVer = '';
    if (preg_match('/\b(iPhone|iPad|iPod)\b.*?OS (\d+)[_.](\d+)/i', $a, $m)) {
        $sistema = 'iOS'; $soVer = $m[2] . '.' . $m[3];
    } elseif (preg_match('/Android (\d+(?:\.\d+)?)/i', $a, $m)) {
        $sistema = 'Android'; $soVer = $m[1];
    } elseif (stripos($a, 'Android') !== false) {
        $sistema = 'Android';
    } elseif (preg_match('/Windows NT (\d+\.\d+)/i', $a, $m)) {
        $sistema = 'Windows';
        $soVer = ['10.0' => '10 u 11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'][$m[1]] ?? '';
    } elseif (stripos($a, 'CrOS') !== false) {
        $sistema = 'ChromeOS';
    } elseif (stripos($a, 'Mac OS X') !== false || stripos($a, 'Macintosh') !== false) {
        $sistema = 'macOS';   // la versión del agente está congelada en 10_15_7: no se muestra
    } elseif (stripos($a, 'Linux') !== false || stripos($a, 'X11') !== false) {
        $sistema = 'Linux';
    }

    // Tipo: una tableta Android no dice «Mobile»; un iPad dice «iPad».
    if (stripos($a, 'iPad') !== false || (stripos($a, 'Android') !== false && stripos($a, 'Mobile') === false)
        || stripos($a, 'Tablet') !== false) {
        $tipo = 'Tableta';
    } elseif (preg_match('/iPhone|iPod|Mobile|Android/i', $a)) {
        $tipo = 'Móvil';
    } else {
        $tipo = 'Escritorio';
    }

    // Navegador, de lo más específico a lo más general (Edge y Opera también dicen «Chrome»).
    $nav = ''; $navVer = '';
    foreach ([
        'Edge'             => '/\bEdg(?:e|A|iOS)?\/(\d+)/',
        'Opera'            => '/\bOPR\/(\d+)/',
        'Samsung Internet' => '/SamsungBrowser\/(\d+)/',
        'Firefox'          => '/(?:Firefox|FxiOS)\/(\d+)/',
        'Chrome'           => '/(?:Chrome|CriOS)\/(\d+)/',
        'Safari'           => '/Version\/(\d+)[\d.]*.*Safari\//',
    ] as $nombre => $patron) {
        if (preg_match($patron, $a, $m)) { $nav = $nombre; $navVer = $m[1]; break; }
    }
    if ($nav === '') $nav = stripos($a, 'Safari') !== false ? 'Safari' : 'Navegador';

    $etiqueta = $nav . ($navVer !== '' ? ' ' . $navVer : '')
              . ($sistema !== '' ? ' en ' . $sistema . ($soVer !== '' ? ' ' . $soVer : '') : '');
    return ['tipo' => $tipo, 'sistema' => $sistema, 'so_version' => $soVer, 'navegador' => $nav, 'nav_version' => $navVer,
            'etiqueta' => $etiqueta, 'clave' => $tipo . '|' . $sistema . '|' . $nav];
}

/**
 * El estado de una sesión. Los motivos de cierre son los que ya guardaba la tabla.
 *
 * @param array $f   cerrada_en, motivo_cierre, creada_en, ultima_actividad
 * @param int|null $ahora segundos (solo para las pruebas)
 * @return array{clave:string, etiqueta:string}
 *   activa · expirada · cerrada_usuario · cerrada_admin · cerrada_seguridad · cerrada
 * «Sospechosa» NO es un estado: es una marca que lleva la sesión de una alerta.
 */
function sesionEstado(array $f, ?int $ahora = null): array {
    $ahora = $ahora ?? time();
    if (!empty($f['cerrada_en'])) {
        $motivo = (string) ($f['motivo_cierre'] ?? '');
        if (in_array($motivo, ['logout', 'manual'], true))   return _sesionFinal('cerrada_usuario');
        if ($motivo === 'admin')                             return _sesionFinal('cerrada_admin');
        if (in_array($motivo, ['inactividad', 'duracion_maxima'], true)) return _sesionFinal('expirada');
        if (in_array($motivo, ['cambio_clave', 'cambio_2fa', 'desactivada', 'rol_cambiado'], true)) {
            return _sesionFinal('cerrada_seguridad');
        }
        return _sesionFinal('cerrada');
    }
    // Abierta en la tabla, pero puede haber vencido sin que nadie volviera a tocarla.
    $inactiva = $ahora - (int) strtotime((string) $f['ultima_actividad']) > _sesionInactividadMin() * 60;
    $vieja    = $ahora - (int) strtotime((string) $f['creada_en']) > _sesionMaximaHoras() * 3600;
    return ($inactiva || $vieja) ? _sesionFinal('expirada') : ['clave' => 'activa', 'etiqueta' => 'Activa'];
}

/** Un estado con el que una sesión TERMINA, con su nombre: de SESION_ESTADOS_FINALES, que es el único sitio donde está escrito. */
function _sesionFinal(string $clave): array {
    return ['clave' => $clave, 'etiqueta' => SESION_ESTADOS_FINALES[$clave]];
}

/**
 * Las sesiones registradas, con su dispositivo, su estado y las marcas «nuevo» y «sospechosa».
 *
 * @param int   $usuarioId 0 = de todo el personal
 * @param array $opc       solo_abiertas (bool), dias (int, 30), limite (int, 60)
 * @param array $sospechosas ['ids' => [...], 'hashes' => [...]] de auditoriaSesionesSospechosas()
 *
 * «Nuevo»: es la primera sesión de esa combinación (tipo + sistema + navegador) de ese usuario, y él ya
 * tenía sesiones de antes con otra. La PRIMERA de todas no lo es: con nada que comparar, todo sería nuevo.
 * El hash del id de sesión no sale de aquí (solo se usa para reconocer la sesión actual).
 */
function sesionesRegistradas(PDO $pdo, int $usuarioId = 0, array $opc = [], array $sospechosas = []): array {
    if (!sesionesDisponible($pdo)) return [];
    $soloAbiertas = !empty($opc['solo_abiertas']);
    $dias   = max(1, (int) ($opc['dias'] ?? 30));
    $limite = max(1, (int) ($opc['limite'] ?? 60));
    try {
        $st = $pdo->prepare("select s.id, s.usuario_id, u.nombre, u.rol, s.sid_hash, s.ip_address, s.agente,
                                    s.creada_en, s.ultima_actividad, s.cerrada_en, s.motivo_cierre
                             from sesiones_activas s join usuarios u on u.id = s.usuario_id
                             where (? = 0 or s.usuario_id = ?)
                               and " . ($soloAbiertas ? 's.cerrada_en is null' : 's.creada_en >= (now() - interval ? day)') . "
                             order by s.ultima_actividad desc, s.id desc limit ?");
        $i = 1;
        $st->bindValue($i++, $usuarioId, PDO::PARAM_INT);
        $st->bindValue($i++, $usuarioId, PDO::PARAM_INT);
        if (!$soloAbiertas) $st->bindValue($i++, $dias, PDO::PARAM_INT);
        $st->bindValue($i++, $limite, PDO::PARAM_INT);
        $st->execute();
        $filas = $st->fetchAll();

        // ¿Cuáles son la primera sesión de su combinación? Se recorre el pasado de cada usuario en orden.
        $pas = $pdo->prepare("select id, usuario_id, agente from sesiones_activas
                              where (? = 0 or usuario_id = ?) and creada_en >= (now() - interval 365 day)
                              order by creada_en asc, id asc");
        $pas->bindValue(1, $usuarioId, PDO::PARAM_INT);
        $pas->bindValue(2, $usuarioId, PDO::PARAM_INT);
        $pas->execute();
        $nuevas = []; $vistas = []; $cuantas = [];
        foreach ($pas->fetchAll() as $p) {
            $u = (int) $p['usuario_id'];
            $clave = sesionDispositivo((string) $p['agente'])['clave'];
            $nuevas[(int) $p['id']] = $clave !== '' && empty($vistas[$u][$clave]) && ($cuantas[$u] ?? 0) > 0;
            if ($clave !== '') $vistas[$u][$clave] = true;
            $cuantas[$u] = ($cuantas[$u] ?? 0) + 1;
        }

        $actual = _sesionHashActual();
        $ids = array_map('intval', $sospechosas['ids'] ?? []);
        $hashes = $sospechosas['hashes'] ?? [];
        foreach ($filas as &$f) {
            $f['id'] = (int) $f['id'];
            $f['usuario_id'] = (int) $f['usuario_id'];
            $f['es_actual'] = ($actual !== '' && $f['sid_hash'] === $actual);
            $f['sospechosa'] = in_array($f['id'], $ids, true) || in_array($f['sid_hash'], $hashes, true);
            unset($f['sid_hash']);   // no sale de aquí, ni siquiera el hash
            $f['dispositivo'] = sesionDispositivo($f['agente']);
            $f['estado'] = sesionEstado($f);
            $f['nuevo'] = !empty($nuevas[$f['id']]);
        }
        unset($f);
        return $filas;
    } catch (Throwable $e) {
        error_log('Sesiones: no se pudo listar: ' . $e->getMessage());
        return [];
    }
}

/**
 * Un administrador cierra la sesión de OTRA persona. Quien llama comprueba que lo es (isAdmin()):
 * aquí solo se garantiza que no se cierre la propia, que la sesión exista y siga abierta, y que el cierre
 * lo haga sesionCerrarPorId() con el usuario dueño de esa sesión.
 *
 * @return array{ok:bool, propia:bool, usuario_id:int, nombre:string, dispositivo:string, ip:string}
 */
function sesionCerrarAjena(PDO $pdo, int $sesionId): array {
    $nada = ['ok' => false, 'propia' => false, 'usuario_id' => 0, 'nombre' => '', 'dispositivo' => '', 'ip' => ''];
    if (!sesionesDisponible($pdo) || $sesionId < 1) return $nada;
    try {
        $st = $pdo->prepare("select s.id, s.usuario_id, s.sid_hash, s.ip_address, s.agente, u.nombre
                             from sesiones_activas s join usuarios u on u.id = s.usuario_id
                             where s.id = ? and s.cerrada_en is null");
        $st->execute([$sesionId]);
        $f = $st->fetch();
        if (!$f) return $nada;
        $datos = ['usuario_id' => (int) $f['usuario_id'], 'nombre' => (string) $f['nombre'],
                  'dispositivo' => sesionDispositivo($f['agente'])['etiqueta'], 'ip' => (string) $f['ip_address']];
        $actual = _sesionHashActual();
        if ($actual !== '' && $f['sid_hash'] === $actual) return ['ok' => false, 'propia' => true] + $datos;
        return ['ok' => sesionCerrarPorId($pdo, (int) $f['usuario_id'], $sesionId, 'admin'), 'propia' => false] + $datos;
    } catch (Throwable $e) {
        error_log('Sesiones: no se pudo cerrar la sesión de otra persona: ' . $e->getMessage());
        return $nada;
    }
}
