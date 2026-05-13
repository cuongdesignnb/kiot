<script setup>
import { ref, computed } from "vue";
import { Head } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import axios from "axios";

const tasks = ref([]);
const loading = ref(true);
const acceptingAll = ref(false);
const activeFilter = ref("all"); // all | pending | active | completed

const load = async () => {
    loading.value = true;
    try {
        const res = await axios.get("/api/my-tasks");
        tasks.value = res.data?.data || [];
    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
};

const filtered = computed(() => {
    if (activeFilter.value === "all") return tasks.value;
    if (activeFilter.value === "pending") return tasks.value.filter(t => t.assignment_status === "pending" && t.status !== "completed" && t.status !== "cancelled");
    if (activeFilter.value === "active") return tasks.value.filter(t => t.assignment_status === "accepted" && t.status !== "completed" && t.status !== "cancelled");
    if (activeFilter.value === "completed") return tasks.value.filter(t => t.status === "completed");
    return tasks.value;
});

const counts = computed(() => ({
    all: tasks.value.length,
    pending: tasks.value.filter(t => t.assignment_status === "pending" && t.status !== "completed" && t.status !== "cancelled").length,
    active: tasks.value.filter(t => t.assignment_status === "accepted" && t.status !== "completed" && t.status !== "cancelled").length,
    completed: tasks.value.filter(t => t.status === "completed").length,
}));

const statusBadge = (status) => {
    const map = {
        pending: { label: "Chờ xử lý", cls: "bg-yellow-100 text-yellow-700" },
        in_progress: { label: "Đang thực hiện", cls: "bg-blue-100 text-blue-700" },
        completed: { label: "Hoàn thành", cls: "bg-green-100 text-green-700" },
        cancelled: { label: "Đã hủy", cls: "bg-red-100 text-red-600" },
    };
    return map[status] || { label: status, cls: "bg-gray-100 text-gray-600" };
};

const priorityBadge = (priority) => {
    const map = {
        low: { label: "Thấp", cls: "text-gray-500" },
        normal: { label: "BT", cls: "text-blue-500" },
        high: { label: "Cao", cls: "text-orange-500 font-bold" },
        urgent: { label: "Khẩn", cls: "text-red-600 font-bold animate-pulse" },
    };
    return map[priority] || { label: priority, cls: "text-gray-500" };
};

const respond = async (assignmentId, status) => {
    const action = status === "accepted" ? "nhận" : "từ chối";
    if (!confirm(`Xác nhận ${action} công việc này?`)) return;
    try {
        await axios.post(`/api/my-tasks/${assignmentId}/respond`, { status });
        load();
    } catch (e) {
        alert(e.response?.data?.message || "Lỗi.");
    }
};

const acceptAll = async () => {
    if (!counts.value.pending) return;
    if (!confirm(`Nhận tất cả ${counts.value.pending} công việc đang chờ?`)) return;
    acceptingAll.value = true;
    try {
        const res = await axios.post("/api/my-tasks/accept-all");
        alert(res.data?.message || "Đã nhận tất cả.");
        load();
    } catch (e) {
        alert(e.response?.data?.message || "Lỗi.");
    } finally {
        acceptingAll.value = false;
    }
};

// Progress inline edit
const editingProgressId = ref(null);
const tempProgress = ref(0);

const startEditProgress = (task) => {
    editingProgressId.value = task.id;
    tempProgress.value = task.progress || 0;
};

const saveProgress = async (taskId) => {
    try {
        await axios.post(`/api/my-tasks/${taskId}/progress`, { progress: tempProgress.value });
        editingProgressId.value = null;
        load();
    } catch (e) {
        alert(e.response?.data?.message || "Lỗi.");
    }
};

const formatDate = (d) => {
    if (!d) return "";
    const dt = new Date(d);
    const dd = String(dt.getDate()).padStart(2, "0");
    const mm = String(dt.getMonth() + 1).padStart(2, "0");
    const yy = dt.getFullYear();
    return `${dd}/${mm}/${yy}`;
};

// Lấy tên máy từ serial hoặc product
const getDeviceName = (t) => {
    return t.serial_imei?.product?.name || t.product?.name || null;
};

const getSerialNumber = (t) => {
    return t.serial_imei?.serial_number || null;
};

// HOTFIX 24.16C — when the physical serial is dismantled, show "Đã bóc tách"
// (red) instead of the misleading "Sẵn bán" green badge.
const getRepairStatusLabel = (t) => {
    const rs = t.serial_imei?.repair_status;
    const status = t.serial_imei?.status;
    if (rs === "ready" && status === "dismantled") {
        return "⚠ Đã bóc tách";
    }
    const map = {
        not_started: "Chưa làm",
        repairing: "Đang xử lý",
        ready: "Sẵn bán",
    };
    return map[rs] || null;
};

const getRepairStatusCls = (t) => {
    const rs = t.serial_imei?.repair_status;
    const status = t.serial_imei?.status;
    if (rs === "ready" && status === "dismantled") {
        return "bg-red-100 text-red-700";
    }
    const map = {
        not_started: "bg-red-100 text-red-600",
        repairing: "bg-yellow-100 text-yellow-700",
        ready: "bg-green-100 text-green-700",
    };
    return map[rs] || "bg-gray-100 text-gray-600";
};

load();
</script>

<template>
    <Head title="Việc của tôi" />
    <AppLayout>
        <div class="p-6">
            <!-- Header -->
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-xl font-bold text-gray-800">Việc của tôi</h1>
                <button v-if="counts.pending > 0"
                    @click="acceptAll"
                    :disabled="acceptingAll"
                    class="px-5 py-2 bg-green-600 text-white rounded-lg font-semibold hover:bg-green-700 disabled:opacity-50 transition flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    {{ acceptingAll ? 'Đang xử lý...' : `Nhận tất cả (${counts.pending})` }}
                </button>
            </div>

            <!-- Filter tabs -->
            <div class="flex gap-2 mb-4">
                <button v-for="f in [
                    { key: 'all', label: 'Tất cả', icon: '📋' },
                    { key: 'pending', label: 'Chờ xác nhận', icon: '⏳' },
                    { key: 'active', label: 'Đang làm', icon: '🔧' },
                    { key: 'completed', label: 'Hoàn thành', icon: '✅' },
                ]" :key="f.key" @click="activeFilter = f.key"
                    :class="activeFilter === f.key ? 'bg-blue-600 text-white shadow-md' : 'bg-white text-gray-600 border hover:bg-gray-50'"
                    class="px-4 py-2 rounded-lg text-sm font-semibold transition">
                    {{ f.icon }} {{ f.label }} <span class="ml-1 text-xs opacity-75">({{ counts[f.key] }})</span>
                </button>
            </div>

            <div v-if="loading" class="text-center py-16 text-gray-400">Đang tải...</div>
            <div v-else-if="!filtered.length" class="text-center py-16 text-gray-400">
                <div class="text-4xl mb-3">📭</div>
                <p>Không có công việc nào.</p>
            </div>

            <div v-else class="space-y-3">
                <div v-for="t in filtered" :key="t.id"
                    class="bg-white border rounded-lg p-4 hover:shadow-md transition"
                    :class="{
                        'border-l-4 border-l-yellow-400': t.assignment_status === 'pending' && t.status !== 'completed' && t.status !== 'cancelled',
                        'border-l-4 border-l-blue-500': t.assignment_status === 'accepted' && t.status !== 'completed' && t.status !== 'cancelled',
                        'border-l-4 border-l-green-500': t.status === 'completed',
                        'border-l-4 border-l-red-300': t.status === 'cancelled',
                    }">
                    <div class="flex items-start justify-between">
                        <div class="flex-1 min-w-0">
                            <!-- Row 1: Code + badges -->
                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                <a :href="`/tasks/${t.id}`" class="text-sm font-bold text-blue-600 hover:underline">{{ t.code }}</a>
                                <span :class="statusBadge(t.status).cls" class="px-2 py-0.5 rounded-full text-xs font-semibold">{{ statusBadge(t.status).label }}</span>
                                <span :class="priorityBadge(t.priority).cls" class="text-xs">{{ priorityBadge(t.priority).label }}</span>
                                <span v-if="t.type === 'repair'" class="bg-purple-100 text-purple-600 px-1.5 py-0.5 rounded text-xs font-semibold">🔧 Sửa chữa</span>
                                <span v-else class="bg-teal-100 text-teal-600 px-1.5 py-0.5 rounded text-xs font-semibold">📝 Công việc</span>
                            </div>

                            <!-- Row 2: Title -->
                            <h3 class="font-semibold text-gray-800">{{ t.title || t.code }}</h3>

                            <!-- Row 3: Device info (repair tasks) -->
                            <div v-if="t.type === 'repair' && (getDeviceName(t) || getSerialNumber(t))" class="flex items-center gap-3 mt-1.5 bg-purple-50 rounded-lg px-3 py-2">
                                <div v-if="getDeviceName(t)" class="flex items-center gap-1.5">
                                    <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                                    <span class="text-sm font-medium text-purple-800">{{ getDeviceName(t) }}</span>
                                </div>
                                <div v-if="getSerialNumber(t)" class="flex items-center gap-1.5">
                                    <span class="text-gray-300">|</span>
                                    <span class="text-xs font-mono text-purple-600 bg-purple-100 px-2 py-0.5 rounded">{{ getSerialNumber(t) }}</span>
                                </div>
                                <div v-if="getRepairStatusLabel(t)" class="flex items-center gap-1.5">
                                    <span class="text-gray-300">|</span>
                                    <span :class="getRepairStatusCls(t)" class="text-xs px-2 py-0.5 rounded-full font-semibold">{{ getRepairStatusLabel(t) }}</span>
                                </div>
                            </div>

                            <!-- Row 4: Description -->
                            <p v-if="t.issue_description" class="text-sm text-gray-500 mt-1.5 line-clamp-2">{{ t.issue_description }}</p>

                            <!-- Row 5: Meta info -->
                            <div class="flex items-center gap-4 mt-2 text-xs text-gray-400 flex-wrap">
                                <span v-if="t.branch?.name">📍 {{ t.branch.name }}</span>
                                <span v-if="t.deadline" :class="new Date(t.deadline) < new Date() && t.status !== 'completed' ? 'text-red-500 font-bold' : ''">
                                    📅 {{ formatDate(t.deadline) }}
                                </span>
                                <span v-if="t.category">
                                    <span class="inline-block w-2 h-2 rounded-full mr-0.5" :style="{ backgroundColor: t.category.color }"></span>
                                    {{ t.category.name }}
                                </span>
                                <span v-if="t.created_at">🕐 {{ formatDate(t.created_at) }}</span>
                                <span v-if="t.creator?.name">👤 {{ t.creator.name }}</span>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex flex-col items-end gap-2 ml-4 flex-shrink-0">
                            <!-- Pending assignment: accept/reject (only if task is still active) -->
                            <template v-if="t.assignment_status === 'pending' && t.status !== 'completed' && t.status !== 'cancelled'">
                                <button @click="respond(t.assignment_id, 'accepted')" class="px-4 py-1.5 bg-green-600 text-white rounded-lg text-sm font-semibold hover:bg-green-700 transition">✓ Nhận việc</button>
                                <button @click="respond(t.assignment_id, 'rejected')" class="px-4 py-1.5 bg-red-50 text-red-600 rounded-lg text-sm font-semibold hover:bg-red-100 border border-red-200 transition">✕ Từ chối</button>
                            </template>
                            <!-- Accepted: show status -->
                            <template v-else-if="t.assignment_status === 'accepted' && t.status !== 'completed' && t.status !== 'cancelled'">
                                <span class="px-3 py-1.5 bg-blue-50 text-blue-600 rounded-lg text-sm font-semibold">🔧 Đang xử lý</span>
                            </template>
                            <!-- Completed: show label -->
                            <template v-else-if="t.status === 'completed'">
                                <span class="px-3 py-1.5 bg-green-50 text-green-600 rounded-lg text-sm font-semibold">✓ Đã hoàn thành</span>
                            </template>
                            <!-- Cancelled -->
                            <template v-else-if="t.status === 'cancelled'">
                                <span class="px-3 py-1.5 bg-red-50 text-red-500 rounded-lg text-sm font-semibold">Đã hủy</span>
                            </template>
                        </div>
                    </div>

                    <!-- Progress bar for active tasks -->
                    <div v-if="t.assignment_status === 'accepted' && t.status !== 'completed' && t.status !== 'cancelled'" class="mt-3 pt-3 border-t">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs text-gray-500">Tiến độ</span>
                            <span class="text-xs font-bold">{{ t.progress || 0 }}%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2 mb-2">
                            <div class="h-2 rounded-full transition-all"
                                :class="(t.progress || 0) >= 80 ? 'bg-green-500' : (t.progress || 0) >= 50 ? 'bg-blue-500' : 'bg-yellow-500'"
                                :style="{ width: (t.progress || 0) + '%' }"></div>
                        </div>
                        <template v-if="editingProgressId === t.id">
                            <div class="flex items-center gap-3">
                                <input type="range" v-model.number="tempProgress" min="0" max="100" class="flex-1 accent-blue-600" />
                                <span class="text-sm font-bold w-10 text-right">{{ tempProgress }}%</span>
                                <button @click="saveProgress(t.id)" class="px-3 py-1 bg-blue-600 text-white rounded text-xs font-semibold hover:bg-blue-700">Lưu</button>
                                <button @click="editingProgressId = null" class="px-3 py-1 border rounded text-xs text-gray-500">Hủy</button>
                            </div>
                        </template>
                        <button v-else @click="startEditProgress(t)" class="text-xs text-blue-500 hover:underline">📊 Cập nhật tiến độ</button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
