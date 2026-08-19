# P1 — Purchase line total auto-calculation

## Outcome

Operators can enter quantity and line total on purchasing forms. The UI derives
the unit price immediately while preserving the canonical persisted equation:

```text
line total = quantity × unit price − line discount
```

The last edited value is authoritative:

- quantity, unit price or line discount changes refresh the line total;
- line total changes derive the unit price;
- serial-managed products use the selected Serial/IMEI count as quantity.

For an integer-VND total that is not divisible by quantity, the UI uses the
smallest possible rounding adjustment in the line discount and displays that
adjustment next to the row. This keeps the entered total exact and keeps the
database contract unchanged.

## Screen audit

Enabled:

- `Purchases/Create.vue`
- `Purchases/Edit.vue`
- `PurchaseOrders/Create.vue`

Intentionally unchanged:

- purchase returns, because quantity and cost come from the source receipt;
- damage and stock-transfer flows, because they use inventory cost contracts;
- sales/POS flows, because price books, promotions, exchange/return and lock
  rules require a separate review.

## Persistence safety

`line_total` is presentation state only and is not trusted by the backend.
Purchase and purchase-order controllers recalculate every persisted line from
quantity, unit price and line discount. A line discount greater than its gross
amount is rejected before a purchase mutation; purchase-order validation is
transactional and also rejects the invalid line.

Purchase orders continue to have no stock or supplier-debt mutation.

## Automated evidence

```text
JS_PRICING_AND_RELATED_TESTS=PASS — 29 tests
TARGETED_PURCHASE_TESTS=PASS — 17 tests / 130 assertions
FRONTEND_BUILD=PASS — 929 modules
PINT=PASS
PHP_LINT=PASS
GIT_DIFF_CHECK=PASS
```

The targeted PHP set covers exact purchase persistence, create/update invalid
discount rollback, forged purchase-order totals, lazy supplier debt display
and backdated supplier-payment behavior.

## Browser QA — disposable MySQL 8

Origin: `http://127.0.0.1:8894`

```text
LOGIN=PASS
PURCHASE_CREATE_RENDER=PASS
18 × 9,000,000 => UNIT_PRICE_500,000=PASS
3 × 100,000 => UNIT_PRICE_33,334 + ROUNDING_DISCOUNT_2=PASS
QUANTITY_CHANGE_PRESERVES_OPERATOR_TOTAL=PASS
UNIT_PRICE_CHANGE_REFRESHES_TOTAL=PASS
PURCHASE_CREATE_SUBMIT=PASS
PURCHASE_EDIT_TOTAL_TO_PRICE=PASS
PURCHASE_EDIT_SUBMIT=PASS
SERIAL_TOTAL_DISABLED_WITHOUT_SERIAL=PASS
SERIAL_COUNT_DRIVES_QUANTITY=PASS
PURCHASE_ORDER_TOTAL_TO_PRICE=PASS
PURCHASE_ORDER_SUBMIT=PASS
HTTP_5XX=0
PAGE_ERRORS=0
```

Database verification after browser QA:

```text
PURCHASE_QUANTITY=4
PURCHASE_UNIT_PRICE=30,000
PURCHASE_LINE_TOTAL=120,000
PRODUCT_STOCK=4
INVENTORY_TOTAL_COST=120,000
SUPPLIER_DEBT=120,000

PURCHASE_ORDER_QUANTITY=3
PURCHASE_ORDER_UNIT_PRICE=33,334
PURCHASE_ORDER_LINE_DISCOUNT=2
PURCHASE_ORDER_LINE_TOTAL=100,000
PURCHASE_ORDER_STOCK_MUTATION=NO
PURCHASE_ORDER_DEBT_MUTATION=NO
```

## Data safety and rollout

```text
MIGRATION=NO
BACKFILL=NO
PRODUCTION_ACCESSED=NO
PRODUCTION_DATABASE_MUTATED=NO
```

Deploy code only after PR review and current-head CI success. No production
database command is required for this change.
