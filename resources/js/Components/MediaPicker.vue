<script setup>
import axios from 'axios';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    primaryMediaId: { type: [Number, String], default: null },
    multiple: { type: Boolean, default: false },
    collection: { type: String, default: 'default' },
    label: { type: String, default: 'Chọn ảnh' },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'update:primaryMediaId', 'select']);

const open = ref(false);
const tab = ref('library');
const search = ref('');
const usage = ref('');
const page = ref(1);
const lastPage = ref(1);
const items = ref([]);
const loading = ref(false);
const uploading = ref(false);
const error = ref('');
const preview = ref(null);

const selectedIds = computed(() => (props.modelValue || []).map((id) => Number(id)).filter(Boolean));
const selectedSet = computed(() => new Set(selectedIds.value));
const selectedItems = computed(() => items.value.filter((item) => selectedSet.value.has(Number(item.id))));

const load = async (targetPage = 1) => {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await axios.get('/api/media', {
            params: { search: search.value.trim() || undefined, usage: usage.value || undefined, page: targetPage, per_page: 30 },
        });
        items.value = data.data || [];
        page.value = data.current_page || targetPage;
        lastPage.value = data.last_page || 1;
        const missingIds = selectedIds.value.filter((id) => !items.value.some((item) => Number(item.id) === id));
        if (missingIds.length) {
            const selected = await Promise.all(missingIds.map(async (id) => {
                try {
                    const { data: item } = await axios.get(`/api/media/${id}`);
                    return item;
                } catch {
                    return null;
                }
            }));
            items.value = [...selected.filter(Boolean), ...items.value];
        }
    } catch (exception) {
        error.value = exception.response?.data?.message || 'Không thể tải thư viện ảnh.';
    } finally {
        loading.value = false;
    }
};

let searchTimer;
watch([search, usage], () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => load(1), 250);
});
watch(open, (value) => { if (value) load(1); });

const toggle = (media) => {
    const id = Number(media.id);
    if (props.multiple) {
        const next = selectedIds.value.includes(id)
            ? selectedIds.value.filter((item) => item !== id)
            : [...selectedIds.value, id];
        emit('update:modelValue', next);
        if (!next.includes(Number(props.primaryMediaId))) {
            emit('update:primaryMediaId', next[0] || null);
        }
        return;
    }

    emit('select', media);
    emit('update:modelValue', [id]);
    emit('update:primaryMediaId', id);
    open.value = false;
};

const setPrimary = (id) => {
    if (selectedSet.value.has(Number(id))) emit('update:primaryMediaId', Number(id));
};

const upload = async (event) => {
    const files = Array.from(event.target.files || []);
    event.target.value = '';
    if (!files.length) return;
    if (!props.multiple && files.length > 1) {
        error.value = 'Trường này chỉ được chọn một ảnh.';
        return;
    }

    uploading.value = true;
    error.value = '';
    try {
        const body = new FormData();
        files.forEach((file) => body.append('files[]', file));
        body.append('collection', props.collection);
        const { data } = await axios.post('/api/media', body, { headers: { 'Content-Type': 'multipart/form-data' } });
        const uploaded = data.media || [];
        const ids = uploaded.map((item) => Number(item.id));
        const next = props.multiple ? [...new Set([...selectedIds.value, ...ids])] : ids.slice(0, 1);
        emit('update:modelValue', next);
        emit('update:primaryMediaId', props.primaryMediaId || next[0] || null);
        uploaded.forEach((item) => emit('select', item));
        items.value = [...uploaded, ...items.value.filter((item) => !ids.includes(Number(item.id)))];
        tab.value = 'library';
    } catch (exception) {
        const validation = Object.values(exception.response?.data?.errors || {}).flat();
        error.value = validation[0] || exception.response?.data?.message || 'Không thể tải ảnh lên.';
    } finally {
        uploading.value = false;
    }
};

const removeSelected = (id) => {
    const next = selectedIds.value.filter((item) => item !== Number(id));
    emit('update:modelValue', next);
    if (Number(props.primaryMediaId) === Number(id)) emit('update:primaryMediaId', next[0] || null);
};

const formatSize = (bytes) => {
    const value = Number(bytes || 0);
    if (value < 1024) return `${value} B`;
    if (value < 1024 * 1024) return `${(value / 1024).toFixed(0)} KB`;
    return `${(value / 1024 / 1024).toFixed(1)} MB`;
};
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-center justify-between gap-2">
            <span class="text-sm font-semibold text-gray-700">{{ label }}</span>
            <button type="button" class="rounded border border-blue-300 px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50 disabled:opacity-50" :disabled="disabled" @click="open = true">
                {{ selectedIds.length ? 'Đổi ảnh' : label }}
            </button>
        </div>

        <div v-if="selectedItems.length" class="grid grid-cols-4 gap-2">
            <div v-for="item in selectedItems" :key="item.id" class="group relative aspect-square overflow-hidden rounded border" :class="Number(props.primaryMediaId) === Number(item.id) ? 'border-blue-500 ring-2 ring-blue-100' : 'border-gray-200'">
                <img :src="item.variants?.small?.url || item.url" :alt="item.original_name" class="h-full w-full cursor-zoom-in object-cover" @click="preview = item.variants?.medium?.url || item.url" />
                <div class="absolute inset-x-0 bottom-0 flex items-center justify-between bg-black/55 p-1 opacity-0 transition group-hover:opacity-100">
                    <button v-if="multiple" type="button" class="text-xs text-white" @click="setPrimary(item.id)">{{ Number(props.primaryMediaId) === Number(item.id) ? '★' : '☆' }}</button>
                    <button type="button" class="ml-auto rounded bg-white px-1 text-[10px] text-red-700" @click="removeSelected(item.id)">Gỡ</button>
                </div>
            </div>
        </div>
        <p v-else class="rounded border border-dashed border-gray-300 px-3 py-4 text-center text-xs text-gray-500">Chưa chọn ảnh</p>

        <Teleport to="body">
            <div v-if="open" class="fixed inset-0 z-[120] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label="Thư viện ảnh" @click.self="open = false">
                <div class="flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl">
                    <div class="flex items-center justify-between border-b px-5 py-4">
                        <div>
                            <h2 class="text-lg font-bold text-gray-800">Thư viện ảnh</h2>
                            <p class="text-xs text-gray-500">Ảnh được dùng chung toàn hệ thống, tải lên sẽ tự chuyển sang WebP.</p>
                        </div>
                        <button type="button" class="text-2xl text-gray-400 hover:text-gray-700" aria-label="Đóng" @click="open = false">×</button>
                    </div>
                    <div class="flex items-center gap-2 border-b px-5 py-3">
                        <button type="button" class="rounded px-3 py-1.5 text-sm" :class="tab === 'library' ? 'bg-blue-50 font-semibold text-blue-700' : 'text-gray-600'" @click="tab = 'library'">Thư viện</button>
                        <button type="button" class="rounded px-3 py-1.5 text-sm" :class="tab === 'upload' ? 'bg-blue-50 font-semibold text-blue-700' : 'text-gray-600'" @click="tab = 'upload'">Tải ảnh mới</button>
                        <input v-if="tab === 'library'" v-model="search" type="search" placeholder="Tìm theo tên ảnh..." class="ml-auto w-64 rounded border px-3 py-1.5 text-sm" />
                        <select v-if="tab === 'library'" v-model="usage" class="rounded border px-2 py-1.5 text-sm" aria-label="Lọc nguồn sử dụng">
                            <option value="">Tất cả nguồn</option>
                            <option value="used">Đang được sử dụng</option>
                            <option value="unused">Chưa sử dụng</option>
                            <option value="product">Sản phẩm</option>
                            <option value="variant">Biến thể</option>
                            <option value="customer">Khách hàng/NCC</option>
                            <option value="employee">Nhân viên</option>
                        </select>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto p-5">
                        <div v-if="tab === 'upload'" class="rounded-lg border-2 border-dashed border-blue-200 bg-blue-50 p-10 text-center">
                            <p class="mb-3 text-sm text-gray-700">JPG, PNG hoặc WebP · tối đa 5 MB · tối đa 40 triệu điểm ảnh</p>
                            <label class="inline-flex cursor-pointer rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700" :class="uploading ? 'pointer-events-none opacity-50' : ''">
                                {{ uploading ? 'Đang xử lý...' : 'Chọn ảnh từ máy' }}
                                <input type="file" class="hidden" :multiple="multiple" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" @change="upload" />
                            </label>
                        </div>
                        <div v-else>
                            <p v-if="loading" class="py-12 text-center text-sm text-gray-500">Đang tải thư viện...</p>
                            <p v-else-if="!items.length" class="py-12 text-center text-sm text-gray-500">Chưa có ảnh phù hợp.</p>
                            <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                                <button v-for="item in items" :key="item.id" type="button" class="group relative overflow-hidden rounded-lg border text-left" :class="selectedSet.has(Number(item.id)) ? 'border-blue-600 ring-2 ring-blue-200' : 'border-gray-200 hover:border-blue-400'" @click="toggle(item)">
                                    <img :src="item.variants?.small?.url || item.url" :alt="item.original_name" class="aspect-square w-full cursor-zoom-in object-cover" @click.stop="preview = item.variants?.medium?.url || item.url" />
                                    <span v-if="selectedSet.has(Number(item.id))" class="absolute right-1 top-1 rounded-full bg-blue-600 px-1.5 py-0.5 text-xs font-bold text-white">✓</span>
                                    <span class="block truncate px-2 pt-1 text-xs font-medium text-gray-700">{{ item.original_name }}</span>
                                    <span class="block px-2 pb-2 text-[10px] text-gray-500">{{ item.width }}×{{ item.height }} · {{ formatSize(item.size) }} · {{ item.usage_count || 0 }} nơi dùng</span>
                                    <span class="absolute inset-0 hidden items-center justify-center bg-black/35 text-xs font-semibold text-white group-hover:flex">Xem/chọn</span>
                                </button>
                            </div>
                            <div v-if="lastPage > 1" class="mt-4 flex items-center justify-center gap-3 text-sm">
                                <button type="button" class="rounded border px-3 py-1 disabled:opacity-40" :disabled="page <= 1" @click="load(page - 1)">Trước</button>
                                <span>{{ page }} / {{ lastPage }}</span>
                                <button type="button" class="rounded border px-3 py-1 disabled:opacity-40" :disabled="page >= lastPage" @click="load(page + 1)">Sau</button>
                            </div>
                        </div>
                        <p v-if="error" class="mt-4 rounded bg-red-50 p-3 text-sm text-red-700" role="alert">{{ error }}</p>
                    </div>
                    <div class="flex items-center justify-between border-t bg-gray-50 px-5 py-3">
                        <span class="text-sm text-gray-600">Đã chọn {{ selectedIds.length }} ảnh</span>
                        <button type="button" class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700" @click="open = false">Xong</button>
                    </div>
                </div>
            </div>
            <div v-if="preview" class="fixed inset-0 z-[130] flex items-center justify-center bg-black/80 p-6" @click.self="preview = null">
                <img :src="preview" class="max-h-full max-w-full object-contain" alt="Xem trước ảnh" />
                <button type="button" class="absolute right-5 top-5 rounded bg-white px-3 py-2 text-sm" @click="preview = null">Đóng</button>
            </div>
        </Teleport>
    </div>
</template>
