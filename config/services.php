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

    // Phone notifications through Expo's push service (off until the mobile app is built with an EAS project).
    'expo_push' => [
        'enabled' => (bool) env('EXPO_PUSH_ENABLED', false),
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

    // Desktop installer currently offered to the Windows app (see DesktopReleaseController).
    'desktop' => [
        'version' => env('DESKTOP_LATEST_VERSION'),
        'url' => env('DESKTOP_DOWNLOAD_URL'),
        'sha256' => env('DESKTOP_SHA256'),
        'notes' => env('DESKTOP_RELEASE_NOTES'),
    ],

    // WhatsApp alerts for check-in / check-out / leave (off until configured).
    'whatsapp' => [
        'enabled' => (bool) env('WHATSAPP_ENABLED', false),
        'provider' => env('WHATSAPP_PROVIDER', 'meta'), // meta | webhook
        'token' => env('WHATSAPP_TOKEN'),
        'phone_id' => env('WHATSAPP_PHONE_ID'),
        'template' => env('WHATSAPP_TEMPLATE'),
        'template_lang' => env('WHATSAPP_TEMPLATE_LANG', 'en'),
        'webhook_url' => env('WHATSAPP_WEBHOOK_URL'),
        'webhook_token' => env('WHATSAPP_WEBHOOK_TOKEN'),
        'notify_manager' => (bool) env('WHATSAPP_NOTIFY_MANAGER', true),
        'permission' => env('WHATSAPP_NOTIFY_PERMISSION'),
        'default_cc' => env('WHATSAPP_DEFAULT_CC', '91'),
        'fixed' => array_filter(array_map('trim', explode(',', (string) env('WHATSAPP_FIXED_RECIPIENTS', '')))),
        'events' => array_filter(array_map('trim', explode(',', (string) env('WHATSAPP_EVENTS', 'check_in,check_out,leave_applied')))),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
