<script setup>
const props = defineProps({
    label: { type: String, required: true },
    field: { type: String, required: true },
    currentSort: { type: String, default: '' },
    currentDirection: { type: String, default: 'asc' },
    align: { type: String, default: 'left' }, // 'left', 'right', 'center'
    defaultDirection: { type: String, default: 'asc' }, // first-click direction: 'asc' for codes/names, 'desc' for dates/amounts
});

const emit = defineEmits(['sort']);

const isActive = () => props.currentSort === props.field;
const isAsc = () => isActive() && props.currentDirection === 'asc';
const isDesc = () => isActive() && props.currentDirection === 'desc';

const toggleSort = () => {
    const first = props.defaultDirection || 'asc';
    const second = first === 'asc' ? 'desc' : 'asc';
    if (!isActive()) {
        emit('sort', props.field, first);
    } else if (props.currentDirection === first) {
        emit('sort', props.field, second);
    } else {
        emit('sort', '', '');
    }
};
</script>

<template>
    <th
        @click="toggleSort"
        class="cursor-pointer select-none group transition-colors hover:bg-blue-50/60"
        :class="{
            'text-left': align === 'left',
            'text-right': align === 'right',
            'text-center': align === 'center',
        }"
    >
        <div
            class="inline-flex items-center gap-1"
            :class="{
                'flex-row-reverse': align === 'right',
            }"
        >
            <span :class="{ 'text-blue-600': isActive() }">{{ label }}</span>
            <span class="inline-flex flex-col -space-y-0.5 ml-0.5">
                <svg
                    class="w-2.5 h-2.5 transition-colors"
                    :class="isAsc() ? 'text-blue-600' : 'text-gray-300 group-hover:text-gray-400'"
                    viewBox="0 0 10 6"
                    fill="currentColor"
                >
                    <path d="M5 0L10 6H0L5 0Z" />
                </svg>
                <svg
                    class="w-2.5 h-2.5 transition-colors"
                    :class="isDesc() ? 'text-blue-600' : 'text-gray-300 group-hover:text-gray-400'"
                    viewBox="0 0 10 6"
                    fill="currentColor"
                >
                    <path d="M5 6L0 0H10L5 6Z" />
                </svg>
            </span>
        </div>
    </th>
</template>
