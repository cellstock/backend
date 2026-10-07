<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        foreach ([
            ['name' => 'Super Administrator', 'slug' => 'super_admin', 'description' => 'Controls all administration and portal resources.', 'is_default' => false],
            ['name' => 'Administrator', 'slug' => 'admin', 'description' => 'Manages customers, users, plans and subscriptions.', 'is_default' => false],
            ['name' => 'User', 'slug' => 'user', 'description' => 'Accesses their own profile and subscription.', 'is_default' => true],
        ] as $role) {
            DB::table('roles')->updateOrInsert(['slug' => $role['slug']], [...$role, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        $userRole = DB::table('roles')->where('slug', 'user')->value('id');
        $legacyIds = DB::table('roles')->whereIn('slug', ['manager', 'staff'])->pluck('id');
        if ($userRole && $legacyIds->isNotEmpty()) DB::table('users')->whereIn('role_id', $legacyIds)->update(['role_id' => $userRole]);

        $superRole = DB::table('roles')->where('slug', 'super_admin')->value('id');
        $primaryAdmin = DB::table('users')->where('email', 'admin@cellexa.com')->first()
            ?? DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')->where('roles.slug', 'admin')->select('users.*')->first();
        if ($superRole && $primaryAdmin) DB::table('users')->where('id', $primaryAdmin->id)->update(['role_id' => $superRole]);

        DB::table('roles')->where('slug', 'user')->update(['is_default' => true]);
        DB::table('roles')->where('slug', '!=', 'user')->update(['is_default' => false]);
    }

    public function down(): void {}
};
