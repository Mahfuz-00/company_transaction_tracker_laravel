<?php

namespace Tests\Browser\InstituteAdmin\Modules\Expenses;

use App\Models\MealExpense;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → EXPENSES.
 *
 * Routes:
 *   GET   /meals/expenses               (`meals.expenses.index`,   meals.expense)
 *   POST  /meals/expenses               (`meals.expenses.store`,   meals.expense)
 *   PATCH /meals/expenses/{id}/reverse  (`meals.expenses.reverse`, meals.expense)
 *
 * MealExpenseController::store posts a cash-out ledger transaction AND the
 * MealExpense row; ::reverse flags the row and posts a matching cash-in.
 */
class ExpensesTest extends DuskTestCase
{
    use DuskSupport;

    public function test_institute_admin_records_an_expense(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'Expenses', 'POST an expense', __LINE__);

        $this->httpAs($admin)
            ->post('/meals/expenses', [
                'amount' => 2400,
                'description' => 'Rice and lentils',
                'category' => 'Groceries',
                'payment_status' => 'paid',
            ])
            ->assertSessionHas('success');

        $this->step('InstituteAdmin', 'Expenses', 'assert expense + ledger link', __LINE__);

        $expense = MealExpense::where('description', 'Rice and lentils')->first();
        $this->assertNotNull($expense);
        $this->assertSame(2400.0, (float) $expense->amount);
        $this->assertNotNull($expense->transaction_id);
    }

    public function test_institute_admin_reverses_an_expense(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->httpAs($admin)->post('/meals/expenses', [
            'amount' => 500, 'description' => 'Reversible item', 'category' => 'Misc',
        ]);

        $expense = MealExpense::where('description', 'Reversible item')->firstOrFail();

        $this->step('InstituteAdmin', 'Expenses', 'PATCH reverse', __LINE__);

        $this->httpAs($admin)
            ->patch("/meals/expenses/{$expense->id}/reverse")
            ->assertSessionHas('success');

        $this->step('InstituteAdmin', 'Expenses', 'assert reversed_at set', __LINE__);

        $this->assertNotNull($expense->fresh()->reversed_at);
    }
}
