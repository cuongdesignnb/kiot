<script setup>
import { ref, computed } from "vue";
import { router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ReportSidebar from "@/Components/ReportSidebar.vue";

const props = defineProps({
    filters: Object,
    uniqueProductsSold: Number,
    totalItemsSold: Number,
    avgRevenuePerProduct: Number,
    avgProfitPerProduct: Number,
    topGroupsBestSeller: Array,
    topGroupsSlowSeller: Array,
    branches: Array,
});

const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const branchId = ref(props.filters.branch_id || "");
const activeTab = ref("best");

const applyFilter = () => {
    router.get(
        "/reports/products",
        {
            date_from: dateFrom.value,
            date_to: dateTo.value,
            branch_id: branchId.value || undefined,
        },
        { preserveState: true },
    );
};

const formatNumber = (n) => {
    if (n === null || n === undefined || isNaN(n) || n === 0) return "---";
    return new Intl.NumberFormat("vi-VN").format(n);
};

const topGroups = computed(() =>
    activeTab.value === "best"
        ? props.topGroupsBestSeller
        : props.topGroupsSlowSeller,
);

const cards = computed(() => [
    { label: "Số mặt hàng đã bán", value: props.uniqueProductsSold, color: "#3b82f6" },
    { label: "Số mặt hàng đã bán", value: props.totalItemsSold, color: "#22c55e" },
    { label: "Doanh thu thuần TB/SP", value: props.avgRevenuePerProduct, color: "#f59e0b" },
    { label: "Lợi nhuận gộp TB/SP", value: props.avgProfitPerProduct, color: "#8b5cf6" },
]);
</script>

<template>
    <AppLayout>
        <template #sidebar>
            <ReportSidebar currentPage="products" />
        </template>

        <div class="max-w-[1200px] mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-xl font-bold text-gray-800">
                    Tổng quan hàng hóa
                </h1>
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

            <!-- Empty state message -->
            <div v-if="uniqueProductsSold === 0" class="bg-white rounded-lg border border-gray-200 p-8 text-center mb-6">
                <h2 class="text-lg font-bold text-gray-800 mb-2">Bạn chưa có hàng hóa nào trong gian hàng</h2>
                <p class="text-gray-500 text-sm">Vui lòng thêm mới hàng hóa để sử dụng tính năng Phân tích nâng cao dưới đây</p>
            </div>

            <!-- Metric Cards -->
            <div class="grid grid-cols-4 gap-4 mb-6">
                <div v-for="card in cards" :key="card.label" class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="text-sm text-gray-500 mb-2">{{ card.label }}</div>
                    <div class="text-2xl font-bold text-gray-800 mb-3">{{ formatNumber(card.value) }}</div>
                    <div class="h-1 rounded-full" :style="{ backgroundColor: card.color, opacity: 0.3 }">
                        <div class="h-1 rounded-full" :style="{ backgroundColor: card.color, width: '60%' }"></div>
                    </div>
                </div>
            </div>

            <!-- Top Groups Table -->
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-3 border-b border-gray-200">
                    <h3 class="text-base font-semibold text-gray-700 mb-3">Top 10% nhóm hàng</h3>
                    <div class="flex gap-1">
                        <button @click="activeTab = 'best'"
                            class="px-4 py-1.5 text-sm rounded-md transition-colors"
                            :class="activeTab === 'best' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'">
                            Bán chạy
                        </button>
                        <button @click="activeTab = 'slow'"
                            class="px-4 py-1.5 text-sm rounded-md transition-colors"
                            :class="activeTab === 'slow' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'">
                            Bán chậm
                        </button>
                    </div>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Tên nhóm hàng</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Số lượng thực bán ▼</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Số lượng trả</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Doanh thu thuần</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Lợi nhuận gộp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="group in topGroups" :key="group.name" class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-5 py-3 text-gray-700">{{ group.name }}</td>
                            <td class="px-5 py-3 text-right text-gray-600">{{ formatNumber(group.qty) }}</td>
                            <td class="px-5 py-3 text-right text-gray-600">{{ formatNumber(group.returns) }}</td>
                            <td class="px-5 py-3 text-right text-gray-600">{{ formatNumber(group.revenue) }}</td>
                            <td class="px-5 py-3 text-right text-gray-600">{{ formatNumber(group.profit) }}</td>
                        </tr>
                        <tr v-if="!topGroups || topGroups.length === 0">
                            <td colspan="5" class="px-5 py-8 text-center text-gray-400">Chưa có dữ liệu</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
