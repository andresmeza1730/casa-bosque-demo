<?php
// ============================================================
// includes/cifrado.php
// Cifrado en reposo AES-256 + índice ciego — Casa Bosque
// ============================================================
// Cifra campos sensibles (documento, teléfono, pasaporte) con
// AES-256-GCM (confidencialidad + integridad) usando IV aleatorio.
// Para poder BUSCAR y garantizar UNICIDAD sin exponer el dato, se
// usa un "índice ciego": un HMAC-SHA256 determinista del valor.
//
// Requisitos (OWASP A04 / ISO 27001):
//   - Claves fuera del código, en .env (APP_ENC_KEY, APP_IDX_KEY).
//   - Nunca almacenar texto plano de estos campos.
//
// Formato del texto cifrado:  v1:<base64(iv | tag | ciphertext)>
// El prefijo de versión permite migrar de esquema en el futuro y
// distinguir datos ya cifrados de texto plano heredado.
// ------------------------------------------------------------

/**
 * Lee y cachea las claves de cifrado desde .env.
 * Devuelve ['enc' => 32 bytes, 'idx' => 32 bytes].
 */
function _clavesCifrado(): array {
    static $claves = null;
    if ($claves !== null) return $claves;

    $envFile = dirname(__DIR__, 2) . '/casabosque-private/.env';
    $env = [];
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $env[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
        }
    }

    $enc = base64_decode($env['APP_ENC_KEY'] ?? '', true);
    $idx = base64_decode($env['APP_IDX_KEY'] ?? '', true);

    if ($enc === false || strlen($enc) !== 32 || $idx === false || strlen($idx) !== 32) {
        error_log('CRITICAL: APP_ENC_KEY/APP_IDX_KEY ausentes o inválidas en .env');
        throw new RuntimeException('Error de configuración de cifrado del servidor.');
    }

    return $claves = ['enc' => $enc, 'idx' => $idx];
}

/**
 * Cifra un valor con AES-256-GCM (IV aleatorio de 12 bytes).
 * Devuelve el texto cifrado con prefijo "v1:" o null si el valor
 * de entrada está vacío (para guardar NULL en la BD).
 */
function cifrar(?string $valor): ?string {
    if ($valor === null || $valor === '') return null;
    $claves = _clavesCifrado();
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($valor, 'aes-256-gcm', $claves['enc'], OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false) {
        throw new RuntimeException('Fallo al cifrar el dato.');
    }
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

/**
 * Descifra un valor producido por cifrar(). Si el valor no está
 * cifrado (texto plano heredado o null), lo devuelve tal cual para
 * no romper la app durante la migración de datos.
 */
function descifrar(?string $valor): ?string {
    if ($valor === null || $valor === '') return $valor;
    if (strncmp($valor, 'v1:', 3) !== 0) {
        return $valor; // texto plano heredado: passthrough
    }
    $claves = _clavesCifrado();
    $raw = base64_decode(substr($valor, 3), true);
    if ($raw === false || strlen($raw) < 28) {
        return null; // dato corrupto
    }
    $iv     = substr($raw, 0, 12);
    $tag    = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plano = openssl_decrypt($cipher, 'aes-256-gcm', $claves['enc'], OPENSSL_RAW_DATA, $iv, $tag);
    return $plano === false ? null : $plano;
}

/**
 * ¿El valor ya está cifrado con este esquema?
 */
function estaCifrado(?string $valor): bool {
    return $valor !== null && strncmp($valor, 'v1:', 3) === 0;
}

/**
 * Deja a la vista solo los últimos cuatro caracteres del documento.
 *
 * Para escribirlo en un registro que no se puede borrar —la auditoría— o para
 * elegir en una lista, los últimos dígitos bastan. El documento entero solo
 * aparece donde hace falta de verdad: la ficha del huésped.
 *
 * La tabla `auditoria` tiene disparadores que impiden UPDATE y DELETE, así que
 * lo que se escriba ahí no se puede corregir después. Un documento completo en
 * `auditoria.detalle` sobrevive incluso a anonimizarHuesped(), que solo limpia
 * la tabla `huespedes`: el derecho de supresión de la Ley 1581 quedaría sin
 * poder cumplirse. Por eso aquí entra enmascarado.
 */
function documentoOculto(?string $doc): string {
    $doc = preg_replace('/\s+/', '', (string) $doc);
    if ($doc === '') {
        return '';
    }
    $largo = mb_strlen($doc);
    if ($largo <= 4) {
        return str_repeat('•', $largo);
    }
    return str_repeat('•', $largo - 4) . mb_substr($doc, -4);
}

/**
 * Índice ciego determinista para búsquedas por igualdad y unicidad.
 * Normaliza (mayúsculas, sin espacios) y devuelve el HMAC-SHA256 en
 * hex. Mismo valor de entrada => mismo hash, sin revelar el dato.
 * Devuelve null si el valor está vacío.
 */
function indiceCiego(?string $valor): ?string {
    if ($valor === null) return null;
    $norm = strtoupper(preg_replace('/\s+/', '', $valor));
    if ($norm === '') return null;
    $claves = _clavesCifrado();
    return hash_hmac('sha256', $norm, $claves['idx']);
}
