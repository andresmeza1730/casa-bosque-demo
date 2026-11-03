<?php
// ============================================================
// config/establecimiento.php
// Datos oficiales del establecimiento para reportes gubernamentales
// ============================================================
// ⚠️  COMPLETAR con la información real antes de generar reportes
//     oficiales. Estos códigos los asigna cada entidad:
//       - RNT  : Registro Nacional de Turismo (Cámara de Comercio / MinCIT)
//       - SIRE : código de establecimiento en Migración Colombia
// ------------------------------------------------------------

return [
    'razon_social'   => '[RAZÓN SOCIAL DEL HOTEL]',
    // El NIT va en dos piezas, como el del receptor en la tabla `facturas`:
    // el número y su dígito de verificación. Los reportes oficiales suelen
    // pedirlos por separado; los comprobantes lo imprimen junto, y de eso se
    // encarga `nit_completo` en datosEstablecimiento().
    'nit'            => '1047375577',
    'nit_dv'         => '9',
    'rnt'            => '[NÚMERO RNT]',          // usado en la TRA (MinCIT)
    'codigo_sire'    => '[CÓDIGO SIRE]',         // usado en el reporte SIRE
    'ciudad'         => '[CIUDAD]',
    'departamento'   => '[DEPARTAMENTO]',

    // ── Datos que SE IMPRIMEN en facturas y tiquetes ──
    // Estos sí están rellenos: son los que ya venían escritos a mano dentro de
    // modules/checkout/factura_pdf.php (y del correo de la factura, retirado en 3.13.0). Se
    // leen con datosEstablecimiento() (includes/establecimiento.php), que es
    // ahora el único sitio del que salen.
    //
    // Son claves APARTE de las de arriba a propósito: 'ciudad', 'razon_social'
    // y 'nit' son para los reportes del Gobierno y siguen sin rellenar.
    'nombre_comercial' => 'Casa Bosque',
    'subtitulo'        => 'HOSPEDAJE',
    'sistema'          => 'SIGH-CB',
    'direccion'        => 'Tv. 48 #20-26, El Bosque',
    'ciudad_contacto'  => 'Cartagena de Indias, Bolívar',
    'telefono'         => '311 7296803',
    'email'            => '',          // ej: info@casabosque.co

    // Número de WhatsApp del hotel para recibir el aviso de nuevas reservas.
    // Formato internacional SIN "+" ni espacios (Colombia = 57 + número).
    // ⚠️ CAMBIAR por el número real del hotel. Ej: '573117296803'
    'whatsapp_admin' => '573000000000',
];
