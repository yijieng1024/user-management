<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MakesJwtRequests;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use MakesJwtRequests;
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
        $this->token = $this->apiLogin($this->admin);
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_an_admin_suspended_while_holding_a_valid_token_is_blocked_immediately(): void
    {
        $this->asAdmin('GET', '/api/users')->assertOk();

        $this->admin->update(['status' => 'suspended']);

        $this->asAdmin('GET', '/api/users')
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is not active.');
    }

    public function test_an_admin_whose_admin_flag_is_removed_is_blocked_immediately(): void
    {
        $this->admin->forceFill(['is_admin' => false])->save();

        $this->asAdmin('GET', '/api/users')
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have admin access.');
    }

    public function test_unknown_api_routes_return_a_json_404(): void
    {
        $this->asAdmin('GET', '/api/does-not-exist')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_the_api_is_limited_to_60_requests_per_minute(): void
    {
        for ($request = 1; $request <= 60; $request++) {
            $this->asAdmin('GET', '/api/users')->assertOk();
        }

        $this->asAdmin('GET', '/api/users')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['message']);
    }

    // ---------------------------------------------------------------------
    // List
    // ---------------------------------------------------------------------

    public function test_users_are_listed_ten_per_page(): void
    {
        User::factory(14)->create();

        $response = $this->asAdmin('GET', '/api/users')->assertOk();
        $this->assertCount(10, $response->json('data'));
        $this->assertSame(15, $response->json('meta.total'));
        $this->assertSame(10, $response->json('meta.per_page'));

        $this->assertCount(5, $this->asAdmin('GET', '/api/users?page=2')->json('data'));
    }

    public function test_users_can_be_filtered_by_status(): void
    {
        User::factory(2)->create(['status' => 'inactive']);
        User::factory(3)->create(['status' => 'suspended']);

        $response = $this->asAdmin('GET', '/api/users?status=suspended')->assertOk();

        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(['suspended'], array_values(array_unique(array_column($response->json('data'), 'status'))));
        $this->assertSame(6, $this->asAdmin('GET', '/api/users?status=bogus')->json('meta.total'));
    }

    public function test_users_can_be_searched_and_filtered_together(): void
    {
        User::factory()->create(['name' => 'Zed Active', 'email' => 'zed1@example.test', 'phone_number' => '011-1111111', 'status' => 'active']);
        User::factory()->create(['name' => 'Zed Suspended', 'email' => 'zed2@example.test', 'phone_number' => '011-2222222', 'status' => 'suspended']);

        $this->assertSame(2, $this->asAdmin('GET', '/api/users?search=Zed')->json('meta.total'));
        $this->assertSame(['Zed Suspended'], array_column($this->asAdmin('GET', '/api/users?search=zed&status=suspended')->json('data'), 'name'));
        $this->assertSame(['Zed Active'], array_column($this->asAdmin('GET', '/api/users?search=zed1@example')->json('data'), 'name'));
        $this->assertSame(['Zed Suspended'], array_column($this->asAdmin('GET', '/api/users?search=011-2222')->json('data'), 'name'));
        $this->assertSame(0, $this->asAdmin('GET', '/api/users?search=%25')->json('meta.total'));
    }

    public function test_pagination_links_keep_the_filters(): void
    {
        User::factory(12)->create(['status' => 'inactive']);

        $response = $this->asAdmin('GET', '/api/users?status=inactive&page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertStringContainsString('status=inactive', $response->json('links.first'));
    }

    public function test_soft_deleted_users_are_not_listed(): void
    {
        User::factory()->create(['name' => 'Deleted Person'])->delete();

        $this->assertNotContains('Deleted Person', array_column($this->asAdmin('GET', '/api/users')->json('data'), 'name'));
    }

    // ---------------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------------

    public function test_admin_can_create_a_user(): void
    {
        $response = $this->asAdmin('POST', '/api/users', $this->validUserData())
            ->assertCreated()
            ->assertJsonPath('data.name', 'New User')
            ->assertJsonPath('data.email', 'new@example.test')
            ->assertJsonPath('data.phone_number', '0123456789')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_admin', false);

        $user = User::findOrFail($response->json('data.id'));
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_is_admin_is_ignored_on_create(): void
    {
        foreach ([true, 1, '1', 'yes', 'not-a-boolean'] as $index => $isAdmin) {
            $response = $this->asAdmin('POST', '/api/users', $this->validUserData([
                'email' => "new{$index}@example.test",
                'phone_number' => "011-000000{$index}",
                'is_admin' => $isAdmin,
            ]))->assertCreated()->assertJsonPath('data.is_admin', false);

            $this->assertFalse(User::findOrFail($response->json('data.id'))->is_admin);
        }
    }

    public function test_creating_a_user_validates_the_request(): void
    {
        $this->asAdmin('POST', '/api/users', ['name' => 'Only A Name'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone_number', 'password', 'status']);

        $this->asAdmin('POST', '/api/users', $this->validUserData([
            'email' => 'not-an-email',
            'phone_number' => str_repeat('1', 21),
            'password_confirmation' => 'different',
            'status' => 'bogus',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone_number', 'password', 'status']);
    }

    public function test_a_soft_deleted_users_email_and_phone_number_stay_taken(): void
    {
        $deleted = User::factory()->create();
        $deleted->delete();

        $this->asAdmin('POST', '/api/users', $this->validUserData([
            'email' => $deleted->email,
            'phone_number' => $deleted->phone_number,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone_number']);
    }

    // ---------------------------------------------------------------------
    // Show
    // ---------------------------------------------------------------------

    public function test_admin_can_view_a_user(): void
    {
        $user = User::factory()->create();

        $this->asAdmin('GET', "/api/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_soft_deleted_and_unknown_users_return_404(): void
    {
        $deleted = User::factory()->create();
        $deleted->delete();

        $this->asAdmin('GET', "/api/users/{$deleted->id}")->assertNotFound()->assertJsonStructure(['message']);
        $this->asAdmin('PATCH', "/api/users/{$deleted->id}", $this->validUserData())->assertNotFound();
        $this->asAdmin('DELETE', "/api/users/{$deleted->id}")->assertNotFound();
        $this->asAdmin('GET', '/api/users/999999')->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // Update
    // ---------------------------------------------------------------------

    public function test_admin_can_update_a_user_and_a_blank_password_keeps_the_old_one(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;

        $this->asAdmin('PATCH', "/api/users/{$user->id}", [
            'name' => 'Renamed',
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'status' => 'suspended',
        ])->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.status', 'suspended');

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_a_new_password_replaces_the_current_one(): void
    {
        $user = User::factory()->create();

        $this->asAdmin('PATCH', "/api/users/{$user->id}", $this->userData($user, [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]))->assertOk();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_updating_rejects_another_users_email_and_phone_number(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->asAdmin('PATCH', "/api/users/{$user->id}", $this->userData($user, [
            'email' => $other->email,
            'phone_number' => $other->phone_number,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone_number']);
    }

    public function test_is_admin_is_ignored_on_update(): void
    {
        $user = User::factory()->create();
        $otherAdmin = User::factory()->admin()->create();

        $this->asAdmin('PATCH', "/api/users/{$user->id}", $this->userData($user, ['is_admin' => true]))
            ->assertOk()->assertJsonPath('data.is_admin', false);
        $this->asAdmin('PATCH', "/api/users/{$otherAdmin->id}", $this->userData($otherAdmin, ['is_admin' => false]))
            ->assertOk()->assertJsonPath('data.is_admin', true);

        $this->assertFalse($user->fresh()->is_admin);
        $this->assertTrue($otherAdmin->fresh()->is_admin);
    }

    public function test_admin_cannot_change_their_own_status(): void
    {
        $this->asAdmin('PATCH', "/api/users/{$this->admin->id}", $this->userData($this->admin, ['status' => 'suspended']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'You cannot change your own status.');

        $this->assertSame('active', $this->admin->fresh()->status);
    }

    public function test_sending_is_admin_false_for_yourself_is_ignored(): void
    {
        $this->asAdmin('PATCH', "/api/users/{$this->admin->id}", $this->userData($this->admin, ['name' => 'New Name', 'is_admin' => false]))
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.is_admin', true);

        $this->assertTrue($this->admin->fresh()->is_admin);
    }

    // ---------------------------------------------------------------------
    // Delete
    // ---------------------------------------------------------------------

    public function test_admin_can_soft_delete_a_user(): void
    {
        $user = User::factory()->create();

        $this->asAdmin('DELETE', "/api/users/{$user->id}")
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertSoftDeleted($user);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $this->asAdmin('DELETE', "/api/users/{$this->admin->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'You cannot delete your own account.');

        $this->assertNotSoftDeleted($this->admin);
    }

    public function test_bulk_delete_soft_deletes_the_given_users_in_a_single_query(): void
    {
        $users = User::factory(3)->create();
        $untouched = User::factory()->create();

        DB::enableQueryLog();
        $this->asAdmin('POST', '/api/users/bulk-delete', ['ids' => $users->pluck('id')->all()])
            ->assertOk()
            ->assertExactJson(['deleted' => 3]);
        $userUpdates = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'update "users"'));
        DB::disableQueryLog();

        $this->assertCount(1, $userUpdates);
        $users->each(fn (User $user) => $this->assertSoftDeleted($user));
        $this->assertNotSoftDeleted($untouched);
    }

    public function test_bulk_delete_always_skips_the_logged_in_admin(): void
    {
        $user = User::factory()->create();

        $this->asAdmin('POST', '/api/users/bulk-delete', ['ids' => [$user->id, $this->admin->id]])
            ->assertOk()
            ->assertExactJson(['deleted' => 1]);
        $this->asAdmin('POST', '/api/users/bulk-delete', ['ids' => [$this->admin->id]])
            ->assertOk()
            ->assertExactJson(['deleted' => 0]);

        $this->assertNotSoftDeleted($this->admin);
    }

    public function test_bulk_delete_validates_the_ids(): void
    {
        $user = User::factory()->create();
        $deleted = User::factory()->create();
        $deleted->delete();

        $this->asAdmin('POST', '/api/users/bulk-delete', [])->assertUnprocessable()->assertJsonValidationErrors(['ids']);
        $this->asAdmin('POST', '/api/users/bulk-delete', ['ids' => 'not-an-array'])->assertUnprocessable()->assertJsonValidationErrors(['ids']);
        $this->asAdmin('POST', '/api/users/bulk-delete', ['ids' => []])->assertUnprocessable()->assertJsonValidationErrors(['ids']);
        $this->asAdmin('POST', '/api/users/bulk-delete', ['ids' => ['abc']])->assertUnprocessable()->assertJsonValidationErrors(['ids.0']);
        $this->asAdmin('POST', '/api/users/bulk-delete', ['ids' => [$user->id, $user->id]])->assertUnprocessable()->assertJsonValidationErrors(['ids.0']);
        $this->asAdmin('POST', '/api/users/bulk-delete', ['ids' => [$user->id, 999999]])->assertUnprocessable()->assertJsonValidationErrors(['ids']);
        $this->asAdmin('POST', '/api/users/bulk-delete', ['ids' => [$user->id, $deleted->id]])->assertUnprocessable()->assertJsonValidationErrors(['ids']);

        $this->assertNotSoftDeleted($user);
    }

    // ---------------------------------------------------------------------
    // Response shape
    // ---------------------------------------------------------------------

    public function test_no_response_ever_contains_a_password_or_remember_token(): void
    {
        $user = User::factory()->create();

        $responses = [
            $this->asAdmin('GET', '/api/users'),
            $this->asAdmin('GET', "/api/users/{$user->id}"),
            $this->asAdmin('POST', '/api/users', $this->validUserData()),
            $this->asAdmin('PATCH', "/api/users/{$user->id}", $this->userData($user, ['password' => 'new-password', 'password_confirmation' => 'new-password'])),
        ];

        foreach ($responses as $response) {
            $response->assertSuccessful();
            $this->assertStringNotContainsString('password', $response->getContent());
            $this->assertStringNotContainsString('remember_token', $response->getContent());
        }

        $this->assertSame(
            ['id', 'name', 'email', 'phone_number', 'status', 'is_admin', 'created_at', 'updated_at'],
            array_keys($this->asAdmin('GET', "/api/users/{$user->id}")->json('data')),
        );
    }

    /**
     * Send an API request as the logged-in admin.
     *
     * @param  array<string, mixed>  $data
     */
    private function asAdmin(string $method, string $uri, array $data = []): TestResponse
    {
        return $this->apiRequest($method, $uri, $data, $this->token);
    }

    /**
     * Get valid data for creating a user.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validUserData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New User',
            'email' => 'new@example.test',
            'phone_number' => '0123456789',
            'password' => 'password',
            'password_confirmation' => 'password',
            'status' => 'active',
        ], $overrides);
    }

    /**
     * Get an existing user's current data for an update request.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function userData(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'status' => $user->status,
        ], $overrides);
    }
}
