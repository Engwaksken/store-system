<?php

use Illuminate\Support\Facades\Schedule;

// Requires one cron entry on the server: * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
Schedule::command('cms:backup --keep=14')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('cms:reminders')->weekdays()->dailyAt('07:00')->withoutOverlapping();
Schedule::command('cms:budget-alerts')->twiceDaily(7, 13)->withoutOverlapping();
Schedule::command('cms:verify-audit')->dailyAt('02:30'); // a failure shows in the scheduler log; see docs/DEPLOYMENT.md
Schedule::command('cms:reconcile')->dailyAt('02:45');
