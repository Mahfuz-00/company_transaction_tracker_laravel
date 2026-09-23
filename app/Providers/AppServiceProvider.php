<?php

namespace App\Providers;

use App\Models\Department;
use App\Models\Deposit;
use App\Models\Institution;
use App\Models\MealEntry;
use App\Models\MealExpense;
use App\Models\MealRateSetting;
use App\Models\Student;
use App\Models\Subsidy;
use App\Models\SubsidySource;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Support\RecordActivity;
use App\Support\TenantManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The tenant resolver is a per-request singleton: middleware forces the
        // validated tenant onto it once, and every tenant-owned model's global
        // scope reads it back. Sharing one instance keeps the resolve cheap and
        // makes the active tenant consistent everywhere in the request.
        $this->app->singleton(TenantManager::class, fn () => new TenantManager());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * API RATE LIMITERS (mobile client).
         *
         * The framework's default `api` middleware group carries NO throttle, so
         * /api/auth/login was an unthrottled brute-force target (the web login,
         * by contrast, is limited to 6/min). Two named limiters close that gap;
         * they are attached in bootstrap/app.php (group-wide) and routes/api.php
         * (auth routes).
         *
         *   api        - a baseline for every API request: 60/min, keyed per
         *                authenticated user (falling back to IP for the public
         *                endpoints), so one abusive client cannot exhaust the API.
         *   api-login  - a strict 5/min keyed per email + IP, so credential
         *                guessing is slowed WITHOUT letting one attacker lock out
         *                a different email from the same address.
         */
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('api-login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')) . '|' . $request->ip()));

        Vite::prefetch(concurrency: 3);

        // Every model carrying business meaning is audited. `Student` is the
        // member record; `User` covers account changes and invitations.
        foreach ([Student::class, User::class, Deposit::class, Subsidy::class, SubsidySource::class, MealRateSetting::class, Vendor::class, Department::class, MealEntry::class, MealExpense::class, Transaction::class] as $model) {
            $model::observe(RecordActivity::class);
        }

        /*
         * Outbox: record every dispatched email (Mailables and Notifications).
         *
         * IMPORTANT: this listener is ALREADY auto-discovered by Laravel from
         * app/Listeners (its handle() type-hints MessageSent). Registering it
         * here as well made it fire TWICE per email, writing two identical
         * outbox rows - the duplicate-log bug. We therefore do NOT call
         * Event::listen() for it; discovery is the single registration path.
         */

        // A Super Admin passes every permission check; Institution Admins are
        // gated by the permissions granted to their role.
        Gate::before(function (User $user, string $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }

            return null;
        });
    }
}
