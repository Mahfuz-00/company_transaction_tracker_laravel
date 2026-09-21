<?php

namespace Tests\Browser\InstituteAdmin\Modules\MealEntries;

use App\Models\MealEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → MEAL ENTRIES.
 *
 * Routes:
 *   GET  /meals/entries         (`meals.entries.index`, meals.entry)
 *   GET  /meals/entries/create  (`meals.entries.create`, meals.entry)
 *   POST /meals/entries         (`meals.entries.store`, meals.entry)
 *
 * MealEntryController::store accepts a WHOLE day's grid in one request and
 * upserts by DATE (matching on whereDate to avoid the unique(student_id, date)
 * clash).
 */
class MealEntriesTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_institute_admin_sees_the_daily_entry_grid(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $this->makeStudent($institution, ['name' => 'Entry Member', 'roll' => 'NSU-7001']);

        $this->step('InstituteAdmin', 'MealEntries', 'visit /meals/entries', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/meals/entries')
                ->waitForText('Entry Member', 20);
        });
    }

    public function test_institute_admin_saves_a_days_meals(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeStudent($institution, ['roll' => 'NSU-7002']);

        $date = now()->toDateString();

        $this->step('InstituteAdmin', 'MealEntries', 'POST the day grid', __LINE__);

        $this->actingAs($admin)
            ->post('/meals/entries', [
                'date' => $date,
                'entries' => [
                    ['student_id' => $member->id, 'breakfast' => 1, 'lunch' => 1, 'dinner' => 0],
                ],
            ])
            ->assertSessionHas('success');

        $this->step('InstituteAdmin', 'MealEntries', 'assert entry persisted for the date', __LINE__);

        $entry = MealEntry::where('student_id', $member->id)->whereDate('date', $date)->first();
        $this->assertNotNull($entry);
        $this->assertSame(1, (int) $entry->breakfast);
        $this->assertSame(1, (int) $entry->lunch);
        $this->assertSame(0, (int) $entry->dinner);
    }
}
