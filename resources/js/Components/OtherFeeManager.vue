<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import MoneyInput from '@/Components/MoneyInput.vue';

const props = defineProps({
    otherFees: Array,
    branches: Array,
    show: Boolean,
});

const emit = defineEmits(['close']);

// Modal state
const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingFee = ref(null);
const activeTab = ref('info'); // 'info' | 'scope'
const filterText = ref('');

const newForm = useForm({
    name: '',
    value: '',
    value_type: 'fixed',
    auto_apply: true,
    refund_on_return: false,
    scope: 'system',
    branch_id: null,
});

const editForm = useForm({
    name: '',
    value: '',
    value_type: 'fixed',
    auto_apply: true,
    refund_on_return: false,
    scope: 'system',
    branch_id: null,
    status: 'active',
});

const filteredFees = computed(() => {
    if (!filterText.value) return props.otherFees;
    const q = filterText.value.toLowerCase();
    return props.otherFees?.filter(f =>
        f.name?.toLowerCase().includes(q) || f.code?.toLowerCase().includes(q)
    );
});

const openCreate = () => {
    newForm.reset();
    newForm.value_type = 'fixed';
    newForm.auto_apply = true;
    newForm.refund_on_return = false;
    newForm.scope = 'system';
    newForm.branch_id = null;
    activeTab.value = 'info';
    showCreateModal.value = true;
};

const openEdit = (fee) => {
    editingFee.value = fee;
    editForm.name = fee.name;
    editForm.value = fee.value || '';
    editForm.value_type = fee.value_type || 'fixed';
    editForm.auto_apply = !!fee.auto_apply;
    editForm.refund_on_return = !!fee.refund_on_return;
    editForm.scope = fee.scope || 'system';
    editForm.branch_id = fee.branch_id;
    editForm.status = fee.status || 'active';
    activeTab.value = 'info';
    showEditModal.value = true;
};

const submitNew = () => {
    newForm.value = Number(newForm.value) || 0;
    newForm.post('/settings/other-fees', {
        preserveScroll: true,
        onSuccess: () => {
            showCreateModal.value = false;
            newForm.reset();
        },
    });
};

const submitEdit = () => {
    editForm.value = Number(editForm.value) || 0;
    editForm.put(`/settings/other-fees/${editingFee.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            editingFee.value = null;
        },
    });
};

const deleteFee = (fee) => {
    if (confirm(`Xóa loại thu khác "${fee.name}"?`)) {
        useForm({}).delete(`/settings/other-fees/${fee.id}`, { preserveScroll: true });
    }
};

const toggleStatus = (fee) => {
    const form = useForm({
        name: fee.name,
        value: Number(fee.value) || 0,
        value_type: fee.value_type,
        auto_apply: fee.auto_apply,
        refund_on_return: fee.refund_on_return,
        scope: fee.scope,
        branch_id: fee.branch_id,
        status: fee.status === 'active' ? 'inactive' : 'active',
    });
    form.put(`/settings/other-fees/${fee.id}`, { preserveScroll: true });
};

const formatValue = (fee) => {
    if (!fee.value || fee.value == 0) return '-';
    if (fee.value_type === 'percent') return fee.value + '%';
    return Number(fee.value).toLocaleString('vi-VN');
};

const statusLabel = (s) => s === 'active' ? 'Đang thu' : 'Ngừng thu';
const statusClass = (s) => s === 'active' ? 'text-green-600' : 'text-gray-400';
</script>

<template>
<!-- Main List View -->
<div v-if="show" class="fixed inset-0 z-[999] flex items-center justify-center bg-black/40" @click.self="emit('close')">
    <div class="bg-white rounded-lg shadow-2xl w-[780px] max-h-[85vh] flex flex-col">
        <!-- Header -->
        <div class="flex items-center gap-3 px-6 py-4 border-b">
            <button @click="emit('close')" class="text-gray-400 hover:text-blue-600 mr-1">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
            </button>
            <div class="flex-1">
                <h2 class="text-lg font-bold text-gray-800">Quản lý thu khác</h2>
                <p class="text-xs text-gray-500">Theo dõi các loại thu khác khi bán hàng</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" :checked="true" class="sr-only peer" disabled>
                <div class="w-11 h-6 bg-blue-600 rounded-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-full"></div>
            </label>
        </div>

        <!-- Toolbar -->
        <div class="px-6 py-3 flex items-center gap-3 border-b bg-gray-50">
            <div class="flex items-center gap-2 border rounded px-2 py-1 bg-white text-sm flex-1 max-w-[200px]">
                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>
                <input v-model="filterText" placeholder="Lọc" class="border-none outline-none text-sm flex-1 bg-transparent py-0">
            </div>
            <div class="flex-1"></div>
            <button @click="openCreate" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded text-sm font-medium flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Tạo loại thu khác
            </button>
        </div>

        <!-- Table -->
        <div class="flex-1 overflow-y-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="text-left px-6 py-2.5 text-xs font-bold text-gray-500 uppercase">Mã thu khác</th>
                        <th class="text-left px-4 py-2.5 text-xs font-bold text-gray-500 uppercase">Tên loại thu</th>
                        <th class="text-right px-4 py-2.5 text-xs font-bold text-gray-500 uppercase w-28">Tiền thu</th>
                        <th class="text-center px-4 py-2.5 text-xs font-bold text-gray-500 uppercase w-28">Trạng thái</th>
                        <th class="text-right px-6 py-2.5 text-xs font-bold text-gray-500 uppercase w-24"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="fee in filteredFees" :key="fee.id" class="hover:bg-gray-50/80 group cursor-pointer" @click="openEdit(fee)">
                        <td class="px-6 py-3 text-blue-600 font-medium">{{ fee.code }}</td>
                        <td class="px-4 py-3 text-gray-800">{{ fee.name }}</td>
                        <td class="text-right px-4 py-3 text-gray-700">{{ formatValue(fee) }}</td>
                        <td class="text-center px-4 py-3">
                            <span :class="statusClass(fee.status)" class="text-xs font-medium">{{ statusLabel(fee.status) }}</span>
                        </td>
                        <td class="text-right px-6 py-3" @click.stop>
                            <button @click="deleteFee(fee)" class="text-red-500 hover:text-red-700 text-xs font-bold opacity-0 group-hover:opacity-100 transition-opacity">Xóa</button>
                        </td>
                    </tr>
                    <tr v-if="!filteredFees?.length">
                        <td colspan="5" class="px-6 py-12 text-center text-gray-400">
                            <svg class="w-10 h-10 mx-auto mb-2 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            Chưa có loại thu khác nào
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <div class="px-6 py-3 border-t bg-gray-50 flex justify-between items-center">
            <span class="text-xs text-gray-500">Tổng: <strong>{{ otherFees?.length || 0 }}</strong> loại thu khác</span>
            <button @click="emit('close')" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-1.5 rounded text-sm font-medium">Đóng</button>
        </div>
    </div>
</div>

<!-- Create Modal -->
<div v-if="showCreateModal" class="fixed inset-0 z-[1000] flex items-center justify-center bg-black/40" @click.self="showCreateModal = false">
    <div class="bg-white rounded-lg shadow-2xl w-[520px] flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b">
            <h3 class="text-lg font-bold text-gray-800">Tạo loại thu khác</h3>
            <button @click="showCreateModal = false" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <!-- Tabs -->
        <div class="flex border-b px-6">
            <button @click="activeTab = 'info'" :class="activeTab === 'info' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'" class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px">Thông tin</button>
            <button @click="activeTab = 'scope'" :class="activeTab === 'scope' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'" class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px">Phạm vi áp dụng</button>
        </div>

        <!-- Tab: Thông tin -->
        <div v-show="activeTab === 'info'" class="px-6 py-4 space-y-4">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Mã thu khác</label>
                <input disabled value="Tự động" class="w-full border border-gray-200 rounded px-3 py-2 text-sm bg-gray-50 text-gray-400">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Tên loại thu <span class="text-red-500">*</span></label>
                <input v-model="newForm.name" placeholder="Bắt buộc" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                <div v-if="newForm.errors.name" class="text-red-500 text-xs mt-1">{{ newForm.errors.name }}</div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Giá trị <svg class="inline w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></label>
                <div class="flex">
                    <MoneyInput
                        v-if="newForm.value_type === 'fixed'"
                        v-model="newForm.value"
                        :min="0"
                        placeholder="0"
                        input-class="flex-1 border border-gray-300 rounded-l px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none"
                    />
                    <input
                        v-else
                        v-model="newForm.value"
                        type="text"
                        inputmode="decimal"
                        placeholder="0"
                        class="flex-1 border border-gray-300 rounded-l px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none"
                    >
                    <div class="flex border border-l-0 border-gray-300 rounded-r overflow-hidden">
                        <button @click="newForm.value_type = 'fixed'" :class="newForm.value_type === 'fixed' ? 'bg-blue-600 text-white' : 'bg-gray-50 text-gray-600 hover:bg-gray-100'" class="px-3 py-2 text-sm font-medium transition-colors">VND</button>
                        <button @click="newForm.value_type = 'percent'" :class="newForm.value_type === 'percent' ? 'bg-blue-600 text-white' : 'bg-gray-50 text-gray-600 hover:bg-gray-100'" class="px-3 py-2 text-sm font-medium transition-colors border-l border-gray-300">%</button>
                    </div>
                </div>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 mb-2">Tùy chọn khác</p>
                <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700 mb-2">
                    <input type="checkbox" v-model="newForm.auto_apply" class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    Tự động áp dụng khi bán hàng
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                    <input type="checkbox" v-model="newForm.refund_on_return" class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    Hoàn lại khi trả hàng
                </label>
            </div>
        </div>

        <!-- Tab: Phạm vi áp dụng -->
        <div v-show="activeTab === 'scope'" class="px-6 py-4 space-y-3">
            <label class="flex items-center gap-2.5 cursor-pointer text-[14px] text-gray-700">
                <input type="radio" v-model="newForm.scope" value="system" class="w-4 h-4 text-blue-600 focus:ring-blue-500">
                <span class="font-medium">Toàn hệ thống</span>
            </label>
            <label class="flex items-center gap-2.5 cursor-pointer text-[14px] text-gray-700">
                <input type="radio" v-model="newForm.scope" value="branch" class="w-4 h-4 text-blue-600 focus:ring-blue-500">
                <span class="font-medium">Chi nhánh</span>
            </label>
            <div v-if="newForm.scope === 'branch'" class="ml-7">
                <select v-model="newForm.branch_id" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                    <option :value="null">-- Chọn chi nhánh --</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t flex justify-end gap-3">
            <button @click="showCreateModal = false" class="px-4 py-2 rounded text-sm font-medium text-gray-700 border border-gray-300 hover:bg-gray-50">Bỏ qua</button>
            <button @click="submitNew" :disabled="!newForm.name || newForm.processing" class="px-6 py-2 rounded text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50">Lưu</button>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div v-if="showEditModal" class="fixed inset-0 z-[1000] flex items-center justify-center bg-black/40" @click.self="showEditModal = false">
    <div class="bg-white rounded-lg shadow-2xl w-[520px] flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b">
            <h3 class="text-lg font-bold text-gray-800">Sửa loại thu khác</h3>
            <button @click="showEditModal = false" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <!-- Tabs -->
        <div class="flex border-b px-6">
            <button @click="activeTab = 'info'" :class="activeTab === 'info' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'" class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px">Thông tin</button>
            <button @click="activeTab = 'scope'" :class="activeTab === 'scope' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'" class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px">Phạm vi áp dụng</button>
        </div>

        <!-- Tab: Thông tin -->
        <div v-show="activeTab === 'info'" class="px-6 py-4 space-y-4">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Mã thu khác</label>
                <input disabled :value="editingFee?.code" class="w-full border border-gray-200 rounded px-3 py-2 text-sm bg-gray-50 text-gray-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Tên loại thu <span class="text-red-500">*</span></label>
                <input v-model="editForm.name" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                <div v-if="editForm.errors.name" class="text-red-500 text-xs mt-1">{{ editForm.errors.name }}</div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Giá trị</label>
                <div class="flex">
                    <MoneyInput
                        v-if="editForm.value_type === 'fixed'"
                        v-model="editForm.value"
                        :min="0"
                        input-class="flex-1 border border-gray-300 rounded-l px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none"
                    />
                    <input
                        v-else
                        v-model="editForm.value"
                        type="text"
                        inputmode="decimal"
                        class="flex-1 border border-gray-300 rounded-l px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none"
                    >
                    <div class="flex border border-l-0 border-gray-300 rounded-r overflow-hidden">
                        <button @click="editForm.value_type = 'fixed'" :class="editForm.value_type === 'fixed' ? 'bg-blue-600 text-white' : 'bg-gray-50 text-gray-600 hover:bg-gray-100'" class="px-3 py-2 text-sm font-medium transition-colors">VND</button>
                        <button @click="editForm.value_type = 'percent'" :class="editForm.value_type === 'percent' ? 'bg-blue-600 text-white' : 'bg-gray-50 text-gray-600 hover:bg-gray-100'" class="px-3 py-2 text-sm font-medium transition-colors border-l border-gray-300">%</button>
                    </div>
                </div>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 mb-2">Tùy chọn khác</p>
                <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700 mb-2">
                    <input type="checkbox" v-model="editForm.auto_apply" class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    Tự động áp dụng khi bán hàng
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                    <input type="checkbox" v-model="editForm.refund_on_return" class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    Hoàn lại khi trả hàng
                </label>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 mb-2">Trạng thái</p>
                <select v-model="editForm.status" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                    <option value="active">Đang thu</option>
                    <option value="inactive">Ngừng thu</option>
                </select>
            </div>
        </div>

        <!-- Tab: Phạm vi áp dụng -->
        <div v-show="activeTab === 'scope'" class="px-6 py-4 space-y-3">
            <label class="flex items-center gap-2.5 cursor-pointer text-[14px] text-gray-700">
                <input type="radio" v-model="editForm.scope" value="system" class="w-4 h-4 text-blue-600 focus:ring-blue-500">
                <span class="font-medium">Toàn hệ thống</span>
            </label>
            <label class="flex items-center gap-2.5 cursor-pointer text-[14px] text-gray-700">
                <input type="radio" v-model="editForm.scope" value="branch" class="w-4 h-4 text-blue-600 focus:ring-blue-500">
                <span class="font-medium">Chi nhánh</span>
            </label>
            <div v-if="editForm.scope === 'branch'" class="ml-7">
                <select v-model="editForm.branch_id" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                    <option :value="null">-- Chọn chi nhánh --</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t flex justify-end gap-3">
            <button @click="showEditModal = false" class="px-4 py-2 rounded text-sm font-medium text-gray-700 border border-gray-300 hover:bg-gray-50">Bỏ qua</button>
            <button @click="submitEdit" :disabled="!editForm.name || editForm.processing" class="px-6 py-2 rounded text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50">Lưu</button>
        </div>
    </div>
</div>
</template>
