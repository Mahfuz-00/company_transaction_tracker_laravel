<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PasswordGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The ONLY sanctioned way to reset a Software Super Admin password from the CLI.
 *
 * WHY A DEDICATED COMMAND
 * -----------------------
 * The credential-integrity bug was caused by ad-hoc scripts writing the SSA
 * password column directly. This command makes the authorised path explicit and
 * unambiguous:
 *   - it refuses to touch a non-SSA account (use the admin UI for those),
 *   - it generates a strong password unless one is supplied,
 *   - it routes through PasswordGuard, so the change is audited and
 *     `password_changed_at` is stamped,
 *   - it forces a change on next login when the operator wants a one-time
 *     handoff password.
 *
 *     php artisan ssa:reset-password mahfuz@example.com
 *     php artisan ssa:reset-password mahfuz@example.com --password="..." --force-change
 */
class ResetSuperAdminPassword extends Command
{
    protected $signature = 'ssa:reset-password
        {email : The Super Admin account email}
        {--password= : Supply a specific password instead of generating one}
        {--force-change : Require the admin to change it again on next login}';

    protected $description = 'Reset a Software Super Admin password through the authorised, audited path.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        if (! $user->isSuperAdmin()) {
            $this->error("{$email} is not a Software Super Admin. Use the User Manager for other accounts.");

            return self::FAILURE;
        }

        $plain = (string) ($this->option('password') ?: Str::password(16));

        if (strlen($plain) < 8) {
            $this->error('The password must be at least 8 characters.');

            return self::FAILURE;
        }

        // AUTHORISED write: forceSsa = true, so the model safety net allows it.
        PasswordGuard::changePassword($user, $plain, 'cli_reset', forceSsa: true);

        // Optionally require a change on next login for a one-time handoff.
        if ($this->option('force-change')) {
            $user->forceFill(['must_change_password' => true])->save();
        }

        $this->info("Password reset for {$email}.");
        $this->line("  New password: {$plain}");

        if ($this->option('force-change')) {
            $this->comment('  The admin will be required to change it on next login.');
        }

        return self::SUCCESS;
    }
}
