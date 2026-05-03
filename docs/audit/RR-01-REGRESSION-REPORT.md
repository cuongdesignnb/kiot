# RR-01 Regression Report

> **Mã rủi ro:** RR-01  
> **Ngày kiểm tra:** 02/05/2026  
> **Phạm vi:** Kiểm tra ảnh hưởng sau khi đổi hủy hóa đơn từ `$invoice->delete()` sang `$invoice->status = 'Đã hủy'`  
> **Business code đã sửa bước này:** KHÔNG

---

## 1. Mục tiêu

Kiểm tra xem việc giữ lại invoice với `status = 'Đã hủy'` có gây sai lệch doanh thu, lợi nhuận, công nợ, hoặc báo cáo không. Trước đây invoice hủy tự biến mất (bị xóa) nên query không cần lọc. Bây giờ invoice hủy vẫn nằm trong DB.

---

## 2. Kết quả kiểm tra query

### Dashboard — DashboardController.php

| Dòng | Query | Có lọc `'Đã hủy'` | Rủi ro |
|---|---|---|---|
| 34 | `$todayRevenue = Invoice::...->sum('total')` | ✅ `where('status','!=','Đã hủy')` | Không |
| 35 | `$yesterdayRevenue` | ✅ Có | Không |
| 38 | `$todayOrders` (count) | ✅ Có | Không |
| 39 | `$yesterdayOrders` (count) | ✅ Có | Không |
| 96-99 | Revenue chart 30 ngày (sum + count) | ✅ Có | Không |
| 123-127 | Top products (InvoiceItem via whereHas) | ✅ `->where('status', '!=', 'Đã hủy')` | Không |
| 183, 214 | Top products by revenue/profit (whereHas) | ✅ Có | Không |
| 238-243 | Top customers by revenue | ✅ `->where('status', '!=', 'Đã hủy')` | Không |
| 256-261 | Top customers by qty | ✅ Có | Không |
| 276-280 | Top employees | ✅ Có | Không |
| 156-158 | **recentInvoices** (list gần đây) | ❌ **KHÔNG lọc** | ⚠️ **P2** — hiển thị HĐ hủy trong "hoạt động gần đây" |

### MetricService — MetricService.php

| Dòng | Query | Có lọc | Rủi ro |
|---|---|---|---|
| 33-34 | `invoiceScope()` — base query cho tất cả metric | ✅ `->where('status', '!=', 'Đã hủy')` | Không |
| 46-47 | `returnScope()` — base query returns | ✅ Có | Không |

> **MetricService là single source of truth cho doanh thu, giá vốn, lợi nhuận** — tất cả đều đã lọc `'Đã hủy'`. ✅ AN TOÀN.

### SalesReportController — SalesReportController.php

| Dòng | Query | Có lọc | Rủi ro |
|---|---|---|---|
| 33-34 | Base `$invoiceQuery` | ✅ `->where('status', '!=', 'Đã hủy')` | Không |
| 39-40 | Base `$returnsQuery` | ✅ Có | Không |
| 67-68 | **salesChannels filter options** | ❌ **KHÔNG lọc** | ⚠️ **P2** — dropdown có thể chứa channel từ HĐ đã hủy |

### CustomerReportController — CustomerReportController.php

| Dòng | Query | Có lọc | Rủi ro |
|---|---|---|---|
| 29-30 | `$invoiceQuery` | ✅ `->where('status', '!=', 'Đã hủy')` | Không |
| 46 | `$costQuery` | ✅ Có | Không |

### ProductReportController — ProductReportController.php

| Dòng | Query | Có lọc | Rủi ro |
|---|---|---|---|
| 36-37 | `$invoiceQuery` | ✅ `->where('status', '!=', 'Đã hủy')` | Không |
| 44 | `$costQuery` | ✅ Có | Không |

### EmployeeReportController — EmployeeReportController.php

| Dòng | Query | Có lọc | Rủi ro |
|---|---|---|---|
| 31-32 | `$invoiceQ` | ✅ `->where('status', '!=', 'Đã hủy')` | Không |
| 41 | Cost query | ✅ Có | Không |
| 65 | **salesChannels filter** | ❌ **KHÔNG lọc** | ⚠️ P2 — dropdown |

### EndOfDayReportController — EndOfDayReportController.php

| Dòng | Query | Có lọc | Rủi ro |
|---|---|---|---|
| 36-37 | `$invoiceQuery` | ✅ `->where('status', '!=', 'Đã hủy')` | Không |
| 73-74 | `$returnsQuery` | ✅ Có | Không |
| 130-134 | **paymentMethods filter** | ❌ **KHÔNG lọc** | ⚠️ P2 — dropdown |
| 135-139 | **salesChannels filter** | ❌ **KHÔNG lọc** | ⚠️ P2 — dropdown |

### ReportController — ReportController.php

| Dòng | Query | Có lọc `'Đã hủy'` | Rủi ro |
|---|---|---|---|
| 245-248 | productOverview: InvoiceItem→whereHas invoice | ❌ **KHÔNG lọc** | 🔴 **P1** — tính cả SP bán trong HĐ hủy |
| 259-262 | topGroupsBestSeller: InvoiceItem→whereHas | ❌ **KHÔNG lọc** | 🔴 **P1** |
| 286-289 | soldCategoryIds: InvoiceItem→whereHas | ❌ **KHÔNG lọc** | 🔴 **P1** |
| 304-307 | topGroupsSlowSeller: join invoices | ❌ **KHÔNG lọc** | 🔴 **P1** |
| 373-375 | deadStockCount: InvoiceItem→whereHas | ❌ **KHÔNG lọc** | ⚠️ P2 — sai dead stock |
| 484-488 | productCategory: join invoices | ❌ **KHÔNG lọc** | 🔴 **P1** |
| 528-533 | customerOverview: totalRevenueInPeriod sum | ❌ **KHÔNG lọc** | 🔴 **P0** — sai doanh thu tổng KH |
| 541-544 | newCustomerRevenue | ❌ **KHÔNG lọc** | 🔴 **P0** |
| 547-552 | oldCustomerRevenue | ❌ **KHÔNG lọc** | 🔴 **P0** |
| 555-558 | walkinRevenue | ❌ **KHÔNG lọc** | 🔴 **P0** |
| 559-562 | walkinCount | ❌ **KHÔNG lọc** | 🔴 **P0** |
| 579-590 | chartData (weekly) | ❌ **KHÔNG lọc** | 🔴 **P0** |
| 662-665 | customerCategory: invoiceCount per customer | ❌ **KHÔNG lọc** | 🔴 **P0** — sai RFM phân loại |
| 667-670 | lastInvoice per customer | ❌ **KHÔNG lọc** | ⚠️ P2 — HĐ hủy tính vào recency |
| 692-695 | custRevenue per customer | ❌ **KHÔNG lọc** | 🔴 **P0** |
| 706-710 | costItems per customer | ❌ **KHÔNG lọc** | 🔴 **P0** — sai giá vốn/lợi nhuận |
| 754-756 | yearRevenue (debt report) | ❌ **KHÔNG lọc** | 🔴 **P0** — sai tỷ lệ nợ/doanh thu |
| 776-778 | monthRev (12-month chart) | ❌ **KHÔNG lọc** | 🔴 **P0** |
| 817-820 | lastInv per debtor | ❌ **KHÔNG lọc** | ⚠️ P2 |
| 824-827 | custYearRevenue per debtor | ❌ **KHÔNG lọc** | 🔴 **P0** |

### InvoiceController — InvoiceController.php

| Dòng | Query | Có lọc | Rủi ro |
|---|---|---|---|
| 50-63 | `index()` — listing paginated | ❌ **KHÔNG lọc mặc định** | ⚠️ **P2** — OK (hiển thị tất cả, có filter UI status) |
| 82-85 | salesChannels filter options | ❌ Không lọc | ⚠️ P2 — dropdown |
| 101 | POS report: listing | Không rõ | P2 |
| 709-711 | `export()` — CSV export | ❌ **KHÔNG lọc** | ⚠️ P2 — export chứa HĐ hủy |
| 720-730 | `show()` | ✅ Không cần lọc — xem chi tiết 1 HĐ | Không |

### CustomerController — CustomerController.php

| Dòng | Query | Có lọc | Rủi ro |
|---|---|---|---|
| 251-253 | salesHistory: list invoices | ❌ **KHÔNG lọc** | ⚠️ P2 — OK (hiển thị history, có status) |
| 271 | debt invoices | Không rõ | P2 |
| 484 | invoices for debt | Không rõ | P2 |
| 536 | invoices for debt | Không rõ | P2 |

### SupplierController — SupplierController.php

| Dòng | Query | Có lọc | Rủi ro |
|---|---|---|---|
| 331-333 | Invoices cho dual-role (supplier debt) | ❌ **KHÔNG lọc** | ⚠️ **P1** — HĐ hủy tính vào công nợ NCC dual-role |

---

## 3. Kết quả kiểm tra POS

| Nội dung | Kết quả |
|---|---|
| PosController có method hủy hóa đơn? | ❌ **KHÔNG** — POS chỉ có `checkout()` (tạo HĐ) |
| PosController gọi InvoiceController@destroy? | ❌ Không |
| PosController có logic delete invoice riêng? | ❌ Không (đã grep: không có `delete`, `destroy`, `cancel`) |
| PosController có tạo invoice? | ✅ Có — `checkout()` tạo Invoice trực tiếp |
| PosController có report riêng? | ❌ Không |

> **Kết luận POS: AN TOÀN.** Hủy HĐ chỉ qua `InvoiceController@destroy` (đã sửa). POS không có flow hủy riêng.

---

## 4. Kết quả kiểm tra CashFlow

| Nội dung | Kết quả |
|---|---|
| `cash_flows` có cột `status` | ✅ Có (default `'active'`, migration 2026_04_19) |
| CashFlowController@index tổng thu | ✅ `where('status', '!=', 'cancelled')->sum('amount')` (dòng 34) |
| CashFlowController@index tổng chi | ✅ `where('status', '!=', 'cancelled')->sum('amount')` (dòng 35) |
| CashFlowController@destroy (cancel) | ✅ `update(['status' => 'cancelled'])` + soft-delete (dòng 189-190) |
| CashFlow listing (paginate) | ⚠️ Listing hiển thị tất cả CashFlow kể cả cancelled — có filter UI `status` |
| CashFlow export | ❌ **KHÔNG lọc status** — export chứa cả cancelled |
| ReportController costProfit: CashFlow expenses | ❌ **KHÔNG lọc cancelled** (dòng 173-176) |

> **Ghi chú:** CashFlow model có `SoftDeletes` → `CashFlowController@destroy` gọi cả `update(['status' => 'cancelled'])` lẫn `$cashFlow->delete()` (soft). Query mặc định sẽ loại trừ soft-deleted. Tuy nhiên RR-01 chỉ gọi `update(['status' => 'cancelled'])` (không soft-delete) → CashFlow từ HĐ hủy vẫn hiện trong listing nhưng metric `totalReceipts/totalPayments` đã lọc `cancelled`. **Rủi ro thấp (P2).**

---

## 5. Kết quả chạy test

| Lệnh | Kết quả |
|---|---|
| `php artisan config:clear` | ✅ Configuration cache cleared |
| `php artisan migrate:fresh --env=testing --force` | ✅ Tất cả migration DONE |
| `php artisan test --env=testing --filter=CancelInvoiceTest` | ✅ **10 passed (20 assertions)** |

---

## 6. Vấn đề phát hiện

### REG-RR01-01 — ReportController thiếu lọc `'Đã hủy'` trong 10+ query
- **File:** `app/Http/Controllers/ReportController.php`
- **Dòng:** 245, 259, 286, 304, 484, 528, 533, 541, 547, 555, 559, 579, 662, 667, 692, 706, 754, 776, 824
- **Vấn đề:** Tất cả Invoice query trong ReportController KHÔNG có `where('status', '!=', 'Đã hủy')`. Bao gồm: tổng quan hàng hóa, phân loại hàng hóa, tổng quan khách hàng, phân loại RFM, công nợ KH.
- **Ảnh hưởng:** Doanh thu, lợi nhuận, số lượng bán, phân loại KH, tỷ lệ nợ/doanh thu đều bị tính cả HĐ đã hủy.
- **Đề xuất:** Thêm `->where('status', '!=', 'Đã hủy')` vào tất cả Invoice::query trong ReportController. Hoặc tạo scope `Invoice::active()`.
- **Mức độ:** 🔴 **P0** — Sai doanh thu báo cáo

### REG-RR01-02 — SupplierController dual-role invoice query thiếu lọc
- **File:** `app/Http/Controllers/SupplierController.php`
- **Dòng:** 331-333
- **Vấn đề:** Khi NCC vừa là khách hàng (dual-role), query invoices cho debt ledger không lọc `'Đã hủy'` → HĐ hủy tính vào công nợ NCC.
- **Ảnh hưởng:** Sổ cái công nợ NCC dual-role tính sai supplier_effect.
- **Đề xuất:** Thêm `->where('status', '!=', 'Đã hủy')` vào query dòng 331.
- **Mức độ:** ⚠️ **P1** — Sai công nợ NCC (chỉ dual-role)

### REG-RR01-03 — Dashboard recentInvoices không lọc
- **File:** `app/Http/Controllers/DashboardController.php`
- **Dòng:** 156-158
- **Vấn đề:** "Hoạt động gần đây" hiển thị cả HĐ đã hủy mà không badge/phân biệt.
- **Ảnh hưởng:** UI confusing — admin thấy HĐ đã hủy trong list hoạt động.
- **Đề xuất:** Lọc `->where('status', '!=', 'Đã hủy')` hoặc thêm badge status.
- **Mức độ:** ⚠️ **P2** — UI only

### REG-RR01-04 — ReportController costProfit: CashFlow expense không lọc cancelled
- **File:** `app/Http/Controllers/ReportController.php`
- **Dòng:** 173-176, 179-181, 193-196, 201-203
- **Vấn đề:** CashFlow expense queries không lọc `status != 'cancelled'`. Tuy nhiên CashFlow model có SoftDeletes → nếu CashFlow bị soft-delete thì sẽ tự động loại trừ. Riêng RR-01 chỉ `update(['status' => 'cancelled'])` KHÔNG soft-delete → vẫn tính.
- **Ảnh hưởng:** Chi phí trong báo cáo Lợi nhuận có thể tính cả CashFlow đã cancelled (từ HĐ hủy).
- **Đề xuất:** Thêm `->where('status', '!=', 'cancelled')` vào CashFlow expense queries.
- **Mức độ:** ⚠️ **P1** — Sai chi phí/lợi nhuận

---

## 7. Tổng hợp rủi ro

| Mức độ | Số lượng | Khu vực |
|---|---|---|
| 🔴 P0 | 1 issue (10+ queries) | ReportController — tất cả báo cáo tổng quan |
| ⚠️ P1 | 2 issues | SupplierController dual-role, CashFlow expense |
| ⚠️ P2 | 4 issues | Dashboard recent, filter dropdowns, export CSV, CashFlow listing |

---

## 8. Kết luận

### 🟡 NEED FIX — Có regression cần sửa trước khi mark RR-01 hoàn toàn Fixed

**Vấn đề nghiêm trọng nhất:** `ReportController.php` có **10+ Invoice queries không lọc** `status != 'Đã hủy'`. Trước đây không ảnh hưởng vì HĐ hủy bị delete, nhưng bây giờ sẽ **tính sai doanh thu tất cả báo cáo tổng quan**.

**Hành động tiếp theo:**
1. **Bước 5.1 (P0):** Thêm `->where('status', '!=', 'Đã hủy')` vào tất cả Invoice query trong ReportController. Cân nhắc tạo scope `Invoice::scopeActive()` để reuse.
2. **Bước 5.2 (P1):** Fix SupplierController dual-role query + CashFlow expense queries.
3. **Bước 5.3 (P2):** Fix filter dropdowns, Dashboard recent, export — có thể để bước sau.

**Những gì AN TOÀN:**
- ✅ Dashboard (trừ recentInvoices) — tất cả metric đã lọc
- ✅ MetricService — single source of truth, đã lọc
- ✅ SalesReportController — base query đã lọc
- ✅ CustomerReportController — đã lọc
- ✅ ProductReportController — đã lọc
- ✅ EmployeeReportController — đã lọc
- ✅ EndOfDayReportController — đã lọc
- ✅ CashFlowController metrics — đã lọc `cancelled`
- ✅ POS — không có flow hủy riêng
- ✅ CancelInvoiceTest — 10/10 PASS
