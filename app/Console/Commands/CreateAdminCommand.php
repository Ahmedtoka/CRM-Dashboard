<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Creates (or promotes) the first admin on a fresh production copy, where the
 * demo seeder never runs. The password is prompted, never passed as an argument,
 * so it does not end up in shell history.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'crm:create-admin {email} {--name=Admin}';

    protected $description = 'Create an admin user (or make an existing user an active admin)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => 'required|email'])->fails()) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (min 8 characters)');
        $repeat = (string) $this->secret('Repeat password');

        if (mb_strlen($password) < 8 || $password !== $repeat) {
            $this->error('Passwords must match and be at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->fill([
            'name' => $user->exists ? $user->name : (string) $this->option('name'),
            'password' => $password,
            'role' => UserRole::Admin,
            'is_active' => true,
        ])->save();

        $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated').' admin '.$email);

        return self::SUCCESS;
    }
}
