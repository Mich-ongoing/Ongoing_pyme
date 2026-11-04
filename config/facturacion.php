<?php

// Facturación mensual: lee la hoja de KPIs de Google Sheets (solo lectura) y crea borradores en Holded.
return [
    'holded_key' => env('HOLDED_API_KEY'),
    'iva' => env('FACTURACION_IVA', 's_iva_21'), // identificador del impuesto en Holded

    // Hoja de Google: el ID va en la URL (docs.google.com/spreadsheets/d/<ID>/edit). Se comparte en «Lector» con la cuenta de servicio.
    'hoja_id' => env('FACTURACION_HOJA_ID'),
    'hoja_rango' => env('FACTURACION_HOJA_RANGO', "'BBDD KPIs Analysis'!A1:AF700"),
    'google_credenciales' => env('FACTURACION_GOOGLE_JSON', storage_path('app/private/google-facturacion.json')),

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
