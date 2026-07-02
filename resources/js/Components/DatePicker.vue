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
import { formatDateVN, parseVNDate, toDateInputValue, pad2 } from '@/utils/dateTime.js';

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
const nativeInputRef = ref(null);

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

const nativeModelValue = computed(() => {
    return toDateInputValue(props.modelValue);
});

const openPicker = () => {
    if (nativeInputRef.value) {
        try {
            nativeInputRef.value.showPicker();
        } catch (e) {
            nativeInputRef.value.click();
        }
    }
};

const onNativeInput = (e) => {
    const val = e.target.value; // "yyyy-MM-dd"
    if (!val) {
        emit('update:modelValue', '');
        return;
    }
    error.value = '';
    text.value = formatDateVN(val);
    emit('update:modelValue', val);
};

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
        'w-full border rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500 pr-10',
        error.value ? 'border-red-400' : 'border-gray-300',
        props.disabled ? 'bg-gray-100 text-gray-500 cursor-not-allowed' : 'bg-white',
        props.inputClass,
    ];
});
</script>

<template>
    <div :class="wrapperClass || 'w-full'">
        <label v-if="label" class="block text-sm font-medium text-gray-700 mb-1">{{ label }}</label>
        <div class="relative flex items-center">
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
            
            <input
                ref="nativeInputRef"
                type="date"
                :value="nativeModelValue"
                @input="onNativeInput"
                :disabled="disabled"
                class="absolute invisible w-0 h-0 pointer-events-none"
            />
            
            <div class="absolute right-0 flex items-center h-full pr-2">
                <button
                    v-if="!disabled"
                    type="button"
                    @click="openPicker"
                    class="p-1 text-gray-400 hover:text-blue-600 focus:outline-none transition-colors duration-150 rounded"
                    tabindex="-1"
                    title="Chọn ngày"
                >
                    <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                </button>
            </div>
        </div>
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
    </div>
</template>
