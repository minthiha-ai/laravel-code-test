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

    private const UPDATABLE = ['first_name', 'last_name', 'phone', 'address', 'salary'];

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
            // Update existing emails, insert new ones, in a single statement.
            Employee::upsert($records, ['email'], self::UPDATABLE);
        }

        if ($this->importId !== null) {
            ImportProgress::advance($this->importId, $rows->count());
        }

        Log::info('EmployeesImport chunk processed', [
            'upserted' => count($records),
            'skipped' => $skipped,
        ]);
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        $importId = $this->importId;

        if ($importId === null) {
            return [];
        }

        // Closures capture only the id string so they stay serializable on the queue.
        return [
            BeforeImport::class => fn () => ImportProgress::markProcessing($importId),
            AfterImport::class => fn () => ImportProgress::markCompleted($importId),
        ];
    }

    /**
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

    public function chunkSize(): int
    {
        return 1000;
    }

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
