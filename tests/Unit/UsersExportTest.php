<?php

namespace Tests\Unit;

use App\Exports\UsersExport;
use App\Models\User;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Unit tests for how UsersExport builds its query and maps users to rows,
 * without generating a spreadsheet file.
 */
class UsersExportTest extends TestCase
{
    public function test_headings_are_in_the_expected_order(): void
    {
        $this->assertSame(['Name', 'Email', 'Phone Number', 'Status', 'Is Admin', 'Created At'], (new UsersExport)->headings());
    }

    public function test_a_user_is_mapped_to_a_row_matching_the_headings(): void
    {
        $user = $this->makeUser(isAdmin: true, status: 'suspended');

        $this->assertSame(
            ['Jane Doe', 'jane@example.test', '0123456789', 'Suspended', 'Yes', '2026-10-07 15:30:00'],
            (new UsersExport)->map($user),
        );
    }

    public function test_is_admin_is_mapped_to_yes_or_no(): void
    {
        $export = new UsersExport;

        $this->assertSame('Yes', $export->map($this->makeUser(isAdmin: true))[4]);
        $this->assertSame('No', $export->map($this->makeUser(isAdmin: false))[4]);
    }

    public function test_a_missing_created_at_is_mapped_to_an_empty_cell(): void
    {
        $user = $this->makeUser();
        $user->created_at = null;

        $this->assertSame('', (new UsersExport)->map($user)[5]);
    }

    public function test_the_query_selects_only_the_exported_columns(): void
    {
        $sql = (new UsersExport)->query()->toSql();

        $this->assertStringContainsString('select "id", "name", "email", "phone_number", "status", "is_admin", "created_at" from "users"', $sql);
        $this->assertStringNotContainsString('password', $sql);
        $this->assertStringNotContainsString('remember_token', $sql);
    }

    public function test_the_query_excludes_soft_deleted_users_and_has_a_stable_order(): void
    {
        $sql = (new UsersExport)->query()->toSql();

        $this->assertStringContainsString('"users"."deleted_at" is null', $sql);
        $this->assertStringEndsWith('order by "id" asc', $sql);
    }

    public function test_users_are_read_in_chunks_of_500(): void
    {
        $this->assertSame(500, (new UsersExport)->chunkSize());
    }

    public function test_numeric_looking_values_are_written_as_text(): void
    {
        $cell = (new Spreadsheet)->getActiveSheet()->getCell('A1');

        (new UsersExport)->bindValue($cell, '0123456789');

        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame('0123456789', $cell->getValue());
    }

    /**
     * Build an unsaved user for mapping.
     */
    private function makeUser(bool $isAdmin = false, string $status = 'active'): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'phone_number' => '0123456789',
            'status' => $status,
            'is_admin' => $isAdmin,
            'created_at' => Carbon::create(2026, 10, 7, 15, 30, 0),
        ]);

        return $user;
    }
}
