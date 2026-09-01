<?php

namespace App\Services;

use App\Models\SerialImei;
use Illuminate\Support\Facades\DB;

/**
 * Read-only evidence classifier for historical serial sales.
 *
 * A normal sale is backed by invoice_item_serials. Older rows may only retain
 * the serial text written on the invoice item, while a damaged update can
 * leave neither source. The latter must never be silently rebuilt or guessed.
 */
class InvoiceItemSerialEvidenceService
{
    public const STANDARD_LINKS = 'standard_links';

    public const DIRECT_SERIAL_ASSIGNMENT = 'direct_serial_assignment';

    public const LEGACY_SERIAL_TEXT = 'legacy_serial_text';

    public const LEGACY_SERIAL_TEXT_INCOMPLETE = 'legacy_serial_text_incomplete';

    public const NO_SERIAL_EVIDENCE = 'no_serial_evidence';

    /**
     * @return array{
     *     classification:string,
     *     expected_quantity:int,
     *     standard_link_count:int,
     *     direct_serial_count:int,
     *     legacy_serial_numbers:array<int, string>,
     *     legacy_matched_count:int
     * }
     */
    public function inspect(
        int $productId,
        int $invoiceItemId,
        int $invoiceId,
        int $expectedQuantity,
        ?string $legacySerialText,
    ): array {
        $expectedQuantity = max(0, $expectedQuantity);
        $standardLinkCount = (int) DB::table('invoice_item_serials')
            ->join('serial_imeis', 'serial_imeis.id', '=', 'invoice_item_serials.serial_imei_id')
            ->where('invoice_item_serials.invoice_item_id', $invoiceItemId)
            ->where('serial_imeis.product_id', $productId)
            ->distinct()
            ->count('invoice_item_serials.serial_imei_id');

        $directSerialCount = (int) SerialImei::query()
            ->where('product_id', $productId)
            ->where('invoice_id', $invoiceId)
            ->count();

        $legacySerialNumbers = $this->parseLegacySerialNumbers($legacySerialText);
        $legacyMatchedCount = $legacySerialNumbers === []
            ? 0
            : (int) SerialImei::query()
                ->where('product_id', $productId)
                ->whereIn('serial_number', $legacySerialNumbers)
                ->count();

        $classification = match (true) {
            $standardLinkCount === $expectedQuantity => self::STANDARD_LINKS,
            $directSerialCount === $expectedQuantity => self::DIRECT_SERIAL_ASSIGNMENT,
            count($legacySerialNumbers) === $expectedQuantity
                && $legacyMatchedCount === $expectedQuantity => self::LEGACY_SERIAL_TEXT,
            $legacySerialNumbers !== [] => self::LEGACY_SERIAL_TEXT_INCOMPLETE,
            default => self::NO_SERIAL_EVIDENCE,
        };

        return [
            'classification' => $classification,
            'expected_quantity' => $expectedQuantity,
            'standard_link_count' => $standardLinkCount,
            'direct_serial_count' => $directSerialCount,
            'legacy_serial_numbers' => $legacySerialNumbers,
            'legacy_matched_count' => $legacyMatchedCount,
        ];
    }

    /** @return array<int, string> */
    private function parseLegacySerialNumbers(?string $legacySerialText): array
    {
        $parts = preg_split('/[\r\n,;|]+/', (string) $legacySerialText) ?: [];
        $numbers = [];

        foreach ($parts as $part) {
            $number = trim($part);
            if ($number === '') {
                continue;
            }

            $numbers[strtolower($number)] = $number;
        }

        return array_values($numbers);
    }
}
