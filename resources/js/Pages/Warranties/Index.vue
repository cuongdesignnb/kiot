<script setup>
import { ref, watch } from "vue";
import { Head, router, Link } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import SortableHeader from "@/Components/SortableHeader.vue";
import axios from "axios";

const props = defineProps({
    warranties: Object,
    filters: Object,
});

const search = ref(props.filters.search || "");
const timeFilter = ref(props.filters.time_filter || "all");
const timeStart = ref(props.filters.time_start || "");
const timeEnd = ref(props.filters.time_end || "");

const statusFilter = ref(props.filters.status || "all");

const expirationFilter = ref(props.filters.expiration_filter || "all");
const expirationStart = ref(props.filters.expiration_start || "");
const expirationEnd = ref(props.filters.expiration_end || "");

const maintenanceFilter = ref(props.filters.maintenance_filter || "all");
const maintenanceStart = ref(props.filters.maintenance_start || "");
const maintenanceEnd = ref(props.filters.maintenance_end || "");
const sortBy = ref(props.filters.sort_by || "");
const sortDirection = ref(props.filters.sort_direction || "");

const handleSort = (field, direction) => {
    sortBy.value = field;
    sortDirection.value = direction;
    router.get(
        "/warranties",
        {
            search: search.value,
            time_filter: timeFilter.value,
            time_start: timeStart.value,
            time_end: timeEnd.value,
            status: statusFilter.value,
            expiration_filter: expirationFilter.value,
            expiration_start: expirationStart.value,
            expiration_end: expirationEnd.value,
            maintenance_filter: maintenanceFilter.value,
            maintenance_start: maintenanceStart.value,
            maintenance_end: maintenanceEnd.value,
            sort_by: field,
            sort_direction: direction,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

let fetchTimeout = null;
const fetchFiltered = () => {
    if (fetchTimeout) clearTimeout(fetchTimeout);
    fetchTimeout = setTimeout(() => {
        router.get(
            "/warranties",
            {
                search: search.value,
                time_filter: timeFilter.value,
                time_start: timeStart.value,
                time_end: timeEnd.value,
                status: statusFilter.value,
                expiration_filter: expirationFilter.value,
                expiration_start: expirationStart.value,
                expiration_end: expirationEnd.value,
                maintenance_filter: maintenanceFilter.value,
                maintenance_start: maintenanceStart.value,
                maintenance_end: maintenanceEnd.value,
                sort_by: sortBy.value,
                sort_direction: sortDirection.value,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 500);
};

watch(
    [
        search,
        timeFilter,
        timeStart,
        timeEnd,
        statusFilter,
        expirationFilter,
        expirationStart,
        expirationEnd,
        maintenanceFilter,
        maintenanceStart,
        maintenanceEnd,
    ],
    fetchFiltered,
);

const expandedRow = ref(null);
const currentTab = ref("warranty"); // 'warranty' or 'maintenance'

const toggleRow = (id) => {
    if (expandedRow.value === id) {
        expandedRow.value = null;
    } else {
        expandedRow.value = id;
        currentTab.value = "warranty";
    }
};

const formatDate = (date) => {
    if (!date) return "";
    const d = new Date(date);
    return d.toLocaleDateString("vi-VN");
};

const formatDateTime = (date) => {
    if (!date) return "";
    const d = new Date(date);
    return (
        d.toLocaleDateString("vi-VN") +
        " " +
        d.toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" })
    );
};

const updateWarranty = async (warrantyId, data) => {
    try {
        await axios.put(`/warranties/${warrantyId}`, data);
        router.reload({ preserveScroll: true });
    } catch (e) {
        alert("Có lỗi xảy ra khi cập nhật!");
    }
};

const printWarranty = (item) => {
    window.open(
        `/warranties/${item.id}/print`,
        "_blank",
        "width=400,height=600",
    );
};
</script>

<template>
    <Head title="Bảo hành, bảo trì - KiotViet Clone" />
    <AppLayout>
        <div class="h-full flex gap-4">
            <!-- Sidebar Navigation & Filters -->
            <div
                class="w-[240px] flex-shrink-0 bg-white shadow-sm border border-gray-200 rounded-sm"
            >
                <!-- Title Header -->
                <div class="p-3 border-b border-gray-200 bg-gray-50/50">
                    <h2 class="font-bold text-[14px] text-gray-800">
                        Bảo hành, bảo trì
                    </h2>
                </div>

                <!-- Filters -->
                <div class="p-3 flex flex-col gap-5">
                    <!-- Chi nhánh -->
                    <div>
                        <label
                            class="block text-[13px] font-bold text-gray-800 mb-1.5"
                            >Chi nhánh</label
                        >
                        <div
                            class="bg-blue-600 text-white text-[12px] px-2.5 py-1 rounded flex justify-between items-center w-max cursor-pointer"
                        >
                            <span class="mr-2">Laptopplus.vn</span>
                            <svg
                                class="w-3 h-3 hover:text-gray-200"
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
                        </div>
                        <input
                            type="text"
                            class="hidden w-full border border-gray-300 rounded p-1.5 text-[13px] focus:ring-1 focus:ring-blue-500 focus:border-blue-500 text-gray-700 outline-none hover:border-blue-400 mt-1"
                        />
                    </div>

                    <!-- Thời gian mua hàng -->
                    <div>
                        <label
                            class="block text-[13px] font-bold text-gray-800 mb-1.5 flex items-center gap-1"
                        >
                            Thời gian mua hàng
                            <div class="w-1 h-1 bg-blue-500 rounded-full"></div>
                        </label>
                        <div class="flex flex-col gap-1.5 text-[13px]">
                            <label
                                class="flex items-center gap-2 cursor-pointer p-1.5 hover:bg-gray-50 rounded border border-transparent"
                                :class="{
                                    'border-blue-200 bg-blue-50/30':
                                        timeFilter === 'all',
                                }"
                            >
                                <input
                                    type="radio"
                                    value="all"
                                    v-model="timeFilter"
                                    class="text-blue-600 focus:ring-blue-500"
                                />
                                <span>Toàn thời gian</span>
                            </label>
                            <label
                                class="flex items-center gap-2 justify-between cursor-pointer p-1.5 hover:bg-gray-50 rounded border border-transparent"
                                :class="{
                                    'border-blue-200 bg-blue-50/30':
                                        timeFilter === 'this_month',
                                }"
                            >
                                <div class="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        value="this_month"
                                        v-model="timeFilter"
                                        class="text-blue-600 focus:ring-blue-500"
                                    />
                                    <span>Tháng này</span>
                                </div>
                                <svg
                                    class="w-3.5 h-3.5 text-gray-400"
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
                                class="flex items-center gap-2 justify-between cursor-pointer p-1.5 border border-gray-300 rounded hover:border-blue-400"
                                :class="{
                                    'border-blue-400': timeFilter === 'custom',
                                }"
                            >
                                <div class="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        value="custom"
                                        v-model="timeFilter"
                                        class="text-blue-600 focus:ring-blue-500"
                                    />
                                    <span
                                        class="text-gray-500"
                                        :class="{
                                            'text-blue-600 font-medium':
                                                timeFilter === 'custom',
                                        }"
                                        >Tùy chỉnh</span
                                    >
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
                            <div
                                v-if="timeFilter === 'custom'"
                                class="flex gap-2"
                            >
                                <input
                                    type="date"
                                    v-model="timeStart"
                                    class="w-full border border-gray-300 rounded p-1 text-[12px] text-gray-600 outline-none focus:border-blue-500"
                                />
                                <input
                                    type="date"
                                    v-model="timeEnd"
                                    class="w-full border border-gray-300 rounded p-1 text-[12px] text-gray-600 outline-none focus:border-blue-500"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Trạng thái bảo hành -->
                    <div>
                        <label
                            class="block text-[13px] font-bold text-gray-800 mb-1.5"
                            >Trạng thái bảo hành</label
                        >
                        <select
                            v-model="statusFilter"
                            class="w-full border border-gray-300 rounded p-1.5 text-[13px] focus:ring-1 focus:ring-blue-500 focus:border-blue-500 text-gray-700 outline-none hover:border-blue-400"
                        >
                            <option value="all">Tất cả</option>
                            <option value="valid">Còn hạn</option>
                            <option value="expired">Hết hạn</option>
                        </select>
                    </div>

                    <!-- Hết hạn bảo hành -->
                    <div>
                        <label
                            class="block text-[13px] font-bold text-gray-800 mb-1.5"
                            >Hết hạn bảo hành</label
                        >
                        <div class="flex flex-col gap-1.5 text-[13px]">
                            <label
                                class="flex items-center gap-2 justify-between cursor-pointer p-1.5 hover:bg-gray-50 rounded border border-transparent"
                                :class="{
                                    'border-blue-200 bg-blue-50/30':
                                        expirationFilter === 'all',
                                }"
                            >
                                <div class="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        value="all"
                                        v-model="expirationFilter"
                                        class="text-blue-600 focus:ring-blue-500"
                                    />
                                    <span class="font-bold text-blue-600"
                                        >Toàn thời gian</span
                                    >
                                </div>
                                <svg
                                    class="w-3.5 h-3.5 text-blue-500"
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
                                class="flex items-center gap-2 justify-between cursor-pointer p-1.5 border border-gray-300 rounded hover:border-blue-400"
                                :class="{
                                    'border-blue-400':
                                        expirationFilter === 'custom',
                                }"
                            >
                                <div class="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        value="custom"
                                        v-model="expirationFilter"
                                        class="text-blue-600 focus:ring-blue-500"
                                    />
                                    <span
                                        class="text-gray-500"
                                        :class="{
                                            'text-blue-600 font-medium':
                                                expirationFilter === 'custom',
                                        }"
                                        >Tùy chỉnh</span
                                    >
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
                            <div
                                v-if="expirationFilter === 'custom'"
                                class="flex gap-2"
                            >
                                <input
                                    type="date"
                                    v-model="expirationStart"
                                    class="w-full border border-gray-300 rounded p-1 text-[12px] text-gray-600 outline-none focus:border-blue-500"
                                />
                                <input
                                    type="date"
                                    v-model="expirationEnd"
                                    class="w-full border border-gray-300 rounded p-1 text-[12px] text-gray-600 outline-none focus:border-blue-500"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Lịch bảo trì -->
                    <div>
                        <label
                            class="block text-[13px] font-bold text-gray-800 mb-1.5"
                            >Lịch bảo trì</label
                        >
                        <div class="flex flex-col gap-1.5 text-[13px]">
                            <label
                                class="flex items-center gap-2 justify-between cursor-pointer p-1.5 hover:bg-gray-50 rounded border border-transparent"
                                :class="{
                                    'border-blue-200 bg-blue-50/30':
                                        maintenanceFilter === 'all',
                                }"
                            >
                                <div class="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        value="all"
                                        v-model="maintenanceFilter"
                                        class="text-blue-600 focus:ring-blue-500"
                                    />
                                    <span class="font-bold text-blue-600"
                                        >Toàn thời gian</span
                                    >
                                </div>
                            </label>
                            <label
                                class="flex items-center gap-2 justify-between cursor-pointer p-1.5 border border-gray-300 rounded hover:border-blue-400"
                                :class="{
                                    'border-blue-400':
                                        maintenanceFilter === 'custom',
                                }"
                            >
                                <div class="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        value="custom"
                                        v-model="maintenanceFilter"
                                        class="text-blue-600 focus:ring-blue-500"
                                    />
                                    <span
                                        class="text-gray-500"
                                        :class="{
                                            'text-blue-600 font-medium':
                                                maintenanceFilter === 'custom',
                                        }"
                                        >Tùy chỉnh</span
                                    >
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
                            <div
                                v-if="maintenanceFilter === 'custom'"
                                class="flex gap-2"
                            >
                                <input
                                    type="date"
                                    v-model="maintenanceStart"
                                    class="w-full border border-gray-300 rounded p-1 text-[12px] text-gray-600 outline-none focus:border-blue-500"
                                />
                                <input
                                    type="date"
                                    v-model="maintenanceEnd"
                                    class="w-full border border-gray-300 rounded p-1 text-[12px] text-gray-600 outline-none focus:border-blue-500"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content -->
            <div
                class="flex-1 bg-white shadow-sm border border-gray-200 rounded-sm flex flex-col min-w-0"
            >
                <!-- Top Toolbar/Title Region -->
                <div
                    class="px-4 py-3 border-b border-gray-200 flex justify-between items-center bg-white flex-shrink-0"
                >
                    <div class="relative w-[400px]">
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
                            v-model="search"
                            type="text"
                            class="block w-full pl-9 pr-8 py-1.5 text-[13px] border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none hover:border-blue-400 placeholder:text-gray-400 transition-colors"
                            placeholder="Theo mã hàng"
                        />
                        <div
                            class="absolute inset-y-0 right-0 pr-2 flex items-center pointer-events-none"
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
                                    d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"
                                ></path>
                            </svg>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <div class="relative group">
                            <ExcelButtons export-url="/warranties/export" />
                        </div>
                        <button
                            class="px-2 py-1.5 text-[13px] text-gray-600 hover:text-gray-800 bg-white border border-gray-300 rounded hover:bg-gray-50 transition-colors focus:ring-1 focus:ring-gray-300 shadow-sm"
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
                            class="px-2 py-1.5 text-[13px] text-gray-600 hover:text-gray-800 bg-white border border-gray-300 rounded hover:bg-gray-50 transition-colors focus:ring-1 focus:ring-gray-300 shadow-sm"
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
                            class="px-2 py-1.5 text-[13px] text-gray-600 hover:text-gray-800 bg-white border border-gray-300 rounded hover:bg-gray-50 transition-colors focus:ring-1 focus:ring-gray-300 shadow-sm"
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

                <!-- View/Table -->
                <div class="flex-1 overflow-auto bg-[#eef1f5]">
                    <table class="w-full text-left border-collapse bg-white">
                        <thead
                            class="bg-[#f0f4f9] text-[#1a56bc] text-[13px] font-bold sticky top-0 z-10 shadow-[0_1px_0_rgba(200,200,200,0.5)]"
                        >
                            <tr>
                                <SortableHeader label="Mã hàng" field="product_sku" :current-sort="sortBy" :current-direction="sortDirection" class="p-3 whitespace-nowrap border-b border-[#dce3ec] font-semibold w-[150px]" @sort="handleSort" />
                                <SortableHeader label="Tên hàng" field="product_name" :current-sort="sortBy" :current-direction="sortDirection" class="p-3 border-b border-[#dce3ec] font-semibold" @sort="handleSort" />
                                <th
                                    class="p-3 whitespace-nowrap border-b border-[#dce3ec] font-semibold w-[150px]"
                                >
                                    Serial/IMEI/Biển số
                                </th>
                                <th
                                    class="p-3 whitespace-nowrap border-b border-[#dce3ec] font-semibold w-[150px]"
                                >
                                    Hóa đơn mua
                                </th>
                                <SortableHeader label="Khách hàng" field="customer_name" :current-sort="sortBy" :current-direction="sortDirection" class="p-3 whitespace-nowrap border-b border-[#dce3ec] font-semibold w-[200px]" @sort="handleSort" />
                                <SortableHeader label="Bảo hành tối đa" field="warranty_period" :current-sort="sortBy" :current-direction="sortDirection" class="p-3 whitespace-nowrap border-b border-[#dce3ec] font-semibold w-[130px]" @sort="handleSort" />
                                <th
                                    class="p-3 whitespace-nowrap border-b border-[#dce3ec] font-semibold w-[130px]"
                                >
                                    Bảo trì định kỳ
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Empty State -->
                            <tr v-if="warranties.data.length === 0">
                                <td
                                    colspan="7"
                                    class="py-20 text-center bg-white border-b border-gray-200"
                                >
                                    <div
                                        class="flex flex-col items-center justify-center text-gray-400"
                                    >
                                        <div
                                            class="w-16 h-16 bg-blue-50 rounded-full flex items-center justify-center mb-4 text-[#005fb8]"
                                        >
                                            <svg
                                                class="w-8 h-8 opacity-80"
                                                fill="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path
                                                    d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-3 8h-8V9h8v2z"
                                                />
                                            </svg>
                                        </div>
                                        <h3
                                            class="font-bold text-gray-800 text-[16px] mb-2"
                                        >
                                            Không tìm thấy kết quả
                                        </h3>
                                        <p class="text-[14px]">
                                            Không tìm thấy giao dịch nào phù
                                            hợp.
                                        </p>
                                    </div>
                                </td>
                            </tr>

                            <!-- Items -->
                            <template
                                v-for="item in warranties.data"
                                :key="item.id"
                            >
                                <!-- Main Row -->
                                <tr
                                    class="hover:bg-[#f0f9ff]/40 border-b border-dashed border-gray-200 text-[13px] text-gray-800 transition-colors cursor-pointer"
                                    @click="toggleRow(item.id)"
                                >
                                    <td class="p-3 text-blue-600 font-medium">
                                        {{ item.product?.sku || "N/A" }}
                                    </td>
                                    <td class="p-3">
                                        <div class="line-clamp-2 truncate">
                                            {{ item.product?.name || "N/A" }}
                                        </div>
                                    </td>
                                    <td class="p-3">
                                        {{ item.serial_imei || "" }}
                                    </td>
                                    <td class="p-3">
                                        {{ item.invoice_code || "" }}
                                    </td>
                                    <td class="p-3">
                                        {{ item.customer_name || "" }}
                                    </td>
                                    <td class="p-3 bg-white">
                                        {{
                                            item.warranty_period
                                                ? `${item.warranty_period} tháng`
                                                : ""
                                        }}
                                    </td>
                                    <td class="p-3"></td>
                                </tr>

                                <!-- Expanded Details -->
                                <tr v-if="expandedRow === item.id">
                                    <td
                                        colspan="7"
                                        class="p-0 border-b-2 border-blue-500 shadow-inner bg-[#f9fafc]"
                                    >
                                        <!-- Header tabs -->
                                        <div
                                            class="flex w-full overflow-x-auto overflow-y-hidden border-b border-gray-200 hide-scrollbar bg-white"
                                        >
                                            <div
                                                class="px-5 py-3 border-b-2 font-medium text-[13px] cursor-pointer whitespace-nowrap text-[#1e58c8] border-[#1e58c8]"
                                            >
                                                Thông tin
                                            </div>
                                        </div>

                                        <!-- Content Body -->
                                        <div
                                            class="p-5 flex flex-col gap-4 bg-white mx-1 my-1 border border-gray-300/60 rounded-sm shadow-sm relative"
                                        >
                                            <!-- Basic info -->
                                            <div class="flex justify-between">
                                                <div>
                                                    <h3
                                                        class="text-[17px] font-bold text-gray-800 flex items-center gap-2 mb-2"
                                                    >
                                                        {{
                                                            item.product
                                                                ?.name || "N/A"
                                                        }}
                                                        <span
                                                            class="text-[#005fb8] font-normal cursor-pointer"
                                                            >{{
                                                                item.product
                                                                    ?.sku ||
                                                                "N/A"
                                                            }}</span
                                                        >
                                                    </h3>
                                                    <div
                                                        class="text-[13px] text-gray-600 flex gap-4 mb-1"
                                                    >
                                                        <p>
                                                            Thời gian:
                                                            <span
                                                                class="font-medium text-gray-800"
                                                                >{{
                                                                    formatDateTime(
                                                                        item.purchase_date,
                                                                    )
                                                                }}</span
                                                            >
                                                        </p>
                                                        <p>
                                                            Hóa đơn mua:
                                                            <span
                                                                class="font-medium text-[#005fb8] cursor-pointer hover:underline"
                                                                >{{
                                                                    item.invoice_code
                                                                }}</span
                                                            >
                                                        </p>
                                                        <p>
                                                            Khách hàng:
                                                            <span
                                                                class="font-medium text-gray-800"
                                                                >{{
                                                                    item.customer_name
                                                                }}</span
                                                            >
                                                        </p>
                                                    </div>
                                                    <div
                                                        class="text-[13px] text-gray-600 flex gap-4"
                                                    >
                                                        <p>
                                                            Số lượng:
                                                            <span
                                                                class="font-medium text-gray-800"
                                                                >1</span
                                                            >
                                                        </p>
                                                        <p>
                                                            Serial/IMEI:
                                                            <span
                                                                class="font-medium text-gray-800"
                                                                >{{
                                                                    item.serial_imei ||
                                                                    "Chưa có"
                                                                }}</span
                                                            >
                                                        </p>
                                                    </div>
                                                </div>
                                                <!-- Branch Marker Right -->
                                                <div
                                                    class="text-right flex flex-col items-end"
                                                >
                                                    <div
                                                        class="text-[13px] font-bold text-gray-700 flex items-center gap-1"
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
                                                                d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"
                                                            ></path>
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"
                                                            ></path>
                                                        </svg>
                                                        Laptopplus.vn
                                                    </div>
                                                </div>
                                            </div>

                                            <div
                                                class="border border-gray-200 mt-2 rounded"
                                            >
                                                <!-- Inner Tabs -->
                                                <div class="flex flex-col">
                                                    <!-- Warranty section -->
                                                    <div
                                                        class="border-b border-gray-100 last:border-0"
                                                    >
                                                        <div
                                                            class="px-4 py-2.5 font-bold text-[13px] text-gray-800 bg-gray-50/50"
                                                        >
                                                            Bảo hành
                                                        </div>
                                                        <table
                                                            class="w-full text-left text-[13px]"
                                                        >
                                                            <thead
                                                                class="bg-gray-100 text-gray-700"
                                                            >
                                                                <tr>
                                                                    <th
                                                                        class="px-4 py-2 font-semibold"
                                                                    >
                                                                        Bảo hành
                                                                    </th>
                                                                    <th
                                                                        class="px-4 py-2 font-semibold w-[200px]"
                                                                    >
                                                                        Thời hạn
                                                                        bảo hành
                                                                    </th>
                                                                    <th
                                                                        class="px-4 py-2 font-semibold w-[200px]"
                                                                    >
                                                                        Ngày hết
                                                                        hạn
                                                                        <svg
                                                                            class="w-3.5 h-3.5 inline-block ml-0.5 text-gray-400"
                                                                            fill="none"
                                                                            stroke="currentColor"
                                                                            viewBox="0 0 24 24"
                                                                        >
                                                                            <path
                                                                                stroke-linecap="round"
                                                                                stroke-linejoin="round"
                                                                                stroke-width="2"
                                                                                d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                                                            ></path>
                                                                        </svg>
                                                                    </th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <tr
                                                                    class="border-b border-gray-100"
                                                                >
                                                                    <td
                                                                        class="px-4 py-2"
                                                                    >
                                                                        Toàn bộ
                                                                        sản phẩm
                                                                    </td>
                                                                    <td
                                                                        class="px-4 py-2"
                                                                    >
                                                                        {{
                                                                            item.warranty_period ||
                                                                            ""
                                                                        }}
                                                                    </td>
                                                                    <td
                                                                        class="px-4 py-2"
                                                                    >
                                                                        {{
                                                                            formatDate(
                                                                                item.warranty_end_date,
                                                                            )
                                                                        }}
                                                                    </td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                        <div
                                                            class="px-4 py-3 flex items-center gap-2 text-[13px]"
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
                                                                    d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                                                                ></path>
                                                            </svg>
                                                            <span
                                                                class="text-gray-600"
                                                                >Đã tắt nhắc hạn
                                                                bảo hành</span
                                                            >
                                                        </div>
                                                    </div>

                                                    <!-- Maintenance section -->
                                                    <div
                                                        class="border-t-4 border-gray-100"
                                                    >
                                                        <div
                                                            class="px-4 py-2.5 font-bold text-[13px] text-gray-800 bg-gray-50/50"
                                                        >
                                                            Bảo trì
                                                        </div>
                                                        <div
                                                            class="px-4 py-8 text-center text-gray-400"
                                                        >
                                                            (Chưa thiết lập bảo
                                                            trì định kỳ)
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div
                                                class="text-[13px] text-gray-500 flex items-center gap-2 mt-1"
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
                                                <span>{{
                                                    item.maintenance_note ||
                                                    "Chưa có ghi chú hàng mua"
                                                }}</span>
                                            </div>

                                            <div
                                                class="flex justify-between mt-3 pt-3 border-t border-gray-200 border-dashed"
                                            >
                                                <button
                                                    class="px-3 py-1.5 text-[13px] font-medium text-gray-700 hover:text-gray-900 bg-white border border-gray-300 rounded hover:bg-gray-50 transition-colors flex items-center justify-center gap-1.5 leading-tight"
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
                                                            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"
                                                        ></path>
                                                    </svg>
                                                    Xuất file
                                                </button>

                                                <div class="flex gap-2">
                                                    <button
                                                        class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded text-[13px] flex items-center gap-1.5 shadow-sm transition-colors"
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
                                                            ></path>
                                                        </svg>
                                                        Chỉnh sửa
                                                    </button>
                                                    <button
                                                        @click.stop="
                                                            printWarranty(item)
                                                        "
                                                        class="px-4 py-1.5 font-medium text-gray-700 hover:text-gray-900 bg-white border border-gray-300 rounded hover:bg-gray-50 flex items-center gap-1.5 text-[13px]"
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
                                                                d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"
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
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div
                    class="px-4 py-3 border-t border-gray-200 flex justify-between items-center bg-gray-50 flex-shrink-0"
                >
                    <div class="text-[13px] text-gray-600">
                        Hiển thị từ
                        <span class="font-medium text-gray-900">{{
                            warranties.from || 0
                        }}</span>
                        đến
                        <span class="font-medium text-gray-900">{{
                            warranties.to || 0
                        }}</span>
                        trên tổng số
                        <span class="font-medium text-gray-900">{{
                            warranties.total
                        }}</span>
                        bản ghi
                    </div>

                    <div
                        class="flex p-0.5 bg-white border border-gray-300 rounded shadow-sm"
                    >
                        <template
                            v-for="(link, i) in warranties.links"
                            :key="i"
                        >
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                class="px-3 py-1 text-[13px] border-r border-gray-200 last:border-0 hover:bg-gray-50 transition-colors"
                                :class="{
                                    'bg-[#eff4fb] text-[#1e58c8] font-bold':
                                        link.active,
                                    'text-gray-600': !link.active,
                                }"
                                v-html="link.label"
                                preserve-scroll
                            />
                            <span
                                v-else
                                class="px-3 py-1 text-[13px] text-gray-300 border-r border-gray-200 last:border-0 bg-gray-50/50 cursor-not-allowed"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Custom select styling for filters */
select {
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 0.5rem center;
    background-size: 1em;
    padding-right: 2rem !important;
}

/* Hide scrollbar structure */
.hide-scrollbar::-webkit-scrollbar {
    display: none;
}
.hide-scrollbar {
    -ms-overflow-style: none; /* IE and Edge */
    scrollbar-width: none; /* Firefox */
}
</style>
