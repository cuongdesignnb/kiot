<script setup>
import { ref, watch } from "vue";
import { Head, router, Link } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";

const props = defineProps({
    purchases: Object,
    filters: Object,
    summary: Object,
});

const search = ref(props.filters?.search || "");
const statusFilters = ref(props.filters?.status || []);
const dateFilter = ref(props.filters?.date_filter || "this_month");

const allStatuses = [
    { value: "draft", label: "Phiếu tạm" },
    { value: "completed", label: "Hoàn thành" },
    { value: "returned", label: "Đã trả hàng" },
    { value: "cancelled", label: "Đã hủy" },
];

let searchTimeout;
const updateFilters = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            "/purchases",
            {
                search: search.value,
                status: statusFilters.value,
                date_filter: dateFilter.value,
            },
            {
                preserveState: true,
                replace: true,
            },
        );
    }, 500);
};

watch([search, statusFilters, dateFilter], updateFilters, { deep: true });

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

const goToDetail = (id) => {
    router.visit(`/purchases/${id}`);
};

const formatCurrency = (val) => Number(val).toLocaleString("vi-VN");
const formatStatus = (val) => {
    const s = allStatuses.find((x) => x.value === val);
    return s ? s.label : val;
};

const printPurchase = (order) => {
    window.open(
        `/purchases/${order.id}/print`,
        "_blank",
        "width=400,height=600",
    );
};

const cancelPurchase = (order) => {
    if (!confirm(`Bạn có chắc muốn hủy phiếu nhập hàng ${order.code}? Tồn kho và công nợ sẽ được hoàn lại.`)) return;
    router.delete(`/purchases/${order.id}`, {
        preserveState: false,
    });
};
</script>

<template>
    <Head title="Nhập hàng - KiotViet Clone" />
    <AppLayout>
        <template #sidebar>
            <!-- Lọc NHÀ CUNG CẤP -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Chi nhánh</label
                >
                <select
                    class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none bg-blue-600 text-white font-medium appearance-none h-[32px]"
                >
                    <option value="">Chi nhánh trung tâm</option>
                </select>
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
                        class="bg-green-600 text-white px-2 py-1 rounded flex items-center gap-1 cursor-pointer"
                    >
                        {{ formatStatus(status) }}
                        <span
                            @click.stop="removeStatusFilter(status)"
                            class="pl-1 border-l border-green-400 font-bold hover:text-gray-200 ml-1"
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
                            class="rounded border-gray-300 text-green-600 focus:ring-green-500 w-4 h-4"
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
                            class="text-green-600 focus:ring-green-500 w-4 h-4"
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
                            class="text-green-600 focus:ring-green-500 w-4 h-4"
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
                    >Nhà cung cấp</label
                >
                <input
                    type="text"
                    placeholder="Chọn nhà cung cấp"
                    class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none text-gray-700 bg-gray-50"
                />
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
        </template>

        <div class="bg-white h-full flex flex-col pt-3">
            <div
                class="flex items-center justify-between px-4 pb-3 border-b border-gray-200"
            >
                <div class="text-2xl font-bold text-gray-800">Nhập hàng</div>

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
                        placeholder="Theo mã phiếu nhập hàng, mã đặt hàng, ncc"
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
                        href="/purchases/create"
                        class="bg-white text-green-600 border border-green-600 px-3 py-1.5 text-sm font-medium rounded hover:bg-green-50 transition flex items-center gap-1"
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
                        Nhập hàng
                    </Link>
                    <ExcelButtons export-url="/purchases/export" />
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
                            <th class="px-2 py-2">Mã nhập hàng</th>
                            <th class="px-2 py-2">Thời gian</th>
                            <th class="px-2 py-2">Nhà cung cấp</th>
                            <th class="px-4 py-2 text-right">Tổng cộng</th>
                            <th class="px-4 py-2 text-right">Cần trả NCC</th>
                            <th class="px-4 py-2 text-right">Đã trả NCC</th>
                            <th class="px-4 py-2 text-right">Còn nợ</th>
                            <th class="px-4 py-2 text-center w-24">
                                Trạng thái
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <!-- Summary row -->
                        <tr class="bg-gray-50 border-b border-gray-200 font-semibold text-sm">
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td class="px-4 py-2 text-right text-gray-700">{{ formatCurrency(summary?.total_amount) }}</td>
                            <td class="px-4 py-2 text-right text-gray-700">{{ formatCurrency((summary?.total_amount || 0) - (summary?.total_discount || 0)) }}</td>
                            <td class="px-4 py-2 text-right text-green-600">{{ formatCurrency(summary?.total_paid) }}</td>
                            <td class="px-4 py-2 text-right text-red-600">{{ formatCurrency(summary?.total_debt) }}</td>
                            <td></td>
                        </tr>
                        <tr v-if="purchases.data.length === 0">
                            <td
                                colspan="10"
                                class="p-16 text-center text-gray-500"
                            >
                                <div
                                    class="flex flex-col items-center justify-center"
                                >
                                    <h3
                                        class="text-[15px] font-bold text-gray-800 mb-1"
                                    >
                                        Không tìm thấy kết quả
                                    </h3>
                                    <p class="text-[13px]">
                                        Không tìm thấy phiếu nhập hàng nào phù
                                        hợp trong tháng này.
                                    </p>
                                </div>
                            </td>
                        </tr>
                        <template
                            v-for="order in purchases.data"
                            :key="order.id"
                        >
                            <tr
                                @click="goToDetail(order.id)"
                                class="hover:bg-blue-50/40 cursor-pointer transition-colors"
                                :class="{
                                    'bg-[#f4f7fe]': isExpanded(order.id),
                                    'border-l-2 border-l-green-500': isExpanded(
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
                                <td class="px-2 py-2 text-blue-600 font-medium">
                                    {{ order.code }}
                                </td>
                                <td class="px-2 py-2">
                                    {{
                                        new Date(
                                            order.purchase_date || order.created_at,
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
                                <td class="px-4 py-2 text-right font-medium">
                                    {{ formatCurrency(order.total_amount) }}
                                </td>
                                <td class="px-4 py-2 text-right font-medium">
                                    {{
                                        formatCurrency(
                                            order.total_amount - order.discount,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-4 py-2 text-right font-medium text-green-600"
                                >
                                    {{ formatCurrency(order.paid_amount) }}
                                </td>
                                <td class="px-4 py-2 text-right font-medium" :class="order.debt_amount > 0 ? 'text-red-600' : 'text-gray-400'">
                                    {{ formatCurrency(order.debt_amount) }}
                                </td>
                                <td class="px-4 py-2 text-center">
                                    <span
                                        class="inline-block px-2 text-[11px] py-0.5 rounded border font-medium"
                                        :class="{
                                            'bg-green-50 text-green-700 border-green-200': order.status === 'completed',
                                            'bg-gray-50 text-gray-500 border-gray-200': order.status === 'draft',
                                            'bg-orange-50 text-orange-600 border-orange-200': order.status === 'returned',
                                            'bg-red-50 text-red-600 border-red-200': order.status === 'cancelled',
                                        }"
                                    >
                                        {{ formatStatus(order.status) }}
                                    </span>
                                </td>
                            </tr>
                            <tr
                                v-if="isExpanded(order.id)"
                                class="border-b-4 border-blue-50"
                            >
                                <td
                                    colspan="10"
                                    class="p-0 border-0 bg-white shadow-[inset_0_2px_4px_rgba(0,0,0,0.02)]"
                                >
                                    <div
                                        class="p-4 flex gap-6 w-full border-t border-blue-100"
                                    >
                                        <div class="flex-1">
                                            <table
                                                class="w-full text-left bg-gray-50/50 border border-gray-200"
                                            >
                                                <thead
                                                    class="text-gray-500 bg-gray-100 border-b border-gray-200"
                                                >
                                                    <tr>
                                                        <th
                                                            class="p-2 font-medium w-12 text-center"
                                                        >
                                                            STT
                                                        </th>
                                                        <th
                                                            class="p-2 font-medium"
                                                        >
                                                            Mã hàng
                                                        </th>
                                                        <th
                                                            class="p-2 font-medium"
                                                        >
                                                            Tên hàng hóa
                                                        </th>
                                                        <th
                                                            class="p-2 font-medium text-center"
                                                        >
                                                            Số lượng
                                                        </th>
                                                        <th
                                                            class="p-2 font-medium text-right"
                                                        >
                                                            Đơn giá
                                                        </th>
                                                        <th
                                                            class="p-2 font-medium text-right"
                                                        >
                                                            Giảm giá
                                                        </th>
                                                        <th
                                                            class="p-2 font-medium text-right pr-4"
                                                        >
                                                            Thành tiền
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody
                                                    class="divide-y divide-gray-200 border-b border-gray-200"
                                                >
                                                    <tr
                                                        v-for="(
                                                            item, i
                                                        ) in order.items"
                                                        :key="item.id"
                                                    >
                                                        <td
                                                            class="p-2 text-center text-gray-400"
                                                        >
                                                            {{ i + 1 }}
                                                        </td>
                                                        <td
                                                            class="p-2 text-blue-600"
                                                        >
                                                            {{
                                                                item.product_code
                                                            }}
                                                        </td>
                                                        <td
                                                            class="p-2 font-medium"
                                                        >
                                                            {{
                                                                item.product_name
                                                            }}
                                                        </td>
                                                        <td
                                                            class="p-2 text-center font-bold"
                                                        >
                                                            {{ item.quantity }}
                                                        </td>
                                                        <td
                                                            class="p-2 text-right"
                                                        >
                                                            {{
                                                                formatCurrency(
                                                                    item.price,
                                                                )
                                                            }}
                                                        </td>
                                                        <td
                                                            class="p-2 text-right"
                                                        >
                                                            {{
                                                                formatCurrency(
                                                                    item.discount,
                                                                )
                                                            }}
                                                        </td>
                                                        <td
                                                            class="p-2 text-right pr-4"
                                                        >
                                                            {{
                                                                formatCurrency(
                                                                    item.subtotal,
                                                                )
                                                            }}
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div
                                            class="w-80 border-l border-gray-200 pl-4 py-2 space-y-2 text-[13.5px]"
                                        >
                                            <div
                                                class="flex justify-between items-center"
                                            >
                                                <span class="text-gray-500"
                                                    >Mã phiếu nhập:</span
                                                >
                                                <strong>{{
                                                    order.code
                                                }}</strong>
                                            </div>
                                            <div
                                                class="flex justify-between items-center"
                                            >
                                                <span class="text-gray-500"
                                                    >Thời gian:</span
                                                >
                                                <span>{{
                                                    new Date(
                                                        order.created_at,
                                                    ).toLocaleString("vi-VN")
                                                }}</span>
                                            </div>
                                            <div
                                                class="flex justify-between items-center"
                                            >
                                                <span class="text-gray-500"
                                                    >Trạng thái:</span
                                                >
                                                <span
                                                    class="font-bold"
                                                    :class="{
                                                        'text-green-600': order.status === 'completed',
                                                        'text-gray-500': order.status === 'draft',
                                                        'text-orange-600': order.status === 'returned',
                                                        'text-red-600': order.status === 'cancelled',
                                                    }"
                                                    >{{ formatStatus(order.status) }}</span
                                                >
                                            </div>
                                            <div
                                                class="flex justify-between items-center"
                                            >
                                                <span class="text-gray-500"
                                                    >Ghi chú:</span
                                                >
                                                <span>{{
                                                    order.note || "Không"
                                                }}</span>
                                            </div>
                                            <div
                                                class="border-t border-gray-200 pt-3 mt-3 flex gap-2"
                                            >
                                                <button
                                                    v-if="order.status !== 'cancelled'"
                                                    class="bg-blue-600 text-white px-4 py-1.5 rounded font-medium hover:bg-blue-700 w-full"
                                                >
                                                    Thanh toán
                                                </button>
                                                <button
                                                    @click.stop="
                                                        printPurchase(order)
                                                    "
                                                    class="bg-gray-100 text-gray-600 px-4 py-1.5 rounded font-medium hover:bg-gray-200 w-full border border-gray-300"
                                                >
                                                    In
                                                </button>
                                                <button
                                                    v-if="order.status === 'completed'"
                                                    @click.stop="cancelPurchase(order)"
                                                    class="bg-red-500 text-white px-4 py-1.5 rounded font-medium hover:bg-red-600 w-full flex items-center justify-center gap-1"
                                                >
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                                    Hủy
                                                </button>
                                                <button
                                                    v-if="order.status === 'draft'"
                                                    @click.stop="cancelPurchase(order)"
                                                    class="bg-red-500 text-white px-4 py-1.5 rounded font-medium hover:bg-red-600 w-full flex items-center justify-center gap-1"
                                                >
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                                    Xóa
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
                    <span class="font-bold">{{ purchases.from || 0 }}</span> đến
                    <span class="font-bold">{{ purchases.to || 0 }}</span> trong
                    tổng số
                    <span class="font-bold">{{ purchases.total || 0 }}</span>
                    phiếu
                </div>
                <!-- Pagination -->
                <div
                    class="flex gap-1"
                    v-if="purchases.links && purchases.links.length > 3"
                >
                    <template
                        v-for="(link, index) in purchases.links"
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
