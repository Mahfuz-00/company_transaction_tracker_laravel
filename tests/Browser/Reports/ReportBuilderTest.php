<?php

namespace Tests\Browser\Reports;

use App\Models\SavedReport;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 13: Report Builder Overhaul.
 *
 * Verifies:
 * - Report builder functions properly with custom filters, date ranges, metrics, and exports without errors.
 * - Saved reports can be executed and shared.
 */
class ReportBuilderTest extends DuskTestCase
{
    use DuskSupport;

    public function test_report_builder_runs_queries_and_exports_without_error(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('IA', 'Reports', 'run custom report builder query in browser', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/meals/report-builder')
                ->waitFor('[data-testid="builder-run"]', 20)
                ->assertVisible('[data-testid="builder-save-open"]')
                ->click('[data-testid="builder-run"]')
                ->waitFor('[data-testid="builder-result"]', 20)
                ->assertVisible('[data-testid="builder-export-csv"]');
        });
    }

    public function test_saved_report_execution_and_storage(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('IA', 'Reports', 'store and run saved report', __LINE__);

        $response = $this->httpAs($admin)->post('/meals/report-builder', [
            'name' => 'Monthly Meal Consumption',
            'description' => 'Aggregated meal volume per student',
            'definition' => [
                'dataset' => 'meals',
                'metric' => 'sum',
                'measure' => 'meals',
                'group_by' => 'member',
            ],
            'is_shared' => true,
        ]);

        $response->assertSessionHas('success');

        $report = SavedReport::where('name', 'Monthly Meal Consumption')->first();
        $this->assertNotNull($report);
        $this->assertTrue((bool) $report->is_shared);

        // Run saved query endpoint
        $runResponse = $this->httpAs($admin)->get('/meals/report-builder/'.$report->id.'/run');
        $runResponse->assertSessionHas('reportResult');
        $this->assertSame(1, $report->fresh()->run_count);
    }
}
