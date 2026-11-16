<?php

namespace App\Services\Integrations\InventoryApi;

use App\Exceptions\LockPeriodException;
use App\Exceptions\PcIntegrationException;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\InventoryApiToken;
use App\Models\Product;
use App\Models\PublicApiWriteLog;
use App\Models\SerialImei;
use App\Models\User;
use App\Services\CostPendingSaleGuard;
use App\Services\Integrations\PcWebsite\PcCustomerResolver;
use App\Services\InvoiceSaleService;
use App\Services\LockPeriodService;
use App\Services\SalesControls\SalesControlService;
use App\Services\SerialAvailabilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The two write functions of the public Kiot API. Both are narrow on purpose:
 *  1. update products.technician_price (Giá bán thợ) of one SKU;
 *  2. create ONE new sales invoice (never edit/delete an existing one).
 */
class PublicApiWriteService
{
    public const MAX_TECHNICIAN_PRICE = 10_000_000_000;

    public const ACTIVITY_TECHNICIAN_PRICE = 'public_api.technician_price_updated';

    public const ACTIVITY_INVOICE_CREATED = 'public_api.invoice_created';

    public function __construct(
        private readonly InvoiceSaleService $sales,
        private readonly PcCustomerResolver $customers,
        private readonly LockPeriodService $lockPeriods,
        private readonly CostPendingSaleGuard $costPending,
        private readonly SerialAvailabilityService $serialAvailability,
    ) {}

    // ───────────────────────── 1. Technician price ─────────────────────────

    public function updateTechnicianPrice(string $sku, array $body, InventoryApiToken $token, PublicApiWriteLog $log): array
    {
        $unknown = array_values(array_diff(array_keys($body), ['technician_price', 'note']));
        if ($unknown !== []) {
            throw new InventoryApiException('UNKNOWN_FIELDS', 'Chỉ được gửi technician_price và note. Trường không được phép: '.implode(', ', $unknown).'.', 422, ['fields' => $unknown]);
        }

        $validator = Validator::make($body, [
            'technician_price' => ['required', 'integer', 'min:0', 'max:'.self::MAX_TECHNICIAN_PRICE],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'technician_price.required' => 'Thiếu technician_price.',
            'technician_price.integer' => 'technician_price phải là số nguyên (VND).',
            'technician_price.min' => 'technician_price không được âm.',
            'technician_price.max' => 'technician_price vượt giới hạn cho phép.',
        ]);
        if ($validator->fails()) {
            throw new InventoryApiException('VALIDATION_FAILED', 'Dữ liệu không hợp lệ.', 422, $validator->errors()->toArray());
        }
        $newPrice = (int) $body['technician_price'];
        $note = isset($body['note']) ? trim((string) $body['note']) : null;

        return DB::transaction(function () use ($sku, $newPrice, $note, $token, $log) {
            $product = Product::query()->where('sku', $sku)->lockForUpdate()->first();
            if (! $product) {
                throw new InventoryApiException('PRODUCT_NOT_FOUND', 'Không tìm thấy hàng hóa có mã '.$sku.'.', 404);
            }

            $oldPrice = (int) round((float) $product->technician_price);
            $product->forceFill(['technician_price' => $newPrice])->save();

            ActivityLog::log(
                self::ACTIVITY_TECHNICIAN_PRICE,
                "API [{$token->name}] sửa giá bán thợ {$product->sku}: ".number_format($oldPrice).' → '.number_format($newPrice),
                $product,
                [
                    'technician_price_old' => $oldPrice,
                    'technician_price_new' => $newPrice,
                    'note' => $note,
                    'inventory_api_token_id' => $token->id,
                    'token_prefix' => $token->token_prefix,
                    'write_log_id' => $log->id,
                ],
            );

            return [
                'status' => 200,
                'target_type' => 'product',
                'target_id' => $product->id,
                'target_ref' => $product->sku,
                'before' => ['technician_price' => $oldPrice],
                'after' => ['technician_price' => $newPrice, 'note' => $note],
                'data' => [
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'technician_price_old' => $oldPrice,
                    'technician_price_new' => $newPrice,
                ],
            ];
        });
    }

    // ───────────────────────── 2. Invoice create ─────────────────────────

    public function createInvoice(array $body, InventoryApiToken $token, PublicApiWriteLog $log): array
    {
        $data = $this->validateInvoiceBody($body);
        $soldAt = $this->resolveSoldAt($data['sold_at'] ?? null);
        [$sellerId, $sellerName] = $this->resolveSeller($data['seller']);
        $lines = $this->resolveLines($data['items']);
        $this->assertSellable($lines);

        $subtotal = 0;
        foreach ($lines as $line) {
            $subtotal += $line['price'] * $line['quantity'] - $line['discount'];
        }
        $total = $subtotal;
        $customerPaid = (int) ($data['customer_paid'] ?? 0);
        if ($customerPaid > $total) {
            throw new InventoryApiException('OVERPAYMENT', 'Khách đã trả ('.number_format($customerPaid).') lớn hơn tổng hóa đơn ('.number_format($total).').', 422);
        }
        $paymentMethod = $data['payment_method'] ?? 'cash';
        $sourceNote = trim((string) ($data['source_note'] ?? ''));

        try {
            $invoice = DB::transaction(function () use ($data, $lines, $subtotal, $total, $customerPaid, $paymentMethod, $sourceNote, $soldAt, $sellerId, $sellerName, $token) {
                $customer = $this->resolveCustomer($data['customer'] ?? null);
                if (! $customer && $customerPaid < $total) {
                    throw new InventoryApiException('CUSTOMER_REQUIRED', 'Khách lẻ phải trả đủ tiền. Hóa đơn còn nợ cần có khách hàng (customer.code hoặc customer.phone).', 422);
                }

                $payload = [
                    'customer_id' => $customer?->id,
                    'branch_id' => null,
                    'subtotal' => $subtotal,
                    'discount' => 0,
                    'total' => $total,
                    'customer_paid' => $customerPaid,
                    'payment_method' => $paymentMethod,
                    'note' => trim('[API '.$token->name.'] '.$sourceNote),
                    'items' => array_map(fn (array $line) => [
                        'product_id' => $line['product']->id,
                        'quantity' => $line['quantity'],
                        'price' => $line['price'],
                        'discount' => $line['discount'],
                        'note' => null,
                        'serial_ids' => $line['serial_ids'],
                        'warranty_policy' => $line['warranty_policy'],
                    ], $lines),
                ];

                $context = [
                    'source' => 'public_api',
                    'code_prefix' => 'HD'.date('YmdHis'),
                    'default_status' => 'Hoàn thành',
                    'price_book_name' => 'Bảng giá chung',
                    'created_by_name' => 'API: '.$token->name,
                    'seller_id' => $sellerId,
                    'seller_name' => $sellerName,
                    'transaction_date' => $soldAt->format('Y-m-d H:i:s'),
                    'validate_before_purchase_date' => true,
                    'validate_stock_setting' => true,
                    // The API never oversells, whatever the shop setting says.
                    'allow_oversell' => false,
                    'cashflow_payment_method' => $paymentMethod,
                    'cashflow_description_extra' => ' (API '.$token->name.')',
                ];

                return $this->sales->createSale($payload, $context);
            });
        } catch (InventoryApiException $exception) {
            throw $exception;
        } catch (LockPeriodException $exception) {
            throw new InventoryApiException('PERIOD_LOCKED', $exception->getMessage(), 422);
        } catch (ValidationException $exception) {
            throw new InventoryApiException('PARTNER_REJECTED', collect($exception->errors())->flatten()->first() ?: 'Khách hàng không hợp lệ để bán hàng.', 422, $exception->errors());
        } catch (PcIntegrationException $exception) {
            throw new InventoryApiException('INVALID_CUSTOMER', $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            if ($exception::class !== \Exception::class) {
                throw $exception;
            }
            // InvoiceSaleService reports business rejections (stock, serial,
            // purchase date, cost pending) as plain \Exception.
            throw new InventoryApiException($this->classifySaleError($exception->getMessage()), $exception->getMessage(), 422);
        }

        $invoice->refresh();
        ActivityLog::log(
            self::ACTIVITY_INVOICE_CREATED,
            "API [{$token->name}] tạo hóa đơn {$invoice->code}",
            $invoice,
            [
                'inventory_api_token_id' => $token->id,
                'token_prefix' => $token->token_prefix,
                'write_log_id' => $log->id,
                'source_note' => $sourceNote,
                'total' => $total,
            ],
        );

        $customerCode = $invoice->customer_id ? Customer::query()->whereKey($invoice->customer_id)->value('code') : null;
        $remaining = max(0, $total - $customerPaid);

        return [
            'status' => 201,
            'target_type' => 'invoice',
            'target_id' => $invoice->id,
            'target_ref' => $invoice->code,
            'before' => null,
            'after' => [
                'invoice_code' => $invoice->code,
                'customer_code' => $customerCode,
                'seller_name' => $sellerName,
                'sold_at' => $soldAt->toIso8601String(),
                'total' => $total,
                'customer_paid' => $customerPaid,
                'items' => array_map(fn (array $line) => [
                    'sku' => $line['product']->sku,
                    'quantity' => $line['quantity'],
                    'price' => $line['price'],
                    'discount' => $line['discount'],
                    'serials' => $line['serials'],
                ], $lines),
                'source_note' => $sourceNote,
            ],
            'data' => [
                'invoice_id' => $invoice->id,
                'invoice_code' => $invoice->code,
                'total' => $total,
                'customer_paid' => $customerPaid,
                'remaining' => $remaining,
                'customer_code' => $customerCode,
            ],
        ];
    }

    private function validateInvoiceBody(array $body): array
    {
        $validator = Validator::make($body, [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['array'],
            'items.*.sku' => ['nullable', 'string', 'max:255'],
            'items.*.serial' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'items.*.price' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'items.*.discount' => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'items.*.warranty_policy' => ['nullable', 'string', 'max:120'],
            'customer' => ['nullable', 'array'],
            'customer.code' => ['nullable', 'string', 'max:100'],
            'customer.phone' => ['nullable', 'string', 'max:30'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'seller' => ['required', 'array'],
            'seller.user_id' => ['nullable', 'integer', 'min:1'],
            'seller.name' => ['nullable', 'string', 'max:255'],
            'customer_paid' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'payment_method' => ['nullable', 'in:cash,transfer'],
            'sold_at' => ['nullable', 'date'],
            'source_note' => ['nullable', 'string', 'max:500'],
        ]);
        if ($validator->fails()) {
            throw new InventoryApiException('VALIDATION_FAILED', 'Dữ liệu không hợp lệ.', 422, $validator->errors()->toArray());
        }

        return $validator->validated();
    }

    private function resolveSoldAt(?string $value): Carbon
    {
        $timezone = (string) config('app.timezone', 'UTC');
        if ($value === null || trim($value) === '') {
            $soldAt = now()->setTimezone($timezone);
        } else {
            $soldAt = Carbon::parse($value)->setTimezone($timezone);
            if ($soldAt->gt(now()->addMinutes(5))) {
                throw new InventoryApiException('SOLD_AT_IN_FUTURE', 'Thời gian bán (sold_at) không được ở tương lai.', 422);
            }
        }

        if ($this->lockPeriods->isLocked($soldAt)) {
            $lockDate = $this->lockPeriods->getLockDate()?->format('d/m/Y');
            throw new InventoryApiException('PERIOD_LOCKED', 'Ngày bán nằm trong kỳ đã khóa sổ'.($lockDate ? " (trước {$lockDate})" : '').'.', 422);
        }

        return $soldAt;
    }

    /**
     * @return array{0: ?int, 1: string} [seller employee id (invoices.created_by), seller name]
     */
    private function resolveSeller(array $seller): array
    {
        $userId = $seller['user_id'] ?? null;
        $name = trim((string) ($seller['name'] ?? ''));
        if (! $userId && $name === '') {
            throw new InventoryApiException('SELLER_REQUIRED', 'Thiếu người bán (seller.user_id hoặc seller.name).', 422);
        }

        if ($userId) {
            $user = User::query()->whereKey($userId)->first();
            if (! $user || ! $user->isActive()) {
                throw new InventoryApiException('SELLER_NOT_FOUND', 'Không tìm thấy tài khoản người bán đang hoạt động (user_id '.$userId.').', 422);
            }

            return $this->sellerFromUser($user);
        }

        $users = User::query()->where('name', $name)->get()->filter(fn (User $user) => $user->isActive())->values();
        if ($users->count() > 1) {
            throw new InventoryApiException('SELLER_AMBIGUOUS', "Có nhiều tài khoản tên \"{$name}\". Hãy gửi seller.user_id.", 422);
        }
        if ($users->count() === 1) {
            return $this->sellerFromUser($users->first());
        }

        $employees = Employee::query()->where('is_active', true)->where('name', $name)->get(['id', 'name']);
        if ($employees->count() === 1) {
            return [(int) $employees->first()->id, $employees->first()->name];
        }
        if ($employees->count() > 1) {
            throw new InventoryApiException('SELLER_AMBIGUOUS', "Có nhiều nhân viên tên \"{$name}\". Hãy gửi seller.user_id.", 422);
        }

        throw new InventoryApiException('SELLER_NOT_FOUND', "Không tìm thấy người bán \"{$name}\" đang hoạt động.", 422);
    }

    private function sellerFromUser(User $user): array
    {
        $employee = Employee::query()->where('user_id', $user->id)->where('is_active', true)->orderBy('id')->first();

        return $employee ? [(int) $employee->id, $employee->name] : [null, $user->name];
    }

    /**
     * @return array<int, array{product: Product, quantity: int, price: int, discount: int, serial_ids: array<int,int>, serials: array<int,string>, warranty_policy: ?string}>
     */
    private function resolveLines(array $items): array
    {
        $lines = [];
        $seenSerials = [];
        foreach (array_values($items) as $index => $item) {
            $row = $index + 1;
            $sku = trim((string) ($item['sku'] ?? ''));
            $serialNumber = trim((string) ($item['serial'] ?? ''));
            $price = (int) $item['price'];
            $discount = (int) ($item['discount'] ?? 0);
            $warranty = isset($item['warranty_policy']) && trim((string) $item['warranty_policy']) !== '' ? trim((string) $item['warranty_policy']) : null;

            if ($sku === '' && $serialNumber === '') {
                throw new InventoryApiException('VALIDATION_FAILED', "Dòng {$row}: cần sku hoặc serial.", 422);
            }

            // Batch 10 đợt 4 — item 32: đơn giá từng dòng phải > 0 (theo thiết lập).
            if ($price <= 0 && app(SalesControlService::class)->linePriceRequired()) {
                throw new InventoryApiException('INVALID_PRICE', "Dòng {$row}: đơn giá bán phải lớn hơn 0.", 422, [
                    'items.'.$index.'.price' => ['Đơn giá bán phải lớn hơn 0.'],
                ]);
            }

            if ($serialNumber !== '') {
                if (isset($item['quantity']) && (int) $item['quantity'] !== 1) {
                    throw new InventoryApiException('VALIDATION_FAILED', "Dòng {$row}: dòng có serial thì quantity phải là 1.", 422);
                }
                if (isset($seenSerials[mb_strtolower($serialNumber)])) {
                    throw new InventoryApiException('DUPLICATE_SERIAL', "Dòng {$row}: serial {$serialNumber} bị lặp.", 422);
                }
                $seenSerials[mb_strtolower($serialNumber)] = true;

                $candidates = SerialImei::query()->where('serial_number', $serialNumber)
                    ->with(['product' => fn ($product) => $product->withTrashed()])
                    ->get();
                if ($sku !== '') {
                    $candidates = $candidates->filter(fn (SerialImei $serial) => $serial->product?->sku === $sku)->values();
                }
                if ($candidates->isEmpty()) {
                    throw new InventoryApiException('SERIAL_NOT_FOUND', "Dòng {$row}: không tìm thấy Serial/IMEI {$serialNumber}".($sku !== '' ? " của mã {$sku}" : '').'.', 422);
                }
                $inStock = $candidates->filter(fn (SerialImei $serial) => $serial->status === 'in_stock')->values();
                if ($inStock->isEmpty()) {
                    throw new InventoryApiException('SERIAL_NOT_AVAILABLE', "Dòng {$row}: Serial/IMEI {$serialNumber} không còn trong kho (trạng thái: {$candidates->first()->status}).", 422);
                }
                if ($inStock->count() > 1) {
                    throw new InventoryApiException('SERIAL_AMBIGUOUS', "Dòng {$row}: Serial/IMEI {$serialNumber} thuộc nhiều mã hàng, hãy gửi kèm sku.", 422);
                }
                $serial = $inStock->first();
                $product = $serial->product;
                if (! $product || $product->trashed()) {
                    throw new InventoryApiException('PRODUCT_NOT_FOUND', "Dòng {$row}: hàng hóa của serial {$serialNumber} đã bị xóa.", 422);
                }
                if ($this->serialAvailability->findBlockedIds([$serial->id], $product->id) !== []) {
                    throw new InventoryApiException('SERIAL_NOT_AVAILABLE', "Dòng {$row}: Serial/IMEI {$serialNumber} đang sửa/không bán được.", 422);
                }

                $quantity = 1;
                $serialIds = [(int) $serial->id];
                $serials = [$serial->serial_number];
            } else {
                $product = Product::query()->where('sku', $sku)->first();
                if (! $product) {
                    throw new InventoryApiException('PRODUCT_NOT_FOUND', "Dòng {$row}: không tìm thấy hàng hóa có mã {$sku}.", 422);
                }
                $quantity = (int) ($item['quantity'] ?? 1);
                if ($product->has_serial && ! $product->isService()) {
                    throw new InventoryApiException('SERIAL_REQUIRED', "Dòng {$row}: mã {$sku} là hàng Serial/IMEI, cần gửi serial cho từng máy (mỗi dòng 1 serial).", 422);
                }
                $serialIds = [];
                $serials = [];
            }

            if ($discount > $price * $quantity) {
                throw new InventoryApiException('VALIDATION_FAILED', "Dòng {$row}: giảm giá lớn hơn thành tiền.", 422);
            }

            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'price' => $price,
                'discount' => $discount,
                'serial_ids' => $serialIds,
                'serials' => $serials,
                'warranty_policy' => $warranty,
            ];
        }

        return $lines;
    }

    private function assertSellable(array $lines): void
    {
        $products = collect($lines)->pluck('product')->keyBy('id');

        try {
            $this->costPending->assertCanInvoiceProductIds($products->keys());
        } catch (\Exception $exception) {
            throw new InventoryApiException('COST_PENDING', $exception->getMessage(), 422);
        }

        $requested = [];
        foreach ($lines as $line) {
            if ($line['serial_ids'] === []) {
                $requested[$line['product']->id] = ($requested[$line['product']->id] ?? 0) + $line['quantity'];
            }
        }
        foreach ($requested as $productId => $quantity) {
            $product = $products[$productId];
            if ($product->tracksInventory() && (float) $product->stock_quantity < $quantity) {
                throw new InventoryApiException(
                    'STOCK_INSUFFICIENT',
                    "Mã {$product->sku} không đủ tồn kho (còn ".(float) $product->stock_quantity.", cần {$quantity}).",
                    422,
                );
            }
        }
    }

    private function resolveCustomer(?array $customer): ?Customer
    {
        if ($customer === null || $customer === []) {
            return null;
        }

        $code = trim((string) ($customer['code'] ?? ''));
        if ($code !== '') {
            $found = Customer::query()->where('code', $code)->lockForUpdate()->first();
            if (! $found) {
                throw new InventoryApiException('CUSTOMER_NOT_FOUND', "Không tìm thấy khách hàng mã {$code}.", 422);
            }

            return $this->assertUsableCustomer($found);
        }

        $phone = trim((string) ($customer['phone'] ?? ''));
        $name = trim((string) ($customer['name'] ?? ''));
        if ($phone === '') {
            if ($name !== '') {
                throw new InventoryApiException('CUSTOMER_PHONE_REQUIRED', 'Cần customer.code hoặc customer.phone để xác định khách hàng (không tìm theo tên).', 422);
            }

            return null;
        }

        $normalized = $this->customers->normalizeVietnamPhone($phone);
        $found = $this->customers->findByPhone($normalized);
        if ($found) {
            return $this->assertUsableCustomer($found);
        }
        if ($name === '') {
            throw new InventoryApiException('CUSTOMER_NOT_FOUND', "Không tìm thấy khách hàng SĐT {$normalized}. Gửi kèm customer.name để tạo khách mới.", 422);
        }

        return Customer::create([
            'code' => $this->newCustomerCode(),
            'name' => $name,
            'phone' => $normalized,
            'type' => 'individual',
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
            'note' => 'Tạo tự động từ API Kiot (tạo hóa đơn).',
        ]);
    }

    private function assertUsableCustomer(Customer $customer): Customer
    {
        if ($customer->merged_into_id) {
            throw new InventoryApiException('CUSTOMER_MERGED', "Khách hàng {$customer->code} đã được gộp vào khách khác.", 422);
        }
        if (strtolower((string) $customer->status) === 'inactive') {
            throw new InventoryApiException('CUSTOMER_INACTIVE', "Khách hàng {$customer->code} đã ngừng hoạt động.", 422);
        }
        if (! (bool) $customer->is_customer) {
            throw new InventoryApiException('CUSTOMER_NOT_CUSTOMER', "Đối tác {$customer->code} không có vai trò khách hàng.", 422);
        }

        return $customer;
    }

    private function newCustomerCode(): string
    {
        do {
            $code = 'KHAPI'.now()->format('ymdHis').random_int(100, 999);
        } while (Customer::query()->where('code', $code)->exists());

        return $code;
    }

    private function classifySaleError(string $message): string
    {
        $lower = mb_strtolower($message);

        return match (true) {
            str_contains($lower, 'giá vốn') => 'COST_PENDING',
            str_contains($lower, 'serial') || str_contains($lower, 'imei') => 'SERIAL_NOT_AVAILABLE',
            str_contains($lower, 'tồn kho') => 'STOCK_INSUFFICIENT',
            str_contains($lower, 'ngày nhập hàng') => 'BEFORE_PURCHASE_DATE',
            default => 'SALE_REJECTED',
        };
    }
}
