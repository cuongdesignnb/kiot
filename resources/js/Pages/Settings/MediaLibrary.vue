<script setup>
import axios from 'axios';
import { onMounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ canDelete: Boolean });
const items = ref([]);
const search = ref('');
const usage = ref('');
const page = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const uploading = ref(false);
const error = ref('');
const preview = ref(null);

const load = async (targetPage = 1) => {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await axios.get('/api/media', { params: { search: search.value.trim() || undefined, usage: usage.value || undefined, page: targetPage, per_page: 60 } });
        items.value = data.data || [];
        page.value = data.current_page || targetPage;
        lastPage.value = data.last_page || 1;
    } catch (exception) {
        error.value = exception.response?.data?.message || 'Không thể tải thư viện ảnh.';
    } finally { loading.value = false; }
};

let timer;
watch([search, usage], () => { clearTimeout(timer); timer = setTimeout(() => load(1), 250); });
onMounted(() => load());

const upload = async (event) => {
    const files = Array.from(event.target.files || []);
    event.target.value = '';
    if (!files.length) return;
    uploading.value = true;
    error.value = '';
    try {
        const body = new FormData();
        files.forEach((file) => body.append('files[]', file));
        body.append('collection', 'library');
        await axios.post('/api/media', body, { headers: { 'Content-Type': 'multipart/form-data' } });
        await load(1);
    } catch (exception) {
        const validation = Object.values(exception.response?.data?.errors || {}).flat();
        error.value = validation[0] || exception.response?.data?.message || 'Không thể tải ảnh lên.';
    } finally { uploading.value = false; }
};

const remove = async (item) => {
    error.value = '';
    try {
        await axios.delete(`/api/media/${item.id}`);
        items.value = items.value.filter((candidate) => candidate.id !== item.id);
    } catch (exception) {
        const usages = exception.response?.data?.usages || [];
        error.value = usages.length
            ? `${exception.response?.data?.message || 'Ảnh đang được sử dụng.'} Nơi dùng: ${usages.map((usage) => usage.label).join(', ')}`
            : exception.response?.data?.message || 'Không thể xóa ảnh.';
    }
};

const formatSize = (bytes) => {
    const value = Number(bytes || 0);
    if (value < 1024) return `${value} B`;
    if (value < 1024 * 1024) return `${(value / 1024).toFixed(0)} KB`;
    return `${(value / 1024 / 1024).toFixed(1)} MB`;
};
</script>

<template>
    <Head title="Thư viện ảnh" />
    <AppLayout>
        <div class="min-h-screen bg-gray-50 p-6">
            <div class="mx-auto max-w-7xl space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Thư viện ảnh</h1>
                        <p class="mt-1 text-sm text-gray-500">Một ảnh dùng được cho nhiều sản phẩm, biến thể, khách hàng và nhân viên.</p>
                    </div>
                    <label class="cursor-pointer rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700" :class="uploading ? 'pointer-events-none opacity-50' : ''">
                        {{ uploading ? 'Đang xử lý...' : 'Tải ảnh mới' }}
                        <input type="file" multiple class="hidden" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" @change="upload" />
                    </label>
                </div>
                <input v-model="search" type="search" placeholder="Tìm theo tên ảnh..." class="w-full max-w-md rounded border bg-white px-3 py-2 text-sm" />
                <select v-model="usage" class="rounded border bg-white px-3 py-2 text-sm" aria-label="Lọc nguồn sử dụng">
                    <option value="">Tất cả nguồn</option>
                    <option value="used">Đang được sử dụng</option>
                    <option value="unused">Chưa sử dụng</option>
                    <option value="product">Sản phẩm</option>
                    <option value="variant">Biến thể</option>
                    <option value="customer">Khách hàng/NCC</option>
                    <option value="employee">Nhân viên</option>
                </select>
                <p v-if="error" class="rounded bg-red-50 p-3 text-sm text-red-700" role="alert">{{ error }}</p>
                <p v-if="loading" class="py-12 text-center text-sm text-gray-500">Đang tải thư viện...</p>
                <div v-else-if="!items.length" class="rounded-lg border border-dashed bg-white py-16 text-center text-sm text-gray-500">Chưa có ảnh.</div>
                <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-6">
                    <div v-for="item in items" :key="item.id" class="overflow-hidden rounded-lg border bg-white shadow-sm">
                        <img :src="item.variants?.small?.url || item.url" :alt="item.original_name" class="aspect-square w-full cursor-zoom-in object-cover" @click="preview = item.variants?.medium?.url || item.url" />
                        <div class="space-y-1 p-2">
                            <p class="truncate text-xs font-semibold text-gray-700" :title="item.original_name">{{ item.original_name }}</p>
                            <p class="text-[11px] text-gray-500">{{ item.width }}×{{ item.height }} · {{ formatSize(item.size) }}</p>
                            <p class="text-[11px] text-gray-500">{{ item.usage_count || 0 }} nơi sử dụng</p>
                            <button v-if="canDelete" type="button" class="w-full rounded border border-red-200 px-2 py-1 text-xs text-red-700 hover:bg-red-50" @click="remove(item)">Xóa khỏi thư viện</button>
                        </div>
                    </div>
                </div>
                <div v-if="lastPage > 1" class="flex items-center justify-center gap-3 text-sm">
                    <button type="button" class="rounded border bg-white px-3 py-1 disabled:opacity-40" :disabled="page <= 1" @click="load(page - 1)">Trước</button>
                    <span>{{ page }} / {{ lastPage }}</span>
                    <button type="button" class="rounded border bg-white px-3 py-1 disabled:opacity-40" :disabled="page >= lastPage" @click="load(page + 1)">Sau</button>
                </div>
                <div v-if="preview" class="fixed inset-0 z-[130] flex items-center justify-center bg-black/80 p-6" @click.self="preview = null">
                    <img :src="preview" class="max-h-full max-w-full object-contain" alt="Xem trước ảnh" />
                    <button type="button" class="absolute right-5 top-5 rounded bg-white px-3 py-2 text-sm" @click="preview = null">Đóng</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
