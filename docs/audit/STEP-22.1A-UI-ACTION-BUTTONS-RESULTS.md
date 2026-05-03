# STEP-22.1A — UI Action Buttons for Cancel/Process Routes

> **Bước:** 22.1A — Thêm UI thao tác cho 3 backend route đã có (P3 backlog)
> **Ngày:** 03/05/2026
> **Phạm vi:** Chỉ UI Vue/Inertia. **Không sửa Controller, Service, Model, Migration, audit tests.**

---

## 1. Mục tiêu

Thêm UI gọi 3 backend route đã được đăng ký từ audit:

| # | Route | Mục đích |
|---|---|---|
| 1 | `returns.cancel` | Hủy phiếu trả hàng khách (rollback tồn/cost/serial/công nợ) |
| 2 | `damages.cancel` | Hủy phiếu xuất hủy (rollback tồn/cost/serial) |
| 3 | `orders.process` | Chuyển Đơn đặt hàng → Hóa đơn (trừ kho, tạo CashFlow) |

---

## 2. File đã sửa

| File | Loại | Nội dung sửa |
|---|---|---|
| `resources/js/Pages/Returns/Index.vue` | Vue (Inertia page) | Thêm hàm `cancelReturn(ret)` + nút "Hủy phiếu" trong action group của expanded row, ẩn khi `ret.status === 'Đã hủy'`. |
| `resources/js/Pages/Returns/Show.vue` | Vue (Inertia page) | Thêm hàm `cancelReturn()` + nút "Hủy phiếu trả hàng" cạnh nút "In" ở header, ẩn khi status là `'Đã hủy'` hoặc `'cancelled'`. |
| `resources/js/Pages/Damages/Index.vue` | Vue (Inertia page) | Thêm hàm `cancelDamage(damage)`. Wire nút "Hủy" sẵn có (trước đây chỉ là static button) thành nút submit thực, ẩn khi `damage.status === 'cancelled'`. Đổi label thành "Hủy phiếu" + style đỏ. |

**Không sửa:**

- `resources/js/Pages/Orders/Index.vue` — Đã có sẵn nút "Xử lý đơn hàng" (line 1648-1655) wire đúng route `/orders/{id}/process` qua modal `openProcessModal` + `submitProcessOrder`. Modal có form input `amount_paid`/`payment_method` đóng vai trò confirm. Status guard sẵn có: `v-if="order.status === 'draft' || order.status === 'confirmed'"`.
- Bất kỳ file Controller / Service / Model / Migration / audit test nào.

---

## 3. UI Discovery

| Module | File UI | Route backend | Hiện có nút chưa | Status được phép thao tác | Ghi chú |
|---|---|---|---|---|---|
| OrderReturn | `resources/js/Pages/Returns/Index.vue`, `resources/js/Pages/Returns/Show.vue` | `returns.cancel` (POST `/returns/{return}/cancel`) | ❌ Chưa có (chỉ "Lưu", "In") | Status `≠ 'Đã hủy'` (controller dùng tiếng Việt) | DB lưu status tiếng Việt; Show.vue có map English fallback |
| Damage | `resources/js/Pages/Damages/Index.vue` | `damages.cancel` (POST `/damages/{damage}/cancel`) | ⚠️ Có button "Hủy" tĩnh nhưng chưa wire | Status `≠ 'cancelled'` (DamageStatus::CANCELLED) | Nút "Hủy" sẵn có nhưng không có click handler |
| Order | `resources/js/Pages/Orders/Index.vue` | `orders.process` (POST `/orders/{order}/process`) | ✅ Có nút "Xử lý đơn hàng" + modal đầy đủ | Status `'draft'` hoặc `'confirmed'` (loại `completed`/`cancelled`/`ended`) | Modal-based confirm với amount_paid + payment_method |

### Frontend stack

- **Inertia + Vue 3** (script setup, `@inertiajs/vue3`).
- Layout: `AppLayout.vue`.
- Form submit: `router.post(...)` từ `@inertiajs/vue3` (CSRF + session flash tự động).
- Confirm: `window.confirm(...)` native (project chưa có SweetAlert wrapper chung).
- Build tool: Vite (`npm run build`).

---

## 4. UI đã thêm

| Module | Nút | Route gọi | Method | Confirm | Điều kiện hiển thị |
|---|---|---|---|---|---|
| OrderReturn — Index (expanded row) | "Hủy phiếu" (đỏ) | `/returns/{id}/cancel` | POST (qua `router.post`) | `confirm("Bạn chắc chắn muốn hủy phiếu trả hàng này? Hệ thống sẽ rollback tồn kho, công nợ và serial đã trả.")` | `ret.status !== 'Đã hủy'` |
| OrderReturn — Show (header) | "Hủy phiếu trả hàng" (đỏ) | `/returns/{id}/cancel` | POST (qua `router.post`) | `confirm("Bạn chắc chắn muốn hủy phiếu trả hàng này? Hệ thống sẽ rollback tồn kho, công nợ và serial đã trả.")` | `returnOrder.status !== 'Đã hủy' && returnOrder.status !== 'cancelled'` |
| Damage — Index (expanded row) | "Hủy phiếu" (đỏ) | `/damages/{id}/cancel` | POST (qua `router.post`) | `confirm("Bạn chắc chắn muốn hủy phiếu xuất hủy này? Hệ thống sẽ rollback tồn kho và serial đã xuất hủy.")` | `damage.status !== 'cancelled'` |
| Order — Index (giữ nguyên) | "Xử lý đơn hàng" (xanh lá) | `/orders/{id}/process` | POST qua modal | Modal `Xử lý đơn hàng → Hóa đơn` với input `amount_paid` + `payment_method` + nút submit | `order.status === 'draft' \|\| order.status === 'confirmed'` |

### Đặc tính chung

1. **CSRF + session:** `router.post()` của Inertia tự thêm CSRF token và xử lý flash session.
2. **Không tự logic backend:** UI chỉ gọi route, mọi guard nghiệp vụ (idempotent cancel, FK, fail-safe serial) vẫn nằm ở Controller.
3. **`preserveScroll: true`** giữ vị trí cuộn sau khi submit.
4. **Status check phía UI là cosmetic** — backend luôn re-check (xem `OrderReturnController@cancel` line 390, `DamageController@cancel` line 178, `OrderController@processOrder` line 321-327) → idempotent kể cả khi UI bypass.

---

## 5. Build / Test

### Lệnh đã chạy

```
php artisan optimize:clear   → DONE (cache/compiled/config/events/routes/views)
php artisan route:list | grep -E "returns.cancel|damages.cancel|orders.process"   → 3/3 route OK
npm run build                → ✓ built in 6.85s (Vite, không lỗi)
```

### Test backend liên quan (chỉ chạy 3 suite trực tiếp dùng các route đã thêm UI)

| Suite | Tests | Assertions | Result |
|---|---:|---:|---|
| `RR08OrderReturnSerialRollbackTest` | 4 | 15 | ✅ 4 PASS |
| `RR09DamageStockTest` | 5 | 12 | ✅ 5 PASS |
| `RR13OrderConvertStockTest` | 4 | 19 | ✅ 4 PASS |
| **Tổng** | **13** | **46** | ✅ **13 PASS, 0 FAIL** |

Không chạy full 87 tests vì task chỉ sửa view/script, không đụng business logic. Smoke test trên 3 route trực tiếp đủ chứng minh không hồi quy.

---

## 6. Manual test checklist (cho chủ dự án)

### A. OrderReturn — Hủy phiếu trả hàng

- [ ] Vào `/returns` → mở rộng (click) 1 phiếu trả hàng có status `≠ 'Đã hủy'`.
- [ ] Thấy nút **"Hủy phiếu"** (viền đỏ chữ đỏ) cạnh nút "Lưu" và "In".
- [ ] Click nút → xuất hiện confirm dialog "Bạn chắc chắn muốn hủy phiếu trả hàng này?...".
- [ ] **Cancel** confirm → không request gì, dữ liệu không đổi.
- [ ] **OK** confirm → POST `/returns/{id}/cancel` → trang reload, status hiển thị "Đã hủy".
- [ ] Mở rộng lại → nút "Hủy phiếu" **không còn hiện**.
- [ ] Vào `/returns/{id}/show` → cũng có nút "Hủy phiếu trả hàng" ở header (cạnh nút In), behavior tương tự.
- [ ] Sau khi hủy, kiểm tra:
  - Tồn kho sản phẩm trên phiếu rollback đúng (giảm về số trước khi tạo phiếu trả).
  - Serial/IMEI quay lại `status='sold'`, `invoice_id=$return->invoice_id` (rollback chính xác theo `serial_ids` đã lưu — RR-08).
  - `customer_debts` có row reversal (RR-06).
  - `stock_movements` có dòng `out_invoice` ghi `'Hủy phiếu trả hàng <code>'`.

### B. Damage — Hủy phiếu xuất hủy

- [ ] Vào `/damages` → mở rộng 1 phiếu Damage có status `≠ 'cancelled'`.
- [ ] Thấy nút **"Hủy phiếu"** (viền đỏ chữ đỏ).
- [ ] Click → confirm dialog "Bạn chắc chắn muốn hủy phiếu xuất hủy này?...".
- [ ] OK → POST `/damages/{id}/cancel` → trang reload, status thành "Đã hủy".
- [ ] Nút "Hủy phiếu" không còn hiện.
- [ ] Sau hủy, verify:
  - Tồn kho phục hồi (RR-09).
  - Serial nếu có: `status='in_stock'`, `damaged_at=null` (rollback chính xác theo `serial_ids` đã snapshot).
  - `stock_movements` ghi 1 dòng đảo.

### C. Order — Xử lý đơn hàng (đã có sẵn — verify không hồi quy)

- [ ] Vào `/orders` → mở rộng 1 đơn hàng status `draft` hoặc `confirmed`.
- [ ] Thấy nút **"Xử lý đơn hàng"** (xanh lá).
- [ ] Click → mở modal "Xử lý đơn hàng → Hóa đơn" với input `amount_paid` + chọn payment_method.
- [ ] Submit → POST `/orders/{id}/process` → tạo Invoice, đơn chuyển status `completed`.
- [ ] Nút "Xử lý đơn hàng" **không còn hiện** (vì status `completed` không thuộc `draft|confirmed`).
- [ ] Verify:
  - Tồn kho sản phẩm giảm đúng qua `MovingAvgCostingService::applySale` (RR-13).
  - `stock_movements` ghi `out_invoice`.
  - Nếu order có sản phẩm serial mà OrderItem chưa có serial_ids → backend trả error fail-safe, không tạo invoice rỗng (RR-13 fail-safe).

### D. Test idempotent (đề phòng spam click)

- [ ] Cancel 1 phiếu trả hàng → thành công.
- [ ] Reload → status đã chuyển "Đã hủy", nút "Hủy phiếu" mất.
- [ ] (Nếu cố gọi lại qua DevTools) backend trả flash error "Phiếu trả hàng đã bị hủy trước đó." — không double-rollback.

---

## 7. Rủi ro còn lại (P3 backlog, không chặn commit)

| # | Khu vực | Mô tả | Mức độ |
|---|---|---|---|
| 1 | Serial selector cho Order chuyển Invoice | Order với sản phẩm `has_serial` mà OrderItem chưa lưu `serial_ids` → backend fail-safe trả error. Cần UI cho user chọn serial trên Order trước khi convert. | P3 |
| 2 | Tab lịch sử công nợ KH | Chưa có UI hiển thị `customer_debts` ledger (dữ liệu đã có sẵn từ RR-06). | P3 |
| 3 | Permission tách | `returns.cancel` đang dùng chung permission `returns.create`; `damages.cancel` dùng chung `damages.create`. Có thể tách ra `returns.cancel` / `damages.cancel` riêng. | P3 |
| 4 | UI hiển thị `serial_ids` đã trả | Trang `Returns/Show` chưa hiển thị danh sách serial đã trả ở mỗi item. | P3 |
| 5 | Toast / SweetAlert | Confirm hiện đang dùng `window.confirm` native. Có thể nâng cấp dùng SweetAlert/toast cho UX đẹp hơn. | P3 |
| 6 | Show.vue cho Damage | `Pages/Damages/` chỉ có `Index.vue` + `Create.vue`, chưa có `Show.vue` riêng. | P3 |
| 7 | Status display nhất quán | Show.vue map English (`completed`/`cancelled`/`pending`) nhưng DB lưu Vietnamese (`Đã hủy`, `Đã trả`). UI hiện fallback `|| returnOrder.status` nên vẫn render được — nhưng nên thống nhất sau. | P3 |

---

## 8. Kết luận

✅ **UI buttons đã thêm đúng yêu cầu, an toàn để commit.**

| Câu hỏi | Trả lời |
|---|---|
| UI buttons đã thêm chưa? | ✅ Có (3/3 route đều có entry point UI: Returns/Index, Returns/Show, Damages/Index; Orders đã có sẵn — verified không sửa). |
| Có đụng business logic không? | ❌ Không. Chỉ sửa Vue script + template. Không sửa Controller/Service/Model/Migration/audit tests. |
| 3 audit test trực tiếp vẫn PASS? | ✅ 13/13 PASS (RR-08 + RR-09 + RR-13). |
| Vite build OK? | ✅ Built in 6.85s, không có warning compile. |
| Routes vẫn đăng ký đúng? | ✅ `route:list` xác nhận đủ 3 route. |
| Có an toàn commit không? | ✅ An toàn. Phạm vi sửa nằm trong cho phép của task UI P3. |

### Điểm cần lưu ý khi deploy production

- Sau khi pull về production phải chạy `npm run build` rồi restart php-fpm (hoặc clear opcache) để Vite chunk mới được phục vụ.
- Không cần migration / không đụng DB.
- Không cần re-run full audit tests trên CI (đã có 13 tests trực tiếp PASS local).

---

## 9. Tài liệu liên quan

| File | Vai trò |
|---|---|
| `AGENT_RULES.md` | Bộ luật bắt buộc — task này tuân thủ mục 1.10 (không format hàng loạt), 5 (idempotent cancel — backend đã có) |
| `docs/audit/RR-08-CLOSURE-REPORT.md` | RR-08 — backend rollback serial cho returns.cancel (đã closed) |
| `docs/audit/RR-09-CLOSURE-REPORT.md` | RR-09 — backend rollback cho damages.cancel (đã closed) |
| `docs/audit/RR-13-CLOSURE-REPORT.md` | RR-13 — backend orders.process (đã closed) |
| `routes/web.php` | 3 route đã đăng ký (line 483, 592, 604) |
| `app/Http/Controllers/OrderReturnController.php` | Method `cancel()` (line 388) — guard idempotent + rollback |
| `app/Http/Controllers/DamageController.php` | Method `cancel()` (line 176) — guard idempotent + rollback |
| `app/Http/Controllers/OrderController.php` | Method `processOrder()` (line 319) — guard status + chuyển Invoice |
