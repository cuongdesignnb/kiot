<script setup>
/**
 * MoneyInput — ô nhập tiền VNĐ có tách hàng nghìn.
 *
 * Props:
 *   modelValue  — number (giá trị thật, VD: 1500000)
 *   placeholder — string (mặc định "0")
 *   suffix      — boolean (nếu true, hiển thị suffix "đ" bên ngoài)
 *   disabled    — boolean
 *   inputClass  — string (class CSS cho input)
 *
 * Emit: update:modelValue (number)
 *
 * Hiển thị: 1.500.000 khi blur, raw number khi focus.
 * Submit value luôn là number.
 */
import { ref, watch } from 'vue';
import { formatMoneyInput, parseVND } from '@/utils/money';

const props = defineProps({
    modelValue: { type: [Number, String], default: 0 },
    placeholder: { type: String, default: '0' },
    suffix: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    inputClass: { type: String, default: '' },
    min: { type: Number, default: undefined },
});

const emit = defineEmits(['update:modelValue']);

const isFocused = ref(false);
const displayValue = ref(formatMoneyInput(props.modelValue));

watch(() => props.modelValue, (val) => {
    if (!isFocused.value) {
        displayValue.value = formatMoneyInput(val);
    }
});

function onFocus(e) {
    isFocused.value = true;
    const num = parseVND(displayValue.value);
    displayValue.value = num === 0 ? '' : String(num);
}

function onBlur(e) {
    isFocused.value = false;
    const num = parseVND(displayValue.value);
    emit('update:modelValue', num);
    displayValue.value = formatMoneyInput(num);
}

function onInput(e) {
    displayValue.value = e.target.value;
    const num = parseVND(e.target.value);
    emit('update:modelValue', num);
}
</script>

<template>
    <div class="relative inline-flex items-center w-full">
        <input
            type="text"
            :value="displayValue"
            @focus="onFocus"
            @blur="onBlur"
            @input="onInput"
            :placeholder="placeholder"
            :disabled="disabled"
            :class="inputClass || 'w-full border border-gray-300 rounded px-3 py-2 text-sm text-right focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none'"
            inputmode="numeric"
        />
        <span v-if="suffix" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm font-medium pointer-events-none">₫</span>
    </div>
</template>
