<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Checks login credentials for both the web login (Fortify) and the API login.
 * Only active admins may log in.
 */
class AuthenticateAdmin
{
    /**
     * Get the user for valid credentials, or null when the credentials are wrong.
     *
     * @throws ValidationException when the credentials are correct but the user may not use the app.
     */
    public function __invoke(string $email, string $password): ?User
    {
        $user = User::where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            return null;
        }

        $deniedReason = $user->adminAccessDeniedReason();

        if ($deniedReason !== null) {
            throw ValidationException::withMessages([
                'email' => $deniedReason,
            ]);
        }

        return $user;
    }
}
