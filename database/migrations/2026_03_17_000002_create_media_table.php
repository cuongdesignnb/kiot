<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('media')) {
            Schema::create('media', function (Blueprint $table) {
                $table->id();
                $table->string('filename');
                $table->string('original_name');
                $table->string('mime_type');
                $table->unsignedBigInteger('size'); // bytes
                $table->string('disk')->default('public');
                $table->string('path'); // relative path in disk
                $table->string('url'); // full public URL
                $table->string('collection')->default('default'); // group: products, employees, etc
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
