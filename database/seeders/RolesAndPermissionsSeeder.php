<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\User;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run()
    {
        // Define permissions grouped by module.
        // These names must match the route middleware in routes/web.php and
        // the `permission` keys in resources/js/Utils/navItems.js.
        $definitions = [
            'transactions' => [
                'transactions.view', 'transactions.create', 'transactions.edit', 'transactions.delete'
            ],
            'meals' => [
                'meals.view', 'meals.entry', 'meals.manage',
                'meals.deposit', 'meals.expense', 'meals.reports',
            ],
            'students' => [
                'students.view', 'students.manage', 'students.invite',
            ],
            'departments' => [
                'departments.view', 'departments.manage',
            ],
            'vendors' => [
                'vendors.view', 'vendors.manage',
            ],
            'subsidies' => [
                'subsidies.view', 'subsidies.manage',
            ],
            'exports' => [
                'exports.download',
            ],
            'institution' => [
                'institution.view', 'institution.manage',
            ],
            'users' => [
                'users.view', 'users.create', 'users.edit', 'users.delete'
            ],
            'roles' => [
                'roles.view', 'roles.manage'
            ],
            // Audit trail visibility. Institution Admins get this scoped to
            // their own institution; Super Admins see everything.
            'audit' => [
                'audit.view',
            ],
            // Appearance + branding (logo, avatar, theme).
            'appearance' => [
                'appearance.view', 'appearance.manage',
            ],
            // The super-admin institution registry.
            'institutions' => [
                'institutions.view', 'institutions.manage',
            ],
            // SSA business monitoring: the cross-tenant control tower. These are
            // granted to the GLOBAL role ONLY - no institution-scoped role may
            // ever see platform-wide revenue/health (see the tier notes below).
            'monitoring' => [
                'monitoring.view', 'monitoring.manage',
            ],
            // Member claims & disputes. Members raise them (claims.submit);
            // managers review them (claims.review).
            'claims' => [
                'claims.view', 'claims.submit', 'claims.review',
            ],
            // In-app notifications. Everyone can view their own; only admins
            // may broadcast an announcement to the institution.
            'notifications' => [
                'notifications.view', 'notifications.announce',
            ],
            // Email Log / Outbox. Scoped per institution in the controller;
            // SSAs see the global log.
            'emails' => [
                'emails.view',
            ],
            // Currency formatting. A WORKSPACE setting: an Institution Admin
            // manages their own institution's format; the SSA manages the one
            // they have switched into. Individuals (members/managers) can view
            // but never change it.
            'currency' => [
                'currency.view', 'currency.manage',
            ],
            // SSA pricing-tier management (create/edit plan definitions).
            'plans' => [
                'plans.view', 'plans.manage',
            ],
        ];

        $allPermissions = [];

        foreach ($definitions as $module => $perms) {
            foreach ($perms as $perm) {
                $p = Permission::firstOrCreate(['name' => $perm], ['guard_name' => 'web']);
                $p->update(['module' => $module]);
                $allPermissions[] = $p->name;
            }
        }

        /* -------------------------------------------------------------- *
         * THE 3-TIER ACCESS HIERARCHY
         *
         *   Tier 1 - Software Super Admin (GLOBAL)
         *     Platform oversight, institution provisioning, SaaS billing/health
         *     monitoring, cross-tenant dashboard switching. The ONLY role that
         *     holds monitoring.* / institutions.* and the only one whose queries
         *     can run without a tenant scope.
         *
         *   Tier 2 - Institution Admin & Meal Manager (INSTITUTION-SCOPED)
         *     Everything operational WITHIN one institution; zero cross-tenant
         *     reach. They cannot see or hold the global monitoring/registry
         *     permissions, and every query they run is auto-filtered to their
         *     institution_id by the model tenant scope.
         *
         *   Tier 3 - Member (PERSONAL)
         *     Personal dashboard, deposits, meal history, balance, claims. No
         *     administrative module is reachable. Their queries are further
         *     narrowed to their own records in the member controllers.
         * -------------------------------------------------------------- */
        // Exactly four core roles - no legacy aliases. Any account still on a
        // removed role was migrated by the streamline_core_roles migration.
        $super = Role::firstOrCreate(['name' => 'Software Super Admin']);
        $instAdmin = Role::firstOrCreate(['name' => 'Institution Admin']);
        $manager = Role::firstOrCreate(['name' => 'Meal Manager']);
        $member = Role::firstOrCreate(['name' => 'Member']);

        // ---- TIER 1: global role gets EVERYTHING ----------------------------
        $super->syncPermissions($allPermissions);

        // Institution Admin: everything operational, scoped to their body.
        // Deliberately excludes nothing operational - they are the top authority
        // *within* an institution - but they are scoped in the controllers.
        $instAdminPerms = array_merge(
            $definitions['transactions'],
            $definitions['meals'],
            $definitions['students'],
            $definitions['departments'],
            $definitions['vendors'],
            $definitions['subsidies'],
            $definitions['exports'],
            $definitions['audit'],
            $definitions['institution'],
            $definitions['appearance'],
            $definitions['currency'],
            $definitions['claims'],
            // Institution Admins can broadcast announcements to their institution.
            $definitions['notifications'],
            $definitions['emails'],
            ['users.view', 'users.create', 'users.edit', 'roles.view']
        );
        // TIER 2: strictly within ONE institution. Deliberately EXCLUDES
        // monitoring.* and institutions.* (Tier 1 only).
        $instAdmin->syncPermissions($instAdminPerms);

        // Meal Manager runs the mess: transactions + meals + roster + vendors.
        // Deliberately excludes roles.manage and users.create/delete so an
        // admin can still revoke access. Sees subsidy data but does not manage it.
        $managerPerms = array_merge(
            $definitions['transactions'],
            $definitions['meals'],
            $definitions['students'],
            $definitions['departments'],
            $definitions['vendors'],
            $definitions['exports'],
            $definitions['claims'],
            // Managers can view notifications but not broadcast announcements.
            // They also get the (institution-scoped) email outbox.
            ['users.view', 'institution.view', 'subsidies.view', 'audit.view', 'appearance.view', 'notifications.view', 'emails.view']
        );
        $manager->syncPermissions($managerPerms);

        // Member: limited view permissions only - sees their own meals and
        // deposits, nothing administrative.
        // Members can view their own data and raise claims, nothing more.
        $member->syncPermissions(['meals.view', 'transactions.view', 'claims.view', 'claims.submit', 'notifications.view']);

        // Assign the top role to the first seed user (if exists).
        $user = User::first();
        if ($user) {
            if (! $user->hasRole($super->name)) {
                $user->assignRole($super->name);
            }
        }
    }
}
