<?php

namespace Tests\Unit\Domain\DebtOffset;

use App\Domain\DebtOffset\DecimalMoney;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalMoneyTest extends TestCase
{
    #[DataProvider('validMoneyProvider')]
    public function test_it_parses_and_formats_decimal_money_without_float(string $input, int $cents, string $decimal): void
    {
        $money = DecimalMoney::from($input);

        $this->assertSame($cents, $money->cents());
        $this->assertSame($decimal, $money->toDecimal());
    }

    public static function validMoneyProvider(): array
    {
        return [
            ['1000000.00', 100000000, '1000000.00'],
            ['0.01', 1, '0.01'],
            ['-42.5', -4250, '-42.50'],
            ['15', 1500, '15.00'],
        ];
    }

    #[DataProvider('invalidMoneyProvider')]
    public function test_it_rejects_non_canonical_or_over_precise_money(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('INVALID_DECIMAL_MONEY');

        DecimalMoney::from($input);
    }

    public static function invalidMoneyProvider(): array
    {
        return [
            ['1.001'],
            ['1,000.00'],
            ['abc'],
            [''],
        ];
    }

    public function test_it_supports_exact_arithmetic_and_minimum(): void
    {
        $left = DecimalMoney::from('10000000.00');
        $right = DecimalMoney::from('6000000.00');
        $offset = DecimalMoney::from('4000000.00');

        $this->assertSame('6000000.00', $left->subtract($offset)->toDecimal());
        $this->assertSame('2000000.00', $right->subtract($offset)->toDecimal());
        $this->assertSame('6000000.00', DecimalMoney::min($left, $right)->toDecimal());
        $this->assertSame('-4000000.00', $offset->negate()->toDecimal());
    }
}
