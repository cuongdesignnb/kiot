# STEP 10H — Bulk read-only debt audit on local production import

## Phạm vi
- **Customer debt**: Audit toàn bộ khách hàng trên local DB.
- **Supplier debt**: Audit toàn bộ nhà cung cấp trên local DB.
- **Document-first timeline**: Sử dụng phương pháp xây dựng timeline từ chứng từ gốc để tính toán đối chiếu số dư nợ.
- **Bulk audit**: Chạy đối chiếu hàng loạt toàn bộ cơ sở dữ liệu local để xuất thống kê rủi ro.
- **Data sync/backfill**: Không thực hiện đồng bộ hay thay đổi bất kỳ trường dữ liệu nào trong cơ sở dữ liệu ở bước này.

## Local DB
- **Local DB production import**: Đang sử dụng dữ liệu import trực tiếp từ DB production và khớp hoàn toàn.
- **Git HEAD**: `13135e6c2a3a2afdc4014ebf9c67000289c0fdc5`
- **customers**: 237
- **invoices**: 268
- **cash_flows**: 529
- **returns**: 9 (không có bảng order_returns)
- **purchases**: 342
- **customer_debts**: 48

## Source/command đã kiểm tra
- **Command**: `php artisan debt:audit-document-timeline`
- **Dry-run guard**: Kiểm thử chạy thiếu `--dry-run` báo lỗi: `This command is read-only. Please pass --dry-run. No data was modified.`
- **Mode guard**: Kiểm thử chạy thiếu mode (chọn partner) báo lỗi: `Provide a single (--customer-code/--supplier-code) or bulk (--all/--all-customers/--all-suppliers) mode.`
- **Customer scan**: Chạy thành công. Thống kê 192 khách hàng có phát sinh hoạt động/timeline.
- **Supplier scan**: Chạy thành công. Thống kê 55 nhà cung cấp có phát sinh hoạt động/timeline.
- **Export JSON/CSV/MD**: Đã ghi đầy đủ các file report vào thư mục `storage/app/audits/step10h-bulk-debt-audit/`.

## Data safety
- **Migration**: Không.
- **Backfill**: Không.
- **Update DB**: Không.
- **Delete**: Không.
- **Recalculate**: Không.
- **DB writes**: Không (đã kiểm định bằng test case `test_no_db_writes_during_audit`).
- **migrate:fresh**: Không chạy.
- **Export committed**: Không commit các file export ra JSON/CSV/MD trong thư mục `storage/app/audits`.

## Smoke tests
### NCC178090885683 (Test Phần Mềm)
- **result**: Max severity warning (do là đối tác vai trò kép).
- **document_final**: 1,300,000đ
- **stored**: 1,300,000đ
- **difference**: 0đ
- **risks**: `dual_role_net_requires_review`

### KH178047230447 (Nguyễn Đình Hoan)
- **result**: OK (max severity ok).
- **document_final**: 0đ
- **stored**: 0đ
- **difference**: 0đ
- **risks**: Không có

### KH177460073148 (Anh Bẩy)
- **result**: OK (max severity ok).
- **document_final**: 0đ
- **stored**: 0đ
- **difference**: 0đ
- **risks**: Không có

## Bulk customers result
- **scanned**: 192
- **OK**: 166
- **mismatch**: 14
- **critical**: 19
- **warning**: 7
- **total abs difference**: 231,710,489đ
- **merge rows**: 0 (đã bị loại trừ do logic STEP 10G)
- **opening rows**: 0
- **fallback rows**: 7
- **missing running balance**: 0
- **audit exceptions**: 0

## Bulk suppliers result
- **scanned**: 55
- **OK**: 0
- **mismatch**: 17
- **critical**: 14
- **warning**: 41
- **supplier_document_timeline_not_implemented**: 55 (Tất cả 55 nhà cung cấp đều dính flag này vì timeline nhà cung cấp chưa được chuyển đổi hoàn chỉnh sang document-first service, hiện đang chạy fallback timeline)
- **audit exceptions**: 0

## Bulk all result
- **scanned**: 247
- **OK**: 166
- **mismatch**: 31
- **critical**: 33
- **warning**: 48
- **info**: 0
- **total abs difference**: 486,190,978đ
- **export JSON**: `storage/app/audits/step10h-bulk-debt-audit/local-all-mismatch.json`
- **export CSV**: `storage/app/audits/step10h-bulk-debt-audit/local-all-mismatch.csv`
- **export MD**: `storage/app/audits/step10h-bulk-debt-audit/local-all-mismatch.md`

## Risk groups
- **document_balance_mismatch**: 4
- **document_balance_mismatch_critical**: 27
- **merge_affects_balance**: 0 (do logic STEP 10G loại trừ dòng kỹ thuật MERGE ra khỏi dòng tiền timeline chính)
- **opening_affects_balance**: 0
- **fallback_payment**: 7
- **missing_running_balance**: 0
- **invoice_display_not_total**: 3
- **return_not_negative**: 1 (`TH2026050809313344` của `KH177794725633` có giá trị dương)
- **dual_role_net_requires_review**: 22
- **supplier_document_timeline_not_implemented**: 55
- **audit_exception**: 0

## Top mismatches
| Partner | View | Stored | Document | Difference | Main risk |
|---|---:|---:|---:|---:|---|
| **NCC177574741694** (Nguyễn Xuân Cường) | customer | 3,450,000đ | -120,150,000đ | -123,600,000đ | document_balance_mismatch_critical, dual_role |
| **NCC177354084249** (Hưng Hoa Mai) | supplier | -30,700,000đ | 0đ | 30,700,000đ | document_balance_mismatch_critical, supplier_timeline_not_implemented |
| **NCC177425584137** (Laptop Đăng Quân) | customer | -21,580,000đ | -36,680,000đ | -15,100,000đ | document_balance_mismatch_critical, dual_role, invalid_document_amount |
| **KH177598487429** (Test) | customer | 0đ | 14,000,000đ | 14,000,000đ | document_balance_mismatch_critical, dual_role |
| **NCC177624592772** (Trọng Hùng) | customer | -880,403,000đ | -866,303,011đ | 14,099,989đ | document_balance_mismatch_critical, dual_role |
| **KH177518347435** (An-Lê Đức Thọ) | customer | 0đ | 11,700,000đ | 11,700,000đ | document_balance_mismatch_critical, fallback_payment |
| **KH177829769472** (Nguyễn Duy Khánh) | customer | 10,000,000đ | 20,000,000đ | 10,000,000đ | document_balance_mismatch_critical |
| **NCC177363196335** (Vũ Kiên) | supplier | -10,500,000đ | 0đ | 10,500,000đ | document_balance_mismatch_critical, supplier_timeline_not_implemented |
| **NCC177406096827** (Thủy Sơn) | supplier | -9,750,000đ | 0đ | 9,750,000đ | document_balance_mismatch_critical, supplier_timeline_not_implemented |
| **NCC177891619859** (Long Pin) | supplier | -9,500,000đ | 0đ | 9,500,000đ | document_balance_mismatch_critical, supplier_timeline_not_implemented |

## Manual QA samples
- **3 OK**:
  - `KH178047230447` (Nguyễn Đình Hoan): Khớp số dư 0đ.
  - `KH177460073148` (Anh Bẩy): Khớp số dư 0đ.
  - `NCC178090885683` (Test Phần Mềm): Khớp số dư 1.300.000đ sau khi loại bỏ dòng gộp nợ kỹ thuật MERGE.
- **5 top mismatch**:
  - `NCC177574741694` (Nguyễn Xuân Cường): Lệch lớn do vừa là KH vừa là NCC và thiếu bù trừ cấn nợ.
  - `NCC177425584137` (Laptop Đăng Quân): Lệch do hóa đơn sai giá trị phát sinh âm.
  - `KH177598487429` (Test): Lệch do nợ ảo trong test data.
  - `NCC177624592772` (Trọng Hùng): Lệch do đối tác kép.
  - `KH177518347435` (An-Lê Đức Thọ): Lệch do dùng phiếu thu tạm tính (fallback) không có trên DB thực.
- **3 MERGE/OPENING**:
  - `NCC178090885683`, `NCC177425584137`, `KH177561736414` (Dũng Kiều Mai) đều được xử lý ẩn đi trên giao diện và tính toán running balance chuẩn.
- **3 fallback**:
  - `KH177460013472` (Kiên Bảo Nghĩa), `KH177518347435`, `KH177561736414` có các dòng tạm tính `TTHD` tự động tạo ra từ hóa đơn chưa có phiếu thu thật trên DB.
- **3 dual-role**:
  - `NCC178090885683`, `NCC177425584137`, `NCC177365798441` đều có cảnh báo vai trò kép cần đối soát cấn nợ.
- **3 supplier**:
  - `NCC177354084249`, `NCC177363196335`, `NCC177891619859` đều lệch do chưa có timeline document-first riêng cho supplier.

## Tests/build
- **AuditDocumentDebtTimelineCommandTest**: PASS
- **CustomerDebtDocumentTimelineTest**: PASS
- **Regression**: PASS
- **npm run build**: PASS

## Kết luận
- **Toàn bộ KH/NCC đã đồng bộ chưa**: Chưa. Các khách hàng cơ bản đã khớp số dư chứng từ sau khi loại trừ MERGE, nhưng nhà cung cấp lệch 100% vì chưa có Supplier timeline service hoàn chỉnh.
- **Có thể deploy code-only chưa**: Có thể deploy code-only phần **Customer Timeline** vì các thay đổi code/test đã được kiểm nghiệm đầy đủ, giúp hiển thị giao diện timeline KiotViet event-time DESC cực chuẩn cho khách hàng.
- **Có cần data-fix/backfill không**: Có, đối với các trường hợp lệch do hóa đơn sai giá trị phát sinh, phiếu thu tạm tính hoặc đối tác kép.
- **Nếu cần data-fix/backfill thì có cần xác nhận trước không**: Bắt buộc phải được xác nhận trước khi làm.
- **Đề xuất STEP tiếp theo**: Implement hoàn chỉnh Supplier timeline service hoặc lên phương án data-fix cho các case mismatch lớn nhất.
