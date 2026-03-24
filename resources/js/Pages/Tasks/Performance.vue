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
const expandedId = ref(null);

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

const tierBadge = (rate) => {
    if (rate >= 90) return { label: "Xuất sắc", cls: "bg-yellow-100 text-yellow-700 border border-yellow-300" };
    if (rate >= 70) return { label: "Tốt", cls: "bg-green-100 text-green-700 border border-green-300" };
    if (rate >= 50) return { label: "Khá", cls: "bg-blue-100 text-blue-700 border border-blue-300" };
    if (rate >= 30) return { label: "Trung bình", cls: "bg-orange-100 text-orange-700 border border-orange-300" };
    return { label: "Cần cải thiện", cls: "bg-red-100 text-red-600 border border-red-300" };
};

const statusBadge = (status) => {
    const map = {
        pending: { label: "Chờ", cls: "bg-yellow-100 text-yellow-700" },
        in_progress: { label: "Đang làm", cls: "bg-blue-100 text-blue-700" },
        completed: { label: "Hoàn thành", cls: "bg-green-100 text-green-700" },
        cancelled: { label: "Đã hủy", cls: "bg-red-100 text-red-600" },
    };
    return map[status] || { label: status, cls: "bg-gray-100 text-gray-600" };
};

const progressColor = (val) => {
    if (val >= 80) return "bg-green-500";
    if (val >= 50) return "bg-blue-500";
    if (val >= 20) return "bg-yellow-500";
    return "bg-red-400";
};

const formatDate = (dt) => {
    if (!dt) return "—";
    const d = new Date(dt);
    return d.toLocaleDateString("vi-VN", { day: "2-digit", month: "2-digit" });
};

const isOverdue = (deadline, status) => {
    if (!deadline || status === "completed" || status === "cancelled") return false;
    return new Date(deadline) < new Date();
};

const toggleExpand = (id) => {
    expandedId.value = expandedId.value === id ? null : id;
};

// Summary cards
const summary = computed(() => {
    const total = data.value.reduce((s, r) => s + (r.total || 0), 0);
    const completed = data.value.reduce((s, r) => s + (r.completed || 0), 0);
    const inProgress = data.value.reduce((s, r) => s + (r.in_progress || 0), 0);
    const overdue = data.value.reduce((s, r) => s + (r.overdue || 0), 0);
    const rate = total > 0 ? Math.round(completed / total * 100) : 0;
    return { total, completed, inProgress, overdue, rate };
});

watch(filters, load, { deep: true, immediate: true });
</script>

<template>
    <Head title="Hiệu suất công việc" />
    <AppLayout>
        <div class="p-6 max-w-[1400px] mx-auto">
            <h1 class="text-xl font-bold text-gray-800 mb-4">📊 Hiệu suất nhân viên</h1>

            <!-- Filters -->
            <div class="flex flex-wrap gap-3 mb-5 bg-white border rounded-lg p-4 shadow-sm">
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

            <!-- Summary Cards -->
            <div v-if="!loading && data.length > 0" class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">
                <div class="bg-white border rounded-lg p-4 shadow-sm text-center">
                    <div class="text-2xl font-bold text-gray-800">{{ summary.total }}</div>
                    <div class="text-xs text-gray-500 mt-1">Tổng việc giao</div>
                </div>
                <div class="bg-white border rounded-lg p-4 shadow-sm text-center">
                    <div class="text-2xl font-bold text-green-600">{{ summary.completed }}</div>
                    <div class="text-xs text-gray-500 mt-1">Hoàn thành</div>
                </div>
                <div class="bg-white border rounded-lg p-4 shadow-sm text-center">
                    <div class="text-2xl font-bold text-blue-600">{{ summary.inProgress }}</div>
                    <div class="text-xs text-gray-500 mt-1">Đang xử lý</div>
                </div>
                <div class="bg-white border rounded-lg p-4 shadow-sm text-center">
                    <div class="text-2xl font-bold" :class="summary.overdue > 0 ? 'text-red-600' : 'text-gray-400'">{{ summary.overdue }}</div>
                    <div class="text-xs text-gray-500 mt-1">Quá hạn</div>
                </div>
                <div class="bg-white border rounded-lg p-4 shadow-sm text-center">
                    <div class="text-2xl font-bold" :class="summary.rate >= 70 ? 'text-green-600' : summary.rate >= 40 ? 'text-yellow-600' : 'text-red-600'">{{ summary.rate }}%</div>
                    <div class="text-xs text-gray-500 mt-1">Tỷ lệ hoàn thành</div>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white border rounded-lg shadow-sm overflow-hidden">
                <div v-if="loading" class="text-center py-16 text-gray-400">Đang tải...</div>
                <table v-else class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-3 text-left">Nhân viên</th>
                            <th class="px-4 py-3 text-center cursor-pointer select-none hover:bg-gray-100" @click="toggleSort('total')">
                                Tổng giao {{ sortIcon('total') }}
                            </th>
                            <th class="px-4 py-3 text-center cursor-pointer select-none hover:bg-gray-100" @click="toggleSort('completed')">
                                Hoàn thành {{ sortIcon('completed') }}
                            </th>
                            <th class="px-4 py-3 text-center cursor-pointer select-none hover:bg-gray-100" @click="toggleSort('in_progress')">
                                Đang làm {{ sortIcon('in_progress') }}
                            </th>
                            <th class="px-4 py-3 text-center cursor-pointer select-none hover:bg-gray-100" @click="toggleSort('overdue')">
                                Quá hạn {{ sortIcon('overdue') }}
                            </th>
                            <th class="px-4 py-3 text-center">Tiến độ TB</th>
                            <th class="px-4 py-3 text-center cursor-pointer select-none hover:bg-gray-100" @click="toggleSort('completion_rate')">
                                Tỷ lệ HT {{ sortIcon('completion_rate') }}
                            </th>
                            <th class="px-4 py-3 text-center">Xếp loại</th>
                            <th class="px-4 py-3 text-center w-10"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!sorted.length"><td colspan="9" class="text-center py-10 text-gray-400">Không có dữ liệu trong kỳ này.</td></tr>
                        <template v-for="row in sorted" :key="row.employee_id">
                            <tr class="border-t hover:bg-gray-50 cursor-pointer" @click="toggleExpand(row.employee_id)">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-gray-800">{{ row.employee_name }}</div>
                                </td>
                                <td class="px-4 py-3 text-center font-bold text-gray-700">{{ row.total || 0 }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span class="text-green-600 font-bold">{{ row.completed || 0 }}</span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="text-blue-600 font-semibold">{{ row.in_progress || 0 }}</span>
                                    <span v-if="row.pending > 0" class="text-yellow-600 text-xs ml-1">(+{{ row.pending }} chờ)</span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span v-if="row.overdue > 0" class="text-red-600 font-bold bg-red-50 px-2 py-0.5 rounded">{{ row.overdue }}</span>
                                    <span v-else class="text-gray-300">0</span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center gap-1.5 justify-center">
                                        <div class="w-16 bg-gray-200 rounded-full h-1.5">
                                            <div class="h-1.5 rounded-full transition-all" :class="progressColor(row.avg_progress || 0)" :style="{ width: (row.avg_progress || 0) + '%' }"></div>
                                        </div>
                                        <span class="text-xs font-semibold text-gray-600 w-8 text-right">{{ row.avg_progress || 0 }}%</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center gap-2 justify-center">
                                        <div class="w-20 bg-gray-200 rounded-full h-2">
                                            <div class="h-2 rounded-full transition-all"
                                                :class="row.completion_rate >= 70 ? 'bg-green-500' : row.completion_rate >= 40 ? 'bg-yellow-500' : 'bg-red-500'"
                                                :style="{ width: Math.min(row.completion_rate, 100) + '%' }">
                                            </div>
                                        </div>
                                        <span class="text-xs font-bold w-10 text-right" :class="row.completion_rate >= 70 ? 'text-green-600' : row.completion_rate >= 40 ? 'text-yellow-600' : 'text-red-600'">
                                            {{ row.completion_rate }}%
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span :class="tierBadge(row.completion_rate).cls" class="px-2 py-0.5 rounded-full text-xs font-semibold">
                                        {{ tierBadge(row.completion_rate).label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <svg class="w-4 h-4 text-gray-400 transition-transform" :class="expandedId === row.employee_id ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </td>
                            </tr>

                            <!-- Expanded: Task list -->
                            <tr v-if="expandedId === row.employee_id && row.tasks?.length > 0">
                                <td colspan="9" class="bg-gray-50 px-6 py-3">
                                    <div class="text-xs font-bold text-gray-500 uppercase mb-2">Chi tiết công việc ({{ row.tasks.length }})</div>
                                    <div class="grid gap-2">
                                        <div v-for="t in row.tasks" :key="t.id"
                                            class="flex items-center gap-3 bg-white border rounded-lg px-3 py-2 text-sm hover:shadow-sm transition">
                                            <!-- Code + link -->
                                            <a :href="`/tasks/${t.id}`" class="text-blue-600 font-semibold text-xs hover:underline whitespace-nowrap w-20">{{ t.code }}</a>

                                            <!-- Machine name -->
                                            <div class="flex-1 min-w-0">
                                                <div class="font-medium text-gray-800 truncate">{{ t.title || t.code }}</div>
                                                <div v-if="t.product_name" class="text-xs text-purple-600 flex items-center gap-1 mt-0.5">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                                                    {{ t.product_name }}
                                                    <span v-if="t.serial_number" class="text-purple-400 font-mono">({{ t.serial_number }})</span>
                                                </div>
                                            </div>

                                            <!-- Status -->
                                            <span :class="statusBadge(t.status).cls" class="px-2 py-0.5 rounded text-xs font-semibold whitespace-nowrap">
                                                {{ statusBadge(t.status).label }}
                                            </span>

                                            <!-- Progress -->
                                            <div class="flex items-center gap-1 w-24">
                                                <div class="w-12 bg-gray-200 rounded-full h-1.5">
                                                    <div class="h-1.5 rounded-full" :class="progressColor(t.progress)" :style="{ width: t.progress + '%' }"></div>
                                                </div>
                                                <span class="text-xs text-gray-500 w-8 text-right">{{ t.progress }}%</span>
                                            </div>

                                            <!-- Deadline -->
                                            <div class="w-20 text-center">
                                                <span v-if="t.deadline" class="text-xs whitespace-nowrap" :class="isOverdue(t.deadline, t.status) ? 'text-red-600 font-bold' : 'text-gray-500'">
                                                    📅 {{ formatDate(t.deadline) }}
                                                </span>
                                                <span v-else class="text-xs text-gray-300">—</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="expandedId === row.employee_id && (!row.tasks || row.tasks.length === 0)">
                                <td colspan="9" class="bg-gray-50 px-6 py-4 text-center text-gray-400 text-sm">Không có chi tiết.</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
