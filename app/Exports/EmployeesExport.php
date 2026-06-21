<?php

namespace App\Exports;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * @implements WithMapping<Employee>
 */
class EmployeesExport implements FromQuery, WithHeadings, WithMapping
{
    /**
     * @return Builder<Employee>
     */
    public function query(): Builder
    {
        return Employee::query()->orderBy('id');
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['first_name', 'last_name', 'email', 'phone', 'address', 'salary'];
    }

    /**
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
