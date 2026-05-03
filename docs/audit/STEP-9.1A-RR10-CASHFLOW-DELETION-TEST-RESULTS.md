# STEP-9.1A — RR-10 CashFlow Deletion Test Results

> **Mã rủi ro:** RR-10  
> **Ngày test:** 02/05/2026  
> **Trạng thái:** 🔴 **4/5 FAIL — RR-10 xác nhận**

---

## 1. Mục tiêu

Chứng minh CashFlow bị soft-delete khi hủy chứng từ nhưng **không set status='cancelled'**, gây ra:
- withTrashed() query thấy CashFlow nhưng status vẫn 'active'
- Nếu restore → CashFlow tính lại vào báo cáo
- withTrashed().active() tính nhầm CashFlow đã hủy

---

## 2. Luồng code phát hiện

| Thành phần | File / Route / Table |
|---|---|
| **CashFlow Model** | `app/Models/CashFlow.php` — SoftDeletes ✅, scopeActive() ✅ |
| **CashFlowController@destroy** | Dòng 189-190: `update(['status'=>'cancelled'])` + `delete()` ✅ |
| **PurchaseController@destroy** | Dòng 710-712: `CashFlow::where(...)->delete()` **KHÔNG set status** ❌ |
| **OrderReturnController@cancel** | Dòng 389-391: `CashFlow::where(...)->delete()` **KHÔNG set status** ❌ |
| **PurchaseReturnController@cancel** | Dòng 474-476: `CashFlow::where(...)->delete()` **KHÔNG set status** ❌ |
| **InvoiceController@update** | Dòng 524-526: `CashFlow::where(...)->delete()` khi sửa HĐ — **KHÔNG set status** ❌ |
| **InvoiceController@cancel** | Đã sửa trong RR-01: `update(['status'=>'cancelled'])` ✅ |

---

## 3. Kết quả scan code

| File | Pattern | Rủi ro | Ghi chú |
|---|---|---|---|
| `CashFlowController.php:189` | `update(['status'=>'cancelled'])` + `delete()` | ✅ Chuẩn | Đã đúng |
| `InvoiceController.php:643-644` | `update(['status'=>'cancelled'])` | ✅ Chuẩn | Đã sửa trong RR-01 |
| `PurchaseController.php:710-712` | `CashFlow::where(...)->delete()` | ❌ **Thiếu status** | Soft-delete nhưng status giữ 'active' |
| `OrderReturnController.php:389-391` | `CashFlow::where(...)->delete()` | ❌ **Thiếu status** | Soft-delete nhưng status giữ 'active' |
| `PurchaseReturnController.php:474-476` | `CashFlow::where(...)->delete()` | ❌ **Thiếu status** | Soft-delete nhưng status giữ 'active' |
| `InvoiceController.php:524-526` | `CashFlow::where(...)->delete()` | ⚠️ **Thiếu status** | Xóa CashFlow cũ khi sửa HĐ — khác ngữ cảnh (update, không cancel) |

### SoftDeletes hệ thống

- CashFlow model dùng `use SoftDeletes` → `->delete()` chỉ set `deleted_at`, KHÔNG xóa vật lý
- Record vẫn trong DB nhưng ẩn khỏi query bình thường
- `withTrashed()` vẫn thấy record
- `scopeActive()` chỉ lọc `status != 'cancelled'`, KHÔNG lọc `deleted_at`

### Vấn đề cụ thể

```
                                     Normal query    withTrashed()   active()   withTrashed()->active()
CashFlow cancelled + deleted_at:     ẩn              ✅ thấy         ❌ lọc       ❌ lọc (đúng)
CashFlow active + deleted_at:        ẩn              ✅ thấy         N/A         ✅ thấy (SAI!)
```

Khi chỉ gọi `->delete()` mà KHÔNG set `status='cancelled'`:
- CashFlow bị trashed nhưng status vẫn `active`
- `withTrashed()->active()` sẽ tính nhầm CashFlow đã hủy
- Nếu ai đó `restore()` → CashFlow xuất hiện lại trong báo cáo với đầy đủ amount

---

## 4. Dữ liệu test

| Loại | Type | Amount | Status trước | Status kỳ vọng |
|---|---|---|---|---|
| Purchase CashFlow | payment | 3.000.000 | active | cancelled |
| OrderReturn CashFlow | payment | 500.000 | active | cancelled |
| PurchaseReturn CashFlow | receipt | 800.000 | active | cancelled |
| Test scope active | receipt | 1M + 9M | active + trashed | chỉ tính 1M |

---

## 5. Test đã tạo

| # | Test | Kỳ vọng | Thực tế | Kết quả |
|---|---|---|---|---|
| 1 | `cancel_purchase_cashflow_must_set_status_cancelled` | status='cancelled' | status='active' | ❌ **FAIL** |
| 2 | `cancel_order_return_cashflow_must_set_status_cancelled` | status='cancelled' | status='active' | ❌ **FAIL** |
| 3 | `cancel_purchase_return_cashflow_must_set_status_cancelled` | status='cancelled' | status='active' | ❌ **FAIL** |
| 4 | `scope_active_should_not_include_soft_deleted_without_status` | sum = 1M | sum = 10M | ❌ **FAIL** |
| 5 | `cashflow_controller_destroy_sets_cancelled_status` | status='cancelled' | status='cancelled' | ✅ **PASS** |

---

## 6. Kết quả chạy test

```
RR10CashFlowDeletionTest: 4 failed, 1 passed (12 assertions)
Duration: 0.50s
```

---

## 7. Nguyên nhân fail

### ❌ TC-01/02/03: Hủy chứng từ KHÔNG set status='cancelled'
- `PurchaseController@destroy`, `OrderReturnController@cancel`, `PurchaseReturnController@cancel` chỉ gọi `->delete()` (soft-delete)
- KHÔNG gọi `update(['status' => 'cancelled'])` trước
- Kết quả: CashFlow bị trashed nhưng status giữ 'active'

### ❌ TC-04: withTrashed()->active() tính nhầm
- CashFlow bị trashed nhưng status='active'
- `withTrashed()->active()` vẫn include record này
- Sum = 10M thay vì 1M (gộp cả CashFlow đã "hủy")

### ✅ TC-05: CashFlowController@destroy đã đúng
- Set `status='cancelled'` trước khi `delete()`
- Đúng chuẩn — pattern để follow cho các controller khác

---

## 8. Existing regression

```
RR07RepairPartsTest: 4 PASS
RR04StockTakeTest: 5 PASS
RR03StockTransferTest: 5 PASS
RR03StockTransferRouteTest: 3 PASS
CancelInvoiceTest: 10 PASS
RR01ReportControllerRegressionTest: 8 PASS
RR01SupplierDualRoleRegressionTest: 2 PASS
RR01CashFlowCancelledRegressionTest: 4 PASS
Tổng existing: 41 PASS, 0 FAIL
```

---

## 9. Kết luận

- ✅ **RR-10 đã được chứng minh bằng test** — 4/5 FAIL xác nhận lỗi
- ✅ Pattern rõ ràng: cần thêm `update(['status'=>'cancelled'])` trước `->delete()` ở 3 controllers
- ✅ **Có đủ điều kiện chuyển sang Bước 9.1B** để sửa

### Phạm vi sửa dự kiến cho Bước 9.1B

1. `PurchaseController@destroy` dòng 710-712: Thêm `update(['status'=>'cancelled'])` trước `->delete()`
2. `OrderReturnController@cancel` dòng 389-391: Thêm `update(['status'=>'cancelled'])` trước `->delete()`
3. `PurchaseReturnController@cancel` dòng 474-476: Thêm `update(['status'=>'cancelled'])` trước `->delete()`
4. (Tùy chọn) `InvoiceController@update` dòng 524-526: Xem xét xử lý CashFlow khi sửa hóa đơn
