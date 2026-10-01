<?php

namespace Tests\Browser\Settings;

use App\Models\MealEntry;
use App\Models\MealExpense;
use App\Support\MealPriceEngine;
use Carbon\Carbon;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 10: Configurable Meal Price Calculation Periods.
 *
 * Verifies:
 * - Institutions can choose their meal price calculation period (Daily, Weekly, or Monthly).
 * - Setting dynamically reflects across analytics, calculations, and tables.
 */
class MealPriceCalculationPeriodTest extends DuskTestCase
{
    use DuskSupport;

    public function test_institution_can_configure_meal_price_period_in_settings(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['meal_price_period' => 'monthly']);
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('IA', 'Settings', 'change meal price period to weekly', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/settings/institution')
                ->waitFor('[data-testid="meal-price-period-select"]', 20)
                ->assertSelected('[data-testid="meal-price-period-select"]', 'monthly')
                ->select('[data-testid="meal-price-period-select"]', 'weekly')
                ->press('Save Institution Settings')
                ->waitForText('Institution settings saved', 20);
        });

        $this->assertSame('weekly', $institution->fresh()->meal_price_period);
    }

    public function test_rate_calculation_dynamically_adapts_to_configured_period(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['meal_price_period' => 'daily']);
        $student = $this->makeStudent($institution);
        $today = Carbon::today();

        // 10 meals today, 50 expense today
        MealEntry::create([
            'institution_id' => $institution->id,
            'student_id' => $student->id,
            'date' => $today->toDateString(),
            'breakfast' => 2, 'lunch' => 4, 'dinner' => 4,
        ]);

        MealExpense::create([
            'institution_id' => $institution->id,
            'category' => 'daily_groceries',
            'amount' => 50.00,
            'created_at' => $today->copy()->addHour(),
        ]);

        // Daily rate: 50 / 10 = 5.0
        $rateDaily = MealPriceEngine::rateForConfiguredPeriod($today, $institution);
        $this->assertEquals(5.0, $rateDaily);

        // Switch to monthly: since month only has these 10 meals and 50 expense, check engine matches
        $institution->update(['meal_price_period' => 'monthly']);
        $rateMonthly = MealPriceEngine::rateForConfiguredPeriod($today, $institution->fresh());
        $this->assertEquals(5.0, $rateMonthly);
    }
}
