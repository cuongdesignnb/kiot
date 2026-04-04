<script setup>
import { ref, watch, reactive } from "vue";
import { Head, router, Link } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import SortableHeader from "@/Components/SortableHeader.vue";
import axios from "axios";

const props = defineProps({
    invoices: Object,
    branches: Array,
    filters: Object,
});

const search = ref(props.filters?.search || "");
const sortBy = ref(props.filters?.sort_by || "");
const sortDirection = ref(props.filters?.sort_direction || "");
const expandedRows = ref([]);
const invoiceTabs = reactive({}); // { invoiceId: 'info' | 'payment' }
const paymentHistoryData = reactive({});
const paymentLoading = reactive({});

const getInvoiceTab = (id) => invoiceTabs[id] || "info";
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

const handleSort = (field, direction) => {
    sortBy.value = field;
    sortDirection.value = direction;
    router.get(
        "/invoices",
        { search: search.value, sort_by: field, sort_direction: direction },
        { preserveState: true, replace: true },
    );
};

let searchTimeout;
const updateFilters = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            "/invoices",
            { search: search.value, sort_by: sortBy.value, sort_direction: sortDirection.value },
            { preserveState: true, replace: true },
        );
    }, 500);
};

watch(search, updateFilters);

const toggleExpand = (id) => {
    const index = expandedRows.value.indexOf(id);
    if (index > -1) {
        expandedRows.value.splice(index, 1);
    } else {
        expandedRows.value.push(id);
    }
};

const isExpanded = (id) => expandedRows.value.includes(id);

const formatCurrency = (val) => Number(val || 0).toLocaleString("vi-VN");

const cancelInvoice = (invoice) => {
    if (!confirm(`Bạn có chắc muốn hủy hóa đơn ${invoice.code}?`)) return;
    router.delete(`/invoices/${invoice.id}`, { preserveScroll: true });
};

const printInvoice = (invoice) => {
    window.open(
        `/invoices/${invoice.id}/print`,
        "_blank",
        "width=400,height=600",
    );
};
</script>

<template>
    <Head title="Hóa đơn - KiotViet Clone" />
    <AppLayout>
        <template #sidebar>
            <!-- Lọc CHI NHÁNH -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-[13px] font-bold text-gray-800 mb-2"
                    >Chi nhánh</label
                >
                <div class="flex items-center">
                    <select
                        class="w-full border border-gray-300 rounded p-1.5 text-[13px] outline-none text-gray-700 font-medium"
                    >
                        <option
                            v-for="branch in branches"
                            :key="branch.id"
                            :value="branch.id"
                        >
                            {{ branch.name }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- Lọc THỜI GIAN -->
            <div class="px-3 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-[13px] font-bold text-gray-800"
                        >Thời gian</label
                    >
                    <svg
                        class="w-3.5 h-3.5 text-blue-600 cursor-pointer"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M19 9l-7 7-7-7"
                        ></path>
                    </svg>
                </div>
                <div class="space-y-2 text-[13px] text-gray-700">
                    <label
                        class="flex items-center justify-between gap-2 cursor-pointer p-1.5 border border-gray-300 rounded hover:border-blue-500"
                    >
                        <div class="flex items-center gap-2">
                            <input
                                type="radio"
                                value="last_year"
                                name="time"
                                checked
                                class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                            />
                            Năm trước (âm lịch)
                        </div>
                        <svg
                            class="w-4 h-4 text-gray-400"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 5l7 7-7 7"
                            ></path>
                        </svg>
                    </label>
                    <label
                        class="flex items-center justify-between gap-2 cursor-pointer p-1.5 border border-gray-300 rounded hover:border-blue-500 text-gray-500"
                    >
                        <div class="flex items-center gap-2">
                            <input
                                type="radio"
                                value="custom"
                                name="time"
                                class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                            />
                            Tùy chỉnh
                        </div>
                        <svg
                            class="w-4 h-4 text-gray-400"
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
                    </label>
                </div>
            </div>

            <!-- LOẠI HÓA ĐƠN -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-[13px] font-bold text-gray-800 mb-2"
                    >Loại hóa đơn</label
                >
                <div class="space-y-1.5">
                    <label
                        class="flex items-center gap-2 cursor-pointer text-[13px] text-gray-700"
                    >
                        <input
                            type="checkbox"
                            checked
                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Không giao hàng
                    </label>
                    <label
                        class="flex items-center gap-2 cursor-pointer text-[13px] text-gray-700"
                    >
                        <input
                            type="checkbox"
                            checked
                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Giao hàng
                    </label>
                </div>
            </div>

            <!-- Lọc TRẠNG THÁI -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-[13px] font-bold text-gray-800 mb-2"
                    >Trạng thái hóa đơn</label
                >
                <div class="space-y-1.5">
                    <label
                        class="flex items-center gap-2 cursor-pointer text-[13px] text-gray-700"
                    >
                        <input
                            type="checkbox"
                            checked
                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Đang xử lý
                    </label>
                    <label
                        class="flex items-center gap-2 cursor-pointer text-[13px] text-gray-700"
                    >
                        <input
                            type="checkbox"
                            checked
                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Hoàn thành
                    </label>
                    <label
                        class="flex items-center gap-2 cursor-pointer text-[13px] text-gray-700"
                    >
                        <input
                            type="checkbox"
                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Không giao được
                    </label>
                    <label
                        class="flex items-center gap-2 cursor-pointer text-[13px] text-gray-700"
                    >
                        <input
                            type="checkbox"
                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Đã hủy
                    </label>
                </div>
            </div>

            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-[13px] font-bold text-gray-800 mb-2"
                    >Trạng thái giao hàng</label
                >
                <select
                    class="w-full border border-gray-300 rounded p-1.5 text-[13px] outline-none text-gray-500"
                >
                    <option value="">Chọn trạng thái</option>
                </select>
            </div>

            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-[13px] font-bold text-gray-800 mb-2"
                    >Đối tác giao hàng</label
                >
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
                        v-model="search"
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
                            <SortableHeader label="Mã hóa đơn" field="code" :current-sort="sortBy" :current-direction="sortDirection" class="px-2 py-2" @sort="handleSort" />
                            <SortableHeader label="Thời gian" field="created_at" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" class="px-2 py-2" @sort="handleSort" />
                            <th class="px-2 py-2">Khách hàng</th>
                            <SortableHeader label="Tổng tiền hàng" field="subtotal" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <SortableHeader label="Giảm giá" field="discount" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <SortableHeader label="Tổng sau giảm giá" field="total" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <SortableHeader label="Khách đã trả" field="customer_paid" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
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
                                    {{ formatCurrency(invoice.customer_paid) }}
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
                                                                "Trần Văn Tiến"
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
                                                        >
                                                            <option>
                                                                {{
                                                                    invoice.seller_name ||
                                                                    "Trần Văn Tiến"
                                                                }}
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
                                                        <div
                                                            class="flex justify-between py-1.5 text-gray-500"
                                                        >
                                                            <span
                                                                >Khách đã
                                                                trả</span
                                                            >
                                                            <span
                                                                class="text-gray-800 font-medium"
                                                                >{{
                                                                    formatCurrency(
                                                                        invoice.customer_paid,
                                                                    )
                                                                }}</span
                                                            >
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
                                                                    class="px-3 py-2 text-blue-600"
                                                                >
                                                                    {{ p.code }}
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
    </AppLayout>
</template>
