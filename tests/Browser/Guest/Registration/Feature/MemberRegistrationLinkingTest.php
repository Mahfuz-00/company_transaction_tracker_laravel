<?php

namespace Tests\Browser\Guest\Registration\Feature;

use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * GUEST → REGISTRATION → INSTITUTION LINKING.
 *
 * Route: POST /register (`register`), RegisteredUserController::store.
 *
 * THE BUG THIS LOCKS IN
 * ---------------------
 * A member who signed up with a valid institution invite code was correctly bound
 * to the institution (`users.institution_id`), but their member AREA resolves the
 * person through `users.studentRecord()` -> `students.user_id`. With no roster
 * row, every member screen showed "Your account is not linked yet".
 *
 * The fix creates the roster (Member) record in the SAME transaction as the
 * account. These tests assert BOTH halves:
 *   1. the account is mapped to the right institution, AND
 *   2. the roster row exists and points back at the account, so the member
 *      dashboard renders as active rather than "not linked".
 *
 * Assertion notes (test-side reliability):
 *   - The registration route sits behind `guest` middleware, so it is exercised
 *     at the HTTP layer (httpAs) rather than through a shared browser session.
 *   - The member-dashboard rendering is exercised in the browser, where the
 *     "not linked" vs "linked" copy actually appears.
 */
class MemberRegistrationLinkingTest extends DuskTestCase
{
    use DuskSupport;

    public function test_a_member_signup_with_an_invite_code_creates_a_linked_roster_record(): void
    {
        $this->seedRbac();

        $institution = $this->makeInstitution(['name' => 'North South University Dorm']);

        $this->step('Guest', 'Registration', 'POST /register with a valid invite code', __LINE__);

        $this->httpAsGuest()->post('/register', [
            'name' => 'Nadia Newcomer',
            'email' => 'nadia@newcomer.test',
            'phone' => '+8801700000000',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite_code' => $institution->invite_code,
            'role' => 'Member',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->step('Guest', 'Registration', 'assert account + roster are linked', __LINE__);

        $user = User::where('email', 'nadia@newcomer.test')->first();
        $this->assertNotNull($user, 'The account must be created.');

        // 1. Bound to the institution the code pointed at.
        $this->assertSame($institution->id, $user->institution_id);
        $this->assertTrue($user->hasRole('Member'));

        // 2. The LINKING FIX: a roster row now points back at this account, which
        //    is what `studentRecord()` (and therefore the member dashboard) reads.
        $this->assertNotNull(
            $user->studentRecord(),
            'A member signup must create a linked roster record, or the dashboard reads as "not linked".'
        );

        $student = Student::where('user_id', $user->id)->first();
        $this->assertSame($institution->id, $student->institution_id);
        $this->assertSame('active', $student->status);
        $this->assertSame('Nadia Newcomer', $student->name);

        // 3. The account is not flagged as "setup pending" - the status the UI
        //    reflects. `setup_completed_at` was missing from $fillable, so this
        //    used to be null even though the controller set it.
        $this->assertNotNull(
            $user->setup_completed_at,
            'setup_completed_at must persist, or the account reads as setup-pending.'
        );
    }

    public function test_a_meal_manager_signup_does_not_get_a_roster_record(): void
    {
        $this->seedRbac();

        $institution = $this->makeInstitution();

        $this->step('Guest', 'Registration', 'POST /register as a Meal Manager', __LINE__);

        $this->httpAsGuest()->post('/register', [
            'name' => 'Marco Manager',
            'email' => 'marco@manager.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite_code' => $institution->invite_code,
            'role' => 'Meal Manager',
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'marco@manager.test')->first();

        $this->assertSame($institution->id, $user->institution_id);
        $this->assertTrue($user->hasRole('Meal Manager'));

        // A Meal Manager is staff, not a participant: no roster row is created.
        $this->assertNull($user->studentRecord());
    }

    public function test_the_new_member_dashboard_renders_as_linked_not_not_linked(): void
    {
        $this->seedRbac();

        $institution = $this->makeInstitution();

        $this->httpAsGuest()->post('/register', [
            'name' => 'Linked Member',
            'email' => 'linked@member.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite_code' => $institution->invite_code,
            'role' => 'Member',
        ]);

        $member = User::where('email', 'linked@member.test')->firstOrFail();

        $this->step('Member', 'Dashboard', 'the freshly registered member opens /my/dashboard', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/my/dashboard')
                // The greeting reads "Welcome back, {name}" (Member/Dashboard.jsx).
                // This test previously waited for "Welcome, {name}" - the wording
                // used before the page title moved into the fixed top bar, which is
                // why it timed out even though the page rendered correctly.
                ->waitForText('Welcome back, Linked Member', 20)
                // The fix: the linked status chip is present...
                ->assertVisible('[data-testid="member-link-status"]')
                // ...and the "not linked" empty state is NOT.
                ->assertDontSee('Your account is not linked yet');
        });
    }

    public function test_an_invalid_invite_code_creates_no_orphaned_account(): void
    {
        $this->seedRbac();

        $this->step('Guest', 'Registration', 'POST /register with a bad code', __LINE__);

        $this->httpAsGuest()
            ->post('/register', [
                'name' => 'Orphan User',
                'email' => 'orphan@nowhere.test',
                'password' => 'password',
                'password_confirmation' => 'password',
                'invite_code' => 'NOPE9999',
            ])
            ->assertSessionHasErrors('invite_code');

        // Critically: no user AND no roster row were created.
        $this->assertNull(User::where('email', 'orphan@nowhere.test')->first());
        $this->assertSame(0, Student::query()->count());
    }

    public function test_registration_requires_an_active_institution(): void
    {
        $this->seedRbac();

        $institution = $this->makeInstitution(['is_active' => false]);

        $this->step('Guest', 'Registration', 'signup against an inactive institution is refused', __LINE__);

        $this->httpAsGuest()
            ->post('/register', [
                'name' => 'Blocked User',
                'email' => 'blocked@inactive.test',
                'password' => 'password',
                'password_confirmation' => 'password',
                'invite_code' => $institution->invite_code,
            ])
            ->assertSessionHasErrors('invite_code');

        $this->assertNull(User::where('email', 'blocked@inactive.test')->first());
    }
}
