<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $roles = Role::query()
            ->when(
                $request->user()->role?->slug === 'admin',
                fn ($query) => $query->where('slug', 'user')
            )
            ->whereIn('slug', ['super_admin', 'admin', 'user'])
            ->orderByRaw("CASE slug WHEN 'super_admin' THEN 1 WHEN 'admin' THEN 2 ELSE 3 END")
            ->get(['id', 'name', 'slug']);

        return ApiResponse::success(['roles' => $roles]);
    }
}
