<?php

namespace Tests\Browser\Meals;

use App\Models\MealEntry;
use App\Models\User;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 18: Meal Entry Audit Column (`given_by`).
 *
 * Verifies:
 * - Meal entry records track precisely who_gave_the_meal_entries (`given_by`),
 *   recording user ID and name of the staff/manager who logged it.
 * - Meal entry stores `given_by` correctly upon saving meal entries.
 */
class MealEntryAuditColumnTest extends DuskTestCase
{
    use DuskSupport;

    public function test_meal_entry_tracks_given_by_audit_column(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution, ['name' => 'Manager Tariq']);
        $member = $this->makeMember($institution);
        $student = $member->studentRecord();
        $student->update(['manager_id' => $manager->id]);

        $today = now()->toDateString();

        $this->step('MealManager', 'Meals', 'save meal entry with given_by attribution', __LINE__);

        $response = $this->httpAs($manager)->post('/meals/entries', [
            'date' => $today,
            'entries' => [
                [
                    'student_id' => $student->id,
                    'breakfast' => 1,
                    'lunch' => 1,
                    'dinner' => 0,
                ],
            ],
        ]);

        $response->assertSessionHas('success');

        $entry = MealEntry::where('student_id', $student->id)->whereDate('date', $today)->first();
        $this->assertNotNull($entry, 'Meal entry must be created.');
        $this->assertSame($manager->id, $entry->given_by, 'given_by must point to the manager who logged it.');
        $this->assertSame('Manager Tariq', $entry->givenBy->name, 'givenBy relationship must resolve the manager.');
    }
}
