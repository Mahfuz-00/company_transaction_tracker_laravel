<?php

namespace Tests\Browser\Intelligence\Feature;

use App\Models\Anomaly;
use App\Models\Deposit;
use App\Models\ForecastBenchmark;
use App\Models\FxRate;
use App\Models\MealEntry;
use App\Models\SavedReport;
use App\Support\AnomalyDetector;
use App\Support\Forecaster;
use App\Support\ReportBuilder;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * ADVANCED PLATFORM MODULES — the intelligence layer.
 *
 * Covers, in one suite, the modules that share a "compute something useful from
 * the ledger" shape:
 *
 *   ANOMALY DETECTION  /meals/anomalies        duplicate deposits, meal spikes,
 *                                              negative balances, unusual expenses
 *   REPORTS BUILDER    /meals/report-builder   saved queries over the finance engine
 *   FX RATES           /platform/currencies    currency snapshots for SSA reporting
 *   FORECASTING        /meals/forecasting      RAG headcount/expense prediction
 *
 * The pure-logic assertions (detector, converter, builder) run directly against
 * the services; the UI assertions confirm the screens actually render the results.
 */
class IntelligenceModulesTest extends DuskTestCase
{
    use DuskSupport;

    /* ------------------------------------------------------------------ *
     * ANOMALY DETECTION
     * ------------------------------------------------------------------ */

    public function test_duplicate_deposits_are_detected(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $student = $this->makeStudent($institution, ['roll' => 'DUP-001']);

        // Two identical deposits, minutes apart - the classic double entry.
        Deposit::create([
            'institution_id' => $institution->id, 'student_id' => $student->id,
            'amount' => 5000, 'payment_method' => 'cash', 'created_at' => now()->subMinutes(10),
        ]);
        Deposit::create([
            'institution_id' => $institution->id, 'student_id' => $student->id,
            'amount' => 5000, 'payment_method' => 'cash', 'created_at' => now()->subMinutes(5),
        ]);

        $result = (new AnomalyDetector)->scan($institution->id);

        $this->assertGreaterThan(0, $result['findings']);
        $this->assertGreaterThanOrEqual(1, $result['by_kind']['duplicate_deposit'] ?? 0);

        $anomaly = Anomaly::where('kind', 'duplicate_deposit')->first();
        $this->assertNotNull($anomaly);
        $this->assertSame($student->id, $anomaly->student_id);
    }

    public function test_the_scan_is_idempotent_and_does_not_duplicate_findings(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $student = $this->makeStudent($institution, ['roll' => 'DUP-002']);

        Deposit::create([
            'institution_id' => $institution->id, 'student_id' => $student->id,
            'amount' => 3000, 'payment_method' => 'cash', 'created_at' => now()->subMinutes(20),
        ]);
        Deposit::create([
            'institution_id' => $institution->id, 'student_id' => $student->id,
            'amount' => 3000, 'payment_method' => 'cash', 'created_at' => now()->subMinutes(15),
        ]);

        $detector = new AnomalyDetector;
        $detector->scan($institution->id);
        $first = Anomaly::count();

        // Re-running must REFRESH, not duplicate - the fingerprint unique index.
        $detector->scan($institution->id);

        $this->assertSame($first, Anomaly::count(), 'A second scan must not create duplicate findings.');
    }

    public function test_a_dismissed_finding_is_not_resurrected_by_a_rescan(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $student = $this->makeStudent($institution, ['roll' => 'DUP-003']);

        Deposit::create([
            'institution_id' => $institution->id, 'student_id' => $student->id,
            'amount' => 2000, 'payment_method' => 'cash', 'created_at' => now()->subMinutes(30),
        ]);
        Deposit::create([
            'institution_id' => $institution->id, 'student_id' => $student->id,
            'amount' => 2000, 'payment_method' => 'cash', 'created_at' => now()->subMinutes(25),
        ]);

        (new AnomalyDetector)->scan($institution->id);

        $anomaly = Anomaly::first();
        $anomaly->update(['status' => 'dismissed', 'reviewed_by' => $admin->id]);

        (new AnomalyDetector)->scan($institution->id);

        // A dismissed false positive must stay dismissed.
        $this->assertSame('dismissed', $anomaly->fresh()->status);
    }

    public function test_a_meal_spike_is_detected_against_the_members_own_baseline(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $student = $this->makeStudent($institution, ['roll' => 'SPIKE-001']);

        // A LONG, perfectly steady baseline of 1 meal/day...
        //
        // The length matters: the detector uses a z-score against the member's own
        // mean and standard deviation, so a SHORT baseline makes the deviation
        // large and a one-off spike falls below the threshold. Thirty steady days
        // make the single 3-meal day an unmistakable outlier - which is exactly the
        // real-world case this detector exists to catch.
        for ($i = 30; $i >= 1; $i--) {
            MealEntry::create([
                'institution_id' => $institution->id, 'student_id' => $student->id,
                'date' => now()->subDays($i)->toDateString(),
                'breakfast' => 0, 'lunch' => 1, 'dinner' => 0,
            ]);
        }

        // ...then a single day at triple their usual intake.
        MealEntry::create([
            'institution_id' => $institution->id, 'student_id' => $student->id,
            'date' => now()->toDateString(),
            'breakfast' => 1, 'lunch' => 1, 'dinner' => 1,
        ]);

        $result = (new AnomalyDetector)->scan($institution->id);

        $this->assertGreaterThanOrEqual(
            1,
            $result['by_kind']['meal_spike'] ?? 0,
            'A sharp rise against the member\'s own steady baseline must be flagged.'
        );
    }

    public function test_the_anomaly_monitor_renders_in_the_browser(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $student = $this->makeStudent($institution, ['roll' => 'DUP-004']);

        Deposit::create([
            'institution_id' => $institution->id, 'student_id' => $student->id,
            'amount' => 1500, 'payment_method' => 'cash', 'created_at' => now()->subMinutes(10),
        ]);
        Deposit::create([
            'institution_id' => $institution->id, 'student_id' => $student->id,
            'amount' => 1500, 'payment_method' => 'cash', 'created_at' => now()->subMinutes(5),
        ]);

        (new AnomalyDetector)->scan($institution->id);

        $this->step('InstituteAdmin', 'Anomalies', 'visit /meals/anomalies', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/meals/anomalies')
                ->waitFor('[data-testid="anomaly-card"]', 20)
                ->assertSee('Possible duplicate deposit')
                ->assertVisible('[data-testid="anomaly-dismiss"]');
        });
    }

    /* ------------------------------------------------------------------ *
     * FX RATES
     * ------------------------------------------------------------------ */

    public function test_an_fx_rate_can_be_recorded_and_used_for_conversion(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $this->step('SoftwareSuperAdmin', 'FX', 'record a rate snapshot', __LINE__);

        $this->httpAs($ssa)
            ->post('/platform/currencies', [
                'base_code' => 'USD',
                'quote_code' => 'BDT',
                'rate' => 120.5,
                'effective_on' => now()->toDateString(),
                'source' => 'manual',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('fx_rates', ['base_code' => 'USD', 'quote_code' => 'BDT']);

        // A direct conversion...
        $this->assertSame(1205.0, FxRate::convert(10, 'USD', 'BDT'));

        // ...and the INVERSE, so one snapshot serves both directions.
        $this->assertSame(10.0, FxRate::convert(1205, 'BDT', 'USD'));

        // A pair with no rate is reported as unknown, never silently 1:1.
        $this->assertNull(FxRate::convert(10, 'USD', 'JPY'));
    }

    public function test_an_unknown_currency_pair_is_reported_not_silently_dropped(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution([
            'name' => 'Pound Hall',
            'currency_code' => 'GBP',
            'subscription_amount' => 500,
        ]);
        $ssa = $this->makeSuperAdmin();

        $this->step('SoftwareSuperAdmin', 'FX', 'the roll-up names unconvertible institutions', __LINE__);

        $response = $this->httpAs($ssa)->get('/platform/currencies');
        $response->assertOk();

        $rollup = $response->viewData('page')['props']['rollup'];

        // With no GBP rate on file, the institution must be NAMED as unconverted
        // rather than quietly omitted from platform revenue.
        $this->assertGreaterThanOrEqual(1, $rollup['unconverted_count']);
        $this->assertSame('Pound Hall', $rollup['unconverted'][0]['institution']);
    }

    public function test_the_fx_page_renders_for_the_super_admin(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $this->step('SoftwareSuperAdmin', 'FX', 'visit /platform/currencies', __LINE__);

        $this->browse(function (Browser $browser) use ($ssa) {
            $browser->loginAs($ssa)
                ->visit('/platform/currencies')
                ->waitFor('[data-testid="fx-new-rate"]', 20)
                ->assertVisible('[data-testid="fx-rollup-total"]');
        });
    }

    public function test_an_institution_admin_cannot_reach_the_fx_module(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->httpAs($admin)->get('/platform/currencies')->assertForbidden();
    }

    /* ------------------------------------------------------------------ *
     * REPORTS BUILDER
     * ------------------------------------------------------------------ */

    public function test_a_report_definition_is_validated_against_the_whitelist(): void
    {
        $builder = new ReportBuilder;

        // A valid definition passes.
        $spec = $builder->validateDefinition([
            'dataset' => 'deposits',
            'metric' => 'sum',
            'measure' => 'amount',
            'group_by' => 'month',
        ]);

        $this->assertSame('deposits', $spec['dataset']);

        // An unknown dataset is refused...
        $this->expectException(\InvalidArgumentException::class);
        $builder->validateDefinition(['dataset' => 'passwords']);
    }

    public function test_a_measure_not_available_for_the_dataset_is_refused(): void
    {
        $builder = new ReportBuilder;

        // `breakfast` is a MEAL measure; it makes no sense over deposits.
        $this->expectException(\InvalidArgumentException::class);

        $builder->validateDefinition([
            'dataset' => 'deposits',
            'metric' => 'sum',
            'measure' => 'breakfast',
        ]);
    }

    public function test_a_report_can_be_run_and_returns_grouped_rows(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $student = $this->makeStudent($institution, ['roll' => 'REP-001']);

        Deposit::create([
            'institution_id' => $institution->id, 'student_id' => $student->id,
            'amount' => 1000, 'payment_method' => 'cash',
        ]);
        Deposit::create([
            'institution_id' => $institution->id, 'student_id' => $student->id,
            'amount' => 2500, 'payment_method' => 'cash',
        ]);

        $result = (new ReportBuilder)->run([
            'dataset' => 'deposits',
            'metric' => 'sum',
            'measure' => 'amount',
            'group_by' => 'member',
        ]);

        $this->assertSame(3500.0, (float) $result['total']);
        $this->assertNotEmpty($result['rows']);
    }

    public function test_an_admin_can_save_and_re_run_a_report(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'ReportBuilder', 'save a report', __LINE__);

        $this->httpAs($admin)
            ->post('/meals/report-builder', [
                'name' => 'Deposits by member',
                'description' => 'Total deposited per member',
                'definition' => [
                    'dataset' => 'deposits',
                    'metric' => 'sum',
                    'measure' => 'amount',
                    'group_by' => 'member',
                ],
            ])
            ->assertSessionHas('success');

        $report = SavedReport::where('name', 'Deposits by member')->first();

        $this->assertNotNull($report);
        $this->assertSame($admin->id, $report->user_id);

        // Running it bumps the usage counters.
        $this->httpAs($admin)->get('/meals/report-builder/'.$report->id.'/run');

        $this->assertSame(1, $report->fresh()->run_count);
    }

    public function test_a_saved_report_can_be_shared_with_the_workspace(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->httpAs($admin)->post('/meals/report-builder', [
            'name' => 'Shared report',
            'definition' => ['dataset' => 'meals', 'metric' => 'sum', 'measure' => 'meals', 'group_by' => 'day'],
            'is_shared' => true,
        ]);

        $this->assertTrue((bool) SavedReport::where('name', 'Shared report')->first()->is_shared);
    }

    public function test_the_report_builder_renders_in_the_browser(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'ReportBuilder', 'visit /meals/report-builder', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/meals/report-builder')
                ->waitFor('[data-testid="builder-run"]', 20)
                ->assertVisible('[data-testid="builder-save-open"]');
        });
    }

    /* ------------------------------------------------------------------ *
     * RAG FORECASTING
     * ------------------------------------------------------------------ */

    public function test_an_institution_with_little_history_falls_back_to_benchmarks(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['country_code' => 'BD']);
        $this->makeStudent($institution, ['roll' => 'FC-001']);

        // A published national benchmark for the fallback to anchor to.
        ForecastBenchmark::create([
            'country_code' => 'BD',
            'metric' => 'cost_per_meal',
            'period_month' => now()->subMonth()->startOfMonth()->toDateString(),
            'value' => 45.5,
            'unit' => 'BDT',
            'source' => 'Test benchmark',
        ]);

        $forecast = (new Forecaster($institution))->forecast();

        // No embedded history => the engine must SAY it used a benchmark.
        $this->assertSame('benchmark', $forecast['basis']);
        $this->assertNotNull($forecast['benchmark']);
        $this->assertTrue($forecast['benchmark']['available']);
        $this->assertNotEmpty($forecast['notes'], 'A benchmark estimate must explain itself.');
    }

    public function test_a_benchmark_forecast_reports_zero_confidence_when_no_data_exists(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['country_code' => 'ZZ']);

        // No benchmark published for this country at all.
        $forecast = (new Forecaster($institution))->forecast();

        $this->assertSame('benchmark', $forecast['basis']);
        $this->assertSame(0.0, $forecast['confidence']);
        $this->assertFalse($forecast['benchmark']['available']);
        $this->assertSame(0, $forecast['meals'], 'With no basis at all, we must not invent a number.');
    }

    public function test_building_embeddings_from_history_enables_a_data_driven_forecast(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $student = $this->makeStudent($institution, ['roll' => 'FC-002']);

        // ~4 months of steady history, enough to clear the 3-month threshold.
        for ($i = 120; $i >= 1; $i--) {
            MealEntry::create([
                'institution_id' => $institution->id,
                'student_id' => $student->id,
                'date' => now()->subDays($i)->toDateString(),
                'breakfast' => 1, 'lunch' => 1, 'dinner' => 1,
            ]);
        }

        $forecaster = new Forecaster($institution);
        $embedded = $forecaster->buildEmbeddings();

        $this->assertGreaterThan(0, $embedded, 'History must be embedded.');

        $forecast = $forecaster->forecast();

        // With real history the basis flips to `history`, and evidence is supplied.
        $this->assertSame('history', $forecast['basis']);
        $this->assertNotEmpty($forecast['evidence'], 'A data-driven forecast must show its evidence.');
        $this->assertGreaterThan(0, $forecast['confidence']);
    }

    public function test_the_forecasting_page_renders_and_states_its_basis(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['country_code' => 'BD']);
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'Forecasting', 'visit /meals/forecasting', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/meals/forecasting')
                // Either banner is acceptable - what matters is that the page
                // STATES which basis produced the numbers.
                ->waitFor('[data-testid="forecast-basis-benchmark"], [data-testid="forecast-basis-history"]', 20)
                ->assertVisible('[data-testid="forecast-confidence"]')
                ->assertVisible('[data-testid="forecast-meals"]');
        });
    }
}
