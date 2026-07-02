# HOTFIX — /invoices 500 strict scope

## 1. Error log

| Mục           | Nội dung |
|---------------|----------|
| URL           | /invoices |
| HTTP status   | 500 |
| Error class   | `TypeError` |
| Error message | `Cannot assign Illuminate\Database\Query\Expression to property App\Http\Controllers\InvoiceController::$dateColumn of type string` |
| File/line     | `app/Support/Filters/FilterableIndex.php:40` (property declaration) → triggered at `app/Http/Controllers/InvoiceController.php:41` |
| SQL           | N/A — fails at PHP type-check BEFORE any SQL is executed |

## 2. Root cause

**PHP 8.2 strict typed property mismatch.**

`FilterableIndex` trait declares:
```php
protected string $dateColumn = 'created_at';  // ← strict string type
```

`InvoiceController::configureInvoiceFilters()` assigns:
```php
$this->dateColumn = DB::raw('COALESCE(invoices.transaction_date, invoices.created_at)');
// DB::raw() returns Illuminate\Database\Query\Expression — NOT a string
```

PHP 8.2 throws `TypeError` on assignment before any query runs. This affects **every page load** of `/invoices`, not just filtered requests.

### Secondary issue found (also fixed):
`creatorColumn = 'created_by'` set unconditionally, but `invoices.created_by` may not exist on production if migration is pending. Added `Schema::hasColumn` guard.

## 3. Scope lock

| File customer vừa fix   | Có sửa không | Lý do |
|--------------------------|-------------|-------|
| Customers/Index.vue     | ❌ Không     | Lỗi nằm hoàn toàn ở FilterableIndex + InvoiceController |
| AppLayout.vue           | ❌ Không     | Không liên quan |
| CustomerGroupController | ❌ Không     | Không liên quan |
| CustomerController      | ❌ Không     | Không liên quan — đã fix ở commit trước |

## 4. Fix applied

| File/command | Nội dung |
|-------------|----------|
| `FilterableIndex.php:40` | Change `protected string $dateColumn` → `protected string\|Expression $dateColumn` |
| `InvoiceController.php:44` | Guard `creatorColumn` with `Schema::hasColumn('invoices', 'created_by')` |
| Commit `3fff412` | `hotfix(invoices): fix TypeError — dateColumn type string\|Expression + guard creatorColumn` |

## 5. Invoice safety

| Rule                               | Còn giữ không |
|------------------------------------|--------------|
| transaction_date policy            | ✅ Giữ nguyên |
| lock_started_at policy             | ✅ Giữ nguyên |
| created_at backward compatibility  | ✅ Giữ nguyên (COALESCE fallback) |
| Date-only không mutate tồn/công nợ | ✅ Giữ nguyên |
| Content update reverse/apply       | ✅ Giữ nguyên |

## 6. Tests

| Test cluster            | Result |
|------------------------|--------|
| PHP syntax (all files) | ✅ No errors |
| Controller instantiation | ✅ `InvoiceController OK` |
| npm run build          | ✅ Built in 7.83s |

## 7. Production QA (to verify after deploy)

- [ ] /invoices OK
- [ ] invoice search OK
- [ ] invoice detail OK
- [ ] /customers OK
- [ ] create customer group OK
- [ ] logs clear

## 8. Conclusion

* **Đã hết 500 chưa:** Cần deploy (git pull + optimize:clear + migrate --force) trên production
* **Có ảnh hưởng customer hotfix không:** ❌ KHÔNG — chỉ sửa FilterableIndex + InvoiceController
* **Có cần migration không:** CÓ — `php artisan migrate --force` (cho transaction_date, lock_started_at, customer_groups, customers.created_by)
* **Có thể tiếp tục 24.4A/24.4B không:** CÓ — sau khi verify production stable

## Deploy commands (trên production)

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git pull origin main
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
npm run build
```
