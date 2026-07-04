<?php

namespace Tests\Feature\Invoices;

use Tests\TestCase;

class InvoiceIndexUtf8Test extends TestCase
{
    public function test_invoice_index_vue_has_no_mojibake_literals(): void
    {
        $path = base_path('resources/js/Pages/Invoices/Index.vue');
        $contents = file_get_contents($path);

        $patterns = [
            'Ãƒ',
            'Ã„',
            'Ã†',
            'Ã¡Âº',
            'Ã¡Â»',
            'Ã‚',
            'Ã¢â‚¬â€',
            'Ã¢â‚¬â€œ',
        ];

        foreach ($patterns as $pattern) {
            $this->assertStringNotContainsString($pattern, $contents, "Invoice index contains mojibake pattern {$pattern}");
        }

        foreach ([
            'Hóa đơn',
            'Mã hóa đơn',
            'Thời gian',
            'Chi nhánh',
            'Trạng thái hóa đơn',
            'Công nợ',
            'Hình thức thanh toán',
            'Người bán',
            'Khách hàng',
            'Khách đã trả',
        ] as $label) {
            $this->assertStringContainsString($label, $contents, "Invoice index must contain UTF-8 label {$label}");
        }
    }
}
