<script setup>
import { ref, watch, computed } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import SortableHeader from "@/Components/SortableHeader.vue";

const debounce = (fn, delay) => {
    let timeoutID;
    return (...args) => {
        if (timeoutID) clearTimeout(timeoutID);
        timeoutID = setTimeout(() => {
            fn(...args);
        }, delay);
    };
};

const props = defineProps({
    damages: Object,
    branches: Array,
    filters: Object,
});

const searchQuery = ref(props.filters.search || "");
const expandedRow = ref(null);
const activeDateFilter = ref(props.filters.date_filter || "all");
const creatorQuery = ref(props.filters.created_by_name || "");
const destroyerQuery = ref(props.filters.destroyed_by_name || "");
const branchId = ref(props.filters.branch_id || "");
const sortBy = ref(props.filters.sort_by || "");
const sortDirection = ref(props.filters.sort_direction || "");

const activeStatusFilters = ref({
    "Phiếu tạm": props.filters.status?.includes("draft") ?? true,
    "Hoàn thành": props.filters.status?.includes("completed") ?? true,
    "Đã hủy": props.filters.status?.includes("cancelled") ?? false,
});

const toggleRow = (id) => {
    expandedRow.value = expandedRow.value === id ? null : id;
};

const formatCurrency = (val) => Number(val).toLocaleString("vi-VN");
const formatDate = (dateStr) => {
    if (!dateStr) return "";
    const date = new Date(dateStr);
    return `${date.getDate().toString().padStart(2, "0")}/${(date.getMonth() + 1).toString().padStart(2, "0")}/${date.getFullYear()} ${date.getHours().toString().padStart(2, "0")}:${date.getMinutes().toString().padStart(2, "0")}`;
};

const getStatusClass = (status) => {
    if (status === "completed")
        return "bg-green-100 text-green-700 pointer-events-none px-2 py-0.5 rounded text-[12px]";
    if (status === "draft")
        return "bg-orange-100 text-orange-700 pointer-events-none px-2 py-0.5 rounded text-[12px]";
    return "bg-gray-100 text-gray-700 pointer-events-none px-2 py-0.5 rounded text-[12px]";
};
const getStatusTextColor = (status) => {
    if (status === "completed") return "text-green-600";
    if (status === "draft") return "text-orange-500";
    return "text-gray-500";
};

const getStatusLabelText = (status) => {
    if (status === "completed") return "Hoàn thành";
    if (status === "draft") return "Phiếu tạm";
    if (status === "cancelled") return "Đã hủy";
    return "Chưa rõ";
};

const handleSort = (field, direction) => {
    sortBy.value = field;
    sortDirection.value = direction;
    let activeStatuses = [];
    if (activeStatusFilters.value["Phiếu tạm"]) activeStatuses.push("draft");
    if (activeStatusFilters.value["Hoàn thành"]) activeStatuses.push("completed");
    if (activeStatusFilters.value["Đã hủy"]) activeStatuses.push("cancelled");
    router.get(
        "/damages",
        {
            search: searchQuery.value,
            status: activeStatuses,
            branch_id: branchId.value,
            created_by_name: creatorQuery.value,
            destroyed_by_name: destroyerQuery.value,
            date_filter: activeDateFilter.value,
            sort_by: field,
            sort_direction: direction,
        },
        { preserveState: true, replace: true },
    );
};

const updateFilters = debounce(() => {
    let activeStatuses = [];
    if (activeStatusFilters.value["Phiếu tạm"]) activeStatuses.push("draft");
    if (activeStatusFilters.value["Hoàn thành"]) activeStatuses.push("completed");
    if (activeStatusFilters.value["Đã hủy"]) activeStatuses.push("cancelled");

    router.get(
        "/damages",
        {
            search: searchQuery.value,
            status: activeStatuses,
            branch_id: branchId.value,
            created_by_name: creatorQuery.value,
            destroyed_by_name: destroyerQuery.value,
            date_filter: activeDateFilter.value,
            sort_by: sortBy.value,
            sort_direction: sortDirection.value,
        },
        { preserveState: true, replace: true },
    );
}, 300);

watch(
    [
        searchQuery,
        activeStatusFilters,
        activeDateFilter,
        creatorQuery,
        destroyerQuery,
        branchId,
    ],
    updateFilters,
    { deep: true },
);

const printDamage = (damage) => {
    window.open(
        `/damages/${damage.id}/print`,
        "_blank",
        "width=400,height=600",
    );
};
</script>

<template>
    <AppLayout>
        <Head title="Xuất hủy - KiotViet Clone" />

        <div class="flex h-full bg-[#eef1f5] text-[13px] font-sans">
            <!-- Sidebar Navigation -->
            <div
                class="w-[240px] bg-white border-r border-gray-200 flex-shrink-0 flex flex-col h-full shadow-[1px_0_0_rgba(0,0,0,0.05)] text-gray-800"
            >
                <div
                    class="h-10 flex items-center px-4 font-bold text-[15px] border-b border-gray-200 text-gray-800"
                >
                    Xuất hủy
                </div>

                <div class="flex-1 overflow-y-auto">
                    <!-- Branch Filter -->
                    <div class="px-4 py-4 border-b border-gray-100">
                        <div class="font-bold mb-2 text-gray-800">
                            Chi nhánh
                        </div>
                        <select
                            v-model="branchId"
                            class="w-full border border-gray-300 rounded px-2 py-1.5 text-[13px] outline-none hover:border-blue-400 transition-colors bg-white"
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
                    </div>

                    <!-- Status Filter -->
                    <div class="px-4 py-4 border-b border-gray-100">
                        <div class="font-bold mb-2 text-gray-800">
                            Trạng thái
                        </div>
                        <div class="space-y-2.5">
                            <label
                                v-for="(val, key) in activeStatusFilters"
                                :key="key"
                                class="flex items-center gap-2 cursor-pointer group hover:text-blue-600 transition-colors"
                            >
                                <input
                                    type="checkbox"
                                    v-model="activeStatusFilters[key]"
                                    class="w-4 h-4 text-blue-600 rounded border-gray-300 focus:ring-blue-500"
                                />
                                <span>{{ key }}</span>
                            </label>
                        </div>
                    </div>

                    <!-- Date Filter -->
                    <div class="px-4 py-4 border-b border-gray-100">
                        <div
                            class="font-bold mb-2 flex items-center gap-1 text-gray-800"
                        >
                            Thời gian
                            <svg
                                class="w-2.5 h-2.5 text-blue-500"
                                fill="currentColor"
                                viewBox="0 0 20 20"
                            >
                                <circle cx="10" cy="10" r="10" />
                            </svg>
                        </div>
                        <div class="space-y-2">
                            <label
                                class="flex items-center gap-2 cursor-pointer group hover:text-blue-600 transition-colors"
                            >
                                <input
                                    type="radio"
                                    v-model="activeDateFilter"
                                    value="all"
                                    name="date"
                                    class="w-4 h-4 text-blue-600 focus:ring-blue-500 border-gray-300"
                                />
                                <span>Tất cả thời gian</span>
                            </label>
                            <label
                                class="flex items-center gap-2 cursor-pointer group hover:text-blue-600 transition-colors"
                            >
                                <input
                                    type="radio"
                                    v-model="activeDateFilter"
                                    value="today"
                                    name="date"
                                    class="w-4 h-4 text-blue-600 focus:ring-blue-500 border-gray-300"
                                />
                                <span>Hôm nay</span>
                            </label>
                            <label
                                class="flex items-center gap-2 cursor-pointer group hover:text-blue-600 transition-colors"
                            >
                                <input
                                    type="radio"
                                    v-model="activeDateFilter"
                                    value="this_month"
                                    name="date"
                                    class="w-4 h-4 text-blue-600 focus:ring-blue-500 border-gray-300"
                                />
                                <span>Tháng này</span>
                            </label>
                        </div>
                    </div>

                    <!-- Creator Filter -->
                    <div class="px-4 py-4 border-b border-gray-100">
                        <div class="font-bold mb-2 text-gray-800">
                            Người tạo
                        </div>
                        <input
                            type="text"
                            v-model="creatorQuery"
                            placeholder="Chọn người tạo"
                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition-shadow text-[13px] shadow-sm"
                        />
                    </div>

                    <!-- Destroyer Filter -->
                    <div class="px-4 py-4 border-b border-gray-100">
                        <div class="font-bold mb-2 text-gray-800">
                            Người xuất hủy
                        </div>
                        <input
                            type="text"
                            v-model="destroyerQuery"
                            placeholder="Chọn người xuất hủy"
                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition-shadow text-[13px] shadow-sm"
                        />
                    </div>
                </div>
            </div>

            <!-- Main Content Area -->
            <div
                class="flex-1 flex flex-col min-w-0 overflow-hidden text-gray-800"
            >
                <div
                    class="h-[60px] bg-[#f0f4f9] border-b border-gray-200 flex items-center justify-between px-4 flex-shrink-0 relative z-10 w-full overflow-hidden"
                >
                    <div class="flex items-center gap-2 w-[400px] relative">
                        <div
                            class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
                        >
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
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                                ></path>
                            </svg>
                        </div>
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Theo mã xuất hủy"
                            class="w-full bg-white pl-9 pr-8 border border-gray-300 focus:border-blue-500 hover:border-gray-400 py-1.5 rounded-sm outline-none transition-colors shadow-none text-[13px] block"
                        />
                        <div
                            class="absolute inset-y-0 right-0 pr-3 flex items-center cursor-pointer text-gray-400"
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
                                    d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"
                                ></path>
                            </svg>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <Link
                            href="/damages/create"
                            class="bg-[#4aa136] hover:bg-[#3d872c] text-white px-3 py-1.5 rounded font-medium flex items-center gap-1.5 transition-colors shadow-sm text-[13px]"
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
                            Xuất hủy
                        </Link>
                        <ExcelButtons export-url="/damages/export" />
                    </div>
                </div>

                <div
                    class="flex-1 overflow-auto bg-white border-t-4 border-transparent"
                >
                    <table class="w-full text-left border-collapse">
                        <thead
                            class="bg-white text-gray-800 font-bold sticky top-0 z-10 shadow-[0_1px_0_rgba(200,200,200,0.5)]"
                        >
                            <tr>
                                <th
                                    class="p-3 w-10 text-center border-b border-[#dce3ec]"
                                >
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer"
                                    />
                                </th>
                                <th
                                    class="p-3 w-10 text-center border-b border-[#dce3ec]"
                                >
                                    <svg
                                        class="w-4 h-4 text-gray-400 mx-auto"
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
                                <SortableHeader label="Mã xuất hủy" field="code" :current-sort="sortBy" :current-direction="sortDirection" class="p-3 border-b border-[#dce3ec]" @sort="handleSort" />
                                <SortableHeader label="Tổng giá trị hủy" field="total_value" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="p-3 text-right border-b border-[#dce3ec]" @sort="handleSort" />
                                <SortableHeader label="Thời gian" field="created_at" :current-sort="sortBy" :current-direction="sortDirection" class="p-3 border-b border-[#dce3ec] pl-10" @sort="handleSort" />
                                <th class="p-3 border-b border-[#dce3ec]">
                                    Chi nhánh
                                </th>
                                <th class="p-3 border-b border-[#dce3ec]">
                                    Ghi chú
                                </th>
                                <SortableHeader label="Trạng thái" field="status" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="p-3 w-28 text-right border-b border-[#dce3ec]" @sort="handleSort" />
                            </tr>
                        </thead>
                        <tbody>
                            <template
                                v-for="damage in damages.data"
                                :key="damage.id"
                            >
                                <tr
                                    @click="toggleRow(damage.id)"
                                    class="border-b border-gray-100 hover:bg-[#ebf5ff] cursor-pointer transition-colors"
                                    :class="{
                                        'bg-[#ebf5ff] border-l-2 border-l-blue-500':
                                            expandedRow === damage.id,
                                        'border-l-2 border-l-transparent':
                                            expandedRow !== damage.id,
                                    }"
                                >
                                    <td class="p-3 w-10 text-center">
                                        <input
                                            type="checkbox"
                                            @click.stop
                                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer"
                                        />
                                    </td>
                                    <td class="p-3 w-10 text-center">
                                        <svg
                                            class="w-4 h-4 text-gray-300 mx-auto"
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
                                    <td class="p-3 font-medium text-gray-800">
                                        {{ damage.code }}
                                    </td>
                                    <td class="p-3 text-right">
                                        {{ formatCurrency(damage.total_value) }}
                                    </td>
                                    <td class="p-3 pl-10">
                                        {{ formatDate(damage.created_at) }}
                                    </td>
                                    <td class="p-3">
                                        {{
                                            damage.branch?.name ||
                                            "Chi nhánh mặc định"
                                        }}
                                    </td>
                                    <td class="p-3 truncate max-w-[150px]">
                                        {{ damage.note || "" }}
                                    </td>
                                    <td
                                        class="p-3 text-right font-medium"
                                        :class="
                                            getStatusTextColor(damage.status)
                                        "
                                    >
                                        {{ getStatusLabelText(damage.status) }}
                                    </td>
                                </tr>

                                <tr
                                    v-if="expandedRow === damage.id"
                                    class="border-b border-l-2 border-l-blue-500 border-gray-200 bg-white shadow-inner"
                                >
                                    <td colspan="11" class="p-6">
                                        <div
                                            class="bg-white border border-blue-200 rounded-sm"
                                        >
                                            <!-- Tabs -->
                                            <div
                                                class="flex border-b border-blue-200 bg-blue-50/50"
                                            >
                                                <div
                                                    class="py-2.5 px-4 font-medium text-blue-600 border-b-2 border-blue-600 cursor-pointer text-[13px]"
                                                >
                                                    Thông tin
                                                </div>
                                            </div>

                                            <!-- Details -->
                                            <div
                                                class="p-5 flex flex-col gap-6"
                                            >
                                                <!-- Header Info -->
                                                <div
                                                    class="flex items-center gap-4 border-b border-gray-100 pb-3 justify-between"
                                                >
                                                    <div
                                                        class="flex items-center gap-3"
                                                    >
                                                        <h2
                                                            class="text-[16px] font-bold text-gray-800"
                                                        >
                                                            {{ damage.code }}
                                                        </h2>
                                                        <span
                                                            :class="
                                                                getStatusClass(
                                                                    damage.status,
                                                                )
                                                            "
                                                            class="font-medium inline-block"
                                                            >{{
                                                                getStatusLabelText(
                                                                    damage.status,
                                                                )
                                                            }}</span
                                                        >
                                                    </div>
                                                    <div
                                                        class="font-medium text-gray-500 text-sm"
                                                    >
                                                        {{
                                                            damage.branch
                                                                ?.name ||
                                                            "Chi nhánh mặc định"
                                                        }}
                                                    </div>
                                                </div>

                                                <div
                                                    class="flex w-full items-start gap-12 text-[13px] text-gray-700"
                                                >
                                                    <div
                                                        class="flex flex-col gap-2"
                                                    >
                                                        <div>
                                                            <span
                                                                class="text-gray-400 w-[100px] inline-block"
                                                                >Người
                                                                tạo:</span
                                                            >
                                                            <span
                                                                class="font-medium text-gray-800"
                                                                >{{
                                                                    damage.created_by_name
                                                                }}</span
                                                            >
                                                        </div>
                                                        <div
                                                            class="flex items-center"
                                                        >
                                                            <span
                                                                class="text-gray-400 w-[100px] inline-block"
                                                                >Người xuất
                                                                hủy:</span
                                                            >
                                                            <select
                                                                class="border border-gray-300 rounded px-2 py-1 outline-none"
                                                            >
                                                                <option
                                                                    selected
                                                                >
                                                                    {{
                                                                        damage.destroyed_by_name
                                                                    }}
                                                                </option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div
                                                        class="flex flex-col gap-2"
                                                    >
                                                        <div>
                                                            <span
                                                                class="text-gray-400 w-[110px] inline-block"
                                                                >Ngày tạo:</span
                                                            >
                                                            <span
                                                                class="font-medium"
                                                                >{{
                                                                    formatDate(
                                                                        damage.created_at,
                                                                    )
                                                                }}</span
                                                            >
                                                        </div>
                                                        <div
                                                            class="flex items-center"
                                                        >
                                                            <span
                                                                class="text-gray-400 w-[110px] inline-block"
                                                                >Ngày xuất
                                                                hủy:</span
                                                            >
                                                            <div
                                                                class="flex items-center border border-gray-300 rounded px-2 py-1 gap-2"
                                                            >
                                                                <span>{{
                                                                    formatDate(
                                                                        damage.destroyed_date,
                                                                    ) || "-"
                                                                }}</span>
                                                                <svg
                                                                    class="w-3.5 h-3.5 text-gray-500 pointer-events-none"
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

                                                <!-- Table Details -->
                                                <div
                                                    class="border rounded-sm overflow-hidden mt-1"
                                                >
                                                    <table
                                                        class="w-full text-left"
                                                    >
                                                        <thead
                                                            class="bg-[#f8f9fa] text-gray-800 font-bold border-b border-gray-200"
                                                        >
                                                            <tr>
                                                                <th
                                                                    class="py-2.5 px-3 w-32 border-r border-[#dce3ec] text-center"
                                                                >
                                                                    Mã hàng
                                                                </th>
                                                                <th
                                                                    class="py-2.5 px-3 border-r border-[#dce3ec] text-center"
                                                                >
                                                                    Tên hàng
                                                                </th>
                                                                <th
                                                                    class="py-2.5 px-3 text-right bg-white border-r border-[#dce3ec]"
                                                                >
                                                                    SL hủy
                                                                </th>
                                                                <th
                                                                    class="py-2.5 px-3 text-right bg-white border-r border-[#dce3ec]"
                                                                >
                                                                    Giá vốn
                                                                </th>
                                                                <th
                                                                    class="py-2.5 px-3 text-right bg-[#f8f9fa]"
                                                                >
                                                                    Giá trị hủy
                                                                </th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <!-- Actual items -->
                                                            <tr
                                                                v-for="item in damage.items"
                                                                :key="item.id"
                                                                class="border-b border-gray-100 hover:bg-gray-50"
                                                            >
                                                                <td
                                                                    class="p-3 text-blue-600 font-medium"
                                                                >
                                                                    {{
                                                                        item
                                                                            .product
                                                                            ?.sku ||
                                                                        "SP_XXX"
                                                                    }}
                                                                </td>
                                                                <td class="p-3">
                                                                    {{
                                                                        item
                                                                            .product
                                                                            ?.name ||
                                                                        "Tên sản phẩm"
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="p-3 text-right"
                                                                >
                                                                    {{
                                                                        item.qty
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="p-3 text-right"
                                                                >
                                                                    {{
                                                                        formatCurrency(
                                                                            item.cost_price,
                                                                        )
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="p-3 text-right font-medium"
                                                                >
                                                                    {{
                                                                        formatCurrency(
                                                                            item.total_value,
                                                                        )
                                                                    }}
                                                                </td>
                                                            </tr>
                                                            <!-- Sample row showing the inputs when empty? Not needed for viewing details generally, but added to mimic SS -->
                                                            <tr
                                                                v-if="
                                                                    !damage.items ||
                                                                    damage.items
                                                                        .length ===
                                                                        0
                                                                "
                                                                class="border-b border-gray-100 bg-gray-50/50"
                                                            >
                                                                <td class="p-2">
                                                                    <input
                                                                        type="text"
                                                                        placeholder="Tìm mã hàng"
                                                                        class="border border-gray-300 rounded px-2 py-1.5 w-full text-[13px] outline-none shadow-inner bg-white"
                                                                    />
                                                                </td>
                                                                <td class="p-2">
                                                                    <input
                                                                        type="text"
                                                                        placeholder="Tìm tên hàng"
                                                                        class="border border-gray-300 rounded px-2 py-1.5 w-[250px] text-[13px] outline-none shadow-inner bg-white"
                                                                    />
                                                                </td>
                                                                <td
                                                                    class="p-2"
                                                                ></td>
                                                                <td
                                                                    class="p-2"
                                                                ></td>
                                                                <td
                                                                    class="p-2"
                                                                ></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>

                                                <!-- Footer Info -->
                                                <div class="flex pb-4">
                                                    <div class="w-2/3 pr-10">
                                                        <textarea
                                                            class="w-full border border-blue-200 rounded-sm p-3 h-32 focus:outline-none focus:border-blue-500 text-[13px] shadow-inner text-gray-500 font-medium"
                                                            placeholder="Chưa có"
                                                            >{{
                                                                damage.note ||
                                                                ""
                                                            }}</textarea
                                                        >
                                                    </div>
                                                    <div
                                                        class="w-1/3 flex flex-col items-end pt-2 text-[13px] space-y-3 p-4"
                                                    >
                                                        <div
                                                            class="flex justify-end w-full border-b border-gray-100 pb-2"
                                                        >
                                                            <span
                                                                class="w-[150px] text-right text-gray-500 font-medium"
                                                                >Tổng số lượng
                                                                hủy:</span
                                                            >
                                                            <span
                                                                class="w-20 text-right font-medium text-[14px]"
                                                                >{{
                                                                    damage.total_qty
                                                                }}</span
                                                            >
                                                        </div>
                                                        <div
                                                            class="flex justify-end w-full font-bold text-[14px]"
                                                        >
                                                            <span
                                                                class="w-[150px] text-right text-gray-700"
                                                                >Tổng giá trị
                                                                hủy:</span
                                                            >
                                                            <span
                                                                class="w-20 text-right"
                                                                >{{
                                                                    formatCurrency(
                                                                        damage.total_value,
                                                                    )
                                                                }}</span
                                                            >
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Action Buttons -->
                                            <div
                                                class="p-4 flex justify-between items-center rounded-b"
                                            >
                                                <div class="flex gap-2">
                                                    <button
                                                        class="bg-white border border-gray-300 px-4 py-1.5 rounded text-gray-700 font-medium hover:bg-gray-50 flex items-center gap-2 shadow-sm"
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
                                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                                            ></path>
                                                        </svg>
                                                        Hủy
                                                    </button>
                                                    <button
                                                        class="bg-white border border-gray-300 px-4 py-1.5 rounded text-gray-700 font-medium hover:bg-gray-50 flex items-center gap-2 shadow-sm"
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
                                                                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"
                                                            ></path>
                                                        </svg>
                                                        Sao chép
                                                    </button>
                                                    <button
                                                        class="bg-white border border-gray-300 px-4 py-1.5 rounded text-gray-700 font-medium hover:bg-gray-50 flex items-center gap-2 shadow-sm"
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
                                                    </button>
                                                </div>
                                                <div class="flex gap-2">
                                                    <button
                                                        class="bg-white border border-gray-300 px-4 py-1.5 rounded text-gray-700 font-medium hover:bg-gray-50 flex items-center gap-2 shadow-sm"
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
                                                                d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"
                                                            ></path>
                                                        </svg>
                                                        Lưu
                                                    </button>
                                                    <button
                                                        @click.stop="
                                                            printDamage(damage)
                                                        "
                                                        class="bg-white border border-gray-300 px-4 py-1.5 rounded text-gray-700 font-medium hover:bg-gray-50 flex items-center gap-2 shadow-sm"
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
                                                                d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"
                                                            ></path>
                                                        </svg>
                                                        In
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>

                            <tr v-if="damages?.data?.length === 0">
                                <td
                                    colspan="11"
                                    class="text-center p-8 text-gray-500 bg-white"
                                >
                                    Không có dữ liệu phiếu xuất hủy.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer Pagination -->
                <div
                    class="flex items-center justify-between p-3 border-t border-gray-200 bg-gray-50/50 text-sm flex-shrink-0"
                >
                    <div class="text-gray-600">
                        Hiển thị từ
                        <span class="font-bold">{{ damages.from || 0 }}</span>
                        đến
                        <span class="font-bold">{{ damages.to || 0 }}</span>
                        trong tổng số
                        <span class="font-bold">{{ damages.total || 0 }}</span>
                        phiếu
                    </div>
                    <!-- Pagination -->
                    <div
                        class="flex gap-1"
                        v-if="damages.links && damages.links.length > 3"
                    >
                        <template
                            v-for="(link, index) in damages.links"
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
        </div>
    </AppLayout>
</template>
