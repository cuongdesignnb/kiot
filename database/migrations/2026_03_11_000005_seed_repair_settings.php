<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Setting;

return new class extends Migration {
    public function up(): void
    {
        Setting::set('repair_tracking_enabled', false, 'repair', 'boolean');
        Setting::set('repair_performance_salary_enabled', false, 'repair', 'boolean');
    }

    public function down(): void
    {
        Setting::where('key', 'like', 'repair_%')->delete();
    }
};
