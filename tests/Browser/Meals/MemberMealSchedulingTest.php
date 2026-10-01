<?php

namespace Tests\Browser\Meals;

use App\Models\MealSchedule;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 6: Member Meal Scheduling & Off/On Notifications.
 *
 * Verifies:
 * - Members can notify the meal manager whether they will take meals or not on a specific date or date range.
 * - Supports one-time, daily, weekly, or intervals.
 * - Schedules appear in the schedules list and meal managers can review and acknowledge.
 */
class MemberMealSchedulingTest extends DuskTestCase
{
    use DuskSupport;

    public function test_member_submits_meal_schedule_declaration(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $this->step('Member', 'Meals', 'declare meal off schedule', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/meals/schedules')
                ->waitFor('[data-testid="create-schedule-btn"]', 20)
                ->click('[data-testid="create-schedule-btn"]')
                ->waitFor('[data-testid="schedule-status-select"]', 20)
                ->select('[data-testid="schedule-status-select"]', 'off')
                ->select('[data-testid="schedule-recurrence-select"]', 'one_time')
                ->type('[data-testid="schedule-starts-on"]', now()->addDay()->format('Y-m-d'))
                ->type('[data-testid="schedule-ends-on"]', now()->addDays(3)->format('Y-m-d'))
                ->type('input[placeholder*="Vacation"]', 'Attending academic conference')
                ->press('[data-testid="submit-schedule-btn"]')
                ->waitForText('Meal schedule notice submitted', 20);
        });

        $this->assertDatabaseHas('meal_schedules', [
            'institution_id' => $institution->id,
            'user_id' => $member->id,
            'status' => 'off',
            'reason' => 'Attending academic conference',
        ]);
    }

    public function test_meal_manager_can_review_and_acknowledge_schedules(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);
        $manager = $this->makeMealManager($institution);

        $student = $member->studentRecord();
        $student->update(['manager_id' => $manager->id]);

        $schedule = MealSchedule::create([
            'institution_id' => $institution->id,
            'student_id' => $student->id,
            'user_id' => $member->id,
            'status' => 'off',
            'recurrence' => 'one_time',
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDays(2)->toDateString(),
            'breakfast' => true,
            'lunch' => true,
            'dinner' => true,
            'reason' => 'Family visit',
            'manager_status' => 'pending',
        ]);

        $this->step('Meal Manager', 'Meals', 'review and acknowledge schedule notice', __LINE__);

        $this->browse(function (Browser $browser) use ($manager, $schedule) {
            $browser->loginAs($manager)
                ->visit('/meals/schedules/review')
                ->waitFor('[data-testid="acknowledge-schedule-'.$schedule->id.'"]', 20)
                ->click('[data-testid="acknowledge-schedule-'.$schedule->id.'"]')
                ->waitForText('Acknowledged', 20);
        });

        $this->assertSame('acknowledged', $schedule->fresh()->manager_status);
    }
}
