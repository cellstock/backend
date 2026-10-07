<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {
    }

    public function login(
        LoginRequest $request
    ): JsonResponse {
        $result = $this->authService->login(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
        );

        return ApiResponse::success(
            data: [
                'token' => $result['token'],
                'token_type' => $result['token_type'],
                'user' => new UserResource($result['user']),
            ],
            message: 'Login successful.',
        );
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register(
            name: $request->string('name')->toString(),
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
        );

        return ApiResponse::success(
            data: [
                'token' => $result['token'],
                'token_type' => $result['token_type'],
                'user' => new UserResource($result['user']),
            ],
            message: 'Account created successfully.',
            status: 201,
        );
    }

    public function me(Request $request): JsonResponse
    {
        $user = $this->authService->currentUser(
            $request->user()
        );

        return ApiResponse::success(
            data: [
                'user' => new UserResource($user),
            ],
            message: 'Authenticated user retrieved successfully.',
        );
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->authService->updateProfile(
            user: $request->user(),
            data: $request->validated(),
        );

        return ApiResponse::success(
            data: ['user' => new UserResource($user)],
            message: 'Profile updated successfully.',
        );
    }

    public function avatar(Request $request): StreamedResponse
    {
        $path = $request->user()->avatar_path;

        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();
        $oldPath = $user->avatar_path;
        $path = $request->file('avatar')->store('avatars');
        $user->update(['avatar_path' => $path]);

        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return ApiResponse::success(
            data: ['user' => new UserResource($user->refresh()->load('role'))],
            message: 'Profile image updated successfully.',
        );
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('local')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return ApiResponse::success(
            data: ['user' => new UserResource($user->refresh()->load('role'))],
            message: 'Profile image removed successfully.',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout(
            $request->user()
        );

        return ApiResponse::success(
            data: null,
            message: 'Logged out successfully.',
        );
    }
}
