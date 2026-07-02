<script setup>
import { ref, watch } from "vue";
import axios from "axios";

const props = defineProps({
    modelValue: { type: String, default: "" },
    collection: { type: String, default: "default" },
    label: { type: String, default: "Chọn ảnh" },
});
const emit = defineEmits(["update:modelValue"]);

const isOpen = ref(false);
const mediaList = ref([]);
const loading = ref(false);
const uploading = ref(false);
const search = ref("");

const loadMedia = async () => {
    loading.value = true;
    try {
        const res = await axios.get("/api/media", { params: { collection: props.collection, search: search.value, per_page: 40 } });
        mediaList.value = res.data?.data || [];
    } catch (e) { console.error(e); }
    loading.value = false;
};

const open = () => {
    isOpen.value = true;
    loadMedia();
};

const selectImage = (media) => {
    emit("update:modelValue", media.url);
    isOpen.value = false;
};

const uploadFile = async (event) => {
    const file = event.target.files[0];
    if (!file) return;
    uploading.value = true;
    try {
        const fd = new FormData();
        fd.append("file", file);
        fd.append("collection", props.collection);
        const res = await axios.post("/api/media", fd, { headers: { "Content-Type": "multipart/form-data" } });
        if (res.data) {
            mediaList.value.unshift(res.data);
            selectImage(res.data);
        }
    } catch (e) {
        alert(e.response?.data?.message || "Upload thất bại");
    }
    uploading.value = false;
    event.target.value = "";
};

const deleteMedia = async (media, event) => {
    event.stopPropagation();
    if (!confirm("Xóa ảnh này?")) return;
    try {
        await axios.delete(`/api/media/${media.id}`);
        mediaList.value = mediaList.value.filter(m => m.id !== media.id);
        if (props.modelValue === media.url) emit("update:modelValue", "");
    } catch (e) { alert("Lỗi khi xóa"); }
};

let searchTimeout;
watch(search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(loadMedia, 400);
});

const formatSize = (bytes) => {
    if (bytes < 1024) return bytes + " B";
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + " KB";
    return (bytes / (1024 * 1024)).toFixed(1) + " MB";
};
</script>

<template>
    <!-- Trigger: thumbnail or placeholder -->
    <div>
        <div @click="open" class="cursor-pointer group">
            <div v-if="modelValue" class="relative w-24 h-24 rounded-lg overflow-hidden border-2 border-gray-200 hover:border-blue-400 transition-colors">
                <img :src="modelValue" class="w-full h-full object-cover" />
                <div class="absolute inset-0 bg-black/30 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                </div>
            </div>
            <div v-else class="w-24 h-24 rounded-lg border-2 border-dashed border-gray-300 hover:border-blue-400 flex flex-col items-center justify-center text-gray-400 hover:text-blue-500 transition-colors">
                <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                <span class="text-[10px]">{{ label }}</span>
            </div>
        </div>
    </div>

    <!-- Modal Overlay -->
    <Teleport to="body">
        <div v-if="isOpen" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50" @click.self="isOpen = false">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl max-h-[80vh] flex flex-col">
                <!-- Header -->
                <div class="flex items-center justify-between px-5 py-3 border-b">
                    <h3 class="font-bold text-lg">Thư viện ảnh</h3>
                    <button @click="isOpen = false" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>

                <!-- Toolbar -->
                <div class="flex items-center gap-3 px-5 py-3 border-b bg-gray-50">
                    <input v-model="search" type="text" placeholder="Tìm ảnh..." class="flex-1 border rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-500" />
                    <label class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium cursor-pointer hover:bg-blue-700">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                        {{ uploading ? "Đang tải..." : "Tải ảnh lên" }}
                        <input type="file" accept="image/*" class="hidden" @change="uploadFile" :disabled="uploading" />
                    </label>
                </div>

                <!-- Grid -->
                <div class="flex-1 overflow-auto p-5">
                    <div v-if="loading" class="text-center py-10 text-gray-400">Đang tải...</div>
                    <div v-else-if="mediaList.length === 0" class="text-center py-10 text-gray-400">
                        <svg class="w-12 h-12 mx-auto mb-2 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        <p>Chưa có ảnh nào. Hãy tải ảnh lên!</p>
                    </div>
                    <div v-else class="grid grid-cols-5 gap-3">
                        <div v-for="m in mediaList" :key="m.id"
                            @click="selectImage(m)"
                            class="relative group cursor-pointer rounded-lg overflow-hidden border-2 transition-all aspect-square"
                            :class="modelValue === m.url ? 'border-blue-500 ring-2 ring-blue-200' : 'border-gray-200 hover:border-blue-300'"
                        >
                            <img :src="m.url" :alt="m.original_name" class="w-full h-full object-cover" />
                            <div class="absolute inset-0 bg-black/20 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                            <div class="absolute bottom-0 inset-x-0 bg-gradient-to-t from-black/60 to-transparent px-2 py-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
                                <p class="text-white text-[10px] truncate">{{ m.original_name }}</p>
                                <p class="text-gray-300 text-[9px]">{{ formatSize(m.size) }}</p>
                            </div>
                            <!-- Delete button -->
                            <button @click="deleteMedia(m, $event)" class="absolute top-1 right-1 w-5 h-5 bg-red-500 text-white rounded-full opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center hover:bg-red-600" title="Xóa">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                            <!-- Selected check -->
                            <div v-if="modelValue === m.url" class="absolute top-1 left-1 w-5 h-5 bg-blue-500 text-white rounded-full flex items-center justify-center">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
