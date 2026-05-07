# STEP 24.4 — Dynamic Sidebar Filter Audit Report

> **Scope**: All 16 controllers that pass `filterOptions` to Inertia list views.
> **Goal**: Identify hardcoded UI options that should be backend-driven, verify date column correctness, and ensure export/pagination consistency.

---

## Legend

| Symbol | Meaning |
|--------|---------|
| ✅ | Dynamic — queries DB or uses `Enum::options()` |
| ⚠️ | Hardcoded but justified (fixed business set, unlikely to change) |
| 🔴 | Hardcoded — should be dynamic or use centralized Enum |
| 🟡 | Needs attention (logic issue, not hardcode) |

---

## 1. InvoiceController

| Filter Key | Source | Status |
|------------|--------|--------|
| `branches` | `Branch::select('id','name')->get()` | ✅ |
| `statuses` | `InvoiceStatus::options()` | ✅ |
| `employees` | `Employee::select(…)->where('is_active',true)` | ✅ |
| `creators` | `User::select('id','name')` | ✅ |
| `paymentMethods` | Hardcoded array: cash/card/transfer/ewallet | 🔴 |
| `salesChannels` | `Invoice::distinct()->pluck('sales_channel')` | ✅ |
| `deliveryOptions` | Hardcoded: 0/1 | ⚠️ Boolean — justified |
| `debtOptions` | Hardcoded: 1/0 | ⚠️ Boolean — justified |

**dateColumn**: `COALESCE(transaction_date, created_at)` ✅ (Step 24.3)
**export()**: Uses `configureInvoiceFilters()` + `applyFilters()` ✅

> **Action**: Extract `paymentMethods` to `PaymentMethod` enum.

---

## 2. OrderController

| Filter Key | Source | Status |
|------------|--------|--------|
| `branches` | `Branch::select('id','name')` | ✅ |
| `statuses` | `OrderStatus::options()` | ✅ |
| `employees` | `Employee::select(…)->where('is_active',true)` | ✅ |
| `creators` | `User::select('id','name')` | ✅ |
| `salesChannels` | `Order::distinct()->pluck('sales_channel')` | ✅ |
| `deliveryOptions` | Hardcoded: 0/1 | ⚠️ Boolean |
| `debtOptions` | Hardcoded: 1/0 | ⚠️ Boolean |

**dateColumn**: `created_at` 🟡 (Orders don't have transaction_date — acceptable for now)
**export()**: Uses `configureOrderFilters()` + `applyFilters()` ✅

> **Action**: None critical. Payment method filter not exposed — OK (orders use same as invoices on conversion).

---

## 3. PurchaseController

| Filter Key | Source | Status |
|------------|--------|--------|
| `branches` | `Branch::select('id','name')` | ✅ |
| `statuses` | `PurchaseStatus::options()` | ✅ |
| `suppliers` | `$suppliers->map(…)` (dynamic from Customer) | ✅ |
| `employees` | `$employees->map(…)` (dynamic from Employee) | ✅ |
| `paymentMethods` | Hardcoded: cash/transfer/card | 🔴 |
| `debtOptions` | Hardcoded: 1/0 | ⚠️ Boolean |

**dateColumn**: `purchases.created_at` 🟡 **Should be `COALESCE(purchase_date, created_at)` — purchases have `purchase_date` like invoices have `transaction_date`**
**export()**: Uses `configurePurchaseFilters()` + `applyFilters()` ✅

> **Actions**:
> 1. Extract `paymentMethods` to `PaymentMethod` enum (shared with Invoice).
> 2. Change `dateColumn` to `COALESCE(purchase_date, created_at)`.

---

## 4. PurchaseReturnController

| Filter Key | Source | Status |
|------------|--------|--------|
| `statuses` | `PurchaseReturnStatus::options()` | ✅ |
| `creators` | `User::select('id','name')` | ✅ |
| `employees` | `Employee::select(…)->where('is_active',true)` | ✅ |
| `suppliers` | `Customer::where('is_supplier',true)` | ✅ |
| `paymentMethods` | Hardcoded: cash/transfer | 🔴 |

**dateColumn**: `return_date` ✅ (correct business date)
**export()**: Uses `configurePurchaseReturnFilters()` + `applyFilters()` ✅

> **Action**: Extract `paymentMethods` to shared enum.

---

## 5. PurchaseOrderController

| Filter Key | Source | Status |
|------------|--------|--------|
| `branches` | `$branches->map(…)` | ✅ |
| `statuses` | `PurchaseOrderStatus::options()` | ✅ |
| `suppliers` | `Customer::where('is_supplier',true)` | ✅ |

**dateColumn**: `created_at` ⚠️ (PO has no separate business date — acceptable)
**export()**: Uses `configurePurchaseOrderFilters()` + `applyFilters()` ✅

> **Action**: None needed — fully dynamic.

---

## 6. OrderReturnController

| Filter Key | Source | Status |
|------------|--------|--------|
| `branches` | `Branch::select('id','name')` | ✅ |
| `statuses` | `ReturnStatus::options()` | ✅ |
| `salesChannels` | `OrderReturn::distinct()->pluck('sales_channel')` | ✅ |

**dateColumn**: `created_at` 🟡 (returns could benefit from `return_date` if column exists)
**export()**: Uses `configureReturnFilters()` + `applyFilters()` ✅

> **Action**: Evaluate adding `return_date` business column (low priority).

---

## 7. CashFlowController

| Filter Key | Source | Status |
|------------|--------|--------|
| `types` | Hardcoded: receipt/payment | ⚠️ Fixed set — only 2 values ever |
| `paymentMethods` | Hardcoded: cash/bank/ewallet | 🔴 |
| `statuses` | Hardcoded: active/cancelled | ⚠️ Fixed set |
| `bankAccounts` | `BankAccount::where('status','active')` | ✅ |
| `categories` | Dynamic from `CashFlow::distinct()->pluck('category')` | ✅ |
| `targetTypes` | Hardcoded: customer/supplier/employee/other | ⚠️ Fixed set |

**dateColumn**: `time` ✅ (correct — CashFlow uses `time` as business date)
**export()**: Uses `configureCashFlowFilters()` + `applyFilters()` ✅

> **Actions**:
> 1. Extract `paymentMethods` to `PaymentMethod` enum.
> 2. `types` and `statuses` are genuinely fixed binary sets — keep as-is.

---

## 8. CustomerController

| Filter Key | Source | Status |
|------------|--------|--------|
| `branches` | `Branch::select('id','name')` | ✅ |
| `types` | Hardcoded: individual/company | ⚠️ Fixed set |
| `genders` | Hardcoded: male/female/none | ⚠️ Fixed set |
| `debtOptions` | Hardcoded: yes/no | ⚠️ Boolean |
| `customerGroups` | `Customer::distinct()->pluck('customer_group')` | ✅ |

**dateColumn**: `created_at` ✅ (customers don't have a transaction date)
**export()**: ❌ **No `export()` method found** — need to verify

> **Action**: Add export if missing. Gender/type are legitimately fixed.

---

## 9. SupplierController

| Filter Key | Source | Status |
|------------|--------|--------|
| `groups` | `$groups->map(…)` (dynamic from Customer) | ✅ |
| `partnerTypes` | Hardcoded: supplier_only/both | ⚠️ Fixed set (only 2 business concepts) |
| `payableOptions` | Hardcoded: 1/0 | ⚠️ Boolean |
| `statuses` | Hardcoded: active/inactive | ⚠️ Fixed set |

**dateColumn**: `created_at` ✅
**export()**: Uses `configureSupplierFilters()` + `applyFilters()` ✅

> **Action**: None critical. All hardcodes are justified fixed-set values.

---

## 10. StockTransferController

| Filter Key | Source | Status |
|------------|--------|--------|
| `branches` | `$branches->map(…)` | ✅ |
| `statuses` | `StockTransferStatus::options()` | ✅ |

**dateColumn**: `created_at` 🟡 (has `sent_date` — could benefit from COALESCE)
**export()**: Uses `configureStockTransferFilters()` + `applyFilters()` ✅

> **Action**: Consider `COALESCE(sent_date, created_at)` for business date consistency.

---

## 11. StockTakeController

| Filter Key | Source | Status |
|------------|--------|--------|
| `branches` | `$branches->map(…)` | ✅ |
| `statuses` | `StockTakeStatus::options()` | ✅ |

**dateColumn**: `created_at` ⚠️ (acceptable — stocktakes don't have separate business dates)
**export()**: Uses `configureStockTakeFilters()` + `applyFilters()` ✅

> **Action**: None needed.

---

## 12. DamageController

| Filter Key | Source | Status |
|------------|--------|--------|
| `branches` | `$branches->map(…)` | ✅ |
| `statuses` | `DamageStatus::options()` | ✅ |

**dateColumn**: `created_at` 🟡 (has `destroyed_date` — could use COALESCE)
**export()**: Uses `configureDamageFilters()` + `applyFilters()` ✅

> **Action**: Consider `COALESCE(destroyed_date, created_at)` for business date.

---

## 13. WarrantyController

| Filter Key | Source | Status |
|------------|--------|--------|
| `statuses` | Hardcoded: valid/expired | ⚠️ Pseudo-status (computed from `warranty_end_date`) |

**dateColumn**: `purchase_date` ✅
**export()**: Uses `configureWarrantyFilters()` ✅

> **Issues**:
> 1. 🟡 Legacy `time_filter` logic (lines 62-73) duplicates the standard `applyDateRange()` call. Should consolidate.
> 2. 🟡 Custom `expiration_filter` range logic is manual — could be standardized.

---

## 14. EmployeeController

| Filter Key | Source | Status |
|------------|--------|--------|
| `branches` | `Branch::select('id','name')` | ✅ |
| `departments` | `Department::select('id','name')` | ✅ |
| `jobTitles` | `JobTitle::select('id','name')` | ✅ |
| `statuses` | Hardcoded: 1/0 (Đang làm/Đã nghỉ) | ⚠️ Boolean |

**dateColumn**: `created_at` ✅
**export()**: Uses `configureEmployeeFilters()` + `applyFilters()` ✅

> **Action**: None needed. Status is binary is_active toggle.

---

## 15. UserController

| Filter Key | Source | Status |
|------------|--------|--------|
| `roles` | `Role::select('id','name')` | ✅ |
| `branches` | `Branch::select('id','name')` | ✅ |
| `statuses` | Hardcoded: active/inactive | ⚠️ Fixed set |

**dateColumn**: `created_at` ✅
**export()**: ❌ **No export method** (Users list typically doesn't need export)

> **Action**: None needed.

---

## 16. ProductController

> **Note**: ProductController does NOT use `FilterableIndex` trait. It has its own custom filtering logic.

> **Action**: Defer to a separate product-filter audit. Not in scope for Step 24.4 sidebar standardization.

---

## Summary Matrix

| Controller | Enum Status | Dynamic Lookups | Payment Method | Date Column | Export Consistent |
|------------|:-----------:|:---------------:|:--------------:|:-----------:|:-----------------:|
| Invoice | ✅ | ✅ | 🔴 hardcode | ✅ COALESCE | ✅ |
| Order | ✅ | ✅ | N/A | ⚠️ created_at | ✅ |
| Purchase | ✅ | ✅ | 🔴 hardcode | 🟡 should COALESCE | ✅ |
| PurchaseReturn | ✅ | ✅ | 🔴 hardcode | ✅ return_date | ✅ |
| PurchaseOrder | ✅ | ✅ | N/A | ⚠️ created_at | ✅ |
| OrderReturn | ✅ | ✅ | N/A | 🟡 created_at | ✅ |
| CashFlow | ⚠️ fixed | ✅ | 🔴 hardcode | ✅ time | ✅ |
| Customer | N/A | ✅ | N/A | ✅ | ❌ no export |
| Supplier | N/A | ✅ | N/A | ✅ | ✅ |
| StockTransfer | ✅ | ✅ | N/A | 🟡 created_at | ✅ |
| StockTake | ✅ | ✅ | N/A | ⚠️ created_at | ✅ |
| Damage | ✅ | ✅ | N/A | 🟡 created_at | ✅ |
| Warranty | ⚠️ pseudo | N/A | N/A | ✅ purchase_date | ✅ |
| Employee | N/A | ✅ | N/A | ✅ | ✅ |
| User | N/A | ✅ | N/A | ✅ | ❌ no export |

---

## Priority Actions

### P0 — Create `PaymentMethod` Enum (Centralized)

Currently **5 controllers** hardcode payment methods with slightly different sets:

| Controller | Values |
|------------|--------|
| Invoice | cash, card, transfer, ewallet |
| Purchase | cash, transfer, card |
| PurchaseReturn | cash, transfer |
| CashFlow | cash, bank, ewallet |
| CashFlow store validation | cash, bank, ewallet |

> **Inconsistency**: CashFlow uses `bank` while Purchase/Invoice use `transfer` for the same concept.

**Proposed `PaymentMethod` enum:**
```php
namespace App\Enums;

enum PaymentMethod: string
{
    case CASH     = 'cash';
    case TRANSFER = 'transfer'; // aka "bank" — standardize to "transfer"
    case CARD     = 'card';
    case EWALLET  = 'ewallet';

    public function label(): string
    {
        return match($this) {
            self::CASH     => 'Tiền mặt',
            self::TRANSFER => 'Chuyển khoản',
            self::CARD     => 'Thẻ',
            self::EWALLET  => 'Ví điện tử',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn(self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
```

> ⚠️ **CashFlow migration note**: Existing records with `payment_method = 'bank'` need a data migration to `'transfer'` OR the enum must support both `bank` and `transfer` as aliases.

### P1 — Fix `PurchaseController` dateColumn

```diff
- $this->dateColumn = 'purchases.created_at';
+ $this->dateColumn = DB::raw('COALESCE(purchases.purchase_date, purchases.created_at)');
```

### P2 — Evaluate Business Date Columns

| Controller | Current | Proposed |
|------------|---------|----------|
| StockTransfer | `created_at` | `COALESCE(sent_date, created_at)` |
| Damage | `created_at` | `COALESCE(destroyed_date, created_at)` |
| OrderReturn | `created_at` | Keep as-is (no separate business date column yet) |

### P3 — Warranty Legacy Cleanup

Remove duplicate `time_filter` logic (lines 62-73 in WarrantyController) — the standard `applyDateRange()` already handles date filtering via the `dateColumn = 'purchase_date'` configuration.

### P4 — Customer Export

Add `export()` method to `CustomerController` reusing `configureCustomerFilters()` + `applyFilters()`.

---

## What's Already Good

1. **All status filters use `Enum::options()`** — no hardcoded status arrays.
2. **All relational lookups (branches, employees, suppliers, creators) are dynamic** — queried from database.
3. **Export methods consistently reuse the same filter configuration** as index — filters apply uniformly.
4. **Invoice dateColumn correctly uses `COALESCE(transaction_date, created_at)`** per Step 24.3.
5. **`withQueryString()`** is used on all pagination calls — URL state persistence is correct.
6. **Dynamic channels** (salesChannels, categories, customerGroups) are queried from actual data via `distinct()->pluck()`.

---

## Next Steps

1. **Implement `PaymentMethod` enum** (P0) — single file, replace hardcoded arrays in 5 controllers.
2. **Fix Purchase dateColumn** (P1) — one-line change.
3. **Evaluate business date columns** (P2) — discuss with stakeholder before changing.
4. **Clean up Warranty legacy filters** (P3) — remove duplicate logic.
5. **Add Customer export** (P4) — straightforward addition.
6. **Vue component audit** — verify that `SidebarFilter` components consume `props.filterOptions` dynamically (not hardcoding options in Vue).
