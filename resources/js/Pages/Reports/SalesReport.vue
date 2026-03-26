<script setup>
import { ref, computed, watch } from "vue";
import { router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import { Bar } from "vue-chartjs";
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    Title,
    Tooltip,
    Legend,
} from "chart.js";

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

const props = defineProps({
    filters: Object,
    periodLabel: String,
    chartData: Object,
    branchName: String,
    branches: Array,
    salesChannels: Array,
});

// Filter state
const concern = ref(props.filters.concern || "time");
const period = ref(props.filters.period || "this_month");
const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const branchId = ref(props.filters.branch_id || "");
const salesChannel = ref(props.filters.sales_channel || "");
const viewMode = ref(props.filters.view || "chart");

const concernOptions = [
    { value: "time", label: "Thời gian" },
    { value: "profit", label: "Lợi nhuận" },
    { value: "discount", label: "Giảm giá HĐ" },
    { value: "returns", label: "Trả hàng" },
    { value: "employee", label: "Nhân viên" },
];

const periodOptions = [
    { value: "this_week", label: "Tuần này" },
    { value: "this_month", label: "Tháng này" },
    { value: "this_year", label: "Năm nay" },
    { value: "last_year", label: "Năm trước" },
    { value: "custom", label: "Tùy chỉnh" },
];

const selectedPeriodLabel = computed(() =>
    periodOptions.find((o) => o.value === period.value)?.label || "Tháng này"
);

const applyFilter = () => {
    const params = {
        concern: concern.value,
        period: period.value,
        view: viewMode.value,
        branch_id: branchId.value || undefined,
        sales_channel: salesChannel.value || undefined,
    };
    if (period.value === "custom") {
        params.date_from = dateFrom.value;
        params.date_to = dateTo.value;
    }
    router.get("/reports/sales", params, { preserveState: true });
};

// Auto-apply when key filters change
watch([concern, period, branchId, salesChannel], () => {
    applyFilter();
});

const switchView = (mode) => {
    viewMode.value = mode;
    applyFilter();
};

const formatNumber = (n) => {
    if (n === null || n === undefined || isNaN(n)) return "0";
    if (Math.abs(n) >= 1e9) return (n / 1e9).toFixed(2).replace(/\.?0+$/, "") + " tỷ";
    if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(2).replace(/\.?0+$/, "") + " tr";
    if (Math.abs(n) >= 1e3) return (n / 1e3).toFixed(1).replace(/\.?0+$/, "") + " k";
    return new Intl.NumberFormat("vi-VN").format(Math.round(n));
};

const formatCurrency = (n) => new Intl.NumberFormat("vi-VN").format(Math.round(n || 0));

// Chart config
const barColors = ["#3b82f6", "#22c55e", "#f59e0b", "#ef4444", "#8b5cf6", "#06b6d4", "#ec4899"];

const barChartData = computed(() => {
    if (!props.chartData?.labels) return { labels: [], datasets: [] };
    return {
        labels: props.chartData.labels,
        datasets: (props.chartData.datasets || []).map((ds, i) => ({
            label: ds.label,
            data: ds.data,
            backgroundColor: barColors[i % barColors.length],
            borderWidth: 0,
            borderRadius: 3,
            barPercentage: 0.6,
        })),
    };
});

const barChartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: (props.chartData?.datasets || []).length > 1,
            position: "bottom",
            labels: { usePointStyle: true },
        },
        tooltip: {
            callbacks: {
                label: (ctx) => ctx.dataset.label + ": " + formatNumber(ctx.raw),
            },
        },
    },
    scales: {
        y: {
            ticks: { callback: (v) => formatNumber(v) },
            beginAtZero: true,
        },
    },
}));

// Report table from chart data
const reportRows = computed(() => {
    if (!props.chartData?.labels) return [];
    return props.chartData.labels.map((label, i) => ({
        label,
        values: (props.chartData.datasets || []).map((ds) => ds.data[i] || 0),
    }));
});

// PDF viewer controls
const PDF_ROWS = 20;
const pdfPage = ref(1);
const pdfZoom = ref(100);
const pdfTotalPages = computed(() => Math.max(1, Math.ceil(reportRows.value.length / PDF_ROWS)));
const pdfPaginatedRows = computed(() => {
    const s = (pdfPage.value - 1) * PDF_ROWS;
    return reportRows.value.slice(s, s + PDF_ROWS);
});
const now = new Date();
const pdfReportDate = `${String(now.getDate()).padStart(2,'0')}/${String(now.getMonth()+1).padStart(2,'0')}/${now.getFullYear()} ${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`;
</script>

<template>
    <AppLayout>
        <div class="flex h-full min-h-[calc(100vh-56px)]">
            <!-- Left Sidebar Filters -->
            <aside class="w-[200px] bg-white border-r border-gray-200 p-4 flex-shrink-0 overflow-y-auto print:hidden">
                <h2 class="text-sm font-bold text-gray-800 mb-4">Báo cáo bán hàng</h2>

                <!-- Kiểu hiển thị -->
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Kiểu hiển thị</label>
                    <div class="flex gap-1">
                        <button @click="switchView('chart')"
                            class="px-3 py-1.5 text-xs rounded transition-colors"
                            :class="viewMode === 'chart' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                            Biểu đồ
                        </button>
                        <button @click="switchView('report')"
                            class="px-3 py-1.5 text-xs rounded transition-colors"
                            :class="viewMode === 'report' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                            Báo cáo
                        </button>
                    </div>
                </div>

                <!-- Mối quan tâm -->
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Mối quan tâm</label>
                    <select v-model="concern" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option v-for="opt in concernOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                </div>

                <!-- Chi nhánh -->
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Chi nhánh</label>
                    <select v-model="branchId" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option value="">Tất cả chi nhánh</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </div>

                <!-- Thời gian -->
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Thời gian •</label>
                    <div class="space-y-2">
                        <div v-for="opt in periodOptions" :key="opt.value">
                            <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700">
                                <input type="radio" v-model="period" :value="opt.value" class="accent-blue-600" />
                                <span>{{ opt.label }}</span>
                            </label>
                        </div>
                        <!-- Custom date range inputs -->
                        <div v-if="period === 'custom'" class="mt-2 space-y-1.5">
                            <input type="date" v-model="dateFrom" class="w-full text-xs border border-gray-300 rounded px-2 py-1.5" />
                            <input type="date" v-model="dateTo" class="w-full text-xs border border-gray-300 rounded px-2 py-1.5" />
                            <button @click="applyFilter" class="w-full text-xs bg-blue-50 text-blue-600 border border-blue-200 rounded py-1.5 hover:bg-blue-100">
                                Áp dụng
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Phương thức bán hàng -->
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Phương thức bán hàng</label>
                    <select v-model="salesChannel" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option value="">Chọn phương thức bán hàng</option>
                        <option v-for="sc in salesChannels" :key="sc" :value="sc">{{ sc }}</option>
                    </select>
                </div>
            </aside>

            <!-- Main Content -->
            <main class="flex-1 bg-gray-50 overflow-auto">
                <!-- Chart View -->
                <template v-if="viewMode === 'chart'">
                    <div class="p-6">
                        <div class="text-center mb-4">
                            <h2 class="text-lg font-semibold text-gray-700">
                                {{ chartData?.title || 'Doanh thu thuần' }}
                                <span class="text-gray-500 font-normal ml-1">{{ selectedPeriodLabel }}</span>
                            </h2>
                        </div>

                        <div class="bg-white rounded-lg border border-gray-200 p-6">
                            <div v-if="chartData?.labels?.length > 0" style="height: 400px">
                                <Bar :data="barChartData" :options="barChartOptions" />
                            </div>
                            <div v-else class="h-[400px] flex items-center justify-center text-gray-400">
                                <div class="text-center">
                                    <div class="text-5xl mb-3">📊</div>
                                    <div class="text-sm">Không có dữ liệu trong khoảng thời gian này</div>
                                </div>
                            </div>

                            <!-- Total summary -->
                            <div v-if="chartData?.total !== undefined" class="mt-4 pt-4 border-t border-gray-100 text-center">
                                <span class="text-sm text-gray-500">Tổng: </span>
                                <span class="text-lg font-bold text-blue-600">{{ formatNumber(chartData.total) }}</span>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- ══════ PDF-STYLE REPORT VIEW ══════ -->
                <template v-if="viewMode === 'report'">
                    <div class="bg-[#404040] px-3 py-1.5 flex items-center justify-between print:hidden sticky top-0 z-10">
                        <div class="flex items-center gap-1">
                            <button @click="pdfPage = 1" class="pdf-btn">⏮</button>
                            <button @click="pdfPage > 1 && pdfPage--" class="pdf-btn">◀</button>
                            <div class="flex items-center gap-1 mx-1">
                                <input type="number" v-model.number="pdfPage" :min="1" :max="pdfTotalPages"
                                    class="w-10 text-center text-xs bg-white text-gray-800 border border-gray-400 rounded px-1 py-0.5" />
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
                            <h1 class="text-lg font-bold text-center mb-1">{{ chartData?.title || 'Báo cáo bán hàng' }}</h1>
                            <p class="text-sm text-gray-500 text-center mb-5">{{ selectedPeriodLabel }} — Chi nhánh: {{ branchName }}</p>
                            <table class="w-full border-collapse" style="font-size:0.85em">
                                <thead>
                                    <tr class="bg-blue-600 text-white">
                                        <th class="px-3 py-2 text-left font-semibold border border-blue-700">Thời gian</th>
                                        <th v-for="ds in (chartData?.datasets || [])" :key="ds.label" class="px-3 py-2 text-right font-semibold border border-blue-700">{{ ds.label }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in pdfPaginatedRows" :key="row.label" class="hover:bg-gray-50">
                                        <td class="px-3 py-1.5 border border-gray-200">{{ row.label }}</td>
                                        <td v-for="(val, i) in row.values" :key="i" class="px-3 py-1.5 border border-gray-200 text-right">{{ formatCurrency(val) }}</td>
                                    </tr>
                                    <tr v-if="reportRows.length === 0"><td :colspan="1 + (chartData?.datasets?.length || 0)" class="px-3 py-8 text-center text-gray-400 border border-gray-200">Không có dữ liệu</td></tr>
                                    <tr v-if="reportRows.length > 0" class="bg-blue-50 font-bold">
                                        <td class="px-3 py-2 border border-gray-200">Tổng cộng</td>
                                        <td v-for="(ds, i) in (chartData?.datasets || [])" :key="'t-' + i" class="px-3 py-2 border border-gray-200 text-right text-blue-700">{{ formatCurrency(ds.data.reduce((a, b) => a + b, 0)) }}</td>
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

