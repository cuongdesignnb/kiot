<script setup>
import { ref, computed } from "vue";
import { router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ReportSidebar from "@/Components/ReportSidebar.vue";
import { Pie, Line } from "vue-chartjs";
import {
    Chart as ChartJS,
    ArcElement,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler,
} from "chart.js";

ChartJS.register(ArcElement, CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler);

const props = defineProps({
    filters: Object,
    totalCustomers: Number,
    totalRevenue: Number,
    customerBreakdown: Object,
    chart: Object,
    branches: Array,
});

const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const branchId = ref(props.filters.branch_id || "");

const applyFilter = () => {
    router.get("/reports/customers", {
        date_from: dateFrom.value, date_to: dateTo.value, branch_id: branchId.value || undefined,
    }, { preserveState: true });
};

const formatNumber = (n) => {
    if (n === null || n === undefined || isNaN(n) || n === 0) return "---";
    if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(2).replace(/\.?0+$/, "") + " triệu";
    if (Math.abs(n) >= 1e3) return (n / 1e3).toFixed(2).replace(/\.?0+$/, "") + " nghìn";
    return new Intl.NumberFormat("vi-VN").format(n);
};

const bd = computed(() => props.customerBreakdown || { old: { count: 0, revenue: 0 }, new: { count: 0, revenue: 0 }, walkin: { count: 0, revenue: 0 } });
const totalCount = computed(() => (bd.value.old?.count || 0) + (bd.value.new?.count || 0) + (bd.value.walkin?.count || 0));

const calcPercent = (val, total) => (total > 0 ? Math.round((val / total) * 100) : 0);

const customerPieData = computed(() => ({
    labels: ["Khách cũ", "Khách mới", "Khách lẻ"],
    datasets: [{
        data: [bd.value.old?.count || 0, bd.value.new?.count || 0, bd.value.walkin?.count || 0],
        backgroundColor: ["#3b82f6", "#22c55e", "#9ca3af"],
        borderWidth: 2,
        borderColor: "#fff",
    }],
}));

const revenuePieData = computed(() => ({
    labels: ["Khách cũ", "Khách mới", "Khách lẻ"],
    datasets: [{
        data: [bd.value.old?.revenue || 0, bd.value.new?.revenue || 0, bd.value.walkin?.revenue || 0],
        backgroundColor: ["#3b82f6", "#22c55e", "#9ca3af"],
        borderWidth: 2,
        borderColor: "#fff",
    }],
}));

const pieOptions = {
    responsive: true,
    maintainAspectRatio: true,
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx) => {
                    const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                    const pct = total > 0 ? Math.round((ctx.raw / total) * 100) : 0;
                    return ctx.label + ": " + pct + "%";
                },
            },
        },
    },
};

const countChartData = computed(() => ({
    labels: props.chart?.labels || [],
    datasets: [
        { label: "Khách cũ", data: props.chart?.countOld || [], borderColor: "#3b82f6", backgroundColor: "transparent", tension: 0.3 },
        { label: "Khách mới", data: props.chart?.countNew || [], borderColor: "#22c55e", backgroundColor: "transparent", tension: 0.3 },
        { label: "Khách lẻ", data: props.chart?.countWalkin || [], borderColor: "#9ca3af", backgroundColor: "transparent", tension: 0.3 },
    ],
}));

const revChartData = computed(() => ({
    labels: props.chart?.labels || [],
    datasets: [
        { label: "Khách cũ", data: props.chart?.revOld || [], borderColor: "#3b82f6", backgroundColor: "transparent", tension: 0.3 },
        { label: "Khách mới", data: props.chart?.revNew || [], borderColor: "#22c55e", backgroundColor: "transparent", tension: 0.3 },
        { label: "Khách lẻ", data: props.chart?.revWalkin || [], borderColor: "#9ca3af", backgroundColor: "transparent", tension: 0.3 },
    ],
}));

const lineOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { position: "bottom", labels: { usePointStyle: true } },
    },
    scales: {
        y: { ticks: { callback: (v) => formatNumber(v) } },
    },
};
</script>

<template>
    <AppLayout>
        <template #sidebar>
            <ReportSidebar currentPage="customers" />
        </template>

        <div class="max-w-[1200px] mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-xl font-bold text-gray-800">Tổng quan khách hàng</h1>
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2 bg-white border border-gray-300 rounded-lg px-3 py-1.5">
                        <input type="date" v-model="dateFrom" class="border-0 text-sm focus:ring-0 p-0 w-32" />
                        <span class="text-gray-400">-</span>
                        <input type="date" v-model="dateTo" class="border-0 text-sm focus:ring-0 p-0 w-32" />
                        <button @click="applyFilter" class="text-blue-600 hover:text-blue-800 ml-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </button>
                    </div>
                    <select v-model="branchId" @change="applyFilter" class="bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
                        <option value="">Tất cả chi nhánh</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </div>
            </div>

            <!-- Empty state -->
            <div v-if="totalCustomers === 0" class="bg-white rounded-lg border border-gray-200 p-8 text-center mb-6">
                <h2 class="text-lg font-bold text-gray-800 mb-2">Bạn chưa có giao dịch nào trong khoảng thời gian này</h2>
                <p class="text-gray-500 text-sm">Vui lòng thực hiện thêm các giao dịch để sử dụng tính năng Phân tích nâng cao dưới đây</p>
            </div>

            <!-- Pie Charts Row -->
            <div class="grid grid-cols-2 gap-4 mb-6">
                <!-- Tổng lượng khách -->
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h3 class="text-base font-semibold text-gray-700 mb-1">Tổng lượng khách</h3>
                    <div class="text-2xl font-bold text-gray-800 mb-4">{{ formatNumber(totalCustomers) }}</div>
                    <div class="flex items-center gap-6">
                        <div class="w-40 h-40">
                            <Pie :data="customerPieData" :options="pieOptions" />
                        </div>
                        <div class="space-y-2 text-sm">
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-3 rounded-sm bg-blue-500"></div>
                                <span class="text-gray-600">Khách cũ</span>
                                <span class="ml-auto text-gray-800 font-medium">{{ formatNumber(bd.old?.count) }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-3 rounded-sm bg-green-500"></div>
                                <span class="text-gray-600">Khách mới</span>
                                <span class="ml-auto text-gray-800 font-medium">{{ formatNumber(bd.new?.count) }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-3 rounded-sm bg-gray-400"></div>
                                <span class="text-gray-600">Khách lẻ</span>
                                <span class="ml-auto text-gray-800 font-medium">{{ formatNumber(bd.walkin?.count) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tổng doanh thu -->
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h3 class="text-base font-semibold text-gray-700 mb-1">Tổng doanh thu</h3>
                    <div class="text-2xl font-bold text-gray-800 mb-4">{{ formatNumber(totalRevenue) }}</div>
                    <div class="flex items-center gap-6">
                        <div class="w-40 h-40">
                            <Pie :data="revenuePieData" :options="pieOptions" />
                        </div>
                        <div class="space-y-2 text-sm">
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-3 rounded-sm bg-blue-500"></div>
                                <span class="text-gray-600">Khách cũ</span>
                                <span class="ml-auto text-gray-800 font-medium">{{ formatNumber(bd.old?.revenue) }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-3 rounded-sm bg-green-500"></div>
                                <span class="text-gray-600">Khách mới</span>
                                <span class="ml-auto text-gray-800 font-medium">{{ formatNumber(bd.new?.revenue) }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-3 rounded-sm bg-gray-400"></div>
                                <span class="text-gray-600">Khách lẻ</span>
                                <span class="ml-auto text-gray-800 font-medium">{{ formatNumber(bd.walkin?.revenue) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Line Charts Row -->
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h3 class="text-base font-semibold text-gray-700 mb-4">Lượng khách</h3>
                    <div style="height: 250px">
                        <Line :data="countChartData" :options="lineOptions" />
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h3 class="text-base font-semibold text-gray-700 mb-4">Doanh thu</h3>
                    <div style="height: 250px">
                        <Line :data="revChartData" :options="lineOptions" />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
