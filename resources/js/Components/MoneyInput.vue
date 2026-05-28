<script setup>
/**
 * MoneyInput — ô nhập tiền VNĐ có tách hàng nghìn.
 *
 * HOTFIX 24.20 — format realtime ngay khi gõ. User gõ "1500000" sẽ
 * thấy "1.500.000" hiện ra ngay từng ký tự, không cần blur. v-model
 * trả về number raw (1500000), không phải string "1.500.000".
 *
 * Props:
 *   modelValue  — number (giá trị thật, VD: 1500000)
 *   placeholder — string (mặc định "0")
 *   suffix      — boolean (nếu true, hiển thị suffix "đ" bên ngoài)
 *   disabled    — boolean
 *   readonly    — boolean
 *   inputClass  — string (class CSS cho input)
 *
 * Emit: update:modelValue (number), input (number), blur (number)
 */
import { ref, watch } from 'vue';
import { formatVndInput, parseVndInput, isMoneyInputEmpty, parseMoneyModelValue } from '@/utils/money';

const props = defineProps({
    modelValue: { type: [Number, String, null], default: '' },
    placeholder: { type: String, default: '0' },
    suffix: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    inputClass: { type: String, default: '' },
    min: { type: Number, default: undefined },
});

const emit = defineEmits(['update:modelValue', 'input', 'blur']);

// HOTFIX 24.20 — empty model → empty input (keep placeholder visible);
// numeric model → formatted display. Skipping the "0 → 0" default lets
// the user see the placeholder instead of having to delete a leading 0
// before they type.
const renderModel = (val) => (isMoneyInputEmpty(val) ? '' : formatVndInput(parseMoneyModelValue(val)));

const displayValue = ref(renderModel(props.modelValue));

watch(() => props.modelValue, (val) => {
    const incoming = renderModel(val);
    // Avoid clobbering the in-flight user input when v-model just echoes
    // back what we already typed (Vue re-emits the numeric round-trip).
    const currentRaw = parseVndInput(displayValue.value);
    const valRaw = parseMoneyModelValue(val);
    if (currentRaw !== valRaw) {
        displayValue.value = incoming;
    }
});

function onInput(e) {
    const raw = e.target.value;
    const formatted = formatVndInput(raw);
    const num = parseVndInput(raw);
    // Force the input's visible text to the formatted version so dot
    // separators appear *while* the user types, not on blur.
    if (e.target.value !== formatted) {
        e.target.value = formatted;
    }
    displayValue.value = formatted;
    emit('update:modelValue', num);
    emit('input', num);
}

function onBlur(e) {
    const num = parseVndInput(e.target.value);
    // Re-normalise the display in case the user pasted something exotic
    // (mix of dots / commas / spaces) — keep formatVndInput as the
    // single source of truth.
    displayValue.value = isMoneyInputEmpty(num) ? '' : formatVndInput(num);
    emit('update:modelValue', num);
    emit('blur', num);
}
</script>

<template>
    <div class="relative inline-flex items-center w-full">
        <input
            type="text"
            :value="displayValue"
            @blur="onBlur"
            @input="onInput"
            :placeholder="placeholder"
            :disabled="disabled"
            :readonly="readonly"
            :class="inputClass || 'w-full border border-gray-300 rounded px-3 py-2 text-sm text-right focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none'"
            inputmode="numeric"
            autocomplete="off"
        />
        <span v-if="suffix" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm font-medium pointer-events-none">₫</span>
    </div>
</template>
