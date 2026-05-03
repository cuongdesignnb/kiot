# STEP-10.1B — Fix RR-11 OrderReturn Qty Validation

> **Mã rủi ro:** RR-11  
> **Ngày sửa:** 02/05/2026  
> **Trạng thái:** ✅ **FIXED — 4/4 test PASS**

---

## 1. Vấn đề đã sửa

- ❌→✅ Cho trả quá số lượng bán (trả 8 khi chỉ bán 5 → stock vọt 13)
- ❌→✅ Cho trả vượt phần còn lại sau khi đã trả một phần (3+3=6 > 5)
- ❌→✅ Cho trả hàng trên invoice đã hủy

---

## 2. File đã sửa

| File | Nội dung sửa |
|---|---|
| `app/Http/Controllers/OrderReturnController.php` | Thêm RR-11 validation block + import `ReturnItem` |

---

## 3. Cách sửa

### Validate invoice status
- Kiểm tra `invoice.status === 'Đã hủy'` → throw `ValidationException`
- Chạy trước mọi DB mutation (trước `DB::transaction`)

### Validate qty trả vs qty bán
- `soldQty`: `InvoiceItem::where(invoice_id, product_id)->sum('quantity')`
- `alreadyReturned`: `ReturnItem::where(product_id)->whereHas('orderReturn', [invoice_id, status != 'Đã hủy'])->sum('quantity')`
- `remainingQty = soldQty - alreadyReturned`
- Nếu `requestedQty > remainingQty` → throw `ValidationException` với message rõ

### Validate nhiều dòng cùng product
- Gom `requestedQty` theo `product_id` trước khi validate
- Đảm bảo tổng qty request cho cùng product không vượt remaining

### Khi validation fail
- Throw `ValidationException` → Laravel redirect back with errors
- Không tạo `OrderReturn`
- Không tạo `ReturnItem`
- Không cộng tồn (`applySaleReturn` không gọi)
- Không tạo `StockMovement`
- Không tạo `CashFlow`
- Không cập nhật `Customer.debt_amount`

### Khi validation pass
- Giữ nguyên toàn bộ logic hiện có:
  - `MovingAvgCostingService::applySaleReturn()`
  - `StockMovementService::record()` TYPE_IN_INVOICE_RETURN
  - `CashFlow` tạo khi `paid_to_customer > 0`
  - Customer debt/total_spent decrement

---

## 4. Kết quả test

### RR11OrderReturnQtyTest — 4/4 PASS

| # | Test | Trước sửa | Sau sửa |
|---|---|---|---|
| 1 | `cannot_return_more_than_invoiced_quantity` | ❌ FAIL (stock 5→13) | ✅ PASS |
| 2 | `cannot_return_exceeding_remaining_quantity` | ❌ FAIL (tổng 6>5) | ✅ PASS |
| 3 | `can_return_exact_remaining_quantity` | ✅ PASS | ✅ PASS |
| 4 | `cannot_return_on_cancelled_invoice` | ❌ FAIL (cho trả) | ✅ PASS |

### Existing regression — 46/46 PASS

| Test Suite | Kết quả |
|---|---|
| RR10CashFlowDeletionTest (5) | ✅ 5 PASS |
| RR07RepairPartsTest (4) | ✅ 4 PASS |
| RR04StockTakeTest (5) | ✅ 5 PASS |
| RR03StockTransferTest (5) | ✅ 5 PASS |
| RR03StockTransferRouteTest (3) | ✅ 3 PASS |
| CancelInvoiceTest (10) | ✅ 10 PASS |
| RR01ReportControllerRegressionTest (8) | ✅ 8 PASS |
| RR01SupplierDualRoleRegressionTest (2) | ✅ 2 PASS |
| RR01CashFlowCancelledRegressionTest (4) | ✅ 4 PASS |

### Tổng

```
Tests:    50 passed (100 assertions)
Duration: 3.5s
```

---

## 5. Rủi ro còn lại

| # | Khu vực | Mô tả | Mức độ |
|---|---|---|---|
| 1 | Route cancel | OrderReturn `cancel()` method tồn tại nhưng chưa đăng ký route | P1 |
| 2 | Test cancel | Chưa có test hủy OrderReturn idempotent | P2 |
| 3 | Test multi-line | Chưa có test nhiều dòng cùng product trong 1 request | P3 |
| 4 | Serial/IMEI | Chưa test trả hàng serial/IMEI validate | P3 |
| 5 | Trả hàng không HĐ | `invoice_id` nullable → có thể trả hàng không cần invoice, validation chỉ chạy khi có invoice_id | P3 |

---

## 6. Kết luận

- ✅ **RR-11 đã Fixed** — 4/4 test PASS, 46/46 regression PASS, tổng 50/50.
- ✅ Sửa tối thiểu: 1 file, ~45 dòng validation code.
- ✅ Không phá happy path (TC-03 vẫn pass).
- ✅ Có thể chuyển sang **closure RR-11**.
