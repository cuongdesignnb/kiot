<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Product;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $cat1 = Category::create(['name' => 'Điện thoại']);
        $cat2 = Category::create(['name' => 'Laptop']);

        $brand1 = Brand::create(['name' => 'Apple']);
        $brand2 = Brand::create(['name' => 'HP']);
        $brand3 = Brand::create(['name' => 'Dell']);

        Product::create([
            'sku' => 'SP002258',
            'barcode' => '8935002258',
            'name' => 'Hp 830 G5',
            'type' => 'standard',
            'category_id' => $cat2->id,
            'brand_id' => $brand2->id,
            'cost_price' => 3100000,
            'retail_price' => 5000000,
            'stock_quantity' => 12,
            'min_stock' => 2,
            'max_stock' => 100,
            'has_serial' => true,
            'is_active' => true,
        ]);

        Product::create([
            'sku' => 'SP002259',
            'barcode' => '8935002259',
            'name' => 'iPhone 15 Pro Max 256GB',
            'type' => 'standard',
            'category_id' => $cat1->id,
            'brand_id' => $brand1->id,
            'cost_price' => 25000000,
            'retail_price' => 29000000,
            'stock_quantity' => 5,
            'min_stock' => 1,
            'max_stock' => 50,
            'has_serial' => true,
            'is_active' => true,
        ]);

        Product::create([
            'sku' => 'SP002260',
            'barcode' => '8935002260',
            'name' => 'Dell XPS 13 9315',
            'type' => 'standard',
            'category_id' => $cat2->id,
            'brand_id' => $brand3->id,
            'cost_price' => 18000000,
            'retail_price' => 23500000,
            'stock_quantity' => 0,
            'min_stock' => 3,
            'has_serial' => false,
            'is_active' => true,
        ]);
    }
}
