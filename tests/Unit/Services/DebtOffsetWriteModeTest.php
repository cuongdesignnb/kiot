<?php

namespace Tests\Unit\Services;

use App\Exceptions\DebtOffsetWorkflowException;
use App\Services\Debt\DebtOffsetWriteMode;
use Tests\TestCase;

class DebtOffsetWriteModeTest extends TestCase
{
    public function test_default_mode_is_legacy(): void
    {
        config()->set('debt.offsets.write_mode', 'legacy');

        $mode = new DebtOffsetWriteMode;
        $this->assertSame('legacy', $mode->current());
        $mode->assertLegacyAllowed();
        $this->addToAssertionCount(1);
    }

    public function test_workflow_mode_disables_legacy_write(): void
    {
        config()->set('debt.offsets.write_mode', 'workflow');

        $this->expectException(DebtOffsetWorkflowException::class);
        $this->expectExceptionMessage('Ghi cấn trừ trực tiếp đã bị tắt.');

        (new DebtOffsetWriteMode)->assertLegacyAllowed();
    }

    public function test_unknown_mode_fails_closed(): void
    {
        config()->set('debt.offsets.write_mode', 'unexpected');

        try {
            (new DebtOffsetWriteMode)->current();
            $this->fail('Unknown mode must not fall back to legacy.');
        } catch (DebtOffsetWorkflowException $exception) {
            $this->assertSame(403, $exception->httpStatus);
            $this->assertSame('DEBT_OFFSET_WORKFLOW_DISABLED', $exception->errorCode);
        }
    }
}
