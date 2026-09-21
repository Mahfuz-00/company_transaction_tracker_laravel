<?php

namespace Tests\Browser\Member\Modules\Meals;

use App\Models\MealEntry;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEMBER → MODULES → MY MEAL ENTRIES.
 *
 * Route: GET /my/meals (`member.meals`), gated by
 * `permission:meals.view` + `role:Member`.
 * MemberDashboardController::meals scopes the list to the signed-in member's own
 * Student id (never another member's).
 */
class MealsTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_member_sees_their_own_meal_entries(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['name' => 'Samira Member']);
        $memberRecord = Student::where('user_id', $member->id)->firstOrFail();

        MealEntry::create([
            'student_id' => $memberRecord->id,
            'date' => now()->toDateString(),
            'breakfast' => 1, 'lunch' => 1, 'dinner' => 1,
            'recorded_by' => $member->id,
        ]);

        $this->step('Member', 'Meals', 'visit /my/meals', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/my/meals')
                ->assertPathIs('/my/meals')
                ->assertSee('Samira Member');
        });
    }
}
