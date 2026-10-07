<?php

namespace Tests\Feature\Api;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->adminRole = Role::where('slug', 'admin')->firstOrFail();
    }

    public function test_visitor_can_register_with_the_default_role(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'New Seller',
            'email' => 'Seller@Example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'seller@example.com')
            ->assertJsonPath('data.user.role.slug', 'user')
            ->assertJsonStructure(['data' => ['token', 'token_type', 'user']]);

        $user = User::query()->where('email', 'seller@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Password123', $user->password));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_registration_rejects_duplicate_email_and_invalid_confirmation(): void
    {
        User::factory()->create([
            'role_id' => $this->adminRole->id,
            'email' => 'existing@example.com',
        ]);

        $this->postJson('/api/auth/register', [
            'name' => 'Another User',
            'email' => 'existing@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Different123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_active_user_can_login(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->adminRole->id,
            'email' => 'admin@cellexa.com',
            'password' => 'Password@123',
            'status' => UserStatus::Active,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@cellexa.com',
            'password' => 'Password@123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.role.slug', 'admin')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'token',
                    'token_type',
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'role',
                        'status',
                        'last_login_at',
                    ],
                ],
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_user_cannot_login_with_incorrect_password(): void
    {
        User::factory()->create([
            'role_id' => $this->adminRole->id,
            'email' => 'admin@cellexa.com',
            'password' => 'Password@123',
            'status' => UserStatus::Active,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@cellexa.com',
            'password' => 'WrongPassword@123',
        ]);

        $response
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'The provided credentials are incorrect.'
            );

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_requires_email_and_password(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'The provided data is invalid.'
            )
            ->assertJsonValidationErrors([
                'email',
                'password',
            ]);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'role_id' => $this->adminRole->id,
            'email' => 'inactive@cellexa.com',
            'password' => 'Password@123',
            'status' => UserStatus::Inactive,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'inactive@cellexa.com',
            'password' => 'Password@123',
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'Your account is not active. Please contact an administrator.'
            );

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_suspended_user_cannot_login(): void
    {
        User::factory()->create([
            'role_id' => $this->adminRole->id,
            'email' => 'suspended@cellexa.com',
            'password' => 'Password@123',
            'status' => UserStatus::Suspended,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'suspended@cellexa.com',
            'password' => 'Password@123',
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_authenticated_user_can_get_their_profile(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->adminRole->id,
            'status' => UserStatus::Active,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->getJson('/api/auth/me');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role.slug', 'admin');
    }

    public function test_unauthenticated_user_cannot_get_profile(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_authenticated_user_can_update_their_profile_and_password(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->adminRole->id,
            'email' => 'before@example.com',
            'password' => 'Password@123',
            'status' => UserStatus::Active,
        ]);

        $response = $this->withToken($user->createToken('test-token')->plainTextToken)
            ->patchJson('/api/auth/profile', [
                'name' => 'Updated User',
                'email' => 'updated@example.com',
                'current_password' => 'Password@123',
                'password' => 'NewPassword@456',
                'password_confirmation' => 'NewPassword@456',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.user.name', 'Updated User')
            ->assertJsonPath('data.user.email', 'updated@example.com');

        $this->assertTrue(Hash::check('NewPassword@456', $user->refresh()->password));
    }

    public function test_profile_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->adminRole->id,
            'password' => 'Password@123',
            'status' => UserStatus::Active,
        ]);

        $this->withToken($user->createToken('test-token')->plainTextToken)
            ->patchJson('/api/auth/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'current_password' => 'WrongPassword',
                'password' => 'NewPassword@456',
                'password_confirmation' => 'NewPassword@456',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The current password is incorrect.');
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->adminRole->id,
            'status' => UserStatus::Active,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $response = $this
            ->withToken($token)
            ->postJson('/api/auth/logout');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'Logged out successfully.'
            );

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_password_is_stored_as_a_hash(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->adminRole->id,
            'password' => 'Password@123',
            'status' => UserStatus::Active,
        ]);

        $this->assertNotSame('Password@123', $user->password);
        $this->assertTrue(
            Hash::check('Password@123', $user->password)
        );
    }
}
