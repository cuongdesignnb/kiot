<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    product: Object,
    categories: Array,
    brands: Array,
    showRetailPrice: Boolean,
    showTechnicianPrice: Boolean,
    technicianPrice: { type: Number, default: 0 },
});

const form = useForm({
    type: props.product.type,
    name: props.product.name,
    sku: props.product.sku,
    barcode: props.product.barcode || '',
    category_id: props.product.category_id || '',
    brand_id: props.product.brand_id || '',
    cost_price: props.product.cost_price || 0,
    retail_price: props.product.retail_price || 0,
    technician_price: props.technicianPrice || 0,
    stock_quantity: props.product.stock_quantity || 0,
    min_stock: props.product.min_stock || 0,
    has_serial: !!props.product.has_serial,
    sell_directly: !!props.product.sell_directly,
    allow_point_accumulation: !!props.product.allow_point_accumulation,
    weight: props.product.weight || '',
    location: props.product.location || '',
});

const submit = () => {
    form.put(`/products/${props.product.id}`);
};
</script>

<template>
    <Head :title="`Cập nhật Hàng hóa: ${props.product.name}`" />
    
    <AppLayout>
        <div class="bg-gray-50 min-h-[calc(100vh-3.5rem)] pb-24">
            <!-- Top Header Form -->
            <div class="bg-white px-6 py-3 border-b border-gray-200 flex items-center justify-between sticky top-0 z-40 shadow-sm">
                <h2 class="text-xl font-bold tracking-tight text-gray-800 flex items-center gap-2">
                    Cập nhật Hàng hóa
                </h2>
                
                <div class="flex items-center gap-3">
                    <button @click="submit" :disabled="form.processing" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded font-medium flex items-center gap-2 transition-colors shadow-sm disabled:opacity-50">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        Lưu Thay Đổi
                    </button>
                    <Link href="/" class="bg-white hover:bg-gray-50 text-gray-700 border border-gray-300 px-5 py-2.5 rounded font-medium transition-colors shadow-sm inline-block">
                        Bỏ qua
                    </Link>
                </div>
            </div>

            <!-- Main Form Container -->
            <div class="max-w-6xl mx-auto p-4 md:p-6 pb-6">
                <div class="flex gap-6">
                    <!-- Cột Trái: Upload Hình Ảnh -->
                    <div class="w-1/4">
                        <div class="bg-white rounded border border-gray-200 shadow-sm p-4">
                            <div class="border-2 border-dashed border-gray-300 rounded-lg h-48 flex items-center justify-center flex-col text-gray-400 bg-gray-50 hover:bg-blue-50 hover:border-blue-400 hover:text-blue-500 cursor-pointer transition-colors group">
                                <svg class="w-10 h-10 mb-2 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                                <span class="text-sm font-semibold">Cập nhật ảnh</span>
                            </div>
                            <div class="pt-4 space-y-3">
                                <label class="flex items-center gap-2 text-sm text-gray-700 font-medium cursor-pointer">
                                    <input type="checkbox" v-model="form.sell_directly" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                                    Bán trực tiếp
                                </label>
                                <label class="flex items-center gap-2 text-sm text-gray-700 font-medium cursor-pointer">
                                    <input type="checkbox" v-model="form.allow_point_accumulation" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                                    Tích điểm
                                </label>
                                <label v-if="props.product.type === 'standard'" class="flex items-center gap-2 text-sm text-gray-700 font-medium pt-2 border-t border-gray-100 cursor-pointer">
                                    <input type="checkbox" v-model="form.has_serial" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                                    Quản lý Serial/IMEI
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Cột Phải: Thông tin chi tiết -->
                    <div class="w-3/4">
                        <div class="bg-white rounded border border-gray-200 shadow-sm overflow-hidden pointer-events-auto">
                            <div class="p-6">
                                <div class="grid grid-cols-2 gap-x-8 gap-y-6">
                                    <!-- Tên hàng -->
                                    <div class="col-span-2">
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Tên hàng <span class="text-red-500">*</span></label>
                                        <input type="text" v-model="form.name" class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-base text-gray-800">
                                        <span v-if="form.errors.name" class="text-red-500 text-xs mt-1 block">{{ form.errors.name }}</span>
                                    </div>

                                    <!-- Mã hàng & Mã vạch -->
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Mã hàng hóa</label>
                                        <input type="text" v-model="form.sku" disabled class="w-full border border-gray-200 bg-gray-50 rounded p-2 outline-none text-sm text-gray-600">
                                        <span v-if="form.errors.sku" class="text-red-500 text-xs mt-1 block">{{ form.errors.sku }}</span>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Mã vạch</label>
                                        <input type="text" v-model="form.barcode" class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800">
                                        <span v-if="form.errors.barcode" class="text-red-500 text-xs mt-1 block">{{ form.errors.barcode }}</span>
                                    </div>

                                    <!-- Nhóm hàng & Thương hiệu -->
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Nhóm hàng</label>
                                        <select v-model="form.category_id" class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800 bg-white">
                                            <option value="">--- Chọn nhóm hàng ---</option>
                                            <option v-for="category in props.categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Thương hiệu</label>
                                        <select v-model="form.brand_id" class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800 bg-white">
                                            <option value="">--- Chọn thương hiệu ---</option>
                                            <option v-for="brand in props.brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
                                        </select>
                                    </div>

                                    <!-- Vị trí & Trọng lượng -->
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Vị trí lưu kho</label>
                                        <input type="text" v-model="form.location" class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Trọng lượng</label>
                                        <input type="text" v-model="form.weight" class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800">
                                    </div>

                                    <div class="col-span-2 border-t border-gray-100 mt-2 mb-2"></div>

                                    <!-- Giá vốn & Giá bán -->
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Giá vốn</label>
                                        <div class="relative">
                                            <input type="number" v-model="form.cost_price" class="w-full border border-gray-300 rounded p-2 pr-10 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-right text-base font-semibold text-gray-800">
                                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm font-medium">₫</span>
                                        </div>
                                        <span v-if="form.errors.cost_price" class="text-red-500 text-xs mt-1 block">{{ form.errors.cost_price }}</span>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Giá bán <span class="text-red-500">*</span></label>
                                        <div class="relative">
                                            <input type="number" v-model="form.retail_price" class="w-full border border-gray-300 rounded p-2 pr-10 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-right text-base text-blue-700 font-bold">
                                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm font-medium">₫</span>
                                        </div>
                                        <span v-if="form.errors.retail_price" class="text-red-500 text-xs mt-1 block">{{ form.errors.retail_price }}</span>
                                    </div>

                                    <!-- Giá bán lẻ (conditional) -->
                                    <div v-if="showRetailPrice">
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Giá bán lẻ</label>
                                        <div class="relative">
                                            <input type="number" v-model="form.retail_price" class="w-full border border-gray-300 rounded p-2 pr-10 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-right text-base font-semibold">
                                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm font-medium">₫</span>
                                        </div>
                                    </div>

                                    <!-- Giá thợ (conditional) -->
                                    <div v-if="showTechnicianPrice">
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Giá bán thợ</label>
                                        <div class="relative">
                                            <input type="number" v-model="form.technician_price" class="w-full border border-gray-300 rounded p-2 pr-10 focus:ring-1 focus:ring-purple-500 focus:border-purple-500 outline-none transition-shadow text-right text-base font-semibold text-purple-700">
                                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm font-medium">₫</span>
                                        </div>
                                        <span v-if="form.errors.technician_price" class="text-red-500 text-xs mt-1 block">{{ form.errors.technician_price }}</span>
                                    </div>

                                    <!-- Tồn kho -->
                                    <template v-if="props.product.type === 'standard'">
                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-1">Tồn kho</label>
                                            <input type="number" v-model="form.stock_quantity" class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-1">Định mức tồn ít nhất</label>
                                            <input type="number" v-model="form.min_stock" class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow">
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
