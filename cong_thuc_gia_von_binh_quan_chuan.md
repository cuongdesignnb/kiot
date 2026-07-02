# CÔNG THỨC GIÁ VỐN BÌNH QUÂN GIA QUYỀN — CHUẨN HỆ THỐNG

> Tài liệu này mô tả công thức tính giá vốn đang áp dụng cho toàn bộ hệ thống KiotViet-clone, từ ngày 26/04/2026 trở đi.

---

## A. ĐỊNH NGHĨA STATE CỦA 1 SẢN PHẨM

| Field trong `products` | Ký hiệu | Ý nghĩa | Vai trò |
|---|---|---|---|
| `stock_quantity` | **S** | Số lượng tồn kho | Counter |
| `inventory_total_cost` | **T** | Tổng giá trị tồn kho | **Chân lý (source of truth)** |
| `cost_price` | **BQ** | Giá vốn bình quân | **Số dẫn xuất** = T ÷ S |

> **Nguyên tắc gốc:**
> `T` là chân lý. `BQ` luôn được tính từ `T ÷ S`.
> KHÔNG bao giờ cập nhật BQ trực tiếp mà không thay T tương ứng.

---

## B. 6 NGHIỆP VỤ — CÔNG THỨC CHUẨN

### 1️⃣ NHẬP MUA (Purchase)

```
S_new  = S + qty
T_new  = T + (qty × giá_nhập)
BQ_new = T_new / S_new
```

✅ **BQ THAY ĐỔI** (trừ khi giá nhập = BQ cũ).

**Ví dụ:**
- Trước: S=2, T=10.000.000, BQ=5.000.000
- Nhập 3 cái giá 6.000.000
- Sau: S=5, T=28.000.000, BQ=5.600.000

---

### 2️⃣ BÁN HÀNG (Sale)

```
COGS   = BQ_hiện_tại × qty       ← chốt giá vốn TẠI THỜI ĐIỂM bán
T_new  = T - COGS
S_new  = S - qty
BQ_new = BQ (KHÔNG ĐỔI)
```

❌ **BQ KHÔNG ĐỔI** — đây là đặc trưng quan trọng nhất của bình quân gia quyền (khác phương pháp đích danh).

**Ghi cứng vào DB:**
- `invoice_items.cost_price` = COGS_per_unit
- `serial_imeis.sold_cost_price` = COGS_per_unit (hàng có serial)
- `serial_imeis.status` = 'sold'

**Ví dụ:**
- Trước: S=5, T=28.000.000, BQ=5.600.000
- Bán 2 cái → COGS = 11.200.000
- Sau: S=3, T=16.800.000, BQ=5.600.000 ✅

---

### 3️⃣ TRẢ HÀNG BÁN (Sale Return)

```
unit_cost = invoice_items.cost_price (đã chốt khi bán)
T_new     = T + (qty × unit_cost)
S_new     = S + qty
BQ_new    = BQ (KHÔNG ĐỔI)
```

❌ **BQ KHÔNG ĐỔI** — vì cộng vào đúng giá đã trừ ra.

Serial trở lại `status = 'in_stock'`.

---

### 4️⃣ TRẢ HÀNG MUA (Purchase Return)

```
unit_cost = giá nhập gốc của lô đó (lấy từ purchase_items)
T_new     = T - (qty × unit_cost)
S_new     = S - qty
BQ_new    = T_new / S_new        ← CÓ THỂ ĐỔI
```

✅ **BQ CÓ THỂ ĐỔI** — vì mất 1 lô có giá có thể khác BQ.

---

### 5️⃣ SỬA CHỮA — LẮP LINH KIỆN VÀO MÁY (Repair-in)

#### Trường hợp 5a: Serial đã bán (∈ soldSerials)
```
SKIP — KHÔNG cộng vào tồn kho
```
> Lý do: máy đã bán không còn trong kho. Chi phí 400k này thực chất là
> bảo hành/dịch vụ, không phải giá vốn hàng hoá.

#### Trường hợp 5b: Serial còn trong kho (hoặc sản phẩm non-serial)
```
T_new  = T + part_total_cost
S_new  = S          ← KHÔNG ĐỔI
BQ_new = T_new / S  ← BQ TĂNG
```

✅ **BQ TĂNG** — chi phí sửa được cộng vào giá vốn tồn kho.

---

### 6️⃣ SỬA CHỮA — THÁO LINH KIỆN RA KHỎI MÁY (Repair-out)

#### Trường hợp 6a: Serial đã bán
```
SKIP
```

#### Trường hợp 6b: Serial chưa bán
```
T_new  = max(0, T - part_total_cost)
S_new  = S
BQ_new = T_new / S  ← BQ GIẢM
```

---

## C. THỨ TỰ XỬ LÝ KHI CÙNG MỐC THỜI GIAN

| Loại nghiệp vụ | Thứ tự |
|---|---|
| Nhập mua | **0** |
| Sửa chữa (in/out) | **1** |
| Bán hàng | **2** |
| Trả hàng bán | **3** |
| Trả hàng mua | **4** |

→ Quy tắc: **Nhập trước → Sửa → Bán → Return**.

---

## D. 7 INVARIANTS BẤT BIẾN (luôn phải đúng)

1. **`T ≥ 0`** luôn luôn (nếu công thức ra âm → clamp về 0)
2. **`S ≥ 0`** luôn luôn
3. **`BQ = T ÷ S`** khi `S > 0`; khi `S = 0` thì BQ giữ giá trị cũ hoặc 0
4. **KHÔNG modify BQ độc lập với T**
5. **Sale KHÔNG đổi BQ** (khác phương pháp đích danh)
6. **Repair trên serial đã bán → BỎ QUA**
7. Mọi update phải nằm trong `DB::transaction` + `Product::lockForUpdate()`

---

## E. BẢNG CẬP NHẬT THEO NGHIỆP VỤ

| Nghiệp vụ | products.S | products.T | products.BQ | invoice_items | serial_imeis | stock_movements |
|---|---|---|---|---|---|---|
| **Nhập mua** | +qty | +qty×giá | T÷S | — | status=in_stock, original_cost | type=in_purchase |
| **Bán** | -qty | -COGS | giữ nguyên | cost_price=BQ | status=sold, sold_cost_price=BQ | type=out_invoice |
| **Trả bán** | +qty | +qty×cost_price | giữ nguyên | — | status=in_stock | type=in_invoice_return |
| **Trả mua** | -qty | -qty×giá_gốc | T÷S | — | xoá/inactive | type=out_purchase_return |
| **Repair-in (chưa bán)** | giữ | +part_cost | T÷S (tăng) | — | — | (không record) |
| **Repair-in (đã bán)** | giữ | giữ | giữ | — | — | **SKIP** |

---

## F. CODE THỰC THI TRONG SOURCE

| Nơi | File |
|---|---|
| Service trung tâm | `app/Services/MovingAvgCostingService.php` |
| Hook nhập mua | `PurchaseController` → `applyPurchase()` |
| Hook bán hàng | `InvoiceController` → `applySale()` |
| Hook trả bán | `OrderReturnController` → `applySaleReturn()` |
| Hook trả mua | `PurchaseReturnController` → `applyPurchaseReturn()` |
| Hook sửa chữa | `RepairService` / `TaskService` → `applyRepairAdjustment()` |
| Sync legacy serial | `SyncSerialCostFromTasks` job |
| Rebuild lịch sử | `php artisan costing:rebuild-moving-avg` |

---

## G. LỆNH REBUILD KHI DATA LỆCH

```bash
# Xem trước (KHÔNG ghi DB)
php artisan costing:rebuild-moving-avg --product=<SKU> --dry-run

# Ghi 1 sản phẩm
php artisan costing:rebuild-moving-avg --product=<SKU>

# Ghi tất cả sản phẩm
php artisan costing:rebuild-moving-avg --all

# Lưu log đầy đủ
php artisan costing:rebuild-moving-avg --all --dry-run > rebuild_dry.log 2>&1
```

### Cách đọc output

```
#48 SP26032084612: qty=9 total=47,378,340.00 BQ=5,264,260.00 (cũ: qty=9 BQ=5,308,704.44 total=47,778,340.00)
ⓘ Bỏ qua 1 repair part trên serial đã bán (tổng 400,000)
```

| Vị trí | Ý nghĩa |
|---|---|
| Trước dấu `(` | Số **MỚI** sau khi rebuild — thứ tự `qty → total → BQ` |
| Trong `(cũ: ...)` | Số **CŨ** đang lưu DB — thứ tự `qty → BQ → total` ⚠️ |
| Dòng `ⓘ Bỏ qua` | Cảnh báo anomaly — số repair part đã bị skip vì serial đã bán |

> ⚠️ **Lưu ý quan trọng:** Thứ tự cột "cũ" và "mới" KHÁC NHAU (cũ là qty-BQ-total, mới là qty-total-BQ). Đọc kỹ tránh nhầm.

---

## H. VÍ DỤ ĐẦY ĐỦ — THINKPAD L13 (CASE THỰC TẾ)

| Bước | Nghiệp vụ | qty | total | BQ |
|---|---|---|---|---|
| 1 | Nhập 10 IMEI tổng 47.378.340 | 10 | 47.378.340 | 4.737.834 |
| 2 | Lắp linh kiện vào IMEI #3 (chưa bán): +0 | 10 | 47.378.340 | 4.737.834 |
| 3 | Bán IMEI PW0205MQ → COGS = 4.737.834 | 9 | 42.640.506 | 4.737.834 |
| 4 | **Anomaly:** Lắp 400k vào PW0205MQ **đã bán** → **SKIP** | 9 | 42.640.506 | 4.737.834 |
| ... | (Các nghiệp vụ khác) | 9 | 47.378.340 | 5.264.260 |

**Kết quả cuối: BQ = 5.264.260 ✅** (khớp với số sếp duyệt)

---

## I. CHECKLIST TRIỂN KHAI

- [x] Migration `inventory_total_cost` đã có
- [x] `MovingAvgCostingService` 6 method
- [x] Tất cả controller đã hook vào service
- [x] `RebuildMovingAvgCosting` command (dry-run, --product, --all)
- [x] Legacy fallback (data trước Phase 4 không có stock_movements)
- [x] Skip repair trên serial đã bán
- [x] 8 file test (147 assertions) đều pass
- [x] Production verify Thinkpad L13: BQ=5.264.260 ✅
- [ ] Backup DB production
- [ ] Chạy `costing:rebuild-moving-avg --all` trên production
- [ ] Verify lại các SKU quan trọng sau khi rebuild
- [ ] Review anomaly với kế toán (các task sửa chữa máy đã bán)

---

## J. QUY TRÌNH TRIỂN KHAI AN TOÀN

```bash
# 1. Backup DB
mysqldump -u <user> -p <db> > backup-$(date +%Y%m%d-%H%M).sql
# hoặc SQLite:
cp database/database.sqlite database/backup-pre-rebuild.sqlite

# 2. Dry-run xem trước
php artisan costing:rebuild-moving-avg --all --dry-run > rebuild_dry.log 2>&1

# 3. Review log: đếm số anomaly
grep -c "Bỏ qua" rebuild_dry.log

# 4. Chạy thật
php artisan costing:rebuild-moving-avg --all 2>&1 | tee rebuild_real.log

# 5. Verify lại 1 vài SKU quan trọng
php artisan costing:rebuild-moving-avg --product=SP26032084612 --dry-run
# → phải báo "không lệch" (cũ = mới)
```

---

## K. KHI NÀO BQ ĐỔI / KHÔNG ĐỔI — BẢNG TÓM TẮT

| Nghiệp vụ | BQ đổi? | Tại sao |
|---|---|---|
| Nhập mua | ✅ Có | Có thể nhập với giá khác BQ cũ |
| Bán hàng | ❌ Không | COGS = BQ × qty, T và S giảm tương ứng |
| Trả hàng bán | ❌ Không | Cộng đúng số đã trừ khi bán |
| Trả hàng mua | ✅ Có | Mất 1 lô giá khác BQ |
| Sửa chữa (lắp) - chưa bán | ✅ Có (tăng) | T tăng, S giữ |
| Sửa chữa (tháo) - chưa bán | ✅ Có (giảm) | T giảm, S giữ |
| Sửa chữa - đã bán | ❌ Không | SKIP |

---

**Tài liệu đóng — đây là chuẩn áp dụng từ ngày 26/04/2026.**
