<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1) Rename device_repair_parts → task_parts (trước khi rename bảng cha)
        Schema::rename('device_repair_parts', 'task_parts');

        // 2) Rename device_repairs → tasks
        Schema::rename('device_repairs', 'tasks');

        // 3) Rename FK column trong task_parts: device_repair_id → task_id
        // FK name có thể khác nhau tuỳ lúc tạo (trước/sau rename bảng)
        Schema::table('task_parts', function (Blueprint $table) {
            // Try dropping FK with possible names
            $possibleFKs = [
                'device_repair_parts_device_repair_id_foreign',
                'task_parts_device_repair_id_foreign',
            ];
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $existingFKs = collect($sm->listTableForeignKeys('task_parts'))->map(fn($fk) => $fk->getName())->toArray();

            foreach ($possibleFKs as $fkName) {
                if (in_array($fkName, $existingFKs)) {
                    $table->dropForeign($fkName);
                    break;
                }
            }
            // Rename column
            $table->renameColumn('device_repair_id', 'task_id');
        });
        Schema::table('task_parts', function (Blueprint $table) {
            // Re-add FK
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
        });

        // 4) Add new columns to tasks
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('type', 20)->default('general')->after('code')->comment('general | repair');
            $table->string('title')->nullable()->after('type');
            $table->unsignedBigInteger('category_id')->nullable()->after('title');
            $table->foreign('category_id')->references('id')->on('task_categories')->nullOnDelete();
            $table->string('priority', 10)->default('normal')->after('issue_description')->comment('low | normal | high | urgent');
            $table->unsignedTinyInteger('progress')->default(0)->after('priority')->comment('0-100%');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
        });

        // 5) Update existing rows: đánh dấu là repair, title = code
        DB::table('tasks')->update([
            'type'  => 'repair',
        ]);
        DB::statement("UPDATE tasks SET title = code WHERE title IS NULL");

        // 6) Expand status enum — change column to varchar to avoid enum issues
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });

        // 7) Add index on type
        Schema::table('tasks', function (Blueprint $table) {
            $table->index('type');
            $table->index('priority');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropIndex(['priority']);
            $table->dropIndex(['category_id']);
            $table->dropForeign(['category_id']);
            $table->dropColumn(['type', 'title', 'category_id', 'priority', 'progress', 'cancelled_at']);
        });

        // Revert status to enum
        Schema::table('tasks', function (Blueprint $table) {
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending')->change();
        });

        // Rename back
        Schema::rename('tasks', 'device_repairs');

        Schema::table('task_parts', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
            $table->renameColumn('task_id', 'device_repair_id');
        });
        Schema::table('task_parts', function (Blueprint $table) {
            $table->foreign('device_repair_id')->references('id')->on('device_repairs')->cascadeOnDelete();
        });

        Schema::rename('task_parts', 'device_repair_parts');
    }
};
