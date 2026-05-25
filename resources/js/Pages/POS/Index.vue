<script setup>
import { formatVND as formatCurrency } from '@/utils/money';
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import QuickCreateCustomerModal from '@/Components/QuickCreateCustomerModal.vue';
import QuickCreateProductModal from '@/Components/QuickCreateProductModal.vue';
import DateTimePicker from '@/Components/DateTimePicker.vue';
import MoneyInput from '@/Components/MoneyInput.vue';

const props = defineProps({
    employees: Array,
    sellerOptions: Array,
    bankAccounts: Array,
});

// State for search and products (global)
const query = ref('');
const products = ref([]);
const isSearching = ref(false);

// ── Multi-tab POS workspace (Step 24.6 — KiotViet-style sale/order/return tabs) ──
let tabIdCounter = 1;
const emptyReturnState = () => ({
    sourceInvoice: null,             // { id, code, status, total, customer_id, branch_id, ... }
    sourceItems: [],                 // [{ invoice_item_id, product_id, ... remaining_qty, serials[] }]
    lineState: {},                   // invoice_item_id -> { qty, serial_ids: [] }
    hiddenReturnLines: {},           // frontend-only: invoice_item_id -> true, never deletes original invoice data
    discount: 0,
    // Step 24.6E: fee can be VND amount or % of (subtotal − discount).
    feeType: 'amount',               // 'amount' | 'percent'
    feeValue: 0,                     // raw user input
    // Step 24.6E-FIX: refund_other intentionally NOT in scope until backend
    // calculator supports it (backlog 24.6F). UI doesn't render the field.
    paidToCustomer: 0,
    paidToCustomerTouched: false,    // user typed manually → don't auto-override
    note: '',
    search: '',
    searchResults: [],
    searching: false,
    loadingItems: false,
    submitting: false,
    error: '',
    errorTitle: '',
    successResult: null,
    exchangeItems: [],
    exchangeSearch: '',
    exchangeResults: [],
    exchangeSearchOpen: false,
    exchangeSearching: false,
    exchangeDiscount: 0,
    exchangeCustomerPaid: 0,
    exchangePaymentMethod: 'cash',
    exchangeBankAccountInfo: '',
});

const createNewTab = (type = 'sale') => ({
    id: tabIdCounter++,
    type,                            // 'sale' | 'order' | 'return'
    cart: [],
    discount: 0,
    customerPaid: 0,
    paymentMethod: 'cash',
    bankAccountInfo: '',
    selectedCustomer: null,
    customerQuery: '',
    note: '',                        // 24.6C: per-tab invoice/order note
    saleMode: type === 'order' ? 'quick_order' : 'normal',
    returnState: type === 'return' ? emptyReturnState() : null,
    // POS order process extensions
    mode: 'normal',                  // 'normal' | 'process_order'
    source_order_id: null,
    source_order_code: '',
    orderDepositAmount: 0,
    delivery: {
        is_delivery: false,
        delivery_mode: 'none',       // 'none' | 'self' | 'partner'
        delivery_partner: '',
        tracking_code: '',
        delivery_fee: 0,
        cod_amount: 0,
        receiver_name: '',
        receiver_phone: '',
        receiver_address: '',
        receiver_ward: '',
        receiver_district: '',
        receiver_city: '',
        weight: 0,
        delivery_note: '',
    }
});

const tabs = ref([createNewTab('sale')]);
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
const orderNote = computed({
    get: () => activeTab.value.note || '',
    set: (v) => { activeTab.value.note = v; }
});

// Tab management
const addTab = (type = 'sale') => {
    tabs.value.push(createNewTab(type));
    activeTabIndex.value = tabs.value.length - 1;
};
const switchTab = (idx) => {
    activeTabIndex.value = idx;
};
const tabHasUnsavedWork = (tab) => {
    if (tab.type === 'return') {
        const rs = tab.returnState;
        return !!(rs && (
            rs.sourceInvoice
            || Object.values(rs.lineState || {}).some((l) => l && l.qty > 0)
            || (rs.exchangeItems || []).length > 0
        ));
    }
    return tab.cart.length > 0 || !!(tab.note && tab.note.trim());
};
const closeTab = (idx) => {
    if (tabs.value.length <= 1) return;
    const tab = tabs.value[idx];
    if (tabHasUnsavedWork(tab) && !confirm('Tab này có dữ liệu chưa lưu. Đóng tab?')) {
        return;
    }
    tabs.value.splice(idx, 1);
    if (activeTabIndex.value >= tabs.value.length) {
        activeTabIndex.value = tabs.value.length - 1;
    }
};

// Per-type counter for friendly labels: "Hóa đơn 1", "Đặt hàng 1", "Trả hàng 1".
const tabIndexAmongType = (tab) => {
    let n = 0;
    for (const t of tabs.value) {
        if (t.type === tab.type) {
            n++;
            if (t.id === tab.id) return n;
        }
    }
    return n;
};
const tabBaseLabel = (tab) => {
    if (tab.type === 'return') return 'Trả hàng';
    if (tab.type === 'order' || tab.saleMode === 'quick_order') return 'Đặt hàng';
    return 'Hóa đơn';
};
const tabLabel = (tab) => `${tabBaseLabel(tab)} ${tabIndexAmongType(tab)}`;
const tabDotClass = (tab) => {
    if (tab.type === 'return') return 'bg-red-400';
    if (tab.type === 'order' || tab.saleMode === 'quick_order') return 'bg-orange-400';
    if (tab.saleMode === 'delivery') return 'bg-green-400';
    return 'bg-blue-400';
};
const tabBadgeCount = (tab) => {
    if (tab.type === 'return') {
        const rs = tab.returnState;
        if (!rs) return 0;
        return Object.values(rs.lineState || {}).filter((l) => l && l.qty > 0).length
            + (rs.exchangeItems || []).length;
    }
    return tab.cart.length;
};

// Employee & time (global)
// HOTFIX 24.33 — selectedSellerKey is the canonical seller selection
// ("employee:<id>" or "admin_user:<id>"). selectedEmployeeId is kept
// purely for legacy draft compat and is derived from selectedSellerKey.
const selectedSellerKey = ref('');
const sellerOptions = computed(() => props.sellerOptions || []);
const selectedEmployeeId = computed({
    get: () => {
        const k = selectedSellerKey.value;
        if (k && k.startsWith('employee:')) return k.slice('employee:'.length);
        return '';
    },
    set: (v) => {
        selectedSellerKey.value = v ? `employee:${v}` : '';
    },
});
const currentTime = ref('');

// Ngày bán
const pad = (n) => String(n).padStart(2, '0');
const nowInit = new Date();
const localNowStr = `${nowInit.getFullYear()}-${pad(nowInit.getMonth()+1)}-${pad(nowInit.getDate())}T${pad(nowInit.getHours())}:${pad(nowInit.getMinutes())}`;
const saleDate = ref(localNowStr);

const updateTime = () => {
    const now = new Date();
    // 24.6C: locale-independent Vietnamese datetime — never shows AM/PM or MM/DD/YYYY
    const dd = String(now.getDate()).padStart(2, '0');
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const yyyy = now.getFullYear();
    const hh = String(now.getHours()).padStart(2, '0');
    const mi = String(now.getMinutes()).padStart(2, '0');
    const ss = String(now.getSeconds()).padStart(2, '0');
    currentTime.value = `${dd}/${mm}/${yyyy} ${hh}:${mi}:${ss}`;
};

const checkAndHydrateOrderFromUrl = async () => {
    const urlParams = new URLSearchParams(window.location.search);
    const orderId = urlParams.get('order_id');
    const mode = urlParams.get('mode');

    if (mode === 'process_order' && orderId) {
        // Clear query parameters from URL so hard-reload doesn't keep duplicating/reloading
        const newUrl = window.location.pathname;
        window.history.replaceState({}, document.title, newUrl);

        try {
            const res = await axios.get(`/orders/${orderId}/pos-payload`);
            if (res.data && res.data.success) {
                const orderData = res.data.order;
                
                // Check if this order tab already exists
                const existingIndex = tabs.value.findIndex(t => t.source_order_id === orderData.id);
                if (existingIndex !== -1) {
                    activeTabIndex.value = existingIndex;
                    return;
                }

                // If first tab is empty and has no customer, we can reuse it
                let tabToUse;
                if (tabs.value.length === 1 && tabs.value[0].cart.length === 0 && !tabs.value[0].selectedCustomer && tabs.value[0].mode === 'normal') {
                    tabToUse = tabs.value[0];
                } else {
                    tabToUse = createNewTab('sale');
                    tabs.value.push(tabToUse);
                    activeTabIndex.value = tabs.value.length - 1;
                }

                // Prefill tab details
                tabToUse.mode = 'process_order';
                tabToUse.source_order_id = orderData.id;
                tabToUse.source_order_code = orderData.code;
                tabToUse.selectedCustomer = orderData.customer;
                tabToUse.discount = orderData.totals.discount;
                tabToUse.orderDepositAmount = orderData.totals.amount_paid;
                tabToUse.note = orderData.note;
                tabToUse.saleMode = orderData.delivery.is_delivery ? 'delivery' : 'normal';
                
                // Delivery fields
                tabToUse.delivery = {
                    is_delivery: orderData.delivery.is_delivery,
                    delivery_mode: orderData.delivery.delivery_mode || (orderData.delivery.is_delivery ? 'self' : 'none'),
                    delivery_partner: orderData.delivery.delivery_partner || '',
                    tracking_code: orderData.delivery.tracking_code || '',
                    delivery_fee: orderData.delivery.delivery_fee || 0,
                    cod_amount: orderData.delivery.cod_amount || 0,
                    receiver_name: orderData.delivery.receiver_name || orderData.customer?.name || '',
                    receiver_phone: orderData.delivery.receiver_phone || orderData.customer?.phone || '',
                    receiver_address: orderData.delivery.receiver_address || orderData.customer?.address || '',
                    receiver_ward: orderData.delivery.receiver_ward || '',
                    receiver_district: orderData.delivery.receiver_district || '',
                    receiver_city: orderData.delivery.receiver_city || '',
                    weight: orderData.delivery.weight || 0,
                    delivery_note: orderData.delivery.delivery_note || '',
                };

                // Cart items
                tabToUse.cart = orderData.items.map(item => {
                    const cartItem = {
                        product: {
                            id: item.product_id,
                            sku: item.sku,
                            name: item.name,
                            cost_price: item.cost_price || 0,
                            retail_price: item.price,
                            has_serial: item.has_serial,
                            stock_quantity: item.stock_quantity,
                        },
                        quantity: item.qty,
                        targetQuantity: item.qty,
                        price: item.price,
                        discount: item.discount,
                        is_serial_product: item.has_serial,
                        serials: item.selected_serials || [],
                        serialInput: '',
                        showSerialDropdown: false,
                        allAvailableSerials: [],
                        availableSerials: [],
                        serialLoading: false,
                    };
                    if (item.has_serial) {
                        loadSerialsForProduct(cartItem);
                    }
                    return cartItem;
                });

                // Default pay additional: remaining debt (or 0 if negative)
                tabToUse.customerPaid = Math.max(0, orderData.totals.remaining);

                saveDraft();
            } else {
                alert("Lỗi load đơn đặt hàng: " + res.data.message);
            }
        } catch (e) {
            console.error(e);
            alert("Lỗi kết nối máy chủ khi load đơn đặt hàng.");
        }
    }
};

watch(() => saleMode.value, (newMode) => {
    if (!activeTab.value) return;
    if (newMode === 'delivery') {
        if (!activeTab.value.delivery) {
            activeTab.value.delivery = {
                is_delivery: true,
                delivery_mode: 'self',
                delivery_partner: '',
                tracking_code: '',
                delivery_fee: 0,
                cod_amount: 0,
                receiver_name: selectedCustomer.value?.name || '',
                receiver_phone: selectedCustomer.value?.phone || '',
                receiver_address: selectedCustomer.value?.address || '',
                receiver_ward: '',
                receiver_district: '',
                receiver_city: '',
                weight: 0,
                delivery_note: '',
            };
        } else {
            activeTab.value.delivery.is_delivery = true;
            if (activeTab.value.delivery.delivery_mode === 'none') {
                activeTab.value.delivery.delivery_mode = 'self';
            }
        }
    } else {
        if (activeTab.value.delivery) {
            activeTab.value.delivery.is_delivery = false;
            activeTab.value.delivery.delivery_mode = 'none';
        }
    }
});

watch(() => selectedCustomer.value, (customer) => {
    if (customer && activeTab.value && activeTab.value.delivery) {
        const d = activeTab.value.delivery;
        if (!d.receiver_name) d.receiver_name = customer.name || '';
        if (!d.receiver_phone) d.receiver_phone = customer.phone || '';
        if (!d.receiver_address) d.receiver_address = customer.address || '';
    }
});

let timeInterval;
onMounted(() => {
    updateTime();
    timeInterval = setInterval(updateTime, 1000);
    searchProducts();
    loadDraft();
    checkAndHydrateOrderFromUrl();
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
    if (activeTab.value && activeTab.value.mode === 'process_order' && item.serials.length >= item.targetQuantity) {
        alert(`Sản phẩm này yêu cầu chính xác ${item.targetQuantity} serial. Vui lòng bỏ chọn serial cũ trước khi chọn serial mới.`);
        return;
    }
    if (!item.serials.find(s => s.id === serialObj.id)) {
        item.serials.push(serialObj);
        if (!activeTab.value || activeTab.value.mode !== 'process_order') {
            item.quantity = item.serials.length;
        }
        normalizeExchangeLineDiscount(item);
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
    if (!activeTab.value || activeTab.value.mode !== 'process_order') {
        item.quantity = item.serials.length;
    }
    normalizeExchangeLineDiscount(item);
    searchSerialsForItem(item);
};

const selectAllSerialsForItem = (item) => {
    if (!item.availableSerials) return;
    item.availableSerials.forEach(s => {
        if (activeTab.value && activeTab.value.mode === 'process_order' && item.serials.length >= item.targetQuantity) {
            return;
        }
        if (!item.serials.find(sel => sel.id === s.id)) {
            item.serials.push(s);
        }
    });
    if (!activeTab.value || activeTab.value.mode !== 'process_order') {
        item.quantity = item.serials.length;
    }
    normalizeExchangeLineDiscount(item);
    item.serialInput = '';
    item.showSerialDropdown = false;
    searchSerialsForItem(item);
};

const deselectAllSerialsForItem = (item) => {
    item.serials = [];
    if (!activeTab.value || activeTab.value.mode !== 'process_order') {
        item.quantity = 0;
    }
    normalizeExchangeLineDiscount(item);
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
        selectedSellerKey: selectedSellerKey.value,
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
            // HOTFIX 24.33 — prefer new seller_key draft; fall back to legacy
            // employee id (convert to employee:<id>).
            if (data.selectedSellerKey) {
                selectedSellerKey.value = data.selectedSellerKey;
            } else if (data.selectedEmployeeId) {
                selectedSellerKey.value = `employee:${data.selectedEmployeeId}`;
            }
            // KHÔNG restore saleDate từ draft — luôn dùng thời gian hiện tại
            // để tránh hoá đơn mới bị ghi ngày cũ

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

watch([tabs, activeTabIndex, selectedSellerKey, saleDate], () => {
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

const priorDeposit = computed(() => {
    return activeTab.value ? (activeTab.value.orderDepositAmount || 0) : 0;
});

const totalPaidOverall = computed(() => {
    return priorDeposit.value + (Number(customerPaid.value) || 0);
});

const remainingDebt = computed(() => {
    return totalAmount.value - totalPaidOverall.value;
});

const changeDue = computed(() => {
    if (activeTab.value && activeTab.value.mode === 'process_order') {
        return totalPaidOverall.value > totalAmount.value ? (totalPaidOverall.value - totalAmount.value) : 0;
    }
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

        // Bán từ Đơn đặt hàng (Xử lý đơn hàng qua POS)
        if (activeTab.value.mode === 'process_order') {
            const invalidSerials = cart.value.filter(i => i.is_serial_product && i.serials.length !== i.targetQuantity);
            if (invalidSerials.length > 0) {
                const names = invalidSerials.map(i => `'${i.product.name}' (yêu cầu ${i.targetQuantity}, đã chọn ${i.serials.length})`).join(', ');
                alert(`Có sản phẩm Serial/IMEI chưa chọn đủ số lượng mã: ${names}`);
                isCheckingOut.value = false;
                return;
            }

            const processPayload = {
                from_pos: true,
                amount_paid: Number(customerPaid.value) || 0,
                payment_method: paymentMethod.value,
                bank_account_info: paymentMethod.value === 'transfer' ? bankAccountInfo.value : null,
                delivery: {
                    is_delivery: activeTab.value.delivery?.is_delivery || false,
                    delivery_mode: activeTab.value.delivery?.delivery_mode || 'none',
                    delivery_partner: activeTab.value.delivery?.delivery_partner || '',
                    tracking_code: activeTab.value.delivery?.tracking_code || '',
                    delivery_fee: Number(activeTab.value.delivery?.delivery_fee) || 0,
                    cod_amount: Number(activeTab.value.delivery?.cod_amount) || 0,
                    receiver_name: activeTab.value.delivery?.receiver_name || '',
                    receiver_phone: activeTab.value.delivery?.receiver_phone || '',
                    receiver_address: activeTab.value.delivery?.receiver_address || '',
                    receiver_ward: activeTab.value.delivery?.receiver_ward || '',
                    receiver_district: activeTab.value.delivery?.receiver_district || '',
                    receiver_city: activeTab.value.delivery?.receiver_city || '',
                    weight: Number(activeTab.value.delivery?.weight) || 0,
                    delivery_note: activeTab.value.delivery?.delivery_note || '',
                },
                items: cart.value.map(item => ({
                    product_id: item.product.id,
                    quantity: item.quantity,
                    serial_ids: item.is_serial_product ? item.serials.map(s => s.id) : [],
                }))
            };

            const response = await axios.post(`/orders/${activeTab.value.source_order_id}/process`, processPayload);
            if (response.data.success) {
                toastMsg.value = response.data.message;
                setTimeout(() => toastMsg.value = '', 4000);
                resetAfterCheckout();
            } else {
                alert("Lỗi: " + response.data.message);
            }
            return;
        }

        // Đặt nhanh: tạo Order (Phiếu tạm) — không cần thanh toán
        if (saleMode.value === 'quick_order') {
            const orderPayload = {
                subtotal: Number(subtotal.value) || 0,
                discount: Number(discount.value) || 0,
                total: Number(totalAmount.value) || 0,
                customer_id: selectedCustomer.value?.id || null,
                seller_key: selectedSellerKey.value || null,
                employee_id: selectedEmployeeId.value || null,
                sale_time: saleDate.value || null,
                note: orderNote.value || null,
                items: cart.value.map(item => ({
                    product_id: item.product.id,
                    quantity: item.quantity,
                    price: Number(item.price) || 0,
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
            subtotal: Number(subtotal.value) || 0,
            discount: Number(discount.value) || 0,
            total: Number(totalAmount.value) || 0,
            customer_paid: Number(customerPaid.value) || 0,
            customer_id: selectedCustomer.value?.id || null,
            seller_key: selectedSellerKey.value || null,
            employee_id: selectedEmployeeId.value || null,
            sale_time: saleDate.value || null,
            payment_method: paymentMethod.value,
            bank_account_info: paymentMethod.value === 'transfer' ? bankAccountInfo.value : null,
            note: orderNote.value || null,
            items: cart.value.map(item => ({
                product_id: item.product.id,
                quantity: item.quantity,
                price: Number(item.price) || 0,
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
        t.note = '';
    }
    // Reset saleDate to current time after checkout
    const now = new Date();
    saleDate.value = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
    saveDraft();
    searchProducts();
};

// STEP 24.13 — page just toggles the shared QuickCreateProductModal.
const showCreateProductModal = ref(false);
const openCreateProductModal = () => { showCreateProductModal.value = true; };

// ════════════════════════════════════════════════════════════════════
//  STEP 24.6 — POS Return Workspace Tab (KiotViet-style)
//
//  Each tab with type='return' carries its own returnState (see
//  emptyReturnState above). All business rules (RR-08, RR-11, Step 23.2
//  serial) stay server-side; this code only collects the payload and
//  surfaces backend errors verbatim.
//
//  24.6B: exchange items are submitted to /api/pos/return-exchange so
//  return + exchange invoice commit atomically on the backend.
// ════════════════════════════════════════════════════════════════════

// Activate-or-create a Return tab.
const openReturnTab = () => {
    // If there's already an empty return tab, just switch to it.
    const existingIdx = tabs.value.findIndex((t) => t.type === 'return' && !t.returnState?.sourceInvoice
        && !Object.values(t.returnState?.lineState || {}).some((l) => l && l.qty > 0));
    if (existingIdx >= 0) {
        activeTabIndex.value = existingIdx;
        return;
    }
    addTab('return');
};

// Per-tab debounced search timers (one Map keyed by tab id).
const returnSearchTimers = new Map();
const onReturnSearchInput = (tab) => {
    if (!tab || tab.type !== 'return' || !tab.returnState) return;
    if (returnSearchTimers.has(tab.id)) clearTimeout(returnSearchTimers.get(tab.id));
    returnSearchTimers.set(tab.id, setTimeout(() => searchReturnableInvoices(tab), 250));
};

const searchReturnableInvoices = async (tab) => {
    const rs = tab?.returnState;
    if (!rs) return;
    rs.error = '';
    const term = (rs.search || '').trim();
    if (term.length < 1) {
        rs.searchResults = [];
        return;
    }
    rs.searching = true;
    try {
        const { data } = await axios.get('/api/pos/returnable-invoices', {
            params: { search: term },
        });
        rs.searchResults = Array.isArray(data) ? data : [];
    } catch (e) {
        if (e.response?.status === 403) {
            rs.error = 'Bạn không có quyền tạo phiếu trả hàng.';
        } else {
            rs.error = e.response?.data?.message || 'Không tìm được hóa đơn.';
        }
        rs.searchResults = [];
    } finally {
        rs.searching = false;
    }
};

const selectReturnInvoice = async (tab, inv) => {
    const rs = tab?.returnState;
    if (!rs) return;
    rs.error = '';
    rs.loadingItems = true;
    try {
        const { data } = await axios.get(`/api/pos/invoices/${inv.id}/returnable-items`);
        rs.sourceInvoice = data.invoice;
        rs.sourceItems = data.items || [];
        const map = {};
        for (const item of rs.sourceItems) {
            map[returnLineKey(item)] = { qty: 0, serial_ids: [] };
        }
        rs.lineState = map;
        rs.hiddenReturnLines = {};
        rs.discount = 0;
        rs.fee = 0;
        rs.paidToCustomer = 0;
        rs.paidToCustomerTouched = false;
        rs.note = '';
    } catch (e) {
        if (e.response?.status === 403) {
            rs.error = 'Bạn không có quyền tạo phiếu trả hàng.';
        } else if (e.response?.status === 422) {
            rs.error = e.response?.data?.message || 'Hóa đơn không hợp lệ.';
        } else {
            rs.error = e.response?.data?.message || 'Không tải được chi tiết hóa đơn.';
        }
    } finally {
        rs.loadingItems = false;
    }
};

const returnLineKey = (item) => item?.invoice_item_id ?? item?.id;
const returnLineState = (tab, item) => tab?.returnState?.lineState?.[returnLineKey(item)] || { qty: 0, serial_ids: [] };
const isReturnLineHidden = (tab, item) => !!tab?.returnState?.hiddenReturnLines?.[returnLineKey(item)];
const visibleReturnItems = (tab) => {
    const rs = tab?.returnState;
    if (!rs) return [];
    return (rs.sourceItems || []).filter((item) => !isReturnLineHidden(tab, item));
};
const returnLineDiscount = (item, qty) => {
    const lineDiscount = Number(item?.discount) || 0;
    if (!lineDiscount || !qty) return 0;
    const soldQty = Number(item?.sold_qty) || qty;
    return Math.round(lineDiscount * qty / Math.max(1, soldQty));
};
const returnLineAmount = (item, tab = activeTab.value) => {
    const ls = returnLineState(tab, item);
    const qty = Number(ls.qty) || 0;
    return Math.max(0, qty * (Number(item?.price) || 0) - returnLineDiscount(item, qty));
};

const backToInvoiceSearch = (tab) => {
    const rs = tab?.returnState;
    if (!rs) return;
    rs.sourceInvoice = null;
    rs.sourceItems = [];
    rs.lineState = {};
    rs.hiddenReturnLines = {};
    rs.error = '';
};

const hideReturnLine = (tab, item) => {
    const rs = tab?.returnState;
    if (!rs) return;
    const key = returnLineKey(item);
    const nextLineState = { ...(rs.lineState || {}) };
    nextLineState[key] = { qty: 0, serial_ids: [] };
    rs.lineState = nextLineState;
    rs.hiddenReturnLines = { ...(rs.hiddenReturnLines || {}), [key]: true };
};

const setReturnQty = (tab, item, qty) => {
    const rs = tab?.returnState;
    if (!rs) return;
    const key = returnLineKey(item);
    const ls = rs.lineState[key] || { qty: 0, serial_ids: [] };
    let next = Number(qty) || 0;
    if (next < 0) next = 0;
    if (next > item.remaining_qty) next = item.remaining_qty;
    ls.qty = next;
    if (item.has_serial && ls.serial_ids.length > next) {
        ls.serial_ids = ls.serial_ids.slice(0, next);
    }
    rs.lineState = { ...rs.lineState, [key]: ls };
};

const toggleReturnSerial = (tab, item, serialId) => {
    const rs = tab?.returnState;
    if (!rs) return;
    const key = returnLineKey(item);
    const ls = rs.lineState[key] || { qty: 0, serial_ids: [] };
    const idx = ls.serial_ids.indexOf(serialId);
    if (idx >= 0) {
        ls.serial_ids = [...ls.serial_ids.slice(0, idx), ...ls.serial_ids.slice(idx + 1)];
    } else {
        ls.serial_ids = [...ls.serial_ids, serialId];
    }
    ls.qty = ls.serial_ids.length;
    rs.lineState = { ...rs.lineState, [key]: ls };
};

const exchangeSearchTimers = new Map();
const focusExchangeSearchOverlay = (tab) => {
    nextTick(() => {
        document.getElementById(`return-exchange-overlay-input-${tab?.id}`)?.focus();
    });
};

const openExchangeSearch = (tab) => {
    const rs = tab?.returnState;
    if (!rs) return;
    rs.exchangeSearchOpen = true;
    focusExchangeSearchOverlay(tab);
    if ((rs.exchangeSearch || '').trim()) {
        searchExchangeProducts(tab);
    }
};

const closeExchangeSearch = (tab) => {
    const rs = tab?.returnState;
    if (!rs) return;
    rs.exchangeSearchOpen = false;
};

const exchangeAvailableQty = (productOrLine) => {
    const product = productOrLine?.product || productOrLine;
    if (!product) return 0;
    const raw = product.has_serial && product.sellable_quantity !== undefined
        ? product.sellable_quantity
        : product.stock_quantity;
    return Math.max(0, Number(raw) || 0);
};

const resolveExchangeDefaultPrice = (product) => {
    const candidates = [
        product?.retail_price,
        product?.selling_price,
        product?.sale_price,
        product?.price,
        product?.unit_price,
    ];

    for (const value of candidates) {
        const n = Number(value);
        if (Number.isFinite(n) && n > 0) return n;
    }

    return 0;
};

const exchangeLineGross = (item) => Math.max(
    0,
    (Number(item?.price) || 0) * (Number(item?.quantity) || 0),
);

const normalizeExchangeLineDiscount = (item) => {
    if (!item) return;
    const gross = exchangeLineGross(item);
    let discount = Number(item.discount) || 0;

    if (discount < 0) discount = 0;
    if (discount > gross) discount = gross;
    if ((Number(item.price) || 0) <= 0) discount = 0;

    item.discount = discount;
};

const normalizeAllExchangeLines = (tab) => {
    for (const item of tab?.returnState?.exchangeItems || []) {
        normalizeExchangeLineDiscount(item);
    }
};

const exchangeLineAmount = (item) => {
    const gross = exchangeLineGross(item);
    const discount = Math.min(Math.max(0, Number(item?.discount) || 0), gross);
    return Math.max(0, gross - discount);
};

const validateExchangeLineQty = (line) => {
    if (!line) return false;
    const available = exchangeAvailableQty(line);
    if (line.is_serial_product) {
        line.quantity = line.serials?.length || 0;
        line.stockWarning = line.quantity > available ? `Vượt tồn khả dụng (${available})` : '';
        return line.quantity > 0 && line.quantity <= available;
    }
    const qty = Number(line.quantity) || 0;
    line.stockWarning = qty > available ? `Vượt tồn khả dụng (${available})` : '';
    return qty > 0 && qty <= available;
};

const setExchangeQty = (line, value) => {
    if (!line || line.is_serial_product) return;
    const available = exchangeAvailableQty(line);
    let next = Number.parseInt(value, 10);
    if (!Number.isFinite(next) || next < 1) next = 1;
    if (available <= 0) {
        line.quantity = 0;
        line.stockWarning = 'Hết tồn kho';
        normalizeExchangeLineDiscount(line);
        return;
    }
    if (next > available) {
        next = available;
        line.stockWarning = `Chỉ còn ${available} trong kho`;
    } else {
        line.stockWarning = '';
    }
    line.quantity = next;
    normalizeExchangeLineDiscount(line);
};

const focusExchangeSerialOverlay = (item) => {
    nextTick(() => {
        document.getElementById(`exchange-serial-overlay-input-${item?.product?.id}`)?.focus();
    });
};

const openExchangeSerialSearch = (item) => {
    if (!item) return;
    item.showSerialDropdown = true;
    searchSerialsForItem(item, true);
    focusExchangeSerialOverlay(item);
};

const onExchangeSearchInput = (tab) => {
    if (!tab || tab.type !== 'return' || !tab.returnState) return;
    tab.returnState.exchangeSearchOpen = true;
    if (exchangeSearchTimers.has(tab.id)) clearTimeout(exchangeSearchTimers.get(tab.id));
    exchangeSearchTimers.set(tab.id, setTimeout(() => searchExchangeProducts(tab), 250));
};

const searchExchangeProducts = async (tab) => {
    const rs = tab?.returnState;
    if (!rs) return;
    const term = (rs.exchangeSearch || '').trim();
    if (term.length < 1) {
        rs.exchangeResults = [];
        return;
    }
    rs.exchangeSearching = true;
    rs.error = '';
    try {
        const { data } = await axios.get('/api/pos/products', { params: { search: term } });
        rs.exchangeResults = Array.isArray(data) ? data : [];
    } catch (e) {
        rs.error = e.response?.data?.message || 'Không tìm được hàng đổi.';
        rs.exchangeResults = [];
    } finally {
        rs.exchangeSearching = false;
    }
};

const addExchangeItem = async (tab, product) => {
    const rs = tab?.returnState;
    if (!rs || !product) return;
    if (exchangeAvailableQty(product) <= 0) {
        rs.error = 'Hàng đổi đã hết tồn kho, không thể chọn để đổi.';
        return;
    }
    const existing = rs.exchangeItems.find((line) => line.product.id === product.id && !line.is_serial_product);
    if (existing && !product.has_serial) {
        setExchangeQty(existing, (Number(existing.quantity) || 0) + 1);
    } else {
        const line = {
            product,
            quantity: product.has_serial ? 0 : 1,
            price: resolveExchangeDefaultPrice(product),
            discount: 0,
            is_serial_product: !!product.has_serial,
            serials: [],
            serialInput: '',
            availableSerials: [],
            allAvailableSerials: [],
            showSerialDropdown: false,
            serialLoading: false,
            stockWarning: '',
        };
        rs.exchangeItems.push(line);
        if (line.is_serial_product) {
            await loadSerialsForProduct(line);
        }
    }
    rs.exchangeSearch = '';
    rs.exchangeResults = [];
    rs.exchangeSearchOpen = false;
};

const removeExchangeItem = (tab, idx) => {
    const rs = tab?.returnState;
    if (!rs) return;
    rs.exchangeItems.splice(idx, 1);
};

const updateExchangeQty = (line, delta) => {
    if (!line || line.is_serial_product) return;
    setExchangeQty(line, (Number(line.quantity) || 1) + delta);
    normalizeExchangeLineDiscount(line);
};

// Subtotal/total/canSubmit are computed against the active return tab.
const activeReturnSubtotal = computed(() => {
    const tab = activeTab.value;
    if (!tab || tab.type !== 'return' || !tab.returnState?.sourceInvoice) return 0;
    let sum = 0;
    for (const item of visibleReturnItems(tab)) {
        sum += returnLineAmount(item, tab);
    }
    return sum;
});

// Step 24.6E: derive VND fee from (feeType, feeValue). Mirrors backend
// ReturnTotalCalculator so the user sees the same number the server will
// charge — but the server still recomputes from the raw inputs.
const activeReturnFeeAmount = computed(() => {
    const tab = activeTab.value;
    if (!tab || tab.type !== 'return' || !tab.returnState) return 0;
    const rs = tab.returnState;
    const base = Math.max(0, activeReturnSubtotal.value - (Number(rs.discount) || 0));
    const value = Math.max(0, Number(rs.feeValue) || 0);
    if (rs.feeType === 'percent') {
        const capped = Math.min(100, value);
        return Math.round(base * capped / 100);
    }
    return Math.min(base, Math.round(value));
});

const activeReturnTotal = computed(() => {
    const tab = activeTab.value;
    if (!tab || tab.type !== 'return' || !tab.returnState) return 0;
    return Math.max(
        0,
        activeReturnSubtotal.value
        - (Number(tab.returnState.discount) || 0)
        - activeReturnFeeAmount.value,
    );
});

const activeExchangeSubtotal = computed(() => {
    const tab = activeTab.value;
    if (!tab || tab.type !== 'return' || !tab.returnState) return 0;
    return (tab.returnState.exchangeItems || []).reduce((sum, item) => {
        return sum + exchangeLineAmount(item);
    }, 0);
});

const activeExchangeTotal = computed(() => {
    const tab = activeTab.value;
    if (!tab || tab.type !== 'return' || !tab.returnState) return 0;
    return Math.max(0, activeExchangeSubtotal.value - (Number(tab.returnState.exchangeDiscount) || 0));
});

const activeExchangeSerialValid = computed(() => {
    const tab = activeTab.value;
    if (!tab || tab.type !== 'return' || !tab.returnState) return true;
    return (tab.returnState.exchangeItems || []).every((item) => {
        if (!validateExchangeLineQty(item)) return false;
        if (!item.is_serial_product) return true;
        return item.serials.length > 0 && item.serials.length === (Number(item.quantity) || 0);
    });
});

const activeExchangeValidationMessage = computed(() => {
    const tab = activeTab.value;
    if (!tab || tab.type !== 'return' || !tab.returnState) return '';
    for (const item of tab.returnState.exchangeItems || []) {
        const available = exchangeAvailableQty(item);
        const qty = Number(item.quantity) || 0;
        const price = Number(item.price) || 0;
        const gross = price * qty;
        const discount = Number(item.discount) || 0;
        if (available <= 0) return `${item.product.name} đã hết tồn kho.`;
        if (price <= 0) return `Hàng đổi "${item.product.name}" đang có đơn giá 0đ, vui lòng nhập đơn giá.`;
        if (discount < 0) return `Giảm giá hàng đổi "${item.product.name}" không được âm.`;
        if (discount > gross) return `Giảm giá hàng đổi "${item.product.name}" không được vượt thành tiền dòng.`;
        if (!item.is_serial_product && qty > available) return `${item.product.name} chỉ còn ${available} trong kho.`;
        if (item.is_serial_product && item.serials.length === 0) return `${item.product.name} cần chọn Serial/IMEI hàng đổi.`;
        if (item.is_serial_product && item.serials.length > available) return `${item.product.name} vượt số serial sẵn bán (${available}).`;
    }
    return '';
});

const activeExchangeNetAmount = computed(() => activeExchangeTotal.value - activeReturnTotal.value);
const activeCustomerPays = computed(() => Math.max(0, activeExchangeNetAmount.value));
const activeRefundToCustomer = computed(() => Math.max(0, -activeExchangeNetAmount.value));
const hasActiveExchangeItems = computed(() => {
    const tab = activeTab.value;
    return !!(tab?.type === 'return' && tab.returnState?.exchangeItems?.length);
});

const canSubmitActiveExchange = computed(() => {
    const tab = activeTab.value;
    if (!tab || tab.type !== 'return' || !tab.returnState?.sourceInvoice) return false;
    if (!canSubmitActiveReturn.value) return false;
    if ((tab.returnState.exchangeItems || []).length === 0) return true;
    return activeExchangeTotal.value >= 0 && activeExchangeSerialValid.value && !activeExchangeValidationMessage.value;
});

// When the user hasn't manually edited paidToCustomer, keep it in sync with
// the canonical net refund — KiotViet UX: "tiền trả khách = cần trả khách".
watch([activeReturnTotal, activeRefundToCustomer, hasActiveExchangeItems], () => {
    const tab = activeTab.value;
    if (!tab || tab.type !== 'return' || !tab.returnState) return;
    if (hasActiveExchangeItems.value) {
        tab.returnState.paidToCustomer = activeRefundToCustomer.value;
        tab.returnState.paidToCustomerTouched = false;
        return;
    }
    if (!tab.returnState.paidToCustomerTouched) {
        tab.returnState.paidToCustomer = activeReturnTotal.value;
    }
});

const activeOriginalTotal = computed(() => {
    const tab = activeTab.value;
    return tab?.returnState?.sourceInvoice?.total || 0;
});

const canSubmitActiveReturn = computed(() => {
    const tab = activeTab.value;
    if (!tab || tab.type !== 'return' || !tab.returnState?.sourceInvoice) return false;
    const rs = tab.returnState;
    const items = visibleReturnItems(tab);
    const anyLine = items.some((item) => {
        const ls = rs.lineState[returnLineKey(item)];
        return ls && ls.qty > 0;
    });
    if (!anyLine) return false;
    for (const item of items) {
        const ls = rs.lineState[returnLineKey(item)];
        if (!ls || !ls.qty) continue;
        if (item.has_serial && ls.serial_ids.length !== ls.qty) return false;
    }
    return true;
});

const formatAxiosError = (e, fallback = 'Có lỗi xảy ra.') => {
    if (!e.response) {
        return 'Không kết nối được máy chủ. Vui lòng kiểm tra mạng hoặc đăng nhập lại.';
    }

    const status = e.response.status;
    const data = e.response.data || {};

    if (status === 404) {
        return 'Không tìm thấy API đổi hàng (/api/pos/return-exchange). Có thể server chưa deploy đúng code hoặc route cache cũ.';
    }

    if (status === 419) {
        return 'Phiên đăng nhập đã hết hạn hoặc CSRF không hợp lệ. Vui lòng tải lại trang rồi thử lại.';
    }

    if (status === 403) {
        return data.message || 'Bạn không có quyền thực hiện thao tác này.';
    }

    if (status === 422) {
        if (data.errors && typeof data.errors === 'object') {
            return Object.values(data.errors).flat().join(' • ');
        }
        return data.message || 'Dữ liệu không hợp lệ.';
    }

    if (data.debug_id) {
        return `${data.message || fallback} (${data.error_code || 'ERROR'} / ${data.debug_id})`;
    }

    if (data.message) {
        return data.message;
    }

    return `${fallback} HTTP ${status}`;
};

const resetReturnTab = (tab) => {
    const rs = tab?.returnState;
    if (!rs) return;
    Object.assign(rs, emptyReturnState());
};

const submitReturnTab = async (tab) => {
    if (!tab || tab.type !== 'return') return;
    const rs = tab.returnState;
    if (!rs?.sourceInvoice) return;
    normalizeAllExchangeLines(tab);
    if (!canSubmitActiveExchange.value && tab.id === activeTab.value?.id) return;

    const hasExchange = (rs.exchangeItems || []).length > 0;
    rs.error = '';
    rs.errorTitle = '';
    rs.successResult = null;
    rs.submitting = true;
    const itemsPayload = [];
    const returnItems = visibleReturnItems(tab);
    for (const item of returnItems) {
        const ls = rs.lineState[returnLineKey(item)];
        if (!ls || !ls.qty) continue;
        itemsPayload.push({
            product_id: item.product_id,
            qty: ls.qty,
            price: Number(item.price) || 0,
            discount: Number(returnLineDiscount(item, ls.qty)) || 0,
            invoice_item_id: item.invoice_item_id,
            serial_ids: item.has_serial ? ls.serial_ids : [],
        });
    }
    const subtotal = (() => {
        let s = 0;
        for (const item of returnItems) {
            s += returnLineAmount(item, tab);
        }
        return s;
    })();
    // Step 24.6E: send raw fee_type + fee_value; backend recomputes fee/total
    // canonically. We still send total/fee for backward-compatible callers.
    const base = Math.max(0, subtotal - (Number(rs.discount) || 0));
    const feeValue = Math.max(0, Number(rs.feeValue) || 0);
    const feeAmount = rs.feeType === 'percent'
        ? Math.round(base * Math.min(100, feeValue) / 100)
        : Math.min(base, Math.round(feeValue));
    const total = Math.max(
        0,
        subtotal - (Number(rs.discount) || 0) - feeAmount,
    );
    const payload = {
        invoice_id: rs.sourceInvoice.id,
        customer_id: rs.sourceInvoice.customer_id,
        branch_id: rs.sourceInvoice.branch_id,
        subtotal,
        discount: Number(rs.discount) || 0,
        fee_type: rs.feeType || 'amount',
        fee_value: feeValue,
        fee: feeAmount,
        total: Number(total) || 0,
        paid_to_customer: hasExchange ? (Number(activeRefundToCustomer.value) || 0) : (Number(rs.paidToCustomer) || 0),
        note: rs.note || null,
        items: itemsPayload,
    };
    try {
        const res = hasExchange
            ? await axios.post('/api/pos/return-exchange', {
                invoice_id: rs.sourceInvoice.id,
                customer_id: rs.sourceInvoice.customer_id,
                branch_id: rs.sourceInvoice.branch_id,
                seller_key: selectedSellerKey.value || null,
                employee_id: selectedEmployeeId.value || null,
                sale_time: saleDate.value || null,
                payment_method: rs.exchangePaymentMethod || 'cash',
                bank_account_info: rs.exchangePaymentMethod === 'transfer' ? rs.exchangeBankAccountInfo : null,
                note: rs.note || null,
                return: {
                    discount: Number(rs.discount) || 0,
                    fee_type: rs.feeType || 'amount',
                    fee_value: feeValue,
                    paid_to_customer: Number(activeRefundToCustomer.value) || 0,
                    items: itemsPayload,
                },
                exchange: {
                    discount: Number(rs.exchangeDiscount) || 0,
                    customer_paid: Number(activeCustomerPays.value) || 0,
                    items: rs.exchangeItems.map((item) => ({
                        product_id: item.product.id,
                        quantity: item.quantity,
                        price: Number(item.price) || 0,
                        discount: Number(item.discount) || 0,
                        serial_ids: item.is_serial_product ? item.serials.map((s) => s.id) : [],
                    })),
                },
            })
            : await axios.post('/returns', payload);
        const created = res.data?.return || res.data;
        if (hasExchange) {
            const invoice = res.data?.exchange_invoice;
            rs.successResult = {
                return: created,
                exchange_invoice: invoice,
                settlement: res.data?.settlement || {
                    return_total: total,
                    exchange_total: Number(activeExchangeTotal.value) || 0,
                    customer_pays: Number(activeCustomerPays.value) || 0,
                    refund_to_customer: Number(activeRefundToCustomer.value) || 0,
                },
            };
        } else {
            alert('Đã tạo phiếu trả hàng' + (created?.code ? ` ${created.code}` : '') + '.');
        }
        if (!hasExchange && created?.code) {
            window.open(`/returns?search=${encodeURIComponent(created.code)}`, '_blank');
        }
        if (!hasExchange) {
            // Reset return-only tab so the user can do another return without losing the workspace.
            Object.assign(rs, emptyReturnState());
        }
    } catch (e) {
        rs.errorTitle = hasExchange ? 'Không thể đổi hàng' : 'Không thể tạo phiếu trả';
        rs.error = formatAxiosError(
            e,
            hasExchange ? 'Có lỗi xảy ra khi đổi hàng.' : 'Có lỗi xảy ra khi tạo phiếu trả.',
        );
    } finally {
        rs.submitting = false;
    }
};

// F3 / F7 keyboard shortcuts (only when a return tab is active).
const onGlobalKeydown = (e) => {
    const tab = activeTab.value;
    if (!tab || tab.type !== 'return') return;
    if (e.key === 'F3') {
        e.preventDefault();
        const el = document.getElementById(`return-search-input-${tab.id}`);
        if (el) el.focus();
    } else if (e.key === 'F7') {
        e.preventDefault();
        const el = document.getElementById(`return-exchange-input-${tab.id}`);
        if (el) el.focus();
    }
};
onMounted(() => window.addEventListener('keydown', onGlobalKeydown));
onUnmounted(() => window.removeEventListener('keydown', onGlobalKeydown));
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
                <button @click="openCreateProductModal" class="w-8 h-8 flex items-center justify-center bg-white/20 hover:bg-white/30 rounded transition-colors" title="Tạo nhanh hàng hóa">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
                </button>
                <div class="relative w-[170px] font-sans">
                    <input 
                        v-model="query" 
                        @input="handleSearchInput" 
                        type="text" 
                        :placeholder="activeTab.mode === 'process_order' ? 'Đơn hàng đang xử lý...' : 'Tìm hàng hóa (F3)'" 
                        :disabled="activeTab.mode === 'process_order'"
                        class="w-full pl-7 pr-2 py-1.5 bg-white/90 hover:bg-white focus:bg-white text-gray-800 placeholder-gray-400 rounded text-[12px] outline-none focus:ring-2 focus:ring-white/50 border-none font-medium shadow-sm disabled:opacity-75 disabled:cursor-not-allowed"
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
                            <div class="text-blue-600 font-bold text-[12px]">{{ formatCurrency(product.retail_price || 0) }}</div>
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
                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" :class="tabDotClass(tab)"></span>
                    {{ tabLabel(tab) }}
                    <span v-if="tabBadgeCount(tab) > 0" class="text-[9px] font-bold ml-0.5" :class="idx === activeTabIndex ? 'text-blue-500' : 'text-white/80'">({{ tabBadgeCount(tab) }})</span>
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
                <select v-model="selectedSellerKey" class="bg-white/20 text-white text-[11px] outline-none border-none rounded px-2 py-1 font-medium cursor-pointer min-w-[100px]">
                    <option value="" class="text-gray-800">-- Người bán --</option>
                    <option v-for="seller in sellerOptions" :key="seller.key" :value="seller.key" class="text-gray-800">{{ seller.display_name || seller.name }}</option>
                </select>
                <DateTimePicker
                    v-model="saleDate"
                    naked
                    compact
                    placeholder="dd/MM/yyyy HH:mm"
                    input-class="text-[11px] font-medium text-white placeholder-white/60 bg-white/15 hover:bg-white/25 rounded px-2 py-1 whitespace-nowrap tabular-nums outline-none border-none cursor-pointer focus:ring-1 focus:ring-white/50 w-[155px]"
                />
                <button
                    type="button"
                    @click="openReturnTab"
                    class="flex items-center gap-1 h-7 px-2 bg-white/20 hover:bg-white/30 rounded transition-colors text-[11px] font-medium text-white whitespace-nowrap"
                    title="Mở tab Trả hàng"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h13a4 4 0 010 8h-3m-10-8l4-4m-4 4l4 4" /></svg>
                    Trả hàng
                </button>
                <Link href="/" class="w-7 h-7 flex items-center justify-center bg-white/20 hover:bg-white/30 rounded transition-colors" title="Về Quản lý">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                </Link>
            </div>
        </header>

        <!-- Main Workspace: Split Left (Products/Cart) and Right (Payment Summary) -->
        <main v-if="activeTab.type !== 'return'" class="flex-1 flex overflow-hidden">

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
                
                <!-- Order Process Warning Note -->
                <div v-if="activeTab.mode === 'process_order'" class="bg-blue-50 border-b border-blue-200 px-4 py-2.5 text-xs text-blue-700 flex items-center gap-2">
                    <svg class="w-4 h-4 text-blue-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <div>
                        Đang xử lý đơn đặt hàng <span class="font-bold font-mono">{{ activeTab.source_order_code }}</span>. 
                        Số lượng và đơn giá được cố định. Bạn có thể chọn lại Serial/IMEI nếu cần thiết.
                    </div>
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
                                    <button @click="updateQuantity(index, -1)" :disabled="activeTab.mode === 'process_order'" class="w-7 h-7 flex items-center justify-center rounded bg-white text-gray-600 hover:bg-red-50 hover:text-red-500 transition-colors shadow-sm cursor-pointer disabled:opacity-50">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"></path></svg>
                                    </button>
                                    <input type="text" readonly :value="item.quantity" class="w-10 text-center bg-transparent text-sm font-bold text-gray-800 focus:outline-none focus:ring-0 select-none">
                                    <button @click="updateQuantity(index, 1)" :disabled="activeTab.mode === 'process_order'" class="w-7 h-7 flex items-center justify-center rounded bg-white text-gray-600 hover:bg-blue-50 hover:text-blue-600 transition-colors shadow-sm cursor-pointer disabled:opacity-50">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"></path></svg>
                                    </button>
                                </div>
                            </template>
                        </div>
                        <div class="col-span-2 text-right">
                            <MoneyInput v-model="item.price" :min="0" :disabled="activeTab.mode === 'process_order'" input-class="w-full text-right outline-none bg-transparent border-b border-dashed border-gray-300 focus:border-blue-500 py-1 font-semibold text-gray-700 disabled:border-none disabled:bg-transparent" />
                        </div>
                        <div class="col-span-2 text-right">
                            <div class="font-bold text-gray-900 text-[15px]">{{ formatCurrency(Number(item.price * item.quantity - (item.discount || 0)) || 0) }}</div>
                            <div class="flex items-center justify-end gap-1 mt-0.5">
                                <span class="text-[10px] text-gray-400">CK:</span>
                                <MoneyInput v-model="item.discount" :min="0" :disabled="activeTab.mode === 'process_order'" input-class="w-16 text-right text-[11px] outline-none bg-transparent border-b border-dashed border-gray-300 focus:border-blue-500 py-0 text-gray-500 disabled:border-none disabled:bg-transparent" placeholder="0" />
                            </div>
                        </div>
                        <div class="col-span-1 flex justify-center">
                            <button v-if="activeTab.mode !== 'process_order'" @click="removeFromCart(index)" class="text-gray-400 hover:text-red-500 hover:bg-red-50 p-2 rounded-full transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                            <svg v-else class="w-5 h-5 text-gray-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
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
                            @click="activeTab.mode !== 'process_order' ? addToCart(product) : null"
                            class="w-32 flex-none bg-white rounded border border-gray-200 p-2 hover:border-blue-500 hover:shadow-md cursor-pointer transition-all flex flex-col shadow-sm group"
                            :class="product.has_serial && product.sellable_quantity <= 0 ? 'opacity-40 pointer-events-none' : (activeTab.mode === 'process_order' ? 'opacity-50 cursor-not-allowed' : '')"
                        >
                            <div class="flex-1 text-sm font-semibold text-gray-800 line-clamp-3 leading-snug group-hover:text-blue-700">{{ product.name }}</div>
                            <div v-if="product.repairing_count > 0" class="text-[10px] text-yellow-600 font-bold mt-1">{{ product.repairing_count }} đang sửa</div>
                            <div class="text-blue-600 font-bold mt-2 font-mono text-sm tracking-tighter">{{ formatCurrency(product.retail_price || 0) }}</div>
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
                                    <span v-if="selectedCustomer.debt_amount > 0" class="text-red-500 ml-1">| Nợ: {{ formatCurrency(selectedCustomer.debt_amount || 0) }}</span>
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
                                    <div v-if="c.debt_amount > 0" class="text-xs text-red-500 font-semibold">Nợ: {{ formatCurrency(c.debt_amount || 0) }}</div>
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
                        <span class="font-bold">{{ formatCurrency(subtotal || 0) }}</span>
                    </div>
                    
                    <div class="flex justify-between items-center text-gray-700 font-medium">
                        <span class="border-b border-dashed border-gray-400 cursor-pointer hover:text-blue-600 transition-colors">Giảm giá</span>
                        <div class="flex items-center">
                            <MoneyInput v-model="discount" :min="0" input-class="w-24 text-right border-b border-gray-300 focus:border-blue-500 outline-none pr-1" />
                        </div>
                    </div>
                    
                    <div class="flex justify-between border-t border-gray-200 pt-3 text-gray-900 font-bold text-lg mt-1">
                        <span>Khách cần trả</span>
                        <span class="text-blue-700 tracking-tight text-xl">{{ formatCurrency(totalAmount || 0) }}</span>
                    </div>

                    <template v-if="activeTab.mode === 'process_order'">
                        <div class="flex justify-between items-center text-gray-700 font-medium pt-2">
                            <span>Khách đã đặt cọc</span>
                            <span class="font-bold text-gray-800">{{ formatCurrency(priorDeposit || 0) }}</span>
                        </div>
                        <div class="flex justify-between items-center pt-2 text-gray-700 font-medium">
                            <span>Khách trả thêm</span>
                            <MoneyInput v-model="customerPaid" :min="0" :placeholder="String(Math.max(0, totalAmount - priorDeposit))" input-class="w-32 text-right border-b border-gray-300 focus:border-blue-500 outline-none font-bold text-gray-900" />
                        </div>
                        <div class="flex justify-between items-center pb-2 text-gray-500 text-sm font-medium">
                            <span :class="remainingDebt > 0 ? 'text-red-500 font-semibold' : ''">{{ remainingDebt > 0 ? 'Còn nợ' : 'Tiền thừa trả khách' }}</span>
                            <span :class="remainingDebt > 0 ? 'text-red-500 font-semibold' : ''">{{ formatCurrency(Math.abs(remainingDebt) || 0) }}</span>
                        </div>
                    </template>
                    <template v-else>
                        <div class="flex justify-between items-center pt-2 text-gray-700 font-medium">
                            <span>Khách thanh toán</span>
                            <MoneyInput v-model="customerPaid" :min="0" :placeholder="String(totalAmount)" input-class="w-32 text-right border-b border-gray-300 focus:border-blue-500 outline-none font-bold text-gray-900" />
                        </div>

                        <div class="flex justify-between items-center pb-2 text-gray-500 text-sm font-medium">
                            <span>Tiền thừa trả khách</span>
                            <span>{{ formatCurrency(changeDue || 0) }}</span>
                        </div>
                    </template>

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

                    <!-- Delivery details section (Bán giao hàng) -->
                    <div v-if="saleMode === 'delivery' && activeTab.delivery" class="border-t border-gray-200 pt-3 space-y-3 bg-gray-50/50 p-3 rounded-lg border border-gray-150 text-xs">
                        <div class="font-bold text-sm text-green-700 uppercase tracking-wide flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1-1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l2.414 2.414a1 1 0 01.293.707V15a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"></path></svg>
                            Thông tin giao hàng
                        </div>

                        <!-- Delivery mode selection tabs: Self vs Partner -->
                        <div class="flex rounded-md shadow-sm text-xs font-semibold bg-white p-0.5 border border-gray-200">
                            <button 
                                type="button"
                                @click="activeTab.delivery.delivery_mode = 'self'"
                                :class="activeTab.delivery.delivery_mode === 'self' ? 'bg-green-600 text-white' : 'text-gray-700 hover:bg-gray-50'"
                                class="flex-1 py-1 px-3 rounded transition-colors text-center font-bold"
                            >
                                Tự giao hàng
                            </button>
                            <button 
                                type="button"
                                @click="activeTab.delivery.delivery_mode = 'partner'"
                                :class="activeTab.delivery.delivery_mode === 'partner' ? 'bg-green-600 text-white' : 'text-gray-700 hover:bg-gray-50'"
                                class="flex-1 py-1 px-3 rounded transition-colors text-center font-bold"
                            >
                                Đối tác giao hàng
                            </button>
                        </div>

                        <!-- Delivery fields -->
                        <div class="space-y-2 text-xs">
                            <div v-if="activeTab.delivery.delivery_mode === 'partner'">
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5">Đối tác giao hàng</label>
                                <select v-model="activeTab.delivery.delivery_partner" class="w-full border border-gray-300 rounded px-2 py-1 text-xs outline-none focus:border-green-500 bg-white">
                                    <option value="">-- Chọn đối tác --</option>
                                    <option value="Grab">Grab</option>
                                    <option value="Ahamove">Ahamove</option>
                                    <option value="GHN">Giao Hàng Nhanh (GHN)</option>
                                    <option value="GHTK">Giao Hàng Tiết Kiệm (GHTK)</option>
                                    <option value="Viettel Post">Viettel Post</option>
                                    <option value="Giao hàng ngoài">Giao hàng ngoài</option>
                                    <option value="Khác">Khác</option>
                                </select>
                            </div>

                            <div v-if="activeTab.delivery.delivery_mode === 'partner'" class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-[10px] font-medium text-gray-500 mb-0.5">Mã vận đơn</label>
                                    <input type="text" v-model="activeTab.delivery.tracking_code" placeholder="Mã bưu tá..." class="w-full border border-gray-300 rounded px-2 py-1 text-xs outline-none focus:border-green-500" />
                                </div>
                                <div>
                                    <label class="block text-[10px] font-medium text-gray-500 mb-0.5">Cân nặng (kg)</label>
                                    <input type="number" v-model="activeTab.delivery.weight" step="0.1" min="0" placeholder="0" class="w-full border border-gray-300 rounded px-2 py-1 text-xs outline-none focus:border-green-500" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-[10px] font-medium text-gray-500 mb-0.5">Phí giao hàng</label>
                                    <MoneyInput v-model="activeTab.delivery.delivery_fee" :min="0" input-class="w-full border border-gray-300 rounded px-2 py-1 text-xs outline-none focus:border-green-500" />
                                </div>
                                <div>
                                    <label class="block text-[10px] font-medium text-gray-500 mb-0.5">Thu hộ tiền (COD)</label>
                                    <MoneyInput v-model="activeTab.delivery.cod_amount" :min="0" input-class="w-full border border-gray-300 rounded px-2 py-1 text-xs outline-none focus:border-green-500" />
                                </div>
                            </div>

                            <div class="border-t border-gray-200/60 my-1 pt-1.5 space-y-1.5">
                                <div class="font-bold text-[10px] text-gray-500 uppercase tracking-wider">Thông tin người nhận</div>
                                
                                <div class="grid grid-cols-2 gap-2">
                                    <input type="text" v-model="activeTab.delivery.receiver_name" placeholder="Tên người nhận..." class="w-full border border-gray-300 rounded px-2 py-1 text-xs outline-none focus:border-green-500" />
                                    <input type="text" v-model="activeTab.delivery.receiver_phone" placeholder="Số điện thoại..." class="w-full border border-gray-300 rounded px-2 py-1 text-xs outline-none focus:border-green-500" />
                                </div>

                                <input type="text" v-model="activeTab.delivery.receiver_address" placeholder="Địa chỉ chi tiết..." class="w-full border border-gray-300 rounded px-2 py-1 text-xs outline-none focus:border-green-500" />

                                <div class="grid grid-cols-3 gap-1">
                                    <input type="text" v-model="activeTab.delivery.receiver_ward" placeholder="Phường/Xã..." class="w-full border border-gray-300 rounded px-1.5 py-1 text-[11px] outline-none focus:border-green-500" />
                                    <input type="text" v-model="activeTab.delivery.receiver_district" placeholder="Quận/Huyện..." class="w-full border border-gray-300 rounded px-1.5 py-1 text-[11px] outline-none focus:border-green-500" />
                                    <input type="text" v-model="activeTab.delivery.receiver_city" placeholder="Tỉnh/Thành..." class="w-full border border-gray-300 rounded px-1.5 py-1 text-[11px] outline-none focus:border-green-500" />
                                </div>
                            </div>

                            <div>
                                <label class="block text-[10px] font-medium text-gray-500 mb-0.5">Ghi chú giao hàng</label>
                                <textarea v-model="activeTab.delivery.delivery_note" rows="2" placeholder="Ghi chú cho shipper..." class="w-full border border-gray-300 rounded px-2 py-1 outline-none focus:border-green-500 resize-none text-xs"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- 24.6C: Invoice/order note -->
                    <div class="border-t border-gray-200 pt-3 mt-3">
                        <textarea
                            v-model="orderNote"
                            rows="2"
                            class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500 resize-none placeholder-gray-400"
                            placeholder="Ghi chú đơn hàng..."
                            maxlength="1000"
                        ></textarea>
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

        <!-- ════════════════════════════════════════════════════════════
             STEP 24.6 — RETURN TAB WORKSPACE (KiotViet-style)
             Active when activeTab.type === 'return'. F3 focuses the
             return search; F7 focuses the (placeholder) exchange search.
             24.6B (atomic exchange) deferred — exchange UI is read-only.
        ════════════════════════════════════════════════════════════ -->
        <main v-else class="flex-1 flex overflow-hidden">
            <!-- Left: return / exchange tables -->
            <div class="flex-1 flex flex-col bg-white overflow-hidden border-r border-gray-300">
                <!-- F3 search hàng trả -->
                <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                    <label class="block text-xs font-semibold text-gray-500 mb-1">Tìm hàng trả (F3)</label>
                    <input
                        :id="`return-search-input-${activeTab.id}`"
                        v-model="activeTab.returnState.search"
                        @input="onReturnSearchInput(activeTab)"
                        type="text"
                        class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500"
                        placeholder="Mã hóa đơn / khách / SĐT / mã hàng / serial — Enter để tìm"
                        autocomplete="off"
                    />
                    <div v-if="activeTab.returnState.error" class="mt-2 px-3 py-2 bg-red-50 border border-red-200 rounded text-xs text-red-700">
                        {{ activeTab.returnState.error }}
                    </div>
                    <div v-if="activeTab.returnState.searching" class="mt-2 text-xs text-gray-500">Đang tìm...</div>
                    <ul
                        v-if="activeTab.returnState.searchResults.length && !activeTab.returnState.sourceInvoice"
                        class="mt-2 max-h-56 overflow-y-auto divide-y divide-gray-100 border border-gray-200 rounded bg-white"
                    >
                        <li
                            v-for="inv in activeTab.returnState.searchResults"
                            :key="inv.id"
                            @click="selectReturnInvoice(activeTab, inv)"
                            class="px-3 py-2 cursor-pointer hover:bg-blue-50 flex items-center justify-between text-sm"
                        >
                            <div>
                                <div class="font-semibold text-gray-800">{{ inv.code }}</div>
                                <div class="text-xs text-gray-500">{{ inv.customer_name || 'Khách lẻ' }}<span v-if="inv.customer_phone"> — {{ inv.customer_phone }}</span></div>
                            </div>
                            <div class="text-right">
                                <div class="font-medium text-gray-700 tabular-nums">{{ formatCurrency(inv.total || 0) }}</div>
                                <div class="text-xs text-gray-400">{{ inv.status }}</div>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Hàng trả -->
                <div class="flex-1 overflow-y-auto">
                    <div v-if="!activeTab.returnState.sourceInvoice" class="p-6 text-center text-sm text-gray-400">
                        Tìm và chọn hóa đơn ở ô trên để bắt đầu trả hàng.
                    </div>
                    <div v-else class="px-4 py-3">
                        <div class="flex items-center justify-between mb-2 text-sm">
                            <div>
                                <span class="font-semibold text-gray-800">Trả hàng / {{ activeTab.returnState.sourceInvoice.code }}</span>
                                <span v-if="activeTab.returnState.sourceInvoice.customer_name" class="text-gray-500"> — {{ activeTab.returnState.sourceInvoice.customer_name }}</span>
                            </div>
                            <button @click="backToInvoiceSearch(activeTab)" class="text-xs text-blue-600 hover:underline">← Đổi hóa đơn</button>
                        </div>
                        <div v-if="activeTab.returnState.loadingItems" class="text-xs text-gray-500">Đang tải...</div>
                        <div v-else class="border border-gray-200 rounded overflow-hidden">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 text-gray-600 text-xs uppercase">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Sản phẩm</th>
                                        <th class="px-3 py-2 text-right">Đã bán</th>
                                        <th class="px-3 py-2 text-right">Đã trả</th>
                                        <th class="px-3 py-2 text-right">Còn được trả</th>
                                        <th class="px-3 py-2 text-right">Đơn giá</th>
                                        <th class="px-3 py-2 text-center w-32">Trả</th>
                                        <th class="px-3 py-2 text-right">Thành tiền</th>
                                        <th class="px-3 py-2 text-center w-10"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-if="!visibleReturnItems(activeTab).length">
                                        <td colspan="8" class="px-3 py-8 text-center text-sm text-gray-400">
                                            Không còn dòng hàng nào trong phiếu trả.
                                        </td>
                                    </tr>
                                    <template v-for="item in visibleReturnItems(activeTab)" :key="item.invoice_item_id">
                                        <tr>
                                            <td class="px-3 py-2">
                                                <div class="font-medium text-gray-800">{{ item.product_name }}</div>
                                                <div class="text-xs text-gray-400">{{ item.product_code }}<span v-if="item.has_serial"> · Có serial</span></div>
                                            </td>
                                            <td class="px-3 py-2 text-right tabular-nums">{{ item.sold_qty }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums text-gray-500">{{ item.already_returned_qty }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums font-medium" :class="item.remaining_qty > 0 ? 'text-blue-600' : 'text-gray-400'">{{ item.remaining_qty }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums">{{ formatCurrency(item.price || 0) }}</td>
                                            <td class="px-3 py-2 text-center">
                                                <input
                                                    v-if="!item.has_serial"
                                                    type="number"
                                                    :min="0"
                                                    :max="item.remaining_qty"
                                                    :disabled="item.remaining_qty <= 0"
                                                    :value="returnLineState(activeTab, item).qty || 0"
                                                    @input="setReturnQty(activeTab, item, $event.target.value)"
                                                    class="w-20 border border-gray-300 rounded px-2 py-1 text-sm text-right tabular-nums focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100"
                                                />
                                                <span v-else class="text-xs text-gray-500 tabular-nums">{{ returnLineState(activeTab, item).serial_ids?.length || 0 }} / {{ item.remaining_qty }}</span>
                                            </td>
                                            <td class="px-3 py-2 text-right tabular-nums font-semibold text-gray-800">{{ formatCurrency(returnLineAmount(item, activeTab)) }}</td>
                                            <td class="px-3 py-2 text-center">
                                                <button
                                                    type="button"
                                                    class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-lg leading-none text-gray-400 hover:border-red-300 hover:bg-red-50 hover:text-red-600"
                                                    title="Ẩn dòng khỏi phiếu trả"
                                                    @click="hideReturnLine(activeTab, item)"
                                                >
                                                    &times;
                                                </button>
                                            </td>
                                        </tr>
                                        <tr v-if="item.has_serial && item.serials.length" class="bg-gray-50/40">
                                            <td colspan="8" class="px-3 py-2">
                                                <div class="text-xs font-medium text-gray-600 mb-1">Chọn serial cần trả</div>
                                                <div class="flex flex-wrap gap-1.5">
                                                    <label
                                                        v-for="s in item.serials"
                                                        :key="s.id"
                                                        :class="[
                                                            'inline-flex items-center gap-1 px-2 py-1 rounded border text-xs cursor-pointer',
                                                            s.already_returned ? 'border-gray-200 bg-gray-100 text-gray-400 line-through cursor-not-allowed' :
                                                            (returnLineState(activeTab, item).serial_ids?.includes(s.id) ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-gray-300 bg-white text-gray-700 hover:border-blue-400')
                                                        ]"
                                                        @click.prevent="!s.already_returned && toggleReturnSerial(activeTab, item, s.id)"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            class="hidden"
                                                            :disabled="s.already_returned"
                                                            :checked="returnLineState(activeTab, item).serial_ids?.includes(s.id)"
                                                        />
                                                        {{ s.serial_number }}
                                                    </label>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="px-4 py-3 border-t border-gray-200 bg-amber-50">
                    <label class="block text-xs font-semibold text-amber-800 mb-1">Tìm hàng đổi (F7)</label>
                    <div>
                        <input
                            :id="`return-exchange-input-${activeTab.id}`"
                            v-model="activeTab.returnState.exchangeSearch"
                            @input="onExchangeSearchInput(activeTab)"
                            @focus="openExchangeSearch(activeTab)"
                            @click="openExchangeSearch(activeTab)"
                            type="text"
                            class="w-full border border-amber-200 rounded px-3 py-2 text-sm bg-white text-gray-800 focus:outline-none focus:ring-1 focus:ring-amber-500"
                            placeholder="Nhập tên / SKU / barcode / Serial/IMEI hàng đổi"
                        />
                        <div
                            v-if="activeTab.returnState.exchangeSearchOpen"
                            class="fixed left-1/2 top-[44%] z-[80] w-[min(820px,calc(100vw-32px))] -translate-x-1/2 -translate-y-1/2"
                        >
                            <div
                                class="overflow-hidden rounded-md border border-amber-200 bg-white shadow-2xl"
                            >
                                <div class="border-b border-amber-100 bg-amber-50 px-3 py-2">
                                    <div class="mb-1 flex items-center justify-between gap-3">
                                        <label :for="`return-exchange-overlay-input-${activeTab.id}`" class="text-xs font-semibold text-amber-800">Tìm hàng đổi (F7)</label>
                                        <button type="button" class="text-xl leading-none text-gray-400 hover:text-gray-700" @click="closeExchangeSearch(activeTab)" title="Đóng">&times;</button>
                                    </div>
                                    <input
                                        :id="`return-exchange-overlay-input-${activeTab.id}`"
                                        v-model="activeTab.returnState.exchangeSearch"
                                        @input="onExchangeSearchInput(activeTab)"
                                        @keydown.esc.prevent="closeExchangeSearch(activeTab)"
                                        type="text"
                                        class="w-full rounded border border-amber-300 bg-white px-3 py-2 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-amber-500"
                                        placeholder="Nhập tên / SKU / barcode / Serial/IMEI hàng đổi"
                                    />
                                    <div class="mt-1 text-[11px] text-gray-500">Gõ Serial/IMEI, SKU, barcode hoặc tên hàng rồi bấm chọn kết quả.</div>
                                </div>
                                <div class="max-h-[46vh] overflow-y-auto">
                                    <div v-if="activeTab.returnState.exchangeSearching" class="px-3 py-6 text-center text-sm text-gray-500">
                                        Đang tìm...
                                    </div>
                                    <div v-else-if="!activeTab.returnState.exchangeSearch" class="px-3 py-6 text-center text-sm text-gray-500">
                                        Nhập từ khóa để tìm hàng đổi.
                                    </div>
                                    <div v-else-if="!activeTab.returnState.exchangeResults.length" class="px-3 py-6 text-center text-sm text-gray-500">
                                        Không có kết quả phù hợp.
                                    </div>
                                    <div
                                        v-else
                                        v-for="product in activeTab.returnState.exchangeResults"
                                        :key="`exchange-${product.id}`"
                                        @mousedown.prevent="addExchangeItem(activeTab, product)"
                                        class="flex cursor-pointer items-start justify-between gap-3 border-b border-gray-100 px-3 py-2.5 last:border-0 hover:bg-amber-50"
                                        :class="exchangeAvailableQty(product) <= 0 ? 'opacity-40 pointer-events-none' : ''"
                                    >
                                        <div class="min-w-0 flex-1">
                                            <div class="truncate text-sm font-semibold text-gray-800">{{ product.name }}</div>
                                            <div class="text-xs text-gray-500">
                                                {{ product.sku }} · Tồn: {{ product.stock_quantity }} · Đặt: 0<span v-if="product.has_serial"> · Sẵn: {{ product.sellable_quantity }}</span>
                                                <span v-if="exchangeAvailableQty(product) <= 0" class="ml-1 font-semibold text-red-600">Hết tồn</span>
                                            </div>
                                            <div v-if="product.matched_serials && product.matched_serials.length" class="mt-1 flex flex-wrap gap-1">
                                                <span
                                                    v-for="s in product.matched_serials"
                                                    :key="`matched-${product.id}-${s.id}`"
                                                    class="inline-flex items-center rounded border border-blue-200 bg-blue-50 px-1.5 py-0.5 font-mono text-[11px] text-blue-700"
                                                >
                                                    {{ s.serial_number }}
                                                </span>
                                            </div>
                                        </div>
                                        <div class="text-sm font-bold tabular-nums text-amber-700">{{ formatCurrency(product.retail_price || 0) }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-if="activeTab.returnState.exchangeItems.length" class="mt-3 border border-amber-200 bg-white rounded divide-y divide-gray-100">
                        <div class="grid grid-cols-12 gap-2 bg-amber-50 px-2 py-1.5 text-[11px] font-semibold uppercase text-amber-800">
                            <div class="col-span-3">Hàng đổi</div>
                            <div class="col-span-2 text-center">SL</div>
                            <div class="col-span-2 text-right">Đơn giá</div>
                            <div class="col-span-2 text-right">Giảm giá</div>
                            <div class="col-span-2 text-right">Thành tiền</div>
                        </div>
                        <div
                            v-for="(item, idx) in activeTab.returnState.exchangeItems"
                            :key="`exchange-line-${idx}`"
                            class="p-2"
                        >
                            <div class="grid grid-cols-12 gap-2 items-center">
                            <div class="col-span-3 min-w-0">
                                    <div class="font-semibold text-sm text-gray-800 truncate">{{ item.product.name }}</div>
                                    <div class="text-xs text-gray-500">
                                        {{ item.product.sku }} · Tồn: {{ item.product.stock_quantity ?? 0 }} · Đặt: 0<span v-if="item.is_serial_product"> · Sẵn: {{ item.product.sellable_quantity ?? exchangeAvailableQty(item) }} · Serial/IMEI</span>
                                    </div>
                                </div>
                                <div class="col-span-2 flex items-center justify-center">
                                    <template v-if="item.is_serial_product">
                                        <span class="text-xs font-bold px-2 py-1 rounded bg-gray-100" :class="item.quantity === 0 ? 'text-red-600 bg-red-50 border border-red-200' : 'text-gray-700'">{{ item.quantity }}</span>
                                    </template>
                                    <template v-else>
                                        <button @click="updateExchangeQty(item, -1)" class="w-7 h-7 border rounded bg-white hover:bg-gray-50">-</button>
                                        <input
                                            :value="item.quantity"
                                            @input="setExchangeQty(item, $event.target.value)"
                                            type="number"
                                            min="1"
                                            :max="exchangeAvailableQty(item)"
                                            class="mx-1 w-14 rounded border border-gray-300 px-1 py-1 text-center text-sm font-bold tabular-nums focus:outline-none focus:ring-1 focus:ring-amber-500"
                                        />
                                        <button
                                            @click="updateExchangeQty(item, 1)"
                                            :disabled="(Number(item.quantity) || 0) >= exchangeAvailableQty(item)"
                                            class="w-7 h-7 border rounded bg-white hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
                                        >+</button>
                                    </template>
                                </div>
                                <div class="col-span-2">
                                    <MoneyInput
                                        v-model="item.price"
                                        :min="0"
                                        @update:model-value="() => normalizeExchangeLineDiscount(item)"
                                        input-class="w-full border border-gray-300 rounded px-2 py-1 text-sm text-right"
                                    />
                                </div>
                                <div class="col-span-2">
                                    <MoneyInput
                                        v-model="item.discount"
                                        :min="0"
                                        @update:model-value="() => normalizeExchangeLineDiscount(item)"
                                        input-class="w-full border border-gray-300 rounded px-2 py-1 text-sm text-right"
                                    />
                                </div>
                                <div class="col-span-2 text-right text-xs font-bold text-gray-800">{{ formatCurrency(exchangeLineAmount(item)) }}</div>
                                <button @click="removeExchangeItem(activeTab, idx)" class="col-span-1 text-gray-400 hover:text-red-600 px-1 text-right" title="Xóa">&times;</button>
                            </div>
                            <div v-if="item.stockWarning" class="mt-1 text-right text-[11px] font-medium text-red-600">{{ item.stockWarning }}</div>
                            <div v-if="item.is_serial_product" class="mt-2">
                                <div class="flex flex-wrap gap-1 mb-1">
                                    <span v-for="(s, sIdx) in item.serials" :key="s.id" class="inline-flex items-center gap-1 bg-blue-50 text-blue-700 text-[11px] px-1.5 py-0.5 rounded border border-blue-200 font-mono">
                                        {{ s.serial_number }}
                                        <button @click="removeSerialFromItem(item, sIdx)" class="text-blue-500 hover:text-red-600">&times;</button>
                                    </span>
                                </div>
                                <div class="relative">
                                    <input
                                        v-model="item.serialInput"
                                        @input="searchSerialsForItem(item)"
                                        @focus="openExchangeSerialSearch(item)"
                                        @click="openExchangeSerialSearch(item)"
                                        @keydown.enter.prevent="addSerialToItem(item)"
                                        type="text"
                                        class="w-full border border-gray-300 rounded px-2 py-1 text-xs"
                                        placeholder="Nhập/Quét serial hàng đổi"
                                    />
                                    <div v-if="item.showSerialDropdown" class="fixed left-1/2 top-[44%] z-[85] w-[min(620px,calc(100vw-32px))] -translate-x-1/2 -translate-y-1/2 overflow-hidden rounded-md border border-blue-200 bg-white shadow-2xl">
                                        <div class="border-b border-blue-100 bg-blue-50 px-3 py-2">
                                            <div class="mb-1 flex items-center justify-between gap-3">
                                                <label :for="`exchange-serial-overlay-input-${item.product.id}`" class="text-xs font-semibold text-blue-800">Chọn Serial/IMEI hàng đổi</label>
                                                <button type="button" class="text-xl leading-none text-gray-400 hover:text-gray-700" @mousedown.prevent="hideSerialDropdown(item)" title="Đóng">&times;</button>
                                            </div>
                                            <input
                                                :id="`exchange-serial-overlay-input-${item.product.id}`"
                                                v-model="item.serialInput"
                                                @input="searchSerialsForItem(item)"
                                                @keydown.enter.prevent="addSerialToItem(item)"
                                                @keydown.esc.prevent="hideSerialDropdown(item)"
                                                type="text"
                                                class="w-full rounded border border-blue-300 bg-white px-3 py-2 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                                placeholder="Nhập/Quét serial hàng đổi"
                                            />
                                        </div>
                                        <div class="max-h-[40vh] overflow-y-auto">
                                            <div v-if="item.serialLoading" class="p-3 text-center text-xs text-gray-400">Đang tải mã...</div>
                                            <div v-else-if="!item.availableSerials || item.availableSerials.length === 0" class="p-3 text-center text-xs text-gray-400">Không còn mã nào</div>
                                            <div
                                                v-for="s in item.availableSerials"
                                                :key="s.id"
                                                @mousedown.prevent="selectSerialForItem(item, s)"
                                                class="cursor-pointer border-b border-gray-100 p-2 font-mono text-xs font-medium text-gray-700 last:border-0 hover:bg-blue-50"
                                            >
                                                {{ s.serial_number }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-if="item.quantity === 0" class="mt-1 text-[10px] text-red-600">Cần chọn đủ serial hàng đổi.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: return summary panel -->
            <div class="w-[360px] flex flex-col bg-gray-50 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 bg-white">
                    <div class="text-xs text-gray-500">Người bán</div>
                    <div class="font-medium text-sm text-gray-800">
                        {{ sellerOptions.find(s => s.key === selectedSellerKey)?.name || '— Chưa chọn —' }}
                    </div>
                </div>
                <div class="px-4 py-3 border-b border-gray-200 bg-white">
                    <div class="text-xs text-gray-500">Khách hàng</div>
                    <div class="font-medium text-sm text-gray-800">
                        {{ activeTab.returnState.sourceInvoice?.customer_name || '— Chưa chọn hóa đơn —' }}
                    </div>
                    <div v-if="activeTab.returnState.sourceInvoice?.customer_phone" class="text-xs text-gray-500">
                        {{ activeTab.returnState.sourceInvoice.customer_phone }}
                    </div>
                </div>
                <div v-if="activeTab.returnState.sourceInvoice" class="px-4 py-3 border-b border-gray-200 bg-white">
                    <div class="text-xs text-gray-500">Trả hàng</div>
                    <div class="font-semibold text-sm text-gray-800">{{ activeTab.returnState.sourceInvoice.code }}</div>
                    <div class="text-xs text-gray-500 mt-1">Tổng giá gốc hàng mua</div>
                    <div class="font-medium text-sm tabular-nums">{{ formatCurrency(activeTab.returnState.sourceInvoice.total || 0) }}</div>
                </div>

                <div class="flex-1 overflow-y-auto px-4 py-3 space-y-2 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-500">Tổng tiền hàng trả</span>
                        <span class="font-medium tabular-nums">{{ formatCurrency(activeReturnSubtotal || 0) }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-gray-500">Giảm giá</span>
                        <MoneyInput v-model="activeTab.returnState.discount" :min="0" input-class="w-44 border border-gray-300 rounded-md px-2 py-1 text-sm text-right tabular-nums focus:outline-none focus:ring-1 focus:ring-blue-500" />
                    </div>
                    <!-- Step 24.6E (UI polish): fee VND/% as one combined input group -->
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-gray-500">Phí trả hàng</span>
                        <div class="w-44">
                            <!-- combined input group: segmented toggle + input + suffix -->
                            <div
                                class="flex items-stretch border border-gray-300 rounded-md overflow-hidden bg-white focus-within:ring-1 focus-within:ring-blue-500 focus-within:border-blue-500 transition-colors"
                            >
                                <div class="flex items-stretch bg-gray-50 border-r border-gray-300 p-0.5 gap-0.5">
                                    <button
                                        type="button"
                                        @click="activeTab.returnState.feeType = 'amount'"
                                        :class="activeTab.returnState.feeType === 'amount'
                                            ? 'bg-blue-600 text-white shadow-sm'
                                            : 'text-gray-500 hover:text-gray-700'"
                                        class="px-2 text-[11px] font-semibold rounded transition-colors"
                                        title="Phí theo VND"
                                    >₫</button>
                                    <button
                                        type="button"
                                        @click="activeTab.returnState.feeType = 'percent'"
                                        :class="activeTab.returnState.feeType === 'percent'
                                            ? 'bg-blue-600 text-white shadow-sm'
                                            : 'text-gray-500 hover:text-gray-700'"
                                        class="px-2 text-[11px] font-semibold rounded transition-colors"
                                        title="Phí theo %"
                                    >%</button>
                                </div>
                                <MoneyInput
                                    v-if="activeTab.returnState.feeType === 'amount'"
                                    v-model="activeTab.returnState.feeValue"
                                    :min="0"
                                    input-class="flex-1 min-w-0 px-2 py-1 text-sm text-right tabular-nums border-0 outline-none focus:ring-0 bg-transparent"
                                />
                                <input
                                    v-else
                                    v-model.number="activeTab.returnState.feeValue"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    class="flex-1 min-w-0 px-2 py-1 text-sm text-right tabular-nums border-0 outline-none focus:ring-0 bg-transparent"
                                />
                            </div>
                            <div
                                v-if="activeTab.returnState.feeType === 'percent' && activeReturnFeeAmount > 0"
                                class="text-right text-[11px] text-gray-400 mt-0.5 tabular-nums"
                            >
                                = {{ formatCurrency(activeReturnFeeAmount) }}
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-between border-t pt-2 mt-2">
                        <span class="font-semibold text-gray-700">
                            {{ activeTab.returnState.exchangeItems.length ? 'Giá trị hàng trả' : 'Cần trả khách' }}
                        </span>
                        <span
                            class="font-bold text-base tabular-nums"
                            :class="activeTab.returnState.exchangeItems.length ? 'text-gray-900' : 'text-blue-600'"
                        >
                            {{ formatCurrency(activeReturnTotal || 0) }}
                        </span>
                    </div>
                    <div v-if="activeTab.returnState.exchangeItems.length" class="border-t pt-2 mt-2 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500">Tiền hàng đổi</span>
                            <span class="font-medium tabular-nums">{{ formatCurrency(activeExchangeSubtotal || 0) }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-gray-500">Giảm giá hàng đổi</span>
                            <MoneyInput v-model="activeTab.returnState.exchangeDiscount" :min="0" input-class="w-44 border border-gray-300 rounded-md px-2 py-1 text-sm text-right tabular-nums focus:outline-none focus:ring-1 focus:ring-blue-500" />
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-gray-700">Tổng tiền hàng đổi</span>
                            <span class="font-bold text-gray-900 tabular-nums">{{ formatCurrency(activeExchangeTotal || 0) }}</span>
                        </div>
                        <div
                            v-if="activeCustomerPays > 0"
                            class="rounded-lg border border-blue-300 bg-blue-50 px-3 py-3"
                        >
                            <div class="text-xs font-semibold uppercase text-blue-700">Chênh lệch sau đổi</div>
                            <div class="mt-1 flex items-center justify-between">
                                <span class="font-bold text-blue-800">Khách cần trả thêm</span>
                                <span class="text-xl font-extrabold tabular-nums text-blue-800">{{ formatCurrency(activeCustomerPays) }}</span>
                            </div>
                        </div>
                        <div
                            v-else-if="activeRefundToCustomer > 0"
                            class="rounded-lg border border-red-300 bg-red-50 px-3 py-3"
                        >
                            <div class="text-xs font-semibold uppercase text-red-700">Chênh lệch sau đổi</div>
                            <div class="mt-1 flex items-center justify-between">
                                <span class="font-bold text-red-800">Cần trả khách</span>
                                <span class="text-xl font-extrabold tabular-nums text-red-800">{{ formatCurrency(activeRefundToCustomer) }}</span>
                            </div>
                        </div>
                        <div
                            v-else
                            class="rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-3"
                        >
                            <div class="text-xs font-semibold uppercase text-emerald-700">Chênh lệch sau đổi</div>
                            <div class="mt-1 flex items-center justify-between">
                                <span class="font-bold text-emerald-800">Không phát sinh thu/trả</span>
                                <span class="text-xl font-extrabold tabular-nums text-emerald-800">0đ</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 text-xs">
                            <label class="flex items-center gap-1.5 cursor-pointer">
                                <input type="radio" v-model="activeTab.returnState.exchangePaymentMethod" value="cash" class="text-blue-600 focus:ring-blue-500 w-4 h-4" />
                                <span>Tiền mặt</span>
                            </label>
                            <label class="flex items-center gap-1.5 cursor-pointer">
                                <input type="radio" v-model="activeTab.returnState.exchangePaymentMethod" value="transfer" class="text-blue-600 focus:ring-blue-500 w-4 h-4" />
                                <span>Chuyển khoản</span>
                            </label>
                        </div>
                        <select
                            v-if="activeTab.returnState.exchangePaymentMethod === 'transfer'"
                            v-model="activeTab.returnState.exchangeBankAccountInfo"
                            class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm outline-none focus:border-blue-500 bg-white"
                        >
                            <option value="">-- Chọn tài khoản --</option>
                            <option v-for="ba in bankAccounts" :key="ba.id" :value="ba.bank_name + ' - ' + ba.account_number + ' - ' + ba.account_holder">
                                {{ ba.bank_name }} - {{ ba.account_number }} ({{ ba.account_holder }})
                            </option>
                        </select>
                    </div>
                    <div v-if="!activeTab.returnState.exchangeItems.length" class="flex items-center justify-between gap-2">
                        <span class="text-gray-500">Tiền trả khách (paid_to_customer)</span>
                        <MoneyInput
                            v-model="activeTab.returnState.paidToCustomer"
                            :min="0"
                            input-class="w-44 border border-gray-300 rounded-md px-2 py-1 text-sm text-right tabular-nums focus:outline-none focus:ring-1 focus:ring-blue-500"
                            @update:model-value="activeTab.returnState.paidToCustomerTouched = true"
                        />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Ghi chú</label>
                        <textarea v-model="activeTab.returnState.note" rows="2" class="w-full border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500" placeholder="Ghi chú trả hàng"></textarea>
                    </div>
                    <div v-if="activeTab.returnState.error" class="px-3 py-2 bg-red-50 border border-red-200 rounded text-xs text-red-700">
                        <div v-if="activeTab.returnState.errorTitle" class="mb-0.5 font-semibold">{{ activeTab.returnState.errorTitle }}</div>
                        <div>{{ activeTab.returnState.error }}</div>
                    </div>
                    <div v-else-if="activeExchangeValidationMessage" class="px-3 py-2 bg-red-50 border border-red-200 rounded text-xs text-red-700">
                        {{ activeExchangeValidationMessage }}
                    </div>
                </div>

                <div class="px-4 py-3 border-t border-gray-200 bg-white">
                    <button
                        @click="submitReturnTab(activeTab)"
                        :disabled="!canSubmitActiveExchange || activeTab.returnState.submitting"
                        class="w-full px-4 py-3 bg-red-600 text-white rounded text-sm font-bold hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed uppercase tracking-wide"
                    >
                        {{ activeTab.returnState.submitting ? 'Đang lưu...' : (activeTab.returnState.exchangeItems.length ? 'ĐỔI HÀNG' : 'TRẢ HÀNG') }}
                    </button>
                    <p class="mt-1 text-[10px] text-gray-400 text-center">F3: ô tìm hàng trả · F7: ô tìm hàng đổi</p>
                </div>
            </div>
        </main>

        <div
            v-if="activeTab?.type === 'return' && activeTab.returnState?.successResult"
            class="fixed inset-0 z-[120] flex items-center justify-center bg-black/40 px-4"
        >
            <div class="w-full max-w-lg rounded-lg bg-white shadow-2xl">
                <div class="border-b border-gray-200 px-5 py-4">
                    <div class="text-lg font-bold text-gray-900">Đổi hàng thành công</div>
                    <div class="mt-1 text-sm text-gray-500">
                        Phiếu trả và hóa đơn đổi đã được tạo trong cùng giao dịch.
                    </div>
                </div>
                <div class="space-y-3 px-5 py-4 text-sm">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded border border-gray-200 px-3 py-2">
                            <div class="text-xs text-gray-500">Mã phiếu trả</div>
                            <div class="mt-0.5 font-bold text-gray-900">{{ activeTab.returnState.successResult.return?.code || '—' }}</div>
                        </div>
                        <div class="rounded border border-gray-200 px-3 py-2">
                            <div class="text-xs text-gray-500">Mã hóa đơn đổi</div>
                            <div class="mt-0.5 font-bold text-gray-900">{{ activeTab.returnState.successResult.exchange_invoice?.code || '—' }}</div>
                        </div>
                    </div>
                    <div class="space-y-2 rounded border border-gray-200 px-3 py-3">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500">Giá trị hàng trả</span>
                            <span class="font-semibold tabular-nums">{{ formatCurrency(activeTab.returnState.successResult.settlement?.return_total || 0) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500">Tổng tiền hàng đổi</span>
                            <span class="font-semibold tabular-nums">{{ formatCurrency(activeTab.returnState.successResult.settlement?.exchange_total || 0) }}</span>
                        </div>
                        <div
                            v-if="(activeTab.returnState.successResult.settlement?.customer_pays || 0) > 0"
                            class="rounded border border-blue-300 bg-blue-50 px-3 py-2"
                        >
                            <div class="text-xs font-semibold uppercase text-blue-700">Chênh lệch sau đổi</div>
                            <div class="mt-1 flex items-center justify-between">
                                <span class="font-bold text-blue-800">Khách trả thêm</span>
                                <span class="text-lg font-extrabold tabular-nums text-blue-800">{{ formatCurrency(activeTab.returnState.successResult.settlement.customer_pays || 0) }}</span>
                            </div>
                        </div>
                        <div
                            v-else-if="(activeTab.returnState.successResult.settlement?.refund_to_customer || 0) > 0"
                            class="rounded border border-red-300 bg-red-50 px-3 py-2"
                        >
                            <div class="text-xs font-semibold uppercase text-red-700">Chênh lệch sau đổi</div>
                            <div class="mt-1 flex items-center justify-between">
                                <span class="font-bold text-red-800">Trả khách</span>
                                <span class="text-lg font-extrabold tabular-nums text-red-800">{{ formatCurrency(activeTab.returnState.successResult.settlement.refund_to_customer || 0) }}</span>
                            </div>
                        </div>
                        <div
                            v-else
                            class="rounded border border-emerald-300 bg-emerald-50 px-3 py-2"
                        >
                            <div class="text-xs font-semibold uppercase text-emerald-700">Chênh lệch sau đổi</div>
                            <div class="mt-1 flex items-center justify-between">
                                <span class="font-bold text-emerald-800">Không phát sinh thu/trả</span>
                                <span class="text-lg font-extrabold tabular-nums text-emerald-800">0đ</span>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <a
                            v-if="activeTab.returnState.successResult.return?.code"
                            :href="`/returns?search=${encodeURIComponent(activeTab.returnState.successResult.return.code)}`"
                            target="_blank"
                            class="rounded border border-gray-300 px-3 py-2 text-center font-semibold text-gray-700 hover:bg-gray-50"
                        >
                            Xem phiếu trả
                        </a>
                        <a
                            v-if="activeTab.returnState.successResult.exchange_invoice?.code"
                            :href="`/invoices?search=${encodeURIComponent(activeTab.returnState.successResult.exchange_invoice.code)}`"
                            target="_blank"
                            class="rounded border border-gray-300 px-3 py-2 text-center font-semibold text-gray-700 hover:bg-gray-50"
                        >
                            Xem hóa đơn đổi
                        </a>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 border-t border-gray-200 px-5 py-3">
                    <button
                        type="button"
                        class="rounded border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                        @click="activeTab.returnState.successResult = null"
                    >
                        Đóng
                    </button>
                    <button
                        type="button"
                        class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
                        @click="resetReturnTab(activeTab)"
                    >
                        Tạo phiếu mới
                    </button>
                </div>
            </div>
        </div>
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

    <!-- STEP 24.13 — Shared QuickCreateProductModal (replaces inline modal). -->
    <QuickCreateProductModal
        :show="showCreateProductModal"
        :initial-name="query"
        @close="showCreateProductModal = false"
        @created="(p) => { addToCart(p); query = ''; searchProducts(); }"
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
