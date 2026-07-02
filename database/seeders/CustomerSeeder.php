<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\Customer::create([
            'code' => 'KH002781',
            'name' => 'Lê Như Quốc Bảo',
            'phone' => '0989296722',
            'total_spent' => 2800000,
            'debt_amount' => 0,
            'gender' => 'male',
        ]);

        \App\Models\Customer::create([
            'code' => 'KH002780',
            'name' => 'Tin Học Trung Kiên',
            'phone' => '0843214214',
            'type' => 'company',
            'total_spent' => 12400000,
            'debt_amount' => 0,
        ]);

        \App\Models\Customer::create([
            'code' => 'KH002779',
            'name' => 'nguyễn Thanh Liêm',
            'phone' => '0981525334',
            'gender' => 'male',
            'total_spent' => 12000000,
            'debt_amount' => 0,
        ]);

        // Add 20 more dummy customers for pagination test
        for ($i = 1; $i <= 20; $i++) {
            \App\Models\Customer::create([
                'code' => 'KH0' . (1000 + $i),
                'name' => 'Khách hàng Demo ' . $i,
                'phone' => '09' . rand(10000000, 99999999),
                'total_spent' => rand(1, 50) * 100000,
                'debt_amount' => rand(0, 1) ? rand(1, 10) * 100000 : 0,
            ]);
        }
    }
}
