<?php

namespace App\Http\Controllers\Api\Integrations\PcWebsite;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Warranty;
use App\Support\Integrations\PcWebsite\PcIntegrationResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * Warranty lookup for the website's /tra-cuu-bao-hanh page (HMAC signed).
 *
 * Two ways in:
 *   - ?serial=…                       exact serial / service tag;
 *   - ?phone=…&serial_last4=…         the buyer's phone plus the last 4
 *                                     characters of the serial, so a phone
 *                                     number alone reveals nothing.
 *
 * Only what the buyer needs is returned: no name, address, price or invoice
 * code; the phone is masked. Cancelled invoices are ignored.
 */
class WarrantyLookupController extends Controller
{
    private const LIMIT = 20;

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'serial' => ['nullable', 'string', 'min:4', 'max:100', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:20', 'required_without:serial'],
            'serial_last4' => ['nullable', 'string', 'size:4', 'required_with:phone'],
        ]);
        if ($validator->fails()) {
            return PcIntegrationResponse::error('INVALID_PAYLOAD', 'Thông tin tra cứu không hợp lệ.', $validator->errors()->toArray());
        }
        $validated = $validator->validated();

        $query = Warranty::query()->with('product:id,name')
            ->whereNotNull('serial_imei')
            ->where(fn (Builder $q) => $q->whereNull('invoice_code')
                ->orWhereNotIn('invoice_code', Invoice::query()->where('status', 'Đã hủy')->select('code')));

        if (! empty($validated['serial'])) {
            $serial = mb_strtolower(trim($validated['serial']));
            $query->whereRaw('LOWER(TRIM(serial_imei)) = ?', [$serial]);
            $bySerial = true;
        } else {
            $phone = $this->normalizePhone($validated['phone']);
            $codes = $phone === null ? collect() : $this->invoiceCodesForPhone($phone);
            if ($codes->isEmpty()) {
                return PcIntegrationResponse::success([]);
            }
            $query->whereIn('invoice_code', $codes)
                ->whereRaw('LOWER(serial_imei) LIKE ?', ['%'.mb_strtolower(addcslashes($validated['serial_last4'], '%_\\'))]);
            $bySerial = false;
        }

        $warranties = $query->orderByDesc('purchase_date')->limit(self::LIMIT)->get();
        $phones = $this->phonesByInvoice($warranties->pluck('invoice_code')->filter()->unique());
        $today = now()->startOfDay();

        return PcIntegrationResponse::success($warranties->map(function (Warranty $warranty) use ($bySerial, $phones, $today) {
            $end = $warranty->warranty_end_date ? Carbon::parse($warranty->warranty_end_date)->startOfDay() : null;
            $serial = (string) $warranty->serial_imei;

            return [
                'product_name' => $warranty->product?->name,
                'serial' => $bySerial ? $serial : $this->maskSerial($serial),
                'purchase_date' => $warranty->purchase_date ? Carbon::parse($warranty->purchase_date)->toDateString() : null,
                'warranty_months' => (int) $warranty->warranty_period,
                'warranty_end_date' => $end?->toDateString(),
                'days_left' => $end ? max(0, (int) $today->diffInDays($end, false)) : null,
                'status' => $end === null ? 'unknown' : ($end->lt($today) ? 'expired' : 'active'),
                'customer_phone' => $this->maskPhone($phones[$warranty->invoice_code] ?? null),
            ];
        })->values()->all());
    }

    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '84') && strlen($digits) >= 11) {
            $digits = '0'.substr($digits, 2);
        }

        return preg_match('/^0\d{9,10}$/', $digits) ? $digits : null;
    }

    /** @return Collection<int, string> */
    private function invoiceCodesForPhone(string $phone): Collection
    {
        $suffix = substr($phone, -9);
        $customerIds = Customer::query()
            ->where(fn (Builder $q) => $q->whereIn('phone', [$phone, '84'.substr($phone, 1), '+84'.substr($phone, 1)])
                ->orWhere('phone', 'like', '%'.$suffix))
            ->limit(50)->pluck('id');

        return $customerIds->isEmpty() ? collect() : Invoice::query()
            ->whereIn('customer_id', $customerIds)
            ->where('status', '!=', 'Đã hủy')
            ->pluck('code');
    }

    /** @return array<string, string|null> */
    private function phonesByInvoice(Collection $codes): array
    {
        if ($codes->isEmpty()) {
            return [];
        }

        return Invoice::query()->whereIn('code', $codes)->with('customer:id,phone')->get(['code', 'customer_id'])
            ->mapWithKeys(fn (Invoice $invoice) => [$invoice->code => $invoice->customer?->phone])->all();
    }

    /** 0987654992 → 098****992 */
    private function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (strlen($digits) < 7) {
            return null;
        }

        return substr($digits, 0, 3).str_repeat('*', strlen($digits) - 6).substr($digits, -3);
    }

    private function maskSerial(string $serial): string
    {
        $length = mb_strlen($serial);

        return $length <= 4 ? $serial : str_repeat('•', min(6, $length - 4)).mb_substr($serial, -4);
    }
}
