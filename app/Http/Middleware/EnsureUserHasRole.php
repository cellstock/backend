<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user()?->loadMissing('role');
        if (! $user) return ApiResponse::error('Unauthenticated.', 401);
        if (! in_array($user->role?->slug, $roles, true)) return ApiResponse::error('You are not authorized to perform this action.', 403);
        return $next($request);
    }
}
