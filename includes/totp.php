<?php
// ============================================================
// includes/totp.php
// Autenticación de dos factores (2FA) — TOTP (RFC 6238)
// ============================================================
// Implementación propia y autocontenida (sin Composer ni librerías
// externas) de contraseñas de un solo uso basadas en tiempo, compatible
// con Google Authenticator, Authy, Microsoft Authenticator, etc.
// Todo el cálculo es local: NO requiere conexiones salientes, ideal
// para un hosting compartido.
// ------------------------------------------------------------

/** Decodifica una cadena Base32 (RFC 4648) a binario. */
function base32Decode(string $b32): string {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper($b32);
    $buffer = 0; $bits = 0; $out = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $c = $b32[$i];
        if ($c === '=') break;
        $val = strpos($alfabeto, $c);
        if ($val === false) continue;   // ignora separadores/espacios
        $buffer = ($buffer << 5) | $val;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out .= chr(($buffer >> $bits) & 0xFF);
        }
    }
    return $out;
}

/** Codifica binario a Base32 (RFC 4648, sin relleno). */
function base32Encode(string $data): string {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0; $bits = 0; $out = '';
    for ($i = 0, $n = strlen($data); $i < $n; $i++) {
        $buffer = ($buffer << 8) | ord($data[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= $alfabeto[($buffer >> $bits) & 0x1F];
        }
    }
    if ($bits > 0) {
        $out .= $alfabeto[($buffer << (5 - $bits)) & 0x1F];
    }
    return $out;
}

/** Genera un secreto nuevo en Base32 (por defecto 160 bits). */
function totpSecretoNuevo(int $bytes = 20): string {
    return base32Encode(random_bytes($bytes));
}

/** Calcula el código TOTP para un contador dado (o el actual). */
function totpCodigo(string $secretB32, ?int $contador = null, int $digitos = 6, int $paso = 30): string {
    if ($contador === null) {
        $contador = intdiv(time(), $paso);
    }
    $key = base32Decode($secretB32);
    // Contador como entero de 8 bytes big-endian.
    $bin = pack('N*', 0) . pack('N*', $contador);
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $parte = ((ord($hash[$offset]) & 0x7F) << 24)
           | ((ord($hash[$offset + 1]) & 0xFF) << 16)
           | ((ord($hash[$offset + 2]) & 0xFF) << 8)
           |  (ord($hash[$offset + 3]) & 0xFF);
    $code = $parte % (10 ** $digitos);
    return str_pad((string) $code, $digitos, '0', STR_PAD_LEFT);
}

/**
 * Verifica un código contra el secreto, con tolerancia de ±$ventana pasos
 * (por desfase de reloj). Comparación en tiempo constante.
 */
function totpVerificar(string $secretB32, string $codigo, int $ventana = 1, int $paso = 30): bool {
    $codigo = preg_replace('/\s+/', '', $codigo);
    if (!preg_match('/^\d{6}$/', $codigo)) return false;
    $actual = intdiv(time(), $paso);
    for ($i = -$ventana; $i <= $ventana; $i++) {
        if (hash_equals(totpCodigo($secretB32, $actual + $i), $codigo)) {
            return true;
        }
    }
    return false;
}

/** URI otpauth:// para el código QR que escanea la app. */
function totpUri(string $secretB32, string $cuenta, string $emisor): string {
    $label = rawurlencode($emisor . ':' . $cuenta);
    return 'otpauth://totp/' . $label
         . '?secret=' . $secretB32
         . '&issuer=' . rawurlencode($emisor)
         . '&algorithm=SHA1&digits=6&period=30';
}
