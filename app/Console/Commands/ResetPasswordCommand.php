<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\EngineLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Set a new password for an operator from the server: `php artisan mymate:user:password
 * someone@example.com`. The way back in when the only admin has forgotten theirs (anyone else can
 * be reset by an admin under Settings). Same rules as the app, asked twice, never on the command
 * line where it would land in shell history.
 */
class ResetPasswordCommand extends Command
{
    protected $signature = 'mymate:user:password {email : The operator email}';

    protected $description = 'Set a new password for an operator';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if ($user === null) {
            $this->error("No operator found with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $password = $this->secret('New password') ?: '';
        $confirmation = $this->secret('Confirm new password') ?: '';

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()]],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user->password = $password; // 'hashed' cast
        // Sign out "remember me" logins made with the old password.
        $user->setRememberToken(Str::random(60));
        $user->save();

        EngineLog::warning('auth: operator password reset from the command line', ['user_id' => $user->id]);
        $this->info("Password updated for {$user->email}.");

        return self::SUCCESS;
    }
}
