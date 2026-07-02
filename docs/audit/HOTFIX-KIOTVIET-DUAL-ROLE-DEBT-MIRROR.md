# HOTFIX — KiotViet dual-role customer/supplier debt mirror

## Phạm vi audit
- **Module**: Financial, Partner, Debt (Công nợ & Lịch sử đối tác)
- **Màn hình**:
  - Khách hàng (tab Công nợ và cột Nợ hiện tại)
  - Nhà cung cấp (tab Công nợ và cột Nợ cần trả hiện tại)
- **Nghiệp vụ**:
  - Công nợ khách hàng kiêm nhà cung cấp (dual-role partner).
  - Mirror ledger NCC sang màn khách với dấu đảo ngược để hiển thị nợ ròng.
  - Tách biệt rõ Nợ phải thu, Nợ phải trả, và Vị thế ròng (Partner Net Position) trên UI cả 2 màn hình.
- **Rủi ro chính**:
  - Rủi ro logic: Hiển thị nhầm lẫn hoặc lệch số dư nợ.
  - Rủi ro data: Thấp. Lập chỉ sửa read-only API và giao diện hiển thị, hoàn toàn không sửa đổi hoặc tự cấn trừ dữ liệu cũ trên database.

## Source đã kiểm tra
- **File**:
  - [PartnerDebtLedgerService](file:///d:/Kiot/kiotviet-clone/app/Services/PartnerDebtLedgerService.php)
  - [CustomerController](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerController.php)
  - [SupplierController](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/SupplierController.php)
  - [Customers/Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Customers/Index.vue)
  - [Suppliers/Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Suppliers/Index.vue)
- **Route**:
  - `GET /customers/{customer}/debt-history`
  - `GET /api/suppliers/{id}/debt-transactions`
- **Controller**:
  - `CustomerController@debtHistory`
  - `SupplierController@debtTransactions`
- **Service**:
  - `PartnerDebtLedgerService`
- **Model**:
  - `Customer`, `CustomerDebt`, `SupplierDebtTransaction`, `Purchase`, `CashFlow`, `DebtOffset`
- **Migration**:
  - Không có migration mới (sử dụng schema hiện hữu).
- **Test**:
  - `tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php`
  - `tests/Feature/Suppliers/SupplierPayableLedgerTest.php`
  - `tests/Feature/Customers/ReconcilePartnerLedgerCommandTest.php`
  - `tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php`
- **Commit**:
  - `a000283` (Base hotfix commit) and follow-up commit.

## Hiện trạng
- **Backend**: Đã chuẩn hóa Service tính toán lịch sử công nợ, đảm bảo mirror chính xác và phân chia rõ rệt running balances. Tránh double-counting giữa `Purchase.paid_amount` và CashFlow thực tế.
- **Frontend**: Hiển thị bảng đối soát 3 cột (Nợ phải thu, Nợ phải trả, Vị thế ròng) trên tab Công nợ của cả 2 màn hình Khách hàng và Nhà cung cấp khi đối tác là dual-role.
- **Database**: Sử dụng các trường `debt_amount` và `supplier_debt_amount` của bảng `customers`.
- **Permission**: Đầy đủ cho môi trường CLI chạy command đối soát.
- **Production/deploy**: Chưa deploy code mới nhất lên production.

## Root cause
- Thiếu lớp Ledger Builder tập trung xử lý cho hai góc nhìn (Khách hàng Net Ledger và Nhà cung cấp Payable Ledger), dẫn tới việc hiển thị lệch số dư running balance ròng và không giải thích rõ được các cấu phần nợ.

## Đối chiếu case Anh Thanh Thiên Phú
- **Môi trường chạy**: Local / Staging (Bằng bộ test tự động và dry-run command CLI).
- **Có phải dữ liệu thật không**: Dữ liệu simulated dựa trên case thực tế của Anh Thanh Thiên Phú.
- **Command/lệnh đã chạy**:
  ```bash
  php artisan customers:reconcile-partner-ledger --code=KH177727496998
  ```
- **Output summary** (chạy trên dữ liệu simulated/local; chưa đối chiếu production thật):
  - **Receivable cached**: 47,400,000.00đ
  - **Receivable computed**: 47,400,000.00đ (matches simulated fixture)
  - **Payable cached**: 75,000,000.00đ
  - **Payable computed**: 75,000,000.00đ (matches simulated fixture)
  - **Partner net position (cached)**: -27,600,000.00đ
  - **Partner net position (computed)**: -27,600,000.00đ (matches simulated fixture)
  - **Has debt-offset voucher**: `false` — chỉ là vị thế ròng display, không phải phiếu CB/HCB.
- **Ledger detail**:
  - `MERGE-CUSTOMER-141`: `+47,420,000đ` (Số dư đầu kỳ / Gộp công nợ)
  - `CKTT26052510573737`: `-20,000đ` (Chiết khấu thanh toán)
  - `PN20260523105400`: `-75,000,000đ` (Nhập hàng - mirror đảo dấu từ NCC)
- **Mismatch**: Không có mismatch trong fixture đã mô phỏng.
- **Kết luận (giới hạn)**: Đối soát ledger pass ở phạm vi test local/staging cho case mô phỏng đã liệt kê. **Chưa** đối chiếu trên dữ liệu thật production; **chưa** đủ điều kiện kết luận "khớp 100%" hoặc "không còn rủi ro". Cần đối chiếu read-only trên dữ liệu thật production sau khi user xác nhận.

## Có ảnh hưởng dữ liệu đang có không?
- **Không**. Tất cả tính toán và đối chiếu công nợ được thực hiện trên lớp đọc (Read-only API & CLI command), không ghi dữ liệu xuống database và không chạy cấn trừ ảo trong GET requests.

## Data safety
- **Migration**: Không.
- **Backfill**: Không.
- **Update dữ liệu cũ**: Không.
- **Delete**: Không.
- **Recalculate**: Không.
- **Rollback plan**: Revert code về HEAD và rebuild frontend asset (`npm run build`).
- **Backup DB**: Không yêu cầu (do không ghi dữ liệu).

## Tests đã chạy
- **Lệnh**:
  ```bash
  vendor/bin/phpunit tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php tests/Feature/Suppliers/SupplierPayableLedgerTest.php tests/Feature/Customers/ReconcilePartnerLedgerCommandTest.php tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php
  ```
- **Kết quả thật**:
  - `OK (14 tests, 129 assertions)`
- **Log hoặc summary**:
  - Cả 14 tests liên quan đến dual-role, cấn trừ offset, chống double-count và command CLI đều pass thành công trên database test.

## Build
- `npm run build`: **PASS** (Biên dịch asset frontend thành công trong 7.51s).

## Manual QA
- **Môi trường**: local/staging với fixture mô phỏng. Chưa QA trên dữ liệu production thật.
- **Màn Khách hàng**: Chọn đối tác "Anh Thanh Thiên Phú", card "Vị thế ròng (Net Position)" hiển thị -27.600.000đ; tab Công nợ thể hiện running balance ròng chronological. Dòng CKTT âm và MERGE dương đúng dấu.
- **Màn NCC**: Chọn đối tác NCC đó, cột nợ chính giữ Nợ cần trả NCC (75.000.000đ); tab Công nợ hiển thị các dòng NCC.
- **Evidence**: Screenshot/log mô phỏng theo fixture local. **Chưa** có bằng chứng từ production thật.

## Rủi ro còn lại
- Dữ liệu legacy/payment/CB trên production có thể có hình thái khác fixture local — đặc biệt:
  - `CashFlow.status = NULL` có thể làm route TTNH double-count nếu service không dùng NULL-safe filter (đã được fix ở HOTFIX FOLLOW-UP).
  - Standalone `SupplierDebtTransaction` có thể bị marked "Đã hạch toán" sai nếu vẫn dùng `$purchasePaidTotal <= 0` (đã được fix ở HOTFIX FOLLOW-UP).
- Hai bản fix trên cần được kiểm tra trên dữ liệu production để xác nhận không gây hồi quy.
- UI "Vị thế ròng" chỉ là delta hiển thị — không phải phiếu cấn trừ thật. Báo cáo này KHÔNG kết luận đã có phiếu CB/HCB cho đối tác.

## Kết luận
- **Đạt/chưa đạt**: Đạt ở phạm vi test local/staging cho các case đã mô phỏng. **Chưa** đủ điều kiện chốt production.
- **Có thể deploy chưa**: Có thể deploy staging để Manual QA tiếp; **chưa** đủ điều kiện deploy production.
- **Cần làm tiếp**:
  1. User xác nhận để chạy `customers:reconcile-partner-ledger` read-only trên production lấy snapshot dữ liệu thật.
  2. Đối chiếu kết quả với cảm nhận nghiệp vụ + KiotViet.
  3. Tests double-count payment + CB display group cần được pin tiếp khi có dữ liệu production làm fixture.
  4. Chỉ chốt "đã đối trừ" khi có chứng từ CB/HCB thật (`has_debt_offset_voucher = true`).

---

## HOTFIX FOLLOW-UP — Đồng bộ trạng thái hủy CashFlow tiếng Việt

### Vấn đề
`scopeNotCancelledCashFlow()` trước đó chỉ loại 4 giá trị tiếng Anh (`cancelled, canceled, void, deleted`) trong khi `isCancelledStatus()` xử lý cả tiếng Việt (`đã hủy, da huy`). Lệch danh sách gây nguy cơ: một CashFlow `status='Đã hủy'` vẫn bị scope SQL coi là payment hợp lệ → service chặn fallback TTNH sai → ledger thiếu payment legacy.

### Cách sửa
- Tạo helper dùng chung `cancelledStatuses()` — single source of truth cho cả PHP-side (`isCancelledStatus`) và DB-side (`scopeNotCancelledCashFlow`).
- `scopeNotCancelledCashFlow()` normalise cột `status` bằng `LOWER(TRIM(status))` để defeat case/whitespace drift (vd `' DA HUY '`, `'Đã Hủy'`).
- Giữ semantics:
  - `status = NULL` → vẫn coi là legacy payment hợp lệ.
  - `status = active/pending/completed/...` (mọi non-cancelled) → hợp lệ.
  - `status ∈ {cancelled, canceled, void, deleted, đã hủy, da huy}` (case-insensitive, trim) → loại.

### Tests pin contract
- `test_vietnamese_cancelled_cashflow_does_not_block_legacy_purchase_paid_amount` — `Đã hủy` → fallback `TTNH...` được sinh.
- `test_ascii_vietnamese_cancelled_cashflow_does_not_block_legacy_purchase_paid_amount` — `da huy` → fallback `TTNH...` được sinh.
- `test_mixed_case_cancelled_cashflow_is_normalised` — `' CANCELLED '` (padded + upper-case) → vẫn loại nhờ `LOWER+TRIM`.

### Wording
- UI: title cột "Nợ hiện tại" ở `Customers/Index.vue` đổi từ "Nợ ròng = Nợ KH − Nợ NCC" sang "Vị thế ròng = Phải thu khách − Phải trả NCC. Đây là delta hiển thị, không phải phiếu cấn trừ.".
- Doc: các dòng mô tả tab 3 cột đổi từ "Nợ ròng" sang "Vị thế ròng (Partner Net Position)".
- Command: đã đổi nhãn từ vòng trước (`Partner Net Position (Vị thế ròng)`), không đổi thêm.

### Data safety
- Không migration, không backfill, không update/delete data, không recalculate.
- Chỉ sửa: 1 helper trong service, 3 test mới, 2 dòng audit doc, 1 tooltip UI.

### Tests run
- `php artisan test --filter=HOTFIXFollowUpSupplierLedgerHardeningTest` → **7/7 pass / 18 assertions / 0.74s**.
- Regression full debt-related → **192 passed / 995 assertions / 35.37s, zero fail**.
- `npm run build` → **built in 7.97s**.

---

## HOTFIX FOLLOW-UP — Align CB/DebtOffset + labels + pagination với KiotViet

### Vấn đề

User nhập DB production vào docker local; tab Công nợ của dual-role partner (vd Long pin) vẫn không khớp KiotViet về 4 điểm:

1. **CB (DebtOffset) sign sai trên màn KH + invisible trên màn NCC** — KiotViet hiển thị CB000306 trên CẢ 2 màn với dấu mirror (`-10M` supplier / `+10M` customer); ta hiện chỉ ra `-10M` ở customer side (sai dấu) và không có row ở supplier side.
2. Cột "Loại": KiotViet là "Thanh toán"; ta dùng "Thanh toán NCC".
3. Tab Công nợ KiotViet phân trang server-side (10 row/page, "121 - 130 trong 232 dòng"); ta trả full ledger.
4. `display_type` CB là "Cấn bằng công nợ"; KiotViet là "Điều chỉnh" (badge_label đã đúng).

### Cách sửa

**Service `PartnerDebtLedgerService.php`**:

- `buildSupplierPayableLedger()` thêm section 6 emit DebtOffset:
  - Active CB → `supplier_effect = -amount`, `type_label/badge_label = 'Điều chỉnh'`.
  - Cancelled CB → CB row (`-amount` tại `created_at`) + HCB row (`+amount` tại `cancelled_at`) để running balance về pre-CB state.
  - Dedup: skip CB row nếu `SupplierDebtTransaction` cùng code đã emit (legacy data có thể carry CB ở cả 2 bảng).
- `buildCustomerReceivableLedger()` thay đoạn `foreach ($offsets as $offset)` bằng guard `$isDualRole ? collect() : ...` — pure customer giữ behavior cũ; dual-role skip để mirror line 389 đảm nhận emission (no double-count).
- 4 chỗ `badge_label = 'Thanh toán NCC'` → `'Thanh toán'` (lines 113, 156, 208 + bỏ logic `display_type = 'Thanh toán NCC'` ở mirror branch line 442).
- `display_type` CB: `'Cấn bằng công nợ'` → `'Điều chỉnh'`; HCB: `'Hủy cấn bằng công nợ'` → `'Hủy điều chỉnh'`.

**Controllers `CustomerController.php` + `SupplierController.php`**:

- Pagination **opt-in**: chỉ paginate khi caller gửi `?page=N`. Không có `?page=` → trả full ledger (backward compat cho tests/exports/scripts).
- Khi paginate: slice `entries`, append `pagination` block `{ total, per_page, current_page, last_page, from, to }`. Summary + reconcile lấy từ full ledger.
- `exportDebtHistory` (cả 2) bypass pagination, gọi trực tiếp service.

**Frontend `Customers/Index.vue` + `Suppliers/Index.vue`**:

- `loadDebtHistory(customerId, page = null)` + `loadSupplierDebt(id, page = null)` gửi `?page=&per_page=10`.
- Nav buttons `|‹ ‹ N/M › ›|` + label "X - Y trong Z dòng" matching KiotViet.

### KiotViet reference signs (verified)

| Document | Supplier "Giá trị" | Sup running | Customer "Giá trị" | Cust running |
|---|---:|---:|---:|---:|
| CB000306 | -10,000,000 | 6,845,000 | +10,000,000 | -6,845,000 |
| PN003212 | +900,000 | 16,845,000 | -900,000 | -16,845,000 |
| PCPN003211 | -980,000 | 15,945,000 | +980,000 | -15,945,000 |

Test `HOTFIXFollowUpDebtOffsetMirrorTest` pin chính xác contract này trên fixture matching Long pin.

### Tests (HOTFIX FOLLOW-UP round 3)

| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=HOTFIXFollowUpDebtOffsetMirrorTest` | ✅ **5/5 pass / 23 assertions** |
| `php artisan test --filter=HOTFIXFollowUpDebtHistoryPaginationTest` | ✅ **5/5 pass / 18 assertions** |
| `php artisan test --filter=HOTFIXFollowUpSupplierDebtPaginationTest` | ✅ **3/3 pass / 14 assertions** |
| `php artisan test --filter="HOTFIXFollowUp\|DualRolePartner\|SupplierPayableLedger\|ReconcilePartnerLedger\|AnhThanhThienPhu\|CustomerDebt\|CustomerNetDebt\|Supplier\|DebtOffset\|CashFlow\|PartnerFinancial"` | ✅ **205 passed / 1049 assertions / 35.92s** zero fail |
| `npm run build` | ✅ built in 7.52s |

3 test cũ được cập nhật theo contract mới (không sửa code để giữ test cũ):
- `DualRolePartnerDebtTimelineTest::test_offset_displays_correctly` — pin "exactly 1 CB row via mirror" thay vì "2 rows".
- `PartnerFinancialTimelineTest::test_supplier_payment_in_customer_screen_offsets_purchase` — `display_type = 'Thanh toán'` thay vì `'Thanh toán NCC'`.
- `HOTFIXFollowUpSupplierLedgerHardeningTest::test_standalone_supplier_payment_*` — `badge_label = 'Thanh toán'` thay vì `'Thanh toán NCC'`.

### File đã sửa

| File | Nội dung |
|---|---|
| `app/Services/PartnerDebtLedgerService.php` | Section 6 emit DebtOffset trên supplier ledger (dedup theo code); pure-vs-dual-role guard cho customer-side CB; label "Thanh toán" + display_type "Điều chỉnh" |
| `app/Http/Controllers/CustomerController.php` | Opt-in pagination `?page=N`; export bypass |
| `app/Http/Controllers/SupplierController.php` | Opt-in pagination `?page=N`; export bypass |
| `resources/js/Pages/Customers/Index.vue` | `loadDebtHistory` + `changeDebtHistoryPage` + nav UI |
| `resources/js/Pages/Suppliers/Index.vue` | `loadSupplierDebt` + `changeSupplierDebtPage` + nav UI |
| `tests/Feature/Customers/HOTFIXFollowUpDebtOffsetMirrorTest.php` | NEW — 5 TC pin CB mirror contract |
| `tests/Feature/Customers/HOTFIXFollowUpDebtHistoryPaginationTest.php` | NEW — 5 TC pin pagination contract |
| `tests/Feature/Suppliers/HOTFIXFollowUpSupplierDebtPaginationTest.php` | NEW — 3 TC |
| `tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php` | Update test_offset_displays_correctly contract |
| `tests/Feature/Customers/PartnerFinancialTimelineTest.php` | Update display_type label |
| `tests/Feature/Suppliers/HOTFIXFollowUpSupplierLedgerHardeningTest.php` | Update badge_label assertion |

### Data safety

| | |
|---|---|
| Migration | Không |
| Backfill | Không |
| Update dữ liệu cũ | Không |
| Delete | Không |
| Recalculate | Không |

### Out of scope

- `PartnerFinancialTimelineService` còn 3 drift (DebtOffset missing, stale `purchasePaidTotal` gate, NULL-safe TTNH dedup) — không gọi từ 2 endpoint customer/supplier debt-history nên không ảnh hưởng UI nhưng nên audit riêng sau.
- Sort/filter loại transaction trong tab Công nợ (dropdown "Tất cả giao dịch") — out of scope.
- KiotViet behavior cho CB partial offset (receivable vẫn còn) — ta theo model "CB chỉ giảm payable" giống screenshot Long pin.

### Kết luận

- Tab Công nợ dual-role (cả 2 màn) hiển thị CB với dấu mirror đúng KiotViet: ✅
- Cột Loại "Thanh toán" / "Điều chỉnh" matching KiotViet: ✅
- Server-side pagination 10/page với meta đầy đủ: ✅
- Backward compat: callers không gửi `?page=` vẫn nhận full ledger: ✅
- Data safety: không đụng dữ liệu cũ: ✅
- Có thể deploy staging: ✅
- Production: cần manual QA trên dữ liệu thật imported (Long pin + Anh Thanh Thiên Phú) so từng row với KiotViet trước khi chốt.
