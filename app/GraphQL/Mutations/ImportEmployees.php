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
     * Accept an uploaded spreadsheet and queue a chunked bulk-upsert import.
     *
     * The file is stored to disk first so the queued chunk jobs can read it.
     * The mutation returns immediately rather than processing 10k rows inline,
     * avoiding HTTP timeouts and high memory use. It also hands back an
     * `import_id` the client can poll (via the `importStatus` query) to render a
     * live progress bar.
     *
     * @param  array{file: UploadedFile}  $args
     * @return array{message: string, queued: bool, import_id: string}
     */
    public function __invoke(null $_, array $args): array
    {
        $file = $args['file'];

        // Persist the upload so the queued workers can read it back.
        $path = $file->store('imports');

        $importId = (string) Str::uuid();
        ImportProgress::start($importId, $this->countRows($path));

        // ShouldQueue + WithChunkReading => each 1,000-row chunk is its own job.
        // The import id flows into the importer so each chunk reports progress.
        (new EmployeesImport($importId))->queue($path);

        return [
            'message' => 'Import accepted. Employees are being imported in the background.',
            'queued' => true,
            'import_id' => $importId,
        ];
    }

    /**
     * Cheaply count data rows (excluding the heading) without loading the whole
     * sheet into memory. `listWorksheetInfo` reads only sheet dimensions. On any
     * failure we return 0, and the frontend falls back to an indeterminate bar.
     */
    private function countRows(string $path): int
    {
        try {
            $absolute = Storage::path($path);
            $reader = IOFactory::createReaderForFile($absolute);
            $reader->setReadDataOnly(true);
            $info = $reader->listWorksheetInfo($absolute);
            $totalRows = (int) ($info[0]['totalRows'] ?? 0);

            return max(0, $totalRows - 1); // minus the heading row
        } catch (Throwable) {
            return 0;
        }
    }
}
