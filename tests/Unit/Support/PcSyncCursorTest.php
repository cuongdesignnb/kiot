<?php

namespace Tests\Unit\Support;

use App\Exceptions\PcIntegrationException;
use App\Support\Integrations\PcWebsite\PcSyncCursor;
use Illuminate\Pagination\Cursor;
use PHPUnit\Framework\TestCase;

class PcSyncCursorTest extends TestCase
{
    public function test_cursor_round_trip_is_opaque_and_preserves_tie_breaker(): void
    {
        $encoded = (new Cursor(['updated_at' => '2026-07-25 12:00:00', 'id' => 42], true))->encode();
        $decoded = PcSyncCursor::decode($encoded);

        $this->assertNotSame('', $encoded);
        $this->assertSame(42, $decoded?->parameter('id'));
        $this->assertSame('2026-07-25 12:00:00', $decoded?->parameter('updated_at'));
    }

    public function test_invalid_cursor_has_explicit_contract_error(): void
    {
        $this->expectException(PcIntegrationException::class);
        $this->expectExceptionMessage('Cursor đồng bộ không hợp lệ.');

        PcSyncCursor::decode('not-a-valid-cursor');
    }
}
