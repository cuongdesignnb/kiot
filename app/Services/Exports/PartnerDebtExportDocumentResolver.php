<?php

namespace App\Services\Exports;

use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturnItem;
use App\Models\ReturnItem;

/**
 * Resolves canonical debt events to their source document and informational
 * product rows.  The resolver deliberately keeps document identity separate
 * from the event identity: cancellation and payment events can point at the
 * same document without creating another financial or product row.
 */
class PartnerDebtExportDocumentResolver
{
    /** @var array<string,array<string,mixed>> */
    private array $identities = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    private array $detailLines = [];

    /** @var array<string,object> */
    private array $documents = [];

    /** @var array<string,float> */
    private array $discounts = [];

    /** @var array<string,bool> */
    private array $preloaded = [];

    /** @var array<string,array<int,object>> */
    private array $legacyDocumentsByTypeCode = [];

    /**
     * Resolve a canonical event to an export document identity.
     *
     * Priority is reference_type/reference_id, then source_type/source_id,
     * then detail identity, with legacy id/code lookup as a final fallback.
     * Payment and adjustment events intentionally resolve to no product
     * document even when they reference an invoice or purchase for context.
     *
     * @return array<string,mixed>
     */
    public function resolve(array $entry): array
    {
        $cacheKey = $this->cacheKey($entry);
        if (! isset($this->identities[$cacheKey])) {
            $this->identities[$cacheKey] = $this->identify($entry);
        }

        $identity = $this->identities[$cacheKey];
        $documentKey = $this->documentKey($identity);
        if ($documentKey !== null && isset($this->documents[$documentKey])) {
            $identity['document_code'] = (string) ($this->documents[$documentKey]->code ?? $identity['document_code'] ?? '');
        }

        if (($identity['original_document_type'] ?? null) && ($identity['original_document_id'] ?? null)) {
            $originalKey = $this->documentKey([
                'document_type' => $identity['original_document_type'],
                'document_id' => $identity['original_document_id'],
            ]);
            if ($originalKey !== null && isset($this->documents[$originalKey])) {
                $identity['original_document_code'] = (string) ($this->documents[$originalKey]->code ?? '');
            }
        }

        return $identity;
    }

    /**
     * Batch-load all document models and detail rows needed by the entries.
     * Canonical entries are resolved without per-entry code lookups.
     */
    public function preload(array $entries, ?string $orientation = null): void
    {
        $this->preloadLegacyDocuments($entries);
        $idsByType = [];
        foreach ($entries as $entry) {
            $identity = $this->resolve($entry);
            $type = $identity['document_type'] ?? null;
            $id = (int) ($identity['document_id'] ?? 0);
            if (! $type || $id <= 0 || ! ($identity['is_product_document'] ?? false)) {
                continue;
            }
            $idsByType[$type][] = $id;
        }

        foreach ($idsByType as $type => $ids) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $ids = array_values(array_filter($ids, fn (int $id): bool => ! isset($this->documents[$type.':'.$id])));
            if ($ids === []) {
                continue;
            }
            if ($type === 'Purchase') {
                foreach (Purchase::query()->whereIn('id', $ids)->get() as $document) {
                    $this->storeDocument('Purchase', $document);
                    $this->discounts['Purchase:'.$document->id] = (float) ($document->discount ?? 0);
                }
            } elseif ($type === 'Invoice') {
                foreach (\App\Models\Invoice::query()->whereIn('id', $ids)->get() as $document) {
                    $this->storeDocument('Invoice', $document);
                }
            } elseif ($type === 'PurchaseReturn') {
                foreach (\App\Models\PurchaseReturn::query()->whereIn('id', $ids)->get() as $document) {
                    $this->storeDocument('PurchaseReturn', $document);
                }
            } elseif ($type === 'OrderReturn') {
                foreach (OrderReturn::query()->whereIn('id', $ids)->get() as $document) {
                    $this->storeDocument('OrderReturn', $document);
                }
            }
        }

        $productWithUnits = static fn ($query) => $query
            ->select(['id', 'sku', 'name'])
            ->with('units:id,product_id,unit_name,conversion_rate,is_base_unit');
        if (($idsByType['Invoice'] ?? []) !== []) {
            $this->preloadDetailLines('Invoice', InvoiceItem::query()->with(['product' => $productWithUnits]), $idsByType['Invoice']);
        }
        if (($idsByType['Purchase'] ?? []) !== []) {
            $this->preloadDetailLines('Purchase', PurchaseItem::query()->with(['product' => $productWithUnits]), $idsByType['Purchase']);
        }
        if (($idsByType['PurchaseReturn'] ?? []) !== []) {
            $this->preloadDetailLines('PurchaseReturn', PurchaseReturnItem::query()->with(['product' => $productWithUnits]), $idsByType['PurchaseReturn']);
        }
        if (($idsByType['OrderReturn'] ?? []) !== []) {
            $this->preloadDetailLines('OrderReturn', ReturnItem::query()->with(['product' => $productWithUnits]), $idsByType['OrderReturn']);
        }

        foreach ($idsByType as $type => $ids) {
            foreach (array_unique(array_map('intval', $ids)) as $id) {
                $this->preloaded[$type.':'.$id] = true;
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function loadDetailLines(array $entry, ?string $orientation = null): array
    {
        $identity = $this->resolve($entry);
        if (! ($identity['is_product_document'] ?? false)) {
            return [];
        }

        $key = $this->documentKey($identity);
        if ($key === null) {
            return [];
        }

        return $this->detailLines[$key] ?? [];
    }

    public function purchaseDiscount(array $entry): float
    {
        $identity = $this->resolve($entry);
        if (($identity['document_type'] ?? null) !== 'Purchase') {
            return 0.0;
        }

        $key = $this->documentKey($identity);
        if ($key !== null && array_key_exists($key, $this->discounts)) {
            return $this->discounts[$key];
        }

        return 0.0;
    }

    /** @param mixed $query */
    private function preloadDetailLines(string $type, $query, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return;
        }

        $foreignKey = match ($type) {
            'Invoice' => 'invoice_id',
            'Purchase' => 'purchase_id',
            'PurchaseReturn' => 'purchase_return_id',
            'OrderReturn' => 'return_id',
            default => null,
        };
        if ($foreignKey === null) {
            return;
        }

        foreach ($query->whereIn($foreignKey, $ids)->get() as $item) {
            $parentId = (int) $item->{$foreignKey};
            $key = $type.':'.$parentId;
            $this->detailLines[$key][] = $this->mapDetailLine($type, $item);
        }

        if ($type === 'Purchase') {
            foreach ($ids as $id) {
                $key = 'Purchase:'.$id;
                $discount = $this->discounts[$key] ?? 0.0;
                if ($discount > 0) {
                    $this->detailLines[$key][] = [
                        'code' => '',
                        'name' => 'Giảm giá hóa đơn',
                        'unit' => '',
                        'quantity' => '',
                        'unit_price' => '',
                        'discount' => $discount,
                        'vat' => '',
                        'cost' => '',
                        'line_total' => -$discount,
                        'note' => '',
                    ];
                }
            }
        }
    }

    /** @return array<string,mixed> */
    private function mapDetailLine(string $type, object $item): array
    {
        $product = $item->product;
        $quantity = (float) ($item->quantity ?? 0);
        $price = (float) ($item->price ?? 0);
        $discount = (float) ($item->discount ?? 0);
        $serial = trim((string) ($item->serial ?? ''));
        $name = (string) ($product?->name ?? $item->product_name ?? '');
        if ($serial !== '') {
            $name = trim($name.' ('.$serial.')');
        }

        $lineTotal = $item->subtotal !== null
            ? (float) $item->subtotal
            : max(0, ($price * $quantity) - $discount);

        return [
            'code' => (string) ($product?->sku ?? $item->product_code ?? ''),
            'name' => $name,
            'unit' => $this->productUnitName($product),
            'quantity' => $quantity,
            'unit_price' => $price,
            'discount' => $discount,
            'vat' => $this->persistedVat($item, $product),
            'cost' => $item->cost_price !== null
                ? (float) $item->cost_price
                : (float) ($item->import_price ?? $price),
            'line_total' => $lineTotal,
            'note' => (string) ($item->note ?? ''),
        ];
    }

    private function storeDocument(string $type, object $document): void
    {
        $this->documents[$type.':'.$document->id] = $document;
    }

    /** @return array<string,mixed> */
    private function identify(array $entry): array
    {
        $eventKind = strtolower((string) ($entry['event_kind'] ?? ''));
        $isPayment = $this->isPayment($entry, $eventKind);
        $isAdjustment = $this->isAdjustment($entry, $eventKind);
        $isCancellation = str_contains($eventKind, 'cancel') || str_contains($eventKind, 'reversal');

        [$type, $id, $source] = $this->firstIdentity($entry);
        if ((! $type || $id <= 0) && $isCancellation) {
            [$type, $id] = $this->parseIdentity((string) ($entry['reversal_of'] ?? $entry['reversal_of_event_identity'] ?? ''));
            $source = 'reversal_of';
        }
        if (! $type || $id <= 0) {
            [$type, $id] = $this->legacyIdentity($entry);
            $source = $source ?: 'legacy_id';
        }

        if ((! $type || $id <= 0) && ! $isPayment && ! $isAdjustment) {
            [$type, $id] = $this->legacyCodeLookup($entry);
            $source = $source ?: 'legacy_code';
        }

        $productDocument = ! $isPayment && ! $isAdjustment && in_array($type, [
            'Invoice', 'Purchase', 'OrderReturn', 'PurchaseReturn',
        ], true) && $id > 0;

        $originalType = $productDocument ? $type : null;
        $originalId = $productDocument ? $id : null;
        if ($isCancellation && $originalType === null) {
            [$originalType, $originalId] = $this->parseIdentity((string) ($entry['reversal_of'] ?? $entry['reversal_of_event_identity'] ?? ''));
        }

        return [
            'document_type' => $productDocument ? $type : null,
            'document_id' => $productDocument ? $id : null,
            'document_code' => (string) ($entry['reference_code'] ?? $entry['code'] ?? ''),
            'original_document_type' => $isCancellation ? $originalType : null,
            'original_document_id' => $isCancellation ? $originalId : null,
            'original_document_code' => '',
            'identity_source' => $source,
            'is_payment' => $isPayment,
            'is_adjustment' => $isAdjustment,
            'is_cancellation' => $isCancellation,
            'is_product_document' => $productDocument,
        ];
    }

    /** @return array{0:?string,1:int,2:?string} */
    private function firstIdentity(array $entry): array
    {
        foreach ([
            ['reference_type', 'reference_id', 'reference'],
            ['source_type', 'source_id', 'source'],
            ['detail_type', 'detail_id', 'detail'],
        ] as [$typeKey, $idKey, $source]) {
            $type = $this->normalizeType($entry[$typeKey] ?? null);
            $id = $this->normalizeId($entry[$idKey] ?? null);
            if ($type && $id > 0) {
                return [$type, $id, $source];
            }
        }

        return [null, 0, null];
    }

    /** @return array{0:?string,1:int} */
    private function legacyIdentity(array $entry): array
    {
        $legacyId = (string) ($entry['id'] ?? $entry['event_identity'] ?? '');
        [$canonicalType, $canonicalId] = $this->parseIdentity($legacyId);
        if ($canonicalType !== null && $canonicalId > 0) {
            return [$canonicalType, $canonicalId];
        }

        if (preg_match('/^(pur|purchase)[-_](\d+)$/i', $legacyId, $m)) {
            return ['Purchase', (int) $m[2]];
        }
        if (preg_match('/^(pret|purchase_return)[-_](\d+)$/i', $legacyId, $m)) {
            return ['PurchaseReturn', (int) $m[2]];
        }
        if (preg_match('/^(inv|invoice)[-_](\d+)$/i', $legacyId, $m)) {
            return ['Invoice', (int) $m[2]];
        }
        if (preg_match('/^(ret|return|order_return)[-_](\d+)$/i', $legacyId, $m)) {
            return ['OrderReturn', (int) $m[2]];
        }

        return [null, 0];
    }

    /** @return array{0:?string,1:int} */
    private function legacyCodeLookup(array $entry): array
    {
        $type = $this->normalizeType($entry['reference_type'] ?? $entry['detail_type'] ?? null);
        $code = trim((string) ($entry['code'] ?? ''));
        if ($code === '' && isset($entry['reference_code'])) {
            $code = trim((string) $entry['reference_code']);
        }
        $codes = array_values(array_unique(array_filter([
            $code,
            preg_replace('/^(HUY-|CANCEL-)/i', '', $code),
        ])));
        if (! $type || $codes === []) {
            return [null, 0];
        }

        $model = null;
        foreach ($codes as $candidate) {
            $model = $this->legacyDocumentsByTypeCode[$type][strtolower($candidate)] ?? null;
            if ($model !== null) {
                break;
            }
        }

        if ($model === null) {
            $model = match ($type) {
                'Invoice' => \App\Models\Invoice::query()->whereIn('code', $codes)->first(),
                'Purchase' => Purchase::query()->whereIn('code', $codes)->first(),
                'PurchaseReturn' => \App\Models\PurchaseReturn::query()->whereIn('code', $codes)->first(),
                'OrderReturn' => OrderReturn::query()->whereIn('code', $codes)->first(),
                default => null,
            };
        }
        if (! $model) {
            return [null, 0];
        }

        $this->storeDocument($type, $model);
        if ($type === 'Purchase') {
            $this->discounts[$type.':'.$model->id] = (float) ($model->discount ?? 0);
        }

        return [$type, (int) $model->id];
    }

    /**
     * Batch legacy code lookup for exports. The one-entry fallback in
     * legacyCodeLookup() remains for callers that use the resolver directly,
     * while exporter preload never performs one query per event.
     */
    private function preloadLegacyDocuments(array $entries): void
    {
        $codesByType = [];
        foreach ($entries as $entry) {
            if ($this->isPayment($entry, strtolower((string) ($entry['event_kind'] ?? ''))) ||
                $this->isAdjustment($entry, strtolower((string) ($entry['event_kind'] ?? '')))) {
                continue;
            }

            $type = $this->normalizeType($entry['reference_type'] ?? $entry['detail_type'] ?? null);
            if ($type === null) {
                continue;
            }
            $rawCodes = [
                trim((string) ($entry['code'] ?? '')),
                trim((string) ($entry['reference_code'] ?? '')),
            ];
            foreach ($rawCodes as $code) {
                if ($code === '') {
                    continue;
                }
                $codesByType[$type][] = $code;
                $codesByType[$type][] = (string) preg_replace('/^(HUY-|CANCEL-)/i', '', $code);
            }
        }

        foreach ($codesByType as $type => $codes) {
            $codes = array_values(array_unique(array_filter($codes)));
            if ($codes === []) {
                continue;
            }
            $models = match ($type) {
                'Invoice' => \App\Models\Invoice::query()->whereIn('code', $codes)->get(),
                'Purchase' => Purchase::query()->whereIn('code', $codes)->get(),
                'PurchaseReturn' => \App\Models\PurchaseReturn::query()->whereIn('code', $codes)->get(),
                'OrderReturn' => OrderReturn::query()->whereIn('code', $codes)->get(),
                default => collect(),
            };
            foreach ($models as $model) {
                $this->storeDocument($type, $model);
                $this->legacyDocumentsByTypeCode[$type][strtolower((string) $model->code)] = $model;
                if ($type === 'Purchase') {
                    $this->discounts[$type.':'.$model->id] = (float) ($model->discount ?? 0);
                }
            }
        }
    }

    public function contextLabel(array $entry, string $fallback): string
    {
        $eventKind = strtolower((string) ($entry['event_kind'] ?? ''));
        if (! $this->isPayment($entry, $eventKind) && ! $this->isAdjustment($entry, $eventKind)) {
            return $fallback;
        }

        if (in_array(strtolower(trim($fallback)), ['payment', 'supplier payment', 'customer payment'], true)) {
            $fallback = 'Thanh toán';
        }
        $parts = [$fallback];
        $method = trim((string) ($entry['payment_method'] ?? ''));
        if ($method !== '') {
            $parts[] = match (strtolower($method)) {
                'cash', 'tien_mat' => 'Tiền mặt',
                'bank', 'bank_transfer', 'transfer' => 'Chuyển khoản',
                default => $method,
            };
        }
        $linkedCode = trim((string) ($entry['payment_for_code'] ?? $entry['linked_document_code'] ?? ''));
        $linkedLabel = trim((string) ($entry['linked_document_label'] ?? ''));
        if ($linkedCode !== '' || $linkedLabel !== '') {
            $parts[] = 'cho '.trim($linkedLabel.' '.$linkedCode);
        }

        return implode(' · ', $parts);
    }

    public function contextNote(array $entry): string
    {
        $parts = [];
        foreach (['note', 'description'] as $key) {
            $value = trim((string) ($entry[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        foreach ([
            'original_cash_flow_amount' => 'Tiền gốc',
            'non_debt_cash_amount' => 'Tiền không ghi nợ',
        ] as $key => $label) {
            if (array_key_exists($key, $entry) && is_numeric($entry[$key])) {
                $parts[] = $label.': '.number_format((float) $entry[$key], 0, ',', '.');
            }
        }

        $identity = $this->resolve($entry);
        $documentKey = $this->documentKey($identity);
        $documentNote = $documentKey !== null
            ? trim((string) ($this->documents[$documentKey]->note ?? ''))
            : '';
        if ($documentNote !== '' && ! in_array($documentNote, $parts, true)) {
            $parts[] = $documentNote;
        }

        return implode(' · ', $parts);
    }

    private function productUnitName(?object $product): string
    {
        if ($product === null || ! method_exists($product, 'relationLoaded') || ! $product->relationLoaded('units')) {
            return '';
        }

        $unit = $product->units->first(static fn (object $item): bool => (bool) $item->is_base_unit)
            ?? $product->units->first();

        return trim((string) ($unit?->unit_name ?? ''));
    }

    private function persistedVat(object $item, ?object $product): string|float
    {
        foreach (['vat', 'vat_rate', 'tax', 'tax_rate', 'vat_amount', 'tax_amount'] as $key) {
            $value = $item->{$key} ?? $product?->{$key};
            if ($value !== null && $value !== '') {
                return is_numeric($value) ? (float) $value : (string) $value;
            }
        }

        return '';
    }

    /** @return array{0:?string,1:int} */
    private function parseIdentity(string $value): array
    {
        $parts = explode('|', $value);
        if (count($parts) < 3) {
            return [null, 0];
        }

        return [$this->normalizeType($parts[1]), $this->normalizeId($parts[2])];
    }

    private function normalizeType(mixed $type): ?string
    {
        $value = trim((string) $type);
        if ($value === '') {
            return null;
        }
        $value = class_basename($value);
        $normalized = strtolower(str_replace(['\\', '-', ' '], '_', $value));

        return match ($normalized) {
            'invoice', 'invoices' => 'Invoice',
            'purchase', 'purchases' => 'Purchase',
            'purchasereturn', 'purchase_return', 'purchase_returns' => 'PurchaseReturn',
            'orderreturn', 'order_return', 'order_returns', 'return', 'returns' => 'OrderReturn',
            default => null,
        };
    }

    private function normalizeId(mixed $value): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }
        if (! is_string($value)) {
            return 0;
        }
        if (preg_match('/(?:^|:)(\d+)(?:$|:)/', $value, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private function isPayment(array $entry, string $eventKind): bool
    {
        $type = strtolower((string) ($entry['reference_type'] ?? $entry['source_type'] ?? ''));

        return str_contains($eventKind, 'payment')
            || str_contains($eventKind, 'refund')
            || in_array($type, ['cashflow', 'cash_flows', 'debtpayment', 'debt_payment'], true);
    }

    private function isAdjustment(array $entry, string $eventKind): bool
    {
        $type = strtolower((string) ($entry['reference_type'] ?? $entry['source_type'] ?? ''));

        return str_contains($eventKind, 'adjustment')
            || str_contains($eventKind, 'offset')
            || str_contains($type, 'debtadjustment')
            || str_contains($type, 'debtoffset');
    }

    /** @param array<string,mixed> $identity */
    private function documentKey(array $identity): ?string
    {
        $type = $identity['document_type'] ?? null;
        $id = (int) ($identity['document_id'] ?? 0);

        return $type && $id > 0 ? $type.':'.$id : null;
    }

    private function cacheKey(array $entry): string
    {
        if (isset($entry['event_identity']) && $entry['event_identity'] !== '') {
            return 'event:'.(string) $entry['event_identity'];
        }

        return 'entry:'.sha1(serialize([
            $entry['reference_type'] ?? null,
            $entry['reference_id'] ?? null,
            $entry['source_type'] ?? null,
            $entry['source_id'] ?? null,
            $entry['id'] ?? null,
            $entry['code'] ?? null,
            $entry['event_kind'] ?? null,
        ]));
    }
}
