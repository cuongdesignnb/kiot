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
    branchName: String,
    branches: Array,
    categories: Array,
    brands: Array,
});

const concern = ref(props.filters.concern || "sales");
const period = ref(props.filters.period || "this_month");
const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const branchId = ref(props.filters.branch_id || "");
const categoryId = ref(props.filters.category_id || "");
const brandId = ref(props.filters.brand_id || "");
const viewMode = ref(props.filters.view || "chart");

const concernOptions = [
    { value: "sales", label: "Bán hàng" },
    { value: "profit", label: "Lợi nhuận" },
    { value: "stock_value", label: "Giá trị kho" },
    { value: "stock_io", label: "Xuất nhập tồn" },
    { value: "stock_io_detail", label: "Xuất nhập tồn chi tiết" },
];
const periodOptions = [
    { value: "this_week", label: "Tuần này" },
    { value: "this_month", label: "Tháng này" },
    { value: "this_year", label: "Năm nay" },
    { value: "last_year", label: "Năm trước" },
    { value: "custom", label: "Tùy chỉnh" },
];
const selectedPeriodLabel = computed(() => periodOptions.find((o) => o.value === period.value)?.label || "Tháng này");

const applyFilter = () => {
    const params = {
        concern: concern.value, period: period.value, view: viewMode.value,
        branch_id: branchId.value || undefined,
        category_id: categoryId.value || undefined,
        brand_id: brandId.value || undefined,
    };
    if (period.value === "custom") { params.date_from = dateFrom.value; params.date_to = dateTo.value; }
    router.get("/reports/products-report", params, { preserveState: true });
};

watch([concern, period, branchId, categoryId, brandId], () => applyFilter());
const switchView = (mode) => { viewMode.value = mode; applyFilter(); };

const formatNumber = (n) => {
    if (n === null || n === undefined || isNaN(n)) return "0";
    if (Math.abs(n) >= 1e9) return (n / 1e9).toFixed(2).replace(/\.?0+$/, "") + " tỷ";
    if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(2).replace(/\.?0+$/, "") + " tr";
    if (Math.abs(n) >= 1e3) return (n / 1e3).toFixed(1).replace(/\.?0+$/, "") + " k";
    return new Intl.NumberFormat("vi-VN").format(Math.round(n));
};
const formatCurrency = (n) => new Intl.NumberFormat("vi-VN").format(Math.round(n || 0));

const barColors = ["#3b82f6", "#22c55e", "#f59e0b", "#ef4444", "#8b5cf6"];

const makeChartData = (chart) => ({
    labels: chart.labels || [],
    datasets: (chart.datasets || []).map((ds, i) => ({
        label: ds.label, data: ds.data,
        backgroundColor: barColors[i % barColors.length],
        borderWidth: 0, borderRadius: 3, barPercentage: 0.6,
    })),
});
const makeChartOptions = (chart) => ({
    indexAxis: chart.type === "horizontal_bar" ? "y" : "x",
    responsive: true, maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => ctx.dataset.label + ": " + formatNumber(ctx.raw) } },
    },
    scales: {
        x: { ticks: chart.type === "horizontal_bar" ? { callback: (v) => formatNumber(v) } : {} },
        y: { ticks: chart.type === "horizontal_bar" ? {} : { callback: (v) => formatNumber(v) }, beginAtZero: true },
    },
});

const isTableMode = computed(() => props.chartData?.isTable);
const multiCharts = computed(() => props.chartData?.charts || []);
const tableData = computed(() => props.chartData?.tableData || []);
const tableColumns = computed(() => props.chartData?.columns || []);

// PDF viewer controls
const PDF_ROWS = 20;
const pdfPage = ref(1);
const pdfZoom = ref(100);
const pdfTotalPages = computed(() => {
    if (isTableMode.value) return Math.max(1, Math.ceil(tableData.value.length / PDF_ROWS));
    const allLabels = multiCharts.value.reduce((n, c) => n + (c.labels?.length || 0), 0);
    return Math.max(1, Math.ceil(allLabels / PDF_ROWS));
});
const paginatedTableData = computed(() => {
    const s = (pdfPage.value - 1) * PDF_ROWS;
    return tableData.value.slice(s, s + PDF_ROWS);
});
const now = new Date();
const pdfReportDate = `${String(now.getDate()).padStart(2,'0')}/${String(now.getMonth()+1).padStart(2,'0')}/${now.getFullYear()} ${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`;
</script>

<template>
    <AppLayout>
        <div class="flex h-full min-h-[calc(100vh-56px)]">
            <aside class="w-[200px] bg-white border-r border-gray-200 p-4 flex-shrink-0 overflow-y-auto print:hidden">
                <h2 class="text-sm font-bold text-gray-800 mb-4">Báo cáo hàng hóa</h2>
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Kiểu hiển thị</label>
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
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Loại hàng</label>
                    <select v-model="categoryId" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option value="">Chọn loại hàng</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Thương hiệu</label>
                    <select v-model="brandId" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option value="">Chọn thương hiệu</option>
                        <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </div>
            </aside>

            <main class="flex-1 bg-gray-50 overflow-auto">
                <!-- Chart View -->
                <template v-if="viewMode === 'chart' && !isTableMode">
                    <div class="p-6">
                        <div v-for="(chart, ci) in multiCharts" :key="ci" class="mb-8">
                            <div class="text-center mb-3"><h2 class="text-base font-semibold text-gray-700">{{ chart.title }}</h2></div>
                            <div class="bg-white rounded-lg border border-gray-200 p-5">
                                <div v-if="chart.labels?.length > 0" :style="{ height: chart.type === 'horizontal_bar' ? Math.max(250, chart.labels.length * 45) + 'px' : '350px' }">
                                    <Bar :data="makeChartData(chart)" :options="makeChartOptions(chart)" />
                                </div>
                                <div v-else class="h-[250px] flex items-center justify-center text-gray-400">
                                    <div class="text-center"><div class="text-4xl mb-2">📦</div><div class="text-sm">Không có dữ liệu</div></div>
                                </div>
                            </div>
                        </div>
                        <div v-if="multiCharts.length === 0" class="bg-white rounded-lg border border-gray-200 p-16 text-center text-gray-400">
                            <div class="text-5xl mb-3">📦</div><div class="text-sm">Không có dữ liệu</div>
                        </div>
                    </div>
                </template>

                <!-- ══════ PDF-STYLE REPORT VIEW ══════ -->
                <template v-if="isTableMode || viewMode === 'report'">
                    <div class="bg-[#404040] px-3 py-1.5 flex items-center justify-between print:hidden sticky top-0 z-10">
                        <div class="flex items-center gap-1">
                            <button @click="pdfPage = 1" class="pdf-btn">⏮</button>
                            <button @click="pdfPage > 1 && pdfPage--" class="pdf-btn">◀</button>
                            <div class="flex items-center gap-1 mx-1">
                                <input type="number" v-model.number="pdfPage" :min="1" :max="pdfTotalPages" class="w-10 text-center text-xs bg-white text-gray-800 border border-gray-400 rounded px-1 py-0.5" />
                                <span class="text-white text-xs">/ {{ pdfTotalPages }}</span>
                            </div>
                            <button @click="pdfPage < pdfTotalPages && pdfPage++" class="pdf-btn">▶</button>
                            <button @click="pdfPage = pdfTotalPages" class="pdf-btn">⏭</button>
                        </div>
                        <div class="flex items-center gap-1">
                            <button @click="applyFilter" class="pdf-btn" title="Làm mới">🔄</button>
                            <span class="w-px h-5 bg-gray-500 mx-1"></span>
                            <button @click="window.print()" class="pdf-btn" title="In">🖨️</button>
                            <span class="w-px h-5 bg-gray-500 mx-1"></span>
                            <button @click="pdfZoom > 60 && (pdfZoom -= 10)" class="pdf-btn">🔍−</button>
                            <span class="text-white text-xs mx-1">{{ pdfZoom }}%</span>
                            <button @click="pdfZoom < 150 && (pdfZoom += 10)" class="pdf-btn">🔍+</button>
                        </div>
                    </div>
                    <div class="p-6 flex justify-center bg-[#e8e8e8] min-h-[calc(100vh-120px)]">
                        <div class="bg-white shadow-lg border border-gray-300 w-full p-10 print:shadow-none print:border-none print:p-0 print:max-w-full"
                            :style="{ maxWidth: (900 * pdfZoom / 100) + 'px', fontSize: (pdfZoom / 100) + 'em' }">
                            <p class="text-xs text-gray-400 mb-3" style="font-size:0.75em">Ngày lập: {{ pdfReportDate }}</p>
                            <h1 class="text-lg font-bold text-center mb-1">{{ chartData?.title || 'Báo cáo hàng hóa' }}</h1>
                            <p class="text-sm text-gray-500 text-center mb-5">{{ selectedPeriodLabel }} — Chi nhánh: {{ branchName }}</p>

                            <!-- Stock I/O Table -->
                            <template v-if="isTableMode">
                                <table class="w-full border-collapse" style="font-size:0.85em">
                                    <thead>
                                        <tr class="bg-blue-600 text-white">
                                            <th v-for="col in tableColumns" :key="col" class="px-3 py-2 text-left font-semibold border border-blue-700">{{ col }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(row, i) in paginatedTableData" :key="i" class="hover:bg-gray-50">
                                            <td class="px-3 py-1.5 border border-gray-200">{{ row.name }}</td>
                                            <td class="px-3 py-1.5 border border-gray-200 text-gray-500">{{ row.sku }}</td>
                                            <td class="px-3 py-1.5 border border-gray-200 text-green-600 font-medium">{{ row.imported }}</td>
                                            <td class="px-3 py-1.5 border border-gray-200 text-red-500 font-medium">{{ row.exported }}</td>
                                            <td class="px-3 py-1.5 border border-gray-200 font-semibold">{{ row.stock }}</td>
                                            <td class="px-3 py-1.5 border border-gray-200 text-right">{{ formatCurrency(row.stockValue) }}</td>
                                        </tr>
                                        <tr v-if="tableData.length === 0"><td :colspan="tableColumns.length" class="px-3 py-8 text-center text-gray-400 border border-gray-200">Không có dữ liệu</td></tr>
                                    </tbody>
                                </table>
                            </template>

                            <!-- Report from chart data -->
                            <template v-else>
                                <div v-for="(chart, ci) in multiCharts" :key="ci" class="mb-6">
                                    <h3 class="text-sm font-semibold mb-2">{{ chart.title }}</h3>
                                    <table class="w-full border-collapse" style="font-size:0.85em">
                                        <thead>
                                            <tr class="bg-blue-600 text-white">
                                                <th class="px-3 py-2 text-left font-semibold border border-blue-700">Hàng hóa</th>
                                                <th v-for="ds in chart.datasets" :key="ds.label" class="px-3 py-2 text-right font-semibold border border-blue-700">{{ ds.label }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(label, i) in chart.labels" :key="i" class="hover:bg-gray-50">
                                                <td class="px-3 py-1.5 border border-gray-200">{{ label }}</td>
                                                <td v-for="ds in chart.datasets" :key="ds.label" class="px-3 py-1.5 border border-gray-200 text-right">{{ formatCurrency(ds.data[i]) }}</td>
                                            </tr>
                                            <tr v-if="!chart.labels?.length"><td :colspan="1 + chart.datasets.length" class="px-3 py-8 text-center text-gray-400 border border-gray-200">Không có dữ liệu</td></tr>
                                            <tr v-if="chart.labels?.length > 0" class="bg-blue-50 font-bold">
                                                <td class="px-3 py-2 border border-gray-200">Tổng cộng</td>
                                                <td v-for="ds in chart.datasets" :key="'t-'+ds.label" class="px-3 py-2 border border-gray-200 text-right text-blue-700">{{ formatCurrency(ds.data.reduce((a,b) => a+b, 0)) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </template>
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
