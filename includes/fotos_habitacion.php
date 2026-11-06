<?php
// ============================================================
// includes/fotos_habitacion.php
// Las fotos de las habitaciones que sube el propio hotel (4.3.0)
// ============================================================
//
// Hasta 4.2.5 las fotos eran seis imágenes fijas del proyecto, repartidas por tipo de habitación (modules/habitaciones/index.php). El
// hotel pidió poder poner las suyas. Esta es la única fuente de cómo se guarda, se valida y se pinta una foto subida:
//
//  · Una foto por habitación, en assets/habitaciones/subidas/habitacion-<id>.webp. El nombre lo pone el SERVIDOR (el id es un entero): lo que
//    el usuario llama a su archivo no llega nunca a una ruta, así que no hay forma de escribir fuera de esa carpeta ni de subir un .php.
//  · NO hay columna nueva en la base (subirla habría sido una versión MAYOR con SQL): que exista el archivo ES que la habitación tiene foto
//    propia. Sin archivo, se sigue viendo la de siempre.
//  · Lo que se sube NUNCA se guarda tal cual: se decodifica con GD y se vuelve a codificar como WebP, reducido a 1280 px de lado mayor.
//    Eso quita de golpe los metadatos (la ubicación GPS que lleva una foto de celular), cualquier carga escondida dentro del archivo
//    (un «.jpg» que también es PHP) y el peso de una foto de 8 MB. Solo se aceptan JPG, PNG y WebP: ni GIF ni SVG (un SVG puede llevar script).
//  · La carpeta lleva su propio .htaccess que impide ejecutar nada ahí (mod_rewrite NO hereda del padre, y LiteSpeed ignora `Require all
//    denied`: por eso van las dos capas, igual que en el resto del proyecto). Si falta —por ejemplo, porque el servidor la creó vacía— se
//    escribe al crear la carpeta.

const FOTO_HAB_MAX_BYTES    = 8 * 1024 * 1024;   // lo que se acepta subir; una foto de celular pesa de 2 a 6 MB
const FOTO_HAB_LADO_MAXIMO  = 1280;              // px del lado mayor al guardar
const FOTO_HAB_LADO_MINIMO  = 320;               // por debajo de esto sale pixelada en la tarjeta
const FOTO_HAB_MAX_PIXELES  = 40000000;          // 40 megapíxeles: más es una bomba de descompresión, no una foto de habitación

const FOTO_HAB_HTACCESS = <<<'HTA'
# Aquí solo hay las fotos de las habitaciones que sube el hotel (WebP con nombre fijo). Nada de esto se ejecuta.
# Dos capas, como en el resto del proyecto: mod_rewrite no hereda del .htaccess de arriba y LiteSpeed ignora `Require all denied`.
Options -Indexes
RewriteEngine On
RewriteRule \.(php[0-9]?|phtml|phar|pl|py|cgi|sh|html?|js|svg)$ - [F,L]
<FilesMatch "\.(php[0-9]?|phtml|phar|pl|py|cgi|sh)$">
    Require all denied
</FilesMatch>
HTA;

function fotoHabitacionDir(): string {
    return dirname(__DIR__) . '/assets/habitaciones/subidas';
}

function fotoHabitacionRuta(int $habId): string {
    return fotoHabitacionDir() . '/habitacion-' . $habId . '.webp';
}

/** La URL de la foto que subió el hotel para esa habitación, o null si no hay. Lleva la fecha del archivo para que el navegador baje la nueva. */
function fotoHabitacionUrl(int $habId): ?string {
    $ruta = fotoHabitacionRuta($habId);
    if ($habId < 1 || !is_file($ruta)) return null;
    return BASE_URL . '/assets/habitaciones/subidas/habitacion-' . $habId . '.webp?v=' . (int) @filemtime($ruta);
}

/** Crea la carpeta de las fotos y su .htaccess si faltan. */
function fotoHabitacionAsegurarDir(): bool {
    $dir = fotoHabitacionDir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    $hta = $dir . '/.htaccess';
    if (!is_file($hta)) @file_put_contents($hta, FOTO_HAB_HTACCESS . "\n");
    return is_dir($dir) && is_writable($dir);
}

/** Cuántos bytes dice php.ini que se pueden usar (-1: sin tope). */
function fotoHabitacionMemoriaLimite(): int {
    $v = trim((string) ini_get('memory_limit'));
    if ($v === '' || $v === '-1') return -1;
    $n = (int) $v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;   // no hay break: cae a los siguientes
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return $n;
}

/**
 * Valida la foto subida y la deja guardada como la de esa habitación. Devuelve ['ok' => bool, 'mensaje' => string].
 * $archivo es la entrada de $_FILES; el llamador ya comprobó que quien sube es un administrador y el testigo CSRF.
 */
function fotoHabitacionGuardar(int $habId, array $archivo): array {
    $fallo = fn(string $m): array => ['ok' => false, 'mensaje' => $m];

    $error = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) return $fallo('Elige una foto para subir.');
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) return $fallo('La foto pesa más de lo que el servidor deja subir. Elige una más liviana (hasta 8 MB).');
    if ($error !== UPLOAD_ERR_OK) return $fallo('La foto no se pudo subir completa. Intenta de nuevo.');

    $tmp = (string) ($archivo['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return $fallo('La foto no llegó bien al servidor. Intenta de nuevo.');
    if ((int) ($archivo['size'] ?? 0) > FOTO_HAB_MAX_BYTES || (int) @filesize($tmp) > FOTO_HAB_MAX_BYTES) return $fallo('La foto pesa más de 8 MB. Elige una más liviana.');
    if (!extension_loaded('gd') || !function_exists('imagewebp')) return $fallo('Este servidor no puede procesar fotos. Avisa a soporte.');

    // Qué es lo que llegó: por su CONTENIDO, no por el nombre ni por lo que dice el navegador.
    $info = @getimagesize($tmp);
    if ($info === false) return $fallo('El archivo no es una imagen válida. Sube una foto en JPG, PNG o WebP.');
    [$ancho, $alto, $tipo] = $info;
    if (!in_array($tipo, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return $fallo('Solo se aceptan fotos en JPG, PNG o WebP.');
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? (string) finfo_file($fi, $tmp) : '';
        if ($fi) finfo_close($fi);
        if ($mime !== '' && !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return $fallo('Solo se aceptan fotos en JPG, PNG o WebP.');
    }
    if ($ancho < FOTO_HAB_LADO_MINIMO || $alto < FOTO_HAB_LADO_MINIMO * 0.6) return $fallo('La foto es muy pequeña (mínimo ' . FOTO_HAB_LADO_MINIMO . ' px de ancho). Se vería pixelada.');
    if ($ancho * $alto > FOTO_HAB_MAX_PIXELES) return $fallo('La foto tiene demasiados píxeles. Redúcela antes de subirla.');

    // Memoria para decodificarla: ~5 bytes por píxel, más lo que ya se usa. Se pide más si hace falta y el servidor lo permite.
    $necesita = $ancho * $alto * 5 + memory_get_usage();
    $limite = fotoHabitacionMemoriaLimite();
    if ($limite !== -1 && $necesita > $limite) {
        @ini_set('memory_limit', (string) (int) ceil($necesita / 1048576 + 16) . 'M');
        $limite = fotoHabitacionMemoriaLimite();
        if ($limite !== -1 && $necesita > $limite) return $fallo('La foto es demasiado grande para procesarla. Redúcela antes de subirla.');
    }

    $img = match ($tipo) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
        default        => @imagecreatefromwebp($tmp),
    };
    if (!$img) return $fallo('No se pudo leer la foto (¿está dañada?). Prueba con otra.');

    // Las fotos de celular traen la orientación en los metadatos, no en los píxeles: sin esto salen acostadas.
    if ($tipo === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $giro = match ((int) ($exif['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($giro !== 0) {
            $girada = @imagerotate($img, $giro, 0);
            if ($girada) { imagedestroy($img); $img = $girada; }
        }
    }

    $w = imagesx($img); $h = imagesy($img);
    $factor = min(1.0, FOTO_HAB_LADO_MAXIMO / max($w, $h));
    $nw = max(1, (int) round($w * $factor)); $nh = max(1, (int) round($h * $factor));
    $salida = imagecreatetruecolor($nw, $nh);
    imagealphablending($salida, false);
    imagesavealpha($salida, true);
    imagefill($salida, 0, 0, imagecolorallocatealpha($salida, 255, 255, 255, 127));
    imagecopyresampled($salida, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);

    if (!fotoHabitacionAsegurarDir()) { imagedestroy($salida); return $fallo('No se pudo guardar la foto: el servidor no deja escribir en la carpeta de fotos. Avisa a soporte.'); }
    $dir = fotoHabitacionDir();
    $provisional = tempnam($dir, 'tmp');
    if ($provisional === false) { imagedestroy($salida); return $fallo('No se pudo guardar la foto en el servidor. Avisa a soporte.'); }
    $bien = @imagewebp($salida, $provisional, 82) && (int) @filesize($provisional) > 0;
    imagedestroy($salida);
    // Se escribe a un archivo provisional y se mueve entero: nadie ve una foto a medias.
    if (!$bien || !@rename($provisional, fotoHabitacionRuta($habId))) {
        @unlink($provisional);
        return $fallo('No se pudo guardar la foto en el servidor. Intenta de nuevo.');
    }
    @chmod(fotoHabitacionRuta($habId), 0644);
    return ['ok' => true, 'mensaje' => 'Foto guardada.'];
}

/** Quita la foto subida (la habitación vuelve a verse con la de siempre). true si había una y se quitó. */
function fotoHabitacionQuitar(int $habId): bool {
    $ruta = fotoHabitacionRuta($habId);
    return $habId > 0 && is_file($ruta) && @unlink($ruta);
}
