<?php
namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use App\Services\Auth\AuthService;
use Tests\TestCase;

class RegistrationRoleTest extends TestCase
{
    public function test_new_registrations_use_role_two_without_changing_existing_users(): void
    {
        foreach (glob(database_path('migrations/*.php')) as $path) {
            // The shared catalog's full-text index is unrelated to registration.
            if (str_contains($path, 'create_refurbed_catalog_tables')) continue;
            (require $path)->up();
        }
        $this->assertNotNull(Role::find(2));
        $regular = Role::where('slug', 'user')->firstOrFail();
        Role::query()->update(['is_default' => false]);
        $regular->update(['is_default' => true]);
        $existing = User::factory()->create(['role_id' => $regular->id]);
        $result = app(AuthService::class)->register('New User', 'new-role@example.com', 'Password123');
        $this->assertSame(2, (int) $result['user']->role_id);
        $this->assertSame($regular->id, $existing->fresh()->role_id);
        $this->assertNotEmpty($result['token']);
    }
}
