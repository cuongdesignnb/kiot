<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Unit;
use App\Models\ProductAttribute;
use App\Models\Location;
use App\Models\OtherFee;
use App\Models\BankAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingController extends Controller
{
    public function index()
    {
        $settings = Setting::all()->groupBy('group');

        // Flatten settings for easier use in Vue
        $formattedSettings = [];
        foreach (Setting::all() as $setting) {
            $formattedSettings[$setting->key] = Setting::get($setting->key);
        }

        // Categories with product count (tree structure)
        $categories = Category::withCount('products')->with(['children' => function($q) {
            $q->withCount('products')->orderBy('name');
        }])->whereNull('parent_id')->orderBy('name')->get();

        $brands = Brand::withCount('products')->orderBy('name')->get();
        $units = Unit::orderBy('name')->get();
        $attributes = ProductAttribute::withCount('values')->orderBy('name')->get();
        $locations = Location::orderBy('name')->get();
        $otherFees = OtherFee::orderBy('name')->get();
        $bankAccounts = BankAccount::orderBy('bank_name')->get();

        return Inertia::render('Settings/Index', [
            'settings' => $formattedSettings,
            'groups' => $settings,
            'categories' => $categories,
            'brands' => $brands,
            'units' => $units,
            'attributes' => $attributes,
            'locations' => $locations,
            'otherFees' => $otherFees,
            'bankAccounts' => $bankAccounts,
            'branches' => \App\Models\Branch::all(),
            'metadata' => [
                'categories_count' => $categories->count(),
                'brands_count' => $brands->count(),
                'branches_count' => \App\Models\Branch::count(),
                'units_count' => $units->count(),
                'attributes_count' => $attributes->count(),
                'locations_count' => $locations->count(),
                'other_fees_count' => $otherFees->count(),
                'bank_accounts_count' => $bankAccounts->count(),
                'purchase_fees_count' => 1,
            ]
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'settings' => 'required|array',
        ]);

        foreach ($validated['settings'] as $key => $value) {
            Setting::set($key, $value);
        }

        return redirect()->back()->with('success', 'Cập nhật thiết lập thành công');
    }

    // ---- Category CRUD (API) ----
    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
        ]);

        $category = Category::create($validated);

        return redirect()->back()->with('success', "Nhóm hàng \"{$category->name}\" đã được tạo.");
    }

    public function quickStoreCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
        ]);

        $category = Category::create($validated);

        return response()->json(['success' => true, 'category' => $category]);
    }

    public function updateCategory(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $category->update($validated);

        return redirect()->back()->with('success', "Nhóm hàng \"{$category->name}\" đã được cập nhật.");
    }

    public function destroyCategory(Category $category)
    {
        if ($category->products()->count() > 0) {
            return redirect()->back()->with('error', "Không thể xóa nhóm hàng \"{$category->name}\" vì đang có {$category->products()->count()} sản phẩm.");
        }
        $name = $category->name;
        $category->delete();
        return redirect()->back()->with('success', "Đã xóa nhóm hàng \"{$name}\".");
    }

    // ---- Brand CRUD (API) ----
    public function storeBrand(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $brand = Brand::create($validated);

        return redirect()->back()->with('success', "Thương hiệu \"{$brand->name}\" đã được tạo.");
    }

    public function quickStoreBrand(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $brand = Brand::create($validated);

        return response()->json(['success' => true, 'brand' => $brand]);
    }

    public function updateBrand(Request $request, Brand $brand)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $brand->update($validated);

        return redirect()->back()->with('success', "Thương hiệu \"{$brand->name}\" đã được cập nhật.");
    }

    public function destroyBrand(Brand $brand)
    {
        if ($brand->products()->count() > 0) {
            return redirect()->back()->with('error', "Không thể xóa thương hiệu \"{$brand->name}\" vì đang có {$brand->products()->count()} sản phẩm.");
        }
        $name = $brand->name;
        $brand->delete();
        return redirect()->back()->with('success', "Đã xóa thương hiệu \"{$name}\".");
    }

    // ---- Unit CRUD ----
    public function storeUnit(Request $request)
    {
        $v = $request->validate(['name' => 'required|string|max:100|unique:units,name']);
        Unit::create($v);
        return redirect()->back()->with('success', "Đơn vị \"{$v['name']}\" đã được tạo.");
    }

    public function updateUnit(Request $request, Unit $unit)
    {
        $v = $request->validate(['name' => 'required|string|max:100']);
        $unit->update($v);
        return redirect()->back()->with('success', "Đơn vị \"{$unit->name}\" đã được cập nhật.");
    }

    public function destroyUnit(Unit $unit)
    {
        $name = $unit->name;
        $unit->delete();
        return redirect()->back()->with('success', "Đã xóa đơn vị \"{$name}\".");
    }

    // ---- Attribute CRUD ----
    public function storeAttribute(Request $request)
    {
        $v = $request->validate(['name' => 'required|string|max:100']);
        ProductAttribute::create($v);
        return redirect()->back()->with('success', "Thuộc tính \"{$v['name']}\" đã được tạo.");
    }

    public function updateAttribute(Request $request, ProductAttribute $attribute)
    {
        $v = $request->validate(['name' => 'required|string|max:100']);
        $attribute->update($v);
        return redirect()->back()->with('success', "Thuộc tính \"{$attribute->name}\" đã được cập nhật.");
    }

    public function destroyAttribute(ProductAttribute $attribute)
    {
        $name = $attribute->name;
        $attribute->delete();
        return redirect()->back()->with('success', "Đã xóa thuộc tính \"{$name}\".");
    }

    // ---- Location CRUD ----
    public function storeLocation(Request $request)
    {
        $v = $request->validate(['name' => 'required|string|max:100']);
        Location::create($v);
        return redirect()->back()->with('success', "Vị trí \"{$v['name']}\" đã được tạo.");
    }

    public function updateLocation(Request $request, Location $location)
    {
        $v = $request->validate(['name' => 'required|string|max:100']);
        $location->update($v);
        return redirect()->back()->with('success', "Vị trí \"{$location->name}\" đã được cập nhật.");
    }

    public function destroyLocation(Location $location)
    {
        $name = $location->name;
        $location->delete();
        return redirect()->back()->with('success', "Đã xóa vị trí \"{$name}\".");
    }

    // ---- OtherFee CRUD ----
    public function storeOtherFee(Request $request)
    {
        $v = $request->validate([
            'name' => 'required|string|max:255',
            'value' => 'nullable|numeric|min:0',
            'value_type' => 'required|in:fixed,percent',
            'auto_apply' => 'boolean',
            'refund_on_return' => 'boolean',
            'scope' => 'required|in:system,branch',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        OtherFee::create($v);
        return redirect()->back()->with('success', "Loại thu khác \"{$v['name']}\" đã được tạo.");
    }

    public function updateOtherFee(Request $request, OtherFee $otherFee)
    {
        $v = $request->validate([
            'name' => 'required|string|max:255',
            'value' => 'nullable|numeric|min:0',
            'value_type' => 'required|in:fixed,percent',
            'auto_apply' => 'boolean',
            'refund_on_return' => 'boolean',
            'scope' => 'required|in:system,branch',
            'branch_id' => 'nullable|exists:branches,id',
            'status' => 'nullable|in:active,inactive',
        ]);

        $otherFee->update($v);
        return redirect()->back()->with('success', "Loại thu khác \"{$otherFee->name}\" đã được cập nhật.");
    }

    public function destroyOtherFee(OtherFee $otherFee)
    {
        $name = $otherFee->name;
        $otherFee->delete();
        return redirect()->back()->with('success', "Đã xóa loại thu khác \"{$name}\".");
    }

    // ---- BankAccount CRUD ----
    public function storeBankAccount(Request $request)
    {
        $v = $request->validate([
            'account_number' => 'required|string|max:50',
            'bank_name' => 'required|string|max:255',
            'account_holder' => 'required|string|max:255',
            'type' => 'required|in:bank,ewallet',
            'scope' => 'required|in:system,branch',
            'branch_id' => 'nullable|exists:branches,id',
            'note' => 'nullable|string|max:500',
        ]);

        BankAccount::create($v);
        return redirect()->back()->with('success', "Tài khoản \"{$v['bank_name']} - {$v['account_number']}\" đã được thêm.");
    }

    public function updateBankAccount(Request $request, BankAccount $bankAccount)
    {
        $v = $request->validate([
            'account_number' => 'required|string|max:50',
            'bank_name' => 'required|string|max:255',
            'account_holder' => 'required|string|max:255',
            'type' => 'required|in:bank,ewallet',
            'scope' => 'required|in:system,branch',
            'branch_id' => 'nullable|exists:branches,id',
            'note' => 'nullable|string|max:500',
            'status' => 'nullable|in:active,inactive',
        ]);

        $bankAccount->update($v);
        return redirect()->back()->with('success', "Tài khoản đã được cập nhật.");
    }

    public function destroyBankAccount(BankAccount $bankAccount)
    {
        $info = "{$bankAccount->bank_name} - {$bankAccount->account_number}";
        $bankAccount->delete();
        return redirect()->back()->with('success', "Đã xóa tài khoản \"{$info}\".");
    }
}
