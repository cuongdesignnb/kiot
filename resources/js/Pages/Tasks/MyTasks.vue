<script setup>
import { ref, computed } from "vue";
import { Head } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import axios from "axios";

const tasks = ref([]);
const loading = ref(true);
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
    if (activeFilter.value === "pending") return tasks.value.filter(t => t.assignment_status === "pending");
    if (activeFilter.value === "active") return tasks.value.filter(t => t.assignment_status === "accepted" && t.status !== "completed" && t.status !== "cancelled");
    if (activeFilter.value === "completed") return tasks.value.filter(t => t.status === "completed");
    return tasks.value;
});

const counts = computed(() => ({
    all: tasks.value.length,
    pending: tasks.value.filter(t => t.assignment_status === "pending").length,
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
        urgent: { label: "Khẩn", cls: "text-red-600 font-bold" },
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

load();
</script>

<template>
    <Head title="Việc của tôi" />
    <AppLayout>
        <div class="p-6">
            <h1 class="text-xl font-bold text-gray-800 mb-4">Việc của tôi</h1>

            <!-- Filter tabs -->
            <div class="flex gap-2 mb-4">
                <button v-for="f in [
                    { key: 'all', label: 'Tất cả' },
                    { key: 'pending', label: 'Chờ xác nhận' },
                    { key: 'active', label: 'Đang làm' },
                    { key: 'completed', label: 'Hoàn thành' },
                ]" :key="f.key" @click="activeFilter = f.key"
                    :class="activeFilter === f.key ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 border'"
                    class="px-4 py-2 rounded-lg text-sm font-semibold transition">
                    {{ f.label }} <span class="ml-1 text-xs opacity-75">({{ counts[f.key] }})</span>
                </button>
            </div>

            <div v-if="loading" class="text-center py-16 text-gray-400">Đang tải...</div>
            <div v-else-if="!filtered.length" class="text-center py-16 text-gray-400">Không có công việc nào.</div>

            <div v-else class="space-y-3">
                <div v-for="t in filtered" :key="t.id" class="bg-white border rounded-lg p-4 hover:shadow-sm transition">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <a :href="`/tasks/${t.id}`" class="text-sm font-bold text-blue-600 hover:underline">{{ t.code }}</a>
                                <span :class="statusBadge(t.status).cls" class="px-2 py-0.5 rounded-full text-xs font-semibold">{{ statusBadge(t.status).label }}</span>
                                <span :class="priorityBadge(t.priority).cls" class="text-xs">{{ priorityBadge(t.priority).label }}</span>
                                <span v-if="t.type === 'repair'" class="bg-purple-100 text-purple-600 px-1.5 py-0.5 rounded text-xs font-semibold">Sửa chữa</span>
                                <span v-else class="bg-teal-100 text-teal-600 px-1.5 py-0.5 rounded text-xs font-semibold">Công việc</span>
                            </div>
                            <h3 class="font-semibold text-gray-800">{{ t.title || t.code }}</h3>
                            <p v-if="t.issue_description" class="text-sm text-gray-500 mt-1 line-clamp-2">{{ t.issue_description }}</p>
                            <div class="flex items-center gap-4 mt-2 text-xs text-gray-400">
                                <span v-if="t.branch?.name">📍 {{ t.branch.name }}</span>
                                <span v-if="t.deadline" :class="new Date(t.deadline) < new Date() && t.status !== 'completed' ? 'text-red-500 font-bold' : ''">
                                    📅 {{ t.deadline }}
                                </span>
                                <span v-if="t.category">🏷️ {{ t.category.name }}</span>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex flex-col items-end gap-2 ml-4">
                            <!-- Pending assignment: accept/reject -->
                            <template v-if="t.assignment_status === 'pending'">
                                <button @click="respond(t.assignment_id, 'accepted')" class="px-4 py-1.5 bg-green-600 text-white rounded-lg text-sm font-semibold hover:bg-green-700">Nhận việc</button>
                                <button @click="respond(t.assignment_id, 'rejected')" class="px-4 py-1.5 bg-red-50 text-red-600 rounded-lg text-sm font-semibold hover:bg-red-100 border border-red-200">Từ chối</button>
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
                            <div class="h-2 rounded-full bg-blue-500 transition-all" :style="{ width: (t.progress || 0) + '%' }"></div>
                        </div>
                        <template v-if="editingProgressId === t.id">
                            <div class="flex items-center gap-3">
                                <input type="range" v-model.number="tempProgress" min="0" max="100" class="flex-1" />
                                <span class="text-sm font-bold w-10 text-right">{{ tempProgress }}%</span>
                                <button @click="saveProgress(t.id)" class="px-3 py-1 bg-blue-600 text-white rounded text-xs font-semibold hover:bg-blue-700">Lưu</button>
                                <button @click="editingProgressId = null" class="px-3 py-1 border rounded text-xs text-gray-500">Hủy</button>
                            </div>
                        </template>
                        <button v-else @click="startEditProgress(t)" class="text-xs text-blue-500 hover:underline">Cập nhật tiến độ</button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
