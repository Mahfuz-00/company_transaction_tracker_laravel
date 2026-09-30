<?php

namespace Tests\Browser\Forecasting\Feature;

use App\Models\ForecastBenchmark;
use App\Models\ForecastModel;
use App\Models\MealEntry;
use App\Models\MealExpense;
use App\Models\Subsidy;
use App\Support\ForecastTrainer;
use App\Support\MealPriceEngine;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * END-OF-MONTH TRAINING + THE 3-MONTH ROLLING FORECAST.
 *
 * WHAT THESE TESTS LOCK IN
 * ------------------------
 *   1. MEAL PRICE IS AN ACCOUNTING IDENTITY, NOT A PREDICTION:
 *
 *          rate = (total expenses − total subsidies) / total consumed meals
 *
 *      There is no "daily similarity" path to a price any more. Test #1 below is
 *      the direct proof of the formula, and the tests after it prove the two edge
 *      cases the formula has to get right: subsidies REDUCE the member-borne rate,
 *      and a month with no meals yields 0 rather than a substituted average.
 *
 *   2. `forecast:train-monthly` fits and PERSISTS the model weights, idempotently
 *      (a re-run replaces the month's row instead of stacking duplicates).
 *   3. `forecast:train-monthly --rolling` generates AND persists the 3-month
 *      forecast - three months, each carrying its own `basis`.
 *   4. The FALLBACK LADDER degrades honestly:
 *        >= 3 months of history  -> `trained`
 *        some history, too thin  -> `benchmark`
 *        no usable data at all   -> `empty`, with clean zeros (never an invention).
 *   5. The forecasting page renders its basis and confidence, so the UI never
 *      presents a benchmark estimate as though it were learned.
 *
 * WHY THE PRICE ASSERTIONS ARE EXACT
 * ----------------------------------
 * The whole point of removing the retrieval-based price was that it produced a
 * number corresponding to no actual period of the ledger. Asserting the exact
 * arithmetic is therefore the requirement, not a nicety - anything looser would let
 * a similarity average creep back in unnoticed.
 */
class MonthlyForecastTrainingTest extends DuskTestCase
{
    use DuskSupport;

    /**
     * THE FORMULA. (expenses − subsidies) / consumed meals.
     *
     * 30,000 spent, 6,000 subsidised, 1,000 meals eaten -> 24.00 per meal.
     */
    public function test_the_meal_price_follows_the_expenses_and_subsidies_formula(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $student = $this->makeStudent($institution, ['roll' => 'PRICE-001']);

        $month = now()->format('Y-m');

        // 1,000 meals consumed across ten days.
        for ($i = 0; $i < 10; $i++) {
            MealEntry::create([
                'institution_id' => $institution->id,
                'student_id' => $student->id,
                'date' => now()->subDays($i)->toDateString(),
                'breakfast' => 20, 'lunch' => 40, 'dinner' => 40,
            ]);
        }

        // 30,000 of expense and 6,000 of subsidy for the month.
        MealExpense::create([
            'institution_id' => $institution->id,
            'category' => 'food',
            'amount' => 30000,
            'description' => 'Month food',
            'created_at' => now(),
        ]);

        Subsidy::create([
            'institution_id' => $institution->id,
            'period_month' => $month,
            'amount' => 6000,
            'source' => 'donor',
            'status' => 'active',
        ]);

        $this->step('Member', 'Forecasting', 'the ledger rate is arithmetic', __LINE__);

        $totals = MealPriceEngine::totalsForMonth($month, $institution);

        $this->assertSame(1000, $totals['meals']);
        $this->assertSame(30000.0, $totals['expenses']);
        $this->assertSame(6000.0, $totals['subsidies']);
        $this->assertSame(24000.0, $totals['net_expense']);

        // (30000 − 6000) / 1000 = 24.0
        $this->assertSame(24.0, MealPriceEngine::rateForMonth($month, $institution));
    }

    /** Subsidies REDUCE the member-borne rate - a cancelled subsidy must not. */
    public function test_only_active_subsidies_reduce_the_rate(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $student = $this->makeStudent($institution, ['roll' => 'PRICE-002']);

        $month = now()->format('Y-m');

        MealEntry::create([
            'institution_id' => $institution->id,
            'student_id' => $student->id,
            'date' => now()->toDateString(),
            'breakfast' => 0, 'lunch' => 100, 'dinner' => 0,
        ]);

        MealExpense::create([
            'institution_id' => $institution->id,
            'category' => 'food', 'amount' => 10000, 'description' => 'x', 'created_at' => now(),
        ]);

        // A CANCELLED subsidy must be ignored entirely.
        Subsidy::create([
            'institution_id' => $institution->id,
            'period_month' => $month, 'amount' => 5000, 'source' => 'donor', 'status' => 'cancelled',
        ]);

        // 10000 / 100 = 100.0 - the cancelled subsidy changed nothing.
        $this->assertSame(100.0, MealPriceEngine::rateForMonth($month, $institution));
    }

    /** A month with no meals yields exactly 0 - never a substituted average. */
    public function test_a_month_with_no_meals_prices_at_zero(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();

        $this->step('Member', 'Forecasting', 'an empty month is a clean zero', __LINE__);

        $this->assertSame(
            0.0,
            MealPriceEngine::rateForMonth('2020-01', $institution),
            'The "0 yields 0" rule: no meals must never produce an invented rate.'
        );
    }

    /** Training fits the weekday multipliers and PERSISTS the row for the month. */
    public function test_training_fits_and_persists_the_model_weights(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $student = $this->makeStudent($institution, ['roll' => 'TRAIN-001']);

        // ~4 months of steady history, enough to clear the 3-month threshold.
        for ($i = 120; $i >= 1; $i--) {
            MealEntry::create([
                'institution_id' => $institution->id,
                'student_id' => $student->id,
                'date' => now()->subDays($i)->toDateString(),
                'breakfast' => 1, 'lunch' => 1, 'dinner' => 1,
            ]);
        }

        $this->step('SSA', 'Forecasting', 'train the monthly model', __LINE__);

        $result = (new ForecastTrainer($institution))->train();

        $this->assertSame('trained', $result['basis']);
        $this->assertArrayHasKey('weekday_multipliers', $result['weights']);
        $this->assertCount(7, $result['weights']['weekday_multipliers'], 'One multiplier per weekday.');

        // The row is PERSISTED, so the trained state can be inspected later.
        $model = ForecastModel::latestFor($institution->id);

        $this->assertNotNull($model, 'Training must persist a model row.');
        $this->assertSame('trained', $model->basis);
        $this->assertNotNull($model->weights);
    }

    /** Re-running training for the same month REPLACES the row, never duplicates it. */
    public function test_training_is_idempotent_per_month(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $student = $this->makeStudent($institution, ['roll' => 'TRAIN-002']);

        for ($i = 120; $i >= 1; $i--) {
            MealEntry::create([
                'institution_id' => $institution->id,
                'student_id' => $student->id,
                'date' => now()->subDays($i)->toDateString(),
                'breakfast' => 1, 'lunch' => 1, 'dinner' => 1,
            ]);
        }

        $trainer = new ForecastTrainer($institution);
        $trainer->train();
        $trainer->train();

        $this->assertSame(
            1,
            ForecastModel::withoutTenantScope()->where('institution_id', $institution->id)->count(),
            'A re-run for the same month must replace, not append.'
        );
    }

    /** Thin history falls back to COUNTRY BENCHMARKS and says so. */
    public function test_thin_history_falls_back_to_country_benchmarks(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['country_code' => 'BD']);
        $this->makeStudent($institution, ['roll' => 'TRAIN-003']);

        // A published national aggregate for the fallback to anchor to.
        ForecastBenchmark::create([
            'country_code' => 'BD',
            'metric' => 'meals_per_member',
            'period_month' => now()->subMonth()->startOfMonth()->toDateString(),
            'value' => 2.5,
            'unit' => 'meals',
            'source' => 'Test benchmark',
        ]);

        $this->step('SSA', 'Forecasting', 'thin history uses benchmarks', __LINE__);

        $result = (new ForecastTrainer($institution))->train();

        $this->assertSame('benchmark', $result['basis'], 'Under the history threshold we must use benchmarks.');
        $this->assertSame('Test benchmark', $result['weights']['source'] ?? null);
    }

    /** With no history AND no benchmark, training produces a labelled clean zero. */
    public function test_no_data_and_no_benchmark_produces_a_clean_zero(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['country_code' => 'ZZ']);
        $this->makeStudent($institution, ['roll' => 'TRAIN-004']);

        $this->step('SSA', 'Forecasting', 'no basis at all yields zeros', __LINE__);

        $result = (new ForecastTrainer($institution))->train();

        $this->assertSame('empty', $result['basis'], 'An institution with nothing must be labelled empty.');
        $this->assertSame(0.0, (float) $result['weights']['baseline_per_member']);
        $this->assertSame(0, (int) $result['weights']['roster']);
    }

    /** The rolling forecast persists THREE months, each carrying its own basis. */
    public function test_the_rolling_forecast_persists_three_months(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $student = $this->makeStudent($institution, ['roll' => 'ROLL-001']);

        // Enough history to train, plus a ledger rate to price the projection.
        for ($i = 120; $i >= 1; $i--) {
            MealEntry::create([
                'institution_id' => $institution->id,
                'student_id' => $student->id,
                'date' => now()->subDays($i)->toDateString(),
                'breakfast' => 1, 'lunch' => 1, 'dinner' => 1,
            ]);
        }

        MealExpense::create([
            'institution_id' => $institution->id,
            'category' => 'food', 'amount' => 50000, 'description' => 'x', 'created_at' => now(),
        ]);

        $this->step('SSA', 'Forecasting', 'generate the rolling forecast', __LINE__);

        $payload = (new ForecastTrainer($institution))->generateRollingForecast();

        $this->assertCount(3, $payload['months'], 'The rolling forecast must cover three months.');

        foreach ($payload['months'] as $month) {
            $this->assertArrayHasKey('month', $month);
            $this->assertArrayHasKey('projected_meals', $month);
            $this->assertArrayHasKey('projected_cost', $month);
            // Every month states how it was derived, so a benchmark month is visible.
            $this->assertArrayHasKey('basis', $month);
        }

        // Every projected month is priced at the SAME ledger rate - no invented drift.
        $rates = array_unique(array_column($payload['months'], 'projected_rate'));
        $this->assertCount(1, $rates, 'A projection is COUNTS x PRICE; the rate must not vary by month.');

        // And it is PERSISTED, so the projection is a stable artefact.
        $model = ForecastModel::latestFor($institution->id);
        $this->assertNotNull($model->forecast_generated_at);
        $this->assertCount(3, $model->rollingForecast());
    }

    /** The scheduled command trains every active institution. */
    public function test_the_command_trains_active_institutions(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'Trainable Hall']);
        $student = $this->makeStudent($institution, ['roll' => 'CMD-001']);

        for ($i = 120; $i >= 1; $i--) {
            MealEntry::create([
                'institution_id' => $institution->id,
                'student_id' => $student->id,
                'date' => now()->subDays($i)->toDateString(),
                'breakfast' => 1, 'lunch' => 1, 'dinner' => 1,
            ]);
        }

        $this->step('SSA', 'Forecasting', 'artisan forecast:train-monthly', __LINE__);

        $this->artisan('forecast:train-monthly')->assertExitCode(0);

        $this->assertNotNull(
            ForecastModel::latestFor($institution->id),
            'The scheduled training run must persist a model row for each active institution.'
        );
    }

    /** The command's `--rolling` flag persists the rolling forecast instead. */
    public function test_the_rolling_flag_persists_the_projection(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'Rolling Hall']);
        $student = $this->makeStudent($institution, ['roll' => 'CMD-002']);

        for ($i = 120; $i >= 1; $i--) {
            MealEntry::create([
                'institution_id' => $institution->id,
                'student_id' => $student->id,
                'date' => now()->subDays($i)->toDateString(),
                'breakfast' => 1, 'lunch' => 1, 'dinner' => 1,
            ]);
        }

        $this->step('SSA', 'Forecasting', 'artisan forecast:train-monthly --rolling', __LINE__);

        $this->artisan('forecast:train-monthly', ['--rolling' => true])->assertExitCode(0);

        $model = ForecastModel::latestFor($institution->id);

        $this->assertNotNull($model);
        $this->assertNotNull($model->forecast_generated_at, '--rolling must persist a generated forecast.');
        $this->assertCount(3, $model->rollingForecast());
    }

    /** `--dry-run` reports but writes nothing, so it is safe to rehearse. */
    public function test_the_dry_run_writes_nothing(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'Dry Run Hall']);
        $this->makeStudent($institution, ['roll' => 'CMD-003']);

        $this->step('SSA', 'Forecasting', 'artisan forecast:train-monthly --dry-run', __LINE__);

        $this->artisan('forecast:train-monthly', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull(
            ForecastModel::latestFor($institution->id),
            'A dry run must not write a model row.'
        );
    }

    /** The forecasting page renders and STATES its basis and confidence. */
    public function test_the_forecasting_page_renders_and_states_its_basis(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['country_code' => 'BD']);
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('IA', 'Forecasting', 'visit /meals/forecasting', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/meals/forecasting')
                // Either basis banner is acceptable - what matters is that the page
                // does not present a number without saying where it came from.
                ->waitFor('[data-testid="forecast-basis-benchmark"], [data-testid="forecast-basis-history"]', 20)
                ->assertVisible('[data-testid="forecast-confidence"]')
                ->assertVisible('[data-testid="forecast-meals"]');
        });
    }
}
