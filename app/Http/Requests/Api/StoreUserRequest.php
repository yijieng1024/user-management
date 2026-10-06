<?php

namespace App\Http\Requests\Api;

use App\Concerns\IgnoresIsAdmin;
use App\Http\Requests\StoreUserRequest as BaseStoreUserRequest;

/**
 * StoreUserRequest for the API: same rules, but is_admin is ignored,
 * so users created through the API are never admins.
 */
class StoreUserRequest extends BaseStoreUserRequest
{
    use IgnoresIsAdmin;
}
