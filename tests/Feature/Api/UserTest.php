<?php

namespace Tests\Feature\Api;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;
    private Role $managerRole;
    private Role $staffRole;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->adminRole = Role::where('slug', 'admin')->firstOrFail();
        $this->managerRole = Role::where('slug', 'manager')->firstOrFail();
        $this->staffRole = Role::where('slug', 'staff')->firstOrFail();

        $this->admin = User::factory()->create([
            'role_id' => $this->adminRole->id,
            'email' => 'admin@cellexa.com',
            'status' => UserStatus::Active,
        ]);
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
    }

    public function test_unauthenticated_user_cannot_access_users(): void
    {
        $response = $this->getJson('/api/users');

        $response
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_non_admin_user_cannot_access_users(): void
    {
        $staff = User::factory()->create([
            'role_id' => $this->staffRole->id,
            'status' => UserStatus::Active,
        ]);

        Sanctum::actingAs($staff, ['*']);

        $response = $this->getJson('/api/users');

        $response
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_list_users(): void
    {
        User::factory()->count(3)->create([
            'role_id' => $this->staffRole->id,
            'status' => UserStatus::Active,
        ]);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/users?per_page=10');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'Users retrieved successfully.'
            )
            ->assertJsonCount(4, 'data')
            ->assertJsonStructure([
                'data',
                'links',
                'meta',
                'success',
                'message',
            ]);
    }

    public function test_users_can_be_searched_by_name(): void
    {
        User::factory()->create([
            'role_id' => $this->staffRole->id,
            'name' => 'Ayesha Khan',
            'email' => 'ayesha@cellexa.com',
            'status' => UserStatus::Active,
        ]);

        User::factory()->create([
            'role_id' => $this->staffRole->id,
            'name' => 'John Smith',
            'email' => 'john@cellexa.com',
            'status' => UserStatus::Active,
        ]);

        $this->actingAsAdmin();

        $response = $this->getJson(
            '/api/users?search=Ayesha'
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.email',
                'ayesha@cellexa.com'
            );
    }

    public function test_users_can_be_filtered_by_role(): void
    {
        User::factory()->create([
            'role_id' => $this->managerRole->id,
            'status' => UserStatus::Active,
        ]);

        User::factory()->create([
            'role_id' => $this->staffRole->id,
            'status' => UserStatus::Active,
        ]);

        $this->actingAsAdmin();

        $response = $this->getJson(
            "/api/users?role_id={$this->managerRole->id}"
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.role.slug',
                'manager'
            );
    }

    public function test_users_can_be_filtered_by_status(): void
    {
        User::factory()->create([
            'role_id' => $this->staffRole->id,
            'status' => UserStatus::Inactive,
        ]);

        User::factory()->create([
            'role_id' => $this->staffRole->id,
            'status' => UserStatus::Active,
        ]);

        $this->actingAsAdmin();

        $response = $this->getJson(
            '/api/users?status=inactive'
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.status',
                'inactive'
            );
    }

    public function test_admin_can_create_a_user(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/users', [
            'name' => 'New Manager',
            'email' => 'manager@cellexa.com',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'role_id' => $this->managerRole->id,
            'status' => 'active',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'User created successfully.'
            )
            ->assertJsonPath(
                'data.user.email',
                'manager@cellexa.com'
            )
            ->assertJsonPath(
                'data.user.role.slug',
                'manager'
            );

        $this->assertDatabaseHas('users', [
            'email' => 'manager@cellexa.com',
            'role_id' => $this->managerRole->id,
            'status' => 'active',
        ]);

        $createdUser = User::where(
            'email',
            'manager@cellexa.com'
        )->firstOrFail();

        $this->assertTrue(
            Hash::check(
                'Password@123',
                $createdUser->password
            )
        );
    }

    public function test_create_user_requires_valid_data(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/users', [
            'name' => '',
            'email' => 'invalid-email',
            'password' => 'short',
            'password_confirmation' => 'different',
            'role_id' => 999999,
            'status' => 'unknown',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors([
                'name',
                'email',
                'password',
                'role_id',
                'status',
            ]);
    }

    public function test_email_must_be_unique_when_creating_user(): void
    {
        $existingUser = User::factory()->create([
            'role_id' => $this->staffRole->id,
            'email' => 'existing@cellexa.com',
            'status' => UserStatus::Active,
        ]);

        $this->actingAsAdmin();

        $response = $this->postJson('/api/users', [
            'name' => 'Duplicate User',
            'email' => $existingUser->email,
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'role_id' => $this->staffRole->id,
            'status' => 'active',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_admin_can_view_a_user(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->managerRole->id,
            'status' => UserStatus::Active,
        ]);

        $this->actingAsAdmin();

        $response = $this->getJson(
            "/api/users/{$user->id}"
        );

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath(
                'data.user.role.slug',
                'manager'
            );
    }

    public function test_admin_can_update_a_user(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->staffRole->id,
            'name' => 'Original Name',
            'status' => UserStatus::Active,
        ]);

        $this->actingAsAdmin();

        $response = $this->patchJson(
            "/api/users/{$user->id}",
            [
                'name' => 'Updated Name',
                'role_id' => $this->managerRole->id,
                'status' => 'active',
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'data.user.name',
                'Updated Name'
            )
            ->assertJsonPath(
                'data.user.role.slug',
                'manager'
            );

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'role_id' => $this->managerRole->id,
        ]);
    }

    public function test_user_can_be_updated_without_changing_password(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->staffRole->id,
            'password' => 'OriginalPassword@123',
            'status' => UserStatus::Active,
        ]);

        $originalPasswordHash = $user->password;

        $this->actingAsAdmin();

        $response = $this->patchJson(
            "/api/users/{$user->id}",
            [
                'name' => 'Updated User',
                'password' => null,
            ]
        );

        $response->assertOk();

        $this->assertSame(
            $originalPasswordHash,
            $user->fresh()->password
        );
    }

    public function test_admin_can_change_user_password(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->staffRole->id,
            'password' => 'OriginalPassword@123',
            'status' => UserStatus::Active,
        ]);

        $this->actingAsAdmin();

        $response = $this->patchJson(
            "/api/users/{$user->id}",
            [
                'password' => 'NewPassword@123',
                'password_confirmation' => 'NewPassword@123',
            ]
        );

        $response->assertOk();

        $this->assertTrue(
            Hash::check(
                'NewPassword@123',
                $user->fresh()->password
            )
        );
    }

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $this->actingAsAdmin();

        $response = $this->patchJson(
            "/api/users/{$this->admin->id}",
            [
                'status' => 'inactive',
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'You cannot deactivate your own account.'
            );

        $this->assertSame(
            UserStatus::Active,
            $this->admin->fresh()->status
        );
    }

    public function test_deactivating_user_revokes_all_tokens(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->staffRole->id,
            'status' => UserStatus::Active,
        ]);

        $user->createToken('token-one');
        $user->createToken('token-two');

        $this->assertCount(2, $user->tokens);

        $this->actingAsAdmin();

        $response = $this->patchJson(
            "/api/users/{$user->id}",
            [
                'status' => 'inactive',
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.user.status',
                'inactive'
            );

        $this->assertDatabaseMissing(
            'personal_access_tokens',
            [
                'tokenable_type' => User::class,
                'tokenable_id' => $user->id,
            ]
        );
    }

    public function test_admin_can_soft_delete_a_user(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->staffRole->id,
            'status' => UserStatus::Active,
        ]);

        $user->createToken('test-token');

        $this->actingAsAdmin();

        $response = $this->deleteJson(
            "/api/users/{$user->id}"
        );

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'User deleted successfully.'
            );

        $this->assertSoftDeleted('users', [
            'id' => $user->id,
        ]);

        $this->assertDatabaseMissing(
            'personal_access_tokens',
            [
                'tokenable_type' => User::class,
                'tokenable_id' => $user->id,
            ]
        );
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $this->actingAsAdmin();

        $response = $this->deleteJson(
            "/api/users/{$this->admin->id}"
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'You cannot delete your own account.'
            );

        $this->assertDatabaseHas('users', [
            'id' => $this->admin->id,
            'deleted_at' => null,
        ]);
    }

    public function test_missing_user_returns_not_found_response(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/users/999999');

        $response
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'The requested resource was not found.'
            );
    }

    public function test_admin_can_list_roles(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/roles');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data.roles')
            ->assertJsonStructure([
                'success',
                'data' => [
                    'roles' => [
                        '*' => [
                            'id',
                            'name',
                            'slug',
                            'description',
                            'is_default',
                        ],
                    ],
                ],
            ]);
    }

}