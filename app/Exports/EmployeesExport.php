<?php

namespace App\Exports;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Exports all employees to a spreadsheet.
 *
 * Scale strategy: FromQuery hands maatwebsite a query builder, which it
 * iterates in chunks (1,000 rows by default) while writing the file. The full
 * 10k result set is never loaded into memory as a collection.
 *
 * @implements WithMapping<Employee>
 */
class EmployeesExport implements FromQuery, WithHeadings, WithMapping
{
    /**
     * The query whose results are streamed into the export.
     *
     * @return Builder<Employee>
     */
    public function query(): Builder
    {
        return Employee::query()->orderBy('id');
    }

    /**
     * Header row.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['first_name', 'last_name', 'email', 'phone', 'address', 'salary'];
    }

    /**
     * Map each employee to a spreadsheet row. Column order matches headings()
     * (and the import format) so an exported file can be re-imported as-is.
     *
     * @param  Employee  $employee
     * @return array<int, mixed>
     */
    public function map($employee): array
    {
        return [
            $employee->first_name,
            $employee->last_name,
            $employee->email,
            $employee->phone,
            $employee->address,
            $employee->salary,
        ];
    }
}
