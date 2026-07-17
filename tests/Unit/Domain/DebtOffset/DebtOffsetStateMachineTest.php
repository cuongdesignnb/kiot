<?php

namespace Tests\Unit\Domain\DebtOffset;

use App\Domain\DebtOffset\DebtOffsetStateMachine;
use App\Exceptions\DebtOffsetWorkflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DebtOffsetStateMachineTest extends TestCase
{
    #[DataProvider('validTransitionProvider')]
    public function test_valid_transitions_are_accepted(string $from, string $to): void
    {
        $machine = new DebtOffsetStateMachine;

        $this->assertTrue($machine->can($from, $to));
        $machine->assertCan($from, $to);
        $this->addToAssertionCount(1);
    }

    public static function validTransitionProvider(): array
    {
        return [
            ['draft', 'pending_approval'],
            ['draft', 'void'],
            ['pending_approval', 'approved'],
            ['pending_approval', 'rejected'],
            ['approved', 'applied'],
            ['applied', 'reversed'],
        ];
    }

    #[DataProvider('invalidTransitionProvider')]
    public function test_invalid_and_terminal_transitions_are_rejected(string $from, string $to): void
    {
        $machine = new DebtOffsetStateMachine;
        $this->assertFalse($machine->can($from, $to));

        try {
            $machine->assertCan($from, $to);
            $this->fail('Expected invalid transition exception.');
        } catch (DebtOffsetWorkflowException $exception) {
            $this->assertSame(409, $exception->httpStatus);
            $this->assertSame('INVALID_DEBT_OFFSET_TRANSITION', $exception->errorCode);
        }
    }

    public static function invalidTransitionProvider(): array
    {
        return [
            ['draft', 'applied'],
            ['draft', 'approved'],
            ['rejected', 'approved'],
            ['rejected', 'applied'],
            ['void', 'pending_approval'],
            ['applied', 'approved'],
            ['reversed', 'applied'],
            ['reversed', 'reversed'],
        ];
    }
}
