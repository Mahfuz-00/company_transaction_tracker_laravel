<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Deposit;
use App\Models\Institution;
use App\Models\Subsidy;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Mobile authentication + the client's identity/context endpoint.
 *
 * HOW AUTH WORKS HERE (for a developer new to token auth)
 * ------------------------------------------------------
 * The mobile app does NOT use cookies or sessions. It exchanges credentials for
 * a Laravel Sanctum *personal access token* once, stores the plain-text token
 * on the device, and then sends it on every subsequent request as:
 *
 *     Authorization: Bearer <token>
 *
 * The token is validated by the `auth:sanctum` middleware; inside a controller
 * `$request->user()` is then the token's owner. `logout` revokes the CALLING
 * token only, so signing out on one phone does not sign the user out everywhere.
 *
 * Every successful auth response returns the user through the shared
 * UserResource, so the shape is identical across login/register/me/profile.
 */
class AuthController extends Controller
{
    /**
     * The message an SSA receives when they attempt to use the mobile app.
     *
     * Shared by login() and me() so the refusal reads identically wherever it is
     * enforced - the client surfaces this string verbatim (see
     * docs/FLUTTER_MOBILE_APP.md §1.1).
     */
    protected const SSA_MOBILE_REFUSAL = 'Platform administrators must use the web console.';

    /** Exchange credentials for a bearer token. */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            // Deliberately the same message for "no such user" and "wrong
            // password" so the endpoint cannot be used to enumerate emails.
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
            ], 422);
        }

        if (! $user->isActive()) {
            return response()->json(['message' => 'This account is inactive.'], 403);
        }

        /*
         * SOFTWARE SUPER ADMIN EXCLUSION (layer 1 of 3).
         *
         * The mobile client is a TENANT field tool; an SSA is a GLOBAL operator
         * whose token has no tenant scope (TenantManager::resolveTenantId()
         * returns null for them), so a token issued here would expose EVERY
         * institution's data. We therefore refuse to mint one at all.
         *
         * The check runs AFTER the credential check so a wrong password on an
         * SSA account still returns the neutral 422 (no account enumeration).
         */
        if ($user->isSuperAdmin()) {
            return response()->json(['message' => self::SSA_MOBILE_REFUSAL], 403);
        }

        $token = $user->createToken($data['device_name'] ?? 'mobile')->plainTextToken;

        $user->update(['last_login_at' => now()]);

        return response()->json([
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    /**
     * Self-registration. New accounts get the Member role.
     *
     * The account is mapped to its institution through the INVITE CODE, exactly
     * as the web sign-up form does: the code is the tenant-mapping key, resolved
     * BEFORE anything is written so a bad code fails cleanly instead of producing
     * an orphaned account. Relying on `Institution::current()` was wrong for a
     * public, unauthenticated call - "current" is a guess with no tenant scope.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'device_name' => ['nullable', 'string', 'max:120'],
            // The tenant-mapping key - required, and must resolve to an institution.
            'invite_code' => ['required', 'string', 'max:24'],
        ]);

        $institution = Institution::findByInviteCode($data['invite_code']);

        if (! $institution) {
            throw ValidationException::withMessages([
                'invite_code' => 'That invitation code is not valid. Ask your institution admin for the correct code.',
            ]);
        }

        if (! $institution->is_active) {
            throw ValidationException::withMessages([
                'invite_code' => 'That institution is not currently accepting new members.',
            ]);
        }

        $user = User::create([
            // THE mapping: bound to the institution from the very first write.
            'institution_id' => $institution->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => 'active',
        ]);

        // A self-registered account is ALWAYS a Member - never trust a client
        // for the role (there is no `role` field accepted here at all).
        $user->assignRole('Member');

        $token = $user->createToken($data['device_name'] ?? 'mobile')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $this->userPayload($user),
            ],
        ], 201);
    }

    /** The currently authenticated user. */
    public function me(Request $request)
    {
        $user = $request->user();

        /*
         * SOFTWARE SUPER ADMIN EXCLUSION (layer 2 of 3).
         *
         * A token minted BEFORE the login guard existed (or by another client)
         * must not become a working mobile session, so we re-assert the rule on
         * every identity read. The client treats a 403 here as "clear the token
         * and return to login", never as a transient failure.
         */
        if ($user->isSuperAdmin()) {
            return response()->json(['message' => self::SSA_MOBILE_REFUSAL], 403);
        }

        return response()->json(['data' => $this->userPayload($user)]);
    }

    /** Revoke the calling token. */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /** Re-issue a token for a recognised device (kept for client convenience). */
    public function registerDevice(Request $request)
    {
        $data = $request->validate([
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $token = $request->user()->createToken($data['device_name'])->plainTextToken;

        return response()->json(['data' => ['token' => $token, 'token_type' => 'Bearer']], 201);
    }

    /** Update the signed-in user's own profile. */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'designation' => ['nullable', 'string', 'max:120'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('avatar')) {
            $data['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
        }

        // `avatar` is the uploaded file (handled above), not a column.
        unset($data['avatar']);
        $user->update($data);

        return response()->json(['data' => $this->userPayload($user->fresh())]);
    }

    /**
     * Public metadata: what the client needs to render itself correctly.
     * Currency, terminology and enum lists live here so the mobile app never
     * hard-codes them.
     */
    public function meta()
    {
        $institution = Institution::current();

        return response()->json([
            'data' => [
                'app_name' => config('app.name'),
                'institution' => $institution ? [
                    'id' => $institution->id,
                    'name' => $institution->name,
                    'subtitle' => $institution->subtitle,
                    'type' => $institution->type,
                    'type_label' => $institution->typeLabel(),
                    'logo_url' => $institution->logoUrl(),
                    'terms' => $institution->terminologyMap(),
                    'theme' => $institution->themeSettings(),
                ] : null,
                'currency' => $institution?->currencySettings()
                    ?? Institution::DEFAULT_CURRENCY_SETTINGS,
                'subsidy_modes' => Subsidy::APPLY_MODES,
                'subsidy_sources' => Subsidy::SOURCES,
                'deposit_kinds' => Deposit::KINDS,
                'api_version' => '1.0.0',
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Consistent user representation across every auth endpoint.
     *
     * Delegates to the shared UserResource so the user wire-format is defined
     * in exactly ONE place - a change there updates login, register, me and
     * profile together, and the mobile contract cannot drift between them.
     * `resolve()` returns the plain array so it can be nested under `data`.
     */
    protected function userPayload(User $user): array
    {
        return (new UserResource($user))->resolve();
    }
}
