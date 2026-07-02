<script setup>
import { Head } from '@inertiajs/vue3';
import { onMounted, reactive, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';

const loading = ref(false);
const report = ref({ data: [], document_issues: [], summary: {} });
const filters = reactive({ section: 'all', branch: '', employee: '' });

const load = async () => {
    loading.value = true;
    try {
        const response = await axios.get('/api/payroll/reconciliation', {
            params: {
                section: filters.section,
                branch: filters.branch || undefined,
                employee: filters.employee || undefined,
            },
        });
        report.value = response.data;
    } finally {
        loading.value = false;
    }
};

const exportReport = () => {
    const params = new URLSearchParams({
        section: filters.section,
        ...(filters.branch ? { branch: filters.branch } : {}),
        ...(filters.employee ? { employee: filters.employee } : {}),
    });
    window.location.href = `/api/payroll/reconciliation/export?${params}`;
};

onMounted(load);
</script>

<template>
    <Head title="Đối soát nợ lương và tạm ứng" />
    <AppLayout>
        <div class="p-6 space-y-5">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Đối soát nợ lương và tạm ứng</h1>
                    <p class="text-sm text-gray-500">Chỉ đọc. Báo cáo không sửa ledger hoặc cache.</p>
                </div>
                <button class="px-4 py-2 rounded bg-green-600 text-white" @click="exportReport">Xuất CSV</button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 bg-white p-4 rounded border">
                <select v-model="filters.section" class="border rounded px-3 py-2">
                    <option value="all">Tất cả</option>
                    <option value="cache">Cache</option>
                    <option value="payments">Thanh toán</option>
                    <option value="advances">Tạm ứng</option>
                    <option value="legacy">Legacy</option>
                </select>
                <input v-model="filters.branch" type="number" class="border rounded px-3 py-2" placeholder="ID chi nhánh" />
                <input v-model="filters.employee" class="border rounded px-3 py-2" placeholder="Mã/ID nhân viên" />
                <button class="px-4 py-2 rounded bg-blue-600 text-white" :disabled="loading" @click="load">
                    {{ loading ? 'Đang tải...' : 'Đối soát' }}
                </button>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <div v-for="(value, key) in report.summary" :key="key" class="bg-white border rounded p-3">
                    <div class="text-xs text-gray-500">{{ key }}</div>
                    <div class="text-lg font-semibold">{{ value }}</div>
                </div>
            </div>
            <div class="bg-white border rounded overflow-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="p-3 text-left">Nhân viên</th><th class="p-3 text-left">Chi nhánh</th>
                        <th class="p-3 text-right">Cache</th><th class="p-3 text-right">Ledger</th>
                        <th class="p-3 text-right">Chênh lệch</th><th class="p-3 text-left">Trạng thái</th>
                        <th class="p-3 text-left">Issues</th><th class="p-3 text-left">Đề xuất</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="row in report.data" :key="row.employee_id" class="border-t">
                            <td class="p-3">{{ row.employee_code }} - {{ row.employee_name }}</td>
                            <td class="p-3">{{ row.branch || 'Không xác định' }}</td>
                            <td class="p-3 text-right">{{ row.salary_balance_cache }}</td>
                            <td class="p-3 text-right">{{ row.ledger_balance }}</td>
                            <td class="p-3 text-right">{{ row.difference }}</td>
                            <td class="p-3 font-medium">{{ row.primary_status }}</td>
                            <td class="p-3">{{ row.issues.join(', ') || 'OK' }}</td>
                            <td class="p-3">{{ row.suggested_action || '-' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
