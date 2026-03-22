<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';
import { usePermission } from '@/composables/usePermission';

const { can, isAdmin } = usePermission();

const props = defineProps({
    employees: Array,
});

const logs = ref([]);
const pagination = ref({});
const loading = ref(false);
const page = ref(1);

// Filters
const filterAction = ref('');
const filterEmployee = ref('');
const filterSearch = ref('');
const filterFrom = ref('');
const filterTo = ref('');

// Action type labels
const actionTypes = ref({});

const actionIcons = {
    purchase_create: '📦',
    purchase_update: '✏️',
    purchase_delete: '🗑️',
    task_create: '📋',
    task_assign: '👤',
    task_accept: '✅',
    task_reject: '❌',
    task_complete: '🎉',
    task_cancel: '🚫',
    task_progress: '📊',
    part_install: '🔧',
    part_remove: '↩️',
    part_disassemble: '🔩',
    comment_add: '💬',
    login: '🔑',
    logout: '🚪',
};

const actionColors = {
    purchase_create: 'bg-blue-100 text-blue-700',
    purchase_update: 'bg-yellow-100 text-yellow-700',
    purchase_delete: 'bg-red-100 text-red-700',
    task_create: 'bg-indigo-100 text-indigo-700',
    task_assign: 'bg-purple-100 text-purple-700',
    task_accept: 'bg-green-100 text-green-700',
    task_reject: 'bg-red-100 text-red-700',
    task_complete: 'bg-emerald-100 text-emerald-700',
    task_cancel: 'bg-gray-200 text-gray-700',
    task_progress: 'bg-cyan-100 text-cyan-700',
    part_install: 'bg-orange-100 text-orange-700',
    part_remove: 'bg-pink-100 text-pink-700',
    part_disassemble: 'bg-amber-100 text-amber-700',
    comment_add: 'bg-sky-100 text-sky-700',
    login: 'bg-teal-100 text-teal-700',
    logout: 'bg-slate-100 text-slate-700',
};

const load = async () => {
    loading.value = true;
    try {
        const params = { page: page.value, per_page: 30 };
        if (filterAction.value) params.action = filterAction.value;
        if (filterEmployee.value) params.employee_id = filterEmployee.value;
        if (filterSearch.value) params.search = filterSearch.value;
        if (filterFrom.value) params.from = filterFrom.value;
        if (filterTo.value) params.to = filterTo.value;

        const res = await axios.get('/api/activity-logs', { params });
        logs.value = res.data.data || [];
        pagination.value = {
            current_page: res.data.current_page,
            last_page: res.data.last_page,
            total: res.data.total,
        };
    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
};

const loadActionTypes = async () => {
    try {
        const res = await axios.get('/api/activity-logs/action-types');
        actionTypes.value = res.data || {};
    } catch (e) {}
};

onMounted(() => {
    load();
    loadActionTypes();
});

watch([filterAction, filterEmployee, filterSearch, filterFrom, filterTo], () => {
    page.value = 1;
    load();
});

const formatDate = (dt) => {
    if (!dt) return '';
    const d = new Date(dt);
    return d.toLocaleString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const timeAgo = (dt) => {
    if (!dt) return '';
    const now = new Date();
    const d = new Date(dt);
    const diff = Math.floor((now - d) / 1000);
    if (diff < 60) return 'Vừa xong';
    if (diff < 3600) return `${Math.floor(diff / 60)} phút trước`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} giờ trước`;
    if (diff < 604800) return `${Math.floor(diff / 86400)} ngày trước`;
    return formatDate(dt);
};

const goPage = (p) => {
    if (p < 1 || p > pagination.value.last_page) return;
    page.value = p;
    load();
};
</script>

<template>
    <Head title="Lịch sử thao tác" />
    <AppLayout>
        <div class="p-6 max-w-[1200px] mx-auto">
            <!-- Header -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    📜 Lịch sử thao tác
                </h1>
                <p class="text-sm text-gray-500 mt-1">Theo dõi lịch sử hoạt động: nhập hàng, lắp máy, tháo linh kiện, hoàn thành việc...</p>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
                <div class="flex flex-wrap gap-3 items-end">
                    <!-- Search -->
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs text-gray-500 mb-1">Tìm kiếm</label>
                        <input v-model="filterSearch" type="text" placeholder="Tìm theo mô tả..."
                            class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <!-- Action type -->
                    <div class="min-w-[180px]">
                        <label class="block text-xs text-gray-500 mb-1">Loại thao tác</label>
                        <select v-model="filterAction" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500">
                            <option value="">Tất cả</option>
                            <option v-for="(label, key) in actionTypes" :key="key" :value="key">
                                {{ actionIcons[key] || '📝' }} {{ label }}
                            </option>
                        </select>
                    </div>

                    <!-- Employee -->
                    <div v-if="isAdmin()" class="min-w-[180px]">
                        <label class="block text-xs text-gray-500 mb-1">Nhân viên</label>
                        <select v-model="filterEmployee" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500">
                            <option value="">Tất cả</option>
                            <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.name }}</option>
                        </select>
                    </div>

                    <!-- Date range -->
                    <div class="min-w-[140px]">
                        <label class="block text-xs text-gray-500 mb-1">Từ ngày</label>
                        <input v-model="filterFrom" type="date" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                    <div class="min-w-[140px]">
                        <label class="block text-xs text-gray-500 mb-1">Đến ngày</label>
                        <input v-model="filterTo" type="date" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500">
                    </div>
                </div>
            </div>

            <!-- Loading -->
            <div v-if="loading" class="text-center py-12">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mx-auto"></div>
                <p class="text-sm text-gray-500 mt-2">Đang tải...</p>
            </div>

            <!-- Timeline -->
            <div v-else-if="logs.length > 0" class="space-y-0">
                <div v-for="(log, idx) in logs" :key="log.id"
                    class="bg-white rounded-lg border border-gray-200 shadow-sm hover:shadow-md transition-shadow mb-3 overflow-hidden">
                    <div class="flex items-start gap-4 p-4">
                        <!-- Icon -->
                        <div class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center text-lg"
                            :class="actionColors[log.action] || 'bg-gray-100 text-gray-600'">
                            {{ actionIcons[log.action] || '📝' }}
                        </div>

                        <!-- Content -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2 py-0.5 rounded text-xs font-semibold"
                                    :class="actionColors[log.action] || 'bg-gray-100 text-gray-600'">
                                    {{ actionTypes[log.action] || log.action }}
                                </span>
                                <span v-if="log.employee" class="text-xs text-gray-500">
                                    bởi <strong class="text-gray-700">{{ log.employee.name }}</strong>
                                </span>
                                <span v-else-if="log.user" class="text-xs text-gray-500">
                                    bởi <strong class="text-gray-700">{{ log.user.name }}</strong>
                                </span>
                            </div>
                            <p class="text-sm text-gray-800 mt-1 leading-relaxed">{{ log.description }}</p>

                            <!-- Properties -->
                            <div v-if="log.properties && Object.keys(log.properties).length > 0" class="mt-2 flex flex-wrap gap-2">
                                <span v-for="(val, key) in log.properties" :key="key"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-gray-100 text-xs text-gray-600">
                                    <span class="font-medium text-gray-500">{{ key }}:</span>
                                    <span>{{ typeof val === 'number' ? Number(val).toLocaleString('vi-VN') : val }}</span>
                                </span>
                            </div>
                        </div>

                        <!-- Time -->
                        <div class="flex-shrink-0 text-right">
                            <div class="text-xs text-gray-400">{{ timeAgo(log.created_at) }}</div>
                            <div class="text-[11px] text-gray-300 mt-0.5">{{ formatDate(log.created_at) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="pagination.last_page > 1" class="flex items-center justify-center gap-2 pt-6">
                    <button @click="goPage(pagination.current_page - 1)"
                        :disabled="pagination.current_page === 1"
                        class="px-3 py-1.5 border border-gray-300 rounded text-sm hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                        ← Trước
                    </button>
                    <span class="text-sm text-gray-600">
                        Trang {{ pagination.current_page }} / {{ pagination.last_page }}
                        ({{ pagination.total }} bản ghi)
                    </span>
                    <button @click="goPage(pagination.current_page + 1)"
                        :disabled="pagination.current_page === pagination.last_page"
                        class="px-3 py-1.5 border border-gray-300 rounded text-sm hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                        Sau →
                    </button>
                </div>
            </div>

            <!-- Empty -->
            <div v-else class="bg-white rounded-lg border border-gray-200 shadow-sm p-12 text-center">
                <div class="text-5xl mb-4">📜</div>
                <h3 class="text-lg font-semibold text-gray-700 mb-2">Chưa có lịch sử thao tác</h3>
                <p class="text-sm text-gray-500">Các thao tác nhập hàng, lắp linh kiện, hoàn thành công việc... sẽ được ghi lại tại đây.</p>
            </div>
        </div>
    </AppLayout>
</template>
