# STEP-14.1A — RR-09 Damage Stock Test Results

> **Bước:** 14.1A — Viết test kiểm chứng RR-09
> **Ngày:** 02/05/2026
> **Phạm vi:** Chỉ nghiên cứu + viết test. **Không sửa business code, schema, route.**

---

## 1. Mục tiêu

Kiểm chứng `DamageController` (xuất hủy hàng hỏng/lỗi/mất) có:
- Trừ tồn đúng (`stock_quantity`).
- Cập nhật giá vốn (`inventory_total_cost` qua `MovingAvgCostingService`).
- Ghi `StockMovement`.
- Xử lý Serial/IMEI.
- Hỗ trợ cancel với rollback.

→ Kết quả: **RR-09 là lỗi thật, đa diện.**

---

## 2. Damage discovery

| Nội dung | Kết quả |
|---|---|
| Route tạo Damage | `GET /damages/create`, `POST /damages` (`damages.store`) |
| Route hủy Damage | ❌ **Không có** (`damages.cancel` / `damages.destroy` chưa đăng ký) |
| Controller | `app/Http/Controllers/DamageController.php` (153 dòng) — index, create, store, export, print. **Không có method cancel/destroy** |
| Model | `App\Models\Damage` (fillable + softDeletes), `App\Models\DamageItem` (fillable + softDeletes) |
| Bảng DB | `damages` (id, code, branch_id, status, created_by_name, destroyed_by_name, destroyed_date, total_qty, total_value, note, timestamps, soft_deletes), `damage_items` (id, damage_id, product_id, qty, cost_price, total_value, note). **Không có cột `serial_ids`** trên `damage_items` |
| Có trừ tồn không | ✅ Có — dòng 119: `$product->stock_quantity -= $item['qty']; $product->save();` (RAW). Chỉ khi `status='completed'`. |
| Có cập nhật `inventory_total_cost` không | ❌ **KHÔNG** — chỉ giảm `stock_quantity`. Vi phạm AGENT_RULES mục 2.5. Hậu quả: `cost_price` inflate (qty giảm, total giữ nguyên). |
| Có `StockMovement` không | ❌ **KHÔNG** — `use App\Services\StockMovementService` không xuất hiện trong controller. Thẻ kho thiếu hoàn toàn dòng damage. |
| Có gọi `MovingAvgCostingService` không | ❌ **KHÔNG** |
| Có validate quá tồn không | ✅ Có — dòng 116-118: `if ($product->stock_quantity < $item['qty']) throw ...`. Pattern này đúng. |
| Có xử lý Serial/IMEI không | ❌ **KHÔNG** — controller không nhận `serial_ids` từ request, không update `serial_imeis.status`, không lưu serial vào `DamageItem` |
| Có cancel/rollback không | ❌ **KHÔNG** — `DamageStatus::CANCELLED` đã được định nghĩa trong enum nhưng không có method/route cancel. Tạo nhầm phiếu Damage không có cách rollback. |
| Rủi ro phát hiện | 5 mặt: (1) `inventory_total_cost` không update → BQ inflate. (2) Thẻ kho thiếu. (3) Không xử lý serial. (4) Không có cancel. (5) Damage status `draft` chuyển `completed` không có flow rõ. |

**Pattern tương tự đã được sửa cho:** RR-03 (StockTransfer), RR-04 (StockTake), RR-07 (Repair). Damage là module duy nhất còn sót pattern raw decrement.

---

## 3. Dữ liệu test

| Mục | Giá trị |
|---|---|
| Product thường | `has_serial=false`, `cost_price=100_000`, `stock_quantity=10`, `inventory_total_cost=1_000_000` |
| Damage qty | 3 (TC-01, TC-02), 15 (TC-03 — quá tồn), 1 serial (TC-04) |
| Product Serial | `has_serial=true`, `cost_price=5_000_000`, `stock_quantity=2`, `inventory_total_cost=10_000_000`, 2 serial in_stock (A, B) |
| Branch | tạo qua `firstOrCreate` |
| Admin user | tạo mới mỗi test |

---

## 4. Test đã tạo

`tests/Feature/Damage/RR09DamageStockTest.php` — 5 test:

| Test | Kỳ vọng | Thực tế | Kết quả |
|---|---|---|---|
| `damage_should_decrease_stock_and_inventory_total_cost` | total_cost = 700k | total_cost = 1M (không update) | ❌ FAIL (`1000000 !== 700000`) |
| `damage_should_create_stock_movement` | StockMovement count tăng | count = 0 | ❌ FAIL (`0 !> 0`) |
| `damage_should_not_allow_quantity_greater_than_stock` | Không tạo Damage completed | Không tạo (guard có sẵn) | ✅ PASS |
| `damage_serial_should_only_affect_selected_serial` | Serial A status = damaged | Serial A status = in_stock | ❌ FAIL |
| `damage_should_support_cancel_with_rollback` | có method + route cancel | thiếu cả hai | ❌ FAIL |

---

## 5. Kết quả chạy test

```
Tests:    4 failed, 1 passed (7 assertions)
Duration: 0.59s
```

| Mục | Kết quả |
|---|---|
| Tổng số test | 5 |
| Pass | 1 |
| Fail | 4 |
| Skipped | 0 |

→ **4 test FAIL chứng minh RR-09 là lỗi thật.** 1 test PASS (validate quá tồn — đã có guard).

---

## 6. Nguyên nhân fail

| Test fail | Nguyên nhân |
|---|---|
| TC-01 (cost) | Controller chỉ `$product->stock_quantity -= $qty`. Không update `inventory_total_cost` → `total/qty` inflate. Cần `MovingAvgCostingService::applyAdjustment(product, -qty)`. |
| TC-02 (movement) | Controller không gọi `StockMovementService::record(...)`. Cần thêm record với `TYPE_ADJUST_OUT` (hoặc thêm `TYPE_DAMAGE_OUT` mới). |
| TC-04 (serial) | Schema `damage_items` không có `serial_ids`. Controller không nhận `serial_ids` từ request. Cần migration + sửa store + update `serial_imeis.status='damaged'/'defective'`. |
| TC-05 (cancel) | DamageController không có method `cancel`/`destroy`. Route `damages.cancel` chưa đăng ký. Cần implement cancel + route + idempotent guard. |

---

## 7. Regression

Chạy theo từng filter riêng (chuẩn audit):

| Test | Kết quả |
|---|---|
| `RR05MovingAvgCostingZeroStockTest` | ✅ 5 PASS |
| `RR05SerialImeiCostingTest` | ✅ 4 PASS |
| `RR08OrderReturnSerialRollbackTest` | ✅ 4 PASS |
| `RR11OrderReturnQtyTest` | ✅ 4 PASS |
| `CancelInvoiceTest` | ✅ 10 PASS |
| `RR01ReportControllerRegressionTest` | ✅ 8 PASS |
| `RR01SupplierDualRoleRegressionTest` | ✅ 2 PASS |
| `RR01CashFlowCancelledRegressionTest` | ✅ 4 PASS |
| `RR03StockTransferTest` | ✅ 5 PASS |
| `RR03StockTransferRouteTest` | ✅ 3 PASS |
| `RR04StockTakeTest` | ✅ 5 PASS |
| `RR07RepairPartsTest` | ✅ 4 PASS |
| `RR10CashFlowDeletionTest` | ✅ 5 PASS |
| **Tổng regression** | ✅ **63 PASS, 0 FAIL** |

→ Không có hồi quy do Bước 14.1A (vì không sửa code).

---

## 8. Kết luận

✅ **RR-09 là lỗi thật, đa diện** (4/5 test fail).

**Lỗi thuộc nhóm:**
1. **Costing** — không cập nhật `inventory_total_cost` qua `MovingAvgCostingService`.
2. **Stock card** — không ghi `StockMovement`.
3. **Serial/IMEI** — không nhận, không lưu, không update.
4. **Lifecycle** — không có cancel + route.

**Đủ điều kiện chuyển sang Bước 14.1B?** ✅ Có.

**Có thể closure RR-09 ngay?** ❌ Không — phải sửa thực sự ở Bước 14.1B.

**Phạm vi sửa Bước 14.1B (kỳ vọng):**
1. **`DamageController@store`**: thay `$product->stock_quantity -= $qty` bằng `MovingAvgCostingService::applyAdjustment($product, -$qty)`. Thêm `StockMovementService::record(..., TYPE_ADJUST_OUT, ...)`. Pattern giống RR-04 (StockTake).
2. **Migration mới**: thêm `damage_items.serial_ids` JSON nullable.
3. **`DamageController@store`**: nếu product `has_serial`, nhận `serial_ids`, update `SerialImei.status='damaged'` (hoặc `'defective'`/`'returned'` theo enum hiện có), lưu vào `DamageItem.serial_ids`.
4. **`DamageController@cancel`**: implement method đảo nghiệp vụ + route `damages.cancel` + idempotent guard `if status='cancelled' return`.
5. **`Damage` model**: thêm `scopeActive()` (loại trừ status='cancelled') để báo cáo dùng.

(Phạm vi cụ thể quyết định ở Bước 14.1B.)

---

## 9. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Mục 2.2 (không raw decrement), 2.3 (StockMovement bắt buộc), 6 (serial/IMEI), 5.1 (hủy phải đổi status không xóa) |
| `docs/audit/RISK_REGISTER.md` | RR-09 trong P1 backlog ("Cần kiểm chứng" → giờ confirmed lỗi thật) |
| `docs/test-cases/RR-09-damage-stock.md` | Test case spec |
| `tests/Feature/Damage/RR09DamageStockTest.php` | Feature test (1 PASS, 4 FAIL) |
| `app/Http/Controllers/DamageController.php` | Controller có 5 vấn đề (thiếu costing, movement, serial, cancel) |
| `app/Models/Damage.php`, `app/Models/DamageItem.php` | Model — chưa có scope active, chưa cast serial_ids |
| `app/Enums/DamageStatus.php` | Enum đã có CANCELLED nhưng controller không dùng |
| `database/migrations/2026_03_01_044957_create_damages_table.php`, `2026_03_01_044958_create_damage_items_table.php` | Schema — chưa có serial_ids |
