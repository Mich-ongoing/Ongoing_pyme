<?php

// Facturación mensual: lee la hoja de KPIs (solo lectura, vía Apps Script) y crea borradores en Holded.
return [
    'holded_key' => env('HOLDED_API_KEY'),
    'iva' => env('FACTURACION_IVA', 's_iva_21'), // identificador del impuesto en Holded

    // La hoja se lee con un Apps Script de solo lectura publicado como aplicación web (resources/apps-script/facturacion/)
    'apps_script_url' => env('FACTURACION_APPS_SCRIPT_URL'),
    'apps_script_token' => env('FACTURACION_APPS_SCRIPT_TOKEN'),

    // REVENUE LINE de la hoja => descripción por defecto de la línea (el concepto es la REVENUE LINE tal cual).
    'conceptos' => [
        'MRR Paid media' => 'Gestión de campañas de publicidad (Paid Media)',
        'MRR Paid variables' => 'Inversión variable en campañas de publicidad',
        'MRR SEO' => 'Posicionamiento SEO',
        'MRR RRSS' => 'Gestión de redes sociales',
        'Maintainment web' => 'Mantenimiento web',
        'Web creations' => 'Diseño y desarrollo web',
        'ONE- OFF SEO' => 'Proyecto SEO',
        'ONE-OFF RRSS' => 'Proyecto de redes sociales',
        'ONE-OFF Paid Media' => 'Proyecto de publicidad (Paid Media)',
        'Others refacturing' => 'Refacturación de gastos',
    ],

    // REVENUE LINE => id del canal de venta (cuenta contable) en Holded: GET /api/v2/sales-channels.
    // Sin cuenta Holded pone «Ventas de mercaderías» (70000000), por eso el panel avisa.
    'cuentas' => [
        'MRR Paid media' => '6981e397d2e4d9e6450a7b74',     // 70500002 MRR PAID MEDIA
        'MRR Paid variables' => '6981e397d2e4d9e6450a7b75', // 70500005 MRR PAID VARIABLES
        'MRR SEO' => '6981e397d2e4d9e6450a7b77',            // 70500004 MRR SEO
        'MRR RRSS' => '6981e397d2e4d9e6450a7b76',           // 70500003 MRR RRSS
        'Maintainment web' => '6981e397d2e4d9e6450a7b73',   // 70500010 MAINTAINMENT WEB
        'Web creations' => '6981e397d2e4d9e6450a7b7e',      // 70500011 WEB CREATIONS
        'ONE- OFF SEO' => '6981e397d2e4d9e6450a7b7b',       // 70500007 ONE-OFF SEO
        'ONE-OFF RRSS' => '6981e397d2e4d9e6450a7b78',       // 70500012 ONE OFF RRSS
        'ONE-OFF Paid Media' => '6981e397d2e4d9e6450a7b7a', // 70500008 ONE-OFF PAID VARIABLES
        'Others refacturing' => '6981e397d2e4d9e6450a7b7c', // 70500009 OTHERS REFACTURING
    ],

    // Líneas que no se facturan (histórico o vacías)
    'ignorar' => ['Invoiced 2024', '-', ''],
    // Líneas que contienen este texto tampoco: el Kit Digital se factura fuera de aquí
    'ignorar_si_contiene' => ['Kids Digitales'],

    // Sociedades (SL NAME) con una factura por cada CLIENT NAME en vez de una sola; da igual mayúsculas, tildes y puntos
    'separar_por_cliente' => ['Dreiblau SL', 'B-BRUCE S.L.', 'Pixel Comunicació S.L.'],
];
