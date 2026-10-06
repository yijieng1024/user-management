<?php

namespace Tests\Unit;

use App\Http\Requests\Api\BulkDeleteUsersRequest;
use App\Http\Requests\Api\StoreUserRequest as ApiStoreUserRequest;
use App\Http\Requests\Api\UpdateUserRequest as ApiUpdateUserRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use LogicException;
use Tests\TestCase;

/**
 * Unit tests for the user Form Requests, which are the single source of the
 * validation rules for both the Users page and the API. They check which
 * rules apply in each situation, without sending any requests.
 */
class UserFormRequestsTest extends TestCase
{
    public function test_store_rules_cover_every_user_field(): void
    {
        $rules = $this->ruleStrings((new StoreUserRequest)->rules());

        $this->assertSame(['name', 'email', 'phone_number', 'password', 'status', 'is_admin'], array_keys($rules));
        $this->assertContains('required', $rules['password']);
        $this->assertContains('confirmed', $rules['password']);
        $this->assertContains(Password::class, $rules['password']);
        $this->assertContains('max:20', $rules['phone_number']);
        $this->assertContains('in:"active","inactive","suspended"', $rules['status']);
        $this->assertContains('unique:users,email,NULL,id', $rules['email']);
        $this->assertContains('unique:users,phone_number,NULL,id', $rules['phone_number']);
    }

    public function test_update_rules_make_the_password_optional_and_ignore_the_user_being_updated(): void
    {
        $rules = $this->ruleStrings((new UpdateUserRequest)->forUser($this->makeUser(id: 5))->rules());

        $this->assertContains('nullable', $rules['password']);
        $this->assertNotContains('required', $rules['password']);
        $this->assertContains('unique:users,email,"5",id', $rules['email']);
        $this->assertContains('unique:users,phone_number,"5",id', $rules['phone_number']);
    }

    public function test_update_rules_for_another_user_allow_any_status_and_admin_flag(): void
    {
        Auth::setUser($this->makeUser(id: 1));

        $request = (new UpdateUserRequest)->forUser($this->makeUser(id: 5));
        $rules = $this->ruleStrings($request->rules());

        $this->assertSame(['boolean'], $rules['is_admin']);
        $this->assertContains('in:"active","inactive","suspended"', $rules['status']);
        $this->assertSame([], $request->messages());
    }

    public function test_update_rules_for_yourself_require_staying_an_active_admin(): void
    {
        $self = $this->makeUser(id: 1);
        Auth::setUser($self);

        $request = (new UpdateUserRequest)->forUser($self);
        $rules = $this->ruleStrings($request->rules());

        $this->assertContains('accepted', $rules['is_admin']);
        $this->assertContains('in:"active"', $rules['status']);
        $this->assertSame([
            'is_admin.accepted' => 'You cannot remove your own admin access.',
            'status.in' => 'You cannot change your own status.',
        ], $request->messages());
    }

    public function test_update_request_needs_to_know_which_user_is_being_updated(): void
    {
        $this->expectException(LogicException::class);

        (new UpdateUserRequest)->rules();
    }

    public function test_api_requests_drop_is_admin_from_the_rules_but_keep_everything_else(): void
    {
        $user = $this->makeUser(id: 5);

        $this->assertSame(
            array_keys(array_diff_key((new StoreUserRequest)->rules(), ['is_admin' => true])),
            array_keys((new ApiStoreUserRequest)->rules()),
        );
        $this->assertSame(
            array_keys(array_diff_key((new UpdateUserRequest)->forUser($user)->rules(), ['is_admin' => true])),
            array_keys((new ApiUpdateUserRequest)->forUser($user)->rules()),
        );
    }

    public function test_api_requests_drop_is_admin_from_the_input_before_validation(): void
    {
        foreach ([ApiStoreUserRequest::class, ApiUpdateUserRequest::class] as $requestClass) {
            $request = $requestClass::create('/api/users', 'POST', ['name' => 'Someone', 'is_admin' => true]);

            $this->assertSame(['name' => 'Someone'], $request->validationData());
        }
    }

    public function test_api_update_for_yourself_still_protects_your_status(): void
    {
        $self = $this->makeUser(id: 1);
        Auth::setUser($self);

        $rules = $this->ruleStrings((new ApiUpdateUserRequest)->forUser($self)->rules());

        $this->assertArrayNotHasKey('is_admin', $rules);
        $this->assertContains('in:"active"', $rules['status']);
    }

    public function test_bulk_delete_rules_check_all_ids_exist_and_are_not_soft_deleted(): void
    {
        $rules = $this->ruleStrings((new BulkDeleteUsersRequest)->rules());

        $this->assertContains('required', $rules['ids']);
        $this->assertContains('array', $rules['ids']);
        $this->assertContains('min:1', $rules['ids']);
        $this->assertContains('max:1000', $rules['ids']);
        $this->assertContains('exists:users,id,deleted_at,"NULL"', $rules['ids']);
        $this->assertSame(['integer', 'distinct'], $rules['ids.*']);
    }

    /**
     * Turn rule objects into strings so rules can be compared, e.g. Unique
     * becomes "unique:users,email,NULL,id" and Password its class name.
     *
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, list<string>>
     */
    private function ruleStrings(array $rules): array
    {
        return array_map(
            fn (array $fieldRules): array => array_values(array_map(
                fn (mixed $rule): string => is_string($rule) || $rule instanceof \Stringable ? (string) $rule : $rule::class,
                $fieldRules,
            )),
            $rules,
        );
    }

    /**
     * Build an unsaved user with the given ID.
     */
    private function makeUser(int $id): User
    {
        $user = new User;
        $user->forceFill(['id' => $id, 'name' => 'User '.$id, 'is_admin' => true, 'status' => 'active']);

        return $user;
    }
}
