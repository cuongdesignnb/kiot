<?php

namespace App\Services\Media;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Media;
use App\Models\ProductImage;
use App\Models\ProductVariant;

class MediaUsageService
{
    public function usages(Media $media): array
    {
        $usages = [];

        ProductImage::query()->with('product:id,sku,name')->where('media_id', $media->id)->get()->each(function (ProductImage $image) use (&$usages): void {
            $usages[] = [
                'type' => 'product',
                'id' => $image->product_id,
                'label' => $image->product?->sku.' - '.$image->product?->name,
                'slot' => 'gallery',
            ];
        });

        Customer::query()->where('avatar_media_id', $media->id)->get(['id', 'code', 'name'])->each(function (Customer $customer) use (&$usages): void {
            $usages[] = [
                'type' => 'customer',
                'id' => $customer->id,
                'label' => $customer->code.' - '.$customer->name,
                'slot' => 'avatar',
            ];
        });

        Employee::query()->where('avatar_media_id', $media->id)->get(['id', 'code', 'name'])->each(function (Employee $employee) use (&$usages): void {
            $usages[] = [
                'type' => 'employee',
                'id' => $employee->id,
                'label' => $employee->code.' - '.$employee->name,
                'slot' => 'avatar',
            ];
        });

        ProductVariant::query()->with('product:id,sku,name')->where('image_media_id', $media->id)->get(['id', 'product_id', 'sku', 'name'])->each(function (ProductVariant $variant) use (&$usages): void {
            $usages[] = [
                'type' => 'product_variant',
                'id' => $variant->id,
                'label' => $variant->product?->sku.' - '.$variant->name,
                'slot' => 'image',
            ];
        });

        return $usages;
    }

    public function count(Media $media): int
    {
        return count($this->usages($media));
    }
}
