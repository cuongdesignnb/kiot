<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import SetupSidebar from '@/Pages/Employees/Partials/SetupSidebar.vue';

const props = defineProps({
    branches: {
        type: Array,
        default: () => [],
    },
    holidayCount: {
        type: Number,
        default: 0,
    },
});

const weekdayOptions = [
    { key: 'mon', label: 'T2' },
    { key: 'tue', label: 'T3' },
    { key: 'wed', label: 'T4' },
    { key: 'thu', label: 'T5' },
    { key: 'fri', label: 'T6' },
    { key: 'sat', label: 'T7' },
    { key: 'sun', label: 'CN' },
];

const showWorkdayModal = ref(false);
const showEditModal = ref(false);
const searchBranch = ref('');
const saving = ref(false);
const toast = reactive({ show: false, type: 'success', message: '' });
const branchRows = ref((props.branches ?? []).map((branch) => ({ ...branch })));
const editingBranchId = ref(null);
const editForm = reactive({
    week_days: {
        mon: true,
        tue: true,
        wed: true,
        thu: true,
        fri: true,
        sat: true,
        sun: false,
    },
    status: 'active',
});

const setToast = (message, type = 'success') => {
    toast.show = true;
    toast.type = type;
    toast.message = message;
    window.clearTimeout(setToast.timeoutId);
    setToast.timeoutId = window.setTimeout(() => {
        toast.show = false;
    }, 2500);
};

const summarizeWeekDays = (weekDays) => weekdayOptions
    .filter((option) => weekDays?.[option.key])
    .map((option) => option.label)
    .join(', ');

const rows = computed(() => [
    {
        key: 'workdays',
        title: 'Ngày làm việc',
        description: 'Thiết lập ngày làm việc trong tuần của các chi nhánh',
        action: () => {
            searchBranch.value = '';
            showWorkdayModal.value = true;
        },
    },
    {
        key: 'holidays',
        title: 'Ngày lễ, tết',
        description: 'Thiết lập các ngày lễ, tết và chính sách thưởng nếu có',
        action: () => {
            router.visit('/employees/workday/settings/holidays');
        },
    },
]);

const filteredBranches = computed(() => {
    const keyword = searchBranch.value.trim().toLowerCase();

    if (!keyword) {
        return branchRows.value;
    }

    return branchRows.value.filter((branch) => branch.name.toLowerCase().includes(keyword));
});

const openEditor = (branch) => {
    editingBranchId.value = branch.id;
    editForm.week_days = {
        mon: Boolean(branch.week_days?.mon),
        tue: Boolean(branch.week_days?.tue),
        wed: Boolean(branch.week_days?.wed),
        thu: Boolean(branch.week_days?.thu),
        fri: Boolean(branch.week_days?.fri),
        sat: Boolean(branch.week_days?.sat),
        sun: Boolean(branch.week_days?.sun),
    };
    editForm.status = branch.status ?? 'active';
    showEditModal.value = true;
};

const activeBranch = computed(() => branchRows.value.find((branch) => branch.id === editingBranchId.value) ?? null);

const saveBranchWorkdays = async () => {
    if (!activeBranch.value) {
        return;
    }

    saving.value = true;

    try {
        await axios.post('/api/workday-settings', {
            branch_id: activeBranch.value.id,
            week_days: editForm.week_days,
            status: editForm.status,
        });

        branchRows.value = branchRows.value.map((branch) => {
            if (branch.id !== activeBranch.value.id) {
                return branch;
            }

            return {
                ...branch,
                week_days: { ...editForm.week_days },
                status: editForm.status,
                summary: summarizeWeekDays(editForm.week_days),
            };
        });

        setToast('Đã lưu ngày làm việc.');
        showEditModal.value = false;
    } catch (error) {
        setToast(error?.response?.data?.message || 'Không thể lưu ngày làm việc.', 'error');
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <Head title="Thiết lập ngày làm việc & ngày nghỉ - KiotViet Clone" />

    <AppLayout>
        <template #sidebar>
            <SetupSidebar active-main="workdays" />
        </template>

        <div class="space-y-4">
            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h1 class="text-lg font-semibold text-gray-900">Thiết lập ngày làm việc & ngày nghỉ</h1>
                </div>

                <div class="divide-y divide-gray-100">
                    <div
                        v-for="row in rows"
                        :key="row.key"
                        class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="flex items-start gap-4">
                            <div class="mt-1 flex h-8 w-8 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                                <span v-if="row.key === 'workdays'">■</span>
                                <span v-else>◔</span>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900">{{ row.title }}</div>
                                <div class="mt-1 text-sm text-gray-500">
                                    {{ row.description }}
                                    <span v-if="row.key === 'holidays' && holidayCount" class="text-gray-400">({{ holidayCount }} ngày đã thiết lập)</span>
                                </div>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700"
                            @click="row.action()"
                        >
                            Chi tiết
                        </button>
                    </div>
                </div>
            </section>
        </div>

        <div v-if="toast.show" class="fixed right-4 top-4 z-50">
            <div
                class="max-w-sm rounded-lg border px-4 py-3 text-sm shadow-lg"
                :class="toast.type === 'success'
                    ? 'border-green-200 bg-green-50 text-green-700'
                    : 'border-red-200 bg-red-50 text-red-700'"
            >
                {{ toast.message }}
            </div>
        </div>

        <div v-if="showWorkdayModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-5xl rounded-xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">Thiết lập ngày làm việc</h2>
                    <button type="button" class="text-gray-400 transition hover:text-gray-600" @click="showWorkdayModal = false">✕</button>
                </div>

                <div class="px-6 py-4">
                    <div class="overflow-hidden rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">STT</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Chi nhánh</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Ngày làm việc</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Trạng thái</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr>
                                    <td class="px-4 py-3 text-sm text-gray-400"></td>
                                    <td class="px-4 py-3">
                                        <input
                                            v-model="searchBranch"
                                            type="text"
                                            placeholder="Tìm chi nhánh"
                                            class="w-full border-b border-gray-300 px-0 py-1 text-sm outline-none placeholder:text-gray-400 focus:border-blue-500"
                                        />
                                    </td>
                                    <td class="px-4 py-3"></td>
                                    <td class="px-4 py-3"></td>
                                    <td class="px-4 py-3"></td>
                                </tr>
                                <tr v-for="(branch, index) in filteredBranches" :key="branch.id">
                                    <td class="px-4 py-3 text-sm text-gray-600">{{ index + 1 }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ branch.name }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-600">{{ branch.summary }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-600">{{ branch.status === 'active' ? 'Đang hoạt động' : 'Ngừng hoạt động' }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            class="rounded-md p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700"
                                            @click="openEditor(branch)"
                                        >
                                            ✎
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!filteredBranches.length">
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">Không tìm thấy chi nhánh nào.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="border-t border-gray-100 bg-gray-50 px-6 py-4 text-right">
                    <button
                        type="button"
                        class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-white"
                        @click="showWorkdayModal = false"
                    >
                        Bỏ qua
                    </button>
                </div>
            </div>
        </div>

        <div v-if="showEditModal && activeBranch" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-lg rounded-xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Cập nhật ngày làm việc</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ activeBranch.name }}</p>
                    </div>
                    <button type="button" class="text-gray-400 transition hover:text-gray-600" @click="showEditModal = false">✕</button>
                </div>

                <div class="space-y-4 px-6 py-5">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-gray-700">Ngày làm việc trong tuần</label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="day in weekdayOptions"
                                :key="day.key"
                                type="button"
                                class="rounded-full border px-3 py-2 text-sm font-medium transition"
                                :class="editForm.week_days[day.key]
                                    ? 'border-blue-500 bg-blue-50 text-blue-700'
                                    : 'border-gray-300 bg-white text-gray-600 hover:border-gray-400'"
                                @click="editForm.week_days[day.key] = !editForm.week_days[day.key]"
                            >
                                {{ day.label }}
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Trạng thái</label>
                        <select
                            v-model="editForm.status"
                            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        >
                            <option value="active">Đang hoạt động</option>
                            <option value="inactive">Ngừng hoạt động</option>
                        </select>
                    </div>
                </div>

                <div class="border-t border-gray-100 bg-gray-50 px-6 py-4">
                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                        <button
                            type="button"
                            class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-white"
                            @click="showEditModal = false"
                        >
                            Bỏ qua
                        </button>
                        <button
                            type="button"
                            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-60"
                            :disabled="saving"
                            @click="saveBranchWorkdays"
                        >
                            {{ saving ? 'Đang lưu...' : 'Lưu' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>