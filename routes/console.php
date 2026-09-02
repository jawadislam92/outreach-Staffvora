<?php

use Illuminate\Support\Facades\Schedule;

// Scheduled outreach emails. On the server a cron entry runs
// `php artisan schedule:run` every minute; locally use `php artisan schedule:work`.
Schedule::command('outreach:send-due')->everyMinute()->withoutOverlapping();
