# P0 — Chặn mirror chéo làm nhân đôi timeline công nợ dual-role

## Phạm vi

Hotfix này xử lý riêng lỗi timeline của đối tác vừa là khách hàng vừa là nhà
cung cấp bị dựng lại các chứng từ phía khách hàng trên màn hình Nhà cung cấp.
Không thay đổi số dư production, không migration, không backfill và không xóa
bất kỳ chứng từ hoặc checkpoint lịch sử nào.

## Root cause

Màn hình Nhà cung cấp từng gửi `view=partner` khi đối tác có hai vai trò.
Tham số trình bày cũ này đi sâu vào `SupplierDebtDomainEventSource` và bật
khối mirror khách hàng. Hậu quả là cùng hóa đơn/phiếu thu được dựng lại lần
hai với dấu và định danh khác trên timeline Nhà cung cấp.

Trường hợp tái hiện:

- Đối tác `KH178333171285` — Anh Hữu Trần Cung.
- Hóa đơn `HD178333171519` trị giá 5.600.000đ và phiếu thu thật
  `PT26072811032316`.
- Phiếu nhập bị hủy `PN20260813100850` trị giá 13.000.000đ.
- Phiếu nhập còn mở `PN20260815145835` trị giá 15.300.000đ.

Mirror cũ làm phiếu thu thật và fallback `TTHD*` xuất hiện thừa trên màn hình
Nhà cung cấp, làm số cuối cùng thành 9.700.000đ thay vì 15.300.000đ.

## Contract sau hotfix

- `view=partner` vẫn được chấp nhận trong URL cũ nhưng bị bỏ trước khi gọi
  nguồn evidence; nó không còn là source selector.
- Frontend Nhà cung cấp không còn gửi `view=partner`.
- Customer source chỉ sinh evidence phía khách hàng; Supplier source chỉ sinh
  evidence phía nhà cung cấp.
- Canonical reducer kết hợp hai source đúng một lần và quyết định orientation
  cho dual-role.
- Phiếu thu/phiếu chi thật luôn là evidence ưu tiên. Fallback `TTHD*`/`TTNH*`
  chỉ được sinh khi hoàn toàn không có evidence thanh toán thật cho chứng từ.
  Không sinh phần fallback còn lại sau một thanh toán thật vì sẽ nhân đôi một
  tác động kinh tế.
- URL API Nhà cung cấp có/không có `view=partner` trả cùng event stream và cùng
  `source_identity_hash`.

## Bất biến an toàn dữ liệu

Hotfix này chỉ thay đổi cách dựng timeline đọc.

```text
MIGRATION=NO
BACKFILL=NO
PRODUCTION_DATA_DELETE=NO
PRODUCTION_BALANCE_MUTATION=NO
CHECKPOINT_STORAGE_MUTATION=NO
```

Các cột compatibility `customers.debt_amount` và
`customers.supplier_debt_amount` vẫn là số liệu legacy trong P0. Nếu canonical
timeline khác cột đã lưu, chênh lệch được báo riêng; P0 không ghi lại số dư để
tránh sửa dữ liệu lịch sử khi chưa có audit/opening balance được duyệt.

## Evidence local QA

Môi trường: MySQL disposable `kiot_p0_debt_timeline_qa`, migration của source
branch, không dùng production dump.

| Kiểm tra | Kết quả |
| --- | --- |
| Fixture dual-role tái hiện `KH178333171285` | PASS |
| Customer raw final balance | `-15.300.000` |
| Supplier raw final balance | `+15.300.000` |
| Identity/hash giữa hai orientation | PASS |
| `PT26072811032316` đúng một lần | PASS |
| Fallback `TTHD*` sau phiếu thu thật | Không có |
| API Supplier với/không `view=partner` | Cùng stream/hash |
| P0 contract test | 14 tests / 136 assertions PASS |
| Hai test bổ sung mirror + real receipt | 2 tests / 13 assertions PASS |
| Fresh schema migrations | PASS |

Một nhóm regression timeline cũ đã đỏ ngay ở base
`b4aec69457add526cba69330bf04612b1839b1aa`: 5 error + 14 failure trong 22
tests; nhóm detail legacy cũng đỏ 7/47 tests. Các lỗi so sánh với baseline có
nguồn từ contract/label legacy hiện hữu, không phải lỗi mới từ hotfix này. P0
không sửa hoặc làm xanh giả các test đó; việc chuẩn hóa test và chuyển source
đọc thuộc rollout journal/projection ở bước tiếp theo.

## Lộ trình sau P0

P0 không phải là cutover sổ cái. Các bước sau tách thành PR độc lập:

1. Bảng journal/projection additive và shadow-write.
2. Một `PartnerDebtMutationCoordinator` cho mọi write path.
3. Audit opening balance được duyệt riêng, read switch và lịch sử archive.

Cho đến khi các gate này đạt, không tự động sửa drift lịch sử, không tạo
checkpoint mới để ép balance, và không đổi nguồn đọc production chỉ dựa vào
stored columns.

## Rollback

Vì không có migration/data mutation, rollback code chỉ cần revert squash commit
P0. Không cần khôi phục database, không cần xóa checkpoint, và không cần
backfill.
