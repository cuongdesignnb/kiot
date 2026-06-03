<template>
    <div class="flex gap-1.5">
        <button
            v-if="exportUrl"
            @click="handleExport"
            class="bg-white text-gray-600 border border-gray-300 px-3 py-1.5 text-sm font-medium rounded hover:bg-gray-50 transition flex items-center gap-1"
            title="Xuất Excel/CSV"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
            </svg>
            <span>Xuất file</span>
        </button>

        <template v-if="importUrl">
            <button
                @click="handleImportClick"
                class="bg-white text-gray-600 border border-gray-300 px-3 py-1.5 text-sm font-medium rounded hover:bg-gray-50 transition flex items-center gap-1"
                title="Nhập từ Excel/CSV"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                </svg>
                <span>Nhập file</span>
            </button>
            <input
                type="file"
                ref="importFile"
                accept=".csv,.txt,.xlsx,.xls"
                class="hidden"
                @change="handleLegacyImport"
            />
        </template>
    </div>

    <Teleport to="body">
        <div v-if="showExportModal" class="fixed inset-0 z-[120] flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-3xl rounded bg-white shadow-xl">
                <div class="flex items-center justify-between border-b px-5 py-4">
                    <h2 class="text-base font-bold text-gray-800">Chọn thông tin xuất file</h2>
                    <button class="text-xl text-gray-400 hover:text-gray-700" @click="showExportModal = false">&times;</button>
                </div>
                <div class="max-h-[70vh] overflow-y-auto px-5 py-4">
                    <div v-for="(label, groupKey) in productExcel.groups" :key="groupKey" class="mb-5">
                        <div class="mb-2 text-sm font-bold text-gray-700">{{ label }}</div>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            <label
                                v-for="field in fieldsByGroup(groupKey).filter((f) => f.exportable)"
                                :key="field.key"
                                class="flex min-h-10 items-start gap-2 rounded border border-gray-200 bg-white px-3 py-2 text-sm hover:bg-gray-50"
                            >
                                <input
                                    type="checkbox"
                                    class="mt-0.5"
                                    :value="field.key"
                                    v-model="selectedExportFields"
                                />
                                <span>
                                    <span class="block font-medium text-gray-700">{{ field.label }}</span>
                                    <span v-if="field.note" class="block text-xs text-gray-500">{{ field.note }}</span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t bg-gray-50 px-5 py-3">
                    <button class="rounded border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50" @click="showExportModal = false">
                        Bỏ qua
                    </button>
                    <button class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50" :disabled="selectedExportFields.length === 0" @click="downloadProductExport">
                        Xuất file
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <Teleport to="body">
        <div v-if="showImportModal" class="fixed inset-0 z-[120] flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-5xl rounded bg-white shadow-xl">
                <div class="flex items-center justify-between border-b px-5 py-4">
                    <h2 class="text-base font-bold text-gray-800">Nhập hàng hóa từ file dữ liệu</h2>
                    <button class="text-xl text-gray-400 hover:text-gray-700" @click="closeImportModal">&times;</button>
                </div>

                <div class="max-h-[76vh] overflow-y-auto px-5 py-4">
                    <a :href="importTemplateUrl" class="mb-4 inline-flex text-sm font-semibold text-blue-600 hover:underline">
                        Tải về file mẫu: Excel file
                    </a>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="space-y-4">
                            <div>
                                <div class="mb-2 text-sm font-bold text-gray-700">Xử lý trùng mã hàng/mã vạch, khác tên hàng hóa</div>
                                <label class="mb-1 flex gap-2 text-sm"><input type="radio" value="error" v-model="importOptions.duplicate_name_strategy" /> Báo lỗi và dừng import</label>
                                <label class="flex gap-2 text-sm"><input type="radio" value="replace_name" v-model="importOptions.duplicate_name_strategy" /> Thay thế tên hàng cũ bằng tên hàng mới</label>
                            </div>

                            <div>
                                <div class="mb-2 text-sm font-bold text-gray-700">Xử lý trùng mã vạch, khác mã hàng</div>
                                <label class="mb-1 flex gap-2 text-sm"><input type="radio" value="error" v-model="importOptions.duplicate_barcode_sku_strategy" /> Báo lỗi và dừng import</label>
                                <label class="flex gap-2 text-sm"><input type="radio" value="replace_sku" v-model="importOptions.duplicate_barcode_sku_strategy" /> Thay thế mã hàng cũ bằng mã hàng mới</label>
                            </div>

                            <div>
                                <div class="mb-2 text-sm font-bold text-gray-700">Cập nhật tồn kho?</div>
                                <label class="mb-1 flex gap-2 text-sm"><input type="radio" :value="false" v-model="importOptions.update_stock" /> Không</label>
                                <label class="flex gap-2 text-sm"><input type="radio" :value="true" v-model="importOptions.update_stock" /> Có</label>
                                <div v-if="importOptions.update_stock" class="mt-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    Phase hiện tại không cập nhật tồn kho hàng cũ qua import hàng hóa. Vui lòng dùng phiếu nhập hoặc kiểm kho nếu cần điều chỉnh tồn.
                                </div>
                            </div>

                            <div>
                                <div class="mb-2 text-sm font-bold text-gray-700">Cập nhật giá vốn?</div>
                                <label class="mb-1 flex gap-2 text-sm"><input type="radio" :value="false" v-model="importOptions.update_cost_price" /> Không</label>
                                <label class="flex gap-2 text-sm"><input type="radio" :value="true" v-model="importOptions.update_cost_price" /> Có</label>
                                <div v-if="importOptions.update_cost_price" class="mt-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    Phase hiện tại không cập nhật giá vốn hàng cũ qua import hàng hóa.
                                </div>
                            </div>

                            <div>
                                <div class="mb-2 text-sm font-bold text-gray-700">Cập nhật mô tả?</div>
                                <label class="mb-1 flex gap-2 text-sm"><input type="radio" :value="false" v-model="importOptions.update_description" /> Không</label>
                                <label class="flex gap-2 text-sm"><input type="radio" :value="true" v-model="importOptions.update_description" /> Có</label>
                            </div>

                            <div class="flex items-center gap-3">
                                <button
                                    type="button"
                                    class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
                                    @click="openProductFilePicker"
                                >
                                    Chọn file dữ liệu
                                </button>
                                <div class="min-w-0">
                                    <span
                                        class="block truncate text-sm"
                                        :class="selectedImportFile ? 'font-semibold text-green-700' : 'text-gray-500'"
                                    >
                                        {{ selectedImportFile?.name || 'Chưa chọn file' }}
                                    </span>
                                    <span v-if="selectedImportFile" class="text-xs text-gray-500">
                                        {{ Math.ceil(selectedImportFile.size / 1024) }} KB
                                    </span>
                                </div>
                                <input
                                    ref="productImportFile"
                                    type="file"
                                    accept=".csv,.txt,.xlsx,.xls"
                                    class="hidden"
                                    @change="handleProductFile"
                                />
                            </div>
                        </div>

                        <div class="rounded border border-gray-200 bg-gray-50 p-4">
                            <div class="mb-3 grid grid-cols-2 gap-2 text-sm">
                                <div class="rounded bg-white p-2"><span class="text-gray-500">Tổng dòng</span><div class="font-bold">{{ importPreview?.total_rows ?? 0 }}</div></div>
                                <div class="rounded bg-white p-2"><span class="text-gray-500">Hợp lệ</span><div class="font-bold text-green-700">{{ importPreview?.valid_rows ?? 0 }}</div></div>
                                <div class="rounded bg-white p-2"><span class="text-gray-500">Cảnh báo</span><div class="font-bold text-amber-700">{{ importPreview?.warning_rows ?? 0 }}</div></div>
                                <div class="rounded bg-white p-2"><span class="text-gray-500">Lỗi</span><div class="font-bold text-red-700">{{ importPreview?.error_rows ?? 0 }}</div></div>
                                <div class="rounded bg-white p-2"><span class="text-gray-500">Sẽ tạo mới</span><div class="font-bold">{{ importPreview?.will_create ?? 0 }}</div></div>
                                <div class="rounded bg-white p-2"><span class="text-gray-500">Sẽ cập nhật</span><div class="font-bold">{{ importPreview?.will_update ?? 0 }}</div></div>
                            </div>

                            <div v-if="importError" class="mb-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ importError }}</div>

                            <div v-if="importPreview?.rows?.length" class="max-h-72 overflow-auto rounded border border-gray-200 bg-white">
                                <table class="min-w-full text-left text-xs">
                                    <thead class="sticky top-0 bg-gray-100 text-gray-600">
                                        <tr>
                                            <th class="px-2 py-2">Dòng</th>
                                            <th class="px-2 py-2">Hành động</th>
                                            <th class="px-2 py-2">Mã hàng</th>
                                            <th class="px-2 py-2">Mã vạch</th>
                                            <th class="px-2 py-2">Tên hàng cũ</th>
                                            <th class="px-2 py-2">Tên hàng mới</th>
                                            <th class="px-2 py-2">Giá bán</th>
                                            <th class="px-2 py-2">Tồn kho</th>
                                            <th class="px-2 py-2">Giá vốn</th>
                                            <th class="px-2 py-2">Cảnh báo</th>
                                            <th class="px-2 py-2">Lỗi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="row in importPreview.rows" :key="row.row" class="border-t">
                                            <td class="px-2 py-2">{{ row.row }}</td>
                                            <td class="px-2 py-2">{{ row.action }}</td>
                                            <td class="px-2 py-2">{{ row.data.sku }}</td>
                                            <td class="px-2 py-2">{{ row.data.barcode }}</td>
                                            <td class="px-2 py-2">{{ row.existing_product?.name || '' }}</td>
                                            <td class="px-2 py-2">{{ row.data.name }}</td>
                                            <td class="px-2 py-2 text-right">{{ row.data.retail_price }}</td>
                                            <td class="px-2 py-2 text-right">{{ row.data.stock_quantity }}</td>
                                            <td class="px-2 py-2 text-right">{{ row.data.cost_price }}</td>
                                            <td class="px-2 py-2 text-amber-700">{{ row.warnings.join('; ') }}</td>
                                            <td class="px-2 py-2 text-red-700">{{ row.errors.join('; ') }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t bg-gray-50 px-5 py-3">
                    <button type="button" class="rounded border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50" @click="closeImportModal">
                        Bỏ qua
                    </button>
                    <button type="button" class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50" :disabled="!selectedImportFile || importLoading" @click="previewImport">
                        {{ importLoading ? 'Đang kiểm tra...' : 'Kiểm tra file' }}
                    </button>
                    <button
                        v-if="importPreview && importPreview.error_rows === 0"
                        class="rounded bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-50"
                        :disabled="importLoading"
                        @click="commitImport"
                    >
                        Xác nhận nhập
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import axios from "axios";

const props = defineProps({
    exportUrl: { type: String, default: "" },
    importUrl: { type: String, default: "" },
    productExcel: { type: Object, default: null },
    importTemplateUrl: { type: String, default: "" },
    importPreviewUrl: { type: String, default: "" },
    importCommitUrl: { type: String, default: "" },
    exportParams: { type: Object, default: () => ({}) },
});

const importFile = ref(null);
const productImportFile = ref(null);
const showExportModal = ref(false);
const showImportModal = ref(false);
const selectedExportFields = ref([]);
const selectedImportFile = ref(null);
const importPreview = ref(null);
const importLoading = ref(false);
const importError = ref("");

const importOptions = ref({
    duplicate_name_strategy: "error",
    duplicate_barcode_sku_strategy: "error",
    update_stock: false,
    update_cost_price: false,
    update_description: false,
});

const isProductExcel = computed(() => Boolean(props.productExcel?.fields?.length));
const storageKey = "products.excel.export.fields";

function defaultImportOptions() {
    return {
        duplicate_name_strategy: "error",
        duplicate_barcode_sku_strategy: "error",
        update_stock: false,
        update_cost_price: false,
        update_description: false,
    };
}

function resetProductImportState() {
    selectedImportFile.value = null;
    importPreview.value = null;
    importError.value = "";
    importOptions.value = defaultImportOptions();

    if (productImportFile.value) {
        productImportFile.value.value = "";
    }
}

watch(
    () => props.productExcel,
    () => {
        if (!isProductExcel.value) return;
        const defaults = props.productExcel.fields.filter((field) => field.default_export && field.exportable).map((field) => field.key);
        try {
            const saved = JSON.parse(localStorage.getItem(storageKey) || "[]");
            selectedExportFields.value = saved.length ? saved : defaults;
        } catch {
            selectedExportFields.value = defaults;
        }
    },
    { immediate: true },
);

function fieldsByGroup(groupKey) {
    return props.productExcel?.fields?.filter((field) => field.group === groupKey) || [];
}

function handleExport() {
    if (isProductExcel.value) {
        showExportModal.value = true;
        return;
    }
    window.location.href = props.exportUrl;
}

function downloadProductExport() {
    localStorage.setItem(storageKey, JSON.stringify(selectedExportFields.value));
    const url = new URL(props.exportUrl, window.location.origin);
    Object.entries(props.exportParams || {}).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== "") url.searchParams.set(key, value);
    });
    selectedExportFields.value.forEach((field) => url.searchParams.append("fields[]", field));
    window.location.href = url.toString();
    showExportModal.value = false;
}

function handleImportClick() {
    if (isProductExcel.value) {
        openImportModal();
        return;
    }
    if (importFile.value) {
        importFile.value.value = "";
        importFile.value.click();
    }
}

function openImportModal() {
    resetProductImportState();
    showImportModal.value = true;
}

function openProductFilePicker() {
    importError.value = "";
    importPreview.value = null;

    if (!productImportFile.value) {
        importError.value = "Không mở được bộ chọn file. Vui lòng tải lại trang và thử lại.";
        return;
    }

    productImportFile.value.value = "";
    productImportFile.value.click();
}

function handleLegacyImport(e) {
    const file = e.target.files[0];
    if (!file) return;
    const formData = new FormData();
    formData.append("file", file);
    router.post(props.importUrl, formData, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            e.target.value = "";
        },
    });
}

function handleProductFile(event) {
    const file = event.target.files?.[0] || null;

    selectedImportFile.value = file;
    importPreview.value = null;
    importError.value = "";

    if (!file) {
        importError.value = "Chưa chọn file dữ liệu.";
        return;
    }

    const allowedExtensions = [".xlsx", ".xls", ".csv", ".txt"];
    const lowerName = (file.name || "").toLowerCase();
    const isAllowed = allowedExtensions.some((extension) => lowerName.endsWith(extension));

    if (!isAllowed) {
        selectedImportFile.value = null;
        importError.value = "File không đúng định dạng. Vui lòng chọn .xlsx, .xls, .csv hoặc .txt.";
        if (productImportFile.value) {
            productImportFile.value.value = "";
        }
    }
}

function appendImportForm(formData) {
    formData.append("file", selectedImportFile.value);
    Object.entries(importOptions.value).forEach(([key, value]) => {
        formData.append(key, typeof value === "boolean" ? (value ? "1" : "0") : value);
    });
}

async function previewImport() {
    if (!selectedImportFile.value) return;
    importLoading.value = true;
    importError.value = "";
    const formData = new FormData();
    appendImportForm(formData);
    try {
        const response = await axios.post(props.importPreviewUrl || props.importUrl, formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });
        importPreview.value = response.data;
    } catch (error) {
        importError.value = error.response?.data?.message || "Không kiểm tra được file import.";
    } finally {
        importLoading.value = false;
    }
}

async function commitImport() {
    if (!selectedImportFile.value) return;
    importLoading.value = true;
    importError.value = "";
    const formData = new FormData();
    appendImportForm(formData);
    try {
        const response = await axios.post(props.importCommitUrl, formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });
        importPreview.value = response.data;
        if ((response.data?.error_rows || 0) === 0) {
            router.reload({ preserveScroll: true });
            closeImportModal();
        }
    } catch (error) {
        importError.value = error.response?.data?.message || "Không nhập được file.";
    } finally {
        importLoading.value = false;
    }
}

function closeImportModal() {
    showImportModal.value = false;
    if (importFile.value) importFile.value.value = "";
    resetProductImportState();
}
</script>
