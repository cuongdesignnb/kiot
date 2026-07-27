# BA Review — An toàn đồng bộ sản phẩm và hiển thị chứng từ lịch sử

## 1. Mục đích tài liệu

Tài liệu này mô tả phần đã được sửa trong hệ thống KIOT để BA có thể review nghiệp vụ và nghiệm thu.

Hotfix tập trung vào ba vấn đề:

1. Làm rõ quy tắc an toàn khi Website PC đồng bộ sản phẩm từ KIOT.
2. Khắc phục tình trạng mã hoặc tên sản phẩm bị trống trên popup chứng từ lịch sử khi sản phẩm đã bị xóa mềm hoặc đã thay đổi thông tin.
3. Chặn thao tác xóa hàng hóa đã có tồn kho, serial hoặc lịch sử nghiệp vụ; đồng thời ghi audit cho cả thao tác thành công và bị chặn.

> **Kết luận điều tra:** `DELETED=137` là số payload tombstone có `sync_status=deleted` mà consumer nhận từ KIOT, không phải nguyên nhân tạo `products.deleted_at`. Sự cố được xác định là 100 sản phẩm bị xóa qua hai request trình duyệt đã xác thực tới `POST /products/bulk-destroy`; implementation cũ của `ProductController::bulkDestroy` soft-delete trực tiếp các ID được gửi lên. Chưa xác định được tài khoản người dùng cụ thể từ dữ liệu session/audit lịch sử còn lại.

## 2. Tóm tắt vấn đề

### 2.1. Đồng bộ sản phẩm có nguy cơ hiểu sai dữ liệu bị thiếu

Một lần full sync ghi nhận:

```text
TOTAL_REMOTE=550
UNCHANGED=550
DELETED=137
ERRORS=0
```

Metric phía consumer tăng `DELETED` mỗi khi nhận một payload có `sync_status=deleted`. Vì vậy số `137` chỉ chứng minh consumer đã nhận 137 tombstone từ KIOT; số liệu này không chứng minh consumer đã ghi `products.deleted_at`, cũng không chứng minh 137 sản phẩm bị thiếu khỏi response.

Kết luận về sync:

- Consumer không soft-delete sản phẩm chỉ vì sản phẩm thiếu khỏi response.
- Consumer không tạo `products.deleted_at` trong sự cố này; sync không gây ra thao tác xóa.
- KIOT phát tombstone sau khi Product đã bị soft-delete bởi request bulk-delete.

Các trường hợp sản phẩm có thể không xuất hiện gồm:

- Sản phẩm được tạo riêng ở hệ thống nhận và không thuộc quyền quản lý của KIOT.
- Request chỉ lấy sản phẩm đang hoạt động hoặc đang được phép bán.
- Bộ lọc, phân trang hoặc request đồng bộ chưa lấy đủ dữ liệu.
- Sản phẩm đang ở trạng thái inactive hoặc không được phát hành lên Website PC.

### 2.2. Popup chứng từ lịch sử bị trống thông tin sản phẩm

Popup phiếu nhập hoặc công nợ có thể không hiển thị mã và tên sản phẩm khi:

- Product đã bị soft-delete.
- Quan hệ tới Product không còn trả về dữ liệu.
- Code đọc nhầm trường `product.code`, trong khi mã sản phẩm thực tế được lưu ở `product.sku`.
- Chứng từ đã lưu snapshot `product_code` và `product_name`, nhưng popup không ưu tiên sử dụng snapshot này.

### 2.3. Nguyên nhân gốc đã xác nhận của sự cố xóa 100 sản phẩm

Một browser session đã đăng nhập thành công lúc `2026-07-24 14:54:59 +0700` và gửi hai request đã xác thực tới endpoint `POST /products/bulk-destroy`:

| Request | Thời gian | Số Product |
|---|---:|---:|
| 1 | `2026-07-24 14:59:31 +0700` | 50 |
| 2 | `2026-07-24 14:59:41 +0700` | 50 |

Hai request đến từ IP `42.116.14.105`, user-agent Chrome 138 trên Macintosh. Hai tập ID khớp chính xác với hai batch `deleted_at`, mỗi batch 50 Product. Implementation cũ của `ProductController::bulkDestroy` trực tiếp soft-delete các ID được gửi lên, không có guard kiểm tra tồn kho, serial hoặc lịch sử nghiệp vụ.

Không được gán hành động cho một nhân viên cụ thể: session và audit lịch sử hiện có không còn đủ dữ liệu để xác định tài khoản đã thực hiện. `ACTOR_ACCOUNT=UNRESOLVED`.

```text
ROOT_CAUSE_CONFIRMED=YES
DELETE_ENDPOINT=POST /products/bulk-destroy
DELETE_REQUEST_1=2026-07-24 14:59:31 +0700
DELETE_REQUEST_1_COUNT=50
DELETE_REQUEST_2=2026-07-24 14:59:41 +0700
DELETE_REQUEST_2_COUNT=50
TOTAL_INCIDENT_PRODUCTS=100
SOURCE_IP=42.116.14.105
USER_AGENT=Chrome 138 on Macintosh
LOGIN_SUCCESS=2026-07-24 14:54:59 +0700
SYNC_CAUSED_DELETE=NO
ACTOR_ACCOUNT=UNRESOLVED
```

### 2.4. Bằng chứng audit sự cố và khả năng restore

Audit trên dữ liệu sự cố cho kết quả:

| Chỉ số | Kết quả |
|---|---:|
| Products audited | 100 |
| Expected IDs match | YES |
| Restore candidates | 100 |
| Có lịch sử nghiệp vụ | 100 |
| Có tồn kho khác 0 | 34 |
| Tổng tồn kho | 197 |
| Có serial | 35 |
| Có phiếu nhập | 97 |
| Có hóa đơn | 24 |
| Có giao dịch kho | 99 |
| Xung đột SKU/remote với Product active | 0 |
| Sẵn sàng restore sau deploy guard | YES |

```text
PRODUCTS_AUDITED=100
EXPECTED_IDS_MATCH=YES
RESTORE_CANDIDATES=100
WITH_BUSINESS_HISTORY=100
WITH_NONZERO_STOCK=34
STOCK_QUANTITY_SUM=197
WITH_SERIALS=35
WITH_PURCHASES=97
WITH_INVOICES=24
WITH_STOCK_MOVEMENTS=99
ACTIVE_SKU_OR_REMOTE_CONFLICTS=0
RESTORE_READY_AFTER_DEPLOY=YES
```

Bằng chứng được lưu ngoài Git, không commit dữ liệu production vào repository:

```text
/www/backup/database/kiot-product-delete-incident-20260727-181724/product-deletion-audit-direct.json
SHA256=e95139667411d2f358d227d3d8a55b641a53fbb5550058ca9850d1dd7acb8cb5

/www/backup/database/kiot-product-delete-incident-20260727-181724/restore-conflict-audit.json
SHA256=12754735e481323f72eb9e29c9612e2afc0276caf154d4a57dd3b1eebff498fe
```

## 3. Phạm vi đã sửa

### 3.1. Chuẩn hóa hợp đồng đồng bộ sản phẩm

API sản phẩm bổ sung thông tin an toàn trong `meta`:

```json
{
  "dataset_complete": true,
  "deletion_policy": "explicit_tombstone_only",
  "missing_products_are_deleted": false
}
```

Ý nghĩa nghiệp vụ:

| Trường | Ý nghĩa |
|---|---|
| `dataset_complete` | Trang hiện tại là trang cuối của đúng chuỗi filter/cursor đang gọi. Phía nhận vẫn phải chứng minh đã lấy thành công toàn bộ các trang trước đó. |
| `deletion_policy` | Chỉ trạng thái xóa được KIOT trả về tường minh mới được coi là tín hiệu xóa. |
| `missing_products_are_deleted` | Luôn là `false`: không xuất hiện trong response không đồng nghĩa đã bị xóa. |

Quy tắc sau hotfix:

```text
Không xuất hiện trong response ≠ Đã bị xóa
```

Chỉ payload có trạng thái tường minh sau mới được xem là tombstone:

```json
{
  "sync_status": "deleted"
}
```

### 3.2. Khôi phục khả năng đọc Product đã soft-delete trong lịch sử

Các dòng chứng từ lịch sử sau vẫn có thể đọc Product đã soft-delete:

- Hóa đơn.
- Đơn hàng.
- Phiếu nhập.
- Phiếu trả nhà cung cấp.
- Phiếu khách trả hàng.
- Lịch sử xuất nhập kho.

Thay đổi này chỉ phục vụ đọc và hiển thị lịch sử. Hotfix không kích hoạt lại sản phẩm, không thay đổi tồn kho và không tạo lại giao dịch kho.

### 3.3. Ưu tiên snapshot trên chứng từ

Đối với phiếu nhập và popup công nợ, mã và tên sản phẩm được lấy theo thứ tự:

1. `product_code` và `product_name` đã lưu trên dòng chứng từ.
2. `product.sku` và `product.name` hiện tại nếu chứng từ không có snapshot.
3. Barcode nếu không có SKU.
4. Chuỗi rỗng chỉ khi cả snapshot và quan hệ Product đều không còn dữ liệu.

Snapshot được ưu tiên để đảm bảo chứng từ luôn phản ánh đúng thông tin tại thời điểm phát sinh, kể cả khi sản phẩm sau đó được đổi SKU hoặc đổi tên.

### 3.4. Chặn xóa Product đã phát sinh nghiệp vụ

Mọi đường xóa Product đang có trong ứng dụng KIOT đều đi qua `ProductDeletionGuard`. Guard từ chối xóa khi sản phẩm có tồn kho khác 0, serial hoặc bất kỳ lịch sử nghiệp vụ nào như nhập hàng, hóa đơn, đơn hàng, trả hàng, thẻ kho, kiểm kho, chuyển kho, xuất hủy, sửa chữa hoặc bảo hành.

Xóa hàng loạt thực hiện preflight toàn bộ danh sách và kiểm tra lại trong transaction. Nếu một sản phẩm bị chặn thì không sản phẩm nào trong yêu cầu bị xóa.

Thông báo nghiệp vụ:

```text
Không thể xóa hàng hóa đã phát sinh tồn kho hoặc chứng từ. Hãy sử dụng chức năng Ngừng kinh doanh.
```

### 3.5. Audit thao tác xóa và lệnh đối soát chỉ đọc

Mỗi lần xóa thành công hoặc bị chặn đều ghi vào `activity_logs`, gồm actor, request ID, route, IP, user agent, nguồn gọi, danh sách ID/SKU, kết quả, lý do và thời điểm. Hotfix tận dụng bảng audit hiện có, không tạo migration.

Lệnh sau chỉ đọc Product đã soft-delete và thống kê tồn kho/lịch sử để phục vụ phân loại thủ công:

```bash
php artisan products:audit-deletions --from="2026-07-24 14:55:00" --to="2026-07-24 15:05:00" --json
```

`restore_candidate=true` chỉ là tín hiệu cần BA/kỹ thuật review vì sản phẩm đã xóa vẫn có tồn kho, serial hoặc lịch sử. Lệnh không restore và không thực hiện `INSERT`, `UPDATE` hoặc `DELETE`.

Command được đăng ký tường minh trong Laravel bootstrap. Bằng chứng cuối phải chạy qua Artisan bình thường:

```bash
php artisan list --raw | grep -F 'products:audit-deletions'
php artisan products:audit-deletions --help
```

## 4. Kết quả trước và sau hotfix

| Tình huống | Trước hotfix | Sau hotfix |
|---|---|---|
| Product đang hoạt động | Có thể hiển thị mã sai do đọc `code` | Hiển thị snapshot hoặc `sku` đúng |
| Product đã đổi SKU/tên | Có thể hiển thị giá trị hiện tại thay vì lịch sử | Giữ nguyên snapshot lúc lập chứng từ |
| Product inactive | Quan hệ vẫn đọc được | Tiếp tục hiển thị bình thường |
| Product soft-delete | Quan hệ có thể trả về `null`, popup bị trống | Quan hệ lịch sử vẫn đọc được Product |
| Quan hệ Product bằng `null` nhưng snapshot còn | Popup có thể trống | Hiển thị mã và tên từ snapshot |
| Sản phẩm vắng mặt trong response sync | Có thể bị phía nhận hiểu là cần xóa | API tuyên bố rõ không phải tín hiệu xóa |
| Tombstone `sync_status=deleted` | Chưa có quy tắc đủ rõ cho consumer | Là tín hiệu xóa tường minh duy nhất |
| Xóa Product có tồn kho/chứng từ | Có thể tạo soft-delete và làm mất Product khỏi luồng hiện hành | Bị chặn, hướng người dùng sang Ngừng kinh doanh và có audit |
| Xóa hàng loạt có một Product không hợp lệ | Có nguy cơ xóa một phần | Preflight toàn bộ; một Product bị chặn thì không xóa Product nào |

## 5. Kịch bản BA cần nghiệm thu

### AC-01 — Sản phẩm không xuất hiện trong response

**Given** một sản phẩm local không xuất hiện trong response sản phẩm của KIOT.

**When** phía nhận xử lý kết quả sync.

**Then** sản phẩm không được tự động soft-delete chỉ vì bị thiếu khỏi response.

### AC-02 — Tombstone tường minh

**Given** KIOT trả về một sản phẩm có `sync_status=deleted`.

**When** phía nhận xử lý payload.

**Then** phía nhận có thể áp dụng quy trình inactive/archive theo đặc tả riêng, nhưng không được xóa quan hệ chứng từ lịch sử.

### AC-03 — Phân trang chưa hoàn chỉnh

**Given** một trang sync bị timeout hoặc lỗi.

**When** phía nhận chưa đi hết chuỗi `next_cursor`.

**Then** không được coi dataset là hoàn chỉnh và không được chạy đối soát xóa theo dữ liệu bị thiếu.

### AC-04 — Product đổi SKU hoặc tên

**Given** phiếu nhập đã lưu mã và tên sản phẩm tại thời điểm lập phiếu.

**When** Product được đổi SKU hoặc tên sau đó.

**Then** popup phiếu nhập vẫn hiển thị mã và tên cũ từ snapshot.

### AC-05 — Product đã soft-delete

**Given** Product liên quan tới hóa đơn hoặc phiếu nhập đã bị soft-delete.

**When** người dùng mở chi tiết chứng từ lịch sử.

**Then** mã và tên sản phẩm vẫn hiển thị, chứng từ không bị mất liên kết hiển thị.

### AC-06 — Quan hệ Product không còn nhưng snapshot vẫn còn

**Given** dòng phiếu nhập còn `product_code` và `product_name`, nhưng quan hệ Product trả về `null`.

**When** người dùng mở popup chi tiết.

**Then** popup hiển thị đầy đủ mã và tên từ snapshot.

### AC-07 — Không thay đổi dữ liệu nghiệp vụ

**Given** hotfix được triển khai.

**When** mở hoặc đọc chứng từ lịch sử.

**Then** tồn kho, giá vốn, công nợ, số lượng dòng chứng từ và primary key không thay đổi.

### AC-08 — Chặn xóa hàng hóa đã phát sinh

**Given** Product có tồn kho, serial hoặc ít nhất một dòng lịch sử nghiệp vụ.

**When** người dùng xóa đơn hoặc xóa hàng loạt.

**Then** Product không bị soft-delete, người dùng nhận thông báo dùng chức năng Ngừng kinh doanh và hệ thống ghi audit `blocked` kèm lý do.

### AC-09 — Xóa hàng loạt là atomic

**Given** danh sách xóa có một Product sạch và một Product đã phát sinh nghiệp vụ.

**When** người dùng xác nhận xóa hàng loạt.

**Then** cả hai Product đều được giữ nguyên; không có partial delete.

### AC-10 — Đối soát soft-delete không sửa dữ liệu

**Given** khoảng thời gian cần điều tra Product đã soft-delete.

**When** kỹ thuật chạy `products:audit-deletions --json`.

**Then** lệnh trả về các chỉ số phục vụ phân loại và không restore hay cập nhật database.

## 6. Bằng chứng kiểm thử

| Hạng mục | Kết quả |
|---|---|
| QA guard xóa Product, đăng ký Artisan command và hiển thị lịch sử | 14 test pass, 56 assertion |
| `php artisan list --raw` có `products:audit-deletions` | Pass |
| `php artisan products:audit-deletions --help` | Pass |
| Toàn bộ test module PC integration | Pass |
| PHP syntax check | Pass |
| Laravel Pint | Pass |
| Frontend production build | Pass |
| Git diff check | Pass |
| GitHub Actions — Provider contract and POS regression | Pass |

Docker MySQL chỉ được bật trong thời gian QA và đã được tắt sau khi hoàn tất.

## 7. Không thuộc phạm vi đã hoàn tất

Các đầu việc sau chưa được tuyên bố hoàn tất:

- Xác định tài khoản người dùng cụ thể đã sở hữu browser session thực hiện hai request bulk-delete; dữ liệu hiện tại chỉ đủ xác nhận session đã xác thực.
- Restore có chọn lọc 100 sản phẩm được chứng minh là bị hai request bulk-delete xóa nhầm.
- Chạy dry-run và real full sync sau deploy.
- Smoke test và hậu kiểm production.

Không được merge hoặc deploy hotfix như một giải pháp hoàn chỉnh cho sự cố cho tới khi hai Draft PR được review, guard được phê duyệt và kế hoạch restore có kiểm soát được phê duyệt. Không blind restore.

## 8. Checklist BA review

- [ ] Đồng ý quy tắc “không xuất hiện trong response không đồng nghĩa đã bị xóa”.
- [ ] Đồng ý chỉ tombstone `sync_status=deleted` là tín hiệu xóa tường minh.
- [ ] Đồng ý snapshot trên chứng từ được ưu tiên hơn tên/SKU hiện tại.
- [ ] Xác nhận Product inactive hoặc soft-delete vẫn phải xem được trong lịch sử.
- [ ] Xác nhận hotfix không được thay đổi tồn kho, giá vốn hoặc công nợ.
- [ ] Xác nhận `DELETED=137` là số tombstone consumer nhận từ KIOT, không phải bằng chứng consumer ghi `deleted_at`.
- [ ] Xác nhận Product có tồn kho, serial hoặc lịch sử phải dùng Ngừng kinh doanh thay cho xóa.
- [ ] Xác nhận xóa hàng loạt phải atomic.
- [x] Xác nhận hai request `POST /products/bulk-destroy` tạo đúng hai batch soft-delete 50 + 50 Product.
- [ ] Xác định tài khoản cụ thể của browser session; hiện vẫn `UNRESOLVED`.
- [ ] Chưa chấp thuận merge/deploy cho tới khi có kế hoạch audit và restore production.

## 9. Tham chiếu triển khai

```text
Repository: cuongdesignnb/kiot
Base branch: production-customer-group
Hotfix branch: codex/hotfix-product-sync-safety
Pull request: #36
Commit đã QA: xem HEAD mới nhất của PR KIOT #36 và Draft PR companion được liên kết trong phần bàn giao.
Trạng thái PR: Draft
```
