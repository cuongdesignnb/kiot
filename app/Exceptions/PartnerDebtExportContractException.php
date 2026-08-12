<?php

namespace App\Exceptions;

use RuntimeException;

class PartnerDebtExportContractException extends RuntimeException
{
    /** @var array<string, mixed> */
    private array $context;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $contract, array $context)
    {
        $this->context = $context;

        parent::__construct(sprintf(
            'Partner debt export contract failed: %s (%s)',
            $contract,
            implode(', ', array_map(
                static fn (string $key, mixed $value): string => $key.'='.self::display($value),
                array_keys($context),
                array_values($context),
            )),
        ));
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    private static function display(mixed $value): string
    {
        if (is_array($value)) {
            return implode('|', array_map(static fn (mixed $item): string => (string) $item, $value));
        }

        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
