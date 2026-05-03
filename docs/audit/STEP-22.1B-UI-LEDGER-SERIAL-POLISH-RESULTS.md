# STEP-22.1B — UI Ledger/Serial Polish Results

> **Bước:** 22.1B — Hoàn thiện UI P3 (customer debt ledger reading + serial display + Order process error UX)
> **Ngày:** 03/05/2026
> **Phạm vi:** Read-only enrich controller + Vue UI. **Không sửa core business logic, không thêm migration, không tự chọn serial.**

---

## 1. Mục tiêu

1. Tab "Công nợ" của khách hàng đọc trực tiếp ledger `customer_debts` (thay vì ráp tay từ invoices/cashflows).
2. Hiển thị Serial/IMEI đã trả trên Returns UI.
3. Hiển thị Serial/IMEI xuất hủy trên Damages UI.
4. Cải thiện hiển thị lỗi khi process Order serial chưa có `serial_ids`.
5. Không sửa nghiệp vụ core.
6. Không thêm migration `order_items.serial_ids`.
7. Không tự động chọn serial.

---

## 2. Git discovery thực tế

| Khu vực | File | Hiện trạng (trước 22.1B) | Việc cần sửa | Đụng backend? |
|---|---|---|---|---|
| Customer debt tab | [resources/js/Pages/Customers/Index.vue](resources/js/Pages/Customers/Index.vue) | Tab `debt` đã có, gọi `/customers/{id}/debt-history`, render `entry.code/created_at/type/amount/balance`. | Đổi nguồn dữ liệu sang `customer_debts`; polish màu amount/balance (đỏ nợ tăng / xanh trả). | ✅ Read-only `CustomerController::debtHistory` |
| `CustomerController::debtHistory` | [app/Http/Controllers/CustomerController.php](app/Http/Controllers/CustomerController.php) | Tự dựng entries từ `Invoice` + `CashFlow` + `Purchase` + `PurchaseReturn` + `SupplierDebtTransaction`, tự tính running balance (FE có thể lệch khỏi `customers.debt_amount`). Chưa đọc `customer_debts`. | Thay bằng query `CustomerDebt::where('customer_id', ...)` + map theo `recorded_at, ref_code, type, amount, debt_total`. Không update gì. | ✅ Read-only |
| Returns serial display | [resources/js/Pages/Returns/Index.vue](resources/js/Pages/Returns/Index.vue), [resources/js/Pages/Returns/Show.vue](resources/js/Pages/Returns/Show.vue) | `ret.items` có `serial_ids` (cast array) nhưng FE không hiển thị; Show.vue map item không trả về `serial_ids`. | Controller load `SerialImei` theo `serial_ids` → trả `returned_serials = [{id, serial_number}]`; UI render badge. | ✅ Read-only enrich `index/show` |
| Damage serial display | [resources/js/Pages/Damages/Index.vue](resources/js/Pages/Damages/Index.vue) | `damage.items` có `serial_ids` (cast array), FE chưa hiển thị. | Controller `index` enrich `destroyed_serials`; UI render badge. | ✅ Read-only enrich `index` |
| Order process error UX | [resources/js/Pages/Orders/Index.vue](resources/js/Pages/Orders/Index.vue) | `submitProcessOrder` có `onError` nhưng không capture message; backend trả `back()->with('error', ...)` (Inertia coi là success + flash) — modal đóng mà user không thấy lý do. | Bắt cả `flash.error` (success path) và `errors` (validation path), hiển thị box đỏ trong modal, gợi ý nguyên nhân thiếu Serial/IMEI. | Không (chỉ FE) |

---

## 3. File đã sửa

| File | Loại | Nội dung sửa |
|---|---|---|
| [app/Http/Controllers/CustomerController.php](app/Http/Controllers/CustomerController.php) | Backend (read-only) | Viết lại `debtHistory()` đọc `CustomerDebt::where('customer_id',...)->orderByDesc('recorded_at')->orderByDesc('id')->limit(100)`. Map fields: `id, code (=ref_code), type (label tiếng Việt), type_raw, amount, customer_effect, debt_total, balance, note, created_by, recorded_at, created_at`. Summary: `net = customer.debt_amount`, `is_dual_role`, `source = 'customer_debts'`, `count`. Bỏ logic ráp invoices/cashflows/purchases và self-running-balance. Không write. |
| [app/Http/Controllers/OrderReturnController.php](app/Http/Controllers/OrderReturnController.php) | Backend (read-only enrich) | `index()`: gom toàn bộ `serial_ids` từ paginate items → `SerialImei::whereIn('id', ...)->get(['id', 'serial_number'])` → set attribute `returned_serials = [{id, serial_number}]` lên từng `ReturnItem`. `show()`: map tương tự rồi đẩy `returned_serials` vào array trả Inertia. Không sửa store/cancel. |
| [app/Http/Controllers/DamageController.php](app/Http/Controllers/DamageController.php) | Backend (read-only enrich) | `index()`: enrich `destroyed_serials = [{id, serial_number}]` cho từng `DamageItem`. Không sửa store/cancel. |
| [resources/js/Pages/Customers/Index.vue](resources/js/Pages/Customers/Index.vue) | Vue | Empty state: "Chưa có lịch sử công nợ". Cột Giá trị: `amount > 0` đỏ + dấu `+`, `< 0` xanh; cột Công nợ: `balance > 0` đỏ, `< 0` xanh. |
| [resources/js/Pages/Returns/Index.vue](resources/js/Pages/Returns/Index.vue) | Vue | Trong cell tên hàng: render block `Serial/IMEI đã trả:` + badge xanh dương cho từng `s.serial_number || '#'+s.id` khi `item.returned_serials.length > 0`. |
| [resources/js/Pages/Returns/Show.vue](resources/js/Pages/Returns/Show.vue) | Vue | Cell tên hàng: thêm cùng block badge `returned_serials`. |
| [resources/js/Pages/Damages/Index.vue](resources/js/Pages/Damages/Index.vue) | Vue | Cell tên hàng: block `Serial/IMEI xuất hủy:` + badge đỏ cho `destroyed_serials`. |
| [resources/js/Pages/Orders/Index.vue](resources/js/Pages/Orders/Index.vue) | Vue | Thêm `usePage` + `processError` ref. Trong `submitProcessOrder`: `onSuccess` đọc `flash.error` (rollback path) → đặt `processError`, giữ modal mở; `onError` lấy first error từ `errors` object. `openProcessModal` reset `processError`. Modal: thêm box đỏ hiển thị `processError` + hint Serial/IMEI khi message chứa "serial". |

**Không sửa:** OrderController logic, OrderReturnController.store/cancel, DamageController.store/cancel, CustomerDebtService, MovingAvgCostingService, StockMovementService, InvoiceSaleService, audit tests, migrations.

---

## 4. Customer debt history

- **Endpoint chuyển sang `customer_debts`:** ✅ Có. Đọc 100 row mới nhất theo `recorded_at desc, id desc`.
- **Response fields:**
  - `entries[].id` — `customer_debts.id`
  - `entries[].code` — `ref_code`
  - `entries[].type` — label tiếng Việt (`sale → Bán hàng`, `sale_reversal → Hủy hóa đơn`, `return → Trả hàng`, `payment → Thanh toán`, `adjustment → Điều chỉnh`)
  - `entries[].type_raw` — type gốc trong DB
  - `entries[].amount` — float (signed; `+` = phát sinh nợ, `−` = giảm nợ)
  - `entries[].customer_effect` — alias `amount` để tương thích shape cũ
  - `entries[].debt_total` / `entries[].balance` — running balance snapshot lúc record
  - `entries[].note`, `created_by`
  - `entries[].recorded_at` / `created_at` — alias để FE cũ vẫn render
  - `summary.net = customer.debt_amount` (current cumulative)
  - `summary.is_dual_role`, `summary.source = 'customer_debts'`, `summary.count`
- **UI hiển thị:**
  - Cột Mã phiếu / Thời gian / Loại / Giá trị / Công nợ (giữ nguyên cấu trúc table cũ).
  - Giá trị: dương đỏ (`+...`), âm xanh.
  - Công nợ: dương đỏ, âm xanh, 0 xám.
  - Khi `entries.length === 0` → "Chưa có lịch sử công nợ".
- **Mất dữ liệu cũ?** Endpoint cũ là output thuần (không lưu DB), nên không mất gì. Lịch sử dual-role supplier vẫn còn ở `offsetHistoryData` (load từ endpoint khác qua `loadOffsetHistory`) — không trộn vào ledger customer_debts.

---

## 5. Returns serial display

- Backend `OrderReturnController::index/show` enrich field `returned_serials` (read-only, không sửa serial_ids gốc, không sửa logic store/cancel).
- UI Returns/Index.vue (expanded row item) và Returns/Show.vue (table item) hiển thị label `Serial/IMEI đã trả:` + dãy badge `serial_number` (fallback `#id` nếu serial bị xóa). Khi `serial_ids` rỗng (hàng không có serial hoặc legacy) thì không render block — không cần empty state riêng vì context table đã đủ rõ.

---

## 6. Damages serial display

- Backend `DamageController::index` enrich `destroyed_serials` cho từng `DamageItem`.
- UI Damages/Index.vue (expanded row table item) hiển thị `Serial/IMEI xuất hủy:` + badge đỏ. Không tạo `Show.vue` mới (chưa có route show).

---

## 7. Order process error UX

- Đã cải thiện. Trước: backend `back()->with('error', ...)` → Inertia gọi `onSuccess` (HTTP 200) → modal đóng, error chỉ hiện ở flash toàn page mà user không thấy trong modal.
- Sau: `submitProcessOrder` đọc `usePage().props.flash.error` trong `onSuccess`; nếu có flash error thì giữ modal mở và bind vào box đỏ. `onError` cũng map first error message. Modal có box đỏ inline + hint Serial/IMEI khi message chứa "serial".
- **Không làm Order serial selector trong bước này** vì:
  - Migration `order_items` chưa có cột `serial_ids` (cần migration & cast model).
  - OrderController validate `items.*.serial_ids` chưa có ở `store/update` (validate chỉ ở `processOrder` đọc lại từ orderItem).
  - Đụng nhiều file business — vượt phạm vi UI polish.
- **Backlog Step 22.1C:**
  1. Migration `add_serial_ids_to_order_items_table` (json nullable).
  2. `OrderItem` cast `serial_ids => array` + fillable.
  3. `OrderController::store/update` validate `items.*.serial_ids` + lưu.
  4. UI Orders/Create+Edit chọn `SerialImei` `where status='in_stock'` + `product.has_serial=true`.
  5. Test: convert đơn serial OK + lock serial in_stock.

---

## 8. Build / Test

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | DONE (cache/compiled/config/events/routes/views) |
| `php artisan route:list \| grep customers...debt-history\|returns.cancel\|damages.cancel\|orders.process` | 4/4 route hiện diện |
| `npm run build` | ✓ built in 6.43s, no Vite errors |
| `php artisan test --filter=RR06CustomerDebtLedgerTest` | 5/5 PASS |
| `php artisan test --filter=RR08OrderReturnSerialRollbackTest` | 4/4 PASS |
| `php artisan test --filter=RR09DamageStockTest` | 5/5 PASS |
| `php artisan test --filter=RR13OrderConvertStockTest` | 4/4 PASS |
| **Tổng targeted** | **18 passed (60 assertions) / 0 fail / 2.67s** |

Không chạy full 87 vì chỉ sửa UI + read-only enrich (không đụng business core). Phạm vi đảm bảo bởi 4 RR liên quan trực tiếp.

---

## 9. Manual test checklist

1. Customer debt history
   - [x] `/customers` → mở khách có công nợ → tab Công nợ hiển thị danh sách `customer_debts`.
   - [x] Cột Thời gian / Loại / Mã phiếu / Giá trị (color-coded) / Công nợ (color-coded).
   - [x] Khách chưa có row → empty state "Chưa có lịch sử công nợ".
2. Return serial
   - [x] `/returns` → mở phiếu có `serial_ids` → thấy `Serial/IMEI đã trả:` + badge.
   - [x] `/returns/{id}` (Show) hiển thị tương tự.
   - [x] Hủy phiếu (returns.cancel UI từ 22.1A) vẫn hoạt động.
3. Damage serial
   - [x] `/damages` → mở phiếu có `serial_ids` → thấy `Serial/IMEI xuất hủy:` + badge.
   - [x] Hủy phiếu (damages.cancel UI từ 22.1A) vẫn hoạt động.
4. Order process
   - [x] Đơn không serial → process OK, modal đóng.
   - [x] Đơn serial chưa có `serial_ids` → backend throw, modal hiện box đỏ + hint Serial/IMEI; không tạo invoice (test RR13 verified).

---

## 10. Rủi ro còn lại

- **Order serial selector chưa làm** → Step 22.1C (migration + cast + UI selector). Trước khi có 22.1C, đơn hàng có sản phẩm Serial/IMEI sẽ luôn fail khi process — đây là fail-safe đúng (RR-13), nhưng UX cần selector để usable.
- **Permission tách riêng:** UI hiện không gate quyền "hủy phiếu trả" / "hủy phiếu xuất hủy" / "xử lý đơn" theo role. Có thể bổ sung sau.
- **UI báo cáo ledger nâng cao (dual-role unified view):** Tab debt hiện chỉ hiển thị `customer_debts`. Khách dual-role (vừa KH vừa NCC) có lịch sử supplier riêng — đang xem qua `offsetHistoryData`. Cần view hợp nhất nếu nghiệp vụ yêu cầu.

---

## 11. Kết luận

- Step 22.1B chỉ chạm UI + read-only enrich. Build OK, 18 test target PASS, không đụng nghiệp vụ.
- **Chưa nên gom commit UI P3 ngay** — Step 22.1C (Order serial selector) đang treo và là phần quan trọng để đơn Serial/IMEI usable. Đề nghị làm 22.1C trước, sau đó commit gộp 22.1A + 22.1B + 22.1C thành 1 commit "UI P3 — action buttons + ledger/serial polish + order serial selector".
- Nếu yêu cầu commit ngay 22.1B, cần ghi rõ trong message rằng đơn Serial/IMEI vẫn chưa process được do thiếu selector (fail-safe đúng nhưng UX hạn chế).
