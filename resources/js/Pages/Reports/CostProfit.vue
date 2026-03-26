<script setup>
import { ref, computed } from "vue";
import { router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ReportSidebar from "@/Components/ReportSidebar.vue";
import { Pie } from "vue-chartjs";
import {
    Chart as ChartJS,
    ArcElement,
    Tooltip,
    Legend,
} from "chart.js";

ChartJS.register(ArcElement, Tooltip, Legend);

const props = defineProps({
    filters: Object,
    netRevenue: Number,
    grossProfit: Number,
    totalExpenses: Number,
    otherIncome: Number,
    netProfit: Number,
    expensePerDay: Number,
    costRevenueRatio: Number,
    prevExpenses: Number,
    prevCostRatio: Number,
    expenseCategories: Array,
    branches: Array,
});

const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const branchId = ref(props.filters.branch_id || "");

const applyFilter = () => {
    router.get(
        "/reports/cost-profit",
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

const formatCurrency = (n) => {
    return new Intl.NumberFormat("vi-VN").format(n || 0);
};

const pieColors = [
    "#3b82f6",
    "#22c55e",
    "#f59e0b",
    "#ef4444",
    "#8b5cf6",
    "#ec4899",
    "#14b8a6",
    "#f97316",
    "#6366f1",
    "#a855f7",
];

const pieData = computed(() => ({
    labels: (props.expenseCategories || []).map((c) => c.name),
    datasets: [
        {
            data: (props.expenseCategories || []).map((c) => c.total),
            backgroundColor: pieColors.slice(
                0,
                (props.expenseCategories || []).length,
            ),
            borderWidth: 2,
            borderColor: "#fff",
        },
    ],
}));

const pieOptions = {
    responsive: true,
    maintainAspectRatio: true,
    plugins: {
        legend: {
            position: "right",
            labels: { usePointStyle: true, font: { size: 12 } },
        },
        tooltip: {
            callbacks: {
                label: (ctx) =>
                    ctx.label +
                    ": " +
                    new Intl.NumberFormat("vi-VN").format(ctx.raw),
            },
        },
    },
};

const prevExpenseChange = computed(() => {
    if (!props.prevExpenses) return null;
    return Math.round(
        ((props.totalExpenses - props.prevExpenses) /
            Math.abs(props.prevExpenses)) *
            100,
    );
});

const summaryCards = computed(() => [
    { label: "Doanh thu thuần", value: props.netRevenue },
    { label: "Lợi nhuận gộp", value: props.grossProfit },
    { label: "Tổng chi phí", value: props.totalExpenses, highlight: true },
    { label: "Thu nhập khác", value: props.otherIncome },
    {
        label: "Lợi nhuận thuần",
        value: props.netProfit,
        color: props.netProfit < 0 ? "text-red-600" : "text-gray-800",
    },
]);
</script>

<template>
    <AppLayout>
        <template #sidebar>
            <ReportSidebar currentPage="cost-profit" />
        </template>

        <div class="max-w-[1200px] mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-xl font-bold text-gray-800">
                    Chi phí - Lợi nhuận
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

            <!-- Top summary cards -->
            <div class="flex gap-3 mb-6">
                <div
                    v-for="card in summaryCards"
                    :key="card.label"
                    class="flex-1 bg-white rounded-lg border border-gray-200 px-4 py-3"
                >
                    <div class="text-xs text-gray-500 mb-1">
                        {{ card.label }}
                    </div>
                    <div
                        class="text-lg font-bold"
                        :class="card.color || 'text-gray-800'"
                    >
                        {{ formatNumber(card.value) }}
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-6">
                <!-- Chi phí hoạt động -->
                <div>
                    <h3 class="text-base font-semibold text-gray-700 mb-3">
                        Chi phí hoạt động
                    </h3>
                    <div class="space-y-3">
                        <div
                            class="bg-white rounded-lg border border-gray-200 p-4"
                        >
                            <div class="text-xs text-gray-500 mb-1">
                                Tổng chi phí
                            </div>
                            <div class="text-xl font-bold text-gray-800">
                                {{ formatNumber(totalExpenses) }}
                            </div>
                            <div class="text-xs text-gray-400 mt-1">
                                So với kỳ trước:
                                <span
                                    :class="
                                        prevExpenseChange > 0
                                            ? 'text-red-500'
                                            : prevExpenseChange < 0
                                              ? 'text-green-500'
                                              : 'text-gray-500'
                                    "
                                >
                                    {{
                                        prevExpenseChange !== null
                                            ? (prevExpenseChange > 0
                                                  ? "+"
                                                  : "") +
                                              prevExpenseChange +
                                              "%"
                                            : "--%"
                                    }}
                                </span>
                            </div>
                        </div>
                        <div
                            class="bg-white rounded-lg border border-gray-200 p-4"
                        >
                            <div class="text-xs text-gray-500 mb-1">
                                Chi phí TB/ngày
                            </div>
                            <div class="text-xl font-bold text-gray-800">
                                {{ formatNumber(expensePerDay) }}
                            </div>
                        </div>
                        <div
                            class="bg-white rounded-lg border border-gray-200 p-4"
                        >
                            <div class="text-xs text-gray-500 mb-1">
                                Chi phí/doanh thu
                            </div>
                            <div class="text-xl font-bold text-gray-800">
                                {{ costRevenueRatio }}%
                            </div>
                            <div class="text-xs text-gray-400 mt-1">
                                So với kỳ trước:
                                <span class="text-gray-500"
                                    >{{ prevCostRatio }}%</span
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cơ cấu chi phí -->
                <div>
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-base font-semibold text-gray-700">
                            Cơ cấu chi phí
                        </h3>
                        <span class="text-sm text-blue-600 cursor-pointer"
                            >Chi tiết</span
                        >
                    </div>
                    <div
                        class="bg-white rounded-lg border border-gray-200 p-4"
                        style="min-height: 280px"
                    >
                        <Pie
                            v-if="
                                expenseCategories &&
                                expenseCategories.length > 0
                            "
                            :data="pieData"
                            :options="pieOptions"
                        />
                        <div
                            v-else
                            class="flex items-center justify-center h-60 text-gray-400"
                        >
                            Chưa có dữ liệu chi phí
                        </div>
                    </div>
                </div>
            </div>

            <!-- Expense category table -->
            <div class="bg-white rounded-lg border border-gray-200">
                <div
                    class="flex items-center justify-between px-5 py-3 border-b border-gray-200"
                >
                    <h3 class="text-base font-semibold text-gray-700">
                        Danh mục chi phí
                    </h3>
                    <span class="text-sm text-blue-600 cursor-pointer"
                        >Chi tiết</span
                    >
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th
                                class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase"
                            >
                                Tên chi phí
                            </th>
                            <th
                                class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase"
                            >
                                Tổng
                            </th>
                            <th
                                class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase"
                            >
                                % Chi phí/Doanh thu
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="cat in expenseCategories"
                            :key="cat.name"
                            class="border-t border-gray-100 hover:bg-gray-50"
                        >
                            <td class="px-5 py-3 font-medium text-gray-700">
                                {{ cat.name }}
                            </td>
                            <td class="px-5 py-3 text-right text-gray-600">
                                {{ formatCurrency(cat.total) }}
                            </td>
                            <td class="px-5 py-3 text-right text-gray-600">
                                {{ cat.percent }}%
                            </td>
                        </tr>
                        <tr
                            v-if="
                                !expenseCategories ||
                                expenseCategories.length === 0
                            "
                        >
                            <td
                                colspan="3"
                                class="px-5 py-8 text-center text-gray-400"
                            >
                                Chưa có dữ liệu chi phí
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
