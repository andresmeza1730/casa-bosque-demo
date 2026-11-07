<?php
// ============================================================
// includes/categorias.php
// Las categorías de la Carta — Casa Bosque (5.0.0)
// ============================================================
// UNA sola fuente para las categorías de los productos. Hasta 4.5.0 eran una
// lista fija de cinco (un ENUM en la base y el mismo arreglo copiado en
// Carta y Consumos). Ahora el hotel las crea, las renombra y las desactiva desde
// «Carta y Restaurante», y las leen las dos pantallas desde aquí.
//
// LA CLAVE Y EL NOMBRE SON COSAS DISTINTAS.
//   · La CLAVE («bebida», «postres») es lo que guarda cada producto en
//     `productos.categoria`. No cambia nunca: se saca del nombre al CREAR la
//     categoría y ahí se queda.
//   · El NOMBRE («Tienda · Bebidas») es lo que se ve y es lo que se renombra.
//   Por eso renombrar una categoría no deja ningún producto sin ella.
//   Y la regla del horario del restaurante (Consumos) se apoya en la clave
//   «restaurante», no en un nombre que alguien pueda cambiar.
//
// DESACTIVAR NO BORRA NADA. Una categoría desactivada deja de ofrecerse al crear
// o editar un producto, pero los productos que ya la tienen siguen existiendo y
// vendiéndose (en Consumos salen bajo «Todas»). «Otros» no se desactiva: es la
// categoría con la que nace un producto cuando no se elige otra.
//
// ANTES DEL SQL (versión MAYOR): si la tabla `categorias_producto` todavía no
// existe, todo sigue funcionando con las cinco de siempre y la Carta avisa que
// falta ejecutar sql/migration_027_categorias_producto.sql. Crear categorías
// solo se puede después. Nada de esto lee information_schema (el hosting no deja).
// ------------------------------------------------------------

/** Las cinco de siempre, con la clave que ya tenían los productos. Es la semilla de la tabla y lo que se usa mientras la tabla no existe. */
const CATEGORIAS_BASE = [
    'snack'       => 'Tienda · Mecato',
    'bebida'      => 'Tienda · Bebidas',
    'servicio'    => 'Servicios',
    'restaurante' => 'Restaurante',
    'otro'        => 'Otros',
];

/** La categoría por defecto de un producto: no se desactiva. */
const CATEGORIA_POR_DEFECTO = 'otro';

const CATEGORIA_NOMBRE_MIN = 2;
const CATEGORIA_NOMBRE_MAX = 40;   // el largo de la columna `nombre`

/**
 * ¿Existe la tabla `categorias_producto`? (¿ya se ejecutó el SQL de 5.0.0?)
 * Se pregunta con `show tables like`, que sí se puede en el hosting, y se recuerda
 * por petición. `$refrescar` vuelve a preguntar (lo usa la suite al cambiar la base).
 */
function categoriasTabla(PDO $pdo, bool $refrescar = false): bool
{
    static $sabe = null;
    if ($sabe === null || $refrescar) {
        try {
            $sabe = (bool) $pdo->query("show tables like 'categorias_producto'")->fetchColumn();
        } catch (Throwable $e) {
            $sabe = false;
        }
    }
    return $sabe;
}

/**
 * Las categorías, en el orden en que se muestran (las de siempre primero y luego las
 * que creó el hotel, por orden de creación). Con `$soloActivas`, solo las que se
 * ofrecen para un producto. `productos` es cuántos productos tiene cada una.
 *
 * Sin la tabla (antes del SQL) son las cinco de siempre, todas activas.
 *
 * @return list<array{id:int,clave:string,nombre:string,activa:int,orden:int,productos:int}>
 */
function categoriasLista(PDO $pdo, bool $soloActivas = false): array
{
    $conteo = [];
    try {
        foreach ($pdo->query("select categoria, count(*) as n from productos group by categoria")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $conteo[(string) $r['categoria']] = (int) $r['n'];
        }
    } catch (Throwable $e) { /* sin productos que contar */ }

    if (!categoriasTabla($pdo)) {
        $lista = [];
        $orden = 10;
        foreach (CATEGORIAS_BASE as $clave => $nombre) {
            $lista[] = ['id' => 0, 'clave' => $clave, 'nombre' => $nombre, 'activa' => 1, 'orden' => $orden, 'productos' => $conteo[$clave] ?? 0];
            $orden += 10;
        }
        return $lista;
    }

    $filas = $pdo->query("select id, clave, nombre, activa, orden from categorias_producto "
        . ($soloActivas ? "where activa = 1 " : "")
        . "order by orden, id")->fetchAll(PDO::FETCH_ASSOC);
    $lista = [];
    foreach ($filas as $f) {
        $lista[] = ['id' => (int) $f['id'], 'clave' => (string) $f['clave'], 'nombre' => (string) $f['nombre'],
                    'activa' => (int) $f['activa'], 'orden' => (int) $f['orden'], 'productos' => $conteo[(string) $f['clave']] ?? 0];
    }
    return $lista;
}

/** clave → nombre, de TODAS (también las desactivadas): sirve para escribir la categoría de un producto que ya existe. */
function categoriasMapa(PDO $pdo): array
{
    $mapa = [];
    foreach (categoriasLista($pdo, false) as $c) $mapa[$c['clave']] = $c['nombre'];
    return $mapa;
}

/** El nombre a mostrar de una clave; si ya no existe (no debería), «Otros». */
function categoriaEtiqueta(array $mapa, ?string $clave): string
{
    return $mapa[(string) $clave] ?? ($mapa[CATEGORIA_POR_DEFECTO] ?? 'Otros');
}

/** Una categoría por su clave, o null. */
function categoriaPorClave(PDO $pdo, string $clave): ?array
{
    foreach (categoriasLista($pdo, false) as $c) {
        if ($c['clave'] === $clave) return $c;
    }
    return null;
}

/**
 * ¿Se puede poner esta categoría a un producto? Tiene que existir y estar activa;
 * la excepción es la que el producto YA tiene (`$claveActual`): editar su precio no
 * debe fallar porque alguien desactivó su categoría.
 */
function categoriaPermitida(PDO $pdo, string $clave, ?string $claveActual = null): bool
{
    $c = categoriaPorClave($pdo, $clave);
    if (!$c) return false;
    return $c['activa'] === 1 || ($claveActual !== null && $clave === $claveActual);
}

/**
 * El nombre limpio, o null si no sirve: de 2 a 40 caracteres, que empiece con una letra o un número, y solo letras, números, espacios y . , · & / ( ) ' -
 * Los espacios repetidos se juntan y los caracteres de control se quitan. Nunca HTML: lo que no es de la lista se rechaza (y además se escapa al mostrarlo).
 *
 * @param mixed $crudo lo que vino del formulario (un arreglo o algo que no es texto da null)
 */
function categoriaNombreValido($crudo): ?string
{
    if (!is_string($crudo) || !mb_check_encoding($crudo, 'UTF-8')) return null;
    $n = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $crudo);
    if ($n === null) return null;
    $n = trim((string) preg_replace('/\s+/u', ' ', $n));
    $largo = mb_strlen($n);
    if ($largo < CATEGORIA_NOMBRE_MIN || $largo > CATEGORIA_NOMBRE_MAX) return null;
    if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .,·&\/()\'\-]*$/u', $n)) return null;
    return $n;
}

/** Lo que se le dice a quien escribió un nombre que no sirve. */
const CATEGORIA_NOMBRE_AYUDA = 'El nombre de la categoría debe tener entre 2 y 40 caracteres: letras, números, espacios y . , · & / ( ) -';

/** La clave de un nombre: minúsculas, sin acentos, solo a-z, 0-9 y guion bajo («Postres y helados» → «postres_y_helados»). */
function categoriaClaveDe(string $nombre): string
{
    $t = mb_strtolower($nombre, 'UTF-8');
    $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
                    'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c']);
    $t = trim((string) preg_replace('/[^a-z0-9]+/', '_', $t), '_');
    if ($t === '') $t = 'categoria';
    return substr($t, 0, 30);
}

/**
 * Crea una categoría. No audita ni imprime: devuelve el resultado y el que llama
 * decide (la pantalla de la Carta audita con el id).
 *
 * @return array{ok:bool,mensaje:string,id:int,clave:string,tipo:string}
 */
function categoriaCrear(PDO $pdo, $nombreCrudo): array
{
    $no = fn(string $m, string $tipo = 'danger') => ['ok' => false, 'mensaje' => $m, 'id' => 0, 'clave' => '', 'tipo' => $tipo];
    if (!categoriasTabla($pdo)) return $no('Para crear categorías falta ejecutar el SQL de la versión 5.0.0 (sql/migration_027_categorias_producto.sql).', 'warning');
    $nombre = categoriaNombreValido($nombreCrudo);
    if ($nombre === null) return $no(CATEGORIA_NOMBRE_AYUDA);

    // Mismo nombre ya existente (sin distinguir mayúsculas ni acentos: lo hace el collation de la columna).
    $st = $pdo->prepare("select count(*) from categorias_producto where nombre = ?");
    $st->execute([$nombre]);
    if ((int) $st->fetchColumn() > 0) return $no('Ya existe una categoría con ese nombre.', 'warning');

    $base = categoriaClaveDe($nombre);
    $mayor = (int) $pdo->query("select coalesce(max(orden), 0) from categorias_producto")->fetchColumn();
    $orden = max(100, $mayor + 10);   // las cinco de siempre (10 a 50) van primero
    for ($i = 1; $i <= 30; $i++) {
        $clave = $i === 1 ? $base : substr($base, 0, 36) . '_' . $i;
        try {
            $pdo->prepare("insert into categorias_producto (clave, nombre, orden) values (?, ?, ?)")->execute([$clave, $nombre, $orden]);
            return ['ok' => true, 'mensaje' => "Categoría «{$nombre}» creada.", 'id' => (int) $pdo->lastInsertId(), 'clave' => $clave, 'tipo' => 'success'];
        } catch (PDOException $e) {
            $dup = ($e->errorInfo[1] ?? 0) === 1062;
            if (!$dup) { error_log('Categorías: no se pudo crear: ' . $e->getMessage()); return $no('No se pudo crear la categoría. Vuelve a intentarlo.'); }
            // 1062 por el NOMBRE (otra persona la creó a la vez): se dice. Por la CLAVE: se prueba con el siguiente número.
            if (stripos($e->getMessage(), 'uk_categoria_nombre') !== false) return $no('Ya existe una categoría con ese nombre.', 'warning');
        }
    }
    return $no('No se pudo crear la categoría. Vuelve a intentarlo.');
}

/**
 * Cambia el NOMBRE de una categoría (la clave no se toca).
 *
 * @return array{ok:bool,mensaje:string,id:int,tipo:string}
 */
function categoriaRenombrar(PDO $pdo, string $clave, $nombreCrudo): array
{
    $no = fn(string $m, string $tipo = 'danger') => ['ok' => false, 'mensaje' => $m, 'id' => 0, 'tipo' => $tipo];
    if (!categoriasTabla($pdo)) return $no('Para renombrar categorías falta ejecutar el SQL de la versión 5.0.0 (sql/migration_027_categorias_producto.sql).', 'warning');
    $nombre = categoriaNombreValido($nombreCrudo);
    if ($nombre === null) return $no(CATEGORIA_NOMBRE_AYUDA);
    $c = categoriaPorClave($pdo, $clave);
    if (!$c) return $no('Esa categoría no existe.');
    if ($c['nombre'] === $nombre) return $no('La categoría ya se llama así.', 'warning');

    $st = $pdo->prepare("select count(*) from categorias_producto where nombre = ? and clave <> ?");
    $st->execute([$nombre, $clave]);
    if ((int) $st->fetchColumn() > 0) return $no('Ya existe otra categoría con ese nombre.', 'warning');
    try {
        $pdo->prepare("update categorias_producto set nombre = ? where clave = ?")->execute([$nombre, $clave]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) return $no('Ya existe otra categoría con ese nombre.', 'warning');
        error_log('Categorías: no se pudo renombrar: ' . $e->getMessage());
        return $no('No se pudo renombrar la categoría. Vuelve a intentarlo.');
    }
    return ['ok' => true, 'mensaje' => "La categoría ahora se llama «{$nombre}».", 'id' => $c['id'], 'tipo' => 'success'];
}

/**
 * Activa o desactiva una categoría. «Otros» no se desactiva.
 *
 * @return array{ok:bool,mensaje:string,id:int,tipo:string}
 */
function categoriaCambiarActiva(PDO $pdo, string $clave, bool $activa): array
{
    $no = fn(string $m, string $tipo = 'danger') => ['ok' => false, 'mensaje' => $m, 'id' => 0, 'tipo' => $tipo];
    if (!categoriasTabla($pdo)) return $no('Para desactivar categorías falta ejecutar el SQL de la versión 5.0.0 (sql/migration_027_categorias_producto.sql).', 'warning');
    $c = categoriaPorClave($pdo, $clave);
    if (!$c) return $no('Esa categoría no existe.');
    if (!$activa && $clave === CATEGORIA_POR_DEFECTO) return $no('«' . $c['nombre'] . '» no se desactiva: es la categoría con la que nace un producto cuando no se elige otra.', 'warning');
    if ($c['activa'] === ($activa ? 1 : 0)) return $no('La categoría ya estaba ' . ($activa ? 'activa' : 'desactivada') . '.', 'warning');
    $pdo->prepare("update categorias_producto set activa = ? where clave = ?")->execute([$activa ? 1 : 0, $clave]);
    return ['ok' => true, 'id' => $c['id'], 'tipo' => 'success',
            'mensaje' => $activa ? '«' . $c['nombre'] . '» vuelve a ofrecerse para los productos.'
                                 : '«' . $c['nombre'] . '» ya no se ofrece para productos nuevos. Los que ya la tienen siguen igual.'];
}

/**
 * El reinicio TOTAL de fábrica deja las categorías como vienen de instalación: se borran
 * las que creó el hotel y las cinco de siempre vuelven a su nombre, activas y en su orden.
 * (Sin la tabla no hace nada.)
 */
function categoriasRestablecer(PDO $pdo): void
{
    if (!categoriasTabla($pdo)) return;
    $claves = array_keys(CATEGORIAS_BASE);
    $marcas = implode(',', array_fill(0, count($claves), '?'));
    $pdo->prepare("delete from categorias_producto where clave not in ($marcas)")->execute($claves);
    $orden = 10;
    foreach (CATEGORIAS_BASE as $clave => $nombre) {
        $pdo->prepare("insert into categorias_producto (clave, nombre, activa, orden) values (?, ?, 1, ?)
                       on duplicate key update nombre = values(nombre), activa = 1, orden = values(orden)")->execute([$clave, $nombre, $orden]);
        $orden += 10;
    }
}
