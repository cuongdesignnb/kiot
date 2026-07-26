<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('storage_disk', 50)->default('public');
            $table->string('storage_path', 1024);
            $table->string('original_filename');
            $table->string('mime_type', 100)->default('image/webp');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('file_size');
            $table->char('checksum', 64);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            // MySQL has no portable partial unique index. This nullable guard is
            // populated only for the primary row, so one product cannot have two.
            $table->unsignedBigInteger('primary_product_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'sort_order'], 'product_images_product_sort_index');
            $table->index(['product_id', 'checksum'], 'product_images_product_checksum_index');
            $table->unique('primary_product_id', 'product_images_one_primary_unique');
            $table->foreign('primary_product_id')
                ->references('id')->on('products')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
