<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RegisteredUserController extends Controller
{
    /**
     * Role granted to every self-registered account.
     * Override with REGISTRATION_DEFAULT_ROLE in .env.
     */
    protected function defaultRole(): string
    {
        return config('permission.registration_default_role', 'Student');
    }

    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register', [
            'defaultRole' => $this->defaultRole(),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'status' => 'active',
            'password' => Hash::make($request->password),
        ]);

        // Assign the default role so new accounts get baseline access.
        $role = $this->defaultRole();
        if (Role::where('name', $role)->exists()) {
            $user->assignRole($role);
        }

        event(new Registered($user));

        Auth::login($user);

        // New self-registered users land on the home/dashboard area.
        return redirect(route('dashboard', absolute: false));
    }
}
