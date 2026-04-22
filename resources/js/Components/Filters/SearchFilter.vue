<script setup>
/**
 * SearchFilter — sidebar text search (debounce handled by useFilters).
 */
const props = defineProps({
    modelValue: { type: String, default: "" },
    label: { type: String, default: "Tìm kiếm" },
    placeholder: { type: String, default: "Tìm kiếm..." },
});
const emit = defineEmits(["update:modelValue"]);
</script>

<template>
    <div class="bg-white rounded-lg shadow-sm p-4">
        <label class="block text-sm font-semibold text-gray-700 mb-2">{{ label }}</label>
        <div class="relative">
            <input
                :value="modelValue"
                @input="emit('update:modelValue', $event.target.value)"
                type="text"
                :placeholder="placeholder"
                class="w-full border border-gray-300 rounded pl-8 pr-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
            <svg class="absolute left-2 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M21 21l-4.35-4.35m0 0A7.5 7.5 0 1010.5 18a7.46 7.46 0 006.15-3.2z" />
            </svg>
        </div>
    </div>
</template>
