<?php

namespace Tests\Feature\DateTime;

use App\Support\BusinessDateTime;
use Carbon\Carbon;
use Tests\TestCase;

class BusinessDateTimeFallbackTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_for_create_uses_now_when_empty(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 14:35:00'));

        $this->assertSame(
            '2026-06-25 14:35:00',
            BusinessDateTime::forCreate(null)->toDateTimeString()
        );

        $this->assertSame(
            '2026-06-25 14:35:00',
            BusinessDateTime::forCreate('')->toDateTimeString()
        );
    }

    public function test_for_create_parses_selected_datetime(): void
    {
        $this->assertSame(
            '2026-06-20 10:30:00',
            BusinessDateTime::forCreate('2026-06-20T10:30')->toDateTimeString()
        );
    }

    public function test_for_update_keeps_current_value_when_empty(): void
    {
        $old = Carbon::parse('2026-06-20 09:15:00');

        $this->assertSame(
            '2026-06-20 09:15:00',
            BusinessDateTime::forUpdate(null, $old)->toDateTimeString()
        );

        $this->assertSame(
            '2026-06-20 09:15:00',
            BusinessDateTime::forUpdate('', $old)->toDateTimeString()
        );
    }

    public function test_nullable_returns_null_for_empty_values(): void
    {
        $this->assertNull(BusinessDateTime::nullable(null));
        $this->assertNull(BusinessDateTime::nullable(''));
    }
}
