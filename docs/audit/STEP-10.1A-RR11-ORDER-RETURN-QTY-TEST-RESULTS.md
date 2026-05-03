# STEP-10.1A — RR-11 OrderReturn Qty Test Results

> **Mã rủi ro:** RR-11  
> **Ngày test:** 02/05/2026  
> **Trạng thái:** 🔴 **3/4 FAIL — RR-11 xác nhận**

---

## 1. Mục tiêu

Chứng minh nghiệp vụ trả hàng khách KHÔNG validate số lượng trả vs số lượng đã bán trên hóa đơn.

---

## 2. Luồng code phát hiện

| Thành phần | File / Route / Table |
|---|---|
| Route tạo trả hàng | `POST /returns` → `returns.store` |
| Route hủy trả hàng | ❌ **KHÔNG CÓ ROUTE** — `cancel()` method tồn tại nhưng chưa đăng ký |
| Controller | `OrderReturnController` |
| Service | Không có service riêng — logic trực tiếp trong controller |
| Model trả hàng | `OrderReturn` (table `returns`) |
| Model items | `ReturnItem` (table `return_items`) |
| Bảng tồn kho | `products.stock_quantity`, `products.inventory_total_cost` |
| Bảng stock movement | `stock_movements` — type `in_invoice_return` |
| Bảng cashflow | `cash_flows` — type `payment`, reference_type `OrderReturn` |

### Validation hiện tại (dòng 96-117)

```php
$validated = $request->validate([
    'items.*.qty' => 'required|numeric|min:1',  // Chỉ check >= 1
    // KHÔNG check vs invoice_item.quantity
    // KHÔNG check tổng đã trả trước đó
    // KHÔNG check invoice.status
]);
```

### Tích hợp đúng (đã có)

| Tích hợp | Có/Không | Dòng |
|---|---|---|
| `MovingAvgCostingService::applySaleReturn()` | ✅ Có | 218-222 |
| `StockMovementService::record()` TYPE_IN_INVOICE_RETURN | ✅ Có | 241-253 |
| CashFlow tạo khi `paid_to_customer > 0` | ✅ Có | 266-281 |
| Customer debt/total_spent rollback | ✅ Có | 255-261 |
| Validate qty trả vs qty bán | ❌ **KHÔNG CÓ** | — |
| Validate invoice status | ❌ **KHÔNG CÓ** | — |
| Check tổng đã trả trước đó | ❌ **KHÔNG CÓ** | — |

---

## 3. Dữ liệu test

| Thành phần | Giá trị |
|---|---|
| Product | stock=10, cost=100K, total_cost=1M |
| Invoice | bán 5 qty × 200K = 1M |
| Stock after sale | 5, total_cost=500K |
| Phiếu trả | qty=8, qty=3+3, qty=3+2 |

---

## 4. Test đã tạo

| # | Test | Kỳ vọng | Thực tế | Kết quả |
|---|---|---|---|---|
| 1 | `cannot_return_more_than_invoiced_quantity` | Reject trả 8 (bán 5) | Stock vọt 13 (+8) | ❌ **FAIL** |
| 2 | `cannot_return_exceeding_remaining_quantity` | Tổng trả ≤ 5 | Tổng trả = 6 (3+3) | ❌ **FAIL** |
| 3 | `can_return_exact_remaining_quantity` | Trả 3+2=5, stock=10 | Đúng | ✅ **PASS** |
| 4 | `cannot_return_on_cancelled_invoice` | Reject trả trên HĐ đã hủy | Cho trả bình thường | ❌ **FAIL** |

---

## 5. Kết quả chạy test

```
RR11OrderReturnQtyTest: 3 failed, 1 passed (7 assertions)
Duration: 0.70s
```

---

## 6. Nguyên nhân fail

### ❌ TC-01: Cho trả quá số lượng bán
- Invoice bán qty=5, trả qty=8 → ACCEPTED
- Stock vọt từ 5 lên 13 (cộng 8 thay vì max 5)
- **Root cause:** Validation chỉ check `qty >= 1`, KHÔNG check `qty <= invoice_item.quantity`
- **Dòng thiếu:** Cần thêm validation sau dòng 117

### ❌ TC-02: Cho trả trùng vượt phần còn lại
- Lần 1 trả 3 (OK), lần 2 trả 3 (ACCEPTED — sai)
- Tổng trả = 6 > qty bán = 5
- **Root cause:** KHÔNG check tổng `return_items.quantity` đã trả cho cùng `invoice_id` + `product_id`

### ❌ TC-04: Cho trả trên invoice đã hủy
- Invoice status = 'Đã hủy', trả hàng → ACCEPTED
- **Root cause:** KHÔNG check `invoice.status` trước khi xử lý

### ✅ TC-03: Happy path đúng
- Trả 3 + 2 = 5 (vừa đúng qty bán) → OK
- Stock cộng đúng → 10

---

## 7. Existing regression

```
RR10CashFlowDeletionTest: 5 PASS
RR07RepairPartsTest: 4 PASS
RR04StockTakeTest: 5 PASS
RR03StockTransferTest: 5 PASS
RR03StockTransferRouteTest: 3 PASS
CancelInvoiceTest: 10 PASS
RR01ReportControllerRegressionTest: 8 PASS
RR01SupplierDualRoleRegressionTest: 2 PASS
RR01CashFlowCancelledRegressionTest: 4 PASS
Tổng existing: 46 PASS, 0 FAIL
```

---

## 8. Phát hiện bổ sung

| # | Vấn đề | Mức độ |
|---|---|---|
| 1 | Route cancel cho OrderReturn chưa đăng ký (method `cancel()` có nhưng thiếu route) | P1 |
| 2 | Không có validation cho invoice đã hủy | P0 |
| 3 | CostingService + StockMovement đã tích hợp đúng — chỉ thiếu validation đầu vào | — |

---

## 9. Kết luận

- ✅ **RR-11 đã được chứng minh bằng test** — 3/4 FAIL xác nhận lỗi rõ ràng
- ✅ Root cause: OrderReturnController@store thiếu validation qty trả vs qty bán + check invoice status
- ✅ **Có đủ điều kiện chuyển sang Bước 10.1B** để sửa

### Phạm vi sửa dự kiến cho Bước 10.1B

1. **OrderReturnController@store**: Thêm validation:
   - Check `invoice.status != 'Đã hủy'`
   - Với mỗi item: tính `total_returned` = sum(return_items.quantity) cho cùng invoice + product
   - Check `total_returned + qty_mới <= invoice_item.quantity`
2. **Route cancel**: Đăng ký route cho `cancel()` method (nếu yêu cầu)
