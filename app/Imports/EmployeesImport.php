<?php

namespace App\Imports;

use App\Models\Employee;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Validators\Failure;

/**
 * Bulk-updates employees from an uploaded spreadsheet.
 *
 * Scale strategy:
 * - WithChunkReading reads 1,000 rows at a time, so a 10k-row file never loads
 *   into memory at once. Combined with ShouldQueue, each chunk runs as its own
 *   queued job.
 * - Each chunk does ONE select (by email) and ONE batched upsert, instead of a
 *   query per row.
 *
 * Matching is by email (the unique business key). Rows whose email matches an
 * existing employee are updated; rows with no match are skipped and counted —
 * this importer never creates new employees.
 *
 * Note: WithBatchInserts is intentionally NOT used. It only applies to the
 * ToModel concern; with ToCollection it would be a silent no-op. The batching
 * here is done explicitly via the chunked upsert below.
 */
class EmployeesImport implements
    ToCollection,
    WithHeadingRow,
    WithChunkReading,
    WithValidation,
    SkipsOnFailure,
    SkipsOnError,
    ShouldQueue
{
    use Importable;
    use SkipsErrors;

    /**
     * Columns updated on a matched employee.
     */
    private const UPDATABLE = ['first_name', 'last_name', 'phone', 'address', 'salary'];

    /**
     * @param  Collection<int, Collection<string, mixed>>  $rows
     */
    public function collection(Collection $rows): void
    {
        $emails = $rows
            ->pluck('email')
            ->filter()
            ->map(fn ($email): string => mb_strtolower(trim((string) $email)))
            ->unique();

        // Single query: which of these emails already exist?
        $existing = Employee::query()
            ->whereIn('email', $emails)
            ->pluck('email')
            ->map(fn (string $email): string => mb_strtolower($email))
            ->flip();

        $updates = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));

            if ($email === '' || ! $existing->has($email)) {
                $skipped++;

                continue;
            }

            $updates[] = [
                'email' => $email,
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'phone' => $row['phone'],
                'address' => $row['address'],
                'salary' => $row['salary'],
            ];
        }

        if ($updates !== []) {
            // One batched UPSERT keyed on email. Because every row here already
            // matched an existing employee, this only ever UPDATEs — it never
            // inserts a new employee.
            Employee::upsert($updates, ['email'], self::UPDATABLE);
        }

        Log::info('EmployeesImport chunk processed', [
            'updated' => count($updates),
            'skipped' => $skipped,
        ]);
    }

    /**
     * Per-row validation rules. Rows are keyed by heading.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'salary' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * Read 1,000 rows per chunk / queued job.
     */
    public function chunkSize(): int
    {
        return 1000;
    }

    /**
     * Log invalid rows instead of failing the whole import.
     */
    public function onFailure(Failure ...$failures): void
    {
        foreach ($failures as $failure) {
            Log::warning('EmployeesImport row skipped (validation)', [
                'row' => $failure->row(),
                'attribute' => $failure->attribute(),
                'errors' => $failure->errors(),
            ]);
        }
    }
}
