<?php

namespace Tests\Browser\MealManager\Refunds\Feature;

use App\Models\Deposit;
use App\Models\Refund;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEAL MANAGER → MODULES → REFUNDS.
 *
 * The Refunds module is gated by `permission:meals.deposit`, which a Meal
 * Manager HOLDS, so a manager can refund their own assigned members. The
 * controller additionally scopes a manager to `scopedStudentIds()`, so they can
 * never refund a member assigned to someone else.
 */
class RefundsTest extends DuskTestCase
{
    use DuskSupport;

    public function test_a_meal_manager_can_refund_an_assigned_member(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);
        $member = $this->makeStudent($institution, ['manager_id' => $manager->id, 'roll' => 'MM-8001']);

        Deposit::create([
            'institution_id' => $institution->id,
            'student_id' => $member->id,
            'amount' => 500,
            'kind' => 'personal',
            'recorded_by' => $manager->id,
        ]);

        $this->step('MealManager', 'Refunds', 'refund an assigned member', __LINE__);

        $this->httpAs($manager)
            ->post('/meals/refunds', ['student_id' => $member->id, 'amount' => 150])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('refunds', ['student_id' => $member->id, 'amount' => 150]);
    }

    public function test_a_meal_manager_cannot_refund_an_unassigned_member(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);
        $otherManager = $this->makeMealManager($institution, ['email' => 'mm2@example.test']);

        $theirs = $this->makeStudent($institution, ['manager_id' => $otherManager->id, 'roll' => 'MM-8002']);
        Deposit::create([
            'institution_id' => $institution->id,
            'student_id' => $theirs->id,
            'amount' => 500,
            'kind' => 'personal',
            'recorded_by' => $otherManager->id,
        ]);

        $this->step('MealManager', 'Refunds', 'refuse an unassigned member', __LINE__);

        $this->httpAs($manager)
            ->post('/meals/refunds', ['student_id' => $theirs->id, 'amount' => 100])
            ->assertSessionHas('error');

        $this->assertSame(0, Refund::where('student_id', $theirs->id)->count());
    }
}
