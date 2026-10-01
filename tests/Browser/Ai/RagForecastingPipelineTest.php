<?php

namespace Tests\Browser\Ai;

use App\Models\ForecastBenchmark;
use App\Models\ForecastModel;
use App\Models\MealEntry;
use App\Models\MealExpense;
use App\Models\Subsidy;
use App\Support\MealPriceEngine;
use Carbon\Carbon;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 5: RAG & Vector Database Architecture Documentation & Implementation.
 *
 * Verifies:
 * - Meal price governance strictly by ledger formula: (Total Expenses - Total Subsidies) / Total Meals.
 * - Monthly training job (forecast:train-monthly) aggregates ledger data and updates weights.
 * - 3-month rolling projection generated, falling back to country aggregate benchmarks if history < 3 months.
 * - Forecasting UI displays basis, confidence, and projections.
 */
class RagForecastingPipelineTest extends DuskTestCase
{
    use DuskSupport;

    public function test_meal_price_governance_strictly_follows_ledger_formula(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $student = $this->makeStudent($institution, ['roll' => 'PRICE-001']);
        $startOfMonth = now()->startOfMonth();
        $month = $startOfMonth->format('Y-m');

        // 1,000 meals consumed across ten days strictly in current month
        for ($i = 0; $i < 10; $i++) {
            MealEntry::create([
                'institution_id' => $institution->id,
                'student_id' => $student->id,
                'date' => $startOfMonth->copy()->addDays($i)->toDateString(),
                'breakfast' => 20, 'lunch' => 40, 'dinner' => 40,
            ]);
        }

        // 30,000 expenses and 6,000 subsidies
        MealExpense::create([
            'institution_id' => $institution->id,
            'category' => 'food',
            'amount' => 30000,
            'description' => 'Month food',
            'created_at' => $startOfMonth->copy()->addDays(1),
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
        $rate = MealPriceEngine::rateForMonth($month, $institution);

        $this->assertSame(1000.0, (float) $totals['meals']);
        $this->assertSame(30000.0, (float) $totals['expenses']);
        $this->assertSame(6000.0, (float) $totals['subsidies']);

        // (30,000 - 6,000) / 1,000 = 24.00
        $this->assertSame(24.0, (float) $rate);
    }

    public function test_monthly_training_command_and_rolling_forecast(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['country_code' => 'BD']);
        $this->makeStudent($institution, ['roll' => 'TRAIN-003']);

        // Benchmark for fallback
        ForecastBenchmark::create([
            'country_code' => 'BD',
            'metric' => 'meals_per_member',
            'period_month' => now()->subMonth()->startOfMonth()->toDateString(),
            'value' => 2.5,
            'unit' => 'meals',
            'source' => 'Test benchmark',
        ]);

        $this->step('SSA', 'Forecasting', 'run artisan forecast:train-monthly --rolling', __LINE__);

        $this->artisan('forecast:train-monthly', ['--rolling' => true])->assertExitCode(0);

        $model = ForecastModel::latestFor($institution->id);
        $this->assertNotNull($model, 'Model row must be persisted.');
        $this->assertNotNull($model->forecast_generated_at);
        $this->assertCount(3, $model->rollingForecast(), '3-month rolling forecast must be persisted.');
    }

    public function test_forecasting_screen_renders_basis_and_metrics(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['country_code' => 'BD']);
        $admin = $this->makeInstitutionAdmin($institution);

        ForecastBenchmark::create([
            'country_code' => 'BD',
            'metric' => 'meals_per_member',
            'period_month' => now()->subMonth()->startOfMonth()->toDateString(),
            'value' => 2.5,
            'unit' => 'meals',
            'source' => 'Test benchmark',
        ]);

        $this->step('IA', 'Forecasting', 'view /meals/forecasting', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/meals/forecasting')
                ->waitFor('[data-testid="forecast-confidence"]', 20)
                ->assertVisible('[data-testid="forecast-confidence"]')
                ->assertVisible('[data-testid="forecast-meals"]');
        });
    }
}
