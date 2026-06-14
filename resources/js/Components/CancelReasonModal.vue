<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    show: Boolean,
    title: { type: String, default: 'Xác nhận hủy chứng từ' },
    warning: { type: String, default: '' },
    documentCode: { type: String, default: '' },
    submitting: Boolean,
});
const emit = defineEmits(['close', 'confirm']);
const reason = ref('');
const valid = computed(() => reason.value.trim().length >= 10);

watch(() => props.show, (show) => {
    if (show) reason.value = '';
});
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-[110] flex items-center justify-center bg-black/40" @click.self="emit('close')">
            <div class="w-full max-w-lg rounded-lg bg-white shadow-xl">
                <div class="flex items-center justify-between border-b px-5 py-4">
                    <h3 class="font-bold text-gray-900">{{ title }}</h3>
                    <button type="button" class="text-xl text-gray-400" @click="emit('close')">&times;</button>
                </div>
                <div class="space-y-4 p-5">
                    <div v-if="documentCode" class="text-sm"><span class="text-gray-500">Chứng từ:</span> <b>{{ documentCode }}</b></div>
                    <div v-if="warning" class="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">{{ warning }}</div>
                    <label class="block text-sm font-medium">Lý do hủy</label>
                    <textarea v-model="reason" rows="4" class="w-full rounded border px-3 py-2" placeholder="Nhập ít nhất 10 ký tự" />
                    <div class="text-xs" :class="valid ? 'text-gray-500' : 'text-red-600'">Tối thiểu 10 ký tự.</div>
                </div>
                <div class="flex justify-end gap-3 border-t px-5 py-4">
                    <button type="button" class="rounded border px-4 py-2" @click="emit('close')">Bỏ qua</button>
                    <button type="button" class="rounded bg-red-600 px-4 py-2 text-white disabled:opacity-50"
                        :disabled="!valid || submitting" @click="emit('confirm', reason.trim())">
                        {{ submitting ? 'Đang xử lý...' : 'Xác nhận hủy' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
