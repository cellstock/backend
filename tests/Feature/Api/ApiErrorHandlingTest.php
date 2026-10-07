<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_api_endpoint_returns_standard_404(): void
    {
        $response = $this->getJson(
            '/api/endpoint-that-does-not-exist'
        );

        $response
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'The requested endpoint was not found.',
            ]);
    }

    public function test_invalid_http_method_returns_standard_405(): void
    {
        $response = $this->getJson('/api/auth/login');

        $response
            ->assertStatus(405)
            ->assertExactJson([
                'success' => false,
                'message' =>
                    'The HTTP method is not allowed for this endpoint.',
            ]);
    }

    public function test_validation_errors_use_standard_format(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'The provided data is invalid.'
            )
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => [
                    'email',
                    'password',
                ],
            ]);
    }
}