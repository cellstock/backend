<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Users\UserService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $userService
    ) {
    }

    public function index(
        Request $request
    ): AnonymousResourceCollection {
        $filters = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:255',
            ],
            'role_id' => [
                'nullable',
                'integer',
                Rule::exists('roles', 'id'),
            ],
            'status' => [
                'nullable',
                Rule::enum(UserStatus::class),
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:5',
                'max:100',
            ],
            'sort_by' => [
                'nullable',
                Rule::in([
                    'name',
                    'email',
                    'status',
                    'created_at',
                ]),
            ],
            'sort_direction' => [
                'nullable',
                Rule::in([
                    'asc',
                    'desc',
                ]),
            ],
        ]);

        $users = $this->userService->paginate($filters, $request->user());

        return UserResource::collection($users)->additional([
            'success' => true,
            'message' => 'Users retrieved successfully.',
        ]);
    }

    public function store(
        StoreUserRequest $request
    ): JsonResponse {
        $user = $this->userService->create($request->validated(), $request->user());

        return ApiResponse::success(
            data: [
                'user' => new UserResource($user),
            ],
            message: 'User created successfully.',
            status: 201,
        );
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $user = $this->userService->get($user, $request->user());

        return ApiResponse::success(
            data: [
                'user' => new UserResource($user),
            ],
            message: 'User retrieved successfully.',
        );
    }

    public function update(
        UpdateUserRequest $request,
        User $user
    ): JsonResponse {
        $user = $this->userService->update(
            user: $user,
            data: $request->validated(),
            performedBy: $request->user(),
        );

        return ApiResponse::success(
            data: [
                'user' => new UserResource($user),
            ],
            message: 'User updated successfully.',
        );
    }

    public function destroy(
        Request $request,
        User $user
    ): JsonResponse {
        $this->userService->delete(
            user: $user,
            performedBy: $request->user(),
        );

        return ApiResponse::success(
            data: null,
            message: 'User deleted successfully.',
        );
    }
}
