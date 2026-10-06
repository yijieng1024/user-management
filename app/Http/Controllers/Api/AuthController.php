<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\AuthenticateAdmin;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use PHPOpenSourceSaver\JWTAuth\Manager;

class AuthController extends Controller
{
    /**
     * Issue a JWT for an active admin. Uses the same check and messages as the web login.
     *
     * @throws ValidationException
     */
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
