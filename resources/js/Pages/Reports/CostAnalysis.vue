<script setup>
import { formatVND as fmt } from '@/utils/money';
import { ref, computed } from "vue";
import { router, Link } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ReportSidebar from "@/Components/ReportSidebar.vue";

const props = defineProps({
    rows: Array,
    summary: Object,
    filters: Object,
});

const search = ref(props.filters?.search || "");
const thresholdPct = ref(props.filters?.threshold_pct ?? 1);
const onlyMismatch = ref(!!props.filters?.only_mismatch);
const sortField = ref("diff_pct");
const sortDir = ref("desc");

const apply = () => {
    router.get(
        "/reports/cost-analysis",
        {
            search: search.value || undefined,
            threshold_pct: thresholdPct.value || undefined,
            only_mismatch: onlyMismatch.value ? 1 : undefined,
        },
        { preserveState: true, preserveScroll: true }
    );
};

let timer = null;
const onSearchInput = () => {
    clearTimeout(timer);
    timer = setTimeout(apply, 400);
};


const sortedRows = computed(() => {
    const f = sortField.value;
    const d = sortDir.value === "asc" ? 1 : -1;
    return [...(props.rows || [])].sort((a, b) => {
        const va = a[f] ?? 0;
        const vb = b[f] ?? 0;
        if (typeof va === "string") return va.localeCompare(vb) * d;
        return (va - vb) * d;
    });
});

const toggleSort = (f) => {
    if (sortField.value === f) {
        sortDir.value = sortDir.value === "asc" ? "desc" : "asc";
    } else {
        sortField.value = f;
        sortDir.value = "desc";
    }
};

const sortIcon = (f) =>
    sortField.value !== f ? "↕" : sortDir.value === "asc" ? "↑" : "↓";

const statusLabel = (s) =>
    ({
        ok: "Khớp",
        mismatch: "Lệch",
        no_in_stock_serial: "Hết serial tồn",
        empty: "Không có serial",
    }[s] || s);

const statusClass = (s) =>
    ({
        ok: "bg-green-100 text-green-700",
        mismatch: "bg-red-100 text-red-700",
        no_in_stock_serial: "bg-yellow-100 text-yellow-700",
        empty: "bg-gray-100 text-gray-600",
    }[s] || "bg-gray-100 text-gray-700");
</script>

<template>
    <AppLayout>
        <template #sidebar>
            <ReportSidebar currentPage="cost-analysis" />
        </template>

        <div class="max-w-[1400px] mx-auto">
            <div class="flex items-center justify-between mb-5">
                <div>
                    <h1 class="text-xl font-bold text-gray-800">Phân tích giá vốn</h1>
                    <p class="text-xs text-gray-500 mt-1">
                        So sánh giá vốn snapshot trên product với trung bình giá vốn
                        các serial còn tồn (in_stock).
                    </p>
                </div>
            </div>

            <!-- Filters -->
            <div
                class="bg-white rounded-lg border border-gray-200 p-4 mb-5 flex flex-wrap items-end gap-3"
            >
                <div class="flex-1 min-w-[240px]">
                    <label class="block text-xs text-gray-500 mb-1">Tìm kiếm</label>
                    <input
                        v-model="search"
                        @input="onSearchInput"
                        type="text"
                        placeholder="SKU, tên sản phẩm..."
                        class="border border-gray-300 rounded px-3 py-1.5 text-sm w-full"
                    />
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1"
                        >Ngưỡng cảnh báo (%)</label
                    >
                    <input
                        v-model.number="thresholdPct"
                        @change="apply"
                        type="number"
                        min="0"
                        step="0.5"
                        class="border border-gray-300 rounded px-3 py-1.5 text-sm w-28"
                    />
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input
                        v-model="onlyMismatch"
                        @change="apply"
                        type="checkbox"
                        class="rounded border-gray-300"
                    />
                    Chỉ hiển thị bất thường
                </label>
            </div>

            <!-- Summary -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <div class="text-xs text-gray-500 mb-1">Tổng sản phẩm</div>
                    <div class="text-xl font-bold text-gray-800">
                        {{ summary?.total ?? 0 }}
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-red-200 p-4">
                    <div class="text-xs text-red-500 mb-1">Sản phẩm lệch</div>
                    <div class="text-xl font-bold text-red-700">
                        {{ summary?.mismatch_count ?? 0 }}
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <div class="text-xs text-gray-500 mb-1">
                        Giá trị tồn (snapshot)
                    </div>
                    <div class="text-lg font-semibold text-gray-800">
                        {{ fmt(summary?.total_inventory_value_snapshot) }}
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <div class="text-xs text-gray-500 mb-1">
                        Giá trị tồn (theo serial)
                    </div>
                    <div class="text-lg font-semibold text-gray-800">
                        {{ fmt(summary?.total_inventory_value_serial) }}
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div
                class="bg-white rounded-lg border border-gray-200 overflow-hidden"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr class="text-xs text-gray-600 uppercase">
                                <th class="px-3 py-2 text-left">SKU</th>
                                <th class="px-3 py-2 text-left">Sản phẩm</th>
                                <th class="px-3 py-2 text-center">Loại</th>
                                <th
                                    class="px-3 py-2 text-right cursor-pointer hover:bg-gray-100"
                                    @click="toggleSort('stock_quantity')"
                                >
                                    Tồn {{ sortIcon("stock_quantity") }}
                                </th>
                                <th
                                    class="px-3 py-2 text-right cursor-pointer hover:bg-gray-100"
                                    @click="toggleSort('snapshot_cost')"
                                >
                                    Giá vốn (snapshot)
                                    {{ sortIcon("snapshot_cost") }}
                                </th>
                                <th
                                    class="px-3 py-2 text-right cursor-pointer hover:bg-gray-100"
                                    @click="toggleSort('avg_serial_cost')"
                                >
                                    BQ Serial in_stock
                                    {{ sortIcon("avg_serial_cost") }}
                                </th>
                                <th class="px-3 py-2 text-right">Min - Max</th>
                                <th
                                    class="px-3 py-2 text-right cursor-pointer hover:bg-gray-100"
                                    @click="toggleSort('diff')"
                                >
                                    Chênh {{ sortIcon("diff") }}
                                </th>
                                <th
                                    class="px-3 py-2 text-right cursor-pointer hover:bg-gray-100"
                                    @click="toggleSort('diff_pct')"
                                >
                                    % {{ sortIcon("diff_pct") }}
                                </th>
                                <th class="px-3 py-2 text-center">Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="!sortedRows.length">
                                <td
                                    colspan="10"
                                    class="px-3 py-8 text-center text-gray-400"
                                >
                                    Không có dữ liệu
                                </td>
                            </tr>
                            <tr
                                v-for="r in sortedRows"
                                :key="r.id"
                                class="hover:bg-gray-50"
                            >
                                <td class="px-3 py-2 font-mono text-xs">
                                    {{ r.sku }}
                                </td>
                                <td class="px-3 py-2">
                                    <Link
                                        :href="`/products/${r.id}/edit`"
                                        class="text-blue-600 hover:underline"
                                    >
                                        {{ r.name }}
                                    </Link>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <span
                                        v-if="r.has_serial"
                                        class="text-xs px-2 py-0.5 rounded bg-purple-100 text-purple-700"
                                        >Serial</span
                                    >
                                    <span
                                        v-else
                                        class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-600"
                                        >Thường</span
                                    >
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ fmt(r.stock_quantity) }}
                                    <span
                                        v-if="
                                            r.has_serial &&
                                            r.in_stock_serial_count !== null &&
                                            r.in_stock_serial_count !==
                                                r.stock_quantity
                                        "
                                        class="text-xs text-orange-600 ml-1"
                                        :title="`Stock = ${r.stock_quantity}, serial in_stock = ${r.in_stock_serial_count}`"
                                        >({{ r.in_stock_serial_count }})</span
                                    >
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ fmt(r.snapshot_cost) }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <span v-if="r.avg_serial_cost !== null">{{
                                        fmt(r.avg_serial_cost)
                                    }}</span>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                                <td
                                    class="px-3 py-2 text-right text-xs text-gray-500"
                                >
                                    <span
                                        v-if="
                                            r.min_serial_cost !== null &&
                                            r.max_serial_cost !== null
                                        "
                                    >
                                        {{ fmt(r.min_serial_cost) }} -
                                        {{ fmt(r.max_serial_cost) }}
                                    </span>
                                    <span v-else>—</span>
                                </td>
                                <td
                                    class="px-3 py-2 text-right"
                                    :class="
                                        r.diff > 0
                                            ? 'text-green-700'
                                            : r.diff < 0
                                            ? 'text-red-700'
                                            : 'text-gray-500'
                                    "
                                >
                                    <span v-if="r.avg_serial_cost !== null">{{
                                        fmt(r.diff)
                                    }}</span>
                                    <span v-else>—</span>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <span v-if="r.avg_serial_cost !== null"
                                        >{{ r.diff_pct }}%</span
                                    >
                                    <span v-else>—</span>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <span
                                        class="text-xs px-2 py-0.5 rounded font-semibold"
                                        :class="statusClass(r.status)"
                                        >{{ statusLabel(r.status) }}</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
