<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesJwtRequests;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use MakesJwtRequests;
    use RefreshDatabase;

    // ---------------------------------------------------------------------
    // Login
    // ---------------------------------------------------------------------

    public function test_active_admins_receive_a_bearer_token(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->apiRequest('POST', '/api/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);

        $this->assertSame('bearer', $response->json('token_type'));
        $this->assertSame(3600, $response->json('expires_in'));
        $this->apiRequest('GET', '/api/users', [], $response->json('access_token'))->assertOk();
    }

    public function test_login_with_a_wrong_password_returns_the_generic_message(): void
    {
        $admin = User::factory()->admin()->create();

        $this->apiRequest('POST', '/api/login', ['email' => $admin->email, 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', __('auth.failed'));
    }

    public function test_login_with_an_unknown_email_returns_the_generic_message(): void
    {
        $this->apiRequest('POST', '/api/login', ['email' => 'nobody@example.test', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', __('auth.failed'));
    }

    public function test_non_admins_cannot_get_a_token(): void
    {
        $user = User::factory()->create();

        $this->apiRequest('POST', '/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'You do not have admin access.')
            ->assertJsonMissingPath('access_token');
    }

    public function test_inactive_and_suspended_admins_cannot_get_a_token(): void
    {
        foreach (['inactive', 'suspended'] as $status) {
            $admin = User::factory()->admin()->create(['status' => $status]);

            $this->apiRequest('POST', '/api/login', ['email' => $admin->email, 'password' => 'password'])
                ->assertUnprocessable()
                ->assertJsonPath('errors.email.0', 'Your account is not active.')
                ->assertJsonMissingPath('access_token');
        }
    }

    public function test_login_validates_the_request(): void
    {
        $this->apiRequest('POST', '/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);

        $this->apiRequest('POST', '/api/login', ['email' => 'not-an-email', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_is_limited_to_five_attempts_per_minute_per_email_and_ip(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->apiRequest('POST', '/api/login', ['email' => $admin->email, 'password' => 'wrong-password'])
                ->assertUnprocessable();
        }

        $this->apiRequest('POST', '/api/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertTooManyRequests()
            ->assertJsonStructure(['message']);

        // Another email has its own limit.
        $this->apiLogin($otherAdmin);

        // The limit resets after a minute.
        $this->travel(61)->seconds();
        $this->apiLogin($admin);
    }

    // ---------------------------------------------------------------------
    // Token problems
    // ---------------------------------------------------------------------

    public function test_requests_without_a_token_get_a_401(): void
    {
        $this->apiRequest('GET', '/api/users')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_requests_with_an_invalid_token_get_a_401(): void
    {
        $this->apiRequest('GET', '/api/users', [], 'not-a-jwt')->assertUnauthorized();
        $this->apiRequest('GET', '/api/users', [], 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.invalid-signature')->assertUnauthorized();
    }

    public function test_requests_with_an_expired_token_get_a_401(): void
    {
        $token = $this->apiLogin(User::factory()->admin()->create());

        $this->travel(61)->minutes();

        $this->apiRequest('GET', '/api/users', [], $token)->assertUnauthorized();
    }

    public function test_a_web_session_cannot_be_used_on_the_api(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->getJson('/api/users')->assertUnauthorized();
    }

    // ---------------------------------------------------------------------
    // Refresh
    // ---------------------------------------------------------------------

    public function test_refresh_returns_a_new_token_and_blacklists_the_old_one(): void
    {
        $oldToken = $this->apiLogin(User::factory()->admin()->create());

        $response = $this->apiRequest('POST', '/api/refresh', [], $oldToken)
            ->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);
        $newToken = $response->json('access_token');

        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame('bearer', $response->json('token_type'));
        $this->apiRequest('GET', '/api/users', [], $newToken)->assertOk();
        $this->apiRequest('GET', '/api/users', [], $oldToken)->assertUnauthorized();
        $this->apiRequest('POST', '/api/refresh', [], $oldToken)->assertUnauthorized();
    }

    public function test_an_expired_token_can_be_refreshed_within_one_day(): void
    {
        $token = $this->apiLogin(User::factory()->admin()->create());

        $this->travel(23)->hours();
        $this->apiRequest('GET', '/api/users', [], $token)->assertUnauthorized();

        $newToken = $this->apiRequest('POST', '/api/refresh', [], $token)->assertOk()->json('access_token');
        $this->apiRequest('GET', '/api/users', [], $newToken)->assertOk();
    }

    public function test_a_token_older_than_one_day_cannot_be_refreshed(): void
    {
        $token = $this->apiLogin(User::factory()->admin()->create());

        $this->travel(25)->hours();

        $this->apiRequest('POST', '/api/refresh', [], $token)
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_an_expired_token_is_still_rejected_after_a_refresh_in_the_same_process(): void
    {
        $admin = User::factory()->admin()->create();
        $expiringToken = $this->apiLogin($admin);
        $otherToken = $this->apiLogin($admin);

        $this->travel(61)->minutes();
        $this->apiRequest('POST', '/api/refresh', [], $otherToken)->assertOk();

        $this->apiRequest('GET', '/api/users', [], $expiringToken)->assertUnauthorized();
    }

    public function test_refresh_fails_for_an_admin_suspended_after_login_and_kills_both_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->apiLogin($admin);

        $admin->update(['status' => 'suspended']);

        $this->apiRequest('POST', '/api/refresh', [], $token)
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is not active.');
        $this->apiRequest('POST', '/api/refresh', [], $token)->assertUnauthorized();
    }

    public function test_refresh_without_a_token_gets_a_401(): void
    {
        $this->apiRequest('POST', '/api/refresh')->assertUnauthorized();
    }

    // ---------------------------------------------------------------------
    // Logout
    // ---------------------------------------------------------------------

    public function test_logout_blacklists_the_token(): void
    {
        $token = $this->apiLogin(User::factory()->admin()->create());

        $this->apiRequest('POST', '/api/logout', [], $token)
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->apiRequest('GET', '/api/users', [], $token)->assertUnauthorized();
        $this->apiRequest('POST', '/api/refresh', [], $token)->assertUnauthorized();
        $this->apiRequest('POST', '/api/logout', [], $token)->assertUnauthorized();
    }

    public function test_logout_without_a_token_gets_a_401(): void
    {
        $this->apiRequest('POST', '/api/logout')->assertUnauthorized();
    }
}
