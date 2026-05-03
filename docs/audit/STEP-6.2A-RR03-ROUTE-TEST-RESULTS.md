# STEP-6.2A — RR-03 Stock Transfer Route Test Results

> **Ngày test:** 02/05/2026  
> **Trạng thái:** 🔴 **2/3 FAIL — Route receive/cancel chưa đăng ký**

---

## 1. Mục tiêu

Kiểm tra route `receive` và `cancel` của StockTransfer có tồn tại và gọi đúng nghiệp vụ khi gọi qua HTTP.

---

## 2. Route hiện tại

```
php artisan route:list | Select-String "stock-transfer"
```

| Method | URI | Name | Action |
|---|---|---|---|
| GET\|HEAD | `/stock-transfers` | `stock-transfers.index` | `StockTransferController@index` |
| POST | `/stock-transfers` | `stock-transfers.store` | `StockTransferController@store` |
| GET\|HEAD | `/stock-transfers/create` | `stock-transfers.create` | `StockTransferController@create` |
| GET\|HEAD | `/stock-transfers/export` | `stock-transfers.export` | `StockTransferController@export` |
| GET\|HEAD | `/stock-transfers/{stock_transfer}/print` | `stock_transfers.print` | `StockTransferController@print` |

### Route THIẾU

| Method | URI | Name (đề xuất) | Action | Trạng thái |
|---|---|---|---|---|
| POST | `/stock-transfers/{id}/receive` | `stock-transfers.receive` | `StockTransferController@receive` | ❌ KHÔNG tồn tại |
| POST | `/stock-transfers/{id}/cancel` | `stock-transfers.cancel` | `StockTransferController@cancel` | ❌ KHÔNG tồn tại |

### Frontend

- **Index.vue**: Không có code gọi receive/cancel
- **Show.vue**: File KHÔNG tồn tại
- **Kết luận**: UI hiện tại **không có cách** nhận hàng hoặc hủy phiếu chuyển kho

---

## 3. Test đã tạo

| # | Test | Mục tiêu | Kết quả |
|---|---|---|---|
| 1 | `test_stock_transfer_receive_route_should_exist` | POST `/stock-transfers/{id}/receive` → 404? | ❌ **FAIL** — 404 |
| 2 | `test_stock_transfer_cancel_route_should_exist` | POST `/stock-transfers/{id}/cancel` → 404? | ❌ **FAIL** — 404 |
| 3 | `test_stock_transfer_cancel_route_should_be_idempotent` | Hủy 2 lần qua route, tồn không đổi thêm | ✅ **PASS** (route cancel trả 404 cả 2 lần → tồn không đổi → pass nhưng vacuously) |

**Lưu ý:** Test 3 PASS nhưng **vacuously** — cả 2 lần gọi đều 404 nên tồn không thay đổi. Test pass nhưng vì lý do sai.

---

## 4. Kết quả chạy test

```
RR03StockTransferRouteTest: 2 failed, 1 passed (3 assertions)
RR03StockTransferTest (core): 5 passed (12 assertions)
```

---

## 5. Nguyên nhân fail

### ❌ TEST 1: receive route — 404

- `StockTransferController` có method `receive(Request $request, $id)` (dòng 188-257)
- Nhưng **không có route đăng ký** trong `routes/web.php`
- Frontend (Index.vue) **không có UI** gọi receive
- → Phiếu chuyển kho status=transferring **không thể nhận hàng** từ giao diện hoặc API

### ❌ TEST 2: cancel route — 404

- `StockTransferController` có method `cancel($id)` (dòng 262-312)
- Nhưng **không có route đăng ký** trong `routes/web.php`
- Frontend (Index.vue) **không có UI** gọi cancel
- → Phiếu chuyển kho **không thể hủy** từ giao diện hoặc API

### ✅ TEST 3: idempotent — PASS (vacuous)

- Route 404 cả 2 lần → tồn không thay đổi → assertion pass
- Sẽ cần verify lại sau khi route được đăng ký

---

## 6. Kết luận

- ✅ **Core RR-03 logic đúng** — 5/5 test PASS (gọi trực tiếp method)
- ❌ **Route chưa đăng ký** — receive/cancel trả 404
- ❌ **UI chưa có** — không có nút nhận hàng/hủy phiếu
- ✅ **Cần chuyển sang Bước 6.2B** để:
  1. Đăng ký route receive + cancel trong `routes/web.php`
  2. Chạy lại route test để verify
  3. (Tùy chọn) Thêm Show.vue hoặc nút action trong Index.vue

### Đề xuất route cần thêm (Bước 6.2B)

```php
// routes/web.php — trong nhóm stock-transfers
Route::post('/stock-transfers/{stockTransfer}/receive', [StockTransferController::class, 'receive'])
    ->name('stock-transfers.receive')
    ->middleware('permission:stock_transfers.create');

Route::post('/stock-transfers/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])
    ->name('stock-transfers.cancel')
    ->middleware('permission:stock_transfers.create');
```
