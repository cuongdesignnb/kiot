<script setup>
import { ref, computed } from "vue";
import { router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ReportSidebar from "@/Components/ReportSidebar.vue";
import { Pie, Bar } from "vue-chartjs";
import {
    Chart as ChartJS,
    ArcElement,
    CategoryScale,
    LinearScale,
    BarElement,
    Tooltip,
    Legend,
} from "chart.js";

ChartJS.register(ArcElement, CategoryScale, LinearScale, BarElement, Tooltip, Legend);

const props = defineProps({
    filters: Object,
    segments: Array,
    totalCustomers: Number,
    branches: Array,
});

const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const branchId = ref(props.filters.branch_id || "");

const applyFilter = () => {
    router.get("/reports/customer-categories", {
        date_from: dateFrom.value, date_to: dateTo.value, branch_id: branchId.value || undefined,
    }, { preserveState: true });
};

const formatNumber = (n) => {
    if (n === null || n === undefined || isNaN(n) || n === 0) return "---";
    if (Math.abs(n) >= 1e9) return (n / 1e9).toFixed(2).replace(/\.?0+$/, "") + " tỷ";
    if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(2).replace(/\.?0+$/, "") + " triệu";
    if (Math.abs(n) >= 1e3) return (n / 1e3).toFixed(2).replace(/\.?0+$/, "") + " nghìn";
    return new Intl.NumberFormat("vi-VN").format(n);
};

const segmentColors = computed(() => (props.segments || []).map(s => s.color));
const segmentNames = computed(() => (props.segments || []).map(s => s.name));

// Pie chart
const pieData = computed(() => ({
    labels: segmentNames.value,
    datasets: [{
        data: (props.segments || []).map(s => s.count),
        backgroundColor: segmentColors.value,
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
                    return ctx.label + ": " + ctx.raw + " (" + pct + "%)";
                },
            },
        },
    },
};

// Horizontal bar charts
const makeBarData = (field) => ({
    labels: segmentNames.value,
    datasets: [{
        data: (props.segments || []).map(s => s[field]),
        backgroundColor: segmentColors.value,
        borderWidth: 0,
        borderRadius: 4,
        barPercentage: 0.6,
    }],
});

const revenueBarData = computed(() => makeBarData("revenue"));
const returnsBarData = computed(() => makeBarData("returns"));
const profitBarData = computed(() => makeBarData("profit"));

const barOptions = {
    responsive: true,
    maintainAspectRatio: false,
    indexAxis: "y",
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx) => formatNumber(ctx.raw),
            },
        },
    },
    scales: {
        x: {
            ticks: { callback: (v) => formatNumber(v) },
        },
    },
};
</script>

<template>
    <AppLayout>
        <template #sidebar>
            <ReportSidebar currentPage="customer-categories" />
        </template>

        <div class="max-w-[1200px] mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-2">
                <h1 class="text-xl font-bold text-gray-800">Phân loại khách hàng</h1>
                <div class="flex items-center gap-3">
                    <select v-model="branchId" @change="applyFilter" class="bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
                        <option value="">Tất cả chi nhánh</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </div>
            </div>
            <p class="text-sm text-gray-500 mb-6">
                Dữ liệu được tổng hợp trên danh sách khách hàng và các giao dịch từ {{ dateFrom }} đến {{ dateTo }}
            </p>

            <!-- Nhóm khách hàng — Pie + Segment cards -->
            <div class="bg-white rounded-lg border border-gray-200 p-5 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-gray-700">Nhóm khách hàng</h3>
                    <span class="text-sm text-blue-600 cursor-pointer">Chi tiết</span>
                </div>

                <div class="flex gap-6">
                    <!-- Pie chart -->
                    <div class="w-60 h-60 flex-shrink-0">
                        <Pie :data="pieData" :options="pieOptions" />
                    </div>

                    <!-- Segment labels with descriptions -->
                    <div class="flex-1 space-y-3">
                        <div v-for="seg in segments" :key="seg.name" class="flex items-start gap-3 border-b border-gray-100 pb-3 last:border-0">
                            <div class="flex items-center gap-2 w-28 flex-shrink-0">
                                <div class="w-3 h-3 rounded-sm flex-shrink-0" :style="{ backgroundColor: seg.color }"></div>
                                <span class="text-sm font-semibold" :style="{ color: seg.color }">{{ seg.name }}</span>
                            </div>
                            <div class="text-lg font-bold text-gray-800 w-12 flex-shrink-0 text-right">{{ seg.count }}</div>
                            <div class="text-xs text-gray-500 leading-relaxed flex-1">{{ seg.desc }}</div>
                        </div>
                    </div>
                </div>

                <!-- Percent labels on pie -->
                <div class="flex gap-4 mt-4 justify-center">
                    <div v-for="seg in segments" :key="'pct-' + seg.name" class="flex items-center gap-1.5 text-xs">
                        <div class="w-2.5 h-2.5 rounded-sm" :style="{ backgroundColor: seg.color }"></div>
                        <span class="text-gray-600">{{ seg.name }}</span>
                        <span class="font-semibold text-gray-800">{{ seg.percent }}%</span>
                    </div>
                </div>
            </div>

            <!-- Bar Charts Grid -->
            <div class="grid grid-cols-2 gap-4 mb-6">
                <!-- Doanh thu -->
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h3 class="text-base font-semibold text-gray-700 mb-3">Doanh thu</h3>
                    <div style="height: 220px">
                        <Bar :data="revenueBarData" :options="barOptions" />
                    </div>
                </div>

                <!-- Trả hàng -->
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h3 class="text-base font-semibold text-gray-700 mb-3">Trả hàng</h3>
                    <div style="height: 220px">
                        <Bar :data="returnsBarData" :options="barOptions" />
                    </div>
                </div>

                <!-- Lợi nhuận gộp -->
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h3 class="text-base font-semibold text-gray-700 mb-3">Lợi nhuận gộp</h3>
                    <div style="height: 220px">
                        <Bar :data="profitBarData" :options="barOptions" />
                    </div>
                </div>

                <!-- Empty slot or future chart -->
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h3 class="text-base font-semibold text-gray-700 mb-3">Kênh bán</h3>
                    <div style="height: 220px" class="flex items-center justify-center text-gray-400 text-sm">
                        Chưa có dữ liệu kênh bán
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
