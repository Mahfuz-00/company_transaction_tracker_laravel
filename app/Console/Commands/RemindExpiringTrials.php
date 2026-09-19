<?php

namespace App\Console\Commands;

use App\Mail\TrialReminderMail;
use App\Models\Institution;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Automated 7-day trial reminders.
 *
 * Run on a schedule (see routes/console.php). For every institution on a trial
 * it decides whether a reminder is due, and sends ONE email per window so an
 * admin never gets spammed:
 *
 *   - 2 days or fewer left      -> "ending" reminder
 *   - trial has just expired     -> "expired" reminder
 *
 * A reminder is suppressed if one was already sent within the last 20 hours,
 * which makes the command safe to run hourly (or even more often).
 *
 *     php artisan trials:remind           # send what is due
 *     php artisan trials:remind --dry-run  # report without sending
 */
class RemindExpiringTrials extends Command
{
    protected $signature = 'trials:remind {--dry-run : List what would be sent without sending}';

    protected $description = 'Send upgrade reminders to institutions whose free trial is ending or has ended.';

    /** How long before we allow another reminder for the same institution. */
    protected const COOLDOWN_HOURS = 20;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;
        $skipped = 0;

        // Cross-tenant sweep: this command deliberately reads every institution.
        app(TenantManager::class)->runGlobally(function () use (&$sent, &$skipped, $dryRun) {
            $trials = Institution::query()
                ->where('onboarding_mode', 'trial')
                ->whereNull('converted_at')
                ->whereNotNull('trial_ends_at')
                ->get();

            foreach ($trials as $institution) {
                $state = $this->dueState($institution);

                if ($state === null) {
                    continue;
                }

                if ($this->recentlyReminded($institution)) {
                    $skipped++;

                    continue;
                }

                $admin = $this->adminFor($institution);

                if (! $admin) {
                    $this->warn("  ! {$institution->name}: no admin to email");

                    continue;
                }

                if ($dryRun) {
                    $this->line("  would email {$admin->email} ({$state}) for {$institution->name}");
                    $sent++;

                    continue;
                }

                try {
                    Mail::to($admin->email)->send(new TrialReminderMail($institution, $admin, $state));
                } catch (\Throwable $e) {
                    report($e);
                    $this->error("  x failed for {$institution->name}: {$e->getMessage()}");

                    continue;
                }

                $institution->forceFill(['trial_reminder_sent_at' => now()])->save();

                AuditLogger::log('updated', "auto-sent a {$state} trial reminder to {$admin->email}", $institution, [
                    'state' => $state,
                    'days_left' => $institution->trialDaysLeft(),
                    'automated' => true,
                ], ['subject_label' => $institution->name, 'institution_id' => $institution->id]);

                $this->info("  -> {$state} reminder sent to {$admin->email} ({$institution->name})");
                $sent++;
            }
        });

        $this->info($dryRun
            ? "Dry run: {$sent} reminder(s) would be sent, {$skipped} skipped (cooldown)."
            : "Trial reminders: {$sent} sent, {$skipped} skipped (cooldown).");

        return self::SUCCESS;
    }

    /**
     * Which reminder (if any) is due for this institution right now.
     */
    protected function dueState(Institution $institution): ?string
    {
        // Already converted or not a trial: nothing to do.
        if ($institution->converted_at !== null || $institution->onboarding_mode !== 'trial') {
            return null;
        }

        $endsAt = $institution->trial_ends_at;

        if ($endsAt === null) {
            return null;
        }

        if ($endsAt->isPast()) {
            return 'expired';
        }

        // Remind once the window is inside the final 2 days.
        return $institution->trialDaysLeft() <= 2 ? 'ending' : null;
    }

    /** Has a reminder gone out within the cooldown window? */
    protected function recentlyReminded(Institution $institution): bool
    {
        $last = $institution->trial_reminder_sent_at;

        return $last !== null && $last->gt(now()->subHours(self::COOLDOWN_HOURS));
    }

    protected function adminFor(Institution $institution): ?User
    {
        return User::query()
            ->where('institution_id', $institution->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Institution Admin'))
            ->orderBy('id')
            ->first()
            ?? User::query()->where('institution_id', $institution->id)->orderBy('id')->first();
    }
}
