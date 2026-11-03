<?php
// ============================================================
// includes/dian.php
// Facturación Electrónica DIAN — capa de integración (preparada)
// ============================================================
// TODA la funcionalidad está guardada tras dianHabilitado(). Mientras
// DIAN esté deshabilitado (config/dian.php), estas funciones no tocan la
// base de datos ni hacen llamadas de red: el sistema factura igual que
// siempre con su comprobante interno.
//
// Para ACTIVAR en un hosting con salida a internet + Proveedor
// Tecnológico (PT): completa config/dian.php e
// implementa dianLlamarProveedor() con el formato de TU proveedor.
// ------------------------------------------------------------

require_once __DIR__ . '/cifrado.php';

/** Configuración DIAN cacheada. */
function dianConfig(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = @include __DIR__ . '/../config/dian.php';
        if (!is_array($cfg)) $cfg = ['habilitado' => false];
    }
    return $cfg;
}

/** ¿La facturación electrónica DIAN está activada? */
function dianHabilitado(): bool {
    return !empty(dianConfig()['habilitado']);
}

/**
 * Devuelve la factura electrónica ya registrada para una estancia, o
 * null. Solo consulta si DIAN está habilitado (evita tocar una tabla que
 * podría no existir mientras está desactivado).
 */
function dianFacturaDeEstancia(PDO $pdo, int $estanciaId): ?array {
    if (!dianHabilitado()) return null;
    $stmt = $pdo->prepare("select numero, cufe, qr_data, estado from facturas_electronicas where estancia_id = ? limit 1");
    $stmt->execute([$estanciaId]);
    return $stmt->fetch() ?: null;
}

/**
 * Reúne los datos fiscales de una estancia para armar la factura.
 * Descifra los campos sensibles (documento). Devuelve un arreglo neutro
 * que luego se mapea al formato del proveedor en dianLlamarProveedor().
 */
function dianArmarDatos(PDO $pdo, int $estanciaId): array {
    $sql = "select e.id, e.fecha_checkin, e.fecha_checkout, e.subtotal_noches,
                   e.total_consumos, e.total_pagar, e.iva_exento,
                   hu.documento_tipo, hu.documento_num, hu.nombres, hu.apellidos, hu.email,
                   h.numero as habitacion
            from estancias e, huespedes hu, habitaciones h
            where e.id = ? and e.huesped_id = hu.id and e.habitacion_id = h.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$estanciaId]);
    $r = $stmt->fetch();
    if (!$r) return [];

    return [
        'estancia_id'    => (int) $r['id'],
        'cliente'        => [
            'tipo_doc'   => $r['documento_tipo'],
            'documento'  => descifrar($r['documento_num']),
            'nombres'    => $r['nombres'],
            'apellidos'  => $r['apellidos'],
            'email'      => $r['email'],
        ],
        'fecha'          => date('Y-m-d'),
        'items'          => [
            ['concepto' => 'Hospedaje', 'valor' => (float) $r['subtotal_noches']],
            ['concepto' => 'Consumos',  'valor' => (float) $r['total_consumos']],
        ],
        'subtotal'       => (float) $r['subtotal_noches'] + (float) $r['total_consumos'],
        'iva_exento'     => (bool) $r['iva_exento'],
        'total'          => (float) $r['total_pagar'],
    ];
}

/**
 * Emite la factura electrónica de una estancia ante la DIAN vía el PT.
 * Idempotente: si ya existe factura para la estancia, la devuelve.
 * Devuelve ['ok'=>bool, 'cufe'=>?, 'qr'=>?, 'numero'=>?, 'error'=>?].
 * SEGURO cuando está deshabilitado: no hace nada.
 */
function dianEmitirFactura(PDO $pdo, int $estanciaId): array {
    if (!dianHabilitado()) {
        return ['ok' => false, 'error' => 'DIAN deshabilitado'];
    }

    // ¿Ya emitida? (idempotencia)
    if ($existe = dianFacturaDeEstancia($pdo, $estanciaId)) {
        return [
            'ok'     => $existe['estado'] === 'emitida',
            'cufe'   => $existe['cufe'],
            'qr'     => $existe['qr_data'],
            'numero' => $existe['numero'],
            'error'  => $existe['estado'] === 'error' ? 'Emisión previa con error' : null,
        ];
    }

    $datos = dianArmarDatos($pdo, $estanciaId);
    if (!$datos) {
        return ['ok' => false, 'error' => 'Estancia no encontrada'];
    }

    try {
        $resp = dianLlamarProveedor(dianConfig(), $datos);   // ← implementar por PT
    } catch (Throwable $e) {
        error_log('DIAN error: ' . $e->getMessage());
        $resp = ['ok' => false, 'error' => 'Fallo de comunicación con el proveedor'];
    }

    // Registrar el resultado (para reimpresión y auditoría).
    $stmt = $pdo->prepare("insert into facturas_electronicas (estancia_id, numero, cufe, qr_data, estado, respuesta)
                           values (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $estanciaId,
        $resp['numero'] ?? null,
        $resp['cufe']   ?? null,
        $resp['qr']     ?? null,
        !empty($resp['ok']) ? 'emitida' : 'error',
        isset($resp['raw']) ? substr((string) $resp['raw'], 0, 4000) : ($resp['error'] ?? null),
    ]);

    return $resp;
}

/**
 * Llamada real a la API del Proveedor Tecnológico.
 * ═══════════════════════════════════════════════════════════════════
 * ⚠️  IMPLEMENTAR SEGÚN TU PROVEEDOR. Cada PT (Facturatech, HKA, Carvajal,
 *     Saphety…) define su propio formato JSON/XML y su autenticación.
 *     El esqueleto con cURL queda listo; solo hay que mapear $datos al
 *     payload del PT y leer el CUFE/QR de la respuesta.
 * ═══════════════════════════════════════════════════════════════════
 *
 * Debe devolver: ['ok'=>bool, 'cufe'=>string, 'qr'=>string,
 *                 'numero'=>string, 'raw'=>string, 'error'=>?string]
 */
function dianLlamarProveedor(array $cfg, array $datos): array {
    if (empty($cfg['api_url']) || empty($cfg['api_token'])) {
        return ['ok' => false, 'error' => 'Proveedor DIAN sin configurar (api_url / api_token)'];
    }

    // ── Esqueleto de la llamada (descomentar y adaptar al PT) ──
    /*
    $payload = [
        // TODO: mapear $datos al formato exacto que exige tu PT.
        'nit'        => $cfg['nit'],
        'resolucion' => $cfg['resolucion'],
        'prefijo'    => $cfg['prefijo'],
        'ambiente'   => $cfg['ambiente'],
        'factura'    => $datos,
    ];

    $ch = curl_init($cfg['api_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['api_token'],
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'cURL: ' . $err];
    }
    $j = json_decode($raw, true);
    return [
        'ok'     => !empty($j['exitoso']),         // ← ajustar a la respuesta del PT
        'cufe'   => $j['cufe']   ?? null,
        'qr'     => $j['qr']     ?? null,
        'numero' => $j['numero'] ?? null,
        'raw'    => $raw,
        'error'  => $j['error']  ?? null,
    ];
    */

    return ['ok' => false, 'error' => 'Integración con el proveedor pendiente de implementar'];
}
