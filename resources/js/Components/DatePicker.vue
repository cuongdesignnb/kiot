<script setup>
/**
 * DatePicker — locale-independent dd/MM/yyyy date text input.
 *
 * v-model contract:
 *   - Reads the canonical "yyyy-MM-dd" form.
 *   - Emits update:modelValue as canonical "yyyy-MM-dd" (or empty string when cleared).
 *
 * Display: dd/MM/yyyy. Never uses native <input type="date"> because that
 * widget honours the browser locale.
 *
 * On blur / Enter, validates the typed text. If invalid, restores the last canonical value
 * and shows the error message.
 */
import { computed, ref, watch } from 'vue';
import { formatDateVN, parseVNDate, pad2 } from '@/utils/dateTime.js';

const props = defineProps({
    modelValue: { type: [String, Date, Number, null], default: '' },
    placeholder: { type: String, default: 'dd/MM/yyyy' },
    disabled: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    label: { type: String, default: '' },
    inputClass: { type: String, default: '' },
    wrapperClass: { type: String, default: '' },
    naked: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'blur']);

const text = ref(formatDateVN(props.modelValue));
const error = ref('');

watch(
    () => props.modelValue,
    (v) => {
        const formatted = formatDateVN(v);
        if (formatted !== text.value) {
            text.value = formatted;
            error.value = '';
        }
    }
);

const onInput = (e) => {
    text.value = e.target.value;
    error.value = '';
};

const commit = () => {
    const raw = (text.value || '').trim();
    if (!raw) {
        emit('update:modelValue', '');
        error.value = '';
        return;
    }
    const d = parseVNDate(raw);
    if (!d) {
        error.value = 'Định dạng phải là dd/MM/yyyy.';
        return;
    }
    error.value = '';
    const canonical = `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
    text.value = formatDateVN(d);
    emit('update:modelValue', canonical);
};

const onBlur = () => {
    commit();
    emit('blur');
};

const onKeydown = (e) => {
    if (e.key === 'Enter') {
        e.preventDefault();
        commit();
    }
};

const computedClass = computed(() => {
    if (props.naked) {
        return ['focus:outline-none', error.value ? 'ring-1 ring-red-400 rounded' : '', props.inputClass];
    }
    return [
        'w-full border rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500',
        error.value ? 'border-red-400' : 'border-gray-300',
        props.disabled ? 'bg-gray-100 text-gray-500 cursor-not-allowed' : 'bg-white',
        props.inputClass,
    ];
});
</script>

<template>
    <div :class="wrapperClass || 'w-full'">
        <label v-if="label" class="block text-sm font-medium text-gray-700 mb-1">{{ label }}</label>
        <div class="relative">
            <input
                type="text"
                inputmode="numeric"
                :value="text"
                @input="onInput"
                @blur="onBlur"
                @keydown="onKeydown"
                :placeholder="placeholder"
                :disabled="disabled"
                :required="required"
                :class="computedClass"
                autocomplete="off"
            />
        </div>
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
    </div>
</template>
