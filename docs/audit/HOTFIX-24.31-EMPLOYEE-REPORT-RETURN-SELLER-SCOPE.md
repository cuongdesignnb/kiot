# HOTFIX 24.31 — Employee Report Return Seller Scope

## 1. Vấn đề

- Trong `/reports/employees`, khi chọn 1 seller (vd Admin, Vũ Hồng Nhung), report vẫn hiện row của seller khác (vd Vũ Thị Thu Thủy) với net âm.
- Có một return 13.600.000đ làm doanh thu/lợi nhuận âm sai.
- Dropdown Người bán: nếu Admin không có employee active linked user thì không xuất hiện — đúng theo contract (Seller = Employee), nhưng cần phân biệt rõ với bug filter.

## 2. Source đã kiểm tra

- `app/Http/Controllers/EmployeeReportController.php`
- `app/Support/Reports/SellerResolver.php`
- `app/Http/Controllers/InvoiceController.php`
- `app/Models/OrderReturn.php` (`table = returns`)
- `app/Models/ReturnItem.php`
- `app/Models/Employee.php`, `app/Models/User.php`
- Schema `returns`: id, code, invoice_id, customer_id, branch_id, status, subtotal, discount, fee, total, paid_to_customer, note, created_by_name, seller_name, sales_channel, price_book_name, created_at, updated_at.

## 3. Data đã kiểm tra

Không có quyền chạy SELECT trên production data trong session này. Tester chạy 4 query SELECT-only trong brief (Admin user, Admin employee, active employees dropdown, return 13.600.000đ) để xác định data state thực tế. Code fix không phụ thuộc vào kết quả query — bug phát hiện được hoàn toàn từ source.

## 4. Root cause

### 4.1. Báo cáo âm sai khi filter seller

`EmployeeReportController@index()` đã filter `$invoiceQ` theo seller bằng `filterBySeller()`, nhưng `$returnQ` chỉ có filter date/branch/status, **không** có filter seller.

Hệ quả:

- `aggregateReturnsBySeller(clone $returnQ, ...)` resolve mỗi return về seller của invoice gốc và group theo key. Khi `$returnQ` không scope, return của seller B vẫn đi vào kết quả với key `employee:<B>`.
- `buildSalesReportRows()` / `buildProfitReportRows()` merge key của revenue (đã scope seller A) với key của returns (chưa scope) → row B xuất hiện với revenue=0, returns>0, net âm.
- Tương tự `cogsReturnedBySeller(clone $returnQ)` kéo `return_items.cost_price` của seller B trừ vào profit.

### 4.2. Sales channel filter cũng leak

`$returnQ` không có scope theo `sales_channel`. Return của invoice kênh khác kéo vào khi filter channel.

### 4.3. Admin không có trong dropdown

`SellerResolver::buildInvoiceSellerOptions()` chỉ lấy `Employee::where('is_active', true)`. Nếu user Admin không có employee, hoặc employee inactive, hoặc không linked → Admin không xuất hiện. Đây là **đúng theo contract** (Seller = Employee), không phải bug. Việc Admin xuất hiện hay không phụ thuộc data thực tế — tester phải xác định bằng query #2/#3 trong brief.

## 5. Phương án sửa

### 5.1. Bổ sung helper trong `SellerResolver`

```php
public function filterReturnsBySeller($returnQuery, string $sellerKey)
{
    return $returnQuery->whereHas('invoice', function ($q) use ($sellerKey) {
        $this->filterBySeller($q, $sellerKey);
    });
}

public function filterReturnsByInvoiceSalesChannel($returnQuery, string $channel)
{
    return $returnQuery->whereHas('invoice', function ($q) use ($channel) {
        $q->where('sales_channel', $channel);
    });
}
```

- `filterReturnsBySeller`: scope return qua invoice gốc, dùng lại `filterBySeller` đã có cho contract `employee:<id>` / `snapshot:<name>` / `unknown`.
- `filterReturnsByInvoiceSalesChannel`: scope theo `invoices.sales_channel` (giá trị gốc tại thời điểm bán), không tin `returns.sales_channel`.

### 5.2. Áp dụng trong `EmployeeReportController@index()`

```php
if ($employeeId) {
    $returnQ = $this->sellers->filterReturnsBySeller($returnQ, $employeeId);
}
if ($salesChannel) {
    $returnQ = $this->sellers->filterReturnsByInvoiceSalesChannel($returnQ, $salesChannel);
}
```

- Không đụng tới `created_by_name`.
- Không đụng tới invoice/return data cũ.
- Branch/status/date hiện có vẫn giữ nguyên.

### 5.3. Admin trong dropdown

Không tự thêm Admin vào dropdown khi Admin không có employee active. Tester confirm trên production data nếu cần employee thì làm thủ công qua module Nhân viên — đây là thay đổi data, không nằm trong phạm vi HOTFIX.

## 6. File đã sửa

| File | Nội dung |
|---|---|
| `app/Support/Reports/SellerResolver.php` | Thêm `filterReturnsBySeller()` + `filterReturnsByInvoiceSalesChannel()`. |
| `app/Http/Controllers/EmployeeReportController.php` | Scope `$returnQ` theo seller + sales_channel filter. |
| `tests/Feature/Reports/HOTFIX2431EmployeeReportReturnSellerScopeTest.php` | NEW — 8 TC. |

## 7. Tests

| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=HOTFIX2431EmployeeReportReturnSellerScopeTest` | ✅ **8 passed / 28 assertions**, 1.19s |
| `php artisan test --filter="HOTFIX2431\|HOTFIX2430\|HOTFIX2428\|EmployeeReport\|Invoice\|Report\|Return\|CashFlow"` | ✅ **240 passed / 2 skipped / 852 assertions**, 48.73s, 1 unrelated fail (`Tests\Feature\ExampleTest` — Laravel scaffold hit `/` thiếu auth, đã ghi nhận từ các HOTFIX trước, không liên quan 24.31). |
| `npm run build` | ✅ **built in 6.69s** |

**8 TC trong `HOTFIX2431EmployeeReportReturnSellerScopeTest`:**

1. `sales_report_seller_filter_excludes_other_seller_returns` — filter A; return của B (13.6M) không kéo seller B vào rows; A returns=0.
2. `profit_report_seller_filter_excludes_other_seller_returns` — profit filter A; `return_value`=0, `cogsReturned` của B không trừ A.
3. `seller_filter_b_shows_b_returns` — filter B vẫn thấy đúng return 13.6M của B; net âm là dữ liệu thật.
4. `no_filter_returns_group_by_own_seller` — không filter; returns group đúng seller (A=0, B=13.6M).
5. `sales_channel_filter_excludes_returns_of_other_channel` — `sales_channel=Shopee`; return của invoice kênh direct không leak.
6. `admin_user_without_employee_is_not_in_seller_options` — user thuần (không employee) không hiện trong `buildInvoiceSellerOptions()`.
7. `admin_user_with_linked_active_employee_appears` — user có employee linked → hiện, name theo user hiện tại (Hướng A 24.30).
8. `created_by_name_never_used_as_seller` — `created_by=NULL + seller_name=NULL + created_by_name=Admin` → `unknown`, không promote thành seller.

## 8. Manual QA

- Admin dropdown: tester chạy query #2/#3 — Admin chỉ xuất hiện nếu có `employees` row `is_active=1` (có thể `user_id` link hoặc không).
- Filter Admin: nếu có employee Admin, doanh thu/returns chỉ của HĐ thuộc Admin.
- Filter Vũ Hồng Nhung: chỉ HĐ + return của Vũ Hồng Nhung; **không** còn dòng Vũ Thị Thu Thủy.
- No seller filter: returns group theo seller thật của invoice gốc; nếu có âm thì đối soát qua return code → query #4.
- Return 13.600.000đ: query #4 cho biết invoice gốc, `created_by` employee → seller thật của return. Sau fix, return này chỉ ảnh hưởng row của seller thật, không leak sang seller khác.

## 9. Data safety

| Loại | Kết quả |
|---|---|
| Migration | Không |
| Backfill | Không |
| Update dữ liệu cũ | Không |
| Recalculate tồn kho/giá vốn/công nợ/cashflow | Không |
| Sửa invoices/returns/items/serials | Không |

## 10. Kết luận

- Báo cáo còn kéo return seller khác khi filter seller: **Không** (đã scope returnQ).
- Báo cáo còn âm sai do return leak: **Không** (đã hết với data test; data thật cần tester confirm).
- Admin có cần tạo/link employee không: tuỳ data thực tế — không nằm trong scope HOTFIX, cần xác nhận nếu muốn thao tác.
- Có thể deploy: ✅
- Commit SHA: pending (sẽ điền sau commit).
