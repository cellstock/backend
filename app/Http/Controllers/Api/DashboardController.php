<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): JsonResponse
    {
        return ApiResponse::success(
            data: $dashboard->forUser($request->user()),
            message: 'Dashboard retrieved successfully.',
        );
    }
}
