# RR-11 Closure Report — Trả hàng khách không được vượt số lượng đã bán

> **Mã rủi ro:** RR-11  
> **Mức độ ban đầu:** 🔴 P0 — Critical  
> **Trạng thái cuối:** ✅ **Fixed/Verified**  
> **Ngày đóng:** 02/05/2026  
> **Test verification:** 50 PASS, 0 FAIL

---

## 1. Tóm tắt lỗi ban đầu

- **Lỗi gì:** OrderReturnController@store cho phép trả hàng khách vượt số lượng đã bán trên hóa đơn, trả trùng nhiều lần vượt qty gốc, và trả hàng trên hóa đơn đã hủy.
- **Root cause:**
  - Validation chỉ check `items.*.qty >= 1` (dòng 111)
  - KHÔNG check `qty trả <= invoice_item.quantity`
  - KHÔNG tính tổng qty đã trả trước đó (`already_returned`)
  - KHÔNG kiểm tra `invoice.status`
- **Ảnh hưởng:**
  - Tồn kho tăng vô lý (bán 5, trả 8 → stock cộng 8)
  - Công nợ khách hàng bị âm quá mức
  - CashFlow hoàn tiền vượt thực tế
  - `inventory_total_cost` sai do `applySaleReturn()` chạy trên qty vượt

---

## 2. Các thay đổi đã thực hiện

| Bước | Nội dung | File liên quan | Kết quả test |
|---|---|---|---|
| **Step 10.1A** | Viết test chứng minh lỗi (4 test cases) | `tests/Feature/OrderReturn/RR11OrderReturnQtyTest.php` | 3 FAIL, 1 PASS |
| **Step 10.1B** | Thêm validation qty + invoice status trong store() | `app/Http/Controllers/OrderReturnController.php` | 4/4 PASS |

### Tổng file đã sửa

| File | Nội dung sửa |
|---|---|
| `app/Http/Controllers/OrderReturnController.php` | Thêm RR-11 validation block (~45 dòng) + import `ReturnItem` |

### Chi tiết validation thêm

```php
// 1. Check invoice chưa hủy
if ($invoice->status === 'Đã hủy') → throw ValidationException

// 2. Gom qty request theo product_id
$requestedByProduct[product_id] += qty

// 3. Với mỗi product:
$soldQty = InvoiceItem::where(invoice_id, product_id)->sum('quantity')
$alreadyReturned = ReturnItem::where(product_id)->whereHas(orderReturn, [invoice_id, status != 'Đã hủy'])->sum('quantity')
$remainingQty = $soldQty - $alreadyReturned

// 4. Nếu requested > remaining → throw ValidationException
```

---

## 3. Test verification

### Kết quả final (02/05/2026)

| Nhóm test | File | Tests | Kết quả |
|---|---|---:|---|
| RR-11 order return qty | `RR11OrderReturnQtyTest.php` | 4 | ✅ **4 PASS** |
| RR-10 cashflow deletion | `RR10CashFlowDeletionTest.php` | 5 | ✅ **5 PASS** |
| RR-07 repair parts | `RR07RepairPartsTest.php` | 4 | ✅ **4 PASS** |
| RR-04 stock take | `RR04StockTakeTest.php` | 5 | ✅ **5 PASS** |
| RR-03 core | `RR03StockTransferTest.php` | 5 | ✅ **5 PASS** |
| RR-03 route | `RR03StockTransferRouteTest.php` | 3 | ✅ **3 PASS** |
| RR-01 cancel invoice | `CancelInvoiceTest.php` | 10 | ✅ **10 PASS** |
| RR-01 report P0 | `RR01ReportControllerRegressionTest.php` | 8 | ✅ **8 PASS** |
| RR-01 supplier P1 | `RR01SupplierDualRoleRegressionTest.php` | 2 | ✅ **2 PASS** |
| RR-01 cashflow P1 | `RR01CashFlowCancelledRegressionTest.php` | 4 | ✅ **4 PASS** |
| **Tổng** | | **50** | ✅ **50 PASS, 0 FAIL** |

```
Tests:    50 passed (100 assertions)
Duration: 3.89s
```

---

## 4. Quy ước mới sau RR-11

### Trả hàng khách theo hóa đơn

1. **Invoice phải chưa hủy** — `invoice.status != 'Đã hủy'`.
2. **Product phải thuộc invoice** — `invoice_items` phải có `product_id` tương ứng.
3. **Tổng đã trả + lần trả mới ≤ số lượng bán** — `already_returned + requested_qty <= sold_qty`.
4. **Nhiều dòng cùng product** — gom qty trước khi validate, không validate từng dòng riêng lẻ.
5. **Validation fail → không tạo gì** — không OrderReturn, ReturnItem, StockMovement, CashFlow, không cập nhật tồn/công nợ.
6. **Luồng hợp lệ** — giữ nguyên: `applySaleReturn()`, `StockMovement TYPE_IN_INVOICE_RETURN`, CashFlow/công nợ.

### So sánh với PurchaseReturnController

| Khía cạnh | PurchaseReturn | OrderReturn (sau RR-11) |
|---|---|---|
| Validate qty | Có (tính `total_returned`) | ✅ Có (tính `already_returned`) |
| Check source status | Chưa rõ | ✅ Check invoice status |
| Gom multi-line | Chưa rõ | ✅ Gom theo product_id |

---

## 5. P1/P3 còn lại đưa vào backlog

| # | Khu vực | Mô tả | Mức độ |
|---|---|---|---|
| 1 | Route cancel | OrderReturn `cancel()` method tồn tại nhưng chưa đăng ký route | P1 |
| 2 | Test cancel | Chưa có test hủy OrderReturn idempotent | P2 |
| 3 | Test multi-line | Chưa có test nhiều dòng cùng product trong 1 request | P3 |
| 4 | Serial/IMEI | Chưa test trả hàng serial/IMEI validate | P3 |
| 5 | Trả hàng không HĐ | `invoice_id` nullable → có thể trả hàng không cần invoice, validation chỉ chạy khi có `invoice_id` | P3 |

---

## 6. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `docs/audit/RISK_REGISTER.md` | Bảng rủi ro tổng — RR-11 = Fixed/Verified |
| `docs/test-cases/RR-11-order-return-qty.md` | Test case document (4 TCs) |
| `docs/audit/STEP-10.1A-RR11-ORDER-RETURN-QTY-TEST-RESULTS.md` | Test chứng minh lỗi |
| `docs/audit/STEP-10.1B-RR11-ORDER-RETURN-QTY-FIX-RESULTS.md` | Sửa controller |
| `docs/audit/RR-11-CLOSURE-REPORT.md` | File này — closure report |

---

## 7. Kết luận

✅ **RR-11 đã Fixed/Verified.**

- Lỗi gốc (thiếu qty validation khi trả hàng khách) đã sửa triệt để.
- 3 loại validation thêm: invoice status, qty vs sold, multi-line gom.
- 4 regression tests bao phủ over-return, cumulative over-return, happy path, cancelled invoice.
- Tổng 50/50 test PASS bao gồm RR-01 + RR-03 + RR-04 + RR-07 + RR-10 regression.
- P1/P3 items đã ghi nhận vào backlog.

### Tổng kết tiến độ audit — TẤT CẢ P0 ĐÃ XONG

| Mã | Module | Trạng thái |
|---|---|---|
| RR-01 | Invoice cancel | ✅ Fixed/Verified |
| RR-03 | Stock transfer | ✅ Fixed/Verified |
| RR-04 | Stock take | ✅ Fixed/Verified |
| RR-07 | Repair parts | ✅ Fixed/Verified |
| RR-10 | CashFlow deletion | ✅ Fixed/Verified |
| RR-11 | OrderReturn qty | ✅ Fixed/Verified |

**Tất cả 6 rủi ro P0 trong Risk Register đã Fixed/Verified.**  
Có thể chuyển sang tổng kết audit / full regression / xử lý P1-P3 backlog.
