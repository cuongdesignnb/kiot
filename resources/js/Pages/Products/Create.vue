<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    type: {
        type: String,
        default: 'standard'
    },
    categories: Array,
    brands: Array,
    showRetailPrice: Boolean,
    showTechnicianPrice: Boolean,
});

const form = useForm({
    type: props.type,
    name: '',
    sku: '',
    barcode: '',
    category_id: '',
    brand_id: '',
    cost_price: 0,
    retail_price: 0,
    technician_price: 0,
    stock_quantity: 0,
    min_stock: 0,
    has_serial: false,
    sell_directly: true,
    allow_point_accumulation: false,
    weight: '',
    location: '',
    base_unit_name: '',
    units: [],
});

// Reactive local copies for inline creation
const localCategories = ref([...(props.categories || [])]);
const localBrands = ref([...(props.brands || [])]);

const showNewCategory = ref(false);
const newCategoryName = ref('');
const creatingCategory = ref(false);
const showNewBrand = ref(false);
const newBrandName = ref('');
const creatingBrand = ref(false);

const quickCreateCategory = async () => {
    if (!newCategoryName.value.trim()) return;
    creatingCategory.value = true;
    try {
        const res = await axios.post('/categories/quick-store', { name: newCategoryName.value.trim() });
        if (res.data.success) {
            localCategories.value.push(res.data.category);
            form.category_id = res.data.category.id;
            newCategoryName.value = '';
            showNewCategory.value = false;
        }
    } catch (e) { alert(e.response?.data?.message || 'Lỗi tạo nhóm hàng'); }
    creatingCategory.value = false;
};

const quickCreateBrand = async () => {
    if (!newBrandName.value.trim()) return;
    creatingBrand.value = true;
    try {
        const res = await axios.post('/brands/quick-store', { name: newBrandName.value.trim() });
        if (res.data.success) {
            localBrands.value.push(res.data.brand);
            form.brand_id = res.data.brand.id;
            newBrandName.value = '';
            showNewBrand.value = false;
        }
    } catch (e) { alert(e.response?.data?.message || 'Lỗi tạo thương hiệu'); }
    creatingBrand.value = false;
};

const generateSku = () => {
    // Generate a temporary random SKU for UX purposes, backend also generates one if empty
    form.sku = 'SP' + new Date().toISOString().slice(2, 10).replace(/-/g, '') + Math.floor(Math.random() * 900 + 100);
};

const addUnit = () => {
    form.units.push({
        unit_name: '',
        conversion_rate: 1,
        retail_price: 0,
    });
};

const removeUnit = (index) => {
    form.units.splice(index, 1);
};

const submit = () => {
    form.post('/products');
};
</script>

<template>
    <Head :title="`Thêm mới ` + (props.type === 'standard' ? 'Hàng hóa' : props.type === 'service' ? 'Dịch vụ' : props.type === 'combo' ? 'Combo - Đóng gói' : 'Hàng sản xuất')" />
    
    <AppLayout>
        <div class="bg-gray-50 min-h-[calc(100vh-3.5rem)] pb-24">
            <!-- Top Header Form -->
            <div class="bg-white px-6 py-3 border-b border-gray-200 flex items-center justify-between sticky top-0 z-40 shadow-sm">
                <h2 class="text-xl font-bold tracking-tight text-gray-800 flex items-center gap-2">
                    Thêm mới
                    <template v-if="props.type === 'standard'">Hàng hóa</template>
                    <template v-else-if="props.type === 'service'">Dịch vụ</template>
                    <template v-else-if="props.type === 'combo'">Combo - Đóng gói</template>
                    <template v-else>Hàng sản xuất</template>
                </h2>
                
                <div class="flex items-center gap-3">
                    <button @click="submit" :disabled="form.processing" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded font-medium flex items-center gap-2 transition-colors shadow-sm disabled:opacity-50">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        Lưu
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
                                <span class="text-sm font-semibold">Thêm ảnh</span>
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
                                <label v-if="props.type === 'standard' && ($page.props.app_settings?.product_use_serial ?? true)" class="flex items-center gap-2 text-sm text-gray-700 font-medium pt-2 border-t border-gray-100 cursor-pointer">
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
                                        <input type="text" v-model="form.name" placeholder="Ví dụ: Giày thể thao Nike Air Max..." class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-base text-gray-800">
                                        <span v-if="form.errors.name" class="text-red-500 text-xs mt-1 block">{{ form.errors.name }}</span>
                                    </div>

                                    <!-- Mã hàng & Mã vạch -->
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Mã hàng hóa</label>
                                        <div class="relative">
                                            <input type="text" v-model="form.sku" placeholder="Mã tự động" class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800">
                                            <button type="button" @click="generateSku" class="absolute right-2 top-1/2 -translate-y-1/2 text-blue-600 hover:text-blue-800 text-xs font-semibold px-2 py-1 mr-[-5px]">Tạo</button>
                                        </div>
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
                                        <div class="flex gap-1">
                                            <select v-model="form.category_id" class="flex-1 border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800 bg-white">
                                                <option value="">--- Chọn nhóm hàng ---</option>
                                                <option v-for="category in localCategories" :key="category.id" :value="category.id">{{ category.name }}</option>
                                            </select>
                                            <button type="button" @click="showNewCategory = !showNewCategory" class="px-2 border border-gray-300 rounded hover:bg-blue-50 hover:border-blue-400 text-blue-600 font-bold text-lg leading-none" title="Thêm nhóm hàng">+</button>
                                        </div>
                                        <div v-if="showNewCategory" class="mt-1 flex gap-1">
                                            <input type="text" v-model="newCategoryName" @keyup.enter="quickCreateCategory" placeholder="Tên nhóm hàng mới" class="flex-1 border border-gray-300 rounded px-2 py-1 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                                            <button type="button" @click="quickCreateCategory" :disabled="creatingCategory" class="px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700 disabled:opacity-50">Lưu</button>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Thương hiệu</label>
                                        <div class="flex gap-1">
                                            <select v-model="form.brand_id" class="flex-1 border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800 bg-white">
                                                <option value="">--- Chọn thương hiệu ---</option>
                                                <option v-for="brand in localBrands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
                                            </select>
                                            <button type="button" @click="showNewBrand = !showNewBrand" class="px-2 border border-gray-300 rounded hover:bg-blue-50 hover:border-blue-400 text-blue-600 font-bold text-lg leading-none" title="Thêm thương hiệu">+</button>
                                        </div>
                                        <div v-if="showNewBrand" class="mt-1 flex gap-1">
                                            <input type="text" v-model="newBrandName" @keyup.enter="quickCreateBrand" placeholder="Tên thương hiệu mới" class="flex-1 border border-gray-300 rounded px-2 py-1 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                                            <button type="button" @click="quickCreateBrand" :disabled="creatingBrand" class="px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700 disabled:opacity-50">Lưu</button>
                                        </div>
                                    </div>

                                    <!-- Vị trí & Trọng lượng -->
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Vị trí lưu kho</label>
                                        <input type="text" v-model="form.location" class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Trọng lượng</label>
                                        <input type="text" v-model="form.weight" placeholder="Ví dụ: 100g, 2kg" class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800">
                                    </div>

                                    <!-- Đơn vị tính section -->
                                    <div v-if="$page.props.app_settings?.product_multiple_units" class="col-span-2 bg-gray-50 border border-dashed border-gray-200 rounded p-4">
                                        <div class="flex justify-between items-center mb-3">
                                            <h4 class="text-sm font-bold text-gray-700">Đơn vị tính</h4>
                                            <button type="button" @click="addUnit" class="text-blue-600 hover:text-blue-800 text-xs font-bold flex items-center gap-1">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                                                Thêm đơn vị
                                            </button>
                                        </div>
                                        <div class="space-y-4">
                                            <!-- Base Unit -->
                                            <div class="grid grid-cols-6 gap-3 items-end">
                                                <div class="col-span-2">
                                                    <label class="block text-[11px] text-gray-500 uppercase font-bold mb-1">Đơn vị cơ bản</label>
                                                    <input type="text" v-model="form.base_unit_name" placeholder="Cái/Chiếc" class="w-full border border-gray-300 rounded p-1.5 text-sm">
                                                </div>
                                            </div>

                                            <!-- Additional Units -->
                                            <div v-for="(unit, index) in form.units" :key="index" class="grid grid-cols-6 gap-3 items-end border-t border-gray-200 pt-3">
                                                <div class="col-span-2">
                                                    <label class="block text-[11px] text-gray-500 uppercase font-bold mb-1">Tên đơn vị</label>
                                                    <input type="text" v-model="unit.unit_name" placeholder="Ví dụ: Thùng" class="w-full border border-gray-300 rounded p-1.5 text-sm">
                                                </div>
                                                <div class="col-span-1">
                                                    <label class="block text-[11px] text-gray-500 uppercase font-bold mb-1">Giá trị quy đổi</label>
                                                    <input type="number" v-model="unit.conversion_rate" class="w-full border border-gray-300 rounded p-1.5 text-sm text-right">
                                                </div>
                                                <div class="col-span-2">
                                                    <label class="block text-[11px] text-gray-500 uppercase font-bold mb-1">Giá bán đơn vị</label>
                                                    <input type="number" v-model="unit.retail_price" class="w-full border border-gray-300 rounded p-1.5 text-sm text-right">
                                                </div>
                                                <div class="col-span-1 flex justify-center pb-1.5">
                                                    <button type="button" @click="removeUnit(index)" class="text-red-500 hover:text-red-700">
                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-span-2 border-t border-gray-100 mt-2 mb-2"></div>

                                    <!-- Giá vốn & Giá bán -->
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Giá vốn</label>
                                        <div class="relative">
                                            <input type="number" v-model="form.cost_price" 
                                                   :disabled="($page.props.app_settings?.inventory_costing_method === 'average')"
                                                   :class="{'bg-gray-50 text-gray-400 cursor-not-allowed': ($page.props.app_settings?.inventory_costing_method === 'average')}"
                                                   class="w-full border border-gray-300 rounded p-2 pr-10 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-right text-base font-semibold">
                                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm font-medium">₫</span>
                                        </div>
                                        <p v-if="$page.props.app_settings?.inventory_costing_method === 'average'" class="text-[11px] text-gray-400 mt-1 italic">Tự động tính theo Giá vốn trung bình</p>
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
                                    <template v-if="props.type === 'standard'">
                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-1">Tồn kho</label>
                                            <input type="number" v-model="form.stock_quantity" class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-1">Định mức tồn ít nhất</label>
                                            <input type="number" v-model="form.min_stock" class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow">
                                        </div>
                                    </template>
                                    
                                    <template v-else-if="props.type === 'combo'">
                                        <div class="col-span-2">
                                            <label class="block text-sm font-semibold text-gray-700 mb-1">Thành phần Combo</label>
                                            <div class="p-4 border border-blue-200 bg-blue-50 rounded text-sm text-blue-600 text-center font-medium">
                                                Tính năng thêm sản phẩm thành phần sẽ được hiện ra và xử lý sau khi tạo thành công Form cha này.
                                            </div>
                                        </div>
                                    </template>

                                    <template v-else-if="props.type === 'manufactured'">
                                        <div class="col-span-2">
                                            <label class="block text-sm font-semibold text-gray-700 mb-1">Nguyên vật liệu cấu thành (BOM)</label>
                                            <div class="p-4 border border-orange-200 bg-orange-50 rounded text-sm text-orange-600 text-center font-medium">
                                                Màn hình thêm nguyên liệu sẽ kích hoạt sau khi khai báo Hàng sản xuất thành công.
                                            </div>
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
