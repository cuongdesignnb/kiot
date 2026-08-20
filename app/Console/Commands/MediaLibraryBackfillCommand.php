<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Media\MediaAssetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MediaLibraryBackfillCommand extends Command
{
    protected $signature = 'media:library-backfill
        {--dry-run : Only inspect legacy images; never write database or files}
        {--backfill : Register internal legacy files and attach media links}
        {--chunk=100 : Number of rows per batch}
        {--json : Emit a machine-readable summary}';

    protected $description = 'Audit and optionally register legacy images in the shared media library';

    public function handle(MediaAssetService $assets): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $backfill = (bool) $this->option('backfill');
        if ($dryRun && $backfill) {
            $this->error('Không thể dùng đồng thời --dry-run và --backfill.');

            return self::INVALID;
        }
        if (! $dryRun && ! $backfill) {
            $this->error('Chỉ được chạy ghi dữ liệu khi truyền --backfill; hãy dùng --dry-run để kiểm tra trước.');

            return self::INVALID;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $summary = [
            'dry_run' => $dryRun,
            'backfill' => $backfill,
            'product_images_pending' => 0,
            'product_images_registered' => 0,
            'product_images_missing' => 0,
            'customer_avatars_pending' => 0,
            'customer_avatars_registered' => 0,
            'employee_avatars_pending' => 0,
            'employee_avatars_registered' => 0,
            'variant_images_pending' => 0,
            'variant_images_registered' => 0,
            'external_or_unresolved' => [],
        ];

        ProductImage::query()->whereNull('media_id')->orderBy('id')->chunkById($chunk, function ($rows) use (&$summary, $assets, $dryRun, $backfill): void {
            foreach ($rows as $image) {
                $summary['product_images_pending']++;
                $media = $this->register($assets, $image->storage_disk, $image->storage_path, $image->original_filename, 'products', $dryRun);
                if (! $media) {
                    $summary['product_images_missing']++;

                    continue;
                }
                if ($backfill) {
                    $image->forceFill(['media_id' => $media->id])->save();
                    $summary['product_images_registered']++;
                }
            }
        });

        Customer::query()->whereNotNull('avatar')->whereNull('avatar_media_id')->orderBy('id')->chunkById($chunk, function ($rows) use (&$summary, $assets, $dryRun, $backfill): void {
            foreach ($rows as $customer) {
                $summary['customer_avatars_pending']++;
                [$disk, $path] = $this->resolveLegacyPath((string) $customer->avatar);
                if (! $disk || ! $path) {
                    $summary['external_or_unresolved'][] = ['type' => 'customer', 'id' => $customer->id, 'value' => $customer->avatar];

                    continue;
                }
                $media = $this->register($assets, $disk, $path, 'customer-'.$customer->id, 'customers', $dryRun);
                if ($media && $backfill) {
                    $customer->forceFill(['avatar_media_id' => $media->id])->save();
                    $summary['customer_avatars_registered']++;
                }
            }
        });

        Employee::query()->whereNotNull('avatar')->whereNull('avatar_media_id')->orderBy('id')->chunkById($chunk, function ($rows) use (&$summary, $assets, $dryRun, $backfill): void {
            foreach ($rows as $employee) {
                $summary['employee_avatars_pending']++;
                [$disk, $path] = $this->resolveLegacyPath((string) $employee->avatar);
                if (! $disk || ! $path) {
                    $summary['external_or_unresolved'][] = ['type' => 'employee', 'id' => $employee->id, 'value' => $employee->avatar];

                    continue;
                }
                $media = $this->register($assets, $disk, $path, 'employee-'.$employee->id, 'employees', $dryRun);
                if ($media && $backfill) {
                    $employee->forceFill(['avatar_media_id' => $media->id])->save();
                    $summary['employee_avatars_registered']++;
                }
            }
        });

        ProductVariant::query()->whereNotNull('image')->whereNull('image_media_id')->orderBy('id')->chunkById($chunk, function ($rows) use (&$summary, $assets, $dryRun, $backfill): void {
            foreach ($rows as $variant) {
                $summary['variant_images_pending']++;
                [$disk, $path] = $this->resolveLegacyPath((string) $variant->image);
                if (! $disk || ! $path) {
                    $summary['external_or_unresolved'][] = ['type' => 'product_variant', 'id' => $variant->id, 'value' => $variant->image];

                    continue;
                }
                $media = $this->register($assets, $disk, $path, 'variant-'.$variant->id, 'products', $dryRun);
                if ($media && $backfill) {
                    $variant->forceFill(['image_media_id' => $media->id])->save();
                    $summary['variant_images_registered']++;
                }
            }
        });

        $this->line($this->option('json') ? json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : $this->formatSummary($summary));

        return self::SUCCESS;
    }

    private function register(MediaAssetService $assets, string $disk, string $path, string $name, string $collection, bool $dryRun): mixed
    {
        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        return $dryRun ? (object) ['id' => null] : $assets->registerStoredFile($disk, $path, $name, $collection);
    }

    /** @return array{0:?string,1:?string} */
    private function resolveLegacyPath(string $value): array
    {
        if ($value === '' || preg_match('/^https?:\/\//i', $value)) {
            return [null, null];
        }

        $path = parse_url($value, PHP_URL_PATH) ?: $value;
        $path = ltrim((string) $path, '/');
        $path = preg_replace('#^storage/#', '', $path) ?: $path;

        return ['public', $path];
    }

    private function formatSummary(array $summary): string
    {
        return collect($summary)->map(fn ($value, $key) => is_array($value)
            ? $key.'='.count($value)
            : $key.'='.$value)->implode(PHP_EOL);
    }
}
