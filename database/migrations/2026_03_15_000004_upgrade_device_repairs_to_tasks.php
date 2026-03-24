<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1) Rename device_repair_parts → task_parts (idempotent)
        if (Schema::hasTable('device_repair_parts') && !Schema::hasTable('task_parts')) {
            Schema::rename('device_repair_parts', 'task_parts');
        }

        // 2) Rename device_repairs → tasks (idempotent)
        if (Schema::hasTable('device_repairs') && !Schema::hasTable('tasks')) {
            Schema::rename('device_repairs', 'tasks');
        }

        // 3) Rename FK column trong task_parts: device_repair_id → task_id
        if (Schema::hasColumn('task_parts', 'device_repair_id')) {
            // Find and drop old FK by querying information_schema
            try {
                $fk = DB::selectOne("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_parts' AND COLUMN_NAME = 'device_repair_id' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1");
                if ($fk) {
                    DB::statement("ALTER TABLE task_parts DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                }
            } catch (\Exception $e) {
                // Ignore constraint drop for SQLite
            }

            Schema::table('task_parts', function (Blueprint $table) {
                $table->renameColumn('device_repair_id', 'task_id');
            });
        }

        // Re-add FK nếu chưa có
        if (Schema::hasColumn('task_parts', 'task_id')) {
            try {
                $hasFk = DB::selectOne("SELECT COUNT(*) as cnt FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_parts' AND COLUMN_NAME = 'task_id' AND REFERENCED_TABLE_NAME = 'tasks'");
                if (!$hasFk || $hasFk->cnt == 0) {
                    Schema::table('task_parts', function (Blueprint $table) {
                        $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
                    });
                }
            } catch (\Exception $e) {
                // SQLite bypass
            }
        }

        // 4) Add new columns to tasks (idempotent)
        if (!Schema::hasColumn('tasks', 'type')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->string('type', 20)->default('general')->after('code')->comment('general | repair');
                $table->string('title')->nullable()->after('type');
                $table->unsignedBigInteger('category_id')->nullable()->after('title');
                $table->foreign('category_id')->references('id')->on('task_categories')->nullOnDelete();
                $table->string('priority', 10)->default('normal')->after('issue_description')->comment('low | normal | high | urgent');
                $table->unsignedTinyInteger('progress')->default(0)->after('priority')->comment('0-100%');
                $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            });
        }

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
