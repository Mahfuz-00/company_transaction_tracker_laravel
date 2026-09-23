<?php

namespace Tests\Browser\MealManager\MealEntries\Feature;

use App\Models\MealEntry;
use App\Models\Student;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEAL MANAGER → MODULES → MEAL ENTRIES (ASSIGNMENT-SCOPED GRID).
 *
 * Routes:
 *   GET  /meals/entries         (`meals.entries.index`,  meals.entry)
 *   GET  /meals/entries/create  (`meals.entries.create`, meals.entry)
 *   POST /meals/entries         (`meals.entries.store`,  meals.entry)
 *
 * MealEntryController::create shows ONLY the manager's assigned members on the
 * daily grid, and ::store silently skips any row for a member the manager is not
 * allowed to touch (so a crafted request cannot write another manager's meals).
 */
class MealEntriesTest extends DuskTestCase
{
    use DuskSupport;

    public function test_meal_manager_sees_only_assigned_members_on_the_grid(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);
        $otherManager = $this->makeMealManager($institution, ['email' => 'other@example.test']);

        Student::create([
            'institution_id' => $institution->id, 'name' => 'Grid Mine',
            'roll' => 'NSU-9301', 'status' => 'active', 'manager_id' => $manager->id,
        ]);
        Student::create([
            'institution_id' => $institution->id, 'name' => 'Grid Foreign',
            'roll' => 'NSU-9302', 'status' => 'active', 'manager_id' => $otherManager->id,
        ]);

        $this->step('MealManager', 'MealEntries', 'open the daily grid', __LINE__);

        $this->browse(function (Browser $browser) use ($manager) {
            $browser->loginAs($manager)
                ->visit('/meals/entries/create')
                ->waitForText('Grid Mine', 20)
                ->assertSee('Grid Mine');

            $this->step('MealManager', 'MealEntries', 'assert foreign member absent from grid', __LINE__);

            $browser->assertDontSee('Grid Foreign');
        });
    }

    public function test_a_row_for_an_unassigned_member_is_skipped(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);
        $otherManager = $this->makeMealManager($institution, ['email' => 'other@example.test']);

        $mine = Student::create([
            'institution_id' => $institution->id, 'name' => 'Grid Mine Row',
            'roll' => 'NSU-9303',
            'status' => 'active', 'manager_id' => $manager->id,
        ]);
        $foreign = Student::create([
            'institution_id' => $institution->id, 'name' => 'Grid Foreign Row',
            'roll' => 'NSU-9304',
            'status' => 'active', 'manager_id' => $otherManager->id,
        ]);

        $date = now()->toDateString();

        $this->step('MealManager', 'MealEntries', 'POST a grid mixing own + foreign rows', __LINE__);

        $this->httpAs($manager)->post('/meals/entries', [
            'date' => $date,
            'entries' => [
                ['student_id' => $mine->id, 'breakfast' => 1, 'lunch' => 1, 'dinner' => 0],
                ['student_id' => $foreign->id, 'breakfast' => 1, 'lunch' => 1, 'dinner' => 1],
            ],
        ]);

        $this->step('MealManager', 'MealEntries', 'assert only the own row was written', __LINE__);

        $this->assertNotNull(
            MealEntry::where('student_id', $mine->id)->whereDate('date', $date)->first(),
            'The assigned member row must be saved.'
        );
        $this->assertNull(
            MealEntry::where('student_id', $foreign->id)->whereDate('date', $date)->first(),
            'An unassigned member row must be silently skipped.'
        );
    }
}
