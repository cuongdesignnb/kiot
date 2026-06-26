<script setup>
import { computed, ref, watch } from "vue";
import axios from "axios";
import { usePermission } from "@/composables/usePermission";

const props = defineProps({
    modelValue: { type: [String, Number, Array, Object], default: "" },
    collection: { type: String, default: "default" },
    folderId: { type: [Number, String, null], default: null },
    label: { type: String, default: "Chọn ảnh" },
    multiple: { type: Boolean, default: false },
    accept: { type: String, default: "image/*" },
    returnType: { type: String, default: "url" },
    previewSize: { type: String, default: "md" },
});

const emit = defineEmits(["update:modelValue", "selected", "uploaded"]);
const { can } = usePermission();

const isOpen = ref(false);
const mediaList = ref([]);
const folders = ref([]);
const loading = ref(false);
const uploading = ref(false);
const savingDetails = ref(false);
const search = ref("");
const currentFolderId = ref(props.folderId || "");
const selectedItems = ref([]);
const detailMedia = ref(null);
const detailForm = ref({ title: "", alt_text: "", caption: "" });

const previewClass = computed(() => ({
    sm: "w-16 h-16",
    md: "w-24 h-24",
    lg: "w-36 h-36",
}[props.previewSize] || "w-24 h-24"));

const modelAsArray = computed(() => Array.isArray(props.modelValue) ? props.modelValue : []);
const previewValue = computed(() => {
    if (props.multiple) return modelAsArray.value[0] || "";
    return typeof props.modelValue === "string" ? props.modelValue : "";
});

const returnValue = (media) => {
    if (props.returnType === "id") return media.id;
    if (props.returnType === "path") return media.path;
    if (props.returnType === "object") return media;
    return media.url;
};

const loadFolders = async () => {
    const res = await axios.get("/api/media-folders");
    folders.value = res.data || [];
};

const loadMedia = async () => {
    loading.value = true;
    try {
        const params = {
            collection: props.collection,
            search: search.value || undefined,
            folder_id: currentFolderId.value || undefined,
            type: "image",
            per_page: 60,
        };
        const res = await axios.get("/api/media", { params });
        mediaList.value = res.data?.data || [];
    } catch (e) {
        alert(e.response?.data?.message || "Không tải được thư viện ảnh");
    } finally {
        loading.value = false;
    }
};

const open = async () => {
    isOpen.value = true;
    selectedItems.value = [];
    await Promise.all([loadFolders(), loadMedia()]);
};

const close = () => {
    isOpen.value = false;
    detailMedia.value = null;
};

const isSelected = (media) => selectedItems.value.some((item) => item.id === media.id);

const chooseMedia = (media) => {
    detailMedia.value = media;
    detailForm.value = {
        title: media.title || "",
        alt_text: media.alt_text || "",
        caption: media.caption || "",
    };

    if (props.multiple) {
        selectedItems.value = isSelected(media)
            ? selectedItems.value.filter((item) => item.id !== media.id)
            : [...selectedItems.value, media];
        return;
    }

    selectedItems.value = [media];
};

const confirmSelection = () => {
    if (!selectedItems.value.length) return;
    const value = props.multiple
        ? selectedItems.value.map(returnValue)
        : returnValue(selectedItems.value[0]);
    emit("update:modelValue", value);
    emit("selected", props.multiple ? selectedItems.value : selectedItems.value[0]);
    close();
};

const uploadFile = async (event) => {
    const files = Array.from(event.target.files || []);
    if (!files.length) return;

    uploading.value = true;
    try {
        for (const file of files) {
            const fd = new FormData();
            fd.append("file", file);
            fd.append("collection", props.collection);
            if (currentFolderId.value) fd.append("folder_id", currentFolderId.value);
            const res = await axios.post("/api/media", fd, { headers: { "Content-Type": "multipart/form-data" } });
            mediaList.value.unshift(res.data);
            emit("uploaded", res.data);
            if (!props.multiple) {
                selectedItems.value = [res.data];
            }
        }
        if (!props.multiple && selectedItems.value.length) confirmSelection();
    } catch (e) {
        alert(e.response?.data?.message || "Upload thất bại");
    } finally {
        uploading.value = false;
        event.target.value = "";
    }
};

const createFolder = async () => {
    const name = prompt("Tên thư mục mới");
    if (!name?.trim()) return;

    try {
        await axios.post("/api/media-folders", {
            name: name.trim(),
            parent_id: currentFolderId.value || null,
        });
        await loadFolders();
    } catch (e) {
        alert(e.response?.data?.message || e.response?.data?.errors?.name?.[0] || "Không tạo được thư mục");
    }
};

const saveDetails = async () => {
    if (!detailMedia.value) return;
    savingDetails.value = true;
    try {
        const res = await axios.put(`/api/media/${detailMedia.value.id}`, detailForm.value);
        const idx = mediaList.value.findIndex((item) => item.id === res.data.id);
        if (idx >= 0) mediaList.value[idx] = res.data;
        detailMedia.value = res.data;
    } catch (e) {
        alert(e.response?.data?.message || "Không lưu được thông tin ảnh");
    } finally {
        savingDetails.value = false;
    }
};

const deleteMedia = async (media, event) => {
    event.stopPropagation();
    if (!confirm("Xóa ảnh này khỏi thư viện?")) return;
    try {
        await axios.delete(`/api/media/${media.id}`);
        mediaList.value = mediaList.value.filter((item) => item.id !== media.id);
        selectedItems.value = selectedItems.value.filter((item) => item.id !== media.id);
        if (detailMedia.value?.id === media.id) detailMedia.value = null;
        if (props.modelValue === media.url) emit("update:modelValue", "");
    } catch (e) {
        alert(e.response?.data?.message || "Không xóa được ảnh");
    }
};

const formatSize = (bytes) => {
    const value = Number(bytes || 0);
    if (value < 1024) return `${value} B`;
    if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
};

let searchTimeout;
watch(search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(loadMedia, 350);
});

watch(currentFolderId, loadMedia);
</script>

<template>
    <div>
        <button type="button" @click="open" class="group block text-left">
            <div v-if="previewValue" :class="['relative rounded overflow-hidden border border-gray-200 bg-gray-50', previewClass]">
                <img :src="previewValue" class="w-full h-full object-cover" />
                <div class="absolute inset-0 hidden group-hover:flex items-center justify-center bg-black/35 text-xs font-semibold text-white">
                    Đổi ảnh
                </div>
            </div>
            <div v-else :class="['rounded border-2 border-dashed border-gray-300 hover:border-blue-400 flex flex-col items-center justify-center text-gray-500 hover:text-blue-600 bg-gray-50 transition-colors', previewClass]">
                <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                <span class="px-2 text-center text-[11px] leading-tight">{{ label }}</span>
            </div>
        </button>
    </div>

    <Teleport to="body">
        <div v-if="isOpen" class="fixed inset-0 z-[70] bg-black/50" @click.self="close">
            <div class="absolute inset-6 flex overflow-hidden rounded bg-white shadow-2xl">
                <aside class="w-60 border-r bg-gray-50 flex flex-col">
                    <div class="px-4 py-3 border-b font-bold">Thư mục</div>
                    <div class="flex-1 overflow-auto p-2 space-y-1">
                        <button type="button" @click="currentFolderId = ''" :class="currentFolderId === '' ? 'bg-blue-50 text-blue-700' : 'hover:bg-white'" class="w-full text-left rounded px-3 py-2 text-sm">
                            Tất cả ảnh
                        </button>
                        <button v-for="folder in folders" :key="folder.id" type="button" @click="currentFolderId = folder.id" :class="Number(currentFolderId) === folder.id ? 'bg-blue-50 text-blue-700' : 'hover:bg-white'" class="w-full text-left rounded px-3 py-2 text-sm">
                            <span class="block truncate">{{ folder.name }}</span>
                            <span class="text-xs text-gray-400">{{ folder.media_count || 0 }} ảnh</span>
                        </button>
                    </div>
                    <button v-if="can('media.create_folder')" type="button" @click="createFolder" class="m-3 rounded border border-gray-300 bg-white px-3 py-2 text-sm font-semibold hover:bg-gray-100">
                        + Tạo thư mục
                    </button>
                </aside>

                <main class="flex min-w-0 flex-1 flex-col">
                    <header class="flex items-center gap-3 border-b px-5 py-3">
                        <h3 class="text-lg font-bold">Thư viện media</h3>
                        <input v-model="search" type="text" placeholder="Tìm ảnh..." class="ml-auto w-80 rounded border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500" />
                        <label v-if="can('media.upload')" class="inline-flex cursor-pointer items-center gap-2 rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                            {{ uploading ? "Đang tải..." : "Tải ảnh lên" }}
                            <input type="file" :accept="accept" :multiple="multiple" class="hidden" :disabled="uploading" @change="uploadFile" />
                        </label>
                        <button type="button" @click="close" class="rounded px-3 py-2 text-gray-500 hover:bg-gray-100">Đóng</button>
                    </header>

                    <div class="flex min-h-0 flex-1">
                        <section class="min-w-0 flex-1 overflow-auto p-5">
                            <div v-if="loading" class="py-16 text-center text-gray-400">Đang tải ảnh...</div>
                            <div v-else-if="mediaList.length === 0" class="py-16 text-center text-gray-400">Chưa có ảnh trong thư mục này.</div>
                            <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-4 md:grid-cols-5 xl:grid-cols-7">
                                <div v-for="media in mediaList" :key="media.id" @click="chooseMedia(media)" :class="isSelected(media) ? 'border-blue-500 ring-2 ring-blue-100' : 'border-gray-200 hover:border-blue-300'" class="group relative aspect-square cursor-pointer overflow-hidden rounded border bg-gray-50">
                                    <img :src="media.url" :alt="media.alt_text || media.original_name" class="h-full w-full object-cover" />
                                    <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-2 opacity-0 transition group-hover:opacity-100">
                                        <div class="truncate text-[11px] font-semibold text-white">{{ media.title || media.original_name }}</div>
                                        <div class="text-[10px] text-gray-200">{{ formatSize(media.size) }}</div>
                                    </div>
                                    <button v-if="can('media.delete')" type="button" @click="deleteMedia(media, $event)" class="absolute right-1 top-1 hidden h-6 w-6 items-center justify-center rounded-full bg-red-600 text-white group-hover:flex">×</button>
                                    <div v-if="isSelected(media)" class="absolute left-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-blue-600 text-white">
                                        ✓
                                    </div>
                                </div>
                            </div>
                        </section>

                        <aside class="w-80 border-l bg-gray-50 p-4" v-if="detailMedia">
                            <img :src="detailMedia.url" class="mb-3 aspect-square w-full rounded border object-cover" />
                            <div class="space-y-3 text-sm">
                                <div>
                                    <label class="mb-1 block font-semibold">Tiêu đề</label>
                                    <input v-model="detailForm.title" class="w-full rounded border border-gray-300 px-3 py-2" />
                                </div>
                                <div>
                                    <label class="mb-1 block font-semibold">Alt text</label>
                                    <input v-model="detailForm.alt_text" class="w-full rounded border border-gray-300 px-3 py-2" />
                                </div>
                                <div>
                                    <label class="mb-1 block font-semibold">Chú thích</label>
                                    <textarea v-model="detailForm.caption" rows="3" class="w-full rounded border border-gray-300 px-3 py-2"></textarea>
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ detailMedia.width || "-" }} × {{ detailMedia.height || "-" }} px · {{ formatSize(detailMedia.size) }}
                                </div>
                                <button v-if="can('media.edit')" type="button" @click="saveDetails" :disabled="savingDetails" class="w-full rounded bg-gray-800 px-3 py-2 font-semibold text-white disabled:opacity-50">
                                    Lưu thông tin
                                </button>
                            </div>
                        </aside>
                    </div>

                    <footer class="flex items-center justify-between border-t px-5 py-3">
                        <div class="text-sm text-gray-500">
                            Đã chọn {{ selectedItems.length }} ảnh
                        </div>
                        <button type="button" @click="confirmSelection" :disabled="selectedItems.length === 0" class="rounded bg-blue-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-40">
                            Chọn ảnh
                        </button>
                    </footer>
                </main>
            </div>
        </div>
    </Teleport>
</template>
