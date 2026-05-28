<script setup>
/**
 * DateTimePicker — locale-independent dd/MM/yyyy HH:mm text input.
 *
 * v-model contract:
 *   - Reads the canonical "yyyy-MM-ddTHH:mm" form (or "yyyy-MM-dd HH:mm:ss" — both accepted).
 *   - Emits update:modelValue as canonical "yyyy-MM-ddTHH:mm" (or empty string when cleared).
 *
 * Display: dd/MM/yyyy HH:mm. Never uses native <input type="datetime-local"> because that
 * widget honours the browser locale and silently shows MM/DD/YYYY in en-US.
 *
 * On blur / Enter, validates the typed text. If invalid, restores the last canonical value
 * and shows the error slot.
 */
import { computed, ref, watch } from 'vue';
import {
    formatDateTimeVN,
    parseVNDateTime,
    pad2,
} from '@/utils/dateTime.js';

const props = defineProps({
    modelValue: { type: [String, Date, Number, null], default: '' },
    placeholder: { type: String, default: 'dd/MM/yyyy HH:mm' },
    disabled: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    /** Optional inline label. */
    label: { type: String, default: '' },
    /** Extra css class on the input. */
    inputClass: { type: String, default: '' },
    /** Extra wrapper css class instead of default w-full. */
    wrapperClass: { type: String, default: '' },
    /** When true, hide the inline "Hiện tại" shortcut button (saves space in tight UIs). */
    compact: { type: Boolean, default: false },
    /** When true, drop the default border/background so inputClass fully owns the look. */
    naked: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'blur']);

// Local text shown to the user (always in dd/MM/yyyy HH:mm form when valid).
const text = ref(formatDateTimeVN(props.modelValue));
const error = ref('');

watch(
    () => props.modelValue,
    (v) => {
        const formatted = formatDateTimeVN(v);
        // Don't clobber while user is mid-typing if their text is being parsed identical.
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
    const d = parseVNDateTime(raw);
    if (!d) {
        error.value = 'Định dạng phải là dd/MM/yyyy HH:mm.';
        return;
    }
    error.value = '';
    // Canonical yyyy-MM-ddTHH:mm.
    const canonical = `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}T${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
    // Re-format text to the normalised display.
    text.value = formatDateTimeVN(d);
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

const setNow = () => {
    const d = new Date();
    text.value = formatDateTimeVN(d);
    error.value = '';
    const canonical = `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}T${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
    emit('update:modelValue', canonical);
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
            <button
                v-if="!disabled && !compact"
                type="button"
                @click="setNow"
                class="absolute inset-y-0 right-1 my-1 px-2 text-xs text-blue-600 hover:bg-blue-50 rounded"
                tabindex="-1"
                title="Thời gian hiện tại"
            >Hiện tại</button>
        </div>
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
    </div>
</template>
