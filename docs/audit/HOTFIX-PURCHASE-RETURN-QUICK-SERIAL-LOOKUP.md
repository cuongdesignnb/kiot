# HOTFIX — Serial/IMEI Lookup trên màn Trả hàng nhập nhanh

**Ngày:** 2026-06-01  
**Tác giả:** AI Agent  
**Status:** ✅ COMPLETE  

## 1. Vấn đề

Người dùng nhập Serial/IMEI vào ô tìm kiếm ở màn `Trả hàng nhập nhanh` (`CreateQuick.vue`) nhưng không ra kết quả.  
Đây là đúng thiết kế cũ — màn trả nhanh chỉ search sản phẩm theo tên/SKU, không hỗ trợ serial.

Mong muốn: người dùng có thể tra cứu Serial/IMEI ngay từ màn trả nhanh, thấy kết quả nào còn trong kho,
và được điều hướng sang phiếu nhập gốc (`Create.vue`) để trả serial đúng quy trình.

## 2. Giải pháp

### 2.1 API: `GET /purchase-returns/serial-lookup`
- **Read-only**, không thay đổi DB.
- Input: `serial` (bắt buộc, min 2), `supplier_id` (tùy chọn).
- Query: `SerialImei` with `product`, `purchase.supplier`.
- Output:
  - `matches[]` — serial in_stock, có purchase, có `return_url`.
  - `blocked_matches[]` — serial tìm thấy nhưng không thể trả (sold, returned, no purchase, cancelled purchase).
  - `message` — thông báo nếu không tìm thấy match.

### 2.2 Preselect tại `Create.vue`
- `create()` method nhận thêm optional `serial_id`.
- Validate: serial phải thuộc đúng `purchase_id`, status `in_stock`.
- Pass `preselectSerialId`, `preselectProductId`, `preselectWarning` vào Inertia props.
- `onMounted()` trên frontend auto-tick serial tương ứng.

### 2.3 UI tại `CreateQuick.vue`
- Ô search có thêm nút "🔍 Tìm serial" bên phải.
- Enter hoặc click nút → gọi API serial-lookup.
- Kết quả hợp lệ: khung xanh dương, hiện serial + sản phẩm + phiếu nhập + nút "Mở phiếu nhập để trả serial này".
- Kết quả bị chặn: khung vàng, hiện lý do.
- Không ảnh hưởng flow thêm sản phẩm thường (tên/SKU).

## 3. Files changed

| File | Action | Nội dung |
|------|--------|----------|
| `app/Models/SerialImei.php` | MODIFY | Thêm relation `purchase()` |
| `routes/web.php` | MODIFY | Thêm route `GET /purchase-returns/serial-lookup` |
| `app/Http/Controllers/PurchaseReturnController.php` | MODIFY | Thêm `serialLookup()` + sửa `create()` preselect |
| `resources/js/Pages/PurchaseReturns/CreateQuick.vue` | MODIFY | Thêm serial lookup UI (nút, kết quả, điều hướng) |
| `resources/js/Pages/PurchaseReturns/Create.vue` | MODIFY | Thêm props preselect + onMounted auto-tick |
| `tests/Feature/Purchase/HOTFIXPurchaseReturnSerialLookupTest.php` | NEW | 7 test cases |

## 4. Data safety

- ❌ Không migration
- ❌ Không backfill / update dữ liệu cũ
- ❌ Không thay đổi stock, cost, debt
- ✅ Chỉ API read-only + UI tra cứu + điều hướng
- ✅ Giữ nguyên tất cả guard `has_serial` ở `addProduct()`, `quickStore()`, `save()`

## 5. Test results

```
Tests:    31 passed (176 assertions)
Duration: 3.71s

PASS  HOTFIX246KPurchaseReturnQuickAndReturnerTest (4 tests)
PASS  HOTFIXPurchaseReturnSerialLookupTest (7 tests)
PASS  PurchaseOtherCostsTest (6 tests)
PASS  Step233PurchaseReturnFlowTest (14 tests)
```

## 6. Build

```
npm run build → ✓ built in 9.63s (no errors)
```

## 7. Follow-up permission fix (2026-06-01)

### Vấn đề sau audit
- Route `GET /purchase-returns/create` đang dùng middleware `permission:purchases.create`.
- User chỉ có `purchases.return.create` lookup serial được, nhưng bấm "Mở phiếu nhập để trả serial này" bị 403.

### Thay đổi
- `routes/web.php`: đổi middleware route `/purchase-returns/create` từ `permission:purchases.create` → `permission:purchases.return.create`.
- `tests/Feature/Purchase/HOTFIXPurchaseReturnPermissionTest.php` (NEW): 4 test cases role-based permission.

### Data safety
- Không migration, không backfill, không update dữ liệu cũ.
- Không ảnh hưởng tồn kho / serial / công nợ / cashflow.

### Tests

```
Tests:    35 passed (199 assertions)
Duration: 4.15s

PASS  HOTFIX246KPurchaseReturnQuickAndReturnerTest (4 tests)
PASS  HOTFIXPurchaseReturnPermissionTest (4 tests)  ← NEW
PASS  HOTFIXPurchaseReturnSerialLookupTest (7 tests)
PASS  PurchaseOtherCostsTest (6 tests)
PASS  Step233PurchaseReturnFlowTest (14 tests)
```

### Build
```
npm run build → pass
```
