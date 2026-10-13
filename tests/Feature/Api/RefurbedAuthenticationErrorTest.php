<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class RefurbedAuthenticationErrorTest extends TestCase
{
    public function test_refurbed_credential_rejection_is_not_a_session_failure(): void
    {
        \Illuminate\Support\Facades\Route::get('/api/test-refurbed-auth', function () {
            throw new \App\Exceptions\RefurbedApiException(
                'Refurbed authentication failed. Please check your API key.',
                16,
                401,
            );
        });

        $this->getJson('/api/test-refurbed-auth')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Refurbed authentication failed. Please check your API key.')
            ->assertJsonPath('errors.refurbed_code', 16);
    }

    public function test_missing_cellexa_session_still_returns_unauthorized(): void
    {
        $this->getJson('/api/marketplaces')->assertUnauthorized();
    }

}
