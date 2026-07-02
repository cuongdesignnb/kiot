<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite: recreate table with nullable purchase_id
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=off');
            DB::statement('CREATE TABLE purchase_returns_tmp AS SELECT * FROM purchase_returns');
            Schema::drop('purchase_returns');
            Schema::create('purchase_returns', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->unsignedBigInteger('purchase_id')->nullable();
                $table->unsignedBigInteger('supplier_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->decimal('total_amount', 15, 0)->default(0);
                $table->decimal('refund_amount', 15, 0)->default(0);
                $table->string('status')->default('completed');
                $table->text('note')->nullable();
                $table->string('payment_method')->default('cash');
                $table->string('bank_account_info')->nullable();
                $table->timestamp('return_date')->nullable();
                $table->timestamps();
            });
            DB::statement('INSERT INTO purchase_returns SELECT * FROM purchase_returns_tmp');
            DB::statement('DROP TABLE purchase_returns_tmp');
            DB::statement('PRAGMA foreign_keys=on');
        } else {
            Schema::table('purchase_returns', function (Blueprint $table) {
                $table->unsignedBigInteger('purchase_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_id')->nullable(false)->change();
        });
    }
};
