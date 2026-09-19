<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PasswordGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Credential-integrity guardrails for the Software Super Admin account.
 *
 * These lock in the fix for the reported bug: an SSA password was found to have
 * been overwritten by a DIRECT, unguarded column write. The rules below must
 * hold forever:
 *   - an unguarded write to an SSA password is rejected,
 *   - the authorised guard changes it and stamps `password_changed_at`,
 *   - a generic admin user-update cannot move an SSA credential.
 */
class SuperAdminPasswordGuardTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdmin(string $password = 'original-secret'): User
    {
        Role::firstOrCreate(['name' => 'Software Super Admin', 'guard_name' => 'web']);

        $user = User::create([
            'name' => 'Mahfuz',
            'email' => 'mahfuz@example.com',
            'status' => 'active',
            'password' => Hash::make($password),
        ]);

        $user->assignRole('Software Super Admin');

        return $user->refresh();
    }

    public function test_a_direct_unguarded_password_write_to_a_super_admin_is_blocked(): void
    {
        $ssa = $this->makeSuperAdmin('original-secret');
        $originalHash = $ssa->password;

        // Simulate a stray seeder / script reaching straight for the column.
        $ssa->forceFill(['password' => Hash::make('hacked-password')])->save();

        $ssa->refresh();

        // The hash is UNCHANGED, and the original password still works.
        $this->assertSame($originalHash, $ssa->password);
        $this->assertTrue(Hash::check('original-secret', $ssa->password));
        $this->assertFalse(Hash::check('hacked-password', $ssa->password));
    }

    public function test_the_authorised_guard_changes_the_password_and_stamps_the_change_time(): void
    {
        $ssa = $this->makeSuperAdmin('original-secret');

        $this->assertNull($ssa->password_changed_at);

        $changed = PasswordGuard::changePassword($ssa, 'brand-new-secret', 'cli_reset', forceSsa: true);

        $this->assertTrue($changed);

        $ssa->refresh();
        $this->assertTrue(Hash::check('brand-new-secret', $ssa->password));
        $this->assertNotNull($ssa->password_changed_at);
    }

    public function test_the_guard_refuses_to_move_an_super_admin_password_without_the_authorised_flag(): void
    {
        $ssa = $this->makeSuperAdmin('original-secret');

        // No forceSsa -> the SSA password must NOT move.
        $changed = PasswordGuard::changePassword($ssa, 'sneaky-change', 'seed');

        $this->assertFalse($changed);

        $ssa->refresh();
        $this->assertTrue(Hash::check('original-secret', $ssa->password));
    }

    public function test_a_non_super_admin_password_changes_freely(): void
    {
        $member = User::create([
            'name' => 'Member',
            'email' => 'member@example.com',
            'status' => 'active',
            'password' => Hash::make('old'),
        ]);

        $changed = PasswordGuard::changePassword($member, 'new-member-pass');

        $this->assertTrue($changed);
        $this->assertTrue(Hash::check('new-member-pass', $member->refresh()->password));
    }

    public function test_may_change_password_rules(): void
    {
        $ssa = $this->makeSuperAdmin();

        // Self-service is allowed.
        $this->assertTrue(PasswordGuard::mayChangePassword($ssa, $ssa));
        // Another actor changing an SSA password is refused.
        $this->assertFalse(PasswordGuard::mayChangePassword($ssa, null));

        $member = User::create([
            'name' => 'Member',
            'email' => 'member2@example.com',
            'status' => 'active',
            'password' => Hash::make('x'),
        ]);
        $this->assertTrue(PasswordGuard::mayChangePassword($member, $ssa));
    }
}
