<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import MoneyInput from '../../Components/MoneyInput.vue';
import { formatVND as formatCurrency } from '@/utils/money';

const props = defineProps({
    product: Object,
    categories: Array,
    brands: Array,
    showRetailPrice: Boolean,
    showTechnicianPrice: Boolean,
    technicianPrice: { type: Number, default: 0 },
    productAttributes: { type: Array, default: () => [] },
});

// ===== Serial/IMEI Management =====
const serials = ref([]);
const serialLoading = ref(false);
const serialSearch = ref('');
const serialStatusFilter = ref('all');
const newSerial = ref('');
const bulkSerials = ref('');
const showBulkInput = ref(false);
const serialError = ref('');
const editingSerialId = ref(null);
const editSerialNumber = ref('');
const editSerialStatus = ref('');
const editSerialCost = ref(0);

const statusLabels = {
    in_stock: 'Còn hàng',
    sold: 'Đã bán',
    returning: 'Đang trả',
    warranty: 'Bảo hành',
    defective: 'Lỗi',
};
const statusColors = {
    in_stock: 'bg-green-100 text-green-700',
    sold: 'bg-gray-100 text-gray-600',
    returning: 'bg-yellow-100 text-yellow-700',
    warranty: 'bg-blue-100 text-blue-700',
    defective: 'bg-red-100 text-red-600',
};

const loadSerials = async () => {
    serialLoading.value = true;
    try {
        const params = {};
        if (serialSearch.value) params.search = serialSearch.value;
        if (serialStatusFilter.value !== 'all') params.status = serialStatusFilter.value;
        const res = await axios.get(`/products/${props.product.id}/serials`, { params });
        serials.value = res.data || [];
    } catch (e) { console.error(e); }
    serialLoading.value = false;
};

const addSerial = async () => {
    serialError.value = '';
    if (!newSerial.value.trim()) return;
    try {
        await axios.post(`/products/${props.product.id}/serials`, { serial_number: newSerial.value.trim() });
        newSerial.value = '';
        loadSerials();
    } catch (e) {
        serialError.value = e.response?.data?.message || e.response?.data?.errors?.serial_number?.[0] || 'Lỗi';
    }
};

const bulkAdd = async () => {
    serialError.value = '';
    if (!bulkSerials.value.trim()) return;
    try {
        const res = await axios.post(`/products/${props.product.id}/serials/bulk`, { serials: bulkSerials.value });
        const d = res.data;
        let msg = `Đã thêm ${d.created} serial.`;
        if (d.duplicates?.length) msg += ` Trùng: ${d.duplicates.join(', ')}`;
        serialError.value = msg;
        bulkSerials.value = '';
        showBulkInput.value = false;
        loadSerials();
    } catch (e) {
        serialError.value = e.response?.data?.message || 'Lỗi';
    }
};

const startEdit = (s) => {
    editingSerialId.value = s.id;
    editSerialNumber.value = s.serial_number;
    editSerialStatus.value = s.status;
    editSerialCost.value = Number(s.cost_price || 0);
};

const cancelEdit = () => { editingSerialId.value = null; };

const saveEdit = async (s) => {
    serialError.value = '';
    try {
        const payload = {
            serial_number: editSerialNumber.value,
            status: editSerialStatus.value,
        };
        // Chỉ gửi cost_price khi serial đang in_stock (BE sẽ chặn nếu không hợp lệ)
        if (s.status === 'in_stock' && editSerialStatus.value === 'in_stock') {
            payload.cost_price = Number(editSerialCost.value) || 0;
        }
        await axios.put(`/products/${props.product.id}/serials/${s.id}`, payload);
        editingSerialId.value = null;
        loadSerials();
    } catch (e) {
        serialError.value = e.response?.data?.message || e.response?.data?.errors?.serial_number?.[0] || 'Lỗi';
    }
};

const deleteSerial = async (s) => {
    if (!confirm(`Xác nhận xóa serial ${s.serial_number}?`)) return;
    try {
        await axios.delete(`/products/${props.product.id}/serials/${s.id}`);
        loadSerials();
    } catch (e) {
        alert(e.response?.data?.message || 'Lỗi');
    }
};

let searchTimeout;
const onSerialSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(loadSerials, 300);
};

onMounted(() => {
    if (props.product.has_serial) loadSerials();
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
    has_variants: !!props.product.has_variants,
    sell_directly: !!props.product.sell_directly,
    allow_point_accumulation: !!props.product.allow_point_accumulation,
    weight: props.product.weight || '',
    location: props.product.location || '',
    variants: (props.product.variants || []).map(v => ({
        id: v.id,
        sku: v.sku || '',
        name: v.name,
        cost_price: v.cost_price || 0,
        retail_price: v.retail_price || 0,
        stock_quantity: v.stock_quantity || 0,
        attribute_value_ids: (v.attribute_values || []).map(av => av.id),
    })),
    // Step 24.9 — warranty / maintenance config
    warranty_months: props.product.warranty_months || 0,
    warranty_policies: Array.isArray(props.product.warranty_policies)
        ? props.product.warranty_policies.map((p) => ({
            name: p.name || '',
            duration_value: Number(p.duration_value) || 0,
            duration_unit: p.duration_unit || 'month',
            is_default: !!p.is_default,
        }))
        : [],
    maintenance_policies: Array.isArray(props.product.maintenance_policies)
        ? props.product.maintenance_policies.map((p) => ({
            name: p.name || '',
            duration_value: Number(p.duration_value) || 0,
            duration_unit: p.duration_unit || 'month',
        }))
        : [],
});

// Step 24.9 — warranty/maintenance row helpers + active product tab
const productActiveTab = ref('info'); // 'info' | 'warranty'
const addWarrantyPolicy = () => {
    form.warranty_policies.push({
        name: form.warranty_policies.length === 0 ? 'Toàn bộ sản phẩm' : '',
        duration_value: 1,
        duration_unit: 'month',
        is_default: form.warranty_policies.length === 0,
    });
};
const removeWarrantyPolicy = (i) => {
    form.warranty_policies.splice(i, 1);
    if (form.warranty_policies.length && !form.warranty_policies.some((r) => r.is_default)) {
        form.warranty_policies[0].is_default = true;
    }
};
const setDefaultWarrantyPolicy = (i) => {
    form.warranty_policies.forEach((r, idx) => { r.is_default = idx === i; });
};
const addMaintenancePolicy = () => {
    form.maintenance_policies.push({ name: '', duration_value: 1, duration_unit: 'month' });
};
const removeMaintenancePolicy = (i) => {
    form.maintenance_policies.splice(i, 1);
};

// Reactive local copies for inline creation
const localCategories = ref([...(props.categories || [])]);
const localBrands = ref([...(props.brands || [])]);

// Flatten tree categories for <select> display
const flattenTree = (nodes, prefix = '') => {
    let result = [];
    for (const node of nodes) {
        result.push({ id: node.id, name: prefix + node.name, parent_id: node.parent_id });
        if (node.children && node.children.length) {
            result = result.concat(flattenTree(node.children, prefix + '── '));
        }
    }
    return result;
};
const flatCategories = computed(() => flattenTree(localCategories.value));

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
        if (res.data.success) {
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
            form.category_id = cat.id;
            newCategoryName.value = '';
            newCategoryParentId.value = '';
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

const submit = () => {
    form.put(`/products/${props.product.id}`);
};

// === VARIANTS / ATTRIBUTES ===
const allAttributes = ref([...(props.productAttributes || [])]);
const selectedAttributes = ref([]);
const newAttrName = ref('');
const newValueInputs = ref({});

const availableAttributes = computed(() => {
    const usedIds = selectedAttributes.value.map(a => a.attribute_id);
    return allAttributes.value.filter(a => !usedIds.includes(a.id));
});

// Initialize selectedAttributes from existing variants
const initVariantAttributes = () => {
    if (!props.product.has_variants || !props.product.variants?.length) return;
    const attrMap = {};
    for (const v of props.product.variants) {
        for (const av of (v.attribute_values || [])) {
            if (!attrMap[av.attribute_id]) {
                const globalAttr = allAttributes.value.find(a => a.id === av.attribute_id);
                attrMap[av.attribute_id] = {
                    attribute_id: av.attribute_id,
                    attribute_name: globalAttr?.name || av.attribute?.name || 'Thuộc tính',
                    values: globalAttr?.values || [],
                    selectedValues: [],
                };
            }
            if (!attrMap[av.attribute_id].selectedValues.includes(av.id)) {
                attrMap[av.attribute_id].selectedValues.push(av.id);
            }
        }
    }
    selectedAttributes.value = Object.values(attrMap);
};
initVariantAttributes();

const addAttribute = (attrId) => {
    const attr = allAttributes.value.find(a => a.id === attrId);
    if (!attr) return;
    selectedAttributes.value.push({
        attribute_id: attr.id,
        attribute_name: attr.name,
        values: [...(attr.values || [])],
        selectedValues: [],
    });
};

const quickCreateAttribute = async () => {
    const name = newAttrName.value.trim();
    if (!name) return;
    try {
        const res = await axios.post('/api/product-attributes', { name });
        const attr = { ...res.data, values: res.data.values || [] };
        allAttributes.value.push(attr);
        selectedAttributes.value.push({
            attribute_id: attr.id,
            attribute_name: attr.name,
            values: attr.values,
            selectedValues: [],
        });
        newAttrName.value = '';
    } catch (e) { alert(e.response?.data?.message || 'Lỗi tạo thuộc tính'); }
};

const quickCreateValue = async (sAttr) => {
    const text = (newValueInputs.value[sAttr.attribute_id] || '').trim();
    if (!text) return;
    try {
        const res = await axios.post(`/api/product-attributes/${sAttr.attribute_id}/values`, { value: text });
        sAttr.values.push(res.data);
        sAttr.selectedValues.push(res.data.id);
        const globalAttr = allAttributes.value.find(a => a.id === sAttr.attribute_id);
        if (globalAttr && globalAttr.values !== sAttr.values) globalAttr.values.push(res.data);
        newValueInputs.value[sAttr.attribute_id] = '';
        generateVariants();
    } catch (e) { alert(e.response?.data?.message || 'Lỗi tạo giá trị'); }
};

const toggleValue = (sAttr, valueId) => {
    const idx = sAttr.selectedValues.indexOf(valueId);
    if (idx >= 0) sAttr.selectedValues.splice(idx, 1);
    else sAttr.selectedValues.push(valueId);
    generateVariants();
};

const removeAttribute = (index) => {
    selectedAttributes.value.splice(index, 1);
    generateVariants();
};

const generateVariants = () => {
    const attrs = selectedAttributes.value.filter(a => a.selectedValues.length > 0);
    if (attrs.length === 0) { form.variants = []; return; }
    const combos = attrs.reduce((acc, attr) => {
        if (acc.length === 0) return attr.selectedValues.map(vId => [{ attribute_id: attr.attribute_id, value_id: vId }]);
        const result = [];
        for (const combo of acc) {
            for (const vId of attr.selectedValues) {
                result.push([...combo, { attribute_id: attr.attribute_id, value_id: vId }]);
            }
        }
        return result;
    }, []);
    const oldVariants = [...form.variants];
    form.variants = combos.map(combo => {
        const attrValueIds = combo.map(c => c.value_id);
        const name = combo.map(c => {
            const attr = attrs.find(a => a.attribute_id === c.attribute_id);
            const val = attr?.values.find(v => v.id === c.value_id);
            return val?.value || '';
        }).join(' - ');
        const existing = oldVariants.find(v =>
            v.attribute_value_ids?.length === attrValueIds.length &&
            v.attribute_value_ids.every(id => attrValueIds.includes(id))
        );
        return {
            id: existing?.id || null,
            sku: existing?.sku || '',
            name: (form.name ? form.name + ' - ' : '') + name,
            cost_price: existing?.cost_price ?? form.cost_price,
            retail_price: existing?.retail_price ?? form.retail_price,
            stock_quantity: existing?.stock_quantity ?? 0,
            attribute_value_ids: attrValueIds,
        };
    });
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
                                <label v-if="props.product.type === 'standard'" class="flex items-center gap-2 text-sm text-gray-700 font-medium cursor-pointer">
                                    <input type="checkbox" v-model="form.has_variants" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                                    Có biến thể (màu sắc, kích thước...)
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Cột Phải: Thông tin chi tiết -->
                    <div class="w-3/4">
                        <div class="bg-white rounded border border-gray-200 shadow-sm overflow-hidden pointer-events-auto">
                            <!-- Step 24.9 — Tab strip: Thông tin / Bảo hành, bảo trì -->
                            <div class="flex items-center border-b border-gray-200 bg-gray-50 px-4">
                                <button
                                    type="button"
                                    @click="productActiveTab = 'info'"
                                    :class="productActiveTab === 'info' ? 'text-blue-600 border-b-2 border-blue-600 font-semibold' : 'text-gray-500 hover:text-gray-700'"
                                    class="px-4 py-3 text-sm transition-colors"
                                >Thông tin</button>
                                <button
                                    type="button"
                                    @click="productActiveTab = 'warranty'"
                                    :class="productActiveTab === 'warranty' ? 'text-blue-600 border-b-2 border-blue-600 font-semibold' : 'text-gray-500 hover:text-gray-700'"
                                    class="px-4 py-3 text-sm transition-colors"
                                >Bảo hành, bảo trì</button>
                            </div>
                            <div v-show="productActiveTab === 'info'" class="p-6">
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
                                        <div class="flex gap-1">
                                            <select v-model="form.category_id" class="flex-1 border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800 bg-white">
                                                <option value="">--- Chọn nhóm hàng ---</option>
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
                                                <input type="text" v-model="newCategoryName" @keyup.enter="quickCreateCategory" placeholder="Tên nhóm hàng mới" class="flex-1 border border-gray-300 rounded px-2 py-1 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                                                <button type="button" @click="quickCreateCategory" :disabled="creatingCategory" class="px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700 disabled:opacity-50">Lưu</button>
                                            </div>
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
                                        <input type="text" v-model="form.weight" class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-sm text-gray-800">
                                    </div>

                                    <div class="col-span-2 border-t border-gray-100 mt-2 mb-2"></div>

                                    <!-- Giá vốn & Giá bán -->
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Giá vốn</label>
                                        <MoneyInput v-model="form.cost_price" suffix
                                                   input-class="w-full border border-gray-300 rounded p-2 pr-7 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-right text-base font-semibold text-gray-800"
                                        />
                                        <span v-if="form.errors.cost_price" class="text-red-500 text-xs mt-1 block">{{ form.errors.cost_price }}</span>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Giá bán <span class="text-red-500">*</span></label>
                                        <MoneyInput v-model="form.retail_price" suffix
                                                   input-class="w-full border border-gray-300 rounded p-2 pr-7 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-right text-base text-blue-700 font-bold"
                                        />
                                        <span v-if="form.errors.retail_price" class="text-red-500 text-xs mt-1 block">{{ form.errors.retail_price }}</span>
                                    </div>

                                    <!-- Giá bán lẻ (conditional) -->
                                    <div v-if="showRetailPrice">
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Giá bán lẻ</label>
                                        <MoneyInput v-model="form.retail_price" suffix
                                                   input-class="w-full border border-gray-300 rounded p-2 pr-7 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-right text-base font-semibold"
                                        />
                                    </div>

                                    <!-- Giá thợ (conditional) -->
                                    <div v-if="showTechnicianPrice">
                                        <label class="block text-sm font-semibold text-gray-700 mb-1">Giá bán thợ</label>
                                        <MoneyInput v-model="form.technician_price" suffix
                                                   input-class="w-full border border-gray-300 rounded p-2 pr-7 focus:ring-1 focus:ring-purple-500 focus:border-purple-500 outline-none transition-shadow text-right text-base font-semibold text-purple-700"
                                        />
                                        <span v-if="form.errors.technician_price" class="text-red-500 text-xs mt-1 block">{{ form.errors.technician_price }}</span>
                                    </div>

                                    <!-- Tồn kho -->
                                    <template v-if="props.product.type === 'standard'">
                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-1">Tồn kho</label>
                                            <template v-if="form.has_serial">
                                                <div class="w-full border border-gray-200 bg-gray-50 rounded p-2 text-gray-600 text-sm cursor-not-allowed">
                                                    {{ form.stock_quantity }} <span class="text-xs text-orange-500 ml-1">(Tự động theo Serial/IMEI)</span>
                                                </div>
                                            </template>
                                            <template v-else>
                                                <input type="number" v-model="form.stock_quantity" class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow">
                                            </template>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-1">Định mức tồn ít nhất</label>
                                            <input type="number" v-model="form.min_stock" class="w-full border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow">
                                        </div>
                                    </template>

                                    <!-- BIẾN THỂ / THUỘC TÍNH -->
                                    <template v-if="form.has_variants && props.product.type === 'standard'">
                                        <div class="col-span-2 border-t border-gray-200 pt-4 mt-2">
                                            <h4 class="text-sm font-bold text-gray-700 mb-3 flex items-center gap-2">
                                                <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path></svg>
                                                Thuộc tính biến thể
                                            </h4>
                                            <div class="flex gap-2 mb-3">
                                                <select @change="addAttribute(Number($event.target.value)); $event.target.value = ''" class="flex-1 border border-gray-300 rounded p-2 text-sm bg-white">
                                                    <option value="">-- Chọn thuộc tính --</option>
                                                    <option v-for="attr in availableAttributes" :key="attr.id" :value="attr.id">{{ attr.name }}</option>
                                                </select>
                                                <div class="flex gap-1">
                                                    <input type="text" v-model="newAttrName" placeholder="Hoặc tạo mới..." @keyup.enter="quickCreateAttribute" class="border border-gray-300 rounded px-2 py-1 text-sm w-40">
                                                    <button type="button" @click="quickCreateAttribute" class="px-3 py-1 bg-purple-600 text-white rounded text-sm hover:bg-purple-700">+</button>
                                                </div>
                                            </div>
                                            <div v-for="(sAttr, idx) in selectedAttributes" :key="sAttr.attribute_id" class="mb-3 bg-gray-50 border border-gray-200 rounded p-3">
                                                <div class="flex justify-between items-center mb-2">
                                                    <span class="text-sm font-semibold text-gray-700">{{ sAttr.attribute_name }}</span>
                                                    <button type="button" @click="removeAttribute(idx)" class="text-red-500 hover:text-red-700 text-xs">Xóa</button>
                                                </div>
                                                <div class="flex flex-wrap gap-1.5 mb-2">
                                                    <button v-for="val in sAttr.values" :key="val.id" type="button"
                                                        @click="toggleValue(sAttr, val.id)"
                                                        :class="sAttr.selectedValues.includes(val.id) ? 'bg-purple-600 text-white border-purple-600' : 'bg-white text-gray-700 border-gray-300 hover:border-purple-400'"
                                                        class="px-2.5 py-1 text-xs rounded-full border font-medium transition-colors">
                                                        {{ val.value }}
                                                    </button>
                                                </div>
                                                <div class="flex gap-1">
                                                    <input type="text" v-model="newValueInputs[sAttr.attribute_id]" placeholder="Thêm giá trị mới..." @keyup.enter="quickCreateValue(sAttr)" class="flex-1 border border-gray-300 rounded px-2 py-1 text-xs">
                                                    <button type="button" @click="quickCreateValue(sAttr)" class="px-2 py-1 bg-gray-600 text-white rounded text-xs hover:bg-gray-700">+</button>
                                                </div>
                                            </div>
                                            <div v-if="form.variants.length > 0" class="mt-4">
                                                <h5 class="text-xs font-bold text-gray-500 uppercase mb-2">Danh sách biến thể ({{ form.variants.length }})</h5>
                                                <div class="overflow-x-auto">
                                                    <table class="w-full text-sm border border-gray-200">
                                                        <thead class="bg-gray-50">
                                                            <tr>
                                                                <th class="px-2 py-1.5 text-left text-xs font-semibold text-gray-600">Tên biến thể</th>
                                                                <th class="px-2 py-1.5 text-left text-xs font-semibold text-gray-600 w-28">SKU</th>
                                                                <th class="px-2 py-1.5 text-right text-xs font-semibold text-gray-600 w-28">Giá vốn</th>
                                                                <th class="px-2 py-1.5 text-right text-xs font-semibold text-gray-600 w-28">Giá bán</th>
                                                                <th class="px-2 py-1.5 text-right text-xs font-semibold text-gray-600 w-20">Tồn kho</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr v-for="(v, vi) in form.variants" :key="vi" class="border-t border-gray-100">
                                                                <td class="px-2 py-1"><input type="text" v-model="v.name" class="w-full border border-gray-200 rounded px-1.5 py-1 text-sm"></td>
                                                                <td class="px-2 py-1"><input type="text" v-model="v.sku" placeholder="Tự động" class="w-full border border-gray-200 rounded px-1.5 py-1 text-sm"></td>
                                                                <td class="px-2 py-1"><MoneyInput v-model="v.cost_price" input-class="w-full border border-gray-200 rounded px-1.5 py-1 text-sm text-right" /></td>
                                                                <td class="px-2 py-1"><MoneyInput v-model="v.retail_price" input-class="w-full border border-gray-200 rounded px-1.5 py-1 text-sm text-right" /></td>
                                                                <td class="px-2 py-1"><input type="number" v-model="v.stock_quantity" class="w-full border border-gray-200 rounded px-1.5 py-1 text-sm text-right"></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <p v-else class="text-xs text-gray-400 italic mt-2">Chọn thuộc tính và giá trị để tạo biến thể tự động.</p>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- ════ Step 24.9 — Bảo hành, bảo trì tab ════ -->
                            <div v-show="productActiveTab === 'warranty'" class="p-6 space-y-8">
                                <!-- Bảo hành section -->
                                <section>
                                    <div class="flex items-center justify-between mb-3">
                                        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide">Bảo hành</h3>
                                        <button type="button" @click="addWarrantyPolicy" class="text-blue-600 hover:text-blue-700 text-sm font-medium flex items-center gap-1">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                                            Thêm mốc
                                        </button>
                                    </div>
                                    <div v-if="form.warranty_policies.length === 0" class="border border-dashed border-gray-300 rounded p-6 text-center text-gray-400 text-sm">
                                        Chưa có bảo hành nào. Bấm "+ Thêm mốc" để cấu hình.
                                    </div>
                                    <table v-else class="w-full text-sm border border-gray-200 rounded overflow-hidden">
                                        <thead class="bg-gray-50 text-xs text-gray-600 uppercase">
                                            <tr>
                                                <th class="px-3 py-2 text-left">Bảo hành</th>
                                                <th class="px-3 py-2 text-center w-40">Thời hạn bảo hành</th>
                                                <th class="px-3 py-2 text-center w-24">Mặc định</th>
                                                <th class="px-3 py-2 w-12"></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <tr v-for="(p, i) in form.warranty_policies" :key="'w-' + i">
                                                <td class="px-3 py-2">
                                                    <input v-model="p.name" type="text" class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500" placeholder="Toàn bộ sản phẩm" />
                                                </td>
                                                <td class="px-3 py-2">
                                                    <div class="flex items-center gap-1">
                                                        <input v-model.number="p.duration_value" type="number" min="0" max="1200" class="w-20 border border-gray-300 rounded px-2 py-1 text-sm text-right tabular-nums focus:outline-none focus:ring-1 focus:ring-blue-500" />
                                                        <select v-model="p.duration_unit" class="border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500">
                                                            <option value="day">Ngày</option>
                                                            <option value="month">Tháng</option>
                                                            <option value="year">Năm</option>
                                                        </select>
                                                    </div>
                                                </td>
                                                <td class="px-3 py-2 text-center">
                                                    <input type="radio" :checked="p.is_default" @change="setDefaultWarrantyPolicy(i)" name="warranty_default" class="w-4 h-4 text-blue-600 focus:ring-blue-500" />
                                                </td>
                                                <td class="px-3 py-2 text-center">
                                                    <button type="button" @click="removeWarrantyPolicy(i)" class="text-gray-400 hover:text-red-600" title="Xóa">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <p class="mt-2 text-xs text-gray-400">Mốc mặc định được dùng để tính ngày kết thúc bảo hành cho mỗi hóa đơn bán. Các mốc khác lưu cùng để in/hiển thị chính sách.</p>
                                </section>

                                <!-- Bảo trì section -->
                                <section>
                                    <div class="flex items-center justify-between mb-3">
                                        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide">Bảo trì định kỳ</h3>
                                        <button type="button" @click="addMaintenancePolicy" class="text-blue-600 hover:text-blue-700 text-sm font-medium flex items-center gap-1">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                                            Thêm mốc
                                        </button>
                                    </div>
                                    <div v-if="form.maintenance_policies.length === 0" class="border border-dashed border-gray-300 rounded p-6 text-center text-gray-400 text-sm">
                                        Chưa có bảo trì nào.
                                    </div>
                                    <table v-else class="w-full text-sm border border-gray-200 rounded overflow-hidden">
                                        <thead class="bg-gray-50 text-xs text-gray-600 uppercase">
                                            <tr>
                                                <th class="px-3 py-2 text-left">Bảo trì định kỳ</th>
                                                <th class="px-3 py-2 text-center w-40">Thời gian bảo trì</th>
                                                <th class="px-3 py-2 w-12"></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <tr v-for="(p, i) in form.maintenance_policies" :key="'m-' + i">
                                                <td class="px-3 py-2">
                                                    <input v-model="p.name" type="text" class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500" placeholder="Vệ sinh định kỳ" />
                                                </td>
                                                <td class="px-3 py-2">
                                                    <div class="flex items-center gap-1">
                                                        <input v-model.number="p.duration_value" type="number" min="1" max="1200" class="w-20 border border-gray-300 rounded px-2 py-1 text-sm text-right tabular-nums focus:outline-none focus:ring-1 focus:ring-blue-500" />
                                                        <select v-model="p.duration_unit" class="border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500">
                                                            <option value="day">Ngày</option>
                                                            <option value="month">Tháng</option>
                                                            <option value="year">Năm</option>
                                                        </select>
                                                    </div>
                                                </td>
                                                <td class="px-3 py-2 text-center">
                                                    <button type="button" @click="removeMaintenancePolicy(i)" class="text-gray-400 hover:text-red-600" title="Xóa">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </section>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Serial/IMEI Management Section -->
            <div v-if="form.has_serial" class="max-w-6xl mx-auto px-4 md:px-6 mt-4">
                <div class="bg-white rounded border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-base font-bold text-gray-800">Quản lý Serial/IMEI</h3>
                        <span class="text-sm text-gray-500">{{ serials.length }} serial</span>
                    </div>

                    <div class="p-6">
                        <!-- Add serial -->
                        <div class="flex flex-wrap gap-2 mb-4">
                            <div class="flex gap-1 flex-1 min-w-[200px]">
                                <input
                                    v-model="newSerial"
                                    @keyup.enter="addSerial"
                                    type="text"
                                    placeholder="Nhập serial mới..."
                                    class="flex-1 border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none"
                                />
                                <button @click="addSerial" class="px-4 py-2 bg-blue-600 text-white rounded text-sm font-semibold hover:bg-blue-700">Thêm</button>
                            </div>
                            <button @click="showBulkInput = !showBulkInput" class="px-3 py-2 border border-gray-300 rounded text-sm font-semibold hover:bg-gray-50">
                                {{ showBulkInput ? 'Ẩn' : 'Thêm hàng loạt' }}
                            </button>
                        </div>

                        <!-- Bulk input -->
                        <div v-if="showBulkInput" class="mb-4 bg-blue-50 border border-blue-200 rounded p-3">
                            <p class="text-xs text-gray-500 mb-2">Mỗi serial một dòng, hoặc ngăn cách bằng dấu phẩy / chấm phẩy</p>
                            <textarea v-model="bulkSerials" rows="4" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 outline-none" placeholder="SERIAL001&#10;SERIAL002&#10;SERIAL003"></textarea>
                            <button @click="bulkAdd" class="mt-2 px-4 py-2 bg-blue-600 text-white rounded text-sm font-semibold hover:bg-blue-700">Thêm hàng loạt</button>
                        </div>

                        <!-- Error/Info message -->
                        <div v-if="serialError" class="mb-3 text-sm px-3 py-2 rounded" :class="serialError.startsWith('Đã thêm') ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-600'">{{ serialError }}</div>

                        <!-- Search & Filter -->
                        <div class="flex gap-2 mb-3">
                            <input v-model="serialSearch" @input="onSerialSearch" type="text" placeholder="Tìm serial..." class="flex-1 border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 outline-none" />
                            <select v-model="serialStatusFilter" @change="loadSerials" class="border border-gray-300 rounded px-3 py-2 text-sm">
                                <option value="all">Tất cả</option>
                                <option value="in_stock">Còn hàng</option>
                                <option value="sold">Đã bán</option>
                                <option value="returning">Đang trả</option>
                                <option value="warranty">Bảo hành</option>
                                <option value="defective">Lỗi</option>
                            </select>
                        </div>

                        <!-- Serial table -->
                        <div v-if="serialLoading" class="text-center py-6 text-gray-400">Đang tải...</div>
                        <table v-else class="w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600 text-xs uppercase">
                                <tr>
                                    <th class="px-3 py-2 text-left">Serial/IMEI</th>
                                    <th class="px-3 py-2 text-center">Trạng thái</th>
                                    <th class="px-3 py-2 text-right">Giá vốn</th>
                                    <th class="px-3 py-2 text-center w-32"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!serials.length">
                                    <td colspan="4" class="text-center py-6 text-gray-400">Chưa có serial nào.</td>
                                </tr>
                                <tr v-for="s in serials" :key="s.id" class="border-t hover:bg-gray-50">
                                    <template v-if="editingSerialId === s.id">
                                        <td class="px-3 py-2">
                                            <input v-model="editSerialNumber" class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:ring-1 focus:ring-blue-500 outline-none" />
                                        </td>
                                        <td class="px-3 py-2 text-center">
                                            <select v-model="editSerialStatus" class="border border-gray-300 rounded px-2 py-1 text-sm">
                                                <option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ label }}</option>
                                            </select>
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <MoneyInput v-if="s.status === 'in_stock' && editSerialStatus === 'in_stock'"
                                                v-model="editSerialCost"
                                                :min="0"
                                                input-class="w-32 border border-gray-300 rounded px-2 py-1 text-sm text-right focus:ring-1 focus:ring-blue-500 outline-none" />
                                            <span v-else class="text-gray-400 text-xs italic" :title="'Chỉ sửa được khi còn tồn'">{{ formatCurrency(s.cost_price) }}</span>
                                        </td>
                                        <td class="px-3 py-2 text-center">
                                            <button @click="saveEdit(s)" class="text-blue-600 text-xs font-semibold mr-2 hover:underline">Lưu</button>
                                            <button @click="cancelEdit" class="text-gray-500 text-xs font-semibold hover:underline">Hủy</button>
                                        </td>
                                    </template>
                                    <template v-else>
                                        <td class="px-3 py-2 font-mono font-semibold text-gray-800">{{ s.serial_number }}</td>
                                        <td class="px-3 py-2 text-center">
                                            <span :class="statusColors[s.status]" class="px-2 py-0.5 rounded-full text-xs font-semibold">{{ statusLabels[s.status] || s.status }}</span>
                                        </td>
                                        <td class="px-3 py-2 text-right text-gray-500">{{ formatCurrency(s.cost_price) }}</td>
                                        <td class="px-3 py-2 text-center">
                                            <button @click="startEdit(s)" class="text-blue-600 text-xs font-semibold mr-2 hover:underline">Sửa</button>
                                            <button v-if="s.status !== 'sold'" @click="deleteSerial(s)" class="text-red-500 text-xs font-semibold hover:underline">Xóa</button>
                                        </td>
                                    </template>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
