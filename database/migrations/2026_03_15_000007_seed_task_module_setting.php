<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Add task_module_enabled setting (keep repair_tracking_enabled as alias)
        $exists = DB::table('settings')->where('key', 'task_module_enabled')->exists();
        if (!$exists) {
            // Copy value from repair_tracking_enabled if it exists
            $repairValue = DB::table('settings')->where('key', 'repair_tracking_enabled')->value('value');
            DB::table('settings')->insert([
                'key'   => 'task_module_enabled',
                'value' => $repairValue ?? '1',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'task_module_enabled')->delete();
    }
};
