<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Enums\UserStatus;
use App\Models\User;
use App\Models\Role;
use RuntimeException;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminRole = Role::where('slug', 'super_admin')->first();
        $userRole = Role::where('slug', 'user')->first();

        if (! $adminRole) {
            throw new RuntimeException(
                'Super Administrator role not found. Run RoleSeeder first.'
            );
        }

        if (! $userRole) {
            throw new RuntimeException(
                'User role not found. Run RoleSeeder first.'
            );
        }

        $users = [
            [
                'name' => 'Cellexa Admin',
                'email' => 'admin@cellexa.com',
                'password' => 'Password@1',
                'role_id' => $adminRole->id,
                'status' => UserStatus::Active,
            ],
            [
                'name' => 'Cellexa User',
                'email' => 'user@cellexa.com',
                'password' => 'Password@1',
                'role_id' => $userRole->id,
                'status' => UserStatus::Active,
            ],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                $user
            );
        }
    }
}
