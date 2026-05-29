<script setup>
import { formatVND as formatCurrency } from '@/utils/money';
import { ref, watch, reactive, computed } from "vue";
import { Head, router, Link, useForm } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import SortableHeader from "@/Components/SortableHeader.vue";
import DateRangeFilter from "@/Components/Filters/DateRangeFilter.vue";
import DateTimePicker from "@/Components/DateTimePicker.vue";
import CustomerGroupCombobox from "@/Components/CustomerGroupCombobox.vue";
import MoneyInput from "@/Components/MoneyInput.vue";
import { useFilters } from "@/composables/useFilters.js";
import axios from "axios";

const props = defineProps({
    customers: Object,
    filters: Object,
    filterOptions: { type: Object, default: () => ({}) },
    summary: Object,
});

// HOTFIX 24.4A-2 — triple-safe defensive guard: default object + spread + strict === true.
const defaultCapabilities = {
    supportsBirthdayFilter: false,
    supportsLastTransactionFilter: false,
    supportsTotalSalesTimeFilter: false,
    supportsDebtDaysFilter: false,
    supportsPointsFilter: false,
    supportsDeliveryAreaFilter: false,
    supportsCreatedByFilter: false,
};
const safeFilterOptions = computed(() => props.filterOptions || {});
const filterCapabilities = computed(() => ({
    ...defaultCapabilities,
    ...(safeFilterOptions.value.capabilities || {}),
}));
const hasCapability = (key) => filterCapabilities.value[key] === true;
const filterCustomerGroups = computed(() => safeFilterOptions.value.customerGroups || []);
const filterTypes = computed(() => safeFilterOptions.value.types || []);
const filterGenders = computed(() => safeFilterOptions.value.genders || []);
const filterBranches = computed(() => safeFilterOptions.value.branches || []);
const filterCreators = computed(() => safeFilterOptions.value.creators || []);
const filterStatuses = computed(() => safeFilterOptions.value.statuses || []);
const filterPartnerTypes = computed(() => safeFilterOptions.value.partnerTypes || []);
const filterDeliveryCities = computed(() => safeFilterOptions.value.deliveryCities || []);
const filterDebtOptions = computed(() => safeFilterOptions.value.debtOptions || []);

const { filters, setSort, reset } = useFilters({
    initial: props.filters,
    route: "/customers",
});

const expandedRows = ref([]); // array of expanded customer IDs

// Tab state per customer
const customerTabs = reactive({}); // { customerId: 'info' | 'address' | 'history' | 'debt' | 'points' }
const getActiveTab = (id) => customerTabs[id] || "info";
const setActiveTab = (id, tab) => {
    customerTabs[id] = tab;
    if (tab === "history" && !salesHistoryData[id]) loadSalesHistory(id);
    if (tab === "debt" && !debtHistoryData[id]) loadDebtHistory(id);
    if (tab === "debt" && !offsetHistoryData[id]) loadOffsetHistory(id);
};

// KiotViet: 'Nợ hiện tại' = NET position (dương = KH nợ DN, âm = DN nợ KH)
const customerNetDebt = (customer) => {
    const debt = Number(customer.debt_amount) || 0;
    const supplierDebt = Number(customer.supplier_debt_amount) || 0;
    // Unified: customer_balance = receivable - payable
    return debt - supplierDebt;
};

// Lazy-loaded tab data
const salesHistoryData = reactive({});
const debtHistoryData = reactive({});
const tabLoading = reactive({});

const loadSalesHistory = async (customerId) => {
    tabLoading[customerId] = true;
    try {
        const { data } = await axios.get(
            `/customers/${customerId}/sales-history`,
        );
        salesHistoryData[customerId] = data;
    } catch (e) {
        salesHistoryData[customerId] = { invoices: [], returns: [] };
    }
    tabLoading[customerId] = false;
};

const getCustomerSalesReturnEntries = (customerId) => {
    const data = salesHistoryData[customerId];

    if (!data) {
        return [];
    }

    const invoices = (data.invoices || []).map((inv) => ({
        ...inv,
        _entryType: "invoice",
        _entryKey: `inv-${inv.id}`,
        _entryCode: inv.code,
        _entryLabel: "Bán hàng",
        _entryAmount: Number(inv.total) || 0,
        _entryStatus: inv.status,
        _entryCreatedAt: inv.created_at,
    }));

    const returns = (data.returns || []).map((ret) => ({
        ...ret,
        _entryType: "return",
        _entryKey: `ret-${ret.id}`,
        _entryCode: ret.code,
        _entryLabel: "Trả hàng",
        _entryAmount: -(Number(ret.total) || 0),
        _entryStatus: ret.status,
        _entryCreatedAt: ret.created_at,
    }));

    return [...invoices, ...returns].sort((a, b) => {
        const at = Date.parse(a._entryCreatedAt || a.created_at || "") || 0;
        const bt = Date.parse(b._entryCreatedAt || b.created_at || "") || 0;

        if (bt !== at) {
            return bt - at;
        }

        return String(b._entryCode || "").localeCompare(String(a._entryCode || ""));
    });
};

const loadDebtHistory = async (customerId) => {
    tabLoading[customerId] = true;
    try {
        const { data } = await axios.get(
            `/customers/${customerId}/debt-history`,
        );
        debtHistoryData[customerId] = data;
    } catch (e) {
        debtHistoryData[customerId] = { entries: [] };
    }
    tabLoading[customerId] = false;
};

const showCustomerDebtExportModal = ref(false);
const debtExportCustomer = ref(null);
const customerDebtExportForm = reactive({
    date_preset: 'all',
    date_from: '',
    date_to: '',
    include_detail: true,
    columns: {
        unit: true,
        quantity: true,
        unit_price: true,
        discount: true,
        vat: true,
        cost: true,
        line_total: true,
        note: true,
    },
});

const parseVietnameseDateToIso = (value) => {
    if (!value) return null;
    const m = String(value).trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (!m) return null;
    const dd = parseInt(m[1], 10);
    const mm = parseInt(m[2], 10);
    const yyyy = parseInt(m[3], 10);
    if (mm < 1 || mm > 12 || dd < 1 || dd > 31) return null;
    const probe = new Date(yyyy, mm - 1, dd);
    if (probe.getFullYear() !== yyyy || probe.getMonth() !== mm - 1 || probe.getDate() !== dd) return null;
    return `${yyyy}-${String(mm).padStart(2, '0')}-${String(dd).padStart(2, '0')}`;
};

const customerDebtExportCustomDatesValid = computed(() => {
    if (customerDebtExportForm.date_preset !== 'custom') return true;
    return !!parseVietnameseDateToIso(customerDebtExportForm.date_from)
        && !!parseVietnameseDateToIso(customerDebtExportForm.date_to);
});

const customerDebtExportPresets = [
    { value: 'today', label: 'Hôm nay' },
    { value: 'this_week', label: 'Tuần này' },
    { value: 'last_7_days', label: '7 ngày qua' },
    { value: 'last_30_days', label: '30 ngày qua' },
    { value: 'this_month', label: 'Tháng này' },
    { value: 'last_month', label: 'Tháng trước' },
    { value: 'this_quarter', label: 'Quý này' },
    { value: 'this_year', label: 'Năm nay' },
    { value: 'all', label: 'Toàn thời gian' },
    { value: 'custom', label: 'Lựa chọn khác' },
];

const customerDebtExportColumnOptions = [
    { key: 'unit', label: 'ĐVT' },
    { key: 'quantity', label: 'Số lượng' },
    { key: 'unit_price', label: 'Đơn giá' },
    { key: 'discount', label: 'Giảm giá' },
    { key: 'vat', label: 'VAT' },
    { key: 'cost', label: 'Giá bán/trả' },
    { key: 'line_total', label: 'Thành tiền' },
    { key: 'note', label: 'Ghi chú dòng' },
];

const openCustomerDebtExportModal = (customer) => {
    if (!customer || !customer.id) return;
    debtExportCustomer.value = customer;
    customerDebtExportForm.date_preset = 'all';
    customerDebtExportForm.date_from = '';
    customerDebtExportForm.date_to = '';
    customerDebtExportForm.include_detail = true;
    customerDebtExportColumnOptions.forEach((option) => {
        customerDebtExportForm.columns[option.key] = true;
    });
    showCustomerDebtExportModal.value = true;
};

const closeCustomerDebtExportModal = () => {
    showCustomerDebtExportModal.value = false;
    debtExportCustomer.value = null;
};

const confirmCustomerDebtExport = () => {
    const customer = debtExportCustomer.value;
    if (!customer || !customer.id) return;

    const params = new URLSearchParams();
    params.set('format', 'xlsx');
    params.set('date_preset', customerDebtExportForm.date_preset);

    if (customerDebtExportForm.date_preset === 'custom') {
        const isoFrom = parseVietnameseDateToIso(customerDebtExportForm.date_from);
        const isoTo = parseVietnameseDateToIso(customerDebtExportForm.date_to);
        if (!isoFrom || !isoTo) return;
        params.set('date_from', isoFrom);
        params.set('date_to', isoTo);
    }

    params.set('include_detail', customerDebtExportForm.include_detail ? '1' : '0');
    if (customerDebtExportForm.include_detail) {
        customerDebtExportColumnOptions.forEach((option) => {
            if (customerDebtExportForm.columns[option.key]) {
                params.append('columns[]', option.key);
            }
        });
    }

    window.location.assign(`/customers/${customer.id}/export-debt?${params.toString()}`);
    closeCustomerDebtExportModal();
};

// ====== INVOICE DETAIL MODAL ======
const invoiceDetail = reactive({ show: false, loading: false, data: null });
const showInvoiceDetail = async (invoiceId) => {
    invoiceDetail.show = true;
    invoiceDetail.loading = true;
    try {
        const { data } = await axios.get(`/invoices/${invoiceId}/detail`);
        invoiceDetail.data = data;
    } catch (e) {
        invoiceDetail.data = null;
    }
    invoiceDetail.loading = false;
};

let searchTimeout;
const applyFilters = () => {}; // no-op: useFilters handles syncing
const handleSort = (field, direction) => setSort(field, direction);

const toggleExpand = (customerId) => {
    const index = expandedRows.value.indexOf(customerId);
    if (index > -1) {
        expandedRows.value.splice(index, 1);
    } else {
        expandedRows.value.push(customerId);
    }
};

const isExpanded = (customerId) => {
    return expandedRows.value.includes(customerId);
};


const formatDate = (val) =>
    val ? new Date(val).toLocaleDateString("vi-VN") : "Chưa có";
const formatDateTime = (val) => {
    if (!val) return "";
    const d = new Date(val);
    return (
        d.toLocaleDateString("vi-VN") +
        " " +
        d.toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" })
    );
};
const formatGender = (val) => {
    if (val === "male") return "Nam";
    if (val === "female") return "Nữ";
    return "Chưa có";
};
const buildFullAddress = (c) =>
    [c.address, c.ward, c.district, c.city].filter(Boolean).join(", ");

const deleteCustomer = (customer) => {
    if (!confirm(`Bạn có chắc muốn xóa khách hàng "${customer.name}"?`)) return;
    router.delete(`/customers/${customer.id}`);
};

const toggleStatus = (customer) => {
    const newStatus = customer.status === "active" ? "inactive" : "active";
    router.put(
        `/customers/${customer.id}`,
        { status: newStatus },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
};

// Edit customer modal
const showEditModal = ref(false);
const editingCustomer = ref(null);
const editForm = useForm({
    name: "", code: "", phone: "", phone2: "", birthday: "", gender: "none",
    email: "", facebook: "", address: "", city: "", district: "", ward: "",
    customer_group: "", note: "", type: "individual",
    invoice_name: "", tax_code: "", invoice_address: "",
});

const openEditModal = (customer) => {
    editingCustomer.value = customer;
    Object.keys(editForm.data()).forEach(key => {
        editForm[key] = customer[key] ?? (key === 'gender' ? 'none' : key === 'type' ? 'individual' : '');
    });
    showEditModal.value = true;
};

const submitEdit = () => {
    editForm.put(`/customers/${editingCustomer.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            editingCustomer.value = null;
            editForm.reset();
        },
    });
};

// Debt payment / adjustment modals
const debtModal = ref({
    show: false,
    type: "",
    customerId: null,
    customerName: "",
    currentDebt: 0,
});
const debtForm = reactive({ amount: 0, note: "", mode: "auto", date: "" });
const outstandingInvoices = ref([]);
const loadingInvoices = ref(false);

const openDebtModal = async (customer, type) => {
    debtModal.value = {
        show: true,
        type,
        customerId: customer.id,
        customerName: customer.name,
        currentDebt: customer.debt_amount || 0,
    };
    debtForm.amount = type === 'adjust' ? (customer.debt_amount || 0) : 0;
    debtForm.note = "";
    debtForm.mode = "auto";
    // Mặc định ngày điều chỉnh = hiện tại (YYYY-MM-DDTHH:mm cho datetime-local)
    const _now = new Date();
    _now.setMinutes(_now.getMinutes() - _now.getTimezoneOffset());
    debtForm.date = _now.toISOString().slice(0, 16);
    outstandingInvoices.value = [];

    // Load outstanding invoices if payment mode
    if (type === "payment") {
        loadingInvoices.value = true;
        try {
            const { data } = await axios.get(`/customers/${customer.id}/outstanding-invoices`);
            outstandingInvoices.value = (data || []).map(inv => ({ ...inv, allocAmount: 0 }));
        } catch (e) {
            outstandingInvoices.value = [];
        }
        loadingInvoices.value = false;
    }
};

const manualTotal = () => outstandingInvoices.value.reduce((sum, inv) => sum + (Number(inv.allocAmount) || 0), 0);

const submitDebtModal = async () => {
    const { type, customerId } = debtModal.value;

    if (type === "payment") {
        if (debtForm.mode === "manual") {
            const allocations = outstandingInvoices.value
                .filter(inv => (Number(inv.allocAmount) || 0) > 0)
                .map(inv => ({ invoice_id: inv.id, amount: Number(inv.allocAmount) }));
            if (allocations.length === 0) {
                alert("Vui lòng nhập số tiền cho ít nhất 1 hóa đơn");
                return;
            }
            try {
                await axios.post(`/customers/${customerId}/debt-payment`, {
                    mode: "manual",
                    allocations,
                    note: debtForm.note,
                    date: debtForm.date,
                });
                debtModal.value.show = false;
                await loadDebtHistory(customerId);
                router.reload({ only: ["customers"], preserveScroll: true });
            } catch (e) {
                alert(e.response?.data?.message || "Có lỗi xảy ra");
            }
            return;
        }
        // Auto mode
        if (!debtForm.amount || debtForm.amount <= 0) {
            alert("Vui lòng nhập số tiền hợp lệ");
            return;
        }
        try {
            await axios.post(`/customers/${customerId}/debt-payment`, {
                mode: "auto",
                amount: Number(debtForm.amount) || 0,
                note: debtForm.note,
                date: debtForm.date,
            });
            debtModal.value.show = false;
            await loadDebtHistory(customerId);
            router.reload({ only: ["customers"], preserveScroll: true });
        } catch (e) {
            alert(e.response?.data?.message || "Có lỗi xảy ra");
        }
        return;
    }

    // Adjustment — amount = nợ cuối mong muốn (có thể 0 hoặc âm)
    if (debtForm.amount === null || debtForm.amount === '') {
        alert("Vui lòng nhập giá trị nợ cuối");
        return;
    }
    try {
        await axios.post(`/customers/${customerId}/debt-adjust`, { amount: Number(debtForm.amount) || 0, note: debtForm.note, date: debtForm.date });
        debtModal.value.show = false;
        await loadDebtHistory(customerId);
        router.reload({ only: ["customers"], preserveScroll: true });
    } catch (e) {
        alert(e.response?.data?.message || "Có lỗi xảy ra");
    }
};

// ====== CUSTOMER PAYMENT DISCOUNTS ======
const paymentDiscountModal = reactive({
    show: false,
    loadingInvoices: false,
    submitting: false,
    customer: null,
    invoices: [],
    users: [],
});

const paymentDiscountForm = reactive({
    amount: 0,
    discount_at: '',
    performed_by: '',
    note: '',
    allocate_to_invoices: true,
});

const openPaymentDiscountModal = async (customer) => {
    if (Number(customer.debt_amount || 0) <= 0) {
        alert("Khách hàng không còn nợ phải thu, không thể tạo chiết khấu thanh toán.");
        return;
    }

    paymentDiscountModal.customer = customer;
    paymentDiscountModal.invoices = [];
    paymentDiscountModal.users = [];
    
    paymentDiscountForm.amount = 0;
    paymentDiscountForm.note = "";
    paymentDiscountForm.allocate_to_invoices = true;
    paymentDiscountForm.performed_by = "";

    const _now = new Date();
    _now.setMinutes(_now.getMinutes() - _now.getTimezoneOffset());
    paymentDiscountForm.discount_at = _now.toISOString().slice(0, 16);

    paymentDiscountModal.show = true;
    paymentDiscountModal.loadingInvoices = true;

    try {
        const { data } = await axios.get(`/customers/${customer.id}/payment-discount-invoices`);
        paymentDiscountModal.invoices = (data.invoices || []).map(inv => ({ ...inv, allocAmount: 0 }));
        paymentDiscountModal.users = data.users || [];
    } catch (e) {
        paymentDiscountModal.invoices = [];
        paymentDiscountModal.users = [];
    } finally {
        paymentDiscountModal.loadingInvoices = false;
    }
};

const allocatePaymentDiscount = () => {
    let remaining = Number(paymentDiscountForm.amount || 0);
    paymentDiscountModal.invoices = paymentDiscountModal.invoices.map((inv) => {
        const max = Number(inv.remaining || 0);
        const alloc = Math.min(max, Math.max(remaining, 0));
        remaining -= alloc;
        return { ...inv, allocAmount: alloc };
    });
};

watch(() => paymentDiscountForm.amount, () => {
    if (paymentDiscountForm.allocate_to_invoices) {
        allocatePaymentDiscount();
    }
});

watch(() => paymentDiscountForm.allocate_to_invoices, (newVal) => {
    if (newVal) {
        allocatePaymentDiscount();
    } else {
        paymentDiscountModal.invoices = paymentDiscountModal.invoices.map(inv => ({ ...inv, allocAmount: 0 }));
    }
});

const unallocatedAmount = computed(() => {
    if (!paymentDiscountForm.allocate_to_invoices) return 0;
    const totalAlloc = paymentDiscountModal.invoices.reduce((sum, inv) => sum + Number(inv.allocAmount || 0), 0);
    return Number(paymentDiscountForm.amount || 0) - totalAlloc;
});

const submitPaymentDiscount = async () => {
    if (!paymentDiscountForm.amount || paymentDiscountForm.amount <= 0) {
        alert("Vui lòng nhập số tiền chiết khấu hợp lệ");
        return;
    }

    if (paymentDiscountForm.amount > Number(paymentDiscountModal.customer?.debt_amount || 0)) {
        alert("Số tiền chiết khấu không được vượt quá số nợ phải thu hiện tại.");
        return;
    }

    if (paymentDiscountForm.allocate_to_invoices && Math.abs(unallocatedAmount.value) > 0.01) {
        alert("Vui lòng phân bổ hết số tiền chiết khấu vào các hóa đơn.");
        return;
    }

    if (paymentDiscountForm.allocate_to_invoices) {
        for (const inv of paymentDiscountModal.invoices) {
            if (Number(inv.allocAmount || 0) > Number(inv.remaining || 0) + 0.01) {
                alert(`Số tiền phân bổ cho hóa đơn ${inv.code} không được vượt quá số tiền còn phải thu.`);
                return;
            }
        }
    }

    paymentDiscountModal.submitting = true;
    try {
        const payload = {
            amount: Number(paymentDiscountForm.amount || 0),
            discount_at: paymentDiscountForm.discount_at,
            performed_by: paymentDiscountForm.performed_by || null,
            note: paymentDiscountForm.note,
            allocate_to_invoices: paymentDiscountForm.allocate_to_invoices,
            allocations: paymentDiscountForm.allocate_to_invoices
                ? paymentDiscountModal.invoices
                    .filter(inv => Number(inv.allocAmount || 0) > 0)
                    .map(inv => ({ invoice_id: inv.id, amount: Number(inv.allocAmount || 0) }))
                : [],
        };

        const { data } = await axios.post(`/customers/${paymentDiscountModal.customer.id}/payment-discounts`, payload);
        if (data.success) {
            paymentDiscountModal.show = false;
            await loadDebtHistory(paymentDiscountModal.customer.id);
            router.reload({ only: ['customers'], preserveScroll: true });
        } else {
            alert(data.message || "Có lỗi xảy ra");
        }
    } catch (e) {
        alert(e.response?.data?.message || "Có lỗi xảy ra");
    } finally {
        paymentDiscountModal.submitting = false;
    }
};

const cancelPaymentDiscount = async (customerId, discountId) => {
    const reason = prompt("Nhập lý do hủy chiết khấu thanh toán (không bắt buộc):");
    if (reason === null) return;

    try {
        const { data } = await axios.post(`/customers/${customerId}/payment-discounts/${discountId}/cancel`, { reason });
        if (data.success) {
            await loadDebtHistory(customerId);
            router.reload({ only: ['customers'], preserveScroll: true });
        } else {
            alert(data.message || "Có lỗi xảy ra");
        }
    } catch (e) {
        alert(e.response?.data?.message || "Có lỗi xảy ra");
    }
};

// ====== MERGE CUSTOMER ↔ SUPPLIER ======
const mergeModal = reactive({
    show: false,
    source: null, // the customer being merged FROM
    searchQuery: '',
    searchResults: [],
    searching: false,
    selected: null, // the target entity to merge INTO
    submitting: false,
});

let mergeSearchTimeout;
const openMergeModal = (customer) => {
    mergeModal.show = true;
    mergeModal.source = customer;
    mergeModal.searchQuery = '';
    mergeModal.searchResults = [];
    mergeModal.selected = null;
    mergeModal.submitting = false;
};

const searchMergeTarget = () => {
    clearTimeout(mergeSearchTimeout);
    mergeModal.selected = null;
    if (!mergeModal.searchQuery || mergeModal.searchQuery.length < 1) {
        mergeModal.searchResults = [];
        return;
    }
    mergeSearchTimeout = setTimeout(async () => {
        mergeModal.searching = true;
        try {
            const { data } = await axios.get('/customers/search-for-merge', {
                params: {
                    q: mergeModal.searchQuery,
                    type: 'supplier',
                    exclude: mergeModal.source?.id,
                },
            });
            mergeModal.searchResults = data;
        } catch (e) {
            mergeModal.searchResults = [];
        }
        mergeModal.searching = false;
    }, 300);
};

const selectMergeTarget = (target) => {
    mergeModal.selected = target;
    mergeModal.searchResults = [];
};

const submitMerge = () => {
    if (!mergeModal.selected || mergeModal.submitting) return;
    mergeModal.submitting = true;
    router.post(`/customers/${mergeModal.source.id}/merge`, {
        merge_with_id: mergeModal.selected.id,
    }, {
        onSuccess: () => {
            mergeModal.show = false;
            mergeModal.source = null;
            mergeModal.selected = null;
        },
        onFinish: () => {
            mergeModal.submitting = false;
        },
    });
};

// ====== CẤN BẰNG CÔNG NỢ ======
const offsetModal = reactive({
    show: false,
    customerId: null,
    customerName: '',
    receivable: 0,
    payable: 0,
    maxOffset: 0,
    submitting: false,
});
const offsetForm = reactive({ amount: 0, note: '' });

const openOffsetModal = (customer) => {
    offsetModal.show = true;
    offsetModal.customerId = customer.id;
    offsetModal.customerName = customer.name;
    offsetModal.receivable = Number(customer.debt_amount) || 0;
    offsetModal.payable = Number(customer.supplier_debt_amount) || 0;
    offsetModal.maxOffset = Math.min(offsetModal.receivable, offsetModal.payable);
    offsetModal.submitting = false;
    offsetForm.amount = offsetModal.maxOffset;
    offsetForm.note = '';
};

const submitOffset = async () => {
    if (!offsetForm.amount || offsetForm.amount <= 0) {
        alert('Vui lòng nhập số tiền hợp lệ');
        return;
    }
    if (offsetForm.amount > offsetModal.maxOffset) {
        alert('Số tiền cấn bằng không được vượt quá ' + formatCurrency(offsetModal.maxOffset));
        return;
    }
    offsetModal.submitting = true;
    try {
        await axios.post(`/customers/${offsetModal.customerId}/debt-offset`, {
            amount: Number(offsetForm.amount) || 0,
            note: offsetForm.note,
        });
        offsetModal.show = false;
        await loadDebtHistory(offsetModal.customerId);
        await loadOffsetHistory(offsetModal.customerId);
        router.reload({ only: ['customers'], preserveScroll: true });
    } catch (e) {
        alert(e.response?.data?.message || 'Có lỗi xảy ra khi cấn bằng công nợ');
    } finally {
        offsetModal.submitting = false;
    }
};

// ====== LỊCH SỬ CẤN BẰNG ======
const offsetHistoryData = reactive({});

const loadOffsetHistory = async (customerId) => {
    try {
        const { data } = await axios.get(`/customers/${customerId}/debt-offset-history`);
        offsetHistoryData[customerId] = data;
    } catch (e) {
        offsetHistoryData[customerId] = [];
    }
};

const cancelOffset = async (customerId, offsetId) => {
    const reason = prompt('Lý do hủy cấn bằng (không bắt buộc):');
    if (reason === null) return; // user cancelled prompt
    try {
        await axios.post(`/customers/${customerId}/cancel-debt-offset/${offsetId}`, { reason });
        await loadDebtHistory(customerId);
        await loadOffsetHistory(customerId);
        router.reload({ only: ['customers'], preserveScroll: true });
    } catch (e) {
        alert(e.response?.data?.message || 'Có lỗi xảy ra khi hủy cấn bằng');
    }
};

// ====== CHI TIẾT PHIẾU CẤN BẰNG (CB) - KiotViet style ======
const cbDetailModal = reactive({
    show: false,
    offset: null,
    customerId: null,
});

const openCbDetail = (code, customerId) => {
    // Tìm offset từ offsetHistoryData
    const offsets = offsetHistoryData[customerId] || [];
    const found = offsets.find(o => o.code === code);
    if (found) {
        cbDetailModal.show = true;
        cbDetailModal.offset = { ...found };
        cbDetailModal.customerId = customerId;
    }
};

const isCbCode = (code) => {
    return code && (code.startsWith('CB') || code.startsWith('DTCN'));
};

const debtVoucherDetailModal = reactive({
    show: false,
    loading: false,
    type: '',
    title: '',
    code: '',
    data: null,
    error: '',
});

const openDebtVoucherDetail = async (entry, customerId) => {
    const code = entry?.code || entry?.ref_code;
    if (!code) return;

    if (isCbCode(code)) {
        openCbDetail(code, customerId);
        return;
    }

    debtVoucherDetailModal.show = true;
    debtVoucherDetailModal.loading = true;
    debtVoucherDetailModal.error = '';
    debtVoucherDetailModal.type = '';
    debtVoucherDetailModal.title = '';
    debtVoucherDetailModal.code = code;
    debtVoucherDetailModal.data = null;

    try {
        const { data } = await axios.get(`/customers/${customerId}/debt-voucher-detail`, {
            params: { code },
        });

        if (!data.success) {
            debtVoucherDetailModal.error = data.message || 'Không tìm thấy chứng từ';
            return;
        }

        debtVoucherDetailModal.type = data.type;
        debtVoucherDetailModal.title = data.title || 'Chi tiết chứng từ';
        debtVoucherDetailModal.code = data.code || code;
        debtVoucherDetailModal.data = data.data;
    } catch (e) {
        debtVoucherDetailModal.error =
            e.response?.data?.message || 'Không thể tải chi tiết chứng từ';
    } finally {
        debtVoucherDetailModal.loading = false;
    }
};

const deleteCbOffset = async () => {
    if (!cbDetailModal.offset || !confirm('Bạn có chắc muốn xóa phiếu cấn bằng này?')) return;
    await cancelOffset(cbDetailModal.customerId, cbDetailModal.offset.id);
    cbDetailModal.show = false;
};

// Modal for CREATE CUSTOMER
const showCreateModal = ref(false);
const form = useForm({
    name: "",
    code: "",
    phone: "",
    phone2: "",
    birthday: "",
    gender: "none",
    email: "",
    facebook: "",

    address: "",
    city: "",
    district: "",
    ward: "",

    customer_group: "",
    note: "",

    type: "individual",
    invoice_name: "",
    id_card: "",
    passport: "",
    tax_code: "",

    invoice_address: "",
    invoice_city: "",
    invoice_district: "",
    invoice_ward: "",

    invoice_email: "",
    invoice_phone: "",
    bank_name: "",
    bank_account: "",

    is_supplier: false,
});

// ====== XÁC NHẬN GỘP KH + NCC (KiotViet style) ======
const supplierConfirm = reactive({
    show: false,
});

const onToggleIsSupplier = () => {
    if (!form.is_supplier) {
        // Turning ON → show confirmation
        supplierConfirm.show = true;
    } else {
        // Turning OFF → just turn off
        form.is_supplier = false;
    }
};

const confirmSupplierToggle = () => {
    form.is_supplier = true;
    supplierConfirm.show = false;
};

const cancelSupplierToggle = () => {
    supplierConfirm.show = false;
};

const submit = () => {
    form.post("/customers", {
        onSuccess: () => {
            showCreateModal.value = false;
            form.reset();
        },
    });
};

// ====== CUSTOMER GROUP MODAL (HOTFIX 24.4A-3) ======
// Hoisted into the script-setup block so openGroupModal / submitGroupModal
// are actually reactive — previously they sat after the script close tag and
// Vue treated them as orphan template text.
const showGroupModal = ref(false);
const groupForm = reactive({
    name: '',
    code: '',
    discount_type: '',
    discount_value: 0,
    note: '',
    description: '',
    conditions: [],
    update_mode: 'none',
    auto_update: false,
});
const groupModalTab = ref('info');
const groupSubmitting = ref(false);
const groupErrors = ref({});

// Locally cached groups merged on top of backend filterOptions.customerGroups
// so a freshly created group is selectable immediately, even if the Inertia
// partial reload hasn't propagated yet.
const localCustomerGroups = ref([]);

const mergedCustomerGroups = computed(() => {
    const backend = filterCustomerGroups.value || [];
    const seen = new Set(backend.map((g) => g.value));
    const out = [...backend];
    for (const g of localCustomerGroups.value || []) {
        if (g?.value && !seen.has(g.value)) {
            out.push(g);
            seen.add(g.value);
        }
    }
    return out;
});

const openGroupModal = () => {
    groupForm.name = '';
    groupForm.code = '';
    groupForm.discount_type = '';
    groupForm.discount_value = 0;
    groupForm.note = '';
    groupForm.description = '';
    groupForm.conditions = [];
    groupForm.update_mode = 'none';
    groupForm.auto_update = false;
    groupModalTab.value = 'info';
    groupErrors.value = {};
    showGroupModal.value = true;
};

const reloadCustomerGroups = async () => {
    try {
        const { data } = await axios.get('/customer-groups/options');
        if (Array.isArray(data)) {
            localCustomerGroups.value = data.map((g) => ({
                value: g.name,
                label: g.name,
                id: g.id,
                source: 'master',
            }));
        }
    } catch (_) {
        // silent — Inertia partial reload below is the second line of defence
    }
    router.reload({ only: ['filterOptions'], preserveScroll: true, preserveState: true });
};

const submitGroupModal = async () => {
    groupErrors.value = {};
    if (!groupForm.name?.trim()) {
        groupErrors.value = { name: ['Vui lòng nhập tên nhóm khách hàng.'] };
        return;
    }
    groupSubmitting.value = true;
    try {
        const { data } = await axios.post('/customer-groups', {
            ...groupForm,
            discount_value: Number(groupForm.discount_value) || 0,
        });
        const created = data?.group;
        if (created?.name) {
            localCustomerGroups.value = [
                ...localCustomerGroups.value.filter((g) => g.value !== created.name),
                { value: created.name, label: created.name, id: created.id, source: 'master' },
            ];
        }
        await reloadCustomerGroups();
        showGroupModal.value = false;
    } catch (e) {
        const status = e.response?.status;
        if (status === 403) {
            groupErrors.value = { _generic: 'Bạn không có quyền tạo nhóm khách hàng.' };
        } else if (status === 422) {
            const errors = e.response?.data?.errors;
            groupErrors.value = errors && Object.keys(errors).length
                ? errors
                : { _generic: e.response?.data?.message || 'Dữ liệu không hợp lệ.' };
        } else {
            groupErrors.value = { _generic: e.response?.data?.message || 'Có lỗi xảy ra khi lưu nhóm.' };
        }
    } finally {
        groupSubmitting.value = false;
    }
};

// HOTFIX 24.10 — quick-create a customer group inline from the Combobox
// without opening the advanced "Tạo nhóm khách hàng" modal. The advanced
// modal stays available via the sidebar "Tạo mới" link unchanged.
//
// Behaviour:
//   - Empty name (user clicked "Tạo nhóm khách hàng mới" with no query)
//     → fall back to opening the advanced modal so they can fill the
//       full form (description, conditions, etc.).
//   - Existing name (case-insensitive match in mergedCustomerGroups)
//     → just select it; no API call.
//   - Otherwise → POST /customer-groups, merge into localCustomerGroups,
//     reload, run `assign(name)` so the calling form picks the new
//     group as its value.
async function createGroupQuick(name, assign) {
    const trimmed = (name || '').trim();
    if (!trimmed) {
        openGroupModal();
        return;
    }
    const existing = mergedCustomerGroups.value.find(
        (g) => (g.label || '').toLowerCase() === trimmed.toLowerCase()
    );
    if (existing) {
        assign(existing.value);
        return;
    }
    try {
        const { data } = await axios.post('/customer-groups', {
            name: trimmed,
            code: '',
            discount_type: '',
            discount_value: 0,
            note: '',
            description: '',
            conditions: [],
            update_mode: 'none',
            auto_update: false,
        });
        const created = data?.group;
        const finalName = created?.name || trimmed;
        if (finalName) {
            localCustomerGroups.value = [
                ...localCustomerGroups.value.filter((g) => g.value !== finalName),
                { value: finalName, label: finalName, id: created?.id, source: 'master' },
            ];
        }
        await reloadCustomerGroups();
        assign(finalName);
    } catch (e) {
        const status = e.response?.status;
        if (status === 422) {
            // Most common cause: duplicate name. Try to refresh and select
            // the matching group if it exists now.
            await reloadCustomerGroups();
            const refreshed = mergedCustomerGroups.value.find(
                (g) => (g.label || '').toLowerCase() === trimmed.toLowerCase()
            );
            if (refreshed) {
                assign(refreshed.value);
                return;
            }
            alert(e.response?.data?.message || 'Tên nhóm không hợp lệ hoặc đã tồn tại.');
        } else if (status === 403) {
            alert('Bạn không có quyền tạo nhóm khách hàng.');
        } else {
            alert(e.response?.data?.message || 'Có lỗi khi tạo nhóm.');
        }
    }
}

const createCustomerGroupAndSelect = (name) => {
    return createGroupQuick(name, (val) => {
        form.customer_group = val;
    });
};

const createCustomerGroupAndSelectForEdit = (name) => {
    return createGroupQuick(name, (val) => {
        editForm.customer_group = val;
    });
};

// Date-range filter v-model bridges for the shared DateRangeFilter component.
// Each exposes { filter, from, to } and writes back into the flat filter fields
// the backend already echoes on /customers.
const birthdayRange = computed({
    get: () => ({
        filter: filters.birthday_filter || (filters.birthday_from || filters.birthday_to ? 'custom' : 'all'),
        from: filters.birthday_from || '',
        to: filters.birthday_to || '',
    }),
    set: (v) => {
        filters.birthday_filter = v?.filter || 'all';
        filters.birthday_from = v?.filter === 'custom' ? (v?.from || '') : '';
        filters.birthday_to = v?.filter === 'custom' ? (v?.to || '') : '';
    },
});

const lastTransactionRange = computed({
    get: () => ({
        filter: filters.last_transaction_filter || (filters.last_transaction_from || filters.last_transaction_to ? 'custom' : 'all'),
        from: filters.last_transaction_from || '',
        to: filters.last_transaction_to || '',
    }),
    set: (v) => {
        filters.last_transaction_filter = v?.filter || 'all';
        filters.last_transaction_from = v?.filter === 'custom' ? (v?.from || '') : '';
        filters.last_transaction_to = v?.filter === 'custom' ? (v?.to || '') : '';
    },
});

const totalSalesDateRange = computed({
    get: () => ({
        filter: filters.total_sales_date_filter || (filters.total_sales_date_from || filters.total_sales_date_to ? 'custom' : 'all'),
        from: filters.total_sales_date_from || '',
        to: filters.total_sales_date_to || '',
    }),
    set: (v) => {
        filters.total_sales_date_filter = v?.filter || 'all';
        filters.total_sales_date_from = v?.filter === 'custom' ? (v?.from || '') : '';
        filters.total_sales_date_to = v?.filter === 'custom' ? (v?.to || '') : '';
    },
});

const createdDateRange = computed({
    get: () => ({
        filter: filters.date_filter || (filters.date_from || filters.date_to ? 'custom' : 'all'),
        from: filters.date_from || '',
        to: filters.date_to || '',
    }),
    set: (v) => {
        filters.date_filter = v?.filter || 'all';
        filters.date_from = v?.filter === 'custom' ? (v?.from || '') : '';
        filters.date_to = v?.filter === 'custom' ? (v?.to || '') : '';
    },
});
</script>

<template>
    <Head title="Khách hàng - KiotViet Clone" />
    <AppLayout>
        <!-- Sidebar slot -->
        <template #sidebar>
            <!-- 1. NHÓM KHÁCH HÀNG -->
            <div class="px-3 py-4 border-b border-gray-200">
                <div class="flex justify-between items-center mb-2">
                    <label class="block text-sm font-bold text-gray-800">Nhóm khách hàng</label>
                    <button @click="openGroupModal" class="text-blue-600 hover:underline text-xs">Tạo mới</button>
                </div>
                <select v-model="filters.customer_group" class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none focus:border-blue-500">
                    <option value="">Tất cả các nhóm</option>
                    <option v-for="g in mergedCustomerGroups" :key="g.value" :value="g.value">{{ g.label }}</option>
                </select>
            </div>

            <!-- 2. LOẠI KHÁCH HÀNG -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2">Loại khách hàng</label>
                <div class="flex flex-wrap gap-2 text-sm">
                    <button @click="filters.type = ''" :class="!filters.type ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'" class="border rounded-full px-3 py-1 whitespace-nowrap">Tất cả</button>
                    <button v-for="t in filterTypes" :key="t.value" @click="filters.type = t.value" :class="filters.type === t.value ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'" class="border rounded-full px-3 py-1 whitespace-nowrap">{{ t.label }}</button>
                </div>
            </div>

            <!-- 3. GIỚI TÍNH -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2">Giới tính</label>
                <div class="flex flex-wrap gap-2 text-sm">
                    <button @click="filters.gender = ''" :class="!filters.gender ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'" class="border rounded-full px-3 py-1 whitespace-nowrap">Tất cả</button>
                    <button v-for="g in filterGenders" :key="g.value" @click="filters.gender = g.value" :class="filters.gender === g.value ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'" class="border rounded-full px-3 py-1 whitespace-nowrap">{{ g.label }}</button>
                </div>
            </div>

            <!-- 4. SINH NHẬT -->
            <div v-if="hasCapability('supportsBirthdayFilter')" class="px-3 py-4 border-b border-gray-200">
                <DateRangeFilter v-model="birthdayRange" label="Sinh nhật" flat />
            </div>

            <!-- 5. NGÀY GIAO DỊCH CUỐI -->
            <div v-if="hasCapability('supportsLastTransactionFilter')" class="px-3 py-4 border-b border-gray-200">
                <DateRangeFilter v-model="lastTransactionRange" label="Ngày giao dịch cuối" flat />
            </div>

            <!-- 6. TỔNG BÁN -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2">Tổng bán</label>
                <div class="grid grid-cols-2 gap-2 mb-3">
                    <MoneyInput v-model="filters.total_sales_from" :min="0" placeholder="Giá trị từ" input-class="w-full min-w-0 border rounded p-1.5 text-sm" />
                    <MoneyInput v-model="filters.total_sales_to" :min="0" placeholder="Giá trị tới" input-class="w-full min-w-0 border rounded p-1.5 text-sm" />
                </div>
                <DateRangeFilter v-if="hasCapability('supportsTotalSalesTimeFilter')" v-model="totalSalesDateRange" label="Thời gian tổng bán" flat />
            </div>

            <!-- 7. NỢ HIỆN TẠI -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2">Nợ hiện tại</label>
                <div class="grid grid-cols-2 gap-2">
                    <MoneyInput v-model="filters.net_debt_from" placeholder="Từ" input-class="w-full min-w-0 border rounded p-1.5 text-sm" />
                    <MoneyInput v-model="filters.net_debt_to" placeholder="Tới" input-class="w-full min-w-0 border rounded p-1.5 text-sm" />
                </div>
            </div>

            <!-- 8. KHU VỰC GIAO HÀNG -->
            <div v-if="hasCapability('supportsDeliveryAreaFilter')" class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2">Khu vực giao hàng</label>
                <select v-model="filters.delivery_city" class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none focus:border-blue-500">
                    <option value="">Tất cả tỉnh/TP</option>
                    <option v-for="c in filterDeliveryCities" :key="c.value" :value="c.value">{{ c.label }}</option>
                </select>
            </div>

            <!-- 9. TRẠNG THÁI -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2">Trạng thái</label>
                <div class="flex flex-wrap gap-2 text-sm">
                    <button @click="filters.status = []" :class="!filters.status?.length ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'" class="border rounded-full px-3 py-1 whitespace-nowrap">Tất cả</button>
                    <button v-for="s in filterStatuses" :key="s.value" @click="filters.status = [s.value]" :class="filters.status?.includes?.(s.value) ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'" class="border rounded-full px-3 py-1 whitespace-nowrap">{{ s.label }}</button>
                </div>
            </div>

            <!-- 10. LOẠI ĐỐI TÁC -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2">Loại đối tác</label>
                <div class="flex flex-wrap gap-2 text-sm">
                    <button @click="filters.partner_type = ''" :class="!filters.partner_type ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'" class="border rounded-full px-3 py-1 whitespace-nowrap">Tất cả</button>
                    <button v-for="p in filterPartnerTypes" :key="p.value" @click="filters.partner_type = p.value" :class="filters.partner_type === p.value ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'" class="border rounded-full px-3 py-1 whitespace-nowrap">{{ p.label }}</button>
                </div>
            </div>

            <!-- 11. NGƯỜI TẠO -->
            <div v-if="hasCapability('supportsCreatedByFilter')" class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2">Người tạo</label>
                <select v-model="filters.created_by" class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none focus:border-blue-500">
                    <option value="">Chọn người tạo</option>
                    <option v-for="u in filterCreators" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
            </div>

            <!-- 12. CHI NHÁNH TẠO -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2">Chi nhánh tạo</label>
                <select v-model="filters.branch_id" class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none focus:border-blue-500">
                    <option value="">Tất cả chi nhánh</option>
                    <option v-for="b in filterBranches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>

            <!-- 13. NGÀY TẠO -->
            <div class="px-3 py-4 border-b border-gray-200">
                <DateRangeFilter v-model="createdDateRange" label="Ngày tạo" flat />
            </div>

            <!-- CLEAR FILTER -->
            <div class="px-3 py-4">
                <button @click="reset" class="w-full text-center text-sm text-blue-600 hover:underline">Xóa bộ lọc</button>
            </div>
        </template>

        <!-- Main content -->
        <div class="bg-white h-full flex flex-col pt-3">
            <!-- Header Toolbar -->
            <div
                class="flex items-center justify-between px-4 pb-3 border-b border-gray-200"
            >
                <div
                    class="flex items-center gap-4 flex-1 max-w-2xl text-2xl font-bold text-gray-800"
                >
                    Khách hàng
                </div>

                <div
                    class="relative w-80 ml-auto mr-4 border-b border-gray-300"
                >
                    <svg
                        class="w-4 h-4 absolute left-1 top-1/2 -translate-y-1/2 text-gray-400"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                        ></path>
                    </svg>
                    <input
                        type="text"
                        v-model="filters.search"
                        placeholder="Theo mã, tên, số điện thoại"
                        class="w-full pl-7 pr-8 py-1.5 focus:outline-none text-sm placeholder-gray-400 bg-transparent"
                    />
                    <svg
                        class="w-4 h-4 absolute right-1 top-1/2 -translate-y-1/2 text-gray-400"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"
                        ></path>
                    </svg>
                </div>

                <div class="flex gap-2 ml-2">
                    <button
                        @click="showCreateModal = true"
                        class="bg-white text-blue-600 border border-blue-600 px-3 py-1.5 text-sm font-medium rounded flex items-center gap-1 hover:bg-blue-50 transition"
                    >
                        <svg
                            class="w-4 h-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 4v16m8-8H4"
                            ></path></svg
                        >Khách hàng
                    </button>
                    <ExcelButtons
                        export-url="/customers/export"
                        import-url="/customers/import"
                    />
                    <button
                        class="bg-white text-gray-600 border border-gray-300 px-2.5 py-1.5 rounded hover:bg-gray-50"
                    >
                        <svg
                            class="w-4 h-4 text-gray-500"
                            fill="currentColor"
                            viewBox="0 0 16 16"
                        >
                            <path
                                d="M3 9.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3m5 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3m5 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3"
                            />
                        </svg>
                    </button>
                    <!-- Settings layout fake -->
                    <div
                        class="flex items-center gap-1 ml-2 border-l border-gray-200 pl-2"
                    >
                        <button class="text-gray-400 hover:text-gray-600 p-1">
                            <svg
                                class="w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M4 6h16M4 10h16M4 14h16M4 18h16"
                                ></path>
                            </svg>
                        </button>
                        <button class="text-gray-400 hover:text-gray-600 p-1">
                            <svg
                                class="w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
                                ></path>
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                ></path>
                            </svg>
                        </button>
                        <button class="text-gray-400 hover:text-gray-600 p-1">
                            <svg
                                class="w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                ></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Summary Bar -->
            <div class="flex items-center gap-6 px-4 py-2 bg-blue-50 border-b border-blue-200 text-sm">
                <div>Tổng nợ phải thu: <span class="font-bold text-red-600">{{ formatCurrency(summary?.total_debt || 0) }}</span></div>
                <div>Tổng bán: <span class="font-bold text-gray-800">{{ formatCurrency(summary?.total_spent || 0) }}</span></div>
                <div>Tổng bán trừ trả: <span class="font-bold text-gray-800">{{ formatCurrency((summary?.total_spent || 0) - (summary?.total_returns || 0)) }}</span></div>
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-auto bg-gray-50/20">
                <table class="w-full text-sm text-left whitespace-nowrap">
                    <thead class="text-[13px] font-bold text-gray-700 bg-white border-b border-gray-200 sticky top-0 z-10 shadow-sm">
                        <tr>
                            <th class="px-4 py-3 w-10 text-center">
                                <input type="checkbox" class="rounded border-gray-300" />
                            </th>
                            <SortableHeader label="Mã khách hàng" field="code" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" class="px-4 py-3" @sort="handleSort" />
                            <SortableHeader label="Tên khách hàng" field="name" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" class="px-4 py-3" @sort="handleSort" />
                            <SortableHeader label="Điện thoại" field="phone" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" class="px-4 py-3" @sort="handleSort" />
                            <SortableHeader label="Nợ hiện tại" field="debt_amount" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="right" class="px-4 py-3 text-right" @sort="handleSort" />
                            <th class="px-4 py-3 text-right">Số ngày nợ</th>
                            <SortableHeader label="Tổng bán" field="total_spent" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="right" class="px-4 py-3 text-right" @sort="handleSort" />
                            <th class="px-4 py-3 text-right">
                                Tổng bán trừ trả hàng
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-gray-800">
                        <!-- Summary row -->
                        <tr class="bg-gray-50 border-b border-gray-200 font-semibold text-sm">
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td class="px-4 py-3 text-right text-red-600">{{ formatCurrency(summary?.total_debt || 0) }}</td>
                            <td></td>
                            <td class="px-4 py-3 text-right text-gray-700">{{ formatCurrency(summary?.total_spent || 0) }}</td>
                            <td class="px-4 py-3 text-right text-gray-700">{{ formatCurrency((summary?.total_spent || 0) - (summary?.total_returns || 0)) }}</td>
                        </tr>
                        <tr v-if="customers.data.length === 0">
                            <td
                                colspan="8"
                                class="px-6 py-12 text-center text-gray-500"
                            >
                                Không tìm thấy khách hàng nào.
                            </td>
                        </tr>
                        <template
                            v-for="customer in customers.data"
                            :key="customer.id"
                        >
                            <!-- Main Row -->
                            <tr
                                @click="toggleExpand(customer.id)"
                                class="hover:bg-blue-50/50 transition-colors cursor-pointer bg-white"
                                :class="{
                                    'bg-[#f4f7fe]': isExpanded(customer.id),
                                    'border-l-2 border-l-blue-500': isExpanded(
                                        customer.id,
                                    ),
                                }"
                            >
                                <td class="px-4 py-3 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300 text-blue-500 focus:ring-blue-500"
                                    />
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <svg v-if="customer.is_supplier" class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z"/></svg>
                                        {{ customer.code }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">{{ customer.name }}</td>
                                <td class="px-4 py-3">{{ customer.phone }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div :class="customerNetDebt(customer) != 0 ? (customerNetDebt(customer) < 0 ? 'text-red-600 font-semibold' : '') : ''">
                                        {{ formatCurrency(customerNetDebt(customer)) }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right text-gray-400">
                                    ---
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{
                                        formatCurrency(
                                            customer.total_spent,
                                        )
                                    }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{
                                        formatCurrency(
                                            customer.total_spent -
                                                customer.total_returns,
                                        )
                                    }}
                                </td>
                            </tr>

                            <!-- Expanded Detail Row -->
                            <tr
                                v-if="isExpanded(customer.id)"
                                class="border-b-4 border-blue-50"
                            >
                                <td colspan="8" class="p-0 border-0 bg-white">
                                    <div
                                        class="px-4 py-4 w-full shadow-inner border-t border-blue-100 flex flex-col pt-0"
                                    >
                                        <!-- Tabs within Detail -->
                                        <div
                                            class="flex text-[13.5px] font-semibold text-gray-600 border-b border-gray-200 sticky top-0 bg-white z-0 pt-2 mb-4"
                                        >
                                            <button
                                                @click="
                                                    setActiveTab(
                                                        customer.id,
                                                        'info',
                                                    )
                                                "
                                                class="px-4 pb-2 transition"
                                                :class="
                                                    getActiveTab(
                                                        customer.id,
                                                    ) === 'info'
                                                        ? 'border-b-2 border-blue-600 text-blue-600'
                                                        : 'hover:text-blue-500'
                                                "
                                            >
                                                Thông tin
                                            </button>
                                            <button
                                                @click="
                                                    setActiveTab(
                                                        customer.id,
                                                        'address',
                                                    )
                                                "
                                                class="px-4 pb-2 transition"
                                                :class="
                                                    getActiveTab(
                                                        customer.id,
                                                    ) === 'address'
                                                        ? 'border-b-2 border-blue-600 text-blue-600'
                                                        : 'hover:text-blue-500'
                                                "
                                            >
                                                Địa chỉ nhận hàng
                                            </button>
                                            <button
                                                @click="
                                                    setActiveTab(
                                                        customer.id,
                                                        'history',
                                                    )
                                                "
                                                class="px-4 pb-2 transition"
                                                :class="
                                                    getActiveTab(
                                                        customer.id,
                                                    ) === 'history'
                                                        ? 'border-b-2 border-blue-600 text-blue-600'
                                                        : 'hover:text-blue-500'
                                                "
                                            >
                                                Lịch sử bán/trả hàng
                                            </button>
                                            <button
                                                @click="
                                                    setActiveTab(
                                                        customer.id,
                                                        'debt',
                                                    )
                                                "
                                                class="px-4 pb-2 transition"
                                                :class="
                                                    getActiveTab(
                                                        customer.id,
                                                    ) === 'debt'
                                                        ? 'border-b-2 border-blue-600 text-blue-600'
                                                        : 'hover:text-blue-500'
                                                "
                                            >
                                                Công nợ
                                            </button>
                                            <button
                                                @click="
                                                    setActiveTab(
                                                        customer.id,
                                                        'points',
                                                    )
                                                "
                                                class="px-4 pb-2 transition"
                                                :class="
                                                    getActiveTab(
                                                        customer.id,
                                                    ) === 'points'
                                                        ? 'border-b-2 border-blue-600 text-blue-600'
                                                        : 'hover:text-blue-500'
                                                "
                                            >
                                                Lịch sử tích điểm
                                            </button>
                                        </div>

                                        <!-- ===== TAB: Thông tin ===== -->
                                        <div
                                            v-if="
                                                getActiveTab(customer.id) ===
                                                'info'
                                            "
                                        >
                                            <div
                                                class="flex items-start gap-4 mb-6"
                                            >
                                                <div
                                                    class="w-24 h-24 bg-blue-100 text-blue-500 rounded-full flex items-center justify-center flex-shrink-0 relative overflow-hidden"
                                                >
                                                    <svg
                                                        class="w-16 h-16 mt-4"
                                                        fill="currentColor"
                                                        viewBox="0 0 20 20"
                                                    >
                                                        <path
                                                            fill-rule="evenodd"
                                                            d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"
                                                            clip-rule="evenodd"
                                                        ></path>
                                                    </svg>
                                                </div>
                                                <div class="flex-1 mt-1">
                                                    <div
                                                        class="flex gap-2 items-end mb-2"
                                                    >
                                                        <h2
                                                            class="text-xl font-bold text-gray-800"
                                                        >
                                                            {{ customer.name }}
                                                        </h2>
                                                        <span
                                                            class="text-gray-500 font-medium mb-0.5"
                                                            >{{
                                                                customer.code
                                                            }}</span
                                                        >
                                                    </div>
                                                    <div
                                                        class="text-[13px] text-gray-500 space-y-1 mb-2"
                                                    >
                                                        <span
                                                            class="border-r border-gray-300 pr-3 mr-2"
                                                            >Người tạo:
                                                            <span
                                                                class="text-gray-700"
                                                                >{{
                                                                    customer.created_by_name ||
                                                                    "—"
                                                                }}</span
                                                            ></span
                                                        >
                                                        <span
                                                            class="border-r border-gray-300 pr-3 mr-2"
                                                            >Ngày tạo:
                                                            <span
                                                                class="text-gray-700"
                                                                >{{
                                                                    formatDate(
                                                                        customer.created_at,
                                                                    )
                                                                }}</span
                                                            ></span
                                                        >
                                                        <span
                                                            >Nhóm khách:
                                                            <span
                                                                class="text-gray-700"
                                                                >{{
                                                                    customer.customer_group ||
                                                                    "Chưa có"
                                                                }}</span
                                                            ></span
                                                        >
                                                    </div>
                                                    <button
                                                        class="text-blue-600 hover:text-blue-800 text-sm font-semibold flex items-center gap-1"
                                                    >
                                                        <svg
                                                            class="w-4 h-4"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            viewBox="0 0 24 24"
                                                        >
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"
                                                            ></path>
                                                        </svg>
                                                        Xem phân tích
                                                    </button>
                                                </div>
                                                <div
                                                    class="text-[13px] text-gray-500 font-medium mt-1 pr-4"
                                                >
                                                    {{
                                                        customer.branch?.name ||
                                                        "—"
                                                    }}
                                                </div>
                                            </div>

                                            <div
                                                class="grid grid-cols-3 gap-y-4 gap-x-8 text-[13.5px] border-b border-gray-200 pb-4 mb-4"
                                            >
                                                <div>
                                                    <div
                                                        class="text-gray-500 mb-0.5 font-medium"
                                                    >
                                                        Điện thoại
                                                    </div>
                                                    <div
                                                        class="text-gray-800 font-medium"
                                                    >
                                                        {{
                                                            customer.phone ||
                                                            "Chưa có"
                                                        }}
                                                    </div>
                                                </div>
                                                <div>
                                                    <div
                                                        class="text-gray-500 mb-0.5 font-medium"
                                                    >
                                                        Sinh nhật
                                                    </div>
                                                    <div
                                                        :class="
                                                            customer.birthday
                                                                ? 'text-gray-800'
                                                                : 'text-gray-400'
                                                        "
                                                    >
                                                        {{
                                                            customer.birthday
                                                                ? formatDate(
                                                                      customer.birthday,
                                                                  )
                                                                : "Chưa có"
                                                        }}
                                                    </div>
                                                </div>
                                                <div>
                                                    <div
                                                        class="text-gray-500 mb-0.5 font-medium"
                                                    >
                                                        Giới tính
                                                    </div>
                                                    <div
                                                        :class="
                                                            customer.gender !==
                                                            'none'
                                                                ? 'text-gray-800'
                                                                : 'text-gray-400'
                                                        "
                                                    >
                                                        {{
                                                            formatGender(
                                                                customer.gender,
                                                            )
                                                        }}
                                                    </div>
                                                </div>
                                                <div>
                                                    <div
                                                        class="text-gray-500 mb-0.5 font-medium"
                                                    >
                                                        Email
                                                    </div>
                                                    <div
                                                        :class="
                                                            customer.email
                                                                ? 'text-gray-800'
                                                                : 'text-gray-400'
                                                        "
                                                    >
                                                        {{
                                                            customer.email ||
                                                            "Chưa có"
                                                        }}
                                                    </div>
                                                </div>
                                                <div>
                                                    <div
                                                        class="text-gray-500 mb-0.5 font-medium"
                                                    >
                                                        Facebook
                                                    </div>
                                                    <div
                                                        :class="
                                                            customer.facebook
                                                                ? 'text-gray-800'
                                                                : 'text-gray-400'
                                                        "
                                                    >
                                                        {{
                                                            customer.facebook ||
                                                            "Chưa có"
                                                        }}
                                                    </div>
                                                </div>
                                                <div></div>
                                                <div class="col-span-3">
                                                    <div
                                                        class="text-gray-500 mb-0.5 font-medium"
                                                    >
                                                        Địa chỉ
                                                    </div>
                                                    <div
                                                        :class="
                                                            buildFullAddress(
                                                                customer,
                                                            )
                                                                ? 'text-gray-800'
                                                                : 'text-gray-400'
                                                        "
                                                    >
                                                        {{
                                                            buildFullAddress(
                                                                customer,
                                                            ) || "Chưa có"
                                                        }}
                                                    </div>
                                                </div>
                                            </div>

                                            <div
                                                class="bg-gray-50/50 rounded p-4 border border-gray-200 mb-4 text-[13.5px]"
                                            >
                                                <div
                                                    class="font-bold text-gray-700 mb-1"
                                                >
                                                    Thông tin xuất hóa đơn
                                                </div>
                                                <div class="text-gray-600 mb-4">
                                                    {{
                                                        customer.invoice_name ||
                                                        customer.name
                                                    }}
                                                    /
                                                    {{
                                                        customer.invoice_phone ||
                                                        customer.phone
                                                    }}
                                                </div>
                                                <div
                                                    class="flex items-center gap-2 text-gray-600 font-bold mt-2"
                                                >
                                                    <svg
                                                        class="w-4 h-4"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        viewBox="0 0 24 24"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                                                        ></path>
                                                    </svg>
                                                    {{
                                                        customer.note ||
                                                        "Chưa có ghi chú"
                                                    }}
                                                </div>
                                            </div>
                                        </div>

                                        <!-- ===== TAB: Địa chỉ nhận hàng ===== -->
                                        <div
                                            v-if="
                                                getActiveTab(customer.id) ===
                                                'address'
                                            "
                                        >
                                            <div class="text-[13.5px]">
                                                <div
                                                    class="bg-gray-50 rounded border border-gray-200 p-4 mb-4"
                                                    v-if="
                                                        buildFullAddress(
                                                            customer,
                                                        )
                                                    "
                                                >
                                                    <div
                                                        class="flex items-center gap-2 mb-2"
                                                    >
                                                        <svg
                                                            class="w-4 h-4 text-blue-500"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            viewBox="0 0 24 24"
                                                        >
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"
                                                            ></path>
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"
                                                            ></path>
                                                        </svg>
                                                        <span
                                                            class="font-bold text-gray-700"
                                                            >Địa chỉ mặc
                                                            định</span
                                                        >
                                                        <span
                                                            class="text-xs bg-blue-100 text-blue-600 px-2 py-0.5 rounded"
                                                            >Mặc định</span
                                                        >
                                                    </div>
                                                    <div
                                                        class="pl-6 text-gray-600 space-y-1"
                                                    >
                                                        <div>
                                                            {{
                                                                customer.name
                                                            }}
                                                            -
                                                            {{ customer.phone }}
                                                        </div>
                                                        <div>
                                                            {{
                                                                buildFullAddress(
                                                                    customer,
                                                                )
                                                            }}
                                                        </div>
                                                    </div>
                                                </div>
                                                <div
                                                    v-else
                                                    class="text-center py-12 text-gray-400"
                                                >
                                                    <svg
                                                        class="w-12 h-12 mx-auto mb-3 text-gray-300"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        viewBox="0 0 24 24"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"
                                                        ></path>
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"
                                                        ></path>
                                                    </svg>
                                                    Chưa có địa chỉ nhận hàng
                                                </div>
                                            </div>
                                        </div>

                                        <!-- ===== TAB: Lịch sử bán/trả hàng ===== -->
                                        <div
                                            v-if="
                                                getActiveTab(customer.id) ===
                                                'history'
                                            "
                                        >
                                            <div
                                                v-if="tabLoading[customer.id]"
                                                class="text-center py-8 text-gray-400"
                                            >
                                                Đang tải...
                                            </div>
                                            <div
                                                v-else-if="
                                                    salesHistoryData[
                                                        customer.id
                                                    ]
                                                "
                                            >
                                                <div
                                                    v-if="
                                                        getCustomerSalesReturnEntries(
                                                            customer.id,
                                                        ).length === 0
                                                    "
                                                    class="text-center py-12 text-gray-400"
                                                >
                                                    <svg
                                                        class="w-12 h-12 mx-auto mb-3 text-gray-300"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        viewBox="0 0 24 24"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                                        ></path>
                                                    </svg>
                                                    Khách hàng chưa có giao dịch
                                                    nào
                                                </div>
                                                <table
                                                    v-else
                                                    class="w-full text-[13px]"
                                                >
                                                    <thead
                                                        class="bg-gray-50 text-gray-600 font-semibold"
                                                    >
                                                        <tr>
                                                            <th
                                                                class="px-3 py-2 text-left"
                                                            >
                                                                Mã chứng từ
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-left"
                                                            >
                                                                Loại
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-left"
                                                            >
                                                                Thời gian
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-right"
                                                            >
                                                                Giá trị
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-left"
                                                            >
                                                                Trạng thái
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody
                                                        class="divide-y divide-gray-100"
                                                    >
                                                        <tr
                                                            v-for="entry in getCustomerSalesReturnEntries(
                                                                customer.id,
                                                            )"
                                                            :key="entry._entryKey"
                                                            class="hover:bg-blue-50/30"
                                                        >
                                                            <td
                                                                class="px-3 py-2 text-blue-600 font-medium"
                                                                :class="
                                                                    entry._entryType ===
                                                                    'invoice'
                                                                        ? 'cursor-pointer hover:underline'
                                                                        : ''
                                                                "
                                                                @click="
                                                                    entry._entryType ===
                                                                        'invoice' &&
                                                                    showInvoiceDetail(
                                                                        entry.id,
                                                                    )
                                                                "
                                                            >
                                                                {{
                                                                    entry._entryCode
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2"
                                                            >
                                                                <span
                                                                    v-if="
                                                                        entry._entryType ===
                                                                        'invoice'
                                                                    "
                                                                    class="bg-green-100 text-green-700 px-2 py-0.5 rounded text-xs"
                                                                    >Bán
                                                                    hàng</span
                                                                >
                                                                <span
                                                                    v-else
                                                                    class="bg-red-100 text-red-700 px-2 py-0.5 rounded text-xs"
                                                                    >Trả
                                                                    hàng</span
                                                                >
                                                            </td>
                                                            <td
                                                                class="px-3 py-2"
                                                            >
                                                                {{
                                                                    formatDate(
                                                                        entry._entryCreatedAt,
                                                                    )
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2 text-right font-medium"
                                                                :class="
                                                                    entry._entryAmount <
                                                                    0
                                                                        ? 'text-red-500'
                                                                        : ''
                                                                "
                                                            >
                                                                <template
                                                                    v-if="
                                                                        entry._entryAmount <
                                                                        0
                                                                    "
                                                                >
                                                                    -{{
                                                                        formatCurrency(
                                                                            Math.abs(
                                                                                entry._entryAmount,
                                                                            ),
                                                                        )
                                                                    }}
                                                                </template>
                                                                <template
                                                                    v-else
                                                                >
                                                                    {{
                                                                        formatCurrency(
                                                                            entry._entryAmount,
                                                                        )
                                                                    }}
                                                                </template>
                                                            </td>
                                                            <td
                                                                class="px-3 py-2"
                                                            >
                                                                {{
                                                                    entry._entryStatus
                                                                }}
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>

                                        <!-- ===== TAB: Nợ cần thu từ khách ===== -->
                                        <div
                                            v-if="
                                                getActiveTab(customer.id) ===
                                                'debt'
                                            "
                                        >
                                            <div
                                                v-if="tabLoading[customer.id]"
                                                class="text-center py-8 text-gray-400"
                                            >
                                                Đang tải...
                                            </div>
                                            <div
                                                v-else-if="
                                                    debtHistoryData[customer.id]
                                                "
                                            >


                                                <!-- Filter dropdown -->
                                                <div
                                                    class="flex items-center justify-end mb-3"
                                                >
                                                    <select
                                                        class="border border-gray-300 rounded px-3 py-1.5 text-[13px] text-gray-600 focus:outline-none focus:border-blue-400"
                                                    >
                                                        <option>
                                                            Tất cả giao dịch
                                                        </option>
                                                        <option>
                                                            Bán hàng
                                                        </option>
                                                        <option>
                                                            Thanh toán
                                                        </option>
                                                    </select>
                                                </div>

                                                <div
                                                    v-if="
                                                        debtHistoryData[
                                                            customer.id
                                                        ].entries.length === 0
                                                    "
                                                    class="text-center py-12 text-gray-400"
                                                >
                                                    <svg
                                                        class="w-12 h-12 mx-auto mb-3 text-gray-300"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        viewBox="0 0 24 24"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                                        ></path>
                                                    </svg>
                                                    Chưa có lịch sử công nợ
                                                </div>
                                                <table
                                                    v-else
                                                    class="w-full text-[13px]"
                                                >
                                                    <thead
                                                        class="bg-gray-50 text-gray-600 font-semibold"
                                                    >
                                                        <tr>
                                                            <th
                                                                class="px-3 py-2 text-left"
                                                            >
                                                                Mã phiếu
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-left"
                                                            >
                                                                Thời gian
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-left"
                                                            >
                                                                Loại
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-right"
                                                            >
                                                                Giá trị
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-right"
                                                            >
                                                                Công nợ
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody
                                                        class="divide-y divide-gray-100"
                                                    >
                                                        <tr
                                                            v-for="entry in debtHistoryData[
                                                                customer.id
                                                            ].entries"
                                                            :key="entry.id"
                                                            class="hover:bg-blue-50/30"
                                                        >
                                                            <td
                                                                class="px-3 py-2 font-medium text-blue-600"
                                                            >
                                                                <span
                                                                    v-if="entry.code && entry.detail_available !== false"
                                                                    class="cursor-pointer hover:underline text-blue-600 font-medium"
                                                                    @click="openDebtVoucherDetail(entry, customer.id)"
                                                                >
                                                                    {{ entry.code }}
                                                                </span>
                                                                <span
                                                                    v-else-if="entry.code"
                                                                    class="text-gray-700 font-medium"
                                                                >
                                                                    {{ entry.code }}
                                                                </span>
                                                                <span v-else>
                                                                    -
                                                                </span>
                                                                <button
                                                                    v-if="entry.can_cancel"
                                                                    @click.stop="cancelPaymentDiscount(customer.id, entry.payment_discount_id)"
                                                                    class="ml-2 text-xs text-red-600 hover:text-red-800 font-semibold underline"
                                                                >
                                                                    Hủy
                                                                </button>
                                                            </td>
                                                            <td
                                                                class="px-3 py-2"
                                                            >
                                                                {{
                                                                    formatDateTime(
                                                                        entry.created_at,
                                                                    )
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2"
                                                            >
                                                                <span>{{ entry.type }}</span>
                                                                <span
                                                                    v-if="entry.is_virtual_payment"
                                                                    class="ml-1 inline-block text-[10px] font-semibold bg-green-50 text-green-700 border border-green-200 px-1.5 py-0.5 rounded"
                                                                    title="Thanh toán trực tiếp khi tạo hóa đơn"
                                                                >Thanh toán HĐ</span>
                                                                <span
                                                                    v-else-if="entry.source === 'ledger'"
                                                                    class="ml-1 inline-block text-[10px] font-semibold bg-blue-50 text-blue-700 border border-blue-200 px-1.5 py-0.5 rounded"
                                                                    title="Ghi nhận qua ledger customer_debts"
                                                                >Ledger</span>
                                                                <span
                                                                    v-else-if="entry.source === 'legacy'"
                                                                    class="ml-1 inline-block text-[10px] font-semibold bg-gray-100 text-gray-600 border border-gray-200 px-1.5 py-0.5 rounded"
                                                                    title="Dựng từ chứng từ cũ (trước ledger)"
                                                                >Chứng từ cũ</span>
                                                            </td>
                                                            <td
                                                                class="px-3 py-2 text-right font-medium"
                                                                :class="
                                                                    entry.amount >
                                                                    0
                                                                        ? 'text-red-600'
                                                                        : entry.amount <
                                                                            0
                                                                          ? 'text-green-600'
                                                                          : 'text-gray-500'
                                                                "
                                                            >
                                                                {{
                                                                    (entry.amount > 0 ? '+' : '') +
                                                                    formatCurrency(
                                                                        entry.amount,
                                                                    )
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2 text-right font-medium"
                                                                :class="
                                                                    entry.balance >
                                                                    0
                                                                        ? 'text-red-600'
                                                                        : entry.balance <
                                                                            0
                                                                          ? 'text-green-600'
                                                                          : 'text-gray-500'
                                                                "
                                                            >
                                                                {{
                                                                    formatCurrency(
                                                                        entry.balance,
                                                                    )
                                                                }}
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>

                                                <!-- Bottom actions -->
                                                <div
                                                    class="flex items-center justify-between mt-4 border-t border-gray-200 pt-3"
                                                >
                                                    <div
                                                        class="flex gap-3 text-[13px]"
                                                    >
                                                        <button
                                                            @click="openCustomerDebtExportModal(customer)"
                                                            class="text-blue-600 hover:text-blue-800 flex items-center gap-1 font-medium"
                                                        >
                                                            <svg
                                                                class="w-4 h-4"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                viewBox="0 0 24 24"
                                                            >
                                                                <path
                                                                    stroke-linecap="round"
                                                                    stroke-linejoin="round"
                                                                    stroke-width="2"
                                                                    d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                                                ></path>
                                                            </svg>
                                                            Xuất file công nợ
                                                        </button>
                                                        <a
                                                            :href="`/customers/${customer.id}/export-sales`"
                                                            target="_blank"
                                                            rel="noopener"
                                                            class="text-blue-600 hover:text-blue-800 flex items-center gap-1 font-medium"
                                                        >
                                                            <svg
                                                                class="w-4 h-4"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                viewBox="0 0 24 24"
                                                            >
                                                                <path
                                                                    stroke-linecap="round"
                                                                    stroke-linejoin="round"
                                                                    stroke-width="2"
                                                                    d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                                                ></path>
                                                            </svg>
                                                            Xuất file
                                                        </a>
                                                    </div>
                                                    <div
                                                        class="flex gap-2 text-[13px]"
                                                    >
                                                        <button
                                                            @click="
                                                                openDebtModal(
                                                                    customer,
                                                                    'adjust',
                                                                )
                                                            "
                                                            class="bg-blue-600 text-white rounded px-3 py-1.5 font-semibold hover:bg-blue-700 flex items-center gap-1"
                                                        >
                                                            <svg
                                                                class="w-4 h-4"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                viewBox="0 0 24 24"
                                                            >
                                                                <path
                                                                    stroke-linecap="round"
                                                                    stroke-linejoin="round"
                                                                    stroke-width="2"
                                                                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                                                                ></path>
                                                            </svg>
                                                            Điều chỉnh
                                                        </button>
                                                        <button
                                                            @click="
                                                                openDebtModal(
                                                                    customer,
                                                                    'payment',
                                                                )
                                                            "
                                                            class="bg-white border border-gray-300 text-gray-700 rounded px-3 py-1.5 font-semibold hover:bg-gray-50 flex items-center gap-1"
                                                        >
                                                            <svg
                                                                class="w-4 h-4"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                viewBox="0 0 24 24"
                                                            >
                                                                <path
                                                                    stroke-linecap="round"
                                                                    stroke-linejoin="round"
                                                                    stroke-width="2"
                                                                    d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                                                ></path>
                                                            </svg>
                                                            Thanh toán
                                                        </button>
                                                        <button
                                                            @click="openPaymentDiscountModal(customer)"
                                                            class="bg-white border border-gray-300 text-gray-700 rounded px-3 py-1.5 font-semibold hover:bg-gray-50 flex items-center gap-1"
                                                        >
                                                            Chiết khấu thanh toán
                                                        </button>
                                                        <button
                                                            class="bg-white border border-gray-300 text-gray-700 rounded px-3 py-1.5 font-semibold hover:bg-gray-50 flex items-center gap-1"
                                                        >
                                                            <svg
                                                                class="w-4 h-4"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                viewBox="0 0 24 24"
                                                            >
                                                                <path
                                                                    stroke-linecap="round"
                                                                    stroke-linejoin="round"
                                                                    stroke-width="2"
                                                                    d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"
                                                                ></path>
                                                            </svg>
                                                            Tạo QR
                                                        </button>
                                                    </div>
                                                </div>

                                                <!-- Offset history section removed - CB entries shown inline in debt table (KiotViet style) -->
                                            </div>
                                        </div>

                                        <!-- ===== TAB: Lịch sử tích điểm ===== -->
                                        <div
                                            v-if="
                                                getActiveTab(customer.id) ===
                                                'points'
                                            "
                                        >
                                            <div
                                                class="text-center py-12 text-gray-400"
                                            >
                                                <svg
                                                    class="w-12 h-12 mx-auto mb-3 text-gray-300"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    viewBox="0 0 24 24"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"
                                                    ></path>
                                                </svg>
                                                Chưa thiết lập chương trình tích
                                                điểm
                                            </div>
                                        </div>

                                        <!-- Footer Actions -->
                                        <div
                                            class="flex items-center justify-between mt-4"
                                        >
                                            <button
                                                @click.stop="
                                                    deleteCustomer(customer)
                                                "
                                                class="text-gray-600 bg-white border border-gray-300 rounded px-3 py-1.5 text-[13.5px] font-semibold hover:bg-gray-50 flex items-center gap-1 shadow-sm"
                                            >
                                                <svg
                                                    class="w-4 h-4 text-gray-500"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    viewBox="0 0 24 24"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                                    ></path></svg
                                                >Xóa
                                            </button>
                                            <div
                                                class="flex gap-2 text-[13.5px]"
                                            >
                                                <button
                                                    @click.stop="openEditModal(customer)"
                                                    class="text-white bg-blue-600 rounded px-4 py-1.5 font-bold hover:bg-blue-700 flex items-center gap-1 shadow-sm"
                                                >
                                                    <svg
                                                        class="w-4 h-4"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        viewBox="0 0 24 24"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                                                        ></path></svg
                                                    >Chỉnh sửa
                                                </button>
                                                <button
                                                    v-if="!customer.is_supplier"
                                                    @click.stop="openMergeModal(customer)"
                                                    class="text-white bg-orange-500 rounded px-4 py-1.5 font-bold hover:bg-orange-600 flex items-center gap-1 shadow-sm"
                                                >
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                                    </svg>Gộp NCC
                                                </button>
                                                <button
                                                    @click.stop="
                                                        toggleStatus(customer)
                                                    "
                                                    class="text-gray-700 bg-white border border-gray-300 rounded px-4 py-1.5 font-bold hover:bg-gray-50 shadow-sm"
                                                >
                                                    {{
                                                        customer.status ===
                                                        "active"
                                                            ? "Ngừng hoạt động"
                                                            : "Kích hoạt lại"
                                                    }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Footer Pagination -->
            <div
                class="flex items-center justify-between px-4 py-2 border-t border-gray-200 bg-white text-sm"
            >
                <div class="text-gray-600">
                    Hiển thị từ
                    <span class="font-bold">{{ customers.from || 0 }}</span> đến
                    <span class="font-bold">{{ customers.to || 0 }}</span> trong
                    tổng số
                    <span class="font-bold">{{ customers.total || 0 }}</span>
                    đối tác
                </div>
                <!-- Pagination -->
                <div
                    class="flex gap-1"
                    v-if="customers.links && customers.links.length > 3"
                >
                    <template
                        v-for="(link, index) in customers.links"
                        :key="index"
                    >
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="px-2.5 py-1 text-sm border rounded"
                            :class="
                                link.active
                                    ? 'bg-blue-600 text-white border-blue-600'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300'
                            "
                            v-html="link.label"
                        ></Link>
                        <span
                            v-else
                            class="px-2.5 py-1 text-sm border rounded bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed"
                            v-html="link.label"
                        ></span>
                    </template>
                </div>
            </div>
        </div>

        <!-- CREATE CUSTOMER MODAL -->
        <div
            v-if="showCreateModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 pt-10 pb-10"
        >
            <div
                class="bg-white rounded shadow-xl w-full max-w-4xl max-h-full overflow-hidden flex flex-col relative text-[13px] text-gray-800"
            >
                <div
                    class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gray-50"
                >
                    <h2 class="text-xl font-bold text-gray-800">
                        Tạo khách hàng
                    </h2>
                    <button
                        @click="showCreateModal = false"
                        class="text-gray-400 hover:text-gray-600"
                    >
                        <svg
                            class="w-6 h-6"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            ></path>
                        </svg>
                    </button>
                </div>

                <div
                    class="flex-1 overflow-y-auto px-6 py-6 custom-scrollbar text-[13.5px]"
                >
                    <form @submit.prevent="submit" class="space-y-6">
                        <!-- Basic Info Master -->
                        <div
                            class="flex gap-8 items-start pb-4 border-b border-gray-100"
                        >
                            <div
                                class="flex-1 grid grid-cols-2 gap-x-6 gap-y-4"
                            >
                                <!-- Row 1 -->
                                <div>
                                    <label class="block font-semibold mb-1"
                                        >Tên khách hàng
                                        <span class="text-red-500"
                                            >*</span
                                        ></label
                                    >
                                    <input
                                        v-model="form.name"
                                        type="text"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
                                        placeholder="Bắt buộc"
                                        required
                                    />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1"
                                        >Mã khách hàng</label
                                    >
                                    <input
                                        v-model="form.code"
                                        type="text"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
                                        placeholder="Tự động"
                                    />
                                </div>

                                <!-- Row 2 -->
                                <div class="flex gap-2">
                                    <div class="w-1/2">
                                        <label class="block font-semibold mb-1"
                                            >Điện thoại</label
                                        >
                                        <input
                                            v-model="form.phone"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        />
                                    </div>
                                    <div class="w-1/2">
                                        <label class="block font-semibold mb-1"
                                            >Điện thoại 2</label
                                        >
                                        <input
                                            v-model="form.phone2"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        />
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <div class="w-1/2">
                                        <label class="block font-semibold mb-1"
                                            >Sinh nhật</label
                                        >
                                        <input
                                            v-model="form.birthday"
                                            type="date"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        />
                                    </div>
                                    <div class="w-1/2">
                                        <label class="block font-semibold mb-1"
                                            >Giới tính</label
                                        >
                                        <select
                                            v-model="form.gender"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        >
                                            <option value="none">
                                                Chọn giới tính
                                            </option>
                                            <option value="male">Nam</option>
                                            <option value="female">Nữ</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Row 3 -->
                                <div>
                                    <label class="block font-semibold mb-1"
                                        >Email</label
                                    >
                                    <input
                                        v-model="form.email"
                                        type="email"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        placeholder="email@gmail.com"
                                    />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1"
                                        >Facebook</label
                                    >
                                    <input
                                        v-model="form.facebook"
                                        type="text"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        placeholder="facebook.com/username"
                                    />
                                </div>
                            </div>

                            <!-- Avatar Circle Upload -->
                            <div class="w-32 flex flex-col items-center mt-2">
                                <div
                                    class="w-28 h-28 rounded-full border border-dashed border-gray-400 bg-gray-50 flex items-center justify-center flex-col text-gray-500 cursor-pointer hover:bg-gray-100 transition"
                                >
                                    <div
                                        class="bg-white border shadow-sm px-3 py-1 rounded text-[12px] font-bold text-gray-700"
                                    >
                                        Thêm ảnh
                                    </div>
                                </div>
                                <p
                                    class="text-[11px] text-gray-400 text-center mt-2"
                                >
                                    Ảnh không được vượt quá 2MB
                                </p>
                            </div>
                        </div>

                        <!-- Accordion 1: Địa chỉ -->
                        <div class="border border-gray-200 rounded">
                            <div
                                class="px-4 py-3 bg-gray-50 flex justify-between items-center cursor-pointer"
                            >
                                <h3 class="font-bold text-gray-800">Địa chỉ</h3>
                                <svg
                                    class="w-4 h-4 text-gray-500"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M5 15l7-7 7 7"
                                    ></path>
                                </svg>
                            </div>
                            <div class="p-4 grid grid-cols-2 gap-x-6 gap-y-4">
                                <div class="col-span-2">
                                    <label class="block font-semibold mb-1"
                                        >Địa chỉ</label
                                    >
                                    <input
                                        v-model="form.address"
                                        type="text"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        placeholder="Nhập địa chỉ"
                                    />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1"
                                        >Khu vực</label
                                    >
                                    <input
                                        v-model="form.city"
                                        type="text"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        placeholder="Chọn Tỉnh/Thành phố"
                                    />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1"
                                        >Phường/Xã</label
                                    >
                                    <input
                                        v-model="form.ward"
                                        type="text"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        placeholder="Chọn Phường/Xã"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Accordion 2: Nhóm khách hàng, ghi chú -->
                        <div class="border border-gray-200 rounded">
                            <div
                                class="px-4 py-3 bg-gray-50 flex justify-between items-center cursor-pointer"
                            >
                                <h3 class="font-bold text-gray-800">
                                    Nhóm khách hàng, ghi chú
                                </h3>
                                <svg
                                    class="w-4 h-4 text-gray-500"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M5 15l7-7 7 7"
                                    ></path>
                                </svg>
                            </div>
                            <div class="p-4 space-y-4">
                                <div>
                                    <label class="block font-semibold mb-1"
                                        >Nhóm khách hàng</label
                                    >
                                    <CustomerGroupCombobox
                                        v-model="form.customer_group"
                                        :groups="mergedCustomerGroups"
                                        placeholder="Chọn nhóm"
                                        @create="createCustomerGroupAndSelect"
                                    />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1"
                                        >Ghi chú</label
                                    >
                                    <textarea
                                        v-model="form.note"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none resize-none h-16"
                                        placeholder="Nhập ghi chú"
                                    ></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Accordion 3: Thông tin xuất hóa đơn -->
                        <div class="border border-gray-200 rounded">
                            <div
                                class="px-4 py-3 bg-gray-50 flex justify-between items-center cursor-pointer"
                            >
                                <h3 class="font-bold text-gray-800">
                                    Thông tin xuất hóa đơn
                                </h3>
                                <svg
                                    class="w-4 h-4 text-gray-500"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M5 15l7-7 7 7"
                                    ></path>
                                </svg>
                            </div>
                            <div class="p-4">
                                <div class="flex items-center gap-6 mb-4">
                                    <label class="font-semibold text-gray-800"
                                        >Loại khách hàng</label
                                    >
                                    <label
                                        class="flex items-center gap-2 cursor-pointer"
                                    >
                                        <input
                                            type="radio"
                                            v-model="form.type"
                                            value="individual"
                                            class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                                        />
                                        Cá nhân
                                    </label>
                                    <label
                                        class="flex items-center gap-2 cursor-pointer"
                                    >
                                        <input
                                            type="radio"
                                            v-model="form.type"
                                            value="company"
                                            class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                                        />
                                        Tổ chức/ Hộ kinh doanh
                                    </label>
                                </div>
                                <div class="grid grid-cols-2 gap-x-6 gap-y-4">
                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Tên người mua</label
                                        >
                                        <input
                                            v-model="form.invoice_name"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="Nhập tên người mua"
                                        />
                                    </div>
                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Mã số thuế</label
                                        >
                                        <input
                                            v-model="form.tax_code"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="Nhập mã số thuế"
                                        />
                                    </div>

                                    <div class="col-span-2">
                                        <label class="block font-semibold mb-1"
                                            >Địa chỉ</label
                                        >
                                        <input
                                            v-model="form.invoice_address"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="Nhập địa chỉ"
                                        />
                                    </div>

                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Tỉnh/Thành phố</label
                                        >
                                        <input
                                            v-model="form.invoice_city"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="Tìm Tỉnh/Thành phố"
                                        />
                                    </div>
                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Phường/Xã</label
                                        >
                                        <input
                                            v-model="form.invoice_ward"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="Tìm Phường/Xã"
                                        />
                                    </div>

                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Số CCCD/CMND</label
                                        >
                                        <input
                                            v-model="form.id_card"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="Nhập số CCCD/CMND"
                                        />
                                    </div>
                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Số hộ chiếu</label
                                        >
                                        <input
                                            v-model="form.passport"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="Nhập số hộ chiếu"
                                        />
                                    </div>

                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Email</label
                                        >
                                        <input
                                            v-model="form.invoice_email"
                                            type="email"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="email@gmail.com"
                                        />
                                    </div>
                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Số điện thoại</label
                                        >
                                        <input
                                            v-model="form.invoice_phone"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="Nhập số điện thoại"
                                        />
                                    </div>

                                    <div class="flex gap-2 col-span-2">
                                        <div class="w-1/2">
                                            <label
                                                class="block font-semibold mb-1"
                                                >Ngân hàng</label
                                            >
                                            <select
                                                v-model="form.bank_name"
                                                class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            >
                                                <option value="">
                                                    Chọn ngân hàng
                                                </option>
                                                <option value="vcb">
                                                    Vietcombank
                                                </option>
                                                <option value="tcb">
                                                    Techcombank
                                                </option>
                                            </select>
                                        </div>
                                        <div class="w-1/2">
                                            <label
                                                class="block font-semibold mb-1"
                                                >Số tài khoản ngân hàng</label
                                            >
                                            <input
                                                v-model="form.bank_account"
                                                type="text"
                                                class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                                placeholder="Nhập số tài khoản ngân hàng"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Switch Supplier -->
                        <div
                            class="bg-gray-50 border border-gray-200 rounded px-4 py-4 flex items-center justify-between"
                        >
                            <div>
                                <h3 class="font-bold text-[14px] text-gray-800">
                                    Khách hàng là nhà cung cấp
                                </h3>
                                <p class="text-[12px] text-gray-500 mt-0.5">
                                    Công nợ của khách hàng và nhà cung cấp sẽ
                                    được gộp với nhau
                                </p>
                            </div>
                            <label
                                class="relative inline-flex items-center cursor-pointer"
                                @click.prevent="onToggleIsSupplier"
                            >
                                <input
                                    type="checkbox"
                                    :checked="form.is_supplier"
                                    class="sr-only peer"
                                />
                                <div
                                    class="w-11 h-6 bg-gray-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"
                                ></div>
                            </label>
                        </div>
                    </form>
                </div>

                <div
                    class="px-6 py-4 border-t border-gray-200 bg-white flex justify-end gap-3 rounded-b"
                >
                    <button
                        @click="showCreateModal = false"
                        class="px-6 py-2 border border-gray-300 rounded text-gray-700 bg-white font-bold hover:bg-gray-50 transition shadow-sm"
                    >
                        Bỏ qua
                    </button>
                    <button
                        @click="submit"
                        class="px-8 py-2 border border-transparent rounded text-white bg-blue-600 font-bold hover:bg-blue-700 transition shadow-sm"
                        :class="{
                            'opacity-50 cursor-not-allowed': form.processing,
                        }"
                    >
                        Lưu
                    </button>
                </div>
            </div>
        </div>

        <!-- EDIT Customer Modal -->
        <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 pt-10 pb-10">
            <div class="bg-white rounded shadow-xl w-full max-w-3xl max-h-full overflow-hidden flex flex-col relative text-[13px] text-gray-800">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h2 class="text-xl font-bold text-gray-800">Chỉnh sửa khách hàng</h2>
                    <button @click="showEditModal = false" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-6 custom-scrollbar text-[13.5px]">
                    <form @submit.prevent="submitEdit" class="space-y-5">
                        <div class="grid grid-cols-2 gap-x-6 gap-y-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Tên khách hàng <span class="text-red-500">*</span></label>
                                <input v-model="editForm.name" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Mã khách hàng</label>
                                <input v-model="editForm.code" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm bg-gray-50" disabled />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Điện thoại</label>
                                <input v-model="editForm.phone" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Điện thoại 2</label>
                                <input v-model="editForm.phone2" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Email</label>
                                <input v-model="editForm.email" type="email" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Facebook</label>
                                <input v-model="editForm.facebook" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Sinh nhật</label>
                                <input v-model="editForm.birthday" type="date" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Giới tính</label>
                                <select v-model="editForm.gender" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                                    <option value="none">Chưa có</option>
                                    <option value="male">Nam</option>
                                    <option value="female">Nữ</option>
                                </select>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Địa chỉ</label>
                                <input v-model="editForm.address" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Tỉnh/Thành phố</label>
                                <input v-model="editForm.city" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Quận/Huyện</label>
                                <input v-model="editForm.district" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Phường/Xã</label>
                                <input v-model="editForm.ward" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Nhóm khách hàng</label>
                                <CustomerGroupCombobox
                                    v-model="editForm.customer_group"
                                    :groups="mergedCustomerGroups"
                                    placeholder="Chọn nhóm"
                                    @create="createCustomerGroupAndSelectForEdit"
                                />
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Ghi chú</label>
                            <textarea v-model="editForm.note" rows="2" class="w-full border border-gray-300 rounded px-3 py-2 text-sm"></textarea>
                        </div>
                        <details class="border border-gray-200 rounded p-3">
                            <summary class="font-semibold text-sm text-gray-700 cursor-pointer">Thông tin xuất hóa đơn</summary>
                            <div class="grid grid-cols-2 gap-x-6 gap-y-4 mt-3">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1">Loại</label>
                                    <select v-model="editForm.type" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                                        <option value="individual">Cá nhân</option>
                                        <option value="company">Công ty</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1">Mã số thuế</label>
                                    <input v-model="editForm.tax_code" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1">Tên xuất hóa đơn</label>
                                    <input v-model="editForm.invoice_name" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1">Địa chỉ hóa đơn</label>
                                    <input v-model="editForm.invoice_address" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                                </div>
                            </div>
                        </details>
                    </form>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 bg-white flex justify-end gap-3 rounded-b">
                    <button @click="showEditModal = false" class="px-6 py-2 border border-gray-300 rounded text-gray-700 bg-white font-bold hover:bg-gray-50 transition shadow-sm">Bỏ qua</button>
                    <button @click="submitEdit" :disabled="editForm.processing" class="px-8 py-2 border border-transparent rounded text-white bg-blue-600 font-bold hover:bg-blue-700 transition shadow-sm" :class="{ 'opacity-50 cursor-not-allowed': editForm.processing }">Lưu</button>
                </div>
            </div>
        </div>

        <!-- Debt Payment/Adjustment Modal -->
        <div
            v-if="debtModal.show"
            class="fixed inset-0 z-[60] flex items-center justify-center"
        >
            <div
                class="fixed inset-0 bg-black/40"
                @click="debtModal.show = false"
            ></div>
            <div class="bg-white rounded-lg shadow-xl w-[540px] max-h-[90vh] overflow-y-auto relative z-10">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800">
                        {{
                            debtModal.type === "payment"
                                ? "Thanh toán công nợ"
                                : "Điều chỉnh công nợ"
                        }}
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">
                        {{ debtModal.customerName }}
                    </p>
                </div>
                <div class="px-6 py-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Dư nợ hiện tại</label>
                        <div class="text-lg font-bold text-red-600">
                            {{ formatCurrency(debtModal.currentDebt) }}
                        </div>
                    </div>

                    <!-- Payment mode: show auto/manual toggle -->
                    <template v-if="debtModal.type === 'payment'">
                        <div class="flex gap-2 text-sm border-b border-gray-200 pb-2">
                            <button
                                @click="debtForm.mode = 'auto'"
                                :class="debtForm.mode === 'auto' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                                class="px-3 py-1.5 rounded font-medium transition-colors"
                            >Tự động phân bổ</button>
                            <button
                                @click="debtForm.mode = 'manual'"
                                :class="debtForm.mode === 'manual' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                                class="px-3 py-1.5 rounded font-medium transition-colors"
                            >Chỉ định từng HD</button>
                        </div>

                        <!-- AUTO mode -->
                        <div v-if="debtForm.mode === 'auto'">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền thu từ khách</label>
                            <MoneyInput v-model="debtForm.amount" placeholder="0" input-class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500" />
                            <p class="text-xs text-gray-400 mt-1">Hệ thống sẽ phân bổ vào hóa đơn cũ trước</p>
                        </div>

                        <!-- MANUAL mode -->
                        <div v-if="debtForm.mode === 'manual'">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Hóa đơn còn nợ</label>
                            <div v-if="loadingInvoices" class="text-sm text-gray-400 py-2">Đang tải...</div>
                            <div v-else-if="outstandingInvoices.length === 0" class="text-sm text-gray-400 py-2">Không có hóa đơn còn nợ</div>
                            <div v-else class="space-y-2 max-h-48 overflow-y-auto">
                                <div
                                    v-for="inv in outstandingInvoices"
                                    :key="inv.id"
                                    class="flex items-center justify-between bg-gray-50 rounded px-3 py-2 text-sm border border-gray-200"
                                >
                                    <div class="flex-1 min-w-0">
                                        <div class="font-semibold text-gray-800">{{ inv.code }}</div>
                                        <div class="text-xs text-gray-500">
                                            Tổng: {{ formatCurrency(inv.total) }} |
                                            Còn nợ: <span class="text-red-500 font-medium">{{ formatCurrency(inv.remaining) }}</span>
                                        </div>
                                    </div>
                                    <div class="ml-3 w-28">
                                        <MoneyInput
                                            v-model="inv.allocAmount"
                                            :min="0"
                                            placeholder="0"
                                            input-class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm text-right focus:ring-blue-500 focus:border-blue-500"
                                        />
                                    </div>
                                </div>
                            </div>
                            <div class="flex justify-between text-sm font-semibold mt-2 pt-2 border-t border-gray-200">
                                <span>Tổng thu:</span>
                                <span class="text-green-600">{{ formatCurrency(manualTotal()) }}</span>
                            </div>
                        </div>
                    </template>

                    <!-- Adjustment mode: nhập nợ cuối mong muốn -->
                    <template v-else>
                        <div class="mb-3 p-3 bg-gray-50 rounded text-sm">
                            <span class="text-gray-500">Nợ hiện tại:</span>
                            <span class="font-semibold ml-1" :class="debtModal.currentDebt < 0 ? 'text-red-500' : ''">
                                {{ formatCurrency(debtModal.currentDebt) }}
                            </span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nợ cuối mong muốn</label>
                            <MoneyInput v-model="debtForm.amount" placeholder="0" input-class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500" />
                        </div>
                    </template>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            {{ debtModal.type === 'payment' ? 'Ngày thanh toán' : 'Ngày điều chỉnh' }}
                        </label>
                        <DateTimePicker
                            v-model="debtForm.date"
                            placeholder="dd/MM/yyyy HH:mm"
                            input-class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
                        <textarea
                            v-model="debtForm.note"
                            rows="2"
                            class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Ghi chú (không bắt buộc)"
                        ></textarea>
                    </div>
                </div>
                <div
                    class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3"
                >
                    <button
                        @click="debtModal.show = false"
                        class="px-5 py-2 border border-gray-300 rounded text-gray-700 bg-white font-medium hover:bg-gray-50"
                    >
                        Bỏ qua
                    </button>
                    <button
                        @click="submitDebtModal"
                        class="px-5 py-2 rounded text-white font-medium"
                        :class="
                            debtModal.type === 'payment'
                                ? 'bg-green-600 hover:bg-green-700'
                                : 'bg-blue-600 hover:bg-blue-700'
                        "
                    >
                        {{
                            debtModal.type === "payment"
                                ? "Thanh toán"
                                : "Điều chỉnh"
                        }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Payment Discount Modal -->
        <div
            v-if="paymentDiscountModal.show"
            class="fixed inset-0 z-[60] flex items-center justify-center"
        >
            <div
                class="fixed inset-0 bg-black/40"
                @click="paymentDiscountModal.show = false"
            ></div>
            <div class="bg-white rounded-lg shadow-xl w-[580px] max-h-[90vh] overflow-y-auto relative z-10">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800">
                        Chiết khấu thanh toán
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">
                        {{ paymentDiscountModal.customer?.name }}
                    </p>
                </div>
                <div class="px-6 py-4 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Nợ phải thu hiện tại</label>
                            <div class="text-base font-bold text-red-600">
                                {{ formatCurrency(paymentDiscountModal.customer?.debt_amount) }}
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Nợ còn lại sau CK</label>
                            <div class="text-base font-bold text-gray-800">
                                {{ formatCurrency(Math.max(0, Number(paymentDiscountModal.customer?.debt_amount || 0) - Number(paymentDiscountForm.amount || 0))) }}
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Thời gian chiết khấu</label>
                            <DateTimePicker
                                v-model="paymentDiscountForm.discount_at"
                                placeholder="dd/MM/yyyy HH:mm"
                                input-class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Người thực hiện</label>
                            <select
                                v-model="paymentDiscountForm.performed_by"
                                class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
                            >
                                <option value="">Tài khoản hiện tại</option>
                                <option
                                    v-for="user in paymentDiscountModal.users"
                                    :key="user.id"
                                    :value="user.id"
                                >
                                    {{ user.name }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Số tiền chiết khấu</label>
                        <MoneyInput
                            v-model="paymentDiscountForm.amount"
                            placeholder="0"
                            input-class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500 font-semibold text-lg text-blue-600"
                        />
                    </div>

                    <div>
                        <label class="flex items-center gap-2 text-sm text-gray-700 font-medium">
                            <input
                                type="checkbox"
                                v-model="paymentDiscountForm.allocate_to_invoices"
                                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                            />
                            Phân bổ số tiền chiết khấu vào hóa đơn
                        </label>
                    </div>

                    <!-- Allocations table -->
                    <div v-if="paymentDiscountForm.allocate_to_invoices" class="space-y-2">
                        <label class="block text-xs font-medium text-gray-500">Danh sách hóa đơn còn nợ</label>
                        <div v-if="paymentDiscountModal.loadingInvoices" class="text-sm text-gray-400 py-2">Đang tải hóa đơn...</div>
                        <div v-else-if="paymentDiscountModal.invoices.length === 0" class="text-sm text-gray-400 py-2">
                            Không tìm thấy hóa đơn bán hàng còn phải thu để phân bổ.
                            Chiết khấu thanh toán khách hàng chỉ phân bổ vào hóa đơn bán hàng HD, không phân bổ vào PN/NCC.
                        </div>
                        <div v-else class="space-y-2 max-h-48 overflow-y-auto border border-gray-200 rounded p-2 bg-gray-50">
                            <div
                                v-for="inv in paymentDiscountModal.invoices"
                                :key="inv.id"
                                class="flex items-center justify-between bg-white rounded p-2 text-xs border border-gray-100 shadow-sm"
                            >
                                <div class="flex-1 min-w-0">
                                    <div class="font-bold text-gray-700">
                                        {{ inv.code }}
                                        <span
                                            v-if="inv.source === 'ledger_invoice'"
                                            class="ml-1 inline-block rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold text-blue-700 border border-blue-200"
                                        >
                                            Ledger
                                        </span>
                                    </div>
                                    <div class="text-[10px] text-gray-400">
                                        Tổng: {{ formatCurrency(inv.total) }} | Còn nợ: <span class="text-red-500 font-bold">{{ formatCurrency(inv.remaining) }}</span>
                                    </div>
                                </div>
                                <div class="ml-3 w-28">
                                    <MoneyInput
                                        v-model="inv.allocAmount"
                                        :min="0"
                                        placeholder="0"
                                        input-class="w-full border border-gray-300 rounded px-2 py-1 text-xs text-right focus:ring-blue-500 focus:border-blue-500"
                                    />
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-between text-xs font-semibold mt-2 pt-2 border-t border-gray-200">
                            <span>Chưa phân bổ:</span>
                            <span :class="Math.abs(unallocatedAmount) > 0.01 ? 'text-red-500' : 'text-green-600'">
                                {{ formatCurrency(unallocatedAmount) }}
                            </span>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Ghi chú</label>
                        <textarea
                            v-model="paymentDiscountForm.note"
                            rows="2"
                            class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Ghi chú chiết khấu..."
                        ></textarea>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3 bg-gray-50">
                    <button
                        @click="paymentDiscountModal.show = false"
                        class="px-4 py-2 border border-gray-300 rounded text-gray-700 bg-white font-medium hover:bg-gray-50 text-sm"
                    >
                        Bỏ qua
                    </button>
                    <button
                        @click="submitPaymentDiscount"
                        :disabled="paymentDiscountModal.submitting"
                        class="px-4 py-2 rounded text-white bg-blue-600 hover:bg-blue-700 font-medium text-sm flex items-center gap-1"
                    >
                        {{ paymentDiscountModal.submitting ? 'Đang tạo...' : 'Tạo phiếu' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- XÁC NHẬN GỘP KH + NCC (KiotViet style) -->
        <div v-if="supplierConfirm.show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800">Xác nhận gộp khách hàng và nhà cung cấp</h3>
                    <button @click="cancelSupplierToggle" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <div class="text-sm font-bold text-gray-700 mb-2">Thông tin trước khi gộp:</div>
                        <div class="text-sm text-gray-600 space-y-1">
                            <div>- Khách hàng <strong class="text-blue-600">{{ form.code || '(Mã tự động)' }}</strong>:</div>
                            <div class="flex items-center gap-2 ml-4">
                                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                Nợ hiện tại: 0 - Điểm: 0
                            </div>
                            <div class="mt-2">- Nhà cung cấp: <span class="text-gray-400">(tạo mới)</span></div>
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-bold text-gray-700 mb-2">Thông tin sau khi gộp:</div>
                        <div class="text-sm text-gray-600 space-y-1">
                            <div class="flex items-center gap-2 ml-4">
                                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                Nợ hiện tại: 0
                            </div>
                            <div class="flex items-center gap-2 ml-4">
                                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                Nhóm khách hàng: Có thể thay đổi do công nợ của khách hàng thay đổi
                            </div>
                        </div>
                    </div>

                    <div class="text-sm text-gray-500 border-t border-gray-200 pt-3">
                        Hệ thống sẽ gộp công nợ, giao dịch khách hàng và nhà cung cấp. Thông tin <strong>Tên, Điện thoại, Địa chỉ...</strong> của nhà cung cấp sẽ cập nhật theo khách hàng.
                    </div>
                    <div class="text-sm text-gray-600 font-medium">
                        Bạn có chắc chắn muốn thực hiện?
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                    <button @click="confirmSupplierToggle" class="px-5 py-2 rounded text-white font-medium bg-blue-600 hover:bg-blue-700">
                        Đồng ý
                    </button>
                    <button @click="cancelSupplierToggle" class="px-5 py-2 border border-gray-300 rounded text-gray-700 font-medium hover:bg-gray-50">
                        Bỏ qua
                    </button>
                </div>
            </div>
        </div>

        <!-- MERGE CONFIRMATION DIALOG -->
        <div v-if="mergeModal.show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800">Xác nhận gộp khách hàng và nhà cung cấp</h3>
                    <button @click="mergeModal.show = false" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <div class="px-6 py-4 space-y-4">
                    <!-- Source info -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="text-sm text-gray-500 mb-1">Khách hàng (nguồn gộp)</div>
                        <div class="font-bold text-gray-800">{{ mergeModal.source?.name }} <span class="text-gray-500 font-normal">{{ mergeModal.source?.code }}</span></div>
                        <div class="text-sm mt-1">Nợ hiện tại: <span class="font-bold text-blue-600">{{ formatCurrency(mergeModal.source?.debt_amount || 0) }}</span></div>
                    </div>

                    <!-- Search for target -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tìm nhà cung cấp để gộp vào</label>
                        <input
                            v-model="mergeModal.searchQuery"
                            @input="searchMergeTarget"
                            type="text"
                            class="w-full border border-gray-300 rounded px-3 py-2 focus:border-blue-500 outline-none text-sm"
                            placeholder="Nhập tên, SĐT hoặc mã NCC..."
                        />
                        <!-- Search results dropdown -->
                        <div v-if="mergeModal.searchResults.length > 0" class="border border-gray-200 rounded mt-1 max-h-40 overflow-y-auto bg-white shadow-lg">
                            <button
                                v-for="r in mergeModal.searchResults"
                                :key="r.id"
                                @click="selectMergeTarget(r)"
                                class="w-full text-left px-3 py-2 hover:bg-blue-50 flex justify-between items-center text-sm border-b last:border-b-0"
                            >
                                <div>
                                    <span class="font-medium">{{ r.name }}</span>
                                    <span class="text-gray-400 ml-2">{{ r.code }}</span>
                                    <span v-if="r.phone" class="text-gray-400 ml-2">{{ r.phone }}</span>
                                    <span v-if="r.is_customer && r.is_supplier" class="ml-2 text-xs bg-purple-100 text-purple-600 px-1.5 py-0.5 rounded">KH+NCC</span>
                                    <span v-else-if="r.is_supplier" class="ml-2 text-xs bg-green-100 text-green-600 px-1.5 py-0.5 rounded">NCC</span>
                                    <span v-else-if="r.is_customer" class="ml-2 text-xs bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded">KH</span>
                                </div>
                                <span class="text-gray-500 text-xs">Nợ NCC: {{ formatCurrency(r.supplier_debt_amount || 0) }}</span>
                            </button>
                        </div>
                        <div v-if="mergeModal.searching" class="text-sm text-gray-400 mt-1">Đang tìm...</div>
                    </div>

                    <!-- Selected target -->
                    <div v-if="mergeModal.selected" class="bg-green-50 border border-green-200 rounded-lg p-4">
                        <div class="text-sm text-gray-500 mb-1">Nhà cung cấp (đích gộp)</div>
                        <div class="font-bold text-gray-800">{{ mergeModal.selected.name }} <span class="text-gray-500 font-normal">{{ mergeModal.selected.code }}</span></div>
                        <div class="text-sm mt-1">Nợ NCC hiện tại: <span class="font-bold text-green-600">{{ formatCurrency(mergeModal.selected.supplier_debt_amount || 0) }}</span></div>
                    </div>

                    <!-- Preview after merge -->
                    <div v-if="mergeModal.selected" class="bg-gray-50 border border-gray-300 rounded-lg p-4">
                        <div class="text-sm font-bold text-gray-700 mb-2">Thông tin sau khi gộp</div>
                        <div class="grid grid-cols-2 gap-2 text-sm">
                            <div>Nợ cần thu (KH):</div>
                            <div class="font-bold text-right">{{ formatCurrency((mergeModal.selected.debt_amount || 0) + (mergeModal.source?.debt_amount || 0)) }}</div>
                            <div>Nợ cần trả (NCC):</div>
                            <div class="font-bold text-right">{{ formatCurrency((mergeModal.selected.supplier_debt_amount || 0) + (mergeModal.source?.supplier_debt_amount || 0)) }}</div>
                        </div>
                        <div class="text-xs text-gray-500 mt-2">Khách hàng <strong>{{ mergeModal.source?.name }}</strong> sẽ bị xóa, mọi giao dịch sẽ chuyển sang <strong>{{ mergeModal.selected.name }}</strong>.</div>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                    <button @click="mergeModal.show = false" class="px-5 py-2 border border-gray-300 rounded text-gray-700 font-medium hover:bg-gray-50">Bỏ qua</button>
                    <button
                        @click="submitMerge"
                        :disabled="!mergeModal.selected || mergeModal.submitting"
                        class="px-5 py-2 rounded text-white font-medium bg-orange-500 hover:bg-orange-600 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {{ mergeModal.submitting ? 'Đang gộp...' : 'Xác nhận gộp' }}
                    </button>
                </div>
            </div>
        </div>
        <!-- CẤN BẰNG CÔNG NỢ MODAL -->
        <div v-if="offsetModal.show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-purple-50">
                    <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                        <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                        Cấn bằng công nợ
                    </h3>
                    <button @click="offsetModal.show = false" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div class="text-sm text-gray-600">
                        Đối tác: <strong>{{ offsetModal.customerName }}</strong>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-center">
                            <div class="text-xs text-gray-500 mb-1">Nợ phải thu (KH nợ)</div>
                            <div class="text-lg font-bold text-blue-600">{{ formatCurrency(offsetModal.receivable) }}</div>
                        </div>
                        <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-center">
                            <div class="text-xs text-gray-500 mb-1">Nợ phải trả (Nợ NCC)</div>
                            <div class="text-lg font-bold text-red-600">{{ formatCurrency(offsetModal.payable) }}</div>
                        </div>
                    </div>
                    <div class="bg-purple-50 border border-purple-200 rounded-lg p-3 text-center">
                        <div class="text-xs text-gray-500 mb-1">Số tiền cấn bằng tối đa</div>
                        <div class="text-lg font-bold text-purple-700">{{ formatCurrency(offsetModal.maxOffset) }}</div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền cấn bằng</label>
                        <MoneyInput v-model="offsetForm.amount" :min="1" placeholder="Nhập số tiền cần bù trừ" input-class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-purple-500 focus:border-purple-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
                        <textarea v-model="offsetForm.note" rows="2"
                            class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-purple-500 focus:border-purple-500"
                            placeholder="Ghi chú (không bắt buộc)"></textarea>
                    </div>
                    <!-- Preview -->
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm">
                        <div class="font-bold text-gray-700 mb-2">Kết quả sau cấn bằng:</div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>Nợ phải thu còn:</div>
                            <div class="font-bold text-right text-blue-600">{{ formatCurrency(Math.max(0, offsetModal.receivable - (offsetForm.amount || 0))) }}</div>
                            <div>Nợ phải trả còn:</div>
                            <div class="font-bold text-right text-red-600">{{ formatCurrency(Math.max(0, offsetModal.payable - (offsetForm.amount || 0))) }}</div>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                    <button @click="offsetModal.show = false" class="px-5 py-2 border border-gray-300 rounded text-gray-700 font-medium hover:bg-gray-50">Bỏ qua</button>
                    <button @click="submitOffset" :disabled="offsetModal.submitting || !offsetForm.amount || offsetForm.amount <= 0 || offsetForm.amount > offsetModal.maxOffset"
                        class="px-5 py-2 rounded text-white font-medium bg-purple-600 hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed">
                        {{ offsetModal.submitting ? 'Đang xử lý...' : 'Xác nhận cấn bằng' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Invoice Detail Modal -->
        <div v-if="invoiceDetail.show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="invoiceDetail.show = false">
            <div class="bg-white rounded-lg shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
                <div v-if="invoiceDetail.loading" class="p-12 text-center text-gray-400">Đang tải...</div>
                <template v-else-if="invoiceDetail.data">
                    <!-- Header -->
                    <div class="flex items-center justify-between px-6 py-4 border-b">
                        <div>
                            <h3 class="text-lg font-bold text-gray-800">{{ invoiceDetail.data.code }}</h3>
                            <span class="inline-block mt-1 px-2 py-0.5 rounded text-xs font-semibold bg-green-100 text-green-700">{{ invoiceDetail.data.status }}</span>
                        </div>
                        <button @click="invoiceDetail.show = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    </div>
                    <!-- Meta info -->
                    <div class="px-6 py-3 grid grid-cols-2 gap-x-8 gap-y-2 text-sm border-b bg-gray-50">
                        <div><span class="text-gray-500">Người tạo:</span> <span class="font-medium">{{ invoiceDetail.data.created_by_name }}</span></div>
                        <div><span class="text-gray-500">Thời gian:</span> <span class="font-medium">{{ invoiceDetail.data.created_at }}</span></div>
                        <div><span class="text-gray-500">Khách hàng:</span> <span class="font-medium">{{ invoiceDetail.data.customer_name }}</span> <span class="text-gray-400">{{ invoiceDetail.data.customer_code }}</span></div>
                        <div v-if="invoiceDetail.data.payment_method"><span class="text-gray-500">Thanh toán:</span> <span class="font-medium">{{ invoiceDetail.data.payment_method }}</span></div>
                    </div>
                    <!-- Items table -->
                    <div class="px-6 py-4">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600 text-xs uppercase">
                                <tr>
                                    <th class="px-3 py-2 text-left">Mã hàng</th>
                                    <th class="px-3 py-2 text-left">Tên hàng</th>
                                    <th class="px-3 py-2 text-right">SL</th>
                                    <th class="px-3 py-2 text-right">Đơn giá</th>
                                    <th class="px-3 py-2 text-right">Giảm giá</th>
                                    <th class="px-3 py-2 text-right">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="(item, idx) in invoiceDetail.data.items" :key="idx" class="hover:bg-gray-50">
                                    <td class="px-3 py-2 text-blue-600 font-medium">{{ item.product_code }}</td>
                                    <td class="px-3 py-2">{{ item.product_name }}</td>
                                    <td class="px-3 py-2 text-right">{{ item.quantity }}</td>
                                    <td class="px-3 py-2 text-right">{{ formatCurrency(item.price) }}</td>
                                    <td class="px-3 py-2 text-right">{{ formatCurrency(item.discount) }}</td>
                                    <td class="px-3 py-2 text-right font-medium">{{ formatCurrency(item.subtotal) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- Totals -->
                    <div class="px-6 py-4 border-t bg-gray-50">
                        <div class="flex justify-end">
                            <div class="w-72 space-y-1 text-sm">
                                <div class="flex justify-between"><span class="text-gray-500">Số lượng mặt hàng:</span><span class="font-medium">{{ invoiceDetail.data.items.length }}</span></div>
                                <div class="flex justify-between"><span class="text-gray-500">Tổng tiền hàng:</span><span class="font-medium">{{ formatCurrency(invoiceDetail.data.subtotal) }}</span></div>
                                <div v-if="invoiceDetail.data.discount > 0" class="flex justify-between"><span class="text-gray-500">Giảm giá:</span><span class="font-medium text-red-500">-{{ formatCurrency(invoiceDetail.data.discount) }}</span></div>
                                <div v-if="invoiceDetail.data.delivery_fee > 0" class="flex justify-between"><span class="text-gray-500">Phí giao hàng:</span><span class="font-medium">{{ formatCurrency(invoiceDetail.data.delivery_fee) }}</span></div>
                                <div class="flex justify-between font-bold text-base border-t pt-1"><span>Tổng cộng:</span><span class="text-blue-600">{{ formatCurrency(invoiceDetail.data.total) }}</span></div>
                                <div class="flex justify-between"><span class="text-gray-500">Khách đã trả:</span><span class="font-medium">{{ formatCurrency(invoiceDetail.data.customer_paid) }}</span></div>
                                <div v-if="invoiceDetail.data.total - invoiceDetail.data.customer_paid > 0" class="flex justify-between"><span class="text-gray-500">Còn nợ:</span><span class="font-medium text-red-500">{{ formatCurrency(invoiceDetail.data.total - invoiceDetail.data.customer_paid) }}</span></div>
                            </div>
                        </div>
                    </div>
                    <!-- Note & actions -->
                    <div class="px-6 py-3 border-t flex items-center justify-between">
                        <div v-if="invoiceDetail.data.note" class="text-sm text-gray-500"><span class="font-medium">Ghi chú:</span> {{ invoiceDetail.data.note }}</div>
                        <div v-else></div>
                        <a :href="`/invoices/${invoiceDetail.data.id}/print`" target="_blank" class="px-4 py-2 bg-blue-600 text-white rounded text-sm font-medium hover:bg-blue-700">In hóa đơn</a>
                    </div>
                </template>
                <div v-else class="p-12 text-center text-gray-400">Không tìm thấy thông tin hóa đơn</div>
            </div>
        </div>

        <!-- CB Detail Modal (KiotViet style - Điều chỉnh) -->
        <div v-if="cbDetailModal.show" class="fixed inset-0 bg-black/50 z-[60] flex items-center justify-center" @click.self="cbDetailModal.show = false">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
                <div class="flex items-center justify-between px-6 py-4 border-b">
                    <h3 class="text-lg font-semibold">Điều chỉnh
                        <span class="text-gray-400 text-sm ml-1 cursor-help" title="Phiếu cấn bằng công nợ KH↔NCC">ⓘ</span>
                    </h3>
                    <button @click="cbDetailModal.show = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <div v-if="cbDetailModal.offset" class="px-6 py-4 space-y-4">
                    <div class="flex items-center">
                        <label class="w-40 text-sm text-gray-600">Mã</label>
                        <div class="font-medium">{{ cbDetailModal.offset.code }}</div>
                    </div>
                    <div class="flex items-center">
                        <label class="w-40 text-sm text-gray-600">Giá trị nợ điều chỉnh</label>
                        <div class="font-medium">{{ formatCurrency(cbDetailModal.offset.amount) }}</div>
                    </div>
                    <div class="flex items-center">
                        <label class="w-40 text-sm text-gray-600">Ngày điều chỉnh</label>
                        <div>{{ formatDateTime(cbDetailModal.offset.created_at) }}</div>
                    </div>
                    <div class="flex items-center">
                        <label class="w-40 text-sm text-gray-600">Người tạo</label>
                        <div>{{ cbDetailModal.offset.user_name || 'Admin' }}</div>
                    </div>
                    <div class="flex items-start">
                        <label class="w-40 text-sm text-gray-600 pt-1">Mô tả</label>
                        <div class="flex-1 text-sm text-gray-700 bg-gray-50 rounded p-2">
                            <span class="text-gray-400">✏</span>
                            {{ cbDetailModal.offset.note || `Cấn bằng công nợ KH↔NCC` }}
                        </div>
                    </div>
                </div>
                <div class="px-6 py-3 border-t flex items-center justify-between">
                    <button
                        v-if="cbDetailModal.offset?.status !== 'cancelled'"
                        @click="deleteCbOffset"
                        class="px-4 py-2 text-red-600 hover:bg-red-50 rounded text-sm font-medium border border-red-200"
                    >Xóa</button>
                    <div v-else class="text-sm text-gray-400 italic">Đã hủy</div>
                    <div class="flex gap-2">
                        <button @click="cbDetailModal.show = false" class="px-4 py-2 border rounded text-sm font-medium hover:bg-gray-50">Bỏ qua</button>
                    </div>
                </div>
            </div>
        </div>


        <!-- Unified Debt Voucher Detail Modal (Read-only) -->
        <div v-if="debtVoucherDetailModal.show" class="fixed inset-0 bg-black/50 z-[70] flex items-center justify-center" @click.self="debtVoucherDetailModal.show = false">
            <div class="bg-white rounded-lg shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-y-auto relative z-10">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between bg-gray-50/50 rounded-t-lg">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-bold text-gray-800">
                                {{ debtVoucherDetailModal.title }}
                            </h3>
                            <span v-if="debtVoucherDetailModal.data?.status" 
                                  class="px-2 py-0.5 rounded text-xs font-semibold"
                                  :class="{
                                      'bg-green-100 text-green-700': debtVoucherDetailModal.data.status === 'completed' || debtVoucherDetailModal.data.status === 'active' || debtVoucherDetailModal.data.status === 'Đã hoàn thành',
                                      'bg-red-100 text-red-700': debtVoucherDetailModal.data.status === 'cancelled' || debtVoucherDetailModal.data.status === 'Đã hủy',
                                      'bg-yellow-100 text-yellow-700': debtVoucherDetailModal.data.status === 'pending' || debtVoucherDetailModal.data.status === 'Chờ xử lý',
                                      'bg-blue-100 text-blue-700': !['completed', 'active', 'cancelled', 'pending', 'Đã hoàn thành', 'Đã hủy', 'Chờ xử lý'].includes(debtVoucherDetailModal.data.status)
                                  }">
                                {{ debtVoucherDetailModal.data.status_label || debtVoucherDetailModal.data.status }}
                            </span>
                        </div>
                        <p class="text-sm text-gray-500 font-medium mt-0.5">
                            Mã chứng từ: <span class="text-blue-600 font-bold">{{ debtVoucherDetailModal.code }}</span>
                        </p>
                    </div>
                    <button @click="debtVoucherDetailModal.show = false" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">
                        &times;
                    </button>
                </div>

                <!-- Content -->
                <div class="px-6 py-5 text-sm">
                    <div v-if="debtVoucherDetailModal.loading" class="text-sm text-gray-400 py-12 text-center flex flex-col items-center justify-center gap-2">
                        <span class="animate-spin text-2xl">⏳</span>
                        <span>Đang tải chi tiết...</span>
                    </div>

                    <div v-else-if="debtVoucherDetailModal.error" class="text-sm text-red-600 py-12 text-center font-medium">
                        ⚠️ {{ debtVoucherDetailModal.error }}
                    </div>

                    <template v-else-if="debtVoucherDetailModal.data">
                        <!-- Invoice Template -->
                        <div v-if="debtVoucherDetailModal.type === 'invoice'" class="space-y-6">
                            <!-- Summary Metadata -->
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-x-8 gap-y-3 bg-gray-50 p-4 rounded border border-gray-100">
                                <div><span class="text-gray-500">Khách hàng:</span> <span class="font-semibold text-gray-800 ml-1">{{ debtVoucherDetailModal.data.customer_name }}</span></div>
                                <div><span class="text-gray-500">Thời gian:</span> <span class="font-medium text-gray-800 ml-1">{{ debtVoucherDetailModal.data.created_at }}</span></div>
                                <div><span class="text-gray-500">Người tạo:</span> <span class="font-medium text-gray-800 ml-1">{{ debtVoucherDetailModal.data.created_by_name }}</span></div>
                                <div><span class="text-gray-500">Người bán:</span> <span class="font-medium text-gray-800 ml-1">{{ debtVoucherDetailModal.data.seller_name }}</span></div>
                                <div><span class="text-gray-500">Chi nhánh:</span> <span class="font-medium text-gray-800 ml-1">{{ debtVoucherDetailModal.data.branch_name || '-' }}</span></div>
                                <div><span class="text-gray-500">Phương thức:</span> <span class="font-medium text-gray-800 ml-1">{{ debtVoucherDetailModal.data.payment_method || '-' }}</span></div>
                            </div>

                            <!-- Note if exists -->
                            <div v-if="debtVoucherDetailModal.data.note" class="bg-blue-50/50 text-blue-800 p-3 rounded text-xs border border-blue-100 flex items-start gap-1.5">
                                <span class="text-blue-500">✏️</span>
                                <div><span class="font-bold">Ghi chú:</span> {{ debtVoucherDetailModal.data.note }}</div>
                            </div>

                            <!-- Items Table -->
                            <div>
                                <h4 class="font-semibold text-gray-700 mb-2.5">Danh sách hàng hóa</h4>
                                <div class="border rounded overflow-hidden">
                                    <table class="w-full text-xs">
                                        <thead class="bg-gray-50 text-gray-600 uppercase border-b font-semibold">
                                            <tr>
                                                <th class="px-3 py-2 text-left">Mã hàng</th>
                                                <th class="px-3 py-2 text-left">Tên hàng</th>
                                                <th class="px-3 py-2 text-right">SL</th>
                                                <th class="px-3 py-2 text-right">Đơn giá</th>
                                                <th class="px-3 py-2 text-right">Giảm giá</th>
                                                <th class="px-3 py-2 text-right">Thành tiền</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y text-gray-700 font-normal">
                                            <tr v-for="(item, idx) in debtVoucherDetailModal.data.items" :key="idx" class="hover:bg-gray-50">
                                                <td class="px-3 py-2 text-blue-600 font-semibold">{{ item.product_code }}</td>
                                                <td class="px-3 py-2">{{ item.product_name }}</td>
                                                <td class="px-3 py-2 text-right font-medium">{{ item.quantity }}</td>
                                                <td class="px-3 py-2 text-right">{{ formatCurrency(item.price) }}</td>
                                                <td class="px-3 py-2 text-right text-red-500">{{ formatCurrency(item.discount) }}</td>
                                                <td class="px-3 py-2 text-right font-semibold">{{ formatCurrency(item.subtotal) }}</td>
                                            </tr>
                                            <tr v-if="!debtVoucherDetailModal.data.items || debtVoucherDetailModal.data.items.length === 0">
                                                <td colspan="6" class="px-3 py-4 text-center text-gray-400 italic">Không có hàng hóa</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Financial Summary -->
                            <div class="flex justify-end pt-2">
                                <div class="w-80 space-y-2 border rounded p-4 bg-gray-50 text-xs">
                                    <div class="flex justify-between text-gray-500">
                                        <span>Tổng tiền hàng:</span>
                                        <span class="font-medium text-gray-800">{{ formatCurrency(debtVoucherDetailModal.data.subtotal) }}</span>
                                    </div>
                                    <div v-if="debtVoucherDetailModal.data.discount > 0" class="flex justify-between text-gray-500">
                                        <span>Giảm giá hóa đơn:</span>
                                        <span class="font-medium text-red-500">-{{ formatCurrency(debtVoucherDetailModal.data.discount) }}</span>
                                    </div>
                                    <div class="flex justify-between font-bold text-sm border-t border-gray-200 pt-2 text-gray-800">
                                        <span>Khách cần trả (Tổng cộng):</span>
                                        <span class="text-blue-600">{{ formatCurrency(debtVoucherDetailModal.data.total) }}</span>
                                    </div>
                                    <div class="flex justify-between text-gray-500">
                                        <span>Khách đã thanh toán:</span>
                                        <span class="font-semibold text-green-600">{{ formatCurrency(debtVoucherDetailModal.data.customer_paid) }}</span>
                                    </div>
                                    <div class="flex justify-between font-bold border-t border-gray-200 pt-2 text-gray-850">
                                        <span>Dư nợ hóa đơn:</span>
                                        <span class="text-red-500">{{ formatCurrency(debtVoucherDetailModal.data.debt_amount) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Purchase Template -->
                        <div v-if="debtVoucherDetailModal.type === 'purchase'" class="space-y-6">
                            <!-- Summary Metadata -->
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-x-8 gap-y-3 bg-gray-50 p-4 rounded border border-gray-100">
                                <div><span class="text-gray-500">Nhà cung cấp:</span> <span class="font-semibold text-gray-800 ml-1">{{ debtVoucherDetailModal.data.supplier_name }}</span> <span class="text-gray-400">({{ debtVoucherDetailModal.data.supplier_code }})</span></div>
                                <div><span class="text-gray-500">Ngày nhập hàng:</span> <span class="font-medium text-gray-800 ml-1">{{ debtVoucherDetailModal.data.purchase_date }}</span></div>
                                <div><span class="text-gray-500">Người tạo:</span> <span class="font-medium text-gray-800 ml-1">{{ debtVoucherDetailModal.data.user_name }}</span></div>
                                <div><span class="text-gray-500">Nhân viên:</span> <span class="font-medium text-gray-800 ml-1">{{ debtVoucherDetailModal.data.employee_name || '-' }}</span></div>
                                <div><span class="text-gray-500">Thanh toán:</span> <span class="font-medium text-gray-800 ml-1">{{ debtVoucherDetailModal.data.payment_method || '-' }}</span></div>
                            </div>

                            <!-- Note if exists -->
                            <div v-if="debtVoucherDetailModal.data.note" class="bg-blue-50/50 text-blue-800 p-3 rounded text-xs border border-blue-100 flex items-start gap-1.5">
                                <span class="text-blue-500">✏️</span>
                                <div><span class="font-bold">Ghi chú:</span> {{ debtVoucherDetailModal.data.note }}</div>
                            </div>

                            <!-- Items Table -->
                            <div>
                                <h4 class="font-semibold text-gray-700 mb-2.5">Danh sách hàng hóa nhập</h4>
                                <div class="border rounded overflow-hidden">
                                    <table class="w-full text-xs">
                                        <thead class="bg-gray-50 text-gray-600 uppercase border-b font-semibold">
                                            <tr>
                                                <th class="px-3 py-2 text-left">Mã hàng</th>
                                                <th class="px-3 py-2 text-left">Tên hàng</th>
                                                <th class="px-3 py-2 text-right">SL</th>
                                                <th class="px-3 py-2 text-right">Đơn giá</th>
                                                <th class="px-3 py-2 text-right">Giảm giá</th>
                                                <th class="px-3 py-2 text-right">Thành tiền</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y text-gray-700 font-normal">
                                            <tr v-for="(item, idx) in debtVoucherDetailModal.data.items" :key="idx" class="hover:bg-gray-50">
                                                <td class="px-3 py-2 text-blue-600 font-semibold">{{ item.product_code }}</td>
                                                <td class="px-3 py-2">{{ item.product_name }}</td>
                                                <td class="px-3 py-2 text-right font-medium">{{ item.quantity }}</td>
                                                <td class="px-3 py-2 text-right">{{ formatCurrency(item.price) }}</td>
                                                <td class="px-3 py-2 text-right text-red-500">{{ formatCurrency(item.discount) }}</td>
                                                <td class="px-3 py-2 text-right font-semibold">{{ formatCurrency(item.subtotal) }}</td>
                                            </tr>
                                            <tr v-if="!debtVoucherDetailModal.data.items || debtVoucherDetailModal.data.items.length === 0">
                                                <td colspan="6" class="px-3 py-4 text-center text-gray-400 italic">Không có hàng hóa</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Financial Summary -->
                            <div class="flex justify-end pt-2">
                                <div class="w-80 space-y-2 border rounded p-4 bg-gray-50 text-xs">
                                    <div class="flex justify-between text-gray-500">
                                        <span>Cần trả nhà cung cấp:</span>
                                        <span class="font-bold text-gray-800 text-sm">{{ formatCurrency(debtVoucherDetailModal.data.total_amount) }}</span>
                                    </div>
                                    <div v-if="debtVoucherDetailModal.data.discount > 0" class="flex justify-between text-gray-500">
                                        <span>Giảm giá phiếu nhập:</span>
                                        <span class="font-medium text-red-500">-{{ formatCurrency(debtVoucherDetailModal.data.discount) }}</span>
                                    </div>
                                    <div class="flex justify-between text-gray-500 border-t border-gray-200 pt-2">
                                        <span>Đã trả nhà cung cấp:</span>
                                        <span class="font-semibold text-green-600">{{ formatCurrency(debtVoucherDetailModal.data.paid_amount) }}</span>
                                    </div>
                                    <div class="flex justify-between font-bold border-t border-gray-200 pt-2 text-gray-850">
                                        <span>Dư nợ NCC:</span>
                                        <span class="text-red-500">{{ formatCurrency(debtVoucherDetailModal.data.debt_amount) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- CashFlow Template -->
                        <div v-if="debtVoucherDetailModal.type === 'cashflow'" class="space-y-4">
                            <div class="grid grid-cols-2 gap-x-8 gap-y-4 bg-gray-50 p-5 rounded border border-gray-100">
                                <div>
                                    <span class="text-gray-500 block text-xs uppercase tracking-wider font-semibold">Loại phiếu</span>
                                    <span class="font-bold text-gray-800 text-base">{{ ['thu', 'receipt'].includes(debtVoucherDetailModal.data.type) ? 'Phiếu thu' : 'Phiếu chi' }}</span>
                                </div>
                                <div>
                                    <span class="text-gray-500 block text-xs uppercase tracking-wider font-semibold">Giá trị</span>
                                    <span class="font-bold text-blue-600 text-base">{{ formatCurrency(debtVoucherDetailModal.data.amount) }}</span>
                                </div>
                                <div>
                                    <span class="text-gray-500 block text-xs uppercase tracking-wider font-semibold">Thời gian</span>
                                    <span class="font-medium text-gray-800">{{ debtVoucherDetailModal.data.time || debtVoucherDetailModal.data.created_at }}</span>
                                </div>
                                <div>
                                    <span class="text-gray-500 block text-xs uppercase tracking-wider font-semibold">Phương thức thanh toán</span>
                                    <span class="font-medium text-gray-800">{{ debtVoucherDetailModal.data.payment_method || 'Tiền mặt' }}</span>
                                </div>
                                <div v-if="debtVoucherDetailModal.data.bank_account_name">
                                    <span class="text-gray-500 block text-xs uppercase tracking-wider font-semibold">Tài khoản ngân hàng</span>
                                    <span class="font-medium text-gray-800">{{ debtVoucherDetailModal.data.bank_account_name }}</span>
                                </div>
                                <div>
                                    <span class="text-gray-500 block text-xs uppercase tracking-wider font-semibold">Hạng mục thu chi</span>
                                    <span class="font-medium text-gray-800">{{ debtVoucherDetailModal.data.category || '-' }}</span>
                                </div>
                                <div>
                                    <span class="text-gray-500 block text-xs uppercase tracking-wider font-semibold">Người nộp/nhận</span>
                                    <span class="font-medium text-gray-800">{{ debtVoucherDetailModal.data.target_name }}</span>
                                    <span class="text-gray-400 text-xs ml-1">({{ debtVoucherDetailModal.data.target_type }})</span>
                                </div>
                                <div v-if="debtVoucherDetailModal.data.reference_code">
                                    <span class="text-gray-500 block text-xs uppercase tracking-wider font-semibold">Mã chứng từ tham chiếu</span>
                                    <span class="font-semibold text-blue-600">{{ debtVoucherDetailModal.data.reference_code }}</span>
                                    <span class="text-gray-400 text-xs ml-1">({{ debtVoucherDetailModal.data.reference_type }})</span>
                                </div>
                            </div>

                            <div v-if="debtVoucherDetailModal.data.description" class="bg-blue-50/50 text-blue-800 p-4 rounded text-xs border border-blue-100 flex items-start gap-1.5">
                                <span class="text-blue-500">✏️</span>
                                <div><span class="font-bold">Ghi chú/Mô tả:</span> {{ debtVoucherDetailModal.data.description }}</div>
                            </div>
                        </div>

                        <!-- CustomerPaymentDiscount (CKTT) Template -->
                        <div v-if="debtVoucherDetailModal.type === 'payment_discount'" class="space-y-6">
                            <!-- Metadata -->
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-x-8 gap-y-3 bg-gray-50 p-4 rounded border border-gray-100">
                                <div><span class="text-gray-500">Giá trị chiết khấu:</span> <span class="font-bold text-blue-600 ml-1">{{ formatCurrency(debtVoucherDetailModal.data.amount) }}</span></div>
                                <div><span class="text-gray-500">Ngày chiết khấu:</span> <span class="font-medium text-gray-800 ml-1">{{ debtVoucherDetailModal.data.discount_at }}</span></div>
                                <div><span class="text-gray-500">Người thực hiện:</span> <span class="font-medium text-gray-800 ml-1">{{ debtVoucherDetailModal.data.performed_by_name }}</span></div>
                                <div><span class="text-gray-500">Người tạo:</span> <span class="font-medium text-gray-800 ml-1">{{ debtVoucherDetailModal.data.created_by_name }}</span></div>
                                <div><span class="text-gray-500">Hóa đơn phân bổ:</span> <span class="font-medium text-gray-800 ml-1">{{ debtVoucherDetailModal.data.allocate_to_invoices ? 'Có' : 'Không' }}</span></div>
                                <div v-if="debtVoucherDetailModal.data.cancelled_at"><span class="text-red-500 font-semibold">Ngày hủy:</span> <span class="font-medium text-red-600 ml-1">{{ debtVoucherDetailModal.data.cancelled_at }}</span></div>
                            </div>

                            <!-- Cancel Reason -->
                            <div v-if="debtVoucherDetailModal.data.cancel_reason" class="bg-red-50 text-red-800 p-3 rounded text-xs border border-red-100">
                                <span class="font-bold">Lý do hủy:</span> {{ debtVoucherDetailModal.data.cancel_reason }}
                            </div>

                            <!-- Note -->
                            <div v-if="debtVoucherDetailModal.data.note" class="bg-blue-50/50 text-blue-800 p-3 rounded text-xs border border-blue-100 flex items-start gap-1.5">
                                <span class="text-blue-500">✏️</span>
                                <div><span class="font-bold">Ghi chú:</span> {{ debtVoucherDetailModal.data.note }}</div>
                            </div>

                            <!-- Allocations -->
                            <div v-if="debtVoucherDetailModal.data.allocate_to_invoices">
                                <h4 class="font-semibold text-gray-700 mb-2.5">Danh sách hóa đơn được phân bổ</h4>
                                <div class="border rounded overflow-hidden">
                                    <table class="w-full text-xs">
                                        <thead class="bg-gray-50 text-gray-600 uppercase border-b font-semibold">
                                            <tr>
                                                <th class="px-3 py-2 text-left">Mã hóa đơn</th>
                                                <th class="px-3 py-2 text-right">Tổng giá trị</th>
                                                <th class="px-3 py-2 text-right">Khách đã trả</th>
                                                <th class="px-3 py-2 text-right text-blue-600">Tiền CKTT phân bổ</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y text-gray-700 font-normal">
                                            <tr v-for="(alloc, idx) in debtVoucherDetailModal.data.allocations" :key="idx" class="hover:bg-gray-50">
                                                <td class="px-3 py-2 font-semibold text-blue-600">{{ alloc.invoice_code }}</td>
                                                <td class="px-3 py-2 text-right">{{ formatCurrency(alloc.invoice_total) }}</td>
                                                <td class="px-3 py-2 text-right">{{ formatCurrency(alloc.invoice_customer_paid) }}</td>
                                                <td class="px-3 py-2 text-right font-bold text-blue-600">{{ formatCurrency(alloc.amount) }}</td>
                                            </tr>
                                            <tr v-if="!debtVoucherDetailModal.data.allocations || debtVoucherDetailModal.data.allocations.length === 0">
                                                <td colspan="4" class="px-3 py-4 text-center text-gray-400 italic">Không có hóa đơn phân bổ</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Ledger Template -->
                        <div v-if="debtVoucherDetailModal.type === 'ledger'" class="space-y-6">
                            <!-- Single / Multiple Ledger Adjustments -->
                            <div>
                                <h4 class="font-semibold text-gray-700 mb-2.5">Nhật ký điều chỉnh công nợ</h4>
                                <div class="border rounded overflow-hidden">
                                    <table class="w-full text-xs">
                                        <thead class="bg-gray-50 text-gray-600 uppercase border-b font-semibold">
                                            <tr>
                                                <th class="px-3 py-2 text-left">Thời gian</th>
                                                <th class="px-3 py-2 text-left">Mã tham chiếu</th>
                                                <th class="px-3 py-2 text-left">Loại giao dịch</th>
                                                <th class="px-3 py-2 text-right">Giá trị thay đổi</th>
                                                <th class="px-3 py-2 text-right">Dư nợ sau giao dịch</th>
                                                <th class="px-3 py-2 text-left">Mô tả/Ghi chú</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y text-gray-700 font-normal">
                                            <tr v-for="(entry, idx) in debtVoucherDetailModal.data.entries" :key="idx" class="hover:bg-gray-50">
                                                <td class="px-3 py-2 text-gray-600">{{ entry.recorded_at || entry.created_at }}</td>
                                                <td class="px-3 py-2 font-semibold text-blue-600">{{ entry.code }}</td>
                                                <td class="px-3 py-2">{{ entry.type }}</td>
                                                <td class="px-3 py-2 text-right font-medium" :class="Number(entry.amount) < 0 ? 'text-red-500' : 'text-green-600'">
                                                    {{ Number(entry.amount) > 0 ? '+' : '' }}{{ formatCurrency(entry.amount) }}
                                                </td>
                                                <td class="px-3 py-2 text-right font-semibold text-gray-800">{{ formatCurrency(entry.debt_total) }}</td>
                                                <td class="px-3 py-2 text-gray-600">{{ entry.note || '-' }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Footer -->
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end bg-gray-50 rounded-b-lg">
                    <button @click="debtVoucherDetailModal.show = false" class="px-4 py-2 border border-gray-300 rounded text-gray-700 bg-white hover:bg-gray-50 font-medium text-xs">
                        Đóng
                    </button>
                </div>
            </div>
        </div>


        <!-- HOTFIX 24.6I — Customer debt Excel export modal -->
        <div
            v-if="showCustomerDebtExportModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40"
            @click.self="closeCustomerDebtExportModal"
        >
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between px-6 py-4 border-b">
                    <h2 class="text-lg font-bold text-gray-800">Xuất file công nợ</h2>
                    <button @click="closeCustomerDebtExportModal" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
                </div>

                <div class="px-6 py-5 space-y-5 text-sm">
                    <div v-if="debtExportCustomer" class="bg-gray-50 px-3 py-2 rounded">
                        <span class="text-gray-500">Khách hàng:</span>
                        <span class="font-semibold text-gray-800 ml-1">{{ debtExportCustomer.name }}</span>
                        <span v-if="debtExportCustomer.code" class="text-gray-400 ml-1">({{ debtExportCustomer.code }})</span>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 mb-2">Thời gian</h3>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="preset in customerDebtExportPresets"
                                :key="preset.value"
                                type="button"
                                @click="customerDebtExportForm.date_preset = preset.value"
                                :class="customerDebtExportForm.date_preset === preset.value
                                    ? 'bg-blue-600 text-white border-blue-600'
                                    : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                                class="px-3 py-1.5 text-xs font-semibold border rounded transition"
                            >
                                {{ preset.label }}
                            </button>
                        </div>
                        <div v-if="customerDebtExportForm.date_preset === 'custom'" class="mt-3 grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Từ ngày</label>
                                <input
                                    v-model="customerDebtExportForm.date_from"
                                    type="text"
                                    inputmode="numeric"
                                    placeholder="dd/mm/yyyy"
                                    maxlength="10"
                                    class="w-full border rounded px-3 py-2 text-sm focus:border-blue-500 outline-none"
                                    :class="customerDebtExportForm.date_from && !parseVietnameseDateToIso(customerDebtExportForm.date_from)
                                        ? 'border-red-400' : 'border-gray-300'"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Đến ngày</label>
                                <input
                                    v-model="customerDebtExportForm.date_to"
                                    type="text"
                                    inputmode="numeric"
                                    placeholder="dd/mm/yyyy"
                                    maxlength="10"
                                    class="w-full border rounded px-3 py-2 text-sm focus:border-blue-500 outline-none"
                                    :class="customerDebtExportForm.date_to && !parseVietnameseDateToIso(customerDebtExportForm.date_to)
                                        ? 'border-red-400' : 'border-gray-300'"
                                />
                            </div>
                            <p
                                v-if="customerDebtExportForm.date_preset === 'custom' && !customerDebtExportCustomDatesValid"
                                class="col-span-2 text-xs text-red-500 -mt-1"
                            >
                                Nhập ngày theo định dạng <code>dd/mm/yyyy</code> (ví dụ <code>30/04/2026</code>).
                            </p>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 mb-2">Thông tin xuất file</h3>
                        <div class="bg-gray-50 border border-gray-200 rounded px-3 py-2 text-xs text-gray-600 mb-3">
                            <span class="font-semibold text-gray-700">Tổng quan luôn có:</span>
                            Thời gian, Mã chứng từ, Loại, Giá trị, Nợ hiện tại/Công nợ, Ghi chú.
                        </div>
                        <label class="flex items-center gap-2 mb-2 cursor-pointer">
                            <input type="checkbox" v-model="customerDebtExportForm.include_detail" class="rounded" />
                            <span class="font-semibold text-gray-700">Chi tiết từng hàng giao dịch</span>
                        </label>
                        <div
                            class="grid grid-cols-2 sm:grid-cols-3 gap-2 pl-6"
                            :class="{ 'opacity-50 pointer-events-none': !customerDebtExportForm.include_detail }"
                        >
                            <label
                                v-for="option in customerDebtExportColumnOptions"
                                :key="option.key"
                                class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer"
                            >
                                <input type="checkbox" v-model="customerDebtExportForm.columns[option.key]" class="rounded" />
                                <span>{{ option.label }}</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3 px-6 py-4 border-t bg-gray-50 rounded-b-xl">
                    <button
                        @click="closeCustomerDebtExportModal"
                        class="px-5 py-2 border border-gray-300 rounded text-sm font-semibold hover:bg-gray-100"
                    >
                        Bỏ qua
                    </button>
                    <button
                        @click="confirmCustomerDebtExport"
                        :disabled="!customerDebtExportCustomDatesValid"
                        class="px-5 py-2 bg-blue-600 text-white rounded text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
                    >
                        Đồng ý
                    </button>
                </div>
            </div>
        </div>

        <!-- ====== CUSTOMER GROUP CREATE MODAL (KiotViet-style 2 tabs) ====== -->
        <div v-if="showGroupModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
                <div class="flex items-center justify-between px-6 py-4 border-b">
                    <h3 class="text-lg font-bold text-gray-800">Tạo nhóm khách hàng</h3>
                    <button @click="showGroupModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <!-- Tabs -->
                <div class="flex border-b">
                    <button @click="groupModalTab = 'info'" :class="groupModalTab === 'info' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-gray-500'" class="px-4 py-2 text-sm font-medium">Thông tin</button>
                    <button @click="groupModalTab = 'advanced'" :class="groupModalTab === 'advanced' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-gray-500'" class="px-4 py-2 text-sm font-medium">Thiết lập nâng cao</button>
                </div>
                <div class="px-6 py-4 max-h-96 overflow-y-auto">
                    <!-- Generic / permission errors -->
                    <div v-if="groupErrors._generic" class="mb-3 px-3 py-2 bg-red-50 border border-red-200 rounded text-sm text-red-700">
                        {{ groupErrors._generic }}
                    </div>
                    <!-- Tab: Thông tin -->
                    <div v-if="groupModalTab === 'info'" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tên nhóm <span class="text-red-500">*</span></label>
                            <input v-model="groupForm.name" type="text" :class="['w-full border rounded p-2 text-sm', groupErrors.name ? 'border-red-400' : '']" placeholder="VD: Khách VIP" />
                            <p v-if="groupErrors.name" class="mt-1 text-xs text-red-600">{{ Array.isArray(groupErrors.name) ? groupErrors.name[0] : groupErrors.name }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Mã nhóm</label>
                            <input v-model="groupForm.code" type="text" :class="['w-full border rounded p-2 text-sm', groupErrors.code ? 'border-red-400' : '']" placeholder="Tự sinh nếu để trống" />
                            <p v-if="groupErrors.code" class="mt-1 text-xs text-red-600">{{ Array.isArray(groupErrors.code) ? groupErrors.code[0] : groupErrors.code }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Loại giảm giá</label>
                                <select v-model="groupForm.discount_type" class="w-full border rounded p-2 text-sm">
                                    <option value="">Không giảm</option>
                                    <option value="percent">Phần trăm (%)</option>
                                    <option value="amount">Số tiền (VNĐ)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Giá trị giảm</label>
                                <MoneyInput v-if="groupForm.discount_type === 'amount'" v-model="groupForm.discount_value" :min="0" input-class="w-full border rounded p-2 text-sm" />
                                <input v-else v-model.number="groupForm.discount_value" type="number" min="0" class="w-full border rounded p-2 text-sm" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
                            <textarea v-model="groupForm.note" rows="2" class="w-full border rounded p-2 text-sm" placeholder="Ghi chú nhóm"></textarea>
                        </div>
                    </div>
                    <!-- Tab: Thiết lập nâng cao -->
                    <div v-if="groupModalTab === 'advanced'" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Mô tả</label>
                            <textarea v-model="groupForm.description" rows="2" class="w-full border rounded p-2 text-sm"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Chế độ cập nhật</label>
                            <select v-model="groupForm.update_mode" class="w-full border rounded p-2 text-sm">
                                <option value="none">Không tự động</option>
                                <option value="add_matching">Thêm khách phù hợp</option>
                                <option value="refresh_matching">Làm mới theo điều kiện</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="checkbox" v-model="groupForm.auto_update" id="group_auto_update" class="w-4 h-4 text-blue-600" />
                            <label for="group_auto_update" class="text-sm text-gray-700">Tự động cập nhật định kỳ</label>
                        </div>
                        <p class="text-xs text-gray-400">Lưu ý: Engine tự động gán khách sẽ được xử lý trong Step 24.4B. Hiện tại chỉ lưu cấu hình.</p>
                    </div>
                </div>
                <div class="flex justify-end gap-2 px-6 py-3 border-t bg-gray-50 rounded-b-lg">
                    <button @click="showGroupModal = false" class="px-4 py-2 border rounded text-sm font-medium hover:bg-gray-100">Bỏ qua</button>
                    <button @click="submitGroupModal" :disabled="groupSubmitting" class="px-4 py-2 bg-blue-600 text-white rounded text-sm font-medium hover:bg-blue-700 disabled:opacity-50">
                        {{ groupSubmitting ? 'Đang lưu...' : 'Lưu' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: #d1d5db;
    border-radius: 10px;
}
</style>
