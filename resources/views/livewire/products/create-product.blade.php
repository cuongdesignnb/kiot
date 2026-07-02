<div class="bg-gray-50 min-h-screen">
    {{-- Top Header Form --}}
    <div
        class="bg-white px-6 py-3 border-b border-gray-200 flex items-center justify-between sticky top-0 z-40 shadow-sm">
        <h2 class="text-xl font-bold tracking-tight text-gray-800 flex items-center gap-2">
            Thêm mới
            @if($type === 'standard') Hàng hóa
            @elseif($type === 'service') Dịch vụ
            @elseif($type === 'combo') Combo - Đóng gói
            @else Hàng sản xuất
            @endif
        </h2>

        <div class="flex items-center gap-3">
            <button wire:click="save"
                class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded font-medium flex items-center gap-2 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                Lưu
            </button>
            <a href="/" wire:navigate
                class="bg-white hover:bg-gray-50 text-gray-700 border border-gray-300 px-5 py-2.5 rounded font-medium transition-colors shadow-sm">
                Bỏ qua
            </a>
        </div>
    </div>

    {{-- Main Form Container --}}
    <div class="max-w-6xl mx-auto p-4 md:p-6 pb-24">
        @if (session()->has('success'))
            <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded text-sm font-medium">
                {{ session('success') }}
            </div>
        @endif

        <div class="flex gap-6">
            {{-- Cột Trái: Upload Hình Ảnh --}}
            <div class="w-1/4">
                <div class="bg-white rounded border border-gray-200 shadow-sm p-4">
                    <div
                        class="border-2 border-dashed border-gray-300 rounded-lg h-48 flex items-center justify-center flex-col text-gray-400 bg-gray-50 hover:bg-blue-50 hover:border-blue-400 hover:text-blue-500 cursor-pointer transition-colors group">
                        <svg class="w-10 h-10 mb-2 group-hover:scale-110 transition-transform" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4">
                            </path>
                        </svg>
                        <span class="text-sm font-semibold">Thêm ảnh</span>
                    </div>
                    <div class="pt-4 space-y-3">
                        <label class="flex items-center gap-2 text-sm text-gray-700 font-medium">
                            <input type="checkbox" wire:model="sell_directly"
                                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4 cursor-pointer">
                            Bán trực tiếp
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700 font-medium">
                            <input type="checkbox" wire:model="allow_point_accumulation"
                                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4 cursor-pointer">
                            Tích điểm
                        </label>
                        @if ($type === 'standard')
                            <label
                                class="flex items-center gap-2 text-sm text-gray-700 font-medium pt-2 border-t border-gray-100">
                                <input type="checkbox" wire:model="has_serial"
                                    class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4 cursor-pointer">
                                Quản lý Serial/IMEI
                            </label>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Cột Phải: Thông tin chi tiết --}}
            <div class="w-3/4">
                <div class="bg-white rounded border border-gray-200 shadow-sm overflow-hidden">
                    <div class="p-6">
                        <div class="grid grid-cols-2 gap-x-8 gap-y-6">
                            {{-- Tên hàng --}}
                            <div class="col-span-2">
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Tên hàng <span
                                        class="text-red-500">*</span></label>
                                <input type="text" wire:model="name" placeholder="Ví dụ: Giày thể thao Nike Air Max..."
                                    class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-base text-gray-800">
                                @error('name') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>

                            {{-- Mã hàng & Mã vạch --}}
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Mã hàng hóa</label>
                                <div class="relative">
                                    <input type="text" wire:model="sku" placeholder="Mã tự động"
                                        class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800">
                                    <button wire:click="generateSku"
                                        class="absolute right-2 top-1/2 -translate-y-1/2 text-blue-600 hover:text-blue-800 text-xs font-semibold px-2 py-1 mr-[-5px]">Tạo</button>
                                </div>
                                @error('sku') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Mã vạch</label>
                                <input type="text" wire:model="barcode"
                                    class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800">
                            </div>

                            {{-- Nhóm hàng & Thương hiệu --}}
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Nhóm hàng</label>
                                <select wire:model="category_id"
                                    class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800 bg-white">
                                    <option value="">--- Chọn nhóm hàng ---</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Thương hiệu</label>
                                <select wire:model="brand_id"
                                    class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800 bg-white">
                                    <option value="">--- Chọn thương hiệu ---</option>
                                    @foreach($brands as $brand)
                                        <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Vị trí & Trọng lượng --}}
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Vị trí lưu kho</label>
                                <input type="text" wire:model="location"
                                    class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Trọng lượng</label>
                                <input type="text" wire:model="weight" placeholder="Ví dụ: 100g, 2kg"
                                    class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800">
                            </div>

                            <div class="col-span-2 border-t border-gray-100 mt-2 mb-2"></div>

                            {{-- Giá vốn & Giá bán --}}
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Giá vốn</label>
                                <div class="relative">
                                    <input type="number" wire:model="cost_price"
                                        class="w-full border border-gray-300 rounded p-2 pr-10 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-right text-base font-semibold text-gray-800">
                                    <span
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm font-medium">₫</span>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Giá bán <span
                                        class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="number" wire:model="retail_price"
                                        class="w-full border border-gray-300 rounded p-2 pr-10 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-right text-base text-blue-700 font-bold">
                                    <span
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm font-medium">₫</span>
                                </div>
                            </div>

                            {{-- Tồn kho --}}
                            @if ($type === 'standard')
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1">Tồn kho</label>
                                    <input type="number" wire:model="stock_quantity"
                                        class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow flex-1">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1">Định mức tồn ít
                                        nhất</label>
                                    <input type="number" wire:model="min_stock"
                                        class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow flex-1">
                                </div>
                            @elseif ($type === 'combo')
                                <div class="col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 mb-1">Thành phần Combo</label>
                                    <div
                                        class="p-4 border border-blue-200 bg-blue-50 rounded text-sm text-blue-600 text-center font-medium">
                                        Tính năng thêm sản phẩm thành phần sẽ được hiện ra và xử lý sau khi tạo thành công
                                        Form cha này.
                                    </div>
                                </div>
                            @elseif ($type === 'manufactured')
                                <div class="col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 mb-1">Nguyên vật liệu cấu thành
                                        (BOM)</label>
                                    <div
                                        class="p-4 border border-orange-200 bg-orange-50 rounded text-sm text-orange-600 text-center font-medium">
                                        Màn hình thêm nguyên liệu sẽ kích hoạt sau khi khai báo Hàng sản xuất thành công.
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>