<?php

namespace Tests\Browser\MealManager\Deposits\Feature;

use App\Models\Deposit;
use App\Models\Student;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEAL MANAGER → MODULES → DEPOSITS (ASSIGNMENT-SCOPED).
 *
 * Route: GET /meals/deposits (`meals.deposits.index`, `permission:meals.deposit`)
 * and POST /meals/deposits (`meals.deposits.store`).
 *
 * DepositController scopes the ledger and the store action through
 * User::scopedStudentIds(): a manager may only record a deposit for a member
 * assigned to them, and any other target is refused ("That member is not
 * assigned to you.").
 */
class DepositsTest extends DuskTestCase
{
    use DuskSupport;

    public function test_meal_manager_records_a_deposit_for_an_assigned_member(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        $member = Student::create([
            'institution_id' => $institution->id,
            'name' => 'Assigned Member',
            'roll' => 'NSU-9201',
            'status' => 'active',
            'manager_id' => $manager->id,
        ]);

        $this->step('MealManager', 'Deposits', 'POST a deposit for an assigned member', __LINE__);

        $this->httpAs($manager)
            ->post('/meals/deposits', [
                'student_id' => $member->id,
                'amount' => 1200,
                'payment_method' => 'Cash',
            ])
            ->assertSessionHas('success');

        $this->step('MealManager', 'Deposits', 'assert deposit recorded', __LINE__);

        $this->assertDatabaseHas('deposits', ['student_id' => $member->id, 'amount' => 1200]);
    }

    public function test_meal_manager_cannot_deposit_for_an_unassigned_member(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);
        $otherManager = $this->makeMealManager($institution, ['email' => 'other@example.test']);

        $foreignMember = Student::create([
            'institution_id' => $institution->id,
            'name' => 'Not My Member',
            'roll' => 'NSU-9202',
            'status' => 'active',
            'manager_id' => $otherManager->id,
        ]);

        $this->step('MealManager', 'Deposits', 'POST refused for an unassigned member', __LINE__);

        $this->httpAs($manager)
            ->post('/meals/deposits', [
                'student_id' => $foreignMember->id,
                'amount' => 999,
            ])
            ->assertSessionHas('error', 'That member is not assigned to you.');

        $this->assertSame(0, Deposit::where('student_id', $foreignMember->id)->count());
    }
}
