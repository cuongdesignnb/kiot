# RR-12 — Hủy phiếu chuyển kho received phải an toàn trong mô hình chưa multi-warehouse

> **Mã rủi ro:** RR-12
> **Mức độ:** P1 — Sai tồn kho khi hủy phiếu received, đặc biệt ở partial receive hoặc khi BQ thay đổi giữa các pha.
> **Ngày tạo:** 02/05/2026

---

## Mục tiêu

Kiểm chứng `StockTransferController@cancel` (đặc biệt nhánh `received`) trong mô hình hệ thống chỉ có `products.stock_quantity` chung (chưa tách inventory theo branch):
- Tổng tồn có sai sau cancel không?
- Giá vốn (`inventory_total_cost`, `cost_price`) có giữ đúng không khi BQ thay đổi giữa các pha?
- Partial received cancel có "fabricate" đơn vị missing không?
- Idempotent có hoạt động không?

---

## Discovery (đã đọc code)

### Logic cancel hiện tại — `StockTransferController@cancel` dòng 320-345

```php
foreach ($transfer->items as $item) {
    $product = Product::find($item->product_id);
    $costPerUnit = (float) $product->cost_price; // ← CURRENT COST, không snapshot

    // Đảo destination khi đã received
    if ($transfer->status === 'received' && $item->received_quantity > 0) {
        MovingAvgCostingService::applySale($product, $item->received_quantity);
        StockMovementService::record(... TYPE_TRANSFER_OUT, $item->received_quantity, $costPerUnit ...);
    }

    // Restore source: cộng đủ item.quantity (KHÔNG chỉ received_quantity)
    $costPerUnit = (float) $product->cost_price; // ← CURRENT COST sau applySale
    MovingAvgCostingService::applyPurchase($product, $item->quantity, $costPerUnit);
    StockMovementService::record(... TYPE_TRANSFER_IN, $item->quantity, $costPerUnit ...);
}
```

### Quan sát

1. **Tổng tồn (numerical) trong product-level**:
   - Initial = X.
   - Store transferring qty=Q: `-Q` từ X → X-Q.
   - Receive received_qty=R: `+R` → X-Q+R.
   - Cancel: `-R` (đảo destination) + `+Q` (restore source) → X-Q+R-R+Q = X. ✅
   - **Tổng tồn về đúng X** trong mọi trường hợp Q vs R.

2. **Cost integrity**:
   - Cancel dùng `current cost_price` (BQ tại thời điểm cancel) thay vì cost snapshot lúc transfer_out.
   - Nếu giữa các pha có giao dịch khác (mua/bán) làm BQ thay đổi → cancel không khôi phục về cost gốc.
   - `inventory_total_cost` lệch sai về business value của lô hàng.

3. **Partial cancel "fabricate" missing units**:
   - Q=5, R=3 → 2 đơn vị "missing" giữa source-destination.
   - Cancel cộng đủ Q=5 về source → 2 đơn vị missing "trở lại" mà không có chứng từ write-off.
   - Audit trail không phản ánh đúng nghiệp vụ "hàng mất trong vận chuyển".

4. **Idempotent**: Có guard `if status='cancelled' return 422` ✅.

5. **Schema thiếu cost snapshot**: `stock_transfer_items` không có cột `cost_price` lưu BQ tại thời điểm `transfer_out`.

---

## Test cases

### TC-RR12-01: Cancel fully received đơn giản (no cost variation) — PASS expected

**Setup:** stock=10, cost=100k, total=1M.

**Action:** store `transferring` qty=3 → receive() full recv=3 → cancel.

**Kỳ vọng:** stock=10, total=1M, cost=100k (về initial).

### TC-RR12-02: Cancel partial received — stock số học OK trong product-level

**Setup:** stock=10, cost=100k, total=1M.

**Action:** store `transferring` qty=5 → receive() partial recv=3 → cancel.

**Kỳ vọng:** stock=10 (tổng đúng trong product-level dù 2 đơn vị "missing" được "fabricate" cộng lại).

→ Đây là behavior hiện tại; test document hành vi này. Trong mô hình multi-warehouse đúng phải có write-off cho 2 đơn vị missing.

### TC-RR12-03: Cancel received idempotent — second cancel không đổi state

**Setup:** Sau TC-01.

**Action:** Cancel lần 2.

**Kỳ vọng:**
- Phản hồi 422 (đã có guard).
- Stock/cost không đổi.
- Không tạo movement đảo thêm.

### TC-RR12-04: Cancel received KHÔNG bảo toàn cost khi BQ thay đổi giữa các pha — FAIL expected

**Setup:** stock=10 @ 100k = 1M.

**Action:**
1. store `transferring` qty=3 → stock=7, total=700k, cost=100k.
2. Mua thêm 5 @ 200k qua `MovingAvgCostingService::applyPurchase` → stock=12, total=1700k, cost=141.67k.
3. receive() full recv=3 (dùng current cost 141.67k) → stock=15, total=2125k.
4. cancel — controller dùng current cost 141.67k cho cả applySale và applyPurchase.

**Kỳ vọng (đúng business):** total = 1M (source ban đầu) + 1M (mua mới) = **2M**, cost = 2M/15 = **133.33k**.

**Thực tế (controller hiện tại):** total ~2125k, cost 141.67k → lệch +125k cost.

→ Test FAIL chứng minh bug RR-12 về cost integrity.

### TC-RR12-05: Cancel transferring (chưa received) restore source đầy đủ — PASS expected

**Setup:** stock=10, cost=100k.

**Action:** store `transferring` qty=3 (không gọi receive) → cancel.

**Kỳ vọng:** stock=10, total=1M, cost=100k.

→ Validate logic RR-03 cho nhánh transferring cancel.

---

## Phạm vi sửa (Bước 15.1B kỳ vọng)

Hai hướng:

**Option A — Validation tạm thời (đề xuất an toàn):**
- Reject cancel `received` nếu `qty != received_qty` → tránh "fabricate" missing.
- Lưu cột `stock_transfer_items.cost_at_transfer` (cost snapshot lúc transfer_out).
- Khi cancel, dùng `cost_at_transfer` thay vì current cost → bảo toàn cost integrity.

**Option B — Multi-warehouse (kiến trúc lớn, dài hạn):**
- Tách bảng `branch_inventory` (product_id, branch_id, stock_quantity, inventory_total_cost).
- Tất cả luồng nhập/bán/chuyển phải qua branch_inventory.
- Phạm vi sửa rất rộng (Invoice, Pos, Purchase, Return, StockTake, Damage, StockTransfer, Repair).

Bước 15.1B nên đi Option A — sửa hẹp và đủ để pass test.
