<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import QuickCreateCustomerModal from '@/Components/QuickCreateCustomerModal.vue';

const props = defineProps({
    products: Array,
    suppliers: Array,
    employees: Array,
    categories: Array,
    brands: Array,
    purchaseCode: String,
    purchaseOrderInfo: Object,
    showRetailPrice: Boolean,
    showTechnicianPrice: Boolean,
    bankAccounts: Array,
});

// Local mutable copy of products list (to add newly created products)
const allProducts = ref([...(props.products || [])]);
const localSuppliers = ref([...(props.suppliers || [])]);

// Quick Create Supplier
const showCreateSupplierModal = ref(false);

const onSupplierCreated = (supplier) => {
    localSuppliers.value.push(supplier);
    selectedSupplierId.value = supplier.id;
    supplierSearchQuery.value = '';
};

const searchQuery = ref('');
const showSuggestions = ref(false);
const showCreateDropdown = ref(false);
const items = ref([]);

const selectedSupplierId = ref('');
const selectedSupplier = computed(() => localSuppliers.value.find(s => s.id === selectedSupplierId.value) || null);

const supplierSearchQuery = ref('');
const showSupplierDropdown = ref(false);
const filteredSuppliers = ref([]);
const isSearchingSupplier = ref(false);

let supplierSearchTimeout = null;
watch(supplierSearchQuery, (val) => {
    if (!val) {
        filteredSuppliers.value = [];
        showSupplierDropdown.value = false;
        return;
    }
    showSupplierDropdown.value = true;
    if (supplierSearchTimeout) clearTimeout(supplierSearchTimeout);
    supplierSearchTimeout = setTimeout(async () => {
        isSearchingSupplier.value = true;
        try {
            const response = await axios.get('/api/suppliers/search', {
                params: { search: val }
            });
            filteredSuppliers.value = response.data;
        } catch (error) {
            console.error("Lỗi tìm kiếm nhà cung cấp:", error);
        } finally {
            isSearchingSupplier.value = false;
        }
    }, 300);
});

const selectSupplier = (supplier) => {
    selectedSupplierId.value = supplier.id;
    supplierSearchQuery.value = '';
    showSupplierDropdown.value = false;
};

const hideSupplierDropdown = () => {
    setTimeout(() => {
        showSupplierDropdown.value = false;
    }, 200);
};

const removeSupplier = () => {
    selectedSupplierId.value = '';
    supplierSearchQuery.value = '';
};
const selectedEmployeeId = ref('');
// Use local time (not UTC) for datetime-local input
const pad = (n) => String(n).padStart(2, '0');
const nowLocal = new Date();
const localNow = `${nowLocal.getFullYear()}-${pad(nowLocal.getMonth()+1)}-${pad(nowLocal.getDate())}T${pad(nowLocal.getHours())}:${pad(nowLocal.getMinutes())}`;
const purchaseDate = ref(localNow);
const status = ref('completed');
const discount = ref(0);
const paidAmount = ref(0);
const note = ref('');
const submitRef = ref(false);
const paymentMethod = ref('cash');
const bankAccountInfo = ref('');

// Chi phí nhập khác (shipping, etc.)
const otherCosts = ref([]);
const showOtherCosts = ref(false);
const newCostName = ref('');
const newCostAmount = ref(0);

const addOtherCost = () => {
    if (!newCostName.value.trim()) return;
    otherCosts.value.push({
        id: Date.now(),
        name: newCostName.value.trim(),
        amount: Number(newCostAmount.value) || 0,
    });
    newCostName.value = '';
    newCostAmount.value = 0;
};
const removeOtherCost = (index) => {
    otherCosts.value.splice(index, 1);
};

onMounted(() => {
    if (props.purchaseOrderInfo) {
        selectedSupplierId.value = props.purchaseOrderInfo.supplier_id || '';
        discount.value = props.purchaseOrderInfo.discount || 0;
        items.value = props.purchaseOrderInfo.items || [];
    }
});

const filteredProducts = ref([]);
const isSearchingProduct = ref(false);

let searchTimeout = null;
watch(searchQuery, (val) => {
    if (!val) {
        filteredProducts.value = [];
        showSuggestions.value = false;
        return;
    }
    showSuggestions.value = true;
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(async () => {
        isSearchingProduct.value = true;
        try {
            const response = await axios.get('/api/products/search', {
                params: { search: val }
            });
            filteredProducts.value = response.data;
        } catch (error) {
            console.error("Lỗi tìm kiếm sản phẩm:", error);
        } finally {
            isSearchingProduct.value = false;
        }
    }, 300);
});

const selectProduct = (product) => {
    const existing = items.value.find(i => i.product_id === product.id);
    if (!existing) {
        items.value.unshift({ 
            product_id: product.id,
            sku: product.sku,
            name: product.name,
            has_serial: !!product.has_serial,
            has_variants: !!product.has_variants,
            variants: product.variants || [],
            quantity: product.has_serial ? 0 : 1,
            price: product.cost_price || 0,
            retail_price: product.retail_price || 0,
            technician_price: product.technician_price || 0,
            discount: 0,
            stock_quantity: product.stock_quantity || 0,
            serials: [],
            serialInput: '',
            serialVariantId: null,
            showSerialArea: !!product.has_serial,
            warranty_months: 0,
        });
    } else {
        if (!existing.has_serial) existing.quantity++;
    }
    searchQuery.value = '';
    showSuggestions.value = false;
};

const hideSuggestions = () => {
    setTimeout(() => { showSuggestions.value = false; showCreateDropdown.value = false; }, 200);
};

const removeItem = (index) => {
    items.value.splice(index, 1);
};

const addSerial = (item) => {
    const val = item.serialInput?.trim();
    if (!val) return;
    if (item.serials.find(s => s.serial_number === val)) {
        alert('Serial/IMEI "' + val + '" đã tồn tại trong danh sách!');
        return;
    }
    item.serials.push({
        serial_number: val,
        variant_id: item.serialVariantId || null,
    });
    item.quantity = item.serials.length;
    item.serialInput = '';
};

const removeSerial = (item, index) => {
    item.serials.splice(index, 1);
    item.quantity = item.serials.length;
};

const getVariantName = (item, variantId) => {
    if (!variantId || !item.variants) return '';
    const v = item.variants.find(v => v.id == variantId);
    return v ? v.name : '';
};

const getItemTotal = (item) => {
    const qty = item.has_serial ? (item.serials?.length || 0) : (parseInt(item.quantity) || 0);
    const price = parseFloat(item.price) || 0;
    const itemDiscount = parseFloat(item.discount) || 0;
    const total = (qty * price) - itemDiscount;
    return isNaN(total) ? 0 : total;
};

const totalAmount = computed(() => {
    if (!items.value || !Array.isArray(items.value)) return 0;
    const sum = items.value.reduce((s, item) => s + getItemTotal(item), 0);
    return isNaN(sum) ? 0 : sum;
});
const totalOtherCosts = computed(() => {
    if (!otherCosts.value || !Array.isArray(otherCosts.value)) return 0;
    const sum = otherCosts.value.reduce((s, c) => s + (Number(c.amount) || 0), 0);
    return isNaN(sum) ? 0 : sum;
});
const totalPayment = computed(() => {
    // Supplier debt = goods total - discount ONLY (ship/other costs excluded)
    const payment = Math.max(0, totalAmount.value - Number(discount.value || 0));
    return isNaN(payment) ? 0 : payment;
});

const isPaidAmountEdited = ref(false);
watch(totalPayment, (newVal) => {
    if (!isPaidAmountEdited.value) {
        paidAmount.value = newVal;
    }
}, { immediate: true });

const debtAmount = computed(() => Math.max(0, totalPayment.value - Number(paidAmount.value)));

const save = async () => {
    if (items.value.length === 0) {
        alert("Vui lòng chọn ít nhất 1 hàng hóa để nhập hàng.");
        return;
    }
    if (!selectedSupplierId.value) {
        alert("Vui lòng chọn nhà cung cấp!");
        return;
    }

    submitRef.value = true;
    
    try {
        await router.post('/purchases', {
            code: props.purchaseCode,
            status: status.value,
            supplier_id: selectedSupplierId.value || null,
            employee_id: selectedEmployeeId.value || null,
            purchase_date: purchaseDate.value || null,
            note: note.value,
            discount: discount.value,
            paid_amount: paidAmount.value,
            payment_method: paymentMethod.value,
            bank_account_info: paymentMethod.value === 'transfer' ? bankAccountInfo.value : null,
            other_costs: otherCosts.value.map(c => ({ name: c.name, amount: c.amount })),
            items: items.value.map(item => ({
                product_id: item.product_id,
                quantity: item.has_serial ? (item.serials?.length || 0) : (parseInt(item.quantity) || 0),
                price: item.price,
                retail_price: item.retail_price || 0,
                technician_price: item.technician_price || 0,
                discount: item.discount,
                serials: (item.serials || []).map(s => ({
                    serial_number: s.serial_number || s,
                    variant_id: s.variant_id || null,
                })),
                warranty_months: item.warranty_months || 0,
            }))
        });
    } catch (e) {
        alert("Có lỗi xảy ra, vui lòng kiểm tra lại dữ liệu.");
        submitRef.value = false;
    }
};

const formatCurrency = (val) => Number(val).toLocaleString('vi-VN');

// Format input hiển thị giá Việt Nam (8.000.000) nhưng lưu số thật
const parseCurrencyInput = (str) => {
    if (!str && str !== 0) return 0;
    return Number(String(str).replace(/\./g, '').replace(/,/g, '')) || 0;
};
const formatCurrencyInput = (val) => {
    const num = Number(val) || 0;
    return num.toLocaleString('vi-VN');
};
const onCurrencyInput = (obj, field, event) => {
    const raw = event.target.value;
    obj[field] = parseCurrencyInput(raw);
    event.target.value = formatCurrencyInput(obj[field]);
};
const onCurrencyFocus = (event) => {
    const val = parseCurrencyInput(event.target.value);
    if (val === 0) event.target.value = '';
    else event.target.value = String(val);
};
const onCurrencyBlur = (obj, field, event) => {
    const val = parseCurrencyInput(event.target.value);
    obj[field] = val;
    event.target.value = formatCurrencyInput(val);
};

// === Quick Create Product Modal ===
const showQuickProductModal = ref(false);

// Flatten categories with children into hierarchical list for select
const flattenedCategories = computed(() => {
    const result = [];
    for (const cat of (props.categories || [])) {
        result.push({ id: cat.id, name: cat.name, level: 0 });
        for (const child of (cat.children || [])) {
            result.push({ id: child.id, name: child.name, level: 1 });
            for (const grandchild of (child.children || [])) {
                result.push({ id: grandchild.id, name: grandchild.name, level: 2 });
            }
        }
    }
    return result;
});
const quickProductForm = ref({
    name: '',
    sku: '',
    barcode: '',
    category_id: '',
    brand_id: '',
    cost_price: 0,
    retail_price: 0,
    technician_price: 0,
    has_serial: true,
});
const isSavingProduct = ref(false);

const openQuickProductModal = () => {
    // Pre-fill name from current search query
    quickProductForm.value = {
        name: searchQuery.value || '',
        sku: '',
        barcode: '',
        category_id: '',
        brand_id: '',
        cost_price: 0,
        retail_price: 0,
        technician_price: 0,
        has_serial: true,
    };
    showQuickProductModal.value = true;
    showCreateDropdown.value = false;
};

const saveQuickProduct = async () => {
    if (!quickProductForm.value.name.trim()) {
        alert('Vui lòng nhập tên sản phẩm!');
        return;
    }
    isSavingProduct.value = true;
    try {
        const res = await axios.post('/products/quick-store', quickProductForm.value);
        const product = res.data.product;
        if (product) {
            // Auto-add to items list
            selectProduct(product);
            showQuickProductModal.value = false;
        }
    } catch (e) {
        const msg = e.response?.data?.message || e.message || 'Lỗi không xác định';
        alert('Lỗi tạo sản phẩm: ' + msg);
    } finally {
        isSavingProduct.value = false;
    }
};

</script>

<template>
    <Head title="Nhập hàng - KiotViet Clone" />
    <div class="h-screen flex flex-col bg-[#eef1f5] text-[13px] overflow-hidden font-sans">
        
        <!-- Header -->
        <header class="bg-white text-gray-800 px-4 h-[56px] flex items-center justify-between border-b border-gray-200 shadow-sm flex-shrink-0">
            <div class="flex items-center gap-4 flex-1">
                <Link href="/purchases" class="text-gray-600 hover:text-green-600 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                </Link>
                <div class="text-xl font-bold text-gray-800 mr-4">Nhập hàng</div>
                
                <div class="relative w-full max-w-[500px]">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                    <input v-model="searchQuery" @focus="showSuggestions = true" @blur="hideSuggestions" type="text" class="w-full pl-9 pr-12 py-[9px] border border-gray-300 text-gray-800 rounded focus:outline-none focus:ring-1 focus:ring-green-500 focus:border-green-500 bg-white" placeholder="Tìm hàng hóa theo mã hoặc tên (F3)">
                    
                    <div v-if="showSuggestions" class="absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 shadow-xl rounded-sm z-50 max-h-[300px] overflow-auto">
                        <div v-if="isSearchingProduct" class="p-3 text-sm text-gray-500 text-center">
                            Đang tìm kiếm...
                        </div>
                        <div v-else-if="filteredProducts.length === 0 && searchQuery" class="p-3 text-sm text-gray-500 text-center">
                            Không tìm thấy sản phẩm hợp lệ
                        </div>
                        <div v-for="product in filteredProducts" :key="product.id" @mousedown.prevent="selectProduct(product)" class="flex items-center gap-3 p-2 border-b border-gray-100 hover:bg-gray-50 cursor-pointer">
                            <img :src="product.image || 'https://ui-avatars.com/api/?name=' + product.name + '&background=random'" class="w-10 h-10 object-cover rounded border border-gray-200">
                            <div class="flex-1">
                                <div class="font-medium text-[13px] text-gray-800">{{ product.name }}</div>
                                <div class="text-[12px] text-gray-500">{{ product.sku }}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-green-600 font-medium text-[13px]">{{ formatCurrency(product.cost_price) }}</div>
                                <div class="text-[12px] text-gray-400">Tồn: {{ product.stock_quantity || 0 }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="absolute inset-y-0 right-0 pr-2 flex items-center gap-1.5 text-gray-400">
                        <div class="relative">
                            <button @click="openQuickProductModal" class="hover:text-green-600" title="Tạo nhanh hàng hóa">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3 text-gray-500">
                 <button class="hover:bg-gray-100 p-2 rounded"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg></button>
            </div>
        </header>

        <!-- Main Content -->
        <div class="flex-1 flex overflow-hidden">
            <!-- Left Panel -->
            <div class="flex-1 flex flex-col bg-white overflow-hidden shadow-[1px_0_0_rgba(0,0,0,0.05)] border-r border-gray-200">
                <div class="flex-1 overflow-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-[#f0f9f1] text-green-800 font-bold sticky top-0 z-10 shadow-sm border-b border-green-100 mt-2">
                            <tr>
                                <th class="p-3 w-12 text-center">STT</th>
                                <th class="p-3 w-[120px]">Mã hàng</th>
                                <th class="p-3">Tên hàng</th>
                                <th class="p-3 w-[80px] text-center">ĐVT</th>
                                <th class="p-3 w-[100px] text-center">Số lượng</th>
                                <th class="p-3 w-[120px] text-right">Đơn giá</th>
                                <th v-if="showRetailPrice" class="p-3 w-[120px] text-right">Giá bán lẻ</th>
                                <th v-if="showTechnicianPrice" class="p-3 w-[120px] text-right">Giá bán thợ</th>
                                <th class="p-3 w-[100px] text-right">Giảm giá</th>
                                <th class="p-3 w-[80px] text-center">BH (tháng)</th>
                                <th class="p-3 w-[140px] text-right pr-6">Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody v-if="items.length > 0">
                            <template v-for="(item, index) in items" :key="item.product_id">
                            <tr class="border-b border-gray-100 hover:bg-[#f8fafc] transition-colors">
                                <td class="p-3 text-center text-gray-500 group relative w-12">
                                    <span class="group-hover:hidden">{{ index + 1 }}</span>
                                    <button @click="removeItem(index)" class="hidden group-hover:flex items-center justify-center w-5 h-5 bg-red-500 hover:bg-red-600 text-white rounded-full mx-auto" title="Xóa">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                    </button>
                                </td>
                                <td class="p-3 text-gray-700 w-[120px]">{{ item.sku }}</td>
                                <td class="p-3">
                                    <div class="font-medium text-blue-600">{{ item.name }}</div>
                                    <div v-if="item.has_serial" class="text-[11px] text-orange-500 mt-0.5">
                                        <svg class="w-3 h-3 inline mr-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path></svg>
                                        Quản lý Serial/IMEI
                                    </div>
                                </td>
                                <td class="p-3 text-center w-[80px]">Cái</td>
                                <td class="p-3 text-center w-[100px]">
                                    <input v-if="!item.has_serial" type="number" v-model="item.quantity" min="1" class="w-[70px] border-b border-dashed border-gray-400 py-1 text-center outline-none focus:border-green-500 text-[13px] hover:bg-green-50 font-medium">
                                    <span v-else class="font-medium text-gray-700">{{ item.serials.length }}</span>
                                </td>
                                <td class="p-3 w-[120px]">
                                    <input type="text" :value="formatCurrencyInput(item.price)" @input="e => item.price = parseCurrencyInput(e.target.value)" @focus="onCurrencyFocus" @blur="onCurrencyBlur(item, 'price', $event)" class="w-full border-b border-dashed border-gray-400 py-1 text-right outline-none focus:border-green-500 text-[13px] hover:bg-green-50 font-medium tracking-wide">
                                </td>
                                <td v-if="showRetailPrice" class="p-3 w-[120px]">
                                    <input type="text" :value="formatCurrencyInput(item.retail_price)" @input="e => item.retail_price = parseCurrencyInput(e.target.value)" @focus="onCurrencyFocus" @blur="onCurrencyBlur(item, 'retail_price', $event)" class="w-full border-b border-dashed border-gray-400 py-1 text-right outline-none focus:border-blue-500 text-[13px] hover:bg-blue-50 font-medium tracking-wide">
                                </td>
                                <td v-if="showTechnicianPrice" class="p-3 w-[120px]">
                                    <input type="text" :value="formatCurrencyInput(item.technician_price)" @input="e => item.technician_price = parseCurrencyInput(e.target.value)" @focus="onCurrencyFocus" @blur="onCurrencyBlur(item, 'technician_price', $event)" class="w-full border-b border-dashed border-gray-400 py-1 text-right outline-none focus:border-purple-500 text-[13px] hover:bg-purple-50 font-medium tracking-wide">
                                </td>
                                <td class="p-3 w-[100px]">
                                    <input type="text" :value="formatCurrencyInput(item.discount)" @input="e => item.discount = parseCurrencyInput(e.target.value)" @focus="onCurrencyFocus" @blur="onCurrencyBlur(item, 'discount', $event)" class="w-full border-b border-dashed border-gray-400 py-1 text-right outline-none focus:border-green-500 text-[13px] hover:bg-green-50">
                                </td>
                                <td class="p-3 w-[80px] text-center">
                                    <input type="number" v-model.number="item.warranty_months" min="0" class="w-full border-b border-dashed border-gray-400 py-1 text-center outline-none focus:border-orange-500 text-[13px] hover:bg-orange-50" placeholder="0">
                                </td>
                                <td class="p-3 font-bold text-gray-800 text-right w-[140px] pr-6">{{ formatCurrency(getItemTotal(item)) }}</td>
                            </tr>
                            <!-- Serial/IMEI input row -->
                            <tr v-if="item.has_serial" class="bg-gray-50/50">
                                <td :colspan="9 + (showRetailPrice ? 1 : 0) + (showTechnicianPrice ? 1 : 0)" class="px-6 py-2">
                                    <div class="flex items-center gap-2 mb-2 flex-wrap">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path></svg>
                                        <input
                                            v-model="item.serialInput"
                                            @keydown.enter.prevent="addSerial(item)"
                                            type="text"
                                            class="flex-1 max-w-[280px] border border-gray-300 rounded px-2.5 py-1.5 text-[13px] outline-none focus:border-green-500 focus:ring-1 focus:ring-green-500"
                                            placeholder="Nhập số Serial/IMEI rồi nhấn Enter"
                                        >
                                        <select v-if="item.has_variants && item.variants && item.variants.length > 0"
                                            v-model="item.serialVariantId"
                                            class="max-w-[220px] border border-gray-300 rounded px-2 py-1.5 text-[12px] outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 bg-white">
                                            <option :value="null">-- Chọn biến thể --</option>
                                            <option v-for="v in item.variants" :key="v.id" :value="v.id">{{ v.name }}</option>
                                        </select>
                                        <button @click="addSerial(item)" class="text-green-600 hover:text-green-700 text-[12px] font-medium px-2 py-1 border border-green-300 rounded hover:bg-green-50">Thêm</button>
                                    </div>
                                    <div v-if="item.serials.length > 0" class="flex flex-wrap gap-1.5">
                                        <span v-for="(s, si) in item.serials" :key="si" class="inline-flex items-center gap-1 bg-blue-50 border border-blue-200 text-blue-700 text-[12px] font-medium px-2 py-0.5 rounded">
                                            {{ s.serial_number }}
                                            <span v-if="s.variant_id && item.variants" class="text-purple-600 text-[11px] font-normal">({{ getVariantName(item, s.variant_id) }})</span>
                                            <button @click="removeSerial(item, si)" class="text-blue-400 hover:text-red-500 ml-0.5">&times;</button>
                                        </span>
                                    </div>
                                    <div v-else class="text-[12px] text-gray-400 italic">Chưa nhập Serial/IMEI nào</div>
                                </td>
                            </tr>
                            </template>
                        </tbody>
                    </table>
                    
                    <div v-if="items.length === 0" class="h-full flex flex-col items-center justify-center min-h-[400px]">
                        <div class="text-center">
                            <h3 class="font-bold text-gray-800 text-[16px] mb-2 mt-12">Thêm sản phẩm từ phiếu đặt / file excel</h3>
                            <button class="bg-[#2ebc5b] hover:bg-[#209644] text-white font-semibold py-2 px-6 rounded shadow-sm text-[14px] flex items-center justify-center w-full max-w-[180px] mx-auto transition-colors">
                                <svg class="w-5 h-5 mr-2 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg> Chọn file dữ liệu
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Info -->
            <div class="w-[340px] flex-shrink-0 flex flex-col bg-white z-20 shadow-[-1px_0_5px_rgba(0,0,0,0.03)] border-l border-gray-200 relative overflow-visible">
                <div class="flex items-center justify-between p-3 border-b border-gray-200 bg-white">
                    <div class="flex items-center gap-2 px-1">
                        <div class="w-6 h-6 bg-gray-200 rounded-full flex items-center justify-center">
                            <svg class="w-4 h-4 text-gray-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path></svg>
                        </div>
                        <select v-model="selectedEmployeeId" class="text-[13px] text-gray-700 font-medium bg-transparent border-b border-dashed border-gray-300 outline-none focus:border-green-500 py-0.5 pr-4">
                            <option value="">-- Nhân viên nhập --</option>
                            <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.name }}</option>
                        </select>
                    </div>
                    <input type="datetime-local" v-model="purchaseDate" class="text-[13px] text-gray-500 bg-transparent border-b border-dashed border-gray-300 outline-none focus:border-green-500 py-0.5 w-[170px]" />
                </div>

                <div class="flex-1 overflow-auto bg-white flex flex-col pt-2">
                    <div class="px-3 pb-3">
                        <div class="relative mb-3 z-30">
                            <div class="flex items-center border-b border-gray-300 pb-1 relative">
                                <svg class="w-4 h-4 text-gray-400 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                                
                                <div class="flex-1 relative">
                                    <!-- Search Input -->
                                    <input 
                                        v-if="!selectedSupplier"
                                        type="text" 
                                        v-model="supplierSearchQuery" 
                                        @focus="showSupplierDropdown = true"
                                        @blur="hideSupplierDropdown"
                                        placeholder="Tìm tên, mã, số điện thoại..." 
                                        class="w-full py-1 outline-none text-[13px] text-gray-800 bg-transparent placeholder-gray-500 font-medium"
                                    />
                                    
                                    <!-- Selected Display -->
                                    <div v-else class="flex items-center justify-between w-full py-1 pr-1 bg-blue-50/50 rounded-sm">
                                        <div class="flex items-center gap-2 line-clamp-1 px-1">
                                            <span class="text-[13px] font-bold text-blue-700">{{ selectedSupplier.name }}</span>
                                            <span v-if="selectedSupplier.phone" class="text-[12px] text-gray-500 bg-white px-1 rounded border border-gray-200">{{ selectedSupplier.phone }}</span>
                                        </div>
                                        <button type="button" @click="removeSupplier" class="text-gray-400 hover:text-red-500 py-0.5 px-1 ml-1" title="Xóa chọn">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                        </button>
                                    </div>

                                    <!-- Autocomplete Dropdown -->
                                    <div v-if="showSupplierDropdown && !selectedSupplier" class="absolute left-0 top-full mt-1 bg-white border border-gray-200 shadow-xl rounded-sm z-50 max-h-64 overflow-y-auto w-full">
                                        <div v-if="isSearchingSupplier" class="p-3 text-sm text-gray-500 text-center">
                                            Đang tìm kiếm...
                                        </div>
                                        <div v-else-if="filteredSuppliers.length === 0 && supplierSearchQuery" class="p-3 text-center text-gray-500 text-[12px]">
                                            Không tìm thấy NCC nào
                                        </div>
                                        <div 
                                            v-for="sup in filteredSuppliers" 
                                            :key="sup.id" 
                                            @mousedown.prevent="selectSupplier(sup)" 
                                            class="p-2.5 cursor-pointer hover:bg-blue-50 border-b border-gray-100 last:border-b-0"
                                        >
                                            <div class="font-bold text-[13px] text-gray-800">{{ sup.name }}</div>
                                            <div class="text-[12px] text-gray-500 flex items-center gap-2 mt-0.5">
                                                <span>{{ sup.code || '---' }}</span>
                                                <span v-if="sup.phone">&bull; {{ sup.phone }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <button type="button" @click="showCreateSupplierModal = true" class="text-green-600 hover:text-green-700 font-bold text-lg leading-none ml-2 shrink-0" title="Thêm nhà cung cấp">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                                </button>
                            </div>
                        </div>

                        <div class="space-y-3.5 mt-4">
                            <div class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium">Mã nhập hàng</label>
                                <input type="text" :value="purchaseCode" disabled class="w-[150px] text-right border-b border-transparent px-1 py-0.5 outline-none text-gray-500 bg-transparent" placeholder="Mã tự động">
                            </div>

                            <div class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium">Trạng thái</label>
                                <select v-model="status" class="w-[150px] border border-gray-300 rounded px-2 py-1 outline-none text-gray-700 focus:border-green-500 bg-green-50 font-medium">
                                    <option value="draft">Phiếu tạm</option>
                                    <option value="completed">Đã nhập hàng</option>
                                </select>
                            </div>

                            <div class="flex justify-between items-center text-[13px] pt-1">
                                <label class="text-gray-700 font-medium flex items-center gap-1">Tổng tiền hàng <span class="bg-gray-100 text-gray-500 px-1.5 rounded text-[11px] font-bold border border-gray-200">{{ items.length }}</span></label>
                                <div class="w-[150px] text-right font-bold text-gray-800 tracking-wide text-[15px]">{{ formatCurrency(totalAmount) }}</div>
                            </div>

                            <div class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium">Giảm giá</label>
                                <input type="text" :value="formatCurrencyInput(discount)" @input="e => discount = parseCurrencyInput(e.target.value)" @focus="onCurrencyFocus" @blur="(e) => { discount = parseCurrencyInput(e.target.value); e.target.value = formatCurrencyInput(discount); }" class="w-[150px] border-b border-dashed border-gray-300 text-right pr-2 py-0.5 outline-none focus:border-green-500 hover:bg-green-50">
                            </div>

                            <!-- Chi phí nhập khác -->
                            <div class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium">Chi phí nhập khác</label>
                                <button @click="showOtherCosts = !showOtherCosts" class="flex items-center gap-1 text-blue-600 hover:text-blue-700 font-medium">
                                    <span>→</span>
                                    <span>{{ formatCurrency(totalOtherCosts) }}</span>
                                </button>
                            </div>
                            <div v-if="showOtherCosts" class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-[13px] space-y-2">
                                <div v-for="(cost, ci) in otherCosts" :key="cost.id" class="flex items-center gap-2">
                                    <input type="text" v-model="cost.name" class="flex-1 border border-gray-300 rounded px-2 py-1 text-[12px] outline-none focus:border-green-500" />
                                    <input type="text" :value="formatCurrencyInput(cost.amount)" @focus="onCurrencyFocus" @blur="onCurrencyBlur(cost, 'amount', $event)" class="w-[100px] border border-gray-300 rounded px-2 py-1 text-right text-[12px] outline-none focus:border-green-500" />
                                    <button @click="removeOtherCost(ci)" class="text-red-400 hover:text-red-600 text-sm">✕</button>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input type="text" v-model="newCostName" @keydown.enter="addOtherCost" class="flex-1 border border-gray-300 rounded px-2 py-1 text-[12px] outline-none focus:border-green-500" placeholder="Tên chi phí (VD: Ship hàng)" />
                                    <input type="text" :value="formatCurrencyInput(newCostAmount)" @focus="onCurrencyFocus" @blur="(e) => { newCostAmount = parseCurrencyInput(e.target.value); e.target.value = formatCurrencyInput(newCostAmount); }" class="w-[100px] border border-gray-300 rounded px-2 py-1 text-right text-[12px] outline-none focus:border-green-500" placeholder="Số tiền" />
                                    <button @click="addOtherCost" class="text-green-600 hover:text-green-700 text-sm font-bold">+</button>
                                </div>
                            </div>

                            <div class="flex justify-between items-center text-[13px] pt-2">
                                <label class="text-gray-800 font-bold">Cần trả nhà cung cấp</label>
                                <div class="w-[150px] text-right font-bold text-green-600 tracking-wide text-lg">{{ formatCurrency(totalPayment) }}</div>
                            </div>

                            <div class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium">Tiền trả nhà cung cấp</label>
                                <input type="text" :value="formatCurrencyInput(paidAmount)" @input="e => { isPaidAmountEdited = true; paidAmount = parseCurrencyInput(e.target.value); }" @focus="onCurrencyFocus" @blur="(e) => { isPaidAmountEdited = true; paidAmount = parseCurrencyInput(e.target.value); e.target.value = formatCurrencyInput(paidAmount); }" class="w-[150px] border-b border-gray-400 text-right pr-2 py-0.5 outline-none focus:border-green-500 hover:bg-green-50 font-bold text-blue-600">
                            </div>

                            <div class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium text-gray-500">Tính vào công nợ</label>
                                 <div class="w-[150px] text-right font-bold text-gray-500 tracking-wide">{{ formatCurrency(debtAmount) }}</div>
                            </div>

                            <!-- Chi phí phụ (ship) ghi nhận riêng -->
                            <div v-if="totalOtherCosts > 0" class="flex justify-between items-center text-[13px] pt-1 border-t border-dashed border-gray-200 mt-1">
                                <label class="text-gray-500 italic flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    Chi phí khác (tạo phiếu chi riêng)
                                </label>
                                <div class="w-[150px] text-right font-medium text-orange-600">{{ formatCurrency(totalOtherCosts) }}</div>
                            </div>

                            <!-- Payment Method -->
                            <div class="pt-3 border-t border-gray-200 mt-2">
                                <label class="block text-[13px] text-gray-700 font-medium mb-2">Phương thức thanh toán</label>
                                <div class="flex items-center gap-4 text-[13px]">
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="radio" v-model="paymentMethod" value="cash" class="text-green-600 focus:ring-green-500 w-4 h-4" />
                                        <span>Tiền mặt</span>
                                    </label>
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="radio" v-model="paymentMethod" value="transfer" class="text-blue-600 focus:ring-blue-500 w-4 h-4" />
                                        <span>Chuyển khoản</span>
                                    </label>
                                </div>
                                <div v-if="paymentMethod === 'transfer'" class="mt-2">
                                    <select v-model="bankAccountInfo" class="w-full border border-gray-300 rounded px-2 py-1.5 text-[13px] outline-none focus:border-blue-500 bg-white">
                                        <option value="">-- Chọn tài khoản ngân hàng --</option>
                                        <option v-for="ba in bankAccounts" :key="ba.id" :value="ba.bank_name + ' - ' + ba.account_number + ' - ' + ba.account_holder">
                                            {{ ba.bank_name }} - {{ ba.account_number }} ({{ ba.account_holder }})
                                        </option>
                                    </select>
                                    <input v-if="!bankAccounts?.length" type="text" v-model="bankAccountInfo" class="w-full border border-gray-300 rounded px-2 py-1.5 text-[13px] outline-none focus:border-blue-500 mt-1" placeholder="Nhập số tài khoản ngân hàng" />
                                </div>
                            </div>

                            <div class="pt-2">
                                <div class="flex items-center border border-gray-300 rounded focus-within:border-green-500 p-2 text-[13px] bg-white text-gray-600 shadow-inner">
                                     <input type="text" v-model="note" placeholder="Ghi chú đơn nhập" class="w-full outline-none bg-transparent">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Button -->
                <div class="p-4 bg-white border-t border-gray-200 shadow-[0_-2px_10px_rgba(0,0,0,0.05)]">
                    <button @click="save" :disabled="submitRef" class="w-full bg-[#2ebc5b] hover:bg-[#209644] text-white font-bold py-3 rounded text-[15px] uppercase tracking-wide transition-colors flex justify-center items-center gap-2 disabled:opacity-50">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg> Hoàn thành
                    </button>
                    <div class="mt-2 text-center">
                        <Link href="/purchases" class="text-gray-500 hover:text-gray-800 text-[13px] underline">Hủy bỏ</Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Create Supplier Modal -->
        <QuickCreateCustomerModal
            :show="showCreateSupplierModal"
            api-url="/api/suppliers/quick-store"
            entity-label="nhà cung cấp"
            :is-supplier="true"
            @close="showCreateSupplierModal = false"
            @created="onSupplierCreated"
        />

        <!-- Quick Create Product Modal -->
        <div v-if="showQuickProductModal" class="fixed inset-0 z-[100] flex items-center justify-center">
            <div class="absolute inset-0 bg-black/40" @click="showQuickProductModal = false"></div>
            <div class="relative bg-white w-[520px] rounded-lg shadow-2xl z-10 max-h-[90vh] overflow-hidden flex flex-col">
                <!-- Header -->
                <div class="px-5 py-3.5 border-b flex justify-between items-center flex-shrink-0">
                    <h3 class="font-bold text-[16px] text-gray-800">
                        <svg class="w-5 h-5 inline mr-1.5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        Tạo nhanh hàng hóa
                    </h3>
                    <button @click="showQuickProductModal = false" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>

                <!-- Body -->
                <div class="flex-1 overflow-y-auto p-5 space-y-4">
                    <!-- Tên SP -->
                    <div>
                        <label class="block text-[13px] font-semibold text-gray-700 mb-1">Tên sản phẩm <span class="text-red-500">*</span></label>
                        <input v-model="quickProductForm.name" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-[14px] outline-none focus:border-green-500 focus:ring-1 focus:ring-green-500" placeholder="Nhập tên sản phẩm..." autofocus>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <!-- Mã SKU -->
                        <div>
                            <label class="block text-[13px] font-semibold text-gray-700 mb-1">Mã hàng (SKU)</label>
                            <input v-model="quickProductForm.sku" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-[13px] outline-none focus:border-green-500" placeholder="Tự động nếu để trống">
                        </div>
                        <!-- Barcode -->
                        <div>
                            <label class="block text-[13px] font-semibold text-gray-700 mb-1">Mã vạch</label>
                            <input v-model="quickProductForm.barcode" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-[13px] outline-none focus:border-green-500" placeholder="Tự động">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <!-- Nhóm hàng -->
                        <div>
                            <label class="block text-[13px] font-semibold text-gray-700 mb-1">Nhóm hàng</label>
                            <select v-model="quickProductForm.category_id" class="w-full border border-gray-300 rounded px-3 py-2 text-[13px] outline-none focus:border-green-500">
                                <option value="">-- Chọn nhóm --</option>
                                <template v-for="cat in flattenedCategories" :key="cat.id">
                                    <option :value="cat.id" :class="cat.level === 1 ? 'pl-4' : cat.level === 2 ? 'pl-8' : ''">
                                        {{ cat.level === 1 ? '\u00a0\u00a0\u251c\u00a0' : cat.level === 2 ? '\u00a0\u00a0\u00a0\u00a0\u2514\u00a0' : '' }}{{ cat.name }}
                                    </option>
                                </template>
                            </select>
                        </div>
                        <!-- Thương hiệu -->
                        <div>
                            <label class="block text-[13px] font-semibold text-gray-700 mb-1">Thương hiệu</label>
                            <select v-model="quickProductForm.brand_id" class="w-full border border-gray-300 rounded px-3 py-2 text-[13px] outline-none focus:border-green-500">
                                <option value="">-- Chọn thương hiệu --</option>
                                <option v-for="brand in brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <!-- Giá nhập -->
                        <div>
                            <label class="block text-[13px] font-semibold text-gray-700 mb-1">Giá nhập (vốn)</label>
                            <input v-model.number="quickProductForm.cost_price" type="number" min="0" class="w-full border border-gray-300 rounded px-3 py-2 text-[13px] outline-none focus:border-green-500 text-right" placeholder="0">
                        </div>
                        <!-- Giá bán -->
                        <div>
                            <label class="block text-[13px] font-semibold text-gray-700 mb-1">Giá bán lẻ</label>
                            <input v-model.number="quickProductForm.retail_price" type="number" min="0" class="w-full border border-gray-300 rounded px-3 py-2 text-[13px] outline-none focus:border-green-500 text-right" placeholder="0">
                        </div>
                        <!-- Giá thợ -->
                        <div>
                            <label class="block text-[13px] font-semibold text-gray-700 mb-1">Giá thợ</label>
                            <input v-model.number="quickProductForm.technician_price" type="number" min="0" class="w-full border border-gray-300 rounded px-3 py-2 text-[13px] outline-none focus:border-green-500 text-right" placeholder="0">
                        </div>
                    </div>

                    <!-- Serial toggle -->
                    <div class="flex items-center justify-between p-3 bg-orange-50 border border-orange-200 rounded-lg">
                        <div>
                            <div class="text-[13px] font-semibold text-gray-800">Quản lý theo Serial/IMEI</div>
                            <div class="text-[12px] text-gray-500">Bật nếu sản phẩm cần quản lý từng số Serial</div>
                        </div>
                        <button type="button" @click="quickProductForm.has_serial = !quickProductForm.has_serial"
                            :class="quickProductForm.has_serial ? 'bg-green-500' : 'bg-gray-300'"
                            class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200">
                            <span :class="quickProductForm.has_serial ? 'translate-x-5' : 'translate-x-0'"
                                class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 mt-0.5 ml-0.5"></span>
                        </button>
                    </div>
                </div>

                <!-- Footer -->
                <div class="px-5 py-3 border-t flex justify-end gap-2 flex-shrink-0 bg-gray-50">
                    <button @click="showQuickProductModal = false" class="px-5 py-2 text-[13px] text-gray-700 border border-gray-300 rounded hover:bg-gray-100 font-medium">Bỏ qua</button>
                    <button @click="saveQuickProduct" :disabled="isSavingProduct" class="px-5 py-2 text-[13px] text-white bg-green-600 hover:bg-green-700 rounded font-medium disabled:opacity-50 flex items-center gap-1.5">
                        <svg v-if="isSavingProduct" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        Lưu & Thêm vào đơn
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
