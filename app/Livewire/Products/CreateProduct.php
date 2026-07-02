<?php

namespace App\Livewire\Products;

use App\Models\Category;
use App\Models\Brand;
use App\Models\Product;
use Livewire\Component;
use Livewire\Attributes\Layout;

class CreateProduct extends Component
{
    // Cấu hình form type
    public $type = 'standard';
    public $categories = [];
    public $brands = [];

    // Fields Form
    public $name = '';
    public $sku = '';
    public $barcode = '';
    public $category_id;
    public $brand_id;
    public $cost_price = 0;
    public $retail_price = 0;
    public $stock_quantity = 0;
    public $min_stock = 0;
    public $has_serial = false;
    public $sell_directly = true;
    public $weight = '';
    public $location = '';

    // Tab hiển thị
    public $activeTab = 'info';

    public function mount($type = 'standard')
    {
        $this->type = in_array($type, ['standard', 'service', 'combo', 'manufactured']) ? $type : 'standard';
        $this->categories = Category::all();
        $this->brands = Brand::all();
    }

    public function generateSku()
    {
        // Simple SKU generator: SP + Time + random
        $this->sku = 'SP' . date('ymd') . rand(100, 999);
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|unique:products,sku',
            'retail_price' => 'numeric|min:0',
            'cost_price' => 'numeric|min:0',
        ]);

        if (empty($this->sku)) {
            $this->generateSku();
        }

        $product = Product::create([
            'type' => $this->type,
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'category_id' => $this->category_id ?: null,
            'brand_id' => $this->brand_id ?: null,
            'cost_price' => $this->cost_price,
            'retail_price' => $this->retail_price,
            'stock_quantity' => $this->stock_quantity,
            'min_stock' => $this->min_stock,
            'has_serial' => $this->has_serial,
            'sell_directly' => $this->sell_directly,
            'weight' => $this->weight,
            'location' => $this->location,
        ]);

        session()->flash('success', 'Hàng hóa được tạo thành công!');
        $this->redirect('/', navigate: true);
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.products.create-product');
    }
}
