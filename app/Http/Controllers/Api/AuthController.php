<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\AuthenticateAdmin;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\Unauthenticated;
use LogicException;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use PHPOpenSourceSaver\JWTAuth\Manager;

#[Group('Authentication', 'Get, refresh and revoke the JWT used to call the other endpoints.')]
class AuthController extends Controller
{
    /**
     * Issue a JWT for an active admin. Uses the same check and messages as the web login.
     *
     * @throws ValidationException
     */
    #[Endpoint('Log in', 'Exchange an admin\'s email and password for a JWT. Only admins whose status is `active` get a token. Limited to 5 requests per minute per email + IP address.')]
    #[Unauthenticated]
    #[BodyParam('email', 'string', 'The admin\'s email address.', example: 'admin@example.com')]
    #[BodyParam('password', 'string', 'The admin\'s password.', example: 'password')]
    #[Response(['access_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.signature', 'token_type' => 'bearer', 'expires_in' => 3600], 200, 'Logged in. Send the token as `Authorization: Bearer {access_token}`. `expires_in` is in seconds.')]
    #[Response(['message' => 'These credentials do not match our records.', 'errors' => ['email' => ['These credentials do not match our records.']]], 422, 'Wrong email or password')]
    #[Response(['message' => 'You do not have admin access.', 'errors' => ['email' => ['You do not have admin access.']]], 422, 'Correct password, but the user is not an admin')]
    #[Response(['message' => 'Your account is not active.', 'errors' => ['email' => ['Your account is not active.']]], 422, 'Correct password, but the admin is inactive or suspended')]
    #[Response(['message' => 'Too Many Attempts.'], 429, 'More than 5 attempts in a minute for this email + IP address')]
    public function login(Request $request, AuthenticateAdmin $authenticateAdmin): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = $authenticateAdmin($credentials['email'], $credentials['password']);

        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        return $this->tokenResponse($this->guard()->login($user));
    }

    /**
     * Swap the current token for a new one (the old one is blacklisted),
     * as long as the user is still an active admin.
     */
    #[Endpoint('Refresh a token', 'Send the current token (it may already be expired) and get a new one. The old token is blacklisted. A token can be refreshed for up to 1 day after it was first issued. Fails if the user is no longer an active admin.')]
    #[Authenticated]
    #[Response(['access_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.new-signature', 'token_type' => 'bearer', 'expires_in' => 3600], 200, 'New token')]
    #[Response(['message' => 'Unauthenticated.'], 401, 'No token, an invalid or blacklisted token, or older than the 1-day refresh window')]
    #[Response(['message' => 'Your account is not active.'], 403, 'The user is no longer an active admin. Both the old and the new token are blacklisted.')]
    public function refresh(): JsonResponse
    {
        $guard = $this->guard();

        try {
            $token = $guard->refresh();
        } finally {
            // The library leaves its shared token manager in "refresh mode" (which skips the
            // expiry check) after refreshing, even when refreshing fails. Reset it so later
            // checks in the same process (e.g. long-running workers) validate expiry again.
            app(Manager::class)->setRefreshFlow(false);
        }

        $user = $guard->setToken($token)->user();

        if (! $user instanceof User || ! $user->hasAdminAccess()) {
            $guard->invalidate();

            abort(403, $user instanceof User ? (string) $user->adminAccessDeniedReason() : '');
        }

        return $this->tokenResponse($token);
    }

    /**
     * Blacklist the current token so it cannot be used again.
     */
    #[Endpoint('Log out', 'Blacklist the current token so it can no longer be used.')]
    #[Response(['message' => 'Successfully logged out.'], 200, 'Logged out')]
    #[Response(['message' => 'Unauthenticated.'], 401, 'No token, or the token is invalid, expired or already blacklisted')]
    public function logout(): JsonResponse
    {
        $this->guard()->logout();

        return response()->json(['message' => __('Successfully logged out.')]);
    }

    /**
     * Build the token response.
     */
    private function tokenResponse(string $token): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $this->guard()->getTTL() * 60,
        ]);
    }

    /**
     * Get the JWT guard used by the API.
     */
    private function guard(): JWTGuard
    {
        $guard = Auth::guard('api');

        if (! $guard instanceof JWTGuard) {
            throw new LogicException('The "api" guard must use the jwt driver.');
        }

        return $guard;
    }
}
