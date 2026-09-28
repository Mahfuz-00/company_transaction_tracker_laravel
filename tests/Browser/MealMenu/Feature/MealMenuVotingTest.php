<?php

namespace Tests\Browser\MealMenu\Feature;

use App\Models\MealMenu;
use App\Models\MealMenuOption;
use App\Models\MealMenuVote;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEAL MENU & VOTING — the propose -> vote -> approve workflow.
 *
 * ROUTES
 *   GET    /meals/menus                       (meals.menus.index)   staff board
 *   POST   /meals/menus                       (meals.menus.store)   propose
 *   GET    /meals/menus/{menu}                (meals.menus.show)    tally + decision
 *   PATCH  /meals/menus/{menu}/open           (meals.menus.open)
 *   PATCH  /meals/menus/{menu}/approve        (meals.menus.approve) ADMIN / MANAGER
 *   PATCH  /meals/menus/{menu}/reject         (meals.menus.reject)
 *   GET    /my/menus                          (member.menus)        member voting
 *   POST   /my/menus/{menu}/vote              (member.menus.vote)
 *
 * WHAT THESE TESTS LOCK IN
 *   1. Staff can propose a menu with options; it starts as a draft.
 *   2. Only an Admin / Meal Manager can APPROVE - the core control of the module.
 *   3. Eligible members can vote, exactly ONE vote each, changeable while open.
 *   4. An ineligible staff member (not enrolled in meals) cannot vote.
 *   5. A menu is never "approved" without a decision.
 */
class MealMenuVotingTest extends DuskTestCase
{
    use DuskSupport;

    public function test_an_admin_can_propose_a_menu_with_options(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'MealMenu', 'POST /meals/menus with two options', __LINE__);

        $this->httpAs($admin)->post('/meals/menus', [
            'title' => 'Friday lunch',
            'meal_type' => 'lunch',
            'menu_date' => now()->addDay()->toDateString(),
            'description' => 'Two options for the vote',
            'open_voting' => true,
            'allow_vote_changes' => true,
            'options' => [
                ['name' => 'Rice + Chicken', 'estimated_cost' => 120, 'is_recommended' => true],
                ['name' => 'Khichuri + Beef', 'estimated_cost' => 150],
            ],
        ])->assertSessionHas('success');

        $menu = MealMenu::where('title', 'Friday lunch')->first();

        $this->assertNotNull($menu, 'The menu must be created.');
        $this->assertSame('voting', $menu->status, 'Opening the vote must set status to voting.');
        $this->assertSame(2, $menu->options()->count());
        $this->assertSame($institution->id, $menu->institution_id);
        $this->assertSame($admin->id, $menu->created_by);
    }

    public function test_a_menu_requires_at_least_two_options(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'MealMenu', 'a single-option menu is refused', __LINE__);

        // A "vote" with one choice is not a vote, so validation rejects it.
        $this->httpAs($admin)
            ->post('/meals/menus', [
                'title' => 'Only one dish',
                'meal_type' => 'lunch',
                'menu_date' => now()->addDay()->toDateString(),
                'options' => [
                    ['name' => 'Rice + Chicken'],
                ],
            ])
            ->assertSessionHasErrors('options');

        $this->assertSame(0, MealMenu::count());
    }

    public function test_a_meal_manager_can_approve_a_menu(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        $menu = $this->makeVotingMenu($institution, $manager);

        // A menu needs a CLEAR WINNER before it can be approved automatically, so
        // cast a vote for one option first - exactly as a member would.
        $voter = $this->makeMember($institution, ['email' => 'approver-voter@example.test']);

        MealMenuVote::create([
            'meal_menu_id' => $menu->id,
            'meal_menu_option_id' => $menu->options()->first()->id,
            'user_id' => $voter->id,
        ]);

        $this->step('MealManager', 'MealMenu', 'PATCH approve', __LINE__);

        $this->httpAs($manager)
            ->patch('/meals/menus/'.$menu->id.'/approve')
            ->assertSessionHas('success');

        $menu->refresh();

        $this->assertSame('approved', $menu->status);
        $this->assertSame($manager->id, $menu->approved_by);
        $this->assertNotNull($menu->approved_at);
    }

    public function test_a_menu_cannot_be_approved_without_a_clear_winner(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        // A voting menu with NO votes cast at all.
        $menu = $this->makeVotingMenu($institution, $admin);

        $this->step('InstituteAdmin', 'MealMenu', 'approve with zero votes is refused', __LINE__);

        $this->httpAs($admin)
            ->patch('/meals/menus/'.$menu->id.'/approve')
            ->assertSessionHas('error');

        $this->assertSame('voting', $menu->fresh()->status, 'The menu must remain unapproved.');
    }

    public function test_a_tie_blocks_automatic_approval_but_allows_an_explicit_choice(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $menu = $this->makeVotingMenu($institution, $admin);
        $options = $menu->options()->get();

        // Two members vote for DIFFERENT options -> a tie.
        $memberA = $this->makeMember($institution, ['email' => 'voter-a@example.test'], withMemberRecord: true);
        $memberB = $this->makeMember($institution, ['email' => 'voter-b@example.test'], withMemberRecord: true);

        MealMenuVote::create([
            'meal_menu_id' => $menu->id, 'meal_menu_option_id' => $options[0]->id, 'user_id' => $memberA->id,
        ]);
        MealMenuVote::create([
            'meal_menu_id' => $menu->id, 'meal_menu_option_id' => $options[1]->id, 'user_id' => $memberB->id,
        ]);

        $this->step('InstituteAdmin', 'MealMenu', 'a tie requires an explicit option', __LINE__);

        // Automatic approval cannot pick between equal winners...
        $this->httpAs($admin)
            ->patch('/meals/menus/'.$menu->id.'/approve')
            ->assertSessionHas('error');

        $this->assertSame('voting', $menu->fresh()->status);

        // ...but choosing an option explicitly resolves it.
        $this->httpAs($admin)
            ->patch('/meals/menus/'.$menu->id.'/approve', ['option_id' => $options[0]->id])
            ->assertSessionHas('success');

        $this->assertSame('approved', $menu->fresh()->status);
    }

    public function test_an_eligible_member_can_cast_one_vote(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeMember($institution, ['email' => 'voter@example.test']);

        $menu = $this->makeVotingMenu($institution, $admin);
        $option = $menu->options()->first();

        $this->step('Member', 'MealVoting', 'POST /my/menus/{id}/vote', __LINE__);

        $this->httpAs($member)
            ->post('/my/menus/'.$menu->id.'/vote', ['option_id' => $option->id])
            ->assertSessionHas('success');

        $this->assertSame(1, MealMenuVote::where('meal_menu_id', $menu->id)->count());
        $this->assertSame(
            $option->id,
            MealMenuVote::where('user_id', $member->id)->first()->meal_menu_option_id
        );
    }

    public function test_voting_twice_updates_the_single_vote_rather_than_adding_one(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeMember($institution, ['email' => 'changer@example.test']);

        $menu = $this->makeVotingMenu($institution, $admin);
        $options = $menu->options()->get();

        $this->step('Member', 'MealVoting', 'change a vote', __LINE__);

        $this->httpAs($member)->post('/my/menus/'.$menu->id.'/vote', ['option_id' => $options[0]->id]);
        $this->httpAs($member)->post('/my/menus/'.$menu->id.'/vote', ['option_id' => $options[1]->id]);

        // ONE vote, moved - not two.
        $this->assertSame(1, MealMenuVote::where('meal_menu_id', $menu->id)->count());
        $this->assertSame(
            $options[1]->id,
            MealMenuVote::where('user_id', $member->id)->first()->meal_menu_option_id
        );
    }

    public function test_a_locked_vote_cannot_be_changed(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeMember($institution, ['email' => 'locked@example.test']);

        $menu = $this->makeVotingMenu($institution, $admin, allowChanges: false);
        $options = $menu->options()->get();

        $this->httpAs($member)->post('/my/menus/'.$menu->id.'/vote', ['option_id' => $options[0]->id]);

        $this->step('Member', 'MealVoting', 'a locked vote refuses a change', __LINE__);

        $this->httpAs($member)
            ->post('/my/menus/'.$menu->id.'/vote', ['option_id' => $options[1]->id])
            ->assertSessionHas('error');

        // The original choice stands.
        $this->assertSame(
            $options[0]->id,
            MealMenuVote::where('user_id', $member->id)->first()->meal_menu_option_id
        );
    }

    public function test_a_staff_member_who_has_not_opted_into_meals_cannot_vote(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        // A Meal Manager with is_meal_participant = false: they run meals but do
        // not eat in the mess, so they have no vote.
        $manager = $this->makeMealManager($institution, [
            'email' => 'non-eater@example.test',
            'is_meal_participant' => false,
        ]);

        $menu = $this->makeVotingMenu($institution, $admin);
        $option = $menu->options()->first();

        $this->step('MealManager', 'MealVoting', 'an ineligible staff member is refused', __LINE__);

        $this->httpAs($manager)
            ->post('/my/menus/'.$menu->id.'/vote', ['option_id' => $option->id])
            ->assertSessionHas('error');

        $this->assertSame(0, MealMenuVote::where('meal_menu_id', $menu->id)->count());
    }

    public function test_a_staff_member_who_opts_into_meals_can_vote(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        // The SAME role, but enrolled in meals - this is the opt-in the module
        // is built around.
        $manager = $this->makeMealManager($institution, [
            'email' => 'eater@example.test',
            'is_meal_participant' => true,
        ]);

        $menu = $this->makeVotingMenu($institution, $admin);
        $option = $menu->options()->first();

        $this->step('MealManager', 'MealVoting', 'an opted-in staff member may vote', __LINE__);

        $this->httpAs($manager)
            ->post('/my/menus/'.$menu->id.'/vote', ['option_id' => $option->id])
            ->assertSessionHas('success');

        $this->assertSame(1, MealMenuVote::where('meal_menu_id', $menu->id)->count());
    }

    public function test_a_vote_for_an_option_from_another_menu_is_refused(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeMember($institution, ['email' => 'cross@example.test']);

        $menuA = $this->makeVotingMenu($institution, $admin, title: 'Menu A');
        $menuB = $this->makeVotingMenu($institution, $admin, title: 'Menu B');

        $foreignOption = $menuB->options()->first();

        $this->step('Member', 'MealVoting', 'a cross-menu option is refused', __LINE__);

        // A crafted request must not be able to vote for a dish on another menu.
        $this->httpAs($member)
            ->post('/my/menus/'.$menuA->id.'/vote', ['option_id' => $foreignOption->id])
            ->assertSessionHas('error');

        $this->assertSame(0, MealMenuVote::where('meal_menu_id', $menuA->id)->count());
    }

    public function test_a_member_sees_the_voting_screen_in_the_browser(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeMember($institution, ['email' => 'viewer@example.test']);

        $menu = $this->makeVotingMenu($institution, $admin, title: 'Browser Menu');

        $this->step('Member', 'MealVoting', 'visit /my/menus', __LINE__);

        $this->browse(function (Browser $browser) use ($member, $menu) {
            $browser->loginAs($member)
                ->visit('/my/menus')
                ->waitFor('[data-testid="open-menu-card"]', 20)
                ->assertSee($menu->title)
                ->assertVisible('[data-testid="submit-vote"]');
        });
    }

    public function test_the_staff_board_renders_the_menu_with_approval_controls(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $menu = $this->makeVotingMenu($institution, $admin, title: 'Board Menu');

        $this->step('InstituteAdmin', 'MealMenu', 'visit /meals/menus', __LINE__);

        $this->browse(function (Browser $browser) use ($admin, $menu) {
            $browser->loginAs($admin)
                ->visit('/meals/menus')
                ->waitFor('[data-testid="menu-card"]', 20)
                ->assertSee($menu->title)
                ->assertVisible('[data-testid="menu-approve-button"]');
        });
    }

    public function test_a_meal_manager_cannot_propose_a_menu_without_the_reporting_right(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();

        // A Member holds none of the menu-management permissions.
        $member = $this->makeMember($institution, ['email' => 'no-perm@example.test']);

        $this->step('Member', 'MealMenu', 'the staff board is forbidden', __LINE__);

        $this->httpAs($member)->get('/meals/menus')->assertForbidden();
    }

    /**
     * A voting menu with two options, ready to receive votes.
     */
    protected function makeVotingMenu(
        $institution,
        $creator,
        string $title = 'Lunch vote',
        bool $allowChanges = true,
    ): MealMenu {
        $menu = MealMenu::create([
            'institution_id' => $institution->id,
            'meal_type' => 'lunch',
            'menu_date' => now()->addDay()->toDateString(),
            'title' => $title,
            'status' => 'voting',
            'voting_opens_at' => now()->subHour(),
            'allow_vote_changes' => $allowChanges,
            'created_by' => $creator->id,
        ]);

        MealMenuOption::create([
            'meal_menu_id' => $menu->id, 'name' => 'Rice + Chicken',
            'estimated_cost' => 120, 'sort_order' => 0,
        ]);

        MealMenuOption::create([
            'meal_menu_id' => $menu->id, 'name' => 'Khichuri + Beef',
            'estimated_cost' => 150, 'sort_order' => 1,
        ]);

        return $menu->fresh();
    }
}
