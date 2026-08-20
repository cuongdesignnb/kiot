<?php

namespace Tests\Feature\Media;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SharedMediaLibraryContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_api_requires_authentication(): void
    {
        $this->getJson('/api/media')->assertUnauthorized();
    }

    public function test_viewer_can_browse_but_cannot_upload(): void
    {
        $role = Role::create([
            'name' => 'media-viewer-'.uniqid(),
            'display_name' => 'Media viewer',
            'permissions' => ['products.view'],
            'is_system' => false,
        ]);
        $viewer = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($viewer, 'sanctum')->getJson('/api/media')->assertOk();
        $this->actingAs($viewer, 'sanctum')->postJson('/api/media', [
            'file' => UploadedFile::fake()->image('forbidden.png'),
        ])->assertForbidden();
    }

    public function test_upload_is_webp_only_deduplicated_and_has_all_variants(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role_id' => null]);

        $first = UploadedFile::fake()->image('shared.png', 800, 600);
        $second = UploadedFile::fake()->createWithContent(
            'copy.png',
            file_get_contents($first->getRealPath()),
        );

        $firstResponse = $this->actingAs($admin, 'sanctum')->postJson('/api/media', [
            'files' => [$first],
            'collection' => 'products',
        ])->assertCreated();
        $secondResponse = $this->actingAs($admin, 'sanctum')->postJson('/api/media', [
            'files' => [$second],
            'collection' => 'products',
        ])->assertCreated();

        $firstId = (int) $firstResponse->json('media.0.id');
        $secondId = (int) $secondResponse->json('media.0.id');
        $media = Media::query()->findOrFail($firstId);

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, Media::query()->count());
        $this->assertSame('image/webp', $media->mime_type);
        $this->assertCount(4, $media->variants);
        $this->assertEqualsCanonicalizing(['thumb', 'small', 'medium', 'original'], $media->variants->pluck('variant')->all());
        $this->assertNotEmpty($media->variants->pluck('path')->filter(fn (string $path) => str_ends_with($path, '.webp')));
        $this->assertTrue(Storage::disk('public')->exists($media->path));
    }

    public function test_shared_media_can_be_used_by_multiple_objects_and_cannot_be_deleted_while_in_use(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role_id' => null]);
        $media = app(\App\Services\Media\MediaAssetService::class)
            ->uploadMany([UploadedFile::fake()->image('avatar.png', 320, 240)], 'shared', $admin)[0];

        $product = Product::create([
            'sku' => 'MEDIA-'.uniqid(),
            'name' => 'Media product',
        ]);
        ProductImage::create([
            'product_id' => $product->id,
            'media_id' => $media->id,
            'storage_disk' => 'public',
            'storage_path' => 'legacy/not-used.png',
            'original_filename' => 'legacy.png',
            'mime_type' => 'image/png',
            'width' => 320,
            'height' => 240,
            'file_size' => 100,
            'checksum' => str_repeat('a', 64),
        ]);
        $customer = Customer::create([
            'code' => 'MEDIA-KH-'.uniqid(),
            'name' => 'Shared customer',
            'avatar_media_id' => $media->id,
        ]);
        $employee = Employee::create([
            'code' => 'MEDIA-NV-'.uniqid(),
            'name' => 'Shared employee',
            'avatar_media_id' => $media->id,
        ]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/media?usage=product')
            ->assertOk()
            ->assertJsonPath('data.0.id', $media->id);

        $response = $this->actingAs($admin, 'sanctum')->deleteJson('/api/media/'.$media->id);
        $response->assertStatus(409)->assertJsonPath('code', 'MEDIA_IN_USE');
        $this->assertCount(3, $response->json('usages'));

        ProductImage::query()->where('media_id', $media->id)->update(['media_id' => null]);
        ProductImage::query()->where('product_id', $product->id)->delete();
        $customer->update(['avatar_media_id' => null]);
        $employee->update(['avatar_media_id' => null]);

        $paths = $media->variants->pluck('path')->push($media->path)->all();
        $this->actingAs($admin, 'sanctum')->deleteJson('/api/media/'.$media->id)->assertOk();
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertDatabaseMissing('media_variants', ['media_id' => $media->id]);
        foreach ($paths as $path) {
            $this->assertFalse(Storage::disk('public')->exists($path));
        }
    }

    public function test_backfill_is_dry_run_safe_and_idempotent(): void
    {
        Storage::fake('public');
        $legacy = UploadedFile::fake()->image('legacy.png', 320, 240);
        Storage::disk('public')->put('legacy/products/legacy.png', file_get_contents($legacy->getRealPath()));

        $product = Product::create([
            'sku' => 'LEGACY-'.uniqid(),
            'name' => 'Legacy media product',
        ]);
        $image = ProductImage::create([
            'product_id' => $product->id,
            'storage_disk' => 'public',
            'storage_path' => 'legacy/products/legacy.png',
            'original_filename' => 'legacy.png',
            'mime_type' => 'image/png',
            'width' => 320,
            'height' => 240,
            'file_size' => 100,
            'checksum' => str_repeat('b', 64),
        ]);

        $this->artisan('media:library-backfill', ['--dry-run' => true, '--json' => true])
            ->assertExitCode(0);
        $this->assertNull($image->fresh()->media_id);
        $this->assertSame(0, Media::query()->count());
        $this->assertTrue(Storage::disk('public')->exists('legacy/products/legacy.png'));

        $this->artisan('media:library-backfill', ['--backfill' => true, '--json' => true])
            ->assertExitCode(0);
        $linkedImage = $image->fresh();
        $this->assertNotNull($linkedImage->media_id);
        $this->assertSame(1, Media::query()->count());

        $this->artisan('media:library-backfill', ['--backfill' => true, '--json' => true])
            ->assertExitCode(0);
        $this->assertSame(1, Media::query()->count());
        $this->assertSame($linkedImage->media_id, $image->fresh()->media_id);
        $this->assertTrue(Storage::disk('public')->exists('legacy/products/legacy.png'));
    }
}
