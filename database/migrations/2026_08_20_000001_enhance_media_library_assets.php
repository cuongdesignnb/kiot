<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media')) {
            Schema::table('media', function (Blueprint $table): void {
                if (! Schema::hasColumn('media', 'checksum')) {
                    $table->char('checksum', 64)->nullable()->after('url');
                }
                if (! Schema::hasColumn('media', 'width')) {
                    $table->unsignedInteger('width')->nullable()->after('checksum');
                }
                if (! Schema::hasColumn('media', 'height')) {
                    $table->unsignedInteger('height')->nullable()->after('width');
                }
                if (! Schema::hasColumn('media', 'status')) {
                    $table->string('status', 20)->default('active')->after('height');
                }
                if (! Schema::hasColumn('media', 'deleted_at')) {
                    $table->softDeletes();
                }
            });

            Schema::table('media', function (Blueprint $table): void {
                $table->index('checksum', 'media_checksum_index');
                $table->index(['collection', 'created_at'], 'media_collection_created_index');
            });
        }

        if (! Schema::hasTable('media_variants')) {
            Schema::create('media_variants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('media_id')->constrained('media')->restrictOnDelete();
                $table->string('variant', 30);
                $table->string('disk', 50)->default('public');
                $table->string('path', 1024);
                $table->string('mime_type', 100)->default('image/webp');
                $table->unsignedInteger('width');
                $table->unsignedInteger('height');
                $table->unsignedBigInteger('size');
                $table->char('checksum', 64);
                $table->timestamps();

                $table->unique(['media_id', 'variant'], 'media_variants_media_variant_unique');
                $table->index('checksum', 'media_variants_checksum_index');
            });
        }

        if (Schema::hasTable('product_images') && ! Schema::hasColumn('product_images', 'media_id')) {
            Schema::table('product_images', function (Blueprint $table): void {
                $table->foreignId('media_id')->nullable()->after('product_id')->constrained('media')->restrictOnDelete();
                $table->index('media_id', 'product_images_media_index');
            });
        }

        foreach ([
            'customers' => 'avatar_media_id',
            'employees' => 'avatar_media_id',
            'product_variants' => 'image_media_id',
        ] as $tableName => $column) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, $column)) {
                Schema::table($tableName, function (Blueprint $table) use ($column): void {
                    $table->foreignId($column)->nullable()->constrained('media')->restrictOnDelete();
                    $table->index($column, $column.'_index');
                });
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'product_variants' => 'image_media_id',
            'employees' => 'avatar_media_id',
            'customers' => 'avatar_media_id',
        ] as $tableName => $column) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, $column)) {
                Schema::table($tableName, function (Blueprint $table) use ($column): void {
                    $table->dropForeign([$column]);
                    $table->dropIndex($column.'_index');
                    $table->dropColumn($column);
                });
            }
        }

        if (Schema::hasTable('product_images') && Schema::hasColumn('product_images', 'media_id')) {
            Schema::table('product_images', function (Blueprint $table): void {
                $table->dropForeign(['media_id']);
                $table->dropIndex('product_images_media_index');
                $table->dropColumn('media_id');
            });
        }

        Schema::dropIfExists('media_variants');

        if (Schema::hasTable('media')) {
            Schema::table('media', function (Blueprint $table): void {
                $table->dropIndex('media_collection_created_index');
                $table->dropIndex('media_checksum_index');
                $table->dropSoftDeletes();
                $table->dropColumn(['status', 'height', 'width', 'checksum']);
            });
        }
    }
};
