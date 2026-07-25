<?php

namespace Tests\Unit\Services;

use App\Models\Product;
use App\Services\Integrations\PcWebsite\PcProductAvailabilityService;
use PHPUnit\Framework\TestCase;

class PcProductAvailabilityServiceTest extends TestCase
{
    public function test_repairing_serial_product_is_never_available(): void
    {
        $product = new Product([
            'type' => 'standard', 'stock_quantity' => 2, 'has_serial' => true,
            'is_active' => true, 'sell_directly' => true,
        ]);
        $product->setAttribute('reserved_quantity', 0);
        $product->setAttribute('ready_serial_quantity', 0);
        $product->setAttribute('repairing_serial_quantity', 2);

        $payload = (new PcProductAvailabilityService)->payload($product);

        $this->assertSame('repairing', $payload['inventory']['status']);
        $this->assertSame(0, $payload['inventory']['available_quantity']);
        $this->assertFalse($payload['availability']['is_available']);
        $this->assertTrue($payload['availability']['is_under_repair']);
    }

    public function test_ready_serials_are_reduced_by_external_reservations(): void
    {
        $product = new Product([
            'type' => 'standard', 'stock_quantity' => 3, 'has_serial' => true,
            'is_active' => true, 'sell_directly' => true,
        ]);
        $product->setAttribute('reserved_quantity', 1);
        $product->setAttribute('ready_serial_quantity', 2);
        $product->setAttribute('repairing_serial_quantity', 1);

        $payload = (new PcProductAvailabilityService)->payload($product);

        $this->assertSame('available', $payload['inventory']['status']);
        $this->assertSame(1, $payload['inventory']['available_quantity']);
        $this->assertTrue($payload['availability']['is_under_repair']);
    }
}
