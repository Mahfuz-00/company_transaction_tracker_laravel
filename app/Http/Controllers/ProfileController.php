<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Support\MemberProfileSynchronizer;
use App\Support\PasswordGuard;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'avatarUrl' => $user->avatarUrl(),
            'designation' => $user->designation,
            // Context so the Profile Manager is informative for every role:
            // which roles the user holds, which institution they belong to, and
            // whether they are the global platform admin.
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'designation' => $user->designation,
                'status' => $user->status,
                'roles' => $user->getRoleNames()->all(),
                'is_super_admin' => $user->isSuperAdmin(),
                'institution' => $user->institution?->name,
                'password_changed_at' => $user->password_changed_at?->format('j M Y'),
                'joined_at' => $user->created_at?->format('j M Y'),
            ],
        ]);
    }

    /**
     * Update the user's profile information, including their profile picture.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->safe()->only(['name', 'email']);

        $user->fill($data);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        /*
         * SINGLE SOURCE OF TRUTH for the name.
         *
         * A member is stored twice (users.name + students.name). When the user
         * edits their name here it must propagate to their linked Member roster
         * record, otherwise the roster keeps the admin's placeholder name. The
         * synchroniser updates both (and any pending invitation) atomically.
         */
        if ($user->isDirty('name')) {
            MemberProfileSynchronizer::syncName($user, $user->name, save: false);
        } elseif ($user->isDirty('email')) {
            // The name was not touched but the email was: realign any open
            // invitation queued to the previous address. syncName() is skipped
            // (it would be a no-op) so we call the email realignment directly.
            $user->save();
            MemberProfileSynchronizer::syncEmail($user);
        }

        // --- Profile picture -------------------------------------------
        if ($request->boolean('remove_avatar') && $user->avatar_path) {
            \Storage::disk('public')->delete($user->avatar_path);
            $user->avatar_path = null;
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar_path) {
                \Storage::disk('public')->delete($user->avatar_path);
            }
            $user->avatar_path = $request->file('avatar')->store('avatars', 'public');
        }

        $user->save();

        return Redirect::route('profile.edit')->with('success', 'Profile updated.');
    }

    /**
     * The forced / voluntary password-change screen.
     *
     * A user with a temporary (demo) password lands here via the
     * EnsurePasswordIsChanged middleware and cannot leave until they set a new
     * one. Any other user can open it voluntarily too.
     */
    public function showChangePassword(Request $request): Response
    {
        return Inertia::render('Auth/ChangePassword', [
            'mustChange' => $request->user()->mustChangePassword(),
        ]);
    }

    /**
     * Apply the new password, clearing the forced-change flag.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();

        /*
         * SELF-SERVICE password change - an AUTHORISED path.
         *
         * The user is changing their OWN password, which is always permitted
         * (including for the Software Super Admin, whose credential can only
         * move this way or via the dedicated reset action). Routing through the
         * guard stamps `password_changed_at` and writes an audit entry, and
         * satisfies the model hook so the write is not blocked.
         */
        PasswordGuard::changePassword(
            $user,
            $data['password'],
            'self_service',
            forceSsa: true,
        );

        return redirect()
            ->route('dashboard')
            ->with('success', 'Your password has been updated.');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
