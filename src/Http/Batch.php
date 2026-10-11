<?php
require_once __DIR__ . '/Http.php';

/**
 * Small helpers for the multi-select / multi-add endpoints.
 *
 * Every batch action loops over the EXISTING single-record service method (same validation, same
 * transaction, same permission checks), so a bulk action can never do something the one-by-one
 * action would refuse. One record failing never stops or rolls back the others — each record's
 * outcome is reported individually.
 */
final class Batch
{
    /** Hard cap per request: keeps one request well inside PHP's time limit. */
    public const MAX_IDS  = 200;
    public const MAX_ROWS = 100;

    /** @return int[] unique, positive integer ids (422 when none / too many) */
    public static function ids($raw, int $max = self::MAX_IDS): array
    {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        $ids = [];
        foreach ($raw as $v) {
            $n = (int) $v;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
        $ids = array_values($ids);
        if (!$ids) {
            throw new HttpException(422, 'Select at least one record first. Nothing was changed.');
        }
        if (count($ids) > $max) {
            throw new HttpException(422, 'Too many records at once (maximum ' . $max . '). Select fewer and try again.');
        }
        return $ids;
    }

    /** @return array<int,array<string,mixed>> the submitted rows (422 when none / too many) */
    public static function rows($raw, int $max = self::MAX_ROWS): array
    {
        $rows = [];
        if (is_array($raw)) {
            foreach ($raw as $r) {
                if (is_array($r)) {
                    $rows[] = $r;
                }
            }
        }
        if (!$rows) {
            throw new HttpException(422, 'Enter at least one record to add.');
        }
        if (count($rows) > $max) {
            throw new HttpException(422, 'Too many records at once (maximum ' . $max . '). Add them in smaller groups.');
        }
        return $rows;
    }

    /** Turn a caught exception into a safe, user-facing failure entry. */
    public static function failure(Throwable $e, string $fallback): array
    {
        if ($e instanceof HttpException) {
            $extra = $e->extra();
            return ['message' => $e->getMessage(), 'errors' => is_array($extra['errors'] ?? null) ? $extra['errors'] : new stdClass()];
        }
        error_log('[sf9] batch item failed: ' . get_class($e) . ': ' . $e->getMessage());
        return ['message' => $fallback, 'errors' => new stdClass()];
    }

    public static function plural(int $n, string $noun): string
    {
        return $n . ' ' . $noun . ($n === 1 ? '' : 's');
    }
}
