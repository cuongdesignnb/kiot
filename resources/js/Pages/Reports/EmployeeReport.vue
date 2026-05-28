<script setup>
import { formatVND as formatCurrency } from '@/utils/money';
import { ref, computed, watch } from "vue";
import { router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import { Bar } from "vue-chartjs";
import {
    Chart as ChartJS, CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend,
} from "chart.js";
ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

const props = defineProps({
    filters: Object, periodLabel: String, chartData: Object,
    reportRows: Array, summary: Object, branchName: String,
    branches: Array, employees: Array, salesChannels: Array,
    dateFromDisplay: String, dateToDisplay: String,
});

const concern = ref(props.filters.concern || "sales");
const period = ref(props.filters.period || "this_month");
const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const branchId = ref(props.filters.branch_id || "");
const employeeId = ref(props.filters.employee_id || "");
const salesChannel = ref(props.filters.sales_channel || "");
const viewMode = ref(props.filters.view || "chart");

const concernOptions = [
    { value: "sales", label: "Bán hàng" },
    { value: "profit", label: "Lợi nhuận" },
    { value: "items", label: "Hàng bán theo nhân viên" },
];
const periodOptions = [
    { value: "this_week", label: "Tuần này" },
    { value: "this_month", label: "Tháng này" },
    { value: "this_year", label: "Năm nay" },
    { value: "last_year", label: "Năm trước" },
    { value: "custom", label: "Tùy chỉnh" },
];

const expandedRows = ref({});
const isExpanded = (row) => !!expandedRows.value[row.id];
const toggleRow = (row) => {
    expandedRows.value[row.id] = !expandedRows.value[row.id];
};
const hasChildren = (row) => {
    return concern.value === 'sales' && Array.isArray(row.children) && row.children.length > 0;
};

const applyFilter = () => {
    const params = {
        concern: concern.value, period: period.value, view: viewMode.value,
        branch_id: branchId.value || undefined,
        employee_id: employeeId.value || undefined,
        sales_channel: salesChannel.value || undefined,
    };
    if (period.value === "custom") { params.date_from = dateFrom.value; params.date_to = dateTo.value; }
    expandedRows.value = {};
    router.get("/reports/employees", params, { preserveState: true });
};
watch([concern, period, branchId, employeeId, salesChannel], () => applyFilter());
const switchView = (mode) => { viewMode.value = mode; applyFilter(); };

const formatNumber = (n) => {
    if (n === null || n === undefined || isNaN(n)) return "0";
    if (Math.abs(n) >= 1e9) return (n / 1e9).toFixed(2).replace(/\.?0+$/, "") + " tỷ";
    if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(2).replace(/\.?0+$/, "") + " tr";
    if (Math.abs(n) >= 1e3) return (n / 1e3).toFixed(1).replace(/\.?0+$/, "") + " k";
    return new Intl.NumberFormat("vi-VN").format(Math.round(n));
};

/** Format negative values with minus sign for display (discount, returns) */
const formatNeg = (n) => {
    if (!n || n === 0) return '0';
    return '-' + formatCurrency(Math.abs(n));
};

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

// PDF viewer
const ROWS_PER_PAGE = 20;
const currentPage = ref(1);
const totalPages = computed(() => Math.max(1, Math.ceil((props.reportRows?.length || 0) / ROWS_PER_PAGE)));
const paginatedRows = computed(() => {
    const start = (currentPage.value - 1) * ROWS_PER_PAGE;
    return (props.reportRows || []).slice(start, start + ROWS_PER_PAGE);
});
const zoom = ref(100);

const now = new Date();
const reportDate = `${String(now.getDate()).padStart(2,'0')}/${String(now.getMonth()+1).padStart(2,'0')}/${now.getFullYear()} ${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`;

const reportTitle = computed(() => {
    if (concern.value === 'profit') return 'Báo cáo lợi nhuận theo nhân viên';
    if (concern.value === 'items') return 'Báo cáo hàng bán theo nhân viên';
    return 'Báo cáo bán hàng theo nhân viên';
});

const isProfit = computed(() => concern.value === 'profit');

const col2Label = computed(() => concern.value === 'profit' ? 'Doanh thu thuần' : 'Doanh thu');
const col3Label = computed(() => {
    if (concern.value === 'profit') return 'Giá vốn';
    if (concern.value === 'items') return 'Số lượng';
    return 'Giá trị trả';
});
const col4Label = computed(() => {
    if (concern.value === 'profit') return 'Lợi nhuận';
    return 'Doanh thu thuần';
});
const summaryLabel = computed(() => `SL người bán: ${summary.value?.count || 0}`);
const summary = computed(() => props.summary || { count: 0, totalRevenue: 0, totalReturns: 0, totalNet: 0 });
</script>

<template>
    <AppLayout>
        <div class="flex h-full min-h-[calc(100vh-56px)]">
            <aside class="w-[200px] bg-white border-r border-gray-200 p-4 flex-shrink-0 overflow-y-auto print:hidden">
                <h2 class="text-sm font-bold text-gray-800 mb-4">Báo cáo nhân viên</h2>
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
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Người bán</label>
                    <select v-model="employeeId" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option value="">Chọn người bán</option>
                        <option v-for="e in employees" :key="e.key" :value="e.key">{{ e.display_name || e.name }}</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Kênh bán</label>
                    <select v-model="salesChannel" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option value="">Chọn kênh bán</option>
                        <option v-for="c in salesChannels" :key="c" :value="c">{{ c }}</option>
                    </select>
                </div>
            </aside>

            <main class="flex-1 bg-gray-50 overflow-auto">
                <!-- Chart View -->
                <template v-if="viewMode === 'chart'">
                    <div class="p-6">
                        <div class="text-center mb-4"><h2 class="text-base font-semibold text-gray-700">{{ chartData?.title }}</h2></div>
                        <div class="bg-white rounded-lg border border-gray-200 p-5">
                            <div v-if="chartData?.labels?.length > 0" :style="{ height: Math.max(300, chartData.labels.length * 45) + 'px' }">
                                <Bar :data="barChartData" :options="barChartOptions" />
                            </div>
                            <div v-else class="h-[300px] flex items-center justify-center text-gray-400">
                                <div class="text-center"><div class="text-4xl mb-2">👤</div><div class="text-sm">Không có dữ liệu nhân viên</div></div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- PDF-STYLE REPORT VIEW -->
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
                            :style="{ maxWidth: (isProfit ? 1100 : 900) * zoom / 100 + 'px', fontSize: (zoom / 100) + 'em' }">
                            <p class="text-xs text-gray-400 mb-3" style="font-size:0.75em">Ngày lập: {{ reportDate }}</p>
                            <h1 class="text-lg font-bold text-center mb-1">{{ reportTitle }}</h1>
                            <p class="text-sm text-gray-500 text-center">Từ ngày {{ dateFromDisplay }} đến ngày {{ dateToDisplay }}</p>
                            <p class="text-sm text-gray-500 text-center mb-5">Chi nhánh: {{ branchName }}</p>

                            <!-- ═══ PROFIT TABLE (8 columns KiotViet style) ═══ -->
                            <template v-if="isProfit">
                                <table class="w-full border-collapse" style="font-size:0.8em">
                                    <thead>
                                        <tr class="bg-blue-600 text-white">
                                            <th class="px-2 py-2 text-left font-semibold border border-blue-700">Nhân viên</th>
                                            <th class="px-2 py-2 text-right font-semibold border border-blue-700">Tổng tiền hàng</th>
                                            <th class="px-2 py-2 text-right font-semibold border border-blue-700">Giảm giá</th>
                                            <th class="px-2 py-2 text-right font-semibold border border-blue-700">Doanh thu</th>
                                            <th class="px-2 py-2 text-right font-semibold border border-blue-700">Giá trị trả</th>
                                            <th class="px-2 py-2 text-right font-semibold border border-blue-700">Doanh thu thuần</th>
                                            <th class="px-2 py-2 text-right font-semibold border border-blue-700">Tổng giá vốn</th>
                                            <th class="px-2 py-2 text-right font-semibold border border-blue-700">Lợi nhuận gộp</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="bg-blue-50 font-bold">
                                            <td class="px-2 py-2 border border-gray-200">{{ summaryLabel }}</td>
                                            <td class="px-2 py-2 text-right border border-gray-200">{{ formatCurrency(summary.gross_revenue ?? 0) }}</td>
                                            <td class="px-2 py-2 text-right border border-gray-200 text-red-600">{{ formatNeg(summary.invoice_discount ?? 0) }}</td>
                                            <td class="px-2 py-2 text-right border border-gray-200">{{ formatCurrency(summary.revenue_after_discount ?? 0) }}</td>
                                            <td class="px-2 py-2 text-right border border-gray-200 text-red-600">{{ formatNeg(summary.return_value ?? 0) }}</td>
                                            <td class="px-2 py-2 text-right border border-gray-200">{{ formatCurrency(summary.net_revenue ?? 0) }}</td>
                                            <td class="px-2 py-2 text-right border border-gray-200">{{ formatCurrency(summary.total_cogs ?? 0) }}</td>
                                            <td class="px-2 py-2 text-right border border-gray-200 text-blue-700 font-bold">{{ formatCurrency(summary.gross_profit ?? 0) }}</td>
                                        </tr>
                                        <tr v-for="row in paginatedRows" :key="row.id" class="hover:bg-gray-50">
                                            <td class="px-2 py-1.5 border border-gray-200 text-blue-600 font-medium">{{ row.name }}</td>
                                            <td class="px-2 py-1.5 border border-gray-200 text-right">{{ formatCurrency(row.gross_revenue) }}</td>
                                            <td class="px-2 py-1.5 border border-gray-200 text-right text-red-600">{{ formatNeg(row.invoice_discount) }}</td>
                                            <td class="px-2 py-1.5 border border-gray-200 text-right">{{ formatCurrency(row.revenue_after_discount) }}</td>
                                            <td class="px-2 py-1.5 border border-gray-200 text-right text-red-600">{{ formatNeg(row.return_value) }}</td>
                                            <td class="px-2 py-1.5 border border-gray-200 text-right">{{ formatCurrency(row.net_revenue) }}</td>
                                            <td class="px-2 py-1.5 border border-gray-200 text-right">{{ formatCurrency(row.total_cogs) }}</td>
                                            <td class="px-2 py-1.5 border border-gray-200 text-right font-semibold">{{ formatCurrency(row.gross_profit) }}</td>
                                        </tr>
                                        <tr v-if="!reportRows?.length">
                                            <td colspan="8" class="px-3 py-8 text-center text-gray-400 border border-gray-200">Báo cáo không có dữ liệu</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </template>

                            <!-- ═══ SALES / ITEMS TABLE (4 columns — original) ═══ -->
                            <template v-else>
                                <table class="w-full border-collapse" style="font-size:0.85em">
                                    <thead>
                                        <tr class="bg-blue-600 text-white">
                                            <th class="px-3 py-2 text-left font-semibold border border-blue-700">Người bán</th>
                                            <th class="px-3 py-2 text-right font-semibold border border-blue-700">{{ col2Label }}</th>
                                            <th class="px-3 py-2 text-right font-semibold border border-blue-700">{{ col3Label }}</th>
                                            <th class="px-3 py-2 text-right font-semibold border border-blue-700">{{ col4Label }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="bg-blue-50 font-bold">
                                            <td class="px-3 py-2 border border-gray-200">{{ summaryLabel }}</td>
                                            <td class="px-3 py-2 text-right border border-gray-200">{{ formatCurrency(summary.totalRevenue) }}</td>
                                            <td class="px-3 py-2 text-right border border-gray-200">{{ formatCurrency(summary.totalReturns) }}</td>
                                            <td class="px-3 py-2 text-right border border-gray-200 text-blue-700">{{ formatCurrency(summary.totalNet) }}</td>
                                        </tr>
                                        <template v-for="row in paginatedRows" :key="row.id">
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-3 py-1.5 border border-gray-200 text-blue-600 font-medium">
                                                    <div class="flex items-center gap-1.5">
                                                        <button 
                                                            v-if="hasChildren(row)" 
                                                            @click.stop="toggleRow(row)" 
                                                            class="text-gray-500 hover:text-blue-600 focus:outline-none w-4 h-4 flex items-center justify-center font-mono border border-gray-200 rounded bg-gray-50 shadow-sm text-[11px]"
                                                        >
                                                            {{ isExpanded(row) ? '−' : '+' }}
                                                        </button>
                                                        <span 
                                                            :class="{'cursor-pointer hover:underline': hasChildren(row)}"
                                                            @click="hasChildren(row) && toggleRow(row)"
                                                        >
                                                            {{ row.name }}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td class="px-3 py-1.5 border border-gray-200 text-right">{{ formatCurrency(row.revenue) }}</td>
                                                <td class="px-3 py-1.5 border border-gray-200 text-right">{{ formatCurrency(row.returns) }}</td>
                                                <td class="px-3 py-1.5 border border-gray-200 text-right font-semibold">{{ formatCurrency(row.net) }}</td>
                                            </tr>
                                            <!-- Children daily rows -->
                                            <tr 
                                                v-if="isExpanded(row)" 
                                                v-for="child in row.children" 
                                                :key="row.id + '-' + child.date"
                                                class="bg-blue-50/20 hover:bg-blue-50/40"
                                            >
                                                <td class="pl-8 pr-3 py-1.5 border border-gray-200 text-gray-700">
                                                    <a 
                                                        v-if="child.drilldown_url"
                                                        :href="child.drilldown_url" 
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="text-blue-600 hover:underline"
                                                        :title="child.drilldown_label || 'Xem chi tiết'"
                                                    >
                                                        {{ child.date_display }}
                                                    </a>
                                                    <span v-else>{{ child.date_display }}</span>
                                                    <span
                                                        v-if="child.drilldown_type === 'returns'"
                                                        class="ml-1.5 px-1 py-0.5 text-[9px] font-semibold text-red-600 bg-red-50 rounded border border-red-200 inline-block align-middle"
                                                    >
                                                        Trả hàng
                                                    </span>
                                                </td>
                                                <td class="px-3 py-1.5 border border-gray-200 text-right text-gray-600">{{ formatCurrency(child.revenue) }}</td>
                                                <td class="px-3 py-1.5 border border-gray-200 text-right text-red-600">
                                                    <a
                                                        v-if="child.returns > 0 && child.return_url"
                                                        :href="child.return_url"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="text-red-600 hover:underline"
                                                        title="Xem phiếu trả hàng"
                                                    >
                                                        -{{ formatCurrency(child.returns) }}
                                                    </a>
                                                    <span v-else>0</span>
                                                </td>
                                                <td 
                                                    class="px-3 py-1.5 border border-gray-200 text-right font-medium"
                                                    :class="child.net < 0 ? 'text-red-600' : 'text-gray-700'"
                                                >
                                                    {{ formatCurrency(child.net) }}
                                                </td>
                                            </tr>
                                        </template>
                                        <tr v-if="!reportRows?.length">
                                            <td colspan="4" class="px-3 py-8 text-center text-gray-400 border border-gray-200">Báo cáo không có dữ liệu</td>
                                        </tr>
                                    </tbody>
                                </table>
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
