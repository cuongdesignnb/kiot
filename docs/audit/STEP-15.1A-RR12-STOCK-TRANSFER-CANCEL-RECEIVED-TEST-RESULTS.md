# STEP-15.1A — RR-12 StockTransfer Cancel Received Test Results

> **Bước:** 15.1A — Viết test kiểm chứng RR-12
> **Ngày:** 02/05/2026
> **Phạm vi:** Chỉ nghiên cứu + viết test. **Không sửa business code, schema, route.**

---

## 1. Mục tiêu

Kiểm chứng `StockTransferController@cancel` (đặc biệt nhánh `received`) trong mô hình hệ thống chưa multi-warehouse — `products.stock_quantity` chung — có làm sai tổng tồn / giá vốn / movement không.

**Kết luận sớm:** RR-12 **có 1 lỗi rõ rệt về cost integrity** khi BQ thay đổi giữa các pha. Tổng tồn (numerical) trong product-level luôn về initial — không phải bug về số học.

---

## 2. Discovery

| Nội dung | Kết quả |
|---|---|
| Tồn kho theo product hay theo branch | Theo product (`products.stock_quantity` chung). **Chưa có** tồn theo branch/warehouse. |
| Có `received_quantity` không | ✅ Có — cột `stock_transfer_items.received_quantity` (nullable, từ migration `2026_04_18_173825`) |
| Có `cost_at_transfer` snapshot không | ❌ **Không có** — `stock_transfer_items` chỉ có `id, stock_transfer_id, product_id, quantity, received_quantity, price`. Không lưu BQ tại thời điểm `transfer_out`. |
| Store status flow | `draft` → `transferring` → `received`; hoặc store thẳng `received` (auto applyPurchase + set received_quantity = quantity) |
| Receive partial support | ✅ Có — `receive($items)` cho phép `received_quantity < quantity` (clamp về 0..quantity). Yêu cầu `receive_note` khi partial. |
| Cancel received support | ✅ Có — đảo destination (`applySale` $received_qty) + restore source (`applyPurchase` $item->quantity). |
| Cancel received công thức hiện tại | Net stock (product-level) = `+quantity (source) - received_quantity (destination)` từ thời điểm received → tổng từ initial luôn = 0. |
| Cancel idempotent | ✅ Có — guard `if status='cancelled' return 422` (dòng 301-303) |
| Movement đảo hiện tại | 2 movements: `TYPE_TRANSFER_OUT` (đảo destination, branch=to_branch_id) + `TYPE_TRANSFER_IN` (restore source, branch=from_branch_id), cả hai dùng **current cost_price** |
| Rủi ro phát hiện | (1) **Cost integrity bị lệch nếu BQ thay đổi giữa các pha** vì cancel dùng current cost thay vì snapshot lúc `transfer_out` (xác nhận FAIL). (2) Partial cancel "fabricate" missing units (Q≠R cộng đủ Q về source — vấn đề audit, không phải numerical). (3) Schema thiếu cột `cost_at_transfer` lưu BQ snapshot. |

---

## 3. Dữ liệu test

| Mục | Giá trị |
|---|---|
| Product | `cost_price=100_000`, `stock_quantity=10`, `inventory_total_cost=1_000_000`, `has_serial=false` |
| Transfer test | `qty=3` hoặc `qty=5` |
| Received test | `received_quantity=3` (full) hoặc `=3` partial khi qty=5 |
| Branch | from + to (tạo mới mỗi test) |
| Pha cost variation (TC-04) | Sau store `transferring` qty=3, gọi thêm `MovingAvgCostingService::applyPurchase($product, 5, 200_000)` → BQ chuyển 100k → 141.67k |

---

## 4. Test đã tạo

`tests/Feature/Inventory/RR12StockTransferCancelReceivedTest.php` — 5 test:

| Test | Kỳ vọng | Thực tế | Kết quả |
|---|---|---|---|
| `cancel_fully_received_simple_keeps_stock_and_cost` | stock=10, total=1M, cost=100k | đúng | ✅ PASS |
| `cancel_partial_received_keeps_stock_in_product_level` | stock=10 (số học OK trong product-level) | stock=10 | ✅ PASS |
| `cancel_received_should_be_idempotent` | 2nd cancel → 422, state unchanged | đúng | ✅ PASS |
| `cancel_received_should_preserve_cost_when_avg_changes_between_phases` | total=2M (1M source + 1M mua mới) | total=2,125,000.01 (lệch +125k) | ❌ FAIL |
| `cancel_transferring_restores_source_stock` | stock=10, total=1M, cost=100k | đúng | ✅ PASS |

---

## 5. Kết quả chạy test

```
Tests:    1 failed, 4 passed (23 assertions)
Duration: 0.84s
```

| Mục | Kết quả |
|---|---|
| Tổng số test | 5 |
| Pass | 4 |
| Fail | 1 |
| Skipped | 0 |

---

## 6. Nguyên nhân fail

**TC-04: Cost integrity FAIL**
- Trước cancel state: stock=15 (12 + 3 receive), total=2,125,000 (1700 + 425).
- Sau cancel:
  - Đảo destination 3 @ current cost 141.67k → applySale: total = 2125 - 425 = 1700k.
  - Restore source 3 @ current cost 141.67k → applyPurchase: total = 1700 + 425 = 2125k.
- **Đáng lẽ:** source 10 @ 100k = 1M + mua thêm 5 @ 200k = 1M → tổng 2M cho 15 đơn vị.
- **Thực tế:** total = 2,125,000.01, lệch **+125k** (~6.25% inflation).

**Root cause:** `StockTransferController@cancel` dòng 317 và 334 dùng `$costPerUnit = (float) $product->cost_price` (current BQ) thay vì cost snapshot lúc `transfer_out`. Schema `stock_transfer_items` không có cột để lưu cost snapshot.

**Pattern bug tương tự:** RR-05 (giá vốn reset 0 khi qty=0) đã fix; RR-12 là biến thể — không reset 0 nhưng dùng wrong snapshot. Cách fix giống RR-08 (`return_items.serial_ids` snapshot lưu trên item) — thêm cột `cost_at_transfer` JSON/decimal trên `stock_transfer_items`.

**Partial cancel "fabricate" missing units:**
- TC-02 PASS với assertion stock=10 — đây là behavior numerical OK trong product-level.
- Nhưng nghiệp vụ: 2 đơn vị "missing in transit" cộng lại vào source mà không có write-off → audit trail không đúng.
- Chỉ là **limitation kiến trúc** (cần multi-warehouse + write-off process), không phải bug numerical bắt buộc fix gấp.

**Các vấn đề khác (không phải bug):**
- Idempotent: PASS (đã có guard).
- Cancel transferring: PASS (RR-03 đã fix).
- Cancel fully received simple: PASS.

---

## 7. Regression

Chạy theo từng filter riêng (chuẩn audit):

| Test | Kết quả |
|---|---|
| `RR03StockTransferTest` | ✅ 5 PASS (12) |
| `RR03StockTransferRouteTest` | ✅ 3 PASS (10) |
| `RR09DamageStockTest` | ✅ 5 PASS (12) |
| `RR05MovingAvgCostingZeroStockTest` | ✅ 5 PASS (15) |
| `RR05SerialImeiCostingTest` | ✅ 4 PASS (16) |
| `RR08OrderReturnSerialRollbackTest` | ✅ 4 PASS (15) |
| `RR11OrderReturnQtyTest` | ✅ 4 PASS (8) |
| `CancelInvoiceTest` | ✅ 10 PASS (20) |
| `RR01ReportControllerRegressionTest` | ✅ 8 PASS (9) |
| `RR01SupplierDualRoleRegressionTest` | ✅ 2 PASS (4) |
| `RR01CashFlowCancelledRegressionTest` | ✅ 4 PASS (4) |
| `RR04StockTakeTest` | ✅ 5 PASS (12) |
| `RR07RepairPartsTest` | ✅ 4 PASS (9) |
| `RR10CashFlowDeletionTest` | ✅ 5 PASS (12) |
| **Tổng regression** | ✅ **68 PASS, 0 FAIL** |

→ Không có hồi quy do Bước 15.1A (vì không sửa code).

---

## 8. Kết luận

✅ **RR-12 có lỗi thật**, nhưng phạm vi hẹp hơn Risk Register ban đầu mô tả:

**Lỗi xác định (1):**
- **Cost integrity** — `cancel()` dùng current `cost_price` thay vì snapshot lúc `transfer_out`. Khi BQ thay đổi giữa các pha (do mua/bán/sửa khác), `inventory_total_cost` sau cancel bị lệch.

**Không phải bug (3):**
- Tổng tồn (numerical) trong product-level luôn về initial — RR-12 ban đầu lo "+qty - received_qty sai" thực ra trong product-level luôn = 0 net từ initial.
- Idempotent đã có guard.
- Cancel `transferring` đã đúng từ RR-03.

**Limitation kiến trúc (1):**
- Partial cancel "fabricate" missing units — vấn đề audit, không phải numerical. Cần multi-warehouse hoặc validation reject partial cancel.

**Đủ điều kiện chuyển sang Bước 15.1B?** ✅ Có — sửa hẹp Option A trong test case doc.

**Phạm vi sửa Bước 15.1B (kỳ vọng):**
1. **Migration mới:** thêm cột `stock_transfer_items.cost_at_transfer` (decimal nullable) lưu BQ tại `transfer_out`.
2. **`StockTransferController@store`:** khi store `transferring` hoặc `received`, lưu `$cogs['cogs_per_unit']` vào `cost_at_transfer`.
3. **`StockTransferController@cancel`:** dùng `$item->cost_at_transfer` thay vì current cost (cho cả applySale destination và applyPurchase source).
4. **Optional — validation tạm:** reject cancel `received` nếu `received_quantity != quantity` (ngăn fabricate missing) hoặc thêm note bắt buộc.

Schema sửa hẹp 1 cột, controller sửa 2 chỗ. Pattern tương tự RR-08 đã làm.

---

## 9. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Mục 3.4 (KH trả ở snapshot cost), 3.5 (trả NCC ở cost lúc nhập) — pattern snapshot |
| `docs/audit/RISK_REGISTER.md` | RR-12 trong P1 backlog |
| `docs/test-cases/RR-12-stock-transfer-cancel-received.md` | Test case spec |
| `tests/Feature/Inventory/RR12StockTransferCancelReceivedTest.php` | Feature test (4 PASS, 1 FAIL) |
| `app/Http/Controllers/StockTransferController.php` | Controller có bug cost integrity ở `cancel()` dòng 317, 334 |
| `app/Models/StockTransferItem.php` | Model — cần thêm cast `cost_at_transfer` |
| `database/migrations/2026_03_01_034706_create_stock_transfer_items_table.php` | Schema gốc — thiếu cost snapshot |
| `database/migrations/2026_04_18_173825_add_received_quantity_to_stock_transfer_items.php` | Migration `received_quantity` (đã có) |
| `docs/audit/RR-03-CLOSURE-REPORT.md` | RR-03 closure — đã thiết lập costing/movement cho transfer |
