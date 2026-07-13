<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grant (or revoke) admin rights on an existing operator by email:
 * `php artisan mymate:user:admin someone@example.com`. Admin = operator-management
 * rights; `is_admin` is not mass-assignable, so this is the supported way to flip it
 * from the CLI. Use `--revoke` to demote back to a normal (view-only) operator.
 */
class MakeAdminCommand extends Command
{
    protected $signature = 'mymate:user:admin {email : The operator email} {--revoke : Remove admin rights instead of granting them}';

    protected $description = 'Grant or revoke admin rights on an existing operator';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No operator found with email {$email}.");

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');

        if ($user->is_admin === $grant) {
            $this->info("{$email} is already ".($grant ? 'an admin' : 'a normal operator').'. Nothing to do.');

            return self::SUCCESS;
        }

        $user->is_admin = $grant; // explicit - is_admin is not fillable
        $user->save();

        $this->info($grant ? "{$email} is now an admin." : "{$email} is now a normal operator.");

        return self::SUCCESS;
    }
}
