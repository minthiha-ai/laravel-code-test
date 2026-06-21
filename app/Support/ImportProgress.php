<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Tracks the live state of a queued employee import so the frontend can poll it
 * and render a progress bar.
 *
 * State is kept in the cache because the work spans several queued jobs in a
 * separate process from the request that started it. Two cache entries per id:
 *  - "<key>"            => ['status' => string, 'total' => int]
 *  - "<key>:processed"  => int   (an ATOMIC counter, so multiple queue workers
 *                                 processing chunks concurrently can't lose
 *                                 increments via read-modify-write)
 *
 * status: queued -> processing -> completed (or failed).
 */
final class ImportProgress
{
    /** How long a tracked import lingers in the cache. */
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

        // Never report more than the total we promised the UI.
        if ($total > 0 && $processed > $total) {
            $processed = $total;
        }

        return ['status' => $meta['status'], 'processed' => $processed, 'total' => $total];
    }

    private static function putMeta(string $id, string $status, int $total): void
    {
        Cache::put(self::key($id), ['status' => $status, 'total' => $total], self::TTL_SECONDS);
    }

    private static function setStatus(string $id, string $status): void
    {
        /** @var array{status: string, total: int}|null $meta */
        $meta = Cache::get(self::key($id));
        $total = $meta['total'] ?? 0;
        self::putMeta($id, $status, $total);
    }

    /** Record that an import has been accepted, with the expected row total. */
    public static function start(string $id, int $total): void
    {
        self::putMeta($id, 'queued', $total);
        Cache::put(self::processedKey($id), 0, self::TTL_SECONDS);
    }

    public static function markProcessing(string $id): void
    {
        self::setStatus($id, 'processing');
    }

    /** Atomically add the rows handled by a chunk to the processed counter. */
    public static function advance(string $id, int $by): void
    {
        Cache::increment(self::processedKey($id), $by);
        self::setStatus($id, 'processing');
    }

    public static function markCompleted(string $id): void
    {
        // Snap the counter to total on completion (skipped/invalid rows can
        // otherwise leave a gap below 100%).
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
}
