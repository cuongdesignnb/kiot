# RR-03 — Chuyển kho phải ghi StockMovement và giữ đúng giá vốn

> **Mã rủi ro:** RR-03  
> **Mức độ:** 🔴 P0 — Critical  
> **Module:** StockTransfer

---

## Mục tiêu

Kiểm tra nghiệp vụ chuyển kho:
- Phải tạo StockMovement 2 chiều (transfer_out + transfer_in)
- Phải cập nhật `inventory_total_cost` trên Product
- Tổng tồn kho toàn hệ thống không đổi sau chuyển
- Tổng giá trị tồn kho toàn hệ thống không đổi sau chuyển

---

## Luồng code phát hiện

| Thành phần | Giá trị |
|---|---|
| **Route tạo** | `POST /stock-transfers` → `StockTransferController@store` |
| **Route nhận** | Không có route đăng ký (method `receive` tồn tại nhưng chưa có route) |
| **Route hủy** | Không có route đăng ký (method `cancel` tồn tại nhưng chưa có route) |
| **Controller** | `app/Http/Controllers/StockTransferController.php` |
| **Models** | `StockTransfer`, `StockTransferItem`, `Product`, `Branch` |
| **DB tables** | `stock_transfers`, `stock_transfer_items`, `products`, `branches` |
| **StockMovement** | ❌ **KHÔNG được gọi** — 0 references trong controller |
| **inventory_total_cost** | ❌ **KHÔNG được cập nhật** — 0 references trong controller |

### Vấn đề cụ thể

1. `store()` dòng 137: `$product->decrement('stock_quantity', ...)` — chỉ trừ số lượng, không ghi StockMovement, không cập nhật `inventory_total_cost`
2. `receive()` dòng 237: `$product->increment('stock_quantity', ...)` — chỉ cộng số lượng, không ghi StockMovement, không cập nhật `inventory_total_cost`
3. `cancel()` dòng 283-287: increment/decrement trực tiếp — không ghi StockMovement, không cập nhật `inventory_total_cost`

### Hạn chế kiến trúc

- Hệ thống **không có tồn kho theo kho** (không có bảng `warehouse_product` / `inventories`)
- Tồn kho lưu trên `products.stock_quantity` (tổng, không phân biệt chi nhánh)
- Chuyển kho thực tế chỉ là: trừ tồn khi gửi, cộng tồn khi nhận
- Giá vốn lưu trên `products.cost_price` + `products.inventory_total_cost`

---

## Dữ liệu đầu vào

| Thành phần | Giá trị |
|---|---|
| **Product A** | cost_price = 100.000, stock_quantity = 10, inventory_total_cost = 1.000.000 |
| **Branch A** (nguồn) | Chi nhánh chuyển |
| **Branch B** (đích) | Chi nhánh nhận |
| **Số lượng chuyển** | 3 |
| **Giá vốn hàng chuyển** | 3 × 100.000 = 300.000 |

---

## Test cases

### TC-RR03-01: Chuyển kho (status=transferring) phải tạo StockMovement transfer_out

**Kỳ vọng:**
- Product.stock_quantity giảm 3 (từ 10 → 7)
- Có StockMovement `type = 'transfer_out'` cho product
- StockMovement ghi đúng qty, ref_code, ref_type

**Nếu fail:** Không tạo StockMovement = thẻ kho thiếu dòng chuyển kho

### TC-RR03-02: Nhận hàng chuyển kho (status=received) phải tạo StockMovement transfer_in

**Kỳ vọng:**
- Product.stock_quantity cộng 3 khi nhận
- Có StockMovement `type = 'transfer_in'` cho product

**Nếu fail:** Thẻ kho nhập không có dòng nhận chuyển kho

### TC-RR03-03: Chuyển kho phải cập nhật inventory_total_cost

**Kỳ vọng:**
- `inventory_total_cost` giảm khi xuất (transfer_out)
- `inventory_total_cost` vẫn giữ tổng đúng toàn hệ thống
- `cost_price` không bị thay đổi sai

**Nếu fail:** Giá vốn bình quân bị sai vì total_cost không đổi nhưng qty đổi

### TC-RR03-04: Chuyển kho (status=received) tổng tồn hệ thống không đổi

**Kỳ vọng:**
- Tổng stock_quantity trước = tổng stock_quantity sau
- Nếu chuyển 3 từ 10: vẫn còn tổng 10

### TC-RR03-05: Hủy phiếu chuyển kho phải đảo đúng tồn và không đảo lặp

**Kỳ vọng:**
- Hủy lần 1: stock_quantity phục hồi đúng
- Hủy lần 2: không thay đổi thêm (idempotent)
- Có StockMovement đảo nếu chuẩn
