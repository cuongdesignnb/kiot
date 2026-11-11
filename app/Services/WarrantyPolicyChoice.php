<?php

namespace App\Services;

use App\Models\Product;

/**
 * Matches a chosen warranty package to one of the product's warranty_policies.
 * Unknown names resolve to null, so the product's default package applies.
 */
class WarrantyPolicyChoice
{
    public function __construct(private readonly ProductWarrantyPolicyNormalizer $normalizer) {}

    /** @return array{name:string, duration_value:int, duration_unit:string, is_default:bool}|null */
    public function find(?Product $product, ?string $name): ?array
    {
        $name = mb_strtolower(trim((string) $name));
        if ($product === null || $name === '') {
            return null;
        }
        foreach ($this->policies($product) as $policy) {
            if (mb_strtolower($policy['name']) === $name) {
                return $policy;
            }
        }

        return null;
    }

    /** The stored name for a chosen package, or null when it is not one of the product's. */
    public function nameFor(?Product $product, ?string $name): ?string
    {
        return $this->find($product, $name)['name'] ?? null;
    }

    /** The package lasting exactly this many months (website orders send months). */
    public function nameForMonths(?Product $product, ?int $months): ?string
    {
        if ($product === null || $months === null || $months <= 0) {
            return null;
        }
        foreach ($this->policies($product) as $policy) {
            if ($this->normalizer->durationInMonths($policy['duration_value'], $policy['duration_unit']) === $months) {
                return $policy['name'];
            }
        }

        return null;
    }

    /** @return list<array{name:string, duration_value:int, duration_unit:string, is_default:bool}> */
    private function policies(Product $product): array
    {
        return $this->normalizer->normalizeWarrantyPolicies($product->getAttribute('warranty_policies'));
    }
}
