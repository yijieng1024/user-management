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

class UserController extends Controller
{
    /**
     * List users, 10 per page, filtered by ?status= and ?search= like the Users page.
     */
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
    public function store(StoreUserRequest $request, CreateUser $createUser): JsonResponse
    {
        $user = $createUser($request->validated());

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    /**
     * Show a user.
     */
    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    /**
     * Update a user. is_admin is ignored; it can only be changed on the web Users page.
     */
    public function update(UpdateUserRequest $request, User $user, UpdateUser $updateUser): UserResource
    {
        return new UserResource($updateUser($user, $request->validated()));
    }

    /**
     * Soft delete a user. Admins cannot delete themselves.
     */
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
    public function bulkDestroy(BulkDeleteUsersRequest $request, DeleteUsers $deleteUsers, #[CurrentUser] User $currentUser): JsonResponse
    {
        /** @var list<int> $ids */
        $ids = $request->validated('ids');

        return response()->json(['deleted' => $deleteUsers($ids, $currentUser)]);
    }
}
