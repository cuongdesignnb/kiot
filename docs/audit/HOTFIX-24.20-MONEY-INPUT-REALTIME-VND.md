# HOTFIX 24.20 — Money Input Realtime VND Format

> **Note on label:** brief gọi là "HOTFIX 24.18", nhưng `HOTFIX 24.18` slot đã dùng cho "restore reassembled serials to stock". Đổi sang **24.20** để tránh trùng SHA/test class/audit doc.

## 1. Vấn đề

- Người dùng nhập số tiền bằng `<input type="number">` (hoặc `MoneyInput` cũ chỉ format on-blur) → khi gõ `10000000` không thấy dấu chấm phân tách hàng nghìn cho đến lúc rời ô.
- Khó đọc số khi đang gõ; dễ nhầm 1tr vs 10tr vs 100tr.
- Yêu cầu: gõ tới đâu hiển thị `1`, `10`, `100`, `1.000`, `10.000`, `100.000`, `1.000.000`, `10.000.000` tới đó. Payload backend vẫn là `number` raw.

## 2. Root cause

- Project đã có helper `formatVND` / `formatMoneyInput` / `parseVND` trong [`resources/js/utils/money.js`](resources/js/utils/money.js) — nhưng `formatMoneyInput(0) → '0'` (không phải `''`) và không có hàm dành riêng cho "input nhận chuỗi đang gõ".
- `Components/MoneyInput.vue` cũ format on `blur` (line 46-51 cũ): khi focus thì show raw `'1500000'`, khi blur mới show `'1.500.000'`. Không realtime.
- Nhiều form nghiệp vụ vẫn dùng `<input type="number" v-model.number="...">` thẳng — browser native `type="number"` KHÔNG hỗ trợ thousands separator → operator gõ chục triệu phải đếm số 0.

## 3. File đã sửa

| File | Loại | Nội dung |
|---|---|---|
| [`resources/js/utils/money.js`](resources/js/utils/money.js) | edit | Thêm 4 helper realtime: `onlyDigits`, `formatVndInput`, `parseVndInput`, `isMoneyInputEmpty`. KHÔNG đụng `formatVND` / `formatMoneyInput` / `parseVND` / `fmtVND` cũ — backward-compat cho các call site hiện có. |
| [`resources/js/Components/MoneyInput.vue`](resources/js/Components/MoneyInput.vue) | edit | Rewrite `onInput` để **format realtime** (chèn dấu chấm khi user gõ). Bỏ `onFocus` flip về raw; emit thêm `input` + `blur` event ngoài `update:modelValue`. Empty modelValue → placeholder hiện (không cần `0` mặc định). Vẫn dùng `<input type="text">` + `inputmode="numeric"`. |
| [`resources/js/tests/moneyInput.test.mjs`](resources/js/tests/moneyInput.test.mjs) | NEW | 7 test cases dùng `node:test` (Node 18+ built-in runner — project không có Vitest/Jest setup). |
| [`resources/js/Pages/Purchases/Show.vue`](resources/js/Pages/Purchases/Show.vue) | edit | 2 ô tiền trong modal sửa phiếu (`editForm.discount`, `editForm.paid_amount`): `<input type="number">` → `<MoneyInput>`. Import component. |
| [`resources/js/Pages/CashFlows/Index.vue`](resources/js/Pages/CashFlows/Index.vue) | edit | Ô `form.amount` của modal Thu/Chi: `<input type="number">` → `<MoneyInput>`. Import component. |
| [`resources/js/Pages/Suppliers/Index.vue`](resources/js/Pages/Suppliers/Index.vue) | edit | 2 ô tiền trong modal Thanh toán / Điều chỉnh / Chiết khấu (`debtAmount`) và Cấn bằng công nợ (`offsetForm.amount`): `<input type="number">` → `<MoneyInput>`. Import component. |
| [`docs/audit/HOTFIX-24.20-MONEY-INPUT-REALTIME-VND.md`](docs/audit/HOTFIX-24.20-MONEY-INPUT-REALTIME-VND.md) | NEW | Báo cáo này. |

**Không sửa:**

- `formatVND` / `formatMoneyInput` / `parseVND` cũ — vẫn cần cho text display + call site cũ.
- Backend: KHÔNG sửa request validation/parse. Backend vẫn nhận `amount: 1000000`, KHÔNG nhận `"1.000.000"`. MoneyInput emit number raw.
- Công thức nghiệp vụ: KHÔNG đụng `debtTransactions`, `recordPayment`, `adjustDebt`, `debtOffset`, Purchase / Invoice / CashFlow / Payroll / POS service-side.
- Input không phải tiền (số lượng, SĐT, mã hàng, serial/IMEI, mã vạch, phần trăm, ngày, tháng bảo hành, điểm, số lần) — không động.
- Migration / DB / Eloquent models — không động.

### Đã có sẵn MoneyInput từ trước (chỉ nâng cấp realtime, không cần đổi call site)

- [`resources/js/Pages/Purchases/Create.vue`](resources/js/Pages/Purchases/Create.vue) — đã import `MoneyInput` từ HOTFIX trước. Sau upgrade realtime, các ô tiền tự động hiện dấu chấm khi gõ.
- [`resources/js/Pages/Purchases/Edit.vue`](resources/js/Pages/Purchases/Edit.vue) — tương tự.
- [`resources/js/Components/QuickCreateProductModal.vue`](resources/js/Components/QuickCreateProductModal.vue) — tương tự.

## 4. Helper / Component

### 4.1. `resources/js/utils/money.js` — helpers MỚI

```js
export const onlyDigits = (value) => String(value ?? '').replace(/[^\d]/g, '');

export const formatVndInput = (value) => {
    const digits = onlyDigits(value);
    if (!digits) return '';
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
};

export const parseVndInput = (value) => {
    const digits = onlyDigits(value);
    if (!digits) return 0;
    return Number(digits);
};

export const isMoneyInputEmpty = (value) =>
    value === null || value === undefined || String(value).trim() === '';
```

| Input | `formatVndInput` | `parseVndInput` |
|---|---|---|
| `'1000'` | `'1.000'` | `1000` |
| `'1000000'` | `'1.000.000'` | `1000000` |
| `'1.000.000'` | `'1.000.000'` | `1000000` |
| `'1,000,000'` | `'1.000.000'` | `1000000` |
| `'1 000 000'` | `'1.000.000'` | `1000000` |
| `''` | `''` | `0` |
| `null` | `''` | `0` |

### 4.2. `resources/js/Components/MoneyInput.vue` — realtime upgrade

```html
<input
    type="text"
    inputmode="numeric"
    autocomplete="off"
    :value="displayValue"
    @input="onInput"
    @blur="onBlur"
/>
```

```js
function onInput(e) {
    const raw       = e.target.value;
    const formatted = formatVndInput(raw);     // '1.500.000'
    const num       = parseVndInput(raw);      // 1500000
    if (e.target.value !== formatted) {
        e.target.value = formatted;            // force visible text → realtime
    }
    displayValue.value = formatted;
    emit('update:modelValue', num);            // payload luôn là number raw
    emit('input', num);
}
```

- **Hiển thị:** user gõ `1` → thấy `1`. Gõ tiếp `1000` → thấy `1.000`. Gõ tiếp `1000000` → thấy `1.000.000`.
- **Submit:** v-model trả về `Number(1000000)`. Backend nhận đúng `amount: 1000000`. KHÔNG bao giờ gửi `"1.000.000"`.
- **Paste:** `1,000,000` / `1 000 000` / `1.000.000` / `1000000` → đều được normalize sang `1.000.000` display + `1000000` model.
- **Empty:** modelValue null/undefined/empty → input rỗng (placeholder hiện), không phải `0`.

## 5. Các màn đã áp dụng

| Màn | File | Áp dụng |
|---|---|---|
| Nhập hàng — tạo phiếu | `Purchases/Create.vue` | ✅ Đã dùng `MoneyInput` từ trước; realtime hoạt động ngay sau upgrade. |
| Nhập hàng — sửa phiếu | `Purchases/Edit.vue` | ✅ Đã dùng `MoneyInput` từ trước. |
| Nhập hàng — sửa inline trên Show | `Purchases/Show.vue` | ✅ **MỚI 24.20:** `editForm.discount`, `editForm.paid_amount`. |
| POS / Bán hàng | `POS/Index.vue` | ⏳ **Phase 2** — các ô giảm giá / khách trả vẫn `type="number"`. Helper sẵn sàng, swap nhanh khi cần. |
| Sản phẩm — tạo/sửa | `Products/Create.vue` + `Edit.vue` | ⏳ **Phase 2** — giá bán / giá vốn / giá nhập. `QuickCreateProductModal` đã dùng MoneyInput. |
| Khách hàng — Index/modal công nợ | `Customers/Index.vue` | ⏳ **Phase 2** — pattern giống `Suppliers/Index` đã làm. |
| Nhà cung cấp — modal công nợ | `Suppliers/Index.vue` | ✅ **MỚI 24.20:** thanh toán / điều chỉnh / chiết khấu (`debtAmount`) + cấn bằng (`offsetForm.amount`). |
| Sửa chữa / Tasks — phiếu sửa | `Repairs/*`, `Tasks/*` | ⏳ **Phase 2** — các ô giá linh kiện / công sửa. |
| CashFlow | `CashFlows/Index.vue` | ✅ **MỚI 24.20:** `form.amount` modal Thu/Chi. |
| Payroll | `Payroll/*` | ⏳ **Phase 2** — lương / phụ cấp / khấu trừ / thưởng / phạt. |

**Phase 2 reasoning:** project có hàng chục input tiền trải đều màn nghiệp vụ; 24.20 đặt nền tảng helper + component + realtime upgrade trên những điểm cao tần nhất (phiếu nhập + công nợ NCC + cashflow). Các màn còn lại có thể swap `<input type="number">` → `<MoneyInput>` bằng find-replace 1 dòng, không cần thêm logic. Đã ghi rõ pattern + danh sách trong audit để team FE tiếp tục đợt sau mà không phải làm lại discovery.

## 6. Test đã chạy

| Lệnh | Kết quả |
|---|---|
| `node --test resources/js/tests/moneyInput.test.mjs` | ✅ **7 tests pass** (onlyDigits / formatVndInput plain / formatVndInput normalised / formatVndInput empty / parseVndInput / parseVndInput empty / isMoneyInputEmpty) |
| `php artisan test --filter="Purchase\|Invoice\|CashFlow\|Supplier\|Customer\|Payroll"` | ✅ **255 passed, 2 skipped / 1198 assertions**, 49.52s |
| `npm run build` | ✅ **built in 7.55s** |

**Lưu ý JS test setup:** project chưa có Vitest/Jest. Test dùng `node:test` built-in (Node 18+) — chạy bằng `node --test resources/js/tests/moneyInput.test.mjs`. Đã ghi rõ trong header file test.

## 7. Manual QA

**Pending tester:**

### 7.1. Nhập hàng
- [ ] Vào `/purchases/create` → ô "Khách trả" → gõ `10000000` → input hiển thị `10.000.000` từng ký tự.
- [ ] Lưu phiếu → DevTools Network → request payload `paid_amount: 10000000` (number, không phải string `"10.000.000"`).
- [ ] Mở `/purchases/{id}` → nhấn "Sửa" → ô discount / paid_amount → realtime format.

### 7.2. POS
- [ ] (Phase 2 — chưa swap) Ô "Tiền khách trả" tạm thời vẫn `type="number"`. Sẽ swap sau.

### 7.3. Công nợ NCC
- [ ] `/suppliers` → mở expanded → tab Công nợ → bấm **Thanh toán** → gõ `5000000` → hiển thị `5.000.000`.
- [ ] Bấm **Điều chỉnh** → gõ `0` (placeholder) → ô rỗng → gõ `1000000` → `1.000.000`.
- [ ] Bấm **Cấn bằng công nợ** → realtime cũng đúng.
- [ ] Submit → công nợ giảm/tăng đúng số.

### 7.4. CashFlow
- [ ] `/cash-flows` → bấm Thu/Chi → ô "Số tiền" → realtime format.
- [ ] Lưu → bảng cashflow hiện đúng giá trị.

### 7.5. Input không phải tiền (regression)
- [ ] Ô số lượng vẫn nhập được số nguyên (type="number" giữ nguyên).
- [ ] Ô SĐT / Serial / IMEI / mã hàng / mã phiếu — KHÔNG bị format dấu chấm.
- [ ] Ô phần trăm — KHÔNG bị format.

### 7.6. Console
- [ ] Không lỗi js khi nhập.
- [ ] Vue dev warning về MoneyInput prop không sai.

## 8. Ảnh hưởng nghiệp vụ

- **Công nợ:** ✅ KHÔNG ảnh hưởng — payload backend vẫn number raw. 72 TC Supplier suite PASS, công nợ ledger không đổi.
- **CashFlow:** ✅ KHÔNG động — service không sửa, backend nhận `amount` number bình thường. 12 TC PASS.
- **Tồn kho / giá vốn:** ✅ KHÔNG động — không touch Purchase / Stock / serial / moving-avg logic.
- **Invoice / POS:** ✅ Phase-2 cho POS; Invoice không trong scope HOTFIX này. Backend không đổi → 50+ Invoice TC PASS.
- **Payroll:** ✅ Phase-2; chưa swap.
- **Phiếu nhập:** ✅ MoneyInput emit number raw → `Purchases/Create+Edit+Show.vue` form submit đúng. 40 Purchase TC PASS.

## 9. Kết luận

- **Mọi input tiền đã format realtime VND chưa?** ⚠️ Một phần — các ô tiền cao tần nhất (phiếu nhập, công nợ NCC, cashflow) đã realtime. POS / Products / Customers / Repairs / Tasks / Payroll còn `type="number"` rải rác (phase 2). Helper + component đã sẵn sàng để swap mà không cần thêm logic.
- **Backend còn nhận number raw không?** ✅ Có — `v-model` của `MoneyInput` luôn là number; payload gửi đúng `amount: 1000000`. KHÔNG bao giờ gửi `"1.000.000"`.
- **Có thể deploy không?** ✅ Có — 255 PHP TC + 7 JS TC PASS, build PASS, không đụng nghiệp vụ backend.
- **Commit SHA:** `190c9b7` — `fix(ui): format VND money inputs in realtime`.
- **Push status:** ✅ đã push, `origin/main` = `190c9b785212f2cc90ee7b70e0fae1eb39186e12`.
