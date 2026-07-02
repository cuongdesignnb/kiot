# HOTFIX 24.21 — Purchase Supplier Balance & Overpayment Display

> **Note on label:** brief gọi là "HOTFIX 24.19" nhưng slot đó đã thuộc về "hide deactivated suppliers from Nhập hàng selectors". Renamed sang **24.21** để tránh trùng test class + audit doc.

## 1. Vấn đề

- Sau khi chọn NCC trong màn tạo/sửa phiếu nhập, **không** thấy nợ cũ hoặc số dư cũ của NCC. Operator phải mở `/suppliers` để check thủ công.
- Khi gõ `paid_amount` lớn hơn tổng cần trả (trả dư), `debtAmount` cũ dùng `Math.max(0, total − paid)` → số tiền thừa bị **clamp về 0** ở UI, không hiển thị. Backend vẫn lưu đúng (debt_amount < 0 → supplier_debt_amount giảm), nhưng operator không biết.
- Trong modal sửa phiếu, không có dòng nào cho biết: NCC đang nợ bao nhiêu, phiếu này trước khi sửa đóng góp nợ bao nhiêu, sau khi save sẽ ra số nào.

## 2. Source đã kiểm tra

| File | Phát hiện |
|---|---|
| [`resources/js/Pages/Purchases/Create.vue`](resources/js/Pages/Purchases/Create.vue) | Có `selectedSupplierObj`, `totalPayment`, `paidAmount`. `debtAmount` dùng `Math.max(0, total − paid)` → bỏ qua phần dư. KHÔNG hiển thị nợ cũ NCC. |
| [`resources/js/Pages/Purchases/Edit.vue`](resources/js/Pages/Purchases/Edit.vue) | Pattern y hệt Create — cũng clamp về 0, không có balance. Orphan (không route nào render), nhưng vẫn patch để tránh drift. |
| [`resources/js/Pages/Purchases/Show.vue`](resources/js/Pages/Purchases/Show.vue) | Modal inline edit (đang dùng thực tế). `editDebt` cũng clamp về 0. KHÔNG có balance card. |
| [`app/Http/Controllers/PurchaseController.php`](app/Http/Controllers/PurchaseController.php) | `create()` load `Customer::where('is_supplier', true)->where(active)->get()` (full row) → `supplier_debt_amount` đã có sẵn trong props. Công thức `debt_amount = pay − paid` cho phép âm khi trả dư; `supplier_debt_amount += debt_amount` (negative subtract khi overpaid). Không cần sửa backend. |
| [`/api/suppliers/search`](app/Http/Controllers/SupplierController.php) | Từ HOTFIX 24.19 đã trả `id, code, name, phone, supplier_debt_amount`. Đã sẵn sàng. |

## 3. Root cause

- **Tiền thừa không hiển thị:** `Math.max(0, total − paid)` clamp về 0 → UI mất tín hiệu để render "Tiền thừa".
- **Nợ/số dư cũ NCC không hiển thị:** dữ liệu đã đến FE (`selectedSupplierObj.supplier_debt_amount`) nhưng template không bind. Thiếu computed + template block.

Backend hoàn toàn đúng — không cần đổi công thức công nợ. 24.21 chỉ thêm computed FE + UI markup.

## 4. File đã sửa

| File | Loại | Nội dung |
|---|---|---|
| [`resources/js/Pages/Purchases/Create.vue`](resources/js/Pages/Purchases/Create.vue) | edit | Thêm 9 computed (`currentPurchaseBalance` / `Debt` / `purchaseOverpaidAmount` / `oldSupplierBalance` / `Debt` / `Credit` / `projectedSupplierBalance` / `Debt` / `Credit`). `debtAmount` alias `currentPurchaseDebt`. UI: dòng "Còn nợ phiếu này" / "Tiền thừa" thay cho "Tính vào công nợ" cũ; card balance hiện sau supplier select. |
| [`resources/js/Pages/Purchases/Show.vue`](resources/js/Pages/Purchases/Show.vue) | edit | Modal sửa phiếu thêm `editPurchaseBalance`, `editOverpaid`, `originalPurchaseDebt`, `supplierCurrentBalance`, `supplierBalanceBeforeThisPurchase`, `projectedSupplier*`. Dòng "Tiền thừa" + card NCC balance (current / before / projected). |
| [`resources/js/Pages/Purchases/Edit.vue`](resources/js/Pages/Purchases/Edit.vue) | edit | Cùng pattern Create + xử lý `originalPurchaseDebt` để không double-count khi NCC vẫn còn giữ debt của phiếu cũ. Orphan page (không route nào render), patch để tránh drift sau này. |
| [`tests/Feature/Purchases/HOTFIX2421PurchaseSupplierBalanceDisplayTest.php`](tests/Feature/Purchases/HOTFIX2421PurchaseSupplierBalanceDisplayTest.php) | NEW | 4 TC pin: prop có supplier_debt_amount, /api/suppliers/search có field, store cho overpay → debt_amount âm, update walks linearly. |
| [`docs/audit/HOTFIX-24.21-PURCHASE-SUPPLIER-BALANCE-OVERPAYMENT.md`](docs/audit/HOTFIX-24.21-PURCHASE-SUPPLIER-BALANCE-OVERPAYMENT.md) | NEW | Báo cáo này. |

**Không sửa:**
- `PurchaseController::create/store/update/destroy` — công thức `debt_amount`, `supplier_debt_amount`, cashflow, stock, moving-avg, serial intact.
- `SupplierController::search` — đã có sẵn `supplier_debt_amount` từ HOTFIX 24.19.
- Migration / DB / Customer / Purchase models.
- POS / Invoice / PurchaseReturn / Repairs / Tasks / Payroll.

## 5. Công thức UI

### 5.1. Create.vue

```js
const currentPurchaseBalance = computed(
    () => Number(totalPayment.value || 0) - Number(paidAmount.value || 0)
);
const currentPurchaseDebt        = computed(() => Math.max(0,  currentPurchaseBalance.value));
const purchaseOverpaidAmount     = computed(() => Math.max(0, -currentPurchaseBalance.value));

const oldSupplierBalance = computed(
    () => Number(selectedSupplierObj.value?.supplier_debt_amount || 0)
);
const oldSupplierDebt    = computed(() => Math.max(0,  oldSupplierBalance.value));
const oldSupplierCredit  = computed(() => Math.max(0, -oldSupplierBalance.value));

const projectedSupplierBalance = computed(
    () => oldSupplierBalance.value + currentPurchaseBalance.value
);
const projectedSupplierDebt    = computed(() => Math.max(0,  projectedSupplierBalance.value));
const projectedSupplierCredit  = computed(() => Math.max(0, -projectedSupplierBalance.value));

const debtAmount = currentPurchaseDebt; // BC alias
```

### 5.2. Show.vue + Edit.vue

Khác Create ở chỗ phải back out `originalPurchaseDebt` để projection không double-count:

```js
const originalPurchaseDebt = computed(() => Number(props.purchase?.debt_amount || 0));

const supplierBalanceBeforeThisPurchase = computed(
    () => oldSupplierBalance.value - originalPurchaseDebt.value
);
const projectedSupplierBalance = computed(
    () => supplierBalanceBeforeThisPurchase.value + currentPurchaseBalance.value
);
```

Vì `supplier_debt_amount` đang lưu đã bao gồm debt của phiếu này (PurchaseController cộng khi store/update). Nếu không trừ ra, projection sẽ tính 2 lần.

### 5.3. Hiển thị

| Trường hợp | Dòng nào hiện |
|---|---|
| `currentPurchaseDebt > 0` | "Còn nợ phiếu này" — đỏ |
| `purchaseOverpaidAmount > 0` | "Tiền thừa" — xanh |
| Cả hai = 0 | "Tính vào công nợ" — xám (legacy) |
| `oldSupplierCredit > 0` | "Số dư cũ NCC" / "Số dư hiện tại NCC đang dư" — xanh |
| `oldSupplierDebt > 0` | "Nợ cũ NCC" / "Nợ hiện tại NCC" — đỏ |
| `projectedSupplierCredit > 0` | "Dự kiến NCC còn dư sau phiếu này/cập nhật" — xanh |
| `projectedSupplierDebt > 0` | "Dự kiến còn nợ NCC sau phiếu này/cập nhật" — đỏ |

## 6. Backend

**Không sửa backend.** Lý do:

1. `PurchaseController::create()` đã `Customer::where(...)->get()` (full row) → `supplier_debt_amount` đã trong props.
2. `/api/suppliers/search` đã trả `supplier_debt_amount` từ HOTFIX 24.19.
3. Công thức `debt_amount = pay − paid` (có thể âm) ĐÚNG với yêu cầu "không block overpay".
4. `supplier_debt_amount += debt_amount` walks linearly (overpay → balance giảm, có thể âm = NCC dư).

TC-03 + TC-04 pin contract này. Test PASS = backend không đụng được nữa.

## 7. Data safety

- Migration: **Không.**
- Backfill: **Không.**
- Update dữ liệu cũ: **Không.**
- Recalculate công nợ: **Không.**
- Sửa công thức backend: **Không.**

Chỉ thêm computed FE + UI markup. Zero side-effect lên data hiện có.

## 8. Test đã chạy

| Lệnh | Kết quả |
|---|---|
| `HOTFIX2421PurchaseSupplierBalanceDisplayTest` | ✅ **4 passed / 18 assertions**, 0.66s |
| `php artisan test --filter="Purchase\|Supplier\|CashFlow"` | ✅ **106 passed / 411 assertions**, 33.36s |
| `npm run build` | ✅ **built in 8.09s** |

**4 TC trong HOTFIX2421PurchaseSupplierBalanceDisplayTest:**

1. `test_purchase_create_suppliers_include_supplier_debt_amount` — `/purchases/create` body có code NCC + `supplier_debt_amount` key + giá trị `1500000`.
2. `test_supplier_search_response_includes_supplier_debt_amount` — `/api/suppliers/search` trả row có `supplier_debt_amount = 2000000`.
3. `test_purchase_store_keeps_negative_debt_amount_when_overpaid` — total 1M, paid 1.2M → row.debt_amount = `-200000`, supplier.supplier_debt_amount = `-200000`. Formula chưa clamp.
4. `test_purchase_update_supplier_debt_walks_linearly_when_paid_changes` — create (paid=0 → NCC nợ 1M), update paid_amount=1.5M → NCC dư 500k. Linear, no clamp.

**Test FE computed:** không tạo Vitest test (project không có setup); 4 PHP TC trên đã pin contract end-to-end (prop có field + backend formula không clamp = FE computed đúng dữ liệu).

## 9. Manual QA — pending tester

### 9.1. Tạo phiếu nhập mới
- [ ] Chọn NCC có `supplier_debt_amount = 1.000.000` → ngay sau khi chọn, dòng "Nợ cũ NCC: 1.000.000" hiện đỏ.
- [ ] Thêm items tổng `2.000.000`, paid `500.000` → dòng "Còn nợ phiếu này: 1.500.000" + "Dự kiến còn nợ NCC sau phiếu này: 2.500.000".
- [ ] Đổi paid lên `2.500.000` → dòng "Tiền thừa: 500.000" (xanh) + "Dự kiến còn nợ NCC sau phiếu này: 500.000" (vì 1M cũ + (2M − 2.5M) = 0.5M).
- [ ] Đổi paid lên `3.500.000` → "Tiền thừa: 1.500.000" + "Dự kiến NCC còn dư sau phiếu này: 500.000" (xanh).

### 9.2. NCC đang có số dư cũ
- [ ] Chọn NCC `supplier_debt_amount = -1.000.000` → "Số dư cũ NCC: 1.000.000" (xanh).
- [ ] Thêm items 500k, paid 0 → "Còn nợ phiếu này: 500.000" + "Dự kiến NCC còn dư sau phiếu này: 500.000".

### 9.3. Sửa phiếu nhập (modal trong Show.vue)
- [ ] Mở phiếu nợ 1M → nhấn Sửa.
- [ ] Modal hiện:
  - "Nợ hiện tại NCC: X" (theo supplier_debt_amount thật)
  - "Nợ phiếu này trước khi sửa: 1.000.000"
  - "Dự kiến công nợ NCC sau cập nhật: …"
- [ ] Đổi paid_amount = 1.500.000 → "Tiền thừa: 500.000" hiện.
- [ ] Lưu → trang refresh → `/suppliers` xem `supplier_debt_amount` đã trừ đúng.

### 9.4. Regression
- [ ] Lưu phiếu mới với tiền thừa → DB.row.debt_amount = `-500000`, supplier_debt_amount đúng.
- [ ] Cashflow tạo theo logic cũ.
- [ ] Tồn kho / giá vốn / serial không đổi behavior.

## 10. Ảnh hưởng nghiệp vụ

- **Công nợ NCC:** ✅ KHÔNG đổi công thức. Số liệu hiển thị thêm là **suy diễn** từ `supplier_debt_amount + currentPurchaseBalance`. Khi save, backend tự update đúng như trước. TC-03/-04 pin linearity.
- **CashFlow:** ✅ KHÔNG động — 12 TC PASS.
- **Tồn kho:** ✅ KHÔNG động — moving-avg + stock movement intact.
- **Giá vốn:** ✅ KHÔNG động.
- **Serial/IMEI:** ✅ KHÔNG động.
- **POS / Invoice / PurchaseReturn / Repairs / Tasks / Payroll:** không đụng.

## 11. Commit & deployment

- **Commit SHA:** `8967905` — `fix(purchases): show supplier balance and overpayment in purchase form`.
- **Push status:** ✅ đã push, `origin/main` = `896790546297ce773d1b7711f2b45655561dbfeb`.

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git pull origin main
php artisan optimize:clear
rm -rf public/build
npm run build
# Hard reload trình duyệt (Ctrl+Shift+R).
```

## 12. Kết luận

- **Tiền thừa đã hiển thị chưa?** ✅ Có — `currentPurchaseBalance < 0` → "Tiền thừa: X" (xanh) trên cả 3 form (Create / Show inline / Edit).
- **Nợ/số dư cũ NCC đã hiển thị sau khi chọn NCC chưa?** ✅ Có — card balance hiện ngay sau supplier select, lấy từ `supplier_debt_amount` đã có sẵn trong props/API.
- **Có đổi công nợ thật không?** ✅ KHÔNG — chỉ thêm computed FE + UI markup. Backend formula intact (TC-03/-04 pin).
- **Có thể deploy chưa?** **Code đã sẵn sàng** — 4 TC mới + 106 TC Purchase+Supplier+CashFlow regression PASS, build PASS. Browser QA §9 cần tester confirm.
