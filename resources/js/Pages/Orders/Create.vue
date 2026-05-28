<script setup>
import { formatVND as formatCurrency, parseMoneyModelValue } from '@/utils/money';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import DateTimePicker from '@/Components/DateTimePicker.vue';
import QuickCreateCustomerModal from '@/Components/QuickCreateCustomerModal.vue';
import MoneyInput from '@/Components/MoneyInput.vue';

const props = defineProps({
    customers: Array,
    branches: Array,
    priceBooks: Array,
    invoice: Object,
    action: { type: String, default: 'edit' },
});

const moneyNumber = (value) => Number(parseMoneyModelValue(value) || 0);

const safeBool = (value) => {
    return value === true || value === 1 || value === '1' || value === 'true';
};

const currentTime = computed(() => {
    const now = new Date();
    return now.toLocaleString('vi-VN', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
});

const pageTitle = computed(() => {
    if (props.invoice) {
        if (props.action === 'edit') {
            return `Sửa hóa đơn ${props.invoice.code} - KiotViet Clone`;
        } else if (props.action === 'return') {
            return `Trả hàng ${props.invoice.code} - KiotViet Clone`;
        }
    }
    return 'Tạo đơn đặt hàng - KiotViet Clone';
});

// Format datetime-local input value
const formatDatetimeLocal = (date) => {
    const d = new Date(date);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

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
    editing_invoice_id: null, // ID of invoice being edited
    discount: 0,
    otherFees: 0,
    amountPaid: 0,
    note: '',
    orderDate: formatDatetimeLocal(new Date()),
    
    isDelivery: false,
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

// Step 22.2E: AJAX customer typeahead. Trước đây Orders/Create render tất cả props.customers
// (Customer::all) làm dropdown đứng yên, không filter. Giờ gọi /api/customers/search,
// debounce 250ms, hiển thị 4 trạng thái (loading / lỗi+retry / rỗng / list).
const customerResults = ref([]);
const customerLoading = ref(false);
const customerError = ref('');
let customerSearchTimer = null;

const customerDisplay = (c) => {
    if (!c) return '';
    return c.display_label || c.name || c.phone || c.code || '';
};

const selectCustomer = (c) => {
    if (!activeTab.value) return;
    activeTab.value.selectedCustomer = c;
    activeTab.value.searchCustomer = customerDisplay(c);
    if (!activeTab.value.receiverName) activeTab.value.receiverName = c.name || '';
    if (!activeTab.value.receiverPhone) activeTab.value.receiverPhone = c.phone || '';
    activeTab.value.showCustomerDropdown = false;
    customerResults.value = [];
    customerError.value = '';
};

// STEP 24.13-FIX — quick-create customer modal for the "+" next to the
// customer search box. Re-uses the full POS customer modal so the form
// matches the standalone /customers create page (was a no-op button).
const showNewCustomerModal = ref(false);
const newCustomerInitialName = ref('');
const openNewCustomerModal = () => {
    newCustomerInitialName.value = activeTab.value?.searchCustomer || '';
    showNewCustomerModal.value = true;
};
const onCustomerCreated = (customer) => {
    showNewCustomerModal.value = false;
    if (customer) selectCustomer(customer);
};

const fetchCustomers = async (query) => {
    customerLoading.value = true;
    customerError.value = '';
    try {
        const { data, headers } = await axios.get('/api/customers/search', {
            params: { search: query },
            timeout: 8000,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const ct = (headers && (headers['content-type'] || headers['Content-Type'])) || '';
        if (typeof data === 'string' || ct.includes('text/html')) {
            customerResults.value = [];
            customerError.value = 'Phiên đăng nhập có thể đã hết hạn. Vui lòng tải lại trang.';
        } else {
            customerResults.value = Array.isArray(data) ? data : [];
        }
    } catch (e) {
        customerResults.value = [];
        const status = e?.response?.status;
        if (e?.code === 'ECONNABORTED') customerError.value = 'Tìm kiếm bị quá thời gian. Thử lại.';
        else if (status === 401 || status === 419) customerError.value = 'Phiên đăng nhập đã hết hạn. Tải lại trang.';
        else if (status === 403) customerError.value = 'Bạn không có quyền tìm khách hàng.';
        else if (status === 404) customerError.value = 'Không tìm thấy API tìm khách hàng.';
        else customerError.value = 'Lỗi tìm khách hàng. Thử lại.';
    } finally {
        customerLoading.value = false;
    }
};

const retryCustomerSearch = () => {
    const q = (activeTab.value?.searchCustomer || '').trim();
    if (q) fetchCustomers(q);
};

watch(() => activeTab.value?.searchCustomer, (query) => {
    clearTimeout(customerSearchTimer);
    const q = (query || '').trim();
    // Nếu user đã chọn KH và text vẫn trùng nhãn hiển thị → không làm gì.
    const selected = activeTab.value?.selectedCustomer;
    if (selected && q === customerDisplay(selected)) {
        customerResults.value = [];
        customerError.value = '';
        return;
    }
    // Text khác KH đang chọn → coi như user đang chỉnh, bỏ KH cũ.
    if (selected && q !== customerDisplay(selected)) {
        activeTab.value.selectedCustomer = null;
    }
    if (q.length < 1) {
        customerResults.value = [];
        customerError.value = '';
        customerLoading.value = false;
        return;
    }
    customerSearchTimer = setTimeout(() => fetchCustomers(q), 250);
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
    const existing = activeTab.value.items.find(i => i.product_id === product.id);
    if (!existing) {
        const newItem = {
            product_id: product.id,
            sku: product.sku,
            name: product.name,
            qty: 1,
            price: product.selling_price || product.retail_price || product.cost_price || 0,
            discount: 0,
            stock_quantity: product.stock_quantity || 0,
            // Step 22.1C: serial selector
            has_serial: !!product.has_serial,
            serial_ids: [],
            available_serials: [],
            serialLoading: false,
            serialError: '',
        };
        activeTab.value.items.unshift(newItem);
        // Step 22.2B: load serial bằng REACTIVE proxy reference (activeTab.value.items[0]),
        // KHÔNG dùng `newItem` plain object — mutation trên plain object không trigger Vue reactivity
        // nên serialLoading=false sau request không update được UI ⇒ bị treo "Đang tải…".
        if (product.has_serial) {
            loadAvailableSerials(activeTab.value.items[0]);
        }
    } else {
        existing.qty++;
    }
    activeTab.value.searchQuery = '';
    activeTab.value.showSuggestions = false;
};

// Step 22.1C / 22.2B: lấy danh sách Serial/IMEI khả dụng cho sản phẩm has_serial.
// 22.2B: hardened — timeout, HTML detection, finally luôn tắt loading,
// item PHẢI là reactive proxy (activeTab.value.items[index]) để UI re-render.
const loadAvailableSerials = async (item) => {
    if (!item || !item.product_id) return;

    item.serialLoading = true;
    item.serialError = '';
    item.available_serials = [];

    try {
        const { data, headers } = await axios.get(`/api/products/${item.product_id}/serials`, {
            timeout: 8000,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        const ct = (headers && headers['content-type']) || '';
        if (typeof data === 'string' || ct.includes('text/html')) {
            item.serialError = 'Server trả HTML thay vì JSON (có thể bị redirect login). Vui lòng đăng nhập lại.';
            return;
        }

        const list = Array.isArray(data)
            ? data
            : (Array.isArray(data?.data) ? data.data : null);

        if (list === null) {
            item.serialError = 'Phản hồi không hợp lệ từ server.';
            return;
        }

        item.available_serials = list;
        // Empty không phải error — để template hiển thị empty state riêng.
    } catch (e) {
        const status = e?.response?.status;
        const ct = e?.response?.headers?.['content-type'] || '';
        const msg = e?.response?.data?.message;

        if (e?.code === 'ECONNABORTED') {
            item.serialError = 'Tải Serial/IMEI quá lâu, vui lòng thử lại.';
        } else if (status === 401 || status === 419) {
            item.serialError = 'Phiên đăng nhập hết hạn, vui lòng đăng nhập lại.';
        } else if (status === 403) {
            item.serialError = 'Không có quyền xem Serial/IMEI.';
        } else if (status === 404) {
            item.serialError = 'Endpoint Serial/IMEI không tồn tại (HTTP 404).';
        } else if (ct.includes('text/html')) {
            item.serialError = 'Server trả HTML thay vì JSON. Kiểm tra route/login.';
        } else {
            item.serialError = msg || `Không tải được Serial/IMEI${status ? ` (HTTP ${status})` : ''}.`;
        }
        // eslint-disable-next-line no-console
        console.debug('[OrderSerials] load failed', { product_id: item.product_id, status, error: e?.message });
    } finally {
        item.serialLoading = false;
    }
};

// Step 22.2B: retry handler binding cho UI.
const retryLoadSerials = (index) => {
    const raw = activeTab.value?.items?.[index];
    if (raw) loadAvailableSerials(raw);
};

// Step 22.1C: toggle 1 serial trong selection của item.
const toggleSerial = (item, serialId) => {
    const ids = Array.isArray(item.serial_ids) ? [...item.serial_ids] : [];
    const idx = ids.indexOf(serialId);
    const qty = parseInt(item.qty) || 0;
    if (idx >= 0) {
        ids.splice(idx, 1);
    } else {
        if (ids.length >= qty) {
            alert(`Đã chọn đủ ${qty} Serial/IMEI cho sản phẩm này. Vui lòng tăng số lượng trước khi chọn thêm.`);
            return;
        }
        ids.push(serialId);
    }
    item.serial_ids = ids;
};

const hideSuggestions = () => {
    window.setTimeout(() => { if(activeTab.value) activeTab.value.showSuggestions = false; }, 200);
};

const hideCustomerDropdown = () => {
    window.setTimeout(() => { if(activeTab.value) activeTab.value.showCustomerDropdown = false; }, 200);
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

// Step 22.2B: cắt bớt serial_ids khi qty giảm — chuyển từ computed (side-effect bẩn)
// sang watch sạch sẽ. Computed không được mutate state.
watch(
    () => activeTab.value?.items?.map(i => ({ id: i.product_id, qty: parseInt(i.qty) || 0, has_serial: !!i.has_serial })),
    () => {
        const items = activeTab.value?.items || [];
        items.forEach(item => {
            if (item.has_serial && Array.isArray(item.serial_ids)) {
                const qty = parseInt(item.qty) || 0;
                if (item.serial_ids.length > qty) {
                    item.serial_ids = item.serial_ids.slice(0, qty);
                }
            }
        });
    },
    { deep: true }
);

watch(
    () => activeTab.value?.isDelivery,
    (isDelivery) => {
        if (!isDelivery && activeTab.value) {
            activeTab.value.isCod = false;
        }
    }
);

const totalAmount = computed(() =>
    itemsComputed.value.reduce((sum, item) => sum + moneyNumber(item.subtotal), 0)
);

const totalPayment = computed(() =>
    Math.max(
        0,
        moneyNumber(totalAmount.value)
            - moneyNumber(activeTab.value.discount)
            + moneyNumber(activeTab.value.otherFees)
    )
);

const effectiveCod = computed(() =>
    !!activeTab.value?.isDelivery && !!activeTab.value?.isCod
);

const balance = computed(() =>
    moneyNumber(activeTab.value.amountPaid) - (effectiveCod.value ? 0 : moneyNumber(totalPayment.value))
);

// Step 22.2G: BẮT BUỘC chọn đủ Serial/IMEI cho hàng has_serial trước khi lưu.
// Trước đây tạo Order hàng serial mà bỏ qua tick serial vẫn luồn qua, để dồn lỗi
// xuống processOrder — sai contract. Frontend chặn + backend chặn + processOrder fail-safe.
const orderItemsSerialStatus = computed(() => {
    return (activeTab.value?.items || []).map((item) => {
        const qty = parseInt(item.qty) || 0;
        const selected = Array.isArray(item.serial_ids) ? item.serial_ids.length : 0;
        return {
            product_id: item.product_id,
            name: item.name,
            qty,
            selected,
            has_serial: !!item.has_serial,
            ok: !item.has_serial || selected === qty,
        };
    });
});

const orderHasSerialMissing = computed(() =>
    orderItemsSerialStatus.value.some((s) => s.has_serial && !s.ok)
);

function validateOrderSerialSelection() {
    const invalid = orderItemsSerialStatus.value.filter((s) => s.has_serial && !s.ok);
    if (invalid.length === 0) return true;
    const message = invalid
        .map((i) => `• ${i.name}: đã chọn ${i.selected}/${i.qty} Serial/IMEI`)
        .join('\n');
    alert('Vui lòng chọn đủ Serial/IMEI cho các sản phẩm sau trước khi lưu đơn:\n' + message);
    return false;
}

const save = async () => {
    if (activeTab.value.items.length === 0) {
        alert("Vui lòng chọn ít nhất 1 hàng hóa.");
        return;
    }
    if (!validateOrderSerialSelection()) return;
    submitRef.value = true;
    try {
        const isReturn = activeTab.value.status === 'return';
        const isEditing = !!activeTab.value.editing_invoice_id;
        const payload = {
            status: activeTab.value.status,
            customer_id: activeTab.value.selectedCustomer?.id || null,
            branch_id: activeTab.value.selectedBranchId || (props.branches?.[0]?.id || null),
            note: activeTab.value.note,
            total_price: moneyNumber(totalAmount.value),
            discount: moneyNumber(activeTab.value.discount),
            total_payment: moneyNumber(totalPayment.value),
            amount_paid: moneyNumber(activeTab.value.amountPaid),
            price_book_id: activeTab.value.selectedPriceBookId,
            price_book_name: activeTab.value.selectedPriceBookName,
            items: itemsComputed.value.map(item => ({
                ...item,
                price: moneyNumber(item.price),
                discount: moneyNumber(item.discount),
            })),
            invoice_id: activeTab.value.invoice_id,
            subtotal: moneyNumber(totalAmount.value),
            total: moneyNumber(totalPayment.value),
            paid_to_customer: moneyNumber(activeTab.value.amountPaid),
            other_fees: moneyNumber(activeTab.value.otherFees),
            is_delivery: activeTab.value.isDelivery,
            receiver_name: activeTab.value.receiverName,
            receiver_phone: activeTab.value.receiverPhone,
            receiver_address: activeTab.value.receiverAddress,
            receiver_ward: activeTab.value.receiverWard,
            receiver_district: activeTab.value.receiverDistrict,
            receiver_city: activeTab.value.receiverCity,
            weight: activeTab.value.weight,
            delivery_fee: moneyNumber(activeTab.value.deliveryFee),
            delivery_note: activeTab.value.deliveryNote,
            cod_amount: effectiveCod.value ? moneyNumber(totalPayment.value) : 0,
            length: activeTab.value.sizeL,
            width: activeTab.value.sizeW,
            height: activeTab.value.sizeH,
            order_date: activeTab.value.orderDate || null,
        };

        if (isEditing) {
            // Remap items fields for backend validation (qty → quantity)
            payload.items = payload.items.map(item => ({
                product_id: item.product_id,
                quantity: Number(item.qty || item.quantity || 0),
                price: moneyNumber(item.price),
                discount: moneyNumber(item.discount),
                serial_ids: item.serial_ids || [],
            }));
            payload.customer_paid = moneyNumber(payload.amount_paid);
            payload.payment_method = activeTab.value.paymentMethod || 'Tiền mặt';
            if (!payload.is_delivery) {
                payload.cod_amount = 0;
            }
            // Update existing invoice — use Inertia callbacks
            router.put(`/invoices/${activeTab.value.editing_invoice_id}`, payload, {
                onSuccess: () => {
                    submitRef.value = false;
                    // Inertia will redirect to invoices.index via backend
                },
                onError: (errors) => {
                    submitRef.value = false;
                    const firstError = Object.values(errors)[0];
                    if (firstError) alert(firstError);
                },
                onFinish: () => {
                    submitRef.value = false;
                },
            });
            return; // Don't reset tab — Inertia handles the redirect
        } else {
            const endpoint = isReturn ? '/returns' : '/orders';
            await router.post(endpoint, payload);
        }
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
    
    const isDeliveryInvoice = safeBool(invoice.is_delivery);
    const codAmount = moneyNumber(invoice.cod_amount);

    activeTab.value.name = isDeliveryInvoice
        ? `Sửa giao hàng ${invoice.code}`
        : `Sửa HĐ ${invoice.code}`;
    activeTab.value.isDelivery = isDeliveryInvoice;

    activeTab.value.status = 'draft';
    activeTab.value.editing_invoice_id = invoice.id;
    activeTab.value.invoice_id = invoice.id;
    activeTab.value.selectedPriceBookId = null;
    activeTab.value.selectedPriceBookName = invoice.price_book_name || 'Bảng giá chung';
    activeTab.value.discount = moneyNumber(invoice.discount);
    activeTab.value.amountPaid = moneyNumber(invoice.customer_paid);
    activeTab.value.note = invoice.note || '';
    activeTab.value.paymentMethod = invoice.payment_method || 'Tiền mặt';
    
    activeTab.value.deliveryFee = moneyNumber(invoice.delivery_fee);
    activeTab.value.otherFees = moneyNumber(invoice.other_fees);
    activeTab.value.receiverName = invoice.receiver_name || '';
    activeTab.value.receiverPhone = invoice.receiver_phone || '';
    activeTab.value.receiverAddress = invoice.receiver_address || '';
    activeTab.value.receiverWard = invoice.receiver_ward || '';
    activeTab.value.receiverDistrict = invoice.receiver_district || '';
    activeTab.value.receiverCity = invoice.receiver_city || '';
    activeTab.value.deliveryNote = invoice.delivery_note || '';
    activeTab.value.isCod = isDeliveryInvoice && codAmount > 0;
    activeTab.value.weight = invoice.weight || 500;
    activeTab.value.sizeL = invoice.length || 10;
    activeTab.value.sizeW = invoice.width || 10;
    activeTab.value.sizeH = invoice.height || 10;

    activeTab.value.items = (invoice.items || []).map(item => ({
        product_id: item.product_id,
        sku: item.product?.sku || '',
        name: item.product?.name || 'Sản phẩm',
        qty: item.quantity,
        price: moneyNumber(item.price),
        discount: moneyNumber(item.discount),
        stock_quantity: item.product?.stock_quantity || 0,
        subtotal: (Number(item.quantity || 0) * moneyNumber(item.price)) - moneyNumber(item.discount)
    }));
};

const saveAndPrint = async () => {
    if (activeTab.value.items.length === 0) {
        alert("Vui lòng chọn ít nhất 1 hàng hóa.");
        return;
    }
    if (!validateOrderSerialSelection()) return;
    submitRef.value = true;
    try {
        const endpoint = activeTab.value.status === 'return' ? '/returns' : '/orders';
        const payload = {
            status: activeTab.value.status,
            customer_id: activeTab.value.selectedCustomer?.id || null,
            branch_id: activeTab.value.selectedBranchId || (props.branches?.[0]?.id || null),
            note: activeTab.value.note,
            total_price: moneyNumber(totalAmount.value),
            discount: moneyNumber(activeTab.value.discount),
            total_payment: moneyNumber(totalPayment.value),
            amount_paid: moneyNumber(activeTab.value.amountPaid),
            price_book_id: activeTab.value.selectedPriceBookId,
            price_book_name: activeTab.value.selectedPriceBookName,
            items: itemsComputed.value.map(item => ({
                ...item,
                price: moneyNumber(item.price),
                discount: moneyNumber(item.discount),
            })),
            invoice_id: activeTab.value.invoice_id,
            subtotal: moneyNumber(totalAmount.value),
            total: moneyNumber(totalPayment.value),
            paid_to_customer: moneyNumber(activeTab.value.amountPaid),
            other_fees: moneyNumber(activeTab.value.otherFees),
            is_delivery: activeTab.value.isDelivery,
            receiver_name: activeTab.value.receiverName,
            receiver_phone: activeTab.value.receiverPhone,
            receiver_address: activeTab.value.receiverAddress,
            receiver_ward: activeTab.value.receiverWard,
            receiver_district: activeTab.value.receiverDistrict,
            receiver_city: activeTab.value.receiverCity,
            weight: activeTab.value.weight,
            delivery_fee: moneyNumber(activeTab.value.deliveryFee),
            delivery_note: activeTab.value.deliveryNote,
            cod_amount: effectiveCod.value ? moneyNumber(totalPayment.value) : 0,
            length: activeTab.value.sizeL,
            width: activeTab.value.sizeW,
            height: activeTab.value.sizeH,
            order_date: activeTab.value.orderDate || null,
            _print: true,
        };

        if (!payload.is_delivery) {
            payload.cod_amount = 0;
        }
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
    if (params.get('action') === 'return' && !props.invoice) {
        showReturnModal.value = true;
        fetchReturnInvoices();
    }
    if (props.invoice) {
        if (props.action === 'edit') {
            selectInvoiceForEdit(props.invoice);
        } else if (props.action === 'return') {
            selectInvoiceForReturn(props.invoice);
        }
    }
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
});

</script>

<template>
    <Head :title="pageTitle">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" />
    </Head>
    <div class="h-screen w-screen flex flex-col bg-[#eef1f5] text-[13px] overflow-hidden font-sans fixed inset-0 z-50">
        
        <!-- Flash Messages -->
        <div v-if="usePage().props.flash?.error" class="bg-red-500 text-white px-4 py-2 text-sm flex items-center gap-2 flex-shrink-0 z-[60]">
            <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
            {{ usePage().props.flash.error }}
        </div>
        <div v-if="usePage().props.flash?.success" class="bg-green-500 text-white px-4 py-2 text-sm flex items-center gap-2 flex-shrink-0 z-[60]">
            <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            {{ usePage().props.flash.success }}
        </div>
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
                        <i v-if="tab.isDelivery" class="fas fa-truck" :class="activeTabIndex === index ? 'text-gray-300' : 'text-blue-300'"></i>
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
                                    <div v-if="item.stock_quantity !== undefined" class="text-[11px] mt-0.5" :class="item.stock_quantity <= 0 ? 'text-red-500 font-bold' : item.stock_quantity < item.qty ? 'text-orange-500' : 'text-gray-400'">
                                        Tồn: {{ item.stock_quantity }}
                                        <span v-if="item.stock_quantity <= 0"> — Hết hàng!</span>
                                        <span v-else-if="item.stock_quantity < item.qty"> — Không đủ!</span>
                                    </div>
                                    <!-- Step 22.1C/22.2B: Serial/IMEI selector — BIND trực tiếp vào reactive proxy
                                         activeTab.items[index] thay vì computed copy `item` để UI nhận mutation. -->
                                    <div v-if="item.has_serial" class="mt-2 w-[260px] lg:w-[350px] xl:w-[450px]">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-[11px] font-semibold text-gray-700">
                                                Serial/IMEI
                                            </span>
                                            <span
                                                class="text-[11px] font-semibold"
                                                :class="(activeTab.items[index]?.serial_ids?.length || 0) === parseInt(item.qty || 0) ? 'text-green-600' : 'text-orange-600'"
                                            >Đã chọn {{ activeTab.items[index]?.serial_ids?.length || 0 }}/{{ item.qty }}</span>
                                        </div>
                                        <!-- Step 22.2G: cảnh báo nếu thiếu Serial/IMEI -->
                                        <div
                                            v-if="(activeTab.items[index]?.serial_ids?.length || 0) !== parseInt(item.qty || 0)"
                                            class="mb-1 text-[11px] text-orange-700 bg-orange-50 border border-orange-200 rounded px-1.5 py-0.5"
                                        >
                                            <i class="fas fa-exclamation-triangle mr-1"></i>Cần chọn đủ Serial/IMEI trước khi lưu đơn.
                                        </div>
                                        <div v-if="activeTab.items[index]?.serialLoading" class="text-[11px] text-gray-400">Đang tải Serial/IMEI…</div>
                                        <div v-else-if="activeTab.items[index]?.serialError" class="flex items-center gap-2">
                                            <span class="text-[11px] text-red-500">{{ activeTab.items[index].serialError }}</span>
                                            <button
                                                type="button"
                                                class="text-[10px] px-1.5 py-0.5 border border-blue-300 text-blue-600 rounded hover:bg-blue-50"
                                                @click="retryLoadSerials(index)"
                                            >Tải lại</button>
                                        </div>
                                        <div v-else-if="!activeTab.items[index]?.available_serials || activeTab.items[index].available_serials.length === 0" class="flex items-center gap-2">
                                            <span class="text-[11px] text-red-500">Không có Serial/IMEI khả dụng cho sản phẩm này.</span>
                                            <button
                                                type="button"
                                                class="text-[10px] px-1.5 py-0.5 border border-blue-300 text-blue-600 rounded hover:bg-blue-50"
                                                @click="retryLoadSerials(index)"
                                            >Tải lại</button>
                                        </div>
                                        <div v-else class="flex flex-wrap gap-1 max-h-24 overflow-auto border border-gray-200 rounded p-1 bg-gray-50">
                                            <label
                                                v-for="s in activeTab.items[index].available_serials"
                                                :key="s.id"
                                                class="flex items-center gap-1 text-[11px] bg-white border rounded px-1.5 py-0.5 cursor-pointer hover:border-blue-400"
                                                :class="(activeTab.items[index].serial_ids || []).includes(s.id) ? 'border-blue-500 bg-blue-50 text-blue-700 font-semibold' : 'border-gray-200 text-gray-700'"
                                                :title="s.is_legacy_status ? 'Dữ liệu cũ — status legacy/null' : ''"
                                            >
                                                <input
                                                    type="checkbox"
                                                    class="hidden"
                                                    :checked="(activeTab.items[index].serial_ids || []).includes(s.id)"
                                                    @change="toggleSerial(activeTab.items[index], s.id)"
                                                />
                                                {{ s.label || s.serial_number || s.imei || ('#' + s.id) }}
                                                <span v-if="s.is_legacy_status" class="text-[9px] text-amber-600 ml-0.5">(cũ)</span>
                                            </label>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-3">
                                    <div class="flex items-center justify-center gap-1 border border-transparent hover:border-blue-400 rounded overflow-hidden w-fit mx-auto transition-colors">
                                        <button class="px-2 py-1 text-gray-400 hover:text-gray-700 font-bold" @click="activeTab.items[index].qty > 1 ? activeTab.items[index].qty-- : null"><i class="fas fa-minus text-[10px]"></i></button>
                                        <input type="text" v-model="activeTab.items[index].qty" class="w-10 text-center outline-none text-[13px] border-b border-transparent focus:border-blue-500 py-0.5 text-blue-600 font-bold">
                                        <button class="px-2 py-1 text-gray-400 hover:text-gray-700 font-bold" @click="activeTab.items[index].qty++"><i class="fas fa-plus text-[10px]"></i></button>
                                    </div>
                                </td>
                                <td class="p-3 text-right font-medium text-gray-800">
                                    <MoneyInput v-model="activeTab.items[index].price" :min="0" input-class="w-24 border-b border-transparent hover:border-gray-300 focus:border-blue-500 text-right outline-none bg-transparent" />
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
                                <MoneyInput v-model="activeTab.discount" :min="0" input-class="w-full text-right outline-none bg-transparent text-gray-800" />
                            </div>
                        </div>
                        <div class="flex justify-between items-center mb-2">
                            <span>Thu khác</span>
                            <div class="border-b border-gray-300 hover:border-blue-500 w-24 transition-colors">
                                <MoneyInput v-model="activeTab.otherFees" :min="0" input-class="w-full text-right outline-none bg-transparent text-gray-800" />
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
                        <div 
                            class="cursor-pointer flex items-center gap-1.5 px-2 py-0.5 rounded"
                            :class="[!activeTab.isDelivery ? 'font-bold text-blue-600 bg-white border border-blue-200 shadow-sm' : 'text-gray-500 hover:text-blue-600']"
                            @click="activeTab.isDelivery = false"
                        >
                            <i class="fas fa-clock" :class="[!activeTab.isDelivery ? 'text-blue-500' : 'text-gray-400']"></i> Bán thường
                        </div>
                        <div 
                            class="cursor-pointer flex items-center gap-1.5 px-2 py-0.5 rounded"
                            :class="[activeTab.isDelivery ? 'font-bold text-blue-600 bg-white border border-blue-200 shadow-sm' : 'text-gray-500 hover:text-blue-600']"
                            @click="activeTab.isDelivery = true"
                        >
                            <i class="fas fa-truck" :class="[activeTab.isDelivery ? 'text-blue-500' : 'text-gray-400']"></i> Bán giao hàng
                        </div>
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
                    <div v-if="activeTab.selectedCustomer" class="flex justify-between items-center mb-2 cursor-pointer hover:bg-gray-50 -mx-1 px-1 rounded" @click="activeTab.selectedCustomer = null; activeTab.searchCustomer = ''">
                       <div class="font-bold text-gray-800 text-[14px] flex items-center gap-1.5">
                           {{ activeTab.selectedCustomer.name }} 
                           <i class="fas fa-walking text-gray-400"></i> 
                           <i class="fas fa-caret-down text-gray-400"></i>
                       </div>
                       <DateTimePicker
                            v-model="activeTab.orderDate"
                            naked
                            compact
                            placeholder="dd/MM/yyyy HH:mm"
                            input-class="text-[12px] text-gray-500 border border-gray-200 rounded px-1.5 py-0.5 outline-none focus:border-blue-500 cursor-pointer w-[150px]"
                            @click.stop
                       />
                    </div>
                    <div v-else class="flex justify-end mb-2">
                        <DateTimePicker
                            v-model="activeTab.orderDate"
                            naked
                            compact
                            placeholder="dd/MM/yyyy HH:mm"
                            input-class="text-[12px] text-gray-500 border border-gray-200 rounded px-1.5 py-0.5 outline-none focus:border-blue-500 cursor-pointer w-[150px]"
                        />
                    </div>
                    
                    <div class="flex gap-2 relative">
                        <!-- Search dropdown -->
                       <div class="relative flex-1">
                          <i class="fas fa-search absolute left-2 top-2 text-gray-400"></i>
                           <input v-model="activeTab.searchCustomer" @focus="activeTab.showCustomerDropdown = true" @blur="hideCustomerDropdown" placeholder="Tìm khách hàng (F4)" class="w-full border-b border-gray-300 outline-none focus:border-blue-500 py-1 pl-7 pr-6 text-[13px]" />
                          <button type="button" @click="openNewCustomerModal" title="Thêm khách hàng mới" class="absolute right-0 top-0.5 text-gray-400 hover:text-blue-600 font-bold px-1"><i class="fas fa-plus"></i></button>
                          
                          <!-- Dropdown Results (Step 22.2E: AJAX) -->
                          <div v-if="activeTab.showCustomerDropdown && (activeTab.searchCustomer || '').trim().length > 0" class="absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 shadow-xl rounded z-50 max-h-[260px] overflow-auto">
                              <div v-if="customerLoading" class="p-2 text-[12px] text-gray-500 flex items-center gap-2">
                                  <i class="fas fa-spinner fa-spin"></i> Đang tìm khách hàng…
                              </div>
                              <div v-else-if="customerError" class="p-2 text-[12px] text-red-600 flex items-center justify-between gap-2">
                                  <span>{{ customerError }}</span>
                                  <button type="button" @mousedown.prevent="retryCustomerSearch" class="text-blue-600 hover:underline">Thử lại</button>
                              </div>
                              <div v-else-if="customerResults.length === 0" class="p-2 text-[12px] text-gray-500">
                                  Không tìm thấy khách hàng phù hợp.
                              </div>
                              <div v-else>
                                  <div v-for="c in customerResults" :key="c.id" @mousedown.prevent="selectCustomer(c)" class="p-2 border-b border-gray-100 hover:bg-blue-50 cursor-pointer">
                                      <div class="font-bold text-gray-800 text-[13px]">{{ c.name }}<span v-if="c.code" class="text-gray-400 font-normal text-[11px]"> · {{ c.code }}</span></div>
                                      <div class="text-[12px] text-gray-500 flex justify-between gap-2">
                                          <span>{{ c.phone || c.email || '—' }}</span>
                                          <span v-if="c.debt_amount > 0" class="text-red-600">Nợ: {{ formatCurrency(c.debt_amount) }}</span>
                                      </div>
                                  </div>
                              </div>
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
                        <div v-show="activeTab.isDelivery">
                            <div class="flex gap-4 mb-3 mt-3">
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
                    </div>
                    
                    <div v-if="activeTab.isDelivery" class="p-4 flex-1">
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
                           <MoneyInput v-model="activeTab.amountPaid" :min="0" input-class="w-full text-right outline-none bg-transparent font-bold text-gray-800" />
                       </div>
                    </div>
                    <div v-if="activeTab.isDelivery" class="flex justify-between items-center mb-3">
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
                    
                    <!-- Only when NOT isDelivery, we show the Save Button here -->
                    <div v-if="!activeTab.isDelivery" class="mt-4 pt-3 border-t border-gray-100">
                        <button @click="save" :disabled="submitRef" class="w-full bg-[#0062c3] hover:bg-blue-700 text-white font-bold py-3 px-4 rounded transition-colors text-[16px] shadow-sm flex items-center justify-center gap-2">
                            <i v-if="submitRef" class="fas fa-circle-notch fa-spin"></i>
                            {{ activeTab.editing_invoice_id ? 'CẬP NHẬT HÓA ĐƠN' : activeTab.status === 'return' ? 'TRẢ HÀNG' : 'ĐẶT HÀNG' }}
                        </button>
                        <div @click="saveAndPrint" class="text-center font-bold text-gray-500 mt-2 text-[12px] cursor-pointer hover:text-blue-600"><i class="fas fa-print"></i> (F9)</div>
                    </div>
                </div>
            </div>

            <!-- Right Side: Delivery Service -->
            <div v-if="activeTab.isDelivery" class="w-[280px] bg-white flex flex-col flex-shrink-0 relative z-30 shadow-[-2px_0_5px_-2px_rgba(0,0,0,0.05)]">
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
                        {{ activeTab.editing_invoice_id ? 'CẬP NHẬT HÓA ĐƠN' : activeTab.status === 'return' ? 'TRẢ HÀNG' : 'ĐẶT HÀNG' }}
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

    <!-- STEP 24.13-FIX — Quick Create Customer Modal (full form, matches /customers create page). -->
    <QuickCreateCustomerModal
        :show="showNewCustomerModal"
        :initial-name="newCustomerInitialName"
        api-url="/customers"
        entity-label="khách hàng"
        @close="showNewCustomerModal = false"
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
