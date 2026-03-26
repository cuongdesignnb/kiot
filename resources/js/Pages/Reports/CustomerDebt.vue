<script setup>
import { ref, computed } from "vue";
import { router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ReportSidebar from "@/Components/ReportSidebar.vue";
import { Line, Bar } from "vue-chartjs";
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    Title,
    Tooltip,
    Legend,
    Filler,
} from "chart.js";

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, Title, Tooltip, Legend, Filler);

const props = defineProps({
    filters: Object,
    debtorCount: Number,
    totalDebt: Number,
    debtRevenueRatio: Number,
    avgDebtPerMonth: Number,
    avgDebtRevenueRatio: Number,
    chart: Object,
    debtByDays: Object,
    topByAmount: Array,
    topByDays: Array,
    branches: Array,
});

const branchId = ref(props.filters?.branch_id || "");
const activeTab = ref("amount");

const applyFilter = () => {
    router.get("/reports/customer-debt", { branch_id: branchId.value || undefined }, { preserveState: true });
};

const formatNumber = (n) => {
    if (n === null || n === undefined || isNaN(n)) return "---";
    if (Math.abs(n) >= 1e9) return (n / 1e9).toFixed(2).replace(/\.?0+$/, "") + " tỷ";
    if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(2).replace(/\.?0+$/, "") + " triệu";
    if (Math.abs(n) >= 1e3) return (n / 1e3).toFixed(2).replace(/\.?0+$/, "") + " nghìn";
    return new Intl.NumberFormat("vi-VN").format(n);
};

const formatCurrency = (n) => new Intl.NumberFormat("vi-VN").format(n || 0);

const topCustomers = computed(() =>
    activeTab.value === "amount" ? props.topByAmount : props.topByDays
);

// Line chart — debt trend
const lineChartData = computed(() => ({
    labels: props.chart?.labels || [],
    datasets: [
        {
            label: "Nợ cuối tháng",
            data: props.chart?.debt || [],
            borderColor: "#ef4444",
            backgroundColor: "transparent",
            tension: 0.3,
            yAxisID: "y",
        },
        {
            label: "Doanh thu thuần",
            data: props.chart?.netRevenue || [],
            borderColor: "#22c55e",
            backgroundColor: "transparent",
            tension: 0.3,
            yAxisID: "y",
        },
    ],
}));

const lineChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { position: "bottom", labels: { usePointStyle: true } },
        tooltip: {
            callbacks: {
                label: (ctx) => ctx.dataset.label + ": " + formatNumber(ctx.raw),
            },
        },
    },
    scales: {
        y: {
            type: "linear",
            display: true,
            position: "left",
            ticks: { callback: (v) => formatNumber(v) },
        },
    },
};

// Bar chart — debt by days
const barChartData = computed(() => {
    const days = props.debtByDays || {};
    const labels = Object.keys(days);
    const data = Object.values(days);
    return {
        labels,
        datasets: [
            {
                label: "Số khách",
                data,
                backgroundColor: data.map((_, i) =>
                    i === data.length - 1 ? "#3b82f6" : i >= data.length - 2 ? "#60a5fa" : "#93c5fd"
                ),
                borderWidth: 0,
                borderRadius: 4,
            },
        ],
    };
});

const barChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx) => ctx.raw + " khách",
            },
        },
    },
    scales: {
        x: {
            title: { display: true, text: "Số ngày nợ", font: { size: 12 } },
        },
        y: {
            title: { display: true, text: "Số khách", font: { size: 12 } },
            ticks: { stepSize: 1 },
        },
    },
};
</script>

<template>
    <AppLayout>
        <template #sidebar>
            <ReportSidebar currentPage="customer-debt" />
        </template>

        <div class="max-w-[1200px] mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-xl font-bold text-gray-800">Công nợ khách hàng</h1>
                <select v-model="branchId" @change="applyFilter" class="bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
                    <option value="">Tất cả chi nhánh</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>

            <!-- 3 Summary Cards -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="text-sm text-gray-500 mb-2">Số lượng khách đang nợ</div>
                    <div class="text-2xl font-bold text-gray-800">{{ debtorCount }}</div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="text-sm text-gray-500 mb-2">Giá trị nợ hiện tại</div>
                    <div class="text-2xl font-bold text-red-600">{{ formatNumber(totalDebt) }}</div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="text-sm text-gray-500 mb-2">Giá trị nợ/Doanh thu thuần năm nay</div>
                    <div class="text-2xl font-bold text-gray-800">{{ debtRevenueRatio }}%</div>
                </div>
            </div>

            <!-- 12-month trend chart -->
            <div class="bg-white rounded-lg border border-gray-200 p-5 mb-6">
                <h3 class="text-base font-semibold text-gray-700 mb-1">Biến động công nợ phải thu trong 12 tháng qua</h3>
                <div class="flex gap-8 mb-4">
                    <div>
                        <div class="text-xs text-gray-500">Nợ phải thu TB/tháng</div>
                        <div class="text-lg font-bold text-gray-800">{{ formatNumber(avgDebtPerMonth) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Giá trị nợ/Doanh thu TB mỗi tháng</div>
                        <div class="text-lg font-bold text-gray-800">{{ avgDebtRevenueRatio }}%</div>
                    </div>
                </div>
                <div style="height: 280px">
                    <Line :data="lineChartData" :options="lineChartOptions" />
                </div>
            </div>

            <!-- Bar chart: debt by days -->
            <div class="bg-white rounded-lg border border-gray-200 p-5 mb-6">
                <h3 class="text-base font-semibold text-gray-700 mb-4">Lượng khách theo số ngày nợ</h3>
                <div style="height: 250px">
                    <Bar :data="barChartData" :options="barChartOptions" />
                </div>
            </div>

            <!-- Top 20% table -->
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-3 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-base font-semibold text-gray-700">Top 20% khách hàng nợ nhiều nhất</h3>
                        <span class="text-sm text-blue-600 cursor-pointer">Chi tiết</span>
                    </div>
                    <div class="flex gap-1 mt-2">
                        <button @click="activeTab = 'amount'"
                            class="px-4 py-1.5 text-sm rounded-md transition-colors"
                            :class="activeTab === 'amount' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'">
                            Nhiều nhất
                        </button>
                        <button @click="activeTab = 'days'"
                            class="px-4 py-1.5 text-sm rounded-md transition-colors"
                            :class="activeTab === 'days' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'">
                            Lâu nhất
                        </button>
                    </div>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Mã khách hàng</th>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Tên khách hàng</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Số ngày nợ</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Giá trị nợ ▼</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Giá trị nợ/DT thuần</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in topCustomers" :key="c.id" class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-5 py-3 text-blue-600 font-medium">{{ c.code }}</td>
                            <td class="px-5 py-3 text-gray-700 font-medium">{{ c.name }}</td>
                            <td class="px-5 py-3 text-right text-gray-600">{{ c.debtDays }}</td>
                            <td class="px-5 py-3 text-right text-red-600 font-semibold">{{ formatCurrency(c.debt) }}</td>
                            <td class="px-5 py-3 text-right text-gray-600">{{ c.debtRatio > 0 ? c.debtRatio + '%' : '--%' }}</td>
                        </tr>
                        <tr v-if="!topCustomers || topCustomers.length === 0">
                            <td colspan="5" class="px-5 py-8 text-center text-gray-400">Không có khách hàng nợ</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
