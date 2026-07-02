<script setup>
import { reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import SetupSidebar from '@/Pages/Employees/Partials/SetupSidebar.vue';

const props = defineProps({
    devices: {
        type: Array,
        default: () => [],
    },
    branches: {
        type: Array,
        default: () => [],
    },
});

const localDevices = ref([...(props.devices || [])]);
const showModal = ref(false);
const editingId = ref(null);
const loadingAction = ref('');
const errorMessage = ref('');

const form = reactive({
    branch_id: null,
    name: '',
    model: '',
    serial_number: '',
    ip_address: '',
    tcp_port: 4370,
    comm_key: 0,
    notes: '',
    status: 'active',
});

const resetForm = () => {
    editingId.value = null;
    form.branch_id = null;
    form.name = '';
    form.model = '';
    form.serial_number = '';
    form.ip_address = '';
    form.tcp_port = 4370;
    form.comm_key = 0;
    form.notes = '';
    form.status = 'active';
    errorMessage.value = '';
};

const openCreate = () => {
    resetForm();
    showModal.value = true;
};

const openEdit = (device) => {
    editingId.value = device.id;
    form.branch_id = device.branch_id;
    form.name = device.name;
    form.model = device.model || '';
    form.serial_number = device.serial_number || '';
    form.ip_address = device.ip_address || '';
    form.tcp_port = device.tcp_port || 4370;
    form.comm_key = device.comm_key || 0;
    form.notes = device.notes || '';
    form.status = device.status || 'active';
    errorMessage.value = '';
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    resetForm();
};

const saveDevice = async () => {
    loadingAction.value = 'save';
    errorMessage.value = '';

    try {
        if (editingId.value) {
            const response = await axios.put(`/api/attendance-devices/${editingId.value}`, { ...form });
            const updated = response?.data?.data;
            const index = localDevices.value.findIndex((item) => item.id === editingId.value);
            if (index !== -1 && updated) {
                localDevices.value[index] = updated;
            }
        } else {
            const response = await axios.post('/api/attendance-devices', { ...form });
            const created = response?.data?.data;
            if (created) {
                localDevices.value.unshift(created);
            }
        }

        closeModal();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Không thể lưu máy chấm công.';
    } finally {
        loadingAction.value = '';
    }
};

const testConnection = async (device) => {
    loadingAction.value = `test-${device.id}`;
    try {
        const response = await axios.post(`/api/attendance-devices/${device.id}/test-connection`);
        window.alert(response?.data?.message || 'Kết nối thành công.');
    } catch (error) {
        window.alert(error?.response?.data?.message || 'Không thể kiểm tra kết nối.');
    } finally {
        loadingAction.value = '';
    }
};

const removeDevice = async (device) => {
    if (!window.confirm(`Xóa máy chấm công ${device.name}?`)) {
        return;
    }

    await axios.delete(`/api/attendance-devices/${device.id}`);
    localDevices.value = localDevices.value.filter((item) => item.id !== device.id);
};
</script>

<template>
    <Head title="Máy chấm công - KiotViet Clone" />

    <AppLayout>
        <template #sidebar>
            <SetupSidebar active-main="attendance" active-utility="devices" />
        </template>

        <div class="space-y-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-[30px] font-semibold text-slate-900">Máy chấm công</h1>
                    <p class="mt-1 text-sm text-slate-500">Quản lý máy chấm công và cấu hình kết nối LAN</p>
                </div>

                <button type="button" class="rounded-md bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700" @click="openCreate">
                    + Thêm máy chấm công
                </button>
            </div>

            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-700">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold">Chi nhánh</th>
                                <th class="px-4 py-3 text-left font-semibold">Tên máy</th>
                                <th class="px-4 py-3 text-left font-semibold">Model</th>
                                <th class="px-4 py-3 text-left font-semibold">Seri</th>
                                <th class="px-4 py-3 text-left font-semibold">IP</th>
                                <th class="px-4 py-3 text-left font-semibold">TCP Port</th>
                                <th class="px-4 py-3 text-left font-semibold">Mã kết nối</th>
                                <th class="px-4 py-3 text-left font-semibold">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="localDevices.length === 0">
                                <td colspan="8" class="px-6 py-10 text-center text-slate-400">Chưa có máy chấm công.</td>
                            </tr>
                            <tr v-for="device in localDevices" :key="device.id" class="border-t border-slate-200">
                                <td class="px-4 py-4">{{ device.branch?.name || '-' }}</td>
                                <td class="px-4 py-4 font-medium text-slate-900">{{ device.name }}</td>
                                <td class="px-4 py-4">{{ device.model || '-' }}</td>
                                <td class="px-4 py-4">{{ device.serial_number || '-' }}</td>
                                <td class="px-4 py-4">{{ device.ip_address }}</td>
                                <td class="px-4 py-4">{{ device.tcp_port }}</td>
                                <td class="px-4 py-4">{{ device.comm_key }}</td>
                                <td class="px-4 py-4">
                                    <div class="flex flex-wrap items-center gap-3 text-sm">
                                        <button type="button" class="text-blue-600 hover:text-blue-700" @click="openEdit(device)">Cập nhật</button>
                                        <button type="button" class="text-slate-600 hover:text-slate-800" :disabled="loadingAction === `test-${device.id}`" @click="testConnection(device)">Test</button>
                                        <button type="button" class="text-red-600 hover:text-red-700" @click="removeDevice(device)">Xóa</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/35 px-4 py-6">
            <div class="w-full max-w-3xl rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                    <h2 class="text-lg font-semibold text-slate-900">{{ editingId ? 'Cập nhật máy chấm công' : 'Thêm máy chấm công' }}</h2>
                    <button type="button" class="text-2xl leading-none text-slate-500 hover:text-slate-700" @click="closeModal">×</button>
                </div>

                <div class="grid grid-cols-1 gap-4 px-6 py-5 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Chi nhánh</label>
                        <select v-model="form.branch_id" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option :value="null">-</option>
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Tên máy</label>
                        <input v-model="form.name" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Model máy</label>
                        <input v-model="form.model" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Seri máy</label>
                        <input v-model="form.serial_number" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    </div>
                    <div class="md:col-span-2 text-sm font-semibold text-slate-700">Thông tin kết nối máy chấm công qua LAN</div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Địa chỉ IP</label>
                        <input v-model="form.ip_address" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Cổng liên kết TCP</label>
                        <input v-model.number="form.tcp_port" type="number" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">Mã kết nối</label>
                        <input v-model.number="form.comm_key" type="number" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-slate-700">Ghi chú</label>
                        <textarea v-model="form.notes" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2"></textarea>
                    </div>
                    <p v-if="errorMessage" class="md:col-span-2 text-sm text-red-600">{{ errorMessage }}</p>
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-200 px-6 py-4">
                    <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeModal">Bỏ qua</button>
                    <button type="button" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60" :disabled="loadingAction === 'save'" @click="saveDevice">Lưu</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>