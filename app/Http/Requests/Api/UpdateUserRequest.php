<?php

namespace App\Http\Requests\Api;

use App\Concerns\IgnoresIsAdmin;
use App\Http\Requests\UpdateUserRequest as BaseUpdateUserRequest;
use Knuckles\Scribe\Attributes\BodyParam;

/**
 * UpdateUserRequest for the API: same rules, but is_admin is ignored,
 * so a user's admin flag can only be changed on the web Users page.
 */
#[BodyParam('password_confirmation', 'string', 'Must match `password` when a new password is sent.', required: false, example: 'N3w!Passw0rd')]
class UpdateUserRequest extends BaseUpdateUserRequest
{
    use IgnoresIsAdmin;
}
