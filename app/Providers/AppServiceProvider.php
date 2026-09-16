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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Every model carrying business meaning is audited. `Student` is the
        // member record; `User` covers account changes and invitations.
        foreach ([Student::class, User::class, Deposit::class, Subsidy::class, SubsidySource::class, MealRateSetting::class, Vendor::class, Department::class, MealEntry::class, MealExpense::class, Transaction::class] as $model) {
            $model::observe(RecordActivity::class);
        }

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
