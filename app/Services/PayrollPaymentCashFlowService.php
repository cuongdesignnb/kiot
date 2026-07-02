<?php

namespace App\Services;

use App\Models\CashFlow;
use App\Models\PaysheetPayment;
use Illuminate\Support\Facades\Schema;

class PayrollPaymentCashFlowService
{
    public const REFERENCE_TYPE = 'PaysheetPayment';

    public function ensureForPayment(PaysheetPayment $payment): ?CashFlow
    {
        $payment->loadMissing(['paysheet', 'payslip', 'employee', 'cashFlow']);

        if ($payment->status !== 'active' || (int) $payment->amount <= 0) {
            return null;
        }

        $existing = $this->validCashFlowForPayment($payment);
        if ($existing) {
            if (! $payment->cash_flow_id) {
                $payment->forceFill(['cash_flow_id' => $existing->id])->save();
            }

            return $existing;
        }

        $payload = $this->payloadForPayment($payment);
        $cashFlow = CashFlow::create($payload);
        $payment->forceFill(['cash_flow_id' => $cashFlow->id])->save();

        return $cashFlow;
    }

    public function validCashFlowForPayment(PaysheetPayment $payment): ?CashFlow
    {
        if ($payment->cash_flow_id) {
            $direct = CashFlow::query()->active()->whereKey($payment->cash_flow_id)->first();
            if ($direct) {
                return $direct;
            }
        }

        if (Schema::hasColumn('cash_flows', 'idempotency_key')) {
            $byKey = CashFlow::query()
                ->active()
                ->where('idempotency_key', $this->idempotencyKey($payment))
                ->first();
            if ($byKey) {
                return $byKey;
            }
        }

        return CashFlow::query()
            ->active()
            ->where('type', 'payment')
            ->where('reference_type', self::REFERENCE_TYPE)
            ->where('reference_code', $payment->code)
            ->where('amount', (int) $payment->amount)
            ->first();
    }

    public function cashFlowsForPayment(PaysheetPayment $payment)
    {
        return CashFlow::withTrashed()
            ->where(function ($query) use ($payment) {
                $query->where('id', $payment->cash_flow_id ?: 0)
                    ->orWhere(function ($byReference) use ($payment) {
                        $byReference->where('reference_type', self::REFERENCE_TYPE)
                            ->where('reference_code', $payment->code);
                    });

                if (Schema::hasColumn('cash_flows', 'idempotency_key')) {
                    $query->orWhere('idempotency_key', $this->idempotencyKey($payment));
                }
            });
    }

    public function cancelForPayment(PaysheetPayment $payment, string $reason): int
    {
        return $this->cashFlowsForPayment($payment)
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', '!=', 'cancelled');
            })
            ->update([
                'status' => 'cancelled',
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'deleted_at' => now(),
            ]);
    }

    public function idempotencyKey(PaysheetPayment $payment): string
    {
        return "payroll_payment_cashflow:{$payment->id}";
    }

    private function payloadForPayment(PaysheetPayment $payment): array
    {
        $paysheet = $payment->paysheet;
        $employee = $payment->employee;
        $payslip = $payment->payslip;

        $payload = [
            'code' => 'PCPL'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
            'type' => 'payment',
            'amount' => (int) $payment->amount,
            'time' => $payment->paid_at ?? now(),
            'category' => 'Chi luong nhan vien',
            'target_type' => 'employee',
            'target_id' => $employee?->id,
            'target_name' => $employee?->name,
            'reference_type' => self::REFERENCE_TYPE,
            'reference_code' => $payment->code,
            'payment_method' => $payment->method ?: 'cash',
            'description' => 'Chi tra luong '.($paysheet?->code ?: '').' - '.($employee?->name ?: $payslip?->code),
            'status' => 'active',
        ];

        if (Schema::hasColumn('cash_flows', 'branch_id')) {
            $payload['branch_id'] = $paysheet?->branch_id ?? $employee?->branch_id;
        }
        if (Schema::hasColumn('cash_flows', 'idempotency_key')) {
            $payload['idempotency_key'] = $this->idempotencyKey($payment);
        }

        return $payload;
    }
}
