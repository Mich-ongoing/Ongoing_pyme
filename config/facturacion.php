<?php

// Facturación mensual: lee la hoja de KPIs (solo lectura, vía Apps Script) y crea borradores en Holded.
return [
    'holded_key' => env('HOLDED_API_KEY'),
    'iva' => env('FACTURACION_IVA', 's_iva_21'), // identificador del impuesto en Holded

    // La hoja se lee con un Apps Script de solo lectura publicado como aplicación web (resources/apps-script/facturacion/)
    'apps_script_url' => env('FACTURACION_APPS_SCRIPT_URL'),
    'apps_script_token' => env('FACTURACION_APPS_SCRIPT_TOKEN'),

    // REVENUE LINE de la hoja => concepto de la factura. Lo que no esté aquí sale tal cual y con aviso.
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
        'ONE-OFF Kids Digitales' => 'Kit Digital',
        'ONE-OFF Kids Digitales SEO' => 'Kit Digital · Posicionamiento SEO',
        'ONE-OFF Kids Digitales RRSS' => 'Kit Digital · Gestión de redes sociales',
        'Others refacturing' => 'Refacturación de gastos',
    ],

    // Líneas que no se facturan (histórico o vacías)
    'ignorar' => ['Invoiced 2024', '-', ''],
];
