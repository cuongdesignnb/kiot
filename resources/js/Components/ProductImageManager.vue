<script setup>
import axios from 'axios';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    productId: { type: [Number, String], default: null },
    initialImages: { type: Array, default: () => [] },
    files: { type: Array, default: () => [] },
    primaryIndex: { type: Number, default: null },
    maxCount: { type: Number, default: 12 },
    maxSizeKb: { type: Number, default: 5120 },
});
const emit = defineEmits(['update:files', 'update:primaryIndex', 'update:busy', 'update:error']);

const items = ref([]);
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

const countLabel = computed(() => `${items.value.length}/${props.maxCount}`);

function emitPending() {
    if (props.productId) return;
    emit('update:files', items.value.map((item) => item.file));
    const index = items.value.findIndex((item) => item.is_primary);
    emit('update:primaryIndex', index >= 0 ? index : null);
}

function validate(file) {
    if (! ['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return 'Chỉ chấp nhận JPG, JPEG, PNG hoặc WebP.';
    if (file.size > props.maxSizeKb * 1024) return `Mỗi ảnh tối đa ${props.maxSizeKb} KB.`;
    return null;
}

async function chooseFiles(event) {
    error.value = '';
    const chosen = Array.from(event.target.files || []);
    event.target.value = '';
    if (! chosen.length) return;
    if (items.value.length + chosen.length > props.maxCount) {
        error.value = `Mỗi sản phẩm được tải tối đa ${props.maxCount} ảnh.`;
        return;
    }
    for (const file of chosen) {
        const message = validate(file);
        if (message) { error.value = message; return; }
    }

    if (props.productId) {
        busy.value = true;
        try {
            const body = new FormData();
            chosen.forEach((file) => body.append('images[]', file));
            if (! items.value.some((item) => item.is_primary)) body.append('primary_index', '0');
            const { data } = await axios.post(`/products/${props.productId}/images`, body);
            items.value = (data.images || []).map((image) => ({ ...image, key: `saved-${image.id}`, saved: true }));
        } catch (exception) {
            error.value = exception.response?.data?.message
                || Object.values(exception.response?.data?.errors || {}).flat()?.[0]
                || 'Không thể tải ảnh lên.';
        } finally {
            busy.value = false;
        }
        return;
    }

    const hasPrimary = items.value.some((item) => item.is_primary);
    chosen.forEach((file, index) => items.value.push({
        key: `${file.name}-${file.size}-${file.lastModified}-${Math.random()}`,
        file,
        url: URL.createObjectURL(file),
        is_primary: ! hasPrimary && index === 0,
        saved: false,
    }));
    emitPending();
}

async function selectPrimary(item, index) {
    error.value = '';
    if (props.productId) {
        busy.value = true;
        try {
            await axios.put(`/products/${props.productId}/images/${item.id}/primary`);
            items.value = items.value.map((candidate) => ({ ...candidate, is_primary: candidate.id === item.id }));
        } catch (exception) {
            error.value = exception.response?.data?.message || 'Không thể chọn ảnh đại diện.';
        } finally { busy.value = false; }
        return;
    }
    items.value = items.value.map((candidate, candidateIndex) => ({ ...candidate, is_primary: candidateIndex === index }));
    emitPending();
}

async function remove(item, index) {
    if (! window.confirm('Xóa ảnh này khỏi sản phẩm?')) return;
    error.value = '';
    if (props.productId) {
        busy.value = true;
        try {
            await axios.delete(`/products/${props.productId}/images/${item.id}`);
            items.value.splice(index, 1);
            if (item.is_primary && items.value.length) items.value[0].is_primary = true;
        } catch (exception) {
            error.value = exception.response?.data?.message || 'Không thể xóa ảnh.';
        } finally { busy.value = false; }
        return;
    }
    if (! item.saved) URL.revokeObjectURL(item.url);
    items.value.splice(index, 1);
    if (item.is_primary && items.value.length) items.value[0].is_primary = true;
    emitPending();
}

function dragStart(index) { draggedIndex.value = index; }
async function dropAt(index) {
    const from = draggedIndex.value;
    draggedIndex.value = null;
    if (from === null || from === index) return;
    const [moved] = items.value.splice(from, 1);
    items.value.splice(index, 0, moved);
    emitPending();
    if (! props.productId) return;
    busy.value = true;
    try {
        const { data } = await axios.put(`/products/${props.productId}/images/reorder`, { image_ids: items.value.map((item) => item.id) });
        items.value = (data.images || []).map((image) => ({ ...image, key: `saved-${image.id}`, saved: true }));
    } catch (exception) {
        error.value = exception.response?.data?.message || 'Không thể sắp xếp ảnh.';
    } finally { busy.value = false; }
}

onBeforeUnmount(() => items.value.filter((item) => ! item.saved).forEach((item) => URL.revokeObjectURL(item.url)));
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center justify-between">
            <span class="text-sm font-semibold text-gray-700">Ảnh sản phẩm</span>
            <span class="text-xs text-gray-500">{{ countLabel }}</span>
        </div>
        <label class="flex cursor-pointer items-center justify-center rounded-lg border-2 border-dashed border-gray-300 px-3 py-5 text-center text-sm text-gray-500 hover:border-blue-400 hover:bg-blue-50 hover:text-blue-600" :class="busy ? 'pointer-events-none opacity-50' : ''">
            <span>{{ busy ? 'Đang xử lý…' : 'Tải nhiều ảnh lên' }}</span>
            <input class="hidden" type="file" multiple accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" @change="chooseFiles" />
        </label>
        <p class="text-[11px] text-gray-500">Kéo thả để sắp xếp. Nhấn ngôi sao để chọn ảnh đại diện.</p>
        <p class="text-[11px] text-gray-500">Ảnh được tự động chuyển sang WebP; máy chủ không lưu bản JPG/PNG gốc.</p>
        <p v-if="error" class="rounded bg-red-50 p-2 text-xs text-red-700">{{ error }}</p>

        <div class="grid grid-cols-2 gap-2">
            <div v-for="(item, index) in items" :key="item.key" draggable="true" class="group relative aspect-square overflow-hidden rounded-lg border bg-gray-50" :class="item.is_primary ? 'border-blue-500 ring-2 ring-blue-100' : 'border-gray-200'" @dragstart="dragStart(index)" @dragover.prevent @drop.prevent="dropAt(index)">
                <img :src="item.url" class="h-full w-full cursor-zoom-in object-cover" alt="Ảnh sản phẩm" @click="preview = item.url" />
                <div class="absolute inset-x-0 bottom-0 flex justify-between bg-black/55 p-1 opacity-0 transition group-hover:opacity-100">
                    <button type="button" class="rounded bg-white/90 px-1.5 py-1 text-xs" :title="item.is_primary ? 'Ảnh đại diện' : 'Chọn làm ảnh đại diện'" @click="selectPrimary(item, index)">{{ item.is_primary ? '★' : '☆' }}</button>
                    <button type="button" class="rounded bg-red-600 px-1.5 py-1 text-xs text-white" @click="remove(item, index)">Xóa</button>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="preview" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 p-6" @click.self="preview = null">
                <img :src="preview" class="max-h-full max-w-full rounded object-contain" alt="Xem trước ảnh sản phẩm" />
                <button type="button" class="absolute right-5 top-5 rounded bg-white px-3 py-2 text-sm" @click="preview = null">Đóng</button>
            </div>
        </Teleport>
    </div>
</template>
