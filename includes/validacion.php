<?php
// ============================================================
// includes/validacion.php
// Helpers de validación y sanitización de entradas — Casa Bosque
// ============================================================
// Centraliza la validación de datos que entran desde formularios
// (especialmente los públicos). Complementa —no reemplaza— a las
// consultas parametrizadas (PDO), que ya previenen SQLi.
//
// Objetivo (OWASP A05): validar y normalizar SIEMPRE en el backend,
// nunca confiar en los atributos maxlength/required del HTML.
// ------------------------------------------------------------

/**
 * Normaliza texto libre: recorta espacios, colapsa espacios internos,
 * elimina caracteres de control y (opcionalmente) recorta a un máximo.
 * NO escapa HTML: eso se hace en la vista con htmlspecialchars().
 */
function limpiarTexto(?string $valor, int $maxLen = 255): string {
    $valor = (string) $valor;
    // Quitar caracteres de control (excepto tab/nueva línea que igual colapsamos)
    $valor = preg_replace('/[\x00-\x1F\x7F]/u', '', $valor) ?? '';
    // Colapsar espacios múltiples y recortar extremos
    $valor = trim(preg_replace('/\s+/u', ' ', $valor) ?? '');
    if (mb_strlen($valor) > $maxLen) {
        $valor = mb_substr($valor, 0, $maxLen);
    }
    return $valor;
}

/**
 * Valida un correo electrónico. Devuelve el correo normalizado
 * (en minúsculas) o null si no es válido.
 */
function validarEmail(?string $email, int $maxLen = 150): ?string {
    $email = limpiarTexto($email, $maxLen);
    if ($email === '') return null;
    $email = mb_strtolower($email);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

/**
 * Valida un número de documento (CC/CE/PAS/NIT).
 * Acepta letras, números y guiones (pasaportes extranjeros pueden
 * tener letras). Devuelve el valor normalizado o null si no cumple.
 */
function validarDocumento(?string $doc, int $maxLen = 30): ?string {
    $doc = strtoupper(limpiarTexto($doc, $maxLen));
    // Solo alfanumérico y guion; entre 3 y $maxLen caracteres
    if (!preg_match('/^[A-Z0-9\-]{3,' . $maxLen . '}$/', $doc)) {
        return null;
    }
    return $doc;
}

/**
 * Valida un teléfono. Permite dígitos, espacios, +, - y paréntesis.
 * Exige al menos 7 dígitos reales. Devuelve el valor normalizado
 * o null si no cumple.
 */
function validarTelefono(?string $tel, int $maxLen = 20): ?string {
    $tel = limpiarTexto($tel, $maxLen);
    if (!preg_match('/^[0-9+\-\s()]{7,' . $maxLen . '}$/', $tel)) {
        return null;
    }
    // Debe contener al menos 7 dígitos
    if (mb_strlen(preg_replace('/\D/', '', $tel)) < 7) {
        return null;
    }
    return $tel;
}

/**
 * Valida un nombre/apellido: letras (incluye acentos y ñ), espacios,
 * apóstrofos y guiones. Devuelve el valor normalizado o null.
 */
function validarNombre(?string $nombre, int $maxLen = 100): ?string {
    $nombre = limpiarTexto($nombre, $maxLen);
    if ($nombre === '') return null;
    if (!preg_match("/^[\p{L}\p{M}'\-. ]{2,{$maxLen}}$/u", $nombre)) {
        return null;
    }
    return $nombre;
}

// ── Montos, tarifas y otros enteros que se teclean (4.2.4) ──────────────────
// Hasta 4.2.3 cada pantalla convertía el campo con `(int)` o `(float)`, y PHP no rechaza nada con eso: «abc» daba 0 (un precio
// de $0 guardado, una capacidad «sin tope»), «12abc» daba 12, «1e6» daba un millón y «40.000» —cómo se escribe cuarenta mil pesos
// en Colombia— daba 40. Estas funciones son la ÚNICA forma de leer un monto o un entero de un formulario: devuelven el número o
// null, y quien llama dice qué estaba mal. Nunca convierten «lo que se pueda».

/** Tope de cualquier monto en pesos: cabe de sobra en decimal(12,2) y nadie en el hotel cobra más. */
const MONTO_MAX = 999999999;

/**
 * Deja el texto de un número entero listo para convertirlo: solo dígitos, con o sin separador de miles
 * («1.500», «1,500», «1 500»). Devuelve null si trae cualquier otra cosa (letras, signos, notación científica,
 * decimales, un arreglo en lugar de un texto…).
 */
function textoEntero(mixed $valor): ?string {
    if (!is_string($valor) && !is_int($valor)) return null;
    $t = trim((string) $valor);
    if ($t === '') return null;
    if (preg_match('/^\d{1,3}(?:[.,\x{00A0} ]\d{3})+$/u', $t)) {
        $t = preg_replace('/[.,\x{00A0} ]/u', '', $t) ?? '';
    }
    return preg_match('/^\d{1,18}$/', $t) === 1 ? $t : null;
}

/** Entero entre $min y $max, o null si el campo no es un entero o se sale del rango. */
function validarEntero(mixed $valor, int $min = 0, int $max = PHP_INT_MAX): ?int {
    $t = textoEntero($valor);
    if ($t === null) return null;
    $n = (int) $t;
    return ($n >= $min && $n <= $max) ? $n : null;
}

/** Monto en pesos enteros (sin centavos), de $min a MONTO_MAX, o null. */
function validarMonto(mixed $valor, int $min = 1): ?int {
    return validarEntero($valor, $min, MONTO_MAX);
}

// ── Documento según su tipo (4.2.3) ─────────────────────────────────────────
// Hasta 4.2.2 el documento de un huésped se guardaba tal cual lo tecleaban: una cédula con letras salía igual que una
// con dígitos. Esta es la forma de leer un documento de un formulario: devuelve el número normalizado o null, y quien llama
// dice qué estaba mal. El filtro de assets/js/campos-numericos.js es ayuda en pantalla; esto es lo que manda.
/**
 * Número de documento según su tipo. CC, CE, TI, RC y NIT son solo dígitos (se aceptan puntos, guiones y espacios
 * al teclear y se quitan: «1.234.567.890»); el pasaporte lleva letras y números. Devuelve el documento normalizado
 * o null. Para un documento sin tipo conocido, usa validarDocumento().
 */
function validarDocumentoTipo(?string $tipo, ?string $doc): ?string {
    $tipo = strtoupper((string) $tipo);
    $doc = strtoupper(limpiarTexto($doc, 40));
    if (in_array($tipo, ['CC', 'CE', 'TI', 'RC', 'NIT'], true)) {
        $solo = preg_replace('/[\s.\-]/', '', $doc) ?? '';
        return preg_match('/^\d{3,20}$/', $solo) === 1 ? $solo : null;
    }
    return preg_match('/^[A-Z0-9\-]{3,30}$/', $doc) === 1 ? $doc : null;
}

// Longitud mínima de contraseña (política ISO 27001). Centralizada para
// reutilizar en el mensaje de ayuda de la interfaz.
const PASSWORD_MIN_LEN = 10;

/**
 * Las reglas de la contraseña, en un único sitio (política ISO 27001).
 *
 * Cada regla trae tres cosas: el texto que se le muestra a quien la escribe,
 * el error que se devuelve si no la cumple, y la comprobación en sí.
 * `validarPassword()` recorre esta lista, y la pantalla de usuarios publica
 * el patrón `js` al navegador para ir marcando las reglas en vivo mientras se
 * teclea. De este modo la ayuda visual y la validación de verdad salen de la
 * MISMA lista: si se duplicaran, acabarían discrepando.
 *
 * La que manda sigue siendo la del servidor. El marcado en pantalla es ayuda,
 * no control.
 *
 * @return array<int,array{texto:string,error:string,php:callable,js:string}>
 */
function reglasPassword(): array {
    return [
        [
            'texto' => 'Al menos ' . PASSWORD_MIN_LEN . ' caracteres',
            'error' => 'La contraseña debe tener al menos ' . PASSWORD_MIN_LEN . ' caracteres.',
            'php'   => fn(string $p): bool => mb_strlen($p) >= PASSWORD_MIN_LEN,
            'js'    => '[\s\S]{' . PASSWORD_MIN_LEN . ',}',
        ],
        [
            'texto' => 'Una letra mayúscula',
            'error' => 'La contraseña debe incluir al menos una letra mayúscula.',
            'php'   => fn(string $p): bool => (bool) preg_match('/[A-Z]/', $p),
            'js'    => '[A-Z]',
        ],
        [
            'texto' => 'Una letra minúscula',
            'error' => 'La contraseña debe incluir al menos una letra minúscula.',
            'php'   => fn(string $p): bool => (bool) preg_match('/[a-z]/', $p),
            'js'    => '[a-z]',
        ],
        [
            'texto' => 'Un número',
            'error' => 'La contraseña debe incluir al menos un número.',
            'php'   => fn(string $p): bool => (bool) preg_match('/[0-9]/', $p),
            'js'    => '[0-9]',
        ],
        [
            'texto' => 'Un carácter especial (! @ # $ %…)',
            'error' => 'La contraseña debe incluir al menos un carácter especial (ej: !@#$%).',
            'php'   => fn(string $p): bool => (bool) preg_match('/[^A-Za-z0-9]/', $p),
            'js'    => '[^A-Za-z0-9]',
        ],
    ];
}

/**
 * Valida la fortaleza de una contraseña. Devuelve el mensaje de error de la
 * primera regla que no se cumpla, o null si la contraseña es válida.
 */
function validarPassword(string $password): ?string {
    foreach (reglasPassword() as $regla) {
        if (!($regla['php'])($password)) {
            return $regla['error'];
        }
    }
    return null;
}
