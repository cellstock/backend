<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Support\ApiResponse;

class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error(
                message: 'Unauthenticated.',
                status: 401,
            );
        }

        if (! $user->status->canLogin()) {
            $user->currentAccessToken()?->delete();

            return ApiResponse::error(
                message: 'Your account is not active. Please contact an administrator.',
                status: 403,
            );
        }
        
        return $next($request);
    }
}
