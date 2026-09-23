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

    /** Self-registration. New accounts get the Member role. */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $institution = Institution::current();

        $user = User::create([
            'institution_id' => $institution?->id,
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
        return response()->json(['data' => $this->userPayload($request->user())]);
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
