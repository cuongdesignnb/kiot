<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    employees: Array,
    bankAccounts: Array,
});

// State for search and products
const query = ref('');
const products = ref([]);
const isSearching = ref(false);

// State for the cart (giỏ hàng)
const cart = ref([]);

// Employee & time
const selectedEmployeeId = ref('');
const currentTime = ref('');

// Ngày bán (cho phép chọn ngày khác hôm nay)
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
});
onUnmounted(() => {
    clearInterval(timeInterval);
});

// Payment details
const discount = ref(0);
const customerPaid = ref(0);
const paymentMethod = ref('cash');
const bankAccountInfo = ref('');

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

    // Each serial = 1 separate line in cart (1 serial = 1 máy)
    selectedSerialIds.value.forEach(serialId => {
        // Skip if this serial is already in cart
        const alreadyInCart = cart.value.some(item => 
            item.serial_id === serialId
        );
        if (alreadyInCart) return;

        const serial = availableSerials.value.find(s => s.id === serialId);
        cart.value.push({
            product: product,
            quantity: 1,
            price: product.retail_price,
            serial_id: serialId,
            serial_number: serial?.serial_number || '',
            is_serial_product: true,
        });
    });

    showSerialModal.value = false;
};

// Fetch products based on search query
const searchProducts = async () => {
    isSearching.value = true;
    try {
        const response = await axios.get('/api/pos/products', {
            params: { search: query.value }
        });
        products.value = response.data;
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

    // If product has serial → open serial picker modal
    if (product.has_serial) {
        openSerialModal(product);
        return;
    }

    // Regular product (no serial)
    const existingItem = cart.value.find(item => item.product.id === product.id && !item.is_serial_product);
    if (existingItem) {
        existingItem.quantity += 1;
    } else {
        cart.value.push({
            product: product,
            quantity: 1,
            price: product.retail_price,
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
    // Serial product: qty is always 1, cannot change
    if (item.is_serial_product) return;
    const newQty = item.quantity + delta;
    if (newQty > 0) {
        item.quantity = newQty;
    } else {
        removeFromCart(index);
    }
};

// Computed totals
const subtotal = computed(() => {
    return cart.value.reduce((total, item) => total + (item.price * item.quantity), 0);
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
        const payload = {
            subtotal: subtotal.value,
            discount: discount.value,
            total: totalAmount.value,
            customer_paid: customerPaid.value,
            employee_id: selectedEmployeeId.value || null,
            sale_time: saleDate.value ? new Date(saleDate.value).toISOString() : new Date().toISOString(),
            payment_method: paymentMethod.value,
            bank_account_info: paymentMethod.value === 'transfer' ? bankAccountInfo.value : null,
            items: cart.value.map(item => ({
                product_id: item.product.id,
                quantity: item.quantity,
                price: item.price,
                serial_ids: item.serial_id ? [item.serial_id] : [],
            }))
        };

        const response = await axios.post('/api/pos/checkout', payload);
        
        if (response.data.success) {
            toastMsg.value = `${response.data.message} - Phiếu ${response.data.invoice_code}`;
            setTimeout(() => toastMsg.value = '', 4000);

            cart.value = [];
            discount.value = 0;
            customerPaid.value = 0;
            paymentMethod.value = 'cash';
            bankAccountInfo.value = '';
            
            searchProducts(); 
        } else {
            alert("Lỗi: " + response.data.message);
        }
    } catch(err) {
        console.error("Checkout Error:", err);
        alert("Lỗi khi kết nối tới máy chủ.");
    } finally {
        isCheckingOut.value = false;
    }
};
</script>

<template>
    <Head title="Bán Hàng (POS)" />

    <!-- Full screen POS UI -->
    <div class="flex flex-col h-screen overflow-hidden bg-gray-100">
        
        <!-- Top Navbar for POS -->
        <header class="bg-blue-600 text-white h-14 flex items-center justify-between px-4 shadow-md flex-shrink-0 z-10">
            <div class="flex items-center gap-4">
                <div class="font-bold text-lg flex items-center gap-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
                    KiotViet POS
                </div>
                <!-- Search Bar -->
                <div class="relative w-96 ml-6 text-gray-800">
                    <input 
                        v-model="query" 
                        @input="handleSearchInput" 
                        type="text" 
                        placeholder="Thêm hàng hóa vào đơn (F3)" 
                        class="w-full pl-10 pr-4 py-1.5 rounded outline-none focus:ring-2 focus:ring-blue-300 text-sm"
                    >
                    <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    
                    <!-- Quick Dropdown Search Results -->
                    <div v-if="query && products.length > 0" class="absolute left-0 right-0 top-full mt-1 bg-white shadow-lg rounded border border-gray-200 p-2 z-50 max-h-60 overflow-y-auto">
                        <div v-for="product in products" :key="'dd-'+product.id" @click="addToCart(product); query=''" class="flex items-center justify-between p-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0" :class="product.has_serial && product.sellable_quantity <= 0 ? 'opacity-50 cursor-not-allowed' : ''">
                            <div>
                                <div class="font-semibold text-sm">{{ product.name }}</div>
                                <div class="text-xs text-gray-500">
                                    {{ product.sku }} | Tồn: {{ product.stock_quantity }}
                                    <span v-if="product.repairing_count > 0" class="text-yellow-600 font-semibold">(🔧 {{ product.repairing_count }} đang sửa)</span>
                                    <span v-if="product.has_serial && product.sellable_quantity !== undefined" class="text-green-600 font-semibold ml-1">| Sẵn bán: {{ product.sellable_quantity }}</span>
                                </div>
                            </div>
                            <div class="text-blue-600 font-bold text-sm">{{ Number(product.retail_price).toLocaleString() }} &curren;</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <!-- Employee Selector -->
                <div class="flex items-center gap-2 bg-blue-700/50 rounded px-3 py-1">
                    <svg class="w-4 h-4 text-blue-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    <select v-model="selectedEmployeeId" class="bg-transparent text-white text-sm outline-none border-none font-medium appearance-none pr-4 cursor-pointer min-w-[120px]">
                        <option value="" class="text-gray-800">-- Nhân viên bán --</option>
                        <option v-for="emp in employees" :key="emp.id" :value="emp.id" class="text-gray-800">{{ emp.name }}</option>
                    </select>
                </div>
                <!-- Ngày bán -->
                <div class="flex items-center bg-blue-700/50 rounded px-2 py-0.5">
                    <svg class="w-4 h-4 text-blue-200 mr-1.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    <input type="datetime-local" v-model="saleDate" class="bg-transparent text-white text-sm outline-none border-none font-medium w-[170px] cursor-pointer" />
                </div>
                <div class="text-xs font-medium text-blue-200 bg-blue-700/50 rounded px-2 py-1 tabular-nums">
                    {{ currentTime }}
                </div>
                <Link href="/" class="text-sm font-medium hover:bg-blue-700 px-3 py-1.5 rounded bg-blue-500 transition-colors flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    Về Quản lý
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
                    <div v-if="cart.length === 0" class="flex flex-col items-center justify-center h-full text-gray-400">
                        <svg class="w-16 h-16 mb-2 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        <p class="font-medium">Chưa có sản phẩm nào trong đơn</p>
                    </div>
                    
                    <div v-for="(item, index) in cart" :key="index" class="grid grid-cols-12 gap-4 px-2 py-3 border-b border-gray-100 items-center hover:bg-gray-50 transition-colors bg-white rounded shadow-sm ring-1 ring-gray-900/5">
                        <div class="col-span-1 text-center font-medium text-gray-500">{{ index + 1 }}</div>
                        <div class="col-span-4 flex flex-col items-start leading-snug">
                            <span class="font-bold text-gray-800 text-sm overflow-hidden text-ellipsis line-clamp-2 w-full" :title="item.product.name">{{ item.product.name }}</span>
                            <span class="text-xs text-blue-600 font-medium tracking-wide mt-0.5">{{ item.product.sku }}</span>
                            <!-- Serial number chip -->
                            <span v-if="item.is_serial_product && item.serial_number" class="inline-block bg-blue-50 text-blue-700 text-[10px] px-2 py-0.5 rounded font-mono mt-1 border border-blue-200">
                                SN: {{ item.serial_number }}
                            </span>
                        </div>
                        
                        <!-- Quantity: hide +/- for serial products -->
                        <div class="col-span-2 flex items-center justify-center">
                            <template v-if="item.is_serial_product">
                                <span class="text-sm font-bold text-gray-800 bg-gray-100 px-3 py-1 rounded">1</span>
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
                        <div class="col-span-2 text-right font-bold text-gray-900 text-[15px]">
                            {{ (item.price * item.quantity).toLocaleString() }}
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
                            v-if="products.length === 0" 
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
                            <div v-if="product.repairing_count > 0" class="text-[10px] text-yellow-600 font-bold mt-1">🔧 {{ product.repairing_count }} sửa</div>
                            <div class="text-blue-600 font-bold mt-2 font-mono text-sm tracking-tighter">{{ Number(product.retail_price).toLocaleString() }} ₫</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side: Order Summary & Checkout -->
            <div class="w-80 lg:w-[400px] flex flex-col bg-white overflow-y-auto flex-shrink-0 z-10 shadow-[-2px_0_5px_-2px_rgba(0,0,0,0.1)] h-full">
                <!-- Customer info -->
                <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                    <div class="flex items-center gap-2 flex-1 relative">
                        <svg class="w-5 h-5 text-gray-400 absolute left-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                        <input type="text" placeholder="Tìm khách hàng (F4)" class="w-full pl-8 py-2 text-sm border-b border-gray-300 focus:border-blue-500 outline-none transition-colors">
                    </div>
                    <button class="ml-2 bg-blue-50 text-blue-600 hover:bg-blue-100 w-8 h-8 rounded flex items-center justify-center transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"></path></svg>
                    </button>
                </div>

                <!-- Tabs (Invoice, Delivery) -->
                <div class="flex border-b border-gray-200">
                    <button class="flex-1 py-3 text-sm font-bold border-b-2 border-blue-600 text-blue-600 bg-blue-50/50">Hóa đơn 1</button>
                    <button class="flex-1 py-3 text-sm font-semibold text-gray-500 hover:text-gray-700 hover:bg-gray-50">+</button>
                </div>

                <!-- Invoice Details Calculation -->
                <div class="p-4 space-y-4 text-[15px] flex-1">
                    <div class="flex justify-between items-center text-gray-700 font-medium">
                        <span>Tổng tiền hàng</span>
                        <span class="font-bold">{{ subtotal.toLocaleString() }}</span>
                    </div>
                    
                    <div class="flex justify-between items-center text-gray-700 font-medium">
                        <span class="border-b border-dashed border-gray-400 cursor-pointer hover:text-blue-600 transition-colors">Giảm giá</span>
                        <div class="flex items-center">
                            <input type="number" v-model="discount" class="w-24 text-right border-b border-gray-300 focus:border-blue-500 outline-none pr-1">
                        </div>
                    </div>
                    
                    <div class="flex justify-between border-t border-gray-200 pt-3 text-gray-900 font-bold text-lg mt-1">
                        <span>Khách cần trả</span>
                        <span class="text-blue-700 tracking-tight text-xl">{{ totalAmount.toLocaleString() }}</span>
                    </div>

                    <div class="flex justify-between items-center pt-2 text-gray-700 font-medium">
                        <span>Khách thanh toán</span>
                        <input type="number" v-model="customerPaid" :placeholder="totalAmount" class="w-32 text-right border-b border-gray-300 focus:border-blue-500 outline-none font-bold text-gray-900">
                    </div>

                    <div class="flex justify-between items-center pb-2 text-gray-500 text-sm font-medium">
                        <span>Tiền thừa trả khách</span>
                        <span>{{ changeDue.toLocaleString() }}</span>
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
                            <input v-if="!bankAccounts?.length" type="text" v-model="bankAccountInfo" class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm outline-none focus:border-blue-500 mt-1" placeholder="Nhập số tài khoản" />
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

                <!-- Checkout Button -->
                <div class="mt-auto border-t border-gray-200 sticky bottom-0 z-20 relative">
                    <button 
                        @click="processCheckout" 
                        :disabled="isCheckingOut"
                        class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-75 disabled:cursor-wait text-white font-bold text-lg py-5 flex items-center justify-center gap-2 transition-colors focus:ring-4 focus:ring-blue-300"
                    >
                        <span v-if="!isCheckingOut">Thanh toán</span>
                        <span v-else>Đang xử lý...</span>
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

    <!-- ═══ Serial/IMEI Selection Modal ═══ -->
    <div v-if="showSerialModal" class="fixed inset-0 z-[100] bg-black/40 flex items-center justify-center font-sans">
        <div class="bg-white rounded-lg shadow-2xl w-[520px] max-h-[80vh] flex flex-col">
            <!-- Header -->
            <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-800">Chọn Serial/IMEI</h3>
                <button @click="showSerialModal = false" class="text-gray-400 hover:text-gray-600 text-xl cursor-pointer">&times;</button>
            </div>

            <!-- Search -->
            <div class="px-5 pt-3 pb-2">
                <div class="relative">
                    <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    <input v-model="serialSearch" type="text" placeholder="Tìm serial/IMEI..." class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                </div>
            </div>

            <!-- Serial List -->
            <div class="flex-1 overflow-auto px-5 py-3 min-h-[200px]">
                <!-- Loading -->
                <div v-if="serialLoading" class="flex items-center justify-center h-32 text-gray-400">
                    <svg class="animate-spin h-6 w-6 text-blue-500 mr-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    Đang tải...
                </div>

                <!-- No serials -->
                <div v-else-if="filteredSerials.length === 0" class="flex flex-col items-center justify-center h-32 text-gray-400">
                    <svg class="w-10 h-10 mb-2 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M12 12h.01M12 12h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span class="text-sm">Không tìm thấy serial nào</span>
                </div>

                <!-- Serial chips -->
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

            <!-- Footer -->
            <div class="px-5 py-3 border-t border-gray-200 flex items-center justify-between bg-gray-50/50">
                <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-600">
                    <input 
                        type="checkbox" 
                        :checked="filteredSerials.length > 0 && selectedSerialIds.length === filteredSerials.length"
                        @change="toggleAllSerials"
                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4"
                    >
                    Chọn tất cả <span v-if="selectedSerialIds.length > 0" class="text-blue-600 font-bold">({{ selectedSerialIds.length }})</span>
                </label>
                <div class="flex gap-2">
                    <button @click="showSerialModal = false" class="px-5 py-2 border border-gray-300 rounded text-sm font-semibold text-gray-600 hover:bg-gray-100 transition-colors cursor-pointer">
                        Hủy
                    </button>
                    <button 
                        @click="confirmSerialSelection" 
                        :disabled="selectedSerialIds.length === 0"
                        class="px-5 py-2 bg-blue-600 text-white rounded text-sm font-bold hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors cursor-pointer"
                    >
                        Xong
                    </button>
                </div>
            </div>
        </div>
    </div>
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
