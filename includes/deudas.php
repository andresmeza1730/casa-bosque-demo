<?php
// ============================================================
// includes/deudas.php
// Quién quedó debiendo y cómo se cobra — Casa Bosque (4.4.0)
// ============================================================
// UNA sola fuente para las deudas. Antes la consulta de los deudores y el cobro
// vivían dentro de dashboard.php; el panel de Deudores (modules/deudores) los
// necesita también, y una regla de dinero escrita en dos sitios es lo que más
// daño ha hecho en este proyecto. Ahora los leen los dos.
//
// Una DEUDA es una estancia CERRADA cuyo total a pagar es mayor que lo abonado:
//   saldo = total_pagar − total_abonado.
// Sale de la lista cuando el saldo llega a cero. «En mora» es más de 30 días
// desde el check-out.
//
// UN PAGO DE DEUDA SE FECHA EL DÍA EN QUE SE PAGA, no el del check-out: la fila
// de `pagos` toma `fecha` del momento en que se inserta, y el Arqueo de Caja
// (includes/caja.php) suma por esa fecha. Por eso la deuda de un check-out de
// hace tres semanas aparece en la caja de HOY, que es cuando el dinero entró.
// La estancia conserva su fecha de salida: lo que cambia es cuándo se recibió.
// ------------------------------------------------------------

require_once __DIR__ . '/validacion.php';   // validarMonto: el monto de un pago es un entero de pesos

/**
 * La nota con la que se guarda el pago de una deuda. Es lo que distingue estos
 * pagos de un abono al reservar o del saldo cobrado en el check-out, y lo que
 * lee el Arqueo de Caja para explicarlos (includes/caja.php). Una sola.
 */
const DEUDA_NOTA_PAGO = 'Pago de deuda posterior al check-out';

/** Desde cuántos días de haber salido se considera «en mora». */
const DEUDA_DIAS_MORA = 30;

/** Los métodos de pago que se aceptan (los mismos de la tabla `pagos`). */
const DEUDA_METODOS = ['efectivo', 'tarjeta', 'transferencia', 'nequi', 'otro'];

/** Lo más largo que se acepta de un texto buscado (el de includes/busqueda.php). */
const DEUDA_BUSQUEDA_TERMINOS = 6;

/**
 * Trozos `and (...)` y sus parámetros para filtrar por nombre, apellido o número
 * de habitación. Cada palabra tiene que aparecer en alguno de los tres: «ana 12»
 * trae a Ana de la 12 y no a las dos por separado. El documento NO se busca aquí:
 * está cifrado y solo se encuentra exacto (índice ciego), y esta lista no lo necesita.
 *
 * @return array{0:string,1:list<string>}
 */
function deudasFiltroBusqueda(string $buscar): array
{
    $sql = '';
    $params = [];
    $terminos = preg_split('/\s+/u', trim($buscar), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach (array_slice($terminos, 0, DEUDA_BUSQUEDA_TERMINOS) as $t) {
        // % _ y \ del texto escrito se escapan: se busca lo que la persona tecleó, no un patrón.
        $like = '%' . addcslashes($t, '%_\\') . '%';
        $sql .= ' and (hu.nombres like ? or hu.apellidos like ? or h.numero like ?)';
        array_push($params, $like, $like, $like);
    }
    return [$sql, $params];
}

/**
 * Los huéspedes que quedaron debiendo, el más antiguo primero.
 *
 * El teléfono sale CIFRADO, como está en la base: quien lo pinta lo descifra.
 *
 * @return list<array{id:int|string,fecha_checkout:string,saldo:string,dias:string,habitacion:string,nombres:string,apellidos:string,telefono:?string}>
 */
function deudasLista(PDO $pdo, string $buscar = ''): array
{
    [$filtro, $params] = deudasFiltroBusqueda($buscar);
    $st = $pdo->prepare("
        select e.id, e.fecha_checkout, (e.total_pagar - e.total_abonado) as saldo,
               datediff(now(), e.fecha_checkout) as dias,
               h.numero as habitacion, hu.nombres, hu.apellidos, hu.telefono
        from estancias e
        join habitaciones h on h.id = e.habitacion_id
        join huespedes hu on hu.id = e.huesped_id
        where e.estado = 'cerrada' and e.total_pagar > e.total_abonado
        $filtro
        order by e.fecha_checkout asc, e.id asc
    ");
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Cuántos días hacia atrás llega la lista de pagos de deuda recibidos. */
const DEUDA_DIAS_PAGOS = 30;

/**
 * Los pagos de deuda recibidos en los últimos DEUDA_DIAS_PAGOS días (incluido hoy),
 * el más reciente primero, con el día y la hora EN QUE SE PAGARON (`pagos.fecha`) y
 * quién los recibió. Es una ventana de DÍAS y no de «las últimas N filas» para que el
 * total de cada día salga completo: cortar por filas dejaría el día del borde con un
 * subtotal menor que el del Arqueo de Caja. El tope de 500 filas es solo una guarda.
 *
 * @return list<array{id:int|string,monto:string,metodo:string,fecha:string,habitacion:string,nombres:string,apellidos:string,usuario:?string}>
 */
function deudasPagosRecibidos(PDO $pdo, string $buscar = ''): array
{
    [$filtro, $params] = deudasFiltroBusqueda($buscar);
    $st = $pdo->prepare("
        select p.id, p.monto, p.metodo, p.fecha,
               h.numero as habitacion, hu.nombres, hu.apellidos,
               u.nombre as usuario
        from pagos p
        join estancias e    on e.id = p.estancia_id
        join habitaciones h on h.id = e.habitacion_id
        join huespedes hu   on hu.id = e.huesped_id
        left join usuarios u on u.id = p.usuario_id
        where p.nota = ?
          and p.fecha >= (curdate() - interval " . (int) (DEUDA_DIAS_PAGOS - 1) . " day)
        $filtro
        order by p.fecha desc, p.id desc
        limit 500
    ");
    $st->execute(array_merge([DEUDA_NOTA_PAGO], $params));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Registra el pago (total o abono) de una deuda. Devuelve [mensaje, tipo] para
 * mostrar con setFlash(): esta función no imprime ni redirige.
 *
 * El PERMISO no se decide aquí: quien la llama ya comprobó isAdmin(), igual que
 * antes en dashboard.php.
 *
 * Es el mismo orden de siempre para el dinero: transacción ANTES de leer, la
 * estancia bloqueada, revalidar DENTRO, escribir con el tope en el `where` y
 * mirar rowCount, commit, y la auditoría DESPUÉS. Antes el saldo se calculaba
 * fuera de la transacción y el `update` no llevaba condición: dos cobros del
 * mismo saldo a la vez (basta un doble clic) leían el mismo `total_abonado`,
 * insertaban DOS filas en `pagos` y dejaban lo abonado por encima de lo que se
 * debía, con la deuda negativa y la estancia fuera de la lista sin rastro.
 *
 * @param mixed $montoCrudo lo que vino del formulario (se valida aquí)
 * @param mixed $metodoCrudo lo que vino del formulario (cualquier otro vale «efectivo»)
 * @return array{0:string,1:string}
 */
function deudaRegistrarPago(PDO $pdo, int $estId, $montoCrudo, $metodoCrudo): array
{
    if ($estId <= 0) {
        return ['No se registró nada: no se indicó a qué deuda corresponde el pago.', 'danger'];
    }
    // 4.2.4: un entero de pesos y nada más. Antes (float): «12abc» cobraba 12 y «1e999» cobraba la deuda entera.
    $monto = validarMonto($montoCrudo, 1);
    $metodo = in_array($metodoCrudo, DEUDA_METODOS, true) ? $metodoCrudo : 'efectivo';
    if ($monto === null) {
        // Antes un monto inválido se descartaba SIN decir nada y parecía que el pago había entrado.
        return ['El monto del pago debe ser un número entero de pesos mayor a cero, sin letras (por ejemplo 50000). No se registró nada.', 'danger'];
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("select id from estancias where id = ? for update")->execute([$estId]);

        // La lectura que vale es la de DESPUÉS del bloqueo.
        $st = $pdo->prepare("select total_pagar, total_abonado from estancias where id = ? and estado = 'cerrada'");
        $st->execute([$estId]);
        $e = $st->fetch();

        $deuda = $e ? ((float) $e['total_pagar'] - (float) $e['total_abonado']) : 0.0;
        if (!$e || $deuda <= 0) {
            $pdo->rollBack();
            return ['No se registró el pago: esa deuda ya estaba saldada.', 'warning'];
        }

        $abono = min($monto, $deuda);   // no cobrar más que lo que debe

        // Y el `update` lleva el tope en el propio `where`: aunque alguien llegara
        // hasta aquí sin el bloqueo, no puede dejar lo abonado por encima de lo que se debe.
        $upd = $pdo->prepare("update estancias
                              set total_abonado = total_abonado + ?
                              where id = ? and estado = 'cerrada'
                                and total_abonado + ? <= total_pagar");
        $upd->execute([$abono, $estId, $abono]);

        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return ['No se registró el pago: la deuda cambió mientras se registraba. Revísala e intenta de nuevo.', 'warning'];
        }

        // `fecha` queda con el momento de ESTE insert: el pago se fecha el día en que se paga.
        $pdo->prepare("insert into pagos (estancia_id, monto, metodo, nota, usuario_id) values (?, ?, ?, ?, ?)")
            ->execute([$estId, $abono, $metodo, DEUDA_NOTA_PAGO, getCurrentUserId()]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Pago de deuda: ' . $ex->getMessage());
        return ['No se pudo registrar el pago. Vuelve a intentarlo.', 'danger'];
    }

    if (function_exists('registrarAuditoria')) {
        registrarAuditoria($pdo, 'estancias', $estId, 'pago_deuda', "Pago de deuda \$" . (int) $abono . " ($metodo)");
    }
    return ['Pago de deuda registrado: $' . number_format($abono, 0, ',', '.') . ' COP.', 'success'];
}
