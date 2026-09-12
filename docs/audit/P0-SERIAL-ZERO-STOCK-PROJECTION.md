# P0 — Projection giá vốn sản phẩm serial khi hết tồn

### Mã việc

RR-05-SERIAL-ZERO-STOCK-PROJECTION

### Phạm vi

Chỉ xử lý projection hiện tại của sản phẩm quản lý Serial/IMEI và giá trị hiển thị trên danh sách hàng hóa.

### Vấn đề

Khi không còn serial `in_stock`, giao diện từng fallback sang `products.cost_price`, khiến giá vốn BQ cũ vẫn xuất hiện dù tồn bằng 0.

### Nguyên nhân gốc

Frontend dùng giá trị lưu cũ khi danh sách serial còn tồn rỗng. Dữ liệu legacy cũng có thể còn projection không khớp nguồn serial.

### Cách xử lý

- Giao diện hiển thị giá vốn BQ bằng 0 khi không có serial `in_stock`.
- Lệnh `costing:audit-serial-product-projection --product=...` mặc định chỉ dry-run và in JSON.
- `--all-sold-out` audit đồng thời mọi sản phẩm serial không còn serial `in_stock`; chỉ những projection lệch mới nằm trong plan.
- Apply yêu cầu mã xác nhận đúng theo trạng thái vừa audit.
- Tham chiếu recovery là tùy chọn; trạng thái trước sửa luôn được lưu trong ActivityLog nên không bắt buộc backup toàn database cho phép sửa projection này.
- Apply chỉ cập nhật `products.stock_quantity`, `products.inventory_total_cost`, `products.cost_price` theo serial `in_stock`.
- Không sửa serial đã bán, snapshot hóa đơn hay stock movement lịch sử.

### Test

- Dry-run nhận diện projection treo và không ghi dữ liệu.
- Apply đưa projection hết tồn về `0 / 0 / 0`, giữ nguyên trạng thái và giá vốn serial đã bán.

### Rủi ro còn lại

Stock movement legacy sai snapshot vẫn được giữ nguyên làm bằng chứng lịch sử; không thuộc phạm vi sửa projection hiện tại.
