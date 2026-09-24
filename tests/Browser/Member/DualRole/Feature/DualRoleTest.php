<?php

namespace Tests\Browser\Member\DualRole\Feature;

use App\Models\Student;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * DUAL-ROLE MEMBERSHIP: a staff account (Institution Admin / Meal Manager) that
 * is ALSO a meal member must be able to use BOTH areas without a role collision.
 *
 * Locked-in behaviours:
 *   1. A pure member still lands on the personal member dashboard.
 *   2. A Meal Manager who is also a member keeps the STAFF dashboard (they are
 *      not swallowed by the member redirect) yet can still open /my/*.
 *   3. Linking a member record to a staff user grants the Member role WITHOUT
 *      stripping their staff role - so the `role:Member` routes stop 403-ing.
 *   4. A Meal Manager's member scope includes their OWN record (which the
 *      manager_id filter alone would exclude).
 */
class DualRoleTest extends DuskTestCase
{
    use DuskSupport;

    public function test_a_pure_member_is_redirected_to_the_member_dashboard(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $this->step('Member', 'DualRole', 'pure member lands on /my/dashboard', __LINE__);

        $this->httpAs($member)
            ->get('/dashboard')
            ->assertRedirect(route('member.dashboard'));
    }

    public function test_a_meal_manager_who_is_also_a_member_keeps_the_staff_dashboard(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        // The manager ALSO has a member record (they eat in the mess they run).
        // Linking it grants the Member role additively (see the Student hook).
        Student::create([
            'institution_id' => $institution->id,
            'user_id' => $manager->id,
            'name' => $manager->name,
            'roll' => 'NSU-2001',
            'status' => 'active',
        ]);

        $this->step('MealManager', 'DualRole', 'keeps the staff dashboard', __LINE__);

        $this->httpAs($manager->fresh())
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_linking_a_member_record_grants_the_member_role_without_stripping_staff_roles(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->assertFalse($admin->hasRole('Member'));

        Student::create([
            'institution_id' => $institution->id,
            'user_id' => $admin->id,
            'name' => $admin->name,
            'roll' => 'NSU-3001',
            'status' => 'active',
        ]);

        $admin = $admin->fresh();
        $this->assertTrue($admin->hasRole('Member'), 'The Member role must be granted.');
        $this->assertTrue($admin->hasRole('Institution Admin'), 'The staff role must be preserved.');
        $this->assertTrue($admin->hasAnyStaffRole());
    }

    public function test_a_dual_role_user_can_open_their_own_member_area(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        Student::create([
            'institution_id' => $institution->id,
            'user_id' => $manager->id,
            'name' => $manager->name,
            'roll' => 'NSU-4001',
            'status' => 'active',
        ]);

        $this->step('MealManager', 'DualRole', 'open /my/dashboard (role:Member gate)', __LINE__);

        // The `role:Member` gate on /my/* would 403 without the dual-role grant.
        $this->httpAs($manager->fresh())
            ->get('/my/dashboard')
            ->assertOk();
    }

    public function test_a_meal_managers_scope_includes_their_own_member_record(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);
        $otherManager = $this->makeMealManager($institution, ['email' => 'mm2@example.test']);

        $own = Student::create([
            'institution_id' => $institution->id,
            'user_id' => $manager->id,
            'name' => $manager->name,
            'roll' => 'NSU-5001',
            'status' => 'active',
        ]);
        $assigned = $this->makeStudent($institution, ['manager_id' => $manager->id, 'roll' => 'NSU-5002']);
        $foreign = $this->makeStudent($institution, ['manager_id' => $otherManager->id, 'roll' => 'NSU-5003']);

        $ids = $manager->scopedStudentIds();

        $this->assertContains($own->id, $ids, 'A manager must be able to act on their own member record.');
        $this->assertContains($assigned->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }
}
