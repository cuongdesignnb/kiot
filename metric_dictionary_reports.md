# Metric Dictionary — Báo cáo / Phân tích

> **Mục tiêu**: Mỗi chỉ tiêu báo cáo chỉ có **1 định nghĩa duy nhất, 1 công thức duy nhất, 1 nguồn SQL duy nhất**. Mọi controller/màn hình phải gọi cùng một helper/query-builder. Đây là nguồn sự thật (single source of truth) cho toàn bộ module báo cáo.
>
> Nếu cần thay đổi công thức, phải cập nhật file này trước, rồi mới sửa code.

---

## 0) Quy ước chung (Global Conventions)

### 0.1 Scope thời gian (Date Scope)
- Toàn bộ metric về **bán hàng / doanh thu** dùng `invoices.created_at` (ngày ghi nhận hóa đơn).
- Toàn bộ metric về **trả hàng** dùng `returns.created_at`.
- Toàn bộ metric về **nhập hàng** dùng `purchases.purchase_date` (nếu null fallback `created_at`).
- Toàn bộ metric về **thu chi** (`cash_flows`) dùng `cash_flows.time` nếu có, fallback `created_at`.
- Mọi khoảng thời gian phải inclusive: `[startOfDay, endOfDay]` — dùng `whereBetween`.

### 0.2 Loại trừ trạng thái hủy (Status Exclusion)
- Hóa đơn: loại trừ `status = 'Đã hủy'`
- Phiếu trả: loại trừ `status = 'Đã hủy'`
- Phiếu nhập: chỉ lấy `status = 'completed'`
- Phiếu thu/chi: hiện tại không có trạng thái hủy; nếu thêm sau, phải cập nhật dictionary này.

### 0.3 Scope chi nhánh (Branch Scope)
- Nếu filter có `branch_id`, áp dụng `where('branch_id', $branchId)` cho mọi bảng gốc (invoices, returns, purchases, cash_flows). Nếu bảng không có `branch_id`, bỏ qua (không được silent-fallback về "tất cả chi nhánh").

### 0.4 Cột tiền trên `invoices` (ý nghĩa chuẩn)
| Cột | Ý nghĩa | Đẳng thức bắt buộc |
|---|---|---|
| `subtotal` | Tổng tiền hàng TRƯỚC giảm giá hóa đơn | `subtotal = SUM(items.quantity * items.price)` |
| `discount` | Giảm giá ở cấp hóa đơn (header) | ≥ 0 |
| `other_fees` | Phụ phí (không phải doanh thu hàng) | ≥ 0 |
| `total` | Khách phải trả cuối cùng | `total = subtotal - discount + other_fees + delivery_fee` |

→ **Doanh thu gross** (tiền hàng) = `subtotal`. **Không dùng `total`** cho doanh thu vì `total` đã trừ discount.

### 0.5 Cột tiền trên `returns` (ý nghĩa chuẩn)
| Cột | Ý nghĩa |
|---|---|
| `subtotal` | Giá trị hàng trả TRƯỚC giảm giá |
| `discount` | Giảm giá trên phiếu trả |
| `fee` | Phí trả hàng (khách phải chịu) |
| `total` | Số tiền shop trả lại khách |

→ **Giá trị hàng bán bị trả lại** = `returns.subtotal` (đối xứng với doanh thu gross).

### 0.6 Giá vốn (Cost basis)
- Ưu tiên: `invoice_items.cost_price` (snapshot tại thời điểm bán) với `NULLIF(.., 0)`.
- Fallback: `products.cost_price` (giá vốn master hiện tại).
- Cuối cùng: `0` (phải log cảnh báo — product không có cost_price).
- Với hàng trả: dùng `return_items.import_price` (đã snapshot lúc trả).

### 0.7 Nguyên tắc bất biến (Invariants)
1. **Đối xứng doanh thu - trả hàng**: nếu doanh thu lấy gross thì trả hàng cũng lấy gross; nếu lấy net thì cũng lấy net.
2. **COGS phải khớp với lưu chuyển kho**: COGS bán ra − COGS hàng trả = COGS thuần. Không được cộng `purchases.other_costs_total` vào COGS (other_costs đã capitalize vào giá vốn kho khi nhập).
3. **Một metric = một query**: không được mỗi controller tự SUM riêng. Phải dùng helper chung.

---

## 1) Danh sách metric cốt lõi

### 1.1 `gross_revenue` — Doanh thu (gross)
- **Mô tả nghiệp vụ**: Tổng tiền hàng khách đã mua, trước khi trừ giảm giá hóa đơn và trả hàng.
- **Công thức**: `SUM(invoices.subtotal)` với điều kiện bên dưới.
- **Điều kiện lọc**:
  - `invoices.created_at BETWEEN [from, to]`
  - `invoices.status != 'Đã hủy'`
  - `invoices.branch_id = :branch_id` (nếu có)
- **Không bao gồm**: phụ phí, phí giao hàng (those are in `total`, not `subtotal`).
- **Đơn vị**: VND.
- **Ví dụ test**:
  - 1 HĐ: 2 × 30k + 4 × 7k = 88k, discount = 8k → `subtotal = 88k`, `total = 80k`. Metric trả về **88k**.

### 1.2 `invoice_discount` — Giảm giá hóa đơn
- **Công thức**: `SUM(invoices.discount)` với cùng điều kiện như `gross_revenue`.
- **Ghi chú**: Chỉ là giảm giá cấp header (toàn hóa đơn). Giảm giá từng dòng đã được phản ánh trong `items.price` (giá sau chiết khấu dòng).

### 1.3 `return_value` — Giá trị hàng bán bị trả lại
- **Công thức**: `SUM(returns.subtotal)` (đối xứng với `gross_revenue`).
- **Điều kiện**: `returns.created_at BETWEEN [from, to]`, `status != 'Đã hủy'`, `branch_id = :branch_id`.
- **Cảnh báo**: KHÔNG dùng `returns.total` (vì `total` đã trừ fee, không đối xứng với doanh thu gross).

### 1.4 `net_revenue` — Doanh thu thuần
- **Công thức**: `gross_revenue − invoice_discount − return_value`
- **Ghi chú**: Đây là con số dùng cho P&L, KPI, dashboard.

### 1.5 `cogs_sold` — Giá vốn hàng đã bán (gross)
- **Công thức (SQL)**:
  ```sql
  SUM(
    invoice_items.quantity *
    COALESCE(NULLIF(invoice_items.cost_price, 0), products.cost_price, 0)
  )
  ```
- **JOIN**: `invoice_items JOIN products ON invoice_items.product_id = products.id`
- **Điều kiện**: `invoice_items.invoice_id IN (invoices hợp lệ theo điều kiện 1.1)`.

### 1.6 `cogs_returned` — Giá vốn hàng trả lại
- **Công thức**:
  ```sql
  SUM(return_items.quantity * COALESCE(return_items.import_price, 0))
  ```
- **Điều kiện**: `return_items.return_id IN (returns hợp lệ theo điều kiện 1.3)`.

### 1.7 `cogs_net` — Giá vốn hàng bán thuần
- **Công thức**: `cogs_sold − cogs_returned`
- **⚠ TUYỆT ĐỐI KHÔNG cộng `purchases.other_costs_total`** vào đây. Chi phí nhập đã được vốn hóa vào giá vốn tồn kho khi nhập (theo giả thiết kế toán hàng tồn kho). Nếu muốn báo cáo riêng chi phí vận chuyển, dùng metric tách `purchase_other_costs`.

### 1.8 `gross_profit` — Lợi nhuận gộp
- **Công thức chuẩn**:
  ```
  gross_profit = net_revenue − cogs_net
               = (gross_revenue − invoice_discount − return_value) − (cogs_sold − cogs_returned)
  ```
- **Màn Báo cáo Tài chính** trình bày dạng:
  ```
  (5) Lợi nhuận gộp = (1) Doanh thu − (2) Giá vốn − (3) Giá trị trả − (4) Giảm giá HĐ
  ```
  Đây là cùng 1 công thức, chỉ viết dưới dạng số học: `subtotal − cogs_net − return_subtotal − discount`.

### 1.9 `operating_expenses` — Chi phí hoạt động
- **Công thức**:
  ```sql
  SUM(cash_flows.amount)
  WHERE type = 'payment'
    AND category NOT IN ('Chi tiền trả NCC', 'Chuyển/Rút', '')
    AND time BETWEEN [from, to]
    AND branch_id = :branch_id
  ```
- **Loại trừ**: "Chi tiền trả NCC" (đã được phản ánh trong công nợ NCC / giá vốn khi nhập) và "Chuyển/Rút" (chuyển nội bộ).

### 1.10 `operating_profit` — Lợi nhuận từ hoạt động kinh doanh
- **Công thức**: `gross_profit − operating_expenses`

### 1.11 `other_income` — Thu nhập khác
- **Công thức**:
  ```sql
  SUM(cash_flows.amount)
  WHERE type = 'receipt'
    AND category NOT IN ('Thu tiền khách trả', 'Thu nợ khách hàng', 'Điều chỉnh công nợ', 'Chuyển/Rút', '')
    AND time BETWEEN [from, to]
    AND branch_id = :branch_id
  ```
- **Loại trừ**: thu tiền khách trả (đã nằm trong doanh thu rồi), chuyển nội bộ, điều chỉnh công nợ.

### 1.12 `net_profit` — Lợi nhuận thuần
- **Công thức**: `operating_profit + other_income − other_expenses`

---

## 2) Metric công nợ (Receivables/Payables)

### 2.1 `customer_debt_balance` — Công nợ khách hàng (tại 1 thời điểm)
- **Công thức**:
  ```
  debt = SUM(invoice.total)  -- hóa đơn hợp lệ
       − SUM(customer_paid)   -- khách đã trả
       − SUM(return.total)    -- trả hàng (giảm nợ)
       + SUM(điều chỉnh dương) − SUM(điều chỉnh âm)
  ```
- Cần chi tiết trong `repo/DEBT_PAYMENT_TRACKING_SYSTEM.md`.

### 2.2 `supplier_debt_balance` — Công nợ NCC
- Tương tự nhưng dùng `purchases` + `supplier_payments`.

---

## 3) Metric hàng hóa (Inventory)

### 3.1 `stock_on_hand` — Tồn kho hiện tại
- **Nguồn chính**: `products.stock_quantity` (denormalized từ stock ledger).
- **Invariant bắt buộc**: `stock_on_hand = opening_stock + SUM(nhập) − SUM(xuất) ± SUM(điều chỉnh)`. Nếu invariant này sai, mọi báo cáo hàng hóa đều sai — phải fix stock ledger trước.

### 3.2 `inventory_value` — Giá trị tồn kho
- **Công thức**: `SUM(products.stock_quantity * products.cost_price)`

---

## 4) Mapping controller ↔ metric (hiện tại)

| Controller | Hiện tại dùng | Đúng chuẩn? |
|---|---|---|
| `FinancialReportController` | subtotal + cogs_net + return_subtotal (SAU FIX 2026-04-22) | ✅ |
| `ReportController::businessOverview` | `invoice.total` (đã trừ discount) − `cogs_sold` (không trừ returns) | ❌ Cần refactor dùng helper chung |
| `ReportController::dashboard` | Tương tự | ❌ |
| `SalesReportController` | Bỏ qua returns | ❌ |
| `ProductReportController` | Dùng cogs_sold (không trừ returns) | ❌ |
| `EmployeeReportController` | Tùy method, chưa audit | ⚠ |
| `CustomerReportController` | Tùy method, chưa audit | ⚠ |
| `SupplierReportController` | Tùy method, chưa audit | ⚠ |
| `EndOfDayReportController` | Dùng `cash_flows` (OK) | ✅ |
| `DashboardController` | Tương tự ReportController | ❌ |

## 5) Kế hoạch refactor (thứ tự bắt buộc)

1. **Tạo `App\Support\Reports\MetricService`**: các method static `grossRevenue($q), cogsNet($q), netRevenue($q), grossProfit($q)`, nhận scope query đã có điều kiện lọc.
2. **Refactor `FinancialReportController` dùng `MetricService`** (hiện đang inline — OK nhưng dài).
3. **Refactor `ReportController::businessOverview` và `dashboard`** → áp dụng `gross_profit = net_revenue − cogs_net`.
4. **Refactor `SalesReport` / `ProductReport` / `Customer`/`Employee`/`Supplier`** → cùng helper.
5. **Test đối soát**: cùng 1 khoảng thời gian, `FinancialReport.grossProfit` = `Dashboard.grossProfit` = `SalesReport.grossProfit`. Nếu không bằng, fix.
6. **Test E2E từng màn**: dùng bộ dữ liệu vàng theo kế hoạch (xem file `ke_hoach_ra_soat_bao_cao_theo_logic_kiotviet.md` mục 3.3 Giai đoạn 3).

---

## 6) Danh sách cảnh báo dữ liệu đang gặp (2026-04-22)

Các cảnh báo dữ liệu làm méo báo cáo dù code đúng:

1. **Ghost invoice** `HD-TEST-1775979640` — total 50M, 0 items → tạo doanh thu ảo.
2. **Dell XPS 13 9315 (SP002260)** — `cost_price=18,000,000` nhưng `retail_price=1,500,000` (đảo ngược).
3. **Test Kiot ERP (SP260227407)** — `cost_price=0` (100% profit margin ảo).

→ **Quy tắc**: trước khi chạy báo cáo production, phải chạy health check:
- HĐ có `total > 0` nhưng không có items → fail
- Product có `cost_price > retail_price` (trừ khi có flag loss-leader) → warn
- Product có `cost_price = 0` trong khi có HĐ bán → warn
- `SUM(items.qty * items.price) != invoices.subtotal` → fail
- `invoices.subtotal - invoices.discount + invoices.other_fees + invoices.delivery_fee != invoices.total` → fail

---

## 7) Thay đổi gần nhất

| Ngày | Chỉnh | Người chỉnh | Ghi chú |
|---|---|---|---|
| 2026-04-22 | Tạo dictionary, fix 3 bug trong FinancialReportController (dùng `total` thay `subtotal`, cộng `purchase_other_costs` vào COGS, không trừ COGS hàng trả) | Copilot | Xác minh qua diag 6 tháng |
