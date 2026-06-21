<?php

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['first_name', 'last_name', 'email', 'phone', 'address', 'salary'])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'salary' => 'decimal:2',
        ];
    }

    /**
     * Stable ordering for pagination (Postgres heap order shifts on update).
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('id');
    }
}
