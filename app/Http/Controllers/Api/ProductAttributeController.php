<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Http\Request;

class ProductAttributeController extends Controller
{
    public function index()
    {
        $attributes = ProductAttribute::with('values')->orderBy('sort_order')->orderBy('name')->get();
        return response()->json($attributes);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $attribute = ProductAttribute::create($data);
        $attribute->load('values');

        return response()->json($attribute, 201);
    }

    public function storeValue(Request $request, ProductAttribute $attribute)
    {
        $data = $request->validate([
            'value' => 'required|string|max:255',
        ]);

        $value = $attribute->values()->create($data);

        return response()->json($value, 201);
    }

    public function destroy(ProductAttribute $attribute)
    {
        $attribute->delete();
        return response()->json(['message' => 'Đã xóa thuộc tính.']);
    }

    public function destroyValue(ProductAttributeValue $value)
    {
        $value->delete();
        return response()->json(['message' => 'Đã xóa giá trị.']);
    }
}
