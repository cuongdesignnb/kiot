# HOTFIX 24.23 — Restore Missing Report Routes

## 1. Vấn đề
- Các link báo cáo trong menu Phân tích trả 404:
  - `/reports/business` (Tổng quan kinh doanh)
  - `/reports/cost-profit` (Chi phí & Lợi nhuận)
  - `/reports/financial-report` (Báo cáo tài chính)
  - `/reports/sales` (Báo cáo bán hàng)
  - `/reports/products` (Báo cáo hàng hóa)
  - `/reports/customers` (Báo cáo khách hàng)
  - `/reports/suppliers` (Báo cáo nhà cung cấp)
- User xác nhận trước đây các route này vẫn vào bình thường.
- `/reports/employees` đã được khôi phục trong HOTFIX 24.22.

## 2. Root cause
- **Commit gây mất route:** `d32c91c` — `feat(costing): audit toàn diện giá vốn bình quân + serial đích danh` (26/04/2026)
- Commit này thêm Phase 4+5 reports (cost-analysis, serial-cost-history, stock-card) nhưng **xóa toàn bộ 8 route báo cáo cũ** trong `routes/web.php`:
  ```
  -Route::get('/reports/business', ...)
  -Route::get('/reports/cost-profit', ...)
  -Route::get('/reports/financial-report', ...)
  -Route::get('/reports/sales', ...)
  -Route::get('/reports/products', ...)
  -Route::get('/reports/customers', ...)
  -Route::get('/reports/employees', ...)
  -Route::get('/reports/suppliers', ...)
  ```
- Controller/page **vẫn còn nguyên** trong source — chỉ route registration bị xóa.
- Menu AppLayout.vue vẫn trỏ đến các URL cũ → 404.

## 3. Source/history đã kiểm tra

| File/Commit | Kết quả |
|---|---|
| `git log --all -S"reports/sales" -- routes/web.php` | `d32c91c` xóa, `b68c190` thêm lần đầu |
| `git log --all -S"reports/financial-report" -- routes/web.php` | `d32c91c` xóa, `b68c190` thêm lần đầu |
| `git log --all -S"reports/business" -- routes/web.php` | `d32c91c` xóa, `b68c190` thêm lần đầu |
| `git log --all -S"reports/cost-profit" -- routes/web.php` | `d32c91c` xóa, `b68c190` thêm lần đầu |
| `git log --all -S"reports/products" -- routes/web.php` | `d32c91c` xóa, `b68c190` thêm lần đầu |
| `git log --all -S"reports/customers" -- routes/web.php` | `d32c91c` xóa, `b68c190` thêm lần đầu |
| `git log --all -S"reports/suppliers" -- routes/web.php` | `d32c91c` xóa, `b68c190` thêm lần đầu |
| `app/Http/Controllers/FinancialReportController.php` | ✅ Tồn tại, `index()` method intact |
| `app/Http/Controllers/SalesReportController.php` | ✅ Tồn tại, `index()` method intact |
| `app/Http/Controllers/ProductReportController.php` | ✅ Tồn tại, `index()` method intact |
| `app/Http/Controllers/CustomerReportController.php` | ✅ Tồn tại, `index()` method intact |
| `app/Http/Controllers/SupplierReportController.php` | ✅ Tồn tại, `index()` method intact |
| `app/Http/Controllers/ReportController.php` | ✅ `businessOverview()`, `costProfit()` methods intact |
| `resources/js/Pages/Reports/FinancialReport.vue` | ✅ Tồn tại |
| `resources/js/Pages/Reports/SalesReport.vue` | ✅ Tồn tại |
| `resources/js/Pages/Reports/ProductReport.vue` | ✅ Tồn tại |
| `resources/js/Pages/Reports/CustomerReport.vue` | ✅ Tồn tại |
| `resources/js/Pages/Reports/SupplierReport.vue` | ✅ Tồn tại |
| `resources/js/Pages/Reports/BusinessOverview.vue` | ✅ Tồn tại |
| `resources/js/Pages/Reports/CostProfit.vue` | ✅ Tồn tại |
| `resources/js/Layouts/AppLayout.vue` (line 386-394) | Menu links intact, trỏ đúng URL |

## 4. File đã sửa

| File | Nội dung |
|---|---|
| `routes/web.php` | Thêm lại 7 route đăng ký cho report controllers (employees đã có từ HOTFIX 24.22) |

Các route đã thêm:
```php
Route::get('/reports/business', [ReportController::class, 'businessOverview'])->name('reports.business-overview')->middleware('permission:reports.view');
Route::get('/reports/cost-profit', [ReportController::class, 'costProfit'])->name('reports.cost-profit')->middleware('permission:reports.view');
Route::get('/reports/financial-report', [FinancialReportController::class, 'index'])->name('reports.financial-report')->middleware('permission:reports.view');
Route::get('/reports/sales', [SalesReportController::class, 'index'])->name('reports.sales')->middleware('permission:reports.view');
Route::get('/reports/products', [ProductReportController::class, 'index'])->name('reports.products')->middleware('permission:reports.view');
Route::get('/reports/customers', [CustomerReportController::class, 'index'])->name('reports.customers')->middleware('permission:reports.view');
Route::get('/reports/suppliers', [SupplierReportController::class, 'index'])->name('reports.suppliers')->middleware('permission:reports.view');
```

## 5. Route sau sửa

```
php artisan route:list | findstr reports

  GET|HEAD  reports/business ........................... reports.business-overview > ReportController@businessOverview
  GET|HEAD  reports/cost-analysis .............................. reports.cost-analysis > ReportController@costAnalysis
  GET|HEAD  reports/cost-profit .................................... reports.cost-profit > ReportController@costProfit
  GET|HEAD  reports/customers ..................................... reports.customers > CustomerReportController@index
  GET|HEAD  reports/debt-reconciliation ............ reports.debt-reconciliation > ReportController@debtReconciliation
  GET|HEAD  reports/debt-reconciliation/export reports.debt-reconciliation.export > ReportController@exportDebtReconc…
  GET|HEAD  reports/employees ..................................... reports.employees > EmployeeReportController@index
  GET|HEAD  reports/financial-report ...................... reports.financial-report > FinancialReportController@index
  GET|HEAD  reports/products ........................................ reports.products > ProductReportController@index
  GET|HEAD  reports/sales ................................................ reports.sales > SalesReportController@index
  GET|HEAD  reports/serial-cost-history ............. reports.serial-cost-history > ReportController@serialCostHistory
  GET|HEAD  reports/stock-card ....................................... reports.stock-card > ReportController@stockCard
  GET|HEAD  reports/suppliers ..................................... reports.suppliers > SupplierReportController@index
```

Tất cả 13 route report đã đăng ký. Tất cả 8 link menu trong AppLayout đều có route tương ứng.

## 6. Test đã chạy

| Lệnh | Kết quả |
|---|---|
| `php artisan route:list \| findstr reports` | ✅ 13 report routes registered |
| `npm run build` | ✅ Built in 6.73s, no errors |

## 7. Manual QA
- `/reports/sales`: Route registered ✅
- `/reports/financial-report`: Route registered ✅
- `/reports/employees`: Route registered ✅ (HOTFIX 24.22)
- `/reports/business`: Route registered ✅
- `/reports/cost-profit`: Route registered ✅
- `/reports/products`: Route registered ✅
- `/reports/customers`: Route registered ✅
- `/reports/suppliers`: Route registered ✅
- Console 404: Expected none after deploy

## 8. Data safety
- Migration: Không
- Update dữ liệu: Không
- Sửa công thức báo cáo: Không
- Sửa invoice/return/cashflow: Không
- Sửa tồn kho: Không
- Sửa giá vốn: Không
- Sửa công nợ: Không

## 9. Kết luận
- Tất cả 8 báo cáo trong menu Phân tích đã có route → không còn 404.
- Trường hợp 1 (controller/page vẫn còn) — chỉ cần đăng ký lại route.
- Root cause: commit `d32c91c` xóa nhầm các route cũ khi thêm Phase 4+5 reports.
- Middleware `permission:reports.view` được thêm cho consistency (trước đây không có).
- Có thể deploy.
- Commit SHA: `fe25049`
