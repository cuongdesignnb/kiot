<script setup>
import { formatVND as formatCurrency } from '@/utils/money';
import { ref, reactive, computed } from "vue";
import { Head, router, Link } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import SortableHeader from "@/Components/SortableHeader.vue";
import SidebarFilter from "@/Components/Filters/SidebarFilter.vue";
import { useFilters } from "@/composables/useFilters.js";
import axios from "axios";

const props = defineProps({
    invoices: Object,
    filters: Object,
    filterOptions: Object,
});

// Standardized sidebar filter state
const { filters, setSort, reset } = useFilters({
    initial: props.filters || {},
    route: "/invoices",
    defaults: { date_filter: "all" },
});

const sidebarConfig = computed(() => [
    {
        key: "date",
        type: "dateRange",
        label: "Thời gian",
        fields: { filter: "date_filter", from: "date_from", to: "date_to" },
        zone: "quick",
    },
    {
        key: "branch_id",
        type: "select",
        label: "Chi nhánh",
        options: (props.filterOptions?.branches || []).map((b) => ({
            value: String(b.id),
            label: b.name,
        })),
        placeholder: "-- Tất cả chi nhánh --",
        zone: "quick",
    },
    {
        key: "status",
        type: "checkbox",
        label: "Trạng thái hóa đơn",
        options: props.filterOptions?.statuses || [],
        zone: "main",
    },
    {
        key: "is_delivery",
        type: "select",
        label: "Loại hóa đơn",
        options: props.filterOptions?.deliveryOptions || [],
        placeholder: "-- Tất cả --",
        zone: "main",
    },
    {
        key: "has_debt",
        type: "select",
        label: "Công nợ",
        options: props.filterOptions?.debtOptions || [],
        placeholder: "-- Tất cả --",
        zone: "main",
    },
    {
        key: "payment_method",
        type: "select",
        label: "Hình thức thanh toán",
        options: props.filterOptions?.paymentMethods || [],
        placeholder: "-- Tất cả --",
        zone: "main",
    },
    {
        key: "seller_key",
        type: "select",
        label: "Người bán",
        options: (props.filterOptions?.sellers || []).map((s) => ({
            value: s.key,
            label: s.display_name || s.name,
        })),
        placeholder: "-- Tất cả --",
        zone: "main",
    },
    {
        key: "creator_key",
        type: "select",
        label: "Người tạo",
        options: (props.filterOptions?.creators || []).map((c) => ({
            value: c.key,
            label: c.display_name || c.name,
        })),
        placeholder: "-- Tất cả --",
        zone: "advanced",
    },
    {
        key: "sales_channel",
        type: "select",
        label: "Kênh bán",
        options: props.filterOptions?.salesChannels || [],
        placeholder: "-- Tất cả --",
        zone: "advanced",
    },
    {
        key: "delivery_partner",
        type: "select",
        label: "Đối tác giao hàng",
        options: [], // populated when partner list is available
        placeholder: "-- Tất cả --",
        zone: "advanced",
    },
]);

const expandedRows = ref([]);
const invoiceTabs = reactive({}); // { invoiceId: 'info' | 'payment' }
const paymentHistoryData = reactive({});
const paymentLoading = reactive({});

const getInvoiceTab = (id) => invoiceTabs[id] || "info";
const isInvoiceCancelled = (invoice) => invoice?.status === 'Đã hủy';
const effectiveCustomerPaid = (invoice) => {
    if (isInvoiceCancelled(invoice)) return 0;
    return Number(invoice?.customer_paid || 0);
};
const setInvoiceTab = (id, tab) => {
    invoiceTabs[id] = tab;
    if (tab === "payment" && !paymentHistoryData[id]) loadPaymentHistory(id);
};

const loadPaymentHistory = async (invoiceId) => {
    paymentLoading[invoiceId] = true;
    try {
        const { data } = await axios.get(
            `/invoices/${invoiceId}/payment-history`,
        );
        paymentHistoryData[invoiceId] = data;
    } catch (e) {
        paymentHistoryData[invoiceId] = { payments: [] };
    }
    paymentLoading[invoiceId] = false;
};

const handleSort = (field, direction) => setSort(field, direction);

const toggleExpand = (id) => {
    const index = expandedRows.value.indexOf(id);
    if (index > -1) {
        expandedRows.value.splice(index, 1);
    } else {
        expandedRows.value.push(id);
    }
};

const isExpanded = (id) => expandedRows.value.includes(id);


// HOTFIX 24.3C — proper cancel modal that handles the time-lock override flow.
// Native window.confirm couldn't collect time_lock_override_reason, so users
// with override permission hit "Cần nhập lý do override" and were stuck.
const showCancelModal = ref(false);
const cancellingInvoice = ref(null);
const cancelReason = ref('');
const cancelError = ref('');
const cancelSubmitting = ref(false);

const cancelInvoice = (invoice) => {
    cancellingInvoice.value = invoice;
    cancelReason.value = '';
    cancelError.value = '';
    showCancelModal.value = true;
};

const closeCancelInvoiceModal = () => {
    if (cancelSubmitting.value) return;
    showCancelModal.value = false;
    cancellingInvoice.value = null;
    cancelReason.value = '';
    cancelError.value = '';
};

const submitCancelInvoice = () => {
    const inv = cancellingInvoice.value;
    if (!inv) return;
    if (inv.cancel_block_reason) return; // UI guard; backend also enforces
    if (inv.requires_override_reason) {
        const trimmed = (cancelReason.value || '').trim();
        if (trimmed.length < 5) {
            cancelError.value = 'Vui lòng nhập lý do override (ít nhất 5 ký tự).';
            return;
        }
    }
    cancelError.value = '';
    cancelSubmitting.value = true;
    router.delete(`/invoices/${inv.id}`, {
        data: inv.requires_override_reason
            ? { time_lock_override_reason: cancelReason.value.trim() }
            : {},
        preserveScroll: true,
        onSuccess: () => {
            showCancelModal.value = false;
            cancellingInvoice.value = null;
            cancelReason.value = '';
        },
        onError: (errors) => {
            // Inertia surfaces validation errors; flash error comes via page props.
            const flashErr = errors?.error || errors?.message;
            cancelError.value = flashErr || 'Không thể hủy hóa đơn. Vui lòng kiểm tra lại.';
        },
        onFinish: () => {
            cancelSubmitting.value = false;
        },
    });
};

const printInvoice = (invoice) => {
    window.open(
        `/invoices/${invoice.id}/print`,
        "_blank",
        "width=400,height=600",
    );
};

// HOTFIX 24.30 — Change seller for an invoice
const sellerUpdating = reactive({});
const invoiceSellerOptions = computed(() => props.filterOptions?.invoiceSellerOptions || []);

const currentSellerKey = (invoice) => {
    if (invoice.seller_key) return invoice.seller_key;
    if (invoice.created_by) return `employee:${invoice.created_by}`;
    return '';
};

const changeSeller = async (invoice, newSellerKey) => {
    if (!newSellerKey || sellerUpdating[invoice.id]) return;
    if (newSellerKey === currentSellerKey(invoice)) return;

    const oldName = invoice.seller_name || 'Chưa xác định';
    const newOpt = invoiceSellerOptions.value.find(o => o.key === newSellerKey);
    const newName = newOpt?.display_name || newOpt?.name || newSellerKey;

    const confirmed = window.confirm(
        `Bạn có chắc muốn đổi người bán của hóa đơn ${invoice.code} từ "${oldName}" sang "${newName}"?\n\nThay đổi này sẽ ảnh hưởng báo cáo doanh số/lợi nhuận theo nhân viên.`
    );
    if (!confirmed) return;

    sellerUpdating[invoice.id] = true;
    try {
        const { data } = await axios.patch(`/invoices/${invoice.id}/seller`, {
            seller_key: newSellerKey,
        });
        invoice.created_by = data.created_by;
        invoice.seller_name = data.seller_name;
        invoice.seller_key = data.seller_key;
    } catch (e) {
        const msg = e.response?.data?.message || 'Không thể đổi người bán. Vui lòng thử lại.';
        alert(msg);
    }
    sellerUpdating[invoice.id] = false;
};
</script>

<template>
    <Head title="Hóa đơn - KiotViet Clone" />
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
            <div
                class="flex items-center justify-between px-4 pb-3 border-b border-gray-200"
            >
                <div class="text-2xl font-bold text-gray-800">Hóa đơn</div>

                <div class="flex-1 max-w-[400px] ml-6 relative">
                    <svg
                        class="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400"
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
                        placeholder="Theo mã hóa đơn, mã KH, tên KH, sđt..."
                        class="w-full pl-9 pr-8 py-1.5 focus:outline-none border border-gray-300 rounded text-sm placeholder-gray-400"
                    />
                </div>

                <div class="flex gap-2 ml-auto">
                    <Link
                        href="/orders/create"
                        class="bg-blue-600 text-white px-3 py-1.5 text-sm font-medium rounded hover:bg-blue-700 transition flex items-center gap-1"
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
                            ></path>
                        </svg>
                        Tạo mới
                    </Link>
                    <ExcelButtons export-url="/invoices/export" />
                    <button
                        class="bg-white text-gray-600 border border-gray-300 px-2.5 py-1.5 rounded hover:bg-gray-50"
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
                </div>
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-auto bg-[#eff3f6]">
                <table
                    class="w-full text-[13px] text-left whitespace-nowrap bg-white"
                >
                    <thead
                        class="font-bold text-gray-700 bg-[#f4f6f8] border-b border-gray-200 sticky top-0 z-10 shadow-sm"
                    >
                        <tr>
                            <th class="px-3 py-2 w-10 text-center">
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-300"
                                />
                            </th>
                            <SortableHeader label="Mã hóa đơn" field="code" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" class="px-2 py-2" @sort="handleSort" />
                            <SortableHeader label="Thời gian" field="created_at" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" class="px-2 py-2" @sort="handleSort" />
                            <th class="px-2 py-2">Khách hàng</th>
                            <SortableHeader label="Tổng tiền hàng" field="subtotal" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <SortableHeader label="Giảm giá" field="discount" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <SortableHeader label="Tổng sau giảm giá" field="total" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <SortableHeader label="Khách đã trả" field="customer_paid" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="invoices.data.length === 0">
                            <td
                                colspan="8"
                                class="p-16 text-center text-gray-500"
                            >
                                <div
                                    class="flex flex-col items-center justify-center"
                                >
                                    <div
                                        class="w-20 h-20 bg-blue-50 rounded-full flex items-center justify-center mb-4"
                                    >
                                        <svg
                                            class="w-10 h-10 text-blue-400"
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
                                    </div>
                                    <h3
                                        class="text-[17px] font-bold text-gray-800 mb-1"
                                    >
                                        Không tìm thấy kết quả
                                    </h3>
                                    <p class="text-[14px]">
                                        Không tìm thấy hóa đơn nào phù hợp.
                                    </p>
                                </div>
                            </td>
                        </tr>
                        <template
                            v-for="invoice in invoices.data"
                            :key="invoice.id"
                        >
                            <tr
                                @click="toggleExpand(invoice.id)"
                                class="hover:bg-blue-50/40 cursor-pointer transition-colors"
                                :class="{
                                    'bg-[#f4f7fe]': isExpanded(invoice.id),
                                    'border-l-2 border-l-blue-500': isExpanded(
                                        invoice.id,
                                    ),
                                }"
                            >
                                <td class="px-3 py-2 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300"
                                    />
                                </td>
                                <td
                                    class="px-2 py-2 font-medium"
                                    :class="
                                        isExpanded(invoice.id)
                                            ? 'text-gray-900'
                                            : 'text-blue-600'
                                    "
                                >
                                    <a :href="`/invoices/${invoice.id}/show`" class="hover:underline" @click.stop>{{ invoice.code }}</a>
                                </td>
                                <td class="px-2 py-2">
                                    {{
                                        new Date(
                                            invoice.created_at,
                                        ).toLocaleString("vi-VN", {
                                            day: "2-digit",
                                            month: "2-digit",
                                            year: "numeric",
                                            hour: "2-digit",
                                            minute: "2-digit",
                                        })
                                    }}
                                </td>
                                <td class="px-2 py-2">
                                    {{ invoice.customer?.name || "Khách lẻ" }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ formatCurrency(invoice.subtotal) }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ formatCurrency(invoice.discount) }}
                                </td>
                                <td
                                    class="px-4 py-2 text-right font-medium text-blue-600"
                                >
                                    {{ formatCurrency(invoice.total) }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <div class="text-right">
                                        <div>
                                            {{ formatCurrency(effectiveCustomerPaid(invoice)) }}
                                        </div>
                                        <div
                                            v-if="isInvoiceCancelled(invoice) && Number(invoice.customer_paid || 0) > 0"
                                            class="text-[11px] text-gray-400 font-normal"
                                        >
                                            Trước hủy: {{ formatCurrency(invoice.customer_paid) }}
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr
                                v-if="isExpanded(invoice.id)"
                                class="border-b-4 border-blue-50"
                            >
                                <td
                                    colspan="8"
                                    class="p-0 border-0 bg-white shadow-[inset_0_2px_4px_rgba(0,0,0,0.02)]"
                                >
                                    <div
                                        class="px-6 py-4 w-full border-t border-blue-100 flex flex-col pt-0"
                                    >
                                        <!-- Tabs -->
                                        <div
                                            class="flex text-[13.5px] font-semibold text-gray-600 border-b border-gray-200 sticky top-0 bg-white z-0 pt-2 mb-4"
                                        >
                                            <button
                                                @click="
                                                    setInvoiceTab(
                                                        invoice.id,
                                                        'info',
                                                    )
                                                "
                                                :class="
                                                    getInvoiceTab(
                                                        invoice.id,
                                                    ) === 'info'
                                                        ? 'border-b-2 border-blue-600 text-blue-600'
                                                        : 'hover:text-blue-600'
                                                "
                                                class="px-4 pb-2"
                                            >
                                                Thông tin
                                            </button>
                                            <button
                                                @click="
                                                    setInvoiceTab(
                                                        invoice.id,
                                                        'payment',
                                                    )
                                                "
                                                :class="
                                                    getInvoiceTab(
                                                        invoice.id,
                                                    ) === 'payment'
                                                        ? 'border-b-2 border-blue-600 text-blue-600'
                                                        : 'hover:text-blue-600'
                                                "
                                                class="px-4 pb-2"
                                            >
                                                Lịch sử thanh toán
                                            </button>
                                        </div>

                                        <!-- Header Info -->
                                        <div
                                            v-if="
                                                getInvoiceTab(invoice.id) ===
                                                'info'
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-2 mb-4"
                                            >
                                                <h2
                                                    class="text-[17px] font-bold text-gray-800"
                                                >
                                                    {{
                                                        invoice.customer
                                                            ?.name ||
                                                        "A Dũng Kiều Mai"
                                                    }}
                                                </h2>
                                                <svg
                                                    class="w-4 h-4 text-gray-400 cursor-pointer"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    viewBox="0 0 24 24"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
                                                    ></path>
                                                </svg>
                                                <span
                                                    class="text-gray-500 text-[13px] ml-1 flex items-center gap-2"
                                                >
                                                    {{ invoice.code }}
                                                    <span
                                                        class="bg-green-100 text-green-700 px-2 py-0.5 rounded textxs font-medium"
                                                        >{{
                                                            invoice.status ||
                                                            "Hoàn thành"
                                                        }}</span
                                                    >
                                                </span>
                                                <div
                                                    class="ml-auto text-[13px] text-gray-700 font-medium"
                                                >
                                                    Laptopplus.vn
                                                </div>
                                            </div>

                                            <div class="flex flex-col gap-6">
                                                <!-- Top details grid -->
                                                <div
                                                    class="grid grid-cols-3 gap-x-12 gap-y-3 text-[13.5px] text-gray-700 w-full mb-2"
                                                >
                                                    <div
                                                        class="flex items-center"
                                                    >
                                                        <span
                                                            class="text-gray-400 w-24"
                                                            >Người tạo:</span
                                                        >
                                                        <span
                                                            class="text-gray-800"
                                                            >{{
                                                                invoice.created_by_name ||
                                                                "Không rõ"
                                                            }}</span
                                                        >
                                                    </div>
                                                    <div
                                                        class="flex items-center"
                                                    >
                                                        <span
                                                            class="text-gray-400 w-24"
                                                            >Người bán:</span
                                                        >
                                                        <select
                                                            class="border border-gray-300 rounded px-2 py-0.5 outline-none flex-1"
                                                            :class="{ 'opacity-50': sellerUpdating[invoice.id] }"
                                                            :disabled="sellerUpdating[invoice.id] || invoice.status === 'Đã hủy'"
                                                            :value="currentSellerKey(invoice)"
                                                            @change="changeSeller(invoice, $event.target.value)"
                                                        >
                                                            <option value="" disabled>
                                                                {{ invoice.seller_name || "Chưa xác định người bán" }}
                                                            </option>
                                                            <option
                                                                v-for="opt in invoiceSellerOptions"
                                                                :key="opt.key"
                                                                :value="opt.key"
                                                            >
                                                                {{ opt.display_name || opt.name }}
                                                            </option>
                                                        </select>
                                                    </div>
                                                    <div
                                                        class="flex items-center justify-end"
                                                    >
                                                        <span
                                                            class="text-gray-400 w-24"
                                                            >Ngày bán:</span
                                                        >
                                                        <div
                                                            class="flex items-center border border-gray-300 rounded px-2 py-0.5 w-[160px] bg-white"
                                                        >
                                                            <span
                                                                class="flex-1"
                                                                >{{
                                                                    new Date(
                                                                        invoice.created_at,
                                                                    ).toLocaleString(
                                                                        "vi-VN",
                                                                        {
                                                                            day: "2-digit",
                                                                            month: "2-digit",
                                                                            year: "numeric",
                                                                            hour: "2-digit",
                                                                            minute: "2-digit",
                                                                        },
                                                                    )
                                                                }}</span
                                                            >
                                                            <svg
                                                                class="w-3.5 h-3.5 text-gray-400 ml-2"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                viewBox="0 0 24 24"
                                                            >
                                                                <path
                                                                    stroke-linecap="round"
                                                                    stroke-linejoin="round"
                                                                    stroke-width="2"
                                                                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                                                                ></path>
                                                            </svg>
                                                            <svg
                                                                class="w-3.5 h-3.5 text-gray-400 ml-1.5"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                viewBox="0 0 24 24"
                                                            >
                                                                <path
                                                                    stroke-linecap="round"
                                                                    stroke-linejoin="round"
                                                                    stroke-width="2"
                                                                    d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                                                                ></path>
                                                            </svg>
                                                        </div>
                                                    </div>

                                                    <div
                                                        class="flex items-center"
                                                    >
                                                        <span
                                                            class="text-gray-400 w-24"
                                                            >Kênh bán:</span
                                                        >
                                                        <select
                                                            class="border border-gray-300 rounded px-2 py-0.5 outline-none flex-1"
                                                        >
                                                            <option>
                                                                {{
                                                                    invoice.sales_channel ||
                                                                    "Bán trực tiếp"
                                                                }}
                                                            </option>
                                                        </select>
                                                    </div>
                                                    <div
                                                        class="flex items-center"
                                                    >
                                                        <span
                                                            class="text-gray-400 w-24"
                                                            >Bảng giá:</span
                                                        >
                                                        <span
                                                            class="text-gray-800"
                                                            >{{
                                                                invoice.price_book_name ||
                                                                "Bảng giá chung"
                                                            }}</span
                                                        >
                                                    </div>
                                                    <div></div>
                                                </div>

                                                <!-- Product list -->
                                                <div
                                                    class="border-y border-gray-300 -mx-6"
                                                >
                                                    <table
                                                        class="w-full text-[13.5px]"
                                                    >
                                                        <thead
                                                            class="bg-white border-b border-gray-200"
                                                        >
                                                            <tr>
                                                                <th
                                                                    class="px-6 py-3 text-left font-bold text-gray-800 w-32 border-r border-gray-100"
                                                                >
                                                                    Mã hàng
                                                                </th>
                                                                <th
                                                                    class="px-4 py-3 text-left font-bold text-gray-800 border-r border-gray-100"
                                                                >
                                                                    Tên hàng
                                                                </th>
                                                                <th
                                                                    class="px-4 py-3 text-right font-bold text-gray-800 w-24 border-r border-gray-100"
                                                                >
                                                                    Số lượng
                                                                </th>
                                                                <th
                                                                    class="px-4 py-3 text-right font-bold text-gray-800 w-32 border-r border-gray-100"
                                                                >
                                                                    Đơn giá
                                                                </th>
                                                                <th
                                                                    class="px-4 py-3 text-right font-bold text-gray-800 w-32 border-r border-gray-100"
                                                                >
                                                                    Giảm giá
                                                                </th>
                                                                <th
                                                                    class="px-4 py-3 text-right font-bold text-gray-800 w-32 border-r border-gray-100"
                                                                >
                                                                    Giá bán
                                                                </th>
                                                                <th
                                                                    class="px-6 py-3 text-right font-bold text-gray-800 w-32 border-r border-gray-100"
                                                                >
                                                                    Thành tiền
                                                                </th>
                                                            </tr>
                                                        </thead>
                                                        <tbody
                                                            class="divide-y divide-gray-100"
                                                        >
                                                            <tr
                                                                v-for="item in invoice.items"
                                                                :key="item.id"
                                                            >
                                                                <td
                                                                    class="px-6 py-3 text-blue-500 font-medium"
                                                                >
                                                                    {{
                                                                        item
                                                                            .product
                                                                            ?.code
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="px-4 py-3"
                                                                >
                                                                    <div
                                                                        class="text-gray-800 flex items-center gap-1"
                                                                    >
                                                                        {{
                                                                            item
                                                                                .product
                                                                                ?.name
                                                                        }}
                                                                        <svg
                                                                            class="w-3.5 h-3.5 text-blue-500"
                                                                            fill="currentColor"
                                                                            viewBox="0 0 20 20"
                                                                        >
                                                                            <path
                                                                                fill-rule="evenodd"
                                                                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                                                clip-rule="evenodd"
                                                                            ></path>
                                                                        </svg>
                                                                    </div>
                                                                    <div
                                                                        v-if="
                                                                            item.serial
                                                                        "
                                                                        class="text-gray-500 text-xs mt-1 bg-gray-100 inline-block px-1 rounded"
                                                                    >
                                                                        {{
                                                                            item.serial
                                                                        }}
                                                                    </div>
                                                                </td>
                                                                <td
                                                                    class="px-4 py-3 text-right text-gray-800"
                                                                >
                                                                    {{
                                                                        item.quantity
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="px-4 py-3 text-right text-gray-800"
                                                                >
                                                                    {{
                                                                        formatCurrency(
                                                                            item.price,
                                                                        )
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="px-4 py-3 text-right text-gray-800"
                                                                >
                                                                    {{
                                                                        formatCurrency(
                                                                            item.discount ||
                                                                                0,
                                                                        )
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="px-4 py-3 text-right text-gray-800"
                                                                >
                                                                    {{
                                                                        formatCurrency(
                                                                            item.price -
                                                                                (item.discount ||
                                                                                    0),
                                                                        )
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="px-6 py-3 text-right font-bold text-gray-800"
                                                                >
                                                                    {{
                                                                        formatCurrency(
                                                                            (item.price -
                                                                                (item.discount ||
                                                                                    0)) *
                                                                                item.quantity,
                                                                        )
                                                                    }}
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>

                                                <!-- Bottom notes and totals -->
                                                <div
                                                    class="flex gap-8 mb-4 min-h-[100px]"
                                                >
                                                    <div class="w-[60%]">
                                                        <textarea
                                                            class="w-full h-24 border border-gray-300 p-3 text-[13px] outline-none focus:border-blue-500 resize-none rounded-none placeholder-gray-400"
                                                            placeholder="Ghi chú..."
                                                        ></textarea>
                                                    </div>
                                                    <div
                                                        class="w-[40%] text-[13.5px]"
                                                    >
                                                        <div
                                                            class="flex justify-between py-1.5 text-gray-500"
                                                        >
                                                            <span
                                                                >Tổng tiền hàng
                                                                ({{
                                                                    invoice
                                                                        .items
                                                                        ?.length ||
                                                                    0
                                                                }})</span
                                                            >
                                                            <span
                                                                class="text-gray-800 font-medium"
                                                                >{{
                                                                    formatCurrency(
                                                                        invoice.subtotal,
                                                                    )
                                                                }}</span
                                                            >
                                                        </div>
                                                        <div
                                                            class="flex justify-between py-1.5 text-gray-500"
                                                        >
                                                            <span
                                                                >Giảm giá hóa
                                                                đơn</span
                                                            >
                                                            <span
                                                                class="text-gray-800 font-medium"
                                                                >{{
                                                                    formatCurrency(
                                                                        invoice.discount,
                                                                    )
                                                                }}</span
                                                            >
                                                        </div>
                                                        <div
                                                            class="flex justify-between py-1.5 text-gray-500"
                                                        >
                                                            <span
                                                                >Khách cần
                                                                trả</span
                                                            >
                                                            <span
                                                                class="text-gray-800 font-medium"
                                                                >{{
                                                                    formatCurrency(
                                                                        invoice.total,
                                                                    )
                                                                }}</span
                                                            >
                                                        </div>
                                                        <div class="flex justify-between py-1.5 text-gray-500">
                                                             <span>
                                                                 {{ isInvoiceCancelled(invoice) ? 'Khách đã trả hiệu lực' : 'Khách đã trả' }}
                                                             </span>
                                                             <span class="text-gray-800 font-medium">
                                                                 {{ formatCurrency(effectiveCustomerPaid(invoice)) }}
                                                             </span>
                                                         </div>
                                                         <div
                                                             v-if="isInvoiceCancelled(invoice) && Number(invoice.customer_paid || 0) > 0"
                                                             class="flex justify-between py-1 text-xs text-gray-400 font-normal"
                                                         >
                                                             <span>Đã trả trước hủy</span>
                                                             <span>{{ formatCurrency(invoice.customer_paid) }}</span>
                                                         </div>
                                                    </div>
                                                </div>

                                                <!-- Actions -->
                                                <div
                                                    class="flex justify-between border-t border-gray-300 pt-4 pb-2 text-[13px]"
                                                >
                                                    <div class="flex gap-2">
                                                        <button
                                                            @click.stop="
                                                                cancelInvoice(
                                                                    invoice,
                                                                )
                                                            "
                                                            class="bg-white border border-gray-300 px-3 py-1.5 rounded text-gray-700 hover:bg-gray-50 flex items-center gap-1.5 font-medium"
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
                                                                ></path>
                                                            </svg>
                                                            Hủy
                                                        </button>
                                                    </div>
                                                    <div class="flex gap-2">
                                                        <Link
                                                            :href="`/orders/create?action=edit&invoice_id=${invoice.id}`"
                                                            class="bg-[#0070f4] text-white px-4 py-1.5 rounded font-medium hover:bg-blue-600 flex items-center gap-1.5"
                                                        >
                                                            <svg
                                                                class="w-3.5 h-3.5"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                viewBox="0 0 24 24"
                                                            >
                                                                <path
                                                                    stroke-linecap="round"
                                                                    stroke-linejoin="round"
                                                                    stroke-width="2"
                                                                    d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                                                                ></path>
                                                            </svg>
                                                            Chỉnh sửa
                                                        </Link>
                                                        <Link
                                                            :href="`/orders/create?action=return&invoice_id=${invoice.id}`"
                                                            class="bg-white border border-gray-300 px-3 py-1.5 rounded text-gray-700 hover:bg-gray-50 flex items-center gap-1.5 font-medium"
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
                                                                    d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"
                                                                ></path>
                                                            </svg>
                                                            Trả hàng
                                                        </Link>
                                                        <button
                                                            @click.stop="
                                                                printInvoice(
                                                                    invoice,
                                                                )
                                                            "
                                                            class="bg-white border border-gray-300 px-3 py-1.5 rounded text-gray-700 hover:bg-gray-50 flex items-center gap-1.5 font-medium"
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
                                                                    d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"
                                                                ></path>
                                                            </svg>
                                                            In
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <!-- end info tab -->

                                            <!-- Payment History Tab -->
                                            <div
                                                v-if="
                                                    getInvoiceTab(
                                                        invoice.id,
                                                    ) === 'payment'
                                                "
                                            >
                                                <div
                                                    v-if="
                                                        paymentLoading[
                                                            invoice.id
                                                        ]
                                                    "
                                                    class="text-center py-8 text-gray-400"
                                                >
                                                    Đang tải...
                                                </div>
                                                <div
                                                    v-else-if="
                                                        paymentHistoryData[
                                                            invoice.id
                                                        ]
                                                    "
                                                >
                                                    <div
                                                        v-if="invoice.status === 'Đã hủy' && Number(invoice.customer_paid || 0) > 0"
                                                        class="mb-3 text-xs text-yellow-600 bg-yellow-50 p-2.5 border border-yellow-200 rounded font-medium"
                                                    >
                                                        Hóa đơn đã hủy. Khoản đã trả trước hủy chỉ còn là snapshot, không còn hiệu lực trong sổ quỹ.
                                                    </div>
                                                    <div
                                                        v-if="
                                                            !paymentHistoryData[
                                                                invoice.id
                                                            ].payments?.length
                                                        "
                                                        class="text-center py-8 text-gray-400"
                                                    >
                                                        Không có lịch sử thanh
                                                        toán
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
                                                                    Phương thức
                                                                </th>
                                                                <th
                                                                    class="px-3 py-2 text-right"
                                                                >
                                                                    Số tiền
                                                                </th>
                                                                <th
                                                                    class="px-3 py-2 text-left"
                                                                >
                                                                    Ghi chú
                                                                </th>
                                                            </tr>
                                                        </thead>
                                                        <tbody
                                                            class="divide-y divide-gray-100"
                                                        >
                                                            <tr
                                                                v-for="p in paymentHistoryData[
                                                                    invoice.id
                                                                ].payments"
                                                                :key="p.id"
                                                            >
                                                                <td
                                                                    class="px-3 py-2 text-blue-600 font-medium"
                                                                    :class="{ 'text-gray-400 line-through': p.is_cancelled || p.status === 'cancelled' }"
                                                                >
                                                                    {{ p.code }}
                                                                    <span
                                                                        v-if="p.status === 'cancelled' || p.is_cancelled"
                                                                        class="ml-2 rounded bg-red-50 px-1.5 py-0.5 text-[11px] font-semibold text-red-600 inline-block"
                                                                    >
                                                                        Đã hủy
                                                                    </span>
                                                                </td>
                                                                <td
                                                                    class="px-3 py-2 text-gray-500"
                                                                >
                                                                    {{
                                                                        new Date(
                                                                            p.created_at,
                                                                        ).toLocaleString(
                                                                            "vi-VN",
                                                                        )
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="px-3 py-2"
                                                                >
                                                                    {{
                                                                        p.method ||
                                                                        "Tiền mặt"
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="px-3 py-2 text-right font-medium"
                                                                    :class="p.is_cancelled || p.status === 'cancelled' ? 'text-gray-400 line-through' : 'text-gray-800'"
                                                                >
                                                                    {{
                                                                        formatCurrency(
                                                                            p.amount,
                                                                        )
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="px-3 py-2 text-gray-500"
                                                                >
                                                                    {{
                                                                        p.note ||
                                                                        ""
                                                                    }}
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <div
                                                    v-else
                                                    class="text-center py-8 text-gray-400"
                                                >
                                                    Đang tải...
                                                </div>
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
                class="flex items-center justify-between p-3 border-t border-gray-200 bg-gray-50/50 text-sm flex-shrink-0"
            >
                <div class="text-gray-600">
                    Hiển thị từ
                    <span class="font-bold">{{ invoices.from || 0 }}</span> đến
                    <span class="font-bold">{{ invoices.to || 0 }}</span> trong
                    tổng số
                    <span class="font-bold">{{ invoices.total || 0 }}</span> hóa
                    đơn
                </div>
                <!-- Pagination -->
                <div
                    class="flex gap-1"
                    v-if="invoices.links && invoices.links.length > 3"
                >
                    <template
                        v-for="(link, index) in invoices.links"
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

        <!-- HOTFIX 24.3C — Cancel invoice modal (replaces window.confirm) -->
        <div
            v-if="showCancelModal && cancellingInvoice"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        >
            <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
                <div class="flex items-center justify-between px-6 py-4 border-b">
                    <h3 class="text-lg font-bold text-gray-800">Hủy hóa đơn</h3>
                    <button
                        @click="closeCancelInvoiceModal"
                        class="text-gray-400 hover:text-gray-600 text-2xl leading-none"
                        :disabled="cancelSubmitting"
                    >&times;</button>
                </div>

                <div class="px-6 py-4 space-y-3 text-sm">
                    <div class="grid grid-cols-2 gap-y-1 gap-x-4">
                        <div class="text-gray-500">Mã hóa đơn</div>
                        <div class="font-semibold text-gray-800">{{ cancellingInvoice.code }}</div>
                        <div class="text-gray-500">Khách hàng</div>
                        <div class="text-gray-800">{{ cancellingInvoice.customer?.name || 'Khách lẻ' }}</div>
                        <div class="text-gray-500">Tổng tiền</div>
                        <div class="text-gray-800 tabular-nums">{{ Number(cancellingInvoice.total || 0).toLocaleString('vi-VN') }} ₫</div>
                        <div class="text-gray-500">Trạng thái</div>
                        <div class="text-gray-800">{{ cancellingInvoice.status }}</div>
                    </div>

                    <div class="px-3 py-2 bg-amber-50 border border-amber-200 rounded text-amber-800 text-xs">
                        Hủy hóa đơn sẽ đảo tồn kho, serial/IMEI, công nợ, dòng tiền và báo cáo liên quan.
                    </div>

                    <div
                        v-if="cancellingInvoice.cancel_block_reason"
                        class="px-3 py-2 bg-red-50 border border-red-200 rounded text-red-700 text-sm"
                    >
                        {{ cancellingInvoice.cancel_block_reason }}
                    </div>

                    <template v-else>
                        <div
                            v-if="cancellingInvoice.is_time_locked"
                            class="px-3 py-2 bg-orange-50 border border-orange-200 rounded text-orange-800 text-xs"
                        >
                            Hóa đơn đã quá thời gian cho phép hủy ({{ cancellingInvoice.order_change_time_hours }} giờ — đã trôi {{ cancellingInvoice.lock_age_hours }} giờ). Cần lý do override.
                        </div>

                        <div v-if="cancellingInvoice.requires_override_reason">
                            <label class="block text-xs font-medium text-gray-700 mb-1">
                                Lý do override <span class="text-red-500">*</span>
                            </label>
                            <textarea
                                v-model="cancelReason"
                                rows="3"
                                class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500"
                                placeholder="Nhập lý do hủy quá hạn (ít nhất 5 ký tự)"
                                :disabled="cancelSubmitting"
                            ></textarea>
                            <p class="mt-1 text-xs text-gray-400">Tối thiểu 5 ký tự. Sẽ ghi vào nhật ký hệ thống.</p>
                        </div>
                    </template>

                    <div
                        v-if="cancelError"
                        class="px-3 py-2 bg-red-50 border border-red-200 rounded text-red-700 text-sm"
                    >
                        {{ cancelError }}
                    </div>
                </div>

                <div class="px-6 py-3 border-t bg-gray-50 flex items-center justify-end gap-2 rounded-b-lg">
                    <button
                        @click="closeCancelInvoiceModal"
                        :disabled="cancelSubmitting"
                        class="px-4 py-2 border border-gray-300 rounded text-sm font-medium text-gray-700 hover:bg-gray-100 disabled:opacity-50"
                    >Đóng</button>
                    <button
                        @click="submitCancelInvoice"
                        :disabled="cancelSubmitting || !!cancellingInvoice.cancel_block_reason"
                        class="px-5 py-2 bg-red-600 text-white rounded text-sm font-medium hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {{ cancelSubmitting ? 'Đang hủy...' : 'Xác nhận hủy' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
