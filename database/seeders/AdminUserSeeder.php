<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@chamaperfumes.ma');
        $password = env('ADMIN_PASSWORD');

        // The seeded admin is a live credential for the whole store, so this
        // never falls back to a default: a default that ends up in a public
        // repository is a published password. Refuse to seed without one.
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException(
                'ADMIN_PASSWORD is not configured. Set it to at least 12 characters (in .env and in the container environment) before seeding the admin user.'
            );
        }

        $admin = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Chamma Admin',
                'password' => Hash::make($password),
                'is_admin' => true,
                'email_verified_at' => now(),
            ],
        );

        $this->command?->info("  Admin: {$email}");
    }
}
