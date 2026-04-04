<script setup>
import { ref, watch, computed, onMounted, onBeforeUnmount } from "vue";
import { Head, router, Link } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import SortableHeader from "@/Components/SortableHeader.vue";

const props = defineProps({
    orders: Object,
    branches: Array,
    employees: Array,
    filters: Object,
});

const salesChannels = [
    "Trực tiếp",
    "Facebook",
    "Zalo",
    "Shopee",
    "Lazada",
    "Tiki",
    "Website",
    "Khác",
];

const updateOrder = (orderId, field, value) => {
    router.put(
        `/orders/${orderId}`,
        { [field]: value },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
};

const search = ref(props.filters?.search || "");
const statusFilters = ref(props.filters?.status || []);
const activeBranch = ref(props.filters?.branch_id || "");
const dateFilter = ref(props.filters?.date_filter || "this_month");
const sortBy = ref(props.filters?.sort_by || "");
const sortDirection = ref(props.filters?.sort_direction || "");

// Bảng trạng thái
const allStatuses = [
    { value: "draft", label: "Phiếu tạm" },
    { value: "confirmed", label: "Đã xác nhận" },
    { value: "delivering", label: "Đang giao hàng" },
    { value: "completed", label: "Hoàn thành" },
    { value: "cancelled", label: "Đã hủy" },
    { value: "return", label: "Trả hàng" },
];

// Status transition map - từ trạng thái nào có thể chuyển sang trạng thái nào
const statusTransitions = {
    draft: ["draft", "confirmed", "cancelled"],
    confirmed: ["confirmed", "delivering", "completed", "cancelled"],
    delivering: ["delivering", "completed", "cancelled"],
    completed: ["completed"],
    cancelled: ["cancelled"],
    return: ["return"],
};

const getAvailableStatuses = (currentStatus) => {
    const allowed = statusTransitions[currentStatus] || [currentStatus];
    return allStatuses.filter((s) => allowed.includes(s.value));
};

const buildAddress = (order) => {
    return [
        order.receiver_address,
        order.receiver_ward,
        order.receiver_district,
        order.receiver_city,
    ]
        .filter(Boolean)
        .join(", ");
};

// Custom dropdown state
const openDropdown = ref(null); // format: 'status-{orderId}', 'channel-{orderId}', 'employee-{orderId}'
const dropdownSearch = ref("");

const toggleDropdown = (key) => {
    if (openDropdown.value === key) {
        openDropdown.value = null;
        dropdownSearch.value = "";
    } else {
        openDropdown.value = key;
        dropdownSearch.value = "";
    }
};

const selectDropdownItem = (orderId, field, value) => {
    updateOrder(orderId, field, value);
    openDropdown.value = null;
    dropdownSearch.value = "";
};

// Click outside to close dropdown
const handleClickOutside = (e) => {
    if (openDropdown.value && !e.target.closest(".custom-dropdown")) {
        openDropdown.value = null;
        dropdownSearch.value = "";
    }
};
onMounted(() => document.addEventListener("click", handleClickOutside));
onBeforeUnmount(() =>
    document.removeEventListener("click", handleClickOutside),
);

const handleSort = (field, direction) => {
    sortBy.value = field;
    sortDirection.value = direction;
    router.get(
        "/orders",
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
            "/orders",
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

const formatCurrency = (val) => Number(val || 0).toLocaleString("vi-VN");
const formatStatus = (val) => {
    const s = allStatuses.find((x) => x.value === val);
    return s ? s.label : val;
};

const printOrder = (order) => {
    window.open(`/orders/${order.id}/print`, "_blank");
};

const cancelOrder = (order) => {
    if (!confirm("Bạn có chắc muốn hủy đơn hàng này?")) return;
    updateOrder(order.id, "status", "cancelled");
};
</script>

<template>
    <Head title="Đặt hàng - KiotViet Clone" />
    <AppLayout>
        <template #sidebar>
            <!-- Lọc CHI NHÁNH -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Chi nhánh xử lý</label
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

            <!-- Đối tác giao hàng -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Đối tác giao hàng</label
                >
                <input
                    type="text"
                    placeholder="Chọn đối tác giao hàng"
                    class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none text-gray-700 bg-gray-50"
                />
            </div>

            <!-- Thời gian giao hàng -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Thời gian giao hàng</label
                >
                <div class="space-y-2 text-sm text-gray-700">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="radio"
                            value="all_time"
                            name="delivery_time"
                            class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                            checked
                        />
                        Toàn thời gian
                    </label>
                    <label
                        class="flex items-center gap-2 cursor-pointer text-gray-500"
                    >
                        <input
                            type="radio"
                            value="custom"
                            name="delivery_time"
                            class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Tùy chỉnh
                    </label>
                </div>
            </div>

            <!-- Khu vực giao hàng -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Khu vực giao hàng</label
                >
                <input
                    type="text"
                    placeholder="Chọn Tỉnh/TP - Quận/Huyện"
                    class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none text-gray-700 bg-gray-50"
                />
            </div>

            <!-- Phương thức thanh toán -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Phương thức thanh toán</label
                >
                <input
                    type="text"
                    placeholder="Chọn phương thức thanh toán..."
                    class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none text-gray-700 bg-gray-50"
                />
            </div>
        </template>

        <div class="bg-white h-full flex flex-col pt-3">
            <div
                class="flex items-center justify-between px-4 pb-3 border-b border-gray-200"
            >
                <div class="text-2xl font-bold text-gray-800">Đặt hàng</div>

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
                        placeholder="Theo mã phiếu đặt"
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
                        href="/orders/create"
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
                        Đặt hàng
                    </Link>
                    <button
                        class="bg-white text-gray-600 border border-gray-300 px-3 py-1.5 text-sm font-medium rounded hover:bg-gray-50 transition flex items-center gap-1"
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
                                d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"
                            ></path>
                        </svg>
                        Gộp đơn
                    </button>
                    <ExcelButtons export-url="/orders/export" />
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
                            <th class="px-3 py-2 text-center w-8">
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
                            <SortableHeader label="Mã đặt hàng" field="code" :current-sort="sortBy" :current-direction="sortDirection" class="px-2 py-2" @sort="handleSort" />
                            <SortableHeader label="Thời gian" field="created_at" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" class="px-2 py-2" @sort="handleSort" />
                            <th class="px-2 py-2">Khách hàng</th>
                            <SortableHeader label="Khách cần trả" field="total_payment" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <SortableHeader label="Khách đã trả" field="amount_paid" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <SortableHeader label="Trạng thái" field="status" :current-sort="sortBy" :current-direction="sortDirection" class="px-4 py-2" @sort="handleSort" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="orders.data.length === 0">
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
                                                d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"
                                            ></path>
                                        </svg>
                                    </div>
                                    <h3
                                        class="text-[17px] font-bold text-gray-800 mb-1"
                                    >
                                        Không tìm thấy kết quả
                                    </h3>
                                    <p class="text-[14px]">
                                        Không tìm thấy giao dịch nào phù hợp
                                        trong tháng này.<br />Nhấn
                                        <a
                                            href="#"
                                            @click.prevent="search = ''"
                                            class="text-blue-500 hover:underline"
                                            >vào đây</a
                                        >
                                        để tiếp tục tìm kiếm.
                                    </p>
                                </div>
                            </td>
                        </tr>
                        <template v-for="order in orders.data" :key="order.id">
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
                                <td
                                    class="px-2 py-2 font-medium"
                                    :class="
                                        isExpanded(order.id)
                                            ? 'text-gray-900'
                                            : 'text-blue-600'
                                    "
                                >
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
                                    {{ order.customer?.name || "Khách lẻ" }}
                                </td>
                                <td class="px-4 py-2 text-right font-medium">
                                    {{ formatCurrency(order.total_payment) }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ formatCurrency(order.amount_paid) }}
                                </td>
                                <td class="px-4 py-2 text-left">
                                    <span
                                        class="inline-block"
                                        :class="{
                                            'text-orange-500':
                                                order.status === 'draft',
                                            'text-blue-600':
                                                order.status === 'confirmed',
                                            'text-blue-500':
                                                order.status === 'delivering',
                                            'text-green-600':
                                                order.status === 'completed',
                                            'text-red-500':
                                                order.status === 'cancelled',
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
                                                class="px-4 pb-2 border-b-2 border-blue-600 text-blue-600"
                                            >
                                                Thông tin
                                            </button>
                                        </div>

                                        <!-- Header Info -->
                                        <div
                                            class="flex items-center gap-2 mb-4"
                                        >
                                            <h2
                                                class="text-xl font-bold text-gray-800"
                                            >
                                                {{
                                                    order.customer?.name ||
                                                    "Khách lẻ"
                                                }}
                                            </h2>
                                            <svg
                                                class="w-4 h-4 text-blue-500 cursor-pointer"
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
                                                class="text-gray-500 text-sm ml-2"
                                                >{{ order.code }}</span
                                            >
                                            <div
                                                class="ml-auto text-[13px] text-gray-500"
                                            >
                                                {{
                                                    order.branch?.name ||
                                                    "Laptopplus.vn"
                                                }}
                                            </div>
                                        </div>

                                        <div class="flex flex-col gap-6">
                                            <!-- Top details -->
                                            <div
                                                class="grid grid-cols-3 gap-x-12 gap-y-3 text-[13px] text-gray-700"
                                            >
                                                <div class="flex items-center">
                                                    <span
                                                        class="text-gray-400 w-28"
                                                        >Người tạo:</span
                                                    >
                                                    <span
                                                        class="font-medium text-gray-800"
                                                        >{{
                                                            order.created_by_name ||
                                                            "—"
                                                        }}</span
                                                    >
                                                </div>
                                                <!-- Người nhận đặt: custom searchable dropdown -->
                                                <div class="flex items-center">
                                                    <span
                                                        class="text-gray-400 w-32"
                                                        >Người nhận đặt:</span
                                                    >
                                                    <div
                                                        class="flex-1 relative custom-dropdown"
                                                        @click.stop
                                                    >
                                                        <div
                                                            @click="
                                                                toggleDropdown(
                                                                    'employee-' +
                                                                        order.id,
                                                                )
                                                            "
                                                            class="border border-gray-300 rounded px-2 py-0.5 cursor-pointer flex items-center justify-between hover:border-blue-400"
                                                        >
                                                            <span>{{
                                                                order.assigned_to_name ||
                                                                "—"
                                                            }}</span>
                                                            <svg
                                                                class="w-3 h-3 text-gray-400"
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
                                                        <div
                                                            v-if="
                                                                openDropdown ===
                                                                'employee-' +
                                                                    order.id
                                                            "
                                                            class="absolute top-full left-0 mt-1 w-56 bg-white border border-gray-200 rounded shadow-lg z-50 max-h-60 flex flex-col"
                                                        >
                                                            <div
                                                                class="p-2 border-b border-gray-100"
                                                            >
                                                                <div
                                                                    class="relative"
                                                                >
                                                                    <svg
                                                                        class="w-3.5 h-3.5 absolute left-2 top-1/2 -translate-y-1/2 text-gray-400"
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
                                                                        v-model="
                                                                            dropdownSearch
                                                                        "
                                                                        type="text"
                                                                        placeholder="Tìm kiếm..."
                                                                        class="w-full pl-7 pr-2 py-1 border border-gray-200 rounded text-[12px] outline-none focus:border-blue-400"
                                                                    />
                                                                </div>
                                                            </div>
                                                            <div
                                                                class="overflow-auto flex-1"
                                                            >
                                                                <div
                                                                    v-for="emp in employees.filter(
                                                                        (e) =>
                                                                            !dropdownSearch ||
                                                                            e.name
                                                                                .toLowerCase()
                                                                                .includes(
                                                                                    dropdownSearch.toLowerCase(),
                                                                                ),
                                                                    )"
                                                                    :key="
                                                                        emp.id
                                                                    "
                                                                    @click="
                                                                        selectDropdownItem(
                                                                            order.id,
                                                                            'assigned_to_name',
                                                                            emp.name,
                                                                        )
                                                                    "
                                                                    class="px-3 py-1.5 hover:bg-blue-50 cursor-pointer flex items-center gap-2 text-[12px]"
                                                                >
                                                                    <svg
                                                                        v-if="
                                                                            order.assigned_to_name ===
                                                                            emp.name
                                                                        "
                                                                        class="w-3.5 h-3.5 text-blue-600 flex-shrink-0"
                                                                        fill="none"
                                                                        stroke="currentColor"
                                                                        viewBox="0 0 24 24"
                                                                    >
                                                                        <path
                                                                            stroke-linecap="round"
                                                                            stroke-linejoin="round"
                                                                            stroke-width="2"
                                                                            d="M5 13l4 4L19 7"
                                                                        ></path>
                                                                    </svg>
                                                                    <span
                                                                        v-else
                                                                        class="w-3.5 flex-shrink-0"
                                                                    ></span>
                                                                    <span
                                                                        :class="
                                                                            order.assigned_to_name ===
                                                                            emp.name
                                                                                ? 'text-blue-600 font-medium'
                                                                                : ''
                                                                        "
                                                                        >{{
                                                                            emp.name
                                                                        }}</span
                                                                    >
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="flex items-center">
                                                    <span
                                                        class="text-gray-400 w-20"
                                                        >Ngày đặt:</span
                                                    >
                                                    <span
                                                        class="text-gray-800 flex items-center gap-1"
                                                        >{{
                                                            new Date(
                                                                order.created_at,
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
                                                        }}
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
                                                                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                                                            ></path></svg
                                                    ></span>
                                                </div>
                                                <!-- Kênh bán: custom searchable dropdown -->
                                                <div class="flex items-center">
                                                    <span
                                                        class="text-gray-400 w-28"
                                                        >Kênh bán:</span
                                                    >
                                                    <div
                                                        class="flex-1 relative custom-dropdown"
                                                        @click.stop
                                                    >
                                                        <div
                                                            @click="
                                                                toggleDropdown(
                                                                    'channel-' +
                                                                        order.id,
                                                                )
                                                            "
                                                            class="border border-gray-300 rounded px-2 py-0.5 cursor-pointer flex items-center justify-between hover:border-blue-400"
                                                        >
                                                            <span>{{
                                                                order.sales_channel ||
                                                                "Trực tiếp"
                                                            }}</span>
                                                            <svg
                                                                class="w-3 h-3 text-gray-400"
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
                                                        <div
                                                            v-if="
                                                                openDropdown ===
                                                                'channel-' +
                                                                    order.id
                                                            "
                                                            class="absolute top-full left-0 mt-1 w-48 bg-white border border-gray-200 rounded shadow-lg z-50 max-h-60 flex flex-col"
                                                        >
                                                            <div
                                                                class="p-2 border-b border-gray-100"
                                                            >
                                                                <div
                                                                    class="relative"
                                                                >
                                                                    <svg
                                                                        class="w-3.5 h-3.5 absolute left-2 top-1/2 -translate-y-1/2 text-gray-400"
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
                                                                        v-model="
                                                                            dropdownSearch
                                                                        "
                                                                        type="text"
                                                                        placeholder="Tìm kiếm..."
                                                                        class="w-full pl-7 pr-2 py-1 border border-gray-200 rounded text-[12px] outline-none focus:border-blue-400"
                                                                    />
                                                                </div>
                                                            </div>
                                                            <div
                                                                class="overflow-auto flex-1"
                                                            >
                                                                <div
                                                                    v-for="ch in salesChannels.filter(
                                                                        (c) =>
                                                                            !dropdownSearch ||
                                                                            c
                                                                                .toLowerCase()
                                                                                .includes(
                                                                                    dropdownSearch.toLowerCase(),
                                                                                ),
                                                                    )"
                                                                    :key="ch"
                                                                    @click="
                                                                        selectDropdownItem(
                                                                            order.id,
                                                                            'sales_channel',
                                                                            ch,
                                                                        )
                                                                    "
                                                                    class="px-3 py-1.5 hover:bg-blue-50 cursor-pointer flex items-center gap-2 text-[12px]"
                                                                >
                                                                    <svg
                                                                        v-if="
                                                                            (order.sales_channel ||
                                                                                'Trực tiếp') ===
                                                                            ch
                                                                        "
                                                                        class="w-3.5 h-3.5 text-blue-600 flex-shrink-0"
                                                                        fill="none"
                                                                        stroke="currentColor"
                                                                        viewBox="0 0 24 24"
                                                                    >
                                                                        <path
                                                                            stroke-linecap="round"
                                                                            stroke-linejoin="round"
                                                                            stroke-width="2"
                                                                            d="M5 13l4 4L19 7"
                                                                        ></path>
                                                                    </svg>
                                                                    <span
                                                                        v-else
                                                                        class="w-3.5 flex-shrink-0"
                                                                    ></span>
                                                                    <span
                                                                        :class="
                                                                            (order.sales_channel ||
                                                                                'Trực tiếp') ===
                                                                            ch
                                                                                ? 'text-blue-600 font-medium'
                                                                                : ''
                                                                        "
                                                                        >{{
                                                                            ch
                                                                        }}</span
                                                                    >
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <!-- Trạng thái: custom searchable dropdown với transition logic -->
                                                <div class="flex items-center">
                                                    <span
                                                        class="text-gray-400 w-32"
                                                        >Trạng thái:</span
                                                    >
                                                    <div
                                                        class="flex-1 relative custom-dropdown"
                                                        @click.stop
                                                    >
                                                        <div
                                                            @click="
                                                                toggleDropdown(
                                                                    'status-' +
                                                                        order.id,
                                                                )
                                                            "
                                                            class="border border-gray-300 rounded px-2 py-0.5 cursor-pointer flex items-center justify-between hover:border-blue-400"
                                                        >
                                                            <span>{{
                                                                formatStatus(
                                                                    order.status,
                                                                )
                                                            }}</span>
                                                            <svg
                                                                class="w-3 h-3 text-gray-400"
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
                                                        <div
                                                            v-if="
                                                                openDropdown ===
                                                                'status-' +
                                                                    order.id
                                                            "
                                                            class="absolute top-full left-0 mt-1 w-48 bg-white border border-gray-200 rounded shadow-lg z-50 max-h-60 flex flex-col"
                                                        >
                                                            <div
                                                                class="p-2 border-b border-gray-100"
                                                            >
                                                                <div
                                                                    class="relative"
                                                                >
                                                                    <svg
                                                                        class="w-3.5 h-3.5 absolute left-2 top-1/2 -translate-y-1/2 text-gray-400"
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
                                                                        v-model="
                                                                            dropdownSearch
                                                                        "
                                                                        type="text"
                                                                        placeholder="Tìm kiếm..."
                                                                        class="w-full pl-7 pr-2 py-1 border border-gray-200 rounded text-[12px] outline-none focus:border-blue-400"
                                                                    />
                                                                </div>
                                                            </div>
                                                            <div
                                                                class="overflow-auto flex-1"
                                                            >
                                                                <div
                                                                    v-for="s in getAvailableStatuses(
                                                                        order.status,
                                                                    ).filter(
                                                                        (st) =>
                                                                            !dropdownSearch ||
                                                                            st.label
                                                                                .toLowerCase()
                                                                                .includes(
                                                                                    dropdownSearch.toLowerCase(),
                                                                                ),
                                                                    )"
                                                                    :key="
                                                                        s.value
                                                                    "
                                                                    @click="
                                                                        selectDropdownItem(
                                                                            order.id,
                                                                            'status',
                                                                            s.value,
                                                                        )
                                                                    "
                                                                    class="px-3 py-1.5 hover:bg-blue-50 cursor-pointer flex items-center gap-2 text-[12px]"
                                                                >
                                                                    <svg
                                                                        v-if="
                                                                            order.status ===
                                                                            s.value
                                                                        "
                                                                        class="w-3.5 h-3.5 text-blue-600 flex-shrink-0"
                                                                        fill="none"
                                                                        stroke="currentColor"
                                                                        viewBox="0 0 24 24"
                                                                    >
                                                                        <path
                                                                            stroke-linecap="round"
                                                                            stroke-linejoin="round"
                                                                            stroke-width="2"
                                                                            d="M5 13l4 4L19 7"
                                                                        ></path>
                                                                    </svg>
                                                                    <span
                                                                        v-else
                                                                        class="w-3.5 flex-shrink-0"
                                                                    ></span>
                                                                    <span
                                                                        :class="
                                                                            order.status ===
                                                                            s.value
                                                                                ? 'text-blue-600 font-medium'
                                                                                : ''
                                                                        "
                                                                        >{{
                                                                            s.label
                                                                        }}</span
                                                                    >
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="flex items-center">
                                                    <span
                                                        class="text-gray-400 w-28"
                                                        >Bảng giá:</span
                                                    >
                                                    <span
                                                        class="text-gray-800"
                                                        >{{
                                                            order.price_book_name ||
                                                            "Bảng giá chung"
                                                        }}</span
                                                    >
                                                </div>
                                                <div class="flex items-center">
                                                    <span
                                                        class="text-gray-400 w-32"
                                                        >Chi nhánh xử lý:</span
                                                    >
                                                    <span
                                                        class="text-gray-500"
                                                        >{{
                                                            order.branch
                                                                ?.name || "—"
                                                        }}</span
                                                    >
                                                </div>
                                            </div>

                                            <!-- Order Items Table -->
                                            <div
                                                class="border border-gray-200 rounded"
                                                v-if="
                                                    order.items &&
                                                    order.items.length
                                                "
                                            >
                                                <table
                                                    class="w-full text-[13px]"
                                                >
                                                    <thead
                                                        class="bg-gray-50 text-gray-600 font-semibold"
                                                    >
                                                        <tr>
                                                            <th
                                                                class="px-3 py-2 text-left"
                                                            >
                                                                Mã hàng
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-left"
                                                            >
                                                                Tên hàng
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-right"
                                                            >
                                                                Số lượng
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-right"
                                                            >
                                                                Đơn giá
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-right"
                                                            >
                                                                Giảm giá
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-right"
                                                            >
                                                                Thành tiền
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody
                                                        class="divide-y divide-gray-100"
                                                    >
                                                        <tr
                                                            v-for="item in order.items"
                                                            :key="item.id"
                                                        >
                                                            <td
                                                                class="px-3 py-2 text-blue-600"
                                                            >
                                                                {{
                                                                    item.product
                                                                        ?.sku ||
                                                                    "—"
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2"
                                                            >
                                                                {{
                                                                    item.product
                                                                        ?.name ||
                                                                    "—"
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2 text-right"
                                                            >
                                                                {{ item.qty }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2 text-right"
                                                            >
                                                                {{
                                                                    formatCurrency(
                                                                        item.price,
                                                                    )
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2 text-right"
                                                            >
                                                                {{
                                                                    formatCurrency(
                                                                        item.discount,
                                                                    )
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2 text-right font-medium"
                                                            >
                                                                {{
                                                                    formatCurrency(
                                                                        item.subtotal,
                                                                    )
                                                                }}
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                    <tfoot
                                                        class="bg-gray-50 font-semibold text-[13px]"
                                                    >
                                                        <tr>
                                                            <td
                                                                colspan="5"
                                                                class="px-3 py-2 text-right"
                                                            >
                                                                Tổng tiền hàng:
                                                            </td>
                                                            <td
                                                                class="px-3 py-2 text-right"
                                                            >
                                                                {{
                                                                    formatCurrency(
                                                                        order.total_price,
                                                                    )
                                                                }}
                                                            </td>
                                                        </tr>
                                                        <tr
                                                            v-if="
                                                                order.discount >
                                                                0
                                                            "
                                                        >
                                                            <td
                                                                colspan="5"
                                                                class="px-3 py-1.5 text-right text-gray-500"
                                                            >
                                                                Giảm giá:
                                                            </td>
                                                            <td
                                                                class="px-3 py-1.5 text-right text-red-500"
                                                            >
                                                                -{{
                                                                    formatCurrency(
                                                                        order.discount,
                                                                    )
                                                                }}
                                                            </td>
                                                        </tr>
                                                        <tr
                                                            v-if="
                                                                order.other_fees >
                                                                0
                                                            "
                                                        >
                                                            <td
                                                                colspan="5"
                                                                class="px-3 py-1.5 text-right text-gray-500"
                                                            >
                                                                Phí khác:
                                                            </td>
                                                            <td
                                                                class="px-3 py-1.5 text-right"
                                                            >
                                                                {{
                                                                    formatCurrency(
                                                                        order.other_fees,
                                                                    )
                                                                }}
                                                            </td>
                                                        </tr>
                                                        <tr
                                                            class="text-blue-700"
                                                        >
                                                            <td
                                                                colspan="5"
                                                                class="px-3 py-2 text-right font-bold"
                                                            >
                                                                Khách cần trả:
                                                            </td>
                                                            <td
                                                                class="px-3 py-2 text-right font-bold"
                                                            >
                                                                {{
                                                                    formatCurrency(
                                                                        order.total_payment,
                                                                    )
                                                                }}
                                                            </td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>

                                            <!-- Delivery Details Section -->
                                            <div
                                                class="border border-gray-200 rounded min-h-[200px]"
                                            >
                                                <div
                                                    class="px-4 py-2 border-b border-gray-100 bg-gray-50 flex items-center font-bold text-gray-700"
                                                >
                                                    <svg
                                                        class="w-4 h-4 mr-2"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        viewBox="0 0 24 24"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"
                                                        ></path>
                                                    </svg>
                                                    Tự giao đến:
                                                    {{
                                                        buildAddress(order) ||
                                                        "Chưa có địa chỉ"
                                                    }}
                                                </div>
                                                <div
                                                    class="p-4 grid grid-cols-3 gap-x-8 gap-y-4 text-[13px]"
                                                >
                                                    <div
                                                        class="col-span-3 flex items-center gap-2 mb-2"
                                                    >
                                                        <input
                                                            type="radio"
                                                            checked
                                                            class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                                                        />
                                                        <span
                                                            class="font-medium text-blue-600"
                                                            >Địa chỉ lấy
                                                            hàng:</span
                                                        >
                                                        <span
                                                            >{{
                                                                order.branch
                                                                    ?.address ||
                                                                "Chưa có địa chỉ"
                                                            }}
                                                            {{
                                                                order.branch
                                                                    ?.phone
                                                                    ? "- " +
                                                                      order
                                                                          .branch
                                                                          .phone
                                                                    : ""
                                                            }}</span
                                                        >
                                                    </div>

                                                    <div class="space-y-4">
                                                        <div
                                                            class="flex items-center"
                                                        >
                                                            <span
                                                                class="text-gray-500 w-24"
                                                                >Người
                                                                nhận:</span
                                                            >
                                                            <input
                                                                type="text"
                                                                class="flex-1 border border-gray-300 rounded px-2 py-1.5 focus:border-blue-500 outline-none"
                                                                :value="
                                                                    order.receiver_name ||
                                                                    order
                                                                        .customer
                                                                        ?.name ||
                                                                    ''
                                                                "
                                                            />
                                                        </div>
                                                        <div
                                                            class="flex items-center"
                                                        >
                                                            <span
                                                                class="text-gray-500 w-24"
                                                                >Điện
                                                                thoại:</span
                                                            >
                                                            <input
                                                                type="text"
                                                                class="flex-1 border border-gray-300 rounded px-2 py-1.5 focus:border-blue-500 outline-none"
                                                                :value="
                                                                    order.receiver_phone ||
                                                                    order
                                                                        .customer
                                                                        ?.phone ||
                                                                    ''
                                                                "
                                                            />
                                                        </div>
                                                        <div
                                                            class="flex items-center"
                                                        >
                                                            <span
                                                                class="text-gray-500 w-24"
                                                                >Địa chỉ:</span
                                                            >
                                                            <input
                                                                type="text"
                                                                class="flex-1 border border-gray-300 rounded px-2 py-1.5 focus:border-blue-500 outline-none"
                                                                :value="
                                                                    buildAddress(
                                                                        order,
                                                                    )
                                                                "
                                                            />
                                                        </div>
                                                        <div
                                                            class="flex items-center"
                                                        >
                                                            <span
                                                                class="text-gray-500 w-24"
                                                                >Khu vực:</span
                                                            >
                                                            <input
                                                                type="text"
                                                                class="flex-1 border border-gray-300 rounded px-2 py-1.5 focus:border-blue-500 outline-none"
                                                                placeholder="Chọn Tỉnh/Thành phố"
                                                            />
                                                        </div>
                                                        <div
                                                            class="flex items-center"
                                                        >
                                                            <span
                                                                class="text-gray-500 w-24"
                                                                >Phường/Xã:</span
                                                            >
                                                            <input
                                                                type="text"
                                                                class="flex-1 border border-gray-300 rounded px-2 py-1.5 focus:border-blue-500 outline-none"
                                                                placeholder="Chọn Phường/Xã"
                                                            />
                                                        </div>
                                                    </div>

                                                    <div class="space-y-4">
                                                        <div
                                                            class="flex items-center"
                                                        >
                                                            <span
                                                                class="text-gray-500 w-24"
                                                                >Mã vận
                                                                đơn:</span
                                                            >
                                                            <input
                                                                type="text"
                                                                class="flex-1 border border-gray-300 rounded px-2 py-1.5 focus:border-blue-500 outline-none"
                                                                :value="
                                                                    order.tracking_code ||
                                                                    ''
                                                                "
                                                            />
                                                        </div>
                                                        <div
                                                            class="flex items-center"
                                                        >
                                                            <span
                                                                class="text-gray-500 w-24"
                                                                >Trọng
                                                                lượng:</span
                                                            >
                                                            <div
                                                                class="flex-1 flex gap-2"
                                                            >
                                                                <input
                                                                    type="text"
                                                                    class="w-full border border-gray-300 rounded px-2 py-1.5 focus:border-blue-500 outline-none"
                                                                    :value="
                                                                        order.weight ||
                                                                        '0'
                                                                    "
                                                                />
                                                                <span
                                                                    class="py-1.5 text-gray-500"
                                                                    >g</span
                                                                >
                                                            </div>
                                                        </div>
                                                        <div
                                                            class="flex items-center gap-2"
                                                        >
                                                            <span
                                                                class="text-gray-500 w-20"
                                                                >Kích
                                                                thước:</span
                                                            >
                                                            <input
                                                                type="text"
                                                                placeholder="Dài"
                                                                class="w-12 border border-gray-300 rounded px-2 py-1.5 focus:border-blue-500 outline-none text-center"
                                                            />
                                                            <input
                                                                type="text"
                                                                placeholder="Rộng"
                                                                class="w-12 border border-gray-300 rounded px-2 py-1.5 focus:border-blue-500 outline-none text-center"
                                                            />
                                                            <input
                                                                type="text"
                                                                placeholder="Cao"
                                                                class="w-12 border border-gray-300 rounded px-2 py-1.5 focus:border-blue-500 outline-none text-center"
                                                            />
                                                        </div>
                                                        <div
                                                            class="flex items-center"
                                                        >
                                                            <span
                                                                class="text-gray-500 w-24"
                                                                >Dịch vụ:</span
                                                            >
                                                            <select
                                                                class="flex-1 border border-gray-300 rounded px-2 py-1.5 outline-none"
                                                            >
                                                                <option>
                                                                    Giao thường
                                                                </option>
                                                            </select>
                                                        </div>
                                                    </div>

                                                    <div class="space-y-4">
                                                        <div
                                                            class="flex items-center"
                                                        >
                                                            <span
                                                                class="text-gray-500 w-24"
                                                                >Người
                                                                giao:</span
                                                            >
                                                            <select
                                                                class="flex-1 border border-gray-300 rounded px-2 py-1.5 outline-none"
                                                            >
                                                                <option>
                                                                    Chọn đối tác
                                                                </option>
                                                            </select>
                                                        </div>
                                                        <div
                                                            class="flex items-center"
                                                        >
                                                            <span
                                                                class="text-gray-500 w-24"
                                                                >Thu hộ
                                                                tiền:</span
                                                            >
                                                            <input
                                                                type="checkbox"
                                                                class="rounded border-gray-300 focus:ring-blue-500 w-4 h-4"
                                                            />
                                                        </div>
                                                        <div
                                                            class="flex items-center"
                                                        >
                                                            <span
                                                                class="text-gray-500 w-24"
                                                                >Phí trả
                                                                ĐTGH:</span
                                                            >
                                                            <span
                                                                class="flex-1 text-right font-medium"
                                                                >0</span
                                                            >
                                                        </div>
                                                        <div
                                                            class="flex items-center"
                                                        >
                                                            <span
                                                                class="text-gray-500 w-24"
                                                                >Thời gian
                                                                giao:</span
                                                            >
                                                            <div
                                                                class="flex-1 relative"
                                                            >
                                                                <input
                                                                    type="text"
                                                                    class="w-full border border-gray-300 rounded px-2 py-1.5 focus:border-blue-500 outline-none pr-8"
                                                                />
                                                                <svg
                                                                    class="w-4 h-4 text-gray-400 absolute right-2 top-1/2 -translate-y-1/2"
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
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Delivery Note -->
                                                <div
                                                    class="p-4 border-t border-gray-100"
                                                >
                                                    <textarea
                                                        class="w-full border border-gray-300 rounded p-2 text-gray-500 outline-none focus:border-blue-500"
                                                        rows="2"
                                                        placeholder="Ghi chú giao..."
                                                    ></textarea>
                                                </div>
                                            </div>

                                            <div
                                                class="flex justify-end gap-2 my-2"
                                            >
                                                <button
                                                    @click="printOrder(order)"
                                                    class="bg-white border border-gray-300 px-4 py-1.5 rounded text-gray-700 font-bold hover:bg-gray-50 shadow-sm flex items-center gap-1"
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
                                                <button
                                                    @click="
                                                        toggleExpand(order.id)
                                                    "
                                                    class="bg-blue-600 text-white px-4 py-1.5 rounded font-bold hover:bg-blue-700 shadow-sm flex items-center gap-1"
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
                                                            d="M5 13l4 4L19 7"
                                                        ></path>
                                                    </svg>
                                                    Đóng
                                                </button>
                                                <button
                                                    v-if="
                                                        order.status !==
                                                            'cancelled' &&
                                                        order.status !==
                                                            'completed'
                                                    "
                                                    @click="cancelOrder(order)"
                                                    class="bg-white border border-gray-300 px-4 py-1.5 rounded text-gray-700 font-bold hover:bg-gray-50 shadow-sm flex items-center gap-1"
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
                                                    Hủy bỏ
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
                class="flex items-center justify-between p-3 border-t border-gray-200 bg-gray-50/50 text-sm flex-shrink-0"
            >
                <div class="text-gray-600">
                    Hiển thị từ
                    <span class="font-bold">{{ orders.from || 0 }}</span> đến
                    <span class="font-bold">{{ orders.to || 0 }}</span> trong
                    tổng số
                    <span class="font-bold">{{ orders.total || 0 }}</span> phiếu
                </div>
                <!-- Pagination -->
                <div
                    class="flex gap-1"
                    v-if="orders.links && orders.links.length > 3"
                >
                    <template
                        v-for="(link, index) in orders.links"
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
