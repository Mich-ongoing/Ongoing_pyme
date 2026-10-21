<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Cloudflare Browser Run para la comparación visual (token con permiso "Browser Rendering - Edit")
    'capturas' => [
        'cuenta' => env('WEBMINDER_CF_ACCOUNT_ID'),
        'token' => env('WEBMINDER_CF_TOKEN'),
    ],

    // watchWP: a quién llegan los avisos de caída y el informe nocturno
    'avisos' => [
        'email' => env('WEBMINDER_AVISOS_EMAIL'),
    ],

    // Injerto: clave privada Ed25519 (base64) con la que el panel firma el código aprobado.
    // Las webs solo tienen la pública: aunque roben sus archivos o su BD, no pueden firmar.
    'injerto' => [
        'clave_privada' => env('WEBMINDER_INJERTO_CLAVE'),
    ],

    // Cerrojo: la versión que el panel instala en las webs (se sube al desplegar; no va en git).
    'cerrojo' => [
        'archivo' => env('WEBMINDER_CERROJO_ARCHIVO', storage_path('app/cerrojo/cerrojo.php')),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
