<?php

// Read through config() so values survive `php artisan config:cache` in production.
return [
    'admin_email' => env('ADMIN_EMAIL', 'admin@example.com'),
    'admin_password' => env('ADMIN_PASSWORD'),
    'company_name' => env('COMPANY_NAME', 'Construction Company Ltd'),
    'mysqldump_path' => env('MYSQLDUMP_PATH', 'mysqldump'),
    // Alerts: in-app always; e-mail too unless CMS_MAIL_ALERTS=false.
    'mail_alerts' => (bool) env('CMS_MAIL_ALERTS', true),
    // Daily reminders for work pending longer than this many days; escalate to the Owner/Admin after escalation_days.
    'reminder_days' => (int) env('CMS_REMINDER_DAYS', 2),
    'escalation_days' => (int) env('CMS_ESCALATION_DAYS', 5),
    // Browser push (Web Push / VAPID). Generate the keys once with `php artisan cms:push-keys`; push is off until they are set.
    'push' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', env('APP_URL', 'mailto:admin@example.com')),
    ],
];
