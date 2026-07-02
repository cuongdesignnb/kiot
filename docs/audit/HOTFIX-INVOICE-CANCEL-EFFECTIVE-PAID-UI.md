# Audit Log — HOTFIX Hóa đơn đã hủy: hiển thị Khách đã trả hiệu lực đúng 0đ (Frontend)

## Background Context
- **Repository**: `cuongdesignnb/kiot`
- **Issue**: Although the previous hotfix correctly implemented backend parameters and detail view summaries, the main table list's "Khách đã trả" column still referenced the snapshot `invoice.customer_paid` directly, creating confusion regarding the validity of payments for cancelled invoices.
- **Safety Requirements**:
  - Only modify frontend files.
  - Do not change `invoice.customer_paid` in the database.
  - Do not alter backend logic, queries, or services.

## Implemented Solutions

### Frontend Changes
- **[Invoices/Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Invoices/Index.vue#L470)**:
  - Updated the "Khách đã trả" table cell.
  - Normal invoices render the active paid value as usual.
  - Cancelled invoices render the primary value as `0đ` using the `effectiveCustomerPaid(invoice)` helper.
  - If a cancelled invoice had a historical paid amount, it displays a small helper badge: `Trước hủy: xxxđ` underneath the main value.

---

## Verification Results
- Vite build completed successfully in **7.04 seconds**.
- All related unit and integration tests passed:
  - `CancelInvoicePaymentDebtFlowTest` — **PASS**
  - `CancelInvoiceTest` — **PASS**
  - `CustomerDebt` — **PASS**
  - `RR09DamageStockTest` — **PASS**
