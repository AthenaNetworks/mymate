<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Create an operator account. There is no public registration - this is
 * how the first/any operator is added: `php artisan mymate:user:create`.
 *
 * : pass `--admin` to grant operator-management rights. The very first account
 * on an empty table is *always* an admin, so a fresh install is never left adminless
 * (an adminless system can never add anyone from the UI).
 */
class CreateUserCommand extends Command
{
    protected $signature = 'mymate:user:create {email?} {name?} {--admin : Grant operator-management (admin) rights}';

    protected $description = 'Create an operator login (no public registration)';

    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('Email');
        $name = $this->argument('name') ?: $this->ask('Name', strtok($email, '@'));
        $password = $this->secret('Password') ?: '';

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // First-ever account is always an admin; otherwise honour --admin.
        $isAdmin = $this->option('admin') || User::query()->doesntExist();

        $user = new User;
        $user->email = $email;
        $user->name = $name;
        $user->password = $password; // 'hashed' cast
        $user->is_admin = $isAdmin; // explicit - is_admin is not fillable
        $user->save();

        $this->info('Operator created: '.$email.($isAdmin ? ' (admin)' : ''));

        return self::SUCCESS;
    }
}
