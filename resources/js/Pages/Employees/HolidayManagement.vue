<script setup>
import { computed, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import SetupSidebar from '@/Pages/Employees/Partials/SetupSidebar.vue';

const props = defineProps({
    holidays: {
        type: Array,
        default: () => [],
    },
});

const itemsPerPage = 10;
const currentPage = ref(1);
const loading = ref(false);
const saving = ref(false);
const deletingIds = ref([]);
const showModal = ref(false);
const toast = reactive({ show: false, type: 'success', message: '' });
const holidayItems = ref((props.holidays ?? []).map((holiday) => ({ ...holiday })));
const form = reactive({
    mode: 'manual',
    year: new Date().getFullYear(),
    name: '',
    start_date: '',
    end_date: '',
    multiplier: 2,
    paid_leave: true,
    status: 'active',
    notes: '',
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

const formatDate = (value) => {
    const date = new Date(value);
    return `${String(date.getDate()).padStart(2, '0')}/${String(date.getMonth() + 1).padStart(2, '0')}/${date.getFullYear()}`;
};

const toDateValue = (value) => new Date(`${value}T00:00:00`);

const groupedHolidays = computed(() => {
    const sorted = [...holidayItems.value]
        .sort((left, right) => String(left.holiday_date).localeCompare(String(right.holiday_date)));
    const groups = [];

    sorted.forEach((holiday) => {
        const currentDate = toDateValue(holiday.holiday_date);
        const year = currentDate.getFullYear();
        const previous = groups[groups.length - 1];
        const baseKey = [holiday.name, year, holiday.multiplier, holiday.status, holiday.paid_leave, holiday.notes ?? ''].join('|');

        if (previous && previous.baseKey === baseKey) {
            const previousEnd = toDateValue(previous.end_date);
            const nextExpected = new Date(previousEnd);
            nextExpected.setDate(previousEnd.getDate() + 1);

            if (nextExpected.toDateString() === currentDate.toDateString()) {
                previous.end_date = holiday.holiday_date;
                previous.ids.push(holiday.id);
                previous.day_count += 1;
                previous.items.push(holiday);
                return;
            }
        }

        groups.push({
            baseKey,
            name: holiday.name,
            start_date: holiday.holiday_date,
            end_date: holiday.holiday_date,
            day_count: 1,
            ids: [holiday.id],
            items: [holiday],
        });
    });

    return groups;
});

const paginatedGroups = computed(() => {
    const start = (currentPage.value - 1) * itemsPerPage;
    return groupedHolidays.value.slice(start, start + itemsPerPage);
});

const totalPages = computed(() => Math.max(1, Math.ceil(groupedHolidays.value.length / itemsPerPage)));
const startIndex = computed(() => groupedHolidays.value.length ? ((currentPage.value - 1) * itemsPerPage) + 1 : 0);
const endIndex = computed(() => Math.min(currentPage.value * itemsPerPage, groupedHolidays.value.length));

const resetForm = () => {
    form.mode = 'manual';
    form.year = new Date().getFullYear();
    form.name = '';
    form.start_date = '';
    form.end_date = '';
    form.multiplier = 2;
    form.paid_leave = true;
    form.status = 'active';
    form.notes = '';
};

const openModal = () => {
    resetForm();
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
};

const reloadHolidays = async () => {
    loading.value = true;

    try {
        const res = await axios.get('/api/holidays');
        holidayItems.value = res.data?.data ?? [];
        currentPage.value = 1;
    } catch (error) {
        setToast('Không thể tải danh sách lễ tết.', 'error');
    } finally {
        loading.value = false;
    }
};

const saveHoliday = async () => {
    saving.value = true;

    try {
        if (form.mode === 'automatic') {
            await axios.post('/api/holidays/auto-generate', { year: Number(form.year) });
            setToast('Đã tạo ngày lễ theo năm.');
        } else {
            if (!form.name.trim() || !form.start_date || !form.end_date) {
                setToast('Vui lòng nhập đủ tên kỳ lễ và khoảng ngày.', 'error');
                saving.value = false;
                return;
            }

            await axios.post('/api/holidays/range', {
                name: form.name.trim(),
                start_date: form.start_date,
                end_date: form.end_date,
                multiplier: Number(form.multiplier || 1),
                paid_leave: Boolean(form.paid_leave),
                status: form.status,
                notes: form.notes?.trim() || null,
            });
            setToast('Đã lưu kỳ lễ tết.');
        }

        await reloadHolidays();
        closeModal();
    } catch (error) {
        setToast(error?.response?.data?.message || 'Không thể lưu dữ liệu lễ tết.', 'error');
    } finally {
        saving.value = false;
    }
};

const removeGroup = async (group) => {
    if (!window.confirm(`Xóa kỳ lễ "${group.name}" từ ${formatDate(group.start_date)} đến ${formatDate(group.end_date)}?`)) {
        return;
    }

    deletingIds.value = group.ids;

    try {
        await Promise.all(group.ids.map((id) => axios.delete(`/api/holidays/${id}`)));
        holidayItems.value = holidayItems.value.filter((item) => !group.ids.includes(item.id));
        if (currentPage.value > totalPages.value) {
            currentPage.value = totalPages.value;
        }
        setToast('Đã xóa kỳ lễ tết.');
    } catch (error) {
        setToast(error?.response?.data?.message || 'Không thể xóa kỳ lễ tết.', 'error');
    } finally {
        deletingIds.value = [];
    }
};
</script>

<template>
    <Head title="Quản lý Lễ tết - KiotViet Clone" />

    <AppLayout>
        <template #sidebar>
            <SetupSidebar active-main="workdays" />
        </template>

        <div class="space-y-4">
            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3 text-gray-900">
                        <span class="text-lg">←</span>
                        <div class="text-lg font-semibold">Quản lý Lễ tết</div>
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700"
                        @click="openModal"
                    >
                        + Quản lý Lễ tết
                    </button>
                </div>

                <div v-if="loading" class="px-5 py-10 text-center text-sm text-gray-500">Đang tải dữ liệu lễ tết...</div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-[#eaf4ff]">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">STT</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Tên kỳ lễ tết</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Từ ngày</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Đến hết ngày</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Số ngày</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            <tr v-for="(group, index) in paginatedGroups" :key="`${group.name}-${group.start_date}-${group.end_date}`">
                                <td class="px-6 py-4 text-sm text-gray-600">{{ startIndex + index }}</td>
                                <td class="px-6 py-4 text-sm font-medium text-blue-600">{{ group.name }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700">{{ formatDate(group.start_date) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700">{{ formatDate(group.end_date) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700">{{ group.day_count }}</td>
                                <td class="px-6 py-4 text-right">
                                    <button
                                        type="button"
                                        class="rounded-md p-2 text-gray-500 transition hover:bg-gray-100 hover:text-red-600 disabled:opacity-60"
                                        :disabled="deletingIds.length > 0 && deletingIds.every((id) => group.ids.includes(id))"
                                        @click="removeGroup(group)"
                                    >
                                        🗑
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!paginatedGroups.length">
                                <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-500">Chưa có kỳ lễ tết nào.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-gray-100 px-5 py-4 text-sm text-gray-600 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded border border-gray-300 px-3 py-1.5 hover:bg-gray-50 disabled:opacity-50"
                            :disabled="currentPage === 1"
                            @click="currentPage -= 1"
                        >
                            ‹
                        </button>
                        <div class="rounded border border-gray-300 px-3 py-1.5">{{ currentPage }}</div>
                        <button
                            type="button"
                            class="rounded border border-gray-300 px-3 py-1.5 hover:bg-gray-50 disabled:opacity-50"
                            :disabled="currentPage >= totalPages"
                            @click="currentPage += 1"
                        >
                            ›
                        </button>
                    </div>

                    <div>{{ startIndex }} - {{ endIndex }} trong {{ groupedHolidays.length }} lễ tết</div>
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

        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-2xl rounded-xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Quản lý Lễ tết</h2>
                        <p class="mt-1 text-sm text-gray-500">Thêm thủ công theo kỳ ngày hoặc tự động tạo ngày lễ Việt Nam theo năm.</p>
                    </div>
                    <button type="button" class="text-gray-400 transition hover:text-gray-600" @click="closeModal">✕</button>
                </div>

                <div class="space-y-5 px-6 py-5">
                    <div class="flex gap-2 rounded-lg bg-gray-100 p-1">
                        <button
                            type="button"
                            class="flex-1 rounded-md px-4 py-2 text-sm font-medium transition"
                            :class="form.mode === 'manual' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'"
                            @click="form.mode = 'manual'"
                        >
                            Thêm thủ công
                        </button>
                        <button
                            type="button"
                            class="flex-1 rounded-md px-4 py-2 text-sm font-medium transition"
                            :class="form.mode === 'automatic' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'"
                            @click="form.mode = 'automatic'"
                        >
                            Tự động theo năm
                        </button>
                    </div>

                    <div v-if="form.mode === 'manual'" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-gray-700">Tên kỳ lễ tết</label>
                            <input
                                v-model="form.name"
                                type="text"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                placeholder="Ví dụ: Tết Nguyên Đán"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Từ ngày</label>
                            <input
                                v-model="form.start_date"
                                type="date"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Đến hết ngày</label>
                            <input
                                v-model="form.end_date"
                                type="date"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Hệ số lương</label>
                            <input
                                v-model.number="form.multiplier"
                                type="number"
                                min="0"
                                step="0.5"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Trạng thái</label>
                            <select
                                v-model="form.status"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            >
                                <option value="active">Đang áp dụng</option>
                                <option value="inactive">Ngừng áp dụng</option>
                            </select>
                        </div>

                        <div class="md:col-span-2">
                            <label class="flex items-center gap-3 rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700">
                                <input v-model="form.paid_leave" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                                <span>Nghỉ hưởng lương</span>
                            </label>
                        </div>

                        <div class="md:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-gray-700">Ghi chú</label>
                            <textarea
                                v-model="form.notes"
                                rows="3"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            />
                        </div>
                    </div>

                    <div v-else>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Năm</label>
                        <input
                            v-model.number="form.year"
                            type="number"
                            min="2024"
                            max="2035"
                            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p class="mt-2 text-sm text-gray-500">Hệ thống sẽ tự tạo Tết Dương lịch, Tết Nguyên Đán, Giỗ Tổ Hùng Vương, 30/4, 1/5 và Quốc khánh cho năm đã chọn.</p>
                    </div>
                </div>

                <div class="border-t border-gray-100 bg-gray-50 px-6 py-4">
                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                        <button
                            type="button"
                            class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-white"
                            @click="closeModal"
                        >
                            Bỏ qua
                        </button>
                        <button
                            type="button"
                            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-60"
                            :disabled="saving"
                            @click="saveHoliday"
                        >
                            {{ saving ? 'Đang lưu...' : 'Lưu' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>