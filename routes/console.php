<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * AUTOMATED TRIAL REMINDERS.
 *
 * Runs daily at 09:00. The command itself is idempotent and cooldown-guarded,
 * so should the schedule ever fire it more than once it will not spam admins.
 */
Schedule::command('trials:remind')->dailyAt('09:00');
