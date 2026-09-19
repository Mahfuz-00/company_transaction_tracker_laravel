<?php

namespace App\Http\Controllers;

use App\Mail\TrialReminderMail;
use App\Models\Institution;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Software Super Admin only: Trial & Subscription Management.
 *
 * Tracks every institution's onboarding mode (7-day free trial vs immediate
 * permanent subscription), shows a live expiry countdown for trial tenants, and
 * lets the SSA send an upgrade prompt - manually here, or automatically via the
 * `trials:remind` scheduled command.
 *
 * TENANCY: like the rest of the SSA area, every read runs inside
 * `TenantManager::runGlobally()` so it can see across all tenants.
 */
class TrialManagementController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Software Super Admin only.');

        $filter = (string) $request->query('filter', 'all');

        $manager = app(TenantManager::class);

        return Inertia::render('Settings/TrialManagement', $manager->runGlobally(function () use ($filter) {
            $all = Institution::query()
                ->withCount('students as members_count')
                ->orderBy('trial_ends_at')
                ->get();

            $rows = $all
                // Apply the tab filter (all | trial | ending | expired | subscribed).
                ->filter(function (Institution $i) use ($filter) {
                    return match ($filter) {
                        'trial' => $i->isOnTrial(),
                        'ending' => $i->isOnTrial() && ($i->trialDaysLeft() ?? 99) <= 2,
                        'expired' => $i->trialExpired(),
                        'subscribed' => $i->onboarding_mode !== 'trial',
                        default => true,
                    };
                })
                ->values()
                ->map(fn (Institution $i) => $this->present($i));

            return [
                'institutions' => $rows,
                'filter' => $filter,
                'stats' => [
                    'total' => $all->count(),
                    'trialing' => $all->filter(fn ($i) => $i->isOnTrial())->count(),
                    'ending_soon' => $all->filter(fn ($i) => $i->isOnTrial() && ($i->trialDaysLeft() ?? 99) <= 2)->count(),
                    'expired' => $all->filter(fn ($i) => $i->trialExpired())->count(),
                    'subscribed' => $all->filter(fn ($i) => $i->onboarding_mode !== 'trial')->count(),
                ],
                'trialDays' => Institution::TRIAL_DAYS,
            ];
        }));
    }

    /**
     * Send an upgrade prompt to one institution's admin. Used by the SSA's
     * "Send upgrade prompt" button in the Trial Management table.
     */
    public function sendUpgradePrompt(Request $request, Institution $institution)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $admin = $this->adminFor($institution);

        if (! $admin) {
            return back()->with('error', "No administrator is attached to \"{$institution->name}\".");
        }

        $state = $institution->trialExpired() ? 'expired' : 'ending';

        $this->dispatchReminder($institution, $admin, $state);

        return back()->with('success', "Upgrade prompt sent to {$admin->email}.");
    }

    /**
     * Convert a trial to a permanent subscription (e.g. once payment is
     * confirmed offline). Keeps all historical data intact.
     */
    public function convert(Request $request, Institution $institution)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $request->validate([
            'subscription_plan' => ['nullable', 'string', 'max:40'],
            'subscription_amount' => ['nullable', 'numeric', 'min:0'],
            'renews_at' => ['nullable', 'date'],
        ]);

        $institution->convertToSubscription([
            'subscription_plan' => $data['subscription_plan'] ?? $institution->subscription_plan,
            'subscription_amount' => $data['subscription_amount'] ?? $institution->subscription_amount,
            'subscription_renews_at' => $data['renews_at'] ?? now()->addMonth()->toDateString(),
        ]);

        AuditLogger::log('updated', "converted \"{$institution->name}\" to a permanent subscription", $institution, [
            'plan' => $institution->subscription_plan,
            'amount' => (float) $institution->subscription_amount,
        ], ['subject_label' => $institution->name, 'institution_id' => $institution->id]);

        return back()->with('success', "\"{$institution->name}\" is now on a permanent subscription.");
    }

    /**
     * Extend a trial window (a courtesy extension the SSA can grant).
     */
    public function extendTrial(Request $request, Institution $institution)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        // Extend from the later of now or the current end, so extending an
        // already-expired trial still moves it forward usefully.
        $base = $institution->trial_ends_at && $institution->trial_ends_at->isFuture()
            ? $institution->trial_ends_at
            : now();

        $institution->forceFill([
            'onboarding_mode' => 'trial',
            'subscription_status' => 'trial',
            'trial_ends_at' => $base->copy()->addDays((int) $data['days']),
            'trial_started_at' => $institution->trial_started_at ?? now(),
            // Allow a fresh reminder for the extended window.
            'trial_reminder_sent_at' => null,
        ])->save();

        AuditLogger::log('updated', "extended the trial for \"{$institution->name}\" by {$data['days']} days", $institution, [
            'days' => (int) $data['days'],
            'new_end' => $institution->trial_ends_at?->toDateTimeString(),
        ], ['subject_label' => $institution->name, 'institution_id' => $institution->id]);

        return back()->with('success', "Trial extended by {$data['days']} days.");
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /** The institution's admin (falls back to any admin-ish user, then SSA-free). */
    protected function adminFor(Institution $institution): ?User
    {
        return User::query()
            ->where('institution_id', $institution->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Institution Admin'))
            ->orderBy('id')
            ->first()
            ?? User::query()->where('institution_id', $institution->id)->orderBy('id')->first();
    }

    /** Send the reminder and stamp the send time (so automation never spams). */
    protected function dispatchReminder(Institution $institution, User $admin, string $state): void
    {
        try {
            Mail::to($admin->email)->send(new TrialReminderMail($institution, $admin, $state));
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        $institution->forceFill(['trial_reminder_sent_at' => now()])->save();

        AuditLogger::log('updated', "sent a {$state} trial reminder to {$admin->email}", $institution, [
            'state' => $state,
        ], ['subject_label' => $institution->name, 'institution_id' => $institution->id]);
    }

    /** Serialise an institution for the Trial Management table. */
    protected function present(Institution $i): array
    {
        $state = $i->trialState();

        return [
            'id' => $i->id,
            'name' => $i->name,
            'slug' => $i->slug,
            'logo_url' => $i->logoUrl(),
            'type_label' => $i->typeLabel(),
            'onboarding_mode' => $i->onboarding_mode,
            'subscription_status' => $i->subscription_status,
            'subscription_label' => $i->subscriptionLabel(),
            'subscription_tone' => $i->subscriptionTone(),
            'subscription_amount' => (float) $i->subscription_amount,
            'subscription_plan' => $i->subscription_plan,
            'members_count' => $i->members_count,
            'trial_state' => $state,
            'trial_started_at' => $i->trial_started_at?->format('j M Y'),
            'trial_ends_at' => $i->trial_ends_at?->format('j M Y'),
            'trial_ends_human' => $i->trial_ends_at?->diffForHumans(),
            'trial_days_left' => $i->trialDaysLeft(),
            'reminder_sent_at' => $i->trial_reminder_sent_at?->diffForHumans(),
            'admin_email' => $this->adminFor($i)?->email,
            'is_active' => (bool) $i->is_active,
        ];
    }
}
