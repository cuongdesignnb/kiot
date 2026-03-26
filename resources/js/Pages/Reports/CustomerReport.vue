<script setup>
import { ref, computed, watch } from "vue";
import { router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import { Bar } from "vue-chartjs";
import {
    Chart as ChartJS, CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend,
} from "chart.js";
ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

const props = defineProps({
    filters: Object,
    periodLabel: String,
    chartData: Object,
    reportRows: Array,
    summary: Object,
    branchName: String,
    branches: Array,
    customerGroups: Array,
    dateFromDisplay: String,
    dateToDisplay: String,
});

const concern = ref(props.filters.concern || "sales");
const period = ref(props.filters.period || "this_month");
const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const branchId = ref(props.filters.branch_id || "");
const customerGroup = ref(props.filters.customer_group || "");
const viewMode = ref(props.filters.view || "chart");

const concernOptions = [
    { value: "sales", label: "Bán hàng" },
    { value: "returns", label: "Trả hàng" },
    { value: "debt", label: "Công nợ" },
];
const periodOptions = [
    { value: "this_week", label: "Tuần này" },
    { value: "this_month", label: "Tháng này" },
    { value: "this_year", label: "Năm nay" },
    { value: "last_year", label: "Năm trước" },
    { value: "custom", label: "Tùy chỉnh" },
];

const applyFilter = () => {
    const params = {
        concern: concern.value, period: period.value, view: viewMode.value,
        branch_id: branchId.value || undefined,
        customer_group: customerGroup.value || undefined,
    };
    if (period.value === "custom") { params.date_from = dateFrom.value; params.date_to = dateTo.value; }
    router.get("/reports/customers-report", params, { preserveState: true });
};
watch([concern, period, branchId, customerGroup], () => applyFilter());
const switchView = (mode) => { viewMode.value = mode; applyFilter(); };

const formatNumber = (n) => {
    if (n === null || n === undefined || isNaN(n)) return "0";
    if (Math.abs(n) >= 1e9) return (n / 1e9).toFixed(2).replace(/\.?0+$/, "") + " tỷ";
    if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(2).replace(/\.?0+$/, "") + " tr";
    if (Math.abs(n) >= 1e3) return (n / 1e3).toFixed(1).replace(/\.?0+$/, "") + " k";
    return new Intl.NumberFormat("vi-VN").format(Math.round(n));
};
const formatCurrency = (n) => new Intl.NumberFormat("vi-VN").format(Math.round(n || 0));

const barChartData = computed(() => ({
    labels: props.chartData?.labels || [],
    datasets: (props.chartData?.datasets || []).map((ds) => ({
        label: ds.label, data: ds.data,
        backgroundColor: "#3b82f6",
        borderWidth: 0, borderRadius: 3, barPercentage: 0.6,
    })),
}));
const barChartOptions = computed(() => ({
    indexAxis: props.chartData?.type === "horizontal_bar" ? "y" : "x",
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ctx.dataset.label + ": " + formatNumber(ctx.raw) } } },
    scales: {
        x: { ticks: props.chartData?.type === "horizontal_bar" ? { callback: (v) => formatNumber(v) } : {} },
        y: { beginAtZero: true },
    },
}));

// PDF viewer controls
const ROWS_PER_PAGE = 20;
const currentPage = ref(1);
const totalPages = computed(() => Math.max(1, Math.ceil((props.reportRows?.length || 0) / ROWS_PER_PAGE)));
const paginatedRows = computed(() => {
    const start = (currentPage.value - 1) * ROWS_PER_PAGE;
    return (props.reportRows || []).slice(start, start + ROWS_PER_PAGE);
});
const zoom = ref(100);

const now = new Date();
const reportDate = `${String(now.getDate()).padStart(2, '0')}/${String(now.getMonth() + 1).padStart(2, '0')}/${now.getFullYear()} ${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
</script>

<template>
    <AppLayout>
        <div class="flex h-full min-h-[calc(100vh-56px)]">
            <aside class="w-[200px] bg-white border-r border-gray-200 p-4 flex-shrink-0 overflow-y-auto print:hidden">
                <h2 class="text-sm font-bold text-gray-800 mb-4">Báo cáo khách hàng</h2>
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Kiểu hiển thị •</label>
                    <div class="flex gap-1">
                        <button @click="switchView('chart')" class="px-3 py-1.5 text-xs rounded transition-colors"
                            :class="viewMode === 'chart' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">Biểu đồ</button>
                        <button @click="switchView('report')" class="px-3 py-1.5 text-xs rounded transition-colors"
                            :class="viewMode === 'report' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">Báo cáo</button>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Mối quan tâm</label>
                    <select v-model="concern" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option v-for="opt in concernOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Chi nhánh</label>
                    <select v-model="branchId" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option value="">Tất cả chi nhánh</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Thời gian •</label>
                    <div class="space-y-1.5">
                        <div v-for="opt in periodOptions" :key="opt.value">
                            <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700">
                                <input type="radio" v-model="period" :value="opt.value" class="accent-blue-600" /><span>{{ opt.label }}</span>
                            </label>
                        </div>
                        <div v-if="period === 'custom'" class="mt-2 space-y-1.5">
                            <input type="date" v-model="dateFrom" class="w-full text-xs border border-gray-300 rounded px-2 py-1.5" />
                            <input type="date" v-model="dateTo" class="w-full text-xs border border-gray-300 rounded px-2 py-1.5" />
                            <button @click="applyFilter" class="w-full text-xs bg-blue-50 text-blue-600 border border-blue-200 rounded py-1.5 hover:bg-blue-100">Áp dụng</button>
                        </div>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Nhóm khách hàng</label>
                    <select v-model="customerGroup" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option value="">Chọn nhóm khách hàng</option>
                        <option v-for="g in customerGroups" :key="g" :value="g">{{ g }}</option>
                    </select>
                </div>
            </aside>

            <main class="flex-1 bg-gray-50 overflow-auto">
                <!-- ══════ CHART VIEW ══════ -->
                <template v-if="viewMode === 'chart'">
                    <div class="p-6">
                        <div class="text-center mb-4"><h2 class="text-base font-semibold text-gray-700">{{ chartData?.title }}</h2></div>
                        <div class="bg-white rounded-lg border border-gray-200 p-5">
                            <div v-if="chartData?.labels?.length > 0" :style="{ height: Math.max(300, chartData.labels.length * 45) + 'px' }">
                                <Bar :data="barChartData" :options="barChartOptions" />
                            </div>
                            <div v-else class="h-[300px] flex items-center justify-center text-gray-400">
                                <div class="text-center"><div class="text-4xl mb-2">👥</div><div class="text-sm">Không có dữ liệu khách hàng</div></div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- ══════ PDF-STYLE REPORT VIEW ══════ -->
                <template v-if="viewMode === 'report'">
                    <div class="bg-[#404040] px-3 py-1.5 flex items-center justify-between print:hidden sticky top-0 z-10">
                        <div class="flex items-center gap-1">
                            <button @click="currentPage = 1" class="pdf-btn">⏮</button>
                            <button @click="currentPage > 1 && currentPage--" class="pdf-btn">◀</button>
                            <div class="flex items-center gap-1 mx-1">
                                <input type="number" v-model.number="currentPage" :min="1" :max="totalPages"
                                    class="w-10 text-center text-xs bg-white text-gray-800 border border-gray-400 rounded px-1 py-0.5" />
                                <span class="text-white text-xs">/ {{ totalPages }}</span>
                            </div>
                            <button @click="currentPage < totalPages && currentPage++" class="pdf-btn">▶</button>
                            <button @click="currentPage = totalPages" class="pdf-btn">⏭</button>
                        </div>
                        <div class="flex items-center gap-1">
                            <button @click="applyFilter" class="pdf-btn" title="Làm mới">🔄</button>
                            <span class="w-px h-5 bg-gray-500 mx-1"></span>
                            <button @click="window.print()" class="pdf-btn" title="In">🖨️</button>
                            <span class="w-px h-5 bg-gray-500 mx-1"></span>
                            <button @click="zoom > 60 && (zoom -= 10)" class="pdf-btn">🔍−</button>
                            <span class="text-white text-xs mx-1">{{ zoom }}%</span>
                            <button @click="zoom < 150 && (zoom += 10)" class="pdf-btn">🔍+</button>
                        </div>
                    </div>
                    <div class="p-6 flex justify-center bg-[#e8e8e8] min-h-[calc(100vh-120px)]">
                        <div class="bg-white shadow-lg border border-gray-300 w-full p-10 print:shadow-none print:border-none print:p-0 print:max-w-full"
                            :style="{ maxWidth: (900 * zoom / 100) + 'px', fontSize: (zoom / 100) + 'em' }">
                            <p class="text-xs text-gray-400 mb-3" style="font-size:0.75em">Ngày lập: {{ reportDate }}</p>
                            <h1 class="text-lg font-bold text-center mb-1">Báo cáo bán hàng theo khách hàng</h1>
                            <p class="text-sm text-gray-500 text-center">Từ ngày {{ dateFromDisplay }} đến ngày {{ dateToDisplay }}</p>
                            <p class="text-sm text-gray-500 text-center mb-5">Chi nhánh: {{ branchName }}</p>
                            <table class="w-full border-collapse" style="font-size:0.85em">
                                <thead>
                                    <tr class="bg-blue-600 text-white">
                                        <th class="px-3 py-2 text-left font-semibold border border-blue-700">Mã KH</th>
                                        <th class="px-3 py-2 text-left font-semibold border border-blue-700">Khách hàng</th>
                                        <th class="px-3 py-2 text-right font-semibold border border-blue-700">Doanh thu</th>
                                        <th class="px-3 py-2 text-right font-semibold border border-blue-700">Giá trị trả</th>
                                        <th class="px-3 py-2 text-right font-semibold border border-blue-700">Doanh thu thuần</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="bg-blue-50 font-bold">
                                        <td colspan="2" class="px-3 py-2 border border-gray-200">SL khách hàng: {{ summary?.count || 0 }}</td>
                                        <td class="px-3 py-2 text-right border border-gray-200">{{ formatCurrency(summary?.totalRevenue) }}</td>
                                        <td class="px-3 py-2 text-right border border-gray-200">{{ formatCurrency(summary?.totalReturns) }}</td>
                                        <td class="px-3 py-2 text-right border border-gray-200 text-blue-700">{{ formatCurrency(summary?.totalNet) }}</td>
                                    </tr>
                                    <tr v-for="row in paginatedRows" :key="row.id" class="hover:bg-gray-50">
                                        <td class="px-3 py-1.5 border border-gray-200 text-blue-600 font-medium">{{ row.code }}</td>
                                        <td class="px-3 py-1.5 border border-gray-200">{{ row.name }}</td>
                                        <td class="px-3 py-1.5 border border-gray-200 text-right">{{ formatCurrency(row.revenue) }}</td>
                                        <td class="px-3 py-1.5 border border-gray-200 text-right">{{ formatCurrency(row.returns) }}</td>
                                        <td class="px-3 py-1.5 border border-gray-200 text-right font-semibold">{{ formatCurrency(row.net) }}</td>
                                    </tr>
                                    <tr v-if="!reportRows?.length">
                                        <td colspan="5" class="px-3 py-8 text-center text-gray-400 border border-gray-200">Báo cáo không có dữ liệu</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>
            </main>
        </div>
    </AppLayout>
</template>

<style scoped>
.pdf-btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
    color: #d1d5db;
    border-radius: 0.25rem;
    cursor: pointer;
    transition: color 0.15s, background-color 0.15s;
    background: none;
    border: none;
}
.pdf-btn:hover { color: #fff; background-color: #4b5563; }
@media print {
    aside { display: none !important; }
    main { padding: 0 !important; }
}
</style>
