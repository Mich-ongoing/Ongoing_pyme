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

    // Líneas que no se facturan (histórico o vacías)
    'ignorar' => ['Invoiced 2024', '-', ''],
    // Líneas que contienen este texto tampoco: el Kit Digital se factura fuera de aquí
    'ignorar_si_contiene' => ['Kids Digitales'],
];
