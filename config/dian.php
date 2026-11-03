<?php
// ============================================================
// config/dian.php
// Configuración de Facturación Electrónica DIAN (Colombia)
// ============================================================
// ⚠️  DESHABILITADO por defecto. La emisión ante la DIAN requiere:
//     1) Hosting con CONEXIONES SALIENTES (InfinityFree gratuito NO
//        las permite; usar hosting de pago o VPS).
//     2) Contrato con un Proveedor Tecnológico (PT) AUTORIZADO por la
//        DIAN (p. ej. Facturatech, The Factory HKA, Carvajal, Saphety…).
//     3) La resolución de numeración de facturación de la DIAN.
//
// Para ACTIVAR: completa los datos, pon 'habilitado' => true y ejecuta
// la tabla facturas_electronicas, que ya viene en sql/schema.sql.
// Mientras 'habilitado' sea false, NADA de
// DIAN se ejecuta (ni consultas ni llamadas de red): el sistema sigue
// funcionando exactamente igual con su factura interna.
// ------------------------------------------------------------

return [
    'habilitado'   => false,          // ← poner true SOLO en hosting con salida a internet

    // ── Proveedor Tecnológico (PT) ──
    'proveedor'    => '',             // nombre del PT contratado
    'api_url'      => '',             // endpoint del PT (emisión)
    'api_token'    => '',             // token / credencial del PT
    'ambiente'     => 'pruebas',      // 'pruebas' | 'produccion'

    // ── Datos del emisor (tu hotel) ──
    'nit'          => '[NIT]',
    'razon_social' => '[RAZÓN SOCIAL DEL HOTEL]',

    // ── Resolución de numeración DIAN ──
    'resolucion'   => '[NÚMERO DE RESOLUCIÓN]',
    'prefijo'      => 'FE',
    'rango_desde'  => 1,
    'rango_hasta'  => 1000,
];
