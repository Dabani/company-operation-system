<?php

return [
    'default_language' => env('OPS_DEFAULT_LANGUAGE', 'en'),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'seed_admin_email' => env('OPS_ADMIN_EMAIL', 'admin@example.com'),
    'seed_admin_password' => env('OPS_ADMIN_PASSWORD'),   // required when seeding; never commit
    'seed_admin_phone' => env('OPS_ADMIN_PHONE'),         // receives SMS alerts for new cases

    'case_prefix' => env('OPS_CASE_PREFIX', 'CASE'),
    'asset_prefix' => env('OPS_ASSET_PREFIX', 'AST'),

    // Request fields that carry rich text (Tiptap HTML). Purified server-side before validation.
    'rich_text_fields' => ['description', 'address', 'body', 'notes', 'impact', 'immediate_action', 'root_cause', 'preventive_action_plan', 'summary'],

    'phone_country_code' => env('OPS_PHONE_COUNTRY', '250'),   // used to turn 078... into 25078...

    'files' => [
        'disk' => env('FILES_DISK', 'local'),                        // private disk; never publicly served
        'max_mb' => (int) env('FILES_MAX_MB', 20),                   // also raise upload_max_filesize / post_max_size in php.ini
        'purge_after_days' => (int) env('FILES_PURGE_DAYS', 30),     // content of deleted files is removed after this window
        'allowed_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'rtf', 'odt', 'ods', 'png', 'jpg', 'jpeg', 'gif', 'webp'],
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),                  // log | brevo | africastalking
        'sender' => env('SMS_SENDER', 'OPSPLATFORM'),          // alphanumeric, max 11; must be registered in Rwanda
        'brevo_key' => env('BREVO_API_KEY'),
        'at_username' => env('AT_USERNAME'),
        'at_key' => env('AT_API_KEY'),
        'at_url' => env('AT_URL', 'https://api.africastalking.com/version1/messaging'),
    ],

    // Bundle 7 ---------------------------------------------------------------------------------------------------
    'retention' => [
        // Operational logs only. The audit log, case/record history and disposal evidence are never pruned automatically.
        'delivery_log_days' => (int) env('OPS_RETAIN_DELIVERY_DAYS', 365),
        'read_notification_days' => (int) env('OPS_RETAIN_NOTIFICATION_DAYS', 180),
    ],
    'health' => [
        'scheduler_max_age' => (int) env('OPS_HEALTH_SCHEDULER_MAX_AGE', 180),       // seconds without a heartbeat before the scheduler counts as down
        'backup_max_age_days' => (int) env('OPS_HEALTH_BACKUP_MAX_AGE_DAYS', 14),    // days since the last backup verified OK
    ],
    'security' => [
        'hsts' => (bool) env('OPS_HSTS', true),                                       // sent only on HTTPS requests
        'force_https' => (bool) env('OPS_FORCE_HTTPS', true),                         // production only
    ],
];
