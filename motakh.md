# HOTFIX — Khách hàng: Chiết khấu thanh toán đúng logic, không làm sai công nợ/sổ quỹ

## Bối cảnh
Repo: `cuongdesignnb/kiot`  
Stack: Laravel + Vue 3/Inertia + MySQL.

User muốn làm tính năng **Chiết khấu thanh toán** trong màn Khách hàng giống luồng KiotViet:
- Mở khách hàng.
- Vào tab **Công nợ**.
- Bấm **Chiết khấu thanh toán**.
- Nhập số tiền chiết khấu, thời gian, người thực hiện, ghi chú.
- Có tùy chọn **Phân bổ vào hóa đơn**.
- Nếu phân bổ vào hóa đơn, chiết khấu được chia vào các hóa đơn còn phải thu.
- Tạo phiếu xong, công nợ khách giảm tương ứng.

Tài liệu KiotViet tham khảo:
`https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-khach-hang/khach-hang/#ii-cac-thao-tac-co-ban`

Hiện trạng source đã audit:
- `resources/js/Pages/Customers/Index.vue` đã có button **Chiết khấu thanh toán** trong tab Công nợ nhưng đang là button tĩnh, chưa có handler/modal.
- `routes/web.php` hiện có:
  - `POST /customers/{customer}/debt-payment`
  - `POST /customers/{customer}/debt-adjust`
  - `GET /customers/{customer}/outstanding-invoices`
- `CustomerController@debtPayment()` hiện đang tăng `invoice.customer_paid` khi khách thanh toán.
- Không được dùng `debtPayment()` cho chiết khấu, vì chiết khấu thanh toán không phải tiền khách trả.
- `CustomerDebtService` hiện có `recordAdjustment()` có thể dùng để ghi ledger công nợ bằng amount âm/dương.
- `customer_debts.type` đang dùng `adjustment`, `payment`, `sale`, `return`; phase này không đổi enum/type hiện có.
- Bảng `customer_debts` không có `invoice_id`, nên để phân bổ chiết khấu theo hóa đơn cần thêm bảng riêng.

## Mục tiêu
Làm tính năng **Chiết khấu thanh toán** theo phương án an toàn đã chốt:

1. Thêm nghiệp vụ riêng cho chiết khấu thanh toán, không dùng nhầm thu nợ.
2. Có phiếu chiết khấu riêng mã `CKTT...`.
3. Có thể phân bổ chiết khấu vào hóa đơn còn nợ.
4. Không sửa `invoice.customer_paid`.
5. Không tạo `cash_flows`.
6. Không làm sai sổ quỹ.
7. Không sửa doanh thu hóa đơn.
8. Không ảnh hưởng tồn kho, serial/IMEI, giá vốn.
9. Có thể hủy phiếu chiết khấu để rollback công nợ.
10. Không backfill dữ liệu cũ.
11. Chỉ dữ liệu mới phát sinh khi user bấm **Tạo phiếu**.

## Có ảnh hưởng dữ liệu đang có không?
- Có, vì có migration thêm bảng mới và khi user tạo/hủy phiếu sẽ thay đổi công nợ khách.
- Cần xác nhận trước khi chạy migration trên production.
- Phase này được phép tạo migration mới chỉ thêm bảng mới.
- Không được sửa schema bảng cũ.
- Không được backfill.
- Không được update hàng loạt.
- Không được sửa dữ liệu hóa đơn cũ.
- Không được sửa `invoice.customer_paid`.

Bảng mới được phép tạo:
- `customer_payment_discounts`
- `customer_payment_discount_allocations`

Không được thêm/sửa/xóa cột trên bảng hiện có:
- `customers`
- `invoices`
- `cash_flows`
- `customer_debts`

## Không được làm
- Không `migrate:fresh`.
- Không backfill dữ liệu cũ.
- Không update hàng loạt.
- Không xóa dữ liệu.
- Không sửa `invoice.customer_paid`.
- Không dùng `debtPayment()` để ghi chiết khấu.
- Không tăng `invoice.customer_paid` khi tạo chiết khấu.
- Không tạo `CashFlow` cho chiết khấu thanh toán.
- Không tạo phiếu thu.
- Không tạo phiếu chi.
- Không làm thay đổi sổ quỹ.
- Không sửa doanh thu hóa đơn.
- Không sửa tồn kho.
- Không sửa serial/IMEI.
- Không sửa giá vốn bình quân.
- Không đổi `customer_debts.type` sang enum/type mới nếu chưa audit toàn bộ report/filter.
- Không sửa logic hủy hóa đơn.
- Không sửa logic trả hàng.
- Không sửa logic cấn trừ công nợ NCC.
- Không commit `.env`, logs, dump, `vendor`, `node_modules`, `public/build`.

## Discovery bắt buộc
Đọc source trước khi sửa:

- `routes/web.php`
- `app/Http/Controllers/CustomerController.php`
  - `debtHistory()`
  - `debtPayment()`
  - `debtAdjust()`
  - `outstandingInvoices()`
- `app/Services/CustomerDebtService.php`
- `app/Models/CustomerDebt.php`
- `app/Models/Customer.php`
- `app/Models/Invoice.php`
- `resources/js/Pages/Customers/Index.vue`
- `resources/js/Components/MoneyInput.vue`
- `resources/js/Components/DateTimePicker.vue`

Phải xác nhận lại:
- Button **Chiết khấu thanh toán** đang nằm trong tab Công nợ nhưng chưa có handler.
- Modal thu nợ đang dùng `outstandingInvoices`.
- `debtPayment()` đang tăng `invoice.customer_paid`, nên không dùng cho chiết khấu.
- `CustomerDebtService::recordAdjustment()` có thể dùng để ghi ledger công nợ.

## Database/Migration

### 1. Tạo bảng `customer_payment_discounts`
Tạo migration mới, không sửa migration cũ.

Schema đề xuất:

```php
Schema::create('customer_payment_discounts', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->foreignId('customer_id')->constrained()->restrictOnDelete();
    $table->decimal('amount', 15, 2);
    $table->dateTime('discount_at');
    $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->boolean('allocate_to_invoices')->default(true);
    $table->string('status')->default('active'); // active | cancelled
    $table->text('note')->nullable();
    $table->dateTime('cancelled_at')->nullable();
    $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
    $table->text('cancel_reason')->nullable();
    $table->timestamps();

    $table->index(['customer_id', 'status']);
    $table->index('discount_at');
});
```

Ghi chú:
- Đây là bảng mới, được phép có unique/index/FK trong bảng mới.
- Không FK cascade delete dữ liệu nghiệp vụ.
- Không xóa record khi hủy, chỉ đổi status.

### 2. Tạo bảng `customer_payment_discount_allocations`
Schema đề xuất:

```php
Schema::create('customer_payment_discount_allocations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('customer_payment_discount_id')
        ->constrained('customer_payment_discounts')
        ->restrictOnDelete();
    $table->foreignId('customer_id')->constrained()->restrictOnDelete();
    $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
    $table->decimal('amount', 15, 2);
    $table->timestamps();

    $table->index(['customer_id', 'invoice_id']);
    $table->index('customer_payment_discount_id');
});
```

Ghi chú:
- Không sửa bảng `invoices`.
- Không thêm cột `discount_paid` vào invoices.
- Không sửa `invoice.customer_paid`.

### 3. Rollback migration
Trong `down()`:
- Drop `customer_payment_discount_allocations` trước.
- Drop `customer_payment_discounts` sau.

## Models

### 1. Tạo model `CustomerPaymentDiscount`
File:
`app/Models/CustomerPaymentDiscount.php`

Yêu cầu:
- `$fillable` đầy đủ.
- Cast:
  - `amount` decimal:2
  - `discount_at` datetime
  - `allocate_to_invoices` boolean
  - `cancelled_at` datetime
- Relationships:
  - `customer()`
  - `allocations()`
  - `creator()`
  - `performer()`
  - `canceller()`
- Helper:
  - `isActive()`
  - `isCancelled()`

### 2. Tạo model `CustomerPaymentDiscountAllocation`
File:
`app/Models/CustomerPaymentDiscountAllocation.php`

Yêu cầu:
- `$fillable`:
  - `customer_payment_discount_id`
  - `customer_id`
  - `invoice_id`
  - `amount`
- Cast `amount` decimal:2.
- Relationships:
  - `discount()`
  - `customer()`
  - `invoice()`

## Service

Tạo service mới:

`app/Services/CustomerPaymentDiscountService.php`

### 1. Method `getInvoiceDiscountAllocatedAmount($invoiceId)`
Trả tổng chiết khấu còn hiệu lực đã phân bổ cho hóa đơn:

```php
CustomerPaymentDiscountAllocation::query()
    ->where('invoice_id', $invoiceId)
    ->whereHas('discount', fn ($q) => $q->where('status', 'active'))
    ->sum('amount');
```

### 2. Method `getInvoiceRemainingReceivable(Invoice $invoice)`
Công thức:

```text
remaining = invoice.total - invoice.customer_paid - active_discount_allocated
```

Yêu cầu:
- Loại hóa đơn `Đã hủy`.
- Clamp minimum 0.
- Không sửa invoice.

### 3. Method `getDiscountableInvoices(Customer $customer)`
Lấy hóa đơn còn phải thu:
- `customer_id = customer.id`
- `status != Đã hủy`
- `total > customer_paid`
- tính thêm `discount_allocated`
- tính `remaining_after_discount`
- chỉ trả hóa đơn có `remaining_after_discount > 0`
- order theo ngày cũ trước.

Response item gồm:
- `id`
- `code`
- `created_at`
- `total`
- `customer_paid`
- `discount_allocated`
- `remaining`

### 4. Method `create(Customer $customer, array $payload)`
Bắt buộc chạy trong `DB::transaction`.

Validation business:
- Lock customer bằng `Customer::lockForUpdate()`.
- Chỉ dùng `customers.debt_amount`, không dùng net debt `debt_amount - supplier_debt_amount`.
- Nếu `customer.debt_amount <= 0`: không cho tạo.
- `amount > 0`.
- `amount <= customer.debt_amount`.
- Nếu `allocate_to_invoices = true`:
  - `allocations` bắt buộc có ít nhất 1 dòng.
  - Tổng allocations phải bằng `amount`.
  - Mỗi allocation phải thuộc invoice của customer.
  - Invoice không được `Đã hủy`.
  - Allocation amount không vượt quá remaining receivable đã tính sau các discount active trước đó.
- Nếu `allocate_to_invoices = false`:
  - Không tạo allocation.
  - Vẫn giảm công nợ tổng.

Tạo mã phiếu:
- Prefix `CKTT`
- Dạng đề xuất: `CKTT` + `ymdHis` + random 2 số.
- Đảm bảo không trùng `code`.

Tạo `CustomerPaymentDiscount`:
- `customer_id`
- `amount`
- `discount_at`
- `performed_by`
- `created_by`
- `allocate_to_invoices`
- `status = active`
- `note`

Nếu có allocations:
- tạo từng dòng `CustomerPaymentDiscountAllocation`.

Ghi ledger công nợ:
- Dùng `CustomerDebtService::recordAdjustment()`.
- Amount signed là âm:

```php
app(CustomerDebtService::class)->recordAdjustment(
    $customer->id,
    -abs($discount->amount),
    'Chiết khấu thanh toán ' . $discount->code . ($discount->note ? ' - ' . $discount->note : ''),
    ['ref_code' => $discount->code]
);
```

Yêu cầu:
- Không tạo CashFlow.
- Không sửa `invoice.customer_paid`.
- Không sửa invoice total.
- Không sửa doanh thu.

### 5. Method `cancel(CustomerPaymentDiscount $discount, ?string $reason)`
Bắt buộc chạy trong `DB::transaction`.

Validation:
- Nếu `status = cancelled`: không hủy lại.
- Lock discount và customer.
- Set:
  - `status = cancelled`
  - `cancelled_at = now()`
  - `cancelled_by = auth()->id()`
  - `cancel_reason`
- Không xóa allocations.
- Allocations tự mất hiệu lực vì parent status cancelled.
- Ghi ledger đảo lại công nợ bằng amount dương:

```php
app(CustomerDebtService::class)->recordAdjustment(
    $customer->id,
    abs($discount->amount),
    'Hủy chiết khấu thanh toán ' . $discount->code . ($reason ? ' - ' . $reason : ''),
    ['ref_code' => $discount->code]
);
```

Yêu cầu:
- Không tạo CashFlow.
- Không sửa invoice.
- Không xóa discount.

## Backend

Có thể làm trong `CustomerController` hoặc tạo controller riêng `CustomerPaymentDiscountController`.  
Khuyến nghị tạo controller riêng để không làm `CustomerController` quá dài:

`app/Http/Controllers/CustomerPaymentDiscountController.php`

### Routes
Thêm vào `routes/web.php`:

```php
Route::middleware('permission:customers.view')->group(function () {
    Route::get('/customers/{customer}/payment-discount-invoices', [CustomerPaymentDiscountController::class, 'discountableInvoices']);
});

Route::middleware('permission:customers.debt_adjust')->group(function () {
    Route::post('/customers/{customer}/payment-discounts', [CustomerPaymentDiscountController::class, 'store']);
    Route::post('/customers/{customer}/payment-discounts/{paymentDiscount}/cancel', [CustomerPaymentDiscountController::class, 'cancel']);
});
```

Ghi chú:
- Nếu muốn tạo permission riêng `customers.payment_discount` thì phải audit permission/migration seed riêng. Phase này tạm dùng `customers.debt_adjust` để tránh thay đổi quyền.
- Không đổi permission hệ thống nếu chưa audit.

### 1. `discountableInvoices(Customer $customer)`
Response JSON:
```json
{
  "customer": {
    "id": 1,
    "name": "Nguyễn Văn A",
    "debt_amount": 340000
  },
  "invoices": [
    {
      "id": 10,
      "code": "HD000023",
      "created_at": "2026-05-23T10:58:00",
      "total": 440000,
      "customer_paid": 100000,
      "discount_allocated": 0,
      "remaining": 340000
    }
  ]
}
```

### 2. `store(Request $request, Customer $customer)`
Validate:
```php
[
    'amount' => ['required', 'numeric', 'min:1'],
    'discount_at' => ['nullable', 'date'],
    'performed_by' => ['nullable', 'integer', 'exists:users,id'],
    'note' => ['nullable', 'string', 'max:500'],
    'allocate_to_invoices' => ['boolean'],
    'allocations' => ['array'],
    'allocations.*.invoice_id' => ['required_with:allocations', 'integer', 'exists:invoices,id'],
    'allocations.*.amount' => ['required_with:allocations', 'numeric', 'min:0'],
]
```

Normalize:
- Nếu `discount_at` empty thì dùng `now()`.
- Nếu `performed_by` empty thì dùng `auth()->id()`.
- `amount` parse number.
- `allocations` bỏ dòng amount <= 0.

Gọi service create.

Response:
- JSON nếu wantsJson:
```json
{
  "success": true,
  "message": "Đã tạo phiếu chiết khấu thanh toán CKTT..."
}
```
- Back redirect nếu non-json.

### 3. `cancel(Request $request, Customer $customer, CustomerPaymentDiscount $paymentDiscount)`
Validate:
```php
[
    'reason' => ['nullable', 'string', 'max:500'],
]
```

Guard:
- Discount phải thuộc customer.
- Nếu đã cancelled thì trả lỗi rõ.

Gọi service cancel.

## Sửa `CustomerController@outstandingInvoices()`
Hiện đang tính:

```php
remaining = invoice.total - invoice.customer_paid
```

Phải sửa để trừ chiết khấu đã phân bổ active:

```text
remaining = invoice.total - invoice.customer_paid - active_payment_discount_allocated
```

Yêu cầu:
- Không sửa `invoice.customer_paid`.
- Không trả hóa đơn `Đã hủy`.
- Không trả hóa đơn `remaining <= 0`.
- Response thêm:
  - `discount_allocated`
  - `remaining`

## Sửa `CustomerController@debtPayment()`
Trong cả manual và auto mode, khi tính số tiền còn phải thu của hóa đơn, phải trừ chiết khấu active đã phân bổ.

### Manual mode
Thay:

```php
$remaining = $invoice->total - $invoice->customer_paid;
```

Bằng:

```php
$remaining = app(CustomerPaymentDiscountService::class)
    ->getInvoiceRemainingReceivable($invoice);
```

Khi increment `customer_paid`, chỉ increment tối đa `remaining`.

### Auto mode
Danh sách invoices còn nợ phải lấy bằng service hoặc query + filter remaining sau discount.

Yêu cầu:
- Không allocate payment vào phần đã được chiết khấu.
- Không làm `invoice.customer_paid` vượt `invoice.total - discount_allocated`.

## Sửa `CustomerController@debtHistory()`
Mục tiêu hiển thị ledger đẹp.

Hiện `CustomerDebtService::recordAdjustment()` sẽ ghi type `adjustment`.

Trong mapper debt history:
- Nếu `ref_code` bắt đầu bằng `CKTT` và amount âm:
  - `type = 'Chiết khấu thanh toán'`
  - `type_raw = 'payment_discount'`
- Nếu `ref_code` bắt đầu bằng `CKTT` và amount dương, note chứa `Hủy chiết khấu thanh toán`:
  - `type = 'Hủy chiết khấu thanh toán'`
  - `type_raw = 'payment_discount_cancel'`

Bổ sung metadata nếu tìm thấy discount theo code:
- `payment_discount_id`
- `payment_discount_status`
- `can_cancel`

Chỉ `can_cancel = true` với dòng:
- `type_raw = payment_discount`
- discount status active
- amount âm

Không backfill.

## Frontend

File chính:
`resources/js/Pages/Customers/Index.vue`

### 1. Button Chiết khấu thanh toán
Button hiện đang tĩnh:

```vue
<button>
    Chiết khấu thanh toán
</button>
```

Gắn handler:

```vue
<button
    @click="openPaymentDiscountModal(customer)"
    class="..."
>
    Chiết khấu thanh toán
</button>
```

Disable hoặc báo lỗi nếu:
- `Number(customer.debt_amount || 0) <= 0`

Lưu ý:
- Dùng `customer.debt_amount` để kiểm tra nợ khách phải thu.
- Không dùng `customerNetDebt(customer)` vì khách có thể đồng thời là NCC.

### 2. State modal
Thêm state:

```js
const paymentDiscountModal = reactive({
    show: false,
    loadingInvoices: false,
    submitting: false,
    customer: null,
    invoices: [],
});

const paymentDiscountForm = reactive({
    amount: 0,
    discount_at: '',
    performed_by: '',
    note: '',
    allocate_to_invoices: true,
});
```

Nếu chưa có danh sách user/người thực hiện trong props:
- Mặc định backend dùng `auth()->id()`.
- Frontend có thể ẩn field người thực hiện hoặc hiển thị text user hiện tại nếu có sẵn.
- Không thêm query phức tạp nếu chưa có data.

### 3. Open modal
`openPaymentDiscountModal(customer)`:
- Nếu `customer.debt_amount <= 0` thì alert:
  `Khách hàng không còn nợ phải thu, không thể tạo chiết khấu thanh toán.`
- Set customer.
- Set amount = 0.
- Set note = ''.
- Set allocate_to_invoices = true.
- Set discount_at = now theo format phù hợp `DateTimePicker`.
- Load invoices từ:
  `/customers/{customer.id}/payment-discount-invoices`
- Mỗi invoice thêm `allocAmount = 0`.
- Show modal.

### 4. Auto allocate
Khi nhập số tiền chiết khấu và `allocate_to_invoices = true`:
- Tự phân bổ từ hóa đơn cũ trước.
- Không vượt `invoice.remaining`.
- Tổng phân bổ phải bằng amount nếu đủ hóa đơn.
- Nếu amount lớn hơn tổng remaining thì báo lỗi/không cho submit.

Thêm function:

```js
const allocatePaymentDiscount = () => {
    let remaining = Number(paymentDiscountForm.amount || 0);
    paymentDiscountModal.invoices = paymentDiscountModal.invoices.map((inv) => {
        const max = Number(inv.remaining || 0);
        const alloc = Math.min(max, Math.max(remaining, 0));
        remaining -= alloc;
        return { ...inv, allocAmount: alloc };
    });
};
```

Watch `paymentDiscountForm.amount` và `allocate_to_invoices`.

### 5. Manual allocation
Cho phép user sửa từng dòng `allocAmount` bằng `MoneyInput`.
Validate frontend:
- Tổng allocation <= amount.
- Mỗi allocation <= invoice.remaining.
- Nếu `allocate_to_invoices` true thì tổng allocation phải bằng amount.
- Hiển thị:
  - `Chiết khấu chưa phân bổ: xxxđ`
  - Nếu khác 0 thì disable nút Tạo phiếu.

### 6. Modal UI
Modal giống KiotViet, gồm:
- Title: `Chiết khấu thanh toán`
- Khách hàng: tên khách
- Nợ hiện tại: format VND
- Thời gian: `DateTimePicker`
- Người thực hiện: nếu có data thì select; nếu chưa có thì text `Tài khoản hiện tại`
- Chiết khấu cho khách: `MoneyInput`
- Nợ còn lại: `current debt - amount`
- Ghi chú: input/textarea
- Checkbox: `Phân bổ vào hóa đơn`
- Table nếu phân bổ:
  - Mã hóa đơn
  - Thời gian
  - Giá trị hóa đơn
  - Còn phải thu
  - Đã chiết khấu
  - Chiết khấu phân bổ
  - Công nợ sau CK
- Footer:
  - Bỏ qua
  - Tạo phiếu

Không dùng input text thường cho tiền, dùng `MoneyInput`.

### 7. Submit
`submitPaymentDiscount()`:
Payload:

```js
{
    amount: Number(paymentDiscountForm.amount || 0),
    discount_at: paymentDiscountForm.discount_at,
    performed_by: paymentDiscountForm.performed_by || null,
    note: paymentDiscountForm.note,
    allocate_to_invoices: paymentDiscountForm.allocate_to_invoices,
    allocations: paymentDiscountForm.allocate_to_invoices
        ? paymentDiscountModal.invoices
            .filter(inv => Number(inv.allocAmount || 0) > 0)
            .map(inv => ({ invoice_id: inv.id, amount: Number(inv.allocAmount || 0) }))
        : [],
}
```

POST:
`/customers/{customer.id}/payment-discounts`

On success:
- Close modal.
- Reload debt history:
  - `await loadDebtHistory(customer.id)`
- Reload customers list:
  - `router.reload({ only: ['customers', 'summary'], preserveScroll: true })`
- Alert/toast success.

### 8. Hiển thị trong tab Công nợ
Nếu backend trả `entry.type = Chiết khấu thanh toán`, UI hiện như các dòng khác.

Với dòng có:
- `entry.type_raw === 'payment_discount'`
- `entry.can_cancel === true`

Hiển thị nút nhỏ `Hủy` hoặc icon trong dòng.

Click gọi:
`cancelPaymentDiscount(customer.id, entry.payment_discount_id)`

### 9. Hủy chiết khấu
Frontend:
- Confirm hoặc modal nhập lý do.
- POST:
`/customers/{customerId}/payment-discounts/{discountId}/cancel`
Payload:
```js
{ reason }
```

On success:
- Reload debt history.
- Reload customers/summary.
- Không reload full page nếu không cần.

## Backend response trong `debtHistory()`
Bổ sung map discount theo code:

```php
$discountCodes = $debts->pluck('ref_code')
    ->filter(fn ($code) => str_starts_with((string) $code, 'CKTT'))
    ->values();

$discountsByCode = CustomerPaymentDiscount::whereIn('code', $discountCodes)
    ->get()
    ->keyBy('code');
```

Trong mapper ledger:
```php
$isPaymentDiscount = str_starts_with((string) $d->ref_code, 'CKTT');
$isDiscountCancel = $isPaymentDiscount && (float) $d->amount > 0 && str_contains(mb_strtolower((string) $d->note), 'hủy chiết khấu');

if ($isPaymentDiscount && !$isDiscountCancel && (float) $d->amount < 0) {
    $label = 'Chiết khấu thanh toán';
    $typeRaw = 'payment_discount';
}

if ($isDiscountCancel) {
    $label = 'Hủy chiết khấu thanh toán';
    $typeRaw = 'payment_discount_cancel';
}
```

Thêm:
```php
$discount = $discountsByCode[$d->ref_code] ?? null;
if ($discount) {
    $entry['payment_discount_id'] = $discount->id;
    $entry['payment_discount_status'] = $discount->status;
    $entry['can_cancel'] = $typeRaw === 'payment_discount' && $discount->status === 'active';
}
```

## Validation chi tiết

### Store discount
Không cho:
- amount <= 0
- amount > customer.debt_amount
- customer.debt_amount <= 0
- allocation vào invoice không thuộc customer
- allocation vào invoice `Đã hủy`
- allocation vượt remaining
- total allocation khác amount nếu `allocate_to_invoices = true`
- amount lớn hơn tổng remaining invoices nếu allocate true

### Cancel discount
Không cho:
- hủy phiếu không thuộc customer
- hủy phiếu đã cancelled
- xóa record
- sửa allocations

## Tests bắt buộc

### Feature tests
Tạo test mới:

`tests/Feature/Customers/CustomerPaymentDiscountTest.php`

Cases:

#### 1. Tạo chiết khấu không phân bổ
- Customer debt_amount = 340000
- POST amount = 100000, allocate_to_invoices = false
- Assert:
  - có row `customer_payment_discounts`
  - status active
  - amount 100000
  - customer.debt_amount = 240000
  - có `customer_debts` ref_code CKTT amount -100000
  - không có `cash_flows`
  - không đổi `invoice.customer_paid`

#### 2. Tạo chiết khấu có phân bổ vào hóa đơn
- Invoice total 440000, customer_paid 100000, remaining 340000
- Chiết khấu 100000 phân bổ vào invoice
- Assert:
  - allocation amount 100000
  - customer debt giảm 100000
  - `outstandingInvoices()` còn lại 240000
  - `invoice.customer_paid` vẫn 100000

#### 3. Không cho chiết khấu vượt nợ hiện tại
- customer debt 340000
- request amount 500000
- Assert 422/error
- debt không đổi

#### 4. Không cho phân bổ vượt còn phải thu
- remaining invoice 340000
- allocation 400000
- Assert 422/error

#### 5. Không cho phân bổ vào hóa đơn đã hủy
- invoice status `Đã hủy`
- allocation vào invoice đó
- Assert 422/error

#### 6. Debt payment không thu trùng phần đã chiết khấu
- Invoice total 440000, customer_paid 100000
- Discount allocation 100000
- Outstanding còn 240000
- Auto payment 300000
- Assert chỉ increment customer_paid thêm 240000
- Không vượt remaining
- customer debt không âm sai

#### 7. Hủy phiếu chiết khấu
- Tạo CKTT 100000
- Cancel
- Assert:
  - discount status cancelled
  - cancelled_at not null
  - customer debt tăng lại 100000
  - có ledger amount +100000
  - allocation không bị xóa
  - outstanding invoice quay lại như trước discount
  - không có cashflow

### Regression tests
Chạy thêm:
```bash
php artisan test --filter=CustomerDebt
php artisan test --filter=CancelInvoicePaymentDebtFlowTest
php artisan test tests/Feature/Damage/RR09DamageStockTest.php
npm run build
```

Nếu test filter không tồn tại hoặc môi trường thiếu, ghi rõ trong report.

## Manual QA bắt buộc
1. Vào `/customers`.
2. Mở khách hàng có `debt_amount > 0`.
3. Vào tab **Công nợ**.
4. Bấm **Chiết khấu thanh toán**.
5. Modal mở đúng.
6. Nhập chiết khấu 100.000đ.
7. Nợ còn lại hiển thị đúng.
8. Bật **Phân bổ vào hóa đơn**.
9. Hệ thống tự phân bổ vào hóa đơn còn nợ cũ nhất.
10. Sửa phân bổ thủ công, không cho vượt còn phải thu.
11. Bấm **Tạo phiếu**.
12. Tab công nợ có dòng `CKTT...` loại `Chiết khấu thanh toán`, giá trị âm.
13. Nợ hiện tại giảm đúng.
14. Mở lại modal thanh toán, hóa đơn đã chiết khấu chỉ còn số tiền phải thu sau chiết khấu.
15. Kiểm tra hóa đơn:
    - `customer_paid` không đổi.
16. Kiểm tra sổ quỹ:
    - không phát sinh phiếu thu/chi mới.
17. Hủy phiếu chiết khấu.
18. Nợ hiện tại tăng lại.
19. Outstanding invoice quay về như trước.
20. Không có record bị xóa vật lý.

## Report
Tạo file:

`docs/audit/HOTFIX-CUSTOMER-PAYMENT-DISCOUNT.md`

Report phải ghi:
- Source đã kiểm tra.
- Tài liệu KiotViet đã tham khảo.
- Root cause: nút đang chưa có logic; không thể dùng `debtPayment()` vì sẽ làm sai `invoice.customer_paid`.
- Files changed.
- Migrations added.
- Tables added:
  - `customer_payment_discounts`
  - `customer_payment_discount_allocations`
- Có backfill không: Không.
- Có update dữ liệu cũ không: Không.
- Có sửa bảng cũ không: Không.
- Có sửa `invoice.customer_paid` không: Không.
- Có tạo CashFlow không: Không.
- Có ảnh hưởng sổ quỹ không: Không.
- Có hủy phiếu chiết khấu không: Có, bằng status cancelled.
- Tests/build kết quả.
- Manual QA đã chạy hay chưa.
- Rủi ro còn lại.

## Commit/Tag
Commit message:

```bash
feat(customers): add payment discount debt flow
```

Không cần tag.

## Output bắt buộc
Sau khi hoàn thành, trả về:
- Commit SHA.
- Files changed.
- Migration files.
- Models/services/controllers/routes đã thêm/sửa.
- Tests đã chạy + kết quả.
- `npm run build` kết quả.
- Manual QA đã chạy + kết quả.
- Xác nhận không backfill/update dữ liệu cũ.
- Xác nhận không sửa `invoice.customer_paid`.
- Xác nhận không tạo CashFlow.
- Xác nhận không sửa doanh thu hóa đơn.
- Xác nhận không ảnh hưởng tồn kho/serial/giá vốn.
- Nếu có bất kỳ chỗ nào cần đổi schema bảng cũ hoặc update dữ liệu cũ, phải dừng và hỏi xác nhận trước.