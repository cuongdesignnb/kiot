# STEP-9.1B — Fix RR-10 CashFlow Deletion

> **Mã rủi ro:** RR-10  
> **Ngày sửa:** 02/05/2026  
> **Trạng thái:** ✅ **FIXED — 5/5 test PASS**

---

## 1. Vấn đề đã sửa

- ❌→✅ PurchaseController soft-delete CashFlow nhưng thiếu `status='cancelled'`
- ❌→✅ OrderReturnController soft-delete CashFlow nhưng thiếu `status='cancelled'`
- ❌→✅ PurchaseReturnController soft-delete CashFlow nhưng thiếu `status='cancelled'`
- ❌→✅ `withTrashed()->active()` tính nhầm CashFlow bị trashed nhưng status='active'
- ❌→✅ Mọi `->delete()` trên CashFlow giờ tự động set `status='cancelled'` qua model override

---

## 2. File đã sửa

| File | Nội dung sửa |
|---|---|
| `app/Models/CashFlow.php` | Override `runSoftDelete()` + `newEloquentBuilder()` auto-set `status='cancelled'`; `scopeActive()` thêm `whereNull('deleted_at')` |
| `app/Http/Controllers/PurchaseController.php` | Thêm `update(['status'=>'cancelled'])` trước `->delete()` (belt-and-suspenders) |
| `app/Http/Controllers/OrderReturnController.php` | Thêm `update(['status'=>'cancelled'])` trước `->delete()` (belt-and-suspenders) |
| `app/Http/Controllers/PurchaseReturnController.php` | Thêm `update(['status'=>'cancelled'])` trước `->delete()` (belt-and-suspenders) |

---

## 3. Cách sửa

### CashFlow Model — Safety net (lớp phòng vệ sâu)

**`runSoftDelete()` override:** Khi gọi `$cashFlow->delete()` (single instance), tự động set `status='cancelled'` cùng lúc với `deleted_at`.

**`newEloquentBuilder()` override:** Khi gọi `CashFlow::where(...)->delete()` (mass operation), custom builder override `delete()` method để set cả `status='cancelled'` + `deleted_at` trong cùng 1 UPDATE query.

**`scopeActive()` cập nhật:** Thêm `whereNull('deleted_at')` để an toàn khi dùng với `withTrashed()`.

### PurchaseController@destroy

**Trước:** Chỉ `CashFlow::where(...)->delete()` (soft-delete, status giữ 'active')

**Sau:** `CashFlow::where(...)->update(['status'=>'cancelled'])` + `CashFlow::where(...)->delete()`

### OrderReturnController@cancel

**Trước:** Chỉ `CashFlow::where(...)->delete()`

**Sau:** `update(['status'=>'cancelled'])` + `->delete()`

### PurchaseReturnController@cancel

**Trước:** Chỉ `CashFlow::where(...)->delete()`

**Sau:** `update(['status'=>'cancelled'])` + `->delete()`

### CashFlow status convention

| Status | Ý nghĩa |
|---|---|
| `active` | CashFlow đang hoạt động, tính vào báo cáo |
| `cancelled` | CashFlow đã hủy, KHÔNG tính vào báo cáo |

---

## 4. Kết quả test

### RR10CashFlowDeletionTest — 5/5 PASS

| # | Test | Trước sửa | Sau sửa |
|---|---|---|---|
| 1 | `cancel_purchase_cashflow_must_set_status_cancelled` | ❌ FAIL (status='active') | ✅ PASS |
| 2 | `cancel_order_return_cashflow_must_set_status_cancelled` | ❌ FAIL (status='active') | ✅ PASS |
| 3 | `cancel_purchase_return_cashflow_must_set_status_cancelled` | ❌ FAIL (status='active') | ✅ PASS |
| 4 | `scope_active_should_not_include_soft_deleted_without_status` | ❌ FAIL (10M vs 1M) | ✅ PASS |
| 5 | `cashflow_controller_destroy_sets_cancelled_status` | ✅ PASS | ✅ PASS |

### Existing regression — 41/41 PASS

| Test Suite | Kết quả |
|---|---|
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
Tests:    46 passed (92 assertions)
Duration: 3.41s
```

---

## 5. Rủi ro còn lại

| # | Khu vực | Mô tả | Mức độ |
|---|---|---|---|
| 1 | InvoiceController@update | Dòng 524-526 xóa CashFlow cũ khi sửa hóa đơn — cần audit riêng (giờ đã tự set cancelled nhờ model override) | P3 |
| 2 | Helper | Cân nhắc tạo `CashFlow::cancelByReference($type, $code)` helper method để tránh lặp code | P3 |
| 3 | Test | Chưa có route-level test cho Purchase/OrderReturn/PurchaseReturn cancel flow | P3 |
| 4 | Test | Chưa test CashFlow khi sửa hóa đơn (update flow) | P3 |

---

## 6. Kiến trúc giải pháp

```
                   Controller fix (belt)          Model override (suspenders)
                   ┌────────────────────┐         ┌─────────────────────────────┐
                   │ update(['status'   │         │ runSoftDelete() — single    │
                   │  => 'cancelled'])  │         │ newEloquentBuilder() — mass │
                   │ + delete()         │         │ → auto set status=cancelled │
                   └────────┬───────────┘         └────────────┬────────────────┘
                            │                                  │
                            └────────────┬─────────────────────┘
                                         ▼
                              CashFlow status = 'cancelled'
                              + deleted_at = timestamp
                                         │
                            ┌────────────┼─────────────────────┐
                            │            │                     │
                  Normal query    withTrashed()        withTrashed()->active()
                  → ẩn (deleted)  → thấy, status      → ẩn (status=cancelled
                                    =cancelled           + deleted_at lọc)
```

---

## 7. Kết luận

- ✅ **RR-10 đã Fixed** — 5/5 test PASS, 41/41 regression PASS, tổng 46/46.
- ✅ Giải pháp 2 lớp: controller fix + model safety net.
- ✅ Mọi `->delete()` trên CashFlow giờ tự động set `status='cancelled'` — không thể tạo "orphaned" soft-deleted records.
- ✅ Có thể chuyển sang **closure RR-10** hoặc **RR-11 (OrderReturn qty validation)**.
