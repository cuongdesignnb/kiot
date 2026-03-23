<script setup>
import { ref, computed } from "vue";
import { Head } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import axios from "axios";

const tasks = ref([]);
const loading = ref(true);
const notLinked = ref(false);
const activeFilter = ref("all"); // all | pending | active | completed

const load = async () => {
    loading.value = true;
    notLinked.value = false;
    try {
        const res = await axios.get("/api/my-tasks");
        if (res.data?.message === 'Tài khoản chưa liên kết nhân viên.') {
            notLinked.value = true;
            tasks.value = [];
        } else {
            tasks.value = res.data?.data || [];
        }
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

const acceptingAll = ref(false);

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

const acceptAllTasks = async () => {
    if (!confirm(`Xác nhận nhận tất cả ${counts.value.pending} công việc đang chờ?`)) return;
    acceptingAll.value = true;
    try {
        const res = await axios.post('/api/my-tasks/accept-all');
        alert(res.data?.message || 'Đã nhận tất cả!');
        load();
    } catch (e) {
        alert(e.response?.data?.message || 'Lỗi.');
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

// ── Work Notes / Ghi chú tiến độ ──
const expandedTaskId = ref(null);
const taskNotes = ref([]);
const loadingNotes = ref(false);
const newNote = ref("");
const submittingNote = ref(false);

const toggleNotes = async (taskId) => {
    if (expandedTaskId.value === taskId) {
        expandedTaskId.value = null;
        return;
    }
    expandedTaskId.value = taskId;
    loadingNotes.value = true;
    try {
        const res = await axios.get(`/api/my-tasks/${taskId}/notes`);
        taskNotes.value = res.data?.data || [];
    } catch (e) {
        console.error(e);
        taskNotes.value = [];
    } finally {
        loadingNotes.value = false;
    }
};

const submitNote = async (taskId) => {
    if (!newNote.value.trim()) return;
    submittingNote.value = true;
    try {
        await axios.post(`/api/my-tasks/${taskId}/notes`, { body: newNote.value.trim() });
        newNote.value = "";
        // Reload notes
        const res = await axios.get(`/api/my-tasks/${taskId}/notes`);
        taskNotes.value = res.data?.data || [];
    } catch (e) {
        alert(e.response?.data?.message || "Lỗi khi ghi chú.");
    } finally {
        submittingNote.value = false;
    }
};

const timeAgo = (dateStr) => {
    const now = new Date();
    const d = new Date(dateStr);
    const diff = Math.floor((now - d) / 1000);
    if (diff < 60) return "vừa xong";
    if (diff < 3600) return Math.floor(diff / 60) + " phút trước";
    if (diff < 86400) return Math.floor(diff / 3600) + " giờ trước";
    return Math.floor(diff / 86400) + " ngày trước";
};

load();
</script>

<template>
    <Head title="Việc của tôi" />
    <AppLayout>
        <div class="p-6">
            <h1 class="text-xl font-bold text-gray-800 mb-4">Việc của tôi</h1>

            <!-- Warning: account not linked -->
            <div v-if="notLinked" class="bg-yellow-50 border border-yellow-300 rounded-lg p-4 mb-4">
                <div class="flex items-start gap-3">
                    <span class="text-2xl">⚠</span>
                    <div>
                        <h3 class="font-bold text-yellow-800">Tài khoản chưa liên kết nhân viên</h3>
                        <p class="text-sm text-yellow-700 mt-1">Tài khoản đăng nhập của bạn chưa được liên kết với hồ sơ nhân viên. Vui lòng liên hệ quản trị viên để liên kết tài khoản.</p>
                    </div>
                </div>
            </div>

            <!-- Filter tabs -->
            <div class="flex items-center gap-2 mb-4 flex-wrap">
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
                <!-- Nhận tất cả -->
                <button
                    v-if="counts.pending > 0"
                    @click="acceptAllTasks"
                    :disabled="acceptingAll"
                    class="ml-auto px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-bold hover:bg-green-700 transition disabled:opacity-50 flex items-center gap-1.5 shadow-sm"
                >
                    <svg v-if="acceptingAll" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    {{ acceptingAll ? 'Đang nhận...' : `Nhận tất cả (${counts.pending})` }}
                </button>
            </div>

            <div v-if="loading" class="text-center py-16 text-gray-400">Đang tải...</div>
            <div v-else-if="!filtered.length" class="text-center py-16 text-gray-400">Không có công việc nào.</div>

            <div v-else class="space-y-3">
                <div v-for="t in filtered" :key="t.id" class="bg-white border rounded-lg hover:shadow-sm transition">
                    <div class="p-4">
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
                    </div>

                    <!-- Progress bar for active tasks -->
                    <div v-if="t.assignment_status === 'accepted' && t.status !== 'completed' && t.status !== 'cancelled'" class="px-4 pb-3 border-t mx-4 pt-3">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs text-gray-500">Tiến độ</span>
                            <span class="text-xs font-bold">{{ t.progress || 0 }}%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2 mb-2">
                            <div class="h-2 rounded-full bg-blue-500 transition-all" :style="{ width: (t.progress || 0) + '%' }"></div>
                        </div>
                        <div class="flex items-center gap-3">
                            <template v-if="editingProgressId === t.id">
                                <input type="range" v-model.number="tempProgress" min="0" max="100" class="flex-1" />
                                <span class="text-sm font-bold w-10 text-right">{{ tempProgress }}%</span>
                                <button @click="saveProgress(t.id)" class="px-3 py-1 bg-blue-600 text-white rounded text-xs font-semibold hover:bg-blue-700">Lưu</button>
                                <button @click="editingProgressId = null" class="px-3 py-1 border rounded text-xs text-gray-500">Hủy</button>
                            </template>
                            <div v-else class="flex items-center gap-3">
                                <button @click="startEditProgress(t)" class="text-xs text-blue-500 hover:underline">Cập nhật tiến độ</button>
                                <button @click="toggleNotes(t.id)" class="text-xs text-indigo-500 hover:underline flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path></svg>
                                    {{ expandedTaskId === t.id ? 'Ẩn ghi chú' : 'Ghi chú tiến độ' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Work Notes / Ghi chú tiến độ (expanded) -->
                    <div v-if="expandedTaskId === t.id" class="border-t bg-gray-50 px-4 py-3">
                        <div class="text-sm font-semibold text-gray-700 mb-2 flex items-center gap-1">
                            <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                            Nhật ký công việc
                        </div>

                        <!-- Input form -->
                        <div class="flex gap-2 mb-3">
                            <input
                                v-model="newNote"
                                @keydown.enter="submitNote(t.id)"
                                type="text"
                                class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                :placeholder="'VD: Chờ vỏ đi sơn về, đã thay pin xong...'"
                            />
                            <button
                                @click="submitNote(t.id)"
                                :disabled="submittingNote || !newNote.trim()"
                                class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 disabled:opacity-50 whitespace-nowrap"
                            >
                                {{ submittingNote ? 'Đang gửi...' : 'Ghi chú' }}
                            </button>
                        </div>

                        <!-- Notes list -->
                        <div v-if="loadingNotes" class="text-gray-400 text-xs text-center py-2">Đang tải...</div>
                        <div v-else-if="taskNotes.length === 0" class="text-gray-400 text-xs text-center py-2">Chưa có ghi chú nào.</div>
                        <div v-else class="space-y-2 max-h-[250px] overflow-y-auto">
                            <div v-for="note in taskNotes" :key="note.id" class="flex gap-2 text-sm">
                                <div class="w-6 h-6 bg-indigo-100 rounded-full flex items-center justify-center text-indigo-600 font-bold text-xs flex-shrink-0 mt-0.5">
                                    {{ note.user?.name?.charAt(0)?.toUpperCase() || '?' }}
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="font-semibold text-gray-700">{{ note.user?.name || 'Ẩn danh' }}</span>
                                        <span class="text-[11px] text-gray-400">{{ timeAgo(note.created_at) }}</span>
                                    </div>
                                    <p class="text-gray-600 mt-0.5">{{ note.body }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
