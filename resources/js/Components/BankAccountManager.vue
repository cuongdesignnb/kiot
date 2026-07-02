<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    bankAccounts: Array,
    branches: Array,
    show: Boolean,
});

const emit = defineEmits(['close']);

const activeTypeTab = ref('bank'); // 'bank' | 'ewallet'
const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingAccount = ref(null);

const newForm = useForm({
    account_number: '',
    bank_name: '',
    account_holder: '',
    type: 'bank',
    scope: 'system',
    branch_id: null,
    note: '',
});

const editForm = useForm({
    account_number: '',
    bank_name: '',
    account_holder: '',
    type: 'bank',
    scope: 'system',
    branch_id: null,
    note: '',
    status: 'active',
});

const filteredAccounts = computed(() => {
    return props.bankAccounts?.filter(a => a.type === activeTypeTab.value) || [];
});

const bankCount = computed(() => props.bankAccounts?.filter(a => a.type === 'bank').length || 0);
const ewalletCount = computed(() => props.bankAccounts?.filter(a => a.type === 'ewallet').length || 0);

const scopeLabel = (acc) => {
    if (acc.scope === 'system') return 'Toàn hệ thống';
    const branch = props.branches?.find(b => b.id === acc.branch_id);
    return branch ? branch.name : 'Chi nhánh';
};

const openCreate = () => {
    newForm.reset();
    newForm.type = activeTypeTab.value;
    newForm.scope = 'system';
    newForm.branch_id = null;
    showCreateModal.value = true;
};

const openEdit = (acc) => {
    editingAccount.value = acc;
    editForm.account_number = acc.account_number;
    editForm.bank_name = acc.bank_name;
    editForm.account_holder = acc.account_holder;
    editForm.type = acc.type;
    editForm.scope = acc.scope || 'system';
    editForm.branch_id = acc.branch_id;
    editForm.note = acc.note || '';
    editForm.status = acc.status || 'active';
    showEditModal.value = true;
};

const submitNew = () => {
    newForm.post('/settings/bank-accounts', {
        preserveScroll: true,
        onSuccess: () => {
            showCreateModal.value = false;
            newForm.reset();
        },
    });
};

const submitEdit = () => {
    editForm.put(`/settings/bank-accounts/${editingAccount.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            editingAccount.value = null;
        },
    });
};

const deleteAccount = (acc) => {
    if (confirm(`Xóa tài khoản "${acc.bank_name} - ${acc.account_number}"?`)) {
        useForm({}).delete(`/settings/bank-accounts/${acc.id}`, { preserveScroll: true });
    }
};

const nameLabel = computed(() => activeTypeTab.value === 'bank' ? 'Ngân hàng' : 'Ví điện tử');
const numberLabel = computed(() => activeTypeTab.value === 'bank' ? 'Số tài khoản' : 'Số điện thoại / ID');
</script>

<template>
<!-- Main List View -->
<div v-if="show" class="fixed inset-0 z-[999] flex items-center justify-center bg-black/40" @click.self="emit('close')">
    <div class="bg-white rounded-lg shadow-2xl w-[860px] max-h-[85vh] flex flex-col">
        <!-- Header -->
        <div class="flex items-center gap-3 px-6 py-4 border-b">
            <button @click="emit('close')" class="text-gray-400 hover:text-blue-600 mr-1">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
            </button>
            <div class="flex-1">
                <h2 class="text-lg font-bold text-gray-800">Quản lý tài khoản thu chi</h2>
                <p class="text-xs text-gray-500">Quản lý tài khoản ngân hàng và ví điện tử cho thanh toán</p>
            </div>
        </div>

        <!-- Type Tabs -->
        <div class="flex border-b px-6">
            <button @click="activeTypeTab = 'bank'" :class="activeTypeTab === 'bank' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'" class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px">
                Tài khoản ngân hàng <span class="ml-1 text-xs bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded-full">{{ bankCount }}</span>
            </button>
            <button @click="activeTypeTab = 'ewallet'" :class="activeTypeTab === 'ewallet' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'" class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px">
                Ví điện tử <span class="ml-1 text-xs bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded-full">{{ ewalletCount }}</span>
            </button>
        </div>

        <!-- Toolbar -->
        <div class="px-6 py-3 flex items-center gap-3 border-b bg-gray-50">
            <div class="flex-1"></div>
            <button @click="openCreate" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded text-sm font-medium flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Thêm tài khoản
            </button>
        </div>

        <!-- Table -->
        <div class="flex-1 overflow-y-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="text-left px-6 py-2.5 text-xs font-bold text-gray-500 uppercase">{{ numberLabel }}</th>
                        <th class="text-left px-4 py-2.5 text-xs font-bold text-gray-500 uppercase">{{ nameLabel }}</th>
                        <th class="text-left px-4 py-2.5 text-xs font-bold text-gray-500 uppercase">Chủ tài khoản</th>
                        <th class="text-left px-4 py-2.5 text-xs font-bold text-gray-500 uppercase">Phạm vi áp dụng</th>
                        <th class="text-left px-4 py-2.5 text-xs font-bold text-gray-500 uppercase">Ghi chú</th>
                        <th class="text-right px-6 py-2.5 text-xs font-bold text-gray-500 uppercase w-24">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="acc in filteredAccounts" :key="acc.id" class="hover:bg-gray-50/80 group">
                        <td class="px-6 py-3 text-blue-600 font-medium">{{ acc.account_number }}</td>
                        <td class="px-4 py-3 text-gray-800">{{ acc.bank_name }}</td>
                        <td class="px-4 py-3 text-gray-700">{{ acc.account_holder }}</td>
                        <td class="px-4 py-3 text-gray-600 text-xs">{{ scopeLabel(acc) }}</td>
                        <td class="px-4 py-3 text-gray-500 text-xs max-w-[150px] truncate">{{ acc.note || '-' }}</td>
                        <td class="text-right px-6 py-3 whitespace-nowrap">
                            <button @click="openEdit(acc)" class="text-blue-600 hover:text-blue-700 text-xs font-medium mr-3">Sửa</button>
                            <button @click="deleteAccount(acc)" class="text-red-500 hover:text-red-700 text-xs font-medium">Xóa</button>
                        </td>
                    </tr>
                    <tr v-if="!filteredAccounts.length">
                        <td colspan="6" class="px-6 py-12 text-center text-gray-400">
                            <svg class="w-10 h-10 mx-auto mb-2 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                            {{ activeTypeTab === 'bank' ? 'Chưa có tài khoản ngân hàng nào' : 'Chưa có ví điện tử nào' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <div class="px-6 py-3 border-t bg-gray-50 flex justify-between items-center">
            <span class="text-xs text-gray-500">Tổng: <strong>{{ filteredAccounts.length }}</strong> tài khoản</span>
            <button @click="emit('close')" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-1.5 rounded text-sm font-medium">Đóng</button>
        </div>
    </div>
</div>

<!-- Create Modal -->
<div v-if="showCreateModal" class="fixed inset-0 z-[1000] flex items-center justify-center bg-black/40" @click.self="showCreateModal = false">
    <div class="bg-white rounded-lg shadow-2xl w-[520px] flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b">
            <h3 class="text-lg font-bold text-gray-800">Thêm tài khoản</h3>
            <button @click="showCreateModal = false" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <div class="px-6 py-4 space-y-4">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">{{ numberLabel }} <span class="text-red-500">*</span></label>
                <input v-model="newForm.account_number" placeholder="Nhập số tài khoản" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                <div v-if="newForm.errors.account_number" class="text-red-500 text-xs mt-1">{{ newForm.errors.account_number }}</div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">{{ nameLabel }} <span class="text-red-500">*</span></label>
                <input v-model="newForm.bank_name" :placeholder="activeTypeTab === 'bank' ? 'VD: Vietcombank, BIDV, Techcombank...' : 'VD: Momo, ZaloPay, VNPay...'" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                <div v-if="newForm.errors.bank_name" class="text-red-500 text-xs mt-1">{{ newForm.errors.bank_name }}</div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Chủ tài khoản <span class="text-red-500">*</span></label>
                <input v-model="newForm.account_holder" placeholder="Tên chủ tài khoản" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                <div v-if="newForm.errors.account_holder" class="text-red-500 text-xs mt-1">{{ newForm.errors.account_holder }}</div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-500 mb-2">Phạm vi áp dụng</label>
                <div class="space-y-2">
                    <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                        <input type="radio" v-model="newForm.scope" value="system" class="w-4 h-4 text-blue-600 focus:ring-blue-500">
                        Toàn hệ thống
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                        <input type="radio" v-model="newForm.scope" value="branch" class="w-4 h-4 text-blue-600 focus:ring-blue-500">
                        Chi nhánh
                    </label>
                    <div v-if="newForm.scope === 'branch'" class="ml-7">
                        <select v-model="newForm.branch_id" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                            <option :value="null">-- Chọn chi nhánh --</option>
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Ghi chú</label>
                <textarea v-model="newForm.note" rows="2" placeholder="Ghi chú thêm..." class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none resize-none"></textarea>
            </div>
        </div>

        <div class="px-6 py-4 border-t flex justify-end gap-3">
            <button @click="showCreateModal = false" class="px-4 py-2 rounded text-sm font-medium text-gray-700 border border-gray-300 hover:bg-gray-50">Bỏ qua</button>
            <button @click="submitNew" :disabled="!newForm.account_number || !newForm.bank_name || !newForm.account_holder || newForm.processing" class="px-6 py-2 rounded text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50">Lưu</button>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div v-if="showEditModal" class="fixed inset-0 z-[1000] flex items-center justify-center bg-black/40" @click.self="showEditModal = false">
    <div class="bg-white rounded-lg shadow-2xl w-[520px] flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b">
            <h3 class="text-lg font-bold text-gray-800">Sửa tài khoản</h3>
            <button @click="showEditModal = false" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <div class="px-6 py-4 space-y-4">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">{{ editingAccount?.type === 'bank' ? 'Số tài khoản' : 'Số điện thoại / ID' }} <span class="text-red-500">*</span></label>
                <input v-model="editForm.account_number" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                <div v-if="editForm.errors.account_number" class="text-red-500 text-xs mt-1">{{ editForm.errors.account_number }}</div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">{{ editingAccount?.type === 'bank' ? 'Ngân hàng' : 'Ví điện tử' }} <span class="text-red-500">*</span></label>
                <input v-model="editForm.bank_name" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                <div v-if="editForm.errors.bank_name" class="text-red-500 text-xs mt-1">{{ editForm.errors.bank_name }}</div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Chủ tài khoản <span class="text-red-500">*</span></label>
                <input v-model="editForm.account_holder" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                <div v-if="editForm.errors.account_holder" class="text-red-500 text-xs mt-1">{{ editForm.errors.account_holder }}</div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-500 mb-2">Phạm vi áp dụng</label>
                <div class="space-y-2">
                    <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                        <input type="radio" v-model="editForm.scope" value="system" class="w-4 h-4 text-blue-600 focus:ring-blue-500">
                        Toàn hệ thống
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                        <input type="radio" v-model="editForm.scope" value="branch" class="w-4 h-4 text-blue-600 focus:ring-blue-500">
                        Chi nhánh
                    </label>
                    <div v-if="editForm.scope === 'branch'" class="ml-7">
                        <select v-model="editForm.branch_id" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                            <option :value="null">-- Chọn chi nhánh --</option>
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Ghi chú</label>
                <textarea v-model="editForm.note" rows="2" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none resize-none"></textarea>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Trạng thái</label>
                <select v-model="editForm.status" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                    <option value="active">Hoạt động</option>
                    <option value="inactive">Ngừng hoạt động</option>
                </select>
            </div>
        </div>

        <div class="px-6 py-4 border-t flex justify-end gap-3">
            <button @click="showEditModal = false" class="px-4 py-2 rounded text-sm font-medium text-gray-700 border border-gray-300 hover:bg-gray-50">Bỏ qua</button>
            <button @click="submitEdit" :disabled="!editForm.account_number || !editForm.bank_name || !editForm.account_holder || editForm.processing" class="px-6 py-2 rounded text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50">Lưu</button>
        </div>
    </div>
</div>
</template>
