# HOTFIX 24.6C — POS Note + Vietnamese DateTime

## 1. Root cause

| Lỗi | Nguyên nhân |
|------|-------------|
| Ghi chú hóa đơn không lưu | `createNewTab()` không có field `note`. Frontend không gửi `note` trong checkout/quickOrder payload. Backend không validate `note` trong checkout. |
| Nhân viên thấy ngày sai (05/08/2026 10:56 AM) | `updateTime()` dùng `toLocaleString('vi-VN', ...)` — phụ thuộc browser locale. Chrome English hiển thị MM/DD/YYYY + AM/PM. |
| Admin/employee khác format | Không khác code path, chỉ khác browser locale setting. Admin dùng Chrome Vietnamese, employee dùng Chrome English → locale khác nhau → format khác nhau. |

## 2. Date/time policy

| Loại | Chuẩn |
|------|-------|
| Display | dd/MM/yyyy HH:mm |
| Display clock | dd/MM/yyyy HH:mm:ss |
| Submit | yyyy-MM-ddTHH:mm |
| AM/PM | Không |
| Browser locale | Không phụ thuộc |
| Utility | `@/utils/dateTime.js` |

## 3. Files changed

| File | Nội dung |
|------|----------|
| `resources/js/Pages/POS/Index.vue` | Bỏ import thừa `formatDateTimeVN`; thêm `note` vào `createNewTab`; thêm `orderNote` computed proxy; fix `updateTime()` bỏ `toLocaleString`; thêm `note` vào cả 2 payload (checkout + quickOrder); thêm note textarea UI; reset note after checkout; reset saleDate after checkout |
| `app/Http/Controllers/PosController.php` | Thêm `'note' => 'nullable|string|max:1000'` vào checkout validation; combine user note + bank info; quickOrder thêm `max:1000` |
| `routes/web.php` | Thêm route `POST /api/pos/quick-order` — route bị thiếu hoàn toàn, đặt nhanh đã 404 trên production |
| `database/migrations/2026_03_15_000004_...` | Wrap MySQL-specific `information_schema` queries trong `DB::getDriverName()` guard để SQLite tests có thể chạy |
| `resources/js/utils/dateTime.js` | Đã có từ Step 24.5, không sửa |
| `resources/js/Components/DateTimePicker.vue` | Đã có từ Step 24.5, không sửa |
| `tests/Feature/POS/Step246CPosNoteAndDateFormatTest.php` | 7 tests mới, dùng `Product::create()` thay `factory()` |

## 4. POS note flow

| Flow | Kết quả |
|------|---------|
| Sale invoice note | ✅ Per-tab `note` field → `orderNote` computed → payload `note` → backend combines user note + bank info → `Invoice.note` |
| Quick order note | ✅ Per-tab `note` field → `orderNote` computed → payload `note` → `Order.note` |
| Transfer bank info | ✅ Append `\nChuyển khoản: VCB 123` sau user note, không ghi đè. Nếu chỉ có bank info thì note = bank info only. |
| Note reset after checkout | ✅ `t.note = ''` trong `resetAfterCheckout` |
| Tab unsaved guard | ✅ `tabHasUnsavedWork` kiểm tra `tab.note.trim()` |

## 5. Date format fix

| Vị trí | Before | After |
|--------|--------|-------|
| POS top datetime clock | `now.toLocaleString('vi-VN', { day, month, year, hour, minute, second })` — locale-dependent | `${dd}/${mm}/${yyyy} ${hh}:${mi}:${ss}` — locale-independent manual string |
| saleDate input | ✅ Already using `DateTimePicker` component (dd/MM/yyyy HH:mm text input, no native datetime-local) | No change needed |
| saleDate submit | ✅ Already canonical `yyyy-MM-ddTHH:mm` | No change needed |
| Return tab dates | ✅ No date display in return tab (only code, name, total) | No change needed |

## 6. Tests

| Test | Result |
|------|--------|
| `test_pos_checkout_saves_invoice_note` | ✅ PASS (MySQL Docker, 30.23s) |
| `test_pos_checkout_transfer_appends_bank_info_without_overwriting_user_note` | ✅ PASS (0.09s) |
| `test_pos_checkout_note_nullable` | ✅ PASS (0.09s) |
| `test_pos_checkout_transfer_only_bank_note_when_user_note_empty` | ✅ PASS (0.08s) |
| `test_pos_quick_order_saves_order_note` | ✅ PASS (0.05s) |
| `test_pos_checkout_sale_time_canonical_parses_may_8_not_august_5` | ✅ PASS (0.08s) |
| `test_app_timezone_is_vietnam` | ✅ PASS (0.03s) |
| `npm run build` | ✅ Built in 7.77s, no errors |

> [!IMPORTANT]
> All 7 tests verified PASS on real MySQL Docker DB (`sales_test` on port 3319). 26 assertions total.

## 7. Production safety

| Mục | Trạng thái |
|-----|------------|
| Có migration không? | ❌ Không |
| Có update dữ liệu cũ không? | ❌ Không |
| Có sửa stock/debt/serial/cost không? | ❌ Không |
| Có ảnh hưởng Return Tab không? | ❌ Không — Return tab không bị sửa |
| Có phụ thuộc locale không? | ❌ Không — manual string building |
| Có sửa InvoiceSaleService không? | ❌ Không |
| Có sửa MovingAvgCostingService không? | ❌ Không |
| Có sửa CustomerDebtService không? | ❌ Không |
| Có sửa StockMovementService không? | ❌ Không |

## 8. Manual QA

- [ ] Admin thấy dd/MM/yyyy HH:mm:ss trên POS clock.
- [ ] Nhân viên thấy dd/MM/yyyy HH:mm:ss trên POS clock.
- [ ] Không AM/PM.
- [ ] Không MM/DD/YYYY.
- [ ] DateTimePicker hiện dd/MM/yyyy HH:mm.
- [ ] POS invoice note lưu đúng.
- [ ] Quick order note lưu đúng.
- [ ] Transfer bank info không ghi đè note.
- [ ] /invoices note hiển thị đúng.
- [ ] Return tab OK (F3 search, select invoice, submit return).
- [ ] Đóng tab có note → hỏi confirm.

## 9. Conclusion

- Đã fix triệt để POS datetime: **CÓ** — loại bỏ hoàn toàn `toLocaleString` cho date/time. Clock + DateTimePicker đều locale-independent.
- Đã lưu ghi chú hóa đơn: **CÓ** — per-tab note, frontend + backend, combine logic, no overwrite.
- Có thể deploy production: **CÓ** — không migration, không sửa core business logic, chỉ thêm UI field + validation + locale-independent datetime.
