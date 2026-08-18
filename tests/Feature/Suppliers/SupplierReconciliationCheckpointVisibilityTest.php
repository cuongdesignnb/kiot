<?php

namespace Tests\Feature\Suppliers;

use Tests\TestCase;

class SupplierReconciliationCheckpointVisibilityTest extends TestCase
{
    public function test_synthetic_reconciliation_checkpoint_is_not_rendered_as_a_debt_row(): void
    {
        $vue = file_get_contents(resource_path('js/Pages/Suppliers/Index.vue'));

        $this->assertStringContainsString(
            '.filter((entry) => !isReconciliationCheckpoint(entry))',
            $vue,
        );
    }
}
