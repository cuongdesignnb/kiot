# PAYROLL PERMISSION MATRIX PROPOSAL

Không permission nào trong bảng này được tự động cấp production.

| Role | View balance | View ledger | Export | Create payroll | Lock payroll | Pay | Cancel pay | Advance | Cancel advance | Adjust | Rebuild | Override | Reconciliation | Branch scope | Ghi chú |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `super_admin` | Có | Có | Có | Có | Có | Có | Có | Có | Có | Có | Có | Có | View/export | Toàn hệ thống | Toàn bộ quyền |
| Kế toán trưởng | Có | Có | Có | Xem | Không mặc định | Có | Có | Có | Có | Có | Theo duyệt | Có | View/export | Theo phạm vi hoặc toàn hệ thống | Quyền tài chính cao |
| Kế toán | Có | Có | Có | Không | Không | Có | Không | Có | Không | Không | Không | Không | View | Theo branch | Không cấp quyền nhạy cảm |
| HR / quản lý nhân sự | Có | Có | Theo duyệt | Có | Có nếu duyệt | Không | Không | Không | Không | Không | Không | Không | Theo duyệt | Theo branch | Không kiêm thanh toán |
| `branch_admin` | Có | Có | Theo duyệt | Theo duyệt | Theo duyệt | Theo duyệt | Chờ Owner | Theo duyệt | Chờ Owner | Không | Không | Không | View | Branch bắt buộc | Backend branch scope |
| `cashier` | Không | Không | Không | Không | Không | Không | Không | Không | Không | Không | Không | Không | Không | Branch | Mặc định không xem lương |
| `warehouse_staff` | Không | Không | Không | Không | Không | Không | Không | Không | Không | Không | Không | Không | Không | Branch | Không xem lương |
| `task_manager` | Không | Không | Không | Không | Không | Không | Không | Không | Không | Không | Không | Không | Không | Branch | Không xem lương |
| `employee_self_view` | Chưa triển khai | Chưa triển khai | Không | Không | Không | Không | Không | Không | Không | Không | Không | Không | Không | Bản thân | Phase sau |

## Permission mapping

```text
payroll.view
payroll.create
payroll.edit
payroll.lock
payroll.cancel
payroll.pay
payroll.pay.cancel
payroll.advance.create
payroll.advance.cancel
payroll.adjust
payroll.ledger.view
payroll.ledger.export
payroll.rebuild_balance
payroll.override_locked_period
payroll.override_backdate_limit
payroll.reconciliation.view
payroll.reconciliation.export
employee.view_salary_balance
```

## Quyền nhạy cảm

Không cấp ngầm:

```text
payroll.adjust
payroll.rebuild_balance
payroll.override_locked_period
payroll.override_backdate_limit
payroll.pay.cancel
payroll.advance.cancel
```

Mọi quyền phải kiểm tra ở backend và áp dụng branch scope. Ẩn UI không thay thế authorization.

## Quyết định Owner

```text
[ ] Kế toán trưởng có quyền rebuild?
[ ] Branch admin có quyền cancel payment?
[ ] Branch admin có quyền cancel advance?
[ ] HR có quyền lock payroll?
[ ] Kế toán có quyền export ledger?
[ ] Phạm vi toàn hệ thống cho vai trò nào?
```

## Owner Approval Matrix

| Role | Permission group | Đề xuất BA | Owner duyệt | Người duyệt | Ngày duyệt | Ghi chú |
|---|---|---|---|---|---|---|
| `super_admin` | Toàn bộ payroll | Có | Chờ duyệt | | | |
| Kế toán trưởng | Xem số dư lương/tạm ứng | Có | Chờ duyệt | | | |
| Kế toán trưởng | Export ledger | Có | Chờ duyệt | | | |
| Kế toán trưởng | Thanh toán lương | Có | Chờ duyệt | | | |
| Kế toán trưởng | Hủy payment | Có | Chờ duyệt | | | Quyền nhạy cảm |
| Kế toán trưởng | Tạo/hủy tạm ứng | Có | Chờ duyệt | | | |
| Kế toán trưởng | Điều chỉnh số dư | Có | Chờ duyệt | | | Quyền nhạy cảm |
| Kế toán trưởng | Rebuild balance | Theo duyệt | Chờ duyệt | | | Rất nhạy cảm |
| Kế toán trưởng | Override locked period/backdate | Có | Chờ duyệt | | | Rất nhạy cảm |
| Kế toán | Xem/export ledger | Có | Chờ duyệt | | | |
| Kế toán | Thanh toán lương | Có | Chờ duyệt | | | |
| Kế toán | Hủy payment | Không mặc định | Chờ duyệt | | | |
| Kế toán | Tạo tạm ứng | Có | Chờ duyệt | | | |
| Kế toán | Hủy tạm ứng | Không mặc định | Chờ duyệt | | | |
| HR/quản lý nhân sự | Tạo/sửa/chốt bảng lương | Theo duyệt | Chờ duyệt | | | Không kiêm thanh toán |
| HR/quản lý nhân sự | Xem ledger | Có | Chờ duyệt | | | |
| `branch_admin` | Xem payroll/ledger trong branch | Có | Chờ duyệt | | | Bắt buộc branch scope |
| `branch_admin` | Thanh toán trong branch | Theo duyệt | Chờ duyệt | | | |
| `branch_admin` | Hủy payment/advance | Chờ Owner | Chờ duyệt | | | Quyền nhạy cảm |
| `cashier` | Xem dữ liệu lương | Không | Chờ duyệt | | | |
| `warehouse_staff` | Xem dữ liệu lương | Không | Chờ duyệt | | | |
| `task_manager` | Xem dữ liệu lương | Không | Chờ duyệt | | | |

### Nhóm quyền cần Owner duyệt riêng

```text
Xem số dư lương/tạm ứng
Export ledger
Thanh toán lương
Hủy payment
Tạo tạm ứng
Hủy tạm ứng
Điều chỉnh số dư
Rebuild balance
Override locked period
Override backdate limit
Reconciliation view/export
```

### Quyền nhạy cảm không được cấp ngầm

```text
payroll.adjust
payroll.rebuild_balance
payroll.override_locked_period
payroll.override_backdate_limit
payroll.pay.cancel
payroll.advance.cancel
```

## Owner Approval Theo Permission

| Permission | Mức độ nhạy cảm | Role được đề xuất | Owner decision | Ghi chú |
|---|---|---|---|---|
| `payroll.ledger.view` | Cao | super_admin, kế toán trưởng, kế toán, HR theo phạm vi | Chờ duyệt | Xem số dư lương |
| `payroll.ledger.export` | Cao | super_admin, kế toán trưởng, kế toán | Chờ duyệt | Xuất dữ liệu nhạy cảm |
| `payroll.pay` | Cao | super_admin, kế toán trưởng, kế toán | Chờ duyệt | Thanh toán lương |
| `payroll.pay.cancel` | Rất cao | super_admin, kế toán trưởng | Chờ duyệt | Hủy payment |
| `payroll.advance.create` | Cao | super_admin, kế toán trưởng, kế toán | Chờ duyệt | Tạo tạm ứng |
| `payroll.advance.cancel` | Rất cao | super_admin, kế toán trưởng | Chờ duyệt | Hủy tạm ứng |
| `payroll.adjust` | Rất cao | super_admin, kế toán trưởng | Chờ duyệt | Điều chỉnh số dư |
| `payroll.rebuild_balance` | Rất cao | super_admin | Chờ duyệt | Rebuild cache/số dư |
| `payroll.override_locked_period` | Rất cao | super_admin, kế toán trưởng | Chờ duyệt | Override kỳ khóa |
| `payroll.override_backdate_limit` | Rất cao | super_admin, kế toán trưởng | Chờ duyệt | Override lùi ngày |
| `payroll.reconciliation.view` | Cao | super_admin, kế toán trưởng, kế toán | Chờ duyệt | Xem đối soát |
| `payroll.reconciliation.export` | Cao | super_admin, kế toán trưởng | Chờ duyệt | Export đối soát |

Không có quyền nào trong bảng này đã được assign production.

```text
Production assigned: No
Owner decision: Chờ duyệt
```
