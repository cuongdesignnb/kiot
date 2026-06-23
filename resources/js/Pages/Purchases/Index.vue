<script setup>
import { formatVND as formatCurrency } from '@/utils/money';
import { ref, watch, computed } from "vue";
import { Head, router, Link } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import SortableHeader from "@/Components/SortableHeader.vue";
import SidebarFilter from "@/Components/Filters/SidebarFilter.vue";
import { useFilters } from "@/composables/useFilters.js";

const props = defineProps({
    purchases: Object,
    filters: Object,
    summary: Object,
    suppliers: Array,
    employees: Array,
    filterOptions: Object,
});

// â”€â”€ Filters â”€â”€
const { filters, setSort, reset } = useFilters({
    initial: props.filters,
    route: "/purchases",
    defaults: { date_filter: "this_month" },
});

const allStatuses = computed(() => props.filterOptions?.statuses || [
    { value: "draft", label: "Phiáº¿u táº¡m" },
    { value: "completed", label: "HoÃ n thÃ nh" },
    { value: "returned", label: "ÄÃ£ tráº£ hÃ ng" },
    { value: "cancelled", label: "ÄÃ£ há»§y" },
]);

const sidebarConfig = computed(() => [
    {
        key: "date",
        type: "dateRange",
        label: "Thá»i gian",
        fields: { filter: "date_filter", from: "date_from", to: "date_to" },
        zone: "quick",
    },
    {
        key: "branch_id",
        type: "select",
        label: "Chi nhÃ¡nh",
        options: (props.filterOptions?.branches || []).map((b) => ({ value: String(b.id), label: b.name })),
        placeholder: "-- Táº¥t cáº£ chi nhÃ¡nh --",
        zone: "quick",
    },
    {
        key: "status",
        type: "checkbox",
        label: "Tráº¡ng thÃ¡i",
        options: allStatuses.value,
        zone: "main",
    },
    {
        key: "supplier_id",
        type: "select",
        label: "NhÃ  cung cáº¥p",
        options: (props.filterOptions?.suppliers || props.suppliers || []).map((s) => ({ value: s.value ?? s.id, label: s.label ?? s.name })),
        placeholder: "-- Táº¥t cáº£ NCC --",
        zone: "main",
    },
    {
        key: "has_debt",
        type: "select",
        label: "CÃ´ng ná»£ NCC",
        options: props.filterOptions?.debtOptions || [],
        placeholder: "-- Táº¥t cáº£ --",
        zone: "main",
    },
    {
        key: "created_by",
        type: "select",
        label: "NgÆ°á»i táº¡o",
        options: (props.filterOptions?.employees || props.employees || []).map((e) => ({ value: e.value ?? e.id, label: e.label ?? e.name })),
        placeholder: "-- Táº¥t cáº£ --",
        zone: "advanced",
    },
    {
        key: "payment_method",
        type: "select",
        label: "PhÆ°Æ¡ng thá»©c TT",
        options: props.filterOptions?.paymentMethods || [],
        placeholder: "-- Táº¥t cáº£ --",
        zone: "advanced",
    },
]);

const handleSort = (field, direction) => setSort(field, direction);

const formatStatus = (val) => {
    const s = allStatuses.value.find((x) => x.value === val);
    return s ? s.label : val;
};

// â”€â”€ Expand â”€â”€
const expandedRows = ref([]);
const toggleExpand = (id) => {
    const index = expandedRows.value.indexOf(id);
    if (index > -1) expandedRows.value.splice(index, 1);
    else expandedRows.value.push(id);
};
const isExpanded = (id) => expandedRows.value.includes(id);

const goToDetail = (id) => router.visit(`/purchases/${id}`);

// â”€â”€ Column Toggle â”€â”€
const allColumns = [
    { key: 'code', label: 'MÃ£ nháº­p hÃ ng', group: 'left' },
    { key: 'purchase_order_code', label: 'MÃ£ Ä‘áº·t hÃ ng nháº­p', group: 'left' },
    { key: 'return_code', label: 'MÃ£ tráº£ hÃ ng nháº­p', group: 'left' },
    { key: 'time', label: 'Thá»i gian', group: 'left' },
    { key: 'created_time', label: 'Thá»i gian táº¡o', group: 'left' },
    { key: 'updated_at', label: 'NgÃ y cáº­p nháº­t', group: 'left' },
    { key: 'supplier_code', label: 'MÃ£ NCC', group: 'left' },
    { key: 'supplier_name', label: 'NhÃ  cung cáº¥p', group: 'left' },
    { key: 'branch', label: 'Chi nhÃ¡nh', group: 'left' },
    { key: 'importer', label: 'NgÆ°á»i nháº­p', group: 'left' },
    { key: 'creator', label: 'NgÆ°á»i táº¡o', group: 'left' },
    { key: 'total_quantity', label: 'Tá»•ng sá»‘ lÆ°á»£ng', group: 'right' },
    { key: 'item_count', label: 'Sá»‘ lÆ°á»£ng máº·t hÃ ng', group: 'right' },
    { key: 'total_amount', label: 'Tá»•ng tiá»n hÃ ng', group: 'right' },
    { key: 'discount', label: 'Giáº£m giÃ¡', group: 'right' },
    { key: 'other_cost', label: 'Chi phÃ­ nháº­p tráº£ NCC', group: 'right' },
    { key: 'need_pay', label: 'Cáº§n tráº£ NCC', group: 'right' },
    { key: 'payment_discount', label: 'Chiáº¿t kháº¥u thanh toÃ¡n', group: 'right' },
    { key: 'paid', label: 'Tiá»n Ä‘Ã£ tráº£ NCC', group: 'right' },
    { key: 'other_import_cost', label: 'Chi phÃ­ nháº­p khÃ¡c', group: 'right' },
    { key: 'note', label: 'Ghi chÃº', group: 'right' },
    { key: 'status', label: 'Tráº¡ng thÃ¡i', group: 'right' },
];

const defaultColumns = ['code', 'time', 'supplier_code', 'supplier_name', 'need_pay', 'status'];
const savedCols = localStorage.getItem('purchase_columns');
const visibleColumns = ref(savedCols ? JSON.parse(savedCols) : [...defaultColumns]);
const showColumnToggle = ref(false);

const toggleColumn = (key) => {
    const idx = visibleColumns.value.indexOf(key);
    if (idx > -1) visibleColumns.value.splice(idx, 1);
    else visibleColumns.value.push(key);
    localStorage.setItem('purchase_columns', JSON.stringify(visibleColumns.value));
};

const isColVisible = (key) => visibleColumns.value.includes(key);
const leftColumns = computed(() => allColumns.filter(c => c.group === 'left'));
const rightColumns = computed(() => allColumns.filter(c => c.group === 'right'));
const totalVisibleCols = computed(() => visibleColumns.value.length + 2); // +2 for checkbox & star

// â”€â”€ Helpers â”€â”€

const formatDate = (d) => d ? new Date(d).toLocaleString("vi-VN", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "";
const formatDateShort = (d) => d ? new Date(d).toLocaleDateString("vi-VN") : "";

const getItemCount = (order) => order.items?.length || 0;
const getTotalQty = (order) => order.items?.reduce((s, i) => s + (i.quantity || 0), 0) || 0;
const getNeedPay = (order) => (order.total_amount || 0) - (order.discount || 0);

const printPurchase = (order) => {
    window.open(`/purchases/${order.id}/print`, "_blank", "width=400,height=600");
};

const cancelPurchase = (order) => {
    if (!confirm(`Báº¡n cÃ³ cháº¯c muá»‘n há»§y phiáº¿u nháº­p hÃ ng ${order.code}?`)) return;
    const cancelReason = window.prompt('Nhập lý do hủy phiếu nhập:');
    if (cancelReason === null) return;
    if (cancelReason.trim().length < 5) {
        alert('Lý do hủy phải có ít nhất 5 ký tự.');
        return;
    }
    router.delete(`/purchases/${order.id}`, {
        data: { cancel_reason: cancelReason.trim() },
        preserveState: false,
    });
};
</script>

<template>
    <Head title="Nháº­p hÃ ng - KiotViet Clone" />
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

        <div class="bg-white h-full flex flex-col pt-3">
            <!-- Header -->
            <div class="flex items-center justify-between px-4 pb-3 border-b border-gray-200">
                <div class="text-2xl font-bold text-gray-800">Nháº­p hÃ ng</div>

                <div class="flex-1 max-w-[400px] ml-6 relative">
                    <svg class="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    <input type="text" v-model="filters.search" placeholder="Theo mÃ£ phiáº¿u nháº­p, mÃ£ Ä‘áº·t hÃ ng, NCC" class="w-full pl-9 pr-8 py-1.5 focus:outline-none border border-gray-300 rounded text-sm placeholder-gray-400" />
                </div>

                <div class="flex gap-2 ml-auto items-center">
                    <Link href="/purchases/create" class="bg-white text-green-600 border border-green-600 px-3 py-1.5 text-sm font-medium rounded hover:bg-green-50 transition flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        Nháº­p hÃ ng
                    </Link>
                    <ExcelButtons export-url="/purchases/export" />

                    <!-- Column toggle button -->
                    <div class="relative">
                        <button @click="showColumnToggle = !showColumnToggle" class="bg-white text-gray-600 border border-gray-300 px-2.5 py-1.5 rounded hover:bg-gray-50" title="Chá»n cá»™t hiá»ƒn thá»‹">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg>
                        </button>
                        <!-- Column toggle panel -->
                        <div v-if="showColumnToggle" class="absolute right-0 top-full mt-1 bg-white border border-gray-300 rounded-lg shadow-xl z-50 p-4 w-[520px]">
                            <div class="grid grid-cols-2 gap-x-8 gap-y-1.5">
                                <div>
                                    <label v-for="col in leftColumns" :key="col.key" class="flex items-center gap-2 cursor-pointer text-sm text-gray-700 hover:text-gray-900 py-0.5">
                                        <input type="checkbox" :checked="isColVisible(col.key)" @change="toggleColumn(col.key)" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4" />
                                        {{ col.label }}
                                    </label>
                                </div>
                                <div>
                                    <label v-for="col in rightColumns" :key="col.key" class="flex items-center gap-2 cursor-pointer text-sm text-gray-700 hover:text-gray-900 py-0.5">
                                        <input type="checkbox" :checked="isColVisible(col.key)" @change="toggleColumn(col.key)" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4" />
                                        {{ col.label }}
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Overlay to close column toggle -->
            <div v-if="showColumnToggle" class="fixed inset-0 z-40" @click="showColumnToggle = false"></div>

            <!-- Table -->
            <div class="flex-1 overflow-auto bg-gray-50/30">
                <table class="w-full text-[13px] text-left whitespace-nowrap">
                    <thead class="font-bold text-gray-700 bg-[#f4f6f8] border-b border-gray-200 sticky top-0 z-10 shadow-sm">
                        <tr>
                            <th class="px-3 py-2 w-10 text-center"><input type="checkbox" class="rounded border-gray-300" /></th>
                            <th class="px-3 py-2 text-center w-10">
                                <svg class="w-4 h-4 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path></svg>
                            </th>
                            <SortableHeader v-if="isColVisible('code')" label="MÃ£ nháº­p hÃ ng" field="code" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" class="px-2 py-2" @sort="handleSort" />
                            <th v-if="isColVisible('purchase_order_code')" class="px-2 py-2">MÃ£ Ä‘áº·t hÃ ng nháº­p</th>
                            <th v-if="isColVisible('return_code')" class="px-2 py-2">MÃ£ tráº£ hÃ ng nháº­p</th>
                            <SortableHeader v-if="isColVisible('time')" label="Thá»i gian" field="purchase_date" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" class="px-2 py-2" @sort="handleSort" />
                            <th v-if="isColVisible('created_time')" class="px-2 py-2">Thá»i gian táº¡o</th>
                            <th v-if="isColVisible('updated_at')" class="px-2 py-2">NgÃ y cáº­p nháº­t</th>
                            <th v-if="isColVisible('supplier_code')" class="px-2 py-2">MÃ£ NCC</th>
                            <th v-if="isColVisible('supplier_name')" class="px-2 py-2">NhÃ  cung cáº¥p</th>
                            <th v-if="isColVisible('branch')" class="px-2 py-2">Chi nhÃ¡nh</th>
                            <th v-if="isColVisible('importer')" class="px-2 py-2">NgÆ°á»i nháº­p</th>
                            <th v-if="isColVisible('creator')" class="px-2 py-2">NgÆ°á»i táº¡o</th>
                            <th v-if="isColVisible('total_quantity')" class="px-4 py-2 text-right">Tá»•ng sá»‘ lÆ°á»£ng</th>
                            <th v-if="isColVisible('item_count')" class="px-4 py-2 text-right">SL máº·t hÃ ng</th>
                            <SortableHeader v-if="isColVisible('total_amount')" label="Tá»•ng tiá»n hÃ ng" field="total_amount" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <SortableHeader v-if="isColVisible('discount')" label="Giáº£m giÃ¡" field="discount" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <th v-if="isColVisible('other_cost')" class="px-4 py-2 text-right">CP nháº­p tráº£ NCC</th>
                            <SortableHeader v-if="isColVisible('need_pay')" label="Cáº§n tráº£ NCC" field="need_pay" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <th v-if="isColVisible('payment_discount')" class="px-4 py-2 text-right">CK thanh toÃ¡n</th>
                            <SortableHeader v-if="isColVisible('paid')" label="Tiá»n Ä‘Ã£ tráº£ NCC" field="paid_amount" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <th v-if="isColVisible('other_import_cost')" class="px-4 py-2 text-right">CP nháº­p khÃ¡c</th>
                            <th v-if="isColVisible('note')" class="px-2 py-2">Ghi chÃº</th>
                            <SortableHeader v-if="isColVisible('status')" label="Tráº¡ng thÃ¡i" field="status" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="center" class="px-4 py-2 text-center w-24" @sort="handleSort" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <!-- Summary row -->
                        <tr class="bg-gray-50 border-b border-gray-200 font-semibold text-sm">
                            <td></td>
                            <td></td>
                            <td v-if="isColVisible('code')"></td>
                            <td v-if="isColVisible('purchase_order_code')"></td>
                            <td v-if="isColVisible('return_code')"></td>
                            <td v-if="isColVisible('time')"></td>
                            <td v-if="isColVisible('created_time')"></td>
                            <td v-if="isColVisible('updated_at')"></td>
                            <td v-if="isColVisible('supplier_code')"></td>
                            <td v-if="isColVisible('supplier_name')"></td>
                            <td v-if="isColVisible('branch')"></td>
                            <td v-if="isColVisible('importer')"></td>
                            <td v-if="isColVisible('creator')"></td>
                            <td v-if="isColVisible('total_quantity')" class="px-4 py-2 text-right text-gray-700">{{ formatCurrency(summary?.total_items) }}</td>
                            <td v-if="isColVisible('item_count')"></td>
                            <td v-if="isColVisible('total_amount')" class="px-4 py-2 text-right text-gray-700">{{ formatCurrency(summary?.total_amount) }}</td>
                            <td v-if="isColVisible('discount')" class="px-4 py-2 text-right text-gray-700">{{ formatCurrency(summary?.total_discount) }}</td>
                            <td v-if="isColVisible('other_cost')"></td>
                            <td v-if="isColVisible('need_pay')" class="px-4 py-2 text-right text-gray-700">{{ formatCurrency((summary?.total_amount || 0) - (summary?.total_discount || 0)) }}</td>
                            <td v-if="isColVisible('payment_discount')"></td>
                            <td v-if="isColVisible('paid')" class="px-4 py-2 text-right text-green-600">{{ formatCurrency(summary?.total_paid) }}</td>
                            <td v-if="isColVisible('other_import_cost')"></td>
                            <td v-if="isColVisible('note')"></td>
                            <td v-if="isColVisible('status')"></td>
                        </tr>

                        <tr v-if="purchases.data.length === 0">
                            <td :colspan="totalVisibleCols" class="p-16 text-center text-gray-500">
                                <h3 class="text-[15px] font-bold text-gray-800 mb-1">KhÃ´ng tÃ¬m tháº¥y káº¿t quáº£</h3>
                                <p class="text-[13px]">KhÃ´ng tÃ¬m tháº¥y phiáº¿u nháº­p hÃ ng nÃ o phÃ¹ há»£p.</p>
                            </td>
                        </tr>

                        <template v-for="order in purchases.data" :key="order.id">
                            <tr @click="goToDetail(order.id)" class="hover:bg-blue-50/40 cursor-pointer transition-colors" :class="{ 'bg-[#f4f7fe]': isExpanded(order.id), 'border-l-2 border-l-green-500': isExpanded(order.id) }">
                                <td class="px-3 py-2 text-center" @click.stop><input type="checkbox" class="rounded border-gray-300" /></td>
                                <td class="px-3 py-2 text-center text-gray-300">
                                    <svg class="w-4 h-4 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path></svg>
                                </td>
                                <td v-if="isColVisible('code')" class="px-2 py-2 text-blue-600 font-medium">{{ order.code }}</td>
                                <td v-if="isColVisible('purchase_order_code')" class="px-2 py-2 text-blue-600">{{ order.purchase_order_code || '' }}</td>
                                <td v-if="isColVisible('return_code')" class="px-2 py-2 text-blue-600">{{ order.return_code || '' }}</td>
                                <td v-if="isColVisible('time')" class="px-2 py-2">{{ formatDate(order.purchase_date || order.created_at) }}</td>
                                <td v-if="isColVisible('created_time')" class="px-2 py-2">{{ formatDate(order.created_at) }}</td>
                                <td v-if="isColVisible('updated_at')" class="px-2 py-2">{{ formatDateShort(order.updated_at) }}</td>
                                <td v-if="isColVisible('supplier_code')" class="px-2 py-2">{{ order.supplier?.code || '' }}</td>
                                <td v-if="isColVisible('supplier_name')" class="px-2 py-2">{{ order.supplier?.name || 'KhÃ¡ch láº»' }}</td>
                                <td v-if="isColVisible('branch')" class="px-2 py-2">Chi nhÃ¡nh trung tÃ¢m</td>
                                <td v-if="isColVisible('importer')" class="px-2 py-2">{{ order.employee?.name || order.user?.name || '' }}</td>
                                <td v-if="isColVisible('creator')" class="px-2 py-2">{{ order.employee?.name || order.user?.name || '' }}</td>
                                <td v-if="isColVisible('total_quantity')" class="px-4 py-2 text-right">{{ getTotalQty(order) }}</td>
                                <td v-if="isColVisible('item_count')" class="px-4 py-2 text-right">{{ getItemCount(order) }}</td>
                                <td v-if="isColVisible('total_amount')" class="px-4 py-2 text-right font-medium">{{ formatCurrency(order.total_amount) }}</td>
                                <td v-if="isColVisible('discount')" class="px-4 py-2 text-right">{{ formatCurrency(order.discount) }}</td>
                                <td v-if="isColVisible('other_cost')" class="px-4 py-2 text-right">0</td>
                                <td v-if="isColVisible('need_pay')" class="px-4 py-2 text-right font-medium">{{ formatCurrency(getNeedPay(order)) }}</td>
                                <td v-if="isColVisible('payment_discount')" class="px-4 py-2 text-right">0</td>
                                <td v-if="isColVisible('paid')" class="px-4 py-2 text-right font-medium text-green-600">{{ formatCurrency(order.paid_amount) }}</td>
                                <td v-if="isColVisible('other_import_cost')" class="px-4 py-2 text-right">0</td>
                                <td v-if="isColVisible('note')" class="px-2 py-2 max-w-[200px] truncate">{{ order.note || '' }}</td>
                                <td v-if="isColVisible('status')" class="px-4 py-2 text-center">
                                    <span class="inline-block px-2 text-[11px] py-0.5 rounded border font-medium" :class="{
                                        'bg-green-50 text-green-700 border-green-200': order.status === 'completed',
                                        'bg-gray-50 text-gray-500 border-gray-200': order.status === 'draft',
                                        'bg-orange-50 text-orange-600 border-orange-200': order.status === 'returned',
                                        'bg-red-50 text-red-600 border-red-200': order.status === 'cancelled',
                                    }">{{ formatStatus(order.status) }}</span>
                                </td>
                            </tr>

                            <!-- Expanded detail row -->
                            <tr v-if="isExpanded(order.id)" class="border-b-4 border-blue-50">
                                <td :colspan="totalVisibleCols" class="p-0 border-0 bg-white shadow-[inset_0_2px_4px_rgba(0,0,0,0.02)]">
                                    <div class="p-4 flex gap-6 w-full border-t border-blue-100">
                                        <div class="flex-1">
                                            <table class="w-full text-left bg-gray-50/50 border border-gray-200">
                                                <thead class="text-gray-500 bg-gray-100 border-b border-gray-200">
                                                    <tr>
                                                        <th class="p-2 font-medium w-12 text-center">STT</th>
                                                        <th class="p-2 font-medium">MÃ£ hÃ ng</th>
                                                        <th class="p-2 font-medium">TÃªn hÃ ng hÃ³a</th>
                                                        <th class="p-2 font-medium text-center">Sá»‘ lÆ°á»£ng</th>
                                                        <th class="p-2 font-medium text-right">ÄÆ¡n giÃ¡</th>
                                                        <th class="p-2 font-medium text-right">Giáº£m giÃ¡</th>
                                                        <th class="p-2 font-medium text-right pr-4">ThÃ nh tiá»n</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-200 border-b border-gray-200">
                                                    <tr v-for="(item, i) in order.items" :key="item.id">
                                                        <td class="p-2 text-center text-gray-400">{{ i + 1 }}</td>
                                                        <td class="p-2 text-blue-600">{{ item.product_code }}</td>
                                                        <td class="p-2 font-medium">{{ item.product_name }}</td>
                                                        <td class="p-2 text-center font-bold">{{ item.quantity }}</td>
                                                        <td class="p-2 text-right">{{ formatCurrency(item.price) }}</td>
                                                        <td class="p-2 text-right">{{ formatCurrency(item.discount) }}</td>
                                                        <td class="p-2 text-right pr-4">{{ formatCurrency(item.subtotal) }}</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="w-80 border-l border-gray-200 pl-4 py-2 space-y-2 text-[13.5px]">
                                            <div class="flex justify-between items-center">
                                                <span class="text-gray-500">MÃ£ phiáº¿u nháº­p:</span>
                                                <strong>{{ order.code }}</strong>
                                            </div>
                                            <div class="flex justify-between items-center">
                                                <span class="text-gray-500">Thá»i gian:</span>
                                                <span>{{ formatDate(order.purchase_date || order.created_at) }}</span>
                                            </div>
                                            <div class="flex justify-between items-center">
                                                <span class="text-gray-500">NCC:</span>
                                                <span class="text-blue-600">{{ order.supplier?.name || '' }}</span>
                                            </div>
                                            <div class="flex justify-between items-center">
                                                <span class="text-gray-500">Tráº¡ng thÃ¡i:</span>
                                                <span class="font-bold" :class="{
                                                    'text-green-600': order.status === 'completed',
                                                    'text-gray-500': order.status === 'draft',
                                                    'text-orange-600': order.status === 'returned',
                                                    'text-red-600': order.status === 'cancelled',
                                                }">{{ formatStatus(order.status) }}</span>
                                            </div>
                                            <div class="border-t border-gray-200 pt-2 mt-2 space-y-1.5">
                                                <div class="flex justify-between"><span class="text-gray-500">Sá»‘ lÆ°á»£ng máº·t hÃ ng</span><span>{{ getItemCount(order) }}</span></div>
                                                <div class="flex justify-between"><span class="text-gray-500">Tá»•ng tiá»n hÃ ng ({{ getTotalQty(order) }})</span><span>{{ formatCurrency(order.total_amount) }}</span></div>
                                                <div class="flex justify-between"><span class="text-gray-500">Giáº£m giÃ¡</span><span>{{ formatCurrency(order.discount) }}</span></div>
                                                <div class="flex justify-between font-bold"><span>Cáº§n tráº£ NCC</span><span>{{ formatCurrency(getNeedPay(order)) }}</span></div>
                                                <div class="flex justify-between"><span class="text-gray-500">Tiá»n Ä‘Ã£ tráº£ NCC</span><span class="text-green-600">{{ formatCurrency(order.paid_amount) }}</span></div>
                                            </div>
                                            <div class="border-t border-gray-200 pt-3 mt-3 flex gap-2">
                                                <button @click.stop="printPurchase(order)" class="bg-gray-100 text-gray-600 px-4 py-1.5 rounded font-medium hover:bg-gray-200 w-full border border-gray-300">In</button>
                                                <button v-if="order.status === 'completed'" @click.stop="cancelPurchase(order)" class="bg-red-500 text-white px-4 py-1.5 rounded font-medium hover:bg-red-600 w-full">Há»§y</button>
                                                <button v-if="order.status === 'draft'" @click.stop="cancelPurchase(order)" class="bg-red-500 text-white px-4 py-1.5 rounded font-medium hover:bg-red-600 w-full">XÃ³a</button>
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
            <div class="flex items-center justify-between p-3 border-t border-gray-200 bg-gray-50/50 text-sm flex-shrink-0">
                <div class="text-gray-600">
                    Hiá»ƒn thá»‹ tá»« <span class="font-bold">{{ purchases.from || 0 }}</span> Ä‘áº¿n
                    <span class="font-bold">{{ purchases.to || 0 }}</span> trong tá»•ng sá»‘
                    <span class="font-bold">{{ purchases.total || 0 }}</span> phiáº¿u
                </div>
                <div class="flex gap-1" v-if="purchases.links && purchases.links.length > 3">
                    <template v-for="(link, index) in purchases.links" :key="index">
                        <Link v-if="link.url" :href="link.url" class="px-2.5 py-1 text-sm border rounded" :class="link.active ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300'" v-html="link.label"></Link>
                        <span v-else class="px-2.5 py-1 text-sm border rounded bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed" v-html="link.label"></span>
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
