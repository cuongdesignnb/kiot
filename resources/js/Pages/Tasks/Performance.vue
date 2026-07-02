<script setup>
import { ref, watch, computed } from "vue";
import { Head } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import axios from "axios";

const props = defineProps({ employees: Array });

const now = new Date();
const filters = ref({
    employee_id: "",
    month: now.getMonth() + 1,
    year: now.getFullYear(),
});
const data = ref([]);
const loading = ref(false);
const sortField = ref("total");
const sortDir = ref("desc");

const months = Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: `Tháng ${i + 1}` }));
const years = Array.from({ length: 5 }, (_, i) => ({ value: now.getFullYear() - 2 + i, label: `${now.getFullYear() - 2 + i}` }));

const load = async () => {
    loading.value = true;
    try {
        const res = await axios.get("/api/tasks/performance", { params: filters.value });
        data.value = res.data || [];
    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
};

const sorted = computed(() => {
    const arr = [...data.value];
    arr.sort((a, b) => {
        const av = a[sortField.value] || 0;
        const bv = b[sortField.value] || 0;
        return sortDir.value === "asc" ? av - bv : bv - av;
    });
    return arr;
});

const toggleSort = (field) => {
    if (sortField.value === field) sortDir.value = sortDir.value === "asc" ? "desc" : "asc";
    else { sortField.value = field; sortDir.value = "desc"; }
};

const sortIcon = (field) => sortField.value === field ? (sortDir.value === "asc" ? "↑" : "↓") : "";

const tierBadge = (completedCount) => {
    if (completedCount >= 20) return { label: "Xuất sắc", cls: "bg-yellow-100 text-yellow-700" };
    if (completedCount >= 10) return { label: "Tốt", cls: "bg-green-100 text-green-700" };
    if (completedCount >= 5) return { label: "Khá", cls: "bg-blue-100 text-blue-700" };
    return { label: "Mới", cls: "bg-gray-100 text-gray-600" };
};

watch(filters, load, { deep: true, immediate: true });
</script>

<template>
    <Head title="Hiệu suất công việc" />
    <AppLayout>
        <div class="p-6">
            <h1 class="text-xl font-bold text-gray-800 mb-4">Hiệu suất nhân viên</h1>

            <!-- Filters -->
            <div class="flex flex-wrap gap-3 mb-4 bg-white border rounded-lg p-4">
                <select v-model="filters.month" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none">
                    <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
                <select v-model="filters.year" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none">
                    <option v-for="y in years" :key="y.value" :value="y.value">{{ y.label }}</option>
                </select>
                <select v-model="filters.employee_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none">
                    <option value="">Tất cả nhân viên</option>
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                </select>
            </div>

            <!-- Table -->
            <div class="bg-white border rounded-lg shadow-sm overflow-hidden">
                <div v-if="loading" class="text-center py-16 text-gray-400">Đang tải...</div>
                <table v-else class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-3 text-left">Nhân viên</th>
                            <th class="px-4 py-3 text-center cursor-pointer select-none" @click="toggleSort('total')">Tổng {{ sortIcon('total') }}</th>
                            <th class="px-4 py-3 text-center cursor-pointer select-none" @click="toggleSort('completed')">Hoàn thành {{ sortIcon('completed') }}</th>
                            <th class="px-4 py-3 text-center cursor-pointer select-none" @click="toggleSort('in_progress')">Đang làm {{ sortIcon('in_progress') }}</th>
                            <th class="px-4 py-3 text-center cursor-pointer select-none" @click="toggleSort('cancelled')">Đã hủy {{ sortIcon('cancelled') }}</th>
                            <th class="px-4 py-3 text-center">Tỉ lệ hoàn thành</th>
                            <th class="px-4 py-3 text-center">Hạng</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!sorted.length"><td colspan="7" class="text-center py-10 text-gray-400">Không có dữ liệu.</td></tr>
                        <tr v-for="row in sorted" :key="row.employee_id" class="border-t hover:bg-gray-50">
                            <td class="px-4 py-3 font-semibold">{{ row.employee_name }}</td>
                            <td class="px-4 py-3 text-center">{{ row.total || 0 }}</td>
                            <td class="px-4 py-3 text-center text-green-600 font-semibold">{{ row.completed || 0 }}</td>
                            <td class="px-4 py-3 text-center text-blue-600">{{ row.in_progress || 0 }}</td>
                            <td class="px-4 py-3 text-center text-red-500">{{ row.cancelled || 0 }}</td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex items-center gap-2 justify-center">
                                    <div class="w-24 bg-gray-200 rounded-full h-2">
                                        <div class="h-2 rounded-full bg-green-500 transition-all" :style="{ width: (row.total ? Math.round(row.completed / row.total * 100) : 0) + '%' }"></div>
                                    </div>
                                    <span class="text-xs font-semibold">{{ row.total ? Math.round(row.completed / row.total * 100) : 0 }}%</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span :class="tierBadge(row.completed).cls" class="px-2 py-0.5 rounded-full text-xs font-semibold">{{ tierBadge(row.completed).label }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
