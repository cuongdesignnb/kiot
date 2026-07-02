# HOTFIX Audit Log — Nhập hàng: Lưu nháp trình duyệt, tạo nhanh nhà cung cấp không reload trang

## 1. Thông tin chung
- **Tên Hotfix**: Nhập hàng: Lưu nháp trình duyệt, tạo nhanh nhà cung cấp không reload trang.
- **Mã lỗi**: Người dùng mất toàn bộ dữ liệu (items, serials, v.v.) đã nhập trên màn `/purchases/create` khi lỡ F5, reload trang, chuyển tab hoặc khi tạo mới NCC (trang bị reload hoặc chuyển hướng).

---

## 2. Phân tích nguyên nhân (Root Cause)
1. **Màn hình `/purchases/create` lưu toàn bộ thông tin state phiếu nhập ở phía Frontend (Vue state)**. Do đó, khi trình duyệt tải lại (F5), tắt tab hoặc chuyển hướng trang, toàn bộ state này bị xóa sạch.
2. **Khi chưa có nhà cung cấp (NCC), người dùng cần sử dụng modal tạo nhanh**. Trước đây, sau khi tạo nhanh NCC bằng modal, trang đôi khi bị reload hoặc người dùng phải mở tab khác để đồng bộ NCC mới vào database rồi F5 lại màn nhập hàng gây mất toàn bộ thông tin.

---

## 3. Giải pháp thực hiện
1. **Lưu nháp trình duyệt (`localStorage`)**:
   - Sử dụng `localStorage` lưu snapshot dữ liệu phiếu nhập dưới key `kiot.purchase.create.draft.v1`.
   - Cơ chế tự động lưu ngầm (autosave) với debounce `800ms` khi người dùng nhập dữ liệu hoặc thay đổi bất kỳ trường thông tin nào (sử dụng deep watch).
   - Đăng ký sự kiện `beforeunload` để tự động lưu nháp trước khi reload/đóng tab.
   - Thêm banner ở phía đầu trang tạo mới phiếu nhập, cho phép người dùng bấm **Khôi phục** (khôi phục lại toàn bộ state đã lưu bao gồm items, serials, discount, note, v.v.) hoặc **Bỏ qua** (xóa dữ liệu nháp cũ khỏi `localStorage`).
   - Thêm nút **Lưu nháp** thủ công ở footer để chủ động lưu bất cứ lúc nào.
   - Khi lưu phiếu nhập thành công (Inertia success callback), dọn dẹp sạch dữ liệu nháp trong `localStorage`.

2. **Cải tiến luồng tạo nhanh Nhà cung cấp**:
   - Cập nhật handler `@created` của `<QuickCreateCustomerModal>`. Khi tạo NCC thành công, NCC mới sẽ được thêm ngay vào danh sách nhà cung cấp cục bộ (`localSuppliers`) và được gán trực tiếp làm NCC đang chọn (`selectedSupplierId`), đóng modal mà không reload trang.
   - Lưu nháp ngầm ngay lập tức sau khi gán NCC mới tạo để đồng bộ state.

---

## 4. Danh sách các file thay đổi (Files Changed)
- [Create.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Purchases/Create.vue)

---

## 5. Đánh giá tính an toàn dữ liệu
- **Có migration không**: Không.
- **Có backfill không**: Không.
- **Có update dữ liệu cũ không**: Không.
- **Có lưu DB khi nháp không**: Không.
- **Có ảnh hưởng tồn kho/serial/giá vốn/công nợ/cashflow không**: Không. (Mọi logic nghiệp vụ kiểm soát tồn kho, công nợ, dòng tiền... chỉ chạy khi phiếu ở trạng thái hoàn thành và lưu vào database).

---

## 6. Kết quả kiểm thử & Biên dịch (Test & Build Results)
- **Kiểm thử tự động**:
  Chạy kiểm thử hồi quy `tests/Feature/Damage/RR09DamageStockTest.php` thành công:
  - `damage should decrease stock and inventory total cost` -> PASS
  - `damage should create stock movement` -> PASS
  - `damage should not allow quantity greater than stock` -> PASS
  - `damage serial should only affect selected serial` -> PASS
  - `damage should support cancel with rollback` -> PASS
- **Kiểm thử thủ công (Manual QA)**:
  - Khôi phục draft thành công sau khi nhấn Lưu nháp và F5 trang.
  - Phục hồi đầy đủ thông tin sản phẩm thường, sản phẩm serial và các mã serial đã điền.
  - Tạo nhanh nhà cung cấp thành công từ modal và NCC mới được chọn ngay lập tức, không reload trang, giữ nguyên sản phẩm/serial đã nhập.
  - Phiếu hoàn thành lưu database thành công và clear sạch draft.

---

## 7. Rủi ro còn lại (Residual Risks)
- Cơ chế lưu nháp hoàn toàn dựa vào `localStorage` của trình duyệt. Dữ liệu nháp chỉ tồn tại trên thiết bị và trình duyệt mà người dùng đang thao tác, không đồng bộ đa thiết bị hoặc trình duyệt khác.
