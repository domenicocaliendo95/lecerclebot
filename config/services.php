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

    'whatsapp' => [
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'api_token'    => env('WHATSAPP_TOKEN'),              // .env: WHATSAPP_TOKEN
        'phone_id'     => env('WHATSAPP_PHONE_NUMBER_ID'),
        'waba_id'      => env('WHATSAPP_WABA_ID'),
        'phone_number' => env('WHATSAPP_PHONE_NUMBER'),
        'api_version'  => env('WHATSAPP_API_VERSION', 'v21.0'),
    ],

    'gemini' => [
        'api_key'  => env('GEMINI_KEY'),                      // .env: GEMINI_KEY
        'model'    => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout'  => (int) env('GEMINI_TIMEOUT', 15),
    ],

    'google_calendar' => [
        'credentials' => env('GOOGLE_CALENDAR_CREDENTIALS'),
        'calendar_id' => env('GOOGLE_CALENDAR_ID'),
    ],

];
