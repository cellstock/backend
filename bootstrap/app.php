<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserHasRole;

use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\RefurbedApiException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(
            fn (): null => null
        );

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'active' => EnsureUserIsActive::class,
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
            $exceptions->shouldRenderJsonWhen(function (
            Request $request,
            Throwable $exception
        ): bool {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (
            ValidationException $exception,
            Request $request
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'The provided data is invalid.',
                status: 422,
                errors: $exception->errors(),
            );
        });

        $exceptions->render(function (
            AuthenticationException $exception,
            Request $request
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'Unauthenticated.',
                status: 401,
            );
        });

        $exceptions->render(function (
            AuthorizationException $exception,
            Request $request
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'You are not authorized to perform this action.',
                status: 403,
            );
        });

        $exceptions->render(function (
            ModelNotFoundException $exception,
            Request $request
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'The requested resource was not found.',
                status: 404,
            );
        });

        $exceptions->render(function (
            NotFoundHttpException $exception,
            Request $request
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'The requested endpoint was not found.',
                status: 404,
            );
        });

        $exceptions->render(function (
            MethodNotAllowedHttpException $exception,
            Request $request
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'The HTTP method is not allowed for this endpoint.',
                status: 405,
            );
        });

        $exceptions->render(function (
            BusinessRuleException $exception,
            Request $request
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage(),
                status: $exception->status(),
            );
        });

        $exceptions->render(function (
            RefurbedApiException $exception,
            Request $request
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage(),
                // Upstream credentials do not determine the CelleXa session.
                status: $exception->httpStatus() === 401 ? 422 : $exception->httpStatus(),
                errors: [
                    'refurbed_code' => $exception->grpcCode(),
                    'details' => $exception->details(),
                ],
            );
        });

        $exceptions->render(function (
            Throwable $exception,
            Request $request
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            report($exception);

            return ApiResponse::error(
                message: config('app.debug')
                    ? $exception->getMessage()
                    : 'An unexpected error occurred.',
                status: 500,
            );
        });

    })->create();
