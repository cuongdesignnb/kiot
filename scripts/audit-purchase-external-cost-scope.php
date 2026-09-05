<?php

// Run from the Laravel application root. No checkout, migration or writes are
// needed: this script deliberately uses only existing production DB columns.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = \Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'mysql') {
    fwrite(STDERR, "This production scope audit requires MySQL/MariaDB.\n");
    exit(2);
}

try {
    $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->statement('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    // Include cancelled/deleted rows: the proposed reader retains historical
    // payee evidence for exact purchase reversals. These require explicit QA.
    $flows = $db->table('cash_flows')
        ->where('reference_type', 'Purchase')->where('type', 'payment')
        ->whereIn('target_type', ['Chi phí', 'Chi phi'])->where('amount', '>', 0)
        ->orderBy('id')->get([
            'id', 'code', 'reference_code', 'target_type', 'amount', 'status', 'deleted_at',
        ]);
    $purchases = $db->table('purchases')->whereIn('code', $flows->pluck('reference_code')->filter()->unique())
        ->get(['id', 'code', 'supplier_id', 'status', 'total_amount', 'discount',
            'other_costs_total', 'paid_amount', 'debt_amount']);
    $groups = $purchases->groupBy('code');
    $rows = [];
    foreach ($flows->groupBy('reference_code') as $code => $vouchers) {
        $matches = $groups->get($code, collect());
        $external = round((float) $vouchers->sum('amount'), 2);
        $flags = [];
        if ($matches->count() !== 1) {
            $flags[] = 'PURCHASE_REFERENCE_NOT_UNIQUE_OR_MISSING';
        }
        if ($vouchers->count() > 1) {
            $flags[] = 'MULTIPLE_EXPENSE_VOUCHERS_REVIEW';
        }
        if ($vouchers->contains(fn ($flow) => $flow->deleted_at !== null
            || ! in_array($flow->status, [null, '', 'active'], true))) {
            $flags[] = 'INACTIVE_EXPENSE_EVIDENCE_REVIEW';
        }
        $purchase = $matches->count() === 1 ? $matches->first() : null;
        $proposed = null;
        if ($purchase) {
            if ($external > (float) $purchase->other_costs_total + 0.01) {
                $flags[] = 'EXTERNAL_AMOUNT_EXCEEDS_COSTS';
            }
            $proposed = round((float) $purchase->total_amount - (float) $purchase->discount
                + (float) $purchase->other_costs_total - $external - (float) $purchase->paid_amount, 2);
            if (abs($proposed - (float) $purchase->debt_amount) > 0.01) {
                $flags[] = 'PROPOSED_DOCUMENT_DEBT_DIFFERS_FROM_STORED';
            }
        }
        $rows[] = [
            'purchase_code' => $code, 'purchase' => $purchase,
            'external_cost_amount' => $external, 'proposed_document_debt' => $proposed,
            'flags' => $flags, 'expense_vouchers' => $vouchers->values()->all(),
        ];
    }
    $db->rollBack();
    $reviewCount = count(array_filter($rows, fn ($row) => $row['flags'] !== []));
    echo json_encode([
        'contract_version' => 'purchase-external-cost-scope-v1',
        'generated_at' => gmdate('c'),
        'expense_vouchers' => $flows->count(), 'purchase_reference_groups' => count($rows),
        'groups_requiring_review' => $reviewCount, 'rows' => $rows,
        'production_business_data_mutation' => 'NO',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
    exit($reviewCount > 0 ? 1 : 0);
} catch (\Throwable $error) {
    if ($db->transactionLevel() > 0) {
        $db->rollBack();
    }
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(2);
}
