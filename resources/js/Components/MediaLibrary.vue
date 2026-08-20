<script setup>
import { ref } from 'vue';
import MediaPicker from '@/Components/MediaPicker.vue';

defineProps({
    modelValue: { type: String, default: '' },
    collection: { type: String, default: 'default' },
    label: { type: String, default: 'Chọn ảnh' },
});
const emit = defineEmits(['update:modelValue']);
const selectedIds = ref([]);

const select = (media) => emit('update:modelValue', media?.url || '');
</script>

<template>
    <MediaPicker
        :model-value="selectedIds"
        :multiple="false"
        :collection="collection"
        :label="label"
        @update:model-value="selectedIds = $event"
        @select="select"
    />
</template>
