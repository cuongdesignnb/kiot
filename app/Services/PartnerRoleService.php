<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PartnerRoleService
{
    public function __construct(private readonly PartnerTransactionGuard $partnerTransactionGuard) {}

    /**
     * Persist one partner for customer/supplier quick-create flows.
     *
     * primaryRole is explicit controller intent. It must not be inferred from
     * the two role flags because dual-role payloads have both flags set.
     */
    public function createOrLink(
        array $attributes,
        string $mode = 'new',
        int|string|null $linkedPartnerId = null,
        string $primaryRole = 'customer',
    ): Customer {
        if (! in_array($mode, ['new', 'link_existing'], true)) {
            throw ValidationException::withMessages([
                'supplier_linking_mode' => 'Cách xử lý đối tác không hợp lệ.',
            ]);
        }
        if (! in_array($primaryRole, ['customer', 'supplier'], true)) {
            throw ValidationException::withMessages([
                'primary_role' => 'Ngữ cảnh đối tác không hợp lệ.',
            ]);
        }

        $primaryFlag = $primaryRole === 'customer' ? 'is_customer' : 'is_supplier';
        if (! (bool) ($attributes[$primaryFlag] ?? false)) {
            throw ValidationException::withMessages([
                $primaryFlag => $primaryRole === 'customer'
                    ? 'Luồng khách hàng phải có is_customer=true.'
                    : 'Luồng nhà cung cấp phải có is_supplier=true.',
            ]);
        }
        if ($mode === 'link_existing') {
            $counterpartFlag = $primaryRole === 'customer' ? 'is_supplier' : 'is_customer';
            if (! (bool) ($attributes[$counterpartFlag] ?? false)) {
                throw ValidationException::withMessages([
                    $counterpartFlag => $primaryRole === 'customer'
                        ? 'Liên kết khách hàng phải có is_supplier=true.'
                        : 'Liên kết nhà cung cấp phải có is_customer=true.',
                ]);
            }
        }

        $codePrefix = $primaryRole === 'customer' ? 'KH' : 'NCC';

        return DB::transaction(function () use ($attributes, $mode, $linkedPartnerId, $primaryRole, $codePrefix): Customer {
            if ($mode === 'link_existing') {
                return $this->linkExisting($linkedPartnerId, $primaryRole);
            }

            return $this->createNew($attributes, $codePrefix, $primaryRole);
        });
    }

    private function createNew(array $attributes, string $codePrefix, string $primaryRole): Customer
    {
        $attributes['code'] = $this->resolveCode($attributes['code'] ?? null, $codePrefix);

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
                && $this->partnerTransactionGuard->isAvailable($existing)
                && (bool) $existing->{$this->counterpartFlag($primaryRole)};

            throw new PartnerAlreadyExistsException(
                $errors,
                $existing ? $this->partnerSummary($existing) : null,
                $canLink ? 'link_existing' : null,
            );
        }

        return Customer::create($attributes);
    }

    private function linkExisting(int|string|null $linkedPartnerId, string $primaryRole): Customer
    {
        if (! $linkedPartnerId) {
            throw ValidationException::withMessages([
                'linked_supplier_id' => 'Vui lòng chọn đối tác cần liên kết.',
            ]);
        }

        $partner = Customer::query()
            ->whereKey($linkedPartnerId)
            ->lockForUpdate()
            ->first();

        if (! $partner) {
            throw ValidationException::withMessages([
                'linked_supplier_id' => 'Đối tác được chọn không tồn tại.',
            ]);
        }

        $counterpartFlag = $this->counterpartFlag($primaryRole);
        if (! (bool) $partner->{$counterpartFlag}) {
            throw ValidationException::withMessages([
                'linked_supplier_id' => $primaryRole === 'customer'
                    ? 'Đối tác được chọn không phải là nhà cung cấp.'
                    : 'Đối tác được chọn không phải là khách hàng.',
            ]);
        }
        if (! $this->partnerTransactionGuard->isAvailable($partner)) {
            throw ValidationException::withMessages([
                'linked_supplier_id' => $partner->merged_into_id !== null
                    ? 'Đối tác được chọn đã được gộp và không thể liên kết.'
                    : 'Đối tác được chọn đang ngừng hoạt động.',
            ]);
        }

        // Deliberately update only the missing primary role. Code, profile,
        // financial fields, documents and debt history stay on this row.
        $partner->forceFill([
            'is_customer' => $primaryRole === 'customer' ? true : (bool) $partner->is_customer,
            'is_supplier' => $primaryRole === 'supplier' ? true : (bool) $partner->is_supplier,
        ])->save();

        return $partner->refresh();
    }

    private function resolveCode(mixed $code, string $prefix): string
    {
        $code = is_string($code) ? trim($code) : $code;
        if ($code !== null && $code !== '') {
            return (string) $code;
        }

        do {
            $generated = $prefix.now()->format('YmdHis').random_int(10, 99);
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

    private function counterpartFlag(string $primaryRole): string
    {
        return $primaryRole === 'customer' ? 'is_supplier' : 'is_customer';
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
