<?php

namespace App\Http\Controllers;

use App\Exports\UsersExport;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UserExportController extends Controller
{
    /**
     * Download all users as an Excel file, e.g. users_2026-10-07_153000.xlsx.
     */
    public function __invoke(): BinaryFileResponse
    {
        return Excel::download(new UsersExport, 'users_'.now()->format('Y-m-d_His').'.xlsx');
    }
}
