# STEP-22.2B — Serial Loading Stuck Fix Results

**Status:** Implementation done, NOT committed/pushed (chờ user QA).

## 1. Lỗi QA
User mở Orders/Create, chọn sản phẩm `has_serial=1` ⇒ ô serial treo mãi `Đang tải Serial/IMEI…`, không chuyển sang danh sách checkbox, không chuyển sang empty/error state.

## 2. Root cause (3 lớp)

**Lớp 1 — Reactivity (chính):** `selectProduct` build `newItem` plain object rồi `activeTab.value.items.unshift(newItem)`. Sau khi insert vào `ref()` array, Vue 3 wrap element bằng Proxy (lazy). Hàm gọi `loadAvailableSerials(newItem)` truyền **plain reference**, không phải Proxy. Mọi mutation `item.serialLoading=false`, `item.available_serials=[...]` xảy ra trên raw object → **bypass Proxy traps → không trigger effects → `itemsComputed` không re-run → UI không re-render**. Request thực ra hoàn tất bình thường nhưng UI không biết.

**Lớp 2 — Template binding:** `<tr v-for="(item, index) in itemsComputed">`. `itemsComputed` trả về **shallow copies** `{...item, subtotal}`. Read state qua copy này phụ thuộc vào computed re-run. Ghép với lớp 1 ⇒ UI không bao giờ thấy `serialLoading=false`.

**Lớp 3 — Side-effect trong computed:** `itemsComputed` mutate `item.serial_ids = item.serial_ids.slice(0, qty)` — Vue antipattern, có thể gây loop tái render và che bug khác.

## 3. File sửa

| File | Nội dung |
|---|---|
| [resources/js/Pages/Orders/Create.vue](resources/js/Pages/Orders/Create.vue#L181) `selectProduct` | Truyền `activeTab.value.items[0]` (Proxy) thay `newItem` (raw) vào `loadAvailableSerials`. |
| [resources/js/Pages/Orders/Create.vue](resources/js/Pages/Orders/Create.vue#L207) `loadAvailableSerials` | Hardened: timeout 8s, reset state đầu hàm, detect HTML response (`content-type` / typeof string), phân biệt 401/403/404/419/ECONNABORTED, message rõ, `finally` luôn tắt loading, `console.debug` khi lỗi. |
| [resources/js/Pages/Orders/Create.vue](resources/js/Pages/Orders/Create.vue#L266) `retryLoadSerials` | Hàm mới, lấy raw item theo index rồi gọi lại loader. |
| [resources/js/Pages/Orders/Create.vue](resources/js/Pages/Orders/Create.vue#L290) `itemsComputed` | Bỏ side-effect cắt `serial_ids`. Pure computed. |
| [resources/js/Pages/Orders/Create.vue](resources/js/Pages/Orders/Create.vue#L300) watcher mới | Cắt `serial_ids` khi qty giảm — đúng vị trí. |
| [resources/js/Pages/Orders/Create.vue](resources/js/Pages/Orders/Create.vue#L640) Template serial cell | Bind state từ `activeTab.items[index].*` (reactive proxy) thay `item.*` (computed copy). Thêm nút **Tải lại** ở error state và empty state. |

## 4. Frontend fix details

- **Reactive proxy:** `selectProduct` ⇒ `loadAvailableSerials(activeTab.value.items[0])`.
- **finally:** luôn `item.serialLoading = false` cuối cùng, không có early return sau khi set loading=true.
- **Error/empty state:** template phân biệt 4 trạng thái rõ rệt: `serialLoading` / `serialError` / empty `available_serials` / list.
- **Retry button:** xuất hiện ở cả error và empty (gọi `retryLoadSerials(index)`).
- **HTML detection:** `if (typeof data === 'string' || ct.includes('text/html'))` ⇒ message "Server trả HTML thay vì JSON".
- **Timeout giảm:** 10s → 8s để fail nhanh hơn nếu route chậm.
- **Debug log:** `console.debug('[OrderSerials] load failed', {...})` chỉ trong nhánh catch.

## 5. Endpoint check

```
php artisan route:list --path=api/products | grep serial
GET|HEAD  api/products/{product}/serials  api.products.serials → PosController@getProductSerials
```

Middleware: chỉ `auth` (đã out khỏi `pos.use` từ Step 22.1E). Controller dùng `SerialAvailabilityService` từ Step 22.2A.

## 6. Build/Test

| Lệnh | Kết quả |
|---|---|
| `npm run build` | ✓ built in 7.15s |
| `php artisan test --filter="SerialAvailability\|RR13"` | 2 skipped, 10 passed (52 assertions) |
| `php artisan route:list --path=api/products` | Route tồn tại, middleware `auth` |

Không sửa core service / business logic.

## 7. Manual QA — yêu cầu user verify

| TC | Mong đợi |
|---|---|
| Chọn product có serial khả dụng | Loading → biến mất → checkbox list hiển thị; tick được; "Đã chọn x/y" cập nhật. |
| Chọn product không có serial khả dụng | Loading → biến mất → "Không có Serial/IMEI khả dụng cho sản phẩm này." + nút **Tải lại**. |
| Endpoint lỗi (giả lập: tắt mạng) | Loading → biến mất sau 8s → "Tải Serial/IMEI quá lâu, vui lòng thử lại." + nút **Tải lại**. |
| Session hết hạn | Loading → biến mất → "Phiên đăng nhập hết hạn…". |
| Bấm **Tải lại** | Loading lại 1 nhịp → state mới. |

## 8. Kết luận

- Loading không còn bị treo: cause là raw-vs-proxy reactivity, đã chuyển sang reactive proxy.
- UI nay luôn rơi vào 1 trong 4 state rõ ràng (loading / error / empty / list).
- Có nút **Tải lại** giúp QA test ngay.
- **Chưa commit. Chưa push.** Đợi user QA pass.

## 9. Nếu vẫn treo sau bước này
Mở DevTools → Network → chọn product có serial → ghi lại:
- URL được gọi.
- HTTP status.
- Content-Type header.
- Response body (đoạn đầu 200 ký tự).

Gửi lại để chuyển 22.2C nếu thực sự là vấn đề tầng HTTP/route/middleware khác.
