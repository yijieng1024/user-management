<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
    }

    // ---------------------------------------------------------------------
    // Access control
    // ---------------------------------------------------------------------

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('users.index'))->assertRedirect(route('login'));
    }

    public function test_admins_can_view_the_users_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Users')
            ->assertSee('Export to Excel');
    }

    public function test_non_admins_get_a_403_on_every_app_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($user)->get(route('profile.edit'))->assertForbidden();
    }

    public function test_inactive_and_suspended_admins_get_a_403(): void
    {
        foreach (['inactive', 'suspended'] as $status) {
            $admin = User::factory()->admin()->create(['status' => $status]);

            $this->actingAs($admin)
                ->get(route('users.index'))
                ->assertForbidden()
                ->assertSee('Your account is not active.');
        }
    }

    public function test_non_admins_are_rejected_at_login_with_a_clear_message(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'You do not have admin access.']);

        $this->assertGuest();
    }

    public function test_inactive_and_suspended_admins_are_rejected_at_login_with_a_clear_message(): void
    {
        foreach (['inactive', 'suspended'] as $status) {
            $admin = User::factory()->admin()->create(['status' => $status]);

            $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
                ->assertSessionHasErrors(['email' => 'Your account is not active.']);

            $this->assertGuest();
        }
    }

    public function test_wrong_password_shows_the_generic_message_even_for_non_admins(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
    }

    public function test_soft_deleted_admins_cannot_log_in(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->delete();

        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
    }

    // ---------------------------------------------------------------------
    // Listing, filtering, search, pagination
    // ---------------------------------------------------------------------

    public function test_users_are_listed_ten_per_page(): void
    {
        User::factory(14)->create();

        $component = $this->usersPage();
        $this->assertCount(10, $component->instance()->users);
        $this->assertSame(15, $component->instance()->users->total());

        $component->call('gotoPage', 2);
        $this->assertCount(5, $component->instance()->users);
    }

    public function test_soft_deleted_users_are_not_listed(): void
    {
        $deleted = User::factory()->create(['name' => 'Deleted Person']);
        $deleted->delete();

        $this->assertNotContains('Deleted Person', $this->usersPage()->instance()->users->pluck('name'));
    }

    public function test_users_can_be_filtered_by_status(): void
    {
        User::factory(2)->create(['status' => 'inactive']);
        User::factory(3)->create(['status' => 'suspended']);

        $component = $this->usersPage()->set('status', 'suspended');

        $this->assertSame(3, $component->instance()->users->total());
        $this->assertSame(['suspended'], $component->instance()->users->pluck('status')->unique()->values()->all());
    }

    public function test_an_unknown_status_filter_shows_all_users(): void
    {
        User::factory(2)->create(['status' => 'inactive']);

        $component = $this->usersPage()->set('status', 'bogus');

        $this->assertSame(3, $component->instance()->users->total());
    }

    public function test_users_can_be_searched_by_name_email_or_phone(): void
    {
        User::factory()->create(['name' => 'Alice Tan', 'email' => 'alice@example.test', 'phone_number' => '011-1111111']);
        User::factory()->create(['name' => 'Bob Lim', 'email' => 'bob@example.test', 'phone_number' => '012-2222222']);

        $component = $this->usersPage();

        $this->assertSame(['Alice Tan'], $component->set('search', 'alice')->instance()->users->pluck('name')->all());
        $this->assertSame(['Bob Lim'], $component->set('search', 'bob@example')->instance()->users->pluck('name')->all());
        $this->assertSame(['Bob Lim'], $component->set('search', '012-2222')->instance()->users->pluck('name')->all());
    }

    public function test_search_and_status_filter_work_together(): void
    {
        User::factory()->create(['name' => 'Zed Active', 'status' => 'active']);
        User::factory()->create(['name' => 'Zed Suspended', 'status' => 'suspended']);

        $component = $this->usersPage()->set('search', 'Zed');
        $this->assertSame(2, $component->instance()->users->total());

        $component->set('status', 'suspended');
        $this->assertSame(['Zed Suspended'], $component->instance()->users->pluck('name')->all());
    }

    public function test_search_treats_percent_and_underscore_as_plain_text(): void
    {
        User::factory(3)->create();

        $this->assertSame(0, $this->usersPage()->set('search', '%')->instance()->users->total());
        $this->assertSame(0, $this->usersPage()->set('search', '_')->instance()->users->total());
    }

    public function test_changing_the_search_or_filter_goes_back_to_page_one_and_clears_the_selection(): void
    {
        User::factory(14)->create();

        $component = $this->usersPage()->call('gotoPage', 2)->set('selected', ['5'])->set('search', 'a');
        $this->assertSame(1, $component->instance()->users->currentPage());
        $this->assertSame([], $component->get('selected'));

        $component->call('gotoPage', 2)->set('selected', ['5'])->set('status', 'active');
        $this->assertSame(1, $component->instance()->users->currentPage());
        $this->assertSame([], $component->get('selected'));
    }

    public function test_search_and_filter_can_be_set_from_the_url(): void
    {
        User::factory()->create(['name' => 'Zed Active', 'status' => 'active']);
        User::factory()->create(['name' => 'Zed Suspended', 'status' => 'suspended']);

        $this->actingAs($this->admin)
            ->get(route('users.index', ['search' => 'Zed', 'status' => 'active']))
            ->assertOk()
            ->assertSee('Zed Active')
            ->assertDontSee('Zed Suspended');
    }

    // ---------------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------------

    public function test_admin_can_create_a_user(): void
    {
        $this->fillUserForm($this->usersPage()->call('create'))
            ->call('save')
            ->assertHasNoErrors();

        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('New User', $user->name);
        $this->assertSame('0123456789', $user->phone_number);
        $this->assertSame('active', $user->status);
        $this->assertFalse($user->is_admin);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_the_is_admin_checkbox_creates_an_admin(): void
    {
        $this->fillUserForm($this->usersPage()->call('create'))
            ->set('form.is_admin', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(User::where('email', 'new@example.test')->firstOrFail()->is_admin);
    }

    public function test_creating_a_user_validates_required_fields(): void
    {
        $this->usersPage()
            ->call('create')
            ->set('form.status', '')
            ->call('save')
            ->assertHasErrors([
                'form.name' => 'required',
                'form.email' => 'required',
                'form.phone_number' => 'required',
                'form.password' => 'required',
                'form.status' => 'required',
            ]);
    }

    public function test_creating_a_user_validates_formats(): void
    {
        $this->fillUserForm($this->usersPage()->call('create'))
            ->set('form.email', 'not-an-email')
            ->set('form.phone_number', str_repeat('1', 21))
            ->set('form.password_confirmation', 'different')
            ->set('form.status', 'bogus')
            ->call('save')
            ->assertHasErrors([
                'form.email' => 'email',
                'form.phone_number' => 'max',
                'form.password' => 'confirmed',
                'form.status' => 'in',
            ]);
    }

    public function test_email_and_phone_number_must_be_unique(): void
    {
        $existing = User::factory()->create();

        $this->fillUserForm($this->usersPage()->call('create'))
            ->set('form.email', $existing->email)
            ->set('form.phone_number', $existing->phone_number)
            ->call('save')
            ->assertHasErrors(['form.email' => 'unique', 'form.phone_number' => 'unique']);
    }

    public function test_a_soft_deleted_users_email_and_phone_number_stay_taken(): void
    {
        $deleted = User::factory()->create();
        $deleted->delete();

        $this->fillUserForm($this->usersPage()->call('create'))
            ->set('form.email', $deleted->email)
            ->set('form.phone_number', $deleted->phone_number)
            ->call('save')
            ->assertHasErrors(['form.email' => 'unique', 'form.phone_number' => 'unique']);
    }

    // ---------------------------------------------------------------------
    // Update
    // ---------------------------------------------------------------------

    public function test_admin_can_update_a_user(): void
    {
        $user = User::factory()->create();

        $this->usersPage()
            ->call('edit', $user->id)
            ->assertSet('form.email', $user->email)
            ->set('form.name', 'Renamed')
            ->set('form.status', 'suspended')
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertSame('Renamed', $user->name);
        $this->assertSame('suspended', $user->status);
    }

    public function test_updating_keeps_the_users_own_email_and_phone_number_valid(): void
    {
        $user = User::factory()->create();

        $this->usersPage()
            ->call('edit', $user->id)
            ->set('form.name', 'Renamed')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_updating_rejects_another_users_email_and_phone_number(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->usersPage()
            ->call('edit', $user->id)
            ->set('form.email', $other->email)
            ->set('form.phone_number', $other->phone_number)
            ->call('save')
            ->assertHasErrors(['form.email' => 'unique', 'form.phone_number' => 'unique']);
    }

    public function test_a_blank_password_keeps_the_current_password(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;

        $this->usersPage()
            ->call('edit', $user->id)
            ->set('form.name', 'Renamed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_a_new_password_replaces_the_current_one(): void
    {
        $user = User::factory()->create();

        $this->usersPage()
            ->call('edit', $user->id)
            ->set('form.password', 'new-password')
            ->set('form.password_confirmation', 'new-password')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_the_is_admin_checkbox_can_promote_and_demote_other_users(): void
    {
        $user = User::factory()->create();

        $this->usersPage()->call('edit', $user->id)->set('form.is_admin', true)->call('save')->assertHasNoErrors();
        $this->assertTrue($user->fresh()->is_admin);

        $this->usersPage()->call('edit', $user->id)->set('form.is_admin', false)->call('save')->assertHasNoErrors();
        $this->assertFalse($user->fresh()->is_admin);
    }

    // ---------------------------------------------------------------------
    // Self-protection
    // ---------------------------------------------------------------------

    public function test_admin_cannot_remove_their_own_admin_flag(): void
    {
        $this->usersPage()
            ->call('edit', $this->admin->id)
            ->set('form.is_admin', false)
            ->call('save')
            ->assertHasErrors(['form.is_admin'])
            ->assertSee('You cannot remove your own admin access.');

        $this->assertTrue($this->admin->fresh()->is_admin);
    }

    public function test_admin_cannot_change_their_own_status(): void
    {
        $this->usersPage()
            ->call('edit', $this->admin->id)
            ->set('form.status', 'suspended')
            ->call('save')
            ->assertHasErrors(['form.status'])
            ->assertSee('You cannot change your own status.');

        $this->assertSame('active', $this->admin->fresh()->status);
    }

    public function test_admin_can_still_edit_their_own_other_details(): void
    {
        $this->usersPage()
            ->call('edit', $this->admin->id)
            ->set('form.name', 'New Name')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New Name', $this->admin->fresh()->name);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $this->usersPage()
            ->call('confirmDelete', $this->admin->id)
            ->call('delete');

        $this->assertNotSoftDeleted($this->admin);
    }

    public function test_the_admins_own_row_has_no_delete_action_or_checkbox(): void
    {
        $other = User::factory()->create();

        $this->usersPage()
            ->assertSeeHtml('confirmDelete('.$other->id.')')
            ->assertDontSeeHtml('confirmDelete('.$this->admin->id.')')
            ->assertSeeHtml('value="'.$other->id.'"')
            ->assertDontSeeHtml('value="'.$this->admin->id.'"');
    }

    // ---------------------------------------------------------------------
    // Delete
    // ---------------------------------------------------------------------

    public function test_admin_can_soft_delete_a_user(): void
    {
        $user = User::factory()->create();

        $this->usersPage()
            ->call('confirmDelete', $user->id)
            ->call('delete');

        $this->assertSoftDeleted($user);
    }

    public function test_bulk_delete_soft_deletes_the_selected_users_in_a_single_query(): void
    {
        $users = User::factory(3)->create();
        $untouched = User::factory()->create();

        $component = $this->usersPage()->set('selected', $users->pluck('id')->map(fn (int $id): string => (string) $id)->all());

        DB::enableQueryLog();
        $component->call('deleteSelected');
        $userUpdates = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'update "users"'));
        DB::disableQueryLog();

        $this->assertCount(1, $userUpdates);
        $users->each(fn (User $user) => $this->assertSoftDeleted($user));
        $this->assertNotSoftDeleted($untouched);
        $this->assertSame([], $component->get('selected'));
    }

    public function test_bulk_delete_always_skips_the_logged_in_admin(): void
    {
        $user = User::factory()->create();

        $this->usersPage()
            ->set('selected', [(string) $user->id, (string) $this->admin->id])
            ->call('deleteSelected');

        $this->assertSoftDeleted($user);
        $this->assertNotSoftDeleted($this->admin);
    }

    /**
     * Open the Users page as the admin.
     */
    private function usersPage(): Testable
    {
        $this->actingAs($this->admin);

        return Livewire::test('pages::users.index');
    }

    /**
     * Fill the create/edit form with valid data.
     */
    private function fillUserForm(Testable $component): Testable
    {
        return $component
            ->set('form.name', 'New User')
            ->set('form.email', 'new@example.test')
            ->set('form.phone_number', '0123456789')
            ->set('form.password', 'password')
            ->set('form.password_confirmation', 'password')
            ->set('form.status', 'active');
    }
}
