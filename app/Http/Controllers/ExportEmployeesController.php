<?php

namespace App\Http\Controllers;

use App\Exports\EmployeesExport;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportEmployeesController extends Controller
{
    /**
     * Stream all employees as an .xlsx download.
     *
     * Exposed as REST rather than GraphQL because GraphQL cannot return a
     * binary file body. The route is still Passport-guarded (auth:api).
     */
    public function __invoke(): BinaryFileResponse
    {
        return Excel::download(new EmployeesExport(), 'employees.xlsx');
    }
}
