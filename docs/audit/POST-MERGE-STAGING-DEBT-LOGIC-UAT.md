# Post-Merge Staging Debt Logic UAT

## 1. Phạm vi và source

- Repo: `cuongdesignnb/kiot`
- PR: `#1`
- Merge method: squash
- Merge commit đã triển khai staging: `8d56564c0e9046f226f2558e93020a45d0392a27`
- Staging chỉ kiểm tra logic công nợ, payment allocation, order payment summary và partner merge.
- Không sửa stock movement, tồn kho, costing, giá vốn, serial/IMEI, payroll, warranty hoặc repair.

## 2. Môi trường staging

| Mục | Giá trị |
|---|---|
| URL | `http://127.0.0.1:8093` |
| Source path | `D:\Kiot\kiotviet-clone.worktrees\staging-debt-main` |
| Database | MySQL `sales_staging_debt_post_merge` |
| Database host | Docker `sales_mysql_test`, local port `3319` |
| Dữ liệu | Seed giả lập dành riêng cho UAT, không phải clone production |
| Commit deployed | `8d56564c0e9046f226f2558e93020a45d0392a27` |

Các service, command và ba migration mới đều được xác nhận có mặt trên commit staging.

## 3. Cài đặt và build

| Lệnh | Kết quả |
|---|---|
| `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction` | PASS |
| `npm ci` | PASS, thêm 100 package |
| `npm run build` | PASS, Vite 5.4.21, 918 module |

Phiên bản môi trường:

- PHP `8.2.29`
- Composer `2.8.8`
- Node `20.15.1`
- npm `10.7.0`

Cảnh báo không chặn:

- PHP CLI báo thiếu extension Oracle/Firebird; staging dùng MySQL nên không ảnh hưởng bài kiểm tra.
- Composer báo hai PSR-4 warning có sẵn tại `App\View\Components\layouts`.
- `@vitejs/plugin-vue` khuyến nghị Node mới hơn.
- `npm ci` báo 8 vulnerability hiện hữu: 4 moderate, 4 high. Không tự nâng dependency trong bước này.

## 4. Migration staging

Ba migration additive:

1. `2026_06_12_120000_create_customer_payment_allocations_table.php`
2. `2026_06_12_120100_add_order_deposit_applied_amount_to_invoices.php`
3. `2026_06_12_120200_add_partner_merge_provenance.php`

Kết quả:

- Trước migration: đúng ba migration trên ở trạng thái `Pending`.
- `php artisan migrate --pretend`: PASS; chỉ sinh SQL cho ba migration mới, không phát hiện bảng/cột trùng hoặc lỗi SQL.
- `php artisan migrate --force`: PASS trên database staging riêng.
- `php artisan migrate:status`: cả ba migration ở batch `2`, trạng thái `Ran`.
- Không dùng `migrate:fresh`.
- Không backfill, cleanup hoặc sửa dữ liệu cũ.

Cache/queue staging:

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | PASS |
| `php artisan config:cache` | PASS |
| `php artisan route:cache` | PASS |
| `php artisan view:cache` | PASS |
| `php artisan queue:restart` | PASS |

## 5. Legacy order audit

Đã chạy trên staging:

```text
php artisan orders:audit-legacy-amount-paid
php artisan orders:audit-legacy-amount-paid --json
```

| Chỉ số | Kết quả |
|---|---:|
| Total orders checked | 2 |
| Orders có `amount_paid > 0` | 1 |
| Orders có invoice | 2 |
| Orders có paid invoice | 2 |
| Deposit only hoặc không có invoice | 0 |
| Suspected legacy cumulative `amount_paid` | 1 |

Mẫu nghi ngờ:

- Order: `STG-UAT-DH-LEGACY`
- Lý do: `amount_paid_plus_invoice_paid_exceeds_order_total`
- Đề xuất: `manual_review_before_production_migration`

Audit chỉ đọc. Snapshot trước và sau command không đổi:

| Bảng | Trước | Sau |
|---|---:|---:|
| `orders` | 2 dòng, tổng `amount_paid` 1.200.000 | Không đổi |
| `invoices` | 4 dòng, tổng `customer_paid` 2.400.000 | Không đổi |
| `customer_debts` | 5 dòng, tổng `amount` 3.100.000 | Không đổi |

Không sửa order nghi ngờ, không backfill và không tạo command remediation. Nếu production audit phát hiện dữ liệu tương tự, cần task riêng và BA phê duyệt.

## 6. Regression tests

Các test chạy trên source `main` cùng commit và database testing riêng:

| Nhóm | Kết quả |
|---|---|
| `SapoDebtParityTest` | PASS, 12 test |
| `Orders` | PASS, 23 test |
| `POS` | PASS, 65 test |
| `CashFlow` + `CashFlows` | PASS, 14 test |
| `Report` + `Reports` | PASS, 109 test |
| Tổng | PASS, 223 test, 1119 assertion |
| `git diff --check` | PASS |

Không dùng production DB và không dùng `migrate:fresh`.

## 7. UAT sáu case

UAT chạy bằng browser trên staging URL với tài khoản admin staging `stg-uat-admin@kiot.local`. Dữ liệu đều có prefix `STG-UAT-`.

### Case 1 - Order partial payment

- Order `STG-UAT-DH-1500`: total 1.500.000, paid 1.200.000.
- UI list/detail hiển thị `order_paid_total = 1.200.000`, còn nợ 300.000.
- Kết quả: PASS.

### Case 2 - Debt overpayment

- Customer `STG-UAT-OVERPAY`, invoice `STG-UAT-HD-OVERPAY`.
- Thu 1.500.000; allocated 1.300.000; unallocated 200.000.
- CashFlow giữ đủ 1.500.000; invoice nhận 1.300.000; debt sau thu là -200.000.
- UI hiển thị credit âm đúng.
- Kết quả: PASS.

### Case 3 - Future credit offset

- Customer `STG-UAT-CREDIT`.
- Debt trước mua -200.000; invoice mới 1.500.000; không trả thêm.
- Debt sau mua 1.300.000; credit cũ không biến thành cọc.
- Kết quả: PASS.

### Case 4 - Merge marker zero

- Merge `STG-UAT-MERGE-SOURCE` vào `STG-UAT-MERGE-TARGET`.
- Marker `MERGE-PARTNER-4-TO-5`, amount `0`, reference-only và không ảnh hưởng debt balance.
- Target debt 300.000; source inactive và `merged_into_id = 5`; không double thành 600.000.
- Kết quả: PASS.

### Case 5 - Dual-role net zero

- Partner `STG-UAT-DUAL`: customer debt 200.000 và supplier debt 200.000.
- Màn customer và supplier đều hiển thị net debt 0; timeline không double balance.
- Kết quả: PASS.

### Case 6 - Merged-source guard

- Thử tạo invoice, order, customer payment, purchase, supplier payment và linked CashFlow bằng source đã merge.
- Tất cả trả `422` với message hướng sang target partner.
- POS lookup không trả source đã merge.
- Database xác nhận không phát sinh order, invoice, purchase hoặc CashFlow ngoài dự kiến cho source.
- Kết quả: PASS.

Browser UAT: `1 passed`, gồm đủ sáu case. Ảnh và JSON kết quả lưu ngoài repo tại `D:\Kiot\staging-debt-uat-evidence`; không commit artifact staging.

## 8. Data safety

| Hành động | Trạng thái |
|---|---|
| Production deploy | Không chạy |
| Production migration | Không chạy |
| Production audit | Không chạy |
| Production backup/restore | Không chạy |
| Backfill | Không |
| Cleanup legacy MERGE/order | Không |
| Update/delete dữ liệu cũ | Không |
| Stock/costing/serial/payroll | Không đụng |

## 9. Production preflight đề xuất

Chỉ chuẩn bị checklist, chưa thực thi:

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git status
git rev-parse HEAD
git fetch origin main
git log --oneline -5
php artisan migrate:status
php artisan migrate --pretend
php artisan orders:audit-legacy-amount-paid
php artisan orders:audit-legacy-amount-paid --json
```

Trước production migration bắt buộc:

1. Backup database production và xác minh khả năng restore.
2. Xác minh commit production hiện tại.
3. Chạy `migrate --pretend`.
4. Chạy audit legacy order read-only sau khi BA cho phép riêng.
5. BA review kết quả audit và phê duyệt migration.
6. Có rollback/forward-fix plan. Rollback ba migration chỉ phù hợp trước khi phát sinh dữ liệu mới.

## 10. Rủi ro còn lại

- Production có thể có order legacy dùng `orders.amount_paid` theo nghĩa cumulative; staging seed đã chứng minh command phát hiện được một mẫu nghi ngờ. Không được tự remediation.
- Invoice legacy có thể thiếu provenance cho phần cọc đã áp dụng; cột mới nullable và không được backfill tự động.
- Nhóm baseline Customers/Suppliers còn lỗi tồn tại trước nhánh công nợ. Lần chạy baseline trước ghi nhận Customers: 118 passed, 30 failed, 1 skipped; Suppliers: 31 passed, 13 failed. Các test phạm vi bắt buộc của thay đổi này đã PASS, nhưng các lỗi baseline cần được quản lý riêng trước production.
- Cảnh báo Node engine, npm vulnerability và PHP extension cần được đội hạ tầng/dependency đánh giá riêng; không phải blocker của sáu case nghiệp vụ staging.
- Staging hiện là seed giả lập, không phải clone production. Production preflight vẫn cần backup và audit read-only trên dữ liệu thật sau phê duyệt.

## 11. Kết luận và quyết định BA cần có

- Ready for production preflight: **Có**, checklist đã chuẩn bị; việc chạy trên production vẫn cần BA xác nhận riêng.
- Ready for production migration: **Chưa**; cần backup, production `migrate --pretend`, audit read-only và BA duyệt kết quả.
- Ready for production deploy: **Chưa được phê duyệt**.
- Staging deploy, ba migration, build, regression và sáu case UAT: **PASS**.
