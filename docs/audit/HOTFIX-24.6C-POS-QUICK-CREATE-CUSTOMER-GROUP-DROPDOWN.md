# HOTFIX 24.6C - POS Quick Create Customer Group Dropdown

## Scope
- Screen: POS quick-create customer modal.
- Change: replace the manual `customer_group` text input with the existing `CustomerGroupCombobox`.
- No schema change, no migration, no backfill, no legacy data update.

## Source Checked
- `resources/js/Pages/POS/Index.vue`
- `resources/js/Components/QuickCreateCustomerModal.vue`
- `resources/js/Components/CustomerGroupCombobox.vue`
- `resources/js/Pages/Customers/Index.vue`
- `app/Http/Controllers/CustomerController.php`
- `app/Http/Controllers/CustomerGroupController.php`
- `app/Http/Controllers/PosController.php`
- `routes/web.php`
- `tests/Feature/Filters/Step244ACustomerGroupUiFlowTest.php`
- `tests/Feature/Customers/Step2410CustomerGroupComboboxTest.php`

## Root Cause
- POS quick-create still rendered `form.customer_group` as a plain text input.
- The main Customers screen already used `CustomerGroupCombobox` with `/customer-groups/options`.
- Follow-up QA found the POS combobox could still be empty because `/customer-groups/options` only returned master `customer_groups`, while the Customers sidebar merged master groups with legacy distinct strings from `customers.customer_group`.

## Fix
- POS quick-create customer modal now loads active customer groups from `/customer-groups/options` when opened.
- `/customer-groups/options` now returns the same business list expected by the Customers screen: active master groups plus legacy `customers.customer_group` values that are not yet in master data.
- Customer modal uses `CustomerGroupCombobox` with string `v-model`, preserving the existing `customers.customer_group` column contract.
- Inline create is supported through `POST /customer-groups`; duplicate names refresh and select the existing group, and 403/422 errors are reported clearly.
- Supplier quick-create keeps the previous text input so this hotfix does not mix customer-group UX into supplier-group workflow.

## Data Safety
- Migration: no.
- Backfill/update old data: no.
- Delete data: no.
- POS tab/cart draft: not touched; changes are scoped to modal-local state.
- After create, POS still receives the new customer from `/api/pos/customers` and selects it in the active tab via existing `onCustomerCreated`.

## Tests
- `php artisan test tests/Feature/POS/Hotfix246CPosQuickCreateCustomerGroupDropdownTest.php`: pass, 2 tests / 8 assertions.
- `php artisan test tests/Feature/Filters/Step244ACustomerGroupUiFlowTest.php`: pass, 14 tests / 89 assertions.
- `php artisan test tests/Feature/Customers/Step2410CustomerGroupComboboxTest.php`: pass, 5 tests / 15 assertions.
- `npm run build`: pass.

Note: one earlier parallel run of `Step2410CustomerGroupComboboxTest` failed because it was run at the same time as a `RefreshDatabase` suite against the same testing database. Rerunning it alone passed.

## Manual QA
- Browser QA not run in this terminal session.
- QA needed: open POS, open quick-create customer modal, verify group dropdown opens, search/select group, optionally quick-create a group with a user that has `customers.edit`, create customer, and confirm the new customer remains selected on the current POS tab.

## Production Readiness
- Code is safe to deploy after browser QA.
- No production data mutation is required.
