<script setup>
/**
 * SelectFilter — simple single-value select bound to an `options` array.
 * options item shape: { value, label }
 */
const props = defineProps({
    modelValue: { type: [String, Number, null], default: "" },
    label: { type: String, default: "" },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: "-- Tất cả --" },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(["update:modelValue"]);
</script>

<template>
    <div class="bg-white rounded-lg shadow-sm p-4">
        <label v-if="label" class="block text-sm font-semibold text-gray-700 mb-2">
            {{ label }}
            <span v-if="disabled" class="ml-1 text-[10px] text-gray-400 font-normal">(đã khoá)</span>
        </label>
        <select
            :value="modelValue"
            :disabled="disabled"
            @change="emit('update:modelValue', $event.target.value)"
            class="w-full border border-gray-300 rounded px-2 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
        >
            <option value="">{{ placeholder }}</option>
            <option v-for="opt in options" :key="opt.key || opt.value" :value="opt.value">
                {{ opt.label }}
            </option>
        </select>
    </div>
</template>
