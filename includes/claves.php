<?php
// ============================================================
// includes/claves.php
// Con qué algoritmo se guardan las contraseñas — Casa Bosque
// ============================================================
// Antes cada sitio escribía `password_hash($clave, PASSWORD_BCRYPT)` por su
// cuenta: cuatro sitios, cuatro oportunidades de que uno se quede atrás. Aquí
// la decisión se toma UNA vez.
//
// El algoritmo es Argon2id, que es lo que pide el estándar. bcrypt no está
// roto —las contraseñas guardadas con él siguen siendo válidas y se verifican
// igual, porque `password_verify()` lee el algoritmo del propio hash—, pero
// Argon2id resiste mejor el descifrado con tarjetas gráficas, que es
// exactamente el escenario de una base de datos robada.
//
// NADIE tiene que cambiar su contraseña: claveRehashSiHaceFalta() reescribe el
// hash de cada quien la próxima vez que entre, que es el único momento en que
// se tiene la contraseña en claro para poder recalcularla.
//
// Si el PHP del servidor no trajera Argon2id, se sigue con bcrypt en lugar de
// reventar el ingreso y la creación de usuarios: `password_hash()` con un
// algoritmo ausente lanza ValueError en PHP 8. Es una decisión de servidor, no
// de código, y el código no debe caerse por ella.
// ------------------------------------------------------------

/**
 * El algoritmo con el que se guardan las contraseñas NUEVAS.
 * Argon2id si este PHP lo trae; bcrypt si no.
 */
function claveAlgoritmo(): string
{
    static $algo = null;
    if ($algo === null) {
        $algo = (defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true))
            ? PASSWORD_ARGON2ID
            : PASSWORD_BCRYPT;
    }
    return $algo;
}

/**
 * Los parámetros de coste, FIJADOS a mano.
 *
 * Son los que PHP trae por defecto hoy (64 MB de memoria, 4 pasadas, 1 hilo),
 * pero escritos aquí a propósito: si un día el servidor sube de versión y PHP
 * cambia sus valores por defecto, `password_needs_rehash()` consideraría viejo
 * el hash de TODO el mundo y los reescribiría al entrar sin que nadie lo haya
 * decidido. Escritos, el coste es una decisión del proyecto.
 *
 * bcrypt no los usa: ignora estas claves y aplica su coste por defecto (10),
 * que es el de las contraseñas que ya existen.
 */
function claveOpciones(): array
{
    if (claveAlgoritmo() === PASSWORD_BCRYPT) return [];
    return ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1];
}

/** Nombre legible del algoritmo, para la pantalla de Seguridad. */
function claveAlgoritmoNombre(): string
{
    return claveAlgoritmo() === PASSWORD_BCRYPT ? 'bcrypt' : 'Argon2id';
}

/** El hash de una contraseña nueva. Único sitio que decide el algoritmo. */
function claveHash(string $clave): string
{
    return password_hash($clave, claveAlgoritmo(), claveOpciones());
}

/**
 * Reescribe el hash de la contraseña si quedó en un algoritmo anterior.
 *
 * Se llama DESPUÉS de que `password_verify()` dijera que la contraseña es
 * correcta y con la contraseña en claro todavía a mano: es el único instante
 * en que se puede recalcular sin pedirle nada a nadie.
 *
 * Los equipos de confianza se vuelven a firmar aquí mismo. Su huella es
 * `indiceCiego(password_hash . '|' . twofa_secret)` (includes/equipos.php),
 * así que cambiar el hash los invalidaría a todos y el administrador tendría
 * que teclear el código otra vez sin haber cambiado nada. Eso NO es lo que esa
 * huella protege: el cambio real de contraseña revoca los equipos de forma
 * explícita (includes/sesiones.php). Aquí la contraseña es la misma.
 *
 * Nunca lanza: un ingreso correcto no se puede estropear por no haber podido
 * mejorar el hash.
 *
 * @return bool true si el hash quedó reescrito
 */
function claveRehashSiHaceFalta(PDO $pdo, int $uid, string $claveEnClaro, string $hashActual): bool
{
    if ($uid < 1 || $hashActual === '' || $claveEnClaro === '') return false;
    // Con las MISMAS opciones que claveHash(): si no, el hash recién escrito
    // se consideraría viejo en el ingreso siguiente y se reescribiría cada vez.
    if (!password_needs_rehash($hashActual, claveAlgoritmo(), claveOpciones())) return false;

    try {
        // La huella de los equipos, tal como está ANTES de tocar el hash.
        $estadoViejo = null;
        if (function_exists('_equipoEstado')) {
            try { $estadoViejo = _equipoEstado($pdo, $uid); } catch (Throwable $e) { $estadoViejo = null; }
        }

        $nuevo = claveHash($claveEnClaro);
        // `and password_hash = ?` para no pisar un cambio de contraseña que
        // haya entrado entre la lectura y esta escritura: si alguien la cambió
        // mientras tanto, esta actualización no afecta ninguna fila y el hash
        // nuevo —el de la contraseña nueva— se queda, que es lo correcto.
        $st = $pdo->prepare("update usuarios set password_hash = ? where id = ? and password_hash = ?");
        $st->execute([$nuevo, $uid, $hashActual]);
        if ($st->rowCount() !== 1) return false;

        if ($estadoViejo !== null && function_exists('equiposReFirmar')) {
            equiposReFirmar($pdo, $uid, $estadoViejo);
        }
        return true;
    } catch (Throwable $e) {
        error_log('Claves: no se pudo rehacer el hash del usuario ' . $uid . ': ' . $e->getMessage());
        return false;
    }
}
