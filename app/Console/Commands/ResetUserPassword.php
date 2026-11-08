<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ResetUserPassword extends Command
{
    protected $signature = 'user:reset-password';

    protected $description = 'Reset the password for an existing local user';

    public function handle(): int
    {
        $identifier = trim((string) $this->ask('User email or ID'));

        if ($identifier === '') {
            $this->error('A user email or ID is required.');

            return self::FAILURE;
        }

        $user = ctype_digit($identifier)
            ? User::query()->find((int) $identifier)
            : User::query()->where('email', $identifier)->first();

        if ($user === null) {
            $this->error('No existing user matches that email or ID.');

            return self::FAILURE;
        }

        $password = $this->secret('New password');

        if ($password === null || mb_strlen($password) < 12) {
            $this->error('Password must be at least 12 characters. No changes were made.');

            return self::FAILURE;
        }

        $confirmation = $this->secret('Confirm new password');

        if ($confirmation === null || ! hash_equals($password, $confirmation)) {
            $this->error('Password confirmation did not match. No changes were made.');

            return self::FAILURE;
        }

        DB::table('users')
            ->where('id', $user->getKey())
            ->update(['password' => Hash::make($password)]);

        $this->info('Password updated for the selected user.');

        return self::SUCCESS;
    }
}
