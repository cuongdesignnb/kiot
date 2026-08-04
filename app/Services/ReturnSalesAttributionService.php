<?php

namespace App\Services;

use App\Enums\ReturnStatus;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\OrderReturn;
use App\Models\User;
use App\Support\BusinessDateTime;
use App\Support\Reports\SellerResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnSalesAttributionService
{
    public function __construct(private readonly SellerResolver $sellerResolver) {}

    /**
     * Change only reporting attribution metadata. This deliberately does not
     * call stock, costing, debt, cash-flow, serial, invoice, or return-total services.
     */
    public function update(OrderReturn $return, ?int $employeeId, string $reason, User $actor): OrderReturn
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => 'Lý do điều chỉnh phải có từ 5 đến 500 ký tự.',
            ]);
        }

        return DB::transaction(function () use ($return, $employeeId, $reason, $actor): OrderReturn {
            $locked = OrderReturn::query()
                ->with(['invoice.creator', 'salesAttributionEmployee'])
                ->lockForUpdate()
                ->findOrFail($return->id);

            if ($this->isCancelled($locked)) {
                throw ValidationException::withMessages([
                    'sales_attribution_employee_id' => 'Không thể điều chỉnh người chịu doanh số của phiếu trả đã hủy.',
                ]);
            }

            $employee = null;
            if ($employeeId !== null) {
                $employee = Employee::query()->lockForUpdate()->find($employeeId);
                if (! $employee) {
                    throw ValidationException::withMessages([
                        'sales_attribution_employee_id' => 'Nhân viên chịu doanh số không tồn tại.',
                    ]);
                }
                if (! $employee->is_active) {
                    throw ValidationException::withMessages([
                        'sales_attribution_employee_id' => 'Chỉ được chọn nhân viên đang hoạt động.',
                    ]);
                }
            }

            $new = [
                'employee_id' => $employee?->id,
                'name' => $employee?->name,
            ];
            $old = [
                'employee_id' => $locked->sales_attribution_employee_id === null
                    ? null
                    : (int) $locked->sales_attribution_employee_id,
                'name' => $locked->sales_attribution_name,
            ];
            $hasChanged = $old !== $new || (string) ($locked->sales_attribution_reason ?? '') !== $reason;

            if (! $hasChanged) {
                return $locked->fresh(['invoice.creator', 'salesAttributionEmployee', 'salesAttributionUpdatedBy']);
            }

            // Disable the generic document timestamp so this operation changes
            // precisely attribution metadata, not document business state.
            $locked->timestamps = false;
            $locked->forceFill([
                'sales_attribution_employee_id' => $new['employee_id'],
                'sales_attribution_name' => $new['name'],
                'sales_attribution_reason' => $reason,
                'sales_attribution_updated_by' => $actor->id,
                'sales_attribution_updated_at' => now(),
            ])->save();

            $effectiveSellerMap = $this->sellerResolver->returnSellerMap(
                OrderReturn::query()->whereKey($locked->id),
            );
            $originalKey = $this->sellerResolver->invoiceSellerMap(
                $locked->invoice_id
                    ? \App\Models\Invoice::query()->whereKey($locked->invoice_id)
                    : \App\Models\Invoice::query()->whereRaw('1 = 0'),
            )[$locked->invoice_id] ?? 'unknown';

            ActivityLog::log(
                ActivityLog::ACTION_RETURN_SALES_ATTRIBUTION_UPDATE,
                "Điều chỉnh người chịu doanh số trả hàng {$locked->code}",
                $locked,
                [
                    'return_code' => $locked->code,
                    'original_seller' => [
                        'key' => $originalKey,
                        'employee_id' => str_starts_with($originalKey, 'employee:')
                            ? (int) substr($originalKey, 9)
                            : null,
                        'name' => $this->sellerResolver->originalSellerNameForReturn($locked),
                    ],
                    'old' => $old,
                    'new' => $new,
                    'reason' => $reason,
                    'business_time' => ($businessTime = BusinessDateTime::nullable($locked->return_date) ?? $locked->created_at)
                        ? \Carbon\Carbon::parse($businessTime)->toDateTimeString()
                        : null,
                    'financial_mutation' => false,
                    'inventory_mutation' => false,
                    'effective_seller_key_after_update' => $effectiveSellerMap[$locked->id] ?? 'unknown',
                ],
                $actor->id,
            );

            return $locked->fresh(['invoice.creator', 'salesAttributionEmployee', 'salesAttributionUpdatedBy']);
        });
    }

    private function isCancelled(OrderReturn $return): bool
    {
        return in_array(trim((string) $return->status), [
            ReturnStatus::CANCELLED,
            'cancelled',
            'canceled',
            'void',
            'deleted',
            'Đã hủy',
        ], true);
    }
}
