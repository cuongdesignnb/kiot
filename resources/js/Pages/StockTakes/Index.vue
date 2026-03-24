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
    stockTakes: Object,
    branches: Array,
    filters: Object,
});

const searchQuery = ref(props.filters.search || "");
const expandedRow = ref(null);
const selectedFilter = ref("all"); // all, draft, balanced, cancelled
const activeDateFilter = ref(props.filters.date_filter || "all");
const creatorQuery = ref(props.filters.user_name || "");
const sortBy = ref(props.filters.sort_by || "");
const sortDirection = ref(props.filters.sort_direction || "");

const activeStatusFilters = ref({
    "Phiếu tạm": props.filters.status?.includes("draft") ?? true,
    "Đã cân bằng kho": props.filters.status?.includes("balanced") ?? true,
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
    if (status === "balanced")
        return "bg-green-100 text-green-700 pointer-events-none px-2 py-0.5 rounded text-[12px]";
    if (status === "draft")
        return "bg-orange-100 text-orange-700 pointer-events-none px-2 py-0.5 rounded text-[12px]";
    return "bg-gray-100 text-gray-700 pointer-events-none px-2 py-0.5 rounded text-[12px]";
};

const getStatusLabelText = (status) => {
    if (status === "balanced") return "Đã cân bằng kho";
    if (status === "draft") return "Phiếu tạm";
    if (status === "cancelled") return "Đã hủy";
    return "Chưa rõ";
};

const handleSort = (field, direction) => {
    sortBy.value = field;
    sortDirection.value = direction;
    let activeStatuses = [];
    if (activeStatusFilters.value["Phiếu tạm"]) activeStatuses.push("draft");
    if (activeStatusFilters.value["Đã cân bằng kho"]) activeStatuses.push("balanced");
    if (activeStatusFilters.value["Đã hủy"]) activeStatuses.push("cancelled");
    router.get(
        "/stock-takes",
        {
            search: searchQuery.value,
            status: activeStatuses,
            date_filter: activeDateFilter.value,
            user_name: creatorQuery.value,
            sort_by: field,
            sort_direction: direction,
        },
        { preserveState: true, replace: true },
    );
};

const updateFilters = debounce(() => {
    let activeStatuses = [];
    if (activeStatusFilters.value["Phiếu tạm"]) activeStatuses.push("draft");
    if (activeStatusFilters.value["Đã cân bằng kho"]) activeStatuses.push("balanced");
    if (activeStatusFilters.value["Đã hủy"]) activeStatuses.push("cancelled");

    router.get(
        "/stock-takes",
        {
            search: searchQuery.value,
            status: activeStatuses,
            date_filter: activeDateFilter.value,
            user_name: creatorQuery.value,
            sort_by: sortBy.value,
            sort_direction: sortDirection.value,
        },
        { preserveState: true, replace: true },
    );
}, 300);

watch(
    [searchQuery, activeStatusFilters, activeDateFilter, creatorQuery],
    updateFilters,
    { deep: true },
);

const printStockTake = (stockTake) => {
    window.open(
        `/stock-takes/${stockTake.id}/print`,
        "_blank",
        "width=400,height=600",
    );
};
</script>

<template>
    <AppLayout>
        <Head title="Phiếu kiểm kho - KiotViet Clone" />

        <div class="flex h-full bg-[#eef1f5] text-[13px] font-sans">
            <!-- Sidebar Navigation -->
            <div
                class="w-[240px] bg-white border-r border-gray-200 flex-shrink-0 flex flex-col h-full shadow-[1px_0_0_rgba(0,0,0,0.05)] text-gray-800"
            >
                <div
                    class="h-10 flex items-center px-4 font-bold text-[15px] border-b border-gray-200 uppercase text-gray-700"
                >
                    Phiếu kiểm kho
                </div>

                <div class="flex-1 overflow-y-auto">
                    <!-- Date Filter -->
                    <div class="px-4 py-4 border-b border-gray-100">
                        <div
                            class="font-bold mb-2 flex items-center gap-1 text-gray-800"
                        >
                            Ngày tạo
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

                    <!-- Branch Filter -->
                    <div class="px-4 py-4 border-b border-gray-100">
                        <div class="font-bold mb-2 text-gray-800">
                            Chi nhánh
                        </div>
                        <select
                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition-shadow text-[13px] shadow-sm"
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
                </div>
            </div>

            <!-- Main Content Area -->
            <div
                class="flex-1 flex flex-col min-w-0 overflow-hidden text-gray-800"
            >
                <div
                    class="h-[60px] bg-white border-b border-gray-200 flex items-center justify-between px-4 flex-shrink-0 shadow-sm relative z-10 w-full overflow-hidden"
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
                            placeholder="Theo mã phiếu kiểm"
                            class="w-full pl-9 pr-8 border-b-2 border-transparent focus:border-blue-500 bg-transparent py-2.5 outline-none transition-colors shadow-none text-[13px] block"
                        />
                        <div
                            class="absolute inset-y-0 right-0 pr-3 flex items-center cursor-pointer text-gray-400 hover:text-gray-600"
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
                            href="/stock-takes/create"
                            class="bg-[#4aa136] hover:bg-[#3d872c] text-white px-3 py-1.5 rounded font-medium flex items-center gap-1.5 transition-colors shadow-sm"
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
                            Kiểm kho
                        </Link>
                        <ExcelButtons export-url="/stock-takes/export" />
                    </div>
                </div>

                <div
                    class="flex-1 overflow-auto bg-white border-t-4 border-transparent"
                >
                    <table class="w-full text-left border-collapse">
                        <thead
                            class="bg-[#f0f4f9] text-gray-700 font-bold sticky top-0 z-10 shadow-[0_1px_0_rgba(200,200,200,0.5)]"
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
                                <SortableHeader label="Mã kiểm kho" field="code" :current-sort="sortBy" :current-direction="sortDirection" class="p-3 border-b border-[#dce3ec]" @sort="handleSort" />
                                <SortableHeader label="Thời gian" field="created_at" :current-sort="sortBy" :current-direction="sortDirection" class="p-3 border-b border-[#dce3ec]" @sort="handleSort" />
                                <th class="p-3 border-b border-[#dce3ec]">
                                    Ngày cân bằng
                                </th>
                                <th
                                    class="p-3 text-right border-b border-[#dce3ec]"
                                >
                                    SL thực tế
                                </th>
                                <th
                                    class="p-3 text-right border-b border-[#dce3ec]"
                                >
                                    Tổng thực tế
                                </th>
                                <th
                                    class="p-3 text-right border-b border-[#dce3ec]"
                                >
                                    Tổng chênh lệch
                                </th>
                                <th
                                    class="p-3 text-right border-b border-[#dce3ec]"
                                >
                                    SL lệch tăng
                                </th>
                                <th
                                    class="p-3 text-right border-b border-[#dce3ec]"
                                >
                                    SL lệch giảm
                                </th>
                                <th class="p-3 border-b border-[#dce3ec]">
                                    Ghi chú
                                </th>
                                <SortableHeader label="Trạng thái" field="status" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="p-3 w-24 text-right border-b border-[#dce3ec]" @sort="handleSort" />
                            </tr>
                        </thead>
                        <tbody>
                            <template
                                v-for="stockTake in stockTakes.data"
                                :key="stockTake.id"
                            >
                                <tr
                                    @click="toggleRow(stockTake.id)"
                                    class="border-b border-gray-100 hover:bg-[#ebf5ff] cursor-pointer transition-colors"
                                    :class="{
                                        'bg-[#ebf5ff] border-l-2 border-l-blue-500':
                                            expandedRow === stockTake.id,
                                        'border-l-2 border-l-transparent':
                                            expandedRow !== stockTake.id,
                                    }"
                                >
                                    <td class="p-3 w-10 text-center">
                                        <input
                                            type="checkbox"
                                            @click.stop
                                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer"
                                        />
                                    </td>
                                    <td class="p-3 font-medium text-blue-600">
                                        {{ stockTake.code }}
                                    </td>
                                    <td class="p-3">
                                        {{ formatDate(stockTake.created_at) }}
                                    </td>
                                    <td class="p-3">
                                        {{
                                            formatDate(stockTake.balanced_date)
                                        }}
                                    </td>
                                    <td class="p-3 text-right">{{ 1 }}</td>
                                    <td class="p-3 text-right">
                                        {{ stockTake.total_actual_qty }}
                                    </td>
                                    <td
                                        class="p-3 text-right font-medium"
                                        :class="{
                                            'text-red-500':
                                                stockTake.total_diff_qty < 0,
                                            'text-green-500':
                                                stockTake.total_diff_qty > 0,
                                        }"
                                    >
                                        {{ stockTake.total_diff_qty }}
                                    </td>
                                    <td class="p-3 text-right text-green-500">
                                        {{ stockTake.total_diff_increase }}
                                    </td>
                                    <td
                                        class="p-3 text-right text-red-500 w-[120px]"
                                    >
                                        {{ stockTake.total_diff_decrease }}
                                    </td>
                                    <td class="p-3 truncate max-w-[150px]">
                                        {{
                                            stockTake.note ||
                                            "Phiếu kiểm kho được tạo tự động..."
                                        }}
                                    </td>
                                    <td class="p-3 text-right">
                                        <span
                                            :class="
                                                getStatusClass(stockTake.status)
                                            "
                                            >{{
                                                getStatusLabelText(
                                                    stockTake.status,
                                                )
                                            }}</span
                                        >
                                    </td>
                                </tr>

                                <tr
                                    v-if="expandedRow === stockTake.id"
                                    class="border-b border-l-2 border-l-blue-500 border-gray-200 bg-white shadow-inner"
                                >
                                    <td colspan="11" class="p-6">
                                        <div class="bg-white border rounded">
                                            <!-- Tabs -->
                                            <div
                                                class="flex border-b border-gray-200 px-4"
                                            >
                                                <div
                                                    class="py-3 px-4 font-medium text-blue-600 border-b-2 border-blue-600 cursor-pointer text-[14px]"
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
                                                    class="flex items-center gap-4"
                                                >
                                                    <h2
                                                        class="text-[18px] font-bold text-gray-800"
                                                    >
                                                        {{ stockTake.code }}
                                                    </h2>
                                                    <span
                                                        :class="
                                                            getStatusClass(
                                                                stockTake.status,
                                                            )
                                                        "
                                                        class="font-medium inline-block"
                                                        >{{
                                                            getStatusLabelText(
                                                                stockTake.status,
                                                            )
                                                        }}</span
                                                    >
                                                </div>

                                                <div
                                                    class="grid grid-cols-2 text-[13px] text-gray-700 gap-y-2 max-w-2xl"
                                                >
                                                    <div>
                                                        <span
                                                            class="text-gray-500 w-[120px] inline-block"
                                                            >Người tạo:</span
                                                        >
                                                        <span
                                                            class="font-medium"
                                                            >{{
                                                                stockTake.user_name
                                                            }}</span
                                                        >
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500 w-[120px] inline-block"
                                                            >Ngày tạo:</span
                                                        >
                                                        <span
                                                            class="font-medium"
                                                            >{{
                                                                formatDate(
                                                                    stockTake.created_at,
                                                                )
                                                            }}</span
                                                        >
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500 w-[120px] inline-block"
                                                            >Người cân
                                                            bằng:</span
                                                        >
                                                        <span
                                                            class="font-medium"
                                                            >{{
                                                                stockTake.balancer_name ||
                                                                "-"
                                                            }}</span
                                                        >
                                                    </div>
                                                    <div>
                                                        <span
                                                            class="text-gray-500 w-[120px] inline-block"
                                                            >Ngày cân
                                                            bằng:</span
                                                        >
                                                        <span
                                                            class="font-medium"
                                                            >{{
                                                                formatDate(
                                                                    stockTake.balanced_date,
                                                                ) || "-"
                                                            }}</span
                                                        >
                                                    </div>
                                                </div>

                                                <!-- Table Details -->
                                                <div
                                                    class="border rounded-sm overflow-hidden"
                                                >
                                                    <table
                                                        class="w-full text-left"
                                                    >
                                                        <thead
                                                            class="bg-[#f4f6f8] text-gray-600 font-bold border-b border-gray-200"
                                                        >
                                                            <tr>
                                                                <th
                                                                    class="py-2.5 px-3"
                                                                >
                                                                    Mã hàng
                                                                </th>
                                                                <th
                                                                    class="py-2.5 px-3"
                                                                >
                                                                    Tên hàng
                                                                </th>
                                                                <th
                                                                    class="py-2.5 px-3 text-right"
                                                                >
                                                                    Tồn kho
                                                                </th>
                                                                <th
                                                                    class="py-2.5 px-3 text-right"
                                                                >
                                                                    Thực tế
                                                                </th>
                                                                <th
                                                                    class="py-2.5 px-3 text-right"
                                                                >
                                                                    SL lệch
                                                                </th>
                                                                <th
                                                                    class="py-2.5 px-3 text-right"
                                                                >
                                                                    Giá trị lệch
                                                                </th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <!-- Sample row showing the inputs -->
                                                            <tr
                                                                class="border-b border-gray-100 bg-gray-50/50"
                                                            >
                                                                <td class="p-2">
                                                                    <input
                                                                        type="text"
                                                                        placeholder="Tìm mã hàng"
                                                                        class="border rounded px-2 py-1.5 w-full text-[13px] outline-none"
                                                                    />
                                                                </td>
                                                                <td class="p-2">
                                                                    <input
                                                                        type="text"
                                                                        placeholder="Tìm tên hàng"
                                                                        class="border rounded px-2 py-1.5 w-[200px] text-[13px] outline-none"
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
                                                                <td
                                                                    class="p-2"
                                                                ></td>
                                                            </tr>
                                                            <!-- Actual items -->
                                                            <tr
                                                                v-for="item in stockTake.items"
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
                                                                        item.system_stock
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="p-3 text-right"
                                                                >
                                                                    {{
                                                                        item.actual_stock
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="p-3 text-right font-medium"
                                                                    :class="{
                                                                        'text-red-500':
                                                                            item.diff_qty <
                                                                            0,
                                                                        'text-green-500':
                                                                            item.diff_qty >
                                                                            0,
                                                                    }"
                                                                >
                                                                    {{
                                                                        item.diff_qty
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="p-3 text-right"
                                                                >
                                                                    {{
                                                                        formatCurrency(
                                                                            item.diff_value,
                                                                        )
                                                                    }}
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>

                                                <!-- Footer Info -->
                                                <div class="flex pb-4">
                                                    <div class="w-1/2 pr-4">
                                                        <textarea
                                                            class="w-full border rounded p-3 h-32 focus:outline-none focus:border-blue-500 text-[13px]"
                                                            placeholder="Ghi chú..."
                                                            >{{
                                                                stockTake.note ||
                                                                "Phiếu kiểm kho được tạo tự động khi cập nhật Hàng hóa"
                                                            }}</textarea
                                                        >
                                                    </div>
                                                    <div
                                                        class="w-1/2 flex flex-col items-end pt-2 text-[13px] space-y-1.5 p-4 border border-transparent"
                                                    >
                                                        <div
                                                            class="flex justify-end w-full max-w-[300px]"
                                                        >
                                                            <span
                                                                class="w-[150px] text-right text-gray-500"
                                                                >Tổng thực tế
                                                                ({{
                                                                    stockTake
                                                                        .items
                                                                        ?.length ||
                                                                    0
                                                                }}):</span
                                                            >
                                                            <span
                                                                class="w-20 text-right font-medium"
                                                                >{{
                                                                    stockTake.total_actual_qty
                                                                }}</span
                                                            >
                                                        </div>
                                                        <div
                                                            class="flex justify-end w-full max-w-[300px]"
                                                        >
                                                            <span
                                                                class="w-[150px] text-right text-gray-500"
                                                                >Tổng lệch tăng
                                                                (0):</span
                                                            >
                                                            <span
                                                                class="w-20 text-right text-green-600 font-medium"
                                                                >{{
                                                                    stockTake.total_diff_increase
                                                                }}</span
                                                            >
                                                        </div>
                                                        <div
                                                            class="flex justify-end w-full max-w-[300px]"
                                                        >
                                                            <span
                                                                class="w-[150px] text-right text-gray-500"
                                                                >Tổng lệch giảm
                                                                ({{
                                                                    (
                                                                        stockTake.items?.filter(
                                                                            (
                                                                                i,
                                                                            ) =>
                                                                                i.diff_qty <
                                                                                0,
                                                                        ) || []
                                                                    ).length
                                                                }}):</span
                                                            >
                                                            <span
                                                                class="w-20 text-right text-red-500 font-medium"
                                                                >{{
                                                                    formatCurrency(
                                                                        stockTake.total_diff_value,
                                                                    )
                                                                }}</span
                                                            >
                                                        </div>
                                                        <div
                                                            class="flex justify-end w-full max-w-[300px]"
                                                        >
                                                            <span
                                                                class="w-[150px] text-right text-gray-500"
                                                                >Tổng chênh lệch
                                                                ({{
                                                                    (
                                                                        stockTake.items?.filter(
                                                                            (
                                                                                i,
                                                                            ) =>
                                                                                i.diff_qty <
                                                                                0,
                                                                        ) || []
                                                                    ).length
                                                                }}):</span
                                                            >
                                                            <span
                                                                class="w-20 text-right font-bold"
                                                                >{{
                                                                    formatCurrency(
                                                                        stockTake.total_diff_value,
                                                                    )
                                                                }}</span
                                                            >
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Action Buttons -->
                                            <div
                                                class="bg-gray-50 p-4 border-t flex justify-between items-center rounded-b"
                                            >
                                                <div class="flex gap-3">
                                                    <button
                                                        class="bg-white border border-gray-300 px-4 py-1.5 rounded text-red-500 font-medium hover:bg-gray-50 flex items-center gap-2"
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
                                                        class="bg-white border border-gray-300 px-4 py-1.5 rounded text-gray-700 font-medium hover:bg-gray-50 flex items-center gap-2"
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
                                                        class="bg-white border border-gray-300 px-4 py-1.5 rounded text-gray-700 font-medium hover:bg-gray-50 flex items-center gap-2"
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
                                                <div class="flex gap-3">
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
                                                            printStockTake(
                                                                stockTake,
                                                            )
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
                                                    <button
                                                        v-if="
                                                            stockTake.status ===
                                                            'draft'
                                                        "
                                                        class="bg-[#4aa136] hover:bg-[#3d872c] text-white px-5 py-1.5 rounded font-medium flex items-center gap-2 shadow-sm"
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
                                                                d="M5 13l4 4L19 7"
                                                            ></path>
                                                        </svg>
                                                        Cân bằng kho
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>

                            <tr v-if="stockTakes?.data?.length === 0">
                                <td
                                    colspan="11"
                                    class="text-center p-8 text-gray-500 bg-white"
                                >
                                    Không có dữ liệu phiếu kiểm kho.
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
                        <span class="font-bold">{{
                            stockTakes.from || 0
                        }}</span>
                        đến
                        <span class="font-bold">{{ stockTakes.to || 0 }}</span>
                        trong tổng số
                        <span class="font-bold">{{
                            stockTakes.total || 0
                        }}</span>
                        phiếu
                    </div>
                    <!-- Pagination -->
                    <div
                        class="flex gap-1"
                        v-if="stockTakes.links && stockTakes.links.length > 3"
                    >
                        <template
                            v-for="(link, index) in stockTakes.links"
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
