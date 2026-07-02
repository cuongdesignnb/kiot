<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable()->after('remember_token');
            $table->unsignedBigInteger('branch_id')->nullable()->after('role_id');
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('status', 10)->default('active')->after('branch_id');

            $table->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn(['role_id', 'branch_id', 'phone', 'status']);
        });
    }
};
