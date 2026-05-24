# Audit Log — HOTFIX Hóa đơn đã hủy: sửa nốt phần chi tiết hiển thị Khách đã trả hiệu lực

## Background Context
- **Repository**: `cuongdesignnb/kiot`
- **Issue**: The expanded detail summary block on the invoices index page still referenced `invoice.customer_paid` directly, causing cancelled invoices to show historical values as active payments rather than effective `0đ`.
- **Safety Requirements**:
  - Only modify frontend files (`resources/js/Pages/Invoices/Index.vue`).
  - Do not edit database rows or schema.
  - Do not edit any backend class.

## Implemented Solutions

### Frontend Changes
- **[Invoices/Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Invoices/Index.vue#L943)**:
  - Updated the "Khách đã trả" row in the details view summary section.
  - Replaced the direct rendering of `invoice.customer_paid` with:
    - Normal invoices display `Khách đã trả` and `invoice.customer_paid`.
    - Cancelled invoices display `Khách đã trả hiệu lực` and `0đ`.
    - Cancelled invoices with historical payments display a secondary row: `Đã trả trước hủy` containing the original `customer_paid` value.

---

## Verification Results
- Vite build completed successfully in **6.78 seconds**.
- Backend test suite `CancelInvoicePaymentDebtFlowTest` passed successfully: **PASS** (4 tests, 31 assertions).
- Excluded all database writes, schema migrations, and backend file edits.
