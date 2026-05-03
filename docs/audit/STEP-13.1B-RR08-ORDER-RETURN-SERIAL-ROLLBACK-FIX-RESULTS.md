# STEP-13.1B — Fix RR-08 OrderReturn Serial Rollback

> **Bước:** 13.1B — Sửa RR-08 (rollback serial chính xác khi hủy phiếu trả hàng KH)
> **Ngày:** 02/05/2026
> **Phạm vi sửa:** 1 migration mới + 3 file business code + 1 route

---

## 1. Vấn đề đã sửa

- `OrderReturnController@store` nhận `items.*.serial_ids` nhưng KHÔNG lưu vào `ReturnItem` → mất thông tin serial nào đã trả.
- `OrderReturnController@cancel` rollback serial bằng query mơ hồ `whereNull('invoice_id')->limit($qty)` → có thể chọn nhầm serial khác đang `in_stock`.
- Schema `return_items` không có cột để lưu serial reference đã trả.
- Route `returns.cancel` chưa được đăng ký (P1 backlog từ RR-11) — đóng luôn ở bước này.

---

## 2. File đã sửa

| File | Loại | Nội dung sửa |
|---|---|---|
| `database/migrations/2026_05_02_120000_add_serial_ids_to_return_items_table.php` | Migration mới | Thêm cột `return_items.serial_ids` (JSON, nullable, after `invoice_item_id`) |
| `app/Models/ReturnItem.php` | Model | Thêm `protected $casts = ['serial_ids' => 'array']` (giữ `$guarded = ['id']` nguyên) |
| `app/Http/Controllers/OrderReturnController.php` | Controller — `store()` | Khi tạo `ReturnItem`, ghi `serial_ids` từ `$restoredSerials->pluck('id')` (nếu product `has_serial` và có serial; ngược lại null) |
| `app/Http/Controllers/OrderReturnController.php` | Controller — `cancel()` | Thay query `whereNull('invoice_id')->limit($qty)` bằng `whereIn('id', $item->serial_ids)` + scope `->where('product_id')` để rollback đúng serial. Bỏ fallback mơ hồ. |
| `routes/web.php` | Route | Thêm `Route::post('/returns/{return}/cancel', ...)->name('returns.cancel')` |

---

## 3. Migration

- **Tên:** `2026_05_02_120000_add_serial_ids_to_return_items_table.php`
- **Cột thêm:** `serial_ids JSON nullable` sau `invoice_item_id` trên bảng `return_items` (kèm comment giải thích RR-08).
- **Idempotent:** kiểm tra `Schema::hasColumn` trước, an toàn chạy lặp.
- **Rollback:** `dropColumn('serial_ids')`.

---

## 4. Cách sửa

### 4.1. ReturnItem (`app/Models/ReturnItem.php`)

```php
protected $casts = [
    'serial_ids' => 'array',
];
```

Vì model dùng `$guarded = ['id']`, mass-assignment qua `create([..., 'serial_ids' => [...]])` đã hoạt động → không cần thêm `$fillable`.

### 4.2. `OrderReturnController@store` — lưu serial_ids

**Trước sửa:** Tạo `ReturnItem` không có `serial_ids`. `$restoredSerials` chỉ dùng để update `serial_imeis.status = 'in_stock'`.

**Sau sửa:**
```php
$serialIdsForItem = $product->has_serial
    ? $restoredSerials->pluck('id')->map(fn ($id) => (int) $id)->all()
    : null;

$return->items()->create([
    ...
    'serial_ids' => !empty($serialIdsForItem) ? $serialIdsForItem : null,
]);
```

Logic:
- Nếu product `has_serial`: lấy danh sách `id` từ `$restoredSerials` (đã được resolve qua 3 fallback hiện có: `serial_ids` request → `invoice_item_serials` → invoice_id+product_id).
- Nếu hàng thường (`has_serial=false`): null.
- Nếu hàng serial nhưng `restoredSerials` rỗng (legacy): null (không bịa serial). Cancel sẽ bỏ qua, ghi rủi ro backward-compat ở mục 6.

### 4.3. `OrderReturnController@cancel` — rollback đúng serial

**Trước sửa:**
```php
SerialImei::where('product_id', $item->product_id)
    ->where('status', 'in_stock')
    ->whereNull('invoice_id')
    ->limit($item->quantity)
    ->update(['status'=>'sold', 'sold_at'=>now(), 'invoice_id'=>$return->invoice_id]);
```

**Sau sửa:**
```php
if ($item->product->has_serial && $return->invoice_id) {
    $serialIds = is_array($item->serial_ids) ? $item->serial_ids : [];
    if (!empty($serialIds)) {
        SerialImei::whereIn('id', $serialIds)
            ->where('product_id', $item->product_id)
            ->update([
                'status'          => 'sold',
                'sold_at'         => now(),
                'invoice_id'      => $return->invoice_id,
                'sold_cost_price' => (float) ($item->cost_price ?: 0) ?: null,
            ]);
    }
    // Nếu serial_ids rỗng (legacy data trước RR-08), không fallback chọn đại
    // — để tránh gán nhầm serial. Cần backfill nếu có data cũ.
}
```

**Có còn fallback query mơ hồ không?** ❌ Không. Đã xóa hoàn toàn nhánh `whereNull('invoice_id')->limit(...)`. Legacy data thiếu `serial_ids` sẽ KHÔNG được rollback (an toàn hơn gán nhầm).

### 4.4. Route — `routes/web.php`

```php
Route::post('/returns/{return}/cancel', [OrderReturnController::class, 'cancel'])
    ->name('returns.cancel')
    ->middleware('permission:returns.create');
```

- Method: POST
- URI: `/returns/{return}/cancel`
- Name: `returns.cancel`
- Permission: `returns.create` (cùng với store — chưa có permission `returns.cancel` riêng; có thể tách sau theo backlog)

---

## 5. Kết quả test

### 5.1. RR-08 tests

| Test | Trước sửa | Sau sửa |
|---|---|---|
| `cancel_order_return_should_restore_exact_returned_serial` | ❌ FAIL | ✅ PASS |
| `cancel_order_return_should_not_pick_another_available_serial` | ❌ FAIL | ✅ PASS |
| `cancel_order_return_should_be_idempotent_for_serials` | ✅ PASS | ✅ PASS |
| `return_items_schema_should_persist_returned_serial_reference` | ❌ FAIL | ✅ PASS |
| **Tổng** | 1 PASS, 3 FAIL | ✅ **4 PASS, 0 FAIL** (15 assertions, 0.74s) |

### 5.2. Regression liên quan

| Test | Kết quả |
|---|---|
| `RR11OrderReturnQtyTest` | ✅ 4 PASS (8) |
| `RR05MovingAvgCostingZeroStockTest` | ✅ 5 PASS (15) |
| `RR05SerialImeiCostingTest` | ✅ 4 PASS (16) |

### 5.3. P0 audit regression

| # | Test | Kết quả |
|---|---|---|
| 1 | `CancelInvoiceTest` | ✅ 10 PASS (20) |
| 2 | `RR01ReportControllerRegressionTest` | ✅ 8 PASS (9) |
| 3 | `RR01SupplierDualRoleRegressionTest` | ✅ 2 PASS (4) |
| 4 | `RR01CashFlowCancelledRegressionTest` | ✅ 4 PASS (4) |
| 5 | `RR03StockTransferTest` | ✅ 5 PASS (12) |
| 6 | `RR03StockTransferRouteTest` | ✅ 3 PASS (10) |
| 7 | `RR04StockTakeTest` | ✅ 5 PASS (12) |
| 8 | `RR07RepairPartsTest` | ✅ 4 PASS (9) |
| 9 | `RR10CashFlowDeletionTest` | ✅ 5 PASS (12) |
| 10 | `RR11OrderReturnQtyTest` | ✅ 4 PASS (8) |
| | **Tổng P0 regression** | ✅ **50 PASS** |

### 5.4. Tổng

| Mục | Kết quả |
|---|---|
| **RR-08** | ✅ 4 PASS, 0 FAIL |
| **RR-11 regression** | ✅ 4 PASS |
| **RR-05 regression** | ✅ 9 PASS (5 unit + 4 serial) |
| **P0 audit regression** | ✅ 50 PASS |
| **Tổng tests sau Bước 13.1B** | ✅ **67 PASS, 0 FAIL** |

---

## 6. Rủi ro còn lại

| # | Khu vực | Mức độ | Ghi chú |
|---|---|---|---|
| 1 | Legacy `return_items` cũ không có `serial_ids` | **Backward compat** | Cancel sẽ KHÔNG rollback serial cho phiếu trả tạo trước RR-08. An toàn hơn gán nhầm — nhưng cần backfill nếu production có data cũ. Đề xuất: Artisan command `php artisan returns:backfill-serial-ids` để rebuild từ `invoice_item_serials` + `serial_imeis.status` history. |
| 2 | UI hiển thị | **P3** | Trang `Returns/Show` chưa hiển thị `serial_ids` đã trả. Nhật ký truy vết có thể thiếu. |
| 3 | Test multi-serial cùng return item | **P3** | Test hiện chỉ cover 1 serial / item. Trường hợp `qty>1, serial_ids=[A,B,C]` chưa có test riêng (nhưng logic `whereIn` đã hỗ trợ). |
| 4 | Test route cancel | **P3** | Test 13.1A/13.1B gọi controller method trực tiếp; chưa có test gọi qua route name `returns.cancel`. Có thể bổ sung sau. |
| 5 | Permission riêng | **P3** | Hiện dùng chung `returns.create`. Có thể tách `returns.cancel` permission riêng để tách quyền. |
| 6 | Validate serial_ids count vs qty | **P3** | Hiện `store()` không validate `count(serial_ids) === qty` cho hàng `has_serial`. Nếu `restoredSerials` rỗng (do request thiếu serial_ids và cũng không tìm được fallback), `serial_ids = null` → cancel bỏ qua serial. Validation này thuộc backlog rộng hơn về data integrity. |

---

## 7. Kết luận

✅ **RR-08 đã Fixed.**

- 4/4 RR-08 tests PASS.
- 50/50 P0 audit regression PASS.
- 9/9 RR-05 regression PASS.
- 4/4 RR-11 regression PASS.
- **Tổng 67/67 PASS, 0 FAIL.**
- Cancel rollback đúng serial đã trả; serial khác không bị động. Idempotent vẫn hoạt động.
- Route `returns.cancel` đã đăng ký — giải quyết luôn 1 P1 backlog cũ từ RR-11.
- Schema thêm 1 cột `serial_ids` JSON, không phá schema cũ; legacy không bị block.

**Có thể chuyển sang RR-08 closure report (Bước 13.2).**

---

## 8. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Mục 6.4 — quy tắc rollback đúng serial đã lưu trên return_item |
| `docs/audit/RISK_REGISTER.md` | RR-08 sẽ chuyển sang Fixed/Verified |
| `docs/test-cases/RR-08-order-return-serial-rollback.md` | Test case spec |
| `docs/audit/STEP-13.1A-RR08-ORDER-RETURN-SERIAL-ROLLBACK-TEST-RESULTS.md` | Test chứng minh lỗi |
| `docs/audit/STEP-13.1B-RR08-ORDER-RETURN-SERIAL-ROLLBACK-FIX-RESULTS.md` | File này — sửa lỗi |
| `tests/Feature/OrderReturn/RR08OrderReturnSerialRollbackTest.php` | Feature test (4 PASS) |
| `app/Http/Controllers/OrderReturnController.php` | Controller đã sửa store()+cancel() |
| `app/Models/ReturnItem.php` | Model đã thêm cast |
| `database/migrations/2026_05_02_120000_add_serial_ids_to_return_items_table.php` | Migration mới |
| `routes/web.php` | Đã đăng ký `returns.cancel` |
