<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\EngineLog;
use App\Support\PasskeyRecovery;
use Illuminate\Console\Command;

/**
 * The way back in for an operator - including the only admin - who has lost their passkeys and
 * has no recovery code left: `php artisan mymate:user:reset-passkeys someone@example.com`. Needs a
 * shell on the server, which is the point: it's the last resort.
 */
class ResetPasskeysCommand extends Command
{
    protected $signature = 'mymate:user:reset-passkeys {email : The operator email} {--force : Don\'t ask for confirmation}';

    protected $description = 'Remove an operator\'s passkeys and recovery codes so they can sign in with their password and set up new ones';

    public function handle(PasskeyRecovery $recovery): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if ($user === null) {
            $this->error("No operator found with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $passkeys = $user->passkeys()->pluck('name');
        if ($passkeys->isEmpty() && $recovery->remaining($user) === 0) {
            $this->info("{$user->email} has no passkeys or recovery codes. Nothing to do.");

            return self::SUCCESS;
        }

        $this->line("{$user->email} has ".$passkeys->count().' passkey(s): '.($passkeys->implode(', ') ?: 'none'));
        if (! $this->option('force') && ! $this->confirm('Remove them, and any recovery codes?')) {
            return self::FAILURE;
        }

        $removed = $recovery->reset($user);
        EngineLog::warning('auth: operator passkeys reset from the command line', ['user_id' => $user->id, 'removed' => $removed]);
        $this->info("Removed {$removed} passkey(s). {$user->email} can now sign in with their password and set up a new passkey.");

        return self::SUCCESS;
    }
}
