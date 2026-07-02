<script setup>
/**
 * CurrencyInput — Hiển thị số tiền phân tách hàng nghìn kiểu Việt Nam
 * Khi focus: hiện số thô để chỉnh sửa dễ dàng
 * Khi blur:  hiện dạng 10.000.000
 * v-model số nguyên — truyền/nhận kiểu Number
 */
import { ref, watch, nextTick } from 'vue';

const props = defineProps({
    modelValue: { type: [Number, String], default: 0 },
});
const emit = defineEmits(['update:modelValue']);

const focused = ref(false);
const displayVal = ref('');

const fmt = (n) => {
    const num = Number(n || 0);
    return num === 0 ? '' : num.toLocaleString('vi-VN');
};

displayVal.value = fmt(props.modelValue);

watch(() => props.modelValue, (v) => {
    if (!focused.value) displayVal.value = fmt(v);
});

const onFocus = (e) => {
    focused.value = true;
    const num = Number(props.modelValue || 0);
    displayVal.value = num ? String(num) : '';
    nextTick(() => e.target.select());
};

const onBlur = () => {
    focused.value = false;
    displayVal.value = fmt(props.modelValue);
};

const onInput = (e) => {
    // Chỉ giữ lại chữ số và dấu trừ
    const raw = e.target.value.replace(/[^\d-]/g, '');
    const num = parseInt(raw) || 0;
    emit('update:modelValue', num);
};
</script>

<template>
    <input
        type="text"
        :value="displayVal"
        @focus="onFocus"
        @blur="onBlur"
        @input="onInput"
        inputmode="numeric"
        v-bind="$attrs"
    />
</template>
