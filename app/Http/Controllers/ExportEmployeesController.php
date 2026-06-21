<?php

namespace App\Http\Controllers;

use App\Exports\EmployeesExport;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportEmployeesController extends Controller
{
    // REST rather than GraphQL: GraphQL can't return a binary file body.
    public function __invoke(): BinaryFileResponse
    {
        return Excel::download(new EmployeesExport(), 'employees.xlsx');
    }
}
