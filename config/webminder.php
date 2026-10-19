<?php

return [
    /**
     * Webminder Client Credentials
     * Set via environment variables: WEBMINDER_CLIENT_ID and WEBMINDER_CLIENT_SECRET
     */
    'client_id' => env('WEBMINDER_CLIENT_ID', ''),
    'client_secret' => env('WEBMINDER_CLIENT_SECRET', ''),

    /**
     * Branding information
     */
    'brand_name' => env('WEBMINDER_BRAND_NAME', 'Ongoing'),
    'brand_url' => env('WEBMINDER_BRAND_URL', 'https://ongoing.es'),
    'brand_color' => env('WEBMINDER_BRAND_COLOR', '#4441FF'),
    'brand_color_dark' => env('WEBMINDER_BRAND_COLOR_DARK', '#3A37D9'),

    /**
     * API Namespace/Version
     */
    'api_namespace' => 'webminder/v1',

    /**
     * Timestamp tolerance for request validation (seconds)
     * Default: 900 (15 minutes)
     */
    'timestamp_tolerance' => env('WEBMINDER_TIMESTAMP_TOLERANCE', 900),

    /**
     * Database cleanup limits
     */
    'db_clean_max_posts' => env('WEBMINDER_DB_CLEAN_MAX_POSTS', 500),

    /**
     * SSO Configuration
     */
    'sso' => [
        'enabled' => env('WEBMINDER_SSO_ENABLED', true),
        'token_lifetime' => env('WEBMINDER_SSO_TOKEN_LIFETIME', 3600), // 1 hour
    ],

    /**
     * Preferred SSO email addresses for the panel
     */
    'sso_preferred_emails' => [
        'michell@ongoing.es',
    ],
];
