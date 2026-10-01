<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Support\AuditLogger;
use App\Support\ForecastTrainer;
use App\Support\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * MONTHLY FORECAST TRAINING + THE 3-MONTH ROLLING FORECAST.
 *
 * TWO JOBS, ONE COMMAND, TWO SCHEDULED DATES
 * ------------------------------------------
 *   `forecast:train-monthly`            -> run on the LAST day of the month.
 *       Aggregates the month's ledger and updates the model weights, then
 *       rebuilds the retrieval corpus.
 *
 *   `forecast:train-monthly --rolling`  -> run on the FIRST day of the next month.
 *       Generates AND PERSISTS the 3-month rolling forecast from those weights.
 *
 * WHY THEY ARE SEPARATE FLAGS RATHER THAN ONE RUN
 * -----------------------------------------------
 * Training needs the month to be COMPLETE; the projection needs the month to have
 * STARTED. Running both on the same date would either train on a partial month
 * (producing weights fitted to 28 days' data) or project before the ledger is
 * closed. The schedule in routes/console.php fires each on its proper day.
 *
 * SCOPE
 * -----
 * Cross-tenant by design - it sweeps every active institution, because a monthly
 * training run that required an operator to log into each workspace would never
 * happen. The sweep is wrapped in TenantManager::runGlobally() so the tenant scope
 * is lifted EXPLICITLY and greppably, rather than being sidestepped by accident.
 *
 *     php artisan forecast:train-monthly                # train every institution
 *     php artisan forecast:train-monthly --rolling      # persist rolling forecasts
 *     php artisan forecast:train-monthly --institution=7
 *     php artisan forecast:train-monthly --month=2026-08
 *     php artisan forecast:train-monthly --dry-run
 */
class TrainMonthlyForecast extends Command
{
    protected $signature = 'forecast:train-monthly
        {--rolling : Generate and persist the 3-month rolling forecast instead of training}
        {--institution= : Limit the run to one institution id}
        {--month= : The period to act on (YYYY-MM); defaults to the current month}
        {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Train the monthly forecast weights, or persist the 3-month rolling forecast.';

    public function handle(): int
    {
        $rolling = (bool) $this->option('rolling');
        $dryRun = (bool) $this->option('dry-run');
        $month = $this->option('month') ?: now()->format('Y-m');

        $institutions = $this->institutionsToProcess();

        if ($institutions->isEmpty()) {
            $this->warn('No institutions to process.');

            return self::SUCCESS;
        }

        $trained = 0;
        $forecast = 0;
        $skipped = 0;

        /*
         * Lift the tenant scope for the sweep. `runGlobally` guarantees the scope
         * is restored afterwards even if a callback throws, so a failure part-way
         * through cannot leave the process reading across tenants.
         */
        app(TenantManager::class)->runGlobally(function () use (
            $institutions, $rolling, $dryRun, $month, &$trained, &$forecast, &$skipped
        ) {
            foreach ($institutions as $institution) {
                $trainer = new ForecastTrainer($institution);

                if ($dryRun) {
                    $this->line($rolling
                        ? "  would persist a rolling forecast for {$institution->name}"
                        : "  would train {$institution->name}");
                    $skipped++;

                    continue;
                }

                try {
                    if ($rolling) {
                        $result = $trainer->generateRollingForecast($month);
                        $forecast++;

                        $months = count($result['months'] ?? []);
                        $this->info("  -> {$institution->name}: {$months}-month rolling forecast persisted ({$result['basis']})");
                    } else {
                        $result = $trainer->train($month);
                        $days = $trainer->refreshCorpus();
                        $trained++;

                        $this->info("  -> {$institution->name}: trained ({$result['basis']}, "
                            ."{$result['history_months']} month(s) history, {$result['days']} day(s), "
                            ."{$days} embedded)");
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $this->error("  x {$institution->name}: {$e->getMessage()}");

                    continue;
                }

                /*
                 * Audit each institution's run so the platform operator can see
                 * WHEN a workspace was trained and on what basis - the same
                 * provenance the forecast itself carries.
                 */
                AuditLogger::log('updated', $rolling
                    ? 'persisted the 3-month rolling forecast'
                    : 'trained the monthly forecasting model', $institution, [
                        'month' => $month,
                        'basis' => $result['basis'] ?? null,
                        'automated' => true,
                    ], ['subject_label' => $institution->name, 'institution_id' => $institution->id]);
            }
        });

        $verb = $rolling ? 'forecast(s) persisted' : 'institution(s) trained';

        $this->info($dryRun
            ? "Dry run: {$skipped} {$verb} (nothing written)."
            : "Done: {$trained} trained, {$forecast} forecast(s) persisted.");

        return self::SUCCESS;
    }

    /**
     * The institutions this run should process.
     *
     * Only ACTIVE workspaces are included: training a suspended or cancelled
     * institution burns time on data nobody will read, and a churned workspace's
     * ledger may legitimately be incomplete.
     *
     * @return Collection<int, Institution>
     */
    protected function institutionsToProcess()
    {
        if ($id = $this->option('institution')) {
            return Institution::query()->whereKey((int) $id)->get();
        }

        return Institution::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }
}
