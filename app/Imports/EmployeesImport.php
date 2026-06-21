<?php

namespace App\Imports;

use App\Models\Employee;
use App\Support\ImportProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Events\AfterImport;
use Maatwebsite\Excel\Events\BeforeImport;
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
 * existing employee are updated; rows with a new email are created. The single
 * chunked upsert does both in one statement.
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
    WithEvents,
    SkipsOnFailure,
    SkipsOnError,
    ShouldQueue
{
    use Importable;
    use SkipsErrors;

    /**
     * Columns refreshed on an existing employee when its email already matches.
     * (On insert, every column including email is written.)
     */
    private const UPDATABLE = ['first_name', 'last_name', 'phone', 'address', 'salary'];

    /**
     * @param  string|null  $importId  Tracking id used to report live progress
     *                                 (rows processed / status) to the frontend.
     */
    public function __construct(private readonly ?string $importId = null) {}

    /**
     * @param  Collection<int, Collection<string, mixed>>  $rows
     */
    public function collection(Collection $rows): void
    {
        $records = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));

            if ($email === '') {
                $skipped++;

                continue;
            }

            $records[] = [
                'email' => $email,
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'phone' => $row['phone'],
                'address' => $row['address'],
                'salary' => $row['salary'],
            ];
        }

        if ($records !== []) {
            // One batched UPSERT keyed on email: rows whose email already exists
            // are UPDATEd (only the UPDATABLE columns), rows with a new email are
            // INSERTed. Eloquent fills created_at/updated_at automatically.
            Employee::upsert($records, ['email'], self::UPDATABLE);
        }

        // Advance the live progress counter by every row we saw in this chunk
        // (processed = upserted + skipped), so the frontend bar reaches total.
        if ($this->importId !== null) {
            ImportProgress::advance($this->importId, $rows->count());
        }

        Log::info('EmployeesImport chunk processed', [
            'upserted' => count($records),
            'skipped' => $skipped,
        ]);
    }

    /**
     * Mark the import processing/completed around the chunk jobs. Registered via
     * closures (not $this) so they stay serializable for the queued import.
     *
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        $importId = $this->importId;

        if ($importId === null) {
            return [];
        }

        return [
            BeforeImport::class => fn () => ImportProgress::markProcessing($importId),
            AfterImport::class => fn () => ImportProgress::markCompleted($importId),
        ];
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
