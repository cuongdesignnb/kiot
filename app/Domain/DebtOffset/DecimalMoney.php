<?php

namespace App\Domain\DebtOffset;

use InvalidArgumentException;

final class DecimalMoney
{
    private function __construct(private readonly int $cents) {}

    public static function from(int|string $value): self
    {
        $raw = trim((string) $value);
        if (! preg_match('/^(-?)(\d{1,13})(?:\.(\d{1,2}))?$/', $raw, $matches)) {
            throw new InvalidArgumentException('INVALID_DECIMAL_MONEY');
        }

        $fraction = str_pad($matches[3] ?? '', 2, '0');
        $cents = ((int) $matches[2] * 100) + (int) $fraction;

        return new self(($matches[1] ?? '') === '-' ? -$cents : $cents);
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->cents < $other->cents;
    }

    public function greaterThan(self $other): bool
    {
        return $this->cents > $other->cents;
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function subtract(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function negate(): self
    {
        return new self(-$this->cents);
    }

    public static function min(self $left, self $right): self
    {
        return $left->cents <= $right->cents ? $left : $right;
    }

    public function toDecimal(): string
    {
        $absolute = abs($this->cents);
        $whole = intdiv($absolute, 100);
        $fraction = $absolute % 100;

        return ($this->cents < 0 ? '-' : '').$whole.'.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }
}
