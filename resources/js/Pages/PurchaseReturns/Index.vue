<script setup>
import { ref, watch } from "vue";
import { Head, router, Link, usePage } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import SortableHeader from "@/Components/SortableHeader.vue";

const page = usePage();
const props = defineProps({
    returns: Object,
    filters: Object,
    summary: Object,
});

const search = ref(props.filters?.search || "");
const statusFilters = ref(props.filters?.status || []);
const dateFilter = ref(props.filters?.date_filter || "this_month");
const sortBy = ref(props.filters?.sort_by || "");
const sortDirection = ref(props.filters?.sort_direction || "");

const allStatuses = [
    { value: "draft", label: "Phiếu tạm" },
    { value: "completed", label: "Đã trả hàng" },
    { value: "cancelled", label: "Đã hủy" },
];

let searchTimeout;
const updateFilters = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            "/purchase-returns",
            {
                search: search.value,
                status: statusFilters.value,
                date_filter: dateFilter.value,
                sort_by: sortBy.value || undefined,
                sort_direction: sortDirection.value || undefined,
            },
            { preserveState: true, replace: true },
        );
    }, 500);
};

watch([search, statusFilters, dateFilter], updateFilters, { deep: true });

const handleSort = (field, direction) => {
    sortBy.value = field;
    sortDirection.value = direction;
    updateFilters();
};

const removeStatusFilter = (val) => {
    const idx = statusFilters.value.indexOf(val);
    if (idx > -1) statusFilters.value.splice(idx, 1);
};

const expandedRows = ref([]);
const toggleExpand = (id) => {
    const index = expandedRows.value.indexOf(id);
    if (index > -1) expandedRows.value.splice(index, 1);
    else expandedRows.value.push(id);
};
const isExpanded = (id) => expandedRows.value.includes(id);

const formatCurrency = (val) => Number(val || 0).toLocaleString("vi-VN");
const formatStatus = (val) => {
    const s = allStatuses.find((x) => x.value === val);
    return s ? s.label : val;
};
const statusClass = (status) => ({
    'bg-green-50 text-green-700 border-green-200': status === 'completed',
    'bg-gray-50 text-gray-500 border-gray-200': status === 'draft',
    'bg-red-50 text-red-600 border-red-200': status === 'cancelled',
});

const cancelReturn = (ret) => {
    if (!confirm(`Bạn có chắc muốn hủy phiếu trả hàng ${ret.code}? Tồn kho và công nợ sẽ được hoàn lại.`)) return;
    router.delete(`/purchase-returns/${ret.id}`, { preserveState: false });
};
</script>

<template>
    <Head title="Trả hàng nhập - KiotViet Clone" />
    <AppLayout>
        <template #sidebar>
            <!-- Chi nhánh -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2">Chi nhánh</label>
                <select class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none bg-blue-600 text-white font-medium appearance-none h-[32px]">
                    <option value="">Chi nhánh trung tâm</option>
                </select>
            </div>

            <!-- Trạng thái -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2">Trạng thái</label>
                <div class="flex flex-wrap gap-2 text-[12px]">
                    <div v-for="status in statusFilters" :key="status"
                        class="bg-green-600 text-white px-2 py-1 rounded flex items-center gap-1 cursor-pointer">
                        {{ formatStatus(status) }}
                        <span @click.stop="removeStatusFilter(status)" class="pl-1 border-l border-green-400 font-bold hover:text-gray-200 ml-1">&times;</span>
                    </div>
                </div>
                <div class="mt-2 space-y-1">
                    <label v-for="s in allStatuses" :key="s.value" class="flex items-center gap-2 cursor-pointer text-sm text-gray-700">
                        <input type="checkbox" :value="s.value" v-model="statusFilters" class="rounded border-gray-300 text-green-600 focus:ring-green-500 w-4 h-4" />
                        {{ s.label }}
                    </label>
                </div>
            </div>

            <!-- Thời gian -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2">Thời gian</label>
                <div class="space-y-2 text-sm text-gray-700">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" v-model="dateFilter" value="this_month" class="text-green-600 focus:ring-green-500 w-4 h-4" />
                        Tháng này
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer text-gray-500">
                        <input type="radio" v-model="dateFilter" value="custom" class="text-green-600 focus:ring-green-500 w-4 h-4" />
                        Tùy chỉnh
                    </label>
                </div>
            </div>

            <!-- Người tạo -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2">Người tạo</label>
                <input type="text" placeholder="Chọn người tạo" class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none text-gray-700 bg-gray-50" />
            </div>

            <!-- Người trả -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2">Người trả</label>
                <input type="text" placeholder="Chọn người trả" class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none text-gray-700 bg-gray-50" />
            </div>
        </template>

        <div class="bg-white h-full flex flex-col pt-3">
            <!-- Flash messages -->
            <div v-if="page.props.flash?.success" class="mx-4 mb-2 bg-green-100 border border-green-400 text-green-700 px-4 py-2 rounded text-sm">
                {{ page.props.flash.success }}
            </div>
            <div v-if="page.props.flash?.error" class="mx-4 mb-2 bg-red-100 border border-red-400 text-red-700 px-4 py-2 rounded text-sm">
                {{ page.props.flash.error }}
            </div>

            <!-- Header -->
            <div class="flex items-center justify-between px-4 pb-3 border-b border-gray-200">
                <div class="text-2xl font-bold text-gray-800">Trả hàng nhập</div>

                <div class="flex-1 max-w-[400px] ml-6 relative">
                    <svg class="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    <input type="text" v-model="search" placeholder="Theo mã phiếu trả"
                        class="w-full pl-9 pr-3 py-1.5 focus:outline-none border border-gray-300 rounded text-sm placeholder-gray-400" />
                </div>

                <div class="flex gap-2 ml-auto">
                    <ExcelButtons export-url="/purchase-returns/export" />
                    <Link href="/purchases" class="bg-white text-green-600 border border-green-600 px-3 py-1.5 text-sm font-medium rounded hover:bg-green-50 transition flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        Trả hàng nhập
                    </Link>
                </div>
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-auto bg-gray-50/30">
                <table class="w-full text-[13px] text-left whitespace-nowrap">
                    <thead class="font-bold text-gray-700 bg-[#f4f6f8] border-b border-gray-200 sticky top-0 z-10 shadow-sm">
                        <tr>
                            <th class="px-3 py-2 w-10 text-center">
                                <input type="checkbox" class="rounded border-gray-300" />
                            </th>
                            <th class="px-3 py-2 text-center w-10">
                                <svg class="w-4 h-4 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path>
                                </svg>
                            </th>
                            <SortableHeader label="Mã trả hàng nhập" field="code" :current-sort="sortBy" :current-direction="sortDirection" class="px-2 py-2" @sort="handleSort" />
                            <SortableHeader label="Thời gian" field="return_date" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" class="px-2 py-2" @sort="handleSort" />
                            <th class="px-2 py-2">Nhà cung cấp</th>
                            <SortableHeader label="Tổng tiền hàng" field="total_amount" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <SortableHeader label="NCC cần trả" field="refund_amount" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="px-4 py-2 text-right" @sort="handleSort" />
                            <th class="px-4 py-2 text-right">NCC đã trả</th>
                            <SortableHeader label="Trạng thái" field="status" :current-sort="sortBy" :current-direction="sortDirection" align="center" class="px-4 py-2 text-center w-28" @sort="handleSort" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <!-- Summary row -->
                        <tr v-if="summary" class="bg-gray-50 border-b border-gray-200 font-semibold text-sm">
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td class="px-4 py-2 text-right text-gray-700">{{ formatCurrency(summary.total_amount) }}</td>
                            <td class="px-4 py-2 text-right text-gray-700">{{ formatCurrency(summary.total_refund) }}</td>
                            <td class="px-4 py-2 text-right text-green-600">{{ formatCurrency(summary.total_refunded) }}</td>
                            <td></td>
                        </tr>
                        <tr v-if="returns.data.length === 0">
                            <td colspan="9" class="p-16 text-center text-gray-500">
                                <h3 class="text-[15px] font-bold text-gray-800 mb-1">Không tìm thấy kết quả</h3>
                                <p class="text-[13px]">Không tìm thấy phiếu trả hàng nhập nào phù hợp.</p>
                            </td>
                        </tr>
                        <template v-for="ret in returns.data" :key="ret.id">
                            <tr @click="toggleExpand(ret.id)"
                                class="hover:bg-blue-50/40 cursor-pointer transition-colors"
                                :class="{ 'bg-[#f4f7fe]': isExpanded(ret.id), 'border-l-2 border-l-orange-500': isExpanded(ret.id) }">
                                <td class="px-3 py-2 text-center" @click.stop>
                                    <input type="checkbox" class="rounded border-gray-300" />
                                </td>
                                <td class="px-3 py-2 text-center text-gray-300">
                                    <svg class="w-4 h-4 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path>
                                    </svg>
                                </td>
                                <td class="px-2 py-2 text-blue-600 font-medium">{{ ret.code }}</td>
                                <td class="px-2 py-2">
                                    {{ new Date(ret.return_date || ret.created_at).toLocaleString("vi-VN", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" }) }}
                                </td>
                                <td class="px-2 py-2">{{ ret.supplier?.name || "—" }}</td>
                                <td class="px-4 py-2 text-right font-medium">{{ formatCurrency(ret.total_amount) }}</td>
                                <td class="px-4 py-2 text-right font-medium">{{ formatCurrency(ret.refund_amount) }}</td>
                                <td class="px-4 py-2 text-right font-medium text-green-600">{{ ret.status === 'completed' ? formatCurrency(ret.refund_amount) : '0' }}</td>
                                <td class="px-4 py-2 text-center">
                                    <span class="inline-block px-2 text-[11px] py-0.5 rounded border font-medium" :class="statusClass(ret.status)">
                                        {{ formatStatus(ret.status) }}
                                    </span>
                                </td>
                            </tr>
                            <!-- Expanded detail row -->
                            <tr v-if="isExpanded(ret.id)" class="border-b-4 border-orange-50">
                                <td colspan="9" class="p-0 border-0 bg-white shadow-[inset_0_2px_4px_rgba(0,0,0,0.02)]">
                                    <div class="border-t border-orange-100">
                                        <!-- Tab header -->
                                        <div class="px-4 pt-3 border-b border-gray-200">
                                            <span class="inline-block px-3 py-1.5 text-sm font-medium text-blue-600 border-b-2 border-blue-600 -mb-px">Thông tin</span>
                                        </div>

                                        <div class="p-4 flex gap-6 w-full">
                                            <div class="flex-1">
                                                <!-- Return header info -->
                                                <div class="flex items-center gap-3 mb-4">
                                                    <span class="text-lg font-bold text-gray-800">{{ ret.code }}</span>
                                                    <span class="inline-block px-2 text-[11px] py-0.5 rounded border font-medium" :class="statusClass(ret.status)">
                                                        {{ formatStatus(ret.status) }}
                                                    </span>
                                                </div>

                                                <!-- Meta info -->
                                                <div class="flex flex-wrap gap-x-8 gap-y-2 text-[13px] mb-4 text-gray-600">
                                                    <div>
                                                        <span class="text-gray-400">Người tạo:</span>
                                                        <span class="ml-1.5 font-medium">{{ ret.user?.name || '—' }}</span>
                                                    </div>
                                                    <div>
                                                        <span class="text-gray-400">Tên NCC:</span>
                                                        <span class="ml-1.5 font-medium text-blue-600">{{ ret.supplier?.name || '—' }}</span>
                                                    </div>
                                                    <div>
                                                        <span class="text-gray-400">Phiếu nhập:</span>
                                                        <Link v-if="ret.purchase" :href="'/purchases/' + ret.purchase.id" class="ml-1.5 font-medium text-blue-600 hover:underline">{{ ret.purchase.code }}</Link>
                                                        <span v-else class="ml-1.5">—</span>
                                                    </div>
                                                    <div>
                                                        <span class="text-gray-400">Ngày trả:</span>
                                                        <span class="ml-1.5">{{ new Date(ret.return_date || ret.created_at).toLocaleString("vi-VN") }}</span>
                                                    </div>
                                                </div>

                                                <!-- Items table -->
                                                <table class="w-full text-left bg-gray-50/50 border border-gray-200">
                                                    <thead class="text-gray-500 bg-gray-100 border-b border-gray-200">
                                                        <tr>
                                                            <th class="p-2 font-medium">Mã hàng</th>
                                                            <th class="p-2 font-medium">Tên hàng</th>
                                                            <th class="p-2 font-medium text-center">Số lượng</th>
                                                            <th class="p-2 font-medium text-right">Giá nhập</th>
                                                            <th class="p-2 font-medium text-right">Giá trả lại</th>
                                                            <th class="p-2 font-medium text-right pr-4">Thành tiền</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-200 border-b border-gray-200">
                                                        <tr v-for="item in ret.items" :key="item.id">
                                                            <td class="p-2 text-blue-600">{{ item.product_code }}</td>
                                                            <td class="p-2 font-medium">
                                                                {{ item.product_name }}
                                                                <span v-if="item.product?.has_serial" class="ml-1 text-[10px] text-orange-500 bg-orange-50 border border-orange-200 rounded px-1 py-0.5">Serial/IMEI</span>
                                                            </td>
                                                            <td class="p-2 text-center font-bold">{{ item.quantity }}</td>
                                                            <td class="p-2 text-right">{{ formatCurrency(item.price) }}</td>
                                                            <td class="p-2 text-right">{{ formatCurrency(item.price) }}</td>
                                                            <td class="p-2 text-right pr-4 font-medium">{{ formatCurrency(item.subtotal) }}</td>
                                                        </tr>
                                                    </tbody>
                                                </table>

                                                <!-- Note -->
                                                <div v-if="ret.note" class="mt-3 text-[13px] text-gray-500">
                                                    <span class="text-gray-400">Ghi chú:</span> {{ ret.note }}
                                                </div>
                                            </div>

                                            <!-- Right summary panel -->
                                            <div class="w-72 border-l border-gray-200 pl-4 py-2 space-y-2 text-[13.5px]">
                                                <div class="flex justify-between items-center">
                                                    <span class="text-gray-500">Số lượng mặt hàng</span>
                                                    <span class="font-bold text-blue-600">{{ ret.items?.length || 0 }}</span>
                                                </div>
                                                <div class="flex justify-between items-center">
                                                    <span class="text-gray-500">Tổng tiền hàng ({{ ret.items?.reduce((s, i) => s + i.quantity, 0) || 0 }})</span>
                                                    <span class="font-bold">{{ formatCurrency(ret.total_amount) }}</span>
                                                </div>
                                                <div class="border-t border-gray-200 pt-2 flex justify-between items-center">
                                                    <span class="text-gray-500">NCC cần trả</span>
                                                    <span class="font-bold">{{ formatCurrency(ret.refund_amount) }}</span>
                                                </div>
                                                <div class="flex justify-between items-center">
                                                    <span class="text-gray-500">NCC đã trả</span>
                                                    <span class="font-bold text-green-600">{{ ret.status === 'completed' ? formatCurrency(ret.refund_amount) : '0' }}</span>
                                                </div>

                                                <!-- Actions -->
                                                <div class="border-t border-gray-200 pt-3 mt-3 flex gap-2">
                                                    <button v-if="ret.status === 'completed'" @click.stop="cancelReturn(ret)"
                                                        class="bg-white text-gray-600 border border-gray-300 px-3 py-1.5 rounded font-medium hover:bg-gray-50 flex items-center gap-1 text-[12px]">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                                        Hủy
                                                    </button>
                                                    <button @click.stop="window.open('/purchase-returns/' + ret.id + '/print', '_blank', 'width=400,height=600')"
                                                        class="bg-white text-gray-600 border border-gray-300 px-3 py-1.5 rounded font-medium hover:bg-gray-50 flex items-center gap-1 text-[12px] ml-auto">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
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
            <div class="flex items-center justify-between p-3 border-t border-gray-200 bg-gray-50/50 text-sm flex-shrink-0">
                <div class="text-gray-600">
                    Hiển thị từ <span class="font-bold">{{ returns.from || 0 }}</span> đến
                    <span class="font-bold">{{ returns.to || 0 }}</span> trong tổng số
                    <span class="font-bold">{{ returns.total || 0 }}</span> phiếu
                </div>
                <div class="flex gap-1" v-if="returns.links && returns.links.length > 3">
                    <template v-for="(link, index) in returns.links" :key="index">
                        <Link v-if="link.url" :href="link.url"
                            class="px-2.5 py-1 text-sm border rounded"
                            :class="link.active ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300'"
                            v-html="link.label" />
                        <span v-else class="px-2.5 py-1 text-sm border rounded bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed" v-html="link.label" />
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
