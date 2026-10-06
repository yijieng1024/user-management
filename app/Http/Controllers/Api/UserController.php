<?php

namespace App\Http\Controllers\Api;

use App\Actions\Users\CreateUser;
use App\Actions\Users\DeleteUsers;
use App\Actions\Users\UpdateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BulkDeleteUsersRequest;
use App\Http\Requests\Api\StoreUserRequest;
use App\Http\Requests\Api\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\ResponseFromApiResource;
use Knuckles\Scribe\Attributes\UrlParam;

#[Group('Users', 'Manage users. Every endpoint needs a token from an active admin; a suspended admin is blocked on their next request, even with an unexpired token. Limited to 60 requests per minute.')]
#[Response(['message' => 'Unauthenticated.'], 401, 'No token, or the token is invalid, expired or blacklisted')]
#[Response(['message' => 'Your account is not active.'], 403, 'The token belongs to a user who is no longer an active admin')]
class UserController extends Controller
{
    /**
     * List users, 10 per page, filtered by ?status= and ?search= like the Users page.
     */
    #[Endpoint('List users', 'Users are returned newest first, 10 per page. Soft-deleted users are not included. `status` and `search` can be combined.')]
    #[QueryParam('status', 'string', 'Only return users with this status: `active`, `inactive` or `suspended`. Any other value is ignored.', required: false, example: 'active', enum: ['active', 'inactive', 'suspended'])]
    #[QueryParam('search', 'string', 'Return users whose name, email or phone number contains this text.', required: false, example: 'ahmad')]
    #[QueryParam('page', 'integer', 'The page number.', required: false, example: 1)]
    #[ResponseFromApiResource(UserResource::class, User::class, collection: true, factoryStates: ['apiDocsExample'], paginate: 10, description: 'A page of users')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->filter($request->string('status')->value(), $request->string('search')->value())
            ->latest()
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return UserResource::collection($users);
    }

    /**
     * Create a user. is_admin is ignored, so API-created users are never admins.
     */
    #[Endpoint('Create a user', 'Users created through the API are never admins: `is_admin` is ignored if sent. Admins can only be created from the web Users page.')]
    #[ResponseFromApiResource(UserResource::class, User::class, status: 201, factoryStates: ['apiDocsExample'], description: 'User created')]
    #[Response(['message' => 'The email has already been taken. (and 1 more error)', 'errors' => ['email' => ['The email has already been taken.'], 'phone_number' => ['The phone number has already been taken.']]], 422, 'Validation error. Soft-deleted users still count as taking their email and phone number.')]
    public function store(StoreUserRequest $request, CreateUser $createUser): JsonResponse
    {
        $user = $createUser($request->validated());

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    /**
     * Show a user.
     */
    #[Endpoint('Show a user')]
    #[UrlParam('id', 'integer', 'The ID of the user.', example: 1)]
    #[ResponseFromApiResource(UserResource::class, User::class, factoryStates: ['apiDocsExample'], description: 'The user')]
    #[Response(['message' => 'No query results for model [App\\Models\\User] 999.'], 404, 'No user with this ID, or the user is soft deleted')]
    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    /**
     * Update a user. is_admin is ignored; it can only be changed on the web Users page.
     */
    #[Endpoint('Update a user', 'Send all fields, like when creating. `password` is optional: leave it out to keep the current password. `is_admin` is ignored; it can only be changed on the web Users page. An admin cannot change their own status. `PUT` works the same way.')]
    #[UrlParam('id', 'integer', 'The ID of the user.', example: 1)]
    #[ResponseFromApiResource(UserResource::class, User::class, factoryStates: ['apiDocsExample'], description: 'The updated user')]
    #[Response(['message' => 'No query results for model [App\\Models\\User] 999.'], 404, 'No user with this ID, or the user is soft deleted')]
    #[Response(['message' => 'You cannot change your own status.', 'errors' => ['status' => ['You cannot change your own status.']]], 422, 'Validation error, e.g. an admin changing their own status')]
    public function update(UpdateUserRequest $request, User $user, UpdateUser $updateUser): UserResource
    {
        return new UserResource($updateUser($user, $request->validated()));
    }

    /**
     * Soft delete a user. Admins cannot delete themselves.
     */
    #[Endpoint('Delete a user', 'Soft deletes the user: they disappear from the list and can no longer log in, but their email and phone number stay reserved. An admin cannot delete themselves.')]
    #[UrlParam('id', 'integer', 'The ID of the user.', example: 2)]
    #[Response(['message' => 'User Tan Mei Ling deleted.'], 200, 'User deleted')]
    #[Response(['message' => 'You cannot delete your own account.'], 403, 'The admin tried to delete themselves')]
    #[Response(['message' => 'No query results for model [App\\Models\\User] 999.'], 404, 'No user with this ID, or the user is already deleted')]
    public function destroy(User $user, #[CurrentUser] User $currentUser): JsonResponse
    {
        if ($user->is($currentUser)) {
            abort(403, __('You cannot delete your own account.'));
        }

        $user->delete();

        return response()->json(['message' => __('User :name deleted.', ['name' => $user->name])]);
    }

    /**
     * Soft delete many users in one query, always skipping the logged-in admin.
     */
    #[Endpoint('Delete many users', 'Soft deletes all the given users in a single query. The logged-in admin\'s own ID is always skipped. If any ID is invalid, nothing is deleted. Returns how many users were deleted.')]
    #[Response(['deleted' => 3], 200, 'Users deleted')]
    #[Response(['message' => 'One or more of the selected users do not exist or have already been deleted.', 'errors' => ['ids' => ['One or more of the selected users do not exist or have already been deleted.']]], 422, 'An ID does not exist or the user is already deleted')]
    public function bulkDestroy(BulkDeleteUsersRequest $request, DeleteUsers $deleteUsers, #[CurrentUser] User $currentUser): JsonResponse
    {
        /** @var list<int> $ids */
        $ids = $request->validated('ids');

        return response()->json(['deleted' => $deleteUsers($ids, $currentUser)]);
    }
}
