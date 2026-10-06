<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Arr;

/**
 * Updates a user from validated UpdateUserRequest data (Users page and API).
 */
class UpdateUser
{
    /**
     * is_admin is set explicitly and only when given. A blank password keeps the current one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(User $user, array $attributes): User
    {
        $user->fill(Arr::except($attributes, ['is_admin', 'password']));

        if (array_key_exists('is_admin', $attributes)) {
            $user->is_admin = (bool) $attributes['is_admin'];
        }

        if (filled($attributes['password'] ?? null)) {
            $user->password = $attributes['password'];
        }

        $user->save();

        return $user;
    }
}
