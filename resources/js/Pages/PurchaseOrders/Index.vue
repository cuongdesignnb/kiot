<script setup>
import { ref, watch, computed } from "vue";
import { Head, router, Link } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import SortableHeader from "@/Components/SortableHeader.vue";

const props = defineProps({
    purchaseOrders: Object,
    branches: Array,
    filters: Object,
});

const search = ref(props.filters?.search || "");
const statusFilters = ref(props.filters?.status || []);
const activeBranch = ref(props.filters?.branch_id || "");
const dateFilter = ref(props.filters?.date_filter || "this_month");
const sortBy = ref(props.filters?.sort_by || "");
const sortDirection = ref(props.filters?.sort_direction || "");

// Bảng trạng thái
const allStatuses = [
    { value: "draft", label: "Phiếu tạm" },
    { value: "confirmed", label: "Đã xác nhận NCC" },
    { value: "partial", label: "Nhập một phần" },
    { value: "completed", label: "Đã nhập hàng" },
];

const handleSort = (field, direction) => {
    sortBy.value = field;
    sortDirection.value = direction;
    router.get(
        "/purchase-orders",
        {
            search: search.value,
            status: statusFilters.value,
            branch_id: activeBranch.value,
            date_filter: dateFilter.value,
            sort_by: field,
            sort_direction: direction,
        },
        { preserveState: true, replace: true },
    );
};

let searchTimeout;
const updateFilters = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            "/purchase-orders",
            {
                search: search.value,
                status: statusFilters.value,
                branch_id: activeBranch.value,
                date_filter: dateFilter.value,
                sort_by: sortBy.value,
                sort_direction: sortDirection.value,
            },
            {
                preserveState: true,
                replace: true,
            },
        );
    }, 500);
};

watch([search, statusFilters, activeBranch, dateFilter], updateFilters, {
    deep: true,
});

const removeStatusFilter = (val) => {
    const idx = statusFilters.value.indexOf(val);
    if (idx > -1) {
        statusFilters.value.splice(idx, 1);
    }
};

const expandedRows = ref([]);
const toggleExpand = (id) => {
    const index = expandedRows.value.indexOf(id);
    if (index > -1) {
        expandedRows.value.splice(index, 1);
    } else {
        expandedRows.value.push(id);
    }
};
const isExpanded = (id) => expandedRows.value.includes(id);

const formatCurrency = (val) => Number(val).toLocaleString("vi-VN");
const formatStatus = (val) => {
    const s = allStatuses.find((x) => x.value === val);
    return s ? s.label : val;
};

const calculateWaitDays = (dateStr) => {
    if (!dateStr) return "";
    const diffTime = Math.abs(new Date(dateStr) - new Date());
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    return diffDays;
};

const printPurchaseOrder = (order) => {
    window.open(
        `/purchase-orders/${order.id}/print`,
        "_blank",
        "width=400,height=600",
    );
};
</script>

<template>
    <Head title="Đặt hàng nhập - KiotViet Clone" />
    <AppLayout>
        <template #sidebar>
            <!-- Lọc CHI NHÁNH -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Chi nhánh</label
                >
                <div class="relative">
                    <select
                        v-model="activeBranch"
                        class="w-full border border-gray-300 rounded p-1.5 pl-3 pr-8 text-sm outline-none bg-blue-600 text-white font-medium appearance-none h-[32px]"
                    >
                        <option value="">Tất cả chi nhánh</option>
                        <option
                            v-for="branch in branches"
                            :key="branch.id"
                            :value="branch.id"
                        >
                            {{ branch.name }}
                        </option>
                    </select>
                    <div
                        class="absolute inset-y-0 right-2 flex items-center pointer-events-none"
                    >
                        <svg
                            class="w-4 h-4 text-white"
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
                </div>
            </div>

            <!-- Lọc TRẠNG THÁI -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Trạng thái</label
                >
                <div class="flex flex-wrap gap-2 text-[12px]">
                    <div
                        v-for="status in statusFilters"
                        :key="status"
                        class="bg-blue-600 text-white px-2 py-1 rounded flex items-center gap-1 cursor-pointer"
                    >
                        {{ formatStatus(status) }}
                        <span
                            @click.stop="removeStatusFilter(status)"
                            class="pl-1 border-l border-blue-400 font-bold hover:text-gray-200 ml-1"
                            >&times;</span
                        >
                    </div>
                </div>
                <div class="mt-2 space-y-1">
                    <label
                        v-for="s in allStatuses"
                        :key="s.value"
                        class="flex items-center gap-2 cursor-pointer text-sm text-gray-700"
                    >
                        <input
                            type="checkbox"
                            :value="s.value"
                            v-model="statusFilters"
                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        {{ s.label }}
                    </label>
                </div>
            </div>

            <!-- Lọc THỜI GIAN -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Thời gian</label
                >
                <div class="space-y-2 text-sm text-gray-700">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="radio"
                            v-model="dateFilter"
                            value="this_month"
                            class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Tháng này
                        <svg
                            class="w-3 h-3 ml-auto text-gray-400"
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
                        class="flex items-center gap-2 cursor-pointer text-gray-500"
                    >
                        <input
                            type="radio"
                            v-model="dateFilter"
                            value="custom"
                            class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Tùy chỉnh
                        <svg
                            class="w-4 h-4 ml-auto"
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

            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Người tạo</label
                >
                <input
                    type="text"
                    placeholder="Chọn người tạo"
                    class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none text-gray-700 bg-gray-50"
                />
            </div>

            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Người nhận đặt</label
                >
                <input
                    type="text"
                    placeholder="Chọn người nhận đặt"
                    class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none text-gray-700 bg-gray-50"
                />
            </div>

            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Chi phí nhập trả NCC</label
                >
                <input
                    type="text"
                    placeholder="Chọn loại chi phí"
                    class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none text-gray-700 bg-gray-50"
                />
            </div>
        </template>

        <div class="bg-white h-full flex flex-col pt-3">
            <div
                class="flex items-center justify-between px-4 pb-3 border-b border-gray-200"
            >
                <div class="text-2xl font-bold text-gray-800">
                    Đặt hàng nhập
                </div>

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
                        placeholder="Theo mã phiếu đặt hàng nhập"
                        class="w-full pl-9 pr-8 py-1.5 focus:outline-none border border-gray-300 rounded text-sm placeholder-gray-400"
                    />
                    <svg
                        class="w-4 h-4 absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400"
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

                <div class="flex gap-2 ml-auto">
                    <Link
                        href="/purchase-orders/create"
                        class="bg-white text-blue-600 border border-blue-600 px-3 py-1.5 text-sm font-medium rounded hover:bg-blue-50 transition flex items-center gap-1"
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
                        Đặt hàng nhập
                    </Link>
                    <ExcelButtons export-url="/purchase-orders/export" />
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
                                d="M4 6h16M4 10h16M4 14h16M4 18h16"
                            ></path>
                        </svg>
                    </button>
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
                                d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                            ></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-auto bg-gray-50/30">
                <table class="w-full text-[13px] text-left whitespace-nowrap">
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
                            <th class="px-3 py-2 text-center w-10">
                                <svg
                                    class="w-4 h-4 mx-auto text-gray-400"
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
                            </th>
                            <SortableHeader label="Mã đặt hàng nhập" field="code" :current-sort="sortBy" :current-direction="sortDirection" class="px-2 py-2" @sort="handleSort" />
                            <SortableHeader label="Thời gian" field="created_at" :current-sort="sortBy" :current-direction="sortDirection" class="px-2 py-2" @sort="handleSort" />
                            <th class="px-2 py-2">Nhà cung cấp</th>
                            <th class="px-2 py-2 text-center">
                                Ngày nhập dự kiến
                            </th>
                            <th class="px-2 py-2 text-center">Số ngày chờ</th>
                            <SortableHeader label="Cần trả NCC" field="total_payment" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <SortableHeader label="Trạng thái" field="status" :current-sort="sortBy" :current-direction="sortDirection" align="center" class="px-4 py-2 text-center w-24" @sort="handleSort" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-if="purchaseOrders.data.length === 0">
                            <td
                                colspan="9"
                                class="p-16 text-center text-gray-500"
                            >
                                <div
                                    class="flex flex-col items-center justify-center"
                                >
                                    <div
                                        class="w-16 h-16 bg-blue-50 rounded-full flex items-center justify-center mb-4"
                                    >
                                        <svg
                                            class="w-8 h-8 text-blue-400"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"
                                            ></path>
                                        </svg>
                                    </div>
                                    <h3
                                        class="text-[15px] font-bold text-gray-800 mb-1"
                                    >
                                        Không tìm thấy kết quả
                                    </h3>
                                    <p class="text-[13px]">
                                        Không tìm thấy phiếu đặt hàng nhập nào
                                        phù hợp trong tháng này.<br />Nhấn
                                        <a
                                            href="#"
                                            class="text-blue-500 hover:underline"
                                            >vào đây</a
                                        >
                                        để tiếp tục tìm kiếm.
                                    </p>
                                </div>
                            </td>
                        </tr>
                        <template
                            v-for="order in purchaseOrders.data"
                            :key="order.id"
                        >
                            <tr
                                @click="toggleExpand(order.id)"
                                class="hover:bg-blue-50/40 cursor-pointer transition-colors"
                                :class="{
                                    'bg-[#f4f7fe]': isExpanded(order.id),
                                    'border-l-2 border-l-blue-500': isExpanded(
                                        order.id,
                                    ),
                                }"
                            >
                                <td class="px-3 py-2 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300"
                                    />
                                </td>
                                <td class="px-3 py-2 text-center text-gray-300">
                                    <svg
                                        class="w-4 h-4 mx-auto"
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
                                </td>
                                <td class="px-2 py-2 text-blue-600">
                                    {{ order.code }}
                                </td>
                                <td class="px-2 py-2">
                                    {{
                                        new Date(
                                            order.created_at,
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
                                    {{ order.supplier?.name || "Khách lẻ" }}
                                </td>
                                <td class="px-2 py-2 text-center">
                                    {{
                                        order.expected_date
                                            ? new Date(
                                                  order.expected_date,
                                              ).toLocaleDateString("vi-VN")
                                            : ""
                                    }}
                                </td>
                                <td class="px-2 py-2 text-center">
                                    {{ calculateWaitDays(order.expected_date) }}
                                </td>
                                <td class="px-4 py-2 text-right font-medium">
                                    {{ formatCurrency(order.total_payment) }}
                                </td>
                                <td class="px-4 py-2 text-center">
                                    <span
                                        class="inline-block px-2 text-[11px] py-0.5 rounded border font-medium"
                                        :class="{
                                            'bg-gray-100 text-gray-600 border-gray-200':
                                                order.status === 'draft',
                                            'bg-blue-50 text-blue-700 border-blue-200':
                                                order.status === 'confirmed',
                                            'bg-orange-50 text-orange-600 border-orange-200':
                                                order.status === 'partial',
                                            'bg-green-50 text-green-700 border-green-200':
                                                order.status === 'completed',
                                        }"
                                        >{{ formatStatus(order.status) }}</span
                                    >
                                </td>
                            </tr>
                            <tr
                                v-if="isExpanded(order.id)"
                                class="border-b-4 border-blue-50"
                            >
                                <td
                                    colspan="9"
                                    class="p-0 border-0 bg-white shadow-inner"
                                >
                                    <div
                                        class="px-6 py-4 w-full border-t border-blue-100 flex flex-col items-center justify-center text-gray-500 py-6 min-h-[150px]"
                                    >
                                        <p class="mb-3">
                                            Chi tiết phiếu đặt hàng
                                            {{ order.code }}
                                        </p>
                                        <div class="flex gap-2">
                                            <Link
                                                :href="`/purchases/create?purchase_order_id=${order.id}`"
                                                class="bg-blue-600 text-white px-4 py-1.5 rounded text-sm hover:bg-blue-700 transition font-medium flex items-center gap-1"
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
                                                Tạo phiếu nhập
                                            </Link>
                                            <button
                                                @click.stop="
                                                    printPurchaseOrder(order)
                                                "
                                                class="bg-white border border-gray-300 px-4 py-1.5 rounded text-sm text-gray-700 hover:bg-gray-50 transition font-medium flex items-center gap-1"
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
                    <span class="font-bold">{{
                        purchaseOrders.from || 0
                    }}</span>
                    đến
                    <span class="font-bold">{{ purchaseOrders.to || 0 }}</span>
                    trong tổng số
                    <span class="font-bold">{{
                        purchaseOrders.total || 0
                    }}</span>
                    phiếu
                </div>
                <!-- Pagination -->
                <div
                    class="flex gap-1"
                    v-if="
                        purchaseOrders.links && purchaseOrders.links.length > 3
                    "
                >
                    <template
                        v-for="(link, index) in purchaseOrders.links"
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
