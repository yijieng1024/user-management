<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Arr;

/**
 * Creates a user from validated StoreUserRequest data (Users page and API).
 */
class CreateUser
{
    /**
     * is_admin is not mass assignable, so it is set explicitly (defaults to false).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(array $attributes): User
    {
        $user = new User(Arr::except($attributes, ['is_admin']));
        $user->is_admin = (bool) ($attributes['is_admin'] ?? false);
        $user->save();

        return $user;
    }
}
