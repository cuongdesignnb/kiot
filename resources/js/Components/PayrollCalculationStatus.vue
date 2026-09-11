<script setup>
import { ref } from 'vue';
import axios from 'axios';

const props = defineProps({ slip: Object, sheetId: Number, locked: Boolean, needsRecalc: Boolean });
const emit = defineEmits(['updated']);
const reason = ref('');
const error = ref('');
const busy = ref(false);
async function confirmZero() {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.put(`/api/paysheets/${props.sheetId}/payslips/${props.slip.id}`, { zero_salary_reason: reason.value });
        emit('updated', data.data);
    } catch (e) {
        error.value = Object.values(e.response?.data?.errors || {}).flat().join(' ') || e.response?.data?.message || 'Không lưu được xác nhận.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="text-xs mt-1 max-w-xs whitespace-normal">
        <p v-if="slip.details?.salary_type === 'hourly'" class="text-gray-600">
            Giờ thường: {{ (Number(slip.details.total_regular_minutes || 0) / 60).toFixed(2) }} giờ
            · Tăng ca: {{ (Number(slip.details.total_overtime_minutes || 0) / 60).toFixed(2) }} giờ
        </p>
        <p v-if="needsRecalc && !locked" class="text-amber-700">Dữ liệu đã thay đổi. Cần tính lại trước khi chốt.</p>
        <template v-if="slip.details?.validation">
            <p v-for="issue in slip.details.validation.issues" :key="issue" class="text-amber-700">{{ issue }}</p>
            <p v-if="slip.details.zero_salary_confirmation" class="text-green-700">Đã xác nhận: {{ slip.details.zero_salary_confirmation.reason }}</p>
            <div v-else-if="!locked && slip.base_salary === 0 && slip.details.validation.status !== 'blocked'" class="mt-1">
                <input v-model="reason" aria-label="Lý do lương chính bằng 0" placeholder="Lý do lương chính bằng 0" maxlength="500" class="border rounded p-1 w-full" />
                <button type="button" :disabled="busy || reason.trim().length < 5" @click.stop="confirmZero" class="text-blue-700 disabled:opacity-50">Lưu xác nhận</button>
            </div>
        </template>
        <p v-else-if="!locked" class="text-amber-700">Cần tính lại để kiểm tra dữ liệu lương.</p>
        <p v-if="error" role="alert" class="text-red-700">{{ error }}</p>
    </div>
</template>
