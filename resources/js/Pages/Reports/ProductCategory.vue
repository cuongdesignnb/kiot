<script setup>
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ReportSidebar from "@/Components/ReportSidebar.vue";

const props = defineProps({
    filters: Object,
    categories: Array,
    branches: Array,
});

const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const branchId = ref(props.filters.branch_id || "");

const applyFilter = () => {
    router.get("/reports/product-categories", {
        date_from: dateFrom.value, date_to: dateTo.value, branch_id: branchId.value || undefined,
    }, { preserveState: true });
};

const formatNumber = (n) => {
    if (n === null || n === undefined || isNaN(n) || n === 0) return "---";
    return new Intl.NumberFormat("vi-VN").format(n);
};
</script>

<template>
    <AppLayout>
        <template #sidebar>
            <ReportSidebar currentPage="product-categories" />
        </template>

        <div class="max-w-[1200px] mx-auto">
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-xl font-bold text-gray-800">Phân loại hàng hóa</h1>
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

            <div class="bg-white rounded-lg border border-gray-200">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Tên nhóm hàng</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Số lượng thực bán</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Số lượng trả</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Doanh thu thuần</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Lợi nhuận gộp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="cat in categories" :key="cat.name" class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-5 py-3 font-medium text-gray-700">{{ cat.name }}</td>
                            <td class="px-5 py-3 text-right text-gray-600">{{ formatNumber(cat.sold) }}</td>
                            <td class="px-5 py-3 text-right text-gray-600">{{ formatNumber(cat.returns) }}</td>
                            <td class="px-5 py-3 text-right text-gray-600">{{ formatNumber(cat.revenue) }}</td>
                            <td class="px-5 py-3 text-right text-gray-600">{{ formatNumber(cat.profit) }}</td>
                        </tr>
                        <tr v-if="!categories || categories.length === 0">
                            <td colspan="5" class="px-5 py-8 text-center text-gray-400">Chưa có dữ liệu</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
