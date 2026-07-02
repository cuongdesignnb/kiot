# AGENT RULES — Bộ luật bắt buộc cho hệ thống quản lý bán hàng

> **Phiên bản:** 1.0  
> **Ngày tạo:** 02/05/2026  
> **Phạm vi:** Mọi AI Agent hoặc developer khi audit, sửa lỗi, hoặc phát triển thêm tính năng trên hệ thống này.  
> **Nguyên tắc tối thượng:** Không được làm sai dữ liệu nghiệp vụ (tồn kho, giá vốn, công nợ, lịch sử chứng từ).

---

## 1. Nguyên tắc an toàn

1. **Không sửa code khi chưa có test case hoặc bằng chứng lỗi.**  
   Phải có mô tả lỗi tái hiện được, dữ liệu đầu vào, kết quả kỳ vọng vs thực tế.

2. **Không sửa nhiều module trong cùng một lần.**  
   Mỗi lần chỉ xử lý 1 mã rủi ro (VD: RR-01). Sửa xong, test xong, báo cáo xong mới chuyển sang mã tiếp.

3. **Không sửa trực tiếp production.**  
   Mọi thay đổi phải qua branch riêng, review, test trước khi merge.

4. **Không tự ý đổi công thức giá vốn.**  
   Giá vốn bình quân di động (Moving Weighted Average) là phương pháp đang dùng. Không được tự chuyển sang FIFO, LIFO, hoặc phương pháp khác khi chưa được yêu cầu rõ ràng.

5. **Không tự ý đổi logic công nợ.**  
   Công nợ khách hàng và nhà cung cấp có ảnh hưởng tài chính trực tiếp. Không tự thêm/bớt logic cộng/trừ nợ.

6. **Không xóa vật lý chứng từ đã phát sinh nghiệp vụ.**  
   Hóa đơn, phiếu nhập, phiếu trả, phiếu kiểm kho... đã phát sinh thì chỉ được đổi trạng thái (cancelled), không được `delete()`.

7. **Không xóa vật lý dòng tiền/cashflow đã phát sinh.**  
   CashFlow đã tạo phải giữ lại, nếu hủy thì đổi status hoặc tạo bút toán đảo.

8. **Không chạy migration nếu chưa được yêu cầu rõ.**  
   Migration thay đổi schema có thể ảnh hưởng dữ liệu production. Phải có yêu cầu cụ thể từ người dùng.

9. **Không sửa database thật.**  
   Không chạy `UPDATE`, `DELETE`, `ALTER TABLE` trực tiếp trên DB production.

10. **Không format lại hàng loạt file không liên quan.**  
    Chỉ sửa file liên quan đến mã rủi ro đang xử lý. Không auto-format, không reorder imports, không refactor code không liên quan.

---

## 2. Nguyên tắc nghiệp vụ tồn kho

1. **Mọi nghiệp vụ làm tăng/giảm tồn kho phải đi qua service chuẩn.**  
   Service hiện tại: `MovingAvgCostingService` (cập nhật stock + cost) và `StockMovementService` (ghi sổ cái).

2. **Không được dùng `increment`/`decrement` trực tiếp trên `stock_quantity`** nếu nghiệp vụ có ảnh hưởng giá vốn hoặc thẻ kho.  
   - ❌ `$product->decrement('stock_quantity', $qty);`  
   - ✅ `MovingAvgCostingService::applySale($product, $qty);`

3. **Mọi thay đổi tồn kho phải có stock movement.**  
   Gọi `StockMovementService::record(...)` sau mỗi lần controller update stock thành công.

4. **Mọi stock movement phải có đủ thông tin:**
   - Mã chứng từ (`ref_code`)
   - Loại nghiệp vụ (`type`: in_purchase, out_invoice, adjust_in, transfer_out, ...)
   - Số lượng vào/ra (`qty`, `direction`)
   - Giá vốn đơn vị tại thời điểm (`unit_cost`)
   - Tồn sau giao dịch (`balance_qty`, `balance_cost`)

5. **Không được để `stock_quantity` thay đổi mà `inventory_total_cost` không thay đổi tương ứng.**  
   Hai trường này phải luôn đồng bộ: `cost_price = inventory_total_cost / stock_quantity`.

6. **Không được để `inventory_total_cost` thay đổi mà không có lý do nghiệp vụ.**  
   Mọi thay đổi phải trace được về 1 chứng từ cụ thể (phiếu nhập, hóa đơn, phiếu kiểm, phiếu sửa chữa...).

---

## 3. Nguyên tắc giá vốn

1. **Giá vốn bình quân phải được xử lý tập trung qua `MovingAvgCostingService`.**  
   Không tính BQ ở controller hoặc ở frontend.

2. **Khi nhập hàng** phải cập nhật cả 3 trường:
   - `stock_quantity` += qty
   - `inventory_total_cost` += qty × unitCost
   - `cost_price` = inventory_total_cost / stock_quantity

3. **Khi bán hàng** phải:
   - COGS = `cost_price` hiện tại (BQ)
   - `stock_quantity` -= qty
   - `inventory_total_cost` -= qty × COGS
   - `cost_price` giữ nguyên (rút ở giá BQ thì BQ không đổi)

4. **Khi trả hàng khách** phải đảo lại tồn và giá vốn theo snapshot lúc bán (`invoice_item.cost_price`), **không phải** cost_price hiện tại.

5. **Khi trả hàng nhập** phải giảm tồn và tổng giá trị tồn theo đúng giá vốn lúc nhập (`purchase_item.unit_cost_allocated`).

6. **Không tự reset `cost_price` về 0** nếu chưa có quy định nghiệp vụ rõ ràng.  
   Hiện tại `applyPurchaseReturn` reset cost_price = 0 khi qty về 0, nhưng `applySale` giữ nguyên. Cần nhất quán.

7. **Nếu số lượng tồn về 0, cách xử lý cost_price phải nhất quán** giữa:
   - Bán hết → giữ BQ cũ (đúng)
   - Trả NCC hết → reset 0 (cần xem lại)
   - Kiểm kho hết → chưa xử lý qua CostingService (cần bổ sung)

---

## 4. Nguyên tắc công nợ

1. **Mọi thay đổi công nợ phải có chứng từ hoặc log giao dịch.**  
   Không được thay đổi `debt_amount` hay `supplier_debt_amount` mà không có record tương ứng (CashFlow, Invoice, Purchase, DebtOffset...).

2. **Không cộng/trừ công nợ rải rác ở nhiều controller** nếu có thể đưa về service.  
   Hiện tại `$customer->increment('debt_amount', ...)` nằm ở InvoiceController, PosController, OrderReturnController, CustomerController — khó kiểm soát.

3. **Công nợ khách hàng và công nợ nhà cung cấp phải có khả năng truy vết.**  
   - NCC đã có `supplier_debt_transactions` ✅
   - KH chưa có bảng tương đương, chỉ dựa vào CashFlow + Invoice ⚠️

4. **Nếu giao dịch bị hủy, công nợ phải được đảo bằng nghiệp vụ hủy/cancel**, không xóa dấu vết.  
   Không được xóa CashFlow rồi decrement ngược — phải tạo bút toán đảo hoặc đổi status.

5. **Với đối tác vừa là khách hàng vừa là nhà cung cấp (dual-role):**
   - Cấn trừ công nợ phải qua `DebtOffsetService`
   - Phải có lịch sử cấn trừ rõ ràng (bảng `debt_offsets`)
   - Hủy cấn trừ phải tạo bút toán đảo

---

## 5. Nguyên tắc hủy/trả/đảo giao dịch

1. **Hủy chứng từ phải đổi trạng thái, không xóa vật lý.**  
   - ✅ `$purchase->status = 'cancelled'; $purchase->save();`
   - ❌ `$invoice->delete();`

2. **Hủy hóa đơn phải đảo đầy đủ:**
   - Tồn kho: cộng lại qty đã bán
   - Giá vốn: phục hồi `inventory_total_cost` theo cost_price snapshot lúc bán
   - Công nợ: giảm `debt_amount` đúng số nợ phát sinh
   - Serial: chuyển về `in_stock`
   - CashFlow: tạo bút toán đảo hoặc đổi status (không xóa)
   - StockMovement: ghi 1 dòng `in_invoice_return`

3. **Hủy phiếu nhập phải đảo đầy đủ:**
   - Tồn kho: trừ qty đã nhập
   - Giá vốn: giảm `inventory_total_cost` theo `unit_cost_allocated`
   - Công nợ NCC: giảm `supplier_debt_amount`
   - Serial: xóa hoặc đổi status

4. **Hủy phiếu trả hàng phải đảo đúng chứng từ gốc.**  
   Phải rollback đúng serial ban đầu — cần lưu serial_ids trên return_item.

5. **Không cho hủy lặp gây cộng/trừ tồn 2 lần.**  
   Kiểm tra status trước khi hủy. Nếu đã `cancelled` thì return ngay.

6. **Mọi nghiệp vụ hủy phải idempotent:** chạy lại không làm sai dữ liệu.  
   Check guard: `if ($record->status === 'cancelled') return;`

---

## 6. Nguyên tắc serial/IMEI

1. **Serial/IMEI phải có trạng thái rõ ràng.**  
   Các trạng thái hiện tại: `in_stock`, `sold`, `returned`.

2. **Khi bán, serial phải chuyển sang `sold`** với đầy đủ:
   - `sold_at` = thời điểm bán
   - `invoice_id` = ID hóa đơn
   - `sold_cost_price` = BQ tại thời điểm bán

3. **Khi trả hàng, serial phải quay lại `in_stock`** hoặc `returned` tùy nghiệp vụ.  
   Clear `sold_at`, `invoice_id`, `sold_cost_price`.

4. **Khi hủy trả hàng, phải rollback đúng serial ban đầu.**  
   - ❌ Chọn đại serial bằng `->limit($qty)->get()` — có thể chọn sai serial
   - ✅ Lưu `serial_ids` trên return_item, rollback đúng những serial đó

5. **Serial đang sửa chữa hoặc đang bảo hành không được bán** nếu chưa chuyển `repair_status` về `ready`.  
   POS đã có filter `whereNotIn('repair_status', ['not_started', 'repairing'])` — cần đảm bảo Invoice cũng có.

---

## 7. Nguyên tắc sửa chữa / bóc tách linh kiện

1. **Khi xuất linh kiện sửa chữa, không được `decrement` trực tiếp.**  
   Phải gọi service chuẩn để đảm bảo `inventory_total_cost` giảm tương ứng.

2. **Linh kiện xuất phải tạo stock movement** (`type: repair_out`).  
   Hiện tại `RepairService::addPart()` chỉ `$product->decrement(...)` — thiếu StockMovement.

3. **Linh kiện xuất phải làm giảm `inventory_total_cost` tương ứng.**  
   Hiện tại chỉ gọi `applyRepairAdjustment()` cho sản phẩm đích (cộng cost), nhưng sản phẩm linh kiện chỉ bị `decrement stock_quantity` mà không giảm `inventory_total_cost` → BQ linh kiện bị sai.

4. **Nếu tháo/bóc tách tạo ra sản phẩm mới**, phải có:
   - Chứng từ nguồn (serial gốc, phiếu sửa)
   - Chứng từ nhập thành phẩm/phụ kiện (nếu linh kiện tháo ra nhập kho)

5. **Mọi thao tác sửa chữa ảnh hưởng kho phải truy vết được** qua StockMovement và DeviceRepairPart.

---

## 8. Quy tắc làm việc khi sửa lỗi

### Trước khi sửa — bắt buộc có:

| Mục | Mô tả |
|---|---|
| **Mã rủi ro** | VD: RR-01 |
| **Test case fail** | Hoặc mô tả lỗi tái hiện được |
| **Dữ liệu đầu vào** | Input cụ thể gây lỗi |
| **Kết quả kỳ vọng** | Hệ thống lẽ ra phải làm gì |
| **Kết quả thực tế** | Hệ thống thực tế đang làm gì |
| **File/function liên quan** | Chỉ rõ path + method |

### Sau khi sửa — bắt buộc có:

| Mục | Mô tả |
|---|---|
| **File đã sửa** | Danh sách file thay đổi |
| **Lý do sửa** | Tại sao sửa như vậy |
| **Test đã chạy** | Liệt kê test case |
| **Kết quả pass/fail** | Mỗi test ra sao |
| **Rủi ro còn lại** | Còn gì chưa xử lý |

### Quy tắc phạm vi:

- **Mỗi lần sửa chỉ được chọn 1 mã rủi ro.**
- Không sửa "tiện thể" các vấn đề khác.
- Nếu phát hiện lỗi mới trong quá trình sửa → ghi vào RISK_REGISTER, không sửa ngay.

---

## 9. Format báo cáo bắt buộc sau mỗi lần làm

```markdown
### Mã việc
RR-XX

### Phạm vi
Module: [tên module]
File: [danh sách file]

### Vấn đề
[Mô tả ngắn gọn lỗi nghiệp vụ]

### Nguyên nhân gốc
[Root cause — tại sao lỗi xảy ra]

### Cách xử lý
[Đã làm gì hoặc đề xuất làm gì — liệt kê từng bước]

### Test
- [ ] Test case 1: [mô tả] → [pass/fail]
- [ ] Test case 2: [mô tả] → [pass/fail]

### Rủi ro còn lại
[Liệt kê rủi ro chưa xử lý, nếu có]
```

---

## 10. Tham chiếu nhanh — Service & Method

### MovingAvgCostingService

| Method | Khi nào dùng | Ảnh hưởng |
|---|---|---|
| `applyPurchase($product, $qty, $unitCost)` | Nhập hàng | +stock, +total_cost, recalc BQ |
| `applySale($product, $qty)` | Bán hàng | -stock, -total_cost, BQ giữ nguyên |
| `applySaleReturn($product, $qty, $costAtSale)` | KH trả hàng, hủy HĐ | +stock, +total_cost, recalc BQ |
| `applyPurchaseReturn($product, $qty, $costAtPurchase)` | Trả NCC, hủy nhập | -stock, -total_cost, recalc BQ |
| `applyRepairAdjustment($product, $deltaTotal)` | Sửa chữa (cộng/trừ cost) | 0 stock, ±total_cost, recalc BQ |
| `applyAdjustment($product, $deltaQty)` | Kiểm kho | ±stock, ±total_cost, BQ giữ nguyên |

### StockMovementService

| Type constant | Nghiệp vụ | Direction |
|---|---|---|
| `TYPE_IN_PURCHASE` | Nhập hàng | in |
| `TYPE_OUT_INVOICE` | Bán hàng | out |
| `TYPE_IN_INVOICE_RETURN` | KH trả hàng / hủy HĐ | in |
| `TYPE_OUT_PURCHASE_RETURN` | Trả NCC / hủy nhập | out |
| `TYPE_ADJUST_IN` | Kiểm kho tăng | in |
| `TYPE_ADJUST_OUT` | Kiểm kho giảm | out |
| `TYPE_TRANSFER_IN` | Chuyển kho nhận | in |
| `TYPE_TRANSFER_OUT` | Chuyển kho gửi | out |
| `TYPE_REPAIR_IN` | Sửa chữa nhập | in |
| `TYPE_REPAIR_OUT` | Sửa chữa xuất | out |
