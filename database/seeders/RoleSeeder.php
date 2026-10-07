<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'Super Administrator',
                'slug' => 'super_admin',
                'description' => 'Controls all administration and portal resources.',
                'is_default' => false,
                'is_system' => true,
            ],
            [
                'name' => 'Administrator',
                'slug' => 'admin',
                'description' => 'Manages customers, users, plans and subscriptions.',
                'is_default' => false,
                'is_system' => true,
            ],
            [
                'name' => 'User',
                'slug' => 'user',
                'description' => 'Accesses their own profile and subscription.',
                'is_default' => true,
                'is_system' => true,
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['slug' => $role['slug']],
                $role,
            );
        }
    }
}
