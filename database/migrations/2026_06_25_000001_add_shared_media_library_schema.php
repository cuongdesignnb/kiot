<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('media_folders')) {
            Schema::create('media_folders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_id')->nullable()->constrained('media_folders')->nullOnDelete();
                $table->string('name');
                $table->string('slug');
                $table->string('path')->unique();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['parent_id', 'slug'], 'media_folders_parent_slug_unique');
            });
        }

        if (Schema::hasTable('media')) {
            Schema::table('media', function (Blueprint $table) {
                if (!Schema::hasColumn('media', 'folder_id')) {
                    $table->foreignId('folder_id')->nullable()->after('id')->constrained('media_folders')->nullOnDelete();
                }
                if (!Schema::hasColumn('media', 'extension')) {
                    $table->string('extension', 20)->nullable()->after('mime_type');
                }
                if (!Schema::hasColumn('media', 'width')) {
                    $table->unsignedInteger('width')->nullable()->after('size');
                }
                if (!Schema::hasColumn('media', 'height')) {
                    $table->unsignedInteger('height')->nullable()->after('width');
                }
                if (!Schema::hasColumn('media', 'original_path')) {
                    $table->string('original_path')->nullable()->after('url');
                }
                if (!Schema::hasColumn('media', 'original_url')) {
                    $table->string('original_url')->nullable()->after('original_path');
                }
                if (!Schema::hasColumn('media', 'webp_path')) {
                    $table->string('webp_path')->nullable()->after('original_url');
                }
                if (!Schema::hasColumn('media', 'webp_url')) {
                    $table->string('webp_url')->nullable()->after('webp_path');
                }
                if (!Schema::hasColumn('media', 'title')) {
                    $table->string('title')->nullable()->after('collection');
                }
                if (!Schema::hasColumn('media', 'alt_text')) {
                    $table->string('alt_text')->nullable()->after('title');
                }
                if (!Schema::hasColumn('media', 'caption')) {
                    $table->text('caption')->nullable()->after('alt_text');
                }
                if (!Schema::hasColumn('media', 'hash')) {
                    $table->string('hash', 64)->nullable()->index()->after('caption');
                }
                if (!Schema::hasColumn('media', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('media')) {
            Schema::table('media', function (Blueprint $table) {
                foreach (['folder_id', 'extension', 'width', 'height', 'original_path', 'original_url', 'webp_path', 'webp_url', 'title', 'alt_text', 'caption', 'hash', 'deleted_at'] as $column) {
                    if (Schema::hasColumn('media', $column)) {
                        if ($column === 'folder_id') {
                            $table->dropConstrainedForeignId('folder_id');
                        } else {
                            $table->dropColumn($column);
                        }
                    }
                }
            });
        }

        Schema::dropIfExists('media_folders');
    }
};
