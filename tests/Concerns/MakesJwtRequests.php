<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Helpers for API tests that send several JWT requests within one test.
 *
 * In a real deployment every request starts with a fresh application, but a
 * test reuses one application for all its requests. The auth guards and the
 * JWT library keep the previous request's user and token in memory, so they
 * are reset before each request to make every request behave like a new one.
 */
trait MakesJwtRequests
{
    /**
     * Send a JSON API request, optionally with a bearer token.
     *
     * @param  array<string, mixed>  $data
     */
    protected function apiRequest(string $method, string $uri, array $data = [], ?string $token = null): TestResponse
    {
        $this->resetJwtState();

        $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

        return $this->json($method, $uri, $data, $headers);
    }

    /**
     * Log in through POST /api/login and return the access token.
     */
    protected function apiLogin(User $user, string $password = 'password'): string
    {
        return $this->apiRequest('POST', '/api/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk()->json('access_token');
    }

    /**
     * Forget the resolved guards and the token held by the JWT library.
     */
    protected function resetJwtState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }
}
