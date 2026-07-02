<script setup>
import { ref } from "vue";
import { Head } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import axios from "axios";

const props = defineProps({
    employees: Array,
});

const now = new Date();
const month = ref(now.getMonth() + 1);
const year = ref(now.getFullYear());
const results = ref([]);
const loading = ref(false);

const loadPerformance = async () => {
    loading.value = true;
    try {
        const res = await axios.get("/api/device-repairs/performance", {
            params: { month: month.value, year: year.value },
        });
        results.value = res.data || [];
    } catch (e) {
        console.error(e);
        results.value = [];
    } finally {
        loading.value = false;
    }
};

const months = Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: `Tháng ${i + 1}` }));
const years = Array.from({ length: 6 }, (_, i) => now.getFullYear() - 2 + i);

loadPerformance();
</script>

<template>
    <Head title="Báo cáo năng suất sửa chữa" />
    <AppLayout>
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-xl font-bold text-gray-800">Báo cáo năng suất sửa chữa</h1>
                <a href="/repairs" class="text-sm text-blue-600 hover:underline">&larr; DS phiếu sửa</a>
            </div>

            <!-- Filter -->
            <div class="flex gap-3 mb-4 items-center">
                <select v-model="month" @change="loadPerformance" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
                <select v-model="year" @change="loadPerformance" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                </select>
            </div>

            <!-- Table -->
            <div class="bg-white border rounded-lg shadow-sm overflow-hidden">
                <div v-if="loading" class="text-center py-10 text-gray-400">Đang tải...</div>
                <table v-else class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-3 text-left">Nhân viên</th>
                            <th class="px-4 py-3 text-center">Được giao</th>
                            <th class="px-4 py-3 text-center">Hoàn thành</th>
                            <th class="px-4 py-3 text-center">Tỷ lệ %</th>
                            <th class="px-4 py-3 text-center">Xếp loại</th>
                            <th class="px-4 py-3 text-center">Hệ số lương %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!results.length">
                            <td colspan="6" class="text-center py-8 text-gray-400">Không có dữ liệu trong kỳ này.</td>
                        </tr>
                        <tr v-for="r in results" :key="r.employee_id" class="border-t">
                            <td class="px-4 py-3 font-semibold">{{ r.employee_name }}</td>
                            <td class="px-4 py-3 text-center">{{ r.assigned }}</td>
                            <td class="px-4 py-3 text-center">{{ r.completed }}</td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <div class="w-24 bg-gray-200 rounded-full h-2">
                                        <div
                                            class="h-2 rounded-full"
                                            :class="r.completion_rate >= 80 ? 'bg-green-500' : r.completion_rate >= 50 ? 'bg-yellow-500' : 'bg-red-500'"
                                            :style="{ width: Math.min(r.completion_rate, 100) + '%' }"
                                        ></div>
                                    </div>
                                    <span class="font-semibold">{{ r.completion_rate }}%</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span
                                    class="px-2 py-0.5 rounded-full text-xs font-semibold"
                                    :class="r.tier?.salary_percent >= 90 ? 'bg-green-100 text-green-700' : r.tier?.salary_percent >= 70 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-600'"
                                >{{ r.tier?.label || 'N/A' }}</span>
                            </td>
                            <td class="px-4 py-3 text-center font-bold">{{ r.tier?.salary_percent ?? '-' }}%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
