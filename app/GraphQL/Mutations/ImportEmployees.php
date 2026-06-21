<?php

namespace App\GraphQL\Mutations;

use App\Imports\EmployeesImport;
use App\Support\ImportProgress;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

final class ImportEmployees
{
    /**
     * @param  array{file: UploadedFile}  $args
     * @return array{message: string, queued: bool, import_id: string}
     */
    public function __invoke(null $_, array $args): array
    {
        $path = $args['file']->store('imports');

        $importId = (string) Str::uuid();
        ImportProgress::start($importId, $this->countRows($path));

        (new EmployeesImport($importId))->queue($path);

        return [
            'message' => 'Import accepted. Employees are being imported in the background.',
            'queued' => true,
            'import_id' => $importId,
        ];
    }

    /**
     * Count data rows without loading the sheet (reads dimensions only).
     * Returns 0 on failure; the frontend then shows an indeterminate bar.
     */
    private function countRows(string $path): int
    {
        try {
            $absolute = Storage::path($path);
            $reader = IOFactory::createReaderForFile($absolute);
            $reader->setReadDataOnly(true);
            $info = $reader->listWorksheetInfo($absolute);

            return max(0, (int) ($info[0]['totalRows'] ?? 0) - 1);
        } catch (Throwable) {
            return 0;
        }
    }
}
