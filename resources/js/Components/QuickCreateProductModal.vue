<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import MoneyInput from '@/Components/MoneyInput.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    categories: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
    showRetailPrice: { type: Boolean, default: false },
    showTechnicianPrice: { type: Boolean, default: false },
    initialName: { type: String, default: '' },
});

const emit = defineEmits(['close', 'created']);

const creating = ref(false);
const errors = ref({});

const form = ref({
    name: '',
    sku: '',
    barcode: '',
    category_id: '',
    brand_id: '',
    cost_price: 0,
    retail_price: 0,
    technician_price: 0,
    has_serial: false,
    warranty_months: 0,
});

const localCategories = ref([]);
const localBrands = ref([]);

watch(() => props.categories, (val) => { localCategories.value = [...(val || [])]; }, { immediate: true });
watch(() => props.brands, (val) => { localBrands.value = [...(val || [])]; }, { immediate: true });

const flattenTree = (nodes, prefix = '') => {
    let result = [];
    for (const node of nodes || []) {
        result.push({ id: node.id, name: prefix + node.name, parent_id: node.parent_id });
        if (node.children && node.children.length) {
            result = result.concat(flattenTree(node.children, prefix + '── '));
        }
    }
    return result;
};
const flatCategories = computed(() => flattenTree(localCategories.value));

const reset = () => {
    form.value = {
        name: props.initialName || '',
        sku: '',
        barcode: '',
        category_id: '',
        brand_id: '',
        cost_price: 0,
        retail_price: 0,
        technician_price: 0,
        has_serial: false,
        warranty_months: 0,
    };
    errors.value = {};
};

watch(() => props.show, (val) => {
    if (val) reset();
});

// Inline quick-create for category / brand inside this modal.
const showNewCategory = ref(false);
const newCategoryName = ref('');
const newCategoryParentId = ref('');
const creatingCategory = ref(false);
const showNewBrand = ref(false);
const newBrandName = ref('');
const creatingBrand = ref(false);

const quickCreateCategory = async () => {
    if (!newCategoryName.value.trim()) return;
    creatingCategory.value = true;
    try {
        const payload = { name: newCategoryName.value.trim() };
        if (newCategoryParentId.value) payload.parent_id = newCategoryParentId.value;
        const res = await axios.post('/categories/quick-store', payload);
        if (res.data?.success) {
            const cat = res.data.category;
            if (cat.parent_id) {
                const addChild = (nodes) => {
                    for (const n of nodes) {
                        if (n.id === cat.parent_id) { if (!n.children) n.children = []; n.children.push({ ...cat, children: [] }); return true; }
                        if (n.children && addChild(n.children)) return true;
                    }
                    return false;
                };
                addChild(localCategories.value);
            } else {
                localCategories.value.push({ ...cat, children: [] });
            }
            form.value.category_id = cat.id;
            newCategoryName.value = '';
            newCategoryParentId.value = '';
            showNewCategory.value = false;
        }
    } catch (e) {
        alert(e.response?.data?.message || 'Lỗi tạo nhóm hàng');
    } finally {
        creatingCategory.value = false;
    }
};

const quickCreateBrand = async () => {
    if (!newBrandName.value.trim()) return;
    creatingBrand.value = true;
    try {
        const res = await axios.post('/brands/quick-store', { name: newBrandName.value.trim() });
        if (res.data?.success) {
            localBrands.value.push(res.data.brand);
            form.value.brand_id = res.data.brand.id;
            newBrandName.value = '';
            showNewBrand.value = false;
        }
    } catch (e) {
        alert(e.response?.data?.message || 'Lỗi tạo thương hiệu');
    } finally {
        creatingBrand.value = false;
    }
};

const submit = async () => {
    if (!form.value.name.trim()) {
        errors.value = { name: 'Tên hàng hóa là bắt buộc' };
        return;
    }
    creating.value = true;
    errors.value = {};
    try {
        // Force numeric payload — never send "1.000.000đ" to backend.
        const payload = {
            name: form.value.name.trim(),
            sku: form.value.sku?.trim() || null,
            barcode: form.value.barcode?.trim() || null,
            category_id: form.value.category_id || null,
            brand_id: form.value.brand_id || null,
            cost_price: Number(form.value.cost_price) || 0,
            retail_price: Number(form.value.retail_price) || 0,
            technician_price: Number(form.value.technician_price) || 0,
            has_serial: !!form.value.has_serial,
            warranty_months: Number(form.value.warranty_months) || 0,
        };
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const res = await axios.post('/products/quick-store', payload, {
            headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
        });
        if (res.data?.success && res.data.product) {
            emit('created', res.data.product);
            emit('close');
        }
    } catch (e) {
        if (e.response?.status === 422 && e.response.data?.errors) {
            const out = {};
            for (const [key, msgs] of Object.entries(e.response.data.errors)) {
                out[key] = Array.isArray(msgs) ? msgs[0] : msgs;
            }
            errors.value = out;
        } else {
            alert(e.response?.data?.message || 'Có lỗi xảy ra khi tạo sản phẩm.');
        }
    } finally {
        creating.value = false;
    }
};

const close = () => emit('close');
</script>

<template>
    <div v-if="show" class="fixed inset-0 bg-black/40 z-[100] flex items-center justify-center p-4 font-sans" @click.self="close">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 sticky top-0 bg-white z-10">
                <h2 class="text-lg font-bold text-gray-800">Tạo hàng hóa</h2>
                <button @click="close" type="button" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>

            <form @submit.prevent="submit" class="p-6">
                <div class="grid grid-cols-2 gap-x-6 gap-y-4">
                    <div class="col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Tên hàng <span class="text-red-500">*</span></label>
                        <input v-model="form.name" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="Nhập tên hàng hóa" autofocus />
                        <span v-if="errors.name" class="text-red-500 text-xs mt-1 block">{{ errors.name }}</span>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Mã hàng</label>
                        <input v-model="form.sku" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="Tự động" />
                        <span v-if="errors.sku" class="text-red-500 text-xs mt-1 block">{{ errors.sku }}</span>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Mã vạch</label>
                        <input v-model="form.barcode" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="Nhập mã vạch" />
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Nhóm hàng</label>
                        <div class="flex gap-1">
                            <select v-model="form.category_id" class="flex-1 border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none bg-white">
                                <option value="">-- Chọn nhóm hàng --</option>
                                <option v-for="cat in flatCategories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                            </select>
                            <button type="button" @click="showNewCategory = !showNewCategory" class="px-2 border border-gray-300 rounded hover:bg-blue-50 hover:border-blue-400 text-blue-600 font-bold text-lg leading-none" title="Thêm nhóm hàng">+</button>
                        </div>
                        <div v-if="showNewCategory" class="mt-1 space-y-1 bg-blue-50 border border-blue-200 rounded p-2">
                            <select v-model="newCategoryParentId" class="w-full border border-gray-300 rounded px-2 py-1 text-sm bg-white outline-none focus:ring-1 focus:ring-blue-500">
                                <option value="">-- Nhóm cha (không chọn = nhóm gốc) --</option>
                                <option v-for="cat in flatCategories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                            </select>
                            <div class="flex gap-1">
                                <input v-model="newCategoryName" @keyup.enter="quickCreateCategory" placeholder="Tên nhóm hàng mới" class="flex-1 border border-gray-300 rounded px-2 py-1 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none" />
                                <button type="button" @click="quickCreateCategory" :disabled="creatingCategory" class="px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700 disabled:opacity-50">Lưu</button>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Thương hiệu</label>
                        <div class="flex gap-1">
                            <select v-model="form.brand_id" class="flex-1 border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none bg-white">
                                <option value="">-- Chọn thương hiệu --</option>
                                <option v-for="brand in localBrands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
                            </select>
                            <button type="button" @click="showNewBrand = !showNewBrand" class="px-2 border border-gray-300 rounded hover:bg-blue-50 hover:border-blue-400 text-blue-600 font-bold text-lg leading-none" title="Thêm thương hiệu">+</button>
                        </div>
                        <div v-if="showNewBrand" class="mt-1 flex gap-1">
                            <input v-model="newBrandName" @keyup.enter="quickCreateBrand" placeholder="Tên thương hiệu mới" class="flex-1 border border-gray-300 rounded px-2 py-1 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none" />
                            <button type="button" @click="quickCreateBrand" :disabled="creatingBrand" class="px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700 disabled:opacity-50">Lưu</button>
                        </div>
                    </div>

                    <!-- Money inputs use MoneyInput for realtime VND formatting + numeric payload. -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Giá vốn (giá nhập)</label>
                        <MoneyInput
                            v-model="form.cost_price"
                            placeholder="0"
                            input-class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none text-right tabular-nums"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Giá bán</label>
                        <MoneyInput
                            v-model="form.retail_price"
                            placeholder="0"
                            input-class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none text-right tabular-nums"
                        />
                    </div>

                    <div v-if="showTechnicianPrice">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Giá bán thợ</label>
                        <MoneyInput
                            v-model="form.technician_price"
                            placeholder="0"
                            input-class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none text-right tabular-nums"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Bảo hành (tháng)</label>
                        <input v-model.number="form.warranty_months" type="number" min="0" step="1" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none text-right" placeholder="0" />
                    </div>

                    <div class="col-span-2">
                        <label class="flex items-center gap-2 text-sm text-gray-700 font-medium cursor-pointer">
                            <input v-model="form.has_serial" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4" />
                            Quản lý theo Serial/IMEI
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-gray-200">
                    <button type="button" @click="close" class="px-5 py-2.5 border border-gray-300 rounded text-sm font-medium text-gray-700 hover:bg-gray-50">Bỏ qua</button>
                    <button type="submit" :disabled="creating || !form.name.trim()" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-medium disabled:opacity-50 flex items-center gap-2">
                        <svg v-if="creating" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        {{ creating ? 'Đang lưu...' : 'Lưu' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
