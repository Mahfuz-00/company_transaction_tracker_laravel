<?php

namespace Tests\Browser\Menu;

use App\Models\MealMenu;
use App\Models\MealMenuOption;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 19: Menu Management Module & Member Notifications.
 *
 * Verifies:
 * - Menu management module maintains full menu history (propose, approve, manage options).
 * - Automatically notifies members via dashboard alert / notification banner about today's menu.
 */
class MenuManagementTest extends DuskTestCase
{
    use DuskSupport;

    public function test_menu_management_maintains_history_and_approval(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        // Staff proposes a dinner menu
        $menu = MealMenu::create([
            'institution_id' => $institution->id,
            'title' => 'Festive Dinner',
            'menu_date' => now()->toDateString(),
            'meal_type' => 'dinner',
            'status' => 'draft',
            'created_by' => $manager->id,
        ]);

        $option = MealMenuOption::create([
            'meal_menu_id' => $menu->id,
            'name' => 'Chicken Biryani & Salad',
            'is_default' => true,
        ]);

        $this->step('MealManager', 'Menu', 'approve proposed menu', __LINE__);

        // Approve the menu
        $response = $this->httpAs($manager)->patch('/meals/menus/'.$menu->id.'/approve', [
            'option_id' => $option->id,
        ]);
        $response->assertSessionHas('success');

        $this->assertSame('approved', $menu->fresh()->status);
        $this->assertSame($manager->id, $menu->fresh()->approved_by);
    }

    public function test_members_see_todays_active_menu_on_dashboard(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);
        $member = $this->makeMember($institution);

        // Create approved menu for today
        $menu = MealMenu::create([
            'institution_id' => $institution->id,
            'title' => 'Chef Daily Special',
            'menu_date' => now()->toDateString(),
            'meal_type' => 'lunch',
            'status' => 'approved',
            'approved_by' => $manager->id,
            'approved_at' => now(),
            'created_by' => $manager->id,
        ]);

        MealMenuOption::create([
            'meal_menu_id' => $menu->id,
            'name' => 'Steamed Rice, Lentils & Fish Curry',
            'is_default' => true,
        ]);

        $this->step('Member', 'Menu', 'view today menu notification on dashboard', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/my/dashboard')
                ->waitFor('[data-testid="today-menu-banner"]', 20)
                ->assertVisible('[data-testid="today-menu-banner"]')
                ->assertSee("Today's Active Menu")
                ->assertSee('Steamed Rice, Lentils & Fish Curry')
                ->assertSee('Chef Daily Special');
        });
    }
}
