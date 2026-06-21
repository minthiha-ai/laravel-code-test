<?php

namespace App\GraphQL\Mutations;

use App\Imports\EmployeesImport;
use Illuminate\Http\UploadedFile;

final class ImportEmployees
{
    /**
     * Accept an uploaded spreadsheet and queue a chunked bulk-update import.
     *
     * The file is stored to disk first so the queued chunk jobs can read it.
     * The mutation returns immediately rather than processing 10k rows inline,
     * avoiding HTTP timeouts and high memory use.
     *
     * @param  array{file: UploadedFile}  $args
     * @return array{message: string, queued: bool}
     */
    public function __invoke(null $_, array $args): array
    {
        $file = $args['file'];

        // Persist the upload so the queued workers can read it back.
        $path = $file->store('imports');

        // ShouldQueue + WithChunkReading => each 1,000-row chunk is its own job.
        (new EmployeesImport())->queue($path);

        return [
            'message' => 'Import accepted. Employees are being bulk-updated in the background.',
            'queued' => true,
        ];
    }
}
