<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPaymentDiscount;
use App\Services\CustomerPaymentDiscountService;
use Illuminate\Http\Request;

class CustomerPaymentDiscountController extends Controller
{
    protected CustomerPaymentDiscountService $service;

    public function __construct(CustomerPaymentDiscountService $service)
    {
        $this->service = $service;
    }

    public function discountableInvoices(Customer $customer)
    {
        $invoices = $this->service->getDiscountableInvoices($customer);
        $users = \App\Models\User::select('id', 'name')->orderBy('name')->get();

        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'debt_amount' => (float) $customer->debt_amount,
            ],
            'invoices' => $invoices,
            'users' => $users,
        ]);
    }

    public function store(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'discount_at' => ['nullable', 'date'],
            'performed_by' => ['nullable', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:500'],
            'allocate_to_invoices' => ['boolean'],
            'allocations' => ['array'],
            'allocations.*.invoice_id' => ['required_with:allocations', 'integer', 'exists:invoices,id'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'min:0'],
        ]);

        try {
            $discount = $this->service->create($customer, $validated);
            return response()->json([
                'success' => true,
                'message' => "Đã tạo phiếu chiết khấu thanh toán {$discount->code}",
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function cancel(Request $request, Customer $customer, CustomerPaymentDiscount $paymentDiscount)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($paymentDiscount->customer_id !== $customer->id) {
            return response()->json([
                'success' => false,
                'message' => 'Phiếu chiết khấu không thuộc khách hàng này.',
            ], 403);
        }

        try {
            $this->service->cancel($paymentDiscount, $validated['reason']);
            return response()->json([
                'success' => true,
                'message' => "Đã hủy phiếu chiết khấu thanh toán {$paymentDiscount->code}",
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
