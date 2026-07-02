<x-layouts.app>

    @section('sidebar')
        <div class="p-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
            <h3 class="font-bold text-gray-700">Bộ lọc tìm kiếm</h3>
        </div>

        <div class="p-4 space-y-6">
            {{-- Nhóm hàng --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Nhóm hàng</label>
                <select
                    class="w-full border border-gray-300 rounded p-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow bg-white">
                    <option>Tất cả nhóm</option>
                    <option>Điện thoại</option>
                    <option>Laptop</option>
                </select>
            </div>

            {{-- Trạng thái --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Trạng thái</label>
                <div class="space-y-2">
                    <label class="flex items-center gap-2 text-gray-600 hover:text-gray-900 cursor-pointer">
                        <input type="checkbox" checked
                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4 cursor-pointer">
                        Đang kinh doanh
                    </label>
                    <label class="flex items-center gap-2 text-gray-600 hover:text-gray-900 cursor-pointer">
                        <input type="checkbox"
                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4 cursor-pointer">
                        Ngừng kinh doanh
                    </label>
                </div>
            </div>

            {{-- Tồn kho --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Tồn kho</label>
                <select
                    class="w-full border border-gray-300 rounded p-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow bg-white">
                    <option>Tất cả</option>
                    <option>Còn hàng trong kho</option>
                    <option>Hết hàng</option>
                </select>
            </div>
        </div>
    @endsection

    {{-- Main Content Dashboard --}}
    <div class="bg-white rounded border border-gray-200 shadow-sm overflow-hidden">
        {{-- Toolbar --}}
        <div class="p-3 border-b border-gray-200 flex items-center justify-between bg-gray-50/30">
            <div class="relative w-96">
                <input type="text" placeholder="Tìm kiếm theo mã, tên hàng, barcode..."
                    class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 focus:border-blue-500 text-sm outline-none bg-white">
                <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
            </div>

            <div class="flex gap-2">
                <div class="relative" x-data="{ open: false }" @click.away="open = false">
                    <button @click="open = !open"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-medium flex items-center gap-2 transition-colors shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4">
                            </path>
                        </svg>
                        Thêm mới
                        <svg class="w-3 h-3 ml-1 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7">
                            </path>
                        </svg>
                    </button>

                    {{-- Dropdown Menu --}}
                    <div x-show="open" x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="transform opacity-0 scale-95"
                        x-transition:enter-end="transform opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="transform opacity-100 scale-100"
                        x-transition:leave-end="transform opacity-0 scale-95"
                        class="absolute right-0 mt-2 w-48 bg-white rounded shadow-lg ring-1 ring-black ring-opacity-5 z-50 divide-y divide-gray-100"
                        style="display: none;">
                        <div class="py-1">
                            <a href="{{ route('products.create', ['type' => 'standard']) }}" wire:navigate
                                class="group flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-700">
                                Hàng hóa
                            </a>
                            <a href="{{ route('products.create', ['type' => 'service']) }}" wire:navigate
                                class="group flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-700">
                                Dịch vụ
                            </a>
                        </div>
                        <div class="py-1">
                            <a href="{{ route('products.create', ['type' => 'combo']) }}" wire:navigate
                                class="group flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-700">
                                Combo - đóng gói
                            </a>
                            <a href="{{ route('products.create', ['type' => 'manufactured']) }}" wire:navigate
                                class="group flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-700">
                                Hàng sản xuất
                            </a>
                        </div>
                    </div>
                </div>
                <button
                    class="bg-white hover:bg-gray-50 text-gray-700 border border-gray-300 px-4 py-2 rounded text-sm font-medium flex items-center gap-2 transition-colors shadow-sm">
                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                    </svg>
                    Xuất file
                </button>
            </div>
        </div>

        {{-- Data Table --}}
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr
                        class="border-b border-gray-200 bg-gray-50/80 text-gray-500 font-semibold text-xs tracking-wider uppercase">
                        <th class="p-3 w-10 text-center"><input type="checkbox" class="rounded border-gray-300"></th>
                        <th class="p-3 w-16">Ảnh</th>
                        <th class="p-3">Mã hàng</th>
                        <th class="p-3">Tên hàng</th>
                        <th class="p-3 text-right">Giá bán</th>
                        <th class="p-3 text-right">Giá vốn</th>
                        <th class="p-3 text-right">Tồn kho</th>
                    </tr>
                </thead>
                <tbody class="text-sm divide-y divide-gray-100">
                    <tr class="hover:bg-blue-50/50 cursor-pointer group transition-colors">
                        <td class="p-3 text-center"><input type="checkbox" class="rounded border-gray-300"></td>
                        <td class="p-3">
                            <div class="w-10 h-10 bg-gray-200 rounded flex items-center justify-center text-gray-400">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z">
                                    </path>
                                </svg>
                            </div>
                        </td>
                        <td class="p-3 text-blue-600 font-medium group-hover:underline">SP002258</td>
                        <td class="p-3 font-medium text-gray-800">Hp 830 G5</td>
                        <td class="p-3 text-right">500,000</td>
                        <td class="p-3 text-right text-gray-500">3,100,000</td>
                        <td class="p-3 text-right"><span class="font-bold text-gray-800">12</span></td>
                    </tr>

                    <tr class="hover:bg-blue-50/50 cursor-pointer group transition-colors bg-gray-50/30">
                        <td colspan="7" class="p-0">
                            {{-- Cửa sổ Expandable Row (Mô phỏng) --}}
                            <div class="p-4 bg-[#f8fbff] shadow-inner border-y border-blue-100">
                                <div class="flex border-b border-gray-200 mb-4">
                                    <button
                                        class="px-4 py-2 border-b-2 border-blue-600 text-blue-600 font-semibold mb-[-1px]">Thông
                                        tin</button>
                                    <button class="px-4 py-2 text-gray-500 hover:text-gray-700 font-medium">Thẻ
                                        kho</button>
                                    <button
                                        class="px-4 py-2 text-gray-500 hover:text-gray-700 font-medium">Serial/IMEI</button>
                                    <button class="px-4 py-2 text-gray-500 hover:text-gray-700 font-medium">Bảo
                                        hành</button>
                                </div>
                                <div class="grid grid-cols-3 gap-6 text-sm">
                                    <div>
                                        <div class="text-gray-500 mb-1">Mã hàng</div>
                                        <div class="font-medium">SP002258</div>
                                        <div class="text-gray-500 mt-3 mb-1">Giá bán</div>
                                        <div class="font-medium text-lg text-blue-700">500,000</div>
                                    </div>
                                    <div>
                                        <div class="text-gray-500 mb-1">Nhóm hàng</div>
                                        <div class="font-medium">LAPTOP CŨ >> LAPTOP HP</div>
                                        <div class="text-gray-500 mt-3 mb-1">Thương hiệu</div>
                                        <div class="font-medium">HP</div>
                                    </div>
                                    <div>
                                        <div class="text-gray-500 mb-1">Định mức tồn</div>
                                        <div class="font-medium">0 - 999,999,999</div>
                                        <div class="text-gray-500 mt-3 mb-1">Vị trí</div>
                                        <div class="font-medium">Chưa có</div>
                                    </div>
                                </div>
                                <div class="mt-4 pt-4 border-t border-gray-200 flex justify-end gap-2">
                                    <button
                                        class="px-4 py-1.5 border border-red-200 text-red-600 hover:bg-red-50 rounded bg-white">Xóa</button>
                                    <button
                                        class="px-4 py-1.5 border border-gray-300 text-gray-700 hover:bg-gray-50 rounded bg-white">In
                                        tem mã</button>
                                    <button
                                        class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded shadow-sm">Cập
                                        nhật</button>
                                </div>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="p-3 border-t border-gray-200 flex items-center justify-between bg-gray-50/50 text-xs text-gray-500">
            <div>Hiển thị từ 1 đến 1 trong tổng số 1 bản ghi</div>
            <div class="flex gap-1">
                <button
                    class="px-2 py-1 border border-gray-300 rounded bg-white hover:bg-gray-50 text-gray-400 cursor-not-allowed">&laquo;</button>
                <button class="px-3 py-1 border border-blue-600 rounded bg-blue-600 text-white font-medium">1</button>
                <button class="px-2 py-1 border border-gray-300 rounded bg-white hover:bg-gray-50">&raquo;</button>
            </div>
        </div>
    </div>
</x-layouts.app>