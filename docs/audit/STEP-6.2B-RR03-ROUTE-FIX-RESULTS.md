# STEP-6.2B — Fix RR-03 Stock Transfer Routes

> **Ngày sửa:** 02/05/2026  
> **Trạng thái:** ✅ **FIXED — 32/32 test PASS**

---

## 1. Vấn đề đã sửa

- ❌→✅ Route `POST /stock-transfers/{id}/receive` chưa đăng ký → đã đăng ký
- ❌→✅ Route `POST /stock-transfers/{id}/cancel` chưa đăng ký → đã đăng ký

---

## 2. File đã sửa

| File | Nội dung sửa |
|---|---|
| `routes/web.php` | Thêm 2 route trong nhóm `permission:stock_transfers.create` |

### Diff

```diff
 Route::middleware('permission:stock_transfers.create')->group(function () {
     Route::get('/stock-transfers/create', ...)->name('stock-transfers.create');
     Route::post('/stock-transfers', ...)->name('stock-transfers.store');
+    Route::post('/stock-transfers/{id}/receive', ...)->name('stock-transfers.receive');
+    Route::post('/stock-transfers/{id}/cancel', ...)->name('stock-transfers.cancel');
 });
```

---

## 3. Route sau sửa

| Method | URI | Name | Action |
|---|---|---|---|
| GET\|HEAD | `/stock-transfers` | `stock-transfers.index` | `@index` |
| POST | `/stock-transfers` | `stock-transfers.store` | `@store` |
| GET\|HEAD | `/stock-transfers/create` | `stock-transfers.create` | `@create` |
| GET\|HEAD | `/stock-transfers/export` | `stock-transfers.export` | `@export` |
| **POST** | **`/stock-transfers/{id}/receive`** | **`stock-transfers.receive`** | **`@receive`** ✅ MỚI |
| **POST** | **`/stock-transfers/{id}/cancel`** | **`stock-transfers.cancel`** | **`@cancel`** ✅ MỚI |
| GET\|HEAD | `/stock-transfers/{stock_transfer}/print` | `stock_transfers.print` | `@print` |

---

## 4. Kết quả test

| Test suite | Trước sửa | Sau sửa |
|---|---|---|
| `RR03StockTransferRouteTest` (3 tests) | 1 PASS, **2 FAIL** | **3 PASS** ✅ |
| `RR03StockTransferTest` (5 tests) | 5 PASS | **5 PASS** ✅ |
| `CancelInvoiceTest` (10 tests) | 10 PASS | **10 PASS** ✅ |
| `RR01ReportControllerRegressionTest` (8 tests) | 8 PASS | **8 PASS** ✅ |
| `RR01SupplierDualRoleRegressionTest` (2 tests) | 2 PASS | **2 PASS** ✅ |
| `RR01CashFlowCancelledRegressionTest` (4 tests) | 4 PASS | **4 PASS** ✅ |
| **Tổng** | 30 PASS, 2 FAIL | **32 PASS, 0 FAIL** ✅ |

```
Tests:    32 passed (59 assertions)
Duration: 2.42s
```

---

## 5. Rủi ro còn lại

| # | Khu vực | Mô tả | Mức độ |
|---|---|---|---|
| 1 | UI | Show.vue chưa tồn tại — không có trang chi tiết phiếu chuyển kho | P2 |
| 2 | UI | Index.vue chưa có nút Nhận hàng / Hủy phiếu | P2 |
| 3 | Architecture | Tồn kho chỉ có `products.stock_quantity` chung, không phân biệt branch | Limitation |
| 4 | Receive partial | Nhận 1 phần chưa có test bổ sung | P3 |

---

## 6. Kết luận

- ✅ **Route receive/cancel đã fixed** — 3/3 route test PASS
- ✅ **Core RR-03 đã fixed** — 5/5 core test PASS
- ✅ **RR-01 regression ổn** — 24/24 PASS
- ✅ **Tổng 32/32 PASS**
- ✅ **Có thể chuyển sang closure RR-03** hoặc tiếp tục RR-04
