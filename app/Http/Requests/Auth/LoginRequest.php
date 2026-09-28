<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates and performs an email/password sign-in.
 *
 * A FormRequest carries not just the validation rules but the whole login STEP:
 * the controller simply calls `$request->authenticate()` and this class does the
 * rest. Credentials are checked with `Auth::attempt()` and, because a login
 * endpoint is the classic brute-force target, every attempt is funnelled through
 * a rate limiter (see ensureIsNotRateLimited()): five failures from the same
 * email + IP pair trigger a lockout for a cooldown window.
 */
class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Rules are keyed by the input name; both fields are required strings, with
     * the email additionally checked for a valid address shape.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * On success the rate-limit counter is cleared so a legitimate user is not
     * penalised by earlier typos. On failure the counter is hit (bringing the
     * lockout closer) and a validation error is thrown for the form to show.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // `attempt()` hashes the given password and compares it to the stored
        // hash; the `remember` flag toggles the long-lived "remember me" cookie.
        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => $this->failedMessage(),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * The message shown when authentication fails.
     *
     * WHY IT IS NOT ALWAYS `trans('auth.failed')`
     * --------------------------------------------
     * The platform owner account has NO public password-reset route (a reset link
     * would be a takeover vector for the most privileged account). When that
     * account cannot sign in - typically because its credential was never seeded,
     * or was seeded from a bootstrap value that has since changed - a generic
     * "These credentials do not match our records" gives the operator nothing to
     * act on. We detect that one case and point at the audited CLI path instead.
     *
     * The message is DELIBERATELY the same for a wrong password and a missing
     * account (no user enumeration), and it never reveals whether a password is
     * set - it only tells an operator who already knows the account exists.
     */
    protected function failedMessage(): string
    {
        $email = strtolower(trim((string) $this->string('email')));

        if ($email === strtolower(\Database\Seeders\SoftwareSuperAdminSeeder::EMAIL)) {
            return 'Those credentials did not match the platform owner account. '
                . 'If the bootstrap password was changed or never seeded, run: '
                . 'php artisan ssa:reset-password ' . \Database\Seeders\SoftwareSuperAdminSeeder::EMAIL;
        }

        return trans('auth.failed');
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * `tooManyAttempts()` returns true once the throttle key has reached five
     * failures within the decay window. We fire a Lockout event (so listeners can
     * alert) and report the seconds remaining in the error message.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     *
     * The key combines the (lower-cased, transliterated) email with the client
     * IP, so one attacker cannot hammer a single account from many addresses, nor
     * can one address cycle through many accounts.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')) . '|' . $this->ip());
    }
}
