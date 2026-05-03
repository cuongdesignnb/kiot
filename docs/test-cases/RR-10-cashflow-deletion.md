# RR-10 — CashFlow không được mất dấu vết khi hủy/xóa

> **Mã rủi ro:** RR-10  
> **Mức độ:** 🔴 P0 — Critical  
> **Module:** CashFlow / PurchaseController / OrderReturnController / PurchaseReturnController

---

## Mục tiêu

Kiểm tra các nghiệp vụ hủy chứng từ không làm mất CashFlow và đặt đúng status = 'cancelled'.

---

## Luồng code phát hiện

| Thành phần | Giá trị |
|---|---|
| **CashFlow Model** | SoftDeletes ✅ + scopeActive() ✅ |
| **CashFlowController@destroy** | status='cancelled' + soft-delete ✅ |
| **PurchaseController cancel** | `CashFlow::where(...)->delete()` — soft-delete nhưng **THIẾU status='cancelled'** ❌ |
| **OrderReturnController cancel** | `CashFlow::where(...)->delete()` — soft-delete nhưng **THIẾU status='cancelled'** ❌ |
| **PurchaseReturnController cancel** | `CashFlow::where(...)->delete()` — soft-delete nhưng **THIẾU status='cancelled'** ❌ |
| **InvoiceController update** | `CashFlow::where(...)->delete()` — xóa CashFlow cũ khi sửa hóa đơn ❌ |

### Vấn đề

Các controller hủy chứng từ chỉ gọi `->delete()` (soft-delete) mà KHÔNG set `status='cancelled'` trước.
Kết quả: `scopeActive()` chỉ lọc theo `status != 'cancelled'`, nên CashFlow bị trashed vẫn qua được `scopeActive()` nếu ai đó restore. Và `withTrashed()` query sẽ thấy CashFlow nhưng status vẫn là giá trị cũ.

---

## Test cases

### TC-RR10-01: Hủy phiếu nhập (Purchase) CashFlow phải có status='cancelled'
- Tạo Purchase + CashFlow
- Hủy Purchase
- CashFlow phải còn trong DB (withTrashed)
- status phải = 'cancelled'

### TC-RR10-02: Hủy phiếu trả hàng KH (OrderReturn) CashFlow phải có status='cancelled'
- Tạo OrderReturn + CashFlow
- Hủy OrderReturn
- CashFlow phải còn + status='cancelled'

### TC-RR10-03: Hủy phiếu trả NCC (PurchaseReturn) CashFlow phải có status='cancelled'
- Tạo PurchaseReturn + CashFlow
- Hủy PurchaseReturn
- CashFlow phải còn + status='cancelled'

### TC-RR10-04: CashFlow::active() không tính CashFlow bị soft-deleted
- CashFlow active + CashFlow soft-deleted
- scopeActive() không tính soft-deleted
