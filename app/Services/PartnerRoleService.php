<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PartnerRoleService
{
    /**
     * Persist one partner for customer/supplier quick-create flows.
     *
     * `new` creates exactly one row. `link_existing` locks and promotes the
     * selected active, unmerged supplier without copying profile or financial
     * values and without invoking the merge workflow.
     */
    public function createOrLink(
        array $attributes,
        string $mode = 'new',
        int|string|null $linkedSupplierId = null,
    ): Customer {
        if (! in_array($mode, ['new', 'link_existing'], true)) {
            throw ValidationException::withMessages([
                'supplier_linking_mode' => 'Cách xử lý đối tác không hợp lệ.',
            ]);
        }

        return DB::transaction(function () use ($attributes, $mode, $linkedSupplierId): Customer {
            if ($mode === 'link_existing') {
                return $this->linkExisting($linkedSupplierId);
            }

            return $this->createNew($attributes);
        });
    }

    private function createNew(array $attributes): Customer
    {
        $attributes['code'] = $this->resolveCode($attributes['code'] ?? null);

        $codeMatch = Customer::query()
            ->where('code', $attributes['code'])
            ->lockForUpdate()
            ->first();
        $phone = $attributes['phone'] ?? null;
        $phoneMatch = $phone !== null && $phone !== ''
            ? Customer::query()->where('phone', $phone)->lockForUpdate()->first()
            : null;

        if ($codeMatch || $phoneMatch) {
            $errors = [];
            if ($codeMatch) {
                $errors['code'] = ['Mã đối tác đã tồn tại.'];
            }
            if ($phoneMatch) {
                $errors['phone'] = ['Số điện thoại đã tồn tại.'];
            }

            $existing = $this->unambiguousExistingPartner($codeMatch, $phoneMatch);
            $canLink = $existing
                && (bool) $existing->is_supplier
                && ($existing->status ?? 'active') === 'active'
                && $existing->merged_into_id === null;

            throw new PartnerAlreadyExistsException(
                $errors,
                $existing ? $this->partnerSummary($existing) : null,
                $canLink ? 'link_existing' : null,
            );
        }

        return Customer::create($attributes);
    }

    private function linkExisting(int|string|null $linkedSupplierId): Customer
    {
        if (! $linkedSupplierId) {
            throw ValidationException::withMessages([
                'linked_supplier_id' => 'Vui lòng chọn nhà cung cấp cần liên kết.',
            ]);
        }

        $supplier = Customer::query()
            ->whereKey($linkedSupplierId)
            ->lockForUpdate()
            ->first();

        if (! $supplier) {
            throw ValidationException::withMessages([
                'linked_supplier_id' => 'Nhà cung cấp được chọn không tồn tại.',
            ]);
        }
        if (! (bool) $supplier->is_supplier) {
            throw ValidationException::withMessages([
                'linked_supplier_id' => 'Đối tác được chọn không phải là nhà cung cấp.',
            ]);
        }
        if (($supplier->status ?? null) !== 'active') {
            throw ValidationException::withMessages([
                'linked_supplier_id' => 'Nhà cung cấp được chọn đang ngừng hoạt động.',
            ]);
        }
        if ($supplier->merged_into_id !== null) {
            throw ValidationException::withMessages([
                'linked_supplier_id' => 'Nhà cung cấp được chọn đã được gộp và không thể liên kết.',
            ]);
        }

        // Deliberately update only role flags. Code, profile, financial fields,
        // documents and debt history remain exactly on the selected row.
        $supplier->forceFill([
            'is_customer' => true,
            'is_supplier' => true,
        ])->save();

        return $supplier->refresh();
    }

    private function resolveCode(mixed $code): string
    {
        $code = is_string($code) ? trim($code) : $code;
        if ($code !== null && $code !== '') {
            return (string) $code;
        }

        do {
            $generated = 'KH'.now()->format('YmdHis').random_int(10, 99);
        } while (Customer::query()->where('code', $generated)->exists());

        return $generated;
    }

    private function unambiguousExistingPartner(?Customer $codeMatch, ?Customer $phoneMatch): ?Customer
    {
        if ($codeMatch && $phoneMatch && $codeMatch->id !== $phoneMatch->id) {
            return null;
        }

        return $codeMatch ?: $phoneMatch;
    }

    private function partnerSummary(Customer $partner): array
    {
        return [
            'id' => (int) $partner->id,
            'code' => $partner->code,
            'name' => $partner->name,
            'phone' => $partner->phone,
            'is_customer' => (bool) $partner->is_customer,
            'is_supplier' => (bool) $partner->is_supplier,
        ];
    }
}
