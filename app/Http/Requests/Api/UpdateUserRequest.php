<?php

namespace App\Http\Requests\Api;

use App\Concerns\IgnoresIsAdmin;
use App\Http\Requests\UpdateUserRequest as BaseUpdateUserRequest;

/**
 * UpdateUserRequest for the API: same rules, but is_admin is ignored,
 * so a user's admin flag can only be changed on the web Users page.
 */
class UpdateUserRequest extends BaseUpdateUserRequest
{
    use IgnoresIsAdmin;
}
