<?php

namespace App\Http\Requests\Api;

use App\Concerns\IgnoresIsAdmin;
use App\Http\Requests\StoreUserRequest as BaseStoreUserRequest;
use Knuckles\Scribe\Attributes\BodyParam;

/**
 * StoreUserRequest for the API: same rules, but is_admin is ignored,
 * so users created through the API are never admins.
 */
#[BodyParam('password_confirmation', 'string', 'Must match `password`.', example: 'S3cure!Passw0rd')]
class StoreUserRequest extends BaseStoreUserRequest
{
    use IgnoresIsAdmin;
}
