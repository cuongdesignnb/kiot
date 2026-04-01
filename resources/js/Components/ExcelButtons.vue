<template>
    <div class="flex gap-1.5">
        <!-- Export button -->
        <button
            v-if="exportUrl"
            @click="handleExport"
            class="bg-white text-gray-600 border border-gray-300 px-3 py-1.5 text-sm font-medium rounded hover:bg-gray-50 transition flex items-center gap-1"
            title="Xuất Excel/CSV"
        >
            <svg
                class="w-4 h-4"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"
                ></path>
            </svg>
            <span>Xuất file</span>
        </button>
        <!-- Import button -->
        <template v-if="importUrl">
            <button
                @click="$refs.importFile.click()"
                class="bg-white text-gray-600 border border-gray-300 px-3 py-1.5 text-sm font-medium rounded hover:bg-gray-50 transition flex items-center gap-1"
                title="Nhập từ Excel/CSV"
            >
                <svg
                    class="w-4 h-4"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"
                    ></path>
                </svg>
                <span>Nhập file</span>
            </button>
            <input
                type="file"
                ref="importFile"
                accept=".csv,.txt,.xlsx,.xls"
                class="hidden"
                @change="handleImport"
            />
        </template>
    </div>
</template>

<script setup>
import { router } from "@inertiajs/vue3";

const props = defineProps({
    exportUrl: { type: String, default: "" },
    importUrl: { type: String, default: "" },
});

function handleExport() {
    window.location.href = props.exportUrl;
}

function handleImport(e) {
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
</script>
