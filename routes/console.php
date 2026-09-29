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

/*
 * MONTHLY FORECAST TRAINING.
 *
 * Runs on the LAST day of every month at 23:30, once the day's meals and expenses
 * have been recorded. It aggregates the closing month and updates the model
 * weights (see App\Support\ForecastTrainer).
 *
 * WHY THE LAST DAY, AND NOT THE FIRST
 * -----------------------------------
 * Training needs a COMPLETE month. Running on the 1st would fit the weights to a
 * month that is still being written to, so every weekday multiplier would be
 * computed from a partial ledger.
 *
 * `lastDayOfMonth()` is Laravel's own schedule helper: it fires on the 28th-31st
 * as appropriate, so February is handled without a special case.
 */
Schedule::command('forecast:train-monthly')
    ->lastDayOfMonth('23:30')
    ->withoutOverlapping();

/*
 * THE 3-MONTH ROLLING FORECAST.
 *
 * Runs on the FIRST day of every month at 00:30 - immediately after training has
 * closed the previous month - and PERSISTS the projection onto the new month's
 * model row.
 *
 * WHY A SEPARATE RUN FROM TRAINING
 * ---------------------------------
 * The projection needs the new month to have STARTED (it forecasts months +1..+3
 * relative to it). Folding it into the training run would project from the closing
 * month and therefore re-forecast the month that has just finished.
 *
 * Persisting it means the forecast a manager reads on the 2nd is the same one they
 * read on the 20th, and it can be compared against what actually happened.
 */
Schedule::command('forecast:train-monthly --rolling')
    ->monthlyOn(1, '00:30')
    ->withoutOverlapping();
