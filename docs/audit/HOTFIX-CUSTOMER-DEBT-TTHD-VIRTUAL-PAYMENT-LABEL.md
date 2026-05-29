# Audit Report — Hotfix: Customer Debt TTHD Virtual Payment Label & Clickability

## Context & Objectives
- **Target**: Improve the UX, badging, and detail navigation for virtual automatic checkout payments (coded as `TTHD...`) and control clickability of legacy transaction items in the Customer detail screen's **Công nợ** (Debt History) tab under `/customers`.
- **Constraints**:
  - Show a green `Thanh toán HĐ` badge instead of `Chứng từ cũ` for virtual payments (`TTHD...`) generated directly upon sale checkout.
  - Disable click links for entries without detailed resolver endpoints by checking a new `detail_available` metadata flag.
  - Ensure title in the modal for `TTHD...` fallback resolves correctly to `Thanh toán hóa đơn` instead of `Phiếu thanh toán`.
  - Maintain absolute symmetry between the `kiotviet-clone` and `kiotviet-sapo` repositories.

## Source Files Audited & Modified
- `app/Http/Controllers/CustomerController.php`
- `resources/js/Pages/Customers/Index.vue`
- `tests/Feature/Customers/CustomerDebtVoucherDetailTest.php`

---

## Root Cause & Current State
Previously:
1. Virtual automatic checkout payments (`TTHD...` codes) generated from `invoice.customer_paid` were indistinguishable from general legacy records, showing a gray `Chứng từ cũ` badge.
2. Clicking a `TTHD` payment entry rendered a modal with the generic title `Phiếu thanh toán`, which was less descriptive of its checkout context.
3. Certain legacy transaction entries (such as virtual purchase payments `TTNH...`, purchase returns `pret-...`, and general supplier adjustments `stx-...`) had active blue hyperlinks despite lacking resolution logic, leading to empty or error-prone modals.

---

## Implementation Details

### 1. Backend Metadata Addition (`CustomerController.php`)
- **`debtHistory` updates**:
  - Attached `'detail_available' => true` to all ledger entries.
  - Attached `'detail_available' => true` to resolvable legacy items (invoices, cashflows, purchases).
  - Attached `'is_virtual_payment' => true` and `'detail_available' => true` to virtual `TTHD` checkout payments.
  - Attached `'detail_available' => false` to non-resolvable legacy items (e.g. `purpay-...`, `pret-...`, `stx-...`).
- **`debtVoucherDetail` updates**:
  - Modified the fallback cashflow resolver for `TTHD` codes to return `'title' => 'Thanh toán hóa đơn'`.

### 2. Frontend Layout & Navigation (`Index.vue`)
- **Conditional Link Clickability**:
  - Replaced the simple `v-if="entry.code"` link with `v-if="entry.code && entry.detail_available !== false"` so that non-resolvable codes render as plain gray/black text.
- **Custom Badging**:
  - Inserted a `v-if="entry.is_virtual_payment"` check before rendering standard badges. If active, shows the green badge `Thanh toán HĐ` with the title "Thanh toán trực tiếp khi tạo hóa đơn".

---

## Verification & Tests
We added two feature tests inside `tests/Feature/Customers/CustomerDebtVoucherDetailTest.php`:
1. `test_tthd_fallback_voucher_detail`: Verifies that querying a `TTHD` code without matching cashflow database entry returns a successful mock representation with the title `'Thanh toán hóa đơn'`.
2. `test_debt_history_contains_detail_available_and_virtual_payment_flags`: Verifies that `/customers/{customer}/debt-history` correctly returns `detail_available` (boolean) and `is_virtual_payment` flags matching their respective sources.

### Syntax Check
Syntax checked modified PHP files using `php -l`. Both files in both repositories are completely clean.

### Frontend Compilation
Vite assets built successfully in both workspaces via `npm run build`.

---

## Data Safety & Integrity Checks
- **Migrations**: No schema modifications or database alters.
- **Data Side Effects**: Purely metadata query enhancements and controller output maps; zero impact on financial balance sheets or ledger math.
