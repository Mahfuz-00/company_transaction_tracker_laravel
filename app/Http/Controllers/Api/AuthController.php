<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
 * Tokens are Sanctum personal access tokens. The client stores the plain-text
 * token and sends it as `Authorization: Bearer <token>`.
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

    /** Consistent user representation across every auth endpoint. */
    protected function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'designation' => $user->designation,
            'avatar_url' => $user->avatarUrl(),
            'status' => $user->status,
            'institution_id' => $user->institution_id,
            'roles' => $user->getRoleNames()->toArray(),
            'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
            'is_super_admin' => $user->isSuperAdmin(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }
}
