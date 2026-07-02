<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ locations: Array, show: Boolean });
const emit = defineEmits(['close']);

const newForm = useForm({ name: '' });
const editingId = ref(null);
const editForm = useForm({ name: '' });

const startEdit = (l) => { editingId.value = l.id; editForm.name = l.name; };
const cancelEdit = () => { editingId.value = null; };

const submitNew = () => {
    newForm.post('/settings/locations', { preserveScroll: true, onSuccess: () => newForm.reset() });
};
const submitEdit = (id) => {
    editForm.put(`/settings/locations/${id}`, { preserveScroll: true, onSuccess: () => { editingId.value = null; } });
};
const deleteItem = (l) => {
    if (confirm(`Xóa vị trí "${l.name}"?`)) {
        useForm({}).delete(`/settings/locations/${l.id}`, { preserveScroll: true });
    }
};
</script>

<template>
<div v-if="show" class="fixed inset-0 z-[999] flex items-center justify-center bg-black/40" @click.self="emit('close')">
    <div class="bg-white rounded-lg shadow-2xl w-[580px] max-h-[85vh] flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b">
            <h2 class="text-lg font-bold text-gray-800">Quản lý Vị trí</h2>
            <button @click="emit('close')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="px-6 py-3 bg-teal-50 border-b flex gap-3 items-end">
            <div class="flex-1">
                <label class="block text-xs font-bold text-gray-500 mb-1">Tên vị trí mới</label>
                <input v-model="newForm.name" @keyup.enter="submitNew" placeholder="Ví dụ: Kệ A1, Tủ B2, Giá C3..." class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:ring-1 focus:ring-teal-500 focus:border-teal-500 outline-none">
            </div>
            <button @click="submitNew" :disabled="!newForm.name || newForm.processing" class="bg-teal-600 hover:bg-teal-700 text-white px-4 py-1.5 rounded text-sm font-medium disabled:opacity-50 whitespace-nowrap">+ Thêm mới</button>
        </div>
        <div class="flex-1 overflow-y-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="text-left px-6 py-2.5 text-xs font-bold text-gray-500 uppercase">Tên vị trí</th>
                        <th class="text-right px-6 py-2.5 text-xs font-bold text-gray-500 uppercase w-32">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="l in locations" :key="l.id" class="hover:bg-gray-50/80 group">
                        <td class="px-6 py-3">
                            <template v-if="editingId === l.id">
                                <input v-model="editForm.name" @keyup.enter="submitEdit(l.id)" @keyup.escape="cancelEdit" class="w-full border border-teal-400 rounded px-2 py-1 text-sm outline-none" autofocus>
                            </template>
                            <template v-else><span class="text-gray-800 font-medium">{{ l.name }}</span></template>
                        </td>
                        <td class="text-right px-6 py-3">
                            <template v-if="editingId === l.id">
                                <button @click="submitEdit(l.id)" class="text-teal-600 text-xs font-bold mr-2">Lưu</button>
                                <button @click="cancelEdit" class="text-gray-500 text-xs font-bold">Hủy</button>
                            </template>
                            <template v-else>
                                <button @click="startEdit(l)" class="text-blue-600 text-xs font-bold mr-3 opacity-0 group-hover:opacity-100 transition-opacity">Sửa</button>
                                <button @click="deleteItem(l)" class="text-red-500 text-xs font-bold opacity-0 group-hover:opacity-100 transition-opacity">Xóa</button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!locations?.length"><td colspan="2" class="px-6 py-8 text-center text-gray-400">Chưa có vị trí nào</td></tr>
                </tbody>
            </table>
        </div>
        <div class="px-6 py-3 border-t bg-gray-50 flex justify-between items-center">
            <span class="text-xs text-gray-500">Tổng: <strong>{{ locations?.length || 0 }}</strong> vị trí</span>
            <button @click="emit('close')" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-1.5 rounded text-sm font-medium">Đóng</button>
        </div>
    </div>
</div>
</template>
