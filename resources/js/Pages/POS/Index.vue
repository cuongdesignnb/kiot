<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import QuickCreateCustomerModal from '@/Components/QuickCreateCustomerModal.vue';

const props = defineProps({
    employees: Array,
    bankAccounts: Array,
});

// State for search and products (global)
const query = ref('');
const products = ref([]);
const isSearching = ref(false);

// ── Multi-tab POS system ──
let tabIdCounter = 1;
const createNewTab = () => ({
    id: tabIdCounter++,
    cart: [],
    discount: 0,
    customerPaid: 0,
    paymentMethod: 'cash',
    bankAccountInfo: '',
    selectedCustomer: null,
    customerQuery: '',
    saleMode: 'normal',
});

const tabs = ref([createNewTab()]);
const activeTabIndex = ref(0);
const activeTab = computed(() => tabs.value[activeTabIndex.value]);

// Per-tab computed proxies — all existing code continues using these
const cart = computed({
    get: () => activeTab.value.cart,
    set: (v) => { activeTab.value.cart = v; }
});
const discount = computed({
    get: () => activeTab.value.discount,
    set: (v) => { activeTab.value.discount = v; }
});
const customerPaid = computed({
    get: () => activeTab.value.customerPaid,
    set: (v) => { activeTab.value.customerPaid = v; }
});
const paymentMethod = computed({
    get: () => activeTab.value.paymentMethod,
    set: (v) => { activeTab.value.paymentMethod = v; }
});
const bankAccountInfo = computed({
    get: () => activeTab.value.bankAccountInfo,
    set: (v) => { activeTab.value.bankAccountInfo = v; }
});
const saleMode = computed({
    get: () => activeTab.value.saleMode,
    set: (v) => { activeTab.value.saleMode = v; }
});

// Tab management
const addTab = () => {
    tabs.value.push(createNewTab());
    activeTabIndex.value = tabs.value.length - 1;
};
const switchTab = (idx) => {
    activeTabIndex.value = idx;
};
const closeTab = (idx) => {
    if (tabs.value.length <= 1) return;
    tabs.value.splice(idx, 1);
    if (activeTabIndex.value >= tabs.value.length) {
        activeTabIndex.value = tabs.value.length - 1;
    }
};
const tabLabel = (tab) => tab.saleMode === 'quick_order' ? 'Đặt hàng' : 'Hóa đơn';

// Employee & time (global)
const selectedEmployeeId = ref('');
const currentTime = ref('');

// Ngày bán
const pad = (n) => String(n).padStart(2, '0');
const nowInit = new Date();
const localNowStr = `${nowInit.getFullYear()}-${pad(nowInit.getMonth()+1)}-${pad(nowInit.getDate())}T${pad(nowInit.getHours())}:${pad(nowInit.getMinutes())}`;
const saleDate = ref(localNowStr);

const updateTime = () => {
    const now = new Date();
    currentTime.value = now.toLocaleString('vi-VN', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
};

let timeInterval;
onMounted(() => {
    updateTime();
    timeInterval = setInterval(updateTime, 1000);
    searchProducts();
    loadDraft();
});
onUnmounted(() => {
    clearInterval(timeInterval);
});

// ── Customer Search ──
const customerQuery = computed({
    get: () => activeTab.value.customerQuery,
    set: (v) => { activeTab.value.customerQuery = v; }
});
const customerResults = ref([]);
const selectedCustomer = computed({
    get: () => activeTab.value.selectedCustomer,
    set: (v) => { activeTab.value.selectedCustomer = v; }
});
const showCustomerDropdown = ref(false);
const customerSearching = ref(false);
let customerTimeout;

const searchCustomers = async () => {
    if (customerQuery.value.length < 1) {
        customerResults.value = [];
        showCustomerDropdown.value = false;
        return;
    }
    customerSearching.value = true;
    try {
        const res = await axios.get('/api/pos/customers', { params: { search: customerQuery.value } });
        customerResults.value = res.data || [];
        showCustomerDropdown.value = true;
    } catch (e) {
        console.error(e);
    } finally {
        customerSearching.value = false;
    }
};

const handleCustomerInput = () => {
    clearTimeout(customerTimeout);
    customerTimeout = setTimeout(searchCustomers, 300);
};

const selectCustomer = (customer) => {
    selectedCustomer.value = customer;
    customerQuery.value = '';
    showCustomerDropdown.value = false;
    customerResults.value = [];
};

const clearCustomer = () => {
    selectedCustomer.value = null;
    customerQuery.value = '';
};

// ── Quick Create Customer Modal ──
const showNewCustomerModal = ref(false);
const newCustomerInitialName = ref('');

const openNewCustomerModal = () => {
    newCustomerInitialName.value = customerQuery.value || '';
    showNewCustomerModal.value = true;
};

const onCustomerCreated = (customer) => {
    selectedCustomer.value = customer;
    customerQuery.value = '';
    showCustomerDropdown.value = false;
};

// ── Inline Serial/IMEI Logic ──
const loadSerialsForProduct = async (item) => {
    item.serialLoading = true;
    try {
        const res = await axios.get(`/api/products/${item.product.id}/serials`);
        item.allAvailableSerials = res.data || [];
        searchSerialsForItem(item);
    } catch (e) {
        console.error('Error fetching serials:', e);
    } finally {
        item.serialLoading = false;
    }
};

const searchSerialsForItem = (item, isFocus = false) => {
    if (!item.allAvailableSerials) return;
    const q = (item.serialInput || '').toLowerCase().trim();
    item.availableSerials = item.allAvailableSerials.filter(s => 
        !item.serials.some(selected => selected.id === s.id) && 
        s.serial_number.toLowerCase().includes(q)
    );
};

const selectSerialForItem = (item, serialObj) => {
    if (!item.serials.find(s => s.id === serialObj.id)) {
        item.serials.push(serialObj);
        item.quantity = item.serials.length;
    }
    item.serialInput = '';
    item.showSerialDropdown = false;
    searchSerialsForItem(item);
};

const addSerialToItem = (item) => {
    const q = (item.serialInput || '').trim().toLowerCase();
    if (!q) return;
    
    // exact match from available
    const exactMatch = item.availableSerials.find(s => s.serial_number.toLowerCase() === q);
    if (exactMatch) {
        selectSerialForItem(item, exactMatch);
    } else {
        alert("Serial không hợp lệ hoặc đã được chọn!");
    }
};

const removeSerialFromItem = (item, idx) => {
    item.serials.splice(idx, 1);
    item.quantity = item.serials.length;
    searchSerialsForItem(item);
};

const selectAllSerialsForItem = (item) => {
    if (!item.availableSerials) return;
    item.availableSerials.forEach(s => {
        if (!item.serials.find(sel => sel.id === s.id)) {
            item.serials.push(s);
        }
    });
    item.quantity = item.serials.length;
    item.serialInput = '';
    item.showSerialDropdown = false;
    searchSerialsForItem(item);
};

const deselectAllSerialsForItem = (item) => {
    item.serials = [];
    item.quantity = 0;
    searchSerialsForItem(item);
};

const hideSerialDropdown = (item) => {
    setTimeout(() => { item.showSerialDropdown = false; }, 200);
};

// ── LocalStorage Draft (multi-tab) ──
const DRAFT_KEY = 'kiotviet_pos_tabs';

const saveDraft = () => {
    const data = {
        tabs: tabs.value,
        activeTabIndex: activeTabIndex.value,
        selectedEmployeeId: selectedEmployeeId.value,
        saleDate: saleDate.value,
    };
    localStorage.setItem(DRAFT_KEY, JSON.stringify(data));
};

const loadDraft = () => {
    try {
        const raw = localStorage.getItem(DRAFT_KEY);
        if (raw) {
            const data = JSON.parse(raw);
            if (data.tabs && data.tabs.length > 0) {
                tabIdCounter = Math.max(...data.tabs.map(t => t.id || 0)) + 1;
                tabs.value = data.tabs.map(tab => ({
                    ...createNewTab(),
                    ...tab,
                    cart: (tab.cart || []).map(i => {
                        if (i.is_serial_product) {
                            return { ...i, showSerialDropdown: false, serialLoading: false, availableSerials: i.allAvailableSerials || [] };
                        }
                        return i;
                    })
                }));
                activeTabIndex.value = Math.min(data.activeTabIndex || 0, tabs.value.length - 1);
            }
            if (data.selectedEmployeeId) selectedEmployeeId.value = data.selectedEmployeeId;
            if (data.saleDate) saleDate.value = data.saleDate;

            // Reload serials for all tabs
            tabs.value.forEach(tab => {
                tab.cart.forEach(item => {
                    if (item.is_serial_product) loadSerialsForProduct(item);
                });
            });
        }
    } catch(e) {
        console.warn('Failed to load POS tabs', e);
    }
};

const clearDraft = () => {
    localStorage.removeItem(DRAFT_KEY);
};

// Fetch products based on search query
const searchProducts = async () => {
    isSearching.value = true;
    try {
        const response = await axios.get('/api/pos/products', {
            params: { search: query.value }
        });
        products.value = response.data || [];
    } catch (error) {
        console.error('Error fetching products:', error);
    } finally {
        isSearching.value = false;
    }
};

// Watch input changes with debounce for search
let timeout;
const handleSearchInput = () => {
    clearTimeout(timeout);
    timeout = setTimeout(() => {
        searchProducts();
    }, 400);
};

// Add product to cart
const addToCart = (product) => {
    // Block products with 0 sellable stock (all units in repair)
    if (product.has_serial && product.sellable_quantity !== undefined && product.sellable_quantity <= 0) {
        alert(`Sản phẩm "${product.name}" hiện có ${product.repairing_count} máy đang sửa, không còn máy sẵn bán!`);
        return;
    }

    if (product.has_serial) {
        const existingGroup = cart.value.find(item => item.product.id === product.id && item.is_serial_product);
        if (!existingGroup) {
            const newItem = {
                product: product,
                quantity: 0,
                price: product.retail_price,
                discount: 0,
                is_serial_product: true,
                serials: [], 
                serialInput: '',
                showSerialDropdown: false,
                allAvailableSerials: [],
                availableSerials: [],
                serialLoading: false,
            };
            cart.value.unshift(newItem);
            loadSerialsForProduct(newItem);
        } else {
            existingGroup.serialInput = '';
            existingGroup.showSerialDropdown = true;
        }
        return;
    }

    // Regular product (no serial)
    const existingItem = cart.value.find(item => item.product.id === product.id && !item.is_serial_product);
    if (existingItem) {
        existingItem.quantity += 1;
    } else {
        cart.value.unshift({
            product: product,
            quantity: 1,
            price: product.retail_price,
            discount: 0,
            is_serial_product: false,
        });
    }
};

// Remove from cart
const removeFromCart = (index) => {
    cart.value.splice(index, 1);
};

// Update quantity
const updateQuantity = (index, delta) => {
    const item = cart.value[index];
    if (item.is_serial_product) return;
    const newQty = item.quantity + delta;
    if (newQty > 0) {
        item.quantity = newQty;
    } else {
        removeFromCart(index);
    }
};

watch([tabs, activeTabIndex, selectedEmployeeId, saleDate], () => {
    saveDraft();
}, { deep: true });

// Computed totals
const subtotal = computed(() => {
    return cart.value.reduce((total, item) => total + (item.price * item.quantity) - (Number(item.discount) || 0), 0);
});

const calculatedDiscount = computed(() => {
    return discount.value;
});

const totalAmount = computed(() => {
    return subtotal.value - calculatedDiscount.value;
});

const changeDue = computed(() => {
    return (customerPaid.value > 0) ? (customerPaid.value - totalAmount.value) : 0;
});

const isCheckingOut = ref(false);
const toastMsg = ref('');

// Checkout action
const processCheckout = async () => {
    if (cart.value.length === 0) {
        alert("Giỏ hàng trống!");
        return;
    }

    isCheckingOut.value = true;

    try {
        const invalidItems = cart.value.filter(i => i.is_serial_product && i.quantity === 0);
        if (invalidItems.length > 0) {
            alert("Có sản phẩm quản lý theo Serial/IMEI chưa được chọn mã nào. Vui lòng chọn ít nhất 1 Serial hoặc xóa khỏi đơn.");
            isCheckingOut.value = false;
            return;
        }

        // Đặt nhanh: tạo Order (Phiếu tạm) — không cần thanh toán
        if (saleMode.value === 'quick_order') {
            const orderPayload = {
                subtotal: subtotal.value,
                discount: discount.value,
                total: totalAmount.value,
                customer_id: selectedCustomer.value?.id || null,
                employee_id: selectedEmployeeId.value || null,
                sale_time: saleDate.value || null,
                items: cart.value.map(item => ({
                    product_id: item.product.id,
                    quantity: item.quantity,
                    price: item.price,
                }))
            };

            const response = await axios.post('/api/pos/quick-order', orderPayload);
            if (response.data.success) {
                toastMsg.value = response.data.message;
                setTimeout(() => toastMsg.value = '', 4000);
                resetAfterCheckout();
            } else {
                alert("Lỗi: " + response.data.message);
            }
            return;
        }

        // Bán thường / Bán giao hàng: tạo Invoice
        const payload = {
            subtotal: subtotal.value,
            discount: discount.value,
            total: totalAmount.value,
            customer_paid: customerPaid.value,
            customer_id: selectedCustomer.value?.id || null,
            employee_id: selectedEmployeeId.value || null,
            sale_time: saleDate.value || null,
            payment_method: paymentMethod.value,
            bank_account_info: paymentMethod.value === 'transfer' ? bankAccountInfo.value : null,
            items: cart.value.map(item => ({
                product_id: item.product.id,
                quantity: item.quantity,
                price: item.price,
                discount: Number(item.discount) || 0,
                serial_ids: item.is_serial_product ? item.serials.map(s => s.id) : [],
            }))
        };

        const response = await axios.post('/api/pos/checkout', payload);
        
        if (response.data.success) {
            toastMsg.value = `${response.data.message} - Phiếu ${response.data.invoice_code}`;
            setTimeout(() => toastMsg.value = '', 4000);
            resetAfterCheckout();
        } else {
            alert("Lỗi: " + response.data.message);
        }
    } catch(err) {
        console.error("Checkout Error:", err);
        const msg = err.response?.data?.message || err.message || "Lỗi khi kết nối tới máy chủ.";
        alert("Lỗi: " + msg);
    } finally {
        isCheckingOut.value = false;
    }
};

const resetAfterCheckout = () => {
    if (tabs.value.length > 1) {
        closeTab(activeTabIndex.value);
    } else {
        const t = activeTab.value;
        t.cart = [];
        t.discount = 0;
        t.customerPaid = 0;
        t.paymentMethod = 'cash';
        t.bankAccountInfo = '';
        t.selectedCustomer = null;
        t.customerQuery = '';
    }
    saveDraft();
    searchProducts();
};
</script>

<template>
    <Head title="Bán Hàng (POS)" />

    <!-- Full screen POS UI -->
    <div class="flex flex-col h-screen overflow-hidden bg-gray-100">
        
        <!-- Top Navbar - KiotViet Style -->
        <header class="bg-gradient-to-r from-blue-500 via-blue-400 to-cyan-400 h-11 flex items-end px-2 flex-shrink-0 z-10 shadow-md">
            <!-- Search icon + input (compact) -->
            <div class="flex items-center gap-1.5 self-center mr-2">
                <button class="w-8 h-8 flex items-center justify-center bg-white/20 hover:bg-white/30 rounded transition-colors" title="Quét Barcode">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path></svg>
                </button>
                <div class="relative w-[170px] font-sans">
                    <input 
                        v-model="query" 
                        @input="handleSearchInput" 
                        type="text" 
                        placeholder="Tìm hàng hóa (F3)" 
                        class="w-full pl-7 pr-2 py-1.5 bg-white/90 hover:bg-white focus:bg-white text-gray-800 placeholder-gray-400 rounded text-[12px] outline-none focus:ring-2 focus:ring-white/50 border-none font-medium shadow-sm"
                    >
                    <svg class="w-3.5 h-3.5 text-gray-400 absolute left-2 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    <!-- Quick Dropdown Search Results -->
                    <div v-if="query && products && products.length > 0" class="absolute left-0 right-0 top-full mt-1 bg-white shadow-xl rounded border border-gray-200 z-50 max-h-[350px] overflow-y-auto w-[450px]">
                        <div v-for="product in products" :key="'dd-'+product.id" @click="addToCart(product); query=''" class="flex items-center justify-between p-2.5 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0 transition-colors" :class="product.has_serial && product.sellable_quantity <= 0 ? 'opacity-50 cursor-not-allowed' : ''">
                            <div class="flex-1 mr-3">
                                <div class="font-bold text-[13px] text-gray-800">{{ product.name }}</div>
                                <div class="text-[11px] text-gray-500 mt-0.5">
                                    {{ product.sku }} | Tồn: {{ product.stock_quantity }}
                                    <span v-if="product.repairing_count > 0" class="text-yellow-600 font-semibold">({{ product.repairing_count }} đang sửa)</span>
                                    <span v-if="product.has_serial && product.sellable_quantity !== undefined" class="text-green-600 font-semibold ml-1">| Sẵn: {{ product.sellable_quantity }}</span>
                                </div>
                            </div>
                            <div class="text-blue-600 font-bold text-[12px]">{{ Number(product.retail_price || 0).toLocaleString() }} ₫</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs - KiotViet browser-tab style -->
            <div class="flex items-end gap-0.5 flex-1 overflow-x-auto pb-0 min-w-0">
                <button
                    v-for="(tab, idx) in tabs"
                    :key="tab.id"
                    @click="switchTab(idx)"
                    class="relative flex items-center gap-1.5 px-3 h-8 text-[12px] font-semibold whitespace-nowrap transition-all rounded-t-md group flex-shrink-0"
                    :class="idx === activeTabIndex
                        ? 'bg-white text-gray-700 shadow-sm z-[1]'
                        : 'bg-white/20 text-white hover:bg-white/30'"
                >
                    <!-- Colored dot icon -->
                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0"
                        :class="tab.saleMode === 'quick_order' ? 'bg-orange-400' : tab.saleMode === 'delivery' ? 'bg-green-400' : 'bg-blue-400'"
                    ></span>
                    {{ tabLabel(tab) }} {{ idx + 1 }}
                    <span v-if="tab.cart.length > 0" class="text-[9px] font-bold ml-0.5" :class="idx === activeTabIndex ? 'text-blue-500' : 'text-white/80'">({{ tab.cart.length }})</span>
                    <button v-if="tabs.length > 1" @click.stop="closeTab(idx)"
                        class="ml-0.5 w-4 h-4 flex items-center justify-center rounded hover:bg-gray-200 transition-colors"
                        :class="idx === activeTabIndex ? 'text-gray-400 hover:text-red-500' : 'text-white/60 hover:text-white hover:bg-white/20'"
                        title="Đóng"
                    >
                        <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </button>
                <!-- Add tab button -->
                <button @click="addTab" class="flex items-center justify-center w-7 h-7 mb-0.5 rounded-full bg-white/20 hover:bg-white/30 text-white text-sm font-bold transition-colors flex-shrink-0 ml-1" title="Thêm hóa đơn mới">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
                </button>
            </div>

            <!-- Right controls -->
            <div class="flex items-center gap-2 self-center ml-2">
                <select v-model="selectedEmployeeId" class="bg-white/20 text-white text-[11px] outline-none border-none rounded px-2 py-1 font-medium cursor-pointer min-w-[100px]">
                    <option value="" class="text-gray-800">-- Nhân viên --</option>
                    <option v-for="emp in employees" :key="emp.id" :value="emp.id" class="text-gray-800">{{ emp.name }}</option>
                </select>
                <input type="datetime-local" v-model="saleDate" class="text-[11px] font-medium text-white bg-white/15 hover:bg-white/25 rounded px-2 py-1 whitespace-nowrap tabular-nums outline-none border-none cursor-pointer focus:ring-1 focus:ring-white/50 w-[155px]" title="Chọn ngày bán (cho phép quá khứ/tương lai)" />
                <Link href="/" class="w-7 h-7 flex items-center justify-center bg-white/20 hover:bg-white/30 rounded transition-colors" title="Về Quản lý">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                </Link>
            </div>
        </header>

        <!-- Main Workspace: Split Left (Products/Cart) and Right (Payment Summary) -->
        <main class="flex-1 flex overflow-hidden">
            
            <!-- Left Side: Cart Items -->
            <div class="flex-1 flex flex-col bg-white overflow-hidden relative shadow-inner z-0 border-r border-gray-300">
                <!-- Data Header -->
                <div class="grid grid-cols-12 gap-4 px-4 py-2 border-b border-gray-200 bg-gray-50 font-semibold text-gray-600 text-xs text-center sticky top-0 shadow-sm z-10 uppercase tracking-wider">
                    <div class="col-span-1">Stt</div>
                    <div class="col-span-4 text-left">Tên hàng hóa</div>
                    <div class="col-span-2">Số lượng</div>
                    <div class="col-span-2 text-right">Đơn giá</div>
                    <div class="col-span-2 text-right">Thành tiền</div>
                    <div class="col-span-1"></div>
                </div>
                
                <!-- Cart List -->
                <div class="flex-1 overflow-y-auto p-2 space-y-2">
                    <div v-if="!cart || cart.length === 0" class="flex flex-col items-center justify-center h-full text-gray-400">
                        <svg class="w-16 h-16 mb-2 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        <p class="font-medium">Chưa có sản phẩm nào trong đơn</p>
                    </div>
                    
                    <div v-for="(item, index) in cart" :key="index" class="grid grid-cols-12 gap-4 px-2 py-3 border-b border-gray-100 items-center hover:bg-gray-50 transition-colors bg-white rounded shadow-sm ring-1 ring-gray-900/5">
                        <div class="col-span-1 text-center font-medium text-gray-500">{{ index + 1 }}</div>
                        <div class="col-span-4 flex flex-col items-start leading-snug">
                            <span class="font-bold text-gray-800 text-sm overflow-hidden text-ellipsis line-clamp-2 w-full" :title="item.product.name">{{ item.product.name }}</span>
                            <span class="text-xs text-blue-600 font-medium tracking-wide mt-0.5">{{ item.product.sku }}</span>
                            
                            <!-- Inline Serial Selection -->
                            <div v-if="item.is_serial_product" class="mt-2 w-full">
                                <div v-if="item.serials && item.serials.length > 0" class="flex flex-wrap gap-1 mb-1.5 items-center">
                                    <span v-for="(s, sIdx) in item.serials" :key="sIdx" class="inline-flex items-center gap-1 bg-blue-50 text-blue-700 text-[11px] px-1.5 py-0.5 rounded font-mono border border-blue-200">
                                        {{ s.serial_number }}
                                        <span v-if="s.variant" class="text-purple-600 text-[10px] font-sans font-normal">({{ s.variant.name }})</span>
                                        <button @click="removeSerialFromItem(item, sIdx)" class="text-blue-400 hover:text-red-500 hover:bg-red-50 rounded pl-0.5 pr-0.5">&times;</button>
                                    </span>
                                    <button v-if="item.serials.length > 1" @mousedown.prevent="deselectAllSerialsForItem(item)" class="text-[10px] text-red-500 hover:text-red-700 font-medium px-1.5 py-0.5 rounded border border-red-200 hover:bg-red-50 transition-colors" title="Bỏ chọn tất cả">✕ Bỏ hết</button>
                                </div>
                                <div class="relative w-full z-20">
                                    <div class="flex items-center gap-1">
                                        <input 
                                           type="text" 
                                           v-model="item.serialInput" 
                                           @keydown.enter.prevent="addSerialToItem(item)"
                                           placeholder="Nhập/Quét Serial..." 
                                           class="flex-1 text-xs border border-gray-300 rounded px-2 py-1 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
                                           @input="searchSerialsForItem(item)"
                                           @focus="item.showSerialDropdown = true; searchSerialsForItem(item, true)"
                                           @blur="hideSerialDropdown(item)"
                                        >
                                        <button v-if="item.allAvailableSerials && item.allAvailableSerials.length > 0 && item.serials.length < item.allAvailableSerials.length" @mousedown.prevent="selectAllSerialsForItem(item)" class="text-[11px] text-green-600 hover:text-green-700 font-semibold px-2 py-1 rounded border border-green-300 hover:bg-green-50 transition-colors whitespace-nowrap" title="Chọn tất cả serial có sẵn">✓ Tất cả</button>
                                    </div>
                                    <div v-if="item.showSerialDropdown" class="absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 shadow-xl rounded z-50 max-h-40 overflow-auto">
                                        <div v-if="item.serialLoading" class="p-2 text-xs text-gray-400 text-center">Đang tải mã...</div>
                                        <div v-else-if="!item.availableSerials || item.availableSerials.length === 0" class="p-2 text-xs text-gray-400 text-center">Không còn mã nào</div>
                                        <template v-else>
                                            <div @mousedown.prevent="selectAllSerialsForItem(item)" class="p-1.5 text-xs text-green-700 border-b-2 border-green-100 hover:bg-green-50 cursor-pointer font-semibold flex items-center gap-1 sticky top-0 bg-white">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                                Chọn tất cả ({{ item.availableSerials.length }})
                                            </div>
                                            <div v-for="s in item.availableSerials" :key="s.id" @mousedown.prevent="selectSerialForItem(item, s)" class="p-1.5 text-xs text-gray-700 border-b border-gray-100 hover:bg-blue-50 cursor-pointer font-mono font-medium truncate flex items-center justify-between">
                                                <span>> {{ s.serial_number }}</span>
                                                <span v-if="s.variant" class="text-purple-500 text-[10px] font-sans ml-2">{{ s.variant.name }}</span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                <div v-if="item.quantity === 0" class="text-[10px] text-red-500 mt-1 font-medium">Vui lòng chọn ít nhất 1 Serial!</div>
                            </div>
                        </div>
                        
                        <!-- Quantity: hide +/- for serial products -->
                        <div class="col-span-2 flex items-center justify-center">
                            <template v-if="item.is_serial_product">
                                <span class="text-sm font-bold text-gray-800 bg-gray-100 px-3 py-1 rounded" :class="{'text-red-500 bg-red-50 border border-red-200': item.quantity === 0}">{{ item.quantity }}</span>
                            </template>
                            <template v-else>
                                <div class="flex items-center bg-gray-100/50 rounded-lg p-1 w-fit ring-1 ring-gray-300">
                                    <button @click="updateQuantity(index, -1)" class="w-7 h-7 flex items-center justify-center rounded bg-white text-gray-600 hover:bg-red-50 hover:text-red-500 transition-colors shadow-sm cursor-pointer disabled:opacity-50">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"></path></svg>
                                    </button>
                                    <input type="text" readonly :value="item.quantity" class="w-10 text-center bg-transparent text-sm font-bold text-gray-800 focus:outline-none focus:ring-0 select-none">
                                    <button @click="updateQuantity(index, 1)" class="w-7 h-7 flex items-center justify-center rounded bg-white text-gray-600 hover:bg-blue-50 hover:text-blue-600 transition-colors shadow-sm cursor-pointer disabled:opacity-50">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"></path></svg>
                                    </button>
                                </div>
                            </template>
                        </div>
                        <div class="col-span-2 text-right">
                            <input type="number" v-model="item.price" class="w-full text-right outline-none bg-transparent border-b border-dashed border-gray-300 focus:border-blue-500 py-1 font-semibold text-gray-700">
                        </div>
                        <div class="col-span-2 text-right">
                            <div class="font-bold text-gray-900 text-[15px]">{{ (Number(item.price * item.quantity - (item.discount || 0)) || 0).toLocaleString() }}</div>
                            <div class="flex items-center justify-end gap-1 mt-0.5">
                                <span class="text-[10px] text-gray-400">CK:</span>
                                <input type="number" v-model="item.discount" min="0" class="w-16 text-right text-[11px] outline-none bg-transparent border-b border-dashed border-gray-300 focus:border-blue-500 py-0 text-gray-500" placeholder="0">
                            </div>
                        </div>
                        <div class="col-span-1 flex justify-center">
                            <button @click="removeFromCart(index)" class="text-gray-400 hover:text-red-500 hover:bg-red-50 p-2 rounded-full transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Product Quick Pick (Optional, below cart) -->
                <div class="h-48 bg-white border-t border-gray-200 z-10 flex flex-col bg-gray-50/50">
                    <div class="font-semibold text-sm px-4 py-2 bg-gray-100/50 border-b border-gray-200 sticky top-0 uppercase tracking-wider text-gray-600">Gợi ý sản phẩm</div>
                    <div class="flex-1 overflow-x-auto p-3 flex gap-3 pb-4">
                        <div 
                            v-if="!products || products.length === 0" 
                            class="text-sm text-gray-400 h-full flex items-center justify-center w-full"
                        >
                            <span v-if="isSearching">Đang tải...</span>
                            <span v-else>Không tìm thấy sản phẩm, gõ vào ô tìm kiếm...</span>
                        </div>
                        <div 
                            v-else
                            v-for="product in products" 
                            :key="'quick-'+product.id"
                            @click="addToCart(product)"
                            class="w-32 flex-none bg-white rounded border border-gray-200 p-2 hover:border-blue-500 hover:shadow-md cursor-pointer transition-all flex flex-col shadow-sm group"
                            :class="product.has_serial && product.sellable_quantity <= 0 ? 'opacity-40 pointer-events-none' : ''"
                        >
                            <div class="flex-1 text-sm font-semibold text-gray-800 line-clamp-3 leading-snug group-hover:text-blue-700">{{ product.name }}</div>
                            <div v-if="product.repairing_count > 0" class="text-[10px] text-yellow-600 font-bold mt-1">{{ product.repairing_count }} đang sửa</div>
                            <div class="text-blue-600 font-bold mt-2 font-mono text-sm tracking-tighter">{{ Number(product.retail_price || 0).toLocaleString() }} ₫</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side: Order Summary & Checkout -->
            <div class="w-80 lg:w-[400px] flex flex-col bg-white overflow-y-auto flex-shrink-0 z-10 shadow-[-2px_0_5px_-2px_rgba(0,0,0,0.1)] h-full">
                <!-- Customer info -->
                <div class="p-4 border-b border-gray-100">
                    <!-- Selected customer display -->
                    <div v-if="selectedCustomer" class="flex items-center justify-between bg-blue-50 border border-blue-200 rounded-lg px-3 py-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <svg class="w-5 h-5 text-blue-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                            <div class="min-w-0">
                                <div class="font-bold text-sm text-blue-800 truncate">{{ selectedCustomer.name }}</div>
                                <div class="text-xs text-blue-600">
                                    {{ selectedCustomer.phone || 'Chưa có SĐT' }}
                                    <span v-if="selectedCustomer.debt_amount > 0" class="text-red-500 ml-1">| Nợ: {{ Number(selectedCustomer.debt_amount || 0).toLocaleString() }}</span>
                                </div>
                            </div>
                        </div>
                        <button @click="clearCustomer" class="text-blue-400 hover:text-red-500 p-1 rounded transition-colors flex-shrink-0" title="Bỏ chọn">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    </div>

                    <!-- Customer search input -->
                    <div v-else class="flex items-center gap-2 relative">
                        <div class="flex-1 relative">
                            <svg class="w-5 h-5 text-gray-400 absolute left-2 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                            <input
                                v-model="customerQuery"
                                @input="handleCustomerInput"
                                @focus="(customerQuery && customerQuery.length >= 1) && searchCustomers()"
                                type="text"
                                placeholder="Tìm khách hàng (tên, SĐT, mã)..."
                                class="w-full pl-8 py-2 text-sm border-b border-gray-300 focus:border-blue-500 outline-none transition-colors"
                            >
                            <svg v-if="customerSearching" class="w-4 h-4 animate-spin text-blue-500 absolute right-2 top-1/2 -translate-y-1/2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>

                            <!-- Dropdown results -->
                            <div v-if="showCustomerDropdown" class="absolute left-0 right-0 top-full mt-1 bg-white shadow-lg rounded-lg border border-gray-200 z-50 max-h-48 overflow-y-auto">
                                <div v-if="(!customerResults || customerResults.length === 0) && !customerSearching" class="px-3 py-4 text-sm text-gray-400 text-center">
                                    Không tìm thấy khách hàng "{{ customerQuery }}"
                                    <button @click="openNewCustomerModal" class="block mx-auto mt-2 text-blue-600 font-semibold hover:underline text-xs">+ Tạo khách mới</button>
                                </div>
                                <div v-for="c in customerResults" :key="c.id"
                                    @click="selectCustomer(c)"
                                    class="flex items-center justify-between px-3 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0 transition-colors">
                                    <div>
                                        <div class="font-semibold text-sm text-gray-800">{{ c.name }}</div>
                                        <div class="text-xs text-gray-500">{{ c.code }} | {{ c.phone || '—' }}</div>
                                    </div>
                                    <div v-if="c.debt_amount > 0" class="text-xs text-red-500 font-semibold">Nợ: {{ Number(c.debt_amount || 0).toLocaleString() }}</div>
                                </div>
                            </div>
                        </div>
                        <button @click="openNewCustomerModal" class="bg-blue-50 text-blue-600 hover:bg-blue-100 w-8 h-8 rounded flex items-center justify-center transition-colors flex-shrink-0" title="Thêm khách mới">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"></path></svg>
                        </button>
                    </div>
                </div>


                <!-- Invoice Details Calculation -->
                <div class="p-4 space-y-4 text-[15px] flex-1">
                    <div class="flex justify-between items-center text-gray-700 font-medium">
                        <span>Tổng tiền hàng</span>
                        <span class="font-bold">{{ (subtotal || 0).toLocaleString() }}</span>
                    </div>
                    
                    <div class="flex justify-between items-center text-gray-700 font-medium">
                        <span class="border-b border-dashed border-gray-400 cursor-pointer hover:text-blue-600 transition-colors">Giảm giá</span>
                        <div class="flex items-center">
                            <input type="number" v-model="discount" class="w-24 text-right border-b border-gray-300 focus:border-blue-500 outline-none pr-1">
                        </div>
                    </div>
                    
                    <div class="flex justify-between border-t border-gray-200 pt-3 text-gray-900 font-bold text-lg mt-1">
                        <span>Khách cần trả</span>
                        <span class="text-blue-700 tracking-tight text-xl">{{ (totalAmount || 0).toLocaleString() }}</span>
                    </div>

                    <div class="flex justify-between items-center pt-2 text-gray-700 font-medium">
                        <span>Khách thanh toán</span>
                        <input type="number" v-model="customerPaid" :placeholder="totalAmount" class="w-32 text-right border-b border-gray-300 focus:border-blue-500 outline-none font-bold text-gray-900">
                    </div>

                    <div class="flex justify-between items-center pb-2 text-gray-500 text-sm font-medium">
                        <span>Tiền thừa trả khách</span>
                        <span>{{ (changeDue || 0).toLocaleString() }}</span>
                    </div>

                    <!-- Payment Method -->
                    <div class="border-t border-gray-200 pt-3">
                        <div class="flex items-center gap-4 text-sm">
                            <label class="flex items-center gap-1.5 cursor-pointer">
                                <input type="radio" v-model="paymentMethod" value="cash" class="text-blue-600 focus:ring-blue-500 w-4 h-4" />
                                <span class="font-medium">Tiền mặt</span>
                            </label>
                            <label class="flex items-center gap-1.5 cursor-pointer">
                                <input type="radio" v-model="paymentMethod" value="transfer" class="text-blue-600 focus:ring-blue-500 w-4 h-4" />
                                <span class="font-medium">Chuyển khoản</span>
                            </label>
                        </div>
                        <div v-if="paymentMethod === 'transfer'" class="mt-2">
                            <select v-model="bankAccountInfo" class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm outline-none focus:border-blue-500 bg-white">
                                <option value="">-- Chọn tài khoản --</option>
                                <option v-for="ba in bankAccounts" :key="ba.id" :value="ba.bank_name + ' - ' + ba.account_number + ' - ' + ba.account_holder">
                                    {{ ba.bank_name }} - {{ ba.account_number }} ({{ ba.account_holder }})
                                </option>
                            </select>
                            <input v-if="!bankAccounts || bankAccounts.length === 0" type="text" v-model="bankAccountInfo" class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm outline-none focus:border-blue-500 mt-1" placeholder="Nhập số tài khoản" />
                        </div>
                    </div>
                    
                    <div class="mt-4 flex gap-2 w-full justify-between pb-6">
                         <div class="flex gap-2">
                             <button class="p-2 border border-gray-200 rounded text-gray-500 hover:bg-gray-50 hover:text-gray-700 tooltip" title="In Tạm Tính">
                                 <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                             </button>
                         </div>
                    </div>
                </div>

                <!-- Sale Mode Bar (giống KiotViet) -->
                <div class="border-t border-gray-200 flex text-xs font-semibold">
                    <button 
                        @click="saleMode = 'quick_order'" 
                        :class="saleMode === 'quick_order' ? 'bg-orange-50 text-orange-600 border-t-2 border-orange-500' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-700'"
                        class="flex-1 py-2.5 flex items-center justify-center gap-1.5 transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                        Đặt nhanh
                    </button>
                    <button 
                        @click="saleMode = 'normal'" 
                        :class="saleMode === 'normal' ? 'bg-blue-50 text-blue-600 border-t-2 border-blue-500' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-700'"
                        class="flex-1 py-2.5 flex items-center justify-center gap-1.5 transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        Bán thường
                    </button>
                    <button 
                        @click="saleMode = 'delivery'" 
                        :class="saleMode === 'delivery' ? 'bg-green-50 text-green-600 border-t-2 border-green-500' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-700'"
                        class="flex-1 py-2.5 flex items-center justify-center gap-1.5 transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12l-2 8H6L4 3H2m6 0v4m8-4v4m-4 8a2 2 0 100 4 2 2 0 000-4zm6 0a2 2 0 100 4 2 2 0 000-4z"></path></svg>
                        Bán giao hàng
                    </button>
                </div>

                <!-- Checkout Button -->
                <div class="mt-auto sticky bottom-0 z-20 relative">
                    <button 
                        @click="processCheckout" 
                        :disabled="isCheckingOut"
                        :class="{
                            'bg-orange-500 hover:bg-orange-600 focus:ring-orange-300': saleMode === 'quick_order',
                            'bg-blue-600 hover:bg-blue-700 focus:ring-blue-300': saleMode === 'normal',
                            'bg-green-600 hover:bg-green-700 focus:ring-green-300': saleMode === 'delivery',
                        }"
                        class="w-full disabled:opacity-75 disabled:cursor-wait text-white font-bold text-lg py-5 flex items-center justify-center gap-2 transition-colors focus:ring-4"
                    >
                        <span v-if="isCheckingOut">Đang xử lý...</span>
                        <span v-else-if="saleMode === 'quick_order'">Đặt hàng</span>
                        <span v-else>Thanh toán</span>
                        <svg v-if="!isCheckingOut" class="w-6 h-6 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </button>
                    
                    <!-- POS Toast Notification inner right column -->
                    <transition
                        enter-active-class="transform transition ease-out duration-300"
                        enter-from-class="translate-y-full opacity-0"
                        enter-to-class="translate-y-0 opacity-100"
                        leave-active-class="transition ease-in duration-200"
                        leave-from-class="translate-y-0 opacity-100"
                        leave-to-class="translate-y-full opacity-0"
                    >
                        <div v-if="toastMsg" class="absolute bottom-20 right-4 left-4 bg-green-500 text-white p-3 rounded shadow-lg text-sm font-semibold flex items-center gap-2 z-50">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            {{ toastMsg }}
                        </div>
                    </transition>
                </div>
            </div>
        </main>
    </div>



    <!-- ═══ Quick Create Customer Modal ═══ -->
    <QuickCreateCustomerModal
        :show="showNewCustomerModal"
        :initial-name="newCustomerInitialName"
        api-url="/api/pos/customers"
        entity-label="khách hàng"
        @close="showNewCustomerModal = false"
        @created="onCustomerCreated"
    />
</template>

<style scoped>
/* Chrome, Safari, Edge, Opera: Hide number arrows */
input::-webkit-outer-spin-button,
input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
/* Firefox: Hide number arrows */
input[type=number] {
  -moz-appearance: textfield;
}
</style>
