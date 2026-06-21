<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed progress for a queued import, polled by the frontend.
 * The processed count is a separate atomic counter so concurrent workers
 * don't lose increments. Status: queued -> processing -> completed/failed.
 */
final class ImportProgress
{
    private const TTL_SECONDS = 3600;

    private static function key(string $id): string
    {
        return "import-progress:{$id}";
    }

    private static function processedKey(string $id): string
    {
        return "import-progress:{$id}:processed";
    }

    /**
     * @return array{status: string, processed: int, total: int}
     */
    public static function get(string $id): array
    {
        /** @var array{status: string, total: int}|null $meta */
        $meta = Cache::get(self::key($id));
        if ($meta === null) {
            return ['status' => 'unknown', 'processed' => 0, 'total' => 0];
        }

        $processed = (int) Cache::get(self::processedKey($id), 0);
        $total = $meta['total'];

        if ($total > 0 && $processed > $total) {
            $processed = $total;
        }

        return ['status' => $meta['status'], 'processed' => $processed, 'total' => $total];
    }

    public static function start(string $id, int $total): void
    {
        self::putMeta($id, 'queued', $total);
        Cache::put(self::processedKey($id), 0, self::TTL_SECONDS);
    }

    public static function markProcessing(string $id): void
    {
        self::setStatus($id, 'processing');
    }

    public static function advance(string $id, int $by): void
    {
        Cache::increment(self::processedKey($id), $by);
        self::setStatus($id, 'processing');
    }

    public static function markCompleted(string $id): void
    {
        $total = (int) (Cache::get(self::key($id))['total'] ?? 0);
        if ($total > 0) {
            Cache::put(self::processedKey($id), $total, self::TTL_SECONDS);
        }
        self::setStatus($id, 'completed');
    }

    public static function markFailed(string $id): void
    {
        self::setStatus($id, 'failed');
    }

    private static function putMeta(string $id, string $status, int $total): void
    {
        Cache::put(self::key($id), ['status' => $status, 'total' => $total], self::TTL_SECONDS);
    }

    private static function setStatus(string $id, string $status): void
    {
        $total = (int) (Cache::get(self::key($id))['total'] ?? 0);
        self::putMeta($id, $status, $total);
    }
}
