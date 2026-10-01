<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class CreateInitialAdmin extends Command
{
    protected $signature = 'app:create-initial-admin';

    protected $description = 'Interactively create the first administrator; never reset an existing account';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Interactive input is required.');

            return self::FAILURE;
        }

        if (User::query()->where('role', User::ROLE_ADMIN)->exists()) {
            $this->error('An administrator already exists; use account management.');

            return self::FAILURE;
        }

        $data = [
            'name' => $this->ask('Name'),
            'email' => strtolower(trim((string) $this->ask('Email'))),
            'password' => $this->secret('Password (at least 12 characters)'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        unset($data['password_confirmation']);

        // Lock only the write phase, so interactive input cannot outlive the lease.
        return Cache::store('database')->lock('create-initial-admin', 30)->block(5, function () use ($data) {
            if (User::query()->where('role', User::ROLE_ADMIN)->exists()) {
                $this->error('An administrator already exists; use account management.');

                return self::FAILURE;
            }
            User::query()->create([...$data, 'role' => User::ROLE_ADMIN, 'is_admin' => true,
                'is_active' => true, 'must_change_password' => true]);
            $this->info('Administrator created. Password change is required at first login.');

            return self::SUCCESS;
        });
    }
}
