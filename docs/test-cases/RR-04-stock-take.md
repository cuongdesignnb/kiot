# RR-04 — Kiểm kho phải ghi StockMovement và cập nhật giá vốn

> **Mã rủi ro:** RR-04  
> **Mức độ:** 🔴 P0 — Critical  
> **Module:** StockTake

---

## Mục tiêu

Kiểm tra nghiệp vụ kiểm kho/cân bằng tồn:
- Phải tạo StockMovement cho mỗi thay đổi tồn (adjust_in / adjust_out)
- Phải cập nhật `inventory_total_cost` khi stock_quantity thay đổi
- `cost_price` phải nhất quán sau cân bằng
- Hủy phiếu phải đảo đúng và idempotent

---

## Luồng code phát hiện

| Thành phần | Giá trị |
|---|---|
| **Route store** | `POST /stock-takes` → `StockTakeController@store` |
| **Route balance** | `POST /stock-takes/{id}/balance` → `StockTakeController@balance` |
| **Route cancel** | `POST /stock-takes/{id}/cancel` → `StockTakeController@cancel` |
| **Controller** | `app/Http/Controllers/StockTakeController.php` |
| **Models** | `StockTake`, `StockTakeItem`, `Product` |
| **StockMovement** | ❌ **KHÔNG được gọi** — 0 references |
| **inventory_total_cost** | ❌ **KHÔNG được cập nhật** — 0 references |
| **MovingAvgCostingService** | ❌ **KHÔNG được gọi** — 0 references |

### Vấn đề cụ thể

- `store()` dòng 121-123: `increment`/`decrement` raw
- `balance()` dòng 273-275: `increment`/`decrement` raw
- `cancel()` dòng 330-332: `increment`/`decrement` raw đảo

---

## Dữ liệu đầu vào

| Thành phần | Giá trị |
|---|---|
| **Product A** | cost_price = 100.000, stock = 10, inventory_total_cost = 1.000.000 |
| **Tồn thực tế (tăng)** | 13 → diff = +3 |
| **Tồn thực tế (giảm)** | 7 → diff = -3 |

---

## Test cases

### TC-RR04-01: Kiểm kho tăng tồn phải tạo StockMovement adjust_in
- stock 10 → actual 13 → diff +3
- Kỳ vọng: StockMovement type `adjust_in`, qty 3, inventory_total_cost = 1.300.000

### TC-RR04-02: Kiểm kho giảm tồn phải tạo StockMovement adjust_out
- stock 10 → actual 7 → diff -3
- Kỳ vọng: StockMovement type `adjust_out`, qty 3, inventory_total_cost = 700.000

### TC-RR04-03: Kiểm kho phải cập nhật inventory_total_cost (tăng)
- Kỳ vọng: total_cost = 1.300.000, cost_price = 100.000

### TC-RR04-04: Kiểm kho phải cập nhật inventory_total_cost (giảm)
- Kỳ vọng: total_cost = 700.000, cost_price = 100.000

### TC-RR04-05: Hủy kiểm kho phải đảo đúng và idempotent
- Hủy lần 1: stock về 10, total_cost về 1.000.000
- Hủy lần 2: không đổi thêm
