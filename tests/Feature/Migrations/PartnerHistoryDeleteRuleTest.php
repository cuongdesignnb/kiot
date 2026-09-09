<?php

namespace Tests\Feature\Migrations;

use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartnerHistoryDeleteRuleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Foreign-key delete-rule contract requires MySQL or MariaDB.');
        }
    }

    public function test_legacy_partner_ledgers_cannot_be_cascade_deleted(): void
    {
        foreach ([
            ['customer_debts', 'customer_id'],
            ['supplier_debt_transactions', 'supplier_id'],
            ['debt_offsets', 'customer_id'],
        ] as [$table, $column]) {
            $rule = DB::table('information_schema.KEY_COLUMN_USAGE as k')
                ->join('information_schema.REFERENTIAL_CONSTRAINTS as r', function ($join) {
                    $join->on('r.CONSTRAINT_SCHEMA', '=', 'k.CONSTRAINT_SCHEMA')
                        ->on('r.TABLE_NAME', '=', 'k.TABLE_NAME')
                        ->on('r.CONSTRAINT_NAME', '=', 'k.CONSTRAINT_NAME');
                })
                ->where('k.CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
                ->where('k.TABLE_NAME', $table)
                ->where('k.COLUMN_NAME', $column)
                ->where('k.REFERENCED_TABLE_NAME', 'customers')
                ->value('r.DELETE_RULE');

            $this->assertSame('RESTRICT', $rule, "Unexpected delete rule for {$table}.{$column}.");
        }
    }

    public function test_database_rejects_raw_partner_deletion_when_legacy_history_exists(): void
    {
        $fixtures = [
            ['customer_debts', 'customer_id', [
                'ref_code' => 'TEST-DEBT-'.uniqid(),
                'amount' => 125000,
                'debt_total' => 125000,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['supplier_debt_transactions', 'supplier_id', [
                'code' => 'TEST-SUPPLIER-DEBT-'.uniqid(),
                'type' => 'purchase',
                'amount' => 125000,
                'debt_remain' => 125000,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['debt_offsets', 'customer_id', [
                'code' => 'TEST-OFFSET-'.uniqid(),
                'amount' => 125000,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]],
        ];

        foreach ($fixtures as [$table, $column, $attributes]) {
            $partner = $this->partner();
            DB::table($table)->insert(array_merge($attributes, [$column => $partner->id]));

            try {
                DB::table('customers')->where('id', $partner->id)->delete();
                $this->fail("Raw deletion unexpectedly succeeded for {$table}.{$column}.");
            } catch (QueryException) {
                $this->assertDatabaseHas('customers', ['id' => $partner->id]);
                $this->assertDatabaseHas($table, [$column => $partner->id]);
            }
        }
    }

    private function partner(): Customer
    {
        return Customer::query()->create([
            'code' => 'TEST-FK-PARTNER-'.uniqid(),
            'name' => 'Synthetic foreign-key fixture',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'total_spent' => 0,
            'total_returns' => 0,
            'total_bought' => 0,
            'is_customer' => true,
            'is_supplier' => true,
            'status' => 'active',
        ]);
    }
}
