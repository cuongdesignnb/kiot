<?php

namespace App\Services\SalesControls;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Batch 10 đợt 4 — "Kiot kiểm soát" rules (items 30 & 32).
 *
 * Every rule sits behind a setting shown in Thiết lập → Đơn hàng →
 * "Kiểm soát bán hàng", seeded ON by migration. Missing rows also mean ON.
 */
class SalesControlService
{
    public const SETTING_REQUIRE_SELLER = 'sales_require_seller';

    public const SETTING_REQUIRE_LINE_PRICE = 'sales_require_line_price';

    public const SETTING_REQUIRE_PURCHASE_COST = 'purchase_require_line_cost';

    public const SETTING_WARN_BELOW_COST = 'sales_warn_below_cost';

    public const SETTING_OLD_INVOICE_DAYS = 'invoice_old_lock_days';

    public const PERMISSION_MODIFY_OLD_INVOICE = 'invoices.modify_old';

    public function sellerRequired(): bool
    {
        return (bool) Setting::get(self::SETTING_REQUIRE_SELLER, true);
    }

    public function linePriceRequired(): bool
    {
        return (bool) Setting::get(self::SETTING_REQUIRE_LINE_PRICE, true);
    }

    public function purchaseCostRequired(): bool
    {
        return filter_var(Setting::get(self::SETTING_REQUIRE_PURCHASE_COST, true), FILTER_VALIDATE_BOOLEAN);
    }

    public function belowCostWarningEnabled(): bool
    {
        return (bool) Setting::get(self::SETTING_WARN_BELOW_COST, true);
    }

    public function oldInvoiceDays(): int
    {
        return max(0, (int) Setting::get(self::SETTING_OLD_INVOICE_DAYS, 0));
    }

    // ─────────────────────────── Seller ───────────────────────────

    /**
     * @throws ValidationException when the seller rule is on and no seller was chosen.
     */
    public function assertSellerPresent(?int $sellerEmployeeId, ?string $sellerName, string $field = 'seller_key'): void
    {
        if (! $this->sellerRequired()) {
            return;
        }

        if ($sellerEmployeeId || trim((string) $sellerName) !== '') {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'Vui lòng chọn người bán cho hóa đơn.',
        ]);
    }

    // ─────────────────────────── Line prices ───────────────────────────

    /**
     * Every sold line must carry a unit price > 0.
     *
     * @param  array<int, array>  $items
     * @param  callable|null  $isExempt  fn(array $item): bool for legitimate 0-price lines
     *
     * @throws ValidationException
     */
    public function assertLinePrices(array $items, string $prefix = 'items', string $priceKey = 'price', ?callable $isExempt = null): void
    {
        if (! $this->linePriceRequired()) {
            return;
        }

        $errors = [];
        $names = $this->productNames($items);
        foreach (array_values($items) as $index => $item) {
            if ($isExempt && $isExempt($item)) {
                continue;
            }
            $price = (float) ($item[$priceKey] ?? 0);
            if ($price > 0) {
                continue;
            }
            $name = $names[(int) ($item['product_id'] ?? 0)] ?? 'hàng hóa';
            $errors["{$prefix}.{$index}.{$priceKey}"] = 'Dòng '.($index + 1)." ({$name}): đơn giá bán phải lớn hơn 0.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Every purchase (phiếu nhập) line must carry a unit cost > 0.
     *
     * @throws ValidationException
     */
    public function assertPurchaseCosts(array $items, string $prefix = 'items', string $priceKey = 'price'): void
    {
        if (! $this->purchaseCostRequired()) {
            return;
        }

        $errors = [];
        $names = $this->productNames($items);
        foreach (array_values($items) as $index => $item) {
            if ((float) ($item[$priceKey] ?? 0) > 0) {
                continue;
            }
            $name = $names[(int) ($item['product_id'] ?? 0)] ?? 'hàng hóa';
            $errors["{$prefix}.{$index}.{$priceKey}"] = 'Dòng '.($index + 1)." ({$name}): giá nhập phải lớn hơn 0.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    // ─────────────────────────── Old invoices ───────────────────────────

    /**
     * "Hóa đơn cũ" = recorded before today (setting 0) or before N days ago.
     * The reference is the time the invoice was entered (lock_started_at),
     * falling back to created_at — the same anchor as the hour time-lock.
     */
    public function isOldInvoice(Invoice $invoice): bool
    {
        $reference = $invoice->lock_started_at ?? $invoice->created_at;
        if (! $reference) {
            return false;
        }

        $cutoff = now()->startOfDay()->subDays($this->oldInvoiceDays());

        return Carbon::parse($reference)->lt($cutoff);
    }

    public function oldInvoiceRuleLabel(): string
    {
        $days = $this->oldInvoiceDays();

        return $days === 0
            ? 'lập trước hôm nay'
            : "lập trước {$days} ngày gần nhất";
    }

    public function canModifyInvoice(Invoice $invoice, ?User $user): bool
    {
        if (! $this->isOldInvoice($invoice)) {
            return true;
        }

        return $user === null || $user->hasPermission(self::PERMISSION_MODIFY_OLD_INVOICE);
    }

    /**
     * Null when allowed, otherwise the Vietnamese message to show.
     */
    public function oldInvoiceBlockMessage(Invoice $invoice, ?User $user, string $verb): ?string
    {
        if ($this->canModifyInvoice($invoice, $user)) {
            return null;
        }

        return "Bạn không có quyền {$verb} hóa đơn cũ (hóa đơn {$this->oldInvoiceRuleLabel()}). "
            .'Cần quyền "Sửa/hủy/đổi người bán hóa đơn cũ".';
    }

    /**
     * @return array<int, string> product_id => name
     */
    private function productNames(array $items): array
    {
        $ids = collect($items)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return Product::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
