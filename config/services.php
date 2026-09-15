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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Hito 9.1 — App\ExerciseCatalog\Providers\YMove\YMoveExerciseProvider.
    // La API key nunca se loguea ni se persiste — ver docs/DECISIONS.md.
    'ymove' => [
        'api_key' => env('YMOVE_API_KEY'),
        'base_url' => env('YMOVE_BASE_URL', 'https://exercise-api.ymove.app/api/v2'),
    ],

    // Controles P0 de lanzamiento (auditoría pre-lanzamiento) — GLOBAL para
    // toda la plataforma, nunca por tenant. `app_secret` es el secreto de la
    // App de Meta (Developers Console), usado exclusivamente por
    // App\Http\Middleware\VerifyMetaWebhookSignature para verificar
    // X-Hub-Signature-256 sobre el cuerpo crudo del webhook — nunca se loguea.
    // `webhook_signature_mode`: 'log-only' (default, sin cambio de
    // comportamiento funcional) | 'strict' (rechaza con 401).
    'meta' => [
        'app_secret' => env('META_APP_SECRET'),
        'webhook_signature_mode' => env('META_WEBHOOK_SIGNATURE_MODE', 'log-only'),
        'webhook_ip_rate_limit' => (int) env('WHATSAPP_WEBHOOK_IP_RATE_LIMIT', 120),
        'contact_rate_limit_max' => (int) env('WHATSAPP_CONTACT_RATE_LIMIT_MAX', 20),
        'contact_rate_limit_minutes' => (int) env('WHATSAPP_CONTACT_RATE_LIMIT_MINUTES', 10),
    ],

];
