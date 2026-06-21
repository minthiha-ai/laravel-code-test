<?php

namespace App\GraphQL\Queries;

use App\Support\ImportProgress;

final class ImportStatus
{
    /**
     * @param  array{id: string}  $args
     * @return array{status: string, processed: int, total: int}
     */
    public function __invoke(null $_, array $args): array
    {
        return ImportProgress::get($args['id']);
    }
}
