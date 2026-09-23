<?php

namespace Tests\Browser\MealManager\Expenses\Feature;

use App\Models\MealExpense;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEAL MANAGER → MODULES → EXPENSES.
 *
 * Routes:
 *   GET  /meals/expenses  (`meals.expenses.index`, meals.expense)
 *   POST /meals/expenses  (`meals.expenses.store`, meals.expense)
 *
 * The Meal Manager role holds `meals.expense` (see RolesAndPermissionsSeeder),
 * so they record day-to-day mess purchases. Expenses are institution-scoped.
 */
class ExpensesTest extends DuskTestCase
{
    use DuskSupport;

    public function test_meal_manager_records_an_expense(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        $this->step('MealManager', 'Expenses', 'POST an expense', __LINE__);

        $this->httpAs($manager)
            ->post('/meals/expenses', [
                'amount' => 650,
                'description' => 'Vegetables for the week',
                'category' => 'Groceries',
            ])
            ->assertSessionHas('success');

        $this->step('MealManager', 'Expenses', 'assert row recorded', __LINE__);

        $this->assertDatabaseHas('meal_expenses', ['description' => 'Vegetables for the week']);
    }

    public function test_meal_manager_sees_the_expenses_list(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        MealExpense::create([
            'description' => 'Cooking gas',
            'category' => 'Utilities',
            'amount' => 1200,
            'recorded_by' => $manager->id,
        ]);

        $this->step('MealManager', 'Expenses', 'visit /meals/expenses', __LINE__);

        $this->browse(function (Browser $browser) use ($manager) {
            $browser->loginAs($manager)
                ->visit('/meals/expenses')
                ->waitForText('Cooking gas', 20);
        });
    }
}
