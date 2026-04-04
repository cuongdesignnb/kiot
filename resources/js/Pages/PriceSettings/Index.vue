<script setup>
import { ref, watch, onMounted, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SortableHeader from '@/Components/SortableHeader.vue';
import axios from 'axios';

const props = defineProps({
    products: Object,
    categories: Array,
    priceBooks: Array,
    branches: Array,
    filters: Object,
});

const search = ref(props.filters.search || '');
const categoryId = ref(props.filters.category_id || '');
const stockFilter = ref(props.filters.stock_filter || '');
const priceCondition = ref(props.filters.price_condition || '');
const priceValue = ref(props.filters.price_value || '');
const activePriceBookId = ref(props.filters.price_book_id || null);
const comparePrice = ref('');
const sortBy = ref(props.filters.sort_by || '');
const sortDirection = ref(props.filters.sort_direction || '');

// Local state for instant updating
const localProducts = ref([]);

const syncProducts = () => {
    localProducts.value = props.products.data.map(p => ({
        ...p,
        isUpdating: false,
        updateSuccess: false,
        editPrice: activePriceBookId.value ? (p.book_price ?? '') : p.retail_price,
        editRetailPrice: activePriceBookId.value ? (p.book_retail_price ?? '') : '',
        editTechnicianPrice: activePriceBookId.value ? (p.book_technician_price ?? '') : (p.technician_price ?? ''),
        originalEditPrice: activePriceBookId.value ? (p.book_price ?? '') : p.retail_price,
        originalEditRetailPrice: activePriceBookId.value ? (p.book_retail_price ?? '') : '',
        originalEditTechnicianPrice: activePriceBookId.value ? (p.book_technician_price ?? '') : (p.technician_price ?? ''),
    }));
};

onMounted(syncProducts);
watch(() => props.products, syncProducts, { deep: true });

let fetchTimeout = null;
const fetchFiltered = () => {
    if (fetchTimeout) clearTimeout(fetchTimeout);
    fetchTimeout = setTimeout(() => {
        router.get('/price-settings', {
            search: search.value,
            category_id: categoryId.value,
            stock_filter: stockFilter.value,
            price_condition: priceCondition.value,
            price_value: priceValue.value,
            price_book_id: activePriceBookId.value || undefined,
            sort_by: sortBy.value || undefined,
            sort_direction: sortDirection.value || undefined,
        }, { preserveState: true, preserveScroll: true, replace: true });
    }, 500);
};

watch([search, categoryId, stockFilter, priceCondition], fetchFiltered);

const handleSort = (field, direction) => {
    sortBy.value = field;
    sortDirection.value = direction;
    fetchFiltered();
};

// Switch price book
const switchPriceBook = (id) => {
    activePriceBookId.value = id;
    fetchFiltered();
};

const activePriceBook = computed(() => {
    if (!activePriceBookId.value) return null;
    return props.priceBooks?.find(b => b.id == activePriceBookId.value);
});

const activePriceBookName = computed(() => {
    return activePriceBook.value?.name || 'Giá bán lẻ';
});

const canEditActivePriceBook = computed(() => {
    return Boolean(activePriceBook.value);
});

const handleEditActivePriceBook = () => {
    if (activePriceBook.value) {
        openEditPriceBook(activePriceBook.value);
        return;
    }

    if (!props.priceBooks?.length) {
        alert('Chưa có bảng giá tự tạo để sửa. Hãy bấm "Tạo mới" trước.');
        return;
    }

    alert('Hãy chọn một bảng giá tự tạo ở cột bên trái, rồi bấm "Sửa bảng giá".');
};

const showRetailColumn = computed(() => {
    if (!activePriceBookId.value) return false;
    return Boolean(activePriceBook.value?.enable_retail_price);
});

const showTechnicianColumn = computed(() => {
    if (!activePriceBookId.value) return true;
    return Boolean(activePriceBook.value?.enable_technician_price);
});

const totalColumns = computed(() => {
    return 5 + (showRetailColumn.value ? 1 : 0) + (showTechnicianColumn.value ? 1 : 0);
});

const normalizePriceValue = (value) => {
    if (value === '' || value === null || value === undefined) {
        return 0;
    }

    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : 0;
    }

    let normalized = String(value).trim().replace(/\s+/g, '');

    // Handle formats like 1.234.567,89 and 1234567,89
    if (normalized.includes(',') && normalized.includes('.')) {
        normalized = normalized.replace(/\./g, '').replace(',', '.');
    } else if (normalized.includes(',')) {
        normalized = normalized.replace(',', '.');
    }

    normalized = normalized.replace(/[^0-9.-]/g, '');
    const parsed = parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : 0;
};

const isPriceUnchanged = (newValue, oldValue) => {
    const normalizeComparable = (val) => {
        if (val === '' || val === null || val === undefined) {
            return null;
        }
        return Number(normalizePriceValue(val).toFixed(2));
    };

    return normalizeComparable(newValue) === normalizeComparable(oldValue);
};

// Update price
const updatePrice = async (product, index) => {
    if (isPriceUnchanged(product.editPrice, product.originalEditPrice)) {
        return;
    }

    localProducts.value[index].isUpdating = true;
    localProducts.value[index].updateSuccess = false;

    const normalizedPrice = normalizePriceValue(product.editPrice);

    try {
        if (activePriceBookId.value) {
            await axios.put(`/price-settings/price-books/${activePriceBookId.value}/products/${product.id}`, {
                price: normalizedPrice
            });
        } else {
            await axios.put(`/price-settings/${product.id}`, {
                retail_price: normalizedPrice
            });
        }

        localProducts.value[index].editPrice = normalizedPrice;
        localProducts.value[index].originalEditPrice = normalizedPrice;

        localProducts.value[index].updateSuccess = true;
        setTimeout(() => {
            if (localProducts.value[index]) localProducts.value[index].updateSuccess = false;
        }, 2000);
    } catch (error) {
        alert('Có lỗi xảy ra khi cập nhật giá!');
    } finally {
        localProducts.value[index].isUpdating = false;
    }
};

const updateOptionalBookPrice = async (product, index, field) => {
    const editField = field === 'retail_price' ? 'editRetailPrice' : 'editTechnicianPrice';
    const originalField = field === 'retail_price' ? 'originalEditRetailPrice' : 'originalEditTechnicianPrice';

    if (isPriceUnchanged(product[editField], product[originalField])) {
        return;
    }

    localProducts.value[index].isUpdating = true;
    localProducts.value[index].updateSuccess = false;

    const normalizedValue = normalizePriceValue(product[editField]);

    try {
        if (activePriceBookId.value) {
            await axios.put(`/price-settings/price-books/${activePriceBookId.value}/products/${product.id}`, {
                [field]: normalizedValue
            });
        } else {
            await axios.put(`/price-settings/${product.id}`, {
                [field]: normalizedValue
            });
        }

        localProducts.value[index][editField] = normalizedValue;
        localProducts.value[index][originalField] = normalizedValue;

        localProducts.value[index].updateSuccess = true;
        setTimeout(() => {
            if (localProducts.value[index]) localProducts.value[index].updateSuccess = false;
        }, 2000);
    } catch (error) {
        alert('Có lỗi xảy ra khi cập nhật giá!');
    } finally {
        localProducts.value[index].isUpdating = false;
    }
};

const formatCurrency = (value) => {
    if (!value && value !== 0) return '';
    return Number(value).toLocaleString('vi-VN');
};

// Export / Import
const exportFile = () => {
    const query = new URLSearchParams({
        search: search.value,
        category_id: categoryId.value,
        stock_filter: stockFilter.value,
        price_condition: priceCondition.value,
        price_value: priceValue.value,
        price_book_id: activePriceBookId.value || '',
    }).toString();
    window.location.href = `/price-settings/export?${query}`;
};

const fileInput = ref(null);
const triggerFileSelect = () => fileInput.value.click();
const handleFileUpload = (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const formData = new FormData();
    formData.append('file', file);
    if (activePriceBookId.value) formData.append('price_book_id', activePriceBookId.value);
    router.post('/price-settings/import', formData, {
        preserveScroll: true,
        onSuccess: () => alert('Import file thành công!')
    });
};

// ============ Formula Modal ============
const isFormulaModalOpen = ref(false);
const formulaForm = ref({
    base_field: 'cost_price',
    operator: '+',
    value: 0,
    is_percent: false,
    product_ids: [],
    price_book_id: null,
});
const selectedProductName = ref('');

const openFormulaModal = (product = null) => {
    isFormulaModalOpen.value = true;
    formulaForm.value.value = 0;
    formulaForm.value.is_percent = false;
    formulaForm.value.price_book_id = activePriceBookId.value;
    if (product) {
        formulaForm.value.product_ids = [product.id];
        selectedProductName.value = product.name;
    } else {
        formulaForm.value.product_ids = props.products.data.map(p => p.id);
        selectedProductName.value = `Tất cả ${props.products.data.length} hàng hoá trên trang này`;
    }
};

const applyFormula = () => {
    router.post('/price-settings/apply-formula', formulaForm.value, {
        preserveScroll: true,
        onSuccess: () => { isFormulaModalOpen.value = false; }
    });
};

// ============ Price Book Modal (Create/Edit) ============
const isPriceBookModalOpen = ref(false);
const pbModalTab = ref('info');
const editingPriceBook = ref(null);

const getLocalIsoTime = (d = new Date()) => {
    const date = new Date(d);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};

const pbForm = ref({
    name: '',
    start_date: '',
    end_date: '',
    status: 'active',
    formula_base: '',
    formula_operator: '+',
    formula_value: 0,
    formula_is_percent: false,
    scope_branch: 'all',
    branch_ids: [],
    scope_customer_group: 'all',
    customer_group_ids: [],
    cashier_rule: 'allow_add',
    cashier_warn_not_in_book: false,
    enable_retail_price: false,
    enable_technician_price: false,
});

const resetPbForm = () => {
    pbForm.value = {
        name: '',
        start_date: getLocalIsoTime(),
        end_date: getLocalIsoTime(Date.now() + 365 * 86400000),
        status: 'active',
        formula_base: '',
        formula_operator: '+',
        formula_value: 0,
        formula_is_percent: false,
        scope_branch: 'all',
        branch_ids: [],
        scope_customer_group: 'all',
        customer_group_ids: [],
        cashier_rule: 'allow_add',
        cashier_warn_not_in_book: false,
        enable_retail_price: false,
        enable_technician_price: false,
    };
    pbModalTab.value = 'info';
    editingPriceBook.value = null;
};

const openCreatePriceBook = () => {
    resetPbForm();
    isPriceBookModalOpen.value = true;
};

const openEditPriceBook = (pb) => {
    editingPriceBook.value = pb;
    pbForm.value = {
        name: pb.name,
        start_date: pb.start_date ? pb.start_date.slice(0, 16) : '',
        end_date: pb.end_date ? pb.end_date.slice(0, 16) : '',
        status: pb.status || 'active',
        formula_base: pb.formula_base || '',
        formula_operator: pb.formula_operator || '+',
        formula_value: pb.formula_value || 0,
        formula_is_percent: pb.formula_is_percent || false,
        scope_branch: pb.scope_branch || 'all',
        branch_ids: pb.branch_ids || [],
        scope_customer_group: pb.scope_customer_group || 'all',
        customer_group_ids: pb.customer_group_ids || [],
        cashier_rule: pb.cashier_rule || 'allow_add',
        cashier_warn_not_in_book: pb.cashier_warn_not_in_book || false,
        enable_retail_price: pb.enable_retail_price || false,
        enable_technician_price: pb.enable_technician_price || false,
    };
    pbModalTab.value = 'info';
    isPriceBookModalOpen.value = true;
};

const submitPriceBook = () => {
    if (!pbForm.value.name) {
        alert('Vui lòng nhập tên bảng giá');
        return;
    }
    if (editingPriceBook.value) {
        router.put(`/price-settings/price-books/${editingPriceBook.value.id}`, pbForm.value, {
            preserveScroll: true,
            onSuccess: () => { isPriceBookModalOpen.value = false; }
        });
    } else {
        router.post('/price-settings/price-books', pbForm.value, {
            preserveScroll: true,
            onSuccess: () => { isPriceBookModalOpen.value = false; }
        });
    }
};

const deletePriceBook = (pb) => {
    if (confirm(`Bạn có chắc muốn xóa bảng giá "${pb.name}"?`)) {
        router.delete(`/price-settings/price-books/${pb.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                if (activePriceBookId.value == pb.id) {
                    activePriceBookId.value = null;
                    fetchFiltered();
                }
            }
        });
    }
};

const deselectPriceBook = (id) => {
    if (activePriceBookId.value == id) {
        activePriceBookId.value = null;
        fetchFiltered();
    }
};

// Formula base options for price book modal
const formulaBaseOptions = computed(() => {
    const opts = [
        { value: '', label: 'Chọn bảng giá' },
        { value: 'cost_price', label: 'Giá vốn' },
        { value: 'last_purchase_price', label: 'Giá nhập cuối' },
        { value: 'retail_price', label: 'Bảng giá chung' },
    ];
    if (props.priceBooks) {
        props.priceBooks.forEach(pb => {
            if (!editingPriceBook.value || pb.id !== editingPriceBook.value.id) {
                opts.push({ value: String(pb.id), label: pb.name });
            }
        });
    }
    return opts;
});
</script>

<template>
    <Head title="Thiết lập giá" />
    <AppLayout>
        <div class="h-full flex gap-0">
            <!-- Sidebar -->
            <div class="w-[230px] flex-shrink-0 bg-white border-r border-gray-200 flex flex-col">
                <!-- Bảng giá header -->
                <div class="p-3 border-b border-gray-200">
                    <div class="flex justify-between items-center mb-2.5">
                        <span class="font-bold text-[13px] text-gray-800">Bảng giá</span>
                        <button @click="openCreatePriceBook" class="text-[12px] text-blue-600 hover:text-blue-800 font-semibold">Tạo mới</button>
                    </div>
                    <!-- Default: Bảng giá chung -->
                    <div
                        class="text-[13px] px-3 py-1.5 rounded flex justify-between items-center cursor-pointer mb-1.5 transition-colors"
                        :class="!activePriceBookId ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                        @click="switchPriceBook(null)"
                    >
                        <span class="font-medium truncate">Bảng giá chung</span>
                        <svg v-if="!activePriceBookId" class="w-3.5 h-3.5 ml-1 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </div>
                    <!-- Custom price books -->
                    <template v-for="pb in priceBooks" :key="pb.id">
                        <div
                            class="text-[13px] px-3 py-1.5 rounded flex justify-between items-center cursor-pointer mb-1 transition-colors group"
                            :class="activePriceBookId == pb.id ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                            @click="switchPriceBook(pb.id)"
                        >
                            <span class="font-medium truncate flex-1" :title="pb.name">{{ pb.name }}</span>
                            <div class="flex items-center gap-1 flex-shrink-0 ml-1">
                                <button @click.stop="openEditPriceBook(pb)" class="transition-opacity opacity-80 hover:opacity-100" :class="activePriceBookId == pb.id ? 'text-white hover:text-white' : 'text-gray-500 hover:text-gray-700'" title="Sửa bảng giá">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                </button>
                                <button v-if="activePriceBookId == pb.id" @click.stop="deselectPriceBook(pb.id)" class="text-white/80 hover:text-white">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                </button>
                                <button v-else @click.stop="deletePriceBook(pb)" class="opacity-70 hover:opacity-100 text-gray-500 hover:text-red-500 transition-opacity" title="Xóa bảng giá">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                </button>
                            </div>
                        </div>
                    </template>
                    <div v-if="!priceBooks?.length" class="mt-2 text-[12px] text-gray-500">
                        Chưa có bảng giá tự tạo. Bấm "Tạo mới" để thêm.
                    </div>
                </div>

                <!-- Filters -->
                <div class="p-3 flex flex-col gap-3.5 overflow-y-auto flex-1">
                    <div>
                        <label class="block text-[13px] font-bold text-gray-800 mb-1">Nhóm hàng</label>
                        <select v-model="categoryId" class="w-full border border-gray-300 rounded p-1.5 text-[13px] outline-none focus:border-blue-500 text-gray-700">
                            <option value="">Chọn nhóm hàng</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[13px] font-bold text-gray-800 mb-1">Tồn kho</label>
                        <select v-model="stockFilter" class="w-full border border-gray-300 rounded p-1.5 text-[13px] outline-none focus:border-blue-500 text-gray-700">
                            <option value="">Tất cả</option>
                            <option value="in_stock">Còn hàng ( > 0 )</option>
                            <option value="out_of_stock">Hết hàng ( &lt;= 0 )</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[13px] font-bold text-gray-800 mb-1">Giá bán</label>
                        <select v-model="priceCondition" class="w-full border border-gray-300 rounded p-1.5 text-[13px] outline-none focus:border-blue-500 text-gray-700 mb-1.5">
                            <option value="">Chọn điều kiện</option>
                            <option value=">">Lớn hơn ( > )</option>
                            <option value=">=">Lớn hơn hoặc bằng</option>
                            <option value="<">Nhỏ hơn ( &lt; )</option>
                            <option value="<=">Nhỏ hơn hoặc bằng</option>
                            <option value="=">Bằng ( = )</option>
                        </select>
                        <select v-model="comparePrice" class="w-full border border-gray-300 rounded p-1.5 text-[13px] outline-none focus:border-blue-500 text-gray-700">
                            <option value="">Chọn giá so sánh</option>
                            <option value="cost_price">Giá vốn</option>
                            <option value="last_purchase_price">Giá nhập cuối</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Content -->
            <div class="flex-1 bg-white flex flex-col min-w-0">
                <!-- Top Bar -->
                <div class="px-4 py-3 border-b border-gray-200 flex justify-between items-center">
                    <h1 class="text-[18px] font-bold text-gray-800">{{ activePriceBookName }}</h1>
                    <div class="flex gap-2">
                        <button
                            @click="handleEditActivePriceBook"
                            class="px-3 py-1.5 text-[13px] font-medium rounded flex items-center gap-1.5"
                            :class="canEditActivePriceBook ? 'text-amber-700 bg-amber-50 border border-amber-300 hover:bg-amber-100' : 'text-gray-500 bg-gray-100 border border-gray-300 hover:bg-gray-200'"
                            :title="canEditActivePriceBook ? 'Sửa bảng giá đang chọn' : 'Đang chọn Bảng giá chung, vui lòng chọn bảng giá tự tạo để sửa'"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                            Sửa bảng giá
                        </button>
                        <button @click="openFormulaModal(null)" class="px-3 py-1.5 text-[13px] font-medium text-blue-600 bg-white border border-blue-600 rounded hover:bg-blue-50 flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                            Công thức chung
                        </button>
                        <input type="file" class="hidden" ref="fileInput" @change="handleFileUpload" accept=".csv,.txt">
                        <button @click="triggerFileSelect" class="px-3 py-1.5 text-[13px] font-medium text-gray-700 bg-white border border-gray-300 rounded hover:bg-gray-50 flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>Import
                        </button>
                        <button @click="exportFile" class="px-3 py-1.5 text-[13px] font-medium text-gray-700 bg-white border border-gray-300 rounded hover:bg-gray-50 flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>Xuất file
                        </button>
                    </div>
                </div>

                <!-- Search Row -->
                <div class="px-4 py-2 bg-white border-b border-gray-100">
                    <div class="relative w-[350px]">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        </div>
                        <input v-model="search" type="text" class="block w-full pl-9 pr-3 py-1.5 text-[13px] border border-gray-300 rounded outline-none focus:border-blue-500 placeholder:text-gray-400" placeholder="Theo mã, tên hàng">
                    </div>
                </div>

                <!-- Table -->
                <div class="flex-1 overflow-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-[#f2f9ff] text-[#005fb8] text-[13px] font-bold sticky top-0 z-10 shadow-[0_1px_0_rgba(200,200,200,0.5)]">
                            <tr>
                                <SortableHeader label="Mã hàng" field="sku" :current-sort="sortBy" :current-direction="sortDirection" class="p-3 whitespace-nowrap border-r border-[#e1eaf5] font-semibold" style="width:140px" @sort="handleSort" />
                                <SortableHeader label="Tên hàng" field="name" :current-sort="sortBy" :current-direction="sortDirection" class="p-3 border-r border-[#e1eaf5] font-semibold" @sort="handleSort" />
                                <SortableHeader label="Giá vốn" field="cost_price" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="p-3 text-right whitespace-nowrap border-r border-[#e1eaf5] font-semibold" style="width:130px" @sort="handleSort" />
                                <SortableHeader label="Giá nhập cuối" field="last_purchase_price" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="p-3 text-right whitespace-nowrap border-r border-[#e1eaf5] font-semibold" style="width:130px" @sort="handleSort" />
                                <SortableHeader label="Giá bán" field="retail_price" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="p-3 text-right whitespace-nowrap font-semibold pr-6" style="width:160px" @sort="handleSort" />
                                <th v-if="showRetailColumn" class="p-3 text-right whitespace-nowrap font-semibold pr-6" style="width:160px">
                                    Giá lẻ
                                </th>
                                <th v-if="showTechnicianColumn" class="p-3 text-right whitespace-nowrap font-semibold pr-6" style="width:160px">
                                    Giá thợ
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="localProducts.length === 0">
                                <td :colspan="totalColumns" class="p-8 text-center text-gray-400 font-medium text-[13px]">Không tìm thấy hàng hóa nào.</td>
                            </tr>
                            <tr
                                v-for="(product, index) in localProducts"
                                :key="product.id"
                                class="hover:bg-[#f0f9ff]/50 border-b border-dashed border-gray-200 text-[13px] text-gray-800 transition-colors"
                            >
                                <td class="p-2.5 font-medium text-blue-600 truncate" :title="product.sku">{{ product.sku }}</td>
                                <td class="p-2.5">
                                    <div class="font-bold text-gray-800 truncate" :title="product.name">{{ product.name }}</div>
                                </td>
                                <td class="p-2.5 text-right font-medium text-gray-700">{{ formatCurrency(product.cost_price) }}</td>
                                <td class="p-2.5 text-right font-medium text-gray-700">{{ formatCurrency(product.last_purchase_price) }}</td>
                                <td class="p-2.5 text-right">
                                    <div class="flex items-center justify-end gap-1.5 relative group">
                                        <div class="relative w-[130px]">
                                            <input
                                                v-model="product.editPrice"
                                                @blur="updatePrice(product, index)"
                                                @keyup.enter="updatePrice(product, index)"
                                                type="number"
                                                class="w-full text-right bg-white border rounded py-1.5 px-2.5 text-[13px] font-bold text-gray-900 border-gray-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition-shadow"
                                                :class="{
                                                    'ring-1 ring-green-500 border-green-500 bg-green-50': product.updateSuccess,
                                                    'animate-pulse opacity-70': product.isUpdating
                                                }"
                                            >
                                            <div v-if="product.updateSuccess" class="absolute left-2 top-1/2 -translate-y-1/2 text-green-600">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                                            </div>
                                            <div v-if="product.isUpdating" class="absolute left-2 top-1/2 -translate-y-1/2 text-blue-500">
                                                <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td v-if="showRetailColumn" class="p-2.5 text-right">
                                    <div class="flex items-center justify-end gap-1.5 relative group">
                                        <div class="relative w-[130px]">
                                            <input
                                                v-model="product.editRetailPrice"
                                                @blur="updateOptionalBookPrice(product, index, 'retail_price')"
                                                @keyup.enter="updateOptionalBookPrice(product, index, 'retail_price')"
                                                type="number"
                                                class="w-full text-right bg-white border rounded py-1.5 px-2.5 text-[13px] font-bold text-gray-900 border-gray-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition-shadow"
                                                :class="{
                                                    'ring-1 ring-green-500 border-green-500 bg-green-50': product.updateSuccess,
                                                    'animate-pulse opacity-70': product.isUpdating
                                                }"
                                            >
                                        </div>
                                    </div>
                                </td>
                                <td v-if="showTechnicianColumn" class="p-2.5 text-right">
                                    <div class="flex items-center justify-end gap-1.5 relative group">
                                        <div class="relative w-[130px]">
                                            <input
                                                v-model="product.editTechnicianPrice"
                                                @blur="updateOptionalBookPrice(product, index, 'technician_price')"
                                                @keyup.enter="updateOptionalBookPrice(product, index, 'technician_price')"
                                                type="number"
                                                class="w-full text-right bg-white border rounded py-1.5 px-2.5 text-[13px] font-bold text-gray-900 border-gray-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition-shadow"
                                                :class="{
                                                    'ring-1 ring-green-500 border-green-500 bg-green-50': product.updateSuccess,
                                                    'animate-pulse opacity-70': product.isUpdating
                                                }"
                                            >
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="px-4 py-3 border-t border-gray-200 flex justify-between items-center bg-gray-50/50">
                    <div class="text-[13px] text-gray-600">
                        Hiển thị {{ products.from || 0 }}-{{ products.to || 0 }} trên {{ products.total }} hàng hóa
                    </div>
                    <div class="flex p-0.5 bg-white border border-gray-300 rounded shadow-sm">
                        <template v-for="(link, i) in products.links" :key="i">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                class="px-3 py-1 text-[13px] border-r border-gray-200 last:border-0 hover:bg-gray-50 transition-colors"
                                :class="{'bg-blue-50 text-blue-700 font-bold': link.active, 'text-gray-600': !link.active}"
                                v-html="link.label"
                                preserve-scroll
                            />
                            <span v-else class="px-3 py-1 text-[13px] text-gray-300 border-r border-gray-200 last:border-0 cursor-not-allowed" v-html="link.label" />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ Formula Modal ============ -->
        <div v-if="isFormulaModalOpen" class="fixed inset-0 z-[100] flex items-center justify-center">
            <div class="absolute inset-0 bg-black/40" @click="isFormulaModalOpen = false"></div>
            <div class="relative bg-white w-[500px] rounded-lg shadow-xl">
                <div class="px-5 py-3 border-b flex justify-between items-center">
                    <h3 class="font-bold text-[16px] text-gray-800">Thiết lập công thức giá</h3>
                    <button @click="isFormulaModalOpen = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
                </div>
                <div class="p-5 space-y-4 text-[13px]">
                    <div>
                        <div class="text-gray-500 mb-1">Áp dụng cho:</div>
                        <div class="font-bold text-blue-800 bg-blue-50 px-3 py-1.5 rounded text-[14px]">{{ selectedProductName }}</div>
                    </div>
                    <div class="p-4 border rounded bg-gray-50">
                        <div class="font-medium text-gray-800 mb-3">Công thức tính giá:</div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-bold">Giá mới</span> =
                            <select v-model="formulaForm.base_field" class="border rounded p-1.5 outline-none focus:border-blue-500">
                                <option value="cost_price">Giá vốn</option>
                                <option value="last_purchase_price">Giá nhập cuối</option>
                                <option value="retail_price">Giá bán hiện tại</option>
                            </select>
                            <select v-model="formulaForm.operator" class="border rounded p-1.5 outline-none focus:border-blue-500 w-14">
                                <option value="+">+</option>
                                <option value="-">-</option>
                            </select>
                            <input v-model="formulaForm.value" type="number" min="0" class="border rounded p-1.5 w-24 outline-none focus:border-blue-500">
                            <select v-model="formulaForm.is_percent" class="border rounded p-1.5 outline-none focus:border-blue-500 w-16">
                                <option :value="false">VNĐ</option>
                                <option :value="true">%</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="px-5 py-3 border-t flex justify-end gap-2">
                    <button @click="isFormulaModalOpen = false" class="px-4 py-1.5 border rounded hover:bg-gray-50 text-[13px]">Bỏ qua</button>
                    <button @click="applyFormula" class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium text-[13px]">Áp dụng</button>
                </div>
            </div>
        </div>

        <!-- ============ Create/Edit Price Book Modal (KiotViet style) ============ -->
        <div v-if="isPriceBookModalOpen" class="fixed inset-0 z-[100] flex items-center justify-center">
            <div class="absolute inset-0 bg-black/40" @click="isPriceBookModalOpen = false"></div>
            <div class="relative bg-white w-[580px] rounded-lg shadow-xl max-h-[90vh] overflow-hidden flex flex-col">
                <!-- Header -->
                <div class="px-5 py-3.5 border-b flex justify-between items-center flex-shrink-0">
                    <h3 class="font-bold text-[17px] text-gray-800">{{ editingPriceBook ? 'Sửa bảng giá' : 'Tạo bảng giá' }}</h3>
                    <button @click="isPriceBookModalOpen = false" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>

                <!-- Tabs -->
                <div class="flex border-b px-5 flex-shrink-0">
                    <button
                        @click="pbModalTab = 'info'"
                        class="px-4 py-2.5 text-[14px] font-medium border-b-2 transition-colors"
                        :class="pbModalTab === 'info' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    >Thông tin</button>
                    <button
                        @click="pbModalTab = 'scope'"
                        class="px-4 py-2.5 text-[14px] font-medium border-b-2 transition-colors"
                        :class="pbModalTab === 'scope' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    >Phạm vi áp dụng</button>
                </div>

                <!-- Tab Content -->
                <div class="flex-1 overflow-y-auto p-5 space-y-5">
                    <!-- ===== INFO TAB ===== -->
                    <template v-if="pbModalTab === 'info'">
                        <!-- Tên bảng giá -->
                        <div>
                            <label class="block text-[13px] font-medium text-gray-700 mb-1">Tên bảng giá</label>
                            <input type="text" v-model="pbForm.name" placeholder="Nhập tên bảng giá" class="w-full border border-gray-300 rounded px-3 py-2 text-[14px] outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        </div>

                        <!-- Cột giá mở rộng -->
                        <div class="border rounded-lg overflow-hidden">
                            <div class="px-4 py-2.5 bg-gray-50 border-b">
                                <span class="font-bold text-[14px] text-gray-800">Cột giá mở rộng</span>
                                <p class="text-[12px] text-gray-500">Bật thêm cột Giá lẻ và/hoặc Giá thợ cho bảng giá này</p>
                            </div>
                            <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3 text-[13px]">
                                <label class="flex items-center gap-2.5 cursor-pointer p-2 border rounded hover:border-blue-300">
                                    <input type="checkbox" v-model="pbForm.enable_retail_price" class="w-4 h-4 rounded border-gray-300 text-blue-600">
                                    <span class="text-gray-700 font-medium">Bật cột Giá lẻ</span>
                                </label>
                                <label class="flex items-center gap-2.5 cursor-pointer p-2 border rounded hover:border-blue-300">
                                    <input type="checkbox" v-model="pbForm.enable_technician_price" class="w-4 h-4 rounded border-gray-300 text-blue-600">
                                    <span class="text-gray-700 font-medium">Bật cột Giá thợ</span>
                                </label>
                            </div>
                        </div>

                        <!-- Hiệu lực -->
                        <div class="border rounded-lg overflow-hidden">
                            <div class="px-4 py-2.5 bg-gray-50 border-b flex justify-between items-center">
                                <span class="font-bold text-[14px] text-gray-800">Hiệu lực</span>
                            </div>
                            <div class="p-4 space-y-3">
                                <div class="flex items-center gap-3 text-[13px]">
                                    <span class="text-gray-600 w-16">Hiệu lực</span>
                                    <input type="datetime-local" v-model="pbForm.start_date" class="border rounded px-2.5 py-1.5 text-[13px] outline-none focus:border-blue-500">
                                    <span class="text-gray-400">đến</span>
                                    <input type="datetime-local" v-model="pbForm.end_date" class="border rounded px-2.5 py-1.5 text-[13px] outline-none focus:border-blue-500">
                                </div>
                                <div class="flex items-center gap-4 text-[13px]">
                                    <span class="text-gray-600 w-16">Trạng thái</span>
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="radio" v-model="pbForm.status" value="active" class="w-4 h-4 text-blue-600">
                                        <span>Áp dụng</span>
                                    </label>
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="radio" v-model="pbForm.status" value="inactive" class="w-4 h-4 text-blue-600">
                                        <span>Chưa áp dụng</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Công thức giá -->
                        <div class="border rounded-lg overflow-hidden">
                            <div class="px-4 py-2.5 bg-gray-50 border-b">
                                <span class="font-bold text-[14px] text-gray-800">Công thức giá</span>
                                <p class="text-[12px] text-gray-500">Tạo công thức dựa trên giá vốn, giá nhập hoặc giá bán ở các bảng giá khác</p>
                            </div>
                            <div class="p-4">
                                <div class="flex items-center gap-2 flex-wrap text-[13px]">
                                    <span class="font-medium text-gray-700">Giá mới =</span>
                                    <select v-model="pbForm.formula_base" class="border rounded px-2.5 py-1.5 outline-none focus:border-blue-500 text-[13px]">
                                        <option v-for="opt in formulaBaseOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                    </select>
                                    <select v-model="pbForm.formula_operator" class="border rounded px-2 py-1.5 outline-none focus:border-blue-500 w-12 text-center text-[13px]">
                                        <option value="+">+</option>
                                        <option value="-">-</option>
                                    </select>
                                    <input type="number" v-model.number="pbForm.formula_value" min="0" class="border rounded px-2.5 py-1.5 w-24 outline-none focus:border-blue-500 text-[13px]">
                                    <select v-model="pbForm.formula_is_percent" class="border rounded px-2 py-1.5 outline-none focus:border-blue-500 w-14 text-[13px]">
                                        <option :value="false">VNĐ</option>
                                        <option :value="true">%</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Khi thu ngân lên đơn -->
                        <div class="border rounded-lg overflow-hidden">
                            <div class="px-4 py-2.5 bg-gray-50 border-b">
                                <span class="font-bold text-[14px] text-gray-800">Khi thu ngân lên đơn với bảng giá này</span>
                            </div>
                            <div class="p-4 space-y-3 text-[13px]">
                                <label class="flex items-start gap-2.5 cursor-pointer">
                                    <input type="radio" v-model="pbForm.cashier_rule" value="allow_add" class="w-4 h-4 text-blue-600 mt-0.5">
                                    <span class="text-gray-700">Được phép thêm hàng hóa không có trong bảng giá</span>
                                </label>
                                <div v-if="pbForm.cashier_rule === 'allow_add'" class="ml-7">
                                    <label class="flex items-center gap-2 cursor-pointer text-[13px] text-gray-600">
                                        <input type="checkbox" v-model="pbForm.cashier_warn_not_in_book" class="w-4 h-4 rounded border-gray-300 text-blue-600">
                                        Gửi cảnh báo khi thêm hàng hóa không có trong bảng giá
                                    </label>
                                </div>
                                <label class="flex items-start gap-2.5 cursor-pointer">
                                    <input type="radio" v-model="pbForm.cashier_rule" value="only_in_book" class="w-4 h-4 text-blue-600 mt-0.5">
                                    <span class="text-gray-700">Chỉ được thêm hàng hóa có trong bảng giá này</span>
                                </label>
                            </div>
                        </div>

                    </template>

                    <!-- ===== SCOPE TAB ===== -->
                    <template v-if="pbModalTab === 'scope'">
                        <!-- Chi nhánh -->
                        <div class="border rounded-lg overflow-hidden">
                            <div class="px-4 py-2.5 bg-gray-50 border-b">
                                <span class="font-bold text-[14px] text-gray-800">Chi nhánh</span>
                            </div>
                            <div class="p-4 space-y-3 text-[13px]">
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="radio" v-model="pbForm.scope_branch" value="all" class="w-4 h-4 text-blue-600">
                                    <span class="text-gray-700">Toàn hệ thống</span>
                                </label>
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="radio" v-model="pbForm.scope_branch" value="specific" class="w-4 h-4 text-blue-600">
                                    <span class="text-gray-700">Chi nhánh cụ thể</span>
                                </label>
                                <div v-if="pbForm.scope_branch === 'specific'" class="ml-7 space-y-1.5">
                                    <label v-for="branch in branches" :key="branch.id" class="flex items-center gap-2 cursor-pointer text-[13px]">
                                        <input type="checkbox" :value="branch.id" v-model="pbForm.branch_ids" class="w-4 h-4 rounded border-gray-300 text-blue-600">
                                        <span>{{ branch.name }}</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Nhóm khách hàng -->
                        <div class="border rounded-lg overflow-hidden">
                            <div class="px-4 py-2.5 bg-gray-50 border-b">
                                <span class="font-bold text-[14px] text-gray-800">Nhóm khách hàng</span>
                            </div>
                            <div class="p-4 space-y-3 text-[13px]">
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="radio" v-model="pbForm.scope_customer_group" value="all" class="w-4 h-4 text-blue-600">
                                    <span class="text-gray-700">Tất cả</span>
                                </label>
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="radio" v-model="pbForm.scope_customer_group" value="specific" class="w-4 h-4 text-blue-600">
                                    <span class="text-gray-700">Nhóm khách hàng cụ thể</span>
                                </label>
                            </div>
                        </div>

                        <!-- Người tạo giao dịch -->
                        <div class="border rounded-lg overflow-hidden">
                            <div class="px-4 py-2.5 bg-gray-50 border-b">
                                <span class="font-bold text-[14px] text-gray-800">Người tạo giao dịch</span>
                            </div>
                            <div class="p-4 space-y-3 text-[13px]">
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="radio" value="all" checked class="w-4 h-4 text-blue-600">
                                    <span class="text-gray-700">Tất cả</span>
                                </label>
                                <label class="flex items-center gap-2.5 cursor-pointer">
                                    <input type="radio" value="specific" class="w-4 h-4 text-blue-600">
                                    <span class="text-gray-700">Người tạo giao dịch cụ thể</span>
                                </label>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Footer -->
                <div class="px-5 py-3 border-t flex justify-end gap-2 flex-shrink-0">
                    <button @click="isPriceBookModalOpen = false" class="px-5 py-2 border rounded hover:bg-gray-50 text-[13px] font-medium">Bỏ qua</button>
                    <button @click="submitPriceBook" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium text-[13px]">Lưu</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
input[type="number"]::-webkit-outer-spin-button,
input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
input[type="number"] { -moz-appearance: textfield; }

select {
    -webkit-appearance: none; -moz-appearance: none; appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat; background-position: right 0.5rem center; background-size: 1em;
    padding-right: 2rem !important;
}
</style>
