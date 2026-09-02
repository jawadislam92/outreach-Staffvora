<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local-development seed only: one admin login. Production admins are created
 * with `php artisan outreach:admin you@example.com --name="Your Name"`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => env('SEED_ADMIN_EMAIL', 'admin@outreach.test')],
            [
                'name' => env('SEED_ADMIN_NAME', 'Admin'),
                'password' => env('SEED_ADMIN_PASSWORD', 'password'),
                'role' => Role::Admin,
                'is_active' => true,
            ],
        );
    }
}
