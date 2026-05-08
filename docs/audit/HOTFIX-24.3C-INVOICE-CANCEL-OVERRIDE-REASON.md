# HOTFIX 24.3C — Invoice Cancel Override Reason Modal

## 1. Root cause

- **Frontend before:** `Invoices/Index.vue` cancel handler used `window.confirm()` only, then issued `router.delete('/invoices/{id}')` with no body. There was no UI for `time_lock_override_reason`.
- **Backend rule (Step 24.3, unchanged):** `InvoiceController@destroy`
  - Computes `lockRef = lock_started_at ?? created_at`. If `now − lockRef > order_change_time` (default 24h), the invoice is "time-locked".
  - Time-locked + user lacks `invoices.override_time_lock` → reject with "Đã quá thời gian cho phép hủy hóa đơn (… giờ). Cần quyền override."
  - Time-locked + user has override perm + reason missing/<5 chars → reject with "Cần nhập lý do override (ít nhất 5 ký tự)."
  - E-invoice block: if `block_edit_cancel_einvoice` setting is on and `einvoice_code` exists → reject regardless of override.
- **Why user thấy lỗi:** native confirm có OK/Cancel, không có ô textarea cho lý do, nên user override luôn submit thiếu reason → backend luôn từ chối.

## 2. Business rule

| Case | UI | Backend |
|---|---|---|
| Recent invoice (within order_change_time) | Modal warning, không bắt nhập lý do, nút Xác nhận hủy active | `destroy` skip override branch, cancel proceeds |
| Old invoice, user thiếu `invoices.override_time_lock` | `cancel_block_reason` populated, modal show banner đỏ, nút disable | `destroy` returns flash error "Đã quá thời gian cho phép hủy hóa đơn" |
| Old invoice + user có override + reason missing/<5 chars | UI yêu cầu textarea ≥5 chars, nút disable nếu thiếu | `destroy` returns flash error "Cần nhập lý do override" — chỉ chạy khi user vẫn cố submit (defence-in-depth) |
| Old invoice + override + reason ≥5 chars | Modal cho phép submit | `destroy` reverses stock/serial/debt/cashflow + audit log `ACTION_INVOICE_CANCEL_TIME_LOCK_OVERRIDE` chứa reason |
| E-invoice (status block on + einvoice_code) | UI: `cancel_block_reason` hiển thị, nút disable | `destroy` rejects bất kể override/reason |

## 3. Files changed

| File | Nội dung |
|---|---|
| `app/Http/Controllers/InvoiceController.php` | `index()` enrich mỗi invoice trong paginator với 7 hint cho UI: `is_time_locked`, `lock_age_hours`, `order_change_time_hours`, `can_override_time_lock`, `requires_override_reason`, `cancel_block_reason`, `can_cancel`. Logic `destroy()` không đổi — vẫn enforce time-lock + override + reason ≥5 chars + e-invoice block. |
| `resources/js/Pages/Invoices/Index.vue` | Replace `window.confirm` + bare `router.delete` bằng modal "Hủy hóa đơn" với state `showCancelModal`/`cancellingInvoice`/`cancelReason`/`cancelError`/`cancelSubmitting`. Hiển thị mã/khách/tổng/trạng thái + warning đảo tồn kho + banner block (nếu `cancel_block_reason`) hoặc ô textarea reason (nếu `requires_override_reason`). Submit gửi `data: { time_lock_override_reason }` qua Inertia delete; surface backend flash error trong modal. |
| `tests/Feature/Invoices/Step243CInvoiceCancelOverrideModalTest.php` | NEW — 7 test cases (1 skipped khi schema không có `einvoice_code`) |
| `docs/audit/HOTFIX-24.3C-INVOICE-CANCEL-OVERRIDE-REASON.md` | NEW — file này |

**Không sửa:** `MovingAvgCostingService`, `CustomerDebtService`, `StockMovementService`, `ActivityLog`, schema, time lock logic, e-invoice block, business invariants Step 24.3.

## 4. Payload

| Field | Khi nào gửi |
|---|---|
| `time_lock_override_reason` | Chỉ gửi khi `invoice.requires_override_reason === true` (UI side). Backend tự skip nếu invoice chưa quá hạn. |

Submit:
```js
router.delete(`/invoices/${id}`, {
  data: inv.requires_override_reason ? { time_lock_override_reason: reason.trim() } : {},
  preserveScroll: true,
  onError, onSuccess, onFinish,
});
```

## 5. Tests

| Test | Result |
|---|---|
| TC-01 `cancel_recent_invoice_does_not_require_override_reason` | ✅ |
| TC-02 `cancel_old_invoice_without_override_permission_is_blocked` (assert flash chứa "quyền override") | ✅ |
| TC-03 `cancel_old_invoice_with_override_permission_requires_reason` | ✅ |
| TC-04 `cancel_old_invoice_with_override_permission_and_reason_succeeds` (+ ActivityLog có reason) | ✅ |
| TC-05 `cancel_override_reason_min_5_chars` | ✅ |
| TC-06 `invoices_index_exposes_cancel_policy_fields` (Inertia props có 7 hint) | ✅ |
| TC-07 `einvoice_block_prevents_cancel_even_with_override_reason` | ⏸ skipped — schema này không có `einvoice_code` column |

Cluster check:
- Step243C + hotfix related (CancelInvoice + InvoiceUpdateEngine + RR02 + Step246 + OrderReturn + RR08 + RR11 + Step244A + Step245): **105 PASS** (669 assertions), 2 pre-existing skipped.
- Broad regression (Step232–242, RR06/09/12/13, Warranty, Order, Purchase, Stock*, Damage, Dashboard, ActivityLog, Permission, Auth): **270 PASS** (1605 assertions), 2 pre-existing skipped, **0 fail**.

## 6. Production safety

| Mục | Trạng thái |
|---|---|
| Có bỏ time lock không? | **Không** |
| Có bỏ reason không? | **Không** — UI yêu cầu ≥5 chars; backend cũng vẫn enforce |
| Có mở hủy cho mọi user không? | **Không** — middleware `permission:invoices.cancel` giữ nguyên; override vẫn cần `invoices.override_time_lock` |
| Có sửa stock/debt/serial/cost không? | **Không** |
| Có ảnh hưởng Customers/POS không? | **Không** — chỉ sửa Invoices/Index.vue + InvoiceController@index |
| Có migration không? | **Không** |
| Có hardcode permission name client-side không? | **Không** — backend trả `can_override_time_lock` boolean trong props |

## 7. Manual QA

- [ ] Recent invoice cancel OK — modal warning hiện, không bắt lý do.
- [ ] Old invoice no override blocked — modal hiển thị banner đỏ với reason cụ thể, nút disabled.
- [ ] Old invoice override requires reason — textarea bắt buộc, ≥5 chars.
- [ ] Reason < 5 chars: nút submit không gửi (UI guard); nếu cố gắng bypass thì backend vẫn reject.
- [ ] Reason valid → cancel OK → tồn/serial/công nợ/cashflow đảo đúng.
- [ ] ActivityLog `invoice_cancel_time_lock_override` có `reason` trong properties.
- [ ] E-invoice (nếu schema có): block vẫn áp dụng dù có override.
- [ ] `/customers` OK (24.4A-* hotfix giữ nguyên).
- [ ] POS bán hàng OK (Step 24.6 quick return giữ nguyên).
- [ ] `/invoices` load OK với cột Hủy hoạt động đúng cho admin và non-admin.

## 8. Conclusion

- UI đã đúng backend rule chưa: **Có** — UI và backend share cùng quyết định (3 đầu vào: is_time_locked, can_override_time_lock, einvoice block); UI là defence-in-depth, backend vẫn là source of truth.
- An toàn production: **Có** — không migration, không backfill, không đổi logic cancel. Reason validation và time-lock guard giữ nguyên 100%.
- Có thể commit/deploy không: **Có** — 6 hotfix test pass, 105 cluster pass, 270 regression pass, 0 fail, build pass.
