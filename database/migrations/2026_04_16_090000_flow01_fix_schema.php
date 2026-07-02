<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Flow 01 Schema Fixes:
 * 1. branches: thêm cột code (unique)
 * 2. customers: xóa linked_supplier_id (FK lỗi tới bảng suppliers không tồn tại)
 * 3. customers: thêm unique index trên phone
 * 4. customer_debts: fix FK order_returns → returns
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // ── FIX 1: Branch thêm cột code ──
        if (!Schema::hasColumn('branches', 'code')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->string('code')->nullable();
            });
            // Auto-generate codes
            foreach (DB::table('branches')->get() as $b) {
                DB::table('branches')->where('id', $b->id)
                    ->update(['code' => 'KHO' . str_pad($b->id, 3, '0', STR_PAD_LEFT)]);
            }
            // Add unique index after data populated
            Schema::table('branches', function (Blueprint $table) {
                $table->unique('code');
            });
        }

        // ── FIX 2: Xóa linked_supplier_id ──
        if (Schema::hasColumn('customers', 'linked_supplier_id')) {
            if ($driver === 'sqlite') {
                // SQLite: phải rebuild bảng vì không hỗ trợ DROP FK
                $pdo = DB::connection()->getPdo();
                $pdo->exec('PRAGMA foreign_keys = OFF');

                $cols = array_filter(Schema::getColumnListing('customers'), fn($c) => $c !== 'linked_supplier_id');
                $colList = implode(', ', array_map(fn($c) => "\"$c\"", $cols));

                $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='customers'")->fetchColumn();
                $sql = preg_replace('/, "linked_supplier_id" integer/', '', $sql);
                $sql = preg_replace('/,\s*foreign key\s*\(\s*"linked_supplier_id"\s*\).*?on delete set null/i', '', $sql);

                $pdo->exec(str_replace('"customers"', '"customers_new"', $sql));
                $pdo->exec("INSERT INTO customers_new ({$colList}) SELECT {$colList} FROM customers");
                $pdo->exec("DROP TABLE customers");
                $pdo->exec("ALTER TABLE customers_new RENAME TO customers");
                $pdo->exec('CREATE UNIQUE INDEX "customers_code_unique" ON "customers" ("code")');
                $pdo->exec('PRAGMA foreign_keys = ON');
            } else {
                // MySQL: drop FK (nếu có) rồi drop column
                try {
                    Schema::table('customers', function (Blueprint $table) {
                        $table->dropForeign(['linked_supplier_id']);
                    });
                } catch (\Exception $e) {
                    // FK không tồn tại — bỏ qua
                }
                Schema::table('customers', function (Blueprint $table) {
                    $table->dropColumn('linked_supplier_id');
                });
            }
        }

        // ── FIX 3: Phone unique index ──
        try {
            if ($driver === 'sqlite') {
                $exists = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND name='customers_phone_unique'");
                if (empty($exists)) {
                    DB::statement("CREATE UNIQUE INDEX \"customers_phone_unique\" ON \"customers\" (\"phone\") WHERE \"phone\" IS NOT NULL AND \"phone\" != ''");
                }
            } else {
                // MySQL: cần xóa duplicates trước, rồi thêm unique
                $dupes = DB::select("SELECT phone, COUNT(*) as cnt FROM customers WHERE phone IS NOT NULL AND phone != '' GROUP BY phone HAVING COUNT(*) > 1");
                if (empty($dupes)) {
                    Schema::table('customers', function (Blueprint $table) {
                        $table->unique('phone');
                    });
                }
            }
        } catch (\Exception $e) {
            // Index có thể đã tồn tại
        }

        // ── FIX 4: customer_debts FK order_returns → returns ──
        if ($driver === 'sqlite') {
            $pdo = DB::connection()->getPdo();
            $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='customer_debts'")->fetchColumn();
            if ($sql && strpos($sql, 'order_returns') !== false) {
                $pdo->exec('PRAGMA foreign_keys = OFF');
                $cols = Schema::getColumnListing('customer_debts');
                $colList = implode(', ', array_map(fn($c) => "\"$c\"", $cols));
                $pdo->exec(str_replace(['"customer_debts"', '"order_returns"'], ['"customer_debts_new"', '"returns"'], $sql));
                $pdo->exec("INSERT INTO customer_debts_new ({$colList}) SELECT {$colList} FROM customer_debts");
                $pdo->exec("DROP TABLE customer_debts");
                $pdo->exec("ALTER TABLE customer_debts_new RENAME TO customer_debts");
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
        // MySQL: FK customer_debts không reference order_returns nên không cần fix
    }

    public function down(): void
    {
        // Reverse: thêm lại linked_supplier_id (không FK)
        if (!Schema::hasColumn('customers', 'linked_supplier_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->unsignedBigInteger('linked_supplier_id')->nullable();
            });
        }
        // Phone unique
        try {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropUnique(['phone']);
            });
        } catch (\Exception $e) {}
        // Branch code
        if (Schema::hasColumn('branches', 'code')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('code');
            });
        }
    }
};
