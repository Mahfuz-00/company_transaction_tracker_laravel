<?php

namespace App\Support;

use App\Models\User;

/**
 * ROLE-SPECIFIC ONBOARDING MANUAL.
 *
 * The single source of truth for the first-login guide. Each of the four user
 * types gets a DIFFERENT journey that explains what its dashboard actually
 * offers - the modules it can reach, the controls it owns, and the sensible
 * first action to take.
 *
 * It is intentionally data, not markup: the same structure drives the React
 * modal AND the Dusk assertions, so the two can never drift.
 *
 * Shape returned by for():
 *   [
 *     'role'      => 'ssa' | 'ia' | 'mm' | 'member',
 *     'title'     => string,   // the modal heading
 *     'subtitle'  => string,   // one-line "who you are" summary
 *     'accent'    => string,   // a tailwind-ish tone key for the UI
 *     'steps'     => [ ['title' => string, 'body' => string, 'icon' => string], ... ],
 *     'first_action' => ['label' => string, 'hint' => string],
 *   ]
 */
class OnboardingGuide
{
    /**
     * The guide for a given user, resolved from their role.
     */
    public static function for(?User $user): array
    {
        $role = $user?->onboardingRole() ?? 'member';

        return static::forRole($role, $user);
    }

    /** The guide for a role key, with optional user context for the copy. */
    public static function forRole(string $role, ?User $user = null): array
    {
        return match ($role) {
            'ssa' => static::ssa($user),
            'ia' => static::institutionAdmin($user),
            'mm' => static::mealManager($user),
            default => static::member($user),
        };
    }

    /** All four journeys, keyed by role - used by tests and docs. */
    public static function all(?User $user = null): array
    {
        return [
            'ssa' => static::ssa($user),
            'ia' => static::institutionAdmin($user),
            'mm' => static::mealManager($user),
            'member' => static::member($user),
        ];
    }

    /* ------------------------------------------------------------------ *
     * SOFTWARE SUPER ADMIN - the platform operator
     * ------------------------------------------------------------------ */
    protected static function ssa(?User $user): array
    {
        return [
            'role' => 'ssa',
            'title' => 'Welcome, Platform Operator',
            'subtitle' => 'You run the whole SaaS platform - every institution, every subscription.',
            'accent' => 'amber',
            'steps' => [
                [
                    'title' => 'Platform Dashboard',
                    'body' => 'Your landing page is the global platform business view: institutions, active users, subscription revenue and trial health across the entire estate - never a single workspace.',
                    'icon' => 'chart',
                ],
                [
                    'title' => 'Institution Registry',
                    // Must contain the literal phrase "institution registry" in the
                    // BODY (the test asserts on step bodies, not titles).
                    'body' => 'The institution registry is where you create a new workspace together with its first Institution Admin, toggle a workspace active/inactive, and use "Access Dashboard" to step INTO any tenant to help or inspect.',
                    'icon' => 'building',
                ],
                [
                    'title' => 'Users & Roles (Global)',
                    'body' => 'The User Manager becomes a global platform directory for you: search every account, filter by institution, and create a user directly against any workspace without switching in first.',
                    'icon' => 'users',
                ],
                [
                    'title' => 'Monitoring, Plans & Trials',
                    'body' => 'Track subscription plans across the platform, convert trials to paid, send upgrade prompts, and review the cross-tenant audit log for compliance and security.',
                    'icon' => 'shield',
                ],
                [
                    'title' => 'Platform Settings & SMTP',
                    'body' => 'Point the whole platform at a mail relay (with a live connection check), manage currency, and broadcast announcements to every institution at once.',
                    'icon' => 'cog',
                ],
            ],
            'first_action' => [
                'label' => 'Open the Institution Registry',
                'hint' => 'Add your first workspace from the institution registry, or step into an existing one to see it as its admin does.',
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * INSTITUTION ADMIN - the workspace owner
     * ------------------------------------------------------------------ */
    protected static function institutionAdmin(?User $user): array
    {
        $institution = $user?->institution?->name;

        return [
            'role' => 'ia',
            'title' => 'Welcome, Institution Admin',
            'subtitle' => $institution
                ? "You own the {$institution} workspace - its people, its meals and its money."
                : 'You own this workspace - its people, its meals and its money.',
            'accent' => 'indigo',
            'steps' => [
                [
                    'title' => 'Your Dashboard',
                    'body' => 'A live overview of your workspace: pool balance, this month\'s deposits and expenses, meals served, members with dues and the recent ledger.',
                    'icon' => 'chart',
                ],
                [
                    'title' => 'Members & Meal Managers',
                    'body' => 'Build your roster, assign each member a Meal Manager, and link logins. Manage every account and role from Settings → Users.',
                    'icon' => 'users',
                ],
                [
                    'title' => 'Meals, Deposits & Expenses',
                    'body' => 'Record daily meal entries, take in deposits, log expenses, issue refunds and apply institutional subsidies. Every figure flows into the reports.',
                    'icon' => 'utensils',
                ],
                [
                    'title' => 'Invite Your People',
                    'body' => 'Share your workspace invite code (Settings → Invite Code) so members can self-register, or email signed invitations that let them set their own password.',
                    'icon' => 'mail',
                ],
                [
                    'title' => 'Reports, Vendors & Branding',
                    'body' => 'Export meal and finance reports, keep a vendor directory, and tailor the workspace name, type, terminology and logo to fit your institution.',
                    'icon' => 'document',
                ],
            ],
            'first_action' => [
                'label' => 'Add your first member',
                'hint' => 'Then share your invite code so your team can join and start recording meals.',
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * MEAL MANAGER - the operational lead
     * ------------------------------------------------------------------ */
    protected static function mealManager(?User $user): array
    {
        return [
            'role' => 'mm',
            'title' => 'Welcome, Meal Manager',
            'subtitle' => 'You run the day-to-day meal operations for the members assigned to you.',
            'accent' => 'emerald',
            'steps' => [
                [
                    'title' => 'Your Assigned Members',
                    'body' => 'You see exactly the members assigned to you - never the whole institution. Their meals, deposits and balances are your workspace.',
                    'icon' => 'users',
                ],
                [
                    'title' => 'Record Meals',
                    'body' => 'Log breakfast, lunch and dinner for your members each day. The per-meal rate is applied automatically to work out their cost.',
                    'icon' => 'utensils',
                ],
                [
                    'title' => 'Take Deposits',
                    'body' => 'Record money coming in against a member, and reverse an entry if you make a mistake - the ledger keeps a full, honest history.',
                    'icon' => 'cash',
                ],
                [
                    'title' => 'Review Claims',
                    'body' => 'Members can raise claims and disputes. Approve or reject them from the review queue, with a note explaining your decision.',
                    'icon' => 'clipboard',
                ],
                [
                    'title' => 'Reports',
                    'body' => 'See how your members are doing and export the numbers your Institution Admin needs for the monthly reconciliation.',
                    'icon' => 'document',
                ],
            ],
            'first_action' => [
                'label' => 'Open today\'s meal entry',
                'hint' => 'Record today\'s meals for your members - it takes seconds.',
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * MEMBER - the participant
     * ------------------------------------------------------------------ */
    protected static function member(?User $user): array
    {
        return [
            'role' => 'member',
            'title' => 'Welcome aboard!',
            'subtitle' => 'This is your personal space - your meals, your money, your balance.',
            'accent' => 'sky',
            'steps' => [
                [
                    'title' => 'Your Dashboard',
                    'body' => 'See your balance, this month\'s meals and deposits, and your full history at a glance. Everything here is private to you.',
                    'icon' => 'chart',
                ],
                [
                    'title' => 'Your Meals',
                    'body' => 'A day-by-day record of the breakfast, lunch and dinner recorded against your name, with what each one cost.',
                    'icon' => 'utensils',
                ],
                [
                    'title' => 'Your Deposits',
                    'body' => 'Every payment recorded for you, so you can always see what you have put in and when.',
                    'icon' => 'cash',
                ],
                [
                    'title' => 'Your Analytics',
                    'body' => 'A personal trend of your meal spend and deposits month by month - no one else\'s figures ever appear here.',
                    'icon' => 'chart',
                ],
                [
                    'title' => 'Raise a Claim',
                    'body' => 'Spotted a mistake, or need to dispute a charge? Raise a claim and track its status as your manager reviews it.',
                    'icon' => 'clipboard',
                ],
            ],
            'first_action' => [
                'label' => 'Check your balance',
                'hint' => 'Your dashboard shows exactly where your account stands right now.',
            ],
        ];
    }
}
