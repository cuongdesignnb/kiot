<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
            $table->string('status', 20)->default('pending')->comment('pending | accepted | rejected');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'employee_id']);
            $table->index('employee_id');
            $table->index('status');
        });

        // Migrate existing assigned_employee_id data from tasks
        DB::statement("
            INSERT INTO task_assignments (task_id, employee_id, assigned_by, status, assigned_at, created_at, updated_at)
            SELECT id, assigned_employee_id, created_by, 'accepted', assigned_at, NOW(), NOW()
            FROM tasks
            WHERE assigned_employee_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignments');
    }
};
