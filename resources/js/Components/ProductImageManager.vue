<script setup>
import axios from 'axios';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import MediaPicker from '@/Components/MediaPicker.vue';

const props = defineProps({
    productId: { type: [Number, String], default: null },
    initialImages: { type: Array, default: () => [] },
    mediaIds: { type: Array, default: () => [] },
    primaryMediaId: { type: [Number, String], default: null },
    maxCount: { type: Number, default: 12 },
});
const emit = defineEmits(['update:mediaIds', 'update:primaryMediaId', 'update:busy', 'update:error']);

const items = ref([]);
const pickerIds = ref([]);
const busy = ref(false);
const error = ref('');
const preview = ref(null);
const draggedIndex = ref(null);

const hydrate = () => {
    items.value = (props.initialImages || []).map((image) => ({ ...image, key: `saved-${image.id}`, saved: true }));
};
hydrate();
watch(() => props.initialImages, hydrate, { deep: true });
watch(busy, (value) => emit('update:busy', value), { immediate: true });
watch(error, (value) => emit('update:error', value), { immediate: true });

const countLabel = computed(() => `${props.productId ? items.value.length : props.mediaIds.length}/${props.maxCount}`);
const existingMediaIds = computed(() => items.value.map((item) => Number(item.media_id)).filter(Boolean));

const applyLocalSelection = (ids) => {
    if (props.productId) return;
    emit('update:mediaIds', ids.map(Number));
};

const attachSelected = async (ids) => {
    if (!props.productId || !ids.length) return;
    busy.value = true;
    error.value = '';
    try {
        const allIds = [...new Set([...existingMediaIds.value, ...ids.map(Number)])];
        const primary = props.primaryMediaId || items.value.find((item) => item.is_primary)?.media_id || allIds[0];
        const { data } = await axios.post(`/products/${props.productId}/images`, {
            media_ids: allIds,
            primary_media_id: primary,
        });
        items.value = (data.images || []).map((image) => ({ ...image, key: `saved-${image.id}`, saved: true }));
        pickerIds.value = [];
    } catch (exception) {
        error.value = exception.response?.data?.message
            || Object.values(exception.response?.data?.errors || {}).flat()?.[0]
            || 'Không thể thêm ảnh vào sản phẩm.';
    } finally {
        busy.value = false;
    }
};

watch(pickerIds, (ids) => { if (ids.length) attachSelected(ids); }, { deep: true });

const choosePrimary = async (item, index) => {
    error.value = '';
    if (props.productId) {
        if (!item.id) return;
        busy.value = true;
        try {
            await axios.put(`/products/${props.productId}/images/${item.id}/primary`);
            items.value = items.value.map((candidate) => ({ ...candidate, is_primary: candidate.id === item.id }));
        } catch (exception) {
            error.value = exception.response?.data?.message || 'Không thể chọn ảnh đại diện.';
        } finally { busy.value = false; }
        return;
    }
    const mediaId = Number(item.id || props.mediaIds[index]);
    emit('update:primaryMediaId', mediaId || null);
};

const remove = async (item, index) => {
    error.value = '';
    if (props.productId) {
        if (!item.id) return;
        busy.value = true;
        try {
            await axios.delete(`/products/${props.productId}/images/${item.id}`);
            items.value.splice(index, 1);
            if (item.is_primary && items.value.length) {
                await axios.put(`/products/${props.productId}/images/${items.value[0].id}/primary`);
                items.value[0].is_primary = true;
            }
        } catch (exception) {
            error.value = exception.response?.data?.message || 'Không thể gỡ ảnh khỏi sản phẩm.';
        } finally { busy.value = false; }
        return;
    }

    const ids = props.mediaIds.filter((id) => Number(id) !== Number(item.id));
    applyLocalSelection(ids);
    if (Number(props.primaryMediaId) === Number(item.id)) emit('update:primaryMediaId', ids[0] || null);
};

const dragStart = (index) => { draggedIndex.value = index; };
const dropAt = async (index) => {
    const from = draggedIndex.value;
    draggedIndex.value = null;
    if (from === null || from === index) return;
    const [moved] = items.value.splice(from, 1);
    items.value.splice(index, 0, moved);
    if (!props.productId) {
        applyLocalSelection(items.value.map((item) => item.id));
        return;
    }
    busy.value = true;
    try {
        const { data } = await axios.put(`/products/${props.productId}/images/reorder`, { image_ids: items.value.map((item) => item.id) });
        items.value = (data.images || []).map((image) => ({ ...image, key: `saved-${image.id}`, saved: true }));
    } catch (exception) {
        error.value = exception.response?.data?.message || 'Không thể sắp xếp ảnh.';
    } finally { busy.value = false; }
};

onBeforeUnmount(() => { preview.value = null; });
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center justify-between">
            <span class="text-sm font-semibold text-gray-700">Ảnh sản phẩm</span>
            <span class="text-xs text-gray-500">{{ countLabel }}</span>
        </div>

        <MediaPicker
            v-if="!productId"
            :model-value="mediaIds"
            :primary-media-id="primaryMediaId"
            :multiple="true"
            collection="products"
            label="Chọn ảnh từ thư viện"
            @update:model-value="applyLocalSelection"
            @update:primary-media-id="emit('update:primaryMediaId', $event)"
        />
        <MediaPicker
            v-else
            v-model="pickerIds"
            :multiple="true"
            collection="products"
            label="Thêm từ thư viện"
        />

        <p class="text-[11px] text-gray-500">Kéo thả để sắp xếp. Nhấn ngôi sao để chọn ảnh đại diện. Ảnh tải lên được lưu WebP và dùng chung toàn hệ thống.</p>
        <p v-if="error" class="rounded bg-red-50 p-2 text-xs text-red-700" role="alert">{{ error }}</p>

        <div v-if="items.length" class="grid grid-cols-2 gap-2">
            <div v-for="(item, index) in items" :key="item.key" draggable="true" class="group relative aspect-square overflow-hidden rounded-lg border bg-gray-50" :class="item.is_primary ? 'border-blue-500 ring-2 ring-blue-100' : 'border-gray-200'" @dragstart="dragStart(index)" @dragover.prevent @drop.prevent="dropAt(index)">
                <img :src="item.variants?.small?.url || item.url" class="h-full w-full cursor-zoom-in object-cover" alt="Ảnh sản phẩm" @click="preview = item.variants?.medium?.url || item.url" />
                <div class="absolute inset-x-0 bottom-0 flex justify-between bg-black/55 p-1 opacity-0 transition group-hover:opacity-100">
                    <button type="button" class="rounded bg-white/90 px-1.5 py-1 text-xs" :title="item.is_primary ? 'Ảnh đại diện' : 'Chọn làm ảnh đại diện'" @click="choosePrimary(item, index)">{{ item.is_primary ? '★' : '☆' }}</button>
                    <button type="button" class="rounded bg-red-600 px-1.5 py-1 text-xs text-white" @click="remove(item, index)">Gỡ</button>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="preview" class="fixed inset-0 z-[130] flex items-center justify-center bg-black/80 p-6" @click.self="preview = null">
                <img :src="preview" class="max-h-full max-w-full rounded object-contain" alt="Xem trước ảnh sản phẩm" />
                <button type="button" class="absolute right-5 top-5 rounded bg-white px-3 py-2 text-sm" @click="preview = null">Đóng</button>
            </div>
        </Teleport>
    </div>
</template>
