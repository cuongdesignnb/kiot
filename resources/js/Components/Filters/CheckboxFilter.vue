<script setup>
/**
 * CheckboxFilter — multi-select checkbox list bound to an array of values.
 * options item shape: { value, label, color? }
 *
 * Optional `color` maps to a small tailwind dot beside each checkbox.
 */
import { computed } from "vue";

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    label: { type: String, default: "" },
    options: { type: Array, default: () => [] },
});
const emit = defineEmits(["update:modelValue"]);

const selected = computed({
    get: () => Array.isArray(props.modelValue) ? props.modelValue : [],
    set: (v) => emit("update:modelValue", v),
});

const toggle = (value) => {
    const arr = [...selected.value];
    const idx = arr.indexOf(value);
    if (idx > -1) arr.splice(idx, 1);
    else arr.push(value);
    selected.value = arr;
};

const dotClass = (color) => ({
    "bg-gray-400": color === "gray" || !color,
    "bg-green-500": color === "green",
    "bg-red-500": color === "red",
    "bg-amber-500": color === "amber",
    "bg-blue-500": color === "blue",
    "bg-purple-500": color === "purple",
    "bg-yellow-500": color === "yellow",
});
</script>

<template>
    <div class="bg-white rounded-lg shadow-sm p-4">
        <label v-if="label" class="block text-sm font-semibold text-gray-700 mb-2">{{ label }}</label>
        <div class="space-y-1.5">
            <label
                v-for="opt in options"
                :key="opt.value"
                class="flex items-center gap-2 cursor-pointer text-sm text-gray-700 hover:text-blue-600"
            >
                <input
                    type="checkbox"
                    :checked="selected.includes(opt.value)"
                    @change="toggle(opt.value)"
                    class="text-blue-600 focus:ring-blue-500 rounded"
                />
                <span v-if="opt.color" :class="['inline-block w-2 h-2 rounded-full', dotClass(opt.color)]"></span>
                {{ opt.label }}
            </label>
        </div>
    </div>
</template>
