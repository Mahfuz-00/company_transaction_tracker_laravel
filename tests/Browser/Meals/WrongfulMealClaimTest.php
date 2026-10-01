<?php

namespace Tests\Browser\Meals;

use App\Models\Claim;
use App\Models\MealEntry;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 14: Wrongful Meal Count Claim System.
 *
 * Verifies:
 * - Members can submit a claim/notification to the meal manager or institute admin
 *   if their meals were wrongfully counted on a specific date.
 * - Claims appear in member claims list and the manager's review queue.
 */
class WrongfulMealClaimTest extends DuskTestCase
{
    use DuskSupport;

    public function test_member_submits_wrongful_meal_claim(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);
        $student = $member->studentRecord();

        // Recorded meal entry on specific date
        $entryDate = now()->subDay()->toDateString();
        MealEntry::create([
            'institution_id' => $institution->id,
            'student_id' => $student->id,
            'date' => $entryDate,
            'breakfast' => 1,
            'lunch' => 1,
            'dinner' => 1,
        ]);

        $this->step('Member', 'Claims', 'submit wrongful meal dispute', __LINE__);

        $response = $this->httpAs($member)->post('/claims', [
            'kind' => 'dispute',
            'subject' => 'meal',
            'title' => 'Wrongful lunch count on '.$entryDate,
            'description' => 'I was away at doctor appointment during lunch.',
            'entry_date' => $entryDate,
            'lunch' => 1,
            'claim_date' => now()->toDateString(),
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('claims', [
            'institution_id' => $institution->id,
            'student_id' => $student->id,
            'kind' => 'dispute',
            'subject' => 'meal',
            'title' => 'Wrongful lunch count on '.$entryDate,
            'status' => 'pending',
        ]);
    }

    public function test_wrongful_meal_claim_appears_in_manager_review_queue(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);
        $manager = $this->makeMealManager($institution);
        $student = $member->studentRecord();
        $student->update(['manager_id' => $manager->id]);

        $claim = Claim::create([
            'institution_id' => $institution->id,
            'student_id' => $student->id,
            'kind' => 'dispute',
            'subject' => 'meal',
            'title' => 'Overcounted dinner on Friday',
            'description' => 'Was marked 2 dinners instead of 1',
            'entry_date' => now()->subDays(2)->toDateString(),
            'dinner' => 1,
            'claim_date' => now()->toDateString(),
            'status' => 'pending',
        ]);

        $this->step('MealManager', 'Claims', 'view wrongful meal claim in review queue', __LINE__);

        $this->browse(function (Browser $browser) use ($manager) {
            $browser->loginAs($manager)
                ->visit('/claims/review')
                ->waitForText('Overcounted dinner on Friday', 20)
                ->assertSee('Overcounted dinner on Friday')
                ->assertSee('Was marked 2 dinners instead of 1');
        });
    }
}
