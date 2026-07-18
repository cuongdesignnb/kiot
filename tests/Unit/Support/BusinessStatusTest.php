<?php

namespace Tests\Unit\Support;

use App\Support\Status\BusinessStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BusinessStatusTest extends TestCase
{
    #[DataProvider('cancelledStatuses')]
    public function test_cancelled_statuses_are_normalized_without_changing_the_source(string $status): void
    {
        $original = $status;

        $this->assertTrue(BusinessStatus::isCancelled($status));
        $this->assertSame($original, $status);
    }

    public static function cancelledStatuses(): array
    {
        return [
            'canonical Vietnamese' => ['Đã hủy'],
            'utf8 interpreted as windows-1252' => ['ÄÃ£ há»§y'],
            'English alias' => ['cancelled'],
        ];
    }

    public function test_repair_text_normalizes_historical_partner_target_type_alias(): void
    {
        $this->assertSame('Khách hàng', BusinessStatus::repairText('KhÃ¡ch hÃ ng'));
        $this->assertSame('Nhà cung cấp', BusinessStatus::repairText('NhÃ  cung cáº¥p'));
    }
}
