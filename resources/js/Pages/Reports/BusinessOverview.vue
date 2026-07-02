<script setup>
import { ref, computed, onMounted, watch } from "vue";
import { router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ReportSidebar from "@/Components/ReportSidebar.vue";
import { Line } from "vue-chartjs";
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler,
} from "chart.js";

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler,
);

const props = defineProps({
    filters: Object,
    invoiceCount: Number,
    revenue: Number,
    returns: Number,
    netRevenue: Number,
    totalCost: Number,
    grossProfit: Number,
    avgPerDay: Object,
    prevPeriod: Object,
    chart: Object,
    branches: Array,
});

const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const branchId = ref(props.filters.branch_id || "");

const applyFilter = () => {
    router.get(
        "/reports/business",
        {
            date_from: dateFrom.value,
            date_to: dateTo.value,
            branch_id: branchId.value || undefined,
        },
        { preserveState: true },
    );
};

const formatNumber = (n) => {
    if (n === null || n === undefined || isNaN(n)) return "---";
    if (Math.abs(n) >= 1e9)
        return (n / 1e9).toFixed(2).replace(/\.?0+$/, "") + " tỷ";
    if (Math.abs(n) >= 1e6)
        return (n / 1e6).toFixed(2).replace(/\.?0+$/, "") + " triệu";
    if (Math.abs(n) >= 1e3)
        return (n / 1e3).toFixed(2).replace(/\.?0+$/, "") + " nghìn";
    return new Intl.NumberFormat("vi-VN").format(n);
};

const calcChange = (current, prev) => {
    if (!prev || prev === 0) return null;
    return Math.round(((current - prev) / Math.abs(prev)) * 100);
};

const cards = computed(() => [
    {
        label: "Số hóa đơn",
        value: props.invoiceCount,
        avg: props.avgPerDay?.invoiceCount,
        prev: props.prevPeriod?.invoiceCount,
        format: "number",
    },
    {
        label: "Doanh thu",
        value: props.revenue,
        avg: props.avgPerDay?.revenue,
        prev: props.prevPeriod?.revenue,
        format: "currency",
    },
    {
        label: "Trả hàng",
        value: props.returns,
        avg: props.avgPerDay?.returns,
        prev: props.prevPeriod?.returns,
        format: "currency",
    },
    {
        label: "Doanh thu thuần",
        value: props.netRevenue,
        avg: props.avgPerDay?.netRevenue,
        prev: props.prevPeriod?.netRevenue,
        format: "currency",
    },
    {
        label: "Tổng giá vốn",
        value: props.totalCost,
        avg: props.avgPerDay?.totalCost,
        prev: props.prevPeriod?.totalCost,
        format: "currency",
    },
    {
        label: "Lợi nhuận gộp",
        value: props.grossProfit,
        avg: props.avgPerDay?.grossProfit,
        prev: props.prevPeriod?.grossProfit,
        format: "currency",
    },
]);

const chartData = computed(() => ({
    labels: props.chart?.labels || [],
    datasets: [
        {
            label: "Doanh thu",
            data: props.chart?.revenue || [],
            borderColor: "#3b82f6",
            backgroundColor: "rgba(59,130,246,0.1)",
            fill: true,
            tension: 0.3,
        },
        {
            label: "Trả hàng",
            data: props.chart?.returns || [],
            borderColor: "#ef4444",
            backgroundColor: "transparent",
            tension: 0.3,
        },
        {
            label: "Tổng giá vốn",
            data: props.chart?.cost || [],
            borderColor: "#f59e0b",
            backgroundColor: "transparent",
            tension: 0.3,
        },
        {
            label: "Lợi nhuận gộp",
            data: props.chart?.profit || [],
            borderColor: "#22c55e",
            backgroundColor: "transparent",
            tension: 0.3,
        },
    ],
}));

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { position: "bottom", labels: { usePointStyle: true } },
        tooltip: {
            callbacks: {
                label: (ctx) =>
                    ctx.dataset.label +
                    ": " +
                    new Intl.NumberFormat("vi-VN").format(ctx.raw),
            },
        },
    },
    scales: {
        y: {
            ticks: {
                callback: (v) => formatNumber(v),
            },
        },
    },
};
</script>

<template>
    <AppLayout>
        <template #sidebar>
            <ReportSidebar currentPage="business" />
        </template>

        <div class="max-w-[1200px] mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-xl font-bold text-gray-800">
                    Tổng quan kinh doanh
                </h1>
                <div class="flex items-center gap-3">
                    <div
                        class="flex items-center gap-2 bg-white border border-gray-300 rounded-lg px-3 py-1.5"
                    >
                        <input
                            type="date"
                            v-model="dateFrom"
                            class="border-0 text-sm focus:ring-0 p-0 w-32"
                        />
                        <span class="text-gray-400">-</span>
                        <input
                            type="date"
                            v-model="dateTo"
                            class="border-0 text-sm focus:ring-0 p-0 w-32"
                        />
                        <button
                            @click="applyFilter"
                            class="text-blue-600 hover:text-blue-800 ml-1"
                        >
                            <svg
                                class="w-4 h-4"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                                ></path>
                            </svg>
                        </button>
                    </div>
                    <select
                        v-model="branchId"
                        @change="applyFilter"
                        class="bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-sm"
                    >
                        <option value="">Tất cả chi nhánh</option>
                        <option
                            v-for="b in branches"
                            :key="b.id"
                            :value="b.id"
                        >
                            {{ b.name }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- Metric Cards Grid -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div
                    v-for="card in cards"
                    :key="card.label"
                    class="bg-white rounded-lg border border-gray-200 p-5"
                >
                    <div class="text-sm text-gray-500 mb-2">
                        {{ card.label }}
                    </div>
                    <div class="text-2xl font-bold text-gray-800 mb-3">
                        {{
                            card.format === "number"
                                ? formatNumber(card.value)
                                : formatNumber(card.value)
                        }}
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <div>
                            <span class="text-gray-400"
                                >Trung bình/ngày</span
                            >
                            <div class="text-gray-600 font-medium">
                                {{ formatNumber(card.avg) }}
                            </div>
                        </div>
                        <div>
                            <span class="text-gray-400"
                                >So với kỳ trước</span
                            >
                            <div
                                class="font-medium"
                                :class="
                                    calcChange(card.value, card.prev) > 0
                                        ? 'text-green-600'
                                        : calcChange(card.value, card.prev) < 0
                                          ? 'text-red-600'
                                          : 'text-gray-500'
                                "
                            >
                                {{
                                    calcChange(card.value, card.prev) !== null
                                        ? (calcChange(card.value, card.prev) > 0
                                              ? "+"
                                              : "") +
                                          calcChange(card.value, card.prev) +
                                          "%"
                                        : "---"
                                }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Chart -->
            <div class="bg-white rounded-lg border border-gray-200 p-5">
                <h3 class="text-base font-semibold text-gray-700 mb-4">
                    Chỉ số kinh doanh
                </h3>
                <div style="height: 350px">
                    <Line :data="chartData" :options="chartOptions" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
