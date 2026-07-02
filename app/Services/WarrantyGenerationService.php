<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Warranty;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 23.7B — Auto-generate Warranty records from a successful sale.
 *
 * Hooked from:
 *   - InvoiceSaleService::createSale  (POS checkout + InvoiceController@store)
 *   - OrderController::processOrder    (Order → Invoice)
 *
 * Rules:
 *   - Hàng has_serial: 1 Warranty/serial.
 *   - Hàng thường: 1 Warranty/invoice item (nếu warranty_months > 0).
 *   - Idempotent theo (invoice_code, product_id, serial_imei).
 *   - Phải nằm trong DB::transaction của caller — rollback nếu sale fail.
 */
class WarrantyGenerationService
{
    public function generateForInvoice(Invoice $invoice): void
    {
        $invoice->loadMissing(['items.product', 'items.serials', 'customer']);

        $purchaseDate = Carbon::parse($invoice->sale_time ?? $invoice->created_at ?? now());
        $customerName = $invoice->customer?->name;
        $invoiceCode  = $invoice->code;

        $normalizer = app(\App\Services\ProductWarrantyPolicyNormalizer::class);

        foreach ($invoice->items as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }

            // Step 24.9 — prefer product.warranty_policies over the legacy
            // warranty_months fallback. Snapshot the full policy + maintenance
            // arrays so future product edits cannot mutate this warranty.
            $warrantyPolicies = Schema::hasColumn('products', 'warranty_policies')
                ? $normalizer->normalizeWarrantyPolicies($product->getAttribute('warranty_policies'))
                : [];
            $maintenancePolicies = Schema::hasColumn('products', 'maintenance_policies')
                ? $normalizer->normalizeMaintenancePolicies($product->getAttribute('maintenance_policies'))
                : [];

            $months  = 0;
            $endDate = null;

            if (!empty($warrantyPolicies)) {
                // Use the default policy to set warranty_period/end_date.
                $primary = $this->pickPrimary($warrantyPolicies);
                $months  = $normalizer->durationInMonths(
                    (int) $primary['duration_value'],
                    (string) $primary['duration_unit'],
                );
                $endDate = $normalizer->addDurationToDate(
                    $purchaseDate,
                    (int) $primary['duration_value'],
                    (string) $primary['duration_unit'],
                );
            } else {
                // Legacy fallback chain (Step 23.7B): item / product / latest purchase_item.
                $months = $this->resolveWarrantyMonths($item, $product);
                if ($months > 0) {
                    $endDate = $purchaseDate->copy()->addMonths($months);
                }
            }

            if ($months <= 0) {
                continue;
            }

            // Compute next_maintenance_date from the first maintenance policy.
            $nextMaintenance = null;
            if (!empty($maintenancePolicies) && Schema::hasColumn('warranties', 'next_maintenance_date')) {
                $first = $maintenancePolicies[0];
                $nextMaintenance = $normalizer->addDurationToDate(
                    $purchaseDate,
                    (int) $first['duration_value'],
                    (string) $first['duration_unit'],
                );
            }

            $base = [
                'customer_name'     => $customerName,
                'warranty_period'   => $months,
                'purchase_date'     => $purchaseDate,
                'warranty_end_date' => $endDate,
            ];
            if (Schema::hasColumn('warranties', 'warranty_policy_snapshot') && !empty($warrantyPolicies)) {
                $base['warranty_policy_snapshot'] = $warrantyPolicies;
            }
            if (Schema::hasColumn('warranties', 'maintenance_policy_snapshot') && !empty($maintenancePolicies)) {
                $base['maintenance_policy_snapshot'] = $maintenancePolicies;
            }
            if (Schema::hasColumn('warranties', 'next_maintenance_date') && $nextMaintenance) {
                $base['next_maintenance_date'] = $nextMaintenance;
            }

            if ($product->has_serial) {
                foreach ($item->serials as $invSerial) {
                    $this->upsertWarranty(
                        $invoiceCode,
                        $product->id,
                        $invSerial->serial_number,
                        $base
                    );
                }
            } else {
                $this->upsertWarranty(
                    $invoiceCode,
                    $product->id,
                    null,
                    $base
                );
            }
        }
    }

    /**
     * Pick the primary policy (default or first row).
     *
     * @param array<int, array{name:string, duration_value:int, duration_unit:string, is_default:bool}> $policies
     */
    private function pickPrimary(array $policies): array
    {
        foreach ($policies as $row) {
            if (!empty($row['is_default'])) return $row;
        }
        return $policies[0];
    }

    private function resolveWarrantyMonths(InvoiceItem $item, Product $product): int
    {
        // 1. invoice_items.warranty_months (snapshot — chưa có trong schema hiện tại).
        if (Schema::hasColumn('invoice_items', 'warranty_months')) {
            $v = $item->getAttribute('warranty_months');
            if (! is_null($v)) {
                return max(0, (int) $v);
            }
        }
        // 2. products.warranty_months (chưa có trong schema hiện tại).
        if (Schema::hasColumn('products', 'warranty_months')) {
            $v = $product->getAttribute('warranty_months');
            if (! is_null($v)) {
                return max(0, (int) $v);
            }
        }
        // 3. Fallback: lấy purchase_items.warranty_months gần nhất cho product này.
        if (Schema::hasColumn('purchase_items', 'warranty_months')) {
            $v = DB::table('purchase_items')
                ->where('product_id', $product->id)
                ->orderByDesc('id')
                ->value('warranty_months');
            if (! is_null($v)) {
                return max(0, (int) $v);
            }
        }
        return 0;
    }

    private function upsertWarranty(string $invoiceCode, int $productId, ?string $serial, array $data): void
    {
        $q = Warranty::where('invoice_code', $invoiceCode)
            ->where('product_id', $productId);
        if (is_null($serial)) {
            $q->whereNull('serial_imei');
        } else {
            $q->where('serial_imei', $serial);
        }
        if ($q->exists()) {
            return;
        }
        Warranty::create(array_merge($data, [
            'invoice_code' => $invoiceCode,
            'product_id'   => $productId,
            'serial_imei'  => $serial,
        ]));
    }
}
