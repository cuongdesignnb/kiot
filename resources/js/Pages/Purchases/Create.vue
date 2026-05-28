<script setup>
import { formatVND as formatCurrency, parseMoneyModelValue } from '@/utils/money';
import { ref, computed, onMounted, watch, onBeforeUnmount } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import DateTimePicker from '@/Components/DateTimePicker.vue';
import QuickCreateProductModal from '@/Components/QuickCreateProductModal.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
// STEP 24.13-FIX — use the full customer modal in supplier mode so the form
// matches the standalone /suppliers create page (4 accordions: basic, address,
// group, invoice info). The previous simple modal was too sparse.
import QuickCreateCustomerModal from '@/Components/QuickCreateCustomerModal.vue';

const page = usePage();

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

// STEP 24.13-FIX — supplier modal owns its own state; page just toggles
// `showCreateSupplierModal` and consumes the `created` event.
const showCreateSupplierModal = ref(false);

const searchQuery = ref('');
const showSuggestions = ref(false);
const showCreateDropdown = ref(false);
const items = ref([]);

const selectedSupplierId = ref('');
const supplierQuery = ref('');
const showSupplierDropdown = ref(false);

const selectedSupplierObj = computed(() => {
    if (!selectedSupplierId.value) return null;
    return localSuppliers.value.find(s => s.id == selectedSupplierId.value);
});

const filteredSuppliers = computed(() => {
    const q = (supplierQuery.value || '').toLowerCase().trim();
    if (!q) return localSuppliers.value.slice(0, 20);
    return localSuppliers.value.filter(s =>
        (s.name && s.name.toLowerCase().includes(q)) ||
        (s.code && s.code.toLowerCase().includes(q)) ||
        (s.phone && s.phone.includes(q))
    ).slice(0, 20);
});

let supplierSearchTimeout;
const handleSupplierSearch = () => {
    clearTimeout(supplierSearchTimeout);
    supplierSearchTimeout = setTimeout(() => {
        showSupplierDropdown.value = true;
    }, 100);
};

const hideSupplierDropdown = () => {
    setTimeout(() => { showSupplierDropdown.value = false; }, 200);
};

const pickSupplier = (supplier) => {
    selectedSupplierId.value = supplier.id;
    supplierQuery.value = '';
    showSupplierDropdown.value = false;
};

const clearSupplier = () => {
    selectedSupplierId.value = '';
    supplierQuery.value = '';
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

const moneyNumber = (value) => Number(parseMoneyModelValue(value) || 0);

// Chi phí nhập khác
const otherCosts = ref([]);
const showOtherCosts = ref(false);
const newCostName = ref('');
const newCostAmount = ref(0);

const pendingOtherCost = computed(() => {
    const name = (newCostName.value || '').trim();
    const amount = moneyNumber(newCostAmount.value);

    if (!name && amount <= 0) return null;

    return {
        id: '__pending__',
        name,
        amount,
        isPending: true,
    };
});

const normalizedOtherCosts = computed(() => {
    const existing = (otherCosts.value || [])
        .map(c => ({
            name: (c.name || '').trim(),
            amount: moneyNumber(c.amount),
        }))
        .filter(c => c.name !== '' && c.amount > 0);

    const pending = pendingOtherCost.value;

    if (pending && pending.name && pending.amount > 0) {
        existing.push({
            name: pending.name,
            amount: pending.amount,
        });
    }

    return existing;
});

const totalOtherCosts = computed(() =>
    normalizedOtherCosts.value.reduce((s, c) => s + moneyNumber(c.amount), 0)
);

const addOtherCost = () => {
    const name = (newCostName.value || '').trim();
    const amount = moneyNumber(newCostAmount.value);

    if (!name && amount <= 0) return;

    if (!name) {
        alert('Vui lòng nhập tên chi phí nhập khác.');
        return;
    }

    if (amount <= 0) {
        alert('Vui lòng nhập số tiền chi phí nhập khác lớn hơn 0.');
        return;
    }

    otherCosts.value.push({
        id: Date.now(),
        name,
        amount,
    });

    newCostName.value = '';
    newCostAmount.value = 0;
};

const removeOtherCost = (index) => {
    otherCosts.value.splice(index, 1);
};

const PURCHASE_DRAFT_KEY = 'kiot.purchase.create.draft.v1';

const isRestoringDraft = ref(false);
const lastDraftSavedAt = ref(null);
const showRestoreDraftBanner = ref(false);
const pendingDraft = ref(null);

const buildDraftSnapshot = () => ({
    version: 1,
    saved_at: new Date().toISOString(),
    purchaseCode: props.purchaseCode,
    selectedSupplierId: selectedSupplierId.value || '',
    supplierQuery: supplierQuery.value || '',
    selectedEmployeeId: selectedEmployeeId.value || '',
    purchaseDate: purchaseDate.value || '',
    status: status.value || 'completed',
    discount: moneyNumber(discount.value),
    paidAmount: moneyNumber(paidAmount.value),
    note: note.value || '',
    paymentMethod: paymentMethod.value || 'cash',
    bankAccountInfo: bankAccountInfo.value || '',
    otherCosts: normalizedOtherCosts.value,
    items: items.value.map((item) => ({
        product_id: item.product_id,
        sku: item.sku,
        name: item.name,
        has_serial: !!item.has_serial,
        quantity: Number(item.quantity) || 0,
        price: moneyNumber(item.price),
        retail_price: moneyNumber(item.retail_price),
        technician_price: moneyNumber(item.technician_price),
        discount: moneyNumber(item.discount),
        stock_quantity: Number(item.stock_quantity) || 0,
        serials: Array.isArray(item.serials) ? [...item.serials] : [],
        serialInput: item.serialInput || '',
        showSerialArea: !!item.showSerialArea,
        warranty_months: Number(item.warranty_months) || 0,
    })),
});

const hasMeaningfulDraft = computed(() =>
    items.value.length > 0 ||
    !!selectedSupplierId.value ||
    !!note.value ||
    moneyNumber(discount.value) > 0 ||
    moneyNumber(paidAmount.value) > 0 ||
    normalizedOtherCosts.value.length > 0
);

const saveBrowserDraft = (silent = false) => {
    if (!hasMeaningfulDraft.value) return;

    const snapshot = buildDraftSnapshot();
    localStorage.setItem(PURCHASE_DRAFT_KEY, JSON.stringify(snapshot));
    lastDraftSavedAt.value = new Date();

    if (!silent) {
        alert('Đã lưu nháp phiếu nhập trên trình duyệt.');
    }
};

const clearBrowserDraft = () => {
    localStorage.removeItem(PURCHASE_DRAFT_KEY);
    pendingDraft.value = null;
    showRestoreDraftBanner.value = false;
    lastDraftSavedAt.value = null;
};

const restoreDraft = (draft) => {
    if (!draft || draft.version !== 1) return;

    isRestoringDraft.value = true;

    selectedSupplierId.value = draft.selectedSupplierId || '';
    supplierQuery.value = draft.supplierQuery || '';
    selectedEmployeeId.value = draft.selectedEmployeeId || '';
    purchaseDate.value = draft.purchaseDate || purchaseDate.value;
    status.value = draft.status || 'completed';
    discount.value = Number(draft.discount) || 0;
    paidAmount.value = Number(draft.paidAmount) || 0;
    note.value = draft.note || '';
    paymentMethod.value = draft.paymentMethod || 'cash';
    bankAccountInfo.value = draft.bankAccountInfo || '';
    otherCosts.value = Array.isArray(draft.otherCosts) ? draft.otherCosts : [];
    items.value = Array.isArray(draft.items) ? draft.items : [];

    showRestoreDraftBanner.value = false;
    pendingDraft.value = null;

    setTimeout(() => {
        isRestoringDraft.value = false;
    }, 0);
};

let draftSaveTimer = null;

watch(
    [
        items,
        selectedSupplierId,
        selectedEmployeeId,
        purchaseDate,
        status,
        discount,
        paidAmount,
        note,
        paymentMethod,
        bankAccountInfo,
        normalizedOtherCosts,
    ],
    () => {
        if (isRestoringDraft.value) return;
        if (!hasMeaningfulDraft.value) return;

        clearTimeout(draftSaveTimer);
        draftSaveTimer = setTimeout(() => {
            saveBrowserDraft(true);
        }, 800);
    },
    { deep: true }
);

const beforeUnloadHandler = (e) => {
    if (!hasMeaningfulDraft.value || submitRef.value) return;

    saveBrowserDraft(true);
    e.preventDefault();
    e.returnValue = '';
};

onMounted(() => {
    if (props.purchaseOrderInfo) {
        selectedSupplierId.value = props.purchaseOrderInfo.supplier_id || '';
        discount.value = props.purchaseOrderInfo.discount || 0;
        items.value = props.purchaseOrderInfo.items || [];
    }

    const rawDraft = localStorage.getItem(PURCHASE_DRAFT_KEY);
    if (rawDraft) {
        try {
            const draft = JSON.parse(rawDraft);

            if (draft?.items?.length || draft?.selectedSupplierId || draft?.note) {
                pendingDraft.value = draft;
                showRestoreDraftBanner.value = true;
            }
        } catch (e) {
            console.warn('Invalid purchase draft, clearing', e);
            localStorage.removeItem(PURCHASE_DRAFT_KEY);
        }
    }

    window.addEventListener('beforeunload', beforeUnloadHandler);
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', beforeUnloadHandler);
    if (draftSaveTimer) clearTimeout(draftSaveTimer);
});

const filteredProducts = computed(() => {
    if (!searchQuery.value) return [];
    const query = searchQuery.value.toLowerCase();
    return allProducts.value.filter(p => 
        p.name.toLowerCase().includes(query) || 
        p.sku.toLowerCase().includes(query)
    ).slice(0, 10);
});

watch(searchQuery, (val) => {
    if (val) showSuggestions.value = true;
});

const selectProduct = (product) => {
    const existing = items.value.find(i => i.product_id === product.id);
    if (!existing) {
        items.value.unshift({ 
            product_id: product.id,
            sku: product.sku,
            name: product.name,
            has_serial: !!product.has_serial,
            quantity: product.has_serial ? 0 : 1,
            price: product.cost_price || 0,
            retail_price: product.retail_price || 0,
            technician_price: product.technician_price || 0,
            discount: 0,
            stock_quantity: product.stock_quantity || 0,
            serials: [],
            serialInput: '',
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
    if (item.serials.includes(val)) {
        alert('Serial/IMEI "' + val + '" đã tồn tại trong danh sách!');
        return;
    }
    item.serials.push(val);
    item.quantity = item.serials.length;
    item.serialInput = '';
};

const removeSerial = (item, index) => {
    item.serials.splice(index, 1);
    item.quantity = item.serials.length;
};

const getItemTotal = (item) => {
    const qty = item.has_serial ? (item.serials?.length || 0) : (parseInt(item.quantity) || 0);
    const price = parseFloat(item.price) || 0;
    const itemDiscount = parseFloat(item.discount) || 0;
    return (qty * price) - itemDiscount;
};

const totalAmount = computed(() => items.value.reduce((sum, item) => sum + getItemTotal(item), 0));
const totalPayment = computed(() =>
    Math.max(
        0,
        moneyNumber(totalAmount.value)
            - moneyNumber(discount.value)
            + moneyNumber(totalOtherCosts.value)
    )
);

// HOTFIX 24.21 — surface supplier balance + overpayment instead of clamping at 0.
// `currentPurchaseBalance` is `cần trả − đã trả`:
//   > 0 → operator still owes the supplier (Còn nợ)
//   < 0 → operator overpaid; |balance| is the change the supplier should refund
//          (Tiền thừa). The legacy `debtAmount` computed clamps to 0 so the UI
//          previously had no signal for the overpaid branch.
const currentPurchaseBalance = computed(
    () => Number(totalPayment.value || 0) - Number(paidAmount.value || 0)
);
const currentPurchaseDebt = computed(() => Math.max(0, currentPurchaseBalance.value));
const purchaseOverpaidAmount = computed(() => Math.max(0, -currentPurchaseBalance.value));

const oldSupplierBalance = computed(
    () => Number(selectedSupplierObj.value?.supplier_debt_amount || 0)
);
const oldSupplierDebt = computed(() => Math.max(0, oldSupplierBalance.value));
const oldSupplierCredit = computed(() => Math.max(0, -oldSupplierBalance.value));

// Sau khi lưu phiếu này, công nợ NCC sẽ thay đổi bằng đúng
// `currentPurchaseBalance` (backend cộng debt_amount = pay − paid vào
// supplier_debt_amount). Hiển thị dự kiến để operator không phải tự nhẩm.
const projectedSupplierBalance = computed(
    () => oldSupplierBalance.value + currentPurchaseBalance.value
);
const projectedSupplierDebt = computed(() => Math.max(0, projectedSupplierBalance.value));
const projectedSupplierCredit = computed(() => Math.max(0, -projectedSupplierBalance.value));

// Backward-compat: nhiều chỗ trong template đang đọc `debtAmount` — giữ alias
// nhưng map sang `currentPurchaseDebt` để vẫn clamp ≥ 0 (đúng với label
// "Tính vào công nợ" cũ).
const debtAmount = currentPurchaseDebt;

const save = () => {
    if (items.value.length === 0) {
        alert("Vui lòng chọn ít nhất 1 hàng hóa để nhập hàng.");
        return;
    }
    if (!selectedSupplierId.value) {
        alert("Vui lòng chọn nhà cung cấp!");
        return;
    }

    submitRef.value = true;
    
    router.post('/purchases', {
        code: props.purchaseCode,
        status: status.value,
        supplier_id: selectedSupplierId.value || null,
        employee_id: selectedEmployeeId.value || null,
        purchase_date: purchaseDate.value || null,
        note: note.value,
        discount: moneyNumber(discount.value),
        other_costs: normalizedOtherCosts.value.map(c => ({
            name: c.name,
            amount: moneyNumber(c.amount),
        })),
        paid_amount: moneyNumber(paidAmount.value),
        payment_method: paymentMethod.value,
        bank_account_info: paymentMethod.value === 'transfer' ? bankAccountInfo.value : null,
        items: items.value.map(item => ({
            product_id: item.product_id,
            quantity: item.has_serial ? (item.serials?.length || 0) : (parseInt(item.quantity) || 0),
            price: moneyNumber(item.price),
            retail_price: moneyNumber(item.retail_price),
            technician_price: moneyNumber(item.technician_price),
            discount: moneyNumber(item.discount),
            serials: item.serials || [],
            warranty_months: item.warranty_months || 0,
        }))
    }, {
        onSuccess: () => {
            clearBrowserDraft();
        },
        onError: (errors) => {
            const firstError = Object.values(errors)[0];
            if (firstError) alert(firstError);
        },
        onFinish: () => {
            submitRef.value = false;
        },
    });
};



// STEP 24.13 — page now just toggles the shared product modal.
const showCreateProductModal = ref(false);
const openCreateProductModal = () => { showCreateProductModal.value = true; };

const localCategories = ref([...(props.categories || [])]);
const localBrands = ref([...(props.brands || [])]);


</script>

<template>
    <Head title="Nhập hàng - KiotViet Clone" />
    <div class="h-screen flex flex-col bg-[#eef1f5] text-[13px] overflow-hidden font-sans">
        
        <!-- Flash / Error Messages -->
        <div v-if="page.props.flash?.error" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 text-sm flex items-center gap-2">
            <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
            {{ page.props.flash.error }}
        </div>
        <div v-if="page.props.flash?.success" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 text-sm flex items-center gap-2">
            <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            {{ page.props.flash.success }}
        </div>

        <!-- Restore Draft Banner -->
        <div v-if="showRestoreDraftBanner && pendingDraft" class="bg-yellow-50 border-b border-yellow-200 px-4 py-3 text-[13px] text-yellow-800 flex items-center justify-between gap-3">
            <div>
                Có phiếu nhập nháp chưa lưu <span v-if="pendingDraft.saved_at" class="font-semibold">({{ new Date(pendingDraft.saved_at).toLocaleString('vi-VN') }})</span> — gồm {{ pendingDraft.items?.length || 0 }} dòng hàng.
            </div>
            <div class="flex items-center gap-2">
                <button type="button" class="rounded bg-yellow-600 px-3 py-1.5 text-white font-semibold hover:bg-yellow-700" @click="restoreDraft(pendingDraft)">
                    Khôi phục
                </button>
                <button type="button" class="rounded border border-yellow-300 px-3 py-1.5 text-yellow-800 hover:bg-yellow-100" @click="clearBrowserDraft">
                    Bỏ qua
                </button>
            </div>
        </div>

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
                    
                    <div v-if="showSuggestions && filteredProducts.length > 0" class="absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 shadow-xl rounded-sm z-50 max-h-[300px] overflow-auto">
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
                            <button @click="showCreateDropdown = !showCreateDropdown" class="hover:text-green-600" title="Tạo hàng hóa mới">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                            </button>
                            <div v-if="showCreateDropdown" class="absolute right-0 top-full mt-2 bg-white border border-gray-200 rounded shadow-lg z-50 w-44 py-1">
                                <button @click="openCreateProductModal(); showCreateDropdown = false" class="w-full text-left px-4 py-2 text-[13px] text-gray-700 hover:bg-gray-100">Hàng hóa</button>
                                <button @click="openCreateProductModal(); showCreateDropdown = false" class="w-full text-left px-4 py-2 text-[13px] text-gray-700 hover:bg-gray-100">Hàng sản xuất</button>
                            </div>
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
                                    <MoneyInput v-model="item.price" :min="0" input-class="w-full border-b border-dashed border-gray-400 py-1 text-right outline-none focus:border-green-500 text-[13px] hover:bg-green-50 font-medium tracking-wide" />
                                </td>
                                <td v-if="showRetailPrice" class="p-3 w-[120px]">
                                    <MoneyInput v-model="item.retail_price" :min="0" input-class="w-full border-b border-dashed border-gray-400 py-1 text-right outline-none focus:border-blue-500 text-[13px] hover:bg-blue-50 font-medium tracking-wide" />
                                </td>
                                <td v-if="showTechnicianPrice" class="p-3 w-[120px]">
                                    <MoneyInput v-model="item.technician_price" :min="0" input-class="w-full border-b border-dashed border-gray-400 py-1 text-right outline-none focus:border-purple-500 text-[13px] hover:bg-purple-50 font-medium tracking-wide" />
                                </td>
                                <td class="p-3 w-[100px]">
                                    <MoneyInput v-model="item.discount" :min="0" input-class="w-full border-b border-dashed border-gray-400 py-1 text-right outline-none focus:border-green-500 text-[13px] hover:bg-green-50" />
                                </td>
                                <td class="p-3 w-[80px] text-center">
                                    <input type="number" v-model.number="item.warranty_months" min="0" class="w-full border-b border-dashed border-gray-400 py-1 text-center outline-none focus:border-orange-500 text-[13px] hover:bg-orange-50" placeholder="0">
                                </td>
                                <td class="p-3 font-bold text-gray-800 text-right w-[140px] pr-6">{{ formatCurrency(getItemTotal(item)) }}</td>
                            </tr>
                            <!-- Serial/IMEI input row -->
                            <tr v-if="item.has_serial" class="bg-gray-50/50">
                                <td :colspan="9 + (showRetailPrice ? 1 : 0) + (showTechnicianPrice ? 1 : 0)" class="px-6 py-2">
                                    <div class="flex items-center gap-2 mb-2">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path></svg>
                                        <input
                                            v-model="item.serialInput"
                                            @keydown.enter.prevent="addSerial(item)"
                                            type="text"
                                            class="flex-1 max-w-[300px] border border-gray-300 rounded px-2.5 py-1.5 text-[13px] outline-none focus:border-green-500 focus:ring-1 focus:ring-green-500"
                                            placeholder="Nhập số Serial/IMEI rồi nhấn Enter"
                                        >
                                        <button @click="addSerial(item)" class="text-green-600 hover:text-green-700 text-[12px] font-medium px-2 py-1 border border-green-300 rounded hover:bg-green-50">Thêm</button>
                                    </div>
                                    <div v-if="item.serials.length > 0" class="flex flex-wrap gap-1.5">
                                        <span v-for="(s, si) in item.serials" :key="si" class="inline-flex items-center gap-1 bg-blue-50 border border-blue-200 text-blue-700 text-[12px] font-medium px-2 py-0.5 rounded">
                                            {{ s }}
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
                    <DateTimePicker
                        v-model="purchaseDate"
                        naked
                        compact
                        placeholder="dd/MM/yyyy HH:mm"
                        input-class="text-[13px] text-gray-500 bg-transparent border-b border-dashed border-gray-300 outline-none focus:border-green-500 py-0.5 w-[170px]"
                    />
                </div>

                <div class="flex-1 overflow-auto bg-white flex flex-col pt-2">
                    <div class="px-3 pb-3">
                        <div class="relative mb-3">
                            <div class="flex items-center border-b border-gray-300 pb-1">
                                <svg class="w-4 h-4 text-gray-400 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                                <!-- Selected supplier display -->
                                <div v-if="selectedSupplierId && selectedSupplierObj" class="flex-1 flex items-center justify-between">
                                    <span class="text-[13px] text-gray-800 font-medium">{{ selectedSupplierObj.name }}</span>
                                    <button type="button" @click="clearSupplier" class="text-gray-400 hover:text-red-500 ml-1" title="Bỏ chọn">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                    </button>
                                </div>
                                <!-- Search input -->
                                <input
                                    v-else
                                    v-model="supplierQuery"
                                    @input="handleSupplierSearch"
                                    @focus="showSupplierDropdown = true; handleSupplierSearch()"
                                    @blur="hideSupplierDropdown"
                                    type="text"
                                    class="flex-1 py-1 outline-none text-[13px] text-gray-800 bg-transparent"
                                    placeholder="Tìm nhà cung cấp (tên, SĐT, mã) *"
                                >
                                <button type="button" @click="showCreateSupplierModal = true" class="text-green-600 hover:text-green-700 font-bold text-lg leading-none ml-1" title="Thêm nhà cung cấp">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                                </button>
                            </div>
                            <!-- Supplier search results dropdown -->
                            <div v-if="showSupplierDropdown && !selectedSupplierId" class="absolute left-0 right-0 top-full mt-1 bg-white shadow-xl rounded border border-gray-200 z-50 max-h-[200px] overflow-auto">
                                <div v-if="filteredSuppliers.length === 0" class="px-3 py-3 text-sm text-gray-400 text-center">
                                    Không tìm thấy "{{ supplierQuery }}"
                                    <button @mousedown.prevent="showCreateSupplierModal = true" class="block mx-auto mt-1 text-green-600 font-semibold hover:underline text-xs">+ Tạo NCC mới</button>
                                </div>
                                <div v-for="s in filteredSuppliers" :key="s.id"
                                    @mousedown.prevent="pickSupplier(s)"
                                    class="flex items-center justify-between px-3 py-2 hover:bg-green-50 cursor-pointer border-b border-gray-100 last:border-0 transition-colors text-[13px]">
                                    <div>
                                        <div class="font-semibold text-gray-800">{{ s.name }}</div>
                                        <div class="text-[11px] text-gray-500">{{ s.code }} | {{ s.phone || '—' }}</div>
                                    </div>
                                    <div v-if="s.supplier_debt_amount > 0" class="text-[11px] text-red-500 font-semibold">Nợ: {{ formatCurrency(s.supplier_debt_amount) }}</div>
                                </div>
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
                                <MoneyInput v-model="discount" :min="0" input-class="w-[150px] border-b border-dashed border-gray-300 text-right pr-2 py-0.5 outline-none focus:border-green-500 hover:bg-green-50" />
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
                                    <MoneyInput v-model="cost.amount" :min="0" input-class="w-[100px] border border-gray-300 rounded px-2 py-1 text-right text-[12px] outline-none focus:border-green-500" />
                                    <button @click="removeOtherCost(ci)" class="text-red-400 hover:text-red-600 text-sm">✕</button>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input type="text" v-model="newCostName" @keydown.enter="addOtherCost" class="flex-1 border border-gray-300 rounded px-2 py-1 text-[12px] outline-none focus:border-green-500" placeholder="Tên chi phí (VD: Ship hàng)" />
                                    <MoneyInput v-model="newCostAmount" :min="0" placeholder="Số tiền" input-class="w-[100px] border border-gray-300 rounded px-2 py-1 text-right text-[12px] outline-none focus:border-green-500" />
                                    <button @click="addOtherCost" class="text-green-600 hover:text-green-700 text-sm font-bold">+</button>
                                </div>
                                <div v-if="pendingOtherCost && pendingOtherCost.name && pendingOtherCost.amount > 0" class="text-[11px] text-gray-500 italic mt-1 pl-1">
                                    * Đang tạm tính vào phiếu, bấm + để thêm cố định.
                                </div>
                            </div>

                            <div class="flex justify-between items-center text-[13px] pt-2">
                                <label class="text-gray-800 font-bold">Cần trả nhà cung cấp</label>
                                <div class="w-[150px] text-right font-bold text-green-600 tracking-wide text-lg">{{ formatCurrency(totalPayment) }}</div>
                            </div>

                            <div class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium">Tiền trả nhà cung cấp</label>
                                <MoneyInput v-model="paidAmount" :min="0" input-class="w-[150px] border-b border-gray-400 text-right pr-2 py-0.5 outline-none focus:border-green-500 hover:bg-green-50 font-bold text-blue-600" />
                            </div>

                            <!-- HOTFIX 24.21 — show overpayment when the operator paid
                                 more than the invoice asks for; previously the surplus
                                 silently disappeared. -->
                            <div v-if="currentPurchaseDebt > 0" class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium">Còn nợ phiếu này</label>
                                <div class="w-[150px] text-right font-bold text-red-500 tracking-wide">{{ formatCurrency(currentPurchaseDebt) }}</div>
                            </div>
                            <div v-else-if="purchaseOverpaidAmount > 0" class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium">Tiền thừa</label>
                                <div class="w-[150px] text-right font-bold text-green-600 tracking-wide">{{ formatCurrency(purchaseOverpaidAmount) }}</div>
                            </div>
                            <div v-else class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium text-gray-500">Tính vào công nợ</label>
                                <div class="w-[150px] text-right font-bold text-gray-500 tracking-wide">{{ formatCurrency(debtAmount) }}</div>
                            </div>

                            <!-- HOTFIX 24.21 — old supplier balance + projected balance after this purchase. -->
                            <div v-if="selectedSupplierObj" class="mt-1 pt-2 border-t border-dashed border-gray-200 space-y-1">
                                <div class="flex justify-between items-center text-[12px]">
                                    <label class="text-gray-500">
                                        <template v-if="oldSupplierCredit > 0">Số dư cũ NCC</template>
                                        <template v-else>Nợ cũ NCC</template>
                                    </label>
                                    <div class="w-[150px] text-right font-semibold tracking-wide"
                                         :class="oldSupplierCredit > 0 ? 'text-green-600' : oldSupplierDebt > 0 ? 'text-red-500' : 'text-gray-500'">
                                        {{ formatCurrency(oldSupplierCredit > 0 ? oldSupplierCredit : oldSupplierDebt) }}
                                    </div>
                                </div>
                                <div class="flex justify-between items-center text-[12px]">
                                    <label class="text-gray-500">
                                        <template v-if="projectedSupplierCredit > 0">Dự kiến NCC còn dư sau phiếu này</template>
                                        <template v-else-if="projectedSupplierDebt > 0">Dự kiến còn nợ NCC sau phiếu này</template>
                                        <template v-else>Dự kiến công nợ NCC sau phiếu này</template>
                                    </label>
                                    <div class="w-[150px] text-right font-bold tracking-wide"
                                         :class="projectedSupplierCredit > 0 ? 'text-green-600' : projectedSupplierDebt > 0 ? 'text-red-500' : 'text-gray-500'">
                                        {{ formatCurrency(projectedSupplierCredit > 0 ? projectedSupplierCredit : projectedSupplierDebt) }}
                                    </div>
                                </div>
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
                <div class="p-4 bg-white border-t border-gray-200 shadow-[0_-2px_10px_rgba(0,0,0,0.05)] space-y-2">
                    <button
                        type="button"
                        @click="saveBrowserDraft(false)"
                        :disabled="!hasMeaningfulDraft"
                        class="w-full bg-white hover:bg-gray-50 text-gray-700 border border-gray-300 font-bold py-2.5 rounded text-[14px] transition-colors disabled:opacity-50"
                    >
                        Lưu nháp
                    </button>
                    <button @click="save" :disabled="submitRef" class="w-full bg-[#2ebc5b] hover:bg-[#209644] text-white font-bold py-3 rounded text-[15px] uppercase tracking-wide transition-colors flex justify-center items-center gap-2 disabled:opacity-50">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg> Hoàn thành
                    </button>
                    <div class="mt-2 text-center">
                        <Link href="/purchases" class="text-gray-500 hover:text-gray-800 text-[13px] underline">Hủy bỏ</Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- STEP 24.13 — Shared QuickCreateProductModal (replaces inline modal). -->
        <QuickCreateProductModal
            :show="showCreateProductModal"
            :categories="localCategories"
            :brands="localBrands"
            :show-retail-price="showRetailPrice"
            :show-technician-price="showTechnicianPrice"
            @close="showCreateProductModal = false"
            @created="(p) => { allProducts.push(p); selectProduct(p); }"
        />

        <QuickCreateCustomerModal
            :show="showCreateSupplierModal"
            api-url="/api/suppliers/quick-store"
            entity-label="nhà cung cấp"
            :is-supplier="true"
            @close="showCreateSupplierModal = false"
            @created="(s) => {
                const exists = localSuppliers.some((item) => item.id === s.id);
                if (!exists) localSuppliers.push(s);

                selectedSupplierId = s.id;
                supplierQuery = '';
                showSupplierDropdown = false;
                showCreateSupplierModal = false;

                saveBrowserDraft(true);
            }"
        />

    </div>
</template>
