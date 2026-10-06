<?php

namespace App\Actions\Users;

use App\Models\User;

/**
 * Soft deletes many users in a single query (Users page bulk delete and API).
 */
class DeleteUsers
{
    /**
     * The acting admin is always skipped, so they cannot delete themselves.
     *
     * @param  array<int, int|string>  $ids
     * @return int The number of users deleted.
     */
    public function __invoke(array $ids, User $actingAdmin): int
    {
        return User::query()
            ->whereIn('id', array_map('intval', $ids))
            ->whereKeyNot($actingAdmin->getKey())
            ->delete();
    }
}
