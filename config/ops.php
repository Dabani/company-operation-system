<?php

return [
    'default_language' => env('OPS_DEFAULT_LANGUAGE', 'en'),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'seed_admin_email' => env('OPS_ADMIN_EMAIL', 'admin@example.com'),
    'seed_admin_password' => env('OPS_ADMIN_PASSWORD'),   // required when seeding; never commit
];
