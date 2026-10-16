<?php

return [
    'default_language' => env('OPS_DEFAULT_LANGUAGE', 'en'),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'seed_admin_email' => env('OPS_ADMIN_EMAIL', 'admin@example.com'),
    'seed_admin_password' => env('OPS_ADMIN_PASSWORD'),   // required when seeding; never commit
    'seed_admin_phone' => env('OPS_ADMIN_PHONE'),         // receives SMS alerts for new cases

    'case_prefix' => env('OPS_CASE_PREFIX', 'CASE'),

    // Request fields that carry rich text (Tiptap HTML). Purified server-side before validation.
    'rich_text_fields' => ['description', 'address', 'body'],

    'phone_country_code' => env('OPS_PHONE_COUNTRY', '250'),   // used to turn 078... into 25078...

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),                  // log | brevo | africastalking
        'sender' => env('SMS_SENDER', 'OPSPLATFORM'),          // alphanumeric, max 11; must be registered in Rwanda
        'brevo_key' => env('BREVO_API_KEY'),
        'at_username' => env('AT_USERNAME'),
        'at_key' => env('AT_API_KEY'),
        'at_url' => env('AT_URL', 'https://api.africastalking.com/version1/messaging'),
    ],
];
