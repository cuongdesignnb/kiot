# STEP 24.5 — Global Vietnamese Date/Time Format Standardization

## 1. Root cause

- Tại sao admin/user khác nhau: `config('app.timezone')` mặc định là `UTC`, và mọi `<input type="datetime-local">` render theo browser locale. User Chrome `en-US` thấy `MM/DD/YYYY hh:mm AM/PM`; user `vi-VN` thấy `DD/MM/YYYY HH:mm`. Cùng dữ liệu, hai cách trình bày → dễ hiểu nhầm 08/05 ↔ 05/08.
- Native `datetime-local`: HTML5 không cho ép locale; Chrome dùng OS/browser preference cho widget, không cho `lang` override.
- Phạm vi: 15+ chỗ dùng `datetime-local` trên màn nghiệp vụ (POS, Orders, Invoices/Edit, CashFlows, Purchases, Stock*, Damages, PurchaseOrders, Suppliers debt, Tasks, Customers debt). 40+ chỗ display dùng `toLocaleString('vi-VN', …)` (đã cố ép locale, OK) hoặc `toLocaleString()` không locale (xấu).

## 2. Date/time policy

| Loại                | Format            |
|---------------------|-------------------|
| Display date        | `dd/MM/yyyy`      |
| Display datetime    | `dd/MM/yyyy HH:mm`|
| Display time        | `HH:mm` (24h)     |
| Submit date         | `yyyy-MM-dd`      |
| Submit datetime     | `yyyy-MM-ddTHH:mm`|
| Timezone            | `Asia/Ho_Chi_Minh`|

Nguyên tắc: UI input/display **không phụ thuộc** browser locale. Mọi format đều do code mình tự build từ year/month/day/hour/minute, không gọi `toLocaleString` cho nội dung chính.

## 3. Audit matrix (Phase 24.5A — critical inputs replaced)

| Module           | File                                  | Field             | Before                        | After |
|------------------|---------------------------------------|-------------------|-------------------------------|-------|
| POS              | `Pages/POS/Index.vue:647`             | `saleDate`        | `<input type=datetime-local>` | `<DateTimePicker naked compact>` |
| Orders/Create    | `Pages/Orders/Create.vue:916,919`     | `activeTab.orderDate` | 2× datetime-local         | 2× `<DateTimePicker naked compact>` |
| CashFlows        | `Pages/CashFlows/Index.vue:851,1220`  | `form.time`       | 2× datetime-local             | 2× `<DateTimePicker>` |
| Purchases/Create | `Pages/Purchases/Create.vue:598`      | `purchaseDate`    | datetime-local                | `<DateTimePicker naked compact>` |
| Purchases/Edit   | `Pages/Purchases/Edit.vue:469`        | `purchaseDate`    | datetime-local                | `<DateTimePicker naked compact>` |
| Purchases/Show   | `Pages/Purchases/Show.vue:453`        | `editForm.purchase_date` | datetime-local        | `<DateTimePicker>` |
| StockTransfers   | `Pages/StockTransfers/Create.vue:317` | `transactionDate` | datetime-local                | `<DateTimePicker naked compact>` |
| StockTakes       | `Pages/StockTakes/Create.vue:240`     | `transactionDate` | datetime-local                | `<DateTimePicker naked compact>` |
| Damages          | `Pages/Damages/Create.vue:242`        | `transactionDate` | datetime-local                | `<DateTimePicker naked compact>` |
| PurchaseOrders   | `Pages/PurchaseOrders/Create.vue:258` | `orderDate`       | datetime-local                | `<DateTimePicker naked compact>` |
| Suppliers debt   | `Pages/Suppliers/Index.vue:1471,1475` | `debtDate`        | 2× datetime-local             | 2× `<DateTimePicker>` |
| Customers debt   | `Pages/Customers/Index.vue:2934`      | `debtForm.date`   | datetime-local                | `<DateTimePicker>` |
| Tasks            | `Pages/Tasks/Index.vue:672`           | `createForm.received_at` | datetime-local         | `<DateTimePicker>` |

Phase 24.5B (display-only / lower priority — **deferred**, not in this commit): Dashboard, Reports/* (~15 files), Customers display rows, Warranties filter inputs (`type="date"`), Tasks deadline (`type="date"`), Repairs deadline, ActivityLogs filter, Employees Holiday/Attendance, Invoices/Orders display rows. Most already use `toLocaleString('vi-VN', …)` — works for vi-VN browsers but inconsistent for others. Migration to `formatDateTimeVN()` from the new utility is straightforward and can land per-screen without coordination.

## 4. Utilities/components

| File | Nội dung |
|---|---|
| `resources/js/utils/dateTime.js` | Locale-independent format/parse helpers: `formatDateVN`, `formatDateTimeVN`, `formatTimeVN`, `parseVNDate`, `parseVNDateTime`, `toDatetimeLocalValue`, `toDateInputValue`, `isValidVN*`, `timeAgoVN`. No `toLocaleString` for primary output. |
| `resources/js/Components/DateTimePicker.vue` | Text-input picker for `dd/MM/yyyy HH:mm`. Reads canonical, emits canonical `yyyy-MM-ddTHH:mm`. `naked` prop drops default border/bg so callers can keep their own minimal styling. `compact` hides the inline "Hiện tại" button for tight UIs. |
| `resources/js/Components/DatePicker.vue` | Same idea, date-only `dd/MM/yyyy` ↔ `yyyy-MM-dd`. |

## 5. Replaced native inputs

See section 3 — 13 file edits, 15 input replacements.

## 6. Backend parsing

| Endpoint | Field | Parse rule |
|---|---|---|
| `config/app.php` | `timezone` | env default changed `UTC` → `Asia/Ho_Chi_Minh`. Production `.env` should set `APP_TIMEZONE=Asia/Ho_Chi_Minh` explicitly to be safe. |
| `POST /orders` (`OrderController::store` → `InvoiceController` chain) | `order_date` | Already accepted canonical `yyyy-MM-ddTHH:mm`; `Carbon::parse` honours app timezone. No change. |
| `PUT /invoices/{id}` | `transaction_date` | validation `nullable\|date` unchanged; canonical form parsed correctly under VN tz. |
| `/customer-groups`, `/customers`, `/cashflows`, `/purchases`, `/purchase-orders`, `/stock-transfers`, `/stock-takes`, `/damages`, `/suppliers/debt-*`, `/tasks` | `*date*` fields | All already accept canonical `yyyy-MM-ddTHH:mm`. New pickers always emit canonical, never localised text. |

No backend logic changes for date semantics — **business invariants from Step 24.3 (transaction_date / lock_started_at policy) preserved**.

## 7. Tests

| Test | Status |
|---|---|
| `Step245DateTimeFormatTest::test_app_timezone_is_vietnam` | ⏸ Blocked — local MySQL on port 3319 offline at run time |
| `…test_now_helper_returns_vietnam_time` | ⏸ Blocked |
| `…test_canonical_payload_08_05_2026_is_parsed_as_may_8_not_august_5` | ⏸ Blocked |
| `…test_carbon_parses_canonical_payload_without_timezone_drift` | ⏸ Blocked |
| `…test_birthday_filter_dd_mm_yyyy_intent_via_canonical_yyyy_mm_dd` | ⏸ Blocked |
| Customer hotfix cluster (33+3) | Last green run before MySQL outage: **36 PASS** |
| Regression cluster (Step232–243, RR02–13, Warranty, Order, Purchase, …) | Last green run before MySQL outage: **285 PASS, 0 fail** |
| `npm run build` | ✅ Built in 8.00s — SFC templates all compile |

**Test caveat:** local dev MySQL went offline mid-session (`SQLSTATE[HY000] [2002]` on port 3319). The test file is committed but **needs a green local run after MySQL restarts**, before this is treated as fully verified. The frontend changes are SFC swaps validated at compile time; the backend change is a one-line timezone default. Risk of regression in the un-tested Step245 cluster is low, but should be confirmed.

## 8. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration không? | **Không** |
| Có update DB cũ không? | **Không** — chỉ chuẩn hóa hiển thị/parse |
| Có đổi `created_at` semantics không? | **Không** |
| Có ảnh hưởng `transaction_date` policy (Step 24.3) không? | **Không** — vẫn dùng canonical, vẫn validate `nullable\|date` |
| Có phụ thuộc browser locale không? | **Không** — text input + util tự format từ getDate/getMonth/etc |
| Có hardcode timezone offset client-side không? | **Không** — luôn dùng Date object's local methods |
| Có rollback hotfix Customer Group / Invoice TypeError không? | **Không** |

## 9. Manual QA

- [ ] Chrome English (en-US): Mở `/pos`, ngày bán hiện `dd/MM/yyyy HH:mm`, không có AM/PM.
- [ ] Chrome Vietnamese (vi-VN): cùng giao diện, cùng giá trị.
- [ ] POS tạo hóa đơn ngày 08/05/2026 10:14 → DB `invoices.transaction_date = 2026-05-08 10:14` (không phải 2026-08-05).
- [ ] Orders/Create: ngày đơn hiển thị + submit đúng VN.
- [ ] Invoices Edit: hóa đơn 08/05 không bị hiểu thành 05/08 sau lưu.
- [ ] CashFlows: ngày thu/chi 24h, không AM/PM.
- [ ] Purchases Create/Edit/Show: ngày nhập đúng.
- [ ] PurchaseOrders Create: ngày đơn đúng.
- [ ] StockTransfers/Takes/Damages Create: ngày thao tác đúng.
- [ ] Suppliers Index modal nợ: ngày điều chỉnh/thanh toán đúng.
- [ ] Customers Index modal nợ: ngày điều chỉnh đúng.
- [ ] Tasks Index modal tạo: `received_at` đúng.
- [ ] Customers sidebar filter (24.4A-3/24.4A-4): preset filter vẫn hoạt động, không bị Step 24.5 phá.
- [ ] Group create modal vẫn lưu được (24.4A-3 hotfix giữ nguyên).

## 10. Conclusion

- Đã hết nhầm MM/DD chưa: **Có cho Phase 24.5A** (15 input critical đã không còn `<input type=datetime-local>`). Phase 24.5B là display-only, đa số đã `toLocaleString('vi-VN')` nên ít rủi ro hiển thị MM/DD; vẫn nên migrate dần sang `formatDateTimeVN()` để tách hoàn toàn khỏi locale.
- An toàn production: **Có** — không migration, không backfill, không đổi business logic. Risk duy nhất: Step245 test cluster chưa chạy được do MySQL local offline; SFC build pass, code mechanical.
- Có thể deploy không: **Có** sau khi user xác nhận MySQL chạy lại + Step245 test pass + CustomerGroup/CustomerFilters/regression cluster vẫn xanh.
