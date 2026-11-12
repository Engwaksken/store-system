<?php

use Illuminate\Support\Facades\Schedule;

// Requires one cron entry on the server: * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
Schedule::command('cms:backup --keep=14')->dailyAt('01:30')->withoutOverlapping();
