<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Customer;

class GeneralSeeder extends Seeder
{
    public function run(): void
    {
        // Branches
        Branch::updateOrCreate(['name' => 'Laptopplus.vn Chi nhánh chính'], ['address' => 'Hà Nội', 'phone' => '0985133992']);
        Branch::updateOrCreate(['name' => 'Chi nhánh Hồ Chí Minh'], ['address' => 'TP. HCM', 'phone' => '0900000000']);

        // More Categories if needed
        Category::updateOrCreate(['name' => 'Linh kiện']);
        Category::updateOrCreate(['name' => 'Phụ kiện']);

        // Brands
        Brand::updateOrCreate(['name' => 'Lenovo']);
        Brand::updateOrCreate(['name' => 'Asus']);

        // Customers/Suppliers
        \App\Models\Customer::updateOrCreate(
            ['name' => 'Khách lẻ'],
            ['is_supplier' => false, 'is_customer' => true, 'type' => 'customer']
        );
        \App\Models\Customer::updateOrCreate(
            ['phone' => '0988888888'],
            [
                'name' => 'Công ty TNHH ABC',
                'type' => 'supplier',
                'is_supplier' => true,
                'is_customer' => false,
                'address' => '456 Lê Lợi, TP. HCM'
            ]
        );

        // Seed Users
        \App\Models\User::updateOrCreate(
            ['email' => 'admin@laptopplus.vn'],
            [
                'name' => 'Trần Văn Tiến',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
            ]
        );
    }
}
