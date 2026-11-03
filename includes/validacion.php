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
