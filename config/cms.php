<?php

// Read through config() so values survive `php artisan config:cache` in production.
return [
    'admin_email' => env('ADMIN_EMAIL', 'admin@example.com'),
    'admin_password' => env('ADMIN_PASSWORD'),
    'company_name' => env('COMPANY_NAME', 'Construction Company Ltd'),
    'mysqldump_path' => env('MYSQLDUMP_PATH', 'mysqldump'),
];
