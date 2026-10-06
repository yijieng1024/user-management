<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

/**
 * Unit tests for the User model's access rules and the filter() query scope.
 * No database is needed: users are built in memory and the scope is checked
 * through the SQL and bindings it produces.
 */
class UserTest extends TestCase
{
    public function test_is_active_is_true_only_for_the_active_status(): void
    {
        $this->assertTrue($this->makeUser(status: 'active')->isActive());
        $this->assertFalse($this->makeUser(status: 'inactive')->isActive());
        $this->assertFalse($this->makeUser(status: 'suspended')->isActive());
    }

    public function test_only_active_admins_have_admin_access(): void
    {
        $this->assertTrue($this->makeUser(isAdmin: true, status: 'active')->hasAdminAccess());
        $this->assertFalse($this->makeUser(isAdmin: true, status: 'inactive')->hasAdminAccess());
        $this->assertFalse($this->makeUser(isAdmin: true, status: 'suspended')->hasAdminAccess());
        $this->assertFalse($this->makeUser(isAdmin: false, status: 'active')->hasAdminAccess());
    }

    public function test_admin_access_denied_reason_is_null_for_active_admins(): void
    {
        $this->assertNull($this->makeUser(isAdmin: true, status: 'active')->adminAccessDeniedReason());
    }

    public function test_admin_access_denied_reason_explains_a_missing_admin_flag(): void
    {
        $this->assertSame('You do not have admin access.', $this->makeUser(isAdmin: false, status: 'active')->adminAccessDeniedReason());
    }

    public function test_admin_access_denied_reason_explains_an_inactive_account(): void
    {
        $this->assertSame('Your account is not active.', $this->makeUser(isAdmin: true, status: 'inactive')->adminAccessDeniedReason());
        $this->assertSame('Your account is not active.', $this->makeUser(isAdmin: true, status: 'suspended')->adminAccessDeniedReason());
    }

    public function test_a_missing_admin_flag_is_reported_before_an_inactive_status(): void
    {
        $this->assertSame('You do not have admin access.', $this->makeUser(isAdmin: false, status: 'suspended')->adminAccessDeniedReason());
    }

    public function test_the_status_list_contains_exactly_the_three_statuses(): void
    {
        $this->assertSame(['active', 'inactive', 'suspended'], User::STATUSES);
    }

    public function test_filter_with_no_status_or_search_adds_no_conditions(): void
    {
        foreach ([[null, null], ['', ''], ['', '   ']] as [$status, $search]) {
            $query = User::query()->filter($status, $search);

            $this->assertStringNotContainsString('"status"', $query->toSql());
            $this->assertStringNotContainsString('like', $query->toSql());
            $this->assertSame([], $query->getBindings());
        }
    }

    public function test_filter_applies_a_known_status(): void
    {
        foreach (User::STATUSES as $status) {
            $query = User::query()->filter($status, null);

            $this->assertStringContainsString('"status" = ?', $query->toSql());
            $this->assertSame([$status], $query->getBindings());
        }
    }

    public function test_filter_ignores_an_unknown_status(): void
    {
        $query = User::query()->filter('deleted', null);

        $this->assertStringNotContainsString('"status"', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function test_filter_searches_name_email_and_phone_number_in_one_group(): void
    {
        $query = User::query()->filter(null, 'alice');

        $this->assertStringContainsString('("name" like ? or "email" like ? or "phone_number" like ?)', $query->toSql());
        $this->assertSame(['%alice%', '%alice%', '%alice%'], $query->getBindings());
    }

    public function test_filter_combines_status_and_search_with_and(): void
    {
        $query = User::query()->filter('suspended', 'alice');

        $this->assertStringContainsString('"status" = ? and ("name" like ?', $query->toSql());
        $this->assertSame(['suspended', '%alice%', '%alice%', '%alice%'], $query->getBindings());
    }

    public function test_filter_trims_the_search_term(): void
    {
        $this->assertSame(['%bob%', '%bob%', '%bob%'], User::query()->filter(null, '  bob  ')->getBindings());
    }

    public function test_filter_escapes_like_wildcards_in_the_search_term(): void
    {
        $this->assertSame(
            ['%50\%\_off\\\\%', '%50\%\_off\\\\%', '%50\%\_off\\\\%'],
            User::query()->filter(null, '50%_off\\')->getBindings(),
        );
    }

    public function test_the_jwt_identifier_is_the_primary_key_and_there_are_no_custom_claims(): void
    {
        $user = $this->makeUser();
        $user->id = 42;

        $this->assertSame(42, $user->getJWTIdentifier());
        $this->assertSame([], $user->getJWTCustomClaims());
    }

    public function test_password_and_remember_token_are_hidden_from_serialization(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['password' => 'secret-hash', 'remember_token' => 'secret-token']);

        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
    }

    public function test_is_admin_is_not_mass_assignable(): void
    {
        $user = new User(['name' => 'Someone', 'is_admin' => true]);

        $this->assertSame('Someone', $user->name);
        $this->assertNull($user->getAttributes()['is_admin'] ?? null);
    }

    /**
     * Build an unsaved user with the given admin flag and status.
     */
    private function makeUser(bool $isAdmin = true, string $status = 'active'): User
    {
        $user = new User;
        $user->forceFill(['name' => 'Test User', 'is_admin' => $isAdmin, 'status' => $status]);

        return $user;
    }
}
