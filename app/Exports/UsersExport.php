<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * Exports every non-deleted user, regardless of the Users page filters.
 *
 * Values are written as text (StringValueBinder) so phone numbers keep
 * their leading zeros instead of being converted to numbers by Excel.
 *
 * @implements WithMapping<User>
 */
class UsersExport extends StringValueBinder implements FromQuery, ShouldAutoSize, WithCustomChunkSize, WithCustomValueBinder, WithHeadings, WithMapping
{
    /**
     * Get the users to export, read from the database in chunks.
     *
     * Only the exported columns are selected, so the password and remember
     * token are never loaded. Soft-deleted users are excluded by the model's
     * SoftDeletes scope. Ordering by the primary key keeps the chunks stable.
     *
     * @return Builder<User>
     */
    public function query(): Builder
    {
        return User::query()
            ->select(['id', 'name', 'email', 'phone_number', 'status', 'is_admin', 'created_at'])
            ->orderBy('id');
    }

    /**
     * Get the number of users read from the database per query.
     */
    public function chunkSize(): int
    {
        return 500;
    }

    /**
     * Get the heading row.
     *
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Name', 'Email', 'Phone Number', 'Status', 'Is Admin', 'Created At'];
    }

    /**
     * Map a user to a spreadsheet row.
     *
     * @param  User  $row
     * @return list<string>
     */
    public function map(mixed $row): array
    {
        return [
            $row->name,
            $row->email,
            $row->phone_number,
            ucfirst($row->status),
            $row->is_admin ? 'Yes' : 'No',
            $row->created_at?->format('Y-m-d H:i:s') ?? '',
        ];
    }
}
