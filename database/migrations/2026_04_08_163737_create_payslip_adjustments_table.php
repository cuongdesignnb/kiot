<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payslip_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained('payslips')->cascadeOnDelete();
            $table->string('type'); // bonus, allowance, deduction, ot
            $table->string('name');
            $table->integer('amount')->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['payslip_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payslip_adjustments');
    }
};
