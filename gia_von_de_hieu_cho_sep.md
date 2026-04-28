# GIÁ VỐN HÀNG TỒN KHO — GIẢI THÍCH ĐƠN GIẢN

> Dành cho sếp / kế toán / người không rành kỹ thuật.
> Đọc 5 phút là hiểu toàn bộ cách hệ thống tính giá vốn.

---

## 🎯 Ý TƯỞNG CỐT LÕI: "BÌNH QUÂN GIA QUYỀN"

Tưởng tượng cái **kho** là 1 cái **hũ tiền**:

- Mỗi lần **nhập hàng** → bỏ thêm tiền vào hũ + tăng số lượng món
- Mỗi lần **bán hàng** → lấy ra giá vốn = (tiền trong hũ ÷ số món) × số bán
- **Giá vốn 1 món** = Tiền trong hũ ÷ Số món trong kho

> 📌 **3 con số quan trọng nhất:**
> - **S** = Số lượng tồn (mấy món còn trong kho)
> - **T** = Tổng tiền trong hũ (tổng giá trị tồn kho)
> - **BQ** = Giá vốn bình quân = T ÷ S

---

## 🌰 VÍ DỤ DỄ HIỂU NHẤT

### Tình huống: Cửa hàng bán Laptop

**Đầu tháng có 2 cái laptop trong kho, mỗi cái mua 5 triệu**
- S = 2 cái
- T = 10.000.000đ
- BQ = 5.000.000đ/cái

**Ngày 5: Nhập thêm 3 cái, mỗi cái 6 triệu (giá lên)**
- S = 2 + 3 = **5 cái**
- T = 10.000.000 + (3 × 6.000.000) = **28.000.000đ**
- BQ = 28.000.000 ÷ 5 = **5.600.000đ/cái** ⬆️ (tăng vì hàng mới đắt hơn)

**Ngày 10: Bán 2 cái với giá 8 triệu/cái**
- Giá vốn xuất ra (COGS) = 5.600.000 × 2 = 11.200.000đ
- S = 5 - 2 = **3 cái**
- T = 28.000.000 - 11.200.000 = **16.800.000đ**
- BQ = 16.800.000 ÷ 3 = **5.600.000đ** ✅ **(KHÔNG ĐỔI)**
- **Lãi gộp** = (8.000.000 - 5.600.000) × 2 = 4.800.000đ

---

## 📋 TẤT CẢ NGHIỆP VỤ LÀM GÌ VỚI 3 CON SỐ

| Nghiệp vụ | Số lượng (S) | Tổng tiền (T) | Giá vốn BQ |
|---|---|---|---|
| **Nhập mua** | ⬆️ Tăng | ⬆️ Tăng | ⬆️/⬇️ Đổi (tuỳ giá nhập) |
| **Bán hàng** | ⬇️ Giảm | ⬇️ Giảm | ✅ **GIỮ NGUYÊN** |
| **Trả hàng bán** | ⬆️ Tăng | ⬆️ Tăng | ✅ **GIỮ NGUYÊN** |
| **Trả hàng mua** | ⬇️ Giảm | ⬇️ Giảm | ⬆️/⬇️ Đổi |
| **Sửa máy (lắp linh kiện) — máy còn trong kho** | ➖ Giữ | ⬆️ Tăng | ⬆️ **TĂNG** |
| **Sửa máy (tháo linh kiện) — máy còn trong kho** | ➖ Giữ | ⬇️ Giảm | ⬇️ **GIẢM** |
| **Sửa máy đã bán rồi** | ➖ KHÔNG TÍNH | ➖ KHÔNG TÍNH | ➖ KHÔNG ĐỔI |

---

## ⚠️ ĐIỂM QUAN TRỌNG NHẤT — BÁN HÀNG KHÔNG LÀM ĐỔI BQ

> Đây là điểm dễ nhầm nhất.

Khi bán 2 cái laptop ở ví dụ trên:
- Lấy 11.200.000đ ra khỏi hũ
- Lấy 2 cái ra khỏi kho
- Hũ còn 16.800.000đ chia cho 3 cái = **vẫn 5.600.000đ/cái**

→ Vì lấy ra theo đúng tỷ lệ, nên giá vốn bình quân **không đổi**.

→ Lần bán tiếp theo (nếu chưa nhập thêm) vẫn dùng giá vốn 5.600.000đ.

---

## 🔧 NGHIỆP VỤ SỬA CHỮA — 2 TRƯỜNG HỢP

### TH1: Sửa máy CHƯA BÁN (đang còn trong kho)

**Ví dụ:** Laptop trong kho bị lỗi → thay pin 500.000đ.

→ Tiền 500.000đ này **CỘNG vào giá vốn của máy đó**.

→ Khi bán máy ra, khách phải gánh thêm 500.000đ chi phí pin → đúng nguyên tắc.

```
Trước sửa:  S=3, T=16.800.000, BQ=5.600.000
Sửa +500k:  S=3, T=17.300.000, BQ=5.766.667 (tăng)
```

### TH2: Sửa máy ĐÃ BÁN cho khách

**Ví dụ:** Khách đem máy đã mua quay lại bảo hành → thay pin 400.000đ.

→ Máy này **không còn trong kho** rồi. Cộng tiền vào tồn kho là VÔ LÝ.

→ Hệ thống **BỎ QUA** (skip) → chi phí 400k này phải hạch toán riêng (chi phí bảo hành / dịch vụ).

> **Tại sao quan trọng?**
> Đây chính là lỗi data của Thinkpad L13 trước đây — task #7 cộng 400k vào BQ
> dù máy đã bán → BQ tính sai thành 5.308.704đ. Sau khi fix → BQ đúng = 5.264.260đ.

---

## 🚨 KHI NÀO DỮ LIỆU "LỆCH" CẦN REBUILD?

Nếu sếp thấy:
- Số tồn kho `S = 0` mà BQ vẫn ≠ 0
- Số tồn kho > 0 mà tổng tiền `T = 0`
- BQ không khớp với phép tính tay

→ Chạy lệnh **rebuild** để hệ thống tính lại từ đầu:

```bash
# Xem trước (chưa ghi)
php artisan costing:rebuild-moving-avg --product=<SKU> --dry-run

# Ghi thật cho 1 sản phẩm
php artisan costing:rebuild-moving-avg --product=<SKU>

# Ghi thật cho TẤT CẢ
php artisan costing:rebuild-moving-avg --all
```

---

## 📊 VÍ DỤ THỰC TẾ — THINKPAD L13 SP26032084612

**Tình huống ban đầu:**
- Nhập 10 cái IMEI, tổng tiền nhập = 47.378.340đ
- Có 1 cái (IMEI: PW0205MQ) đã được bán cho hoá đơn #55
- Sau khi bán, có người thêm 400.000đ phụ tùng vào IMEI đã bán này (sai nghiệp vụ)

**Tính sai (cũ — gộp luôn 400k):**
- S = 9, T = 47.778.340đ, BQ = 5.308.704đ ❌

**Tính đúng (mới — bỏ qua 400k vì serial đã bán):**
- S = 9, T = 47.378.340đ, BQ = **5.264.260đ** ✅
- Khớp với số sếp duyệt 🎯

---

## ✅ TÓM LẠI 1 CÂU

> **Hệ thống tính giá vốn bằng cách "trộn đều" giá nhập của tất cả hàng còn trong kho.**
> **Bán hàng không làm đổi giá vốn.**
> **Sửa máy đang trong kho thì cộng vào giá vốn. Sửa máy đã bán thì không tính.**

Đó là toàn bộ.

---

## 📞 KHI CẦN AI HỖ TRỢ

- **Số liệu báo cáo bị lệch** → chạy lệnh rebuild ở mục trên
- **Có bất thường (số âm, lệch lớn)** → gửi log cho dev xem
- **Câu hỏi nghiệp vụ** → đối chiếu bảng nghiệp vụ ở mục "Tất cả nghiệp vụ làm gì với 3 con số"

**Tài liệu tham khảo chi tiết hơn:** [cong_thuc_gia_von_binh_quan_chuan.md](cong_thuc_gia_von_binh_quan_chuan.md)
