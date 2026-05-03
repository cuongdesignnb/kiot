# RR-10 Closure Report — CashFlow không được mất dấu vết khi hủy/xóa

> **Mã rủi ro:** RR-10  
> **Mức độ ban đầu:** 🔴 P0 — Critical  
> **Trạng thái cuối:** ✅ **Fixed/Verified**  
> **Ngày đóng:** 02/05/2026  
> **Test verification:** 46 PASS, 0 FAIL

---

## 1. Tóm tắt lỗi ban đầu

- **Lỗi gì:** Khi hủy chứng từ (Purchase, OrderReturn, PurchaseReturn), CashFlow liên quan bị soft-delete nhưng **không set `status='cancelled'`**. Status giữ nguyên giá trị `'active'`.
- **Root cause:**
  - `PurchaseController@destroy` dòng 710-712: `CashFlow::where(...)->delete()` — soft-delete, KHÔNG set status
  - `OrderReturnController@cancel` dòng 389-391: `CashFlow::where(...)->delete()` — soft-delete, KHÔNG set status
  - `PurchaseReturnController@cancel` dòng 474-476: `CashFlow::where(...)->delete()` — soft-delete, KHÔNG set status
  - `CashFlowController@destroy` dòng 189-190 ĐÃ đúng: `update(['status'=>'cancelled'])` + `delete()`
  - `InvoiceController@cancel` ĐÃ sửa trong RR-01
- **Ảnh hưởng:**
  - CashFlow bị trashed nhưng status vẫn `'active'`
  - `withTrashed()->active()` tính nhầm CashFlow đã hủy vào báo cáo
  - Nếu ai `restore()` nhầm → CashFlow xuất hiện lại với số tiền đầy đủ
  - Audit trail không phân biệt được CashFlow active thật và CashFlow bị hủy nhưng status giữ active

---

## 2. Các thay đổi đã thực hiện

| Bước | Nội dung | File liên quan | Kết quả test |
|---|---|---|---|
| **Step 9.1A** | Viết test chứng minh lỗi (5 test cases) | `tests/Feature/CashFlow/RR10CashFlowDeletionTest.php` | 4 FAIL, 1 PASS |
| **Step 9.1B** | Sửa 3 controllers + CashFlow model safety net | `PurchaseController.php`, `OrderReturnController.php`, `PurchaseReturnController.php`, `CashFlow.php` | 5/5 PASS |

### Tổng file đã sửa

| File | Nội dung sửa |
|---|---|
| `app/Models/CashFlow.php` | Override `runSoftDelete()` (single instance) + `newEloquentBuilder()` (mass delete) auto-set `status='cancelled'`; `scopeActive()` thêm `whereNull('deleted_at')` |
| `app/Http/Controllers/PurchaseController.php` | Thêm `update(['status'=>'cancelled'])` trước `->delete()` |
| `app/Http/Controllers/OrderReturnController.php` | Thêm `update(['status'=>'cancelled'])` trước `->delete()` |
| `app/Http/Controllers/PurchaseReturnController.php` | Thêm `update(['status'=>'cancelled'])` trước `->delete()` |

### Kiến trúc giải pháp (2 lớp)

```
Lớp 1 — Controller fix (belt):
  Explicit update(['status'=>'cancelled']) trước delete()
  ├── PurchaseController@destroy
  ├── OrderReturnController@cancel
  └── PurchaseReturnController@cancel

Lớp 2 — Model safety net (suspenders):
  Auto-set status='cancelled' khi soft-delete
  ├── runSoftDelete() → single $model->delete()
  └── newEloquentBuilder() → mass CashFlow::where(...)->delete()
```

---

## 3. Test verification

### Kết quả final (02/05/2026)

| Nhóm test | File | Tests | Kết quả |
|---|---|---:|---|
| RR-10 CashFlow deletion | `RR10CashFlowDeletionTest.php` | 5 | ✅ **5 PASS** |
| RR-07 repair parts | `RR07RepairPartsTest.php` | 4 | ✅ **4 PASS** |
| RR-04 stock take | `RR04StockTakeTest.php` | 5 | ✅ **5 PASS** |
| RR-03 core | `RR03StockTransferTest.php` | 5 | ✅ **5 PASS** |
| RR-03 route | `RR03StockTransferRouteTest.php` | 3 | ✅ **3 PASS** |
| RR-01 cancel invoice | `CancelInvoiceTest.php` | 10 | ✅ **10 PASS** |
| RR-01 report P0 | `RR01ReportControllerRegressionTest.php` | 8 | ✅ **8 PASS** |
| RR-01 supplier P1 | `RR01SupplierDualRoleRegressionTest.php` | 2 | ✅ **2 PASS** |
| RR-01 cashflow P1 | `RR01CashFlowCancelledRegressionTest.php` | 4 | ✅ **4 PASS** |
| **Tổng** | | **46** | ✅ **46 PASS, 0 FAIL** |

```
Tests:    46 passed (92 assertions)
Duration: 3.55s
```

---

## 4. Quy ước mới sau RR-10

### CashFlow lifecycle

| Trạng thái | Ý nghĩa | Tính vào báo cáo |
|---|---|---|
| `active` | CashFlow đang hoạt động | ✅ Có |
| `cancelled` | CashFlow đã hủy | ❌ Không |

### Quy tắc bắt buộc

1. **CashFlow đã phát sinh không được hard delete** (`forceDelete()` cấm).
2. **Khi hủy/xóa CashFlow nghiệp vụ phải set `status='cancelled'`** trước khi soft-delete.
3. **Mọi soft-delete CashFlow hiện được model tự set `status='cancelled'`** — safety net qua `runSoftDelete()` + `newEloquentBuilder()`.
4. **`CashFlow::active()` chỉ trả dòng `status != 'cancelled'` VÀ `deleted_at IS NULL`** — an toàn với `withTrashed()`.
5. **Báo cáo tiền/quỹ phải dùng `CashFlow::active()`** — không dùng query thường.
6. **Nếu cần xem audit trail thì dùng `withTrashed()`** — thấy cả CashFlow bị hủy.
7. **Không được dùng `forceDelete()` cho CashFlow nghiệp vụ** — chỉ chấp nhận soft-delete.

### So sánh pattern với RR-01

| Khía cạnh | RR-01 (Invoice) | RR-10 (CashFlow) |
|---|---|---|
| Vấn đề | Hard delete record | Soft-delete thiếu status |
| Giải pháp | Set `status='Đã hủy'` + `save()` | Set `status='cancelled'` + `delete()` |
| Scope | `Invoice::scopeActive()` | `CashFlow::scopeActive()` |
| Safety net | Không (chỉ controller fix) | Model override (auto-set status) |

---

## 5. P3 còn lại đưa vào backlog

| # | Khu vực | Mô tả | Mức độ |
|---|---|---|---|
| 1 | InvoiceController@update | Dòng 524-526 xóa CashFlow cũ khi sửa hóa đơn — giờ tự set cancelled nhờ model override, nhưng cần audit riêng | P3 |
| 2 | Helper | Cân nhắc tạo `CashFlow::cancelByReference($type, $code)` để tránh lặp code | P3 |
| 3 | Test | Chưa có route-level test cho Purchase/OrderReturn/PurchaseReturn cancel flow | P3 |
| 4 | Test | Chưa test CashFlow khi sửa hóa đơn (update flow) | P3 |
| 5 | Audit | RR-11 (OrderReturn qty validation) chưa xử lý | 🔴 P0 |

---

## 6. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `docs/audit/RISK_REGISTER.md` | Bảng rủi ro tổng — RR-10 = Fixed/Verified |
| `docs/test-cases/RR-10-cashflow-deletion.md` | Test case document (5 TCs) |
| `docs/audit/STEP-9.1A-RR10-CASHFLOW-DELETION-TEST-RESULTS.md` | Test chứng minh lỗi |
| `docs/audit/STEP-9.1B-RR10-CASHFLOW-DELETION-FIX-RESULTS.md` | Sửa controllers + model |
| `docs/audit/RR-10-CLOSURE-REPORT.md` | File này — closure report |

---

## 7. Kết luận

✅ **RR-10 đã Fixed/Verified.**

- Lỗi gốc (soft-delete CashFlow mà thiếu `status='cancelled'`) đã sửa triệt để.
- Giải pháp 2 lớp: controller explicit + model safety net.
- 5 regression tests bao phủ 3 controller cancel patterns + scope validation + CashFlowController regression.
- Tổng 46/46 test PASS bao gồm RR-01 + RR-03 + RR-04 + RR-07 regression.
- P3 items đã ghi nhận vào backlog.
- **Sẵn sàng chuyển sang RR-11 (OrderReturn qty validation).**

### Tổng kết tiến độ audit

| Mã | Module | Trạng thái |
|---|---|---|
| RR-01 | Invoice cancel | ✅ Fixed/Verified |
| RR-03 | Stock transfer | ✅ Fixed/Verified |
| RR-04 | Stock take | ✅ Fixed/Verified |
| RR-07 | Repair parts | ✅ Fixed/Verified |
| RR-10 | CashFlow deletion | ✅ Fixed/Verified |
| RR-11 | OrderReturn qty | 🔴 Chưa xử lý |
