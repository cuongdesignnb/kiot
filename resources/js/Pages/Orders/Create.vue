<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import QuickCreateCustomerModal from '@/Components/QuickCreateCustomerModal.vue';

const props = defineProps({
    customers: Array,
    branches: Array,
    priceBooks: Array,
    invoice: Object,
    action: { type: String, default: 'edit' },
});

// Date helper
const pad = (n) => String(n).padStart(2, '0');
const getLocalNow = () => {
    const now = new Date();
    return `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
};

// Create an initial tab state template
// Create an initial tab state template
const createInitialTab = (index) => ({
    id: Date.now() + index,
    name: `Đặt hàng ${index}`,
    searchQuery: '',
    showSuggestions: false,
    items: [],
    
    searchCustomer: '',
    selectedCustomer: null,
    showCustomerDropdown: false,
    
    status: 'draft',
    invoice_id: null,
    discount: 0,
    otherFees: 0,
    amountPaid: 0,
    note: '',
    
    isDelivery: true,
    receiverName: '',
    receiverPhone: '',
    receiverAddress: '',
    receiverWard: '',
    receiverDistrict: '',
    receiverCity: '',
    weight: 500,
    sizeL: 10,
    sizeW: 10,
    sizeH: 10,
    deliveryNote: '',
    isCod: false,
    deliveryFee: 0,
    selectedBranchId: null,
    selectedPriceBookId: null,
    selectedPriceBookName: 'Bảng giá chung',
    orderDate: getLocalNow(),
});

const tabs = ref([createInitialTab(1)]);
const activeTabIndex = ref(0);
let tabCounter = 1;

const activeTab = computed(() => {
    return tabs.value[activeTabIndex.value] || tabs.value[0];
});

const addTab = () => {
    tabCounter++;
    tabs.value.push(createInitialTab(tabCounter));
    activeTabIndex.value = tabs.value.length - 1;
};

const switchTab = (index) => {
    activeTabIndex.value = index;
};

const closeTab = (index) => {
    if (tabs.value.length === 1) return;
    tabs.value.splice(index, 1);
    if (activeTabIndex.value >= tabs.value.length) {
        activeTabIndex.value = tabs.value.length - 1;
    } else if (activeTabIndex.value > index) {
        activeTabIndex.value--;
    }
};

const submitRef = ref(false);

// API-based product search with debounce
const searchResults = ref([]);
const searchLoading = ref(false);
let searchTimer = null;

const filteredProducts = computed(() => searchResults.value);

const resolvePriceBookName = (priceBookId) => {
    if (!priceBookId) return 'Bảng giá chung';
    const found = (props.priceBooks || []).find(pb => String(pb.id) === String(priceBookId));
    return found?.name || 'Bảng giá chung';
};

watch(() => [activeTab.value?.searchQuery, activeTab.value?.selectedPriceBookId], ([query, priceBookId]) => {
    clearTimeout(searchTimer);
    if (!query || query.length < 1) {
        searchResults.value = [];
        return;
    }
    searchLoading.value = true;
    searchTimer = setTimeout(async () => {
        try {
            const { data } = await axios.get('/api/products/search', {
                params: {
                    search: query,
                    price_book_id: priceBookId || undefined,
                }
            });
            searchResults.value = data;
        } catch (e) {
            searchResults.value = [];
        }
        searchLoading.value = false;
    }, 300);
});

const handlePriceBookChange = async () => {
    if (!activeTab.value) return;

    activeTab.value.selectedPriceBookName = resolvePriceBookName(activeTab.value.selectedPriceBookId);

    const productIds = activeTab.value.items.map(item => item.product_id);
    if (!productIds.length) return;

    try {
        const { data } = await axios.get('/api/products/search', {
            params: {
                product_ids: productIds,
                price_book_id: activeTab.value.selectedPriceBookId || undefined,
            }
        });

        const priceMap = new Map(
            (data || []).map(product => [
                product.id,
                Number(product.selling_price ?? product.retail_price ?? product.cost_price ?? 0)
            ])
        );

        activeTab.value.items.forEach(item => {
            if (priceMap.has(item.product_id)) {
                item.price = priceMap.get(item.product_id);
            }
        });
    } catch (e) {
        // Keep current prices if lookup fails.
    }
};

const selectProduct = (product) => {
    // If product has serial → open serial picker
    if (product.has_serial) {
        openSerialModal(product);
        activeTab.value.searchQuery = '';
        activeTab.value.showSuggestions = false;
        return;
    }

    const existing = activeTab.value.items.find(i => i.product_id === product.id);
    if (!existing) {
        activeTab.value.items.unshift({ 
            product_id: product.id,
            sku: product.sku,
            name: product.name,
            has_serial: false,
            qty: 1,
            price: product.selling_price || product.retail_price || product.cost_price || 0,
            discount: 0,
            stock_quantity: product.stock_quantity || 0,
            serial_ids: [],
            serial_names: [],
        });
    } else {
        existing.qty++;
    }
    activeTab.value.searchQuery = '';
    activeTab.value.showSuggestions = false;
};

// ── Serial/IMEI Selection Modal ──
const showSerialModal = ref(false);
const serialModalProduct = ref(null);
const availableSerials = ref([]);
const selectedSerialIds = ref([]);
const serialSearch = ref('');
const serialLoading = ref(false);

const filteredSerials = computed(() => {
    if (!serialSearch.value) return availableSerials.value;
    const q = serialSearch.value.toLowerCase();
    return availableSerials.value.filter(s => s.serial_number.toLowerCase().includes(q));
});

const isSerialSelected = (id) => selectedSerialIds.value.includes(id);

const toggleSerial = (id) => {
    const idx = selectedSerialIds.value.indexOf(id);
    if (idx > -1) {
        selectedSerialIds.value.splice(idx, 1);
    } else {
        selectedSerialIds.value.push(id);
    }
};

const toggleAllSerials = () => {
    if (selectedSerialIds.value.length === filteredSerials.value.length) {
        selectedSerialIds.value = [];
    } else {
        selectedSerialIds.value = filteredSerials.value.map(s => s.id);
    }
};

const openSerialModal = async (product) => {
    serialModalProduct.value = product;
    selectedSerialIds.value = [];
    serialSearch.value = '';
    availableSerials.value = [];
    showSerialModal.value = true;
    serialLoading.value = true;
    try {
        const res = await axios.get(`/api/products/${product.id}/serials`);
        availableSerials.value = res.data || [];
    } catch (e) {
        console.error('Error fetching serials:', e);
    } finally {
        serialLoading.value = false;
    }
};

const confirmSerialSelection = () => {
    if (!serialModalProduct.value || selectedSerialIds.value.length === 0) return;
    const product = serialModalProduct.value;

    // Each serial = 1 separate line item (1 serial = 1 máy)
    selectedSerialIds.value.forEach(serialId => {
        // Skip if already in cart
        const alreadyInCart = activeTab.value.items.some(i => i.serial_id === serialId);
        if (alreadyInCart) return;

        const serial = availableSerials.value.find(s => s.id === serialId);
        activeTab.value.items.unshift({
            product_id: product.id,
            sku: product.sku,
            name: product.name,
            is_serial_product: true,
            serial_id: serialId,
            serial_number: serial?.serial_number || '',
            qty: 1,
            price: product.selling_price || product.retail_price || product.cost_price || 0,
            discount: 0,
            stock_quantity: product.stock_quantity || 0,
        });
    });

    showSerialModal.value = false;
};

const hideSuggestions = () => {
    window.setTimeout(() => { if(activeTab.value) activeTab.value.showSuggestions = false; }, 200);
};

const hideCustomerDropdown = () => {
    window.setTimeout(() => { if(activeTab.value) activeTab.value.showCustomerDropdown = false; }, 200);
};

// Filter customers by search text
const filteredCustomers = computed(() => {
    const query = (activeTab.value?.searchCustomer || '').trim().toLowerCase();
    if (!query) return props.customers || [];
    return (props.customers || []).filter(c => {
        return (c.name || '').toLowerCase().includes(query)
            || (c.phone || '').includes(query)
            || (c.code || '').toLowerCase().includes(query);
    });
});

// Quick create customer modal
const showQuickCreateCustomer = ref(false);

const openQuickCreateCustomer = () => {
    showQuickCreateCustomer.value = true;
};

const onCustomerCreated = (customer) => {
    showQuickCreateCustomer.value = false;
    // Add to local list and select
    if (customer) {
        props.customers.push(customer);
        activeTab.value.selectedCustomer = customer;
        activeTab.value.searchCustomer = customer.name;
        activeTab.value.receiverName = customer.name;
        activeTab.value.receiverPhone = customer.phone || '';
        activeTab.value.receiverAddress = customer.address || '';
    }
};

const removeItem = (index) => {
    activeTab.value.items.splice(index, 1);
};

const itemsComputed = computed(() => {
    if (!activeTab.value) return [];
    return activeTab.value.items.map(item => {
        const qty = parseInt(item.qty) || 0;
        const price = parseFloat(item.price) || 0;
        const itemDiscount = parseFloat(item.discount) || 0;
        return { ...item, subtotal: (qty * price) - itemDiscount };
    });
});

const totalAmount = computed(() => itemsComputed.value.reduce((sum, item) => sum + item.subtotal, 0));
const totalPayment = computed(() => Math.max(0, totalAmount.value - Number(activeTab.value.discount) + Number(activeTab.value.otherFees)));
const balance = computed(() => activeTab.value.amountPaid - (activeTab.value.isCod ? 0 : totalPayment.value));

const save = async () => {
    if (activeTab.value.items.length === 0) {
        alert("Vui lòng chọn ít nhất 1 hàng hóa.");
        return;
    }
    submitRef.value = true;
    try {
        const isReturn = activeTab.value.status === 'return';
        const endpoint = isReturn ? '/returns' : '/orders';
        const payload = {
            status: activeTab.value.status,
            customer_id: activeTab.value.selectedCustomer?.id || null,
            branch_id: activeTab.value.selectedBranchId || (props.branches?.[0]?.id || null),
            note: activeTab.value.note,
            total_price: totalAmount.value,
            discount: activeTab.value.discount,
            total_payment: totalPayment.value,
            amount_paid: activeTab.value.amountPaid,
            price_book_id: activeTab.value.selectedPriceBookId,
            price_book_name: activeTab.value.selectedPriceBookName,
            order_date: activeTab.value.orderDate || null,
            items: itemsComputed.value,
            invoice_id: activeTab.value.invoice_id,
            subtotal: totalAmount.value,
            total: totalPayment.value,
            paid_to_customer: activeTab.value.amountPaid,
            other_fees: activeTab.value.otherFees,
            is_delivery: activeTab.value.isDelivery,
            receiver_name: activeTab.value.receiverName,
            receiver_phone: activeTab.value.receiverPhone,
            receiver_address: activeTab.value.receiverAddress,
            receiver_ward: activeTab.value.receiverWard,
            receiver_district: activeTab.value.receiverDistrict,
            receiver_city: activeTab.value.receiverCity,
            weight: activeTab.value.weight,
            delivery_fee: activeTab.value.deliveryFee,
            delivery_note: activeTab.value.deliveryNote,
            cod_amount: activeTab.value.isCod ? totalPayment.value : 0,
            length: activeTab.value.sizeL,
            width: activeTab.value.sizeW,
            height: activeTab.value.sizeH,
        };
        await router.post(endpoint, payload);
        if (tabs.value.length > 1) {
            closeTab(activeTabIndex.value);
        } else {
            tabs.value[0] = createInitialTab(++tabCounter);
        }
        submitRef.value = false;
    } catch (e) {
        alert("Có lỗi xảy ra, vui lòng kiểm tra lại dữ liệu.");
        submitRef.value = false;
    }
};

const formatCurrency = (val) => Number(val).toLocaleString('vi-VN');

const showReturnModal = ref(false);
const returnSearch = ref('');
const returnInvoices = ref([]);
const loadingReturns = ref(false);

const fetchReturnInvoices = async () => {
    loadingReturns.value = true;
    try {
        const res = await axios.get('/api/invoices/search', { params: { search: returnSearch.value } });
        returnInvoices.value = res.data;
    } catch (e) {
        console.error("Failed to fetch invoices", e);
    } finally {
        loadingReturns.value = false;
    }
};

const selectInvoiceForReturn = (invoice) => {
    activeTab.value.selectedCustomer = invoice.customer;
    activeTab.value.searchCustomer = invoice.customer?.name || '';
    activeTab.value.name = `Trả hàng ${invoice.code}`;
    activeTab.value.status = 'return';
    activeTab.value.invoice_id = invoice.id;
    activeTab.value.selectedPriceBookId = null;
    activeTab.value.selectedPriceBookName = invoice.price_book_name || 'Bảng giá chung';
    activeTab.value.discount = invoice.discount || 0;
    activeTab.value.items = (invoice.items || []).map(item => ({
        product_id: item.product_id,
        sku: item.product?.sku || '',
        name: item.product?.name || 'Sản phẩm',
        qty: item.quantity,
        price: item.price, 
        discount: item.discount || 0,
        stock_quantity: item.product?.stock_quantity || 0,
        subtotal: (item.quantity * item.price) - (item.discount || 0)
    }));
    showReturnModal.value = false;
};

const selectInvoiceForEdit = (invoice) => {
    activeTab.value.selectedCustomer = invoice.customer;
    activeTab.value.searchCustomer = invoice.customer?.name || '';
    activeTab.value.name = `Sửa HĐ ${invoice.code}`;
    activeTab.value.status = 'draft';
    activeTab.value.invoice_id = invoice.id;
    activeTab.value.selectedPriceBookId = null;
    activeTab.value.selectedPriceBookName = invoice.price_book_name || 'Bảng giá chung';
    activeTab.value.discount = invoice.discount || 0;
    activeTab.value.amountPaid = invoice.customer_paid || 0;
    activeTab.value.note = invoice.note || '';
    activeTab.value.receiverName = invoice.customer?.name || '';
    activeTab.value.receiverPhone = invoice.customer?.phone || '';
    activeTab.value.receiverAddress = invoice.customer?.address || '';
    activeTab.value.items = (invoice.items || []).map(item => ({
        product_id: item.product_id,
        sku: item.product?.sku || '',
        name: item.product?.name || 'Sản phẩm',
        qty: item.quantity,
        price: item.price, 
        discount: item.discount || 0,
        stock_quantity: item.product?.stock_quantity || 0,
        subtotal: (item.quantity * item.price) - (item.discount || 0)
    }));
};

const saveAndPrint = async () => {
    if (activeTab.value.items.length === 0) {
        alert("Vui lòng chọn ít nhất 1 hàng hóa.");
        return;
    }
    submitRef.value = true;
    try {
        const endpoint = activeTab.value.status === 'return' ? '/returns' : '/orders';
        const payload = {
            status: activeTab.value.status,
            customer_id: activeTab.value.selectedCustomer?.id || null,
            branch_id: activeTab.value.selectedBranchId || (props.branches?.[0]?.id || null),
            note: activeTab.value.note,
            total_price: totalAmount.value,
            discount: activeTab.value.discount,
            total_payment: totalPayment.value,
            amount_paid: activeTab.value.amountPaid,
            price_book_id: activeTab.value.selectedPriceBookId,
            price_book_name: activeTab.value.selectedPriceBookName,
            order_date: activeTab.value.orderDate || null,
            items: itemsComputed.value,
            invoice_id: activeTab.value.invoice_id,
            subtotal: totalAmount.value,
            total: totalPayment.value,
            paid_to_customer: activeTab.value.amountPaid,
            other_fees: activeTab.value.otherFees,
            is_delivery: activeTab.value.isDelivery,
            receiver_name: activeTab.value.receiverName,
            receiver_phone: activeTab.value.receiverPhone,
            receiver_address: activeTab.value.receiverAddress,
            receiver_ward: activeTab.value.receiverWard,
            receiver_district: activeTab.value.receiverDistrict,
            receiver_city: activeTab.value.receiverCity,
            weight: activeTab.value.weight,
            delivery_fee: activeTab.value.deliveryFee,
            delivery_note: activeTab.value.deliveryNote,
            cod_amount: activeTab.value.isCod ? totalPayment.value : 0,
            length: activeTab.value.sizeL,
            width: activeTab.value.sizeW,
            height: activeTab.value.sizeH,
            _print: true,
        };
        const res = await axios.post(endpoint, payload);
        if (res.data?.id) {
            window.open(`/orders/${res.data.id}/print`, '_blank');
        }
        if (tabs.value.length > 1) {
            closeTab(activeTabIndex.value);
        } else {
            tabs.value[0] = createInitialTab(++tabCounter);
        }
    } catch (e) {
        alert("Có lỗi xảy ra, vui lòng kiểm tra lại dữ liệu.");
    }
    submitRef.value = false;
};

const handleKeydown = (e) => {
    if (e.key === 'F9') { e.preventDefault(); saveAndPrint(); }
    if (e.key === 'F3') { e.preventDefault(); document.querySelector('input[placeholder*="Tìm hàng hóa"]')?.focus(); }
};

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
    const params = new URLSearchParams(window.location.search);
    const action = params.get('action') || props.action || 'edit';

    if (action === 'return' && !props.invoice) {
        // No invoice pre-loaded, show modal to select one
        showReturnModal.value = true;
        fetchReturnInvoices();
    } else if (props.invoice) {
        if (action === 'return') {
            selectInvoiceForReturn(props.invoice);
        } else {
            selectInvoiceForEdit(props.invoice);
        }
    }
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
});

</script>

<template>
    <Head title="Tạo đơn đặt hàng - KiotViet Clone">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" />
    </Head>
    <div class="h-screen w-screen flex flex-col bg-[#eef1f5] text-[13px] overflow-hidden font-sans fixed inset-0 z-50">
        
        <!-- Header POS (Blue) -->
        <header class="bg-[#0052a3] text-white px-2 h-[48px] flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-2 h-full flex-1 w-0">
                <div class="relative w-[340px] flex items-center h-[32px] bg-white rounded flex-shrink-0 z-50">
                    <i class="fas fa-search text-gray-400 absolute left-2 pointer-events-none"></i>
                    <input v-model="activeTab.searchQuery" @focus="activeTab.showSuggestions = true" @blur="hideSuggestions" type="text" class="w-full pl-8 pr-8 h-full rounded text-gray-800 outline-none text-[13px] border-none focus:ring-0" placeholder="Tìm hàng hóa (F3)">
                    <i class="fas fa-barcode text-gray-400 absolute right-2 text-lg hover:text-blue-500 cursor-pointer"></i>
                    
                    <div v-if="activeTab.showSuggestions && filteredProducts.length > 0" class="absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 shadow-xl rounded z-50 max-h-[300px] overflow-auto">
                        <div v-for="product in filteredProducts" :key="product.id" @mousedown.prevent="selectProduct(product)" class="flex items-center gap-3 p-2 border-b border-gray-100 hover:bg-gray-50 cursor-pointer text-gray-800">
                            <div class="flex-1">
                                <div class="font-medium text-[13px]">{{ product.name }}</div>
                                <div class="text-[12px] text-gray-500">{{ product.sku }}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-blue-600 font-medium text-[13px]">{{ formatCurrency(product.selling_price || product.retail_price || product.cost_price) }}</div>
                                <div class="text-[12px] text-gray-400">Tồn: {{ product.stock_quantity || 0 }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-[#00478e] w-[40px] h-[32px] rounded flex items-center justify-center cursor-pointer hover:bg-blue-800 ml-1 flex-shrink-0">
                    <i class="fas fa-expand"></i>
                </div>

                <!-- Tabs header -->
                <div class="flex items-end h-[48px] ml-4 bg-[#0052a3] overflow-x-auto overflow-y-hidden hide-scrollbar">
                    <div v-for="(tab, index) in tabs" :key="tab.id" 
                         @click="switchTab(index)"
                         :class="[
                            'px-4 py-1.5 rounded-t-lg flex items-center gap-3 h-[36px] cursor-pointer whitespace-nowrap transition-colors border-r border-[#00478e]/50 flex-shrink-0',
                            activeTabIndex === index ? 'bg-white text-[#0062c3] font-bold shadow-sm' : 'bg-[#00478e] text-white hover:bg-[#003d7a]'
                         ]">
                        <i class="fas fa-exchange-alt"></i> {{ tab.name }}
                        <i v-if="tab.items.length > 0" class="fas fa-truck" :class="activeTabIndex === index ? 'text-gray-300' : 'text-blue-300'"></i>
                        <i class="fas fa-times cursor-pointer" 
                           :class="activeTabIndex === index ? 'text-gray-400 hover:text-red-500' : 'text-blue-200 hover:text-white'"
                           @click.stop="closeTab(index)"></i>
                    </div>
                    <div @click="addTab" class="text-white hover:bg-white hover:text-[#0062c3] px-3 h-[36px] ml-1 rounded-t flex items-center justify-center font-bold cursor-pointer transition-colors duration-150 flex-shrink-0">
                        +
                    </div>
                </div>
            </div>

            <!-- Right header icons -->
            <div class="flex justify-end items-center gap-4 text-white text-[16px] pr-2 flex-shrink-0 pl-4">
                <i class="fas fa-lock cursor-pointer opacity-80 hover:opacity-100"></i>
                <i class="fas fa-undo cursor-pointer opacity-80 hover:opacity-100"></i>
                <i class="fas fa-sync cursor-pointer opacity-80 hover:opacity-100"></i>
                <i class="fas fa-print cursor-pointer opacity-80 hover:opacity-100"></i>
                <span class="text-[14px] font-bold ml-2">0985133992 <i class="fas fa-caret-down text-[12px]"></i></span>
                <Link href="/orders" class="text-white hover:text-blue-200 ml-2"><i class="fas fa-bars cursor-pointer opacity-80 hover:opacity-100"></i></Link>
            </div>
        </header>

        <div class="flex flex-1 overflow-hidden">
            <!-- Left Panel: List Items -->
            <div class="flex-[3_3_0%] bg-white flex flex-col border-r border-[#dce3ec] shadow-sm relative z-10 w-0">
                <div class="flex-1 overflow-auto">
                    <table class="w-full text-left whitespace-nowrap">
                        <thead class="text-gray-800 font-bold border-b border-gray-200 sticky top-0 bg-white shadow-sm z-10">
                            <tr>
                                <th class="py-2.5 px-2 w-10 text-center text-gray-400"><i class="fas fa-trash-alt"></i></th>
                                <th class="py-2.5 px-3 w-16">Mã hàng</th>
                                <th class="py-2.5 px-3">Tên hàng</th>
                                <th class="py-2.5 px-3 text-center w-32">Số lượng</th>
                                <th class="py-2.5 px-3 text-right">Đơn giá</th>
                                <th class="py-2.5 px-3 text-right">Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, index) in itemsComputed" :key="index" class="border-b border-gray-100 hover:bg-blue-50/20 group">
                                <td class="p-2 text-center text-red-300 cursor-pointer hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity" @click="removeItem(index)">
                                    <i class="fas fa-minus-circle"></i>
                                </td>
                                <td class="p-3 text-gray-800 text-[12px]">{{ item.sku }}</td>
                                <td class="p-3 font-medium text-gray-800">
                                    <div class="truncate w-[150px] lg:w-[250px] xl:w-[350px]">{{ item.name }}</div>
                                    <!-- Serial number chip -->
                                    <span v-if="item.is_serial_product && item.serial_number" class="inline-block bg-blue-50 text-blue-700 text-[10px] px-2 py-0.5 rounded font-mono mt-1 border border-blue-200">
                                        SN: {{ item.serial_number }}
                                    </span>
                                </td>
                                <td class="p-3">
                                    <template v-if="item.is_serial_product">
                                        <div class="text-center text-blue-600 font-bold text-[13px]">1</div>
                                    </template>
                                    <template v-else>
                                        <div class="flex items-center justify-center gap-1 border border-transparent hover:border-blue-400 rounded overflow-hidden w-fit mx-auto transition-colors">
                                            <button class="px-2 py-1 text-gray-400 hover:text-gray-700 font-bold" @click="activeTab.items[index].qty > 1 ? activeTab.items[index].qty-- : null"><i class="fas fa-minus text-[10px]"></i></button>
                                            <input type="text" v-model="activeTab.items[index].qty" class="w-10 text-center outline-none text-[13px] border-b border-transparent focus:border-blue-500 py-0.5 text-blue-600 font-bold">
                                            <button class="px-2 py-1 text-gray-400 hover:text-gray-700 font-bold" @click="activeTab.items[index].qty++"><i class="fas fa-plus text-[10px]"></i></button>
                                        </div>
                                    </template>
                                </td>
                                <td class="p-3 text-right font-medium text-gray-800">
                                    <input type="text" :value="formatCurrency(item.price)" @change="e => activeTab.items[index].price = Number(e.target.value.replace(/\D/g,''))" class="w-24 border-b border-transparent hover:border-gray-300 focus:border-blue-500 text-right outline-none bg-transparent">
                                </td>
                                <td class="p-3 text-right font-bold text-gray-800 pr-4">{{ formatCurrency(item.subtotal) }}</td>
                            </tr>
                            <tr v-if="itemsComputed.length === 0">
                                <td colspan="6" class="p-12 text-center text-gray-400 relative">
                                    <svg class="w-12 h-12 mx-auto text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
                                    Chưa có sản phẩm nào
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer sums Desktop (Left panel bottom) -->
                <div class="h-[140px] border-t border-gray-200 flex flex-shrink-0 bg-white">
                    <div class="w-1/2 p-3 border-r border-gray-200">
                        <div class="flex items-start gap-2 h-full text-gray-500">
                            <i class="fas fa-pencil-alt mt-1.5 opacity-60"></i>
                            <textarea v-model="activeTab.note" class="w-full h-full resize-none outline-none text-[13px] hover:bg-gray-50 focus:bg-white p-1 rounded transition-colors text-gray-700" placeholder="Ghi chú đơn hàng"></textarea>
                        </div>
                    </div>
                    <div class="w-1/2 px-4 py-3 flex flex-col justify-between text-[13px] font-medium text-gray-700">
                        <div class="flex justify-between items-center mb-1">
                            <span>Tổng tiền hàng</span>
                            <span class="font-bold text-gray-800">
                               <span class="text-gray-800 w-8 inline-block text-center mr-2">{{ itemsComputed.reduce((s,i)=>s+i.qty, 0) }}</span> 
                               {{ formatCurrency(totalAmount) }}
                            </span>
                        </div>
                        <div class="flex justify-between items-center mb-1">
                            <span>Giảm giá</span>
                            <div class="border-b border-gray-300 hover:border-blue-500 w-24 transition-colors">
                                <input type="text" :value="formatCurrency(activeTab.discount)" @change="e => activeTab.discount = Number(e.target.value.replace(/\D/g, ''))" class="w-full text-right outline-none bg-transparent text-gray-800">
                            </div>
                        </div>
                        <div class="flex justify-between items-center mb-2">
                            <span>Thu khác</span>
                            <div class="border-b border-gray-300 hover:border-blue-500 w-24 transition-colors">
                                <input type="text" :value="formatCurrency(activeTab.otherFees)" @change="e => activeTab.otherFees = Number(e.target.value.replace(/\D/g, ''))" class="w-full text-right outline-none bg-transparent text-gray-800">
                            </div>
                        </div>
                        <div class="flex justify-between items-center text-[15px] font-bold text-gray-800 pt-1">
                            <span>Khách cần trả</span>
                            <span class="text-blue-600">{{ formatCurrency(totalPayment) }}</span>
                        </div>
                    </div>
                </div>
                
                <div class="h-[40px] bg-[#f8f9fc] border-t border-gray-200 flex items-center justify-between px-3 text-[13px] text-gray-600 flex-shrink-0">
                    <div class="flex items-center gap-4">
                        <div class="cursor-pointer hover:text-blue-600 flex items-center gap-1.5"><i class="fas fa-bolt text-blue-500"></i> Bán nhanh</div>
                        <div class="cursor-pointer hover:text-blue-600 flex items-center gap-1.5"><i class="fas fa-clock text-gray-400"></i> Bán thường</div>
                        <div class="cursor-pointer font-bold text-blue-600 bg-white px-2 py-1 rounded border border-blue-200 shadow-sm flex items-center gap-1.5"><i class="fas fa-truck text-blue-500"></i> Bán giao hàng</div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-blue-600 cursor-pointer font-bold"><i class="fas fa-comment-dots"></i> 1900 6522</div>
                        <i class="fas fa-question-circle text-blue-600 text-[16px] cursor-pointer"></i>
                    </div>
                </div>
            </div>

            <!-- Middle Side: Customer & Address -->
            <div class="w-[320px] bg-white flex flex-col flex-shrink-0 border-r border-[#dce3ec] z-20">
                <!-- Top area Customer info -->
                <div class="p-3 border-b border-[#dce3ec] relative shadow-[0_2px_4px_-2px_rgba(0,0,0,0.05)]">
                    <div v-if="activeTab.selectedCustomer" class="flex justify-between items-center mb-2 cursor-pointer hover:bg-gray-50 -mx-1 px-1 rounded" @click="activeTab.selectedCustomer = null">
                       <div class="font-bold text-gray-800 text-[14px] flex items-center gap-1.5">
                           {{ activeTab.selectedCustomer.name }} 
                           <i class="fas fa-walking text-gray-400"></i> 
                           <i class="fas fa-caret-down text-gray-400"></i>
                       </div>
                       <div class="text-[12px] text-gray-500">
                           <input type="datetime-local" v-model="activeTab.orderDate" class="bg-transparent border-b border-dashed border-gray-300 outline-none focus:border-blue-500 text-[12px] text-gray-500 py-0.5 w-[170px]" />
                       </div>
                    </div>
                    <div v-else class="flex justify-end text-[12px] text-gray-500 mb-2">
                        <input type="datetime-local" v-model="activeTab.orderDate" class="bg-transparent border-b border-dashed border-gray-300 outline-none focus:border-blue-500 text-[12px] text-gray-500 py-0.5 w-[170px]" />
                    </div>
                    
                    <div class="flex gap-2 relative">
                        <!-- Search dropdown -->
                       <div class="relative flex-1">
                          <i class="fas fa-search absolute left-2 top-2 text-gray-400"></i>
                           <input v-model="activeTab.searchCustomer" @focus="activeTab.showCustomerDropdown = true" @blur="hideCustomerDropdown" placeholder="Tìm khách hàng (F4)" class="w-full border-b border-gray-300 outline-none focus:border-blue-500 py-1 pl-7 pr-6 text-[13px]" />
                          <button type="button" @click="openQuickCreateCustomer" class="absolute right-0 top-0.5 text-gray-400 hover:text-blue-600 font-bold px-1" title="Thêm khách hàng mới"><i class="fas fa-plus"></i></button>
                          
                          <!-- Dropdown Results -->
                          <div v-if="activeTab.showCustomerDropdown && filteredCustomers.length > 0" class="absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 shadow-xl rounded z-50 max-h-[200px] overflow-auto">
                              <div v-for="c in filteredCustomers" :key="c.id" @mousedown.prevent="activeTab.selectedCustomer = c; activeTab.searchCustomer = c.name; activeTab.receiverName = c.name; activeTab.receiverPhone = c.phone || ''; activeTab.receiverAddress = c.address || '';" class="p-2 border-b border-gray-100 hover:bg-blue-50 cursor-pointer">
                                  <div class="font-bold text-gray-800">{{ c.name }}</div>
                                  <div v-if="c.phone" class="text-[12px] text-green-600">{{ c.phone }}</div>
                              </div>
                          </div>
                          <div v-if="activeTab.showCustomerDropdown && activeTab.searchCustomer && filteredCustomers.length === 0" class="absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 shadow-xl rounded z-50 p-3 text-center text-gray-400 text-[13px]">
                              Không tìm thấy khách hàng
                              <button @mousedown.prevent="openQuickCreateCustomer" class="block mx-auto mt-2 text-blue-600 hover:underline font-medium">+ Tạo khách hàng mới</button>
                          </div>
                       </div>
                       <select
                           v-model="activeTab.selectedPriceBookId"
                           @change="handlePriceBookChange"
                           class="border border-gray-300 rounded outline-none text-[13px] px-1 bg-gray-50/50"
                       >
                           <option :value="null">Bảng giá chung</option>
                           <option v-for="pb in priceBooks" :key="pb.id" :value="pb.id">{{ pb.name }}</option>
                       </select>
                    </div>
                </div>

                <!-- Address & Package Info Scrollable Area -->
                <div class="flex-1 overflow-auto flex flex-col">
                    <div class="p-4 border-b border-[#dce3ec]">
                        <div class="flex items-start gap-2 mb-3">
                            <i class="fas fa-map-marker-alt text-green-500 mt-1"></i>
                            <select v-model="activeTab.selectedBranchId" class="w-full text-[13px] border-b border-gray-300 py-1 outline-none text-gray-700 font-medium">
                                <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }} - {{ branch.address }}</option>
                            </select>
                        </div>
                        <div class="flex gap-4 mb-3">
                            <div class="flex-1 relative">
                                <div class="w-2 h-2 rounded-full bg-green-500 absolute -left-4 top-2.5 border border-white"></div>
                                <input v-model="activeTab.receiverName" placeholder="Tên người nhận" class="w-full border-b border-gray-300 outline-none focus:border-blue-500 py-1 text-[13px]">
                            </div>
                            <div class="flex-1">
                                <input v-model="activeTab.receiverPhone" placeholder="Số điện thoại" class="w-full border-b border-gray-300 outline-none focus:border-blue-500 py-1 text-[13px]">
                            </div>
                        </div>
                        <div class="mb-3 pl-2 border-l-2 border-dotted border-gray-300 -ml-[13px] pl-6 space-y-3">
                            <input v-model="activeTab.receiverAddress" placeholder="Địa chỉ chi tiết (Số nhà, ngõ, đường)" class="w-full border-b border-gray-300 outline-none focus:border-blue-500 py-1 text-[13px]"/>
                            <input v-model="activeTab.receiverDistrict" placeholder="Khu vực" class="w-full border-b border-gray-300 outline-none focus:border-blue-500 py-1 text-[13px]"/>
                            <input v-model="activeTab.receiverWard" placeholder="Phường/Xã" class="w-full border-b border-gray-300 outline-none focus:border-blue-500 py-1 text-[13px]"/>
                        </div>
                    </div>
                    
                    <div class="p-4 flex-1">
                        <div class="font-bold flex items-center gap-2 mb-3 text-gray-700">
                           <i class="fas fa-box text-gray-500"></i> 1 kiện
                        </div>
                        <div class="flex items-center gap-2 mb-4 text-[13px] text-gray-600 font-medium whitespace-nowrap">
                           <input v-model="activeTab.weight" class="w-10 border-b border-gray-300 text-center outline-none focus:border-blue-500"/> g &nbsp;-&nbsp; 
                           <input v-model="activeTab.sizeL" class="w-6 border-b border-gray-300 text-center outline-none focus:border-blue-500"/> x 
                           <input v-model="activeTab.sizeW" class="w-6 border-b border-gray-300 text-center outline-none focus:border-blue-500"/> x 
                           <input v-model="activeTab.sizeH" class="w-6 border-b border-gray-300 text-center outline-none focus:border-blue-500"/> cm
                        </div>
                        <div class="flex items-center gap-2 text-gray-500">
                            <i class="fas fa-edit mt-1 opacity-70"></i>
                            <input v-model="activeTab.deliveryNote" placeholder="Ghi chú cho bưu tá" class="w-full border-b border-gray-300 outline-none focus:border-blue-500 py-1 text-[13px]"/>
                        </div>
                    </div>
                </div>
                
                <!-- Payment details inside customer panel -->
                <div class="p-4 bg-white border-t border-[#dce3ec] flex-shrink-0">
                    <div class="flex justify-between items-center mb-3 text-[13px] text-gray-700">
                       <span class="font-bold flex items-center gap-1 cursor-pointer hover:text-blue-600">Khách thanh toán <i class="fas fa-th-list text-[11px] ml-1"></i></span>
                       <div class="border-b border-gray-300 hover:border-blue-500 w-24 transition-colors">
                           <input type="text" :value="formatCurrency(activeTab.amountPaid)" @change="e => activeTab.amountPaid = Number(e.target.value.replace(/\D/g, ''))" class="w-full text-right outline-none bg-transparent font-bold text-gray-800">
                       </div>
                    </div>
                    <div class="flex justify-between items-center mb-3">
                       <span class="font-bold text-gray-700">Thu hộ tiền (COD)</span>
                       <div class="flex items-center gap-3">
                          <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" v-model="activeTab.isCod" class="sr-only peer">
                            <div class="w-8 h-4 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3.5 after:w-3.5 after:transition-all peer-checked:bg-blue-600"></div>
                          </label>
                          <span class="font-bold text-[15px] text-gray-800 w-20 text-right">{{ formatCurrency(activeTab.isCod ? totalPayment : 0) }}</span>
                       </div>
                    </div>
                    <div class="flex justify-between items-center text-[13px] text-gray-500">
                       <span>Tiền thừa trả khách</span>
                       <span class="font-bold text-gray-600">{{ balance < 0 ? '-' : '' }} {{ formatCurrency(Math.abs(balance)) }}</span>
                    </div>
                </div>
            </div>

            <!-- Right Side: Delivery Service -->
            <div class="w-[280px] bg-white flex flex-col flex-shrink-0 relative z-30 shadow-[-2px_0_5px_-2px_rgba(0,0,0,0.05)]">
                <!-- Toggle Chevron -->
                <div class="absolute -left-3 top-1/2 -translate-y-1/2 w-6 h-10 bg-white border border-[#dce3ec] rounded-full flex items-center justify-center cursor-pointer z-10 shadow-sm hover:bg-gray-50 text-gray-400">
                    <i class="fas fa-angle-double-right"></i>
                </div>

                <div class="flex text-[13px] border-b border-[#dce3ec] font-bold">
                    <div class="flex-1 text-center py-2.5 bg-white text-blue-600 border-b-2 border-blue-600 cursor-pointer shadow-sm flex items-center justify-center gap-2">
                       <i class="fas fa-truck text-blue-500"></i> Cổng KiotViet
                    </div>
                    <div class="flex-1 text-center py-2.5 bg-[#f8f9fc] text-gray-500 cursor-pointer hover:bg-gray-50 border-b-2 border-transparent">
                       <i class="fas fa-user mb-0.5"></i> Tự giao hàng
                    </div>
                </div>
                
                <div class="p-4 flex-1 flex flex-col bg-[#f4f6f8]">
                    <div class="bg-blue-50 text-[#0062c3] text-[12px] p-2.5 rounded flex items-start gap-2 mb-4 border border-blue-100 shadow-sm">
                        <i class="fas fa-info-circle mt-0.5"></i>
                        <span>Nhập đầy đủ địa chỉ lấy và giao (cùng loại cũ/mới) để tra cước và tạo đơn.</span>
                    </div>
                    
                    <div class="flex justify-end mb-2">
                        <i class="fas fa-cog text-gray-400 cursor-pointer hover:text-gray-600"></i>
                    </div>

                    <!-- Ads Banner Mock -->
                    <div class="rounded-lg overflow-hidden border border-gray-200 shadow-sm relative block cursor-pointer group">
                        <img src="https://ui-avatars.com/api/?name=Ahamove&background=0D8BD1&color=fff&size=400&font-size=0.15&length=7" alt="Ahamove" class="w-full h-[300px] object-cover mix-blend-multiply" />
                        <div class="absolute inset-0 bg-gradient-to-b from-[#005bb5]/80 to-[#005bb5]/90 p-4 flex flex-col items-center justify-center text-white text-center">
                            <div class="bg-white/20 px-3 py-1 rounded-full text-[12px] font-bold mb-3 flex items-center gap-1">
                                <i class="fas fa-star text-yellow-300"></i> Đối tác giao hàng
                            </div>
                            <h3 class="text-[20px] font-bold leading-tight mb-2">GIAO HÀNG TRÊN KIOTVIET</h3>
                            <p class="text-[13px] opacity-90 mb-4">Kết nối mọi đơn vị vận chuyển hàng đầu nhanh chóng.</p>
                            <img src="https://ui-avatars.com/api/?name=Sale+15%25&background=ff9900&color=fff&rounded=true&font-size=0.25" class="w-16 h-16 shadow-lg mb-2">
                        </div>
                    </div>
                </div>

                <div class="p-3 bg-white border-t border-[#dce3ec] flex-shrink-0">
                    <button @click="save" :disabled="submitRef" class="w-full bg-[#0062c3] hover:bg-blue-700 text-white font-bold py-3 px-4 rounded transition-colors text-[16px] shadow-sm flex items-center justify-center gap-2">
                        <i v-if="submitRef" class="fas fa-circle-notch fa-spin"></i>
                        {{ activeTab.status === 'return' ? 'TRẢ HÀNG' : 'ĐẶT HÀNG' }}
                    </button>
                    <div @click="saveAndPrint" class="text-center font-bold text-gray-500 mt-2 text-[12px] cursor-pointer hover:text-blue-600"><i class="fas fa-print"></i> (F9)</div>
                </div>
            </div>

        </div>
    </div>

    <!-- Return Invoice Modal -->
    <div v-if="showReturnModal" class="fixed inset-0 z-[100] bg-black/40 flex items-center justify-center font-sans text-[13px]">
        <div class="bg-white rounded shadow-xl w-[900px] flex flex-col h-[500px]">
            <!-- Modal Header -->
            <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-[16px] font-bold text-gray-800">Chọn hóa đơn trả hàng</h3>
                <button @click="showReturnModal = false" class="text-gray-400 hover:text-gray-600 focus:outline-none">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            
            <!-- Modal Body -->
            <div class="flex-1 overflow-hidden flex bg-gray-50/50 p-4 gap-4">
                <!-- Sidebar search -->
                <div class="w-[240px] flex flex-col gap-4">
                    <div class="bg-white border text-gray-800 border-gray-200 rounded p-3 shadow-sm">
                        <h4 class="font-bold mb-3">Tìm kiếm</h4>
                        <div class="space-y-3">
                            <input v-model="returnSearch" @input="fetchReturnInvoices" type="text" placeholder="Theo mã hóa đơn, mã KH, sđt..." class="w-full text-[13px] border-b border-gray-200 pb-1 outline-none text-gray-700 bg-transparent placeholder-gray-400">
                            <input disabled type="text" placeholder="Theo mã vận đơn bán" class="w-full text-[13px] border-b border-gray-200 pb-1 outline-none text-gray-700 bg-transparent placeholder-gray-400 opacity-50 cursor-not-allowed">
                            <input disabled type="text" placeholder="Theo Serial/IMEI" class="w-full text-[13px] border-b border-gray-200 pb-1 outline-none text-gray-700 bg-transparent placeholder-gray-400 opacity-50 cursor-not-allowed">
                        </div>
                    </div>
                </div>
                
                <!-- Main table -->
                <div class="flex-1 bg-white border border-gray-200 rounded shadow-sm flex flex-col overflow-hidden">
                    <div class="bg-blue-500 text-white flex text-[13px] font-bold items-center sticky top-0 h-[40px] px-2 flex-shrink-0">
                        <div class="w-1/4 px-2 text-[12px]">Mã hóa đơn</div>
                        <div class="w-1/4 px-2 text-[12px]">Thời gian</div>
                        <div class="w-1/4 px-2 text-[12px]">Khách hàng</div>
                        <div class="w-[25%] px-2 text-right text-[12px]">Tổng cộng</div>
                    </div>
                    
                    <div class="flex-1 overflow-auto">
                        <div v-if="loadingReturns" class="p-8 text-center text-gray-500">
                             <i class="fas fa-circle-notch fa-spin text-xl"></i>
                        </div>
                        <template v-else-if="returnInvoices.length > 0">
                            <div v-for="invoice in returnInvoices" 
                                 :key="invoice.id" 
                                 @click="selectInvoiceForReturn(invoice)"
                                 class="flex text-[13px] items-center border-b border-gray-100 hover:bg-blue-50 cursor-pointer py-2.5 px-2 transition-colors">
                                <div class="w-1/4 px-2 font-bold text-blue-600">{{ invoice.code }}</div>
                                <div class="w-1/4 px-2 text-gray-600">{{ new Date(invoice.created_at).toLocaleString() }}</div>
                                <div class="w-1/4 px-2 text-gray-800">{{ invoice.customer?.name || 'Khách lẻ' }}</div>
                                <div class="w-[25%] px-2 text-right font-bold">{{ formatCurrency(invoice.total) }}</div>
                            </div>
                        </template>
                        <div v-else class="h-full flex flex-col items-center justify-center text-gray-500 py-10">
                            <svg class="w-12 h-12 text-gray-200 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                            <p class="text-[12px]">Không tìm thấy hóa đơn nào phù hợp</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Modal Footer -->
            <div class="px-4 py-3 flex justify-end">
                <button @click="showReturnModal = false" class="bg-[#0070f4] hover:bg-blue-600 text-white font-bold px-6 py-1.5 rounded shadow-sm">
                    Trả nhanh
                </button>
            </div>
        </div>
    </div>

    <!-- ═══ Serial/IMEI Selection Modal ═══ -->
    <div v-if="showSerialModal" class="fixed inset-0 z-[200] bg-black/40 flex items-center justify-center font-sans text-[13px]">
        <div class="bg-white rounded-lg shadow-2xl w-[520px] max-h-[80vh] flex flex-col">
            <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-800">Chọn Serial/IMEI</h3>
                <button @click="showSerialModal = false" class="text-gray-400 hover:text-gray-600 text-xl cursor-pointer">&times;</button>
            </div>
            <div class="px-5 pt-3 pb-2">
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input v-model="serialSearch" type="text" placeholder="Tìm serial/IMEI..." class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded text-sm outline-none focus:border-blue-500">
                </div>
            </div>
            <div class="flex-1 overflow-auto px-5 py-3 min-h-[200px]">
                <div v-if="serialLoading" class="flex items-center justify-center h-32 text-gray-400">
                    <i class="fas fa-spinner fa-spin text-blue-500 mr-2"></i> Đang tải...
                </div>
                <div v-else-if="filteredSerials.length === 0" class="flex flex-col items-center justify-center h-32 text-gray-400">
                    <span class="text-sm">Không tìm thấy serial nào</span>
                </div>
                <div v-else class="flex flex-wrap gap-2">
                    <button 
                        v-for="serial in filteredSerials" 
                        :key="serial.id"
                        @click="toggleSerial(serial.id)"
                        class="px-3 py-1.5 rounded border text-sm font-mono transition-all cursor-pointer"
                        :class="isSerialSelected(serial.id) 
                            ? 'bg-blue-600 text-white border-blue-600 shadow-sm' 
                            : 'bg-white text-gray-700 border-gray-300 hover:border-blue-400 hover:bg-blue-50'"
                    >
                        {{ serial.serial_number }}
                    </button>
                </div>
            </div>
            <div class="px-5 py-3 border-t border-gray-200 flex items-center justify-between bg-gray-50/50">
                <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-600">
                    <input 
                        type="checkbox" 
                        :checked="filteredSerials.length > 0 && selectedSerialIds.length === filteredSerials.length"
                        @change="toggleAllSerials"
                        class="rounded border-gray-300 text-blue-600 w-4 h-4"
                    >
                    Chọn tất cả <span v-if="selectedSerialIds.length > 0" class="text-blue-600 font-bold">({{ selectedSerialIds.length }})</span>
                </label>
                <div class="flex gap-2">
                    <button @click="showSerialModal = false" class="px-5 py-2 border border-gray-300 rounded text-sm font-semibold text-gray-600 hover:bg-gray-100 cursor-pointer">Hủy</button>
                    <button 
                        @click="confirmSerialSelection" 
                        :disabled="selectedSerialIds.length === 0"
                        class="px-5 py-2 bg-blue-600 text-white rounded text-sm font-bold hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                    >Xong</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Create Customer Modal -->
    <QuickCreateCustomerModal
        :show="showQuickCreateCustomer"
        :initial-name="activeTab?.searchCustomer || ''"
        api-url="/api/pos/customers"
        entity-label="khách hàng"
        @close="showQuickCreateCustomer = false"
        @created="onCustomerCreated"
    />
</template>

<style scoped>
.hide-scrollbar::-webkit-scrollbar {
    display: none;
}
.hide-scrollbar {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
</style>
