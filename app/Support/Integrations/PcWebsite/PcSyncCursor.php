<?php

namespace App\Support\Integrations\PcWebsite;

use App\Exceptions\PcIntegrationException;
use Illuminate\Pagination\Cursor;

final class PcSyncCursor
{
    public static function decode(?string $encoded): ?Cursor
    {
        if ($encoded === null || trim($encoded) === '') {
            return null;
        }

        $cursor = Cursor::fromEncoded($encoded);
        if (! $cursor instanceof Cursor) {
            throw new PcIntegrationException('INVALID_CURSOR', 'Cursor đồng bộ không hợp lệ.', 422);
        }

        return $cursor;
    }
}
