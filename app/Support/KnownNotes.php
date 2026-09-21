<?php

namespace App\Support;

/** Optional download optimization. Missing or malformed hints always fall back to full notes. */
final class KnownNotes
{
    public static function shouldDownload(mixed $known, string $id, int $modified, bool $deleted): bool
    {
        // Tombstones must be delivered even if a legacy delete reused the timestamp.
        if ($deleted || $modified <= 0 || !is_array($known) || !array_key_exists($id, $known)) {
            return true;
        }

        return !is_int($known[$id]) || $known[$id] !== $modified;
    }
}
