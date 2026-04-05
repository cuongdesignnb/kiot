<script setup>
import { ref, computed } from "vue";
import { router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ReportSidebar from "@/Components/ReportSidebar.vue";

const props = defineProps({
    filters: Object,
    rows: Array,
    summary: Object,
    branches: Array,
});

const branchId = ref(props.filters?.branch_id || "");
const dateFrom = ref(props.filters?.date_from || "");
const dateTo = ref(props.filters?.date_to || "");
const partnerType = ref(props.filters?.partner_type || "all");
const search = ref(props.filters?.search || "");
const sortField = ref("net");
const sortDir = ref("desc");

const applyFilter = () => {
    router.get(
        "/reports/debt-reconciliation",
        {
            branch_id: branchId.value || undefined,
            date_from: dateFrom.value || undefined,
            date_to: dateTo.value || undefined,
            partner_type: partnerType.value !== "all" ? partnerType.value : undefined,
            search: search.value || undefined,
        },
        { preserveState: true }
    );
};

let searchTimeout = null;
const onSearchInput = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilter, 400);
};

const sortedRows = computed(() => {
    const field = sortField.value;
    const dir = sortDir.value === "asc" ? 1 : -1;
    return [...(props.rows || [])].sort((a, b) => {
        const va = a[field] ?? 0;
        const vb = b[field] ?? 0;
        if (typeof va === "string") return va.localeCompare(vb) * dir;
        return (va - vb) * dir;
    });
});

const toggleSort = (field) => {
    if (sortField.value === field) {
        sortDir.value = sortDir.value === "asc" ? "desc" : "asc";
    } else {
        sortField.value = field;
        sortDir.value = "desc";
    }
};

const sortIcon = (field) => {
    if (sortField.value !== field) return "↕";
    return sortDir.value === "asc" ? "↑" : "↓";
};

const formatCurrency = (n) => new Intl.NumberFormat("vi-VN").format(Math.round(n || 0));

const statusLabel = (status) => {
    const map = {
        clear: "Hết nợ",
        balanced: "Cân bằng",
        receivable: "Phải thu ròng",
        payable: "Phải trả ròng",
    };
    return map[status] || status;
};

const statusClass = (status) => {
    const map = {
        clear: "bg-green-100 text-green-700",
        balanced: "bg-blue-100 text-blue-700",
        receivable: "bg-red-100 text-red-700",
        payable: "bg-orange-100 text-orange-700",
    };
    return map[status] || "bg-gray-100 text-gray-700";
};

const roleLabel = (row) => {
    if (row.is_customer && row.is_supplier) return "KH + NCC";
    if (row.is_customer) return "Khách hàng";
    return "Nhà cung cấp";
};

const exportCsv = () => {
    const params = new URLSearchParams();
    if (branchId.value) params.set("branch_id", branchId.value);
    if (dateFrom.value) params.set("date_from", dateFrom.value);
    if (dateTo.value) params.set("date_to", dateTo.value);
    window.location.href = `/reports/debt-reconciliation/export?${params.toString()}`;
};
</script>

<template>
    <AppLayout>
        <template #sidebar>
            <ReportSidebar currentPage="debt-reconciliation" />
        </template>

        <div class="max-w-[1400px] mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-5">
                <h1 class="text-xl font-bold text-gray-800">Đối soát công nợ hai chiều</h1>
                <button
                    @click="exportCsv"
                    class="flex items-center gap-1.5 px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Xuất CSV
                </button>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg border border-gray-200 p-4 mb-5 flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Chi nhánh</label>
                    <select v-model="branchId" @change="applyFilter" class="border border-gray-300 rounded px-3 py-1.5 text-sm w-44">
                        <option value="">Tất cả</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Từ ngày</label>
                    <input type="date" v-model="dateFrom" @change="applyFilter" class="border border-gray-300 rounded px-3 py-1.5 text-sm w-40" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Đến ngày</label>
                    <input type="date" v-model="dateTo" @change="applyFilter" class="border border-gray-300 rounded px-3 py-1.5 text-sm w-40" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Loại đối tác</label>
                    <select v-model="partnerType" @change="applyFilter" class="border border-gray-300 rounded px-3 py-1.5 text-sm w-44">
                        <option value="all">Tất cả</option>
                        <option value="dual">KH + NCC</option>
                        <option value="customer_only">Chỉ khách hàng</option>
                        <option value="supplier_only">Chỉ nhà cung cấp</option>
                    </select>
                </div>
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs text-gray-500 mb-1">Tìm kiếm</label>
                    <input v-model="search" @input="onSearchInput" type="text" placeholder="Mã, tên, SĐT..." class="border border-gray-300 rounded px-3 py-1.5 text-sm w-full" />
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <div class="text-xs text-gray-500 mb-1">Tổng đối tác</div>
                    <div class="text-2xl font-bold text-gray-800">{{ summary?.total_partners || 0 }}</div>
                    <div class="flex gap-2 mt-2 text-xs">
                        <span class="text-green-600">{{ summary?.clear_count || 0 }} hết nợ</span>
                        <span class="text-blue-600">{{ summary?.balanced_count || 0 }} cân bằng</span>
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-red-200 p-4">
                    <div class="text-xs text-gray-500 mb-1">Tổng nợ phải thu</div>
                    <div class="text-2xl font-bold text-red-600">{{ formatCurrency(summary?.total_receivable) }}</div>
                    <div class="text-xs text-gray-500 mt-2">{{ summary?.receivable_count || 0 }} đối tác phải thu ròng</div>
                </div>
                <div class="bg-white rounded-lg border border-orange-200 p-4">
                    <div class="text-xs text-gray-500 mb-1">Tổng nợ phải trả</div>
                    <div class="text-2xl font-bold text-orange-600">{{ formatCurrency(summary?.total_payable) }}</div>
                    <div class="text-xs text-gray-500 mt-2">{{ summary?.payable_count || 0 }} đối tác phải trả ròng</div>
                </div>
                <div class="bg-white rounded-lg border border-purple-200 p-4">
                    <div class="text-xs text-gray-500 mb-1">Tổng đã cấn bằng</div>
                    <div class="text-2xl font-bold text-purple-600">{{ formatCurrency(summary?.total_offset_amount) }}</div>
                    <div class="flex gap-2 mt-2 text-xs">
                        <span class="text-gray-500">Tự động: {{ formatCurrency(summary?.total_auto_offset) }}</span>
                        <span class="text-purple-600">Thủ công: {{ formatCurrency(summary?.total_manual_offset) }}</span>
                    </div>
                </div>
            </div>

            <!-- Net balance card -->
            <div class="bg-gradient-to-r from-blue-50 to-purple-50 rounded-lg border border-blue-200 p-4 mb-5 flex items-center justify-between">
                <div>
                    <span class="text-sm text-gray-600">Công nợ ròng (Phải thu - Phải trả):</span>
                    <span class="ml-3 text-xl font-bold" :class="(summary?.total_net || 0) >= 0 ? 'text-blue-700' : 'text-orange-700'">
                        {{ (summary?.total_net || 0) >= 0 ? '+' : '' }}{{ formatCurrency(summary?.total_net) }}
                    </span>
                </div>
                <div v-if="summary?.total_cancelled" class="text-xs text-gray-500">
                    Đã hủy cấn bằng: {{ formatCurrency(summary?.total_cancelled) }}
                </div>
            </div>

            <!-- Data Table -->
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-200">
                                <th class="text-left px-4 py-3 font-semibold text-gray-600 cursor-pointer hover:text-gray-800" @click="toggleSort('code')">
                                    Mã <span class="text-xs">{{ sortIcon('code') }}</span>
                                </th>
                                <th class="text-left px-4 py-3 font-semibold text-gray-600 cursor-pointer hover:text-gray-800" @click="toggleSort('name')">
                                    Đối tác <span class="text-xs">{{ sortIcon('name') }}</span>
                                </th>
                                <th class="text-center px-4 py-3 font-semibold text-gray-600">Vai trò</th>
                                <th class="text-right px-4 py-3 font-semibold text-gray-600 cursor-pointer hover:text-gray-800" @click="toggleSort('receivable')">
                                    Nợ phải thu <span class="text-xs">{{ sortIcon('receivable') }}</span>
                                </th>
                                <th class="text-right px-4 py-3 font-semibold text-gray-600 cursor-pointer hover:text-gray-800" @click="toggleSort('payable')">
                                    Nợ phải trả <span class="text-xs">{{ sortIcon('payable') }}</span>
                                </th>
                                <th class="text-right px-4 py-3 font-semibold text-gray-600 cursor-pointer hover:text-gray-800" @click="toggleSort('total_offset')">
                                    Đã cấn bằng <span class="text-xs">{{ sortIcon('total_offset') }}</span>
                                </th>
                                <th class="text-right px-4 py-3 font-semibold text-gray-600 cursor-pointer hover:text-gray-800" @click="toggleSort('net')">
                                    Còn lại <span class="text-xs">{{ sortIcon('net') }}</span>
                                </th>
                                <th class="text-center px-4 py-3 font-semibold text-gray-600">Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!sortedRows.length">
                                <td colspan="8" class="text-center py-10 text-gray-400">Không có dữ liệu</td>
                            </tr>
                            <tr v-for="row in sortedRows" :key="row.id" class="border-b border-gray-100 hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 text-blue-600 font-medium">{{ row.code }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-800">{{ row.name }}</div>
                                    <div v-if="row.phone" class="text-xs text-gray-500">{{ row.phone }}</div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span
                                        class="inline-block px-2 py-0.5 rounded text-xs font-medium"
                                        :class="row.is_customer && row.is_supplier ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600'"
                                    >
                                        {{ roleLabel(row) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right font-medium" :class="row.receivable > 0 ? 'text-red-600' : 'text-gray-400'">
                                    {{ formatCurrency(row.receivable) }}
                                </td>
                                <td class="px-4 py-3 text-right font-medium" :class="row.payable > 0 ? 'text-orange-600' : 'text-gray-400'">
                                    {{ formatCurrency(row.payable) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="font-medium text-purple-600">{{ formatCurrency(row.total_offset) }}</div>
                                    <div v-if="row.offset_count" class="text-xs text-gray-400">{{ row.offset_count }} lần</div>
                                </td>
                                <td class="px-4 py-3 text-right font-bold" :class="row.net >= 0 ? 'text-blue-600' : 'text-orange-600'">
                                    {{ row.net >= 0 ? '+' : '' }}{{ formatCurrency(row.net) }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-block px-2 py-0.5 rounded text-xs font-medium" :class="statusClass(row.status)">
                                        {{ statusLabel(row.status) }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                        <!-- Summary footer -->
                        <tfoot v-if="sortedRows.length">
                            <tr class="bg-gray-50 border-t-2 border-gray-300 font-bold">
                                <td class="px-4 py-3" colspan="3">Tổng cộng ({{ summary?.total_partners || 0 }} đối tác)</td>
                                <td class="px-4 py-3 text-right text-red-600">{{ formatCurrency(summary?.total_receivable) }}</td>
                                <td class="px-4 py-3 text-right text-orange-600">{{ formatCurrency(summary?.total_payable) }}</td>
                                <td class="px-4 py-3 text-right text-purple-600">{{ formatCurrency(summary?.total_offset_amount) }}</td>
                                <td class="px-4 py-3 text-right" :class="(summary?.total_net || 0) >= 0 ? 'text-blue-600' : 'text-orange-600'">
                                    {{ (summary?.total_net || 0) >= 0 ? '+' : '' }}{{ formatCurrency(summary?.total_net) }}
                                </td>
                                <td class="px-4 py-3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
