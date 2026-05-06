# STEP 23.8F — Repair UI Integration

> **Bước:** 23.8F — UI cho Repair flows mới (23.8A → 23.8E)
> **Ngày:** 06/05/2026
> **Phạm vi:** Frontend Vue/Inertia + 1 mở rộng nhỏ Api/TaskController@show. **Không sửa core service.**

---

## 1. Discovery

| UI | File | Hiện trạng | API backend | Thiếu gì |
|---|---|---|---|---|
| Tạo external repair | `resources/js/Pages/Tasks/Index.vue` | Chỉ có 'general' và 'repair' (internal). Không có option 'repair-external'. | `POST /api/tasks` đã hỗ trợ `external=true` từ Step 23.8A | Tab + form: customer search, customer_name, customer_phone, received_at |
| Thêm linh kiện thường | `Tasks/Show.vue` modal addPart | Đã có | `POST /api/tasks/{task}/parts` | OK |
| Thêm linh kiện serial | `Tasks/Show.vue` modal addPart | Không hỗ trợ chọn `serial_ids` | `POST /api/tasks/{task}/parts` đã accept `serial_ids` từ Step 23.8B | Load serials qua `/api/tasks/product-serials`, checkbox chọn, validate count |
| Hoàn thành sửa chữa | `Tasks/Show.vue` `markComplete()` | Chỉ confirm rồi gọi POST không payload — fail cho external | `POST /api/tasks/{task}/complete` đã accept full payload | Modal với labor_fee, part_prices, paid_amount, payment_method, note, warranty_policy |
| Lookup/attach warranty | Không có | — | `GET /api/tasks/lookup-warranty` + `POST /api/tasks/{task}/attach-warranty` từ Step 23.8D | Modal tra cứu + nút gắn |
| Bóc linh kiện thường | `Tasks/Show.vue` modal disassemble | Đã có nhưng không hiển thị `available_for_disassembly` | `POST /api/tasks/{task}/disassemble-part` | Hiển thị available, validate frontend |
| Bóc linh kiện serial | `Tasks/Show.vue` modal disassemble | Không cho nhập `serial_numbers` | `POST /api/tasks/{task}/disassemble-part` đã accept `serial_numbers` từ Step 23.8E | Input serial_numbers theo qty |
| Task detail display | `Tasks/Show.vue` Info tab | Không hiển thị customer, financial, warranty | API `show()` chưa load `customer/warranty/invoice` | Mở rộng API + 3 block UI (Customer / Tài chính / Bảo hành) |

---

## 2. UI implemented

### 2.1 External repair create

- **Kết quả:** Trong `Tasks/Index.vue`, dropdown "+ Tạo mới" thêm option **"Sửa chữa khách ngoài"**. Tabs trong modal có 3 lựa chọn (`general` / `repair` / `repair-external`).
- Form external: tìm khách hàng theo SĐT/tên qua `/api/pos/customers`, hoặc nhập snapshot `customer_name` + `customer_phone`. Có ngày tiếp nhận `received_at`. `issue_description` bắt buộc.
- Payload gửi `{ type: 'repair', external: true, customer_id?, customer_name, customer_phone?, issue_description, received_at?, ... }`.
- Submit button validate: external repair yêu cầu `customer_name + issue_description`; internal repair yêu cầu serial; general yêu cầu title.

### 2.2 Add repair part serial selector

- **Kết quả:** Khi chọn output product có `has_serial=true`, UI tự động load `/api/tasks/product-serials?product_id=X` và hiển thị danh sách checkbox.
- Counter `({{ partSelectedSerialIds.length }}/{{ partForm.quantity }})` trên label.
- Submit button disabled cho đến khi `selected.length === quantity`.
- Frontend guard: tự throw lỗi message rõ trước khi call API nếu thiếu serial. Backend vẫn validate (defense in depth).
- Empty state: nếu không có serial in_stock → hiển thị warning, không cho submit.

### 2.3 Complete repair modal

- **Kết quả:** Khi `task.external && task.type === 'repair'`, click "Hoàn thành" → mở modal lớn thay vì confirm đơn giản.
- Form gồm: `labor_fee`, `part_prices` (per `task_part export`), `warranty_policy` (none/free_labor/free_parts/full_free), `paid_amount`, `payment_method`, `note`.
- Computed live: `parts_total`, `gross_total`, `covered_amount`, `payable`, `debt`.
- Frontend guards:
  - `paid_amount > payable` → chặn.
  - `debt > 0 && !customer_id` → chặn (cần khách hàng).
  - Policy `free_*` chỉ enable khi `task.warranty_id != null && warranty_valid`. Nếu warranty hết hạn / chưa attach → option disabled + cảnh báo.
- Payload POST: `{ labor_fee, paid_amount, payment_method, note, part_prices, warranty_policy }`.

### 2.4 Warranty lookup / attach

- **Kết quả:** Block "Bảo hành" xuất hiện ở Info section khi `task.external && task.type === 'repair'`.
  - Nếu chưa gắn: "Chưa gắn bảo hành" + nút "Tra cứu / Gắn".
  - Nếu đã gắn: hiển thị `invoice_code`, product, serial, purchase_date, warranty_end_date, status (Còn hạn / Hết hạn).
- Modal lookup: input `serial_imei` hoặc `invoice_code` (≥1 trong 2). Gọi `GET /api/tasks/lookup-warranty`. Hiển thị list kèm flag `valid` từ backend, nút "Gắn" mỗi row.
- Attach: `POST /api/tasks/{task}/attach-warranty {warranty_id}`. Nếu serial mismatch / internal repair / completed → backend trả lỗi rõ.

### 2.5 Disassembly serial_numbers

- **Kết quả:** Trong modal "Bóc LK":
  - Hiển thị banner "Giá vốn còn khả dụng để bóc: X đ" (lấy từ `task.available_for_disassembly` mới được API show trả).
  - Nếu output product `has_serial=true` → block input serial_numbers theo `quantity`. `watch(quantity)` tự đồng bộ độ dài array.
  - Frontend guards: `serial_numbers.length === quantity`, không trùng nội bộ. Backend tiếp tục validate (chưa tồn tại DB, format).
- Payload với output có serial: `{ product_id, quantity, unit_cost, notes, serial_numbers: [...] }`. Output thường: không gửi `serial_numbers`.

### 2.6 Task detail display

- **Kết quả:** `API show()` mở rộng include: `customer:id,name,phone,code`, `warranty:...`, `warranty.product:id,name,sku`, `invoice:id,code,total,customer_paid,status`. Thêm `warranty_valid` flag và `available_for_disassembly` cho UI.
- Show.vue thêm 3 block khi `task.external && task.type === 'repair'`:
  - **Khách hàng:** name, phone, code, received_at, sub_status.
  - **Tài chính sửa chữa:** labor_fee, parts_total (gross), warranty_covered_amount, total_amount (payable), paid_amount, debt_amount, link tới hóa đơn nếu đã có `invoice_id`.
  - **Bảo hành:** warranty.invoice_code, product, serial_imei, purchase_date, warranty_end_date, status (Còn hạn/Hết hạn).
- Parts table bổ sung: hiển thị `Serial: N cái` nếu `serial_ids?.length`, hiển thị `Giá bán: ...đ` nếu `sale_price`. Nút "Gỡ" disabled khi `direction === 'import'` (Step 23.8E policy).

---

## 3. Files changed

| File | Nội dung |
|---|---|
| `app/Http/Controllers/Api/TaskController.php` | Mở rộng `show()` load `customer/warranty/warranty.product/invoice`; thêm `warranty_valid` + `available_for_disassembly` vào response |
| `resources/js/Pages/Tasks/Index.vue` | Thêm option "Sửa chữa khách ngoài", customer search, form fields cho external repair, submit logic |
| `resources/js/Pages/Tasks/Show.vue` | Toàn bộ phần UI: 3 block info external repair, serial selector trong addPart modal, serial_numbers input trong disassemble modal, complete repair modal đầy đủ, warranty lookup modal, parts table cải thiện |
| `docs/audit/STEP-23.8F-REPAIR-UI-INTEGRATION.md` | File này |

**Không sửa:**

- `app/Services/TaskService.php`, `MovingAvgCostingService`, `StockMovementService`, `SerialAvailabilityService`, `WarrantyGenerationService`, `InvoiceSaleService`.
- Models (`Task`, `TaskPart`, `Warranty`, `SerialImei`, `Product`).
- Migrations.
- Routes (mọi endpoint dùng đều đã có sẵn từ 23.8A → 23.8E).
- Tests cũ (RR07, Step238A/B/C/D/E, Step237B/W).

---

## 4. API changes if any

| Endpoint | Thay đổi |
|---|---|
| `GET /api/tasks/{task}` (Api\TaskController@show) | Bổ sung eager load: `customer`, `warranty`, `warranty.product`, `invoice`; thêm vào response 2 field tính sẵn: `warranty_valid` (bool), `available_for_disassembly` (number\|null) |

Không endpoint mới. Không sửa controller method khác. Không thay đổi auth / middleware / route.

---

## 5. Build/Test

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ DONE 6/6 |
| `npm run build` (Vite) | ✅ Built in 5.99s |

### Regression

| Cluster | Tests | Result |
|---|---:|---|
| `Step238E\|Step238D\|Step238C\|Step238B\|Step238A\|RR07\|Task\|Repair\|Warranty` | 73 | ✅ 73 PASS (229 assertions) |
| `RR06\|RR08\|RR09\|RR11\|RR12\|RR13\|SerialAvailability\|RequireSerial\|CustomerSearch\|Order\|Purchase\|PurchaseReturn\|StockTake\|StockTransfer\|Damage` | 140 + 2 skipped | ✅ 140 PASS (480 assertions) |
| `Step232\|Step233\|Step234\|Step235\|Step236\|Step237` | 87 | ✅ 87 PASS (298 assertions) |
| `RR02InvoicePosCharacterizationTest` (chạy riêng) | 5 | ✅ 5 PASS (48 assertions) |

**Tổng:** 305 PASS, 0 FAIL, 2 skipped, ~1055 assertions.

Không hồi quy do 23.8F. Backend không bị động đến. API show mở rộng response — backward-compatible (chỉ thêm field, không bỏ field cũ).

---

## 6. Manual QA checklist

- [ ] Trang `/tasks` — bấm "+ Tạo mới" → "Sửa chữa khách ngoài" → form load đúng tab.
- [ ] Tìm khách qua input tên/SĐT → suggest list, click select → fill `customer_name`/`customer_phone`.
- [ ] Hoặc nhập tay `customer_name` (không cần `customer_id`) — submit OK.
- [ ] Sau tạo → vào `/tasks/{id}` → 3 block "Khách hàng / Tài chính / Bảo hành" hiển thị đúng.
- [ ] Click "+ Lắp LK" → chọn linh kiện thường → flow cũ vẫn OK.
- [ ] Click "+ Lắp LK" → chọn linh kiện `has_serial=true` → checkbox list serials in_stock load đúng. Chọn thiếu → submit disabled. Chọn đủ → POST OK.
- [ ] Click "↑ Bóc LK" → banner "Giá vốn còn khả dụng" hiển thị.
- [ ] Bóc output thường → flow cũ OK.
- [ ] Bóc output `has_serial=true` → input serial_numbers xuất hiện theo qty. Trùng/thiếu → frontend chặn.
- [ ] Block bảo hành: click "Tra cứu / Gắn" → modal mở.
- [ ] Tra cứu theo serial_imei của HĐ bán → list trả về với flag Còn hạn/Hết hạn.
- [ ] Click "Gắn" → POST attach OK → modal đóng → block bảo hành cập nhật.
- [ ] Serial mismatch → backend trả 422, hiển thị error rõ.
- [ ] Click "Hoàn thành" trên external repair → mở modal complete (không phải confirm đơn giản).
- [ ] Modal: nhập labor_fee, part_prices, warranty_policy = `free_labor` → covered hiển thị = labor, payable = parts. Đặt paid = payable → debt = 0.
- [ ] Policy `free_parts` → covered = parts, payable = labor.
- [ ] Policy `full_free` → payable = 0, paid_amount tự cap, không cashflow / không debt được tạo.
- [ ] Khi chưa gắn warranty hoặc warranty hết hạn → policy `free_*` disabled trong dropdown.
- [ ] Debt > 0 mà task chưa có customer_id → frontend chặn submit.
- [ ] Submit OK → task chuyển `completed`, modal đóng, block tài chính cập nhật, link đến hóa đơn xuất hiện.
- [ ] Verify backend: stock_movements không phát sinh khi complete (đã verify Step 23.8C). Serial linh kiện vẫn `used_for_repair`. Serial máy gốc (nếu đã bóc) vẫn `dismantled`.
- [ ] Sửa chữa nội bộ cũ (không external): flow internal repair RR-07 vẫn chạy bình thường.

---

## 7. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | ❌ Không |
| Có sửa core service không? | ❌ Không (chỉ mở rộng `Api\TaskController@show` để load thêm relation cho UI hiển thị) |
| Có tự chọn serial không? | ❌ Không (UI bắt buộc user chọn / nhập) |
| Có thay đổi tồn kho từ UI ngoài API đã kiểm soát không? | ❌ Không (mọi mutation đều qua endpoint đã được verify ở 23.8B/C/E) |
| Có ảnh hưởng flow cũ không? | ❌ Không. RR-07/Step238A/B/C/D/E + RR-02/06/08-13 + module suite + Step232-237 đều PASS |
| Có tạo invoice/cashflow/debt mới ngoài endpoint complete đã kiểm soát không? | ❌ Không |

---

## 8. Backlog

| # | Mục | Mức |
|---|---|---|
| 1 | UI nhập serial output cho `disassembly` dạng dropdown thay vì text | P3 |
| 2 | UI hiển thị tồn kho output product trước khi bóc (gợi ý serial format từ SKU) | P3 |
| 3 | Permission tách: `tasks.attach_warranty`, `tasks.complete_repair`, `tasks.disassemble` | P3 |
| 4 | Báo cáo chi phí bảo hành/sửa chữa (sum `warranty_covered_amount`, `parts_cost`) | P3 |
| 5 | Serial transfer chi tiết (link giữa serial input dismantled và serial output) | P3 |
| 6 | Final full regression audit toàn bộ test suite (incl. cluster lớn cùng lúc) | P2 |
| 7 | Modal complete repair: gợi ý retail_price khi chưa có sale_price | P3 |
| 8 | UI hiển thị `serial_imei text` (chuỗi khách hàng cung cấp khi đem máy đến) — hiện chưa có cột riêng trên `tasks`, đang dùng `issue_description` | P3 (cần migration nhỏ nếu muốn tách) |

---

## 9. Conclusion

| Câu hỏi | Trả lời |
|---|---|
| UI repair mới đã dùng được chưa? | ✅ Có. 6/6 luồng (create external / add part with serial / disassemble with serial / complete với policy / warranty lookup-attach / detail display) đã wire vào backend hiện có. |
| Có thể deploy production không? | ✅ Có. Không migration, không sửa core service, 305 regression tests vẫn PASS. Chỉ cần `git pull && composer dump-autoload && npm run build && php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan queue:restart` rồi restart php-fpm. |

---

## 10. Tài liệu liên quan

| File | Vai trò |
|---|---|
| `AGENT_RULES.md` | Bộ luật bắt buộc — task này tuân thủ mục 1.10 (không format file không liên quan) |
| `docs/audit/STEP-23.8A-EXTERNAL-REPAIR-TICKET.md`, `STEP-23.8B-...`, `23.8C-...`, `23.8D-...`, `23.8E-...` | Backend đã làm (UI dùng các endpoint từ đó) |
| `docs/audit/STEP-23.8F-REPAIR-UI-INTEGRATION.md` | File này |
| `app/Http/Controllers/Api/TaskController.php` | API mở rộng `show()` |
| `resources/js/Pages/Tasks/Index.vue` | Tạo external repair |
| `resources/js/Pages/Tasks/Show.vue` | Toàn bộ UI repair flows mới |
