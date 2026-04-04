<script setup>
import { ref, watch } from "vue";
import { Head, router, Link } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import SortableHeader from "@/Components/SortableHeader.vue";

const props = defineProps({
    transfers: Object,
    branches: Array,
    filters: Object,
});

const search = ref(props.filters.search || "");
const fromBranchId = ref(props.filters.from_branch_id || "");
const toBranchId = ref(props.filters.to_branch_id || "");
const statuses = ref(
    props.filters.status || ["draft", "transferring", "received"],
);
const sortBy = ref(props.filters.sort_by || "");
const sortDirection = ref(props.filters.sort_direction || "");

const toggleStatus = (status) => {
    if (statuses.value.includes(status)) {
        statuses.value = statuses.value.filter((s) => s !== status);
    } else {
        statuses.value.push(status);
    }
    fetchFiltered();
};

const handleSort = (field, direction) => {
    sortBy.value = field;
    sortDirection.value = direction;
    router.get(
        "/stock-transfers",
        {
            search: search.value,
            from_branch_id: fromBranchId.value,
            to_branch_id: toBranchId.value,
            status: statuses.value,
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
            "/stock-transfers",
            {
                search: search.value,
                from_branch_id: fromBranchId.value,
                to_branch_id: toBranchId.value,
                status: statuses.value,
                sort_by: sortBy.value,
                sort_direction: sortDirection.value,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 500);
};

watch([search, fromBranchId, toBranchId], fetchFiltered);

const formatCurrency = (value) => {
    if (!value) return "0";
    return Number(value).toLocaleString("vi-VN");
};
const formatDate = (date) => {
    if (!date) return "";
    return new Date(date).toLocaleString("vi-VN");
};
const getStatusBadge = (status) => {
    const map = {
        draft: {
            label: "Phiếu tạm",
            classes: "bg-gray-100 text-gray-700 border-gray-300",
        },
        transferring: {
            label: "Đang chuyển",
            classes: "bg-orange-100 text-orange-700 border-orange-200",
        },
        received: {
            label: "Đã nhận",
            classes: "bg-green-100 text-green-700 border-green-200",
        },
    };
    return map[status] || { label: status, classes: "bg-gray-100" };
};

const printTransfer = (item) => {
    window.open(
        `/stock-transfers/${item.id}/print`,
        "_blank",
        "width=400,height=600",
    );
};
</script>

<template>
    <Head title="Chuyển hàng - KiotViet Clone" />
    <AppLayout>
        <div class="h-full flex gap-4">
            <!-- Sidebar -->
            <div
                class="w-[240px] flex-shrink-0 bg-white shadow-sm border border-gray-200 rounded-sm"
            >
                <div class="p-3 border-b border-gray-200 bg-gray-50/50">
                    <h2 class="font-bold text-[14px] text-gray-800">
                        Chuyển hàng
                    </h2>
                </div>

                <div class="p-3 flex flex-col gap-4">
                    <!-- Chuyển đi -->
                    <div>
                        <label
                            class="block text-[13px] font-bold text-gray-800 mb-1.5"
                            >Chuyển đi</label
                        >
                        <select
                            v-model="fromBranchId"
                            class="w-full border border-gray-300 rounded p-1.5 text-[13px] focus:ring-1 focus:ring-blue-500 focus:border-blue-500 text-gray-700 outline-none hover:border-blue-400"
                        >
                            <option value="">Chọn chi nhánh</option>
                            <option
                                v-for="branch in branches"
                                :key="branch.id"
                                :value="branch.id"
                            >
                                {{ branch.name }}
                            </option>
                        </select>
                    </div>

                    <!-- Nhận về -->
                    <div>
                        <label
                            class="block text-[13px] font-bold text-gray-800 mb-1.5"
                            >Nhận về</label
                        >
                        <select
                            v-model="toBranchId"
                            class="w-full border border-gray-300 rounded p-1.5 text-[13px] focus:ring-1 focus:ring-blue-500 focus:border-blue-500 text-gray-700 outline-none hover:border-blue-400"
                        >
                            <option value="">Chọn chi nhánh</option>
                            <option
                                v-for="branch in branches"
                                :key="branch.id"
                                :value="branch.id"
                            >
                                {{ branch.name }}
                            </option>
                        </select>
                    </div>

                    <!-- Trạng thái -->
                    <div>
                        <label
                            class="block text-[13px] font-bold text-gray-800 mb-2"
                            >Trạng thái</label
                        >
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                @click="toggleStatus('draft')"
                                class="text-[12px] px-2 py-1 rounded border flex items-center gap-1"
                                :class="
                                    statuses.includes('draft')
                                        ? 'bg-blue-600 text-white border-blue-600'
                                        : 'bg-gray-50 text-gray-600 border-gray-300'
                                "
                            >
                                Phiếu tạm
                                <span v-if="statuses.includes('draft')">×</span>
                            </button>
                            <button
                                @click="toggleStatus('transferring')"
                                class="text-[12px] px-2 py-1 rounded border flex items-center gap-1"
                                :class="
                                    statuses.includes('transferring')
                                        ? 'bg-blue-600 text-white border-blue-600'
                                        : 'bg-gray-50 text-gray-600 border-gray-300'
                                "
                            >
                                Đang chuyển
                                <span v-if="statuses.includes('transferring')"
                                    >×</span
                                >
                            </button>
                            <button
                                @click="toggleStatus('received')"
                                class="text-[12px] px-2 py-1 rounded border flex items-center gap-1"
                                :class="
                                    statuses.includes('received')
                                        ? 'bg-blue-600 text-white border-blue-600'
                                        : 'bg-gray-50 text-gray-600 border-gray-300'
                                "
                            >
                                Đã nhận
                                <span v-if="statuses.includes('received')"
                                    >×</span
                                >
                            </button>
                        </div>
                    </div>

                    <!-- Thời gian -->
                    <div class="border-t border-gray-100 pt-4 mt-2">
                        <label
                            class="block text-[13px] font-bold text-gray-800 mb-2"
                            >Thời gian</label
                        >
                        <label
                            class="flex items-center gap-2 mb-1 cursor-pointer"
                        >
                            <input
                                type="radio"
                                name="time_type"
                                class="text-blue-600"
                                checked
                            />
                            <span class="text-[13px] text-gray-700"
                                >Ngày chuyển</span
                            >
                        </label>
                        <label
                            class="flex items-center gap-2 mb-2 cursor-pointer"
                        >
                            <input
                                type="radio"
                                name="time_type"
                                class="text-blue-600"
                            />
                            <span class="text-[13px] text-gray-700"
                                >Ngày nhận</span
                            >
                        </label>
                        <select
                            class="w-full border border-gray-300 rounded p-1.5 text-[13px] focus:ring-1 focus:ring-blue-500 focus:border-blue-500 text-gray-700 outline-none hover:border-blue-400"
                        >
                            <option>Tháng này</option>
                            <option>Hôm nay</option>
                            <option>Tuỳ chỉnh</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Content Area -->
            <div
                class="flex-1 bg-white shadow-sm border border-gray-200 rounded-sm flex flex-col min-w-0"
            >
                <div
                    class="px-4 py-3 border-b border-gray-200 flex justify-between items-center flex-shrink-0 bg-white"
                >
                    <div class="relative w-[300px]">
                        <input
                            v-model="search"
                            type="text"
                            class="block w-full pl-9 pr-3 py-1.5 text-[13px] border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none hover:border-blue-400 placeholder:text-gray-400"
                            placeholder="Theo mã phiếu chuyển"
                        />
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
                    </div>

                    <div class="flex gap-2">
                        <Link
                            href="/stock-transfers/create"
                            class="px-3 py-1.5 text-[13px] font-medium text-white bg-blue-600 rounded hover:bg-blue-700 transition-colors flex items-center gap-1.5 shadow-sm"
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
                            Chuyển hàng
                        </Link>
                        <ExcelButtons export-url="/stock-transfers/export" />
                    </div>
                </div>

                <div class="flex-1 overflow-auto bg-[#eef1f5]">
                    <table class="w-full text-left border-collapse bg-white">
                        <thead
                            class="bg-[#f0f4f9] text-[#1a56bc] text-[13px] font-bold sticky top-0 shadow-[0_1px_0_rgba(200,200,200,0.5)] z-10"
                        >
                            <tr>
                                <th
                                    class="p-3 w- diez border-b border-[#dce3ec]"
                                ></th>
                                <SortableHeader label="Mã chuyển hàng" field="code" :current-sort="sortBy" :current-direction="sortDirection" class="p-3 border-b border-[#dce3ec]" @sort="handleSort" />
                                <SortableHeader label="Ngày chuyển" field="sent_date" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" class="p-3 border-b border-[#dce3ec]" @sort="handleSort" />
                                <th class="p-3 border-b border-[#dce3ec]">
                                    Ngày nhận
                                </th>
                                <th class="p-3 border-b border-[#dce3ec]">
                                    Từ chi nhánh
                                </th>
                                <th class="p-3 border-b border-[#dce3ec]">
                                    Tới chi nhánh
                                </th>
                                <SortableHeader label="Giá trị chuyển" field="total_price" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="p-3 text-right border-b border-[#dce3ec]" @sort="handleSort" />
                                <SortableHeader label="Trạng thái" field="status" :current-sort="sortBy" :current-direction="sortDirection" class="p-3 border-b border-[#dce3ec]" @sort="handleSort" />
                                <th
                                    class="p-3 border-b border-[#dce3ec] text-center w-16"
                                ></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="transfers.data.length === 0">
                                <td
                                    colspan="9"
                                    class="py-20 text-center border-b border-gray-200"
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
                                            Chưa có giao dịch
                                        </h3>
                                        <p class="text-[14px]">
                                            Không tìm thấy phiếu chuyển hàng
                                            nào.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                            <tr
                                v-for="item in transfers.data"
                                :key="item.id"
                                class="hover:bg-[#f0f9ff]/40 border-b border-gray-100 text-[13px]"
                            >
                                <td
                                    class="p-3 border-b border-dashed border-gray-200 text-center"
                                >
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                    />
                                </td>
                                <td
                                    class="p-3 text-blue-600 font-medium cursor-pointer hover:underline border-b border-dashed border-gray-200"
                                >
                                    {{ item.code }}
                                </td>
                                <td
                                    class="p-3 border-b border-dashed border-gray-200"
                                >
                                    {{ formatDate(item.sent_date) }}
                                </td>
                                <td
                                    class="p-3 border-b border-dashed border-gray-200"
                                >
                                    {{ formatDate(item.receive_date) }}
                                </td>
                                <td
                                    class="p-3 border-b border-dashed border-gray-200"
                                >
                                    {{ item.from_branch?.name || "" }}
                                </td>
                                <td
                                    class="p-3 border-b border-dashed border-gray-200"
                                >
                                    {{ item.to_branch?.name || "" }}
                                </td>
                                <td
                                    class="p-3 text-right font-medium border-b border-dashed border-gray-200"
                                >
                                    {{ formatCurrency(item.total_price) }}
                                </td>
                                <td
                                    class="p-3 border-b border-dashed border-gray-200"
                                >
                                    <span
                                        class="px-2 py-0.5 rounded text-[12px] font-medium border"
                                        :class="
                                            getStatusBadge(item.status).classes
                                        "
                                    >
                                        {{ getStatusBadge(item.status).label }}
                                    </span>
                                </td>
                                <td
                                    class="p-3 border-b border-dashed border-gray-200 text-center"
                                >
                                    <button
                                        @click.stop="printTransfer(item)"
                                        class="text-gray-400 hover:text-blue-600 transition"
                                        title="In phiếu"
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
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    class="px-4 py-3 border-t border-gray-200 flex justify-between items-center bg-gray-50 flex-shrink-0"
                >
                    <div class="text-[13px] text-gray-600">
                        Hiển thị từ
                        <span class="font-medium text-gray-900">{{
                            transfers.from || 0
                        }}</span>
                        đến
                        <span class="font-medium text-gray-900">{{
                            transfers.to || 0
                        }}</span>
                        trên tổng số
                        <span class="font-medium text-gray-900">{{
                            transfers.total || 0
                        }}</span>
                        hóa đơn
                    </div>
                    <!-- Pagination -->
                    <div
                        class="flex gap-1"
                        v-if="transfers.links && transfers.links.length > 3"
                    >
                        <template
                            v-for="(link, index) in transfers.links"
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

<style scoped>
select {
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 0.5rem center;
    background-size: 1em;
}
</style>
