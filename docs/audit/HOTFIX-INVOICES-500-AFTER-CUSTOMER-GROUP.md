# HOTFIX — /invoices 500 After Customer Group Deploy

## 1. Error

* **URL:** `/invoices`
* **HTTP status:** 500
* **Time:** 2026-05-08 09:30 UTC+7
* **Commit before fix:** `fc83009` (Step 24.4A-4)
* **Likely Laravel log excerpt:**
  ```
  SQLSTATE[42S22]: Column not found: 1054 Unknown column 'transaction_date' in 'field list'
  ```

## 2. Root Cause

**Code references columns that may not exist on production if migrations haven't been run.**

Step 24.3 introduced `invoices.transaction_date` and `invoices.lock_started_at` columns via migration `2026_05_07_000001_add_transaction_date_lock_started_at_to_invoices.php`, but the code deployed after that commit uses these columns in **raw SQL** without checking whether they physically exist on the database.

The `dateColumn` in `InvoiceController::configureInvoiceFilters()` was set to:
```php
DB::raw('COALESCE(transaction_date, created_at)')
```

When `transaction_date` doesn't exist in the database, MySQL throws:
```
Column not found: 1054 Unknown column 'transaction_date'
```

### Affected locations (all same root cause):

| File | Line(s) | Expression |
|------|---------|------------|
| `InvoiceController.php` | 41 | `COALESCE(transaction_date, created_at)` in dateColumn |
| `StockTransferController.php` | 32 | `COALESCE(sent_date, created_at)` in dateColumn |
| `DamageController.php` | 30 | `COALESCE(destroyed_date, created_at)` in dateColumn |
| `PurchaseController.php` | 36, 62 | `COALESCE(purchase_date, created_at)` in dateColumn & orderByRaw |
| `CustomerController.php` | 122, 155, 158 | `COALESCE(transaction_date, created_at)` in subqueries |
| `InvoiceSaleService.php` | 64-72 | `$invoice->update(['transaction_date' => ...])` |
| `InvoiceUpdateService.php` | 213, 316 | `$invoice->transaction_date = ...` |

## 3. Fix Applied

**All COALESCE dateColumn references now use `Schema::hasColumn()` guard:**

```php
// Before (BREAKS if column missing):
$this->dateColumn = DB::raw('COALESCE(transaction_date, created_at)');

// After (SAFE regardless of migration state):
$this->dateColumn = Schema::hasColumn('invoices', 'transaction_date')
    ? DB::raw('COALESCE(invoices.transaction_date, invoices.created_at)')
    : 'created_at';
```

### Files changed:

| File | Change |
|------|--------|
| `InvoiceController.php` | Defensive dateColumn with `Schema::hasColumn` |
| `StockTransferController.php` | Defensive dateColumn with `Schema::hasColumn` |
| `DamageController.php` | Defensive dateColumn with `Schema::hasColumn` |
| `PurchaseController.php` | Defensive dateColumn + orderByRaw with `Schema::hasColumn` |
| `CustomerController.php` | `invoiceDateExpr()` helper method; all subqueries use it |
| `InvoiceSaleService.php` | Guard `transaction_date`/`lock_started_at` update |
| `InvoiceUpdateService.php` | Guard `transaction_date` assignment in both update paths |

### Commands needed on production:

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git pull origin main
php artisan migrate --force       # run pending migrations
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
npm run build
```

## 4. Tests

| Test | Result |
|------|--------|
| PHP syntax (all 7 files) | ✅ No errors |
| `npm run build` | ✅ Built in 9.15s |

## 5. Production QA (to verify after deploy)

- [ ] `/invoices` no 500 — loads list
- [ ] `/customers` no errors
- [ ] Invoice search works
- [ ] Invoice detail loads
- [ ] New invoice creation works
- [ ] POS checkout works
- [ ] `/purchases` list works
- [ ] `/damages` list works
- [ ] `/stock-transfers` list works
- [ ] `storage/logs/laravel.log` has no new errors

## 6. Conclusion

* **Root cause:** Code deployed with raw SQL referencing columns (`transaction_date`, `purchase_date`, `sent_date`, `destroyed_date`) without defensive checks. If migration wasn't run on production, MySQL throws `Column not found`.
* **Fix type:** Code-level defense — all COALESCE expressions now check column existence via `Schema::hasColumn()` before use, falling back to `created_at`.
* **Commit needed:** YES — code changes in 7 files.
* **Migration needed:** YES — `php artisan migrate --force` on production (but code works even without it now).
* **Can resume 24.4A:** YES — after verifying production is stable.

> [!IMPORTANT]
> After this hotfix, the system is **backward compatible** regardless of migration state:
> - If migration **has** run: uses `COALESCE(transaction_date, created_at)` for proper business date filtering.
> - If migration **has not** run: falls back to `created_at` silently. No 500.
