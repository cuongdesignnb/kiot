# RR-11 — Trả hàng khách không được vượt số lượng đã bán

> **Mã rủi ro:** RR-11  
> **Mức độ:** 🔴 P0 — Critical  
> **Module:** OrderReturnController

---

## Mục tiêu

Kiểm tra nghiệp vụ trả hàng khách không cho trả quá số lượng đã bán hoặc trả trùng nhiều lần.

---

## Luồng code phát hiện

| Thành phần | Giá trị |
|---|---|
| **Route tạo** | `POST /returns` → `OrderReturnController@store` |
| **Route hủy** | ❌ **KHÔNG CÓ ROUTE** — `cancel()` method tồn tại nhưng chưa đăng ký |
| **Controller** | `OrderReturnController` |
| **Model** | `OrderReturn` (table `returns`) + `ReturnItem` (table `return_items`) |
| **Validation qty** | ❌ **KHÔNG CÓ** — chỉ validate `items.*.qty` ≥ 1, không check vs invoice qty |
| **CostingService** | ✅ `applySaleReturn()` |
| **StockMovement** | ✅ `TYPE_IN_INVOICE_RETURN` |
| **CashFlow** | ✅ Tạo payment khi `paid_to_customer > 0` |

### Vấn đề chính

- `OrderReturnController@store` (dòng 96-117): Validation chỉ check `items.*.qty >= 1`, **KHÔNG check tổng đã trả vs invoice_item.quantity**
- Có thể tạo nhiều phiếu trả cùng invoice, mỗi phiếu trả đầy đủ qty → tồn cộng gấp đôi
- Không check invoice status = 'Đã hủy'

---

## Test cases

### TC-RR11-01: Không cho trả quá số lượng bán trong một lần
- Invoice bán Product A qty = 5
- Tạo phiếu trả qty = 8
- Kỳ vọng: FAIL / validation error

### TC-RR11-02: Không cho trả vượt phần còn lại sau khi đã trả một phần
- Invoice bán qty = 5
- Lần 1 trả 3 → thành công
- Lần 2 trả 3 → phải FAIL (chỉ còn 2)

### TC-RR11-03: Cho trả đúng phần còn lại
- Invoice bán qty = 5
- Đã trả 3
- Trả tiếp 2 → thành công

### TC-RR11-04: Không cho trả hàng trên invoice đã hủy
- Invoice status = 'Đã hủy'
- Tạo phiếu trả → phải FAIL
