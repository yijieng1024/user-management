<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class UserExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
    }

    public function test_admin_can_download_the_export_with_a_timestamped_file_name(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 7)->setTime(15, 30, 0));

        $this->actingAs($this->admin)
            ->get(route('users.export'))
            ->assertOk()
            ->assertDownload('users_2026-10-07_153000.xlsx');
    }

    public function test_non_admins_get_a_403(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('users.export'))
            ->assertForbidden();
    }

    public function test_inactive_admins_get_a_403(): void
    {
        $this->actingAs(User::factory()->admin()->create(['status' => 'suspended']))
            ->get(route('users.export'))
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('users.export'))->assertRedirect(route('login'));
    }

    public function test_the_users_page_links_to_the_export(): void
    {
        $this->actingAs($this->admin)
            ->get(route('users.index'))
            ->assertSee(route('users.export'))
            ->assertSee('Export to Excel');
    }

    public function test_the_export_has_the_expected_heading_row(): void
    {
        $rows = $this->exportRows($this->downloadExport());

        $this->assertSame(['Name', 'Email', 'Phone Number', 'Status', 'Is Admin', 'Created At'], $rows[0]);
    }

    public function test_the_export_maps_each_user_to_a_row(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 7)->setTime(15, 30, 0));
        $user = User::factory()->create([
            'name' => 'Normal User',
            'email' => 'normal@example.test',
            'phone_number' => '012-3456789',
            'status' => 'suspended',
        ]);

        $rows = $this->exportRows($this->downloadExport());
        $row = collect($rows)->firstWhere(0, 'Normal User');

        $this->assertSame(['Normal User', 'normal@example.test', '012-3456789', 'Suspended', 'No', '2026-10-07 15:30:00'], $row);
        $this->assertSame('Yes', collect($rows)->firstWhere(0, 'Admin')[4]);
    }

    public function test_all_non_deleted_users_are_included_and_soft_deleted_users_are_excluded(): void
    {
        User::factory(5)->create(['status' => 'inactive']);
        User::factory()->create(['name' => 'Deleted Person'])->delete();

        $rows = $this->exportRows($this->downloadExport());

        $this->assertCount(1 + 6, $rows);
        $this->assertNotContains('Deleted Person', array_column($rows, 0));
    }

    public function test_the_export_ignores_the_users_page_filters(): void
    {
        User::factory(3)->create(['status' => 'suspended']);

        $rows = $this->exportRows($this->downloadExport(['status' => 'active', 'search' => 'Admin']));

        $this->assertCount(1 + 4, $rows);
    }

    public function test_the_export_never_contains_passwords_or_remember_tokens(): void
    {
        $user = User::factory()->create();

        DB::enableQueryLog();
        $rows = $this->exportRows($this->downloadExport());
        $userSelects = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "users"'));
        DB::disableQueryLog();

        $this->assertNotEmpty($userSelects);
        $userSelects->each(function (array $query): void {
            $this->assertStringNotContainsString('password', $query['query']);
            $this->assertStringNotContainsString('remember_token', $query['query']);
            $this->assertStringNotContainsString('*', $query['query']);
        });

        $cells = collect($rows)->flatten();
        $this->assertFalse($cells->contains($user->password));
        $this->assertFalse($cells->contains($user->remember_token));
    }

    public function test_phone_numbers_keep_their_leading_zero(): void
    {
        User::factory()->create(['name' => 'Zero Phone', 'phone_number' => '0123456789']);

        $rows = $this->exportRows($this->downloadExport());

        $this->assertSame('0123456789', collect($rows)->firstWhere(0, 'Zero Phone')[2]);
    }

    public function test_large_exports_read_users_from_the_database_in_chunks(): void
    {
        User::factory(1100)->create();

        DB::enableQueryLog();
        $rows = $this->exportRows($this->downloadExport());
        $chunkQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "users"') && str_contains($query['query'], 'limit 500'));
        DB::disableQueryLog();

        $this->assertCount(1 + 1101, $rows);
        $this->assertGreaterThanOrEqual(3, $chunkQueries->count());
    }

    /**
     * Download the export as the admin.
     *
     * @param  array<string, string>  $query
     */
    private function downloadExport(array $query = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->get(route('users.export', $query))
            ->assertOk();
    }

    /**
     * Read the downloaded spreadsheet's rows, then delete the temporary file.
     *
     * @return array<int, array<int, mixed>>
     */
    private function exportRows(TestResponse $response): array
    {
        $path = $response->baseResponse->getFile()->getPathname();

        $rows = IOFactory::load($path)->getActiveSheet()->toArray();

        @unlink($path);

        return $rows;
    }
}
