<?php
// ============================================================
// includes/login_throttle.php
// Control de intentos de login — anti fuerza bruta
// Sistema Casa Bosque
// ============================================================
// Estrategia: tras MAX_INTENTOS fallidos en VENTANA_MINUTOS,
// se bloquea el login (por email Y por IP) durante
// BLOQUEO_MINUTOS. Un login exitoso limpia los intentos.
// No requiere servicios externos: solo la tabla intentos_login.
// ------------------------------------------------------------

const LOGIN_MAX_INTENTOS    = 5;   // fallos permitidos
const LOGIN_VENTANA_MINUTOS = 15;  // ventana en la que se cuentan
const LOGIN_BLOQUEO_MINUTOS = 15;  // duración del bloqueo

/**
 * Cuenta los intentos fallidos recientes para un email o una IP.
 * Devuelve el número de fallos dentro de la ventana de tiempo.
 */
function contarIntentosFallidos(PDO $pdo, string $email, string $ip): int {
    $stmt = $pdo->prepare("
        select count(*) as total
        from intentos_login
        where exitoso = 0
          and intento_en > (now() - interval ? minute)
          and (email = ? or ip_address = ?)
    ");
    // bindValue posicional: el intervalo va como entero
    $stmt->bindValue(1, LOGIN_VENTANA_MINUTOS, PDO::PARAM_INT);
    $stmt->bindValue(2, $email);
    $stmt->bindValue(3, $ip);
    $stmt->execute();
    $row = $stmt->fetch();
    return (int) ($row['total'] ?? 0);
}

/**
 * Indica si el login está bloqueado para este email/IP.
 * Devuelve los minutos restantes de bloqueo, o 0 si no está bloqueado.
 */
function minutosBloqueoRestante(PDO $pdo, string $email, string $ip): int {
    $fallos = contarIntentosFallidos($pdo, $email, $ip);
    if ($fallos < LOGIN_MAX_INTENTOS) {
        return 0;
    }
    // Buscar el fallo más reciente para calcular cuándo expira el bloqueo
    $stmt = $pdo->prepare("
        select max(intento_en) as ultimo
        from intentos_login
        where exitoso = 0
          and intento_en > (now() - interval ? minute)
          and (email = ? or ip_address = ?)
    ");
    $stmt->bindValue(1, LOGIN_VENTANA_MINUTOS, PDO::PARAM_INT);
    $stmt->bindValue(2, $email);
    $stmt->bindValue(3, $ip);
    $stmt->execute();
    $row = $stmt->fetch();
    if (empty($row['ultimo'])) {
        return 0;
    }
    $expira = strtotime($row['ultimo']) + (LOGIN_BLOQUEO_MINUTOS * 60);
    $restante = $expira - time();
    return $restante > 0 ? (int) ceil($restante / 60) : 0;
}

/**
 * Registra un intento de login (exitoso o fallido).
 */
function registrarIntentoLogin(PDO $pdo, string $email, string $ip, bool $exitoso): void {
    $stmt = $pdo->prepare("
        insert into intentos_login (email, ip_address, exitoso)
        values (?, ?, ?)
    ");
    $stmt->execute([$email, $ip, $exitoso ? 1 : 0]);
}

/**
 * Limpia los intentos fallidos de un email tras un login exitoso.
 */
function limpiarIntentosLogin(PDO $pdo, string $email): void {
    $stmt = $pdo->prepare("delete from intentos_login where email = ?");
    $stmt->execute([$email]);
}