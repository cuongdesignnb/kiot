<script setup>
import { ref, watch } from "vue";
import { Head, router, Link } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import SortableHeader from "@/Components/SortableHeader.vue";

const props = defineProps({
    returns: Object,
    branches: Array,
    filters: Object,
});

const search = ref(props.filters?.search || "");
const sortBy = ref(props.filters?.sort_by || "");
const sortDirection = ref(props.filters?.sort_direction || "");
const expandedRows = ref([]);

const handleSort = (field, direction) => {
    sortBy.value = field;
    sortDirection.value = direction;
    router.get(
        "/returns",
        { search: search.value, sort_by: field, sort_direction: direction },
        { preserveState: true, replace: true },
    );
};

let searchTimeout;
const updateFilters = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            "/returns",
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

const printReturn = (ret) => {
    window.open(`/returns/${ret.id}/print`, "_blank", "width=400,height=600");
};
</script>

<template>
    <Head title="Trả hàng - KiotViet Clone" />
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

            <!-- LOẠI TRẢ HÀNG -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-[13px] font-bold text-gray-800 mb-2"
                    >Loại trả hàng</label
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
                        Theo hóa đơn
                    </label>
                    <label
                        class="flex items-center gap-2 cursor-pointer text-[13px] text-gray-700"
                    >
                        <input
                            type="checkbox"
                            checked
                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Trả nhanh
                    </label>
                    <label
                        class="flex items-center gap-2 cursor-pointer text-[13px] text-gray-700"
                    >
                        <input
                            type="checkbox"
                            checked
                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Chuyển hoàn
                    </label>
                </div>
            </div>

            <!-- Lọc TRẠNG THÁI -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-[13px] font-bold text-gray-800 mb-2"
                    >Trạng thái</label
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
                        Đã trả
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

            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-[13px] font-bold text-gray-800 mb-2"
                    >Người tạo</label
                >
                <input
                    type="text"
                    placeholder="Chọn người tạo"
                    class="w-full border border-gray-300 rounded p-1.5 text-[13px] outline-none text-gray-500"
                />
            </div>

            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-[13px] font-bold text-gray-800 mb-2"
                    >Người nhận trả</label
                >
                <input
                    type="text"
                    placeholder="Chọn người nhận trả"
                    class="w-full border border-gray-300 rounded p-1.5 text-[13px] outline-none text-gray-500"
                />
            </div>

            <div class="px-3 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-[13px] font-bold text-gray-800"
                        >Kênh bán</label
                    >
                    <span class="text-blue-600 text-[13px] cursor-pointer"
                        >Tạo mới</span
                    >
                </div>
                <input
                    type="text"
                    placeholder="Chọn kênh bán"
                    class="w-full border border-gray-300 rounded p-1.5 text-[13px] outline-none text-gray-500"
                />
            </div>

            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-[13px] font-bold text-gray-800 mb-2"
                    >Loại thu khác</label
                >
                <input
                    type="text"
                    placeholder="Chọn loại thu khác"
                    class="w-full border border-gray-300 rounded p-1.5 text-[13px] outline-none text-gray-500"
                />
            </div>
        </template>

        <div class="bg-white h-full flex flex-col pt-3">
            <div
                class="flex items-center justify-between px-4 pb-3 border-b border-gray-200"
            >
                <div class="text-2xl font-bold text-gray-800">Trả hàng</div>

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
                        placeholder="Theo mã phiếu trả"
                        class="w-full pl-9 pr-8 py-1.5 focus:outline-none border border-gray-300 rounded text-sm placeholder-gray-400"
                    />
                </div>

                <div class="flex gap-2 ml-auto">
                    <Link
                        href="/orders/create?action=return"
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
                        Trả hàng
                    </Link>
                    <ExcelButtons export-url="/returns/export" />
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
                            <SortableHeader label="Mã trả hàng" field="code" :current-sort="sortBy" :current-direction="sortDirection" class="px-2 py-2" @sort="handleSort" />
                            <th class="px-2 py-2">Người bán</th>
                            <SortableHeader label="Thời gian" field="created_at" :current-sort="sortBy" :current-direction="sortDirection" class="px-2 py-2" @sort="handleSort" />
                            <th class="px-2 py-2">Khách hàng</th>
                            <SortableHeader label="Tổng tiền hàng" field="total" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <th class="px-4 py-2 text-right">Cần trả khách</th>
                            <SortableHeader label="Đã trả khách" field="paid_to_customer" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <SortableHeader label="Trạng thái" field="status" :current-sort="sortBy" :current-direction="sortDirection" class="px-2 py-2 text-left" @sort="handleSort" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="returns.data.length === 0">
                            <td
                                colspan="9"
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
                                        Không tìm thấy phiếu trả hàng nào phù
                                        hợp.
                                    </p>
                                </div>
                            </td>
                        </tr>
                        <template v-for="ret in returns.data" :key="ret.id">
                            <tr
                                @click="toggleExpand(ret.id)"
                                class="hover:bg-blue-50/40 cursor-pointer transition-colors"
                                :class="{
                                    'bg-[#f4f7fe]': isExpanded(ret.id),
                                    'border-l-2 border-l-blue-500': isExpanded(
                                        ret.id,
                                    ),
                                }"
                            >
                                <td class="px-3 py-2 text-center" @click.stop>
                                    <div class="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            class="rounded border-gray-300"
                                        />
                                        <svg
                                            class="w-4 h-4 text-gray-300"
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
                                    </div>
                                </td>
                                <td
                                    class="px-2 py-2 font-medium"
                                    :class="
                                        isExpanded(ret.id)
                                            ? 'text-gray-900'
                                            : 'text-blue-600'
                                    "
                                >
                                    {{ ret.code }}
                                </td>
                                <td class="px-2 py-2">
                                    {{ ret.seller_name || "Trần Văn Tiến" }}
                                </td>
                                <td class="px-2 py-2">
                                    {{
                                        new Date(ret.created_at).toLocaleString(
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
                                </td>
                                <td class="px-2 py-2">
                                    {{ ret.customer?.name || "Khách lẻ" }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ formatCurrency(ret.subtotal) }}
                                </td>
                                <td
                                    class="px-4 py-2 text-right font-medium text-gray-800"
                                >
                                    {{ formatCurrency(ret.total) }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ formatCurrency(ret.paid_to_customer) }}
                                </td>
                                <td class="px-2 py-2 text-left">
                                    <span
                                        class="text-green-600 px-1 py-0.5"
                                        :class="{
                                            'bg-green-100/50 rounded':
                                                !isExpanded(ret.id),
                                        }"
                                        >{{ ret.status || "Đã trả" }}</span
                                    >
                                </td>
                            </tr>
                            <tr
                                v-if="isExpanded(ret.id)"
                                class="border-b-4 border-blue-50"
                            >
                                <td
                                    colspan="9"
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
                                            <button
                                                class="px-4 pb-2 hover:text-blue-600"
                                            >
                                                Lịch sử thanh toán
                                            </button>
                                        </div>

                                        <!-- Header Info -->
                                        <div
                                            class="flex items-center gap-2 mb-4"
                                        >
                                            <h2
                                                class="text-[17px] font-bold text-gray-800"
                                            >
                                                {{
                                                    ret.customer?.name ||
                                                    "Hà Phi Hùng"
                                                }}
                                            </h2>
                                            <svg
                                                class="w-4 h-4 text-blue-600 cursor-pointer"
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
                                                {{ ret.code }}
                                                <span
                                                    class="bg-green-100 text-green-700 px-2 py-0.5 rounded text-xs font-medium border border-green-200"
                                                    >{{
                                                        ret.status || "Đã trả"
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
                                                <div class="flex items-center">
                                                    <span
                                                        class="text-gray-400 w-24"
                                                        >Người tạo:</span
                                                    >
                                                    <span
                                                        class="text-gray-800"
                                                        >{{
                                                            ret.created_by_name ||
                                                            "Trần Văn Tiến"
                                                        }}</span
                                                    >
                                                </div>
                                                <div class="flex items-center">
                                                    <span
                                                        class="text-gray-400 w-24"
                                                        >Người nhận trả:</span
                                                    >
                                                    <select
                                                        class="border border-gray-300 rounded px-2 py-0.5 outline-none flex-1"
                                                    >
                                                        <option>
                                                            {{
                                                                ret.seller_name ||
                                                                "Vũ Hồng Nhung"
                                                            }}
                                                        </option>
                                                    </select>
                                                </div>
                                                <div
                                                    class="flex items-center justify-end"
                                                >
                                                    <span
                                                        class="text-gray-400 w-24"
                                                        >Ngày trả:</span
                                                    >
                                                    <div
                                                        class="flex items-center border border-gray-300 rounded px-2 py-0.5 w-[160px] bg-white"
                                                    >
                                                        <span class="flex-1">{{
                                                            new Date(
                                                                ret.created_at,
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
                                                        }}</span>
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

                                                <div class="flex items-center">
                                                    <span
                                                        class="text-gray-400 w-24"
                                                        >Mã hóa đơn:</span
                                                    >
                                                    <span
                                                        class="text-blue-500 cursor-pointer"
                                                        >{{
                                                            ret.invoice?.code ||
                                                            "HD008183"
                                                        }}</span
                                                    >
                                                </div>
                                                <div class="flex items-center">
                                                    <span
                                                        class="text-gray-400 w-24"
                                                        >Kênh bán:</span
                                                    >
                                                    <span
                                                        class="text-gray-800"
                                                        >{{
                                                            ret.sales_channel ||
                                                            "Bán trực tiếp"
                                                        }}</span
                                                    >
                                                </div>
                                                <div
                                                    class="flex items-center justify-end"
                                                >
                                                    <span
                                                        class="text-gray-400 w-24"
                                                        >Bảng giá:</span
                                                    >
                                                    <div
                                                        class="w-[160px] font-medium text-gray-800"
                                                    >
                                                        Bảng giá chung
                                                    </div>
                                                </div>
                                                <div class="flex items-center">
                                                    <span
                                                        class="text-gray-400 w-24"
                                                        >Mã phiếu chi:</span
                                                    >
                                                    <span
                                                        class="text-blue-500 cursor-pointer"
                                                        >TTTH000390</span
                                                    >
                                                </div>
                                                <div></div>
                                                <!-- Empty cell -->
                                                <div></div>
                                                <!-- Empty cell -->
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
                                                                Giá trả hàng
                                                            </th>
                                                            <th
                                                                class="px-4 py-3 text-right font-bold text-gray-800 w-32 border-r border-gray-100"
                                                            >
                                                                Giảm giá
                                                            </th>
                                                            <th
                                                                class="px-4 py-3 text-right font-bold text-gray-800 w-32 border-r border-gray-100"
                                                            >
                                                                Giá nhập lại
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
                                                        <!-- Fake items to look like the design -->
                                                        <tr
                                                            v-if="
                                                                !ret.items ||
                                                                ret.items
                                                                    .length ===
                                                                    0
                                                            "
                                                        >
                                                            <td
                                                                class="px-6 py-3 text-blue-500 font-medium"
                                                            >
                                                                SP003731
                                                            </td>
                                                            <td
                                                                class="px-4 py-3"
                                                            >
                                                                <div
                                                                    class="text-gray-800"
                                                                >
                                                                    Dell 7290
                                                                    i5-7300U/8/256
                                                                </div>
                                                                <div
                                                                    class="text-gray-500 text-xs mt-1 bg-gray-100 inline-block px-1 rounded"
                                                                >
                                                                    GLRCSQ2
                                                                </div>
                                                            </td>
                                                            <td
                                                                class="px-4 py-3 text-right text-gray-800"
                                                            >
                                                                1
                                                            </td>
                                                            <td
                                                                class="px-4 py-3 text-right text-gray-800"
                                                            >
                                                                2,970,000
                                                            </td>
                                                            <td
                                                                class="px-4 py-3 text-right text-gray-800"
                                                            >
                                                                0
                                                            </td>
                                                            <td
                                                                class="px-4 py-3 text-right text-gray-800"
                                                            >
                                                                2,970,000
                                                            </td>
                                                            <td
                                                                class="px-6 py-3 text-right font-bold text-gray-800"
                                                            >
                                                                2,970,000
                                                            </td>
                                                        </tr>
                                                        <tr
                                                            v-if="
                                                                !ret.items ||
                                                                ret.items
                                                                    .length ===
                                                                    0
                                                            "
                                                        >
                                                            <td
                                                                class="px-6 py-3 text-blue-500 font-medium"
                                                            >
                                                                SP007938
                                                            </td>
                                                            <td
                                                                class="px-4 py-3"
                                                            >
                                                                <div
                                                                    class="text-gray-800"
                                                                >
                                                                    Dán phím
                                                                    Dell
                                                                </div>
                                                            </td>
                                                            <td
                                                                class="px-4 py-3 text-right text-gray-800"
                                                            >
                                                                1
                                                            </td>
                                                            <td
                                                                class="px-4 py-3 text-right text-gray-800"
                                                            >
                                                                0
                                                            </td>
                                                            <td
                                                                class="px-4 py-3 text-right text-gray-800"
                                                            >
                                                                0
                                                            </td>
                                                            <td
                                                                class="px-4 py-3 text-right text-gray-800"
                                                            >
                                                                0
                                                            </td>
                                                            <td
                                                                class="px-6 py-3 text-right font-bold text-gray-800"
                                                            >
                                                                0
                                                            </td>
                                                        </tr>

                                                        <tr
                                                            v-for="item in ret.items"
                                                            :key="item.id"
                                                        >
                                                            <td
                                                                class="px-6 py-3 text-blue-500 font-medium"
                                                            >
                                                                {{
                                                                    item.product
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
                                                                        item.import_price ||
                                                                            0,
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
                                                            >Tổng tiền hàng trả
                                                            ({{
                                                                ret.items
                                                                    ?.length ||
                                                                3
                                                            }})</span
                                                        >
                                                        <span
                                                            class="text-gray-800 font-medium"
                                                            >{{
                                                                formatCurrency(
                                                                    ret.subtotal ||
                                                                        2970000,
                                                                )
                                                            }}</span
                                                        >
                                                    </div>
                                                    <div
                                                        class="flex justify-between py-1.5 text-gray-500"
                                                    >
                                                        <span
                                                            >Giảm giá phiếu
                                                            trả</span
                                                        >
                                                        <span
                                                            class="text-gray-800 font-medium"
                                                            >{{
                                                                formatCurrency(
                                                                    ret.discount ||
                                                                        0,
                                                                )
                                                            }}</span
                                                        >
                                                    </div>
                                                    <div
                                                        class="flex justify-between py-1.5 text-gray-500"
                                                    >
                                                        <span
                                                            >Phí trả hàng</span
                                                        >
                                                        <span
                                                            class="text-gray-800 font-medium"
                                                            >{{
                                                                formatCurrency(
                                                                    ret.fee ||
                                                                        0,
                                                                )
                                                            }}</span
                                                        >
                                                    </div>
                                                    <div
                                                        class="flex justify-between py-1.5 text-gray-500"
                                                    >
                                                        <span
                                                            >Cần trả khách</span
                                                        >
                                                        <span
                                                            class="text-gray-800 font-medium"
                                                            >{{
                                                                formatCurrency(
                                                                    ret.total ||
                                                                        2970000,
                                                                )
                                                            }}</span
                                                        >
                                                    </div>
                                                    <div
                                                        class="flex justify-between py-1.5 text-gray-500"
                                                    >
                                                        <span
                                                            >Đã trả khách</span
                                                        >
                                                        <span
                                                            class="text-gray-800 font-medium"
                                                            >{{
                                                                formatCurrency(
                                                                    ret.paid_to_customer ||
                                                                        2900000,
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
                                                    <button
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
                                                                d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"
                                                            ></path>
                                                        </svg>
                                                        Sao chép
                                                    </button>
                                                    <button
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
                                                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"
                                                            ></path>
                                                        </svg>
                                                        Xuất file
                                                    </button>
                                                </div>
                                                <div class="flex gap-2">
                                                    <button
                                                        class="bg-[#0070f4] text-white px-5 py-1.5 rounded font-medium hover:bg-blue-600 flex items-center gap-1.5"
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
                                                                d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"
                                                            ></path>
                                                        </svg>
                                                        Lưu
                                                    </button>
                                                    <button
                                                        @click.stop="
                                                            printReturn(ret)
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
                    <span class="font-bold">{{ returns.from || 0 }}</span> đến
                    <span class="font-bold">{{ returns.to || 0 }}</span> trong
                    tổng số
                    <span class="font-bold">{{ returns.total || 0 }}</span>
                    phiếu trả hàng
                </div>
                <!-- Pagination -->
                <div
                    class="flex gap-1"
                    v-if="returns.links && returns.links.length > 3"
                >
                    <template
                        v-for="(link, index) in returns.links"
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
