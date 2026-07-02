# HOTFIX: Dual-role debt timeline financial display

## Problem

Dual-role customer/supplier timelines used the same signed field for both display value and running-balance math. Reference-only documents correctly had `customer_effect = 0` to avoid double counting, but the UI also used that field for the `Giá trị` column, so real invoices, payments, and returns could show `0đ`.

## Contract

Each timeline entry now separates:

- `display_effect` / `financial_effect`: signed document value shown in the `Giá trị` column.
- `balance_effect`: signed value used for running balance.
- `customer_display_effect`, `customer_balance_effect`, `customer_running_balance`: customer-screen orientation.
- `supplier_display_effect`, `supplier_balance_effect`, `supplier_running_balance`: supplier-screen orientation.
- `supplier_partner_effect`, `supplier_partner_running_balance`: backward-compatible supplier partner timeline fields.

Reference-only documents keep their real `display_effect`, but use `balance_effect = 0` and `running_balance = null`.

## UI behavior

- Customer screen prioritizes customer display fields before legacy fields.
- Supplier partner screen prioritizes supplier display fields before legacy fields.
- Null running balance renders as `—`, not `0đ`.
- The UI no longer renders the badge text `Đã hạch toán`.

## Scope

This change only affects read/display payloads and Vue rendering. It does not create vouchers, recalculate balances, backfill data, migrate schema, or update stored debt totals.
