<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    public function register(string $name, string $email, string $password): array
    {
        $normalizedEmail = Str::lower(trim($email));

        return DB::transaction(function () use ($name, $normalizedEmail, $password): array {
            $role = Role::query()->where('is_default', true)->first()
                ?? Role::query()->where('slug', 'staff')->first();

            if (! $role) {
                throw new BusinessRuleException('User registration is not configured. Please contact an administrator.', 503);
            }

            $user = User::query()->create([
                'name' => trim($name),
                'email' => $normalizedEmail,
                'password' => Hash::make($password),
                'role_id' => $role->id,
                'status' => 'active',
                'last_login_at' => now(),
            ]);

            $token = $user->createToken('cellexa-web', ['*'], now()->addHours(8));

            return [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'user' => $user->load('role'),
            ];
        });
    }

    /**
     * Authenticate a user and create an access token.
     *
     * @return array{
     *     token: string,
     *     token_type: string,
     *     user: User
     * }
     */
    public function login(
        string $email,
        string $password
    ): array {
        $normalizedEmail = Str::lower(trim($email));

        $user = User::query()
            ->with('role')
            ->where('email', $normalizedEmail)
            ->first();

        if (
            ! $user
            || ! Hash::check($password, $user->password)
        ) {
            throw new BusinessRuleException(
                message: 'The provided credentials are incorrect.',
                status: 401,
            );
        }

        if (! $user->status->canLogin()) {
            throw new BusinessRuleException(
                message: 'Your account is not active. Please contact an administrator.',
                status: 403,
            );
        }

        return DB::transaction(function () use ($user) {
            $user->update([
                'last_login_at' => now(),
            ]);

            $token = $user->createToken(
                name: 'cellexa-web',
                abilities: ['*'],
                expiresAt: now()->addHours(8),
            );

            $user->refresh()->load('role');

            return [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'user' => $user,
            ];
        });
    }

    public function currentUser(User $user): User
    {
        return $user->load('role');
    }

    public function updateProfile(User $user, array $data): User
    {
        if (! empty($data['password']) && ! Hash::check((string) ($data['current_password'] ?? ''), $user->password)) {
            throw new BusinessRuleException('The current password is incorrect.', 422);
        }

        return DB::transaction(function () use ($user, $data): User {
            $changes = [
                'name' => trim($data['name']),
                'email' => Str::lower(trim($data['email'])),
            ];

            if (! empty($data['password'])) {
                $changes['password'] = Hash::make($data['password']);
            }

            $user->update($changes);

            return $user->refresh()->load('role');
        });
    }

    public function logout(User $user): void
    {
        $accessToken = $user->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            $accessToken->delete();
        }
    }
}
