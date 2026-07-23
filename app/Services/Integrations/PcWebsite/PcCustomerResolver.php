<?php

namespace App\Services\Integrations\PcWebsite;

use App\Exceptions\PcIntegrationException;
use App\Models\Customer;

class PcCustomerResolver
{
    public function resolve(array $payload, int $branchId): Customer
    {
        $phone = $this->normalizeVietnamPhone((string) ($payload['phone'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));

        $customer = $this->findByPhone($phone);
        if (! $customer && $email !== '') {
            $matches = Customer::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->limit(3)
                ->get();
            if ($matches->count() > 1) {
                throw new PcIntegrationException('INVALID_PAYLOAD', 'Không thể xác định duy nhất khách hàng theo email.', 422, [[
                    'field' => 'customer.email', 'reason' => 'ambiguous_customer',
                ]]);
            }
            $customer = $matches->first();
        }

        if ($customer) {
            if ($customer->merged_into_id) {
                throw new PcIntegrationException('INVALID_PAYLOAD', 'Khách hàng đã được hợp nhất và không còn hợp lệ để tự động mapping.', 422, [[
                    'field' => 'customer.phone', 'reason' => 'merged_customer',
                ]]);
            }
            if (strtolower((string) $customer->status) === 'inactive') {
                throw new PcIntegrationException('INVALID_PAYLOAD', 'Khách hàng đã ngừng hoạt động hoặc không còn hợp lệ.', 422, [[
                    'field' => 'customer.phone', 'reason' => 'inactive_customer',
                ]]);
            }

            $updates = [];
            if (! (bool) $customer->is_customer) {
                $updates['is_customer'] = true;
            }
            if ($email !== '' && trim((string) $customer->email) === '') {
                $updates['email'] = $email;
            }
            if ($updates !== []) {
                $customer->update($updates);
            }

            return $customer->refresh();
        }

        return Customer::create([
            'code' => $this->newCustomerCode(),
            'name' => trim((string) $payload['name']),
            'phone' => $phone,
            'email' => $email !== '' ? $email : null,
            'type' => 'individual',
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
            'branch_id' => $branchId,
            'created_by' => null,
        ]);
    }

    public function normalizeVietnamPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
        if (str_starts_with($digits, '0084')) {
            $digits = '0'.substr($digits, 4);
        } elseif (str_starts_with($digits, '84')) {
            $digits = '0'.substr($digits, 2);
        } elseif (strlen($digits) === 9) {
            $digits = '0'.$digits;
        }

        if (! preg_match('/^0\d{9,10}$/', $digits)) {
            throw new PcIntegrationException('INVALID_PAYLOAD', 'Số điện thoại khách hàng không hợp lệ.', 422, [[
                'field' => 'customer.phone', 'reason' => 'invalid_vietnam_phone',
            ]]);
        }

        return $digits;
    }

    private function findByPhone(string $normalized): ?Customer
    {
        $suffix = substr($normalized, -9);
        $variants = [$normalized, '84'.substr($normalized, 1), '+84'.substr($normalized, 1)];
        $matches = Customer::query()
            ->where(function ($query) use ($variants, $suffix) {
                $query->whereIn('phone', $variants)->orWhere('phone', 'like', '%'.$suffix);
            })
            ->lockForUpdate()
            ->limit(20)
            ->get()
            ->filter(function (Customer $customer) use ($normalized) {
                try {
                    return $this->normalizeVietnamPhone((string) $customer->phone) === $normalized;
                } catch (PcIntegrationException) {
                    return false;
                }
            })
            ->values();

        if ($matches->count() > 1) {
            throw new PcIntegrationException('INVALID_PAYLOAD', 'Không thể xác định duy nhất khách hàng theo số điện thoại.', 422, [[
                'field' => 'customer.phone', 'reason' => 'ambiguous_customer',
            ]]);
        }

        return $matches->first();
    }

    private function newCustomerCode(): string
    {
        do {
            $code = 'KHWEB'.now()->format('ymdHis').random_int(100, 999);
        } while (Customer::query()->where('code', $code)->exists());

        return $code;
    }
}
