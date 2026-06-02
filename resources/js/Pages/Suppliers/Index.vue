<script setup>
import { formatVND as formatCurrency } from '@/utils/money';
import { ref, computed, reactive } from "vue";
import { Head, router, Link, useForm } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import SortableHeader from "@/Components/SortableHeader.vue";
import SidebarFilter from "@/Components/Filters/SidebarFilter.vue";
import DateTimePicker from "@/Components/DateTimePicker.vue";
import MoneyInput from "@/Components/MoneyInput.vue";
import { useFilters } from "@/composables/useFilters.js";
import axios from "axios";

const formatDateTime = (val) => {
    if (!val) return '';
    const d = new Date(val);
    if (isNaN(d)) return val;
    return d.toLocaleDateString('vi-VN') + ' ' + d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
};

const supplierEntryDisplayTime = (entry) =>
    entry?.display_time ||
    entry?.time ||
    entry?.recorded_at ||
    entry?.transaction_date ||
    entry?.purchase_date ||
    entry?.return_date ||
    entry?.created_at ||
    entry?.date ||
    '';

const truthyFlag = (value) =>
    value === true ||
    value === 1 ||
    value === '1' ||
    value === 'true' ||
    value === 'yes';

// Supplier list uses supplier-oriented debt for dual-role partners.
const supplierNetDebt = (supplier) => {
    if (!supplier) return 0;

    const supplierScreenDebt = Number(
        supplier.supplier_screen_debt ??
        supplier.supplier_list_debt_amount ??
        supplier.supplier_oriented_balance ??
        0
    );

    if (
        supplier.supplier_screen_debt !== undefined ||
        supplier.supplier_list_debt_amount !== undefined ||
        supplier.supplier_oriented_balance !== undefined
    ) {
        return supplierScreenDebt;
    }

    const supplierPayable = Number(
        supplier.supplier_payable_balance ??
        supplier.supplier_debt_amount ??
        0
    );
    const customerReceivable = Number(
        supplier.customer_receivable_balance ??
        supplier.debt_amount ??
        0
    );

    return truthyFlag(supplier.is_customer)
        ? supplierPayable - customerReceivable
        : supplierPayable;
};

const props = defineProps({
    suppliers: Object,
    groups: Array,
    filters: Object,
    filterOptions: Object,
    summary: Object,
});

const { filters, setSort, reset } = useFilters({
    initial: props.filters,
    route: "/suppliers",
    defaults: { date_filter: "all" },
});

const sidebarConfig = computed(() => [
    {
        key: "search",
        type: "search",
        label: "Tìm kiếm",
        placeholder: "Theo mã, tên, SĐT NCC",
    },
    {
        key: "customer_group",
        type: "select",
        label: "Nhóm nhà cung cấp",
        placeholder: "-- Tất cả các nhóm --",
        options: props.filterOptions?.groups || [],
    },
    {
        key: "partner_type",
        type: "select",
        label: "Loại đối tác",
        placeholder: "-- Tất cả --",
        options: props.filterOptions?.partnerTypes || [],
    },
    {
        key: "date",
        type: "dateRange",
        label: "Ngày tạo",
        fields: { filter: "date_filter", from: "date_from", to: "date_to" },
    },
]);

const handleSort = (field, direction) => setSort(field, direction);

const expandedRows = ref([]); // array of expanded supplier IDs

const toggleExpand = (supplierId) => {
    const index = expandedRows.value.indexOf(supplierId);
    if (index > -1) {
        expandedRows.value.splice(index, 1);
    } else {
        expandedRows.value.push(supplierId);
    }
};

const isExpanded = (supplierId) => {
    return expandedRows.value.includes(supplierId);
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

    is_customer: false,
});

// ====== XÁC NHẬN GỘP NCC + KH (KiotViet style) ======
const customerConfirm = reactive({
    show: false,
});

const onToggleIsCustomer = () => {
    if (!form.is_customer) {
        customerConfirm.show = true;
    } else {
        form.is_customer = false;
    }
};

const confirmCustomerToggle = () => {
    form.is_customer = true;
    customerConfirm.show = false;
};

const cancelCustomerToggle = () => {
    customerConfirm.show = false;
};

const submit = () => {
    form.post("/suppliers", {
        onSuccess: () => {
            showCreateModal.value = false;
            form.reset();
        },
    });
};

// ====== TAB MANAGEMENT ======
const supplierTabs = reactive({}); // { supplierId: 'info' | 'history' | 'debt' }
const supplierHistory = reactive({}); // { supplierId: [] }
const supplierDebt = reactive({}); // { supplierId: [] }
const supplierDataLoading = reactive({}); // { supplierId: bool }
const debtFilter = ref('all');

const getSupplierTab = (id) => supplierTabs[id] || 'info';

// HOTFIX 24.14 — inline `window.open(...)` in templates fails as
// "Cannot read properties of undefined (reading 'open')" because Vue 3
// template expressions resolve identifiers via the component instance
// scope, and `window` isn't a registered template global. Resolve via a
// script-defined helper instead so the `window` reference comes from the
// real module scope.
const supplierTabExportUrl = (supplier, tab) => {
    if (!supplier || !supplier.id) return '';
    if (tab === 'history') return `/api/suppliers/${supplier.id}/export-purchases`;
    if (tab === 'debt') return `/api/suppliers/${supplier.id}/export-debt`;
    return '';
};

const exportSupplierTab = (supplier, tab) => {
    const url = supplierTabExportUrl(supplier, tab);
    if (!url) return;
    window.location.assign(url);
};

// ═══ HOTFIX 24.17 — Modal xuất file công nợ NCC ═══
const showDebtExportModal = ref(false);
const debtExportSupplier = ref(null);
const debtExportForm = reactive({
    date_preset: 'all',
    // HOTFIX 24.17C — Vietnamese dd/mm/yyyy text inputs. We keep the
    // raw display value here and convert to ISO on submit so backend
    // can never misread an ambiguous M/D/Y string.
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

// Returns ISO `YYYY-MM-DD` if `value` matches dd/mm/yyyy AND the date
// is a real calendar day; otherwise returns null. Used to gate the
// "Đồng ý" button and to normalize the value before hitting the API.
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

const debtExportCustomDatesValid = computed(() => {
    if (debtExportForm.date_preset !== 'custom') return true;
    return !!parseVietnameseDateToIso(debtExportForm.date_from)
        && !!parseVietnameseDateToIso(debtExportForm.date_to);
});

const debtExportPresets = [
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

const debtExportColumnOptions = [
    { key: 'unit', label: 'ĐVT' },
    { key: 'quantity', label: 'Số lượng' },
    { key: 'unit_price', label: 'Đơn giá' },
    { key: 'discount', label: 'Giảm giá' },
    { key: 'vat', label: 'VAT' },
    { key: 'cost', label: 'Giá nhập/trả' },
    { key: 'line_total', label: 'Thành tiền' },
    { key: 'note', label: 'Ghi chú dòng' },
];

const openSupplierDebtExportModal = (supplier) => {
    if (!supplier || !supplier.id) return;
    debtExportSupplier.value = supplier;
    // Reset form to defaults each time the modal opens — không leak state
    // sang NCC khác.
    debtExportForm.date_preset = 'all';
    debtExportForm.date_from = '';
    debtExportForm.date_to = '';
    debtExportForm.include_detail = true;
    debtExportColumnOptions.forEach(o => { debtExportForm.columns[o.key] = true; });
    showDebtExportModal.value = true;
};

const closeDebtExportModal = () => {
    showDebtExportModal.value = false;
    debtExportSupplier.value = null;
};

const confirmDebtExport = () => {
    const sup = debtExportSupplier.value;
    if (!sup || !sup.id) return;

    const params = new URLSearchParams();
    // HOTFIX 24.17B — always request KiotViet-style xlsx from the modal.
    params.set('format', 'xlsx');
    params.set('date_preset', debtExportForm.date_preset);
    if (debtExportForm.date_preset === 'custom') {
        if (debtExportForm.date_from) params.set('date_from', debtExportForm.date_from);
        if (debtExportForm.date_to) params.set('date_to', debtExportForm.date_to);
    }
    params.set('include_detail', debtExportForm.include_detail ? '1' : '0');
    if (debtExportForm.include_detail) {
        debtExportColumnOptions.forEach(o => {
            if (debtExportForm.columns[o.key]) params.append('columns[]', o.key);
        });
    }

    // HOTFIX 24.17C — convert dd/mm/yyyy back to ISO right before
    // hitting the API so the backend never has to guess. The custom
    // branch above wrote raw user input; rewrite the two params here.
    if (debtExportForm.date_preset === 'custom') {
        const isoFrom = parseVietnameseDateToIso(debtExportForm.date_from);
        const isoTo   = parseVietnameseDateToIso(debtExportForm.date_to);
        if (!isoFrom || !isoTo) return; // guarded by disabled button — defensive
        params.set('date_from', isoFrom);
        params.set('date_to', isoTo);
    }

    const url = `/api/suppliers/${sup.id}/export-debt?${params.toString()}`;
    window.location.assign(url);
    closeDebtExportModal();
};

const setSupplierTab = async (id, tab) => {
    supplierTabs[id] = tab;
    if (tab === 'history' && !supplierHistory[id]) {
        supplierDataLoading[id] = true;
        try {
            const res = await axios.get(`/api/suppliers/${id}/purchase-history`);
            supplierHistory[id] = res.data;
        } catch (e) { supplierHistory[id] = []; }
        supplierDataLoading[id] = false;
    }
    if (
        tab === 'debt' &&
        (
            !supplierDebt[id] ||
            (isDualRoleSupplier(id) && !isSupplierPartnerTimeline(id))
        )
    ) {
        await loadSupplierDebt(id, 1);
        if (!offsetHistoryData[id]) loadOffsetHistory(id);
    }
};

// HOTFIX FOLLOW-UP — opt-in paginated supplier debt-transactions
// (KiotViet 10/page). Caller passes ?page=N to activate.
const supplierDebtPage = reactive({});
const supplierDebtPerPage = 10;
const shouldShowDebtReconcileWarning = (reconcile) =>
    reconcile?.severity === "warning" || reconcile?.user_warning === true;
const shouldShowDebtReconcileInfo = (reconcile) =>
    reconcile?.severity === "info" && !!reconcile?.message;

const supplierRows = computed(() => {
    if (Array.isArray(props.suppliers?.data)) return props.suppliers.data;
    if (Array.isArray(props.suppliers)) return props.suppliers;
    return [];
});

const findSupplierById = (id) =>
    supplierRows.value.find((supplier) => String(supplier.id) === String(id));

const isDualRoleSupplier = (id) => {
    const supplier = findSupplierById(id);
    return !!supplier && truthyFlag(supplier.is_customer);
};

const supplierDebtSummary = (id) => supplierDebt[id]?.summary || {};

const isSupplierPartnerTimeline = (id) => {
    const summary = supplierDebtSummary(id);
    return summary.display_mode === 'supplier_partner_timeline' ||
        summary.display_mode === 'partner_net_timeline' ||
        summary.legacy_display_mode === 'partner_net_timeline' ||
        summary.is_supplier_tab_partner_timeline === true;
};

const supplierDebtFinalColumnLabel = (id) =>
    supplierDebtSummary(id).balance_label || 'Nợ cần trả nhà cung cấp';

const supplierDebtEntryEffect = (entry, id) => {
    if (isSupplierPartnerTimeline(id)) {
        return Number(
            entry?.supplier_display_effect ??
            entry?.supplier_partner_effect ??
            entry?.partner_effect ??
            entry?.display_effect ??
            entry?.financial_effect ??
            entry?.supplier_effect ??
            entry?.amount ??
            0
        );
    }
    return Number(entry?.supplier_display_effect ?? entry?.display_effect ?? entry?.financial_effect ?? entry?.supplier_effect ?? entry?.amount ?? 0);
};

const supplierDebtEntryBalance = (entry, id) => {
    if (isSupplierPartnerTimeline(id)) {
        for (const key of ['supplier_display_running_balance', 'supplier_partner_running_balance', 'partner_running_balance', 'debt_remain', 'balance']) {
            const value = entry?.[key];
            if (value !== undefined && value !== null && value !== '') return Number(value);
        }
        return null;
    }
    for (const key of ['supplier_display_running_balance', 'debt_remain', 'supplier_running_balance', 'supplier_balance', 'balance']) {
        const value = entry?.[key];
        if (value !== undefined && value !== null && value !== '') return Number(value);
    }
    return null;
};

const supplierDebtEntryBadge = (entry, id) => {
    if (!isSupplierPartnerTimeline(id)) return '';
    if (entry?.badge_label === 'Đã hạch toán') return '';
    if (entry?.affects_partner_net === false) return entry?.badge_label || '';
    return entry?.badge_label || (
        entry?.domain === 'customer' || entry?.source_ledger === 'customer_receivable'
            ? 'Phải thu KH'
            : (entry?.domain === 'supplier' || entry?.source_ledger === 'supplier_payable' ? 'Phải trả NCC' : '')
    );
};

const loadSupplierDebt = async (id, page = null) => {
    supplierDataLoading[id] = true;
    const targetPage = page ?? supplierDebtPage[id] ?? 1;
    supplierDebtPage[id] = targetPage;
    try {
        const params = { page: targetPage, per_page: supplierDebtPerPage };
        if (isDualRoleSupplier(id)) {
            params.view = 'partner';
        }
        const res = await axios.get(`/api/suppliers/${id}/debt-transactions`, {
            params,
        });
        supplierDebt[id] = res.data;
    } catch (e) {
        supplierDebt[id] = { entries: [], summary: null, pagination: { total: 0, last_page: 1, current_page: 1, from: 0, to: 0 } };
    }
    supplierDataLoading[id] = false;
};

const changeSupplierDebtPage = (id, newPage) => {
    const meta = supplierDebt[id]?.pagination;
    if (!meta) return;
    const clamped = Math.max(1, Math.min(meta.last_page, newPage));
    if (clamped === meta.current_page) return;
    loadSupplierDebt(id, clamped);
};


// ====== PURCHASE DETAIL MODAL ======
const purchaseDetail = reactive({ show: false, loading: false, data: null });
const showPurchaseDetail = async (purchaseId) => {
    purchaseDetail.show = true;
    purchaseDetail.loading = true;
    try {
        const { data } = await axios.get(`/purchases/${purchaseId}/detail`);
        purchaseDetail.data = data;
    } catch (e) {
        purchaseDetail.data = null;
    }
    purchaseDetail.loading = false;
};

const filteredDebt = (id) => {
    const raw = supplierDebt[id];
    const data = Array.isArray(raw) ? raw : (raw?.entries || []);
    if (debtFilter.value === 'all') return data;
    return data.filter(d => d.type === debtFilter.value || d.event_kind === debtFilter.value);
};

// HOTFIX 24.15 — per-supplier-tab sort state. Default = newest first.
// Key = `${supplierId}:${tab}`. Kept separate from the parent table's
// `filters.sort_by` so toggling a tab's header doesn't trigger a server
// fetch on the supplier list.
const supplierTabSorts = reactive({});

const getSupplierTabSort = (supplierId, tab) => {
    return supplierTabSorts[`${supplierId}:${tab}`] || { field: 'time', direction: 'desc' };
};

const toggleSupplierTabTimeSort = (supplierId, tab) => {
    const key = `${supplierId}:${tab}`;
    const current = getSupplierTabSort(supplierId, tab);
    supplierTabSorts[key] = {
        field: 'time',
        direction: current.direction === 'desc' ? 'asc' : 'desc',
    };
};

const parseSupplierTabTime = (value) => {
    if (!value) return 0;
    if (value instanceof Date) {
        const t = value.getTime();
        return Number.isNaN(t) ? 0 : t;
    }
    const raw = String(value).trim();
    const direct = new Date(raw).getTime();
    if (!Number.isNaN(direct)) return direct;
    // dd/mm/yyyy [HH:mm[:ss]] fallback (BE may format `date` for history rows).
    const m = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?$/);
    if (m) {
        const [, dd, mm, yyyy, hh = '0', mi = '0', ss = '0'] = m;
        const d = new Date(Number(yyyy), Number(mm) - 1, Number(dd), Number(hh), Number(mi), Number(ss));
        const t = d.getTime();
        return Number.isNaN(t) ? 0 : t;
    }
    return 0;
};

const sortedSupplierHistory = (supplierId) => {
    const data = supplierHistory[supplierId] || [];
    const sort = getSupplierTabSort(supplierId, 'history');
    // Copy before sorting — never mutate the cached source.
    return [...data].sort((a, b) => {
        const av = parseSupplierTabTime(a.date || a.purchase_date || a.created_at);
        const bv = parseSupplierTabTime(b.date || b.purchase_date || b.created_at);
        return sort.direction === 'desc' ? bv - av : av - bv;
    });
};

const sortedSupplierDebt = (supplierId) => {
    // Start from filteredDebt so the type filter still applies.
    // Each entry already carries the correct `debt_remain` (computed on the
    // server in chronological order), so reordering display is safe.
    const data = filteredDebt(supplierId);
    const sort = getSupplierTabSort(supplierId, 'debt');
    return [...data].sort((a, b) => {
        const av = parseSupplierTabTime(supplierEntryDisplayTime(a));
        const bv = parseSupplierTabTime(supplierEntryDisplayTime(b));
        return sort.direction === 'desc' ? bv - av : av - bv;
    });
};

// ====== DEBT ACTION MODAL ======
const showDebtModal = ref(false);
const debtActionType = ref('payment'); // 'payment', 'adjustment', 'discount'
const debtActionSupplier = ref(null);
const debtAmount = ref(0);
const debtNote = ref('');
const debtDate = ref('');
const debtSubmitting = ref(false);

const debtActionLabels = {
    payment: 'Thanh toán công nợ',
    adjustment: 'Điều chỉnh công nợ',
    discount: 'Chiết khấu thanh toán',
};

const openDebtAction = (supplier, type) => {
    debtActionSupplier.value = supplier;
    debtActionType.value = type;
    debtAmount.value = type === 'adjustment' ? (supplier.supplier_debt_amount || 0) : 0;
    debtNote.value = '';
    // Mặc định ngày điều chỉnh = hiện tại
    const _now = new Date();
    _now.setMinutes(_now.getMinutes() - _now.getTimezoneOffset());
    debtDate.value = _now.toISOString().slice(0, 16);
    showDebtModal.value = true;
};

const submitDebtAction = async () => {
    // Payment/discount: amount must be > 0. Adjustment: any value allowed
    if (debtActionType.value !== 'adjustment' && !debtAmount.value) return;
    if (debtActionType.value === 'adjustment' && (debtAmount.value === null || debtAmount.value === '')) return;
    debtSubmitting.value = true;
    const id = debtActionSupplier.value.id;
    try {
        if (debtActionType.value === 'payment') {
            await axios.post(`/api/suppliers/${id}/payment`, {
                amount: debtAmount.value,
                note: debtNote.value,
                date: debtDate.value,
            });
        } else {
            await axios.post(`/api/suppliers/${id}/adjust-debt`, {
                amount: debtAmount.value,
                note: debtNote.value,
                type: debtActionType.value,
                date: debtDate.value,
            });
        }
        // Reload debt data
        delete supplierDebt[id];
        await setSupplierTab(id, 'debt');
        showDebtModal.value = false;
        // Refresh page to update summary
        router.reload({ only: ['suppliers', 'summary'] });
    } catch (e) {
        alert(e.response?.data?.message || 'Lỗi xử lý.');
    } finally {
        debtSubmitting.value = false;
    }
};

// ====== MERGE SUPPLIER ↔ CUSTOMER ======
const mergeModal = reactive({
    show: false,
    source: null,
    searchQuery: '',
    searchResults: [],
    searching: false,
    selected: null,
    submitting: false,
});

let mergeSearchTimeout;
const openMergeModal = (supplier) => {
    mergeModal.show = true;
    mergeModal.source = supplier;
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
                    type: 'customer',
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

// ====== CẤN BẰNG CÔNG NỢ (SUPPLIER VIEW) ======
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
const offsetHistoryData = reactive({});

const openOffsetModal = (supplier) => {
    offsetModal.show = true;
    offsetModal.customerId = supplier.id;
    offsetModal.customerName = supplier.name;
    offsetModal.receivable = Number(supplier.debt_amount) || 0;
    offsetModal.payable = Number(supplier.supplier_debt_amount) || 0;
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
            amount: offsetForm.amount,
            note: offsetForm.note,
        });
        offsetModal.show = false;
        // Reload debt & offset data
        delete supplierDebt[offsetModal.customerId];
        await setSupplierTab(offsetModal.customerId, 'debt');
        await loadOffsetHistory(offsetModal.customerId);
        router.reload({ only: ['suppliers', 'summary'], preserveScroll: true });
    } catch (e) {
        alert(e.response?.data?.message || 'Có lỗi xảy ra khi cấn bằng công nợ');
    } finally {
        offsetModal.submitting = false;
    }
};

const loadOffsetHistory = async (supplierId) => {
    try {
        const { data } = await axios.get(`/customers/${supplierId}/debt-offset-history`);
        offsetHistoryData[supplierId] = data;
    } catch (e) {
        offsetHistoryData[supplierId] = [];
    }
};

const cancelOffset = async (supplierId, offsetId) => {
    const reason = prompt('Lý do hủy cấn bằng (không bắt buộc):');
    if (reason === null) return;
    try {
        await axios.post(`/customers/${supplierId}/cancel-debt-offset/${offsetId}`, { reason });
        delete supplierDebt[supplierId];
        await setSupplierTab(supplierId, 'debt');
        await loadOffsetHistory(supplierId);
        router.reload({ only: ['suppliers', 'summary'], preserveScroll: true });
    } catch (e) {
        alert(e.response?.data?.message || 'Có lỗi xảy ra khi hủy cấn bằng');
    }
};

// ====== CB TOAST (KiotViet style) ======
const cbToast = ref({ show: false, timer: null });
const showCbToast = () => {
    if (cbToast.value.timer) clearTimeout(cbToast.value.timer);
    cbToast.value.show = true;
    cbToast.value.timer = setTimeout(() => { cbToast.value.show = false; }, 4000);
};

// ════════════════════════════════════════════════════════════════════
//  HOTFIX 24.8 — Update + Deactivate/Activate supplier
//  Backend (SupplierController) is the source of truth for which fields
//  may be edited and forces is_supplier=true. UI never touches debt
//  or total_bought.
// ════════════════════════════════════════════════════════════════════
const showEditModal = ref(false);
const editingSupplier = ref(null);
const editForm = useForm({
    name: '',
    code: '',
    phone: '',
    phone2: '',
    email: '',
    address: '',
    city: '',
    district: '',
    ward: '',
    customer_group: '',
    tax_code: '',
    note: '',
    invoice_name: '',
    invoice_address: '',
    invoice_email: '',
    invoice_phone: '',
    bank_name: '',
    bank_account: '',
    is_customer: false,
});

const openEditModal = (supplier) => {
    editingSupplier.value = supplier;
    editForm.name           = supplier.name           ?? '';
    editForm.code           = supplier.code           ?? '';
    editForm.phone          = supplier.phone          ?? '';
    editForm.phone2         = supplier.phone2         ?? '';
    editForm.email          = supplier.email          ?? '';
    editForm.address        = supplier.address        ?? '';
    editForm.city           = supplier.city           ?? '';
    editForm.district       = supplier.district       ?? '';
    editForm.ward           = supplier.ward           ?? '';
    editForm.customer_group = supplier.customer_group ?? '';
    editForm.tax_code       = supplier.tax_code       ?? '';
    editForm.note           = supplier.note           ?? '';
    editForm.invoice_name   = supplier.invoice_name   ?? '';
    editForm.invoice_address= supplier.invoice_address?? '';
    editForm.invoice_email  = supplier.invoice_email  ?? '';
    editForm.invoice_phone  = supplier.invoice_phone  ?? '';
    editForm.bank_name      = supplier.bank_name      ?? '';
    editForm.bank_account   = supplier.bank_account   ?? '';
    editForm.is_customer    = !!supplier.is_customer;
    showEditModal.value = true;
};

const closeEditModal = () => {
    if (editForm.processing) return;
    showEditModal.value = false;
    editingSupplier.value = null;
};

const submitEdit = () => {
    if (!editingSupplier.value) return;
    editForm.put(`/suppliers/${editingSupplier.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            editingSupplier.value = null;
        },
    });
};

const deactivateConfirm = ref({ show: false, supplier: null, processing: false });
const openDeactivateConfirm = (supplier) => {
    deactivateConfirm.value = { show: true, supplier, processing: false };
};
const closeDeactivateConfirm = () => {
    if (deactivateConfirm.value.processing) return;
    deactivateConfirm.value = { show: false, supplier: null, processing: false };
};
const submitDeactivate = () => {
    const sup = deactivateConfirm.value.supplier;
    if (!sup) return;
    deactivateConfirm.value.processing = true;
    router.post(`/suppliers/${sup.id}/deactivate`, {}, {
        preserveScroll: true,
        onFinish: () => {
            deactivateConfirm.value = { show: false, supplier: null, processing: false };
        },
    });
};

const submitActivate = (supplier) => {
    if (!supplier) return;
    router.post(`/suppliers/${supplier.id}/activate`, {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="Nhà cung cấp - KiotViet Clone" />
    <AppLayout>
        <template #sidebar>
            <div class="p-3">
                <SidebarFilter
                    v-model="filters"
                    :config="sidebarConfig"
                    @reset="reset"
                />
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
                    Nhà cung cấp
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
                        >Nhà cung cấp
                    </button>
                    <ExcelButtons
                        export-url="/suppliers/export"
                        import-url="/suppliers/import"
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
            <div class="flex items-center gap-6 px-4 py-2 bg-green-50 border-b border-green-200 text-sm">
                <div>Tổng nợ phải trả NCC: <span class="font-bold text-red-600">{{ formatCurrency(summary?.total_debt || 0) }}</span></div>
                <div>Tổng mua: <span class="font-bold text-gray-800">{{ formatCurrency(summary?.total_bought || 0) }}</span></div>
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-auto bg-gray-50/20">
                <table class="w-full text-sm text-left whitespace-nowrap">
                    <thead
                        class="text-[13px] font-bold text-gray-700 bg-white border-b border-gray-200 sticky top-0 z-10 shadow-sm"
                    >
                        <tr>
                            <th class="px-4 py-3 w-10 text-center">
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-300"
                                />
                            </th>
                            <SortableHeader label="Mã nhà cung cấp" field="code" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" class="px-4 py-3" @sort="handleSort" />
                            <SortableHeader label="Tên nhà cung cấp" field="name" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" class="px-4 py-3" @sort="handleSort" />
                            <SortableHeader label="Điện thoại" field="phone" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" class="px-4 py-3" @sort="handleSort" />
                            <SortableHeader label="Email" field="email" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" class="px-4 py-3" @sort="handleSort" />
                            <SortableHeader label="Nợ cần trả hiện tại" field="supplier_debt_amount" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="right" class="px-4 py-3 text-right" @sort="handleSort" />
                            <SortableHeader label="Tổng mua" field="total_bought" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="right" class="px-4 py-3 text-right" @sort="handleSort" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-gray-800">
                        <!-- Summary row -->
                        <tr class="bg-gray-50 border-b border-gray-200 font-semibold text-sm">
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td class="px-4 py-3 text-right text-red-600">{{ formatCurrency(summary?.total_debt || 0) }}</td>
                            <td class="px-4 py-3 text-right text-gray-700">{{ formatCurrency(summary?.total_bought || 0) }}</td>
                        </tr>
                        <tr v-if="suppliers.data.length === 0">
                            <td
                                colspan="7"
                                class="px-6 py-12 text-center text-gray-500"
                            >
                                Không tìm thấy nhà cung cấp nào.
                            </td>
                        </tr>
                        <template
                            v-for="supplier in suppliers.data"
                            :key="supplier.id"
                        >
                            <!-- Main Row -->
                            <tr
                                @click="toggleExpand(supplier.id)"
                                class="hover:bg-blue-50/50 transition-colors cursor-pointer bg-white"
                                :class="{
                                    'bg-[#f4f7fe]': isExpanded(supplier.id),
                                    'border-l-2 border-l-blue-500': isExpanded(
                                        supplier.id,
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
                                        <svg
                                            v-if="supplier.is_customer"
                                            class="w-4 h-4 text-blue-500"
                                            fill="currentColor"
                                            viewBox="0 0 20 20"
                                            xmlns="http://www.w3.org/2000/svg"
                                        >
                                            <path
                                                d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"
                                            ></path>
                                        </svg>
                                        {{ supplier.code }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">{{ supplier.name }}</td>
                                <td class="px-4 py-3">{{ supplier.phone }}</td>
                                <td class="px-4 py-3">{{ supplier.email }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div :class="supplierNetDebt(supplier) < 0 ? 'text-green-600 font-semibold' : 'text-red-600'">
                                        {{ formatCurrency(supplierNetDebt(supplier)) }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{
                                        formatCurrency(
                                            supplier.total_bought,
                                        )
                                    }}
                                </td>
                            </tr>

                            <!-- Expanded Detail Row -->
                            <tr
                                v-if="isExpanded(supplier.id)"
                                class="border-b-4 border-blue-50"
                            >
                                <td colspan="7" class="p-0 border-0 bg-white">
                                    <div
                                        class="px-4 py-4 w-full shadow-inner border-t border-blue-100 flex flex-col pt-0"
                                    >
                                        <!-- Tabs within Detail -->
                                        <div
                                            class="flex text-[13.5px] font-semibold text-gray-600 border-b border-gray-200 sticky top-0 bg-white z-0 pt-2 mb-4"
                                        >
                                            <button
                                                @click="setSupplierTab(supplier.id, 'info')"
                                                :class="getSupplierTab(supplier.id) === 'info' ? 'border-b-2 border-blue-600 text-blue-600' : ''"
                                                class="px-4 pb-2 hover:text-blue-500 transition"
                                            >
                                                Thông tin
                                            </button>
                                            <button
                                                @click="setSupplierTab(supplier.id, 'history')"
                                                :class="getSupplierTab(supplier.id) === 'history' ? 'border-b-2 border-blue-600 text-blue-600' : ''"
                                                class="px-4 pb-2 hover:text-blue-500 transition"
                                            >
                                                Lịch sử nhập/trả hàng
                                            </button>
                                            <button
                                                @click="setSupplierTab(supplier.id, 'debt')"
                                                :class="getSupplierTab(supplier.id) === 'debt' ? 'border-b-2 border-blue-600 text-blue-600' : ''"
                                                class="px-4 pb-2 hover:text-blue-500 transition"
                                            >
                                                Công nợ
                                            </button>
                                        </div>

                                        <!-- Tab: Thông tin -->
                                        <template v-if="getSupplierTab(supplier.id) === 'info'">
                                            <div class="flex items-start gap-4 mb-6">
                                                <div class="w-24 h-24 bg-blue-100 text-blue-500 rounded-full flex items-center justify-center flex-shrink-0 relative overflow-hidden">
                                                    <svg class="w-16 h-16 mt-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path></svg>
                                                </div>
                                                <div class="flex-1 mt-1">
                                                    <div class="flex gap-2 items-end mb-2">
                                                        <h2 class="text-xl font-bold text-gray-800">{{ supplier.name }}</h2>
                                                        <span class="text-gray-500 font-medium mb-0.5">{{ supplier.code }}</span>
                                                    </div>
                                                    <div class="text-[13px] text-gray-500 space-y-1 mb-2">
                                                        <div class="flex items-center gap-1 border-r border-gray-300 pr-3 mr-2 inline-block">Người tạo: <span class="text-gray-700">Admin</span></div>
                                                        <div class="flex items-center gap-1 border-r border-gray-300 pr-3 mr-2 inline-block">Ngày tạo: <span class="text-gray-700">{{ new Date(supplier.created_at).toLocaleDateString('vi-VN') }}</span></div>
                                                        <div class="flex items-center gap-1 inline-block">Nhóm nhà cung cấp: <span class="text-gray-700">{{ supplier.customer_group || 'Chưa có' }}</span></div>
                                                    </div>
                                                </div>
                                                <div class="text-[13px] text-gray-500 font-medium mt-1 pr-4">Laptopplus.vn</div>
                                            </div>
                                            <div class="grid grid-cols-2 gap-y-4 gap-x-8 text-[13.5px] border-b border-gray-200 pb-4 mb-4">
                                                <div><div class="text-gray-500 mb-0.5 font-medium">Điện thoại</div><div class="text-gray-800 font-medium">{{ supplier.phone || 'Chưa có' }}</div></div>
                                                <div><div class="text-gray-500 mb-0.5 font-medium">Email</div><div class="text-gray-400">{{ supplier.email || 'Chưa có' }}</div></div>
                                                <div class="col-span-2"><div class="text-gray-500 mb-0.5 font-medium">Địa chỉ</div><div class="text-gray-400">{{ supplier.address || 'Chưa có' }}</div></div>
                                            </div>
                                            <div class="bg-gray-50/50 rounded p-4 border border-gray-200 mb-4 text-[13.5px]">
                                                <div class="font-bold text-blue-600 mb-1 cursor-pointer hover:underline">Thêm thông tin xuất hóa đơn</div>
                                                <div class="flex items-center gap-2 text-gray-600 mt-2">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                                    {{ supplier.note || 'Chưa có ghi chú' }}
                                                </div>
                                            </div>
                                            <div class="flex items-center justify-between">
                                                <button class="text-gray-600 bg-white border border-gray-300 rounded px-3 py-1.5 text-[13.5px] font-semibold hover:bg-gray-50 flex items-center gap-1 shadow-sm">
                                                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>Xóa
                                                </button>
                                                <div class="flex gap-2 text-[13.5px]">
                                                    <button
                                                        @click.stop="openEditModal(supplier)"
                                                        class="text-white bg-blue-600 rounded px-4 py-1.5 font-bold hover:bg-blue-700 flex items-center gap-1 shadow-sm"
                                                    >
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>Cập nhật
                                                    </button>
                                                    <button
                                                        v-if="!supplier.is_customer"
                                                        @click.stop="openMergeModal(supplier)"
                                                        class="text-white bg-orange-500 rounded px-4 py-1.5 font-bold hover:bg-orange-600 flex items-center gap-1 shadow-sm"
                                                    >
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>Gộp KH
                                                    </button>
                                                    <button
                                                        v-if="supplier.status !== 'inactive'"
                                                        @click.stop="openDeactivateConfirm(supplier)"
                                                        class="text-gray-700 bg-white border border-gray-300 rounded px-4 py-1.5 font-bold hover:bg-gray-50 shadow-sm"
                                                    >Ngừng hoạt động</button>
                                                    <button
                                                        v-else
                                                        @click.stop="submitActivate(supplier)"
                                                        class="text-white bg-green-600 rounded px-4 py-1.5 font-bold hover:bg-green-700 shadow-sm"
                                                    >Hoạt động lại</button>
                                                </div>
                                            </div>
                                        </template>

                                        <!-- Tab: Lịch sử nhập/trả hàng -->
                                        <template v-if="getSupplierTab(supplier.id) === 'history'">
                                            <div v-if="supplierDataLoading[supplier.id]" class="text-center py-8 text-gray-400">Đang tải...</div>
                                            <template v-else>
                                                <table class="w-full text-sm text-left mb-4">
                                                    <thead class="bg-gray-50 text-gray-600 text-xs uppercase">
                                                        <tr>
                                                            <th class="px-3 py-2">Mã phiếu</th>
                                                            <th
                                                                class="px-3 py-2 cursor-pointer select-none hover:text-gray-900"
                                                                @click.stop="toggleSupplierTabTimeSort(supplier.id, 'history')"
                                                                :title="getSupplierTabSort(supplier.id, 'history').direction === 'desc' ? 'Đang sắp xếp: mới nhất trước' : 'Đang sắp xếp: cũ nhất trước'"
                                                            >
                                                                Thời gian
                                                                <span class="ml-1 text-[10px]">{{ getSupplierTabSort(supplier.id, 'history').direction === 'desc' ? '▼' : '▲' }}</span>
                                                            </th>
                                                            <th class="px-3 py-2">Người tạo</th>
                                                            <th class="px-3 py-2">Chi nhánh</th>
                                                            <th class="px-3 py-2 text-right">Tổng cộng</th>
                                                            <th class="px-3 py-2">Trạng thái</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y">
                                                        <tr v-if="!supplierHistory[supplier.id]?.length"><td colspan="6" class="px-3 py-6 text-center text-gray-400">Chưa có phiếu nhập/trả hàng.</td></tr>
                                                        <tr v-for="h in sortedSupplierHistory(supplier.id)" :key="h.id" class="hover:bg-gray-50">
                                                            <td class="px-3 py-2 text-blue-600 font-semibold cursor-pointer hover:underline" @click="showPurchaseDetail(h.id)">{{ h.code }}</td>
                                                            <td class="px-3 py-2">{{ h.date }}</td>
                                                            <td class="px-3 py-2">{{ h.user_name }}</td>
                                                            <td class="px-3 py-2">{{ h.branch }}</td>
                                                            <td class="px-3 py-2 text-right font-semibold">{{ formatCurrency(h.total) }}</td>
                                                            <td class="px-3 py-2">
                                                                <span :class="h.status === 'completed' ? 'text-green-600' : 'text-orange-600'" class="font-semibold text-xs">{{ h.status_label }}</span>
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                                <div class="flex gap-2">
                                                    <button
                                                        v-if="supplier?.id"
                                                        type="button"
                                                        @click.stop="exportSupplierTab(supplier, 'history')"
                                                        class="text-gray-600 bg-white border border-gray-300 rounded px-3 py-1.5 text-[13px] font-semibold hover:bg-gray-50 flex items-center gap-1"
                                                    >
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                                        Xuất file
                                                    </button>
                                                </div>
                                            </template>
                                        </template>

                                        <!-- Tab: Nợ cần trả NCC -->
                                        <template v-if="getSupplierTab(supplier.id) === 'debt'">
                                            <div v-if="supplierDataLoading[supplier.id]" class="text-center py-8 text-gray-400">Đang tải...</div>
                                            <template v-else>
                                                <div
                                                    v-if="shouldShowDebtReconcileWarning(supplierDebt[supplier.id]?.reconcile)"
                                                    class="mb-3 p-3 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded text-xs flex items-center gap-2"
                                                >
                                                    <svg class="w-4 h-4 text-yellow-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                                    <span>{{ supplierDebt[supplier.id]?.reconcile?.message || 'Lịch sử công nợ đang lệch với Nợ cần trả nhà cung cấp. Cần đối soát dữ liệu trước khi cập nhật.' }}</span>
                                                </div>
                                                <div
                                                    v-else-if="shouldShowDebtReconcileInfo(supplierDebt[supplier.id]?.reconcile)"
                                                    class="mb-3 text-xs text-slate-500"
                                                >
                                                    {{ supplierDebt[supplier.id].reconcile.message }}
                                                </div>
                                                <div class="flex justify-end mb-3">
                                                    <select v-model="debtFilter" class="border border-gray-300 rounded px-3 py-1.5 text-sm outline-none">
                                                        <option value="all">Tất cả giao dịch</option>
                                                        <option value="purchase">Nhập hàng</option>
                                                        <option value="payment">Thanh toán</option>
                                                        <option value="adjustment">Điều chỉnh</option>
                                                        <option value="discount">Chiết khấu</option>
                                                        <option value="offset">Đối trừ CN</option>
                                                    </select>
                                                </div>
                                                <table class="w-full text-sm text-left mb-4">
                                                    <thead class="bg-gray-50 text-gray-600 text-xs uppercase">
                                                        <tr>
                                                            <th class="px-3 py-2">Mã phiếu</th>
                                                            <th
                                                                class="px-3 py-2 cursor-pointer select-none hover:text-gray-900"
                                                                @click.stop="toggleSupplierTabTimeSort(supplier.id, 'debt')"
                                                                :title="getSupplierTabSort(supplier.id, 'debt').direction === 'desc' ? 'Đang sắp xếp: mới nhất trước' : 'Đang sắp xếp: cũ nhất trước'"
                                                            >
                                                                Thời gian
                                                                <span class="ml-1 text-[10px]">{{ getSupplierTabSort(supplier.id, 'debt').direction === 'desc' ? '▼' : '▲' }}</span>
                                                            </th>
                                                            <th class="px-3 py-2">Loại</th>
                                                            <th class="px-3 py-2 text-right">Giá trị</th>
                                                            <th class="px-3 py-2 text-right">{{ supplierDebtFinalColumnLabel(supplier.id) }}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y">
                                                        <tr v-if="!filteredDebt(supplier.id)?.length"><td colspan="5" class="px-3 py-6 text-center text-gray-400">Chưa có giao dịch công nợ.</td></tr>
                                                        <tr
                                                            v-for="d in sortedSupplierDebt(supplier.id)"
                                                            :key="`${d.source_ledger || d.domain || d.source || 'debt'}-${d.type || d.event_kind || 'row'}-${d.id}-${d.code}-${d.time || d.created_at || d.date}`"
                                                            class="hover:bg-gray-50"
                                                        >
                                                            <td class="px-3 py-2 font-semibold"
                                                                :class="(d.code && (d.code.startsWith('CB') || d.code.startsWith('DTCN'))) ? 'text-blue-600 cursor-pointer hover:underline' : 'text-blue-600'"
                                                                @click="(d.code && (d.code.startsWith('CB') || d.code.startsWith('DTCN'))) && showCbToast()"
                                                            >{{ d.code }}</td>
                                                            <td class="px-3 py-2">{{ formatDateTime(supplierEntryDisplayTime(d)) }}</td>
                                                            <td class="px-3 py-2">
                                                                <div class="flex items-center gap-2">
                                                                    <span>{{ d.type_label || d.display_type || d.type }}</span>
                                                                    <span
                                                                        v-if="supplierDebtEntryBadge(d, supplier.id)"
                                                                        class="inline-flex items-center rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600"
                                                                    >
                                                                        {{ supplierDebtEntryBadge(d, supplier.id) }}
                                                                    </span>
                                                                </div>
                                                            </td>
                                                            <td
                                                                class="px-3 py-2 text-right font-semibold"
                                                                :class="supplierDebtEntryEffect(d, supplier.id) < 0 ? 'text-green-600' : 'text-red-600'"
                                                            >
                                                                {{ supplierDebtEntryEffect(d, supplier.id) > 0 ? '+' : '' }}{{ formatCurrency(supplierDebtEntryEffect(d, supplier.id)) }}
                                                            </td>
                                                            <td class="px-3 py-2 text-right font-semibold">
                                                                <span v-if="supplierDebtEntryBalance(d, supplier.id) === null">—</span>
                                                                <span v-else>{{ formatCurrency(supplierDebtEntryBalance(d, supplier.id)) }}</span>
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>

                                                <!-- HOTFIX FOLLOW-UP — KiotViet-style pagination -->
                                                <div
                                                    v-if="supplierDebt[supplier.id]?.pagination && supplierDebt[supplier.id].pagination.last_page > 1"
                                                    class="flex items-center justify-end gap-2 mt-3 text-[12px] text-gray-600"
                                                >
                                                    <button
                                                        class="px-2 py-1 border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
                                                        :disabled="supplierDebt[supplier.id].pagination.current_page <= 1"
                                                        @click="changeSupplierDebtPage(supplier.id, 1)"
                                                        title="Trang đầu"
                                                    >|‹</button>
                                                    <button
                                                        class="px-2 py-1 border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
                                                        :disabled="supplierDebt[supplier.id].pagination.current_page <= 1"
                                                        @click="changeSupplierDebtPage(supplier.id, supplierDebt[supplier.id].pagination.current_page - 1)"
                                                        title="Trang trước"
                                                    >‹</button>
                                                    <span class="px-2 font-medium text-gray-800">
                                                        {{ supplierDebt[supplier.id].pagination.current_page }} / {{ supplierDebt[supplier.id].pagination.last_page }}
                                                    </span>
                                                    <button
                                                        class="px-2 py-1 border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
                                                        :disabled="supplierDebt[supplier.id].pagination.current_page >= supplierDebt[supplier.id].pagination.last_page"
                                                        @click="changeSupplierDebtPage(supplier.id, supplierDebt[supplier.id].pagination.current_page + 1)"
                                                        title="Trang sau"
                                                    >›</button>
                                                    <button
                                                        class="px-2 py-1 border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
                                                        :disabled="supplierDebt[supplier.id].pagination.current_page >= supplierDebt[supplier.id].pagination.last_page"
                                                        @click="changeSupplierDebtPage(supplier.id, supplierDebt[supplier.id].pagination.last_page)"
                                                        title="Trang cuối"
                                                    >›|</button>
                                                    <span class="ml-3 text-gray-500">
                                                        {{ supplierDebt[supplier.id].pagination.from }} - {{ supplierDebt[supplier.id].pagination.to }} trong {{ supplierDebt[supplier.id].pagination.total }} dòng
                                                    </span>
                                                </div>

                                                <div class="flex items-center justify-between">
                                                    <div class="flex gap-2">
                                                        <button
                                                            v-if="supplier?.id"
                                                            type="button"
                                                            @click.stop="openSupplierDebtExportModal(supplier)"
                                                            class="text-gray-600 bg-white border border-gray-300 rounded px-3 py-1.5 text-[13px] font-semibold hover:bg-gray-50 flex items-center gap-1"
                                                        >
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                                            Xuất file công nợ
                                                        </button>
                                                    </div>
                                                    <div class="flex gap-2 text-[13px]">
                                                        <button @click="openDebtAction(supplier, 'adjustment')" class="text-white bg-blue-600 rounded px-4 py-1.5 font-bold hover:bg-blue-700 shadow-sm flex items-center gap-1">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                                            Điều chỉnh
                                                        </button>
                                                        <button @click="openDebtAction(supplier, 'payment')" class="text-gray-700 bg-white border border-gray-300 rounded px-4 py-1.5 font-bold hover:bg-gray-50 shadow-sm flex items-center gap-1">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z"></path></svg>
                                                            Thanh toán
                                                        </button>
                                                        <button @click="openDebtAction(supplier, 'discount')" class="text-gray-700 bg-white border border-gray-300 rounded px-4 py-1.5 font-bold hover:bg-gray-50 shadow-sm flex items-center gap-1">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path></svg>
                                                            Chiết khấu TT
                                                        </button>
                                                    </div>
                                                </div>



                                            </template>
                                        </template>
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
                    <span class="font-bold">{{ suppliers.from || 0 }}</span> đến
                    <span class="font-bold">{{ suppliers.to || 0 }}</span> trong
                    tổng số
                    <span class="font-bold">{{ suppliers.total || 0 }}</span>
                    đối tác
                </div>
                <!-- Pagination -->
                <div
                    class="flex gap-1"
                    v-if="suppliers.links && suppliers.links.length > 3"
                >
                    <template
                        v-for="(link, index) in suppliers.links"
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
                        Thêm nhà cung cấp
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
                                        >Tên nhà cung cấp
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
                                        >Mã nhà cung cấp</label
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
                                    <input
                                        v-model="form.customer_group"
                                        type="text"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        placeholder="Chọn nhóm khách hàng"
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

                        <!-- Switch Is Customer -->
                        <div
                            class="bg-gray-50 border border-gray-200 rounded px-4 py-4 flex items-center justify-between"
                        >
                            <div>
                                <h3 class="font-bold text-[14px] text-gray-800">
                                    Là khách hàng
                                </h3>
                                <p class="text-[12px] text-gray-500 mt-0.5">
                                    Nhà cung cấp này đồng thời là một khách hàng
                                    trên hệ thống
                                </p>
                            </div>
                            <label
                                class="relative inline-flex items-center cursor-pointer"
                                @click.prevent="onToggleIsCustomer"
                            >
                                <input
                                    type="checkbox"
                                    :checked="form.is_customer"
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

        <!-- Debt Action Modal (Thanh toan / Dieu chinh / Chiet khau) -->
        <div v-if="showDebtModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="showDebtModal = false">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4">
                <div class="px-6 py-4 border-b flex items-center justify-between" :class="debtActionType === 'payment' ? 'bg-green-50' : debtActionType === 'discount' ? 'bg-orange-50' : 'bg-blue-50'">
                    <h3 class="text-lg font-bold" :class="debtActionType === 'payment' ? 'text-green-700' : debtActionType === 'discount' ? 'text-orange-700' : 'text-blue-700'">
                        {{ debtActionLabels[debtActionType] }}
                    </h3>
                    <button @click="showDebtModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div class="text-sm text-gray-600 bg-gray-50 rounded p-3 border">
                        <div class="flex justify-between">
                            <span>Nhà cung cấp:</span>
                            <span class="font-semibold text-gray-800">{{ debtActionSupplier?.name }}</span>
                        </div>
                        <div class="flex justify-between mt-1">
                            <span>Nợ hiện tại:</span>
                            <span class="font-bold text-red-600">{{ formatCurrency(debtActionSupplier?.supplier_debt_amount || 0) }}</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">
                            {{ debtActionType === 'payment' ? 'Số tiền thanh toán' : debtActionType === 'discount' ? 'Số tiền chiết khấu' : 'Nợ cuối mong muốn' }}
                            <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <MoneyInput v-model="debtAmount" input-class="w-full border border-gray-300 rounded-lg px-3 py-2 pr-8 text-sm text-right font-semibold focus:border-blue-500 outline-none" :placeholder="debtActionType === 'adjustment' ? 'Nhập giá trị nợ cuối (VD: 0)' : '0'" />
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">₫</span>
                        </div>
                        <p v-if="debtActionType === 'adjustment'" class="text-xs text-gray-400 mt-1">Nhập giá trị nợ cuối mong muốn. VD: nhập 0 để xóa nợ.</p>
                    </div>
                    <div v-if="debtActionType !== 'payment'">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">
                            {{ debtActionType === 'discount' ? 'Ngày chiết khấu' : 'Ngày điều chỉnh' }}
                        </label>
                        <DateTimePicker
                            v-model="debtDate"
                            placeholder="dd/MM/yyyy HH:mm"
                            input-class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none"
                        />
                    </div>
                    <div v-else>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Ngày thanh toán</label>
                        <DateTimePicker
                            v-model="debtDate"
                            placeholder="dd/MM/yyyy HH:mm"
                            input-class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Ghi chú</label>
                        <input v-model="debtNote" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" :placeholder="debtActionLabels[debtActionType]" />
                    </div>
                </div>
                <div class="flex justify-end gap-3 px-6 py-4 border-t">
                    <button @click="showDebtModal = false" class="px-5 py-2 border rounded-lg text-sm font-semibold text-gray-600 hover:bg-gray-50">Hủy</button>
                    <button @click="submitDebtAction" :disabled="(debtActionType !== 'adjustment' && !debtAmount) || (debtActionType === 'adjustment' && (debtAmount === null || debtAmount === '')) || debtSubmitting"
                        class="px-6 py-2 rounded-lg text-sm font-bold text-white disabled:opacity-50 flex items-center gap-2"
                        :class="debtActionType === 'payment' ? 'bg-green-600 hover:bg-green-700' : debtActionType === 'discount' ? 'bg-orange-600 hover:bg-orange-700' : 'bg-blue-600 hover:bg-blue-700'">
                        <svg v-if="debtSubmitting" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        {{ debtSubmitting ? 'Đang xử lý...' : 'Xác nhận' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- XÁC NHẬN GỘP NCC + KH (KiotViet style) -->
        <div v-if="customerConfirm.show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800">Xác nhận gộp nhà cung cấp và khách hàng</h3>
                    <button @click="cancelCustomerToggle" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <div class="text-sm font-bold text-gray-700 mb-2">Thông tin trước khi gộp:</div>
                        <div class="text-sm text-gray-600 space-y-1">
                            <div>- Nhà cung cấp <strong class="text-green-600">{{ form.code || '(Mã tự động)' }}</strong>:</div>
                            <div class="flex items-center gap-2 ml-4">
                                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                Nợ hiện tại: 0
                            </div>
                            <div class="mt-2">- Khách hàng: <span class="text-gray-400">(tạo mới)</span></div>
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
                        Hệ thống sẽ gộp công nợ, giao dịch nhà cung cấp và khách hàng. Thông tin <strong>Tên, Điện thoại, Địa chỉ...</strong> của khách hàng sẽ cập nhật theo nhà cung cấp.
                    </div>
                    <div class="text-sm text-gray-600 font-medium">
                        Bạn có chắc chắn muốn thực hiện?
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                    <button @click="confirmCustomerToggle" class="px-5 py-2 rounded text-white font-medium bg-blue-600 hover:bg-blue-700">
                        Đồng ý
                    </button>
                    <button @click="cancelCustomerToggle" class="px-5 py-2 border border-gray-300 rounded text-gray-700 font-medium hover:bg-gray-50">
                        Bỏ qua
                    </button>
                </div>
            </div>
        </div>

        <!-- MERGE CONFIRMATION DIALOG -->
        <div v-if="mergeModal.show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800">Xác nhận gộp nhà cung cấp và khách hàng</h3>
                    <button @click="mergeModal.show = false" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <div class="px-6 py-4 space-y-4">
                    <!-- Source info -->
                    <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                        <div class="text-sm text-gray-500 mb-1">Nhà cung cấp (nguồn gộp)</div>
                        <div class="font-bold text-gray-800">{{ mergeModal.source?.name }} <span class="text-gray-500 font-normal">{{ mergeModal.source?.code }}</span></div>
                        <div class="text-sm mt-1">Nợ NCC hiện tại: <span class="font-bold text-green-600">{{ formatCurrency(mergeModal.source?.supplier_debt_amount || 0) }}</span></div>
                    </div>

                    <!-- Search for target -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tìm khách hàng để gộp vào</label>
                        <input
                            v-model="mergeModal.searchQuery"
                            @input="searchMergeTarget"
                            type="text"
                            class="w-full border border-gray-300 rounded px-3 py-2 focus:border-blue-500 outline-none text-sm"
                            placeholder="Nhập tên, SĐT hoặc mã KH..."
                        />
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
                                    <span v-else-if="r.is_customer" class="ml-2 text-xs bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded">KH</span>
                                    <span v-else-if="r.is_supplier" class="ml-2 text-xs bg-green-100 text-green-600 px-1.5 py-0.5 rounded">NCC</span>
                                </div>
                                <span class="text-gray-500 text-xs">Nợ KH: {{ formatCurrency(r.debt_amount || 0) }}</span>
                            </button>
                        </div>
                        <div v-if="mergeModal.searching" class="text-sm text-gray-400 mt-1">Đang tìm...</div>
                    </div>

                    <!-- Selected target -->
                    <div v-if="mergeModal.selected" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="text-sm text-gray-500 mb-1">Khách hàng (đích gộp)</div>
                        <div class="font-bold text-gray-800">{{ mergeModal.selected.name }} <span class="text-gray-500 font-normal">{{ mergeModal.selected.code }}</span></div>
                        <div class="text-sm mt-1">Nợ KH hiện tại: <span class="font-bold text-blue-600">{{ formatCurrency(mergeModal.selected.debt_amount || 0) }}</span></div>
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
                        <div class="text-xs text-gray-500 mt-2">NCC <strong>{{ mergeModal.source?.name }}</strong> sẽ bị xóa, mọi giao dịch sẽ chuyển sang <strong>{{ mergeModal.selected.name }}</strong>.</div>
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
                        <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-center">
                            <div class="text-xs text-gray-500 mb-1">Nợ phải trả (Nợ NCC)</div>
                            <div class="text-lg font-bold text-red-600">{{ formatCurrency(offsetModal.payable) }}</div>
                        </div>
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-center">
                            <div class="text-xs text-gray-500 mb-1">Nợ phải thu (KH nợ)</div>
                            <div class="text-lg font-bold text-blue-600">{{ formatCurrency(offsetModal.receivable) }}</div>
                        </div>
                    </div>
                    <div class="bg-purple-50 border border-purple-200 rounded-lg p-3 text-center">
                        <div class="text-xs text-gray-500 mb-1">Số tiền cấn bằng tối đa</div>
                        <div class="text-lg font-bold text-purple-700">{{ formatCurrency(offsetModal.maxOffset) }}</div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền cấn bằng</label>
                        <MoneyInput v-model="offsetForm.amount" placeholder="Nhập số tiền cấn bằng"
                            input-class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-purple-500 focus:border-purple-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
                        <textarea v-model="offsetForm.note" rows="2"
                            class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-purple-500 focus:border-purple-500"
                            placeholder="Ghi chú (không bắt buộc)"></textarea>
                    </div>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm">
                        <div class="font-bold text-gray-700 mb-2">Kết quả sau cấn bằng:</div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>Nợ phải trả còn:</div>
                            <div class="font-bold text-right text-red-600">{{ formatCurrency(Math.max(0, offsetModal.payable - (offsetForm.amount || 0))) }}</div>
                            <div>Nợ phải thu còn:</div>
                            <div class="font-bold text-right text-blue-600">{{ formatCurrency(Math.max(0, offsetModal.receivable - (offsetForm.amount || 0))) }}</div>
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

        <!-- Purchase Detail Modal -->
        <div v-if="purchaseDetail.show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="purchaseDetail.show = false">
            <div class="bg-white rounded-lg shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
                <div v-if="purchaseDetail.loading" class="p-12 text-center text-gray-400">Đang tải...</div>
                <template v-else-if="purchaseDetail.data">
                    <!-- Header -->
                    <div class="flex items-center justify-between px-6 py-4 border-b">
                        <div>
                            <h3 class="text-lg font-bold text-gray-800">{{ purchaseDetail.data.code }}</h3>
                            <span class="inline-block mt-1 px-2 py-0.5 rounded text-xs font-semibold"
                                :class="purchaseDetail.data.status === 'completed' ? 'bg-green-100 text-green-700' : purchaseDetail.data.status === 'cancelled' ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700'">
                                {{ purchaseDetail.data.status_label }}
                            </span>
                        </div>
                        <button @click="purchaseDetail.show = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    </div>
                    <!-- Meta info -->
                    <div class="px-6 py-3 grid grid-cols-2 gap-x-8 gap-y-2 text-sm border-b bg-gray-50">
                        <div><span class="text-gray-500">Người tạo:</span> <span class="font-medium">{{ purchaseDetail.data.user_name }}</span></div>
                        <div v-if="purchaseDetail.data.employee_name"><span class="text-gray-500">Người nhận:</span> <span class="font-medium">{{ purchaseDetail.data.employee_name }}</span></div>
                        <div><span class="text-gray-500">Ngày nhập:</span> <span class="font-medium">{{ purchaseDetail.data.purchase_date }}</span></div>
                        <div><span class="text-gray-500">NCC:</span> <span class="font-medium">{{ purchaseDetail.data.supplier_name }}</span> <span class="text-gray-400">{{ purchaseDetail.data.supplier_code }}</span></div>
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
                                <tr v-for="(item, idx) in purchaseDetail.data.items" :key="idx" class="hover:bg-gray-50">
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
                                <div class="flex justify-between"><span class="text-gray-500">Số lượng mặt hàng:</span><span class="font-medium">{{ purchaseDetail.data.items.length }}</span></div>
                                <div class="flex justify-between"><span class="text-gray-500">Tổng tiền hàng:</span><span class="font-medium">{{ formatCurrency(purchaseDetail.data.total_amount) }}</span></div>
                                <div v-if="purchaseDetail.data.discount > 0" class="flex justify-between"><span class="text-gray-500">Giảm giá:</span><span class="font-medium text-red-500">-{{ formatCurrency(purchaseDetail.data.discount) }}</span></div>
                                <div class="flex justify-between font-bold text-base border-t pt-1"><span>Cần trả NCC:</span><span class="text-blue-600">{{ formatCurrency(purchaseDetail.data.total_amount - (purchaseDetail.data.discount || 0)) }}</span></div>
                                <div class="flex justify-between"><span class="text-gray-500">Đã trả NCC:</span><span class="font-medium">{{ formatCurrency(purchaseDetail.data.paid_amount) }}</span></div>
                                <div v-if="purchaseDetail.data.debt_amount > 0" class="flex justify-between"><span class="text-gray-500">Còn nợ:</span><span class="font-medium text-red-500">{{ formatCurrency(purchaseDetail.data.debt_amount) }}</span></div>
                            </div>
                        </div>
                    </div>
                    <!-- Note & actions -->
                    <div class="px-6 py-3 border-t flex items-center justify-between">
                        <div v-if="purchaseDetail.data.note" class="text-sm text-gray-500"><span class="font-medium">Ghi chú:</span> {{ purchaseDetail.data.note }}</div>
                        <div v-else></div>
                        <a :href="`/purchases/${purchaseDetail.data.id}`" class="px-4 py-2 bg-blue-600 text-white rounded text-sm font-medium hover:bg-blue-700">Mở phiếu</a>
                    </div>
                </template>
                <div v-else class="p-12 text-center text-gray-400">Không tìm thấy thông tin phiếu nhập</div>
            </div>
        </div>

        <!-- CB Toast (KiotViet style) -->
        <transition name="fade">
            <div v-if="cbToast.show" class="fixed bottom-6 right-6 z-[100] flex items-start gap-3 bg-orange-50 border border-orange-200 rounded-lg shadow-lg px-4 py-3 max-w-sm">
                <svg class="w-5 h-5 text-orange-500 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <span class="text-sm text-orange-800">Giao dịch này thuộc khách hàng, vui lòng sang bên khách hàng để xử lý</span>
            </div>
        </transition>

        <!-- ════ HOTFIX 24.8 — Edit supplier modal ════ -->
        <div v-if="showEditModal && editingSupplier" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[90vh] flex flex-col">
                <div class="flex items-center justify-between px-6 py-4 border-b">
                    <h3 class="text-lg font-bold text-gray-800">Cập nhật nhà cung cấp</h3>
                    <button @click="closeEditModal" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-4 grid grid-cols-2 gap-4 text-sm">
                    <div class="col-span-2 grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Mã NCC</label>
                            <input v-model="editForm.code" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500" />
                            <p v-if="editForm.errors.code" class="mt-1 text-xs text-red-600">{{ editForm.errors.code }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Tên NCC <span class="text-red-500">*</span></label>
                            <input v-model="editForm.name" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500" />
                            <p v-if="editForm.errors.name" class="mt-1 text-xs text-red-600">{{ editForm.errors.name }}</p>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Điện thoại</label>
                        <input v-model="editForm.phone" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500" />
                        <p v-if="editForm.errors.phone" class="mt-1 text-xs text-red-600">{{ editForm.errors.phone }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Điện thoại 2</label>
                        <input v-model="editForm.phone2" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Email</label>
                        <input v-model="editForm.email" type="email" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Mã số thuế</label>
                        <input v-model="editForm.tax_code" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500" />
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Địa chỉ</label>
                        <input v-model="editForm.address" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Tỉnh/Thành</label>
                        <input v-model="editForm.city" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Quận/Huyện</label>
                        <input v-model="editForm.district" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Phường/Xã</label>
                        <input v-model="editForm.ward" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Nhóm NCC</label>
                        <input v-model="editForm.customer_group" type="text" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500" />
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Ghi chú</label>
                        <textarea v-model="editForm.note" rows="2" class="w-full border border-gray-300 rounded px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500"></textarea>
                    </div>
                    <div class="col-span-2 border-t pt-3 mt-1">
                        <label class="inline-flex items-center gap-2">
                            <input v-model="editForm.is_customer" type="checkbox" class="rounded text-blue-600 focus:ring-blue-500" />
                            <span class="text-gray-700">Vừa là khách hàng</span>
                        </label>
                    </div>
                </div>

                <div class="px-6 py-3 border-t bg-gray-50 flex justify-end gap-2 rounded-b-lg">
                    <button @click="closeEditModal" :disabled="editForm.processing" class="px-4 py-2 border border-gray-300 rounded text-sm font-medium text-gray-700 hover:bg-gray-100 disabled:opacity-50">Đóng</button>
                    <button
                        @click="submitEdit"
                        :disabled="editForm.processing"
                        class="px-5 py-2 bg-blue-600 text-white rounded text-sm font-bold hover:bg-blue-700 disabled:opacity-50"
                    >{{ editForm.processing ? 'Đang lưu...' : 'Lưu' }}</button>
                </div>
            </div>
        </div>

        <!-- ════ HOTFIX 24.8 — Deactivate confirm modal ════ -->
        <div v-if="deactivateConfirm.show && deactivateConfirm.supplier" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
                <div class="px-6 py-4 border-b">
                    <h3 class="text-lg font-bold text-gray-800">Ngừng hoạt động NCC</h3>
                </div>
                <div class="px-6 py-4 space-y-3 text-sm text-gray-700">
                    <div class="text-base font-semibold text-gray-900">{{ deactivateConfirm.supplier.name }}</div>
                    <p>
                        Ngừng hoạt động NCC sẽ <strong>không xóa</strong> lịch sử nhập/trả hàng và công nợ.
                        NCC sẽ không xuất hiện cho giao dịch mới.
                    </p>
                    <div
                        v-if="(Number(deactivateConfirm.supplier.supplier_debt_amount) || 0) > 0"
                        class="px-3 py-2 bg-amber-50 border border-amber-200 rounded text-amber-800 text-xs"
                    >
                        NCC còn nợ phải trả: <strong class="tabular-nums">{{ Number(deactivateConfirm.supplier.supplier_debt_amount).toLocaleString('vi-VN') }}đ</strong>.
                        Bạn vẫn có thể thanh toán/đối chiếu sau khi ngừng hoạt động.
                    </div>
                </div>
                <div class="px-6 py-3 border-t bg-gray-50 flex justify-end gap-2 rounded-b-lg">
                    <button @click="closeDeactivateConfirm" :disabled="deactivateConfirm.processing" class="px-4 py-2 border border-gray-300 rounded text-sm font-medium text-gray-700 hover:bg-gray-100 disabled:opacity-50">Đóng</button>
                    <button
                        @click="submitDeactivate"
                        :disabled="deactivateConfirm.processing"
                        class="px-5 py-2 bg-red-600 text-white rounded text-sm font-bold hover:bg-red-700 disabled:opacity-50"
                    >{{ deactivateConfirm.processing ? 'Đang xử lý...' : 'Xác nhận ngừng' }}</button>
                </div>
            </div>
        </div>

        <!-- HOTFIX 24.17 — Modal xuất file công nợ NCC -->
        <div v-if="showDebtExportModal"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/40"
             @click.self="closeDebtExportModal">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between px-6 py-4 border-b">
                    <h2 class="text-lg font-bold text-gray-800">Xuất file công nợ</h2>
                    <button @click="closeDebtExportModal" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
                </div>

                <div class="px-6 py-5 space-y-5 text-sm">
                    <div v-if="debtExportSupplier" class="bg-gray-50 px-3 py-2 rounded">
                        <span class="text-gray-500">Nhà cung cấp:</span>
                        <span class="font-semibold text-gray-800 ml-1">{{ debtExportSupplier.name }}</span>
                        <span v-if="debtExportSupplier.code" class="text-gray-400 ml-1">({{ debtExportSupplier.code }})</span>
                    </div>

                    <!-- Thời gian -->
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 mb-2">Thời gian</h3>
                        <div class="flex flex-wrap gap-2">
                            <button v-for="p in debtExportPresets" :key="p.value"
                                type="button"
                                @click="debtExportForm.date_preset = p.value"
                                :class="debtExportForm.date_preset === p.value
                                    ? 'bg-blue-600 text-white border-blue-600'
                                    : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                                class="px-3 py-1.5 text-xs font-semibold border rounded transition">
                                {{ p.label }}
                            </button>
                        </div>
                        <div v-if="debtExportForm.date_preset === 'custom'" class="mt-3 grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Từ ngày</label>
                                <input v-model="debtExportForm.date_from" type="text" inputmode="numeric"
                                    placeholder="dd/mm/yyyy" maxlength="10"
                                    class="w-full border rounded px-3 py-2 text-sm focus:border-blue-500 outline-none"
                                    :class="debtExportForm.date_from && !parseVietnameseDateToIso(debtExportForm.date_from)
                                        ? 'border-red-400' : 'border-gray-300'" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Đến ngày</label>
                                <input v-model="debtExportForm.date_to" type="text" inputmode="numeric"
                                    placeholder="dd/mm/yyyy" maxlength="10"
                                    class="w-full border rounded px-3 py-2 text-sm focus:border-blue-500 outline-none"
                                    :class="debtExportForm.date_to && !parseVietnameseDateToIso(debtExportForm.date_to)
                                        ? 'border-red-400' : 'border-gray-300'" />
                            </div>
                            <p v-if="debtExportForm.date_preset === 'custom' && !debtExportCustomDatesValid"
                               class="col-span-2 text-xs text-red-500 -mt-1">
                                Nhập ngày theo định dạng <code>dd/mm/yyyy</code> (ví dụ <code>30/04/2026</code>).
                            </p>
                        </div>
                    </div>

                    <!-- Thông tin xuất file -->
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 mb-2">Thông tin xuất file</h3>
                        <div class="bg-gray-50 border border-gray-200 rounded px-3 py-2 text-xs text-gray-600 mb-3">
                            <span class="font-semibold text-gray-700">Tổng quan luôn có:</span>
                            Thời gian, Mã chứng từ, Loại, Giá trị, Nợ cần trả nhà cung cấp, Ghi chú.
                        </div>
                        <label class="flex items-center gap-2 mb-2 cursor-pointer">
                            <input type="checkbox" v-model="debtExportForm.include_detail" class="rounded" />
                            <span class="font-semibold text-gray-700">Chi tiết từng hàng giao dịch</span>
                        </label>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 pl-6"
                             :class="{ 'opacity-50 pointer-events-none': !debtExportForm.include_detail }">
                            <label v-for="o in debtExportColumnOptions" :key="o.key"
                                class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                                <input type="checkbox" v-model="debtExportForm.columns[o.key]" class="rounded" />
                                <span>{{ o.label }}</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3 px-6 py-4 border-t bg-gray-50 rounded-b-xl">
                    <button @click="closeDebtExportModal"
                        class="px-5 py-2 border border-gray-300 rounded text-sm font-semibold hover:bg-gray-100">
                        Bỏ qua
                    </button>
                    <button @click="confirmDebtExport"
                        :disabled="!debtExportCustomDatesValid"
                        class="px-5 py-2 bg-blue-600 text-white rounded text-sm font-semibold hover:bg-blue-700 disabled:opacity-50">
                        Đồng ý
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
