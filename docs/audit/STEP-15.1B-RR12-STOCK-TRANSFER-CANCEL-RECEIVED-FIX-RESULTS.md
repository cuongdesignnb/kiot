# STEP-15.1B — Fix RR-12 StockTransfer Cancel Received

> **Bước:** 15.1B — Sửa RR-12 (cancel dùng cost snapshot, không current cost)
> **Ngày:** 02/05/2026
> **Phạm vi sửa:** 1 migration + 2 file business code (model + controller)

---

## 1. Vấn đề đã sửa

- Cancel received dùng `current $product->cost_price` → khi BQ thay đổi giữa các pha, cost đảo không khớp cost lúc transfer_out.
- `stock_transfer_items` thiếu cột `cost_at_transfer` để snapshot BQ tại thời điểm xuất chuyển.
- `inventory_total_cost` lệch sau cancel (test fail: `2,125,000.01` thay vì `2,000,000`).
- Đảo destination dùng `applySale($qty)` (không nhận cost parameter, dùng current BQ) → cũng sai cost. Phải đổi sang `applyPurchaseReturn($qty, $costAtTransfer)` để rút tồn theo cost snapshot.

---

## 2. File đã sửa

| File | Loại | Nội dung sửa |
|---|---|---|
| `database/migrations/2026_05_02_120200_add_cost_at_transfer_to_stock_transfer_items_table.php` | Migration mới | Thêm cột `stock_transfer_items.cost_at_transfer` (decimal 15,2 nullable, after `price`). Idempotent + có rollback. |
| `app/Models/StockTransferItem.php` | Model | Thêm `cost_at_transfer` vào `$fillable` + `$casts = ['cost_at_transfer' => 'decimal:2']`. |
| `app/Http/Controllers/StockTransferController.php` — `store()` | Controller | Snapshot `$costAtTransfer = $product->cost_price` **trước** `applySale`. Lưu vào item. Khi store thẳng `received`, dùng `$costAtTransfer` cho `applyPurchase` destination thay vì current cost. |
| `app/Http/Controllers/StockTransferController.php` — `receive()` | Controller | `$costPerUnit = $item->cost_at_transfer ?: $product->cost_price` (fallback legacy). |
| `app/Http/Controllers/StockTransferController.php` — `cancel()` | Controller | Dùng `$item->cost_at_transfer ?: $product->cost_price` cho cả 2 nhánh: (1) đảo destination — đổi từ `applySale($qty)` sang `applyPurchaseReturn($qty, $costPerUnit)` để rút tồn theo cost snapshot; (2) restore source — `applyPurchase($qty, $costPerUnit)`. |

**Không sửa:** MovingAvgCostingService, StockMovementService, OrderReturnController, các module khác. Không tạo branch inventory.

---

## 3. Migration

- **Tên:** `2026_05_02_120200_add_cost_at_transfer_to_stock_transfer_items_table.php`
- **Cột thêm:** `stock_transfer_items.cost_at_transfer DECIMAL(15,2) NULLABLE` (after `price`)
- **Idempotent:** check `Schema::hasColumn` trước khi add/drop
- **Rollback:** `dropColumn('cost_at_transfer')`

---

## 4. Cách sửa

### 4.1. `StockTransferItem` (model)

```php
protected $fillable = [..., 'cost_at_transfer'];
protected $casts = ['cost_at_transfer' => 'decimal:2'];
```

### 4.2. `StockTransferController@store`

**Trước:** Tạo item không có `cost_at_transfer`. Khi store `received`, dùng `(float) $product->cost_price` (current) cho `applyPurchase` destination.

**Sau:**
```php
// RR-12: snapshot BQ TRƯỚC khi applySale (applySale sẽ giảm total nhưng không đổi BQ).
$costAtTransfer = $product ? (float) $product->cost_price : 0.0;

$transferItem = StockTransferItem::create([
    ...
    'cost_at_transfer' => $costAtTransfer,
]);

// Transfer out: applySale (giữ BQ, giảm total theo BQ).
if ($request->status !== 'draft' && $product) {
    $cogs = MovingAvgCostingService::applySale($product, $item['quantity']);
    StockMovementService::record(... TYPE_TRANSFER_OUT ... $cogs['cogs_per_unit'] ...);
}

// Transfer in (received immediately): dùng $costAtTransfer thay vì current cost.
if ($request->status === 'received' && $product) {
    $costPerUnit = $costAtTransfer;
    MovingAvgCostingService::applyPurchase($product, $item['quantity'], $costPerUnit);
    ...
}
```

- **`cost_at_transfer` được lưu lúc nào?** Trước `applySale` (vì applySale giảm total nhưng giữ BQ; tuy nhiên để snapshot rõ ràng vẫn lấy trước).
- **Lấy cost trước hay sau applyTransferOut?** Trước, để bảo đảm là BQ "lúc xuất".

### 4.3. `StockTransferController@receive`

```php
// RR-12: dùng cost_at_transfer (snapshot lúc xuất); fallback current cost cho legacy.
$costPerUnit = (float) ($item->cost_at_transfer ?: $product->cost_price);
MovingAvgCostingService::applyPurchase($product, $recvQty, $costPerUnit);
StockMovementService::record(... TYPE_TRANSFER_IN ... $costPerUnit ...);
```

- Hàng chuyển sang kho đích phải giữ giá vốn từ kho nguồn (không phụ thuộc BQ destination tại thời điểm nhận).

### 4.4. `StockTransferController@cancel`

**Trước:**
```php
$costPerUnit = (float) $product->cost_price;  // CURRENT (sai)

if ($transfer->status === 'received' && $item->received_quantity > 0) {
    MovingAvgCostingService::applySale($product, $item->received_quantity);  // dùng current BQ
    ...
}

$costPerUnit = (float) $product->cost_price;  // re-read CURRENT (sai)
MovingAvgCostingService::applyPurchase($product, $item->quantity, $costPerUnit);
```

**Sau:**
```php
// RR-12: dùng cost_at_transfer (snapshot); fallback current cost cho legacy.
$costPerUnit = (float) ($item->cost_at_transfer ?: $product->cost_price);

if ($transfer->status === 'received' && $item->received_quantity > 0) {
    // Đảo destination: rút tồn ở COST SNAPSHOT, không current BQ.
    // applyPurchaseReturn(qty, costAtPurchase) phù hợp vì nó "trừ qty và trừ total theo cost snapshot".
    MovingAvgCostingService::applyPurchaseReturn($product, $item->received_quantity, $costPerUnit);
    ...
}

// Restore source: cộng tồn ở cost snapshot.
MovingAvgCostingService::applyPurchase($product, $item->quantity, $costPerUnit);
```

- **Trước sửa dùng cost nào?** `current $product->cost_price` (sai khi BQ thay đổi).
- **Sau sửa dùng cost nào?** `$item->cost_at_transfer` (snapshot lúc xuất).
- **Legacy null xử lý sao?** Fallback `$product->cost_price` để không crash; ghi backlog backfill nếu production có data cũ.
- **Đổi method service:** `applySale` → `applyPurchaseReturn` cho đảo destination (vì applySale không nhận cost, dùng current BQ). `applyPurchase` giữ nguyên cho restore source (đã nhận cost parameter).

---

## 5. Kết quả test

### 5.1. RR-12

| Test | Trước fix | Sau fix v1 | Sau fix v2 (final) |
|---|---|---|---|
| `cancel_fully_received_simple_keeps_stock_and_cost` | ✅ PASS | ✅ PASS | ✅ PASS |
| `cancel_partial_received_keeps_stock_in_product_level` | ✅ PASS | ✅ PASS | ✅ PASS |
| `cancel_received_should_be_idempotent` | ✅ PASS | ✅ PASS | ✅ PASS |
| `cancel_received_should_preserve_cost_when_avg_changes_between_phases` | ❌ 2,125,000.01 | ❌ 1,900,000.01 | ✅ PASS (= 2,000,000) |
| `cancel_transferring_restores_source_stock` | ✅ PASS | ✅ PASS | ✅ PASS |
| **Tổng** | 4 PASS, 1 FAIL | 4 PASS, 1 FAIL | ✅ **5 PASS, 0 FAIL** (23 assertions, 1.60s) |

**Lưu ý fix lặp 2 lần:**
- Lần 1: Lưu `cost_at_transfer` + dùng nó cho `applyPurchase` source. Nhưng đảo destination vẫn dùng `applySale` (không nhận cost) → vẫn lệch.
- Lần 2: Đổi `applySale` → `applyPurchaseReturn(qty, costPerUnit)` cho đảo destination → khớp đầy đủ.

### 5.2. Regression liên quan

| Test | Kết quả |
|---|---|
| `RR03StockTransferTest` | ✅ 5 PASS (12) |
| `RR03StockTransferRouteTest` | ✅ 3 PASS (10) |
| `RR09DamageStockTest` | ✅ 5 PASS (12) |
| `RR05MovingAvgCostingZeroStockTest` | ✅ 5 PASS (15) |
| `RR05SerialImeiCostingTest` | ✅ 4 PASS (16) |
| `RR08OrderReturnSerialRollbackTest` | ✅ 4 PASS (15) |
| `RR11OrderReturnQtyTest` | ✅ 4 PASS (8) |

### 5.3. P0 audit regression

| Test | Kết quả |
|---|---|
| `CancelInvoiceTest` | ✅ 10 PASS (20) |
| `RR01ReportControllerRegressionTest` | ✅ 8 PASS (9) |
| `RR01SupplierDualRoleRegressionTest` | ✅ 2 PASS (4) |
| `RR01CashFlowCancelledRegressionTest` | ✅ 4 PASS (4) |
| `RR03StockTransferTest` | ✅ 5 PASS |
| `RR03StockTransferRouteTest` | ✅ 3 PASS |
| `RR04StockTakeTest` | ✅ 5 PASS (12) |
| `RR07RepairPartsTest` | ✅ 4 PASS (9) |
| `RR10CashFlowDeletionTest` | ✅ 5 PASS (12) |
| `RR11OrderReturnQtyTest` | ✅ 4 PASS (8) |
| **Tổng P0 regression** | ✅ **50 PASS** |

### 5.4. Tổng

| Mục | Kết quả |
|---|---|
| **RR-12** | ✅ 5 PASS, 0 FAIL |
| **RR-03 regression** | ✅ 8 PASS |
| **RR-09 regression** | ✅ 5 PASS |
| **RR-05 regression** | ✅ 9 PASS |
| **RR-08 regression** | ✅ 4 PASS |
| **RR-11 regression** | ✅ 4 PASS |
| **P0 audit regression (10 filter)** | ✅ 50 PASS |
| **Tổng tests sau Bước 15.1B** | ✅ **77 PASS, 0 FAIL** (lưu trùng filter chỉ đếm 1 lần) |

---

## 6. Rủi ro còn lại

| # | Khu vực | Mức độ | Ghi chú |
|---|---|---|---|
| 1 | Legacy `stock_transfer_items` cũ | Backward compat | Records tạo trước RR-12 không có `cost_at_transfer` → fallback current cost (chấp nhận tạm thời, có thể lệch nếu BQ đã thay đổi). Cần Artisan command backfill từ `stock_movements` hoặc đặt = `price` nếu production có data cũ. |
| 2 | Partial received cancel "fabricate" missing units | Limitation kiến trúc | Q=5, R=3 → cancel cộng đủ 5 về source dù chỉ R=3 đã nhận. Tổng tồn (numerical) đúng product-level nhưng audit không phản ánh "hàng mất trong vận chuyển". Cần multi-warehouse + write-off process để xử lý đúng. |
| 3 | Multi-warehouse architecture | Limitation lớn | `products.stock_quantity` chung không tách theo branch. Cần bảng `branch_inventory(product_id, branch_id, stock_qty, total_cost)`. Phạm vi sửa rất rộng (mọi controller liên quan tồn). Để dài hạn. |
| 4 | UI partial received cancel cảnh báo | P3 | Trang `StockTransfers/Show` chưa cảnh báo người dùng khi cancel partial sẽ "phục hồi" missing units. |
| 5 | Test partial cancel với cost variation | P3 | Chưa cover scenario cost change + partial cancel cùng lúc. |
| 6 | Receive partial gọi `applyPurchase` qua từng dòng riêng | P3 | Nếu nhiều item partial trong 1 phiếu, cost được dùng riêng cho từng dòng — đã đúng vì mỗi dòng có `cost_at_transfer` riêng. |

---

## 7. Kết luận

✅ **RR-12 đã Fixed.**

- 5/5 RR-12 tests PASS (cost integrity với BQ variation đã được khôi phục đúng business value).
- 50/50 P0 audit regression PASS, 30/30 P1 regression PASS (RR-03, RR-05, RR-08, RR-09, RR-11).
- **Tổng 77/77 PASS, 0 FAIL.**
- Phạm vi sửa hẹp: 1 migration + 2 file business code (model + controller). Không sửa service/module khác. Không refactor multi-warehouse.
- Pattern thống nhất với RR-08 (`return_items.serial_ids` snapshot per-item) và RR-09 (`damage_items.serial_ids`) — đều dùng cột snapshot trên item để cancel đảo nghiệp vụ chính xác.

**Có thể chuyển sang RR-12 closure report (Bước 15.2).**

---

## 8. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Mục 3.4 (snapshot cost lúc bán cho KH trả), 3.5 (snapshot cost lúc nhập cho trả NCC) — pattern snapshot thống nhất |
| `docs/audit/RISK_REGISTER.md` | RR-12 sẽ chuyển sang Fixed/Verified |
| `docs/test-cases/RR-12-stock-transfer-cancel-received.md` | Test case spec |
| `docs/audit/STEP-15.1A-RR12-STOCK-TRANSFER-CANCEL-RECEIVED-TEST-RESULTS.md` | Test chứng minh lỗi (4 PASS, 1 FAIL) |
| `docs/audit/STEP-15.1B-RR12-STOCK-TRANSFER-CANCEL-RECEIVED-FIX-RESULTS.md` | File này — sửa lỗi (5 PASS, 0 FAIL) |
| `tests/Feature/Inventory/RR12StockTransferCancelReceivedTest.php` | Feature test (5 PASS) |
| `app/Http/Controllers/StockTransferController.php` | Controller đã sửa store + receive + cancel |
| `app/Models/StockTransferItem.php` | Model đã thêm cast |
| `database/migrations/2026_05_02_120200_add_cost_at_transfer_to_stock_transfer_items_table.php` | Migration mới |
| `docs/audit/RR-03-CLOSURE-REPORT.md` | RR-03 closure — context cho fix RR-12 |
