<?php

namespace Tests\Feature\Suppliers;

use Tests\TestCase;

class SupplierVoucherPresentationTest extends TestCase
{
    public function test_supplier_voucher_modal_uses_vietnamese_view_model_not_raw_api_keys(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/Suppliers/Index.vue'));

        $this->assertStringContainsString('supplierVoucherDisplayRows', $vue);
        $this->assertStringNotContainsString('v-for="(val, key) in supplierVoucher.payload.data"', $vue);

        foreach ([
            'Mã phiếu',
            'Trạng thái',
            'Ngày nhập',
            'Nhà cung cấp',
            'Mã nhà cung cấp',
            'Người tạo',
            'Tổng tiền',
            'Giảm giá',
            'Đã thanh toán',
            'Còn phải trả',
            'Phương thức thanh toán',
        ] as $label) {
            $this->assertStringContainsString($label, $vue);
        }
    }
}
