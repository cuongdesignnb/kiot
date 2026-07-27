# BA Review — An toàn đồng bộ sản phẩm và hiển thị chứng từ lịch sử

## 1. Mục đích tài liệu

Tài liệu này mô tả phần đã được sửa trong hệ thống KIOT để BA có thể review nghiệp vụ và nghiệm thu.

Hotfix tập trung vào hai vấn đề:

1. Làm rõ quy tắc an toàn khi Website PC đồng bộ sản phẩm từ KIOT.
2. Khắc phục tình trạng mã hoặc tên sản phẩm bị trống trên popup chứng từ lịch sử khi sản phẩm đã bị xóa mềm hoặc đã thay đổi thông tin.

> **Lưu ý quan trọng:** Repo KIOT hiện tại là phía cung cấp dữ liệu sản phẩm. Code phía nhận dữ liệu đã tạo kết quả `DELETED=137` không nằm trong repo này. Vì vậy hotfix này chưa được xem là hoàn tất toàn bộ sự cố production và chưa bao gồm khôi phục 137 sản phẩm.

## 2. Tóm tắt vấn đề

### 2.1. Đồng bộ sản phẩm có nguy cơ hiểu sai dữ liệu bị thiếu

Một lần full sync ghi nhận:

```text
TOTAL_REMOTE=550
UNCHANGED=550
DELETED=137
ERRORS=0
```

137 sản phẩm local không xuất hiện trong dữ liệu trả về đã bị phía nhận sync coi là sản phẩm cần xóa. Tuy nhiên, việc một sản phẩm không xuất hiện trong response không chứng minh rằng sản phẩm đó đã bị xóa tại KIOT.

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

## 6. Bằng chứng kiểm thử

| Hạng mục | Kết quả |
|---|---|
| Regression test trực tiếp của hotfix | 13 test pass, 89 assertion |
| Toàn bộ test module PC integration | Pass |
| PHP syntax check | Pass |
| Laravel Pint | Pass |
| Frontend production build | Pass |
| Git diff check | Pass |
| GitHub Actions — Provider contract and POS regression | Pass |

Docker MySQL chỉ được bật trong thời gian QA và đã được tắt sau khi hoàn tất.

## 7. Không thuộc phạm vi đã hoàn tất

Các đầu việc sau chưa được tuyên bố hoàn tất:

- Xác định code phía consumer đã tạo metric `DELETED=137`.
- Xác định chính xác sync run gây sự cố trên production.
- Audit và phân loại 137 sản phẩm bị ảnh hưởng.
- Backup database production.
- Restore có chọn lọc các sản phẩm được chứng minh là bị sync xóa nhầm.
- Chạy dry-run và real full sync sau deploy.
- Smoke test và hậu kiểm production.

Không được merge hoặc deploy hotfix như một giải pháp hoàn chỉnh cho sự cố cho tới khi phía consumer được xác định và chặn hành vi xóa do thiếu dữ liệu.

## 8. Checklist BA review

- [ ] Đồng ý quy tắc “không xuất hiện trong response không đồng nghĩa đã bị xóa”.
- [ ] Đồng ý chỉ tombstone `sync_status=deleted` là tín hiệu xóa tường minh.
- [ ] Đồng ý snapshot trên chứng từ được ưu tiên hơn tên/SKU hiện tại.
- [ ] Xác nhận Product inactive hoặc soft-delete vẫn phải xem được trong lịch sử.
- [ ] Xác nhận hotfix không được thay đổi tồn kho, giá vốn hoặc công nợ.
- [ ] Xác định owner/repository của consumer đang thực hiện full product sync.
- [ ] Chưa chấp thuận merge/deploy cho tới khi có kế hoạch audit và restore production.

## 9. Tham chiếu triển khai

```text
Repository: cuongdesignnb/kiot
Base branch: production-customer-group
Hotfix branch: codex/hotfix-product-sync-safety
Pull request: #36
Commit đã QA: 0e377690ebcbea0f9fc1c1db3e3f18022fe8d37b
Trạng thái PR: Draft
```
