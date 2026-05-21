<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Product;
use App\Support\Status\BusinessStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosReturnExchangeService
{
    public function __construct(
        private OrderReturnCreationService $returns,
        private InvoiceSaleService $sales
    ) {
    }

    public function create(array $payload, array $context = []): array
    {
        return DB::transaction(function () use ($payload, $context) {
            $sourceInvoice = Invoice::lockForUpdate()->findOrFail($payload['invoice_id']);
            if (BusinessStatus::isCancelled($sourceInvoice->status)) {
                throw ValidationException::withMessages([
                    'invoice_id' => 'Khong the doi hang cho hoa don da huy.',
                ]);
            }

            $returnCalc = app(ReturnTotalCalculator::class)->calculate([
                'items' => $payload['return']['items'] ?? [],
                'discount' => $payload['return']['discount'] ?? 0,
                'fee_type' => $payload['return']['fee_type'] ?? 'amount',
                'fee_value' => $payload['return']['fee_value'] ?? 0,
                'fee' => $payload['return']['fee'] ?? null,
                'paid_to_customer' => 0,
            ]);

            $exchange = $this->calculateExchange($payload['exchange'] ?? []);
            $returnTotal = (float) $returnCalc['total_refund'];
            $exchangeTotal = (float) $exchange['total'];
            $customerPays = max(0.0, $exchangeTotal - $returnTotal);
            $refundToCustomer = max(0.0, $returnTotal - $exchangeTotal);
            $providedRefund = data_get($payload, 'return.paid_to_customer');
            if ($providedRefund !== null && abs((float) $providedRefund - $refundToCustomer) > 0.5) {
                throw ValidationException::withMessages([
                    'return.paid_to_customer' => 'Số tiền trả khách không khớp với chênh lệch sau đổi hàng. Vui lòng tải lại màn hình.',
                ]);
            }
            $providedCustomerPays = data_get($payload, 'exchange.customer_paid');
            if ($providedCustomerPays !== null && abs((float) $providedCustomerPays - $customerPays) > 0.5) {
                throw ValidationException::withMessages([
                    'exchange.customer_paid' => 'Số tiền khách trả thêm không khớp với chênh lệch sau đổi hàng. Vui lòng tải lại màn hình.',
                ]);
            }

            $returnPayload = [
                'invoice_id' => $sourceInvoice->id,
                'customer_id' => $payload['customer_id'] ?? $sourceInvoice->customer_id,
                'branch_id' => $payload['branch_id'] ?? $sourceInvoice->branch_id,
                'subtotal' => $returnCalc['subtotal'],
                'discount' => $returnCalc['discount'],
                'fee_type' => $returnCalc['fee_type'],
                'fee_value' => $returnCalc['fee_value'],
                'fee' => $returnCalc['fee_amount'],
                'total' => $returnTotal,
                'paid_to_customer' => $refundToCustomer,
                'note' => $payload['note'] ?? ('Doi hang tu hoa don ' . $sourceInvoice->code),
                'items' => $payload['return']['items'] ?? [],
            ];

            $return = $this->returns->create($returnPayload, [
                'created_by_name' => $context['created_by_name'] ?? auth()->user()?->name ?? 'POS',
                'payment_method' => $payload['payment_method'] ?? 'cash',
            ]);

            $exchangePayload = [
                'customer_id' => $payload['customer_id'] ?? $sourceInvoice->customer_id,
                'branch_id' => $payload['branch_id'] ?? $sourceInvoice->branch_id,
                'subtotal' => $exchange['subtotal'],
                'discount' => $exchange['discount'],
                'total' => $exchangeTotal,
                'customer_paid' => $customerPays,
                'payment_method' => $payload['payment_method'] ?? 'cash',
                'note' => trim(($payload['note'] ?? '') . "\nDoi hang tu phieu {$return->code}, hoa don goc {$sourceInvoice->code}"),
                'items' => $exchange['items'],
            ];

            $invoice = $this->sales->createSale($exchangePayload, array_merge([
                'source' => 'pos_exchange',
                'code_prefix' => 'HD' . time(),
                'default_status' => 'Hoàn thành',
                'sales_channel' => 'Đổi hàng POS',
                'created_by_name' => auth()->user()?->name ?? 'POS',
                'transaction_date' => $payload['sale_time'] ?? null,
                'validate_before_purchase_date' => false,
                'validate_stock_setting' => false,
                'allow_oversell' => false,
                'cashflow_payment_method' => $payload['payment_method'] ?? 'cash',
                'cashflow_description_extra' => !empty($payload['bank_account_info'])
                    ? ' - CK: ' . $payload['bank_account_info']
                    : '',
                'stock_movement_branch_id' => $payload['branch_id'] ?? $sourceInvoice->branch_id,
            ], $context['sale_context'] ?? []));

            $this->annotateDocuments($return, $invoice, $sourceInvoice);

            return [
                'return' => $return->fresh(),
                'exchange_invoice' => $invoice->fresh(),
                'settlement' => [
                    'return_total' => $returnTotal,
                    'exchange_total' => $exchangeTotal,
                    'customer_pays' => $customerPays,
                    'refund_to_customer' => $refundToCustomer,
                ],
            ];
        });
    }

    private function calculateExchange(array $exchange): array
    {
        $items = $exchange['items'] ?? [];
        if (empty($items)) {
            throw ValidationException::withMessages([
                'exchange.items' => 'Can co it nhat mot hang doi.',
            ]);
        }

        $subtotal = 0.0;
        $normalizedItems = [];
        $serialAvailability = app(SerialAvailabilityService::class);
        foreach ($items as $item) {
            $qty = (int) ($item['quantity'] ?? 0);
            $price = (float) ($item['price'] ?? 0);
            $discount = (float) ($item['discount'] ?? 0);
            $product = Product::lockForUpdate()->find((int) ($item['product_id'] ?? 0));
            if (!$product) {
                throw ValidationException::withMessages([
                    'exchange.items' => 'Hang doi khong ton tai.',
                ]);
            }
            if ($qty <= 0) {
                throw ValidationException::withMessages([
                    'exchange.items' => 'So luong hang doi phai lon hon 0.',
                ]);
            }
            if ($price <= 0) {
                throw ValidationException::withMessages([
                    'exchange.items' => "Đơn giá hàng đổi '{$product->name}' phải lớn hơn 0.",
                ]);
            }
            $gross = $qty * $price;
            if ($discount < 0) {
                throw ValidationException::withMessages([
                    'exchange.items' => "Giảm giá hàng đổi '{$product->name}' không được âm.",
                ]);
            }
            if ($discount > $gross) {
                throw ValidationException::withMessages([
                    'exchange.items' => "Giảm giá hàng đổi '{$product->name}' không được vượt thành tiền dòng.",
                ]);
            }
            $serialIds = array_values(array_filter(array_map('intval', (array) ($item['serial_ids'] ?? []))));
            if (count($serialIds) !== count(array_unique($serialIds))) {
                throw ValidationException::withMessages([
                    'exchange.items' => "San pham '{$product->name}' co serial hang doi bi trung.",
                ]);
            }
            if ($product->has_serial) {
                if (count($serialIds) !== $qty) {
                    throw ValidationException::withMessages([
                        'exchange.items' => "San pham '{$product->name}' can chon du {$qty} serial hang doi.",
                    ]);
                }
                $valid = $serialAvailability->countSellable($serialIds, $product->id);
                if ($valid !== count($serialIds)) {
                    throw ValidationException::withMessages([
                        'exchange.items' => "San pham '{$product->name}' co serial hang doi khong hop le.",
                    ]);
                }
            } elseif ((float) $product->stock_quantity < $qty) {
                throw ValidationException::withMessages([
                    'exchange.items' => "San pham '{$product->name}' khong du ton kho hang doi (con {$product->stock_quantity}).",
                ]);
            }
            $subtotal += $gross - $discount;
            $normalizedItems[] = [
                'product_id' => (int) $item['product_id'],
                'quantity' => $qty,
                'price' => $price,
                'discount' => $discount,
                'serial_ids' => $serialIds,
            ];
        }

        $discount = max(0.0, (float) ($exchange['discount'] ?? 0));
        $discount = min($discount, $subtotal);

        return [
            'items' => $normalizedItems,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => max(0.0, $subtotal - $discount),
        ];
    }

    private function annotateDocuments($return, Invoice $invoice, Invoice $sourceInvoice): void
    {
        $returnNote = trim((string) ($return->note ?? ''));
        $return->update([
            'note' => trim($returnNote . "\nHoa don doi: {$invoice->code}"),
        ]);

        $invoiceNote = trim((string) ($invoice->note ?? ''));
        $invoice->update([
            'note' => trim($invoiceNote . "\nPhieu tra hang: {$return->code}; hoa don goc: {$sourceInvoice->code}"),
        ]);
    }
}
