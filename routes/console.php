<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('inbox:dispatch-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('inbox:sync-email')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sequences:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('marketing:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('approvals:escalate-due')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('support:dispatch-sla')->everyMinute()->withoutOverlapping();
