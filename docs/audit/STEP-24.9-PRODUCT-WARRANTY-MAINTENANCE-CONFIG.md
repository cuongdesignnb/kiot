# STEP 24.9 — Product Warranty & Maintenance Configuration

## 1. Root cause

- Product create/edit form chưa có tab "Bảo hành, bảo trì" như KiotViet.
- `WarrantyGenerationService` đã có (Step 23.7B) nhưng chỉ dùng `warranty_months` int đơn lẻ qua chuỗi fallback `invoice_items → products → purchase_items`. Không có cấu trúc nhiều mốc bảo hành (Toàn bộ sản phẩm / Pin / Sạc) và không có lịch bảo trì định kỳ.
- Khi sửa product sau khi đã bán, không có snapshot ⇒ thay đổi policy ngày hôm nay làm lệch hạn bảo hành của hóa đơn cũ.

## 2. KiotViet reference

| Thành phần | KiotViet | Hệ thống đã làm |
|---|---|---|
| Tab "Bảo hành, bảo trì" trong product form | ✓ | ✓ — Products/Edit.vue có 2 tab "Thông tin" / "Bảo hành, bảo trì" |
| Bảo hành nhiều mốc | ✓ (Toàn bộ / Pin / Sạc, mỗi mốc có thời hạn riêng) | ✓ |
| Bảo trì định kỳ nhiều mốc | ✓ | ✓ |
| Sửa lại policy ở product edit | ✓ | ✓ |
| Sinh warranty sau bán | ✓ | ✓ — `WarrantyGenerationService` ưu tiên policies, fallback legacy |
| Snapshot policy tại thời điểm bán | ✓ | ✓ — `warranty_policy_snapshot` + `maintenance_policy_snapshot` |

## 3. Business policy

| Mục | Rule |
|---|---|
| Product warranty config | Mảng JSON `warranty_policies = [{name, duration_value, duration_unit, is_default}]` — name required, duration_value ≥ 0, unit ∈ {day, month, year}. Nếu không có row nào `is_default`, row đầu tự động default. `warranty_months` derived từ default policy (back-compat fallback chain). |
| Product maintenance config | Mảng JSON `maintenance_policies = [{name, duration_value, duration_unit}]` — không bắt buộc. Row có duration ≤ 0 hoặc name rỗng bị strip. |
| Sale warranty generation | Ưu tiên `product.warranty_policies` (default policy → warranty_period + end_date). Nếu không có policies → fallback legacy chain (item / product.warranty_months / latest purchase_item). Không có gì → không sinh warranty. |
| Snapshot | Mỗi warranty record lưu `warranty_policy_snapshot` (toàn bộ array tại sale time) + `maintenance_policy_snapshot` + `next_maintenance_date` (ngày bán + first maintenance policy). Sửa product sau này không thay đổi snapshot. |
| Existing warranty | Không backfill, không động vào warranty cũ. Snapshot chỉ áp dụng cho warranty mới sinh từ thời điểm step 24.9 deploy. |

## 4. Schema

| Table | Field | Purpose |
|---|---|---|
| products | `warranty_months` (int nullable) | primary/maximum policy duration in months — back-compat với fallback chain hiện có |
| products | `warranty_policies` (json nullable) | `[{name, duration_value, duration_unit, is_default}]` |
| products | `maintenance_policies` (json nullable) | `[{name, duration_value, duration_unit}]` |
| warranties | `warranty_policy_snapshot` (json nullable) | snapshot tại thời điểm bán |
| warranties | `maintenance_policy_snapshot` (json nullable) | snapshot maintenance |
| warranties | `next_maintenance_date` (datetime nullable) | purchase_date + first maintenance interval |

Migration `2026_05_09_140000_add_warranty_maintenance_config_to_products_and_warranties.php` idempotent (`Schema::hasColumn` checks). Existing rows giữ NULL.

## 5. Backend changes

| File | Nội dung |
|---|---|
| `database/migrations/2026_05_09_140000_add_warranty_maintenance_config_to_products_and_warranties.php` | NEW — 3 cột vào products, 3 cột vào warranties |
| `app/Services/ProductWarrantyPolicyNormalizer.php` | NEW — `normalizeWarrantyPolicies`, `normalizeMaintenancePolicies`, `resolvePrimaryWarrantyMonths`, `durationInMonths`, `addDurationToDate` |
| `app/Models/Product.php` | Fillable + casts cho `warranty_months/policies` |
| `app/Models/Warranty.php` | Fillable + casts cho 3 snapshot fields |
| `app/Http/Controllers/ProductController.php` | Validation `warranty_policies.*` + `maintenance_policies.*` cho store + update; gọi normalizer; derive `warranty_months` từ primary policy |
| `app/Services/WarrantyGenerationService.php` | Ưu tiên `product.warranty_policies`; tính `end_date` qua `addDurationToDate`; persist snapshots + `next_maintenance_date`; legacy fallback giữ nguyên |

**Không sửa:** `InvoiceSaleService`, `MovingAvgCostingService`, `StockMovementService`, `CustomerDebtService`. Stock/cost/debt/cashflow/serial flow không đổi.

## 6. Frontend changes

| File | Nội dung |
|---|---|
| `resources/js/Pages/Products/Edit.vue` | Form thêm `warranty_months`, `warranty_policies`, `maintenance_policies`. Tab strip "Thông tin / Bảo hành, bảo trì". Tab Bảo hành: bảng warranty rows (name, duration_value, duration_unit, is_default radio, delete). Tab Bảo trì: bảng maintenance rows (name, duration, delete). "+ Thêm mốc" buttons. Helpers: `addWarrantyPolicy`, `removeWarrantyPolicy`, `setDefaultWarrantyPolicy`, `addMaintenancePolicy`, `removeMaintenancePolicy`. |

**Welcome.vue** product create modal: chưa update trong step này (backlog 24.9B). Form Edit là kênh chính để cấu hình; modal create-quick chỉ tạo product cơ bản, user vào Edit để cấu hình bảo hành — đúng UX KiotViet (Tạo nhanh / Tạo đầy đủ).

## 7. WarrantyGenerationService

| Case | Result |
|---|---|
| Product có warranty_policies | ✓ Dùng default policy → period + end_date; snapshot policies; tính next_maintenance_date |
| Product has serial | ✓ 1 warranty / serial (idempotent) |
| Product normal | ✓ 1 warranty / invoice item |
| Product không có policies, có warranty_months | ✓ Legacy fallback chain hoạt động |
| Product không có gì | ✓ Không sinh warranty |
| Fallback `purchase_items.warranty_months` | ✓ Vẫn hoạt động khi không có product policy |
| Product edited after sale | ✓ Snapshot bảo vệ — TC `product_update_does_not_mutate_existing_warranty` verify |

## 8. Tests

| Test | Result |
|---|---|
| Step249ProductWarrantyConfigTest::product_create_saves_warranty_policies | ✅ |
| ::product_update_saves_warranty_policies | ✅ |
| ::product_create_saves_maintenance_policies | ✅ |
| ::empty_warranty_rows_are_removed | ✅ |
| ::invalid_duration_unit_fails | ✅ |
| ::negative_duration_fails | ✅ |
| ::first_warranty_policy_becomes_default_if_none_selected | ✅ |
| Step249WarrantyGenerationFromProductPolicyTest::sale_generates_warranty_from_product_policy_for_normal_product | ✅ |
| ::sale_generates_one_warranty_per_serial | ✅ |
| ::sale_does_not_generate_warranty_when_no_policy | ✅ |
| ::sale_stores_warranty_policy_snapshot | ✅ |
| ::product_update_does_not_mutate_existing_warranty | ✅ |
| ::sale_stores_maintenance_policy_snapshot_and_next_maintenance_date | ✅ |

Cluster:
- Step249: ✅ **13 PASS** (37 assertions)
- Combined Step249 + Step237/Warranty + Step238D + RR02 + InvoiceUpdateEngine + Product: ✅ **117 PASS** (1086 assertions), 1 pre-existing skipped, 0 fail
- Wider regression (POS + Order + Purchase + Serial* + Step246* + ReturnFeeType + Step247 + Step248 + CustomerGroup + CustomerFiltersHotfix + Auth + Permission): ✅ **168 PASS** (678 assertions), 2 pre-existing skipped, 0 fail
- `npm run build`: ✅ 8.13s

## 9. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration không? | **Có** — 3 cột nullable vào products + 3 cột nullable vào warranties; idempotent |
| Có backfill không? | **Không** — existing rows giữ NULL |
| Có sửa warranty cũ không? | **Không** — snapshot chỉ áp cho warranty mới sinh |
| Có sửa stock/cost/debt không? | **Không** |
| Có ảnh hưởng POS không? | **Không** — POS sale flow không đổi |
| Có snapshot policy không? | **Có** — TC verify edit product không mutate warranty cũ |

## 10. Manual QA

- [ ] Vào `/products/{id}/edit` → tab "Bảo hành, bảo trì" hiện.
- [ ] "+ Thêm mốc" Bảo hành → row mới với "Toàn bộ sản phẩm" + 1 tháng + default.
- [ ] Lưu → reload page → row vẫn còn.
- [ ] Thêm mốc Pin 6 tháng → radio default chỉ chọn 1 row.
- [ ] "+ Thêm mốc" Bảo trì → "Vệ sinh định kỳ" 3 tháng.
- [ ] Bán product qua POS → `/warranties` có row mới với `warranty_period=12`, `end_date = sale + 12 tháng`.
- [ ] Sửa product sang 24 tháng → warranty đã sinh không đổi.
- [ ] Bán hóa đơn mới → warranty mới dùng 24 tháng.
- [ ] Product serial bán 2 serial → 2 warranty records.
- [ ] Product không có policy không sinh warranty.
- [ ] /pos, /invoices, /products không lỗi.

## 11. Backlog

- Welcome.vue quick-create modal: thêm tab Bảo hành (chưa có; user dùng Edit là kênh chính).
- Variant-level warranty policy.
- Maintenance reminder notification job.
- UI hiển thị snapshot policy trong `/warranties/{id}` show & print.
- Filter "Lịch bảo trì" theo `next_maintenance_date`.
- Warranty report by product/customer/expiry.

## 12. Conclusion

- **Đã giống KiotViet chưa:** Có ở mặt cấu trúc dữ liệu + Edit page tab. Quick-create modal trong Welcome.vue chưa có tab — backlog 24.9B.
- **Có an toàn production không:** Có — migration nullable, không backfill, không mutate warranty cũ (snapshot bảo vệ), không sửa core invoice/stock/cost/debt service.
- **Có thể deploy không:** Có — 13 hotfix cluster + 285 regression test pass, 0 fail.
