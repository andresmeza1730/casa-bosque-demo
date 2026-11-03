<?php
/**
 * Asistente con IA — lado del servidor
 * ============================================================
 *
 * Qué hace: recibe una pregunta del personal sobre CÓMO SE USA el sistema,
 * se la manda a la API de Google con un manual del sistema como contexto, y
 * devuelve la respuesta en texto plano.
 *
 * Qué NO hace, a propósito:
 *   · No consulta la base de datos. No sabe cuántas habitaciones hay libres
 *     ni quién está hospedado, y no debe saberlo. Para eso está el panel.
 *   · No manda NUNCA datos de huéspedes a Google. Lo único que sale de aquí
 *     es la pregunta que escribió el empleado y el manual de abajo.
 *
 * Por qué vive en el servidor y no en el navegador:
 *   1. La clave va en el .env, fuera del docroot. En el navegador sería
 *      pública para cualquiera que mire el código fuente.
 *   2. La CSP del sistema lleva connect-src 'self': aunque se quisiera, el
 *      navegador no puede llamar a googleapis.com.
 *
 * Si la IA falla —sin clave, sin red, cuota agotada, modelo equivocado— el
 * asistente NO se rompe: el navegador vuelve a la búsqueda por palabras
 * clave de siempre. Esto es una mejora encima de lo que ya había, no un
 * reemplazo del que dependa el sistema.
 */

/** Cuánto se espera a Google antes de rendirse y usar el respaldo local. */
const IA_TIMEOUT_SEG = 8;
/** Preguntas más largas que esto se recortan: nadie pregunta así, y limita
 *  lo que puede acabar enviándose por accidente. */
const IA_MAX_PREGUNTA = 300;
/** Tope por sesión y por hora. Evita que alguien se quede jugando con el bot
 *  y agote la cuota del día para todo el hotel. */
const IA_LIMITE_HORA = 25;
/** Tope de la respuesta, por si el modelo se extiende de más. */
const IA_MAX_RESPUESTA = 1200;

/**
 * Lee la configuración del .env privado. Mismo patrón que avisos.php: el
 * archivo vive fuera del docroot y nunca se sirve por web.
 */
function iaConfig(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $envFile = dirname(__DIR__, 2) . '/casabosque-private/.env';
    $env = [];
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $p = strpos($line, '=');
            if ($p === false) continue;
            $env[trim(substr($line, 0, $p))] = trim(substr($line, $p + 1));
        }
    }

    $clave = trim($env['IA_API_KEY'] ?? '');
    // El nombre del modelo se configura, no se fija aquí: Google los cambia
    // y los retira cada pocos meses. El que valga para esta clave se mira en
    // Google AI Studio. Si el que está puesto ya no existe, la API responde
    // 404 y eso queda escrito en el registro de errores con el nombre exacto.
    $modelo = trim($env['IA_MODELO'] ?? '') ?: 'gemini-2.5-flash';

    return $cfg = [
        'clave'  => $clave,
        'modelo' => preg_match('/^[a-z0-9.\-]{3,60}$/i', $modelo) ? $modelo : 'gemini-2.5-flash',
    ];
}

/** ¿Hay clave configurada? Sin esto el asistente funciona como siempre. */
function iaDisponible(): bool
{
    return iaConfig()['clave'] !== '';
}

/**
 * El manual que se le da al modelo como única fuente.
 *
 * Está escrito en texto plano y en español porque es lo que el modelo lee
 * mejor. Las secciones de administrador solo se incluyen si quien pregunta
 * es administrador: un recepcionista no debe recibir instrucciones sobre
 * arqueos, respaldos ni auditoría.
 *
 * OJO AL MANTENER: este manual y la base de conocimiento de
 * includes/asistente.php describen lo mismo con dos formatos distintos —
 * aquí texto para el modelo, allí respuestas listas para mostrar. Si cambia
 * una función del sistema, hay que tocar los dos.
 */
function iaManual(bool $esAdmin): string
{
    $m = <<<'TXT'
SISTEMA: Casa Bosque — gestión de hotel (hospedaje y restaurante).
Roles: administrador (acceso total) y recepcionista (operación diaria).
En el celular, el botón "Más" abre una página con todos los accesos
agrupados: Operaciones, Reportes, Legal y documentos y Cuenta.

RESERVAS
Las reservas las hace el personal, por teléfono o en recepción, desde el
módulo Reservas. El sistema NO tiene reserva en línea para el huésped ni
código de reserva ni una página para que el huésped consulte por su cuenta:
cada reserva se identifica por su número (#) y el nombre del huésped.

CHECK-IN
Módulo Check-In: buscar la reserva en "Llegadas de hoy", verificar datos,
"Registrar entrada". No hay que cambiar el estado de la habitación a mano.
También se registra a un huésped sin reserva con una habitación disponible.
Cada llegada tiene el botón Acompañantes para registrar a quienes vienen con
el titular.

MENORES DE EDAD
Se registran en Check-in → Acompañantes ANTES de dar la entrada. Documentos:
Registro Civil siempre; si no viaja con ambos padres, autorización notarial.
Al registrar la entrada el sistema obliga a confirmar que se verificaron esos
documentos (Ley 1098 y advertencia Ley 679 - ESCNNA). Si la fecha de
nacimiento indica menor de 18, el sistema pide solo lo que exige la ley.

CONSUMOS
Módulo Consumos: elegir el huésped con estancia activa, registrar concepto y
valor, guardar. Todo se suma a su cuenta y aparece en el check-out.

ESTADO DE CUENTA Y PAGOS
Más → Estado de cuenta: se busca por documento o nombre y se ve habitación,
noches, consumos y total a pagar. Para registrar un pago o abono: abrir la
ficha, "Registrar pago", monto y método (efectivo, tarjeta, transferencia u
otro). Se puede cobrar todo o por partes; el saldo se recalcula solo. El
check-out cobra el saldo que falte preguntando el método.

CHECK-OUT
Módulo Check-Out: elegir el huésped, revisar la cuenta (noches + consumos),
confirmar. El sistema cierra la estancia, genera la liquidación y deja la
habitación en "limpieza"; alguien debe marcarla disponible cuando esté lista.
Genera factura PDF tamaño carta y tiquete de 80mm para impresora térmica.
Los dos se imprimen en el momento de cerrar la salida; ahí están los botones.

HABITACIONES
Estados: Disponible (libre), Ocupada (hay un huésped hospedado; se marca en el check-in; una reserva no cambia el estado),
Limpieza (recién desocupada; queda así tras cada check-out), Mantenimiento
(fuera de servicio). El administrador crea habitaciones, tipos y tarifas en
el módulo Habitaciones.

CALENDARIO
Una semana por pantalla, con los días arriba y una fila por habitación. Cada
celda es L (libre), R (reservada, azul) u O (ocupada, marrón); al tocarla sale
una tarjeta con el huésped y las fechas.

PANEL DE RECEPCIÓN
"Requiere Atención": salidas de hoy, salidas atrasadas (en rojo), ingresos
pendientes y reservas que no llegaron. Cada tarjeta lleva al check-in o
check-out. "Hospedados ahora": huéspedes activos con habitación y fecha de
salida. Buscador: nombre o documento y Enter.

SEGURIDAD
Usuario y contraseña personales, no se comparten. Cerrar sesión al terminar.
Tras varios intentos fallidos el acceso se bloquea 15 minutos. Si se olvida
la contraseña, se contacta al administrador. La verificación en dos pasos
(2FA) se configura en Más → Seguridad escaneando un QR con Google
Authenticator; obligatoria para administradores, recomendada para
recepcionistas.

LEGAL Y CONTINGENCIA
Más → Legal y documentos: Política de Tratamiento de Datos, Política de datos
de menores y Carta de autorización de menor para imprimir y autenticar.
Si se cae el internet el sistema queda inaccesible hasta que vuelva la conexión.
No existe un módulo de contingencia: se anotan llegadas y salidas en papel y se
registran después.

NOTIFICACIONES
El sistema manda al celular un aviso diario de los check-ins y check-outs del
día. No avisa de reservas nuevas: las reservas las crea el personal.
TXT;

    if ($esAdmin) {
        $m .= "\n\n" . <<<'TXT'
SOLO ADMINISTRADOR
Comprobantes: vuelve a abrir la factura o el tiquete de cualquier salida ya
cerrada, buscando por fecha o por huésped. Recepción no entra aquí: para ella
los comprobantes se imprimen en el momento del check-out.
Arqueo de Caja: movimientos del día por fecha de check-out —hospedaje,
consumos y total— con el detalle de cada salida y un PDF del cierre con
espacio para firmas.
Reportes de Ingresos: historia por mes (día a día) o por año (mes a mes),
hospedaje vs consumos separados, gráfico del periodo e ingresos por empleado.
Todo se calcula por fecha de check-out.
Respaldo BD (Más → Respaldo): descarga la base de datos completa en .sql. Se
recomienda respaldar seguido y guardar la copia fuera del servidor.
Auditoría: registra quién hizo qué y cuándo.
SIRE / TRA (Más): reportes gubernamentales por rango de fechas. Hay que
verificar el formato contra la especificación oficial vigente antes de
enviarlo.
Facturación electrónica DIAN: preparada pero desactivada; requiere proveedor
tecnológico autorizado y resolución de la DIAN. Mientras tanto se emite el
comprobante interno de siempre.
Retención y borrado seguro: en Huéspedes se puede anonimizar los datos
personales conservando el historial (Ley 1581 e ISO 27701). En Más →
Retención se ve quién supera el tiempo de retención.
TXT;
    }

    return $m;
}

/** Las instrucciones de comportamiento, separadas del manual. */
function iaInstrucciones(bool $esAdmin): string
{
    $rol = $esAdmin ? 'administrador' : 'recepcionista';
    return "Eres el asistente de ayuda del sistema hotelero Casa Bosque. "
         . "Quien pregunta es un $rol del hotel.\n\n"
         . "REGLAS:\n"
         . "1. Respondes ÚNICAMENTE sobre cómo se usa el sistema, apoyándote en el manual de abajo.\n"
         . "2. Si la respuesta no está en el manual, dilo con franqueza y sugiere consultar al "
         . "administrador del sistema. NO te inventes menús, botones ni rutas que no aparezcan ahí.\n"
         . "3. No tienes acceso a datos del hotel: no sabes cuántas habitaciones hay libres, quién "
         . "está hospedado ni cuánto se facturó. Si te lo preguntan, di que eso se consulta en el "
         . "panel y explica dónde mirarlo.\n"
         . "4. Responde en español, en tono cercano y directo, de tú. BREVE: 6 frases como mucho, o una "
         . "lista numerada de 4 pasos como mucho. No te extiendas.\n"
         . "5. Texto plano. Nada de markdown, asteriscos ni etiquetas HTML.\n\n"
         . "MANUAL DEL SISTEMA:\n" . iaManual($esAdmin);
}

/**
 * ¿Le queda cuota a esta sesión? Guarda las marcas de tiempo de la última
 * hora y cuenta. Es por sesión, no global: no pretende ser un control de
 * gasto exacto, sino evitar el uso desbocado de una sola persona.
 */
function iaQuedaCuota(): bool
{
    $ahora = time();
    $usos = $_SESSION['ia_usos'] ?? [];
    $usos = array_values(array_filter($usos, fn($t) => $ahora - $t < 3600));
    $_SESSION['ia_usos'] = $usos;
    return count($usos) < IA_LIMITE_HORA;
}

/** Anota un uso. Se llama solo cuando la petición llegó a salir. */
function iaAnotarUso(): void
{
    $_SESSION['ia_usos'][] = time();
}

/**
 * Arma el cuerpo JSON de la peticion. Esta separado de iaPreguntar() para
 * poder comprobarlo sin red ni clave: es la parte que mas facil se rompe
 * y la que no se ve cuando falla.
 */
function iaCuerpo(string $pregunta, bool $esAdmin): string
{
    return json_encode([
        'systemInstruction' => [
            'parts' => [['text' => iaInstrucciones($esAdmin)]],
        ],
        'contents' => [[
            'role'  => 'user',
            'parts' => [['text' => $pregunta]],
        ]],
        'generationConfig' => [
            'temperature'     => 0.3,   // bajo: queremos exactitud, no creatividad
            'maxOutputTokens' => 1200,
        ],
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Pregunta a la API de Google.
 *
 * Devuelve ['ok' => bool, 'texto' => string, 'motivo' => string].
 * El motivo es para el registro, no para enseñárselo al usuario: cuando algo
 * falla, el navegador se calla y usa el respaldo local.
 */
function iaPreguntar(string $pregunta, bool $esAdmin): array
{
    $cfg = iaConfig();
    if ($cfg['clave'] === '') {
        return ['ok' => false, 'texto' => '', 'motivo' => 'sin clave'];
    }

    $pregunta = trim($pregunta);
    if ($pregunta === '') {
        return ['ok' => false, 'texto' => '', 'motivo' => 'pregunta vacia'];
    }
    if (mb_strlen($pregunta) > IA_MAX_PREGUNTA) {
        $pregunta = mb_substr($pregunta, 0, IA_MAX_PREGUNTA);
    }
    if (!iaQuedaCuota()) {
        return ['ok' => false, 'texto' => '', 'motivo' => 'cuota de la sesion agotada'];
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . rawurlencode($cfg['modelo']) . ':generateContent';

    $cuerpo = iaCuerpo($pregunta, $esAdmin);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $cuerpo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => IA_TIMEOUT_SEG,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        // La clave va en cabecera y no en la URL: las URL acaban en registros
        // de acceso, en intermediarios y en cualquier traza. La cabecera no.
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $cfg['clave'],
        ],
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    iaAnotarUso();

    if ($resp === false) {
        error_log('Asistente IA: error de red (' . $err . ')');
        return ['ok' => false, 'texto' => '', 'motivo' => 'red'];
    }
    if ($http !== 200) {
        // El 404 casi siempre es el nombre del modelo: Google los retira. Se
        // deja escrito cuál se pidió para no tener que adivinarlo.
        $pista = $http === 404
            ? ' — el modelo "' . $cfg['modelo'] . '" no existe para esta clave; mira Google AI Studio y ajusta IA_MODELO en el .env'
            : '';
        error_log('Asistente IA: HTTP ' . $http . $pista . ' :: ' . mb_substr((string) $resp, 0, 300));
        return ['ok' => false, 'texto' => '', 'motivo' => 'http ' . $http];
    }

    $datos = json_decode($resp, true);
    $texto = $datos['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $fin   = $datos['candidates'][0]['finishReason'] ?? '';

    // MAX_TOKENS significa que la respuesta llego cortada a media frase.
    // Se muestra igual —media respuesta sirve mas que ninguna— pero queda
    // anotado, porque el sintoma en pantalla no dice la causa.
    if ($fin === 'MAX_TOKENS') {
        error_log('Asistente IA: respuesta cortada por el tope de salida. '
            . 'Si se repite, sube maxOutputTokens en iaCuerpo().');
    }
    if (!is_string($texto) || trim($texto) === '') {
        // Puede venir vacío si el modelo corta por sus propios filtros.
        $razon = $datos['candidates'][0]['finishReason'] ?? 'desconocida';
        error_log('Asistente IA: respuesta vacía (motivo: ' . $razon . ')');
        return ['ok' => false, 'texto' => '', 'motivo' => 'respuesta vacia'];
    }

    // Se quita cualquier etiqueta: el chat del navegador pinta con innerHTML,
    // así que todo lo que venga de fuera se trata como texto, nunca como
    // marcado. El navegador vuelve a escapar por su cuenta.
    $texto = trim(strip_tags($texto));
    if (mb_strlen($texto) > IA_MAX_RESPUESTA) {
        $texto = mb_substr($texto, 0, IA_MAX_RESPUESTA) . '…';
    }

    return ['ok' => true, 'texto' => $texto, 'motivo' => ''];
}
