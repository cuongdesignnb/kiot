# RR-12 Closure Report — StockTransfer cancel received phải dùng cost snapshot

> **Mã rủi ro:** RR-12
> **Mức độ ban đầu:** 🟡 P1 — High
> **Trạng thái cuối:** ✅ **Fixed/Verified**
> **Ngày đóng:** 02/05/2026
> **Test verification:** 77 PASS, 0 FAIL (5 RR-12 + 22 P1 regression + 50 P0 audit regression)

---

## 1. Tóm tắt lỗi ban đầu

- **Rủi ro ban đầu (Risk Register):** Hủy phiếu chuyển kho `received` có thể tính sai stock vì hệ thống chưa có multi-warehouse. Cộng source + trừ destination trên cùng `products.stock_quantity` → net = `+qty - received_qty`, có thể sai nếu `qty ≠ received_qty`.
- **Discovery điều chỉnh phạm vi (Step 15.1A):**
  - Tổng tồn (numerical) trong product-level luôn về initial: `-Q + R - R + Q = 0` net từ initial. ✅ KHÔNG sai.
  - Idempotent đã có guard `if status='cancelled' return 422`. ✅
  - Cancel `transferring` đã đúng từ RR-03. ✅
  - **Lỗi thật là cost integrity** khi BQ thay đổi giữa các pha — cancel dùng current cost, không snapshot.
  - Partial cancel "fabricate" missing units — limitation kiến trúc, không phải bug numerical.
- **Lỗi thật:** `StockTransferController@cancel` dùng `(float) $product->cost_price` (current BQ) cho cả `applySale` đảo destination và `applyPurchase` restore source. Khi giữa transfer_out và cancel có giao dịch khác (mua/bán) làm BQ thay đổi → cost đảo không khớp cost lúc xuất chuyển → `inventory_total_cost` lệch.
- **Root cause:**
  - Schema `stock_transfer_items` thiếu cột `cost_at_transfer` để snapshot BQ tại thời điểm `transfer_out`.
  - `applySale($product, $qty)` không nhận cost parameter — dùng current `$product->cost_price` để tính COGS → khi cancel, áp dụng BQ "hiện tại" thay vì "lúc xuất chuyển".
- **Ảnh hưởng:**
  - `inventory_total_cost` lệch sau cancel (test fail: `2,125,000.01` thay vì `2,000,000`).
  - `cost_price` (BQ moving avg) bị bias sang cost của các giao dịch sau transfer_out.
  - Báo cáo giá vốn / tồn kho hiển thị sai value cho khoảng thời gian sau cancel.
- **Ví dụ lệch cost khi BQ thay đổi:**
  - Initial: stock=10 @ 100k, total=1M.
  - Store transfer qty=3 (transferring) → stock=7, total=700k, cost=100k.
  - Mua thêm 5 @ 200k → stock=12, total=1700k, cost=141.67k.
  - Receive transfer 3 (dùng current 141.67k) → stock=15, total=2125k, cost=141.67k.
  - Cancel (current BQ 141.67k) → applySale 3 + applyPurchase 3 → stock=15, total=2125k, cost=141.67k.
  - **Đáng lẽ:** total = 1M (source) + 1M (mua mới) = 2M, cost = 133.33k.
  - **Thực tế:** total = 2,125k, cost = 141.67k → lệch +125k cost (~6.25% inflation).

---

## 2. Discovery

| Nội dung | Kết quả |
|---|---|
| Tồn kho theo product hay theo branch | Theo product (`products.stock_quantity` chung). **Chưa có** branch_inventory. |
| Có `received_quantity` không | ✅ Có — cột `stock_transfer_items.received_quantity` (nullable, từ migration `2026_04_18_173825`) |
| Có `cost_at_transfer` snapshot không | ❌ **Không** (trước fix). ✅ **Có** (sau fix RR-12). |
| Receive partial support | ✅ Có — `receive($items)` cho phép `recv_qty < quantity` (clamp 0..quantity), yêu cầu `receive_note` khi partial. |
| Cancel received support | ✅ Có — đảo destination + restore source. |
| Cancel idempotent | ✅ Có — `if status='cancelled' return 422`. |
| Tổng tồn product-level sau cancel | ✅ Đúng (luôn về initial trong mọi case Q vs R). |
| inventory_total_cost sau cancel khi BQ ổn định | ✅ Đúng. |
| inventory_total_cost sau cancel khi BQ biến động | ❌ Lệch (trước fix). ✅ Đúng (sau fix). |

---

## 3. Các thay đổi đã thực hiện

| Bước | Nội dung | File liên quan | Kết quả test |
|---|---|---|---|
| **Step 15.1A** | Discovery + viết test kiểm chứng (5 test cases — bao gồm cost variation TC-04) | `tests/Feature/Inventory/RR12StockTransferCancelReceivedTest.php`, `docs/test-cases/RR-12-stock-transfer-cancel-received.md`, `docs/audit/STEP-15.1A-...-TEST-RESULTS.md` | 4 PASS, 1 FAIL (TC-04 cost integrity) |
| **Step 15.1B** | Migration thêm `cost_at_transfer`, sửa `store/receive/cancel` dùng snapshot, đổi `applySale`→`applyPurchaseReturn` cho đảo destination | `database/migrations/2026_05_02_120200_add_cost_at_transfer_to_stock_transfer_items_table.php`, `app/Models/StockTransferItem.php`, `app/Http/Controllers/StockTransferController.php`, `docs/audit/STEP-15.1B-...-FIX-RESULTS.md` | 5 PASS, 0 FAIL |
| **Step 15.2** | Closure: cập nhật RISK_REGISTER + tạo closure report | `docs/audit/RISK_REGISTER.md`, `docs/audit/RR-12-CLOSURE-REPORT.md` (file này) | 77 PASS, 0 FAIL |

---

## 4. File đã sửa

| File | Loại | Nội dung sửa |
|---|---|---|
| `database/migrations/2026_05_02_120200_add_cost_at_transfer_to_stock_transfer_items_table.php` | Migration mới | Thêm `stock_transfer_items.cost_at_transfer DECIMAL(15,2) NULLABLE` (after `price`). Idempotent + có rollback. |
| `app/Models/StockTransferItem.php` | Model | Thêm `cost_at_transfer` vào `$fillable` + `$casts = ['cost_at_transfer' => 'decimal:2']`. |
| `app/Http/Controllers/StockTransferController.php` — `store()` | Controller | Snapshot `$costAtTransfer = $product->cost_price` **trước** `applySale`. Lưu vào item. Khi store thẳng `received`, dùng snapshot cho `applyPurchase` destination. |
| `app/Http/Controllers/StockTransferController.php` — `receive()` | Controller | `$costPerUnit = $item->cost_at_transfer ?: $product->cost_price` (fallback legacy). |
| `app/Http/Controllers/StockTransferController.php` — `cancel()` | Controller | Dùng snapshot cho cả 2 nhánh; đổi `applySale($qty)` → `applyPurchaseReturn($qty, $costPerUnit)` cho đảo destination để rút tồn theo cost snapshot (applySale không nhận cost — dùng current BQ). |

**Không sửa:** MovingAvgCostingService, StockMovementService, OrderReturnController, các module khác. Không tạo branch_inventory. Không refactor multi-warehouse.

---

## 5. Test verification

### Môi trường

```
APP_ENV=testing
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3319
DB_DATABASE=sales_test
```

### Kết quả final (02/05/2026)

| Nhóm test | File | Tests | Assertions | Kết quả |
|---|---|---:|---:|---|
| RR-12 stock transfer cancel received | `RR12StockTransferCancelReceivedTest.php` | 5 | 23 | ✅ **5 PASS** |
| RR-03 stock transfer | `RR03StockTransferTest.php` | 5 | 12 | ✅ **5 PASS** |
| RR-03 stock transfer route | `RR03StockTransferRouteTest.php` | 3 | 10 | ✅ **3 PASS** |
| RR-09 damage | `RR09DamageStockTest.php` | 5 | 12 | ✅ **5 PASS** |
| RR-05 unit | `RR05MovingAvgCostingZeroStockTest.php` | 5 | 15 | ✅ **5 PASS** |
| RR-05 feature serial | `RR05SerialImeiCostingTest.php` | 4 | 16 | ✅ **4 PASS** |
| RR-08 serial rollback | `RR08OrderReturnSerialRollbackTest.php` | 4 | 15 | ✅ **4 PASS** |
| RR-11 order return qty | `RR11OrderReturnQtyTest.php` | 4 | 8 | ✅ **4 PASS** |
| RR-01 cancel invoice | `CancelInvoiceTest.php` | 10 | 20 | ✅ **10 PASS** |
| RR-01 report P0 | `RR01ReportControllerRegressionTest.php` | 8 | 9 | ✅ **8 PASS** |
| RR-01 supplier P1 | `RR01SupplierDualRoleRegressionTest.php` | 2 | 4 | ✅ **2 PASS** |
| RR-01 cashflow P1 | `RR01CashFlowCancelledRegressionTest.php` | 4 | 4 | ✅ **4 PASS** |
| RR-04 stock take | `RR04StockTakeTest.php` | 5 | 12 | ✅ **5 PASS** |
| RR-07 repair parts | `RR07RepairPartsTest.php` | 4 | 9 | ✅ **4 PASS** |
| RR-10 cashflow deletion | `RR10CashFlowDeletionTest.php` | 5 | 12 | ✅ **5 PASS** |
| **Tổng phân biệt theo file** | | **73** | **181** | ✅ **73 PASS, 0 FAIL** |

(Lưu ý: RR-03 và RR-11 vừa nằm trong "regression liên quan" vừa "P0 audit" — đếm 1 lần. Tổng test calls khi tính tất cả filter group = 77.)

---

## 6. Quy ước mới sau RR-12

### StockTransfer

1. **Mỗi `stock_transfer_item` phải lưu `cost_at_transfer`** tại thời điểm `transfer_out` — snapshot BQ trước `applySale`.
2. **`transfer_in` / `receive()` phải dùng `cost_at_transfer`**, không dùng current BQ destination. Hàng chuyển sang kho đích phải giữ giá vốn từ kho nguồn.
3. **`cancel()` phải dùng `cost_at_transfer`** cho cả:
   - Đảo destination: `MovingAvgCostingService::applyPurchaseReturn(qty, cost_at_transfer)` (rút tồn ở cost snapshot, không current BQ).
   - Restore source: `MovingAvgCostingService::applyPurchase(qty, cost_at_transfer)`.
4. **Đảo destination KHÔNG được dùng `applySale($qty)`** vì service này không nhận cost parameter — sẽ rút tồn theo current BQ → sai cost integrity.
5. **Legacy `cost_at_transfer` null fallback `$product->cost_price`** để không crash, kèm rủi ro ghi nhận trong backlog backfill.
6. **Idempotent guard `if status='cancelled' return 422`** giữ nguyên — đã đúng.

### Pattern thống nhất giữa các RR

| RR | Module | Cột snapshot per-item |
|---|---|---|
| RR-08 | OrderReturn | `return_items.serial_ids` (JSON array) |
| RR-09 | Damage | `damage_items.serial_ids` (JSON array) |
| RR-12 | StockTransfer | `stock_transfer_items.cost_at_transfer` (decimal) |

→ Mỗi item bảo toàn snapshot cần thiết để cancel/đảo nghiệp vụ chính xác.

---

## 7. Rủi ro còn lại đưa vào backlog

| # | Khu vực | Mức độ | Ghi chú |
|---|---|---|---|
| 1 | Legacy `stock_transfer_items` cũ | Backward compat | Records tạo trước RR-12 không có `cost_at_transfer` → fallback current cost (chấp nhận tạm thời). Cần Artisan command backfill từ `stock_movements` hoặc đặt = `price` nếu production có data cũ. |
| 2 | Partial received cancel "fabricate" missing units | Limitation kiến trúc | Q=5, R=3 → cancel cộng đủ 5 về source dù chỉ R=3 đã nhận. Tổng tồn (numerical) đúng product-level nhưng audit không phản ánh "hàng mất trong vận chuyển". Cần multi-warehouse + write-off process. |
| 3 | Multi-warehouse architecture (`branch_inventory`) | Limitation lớn | `products.stock_quantity` chung không tách theo branch. Cần bảng `branch_inventory(product_id, branch_id, stock_qty, total_cost)`. Phạm vi sửa rất rộng (Invoice, Pos, Purchase, Return, StockTake, Damage, StockTransfer, Repair). Để dài hạn. |
| 4 | UI partial cancel cảnh báo | P3 | Trang `StockTransfers/Show` chưa cảnh báo khi cancel partial sẽ "phục hồi" missing units. |
| 5 | Test partial cancel với cost variation | P3 | Chưa cover scenario partial + cost change cùng lúc. |
| 6 | RR-02 Invoice/POS duplicate | P1 | Logic bán hàng duplicate — độc lập với RR-12 |
| 7 | RR-06 customer_debt_transactions | P2 | Tách bảng + service |
| 8 | Backlog RR-08/RR-09/Bước 11 | P2/P3 | Legacy backfill, UI, permission tách, test multi-line/draft cancel — đã có |
| 9 | Cosmetic RR-05 | — | `applyPurchase`/`applySaleReturn` fallback 0 (không xảy ra trong nghiệp vụ) |

---

## 8. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Mục 3.4 (snapshot cost lúc bán cho KH trả), 3.5 (snapshot cost lúc nhập cho trả NCC), 5 (hủy phải đảo đúng) — pattern snapshot thống nhất |
| `docs/audit/RISK_REGISTER.md` | Bảng rủi ro tổng — RR-12 = Fixed/Verified |
| `docs/test-cases/RR-12-stock-transfer-cancel-received.md` | Test case spec |
| `docs/audit/STEP-15.1A-RR12-STOCK-TRANSFER-CANCEL-RECEIVED-TEST-RESULTS.md` | Test chứng minh lỗi (4 PASS, 1 FAIL) |
| `docs/audit/STEP-15.1B-RR12-STOCK-TRANSFER-CANCEL-RECEIVED-FIX-RESULTS.md` | Sửa lỗi (5 PASS, 0 FAIL) |
| `docs/audit/RR-12-CLOSURE-REPORT.md` | File này — closure report |
| `tests/Feature/Inventory/RR12StockTransferCancelReceivedTest.php` | Feature test (5 PASS) |
| `app/Http/Controllers/StockTransferController.php` | Controller đã sửa store + receive + cancel |
| `app/Models/StockTransferItem.php` | Model đã thêm cast |
| `database/migrations/2026_05_02_120200_add_cost_at_transfer_to_stock_transfer_items_table.php` | Migration mới |
| `docs/audit/RR-03-CLOSURE-REPORT.md` | RR-03 closure — context cho fix RR-12 |
| `docs/audit/RR-09-CLOSURE-REPORT.md` | RR-09 closure — pattern snapshot tham khảo |

---

## 9. Kết luận

✅ **RR-12 đã Fixed/Verified về cost integrity.**

- Phạm vi bug điều chỉnh đúng đắn: tổng tồn product-level OK, idempotent OK, cancel transferring OK; bug duy nhất là cost integrity khi BQ biến động.
- Migration thêm `cost_at_transfer`; controller dùng snapshot cho store/receive/cancel; cancel đảo destination dùng `applyPurchaseReturn` thay vì `applySale` để rút tồn theo cost snapshot.
- 5/5 RR-12 + 22 P1 regression + 50 P0 audit regression = **77/77 PASS, 0 FAIL.**
- Phạm vi sửa hẹp (1 migration + 2 file business code), không refactor multi-warehouse, không sửa service/module khác.
- Pattern snapshot thống nhất với RR-08 (`return_items.serial_ids`) và RR-09 (`damage_items.serial_ids`).
- **Multi-warehouse architecture vẫn là limitation lớn ở backlog dài hạn**, không chặn đóng RR-12.

### Tổng kết tiến độ audit

| Mã | Module | Mức | Trạng thái |
|---|---|---|---|
| RR-01 | Invoice cancel | P0 | ✅ Fixed/Verified |
| RR-02 | Invoice/POS duplicate | P1 | 🟡 Chưa xử lý |
| RR-03 | Stock transfer | P0 | ✅ Fixed/Verified |
| RR-04 | Stock take | P0 | ✅ Fixed/Verified |
| RR-05 | Costing zero stock | P1 | ✅ Fixed/Verified |
| RR-06 | Customer debt | P2 | 🔵 Chưa xử lý |
| RR-07 | Repair parts | P0 | ✅ Fixed/Verified |
| RR-08 | OrderReturn rollback serial | P1 | ✅ Fixed/Verified |
| RR-09 | Damage | P1 | ✅ Fixed/Verified |
| RR-10 | CashFlow deletion | P0 | ✅ Fixed/Verified |
| RR-11 | OrderReturn qty | P0 | ✅ Fixed/Verified |
| RR-12 | StockTransfer multi-warehouse | P1 | ✅ **Fixed/Verified (Bước 15.2)** |

**Sẵn sàng chuyển sang RR-02 (P1 cuối cùng) hoặc tổng kết P1.**

**Tổng tiến độ:** 10/12 rủi ro đã đóng (6 P0 + 4 P1 = RR-05, RR-08, RR-09, RR-12). Còn 2 rủi ro: 1 P1 (RR-02) + 1 P2 (RR-06).
