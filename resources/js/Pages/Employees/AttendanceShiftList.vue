<script setup>
import { reactive, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import SetupSidebar from '@/Pages/Employees/Partials/SetupSidebar.vue';

const props = defineProps({
    shifts: {
        type: Array,
        default: () => [],
    },
});

const localShifts = ref([...(props.shifts || [])]);
const showModal = ref(false);
const submitting = ref(false);
const editingId = ref(null);
const errorMessage = ref('');

const form = reactive({
    name: '',
    start_time: '',
    end_time: '',
    allow_late_minutes: 0,
    allow_early_minutes: 0,
    notes: '',
    status: 'active',
});

const resetForm = () => {
    editingId.value = null;
    form.name = '';
    form.start_time = '';
    form.end_time = '';
    form.allow_late_minutes = 0;
    form.allow_early_minutes = 0;
    form.notes = '';
    form.status = 'active';
    errorMessage.value = '';
};

const openCreate = () => {
    resetForm();
    showModal.value = true;
};

const openEdit = (shift) => {
    editingId.value = shift.id;
    form.name = shift.name;
    form.start_time = (shift.start_time || '').slice(0, 5);
    form.end_time = (shift.end_time || '').slice(0, 5);
    form.allow_late_minutes = shift.allow_late_minutes || 0;
    form.allow_early_minutes = shift.allow_early_minutes || 0;
    form.notes = shift.notes || '';
    form.status = shift.status || 'active';
    errorMessage.value = '';
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    resetForm();
};

const formatTime = (value) => (value || '').slice(0, 5);

const formatWorkHours = (startTime, endTime) => {
    if (!startTime || !endTime) {
        return '0 giờ';
    }

    const [startHour, startMinute] = startTime.split(':').map(Number);
    const [endHour, endMinute] = endTime.split(':').map(Number);
    let total = ((endHour * 60) + endMinute) - ((startHour * 60) + startMinute);

    if (total < 0) {
        total += 24 * 60;
    }

    const hours = total / 60;
    return Number.isInteger(hours) ? `${hours} giờ` : `${hours.toFixed(1)} giờ`;
};

const sortShifts = () => {
    localShifts.value = [...localShifts.value].sort((a, b) => a.id - b.id);
};

const submit = async () => {
    if (!form.name || !form.start_time || !form.end_time) {
        errorMessage.value = 'Vui lòng nhập tên ca và thời gian làm việc.';
        return;
    }

    submitting.value = true;
    errorMessage.value = '';

    try {
        const payload = {
            ...form,
            start_time: (form.start_time || '').slice(0, 5),
            end_time: (form.end_time || '').slice(0, 5),
        };
        if (editingId.value) {
            const response = await axios.put(`/api/shifts/${editingId.value}`, payload);
            const updated = response?.data?.data;
            const index = localShifts.value.findIndex((item) => item.id === editingId.value);
            if (index !== -1 && updated) {
                localShifts.value[index] = updated;
            }
        } else {
            const response = await axios.post('/api/shifts', payload);
            const created = response?.data?.data;
            if (created) {
                localShifts.value.push(created);
            }
        }

        sortShifts();
        closeModal();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Không thể lưu ca làm việc.';
    } finally {
        submitting.value = false;
    }
};

const toggleShift = async (shift) => {
    const response = await axios.patch(`/api/shifts/${shift.id}/toggle`);
    const updated = response?.data?.data;
    const index = localShifts.value.findIndex((item) => item.id === shift.id);
    if (index !== -1 && updated) {
        localShifts.value[index] = updated;
        sortShifts();
    }
};

const removeShift = async (shift) => {
    if (!window.confirm(`Xóa ca làm việc ${shift.name}?`)) {
        return;
    }

    await axios.delete(`/api/shifts/${shift.id}`);
    localShifts.value = localShifts.value.filter((item) => item.id !== shift.id);
};
</script>

<template>
    <Head title="Danh sách ca làm việc - KiotViet Clone" />

    <AppLayout>
        <template #sidebar>
            <SetupSidebar active-main="attendance" />
        </template>

        <div class="space-y-4">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <Link href="/employees/attendance/settings" class="text-[22px] leading-none text-slate-700 hover:text-slate-900">‹</Link>
                    <h1 class="text-[30px] font-semibold text-slate-900">Danh sách ca làm việc</h1>
                </div>

                <button type="button" class="rounded-md bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700" @click="openCreate">
                    + Thêm ca làm việc
                </button>
            </div>

            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-[#dce9f7] text-slate-700">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold">STT</th>
                                <th class="px-4 py-3 text-left font-semibold">Ca làm việc</th>
                                <th class="px-4 py-3 text-center font-semibold">Thời gian</th>
                                <th class="px-4 py-3 text-center font-semibold">Tổng giờ làm việc</th>
                                <th class="px-4 py-3 text-center font-semibold">Hoạt động</th>
                                <th class="px-4 py-3 text-center font-semibold">Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="localShifts.length === 0">
                                <td colspan="6" class="px-6 py-10 text-center text-slate-400">Chưa có ca làm việc nào.</td>
                            </tr>
                            <tr v-for="(shift, index) in localShifts" :key="shift.id" class="border-t border-slate-200">
                                <td class="px-4 py-4 text-slate-700">{{ index + 1 }}</td>
                                <td class="px-4 py-4 font-medium text-slate-900">{{ shift.name }}</td>
                                <td class="px-4 py-4 text-center text-slate-700">{{ formatTime(shift.start_time) }} - {{ formatTime(shift.end_time) }}</td>
                                <td class="px-4 py-4 text-center text-slate-700">{{ formatWorkHours(shift.start_time, shift.end_time) }}</td>
                                <td class="px-4 py-4">
                                    <div class="flex justify-center">
                                        <button type="button" @click="toggleShift(shift)">
                                            <div class="relative inline-flex h-6 w-11 items-center rounded-full transition" :class="shift.status === 'active' ? 'bg-blue-600' : 'bg-slate-300'">
                                                <span class="inline-block h-4 w-4 transform rounded-full bg-white transition" :class="shift.status === 'active' ? 'translate-x-6' : 'translate-x-1'" />
                                            </div>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex items-center justify-center gap-4 text-slate-700">
                                        <button type="button" class="hover:text-blue-700" @click="openEdit(shift)">✎</button>
                                        <button type="button" class="hover:text-red-700" @click="removeShift(shift)">🗑</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/35 px-4 py-6">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                    <h2 class="text-lg font-semibold text-slate-900">{{ editingId ? 'Cập nhật ca làm việc' : 'Thêm ca làm việc' }}</h2>
                    <button type="button" class="text-2xl leading-none text-slate-500 hover:text-slate-700" @click="closeModal">×</button>
                </div>

                <div class="space-y-4 px-6 py-5">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Tên ca</label>
                        <input v-model="form.name" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Ví dụ: Ca hành chính">
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Bắt đầu</label>
                            <input v-model="form.start_time" type="time" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Kết thúc</label>
                            <input v-model="form.end_time" type="time" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Cho phép đi muộn (phút)</label>
                            <input v-model.number="form.allow_late_minutes" type="number" min="0" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Cho phép về sớm (phút)</label>
                            <input v-model.number="form.allow_early_minutes" type="number" min="0" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Ghi chú</label>
                        <textarea v-model="form.notes" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2"></textarea>
                    </div>

                    <p v-if="errorMessage" class="text-sm text-red-600">{{ errorMessage }}</p>
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-200 px-6 py-4">
                    <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeModal">Bỏ qua</button>
                    <button type="button" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60" :disabled="submitting" @click="submit">Lưu</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>