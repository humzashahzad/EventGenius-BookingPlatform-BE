<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Admin user (for first-time setup; change password in production)
        if (!User::where('email', 'admin@example.com')->exists()) {
            User::factory()->create([
                'name'     => 'Admin',
                'email'    => 'admin@example.com',
                'password' => Hash::make('Admin@123'),
                'role'     => 'admin',
                'is_active'=> true,
            ]);
        }

        // Optional: test client
        if (!User::where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name'  => 'Test User',
                'email' => 'test@example.com',
                'role'  => 'client',
            ]);
        }
    }
}
