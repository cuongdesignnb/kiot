<script setup>
import { ref, computed, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import axios from "axios";

const props = defineProps({
    taskId: [Number, String],
    employees: Array,
    categories: Array,
});

const task = ref(null);
const loading = ref(true);
const activeTab = ref("info");

// Part modal (add) — multi-line
const showPartModal = ref(false);
const partItems = ref([]);
const partError = ref("");
const partSearchIdx = ref(-1);
const partSearchQuery = ref("");
const partSearchResults = ref([]);

// Disassemble modal (bóc máy) — multi-line
const showDisassembleModal = ref(false);
const disItems = ref([]);
const disError = ref("");
const disSearchIdx = ref(-1);
const disSearchQuery = ref("");
const disSearchResults = ref([]);

// Quick create product
const showQuickCreate = ref(false);
const quickCreateFor = ref(""); // 'add' or 'dis'
const quickProduct = ref({ name: "", cost_price: 0 });
const quickCreateError = ref("");

// Helpers
const newPartRow = () => ({ product_id: null, product_name: "", quantity: 1, notes: "", cost_price: 0, stock_quantity: 0 });
const newDisRow = () => ({ product_id: null, product_name: "", quantity: 1, unit_cost: 0, notes: "", stock_quantity: 0 });

// Assign modal
const showAssignModal = ref(false);
const assignEmployeeIds = ref([]);
const assignError = ref("");

// Comment
const commentBody = ref("");
const commentLoading = ref(false);

// Progress
const editingProgress = ref(false);
const progressValue = ref(0);

const loadTask = async () => {
    loading.value = true;
    try {
        const res = await axios.get(`/api/tasks/${props.taskId}`);
        task.value = res.data;
        progressValue.value = res.data.progress || 0;
    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
};

const formatCurrency = (v) => v ? Number(v).toLocaleString("vi-VN") : "0";

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
        low: { label: "Thấp", cls: "bg-gray-100 text-gray-600" },
        normal: { label: "Bình thường", cls: "bg-blue-50 text-blue-600" },
        high: { label: "Cao", cls: "bg-orange-100 text-orange-600" },
        urgent: { label: "Khẩn cấp", cls: "bg-red-100 text-red-600" },
    };
    return map[priority] || { label: priority, cls: "bg-gray-100 text-gray-600" };
};

const assignmentStatusBadge = (status) => {
    const map = {
        pending: { label: "Chờ xác nhận", cls: "bg-yellow-50 text-yellow-600" },
        accepted: { label: "Đã nhận", cls: "bg-green-50 text-green-600" },
        rejected: { label: "Từ chối", cls: "bg-red-50 text-red-600" },
    };
    return map[status] || { label: status, cls: "bg-gray-100 text-gray-600" };
};

// ── Product search (shared for both modals) ──
let searchTimeout;
const doProductSearch = (query, target) => {
    clearTimeout(searchTimeout);
    if (!query || query.length < 2) {
        if (target === 'add') partSearchResults.value = [];
        else disSearchResults.value = [];
        return;
    }
    searchTimeout = setTimeout(async () => {
        try {
            const res = await axios.get("/api/tasks/search-products", { params: { q: query } });
            if (target === 'add') partSearchResults.value = res.data || [];
            else disSearchResults.value = res.data || [];
        } catch (e) {
            if (target === 'add') partSearchResults.value = [];
            else disSearchResults.value = [];
        }
    }, 300);
};

const onPartSearch = (idx) => {
    partSearchIdx.value = idx;
    doProductSearch(partSearchQuery.value, 'add');
};
const selectPartProduct = (p, idx) => {
    partItems.value[idx].product_id = p.id;
    partItems.value[idx].product_name = p.name;
    partItems.value[idx].cost_price = p.cost_price || 0;
    partItems.value[idx].stock_quantity = p.stock_quantity ?? 0;
    partSearchResults.value = [];
    partSearchIdx.value = -1;
    partSearchQuery.value = "";
};

const onDisSearch = (idx) => {
    disSearchIdx.value = idx;
    doProductSearch(disSearchQuery.value, 'dis');
};
const selectDisProduct = (p, idx) => {
    disItems.value[idx].product_id = p.id;
    disItems.value[idx].product_name = p.name;
    disItems.value[idx].unit_cost = p.cost_price || 0;
    disItems.value[idx].stock_quantity = p.stock_quantity ?? 0;
    disSearchResults.value = [];
    disSearchIdx.value = -1;
    disSearchQuery.value = "";
};

// ── Quick Create Product ──
const openQuickCreate = (target) => {
    quickCreateFor.value = target;
    quickProduct.value = { name: "", cost_price: 0 };
    quickCreateError.value = "";
    showQuickCreate.value = true;
};
const submitQuickProduct = async () => {
    quickCreateError.value = "";
    if (!quickProduct.value.name.trim()) { quickCreateError.value = "Nhập tên sản phẩm."; return; }
    try {
        const res = await axios.post("/api/tasks/quick-create-product", quickProduct.value);
        const p = res.data;
        // Add to the current modal's items
        if (quickCreateFor.value === 'add') {
            partItems.value.push({ product_id: p.id, product_name: p.name, quantity: 1, notes: "", cost_price: p.cost_price || 0, stock_quantity: 0 });
        } else {
            disItems.value.push({ product_id: p.id, product_name: p.name, quantity: 1, unit_cost: p.cost_price || 0, notes: "", stock_quantity: 0 });
        }
        showQuickCreate.value = false;
    } catch (e) {
        quickCreateError.value = e.response?.data?.message || "Lỗi tạo sản phẩm.";
    }
};

// ── Submit batch add ──
const submitAddParts = async () => {
    partError.value = "";
    const validItems = partItems.value.filter(i => i.product_id && i.quantity > 0);
    if (!validItems.length) { partError.value = "Thêm ít nhất 1 linh kiện."; return; }
    try {
        const res = await axios.post(`/api/tasks/${props.taskId}/batch-parts`, { items: validItems });
        if (res.data.errors?.length) {
            partError.value = res.data.errors.join("\n");
        } else {
            showPartModal.value = false;
        }
        loadTask();
    } catch (e) {
        partError.value = e.response?.data?.message || "Lỗi khi thêm linh kiện.";
    }
};

// ── Submit batch disassemble ──
const submitDisassembleParts = async () => {
    disError.value = "";
    const validItems = disItems.value.filter(i => i.product_id && i.quantity > 0);
    if (!validItems.length) { disError.value = "Thêm ít nhất 1 linh kiện."; return; }
    try {
        const res = await axios.post(`/api/tasks/${props.taskId}/batch-disassemble`, { items: validItems });
        if (res.data.errors?.length) {
            disError.value = res.data.errors.join("\n");
        } else {
            showDisassembleModal.value = false;
        }
        loadTask();
    } catch (e) {
        disError.value = e.response?.data?.message || "Lỗi khi bóc linh kiện.";
    }
};

const removePart = async (partId) => {
    if (!confirm("Xác nhận gỡ linh kiện này?")) return;
    try {
        await axios.delete(`/api/tasks/${props.taskId}/parts/${partId}`);
        loadTask();
    } catch (e) {
        alert(e.response?.data?.message || "Lỗi.");
    }
};

const openPartModal = () => {
    partError.value = '';
    partItems.value = [newPartRow()];
    partSearchIdx.value = -1;
    partSearchQuery.value = "";
    partSearchResults.value = [];
    showPartModal.value = true;
};
const openDisassembleModal = () => {
    disError.value = '';
    disItems.value = [newDisRow()];
    disSearchIdx.value = -1;
    disSearchQuery.value = "";
    disSearchResults.value = [];
    showDisassembleModal.value = true;
};

// Assign
const openAssignModal = () => {
    assignEmployeeIds.value = [];
    assignError.value = "";
    showAssignModal.value = true;
};

const toggleAssignEmployee = (empId) => {
    const idx = assignEmployeeIds.value.indexOf(empId);
    if (idx >= 0) assignEmployeeIds.value.splice(idx, 1);
    else assignEmployeeIds.value.push(empId);
};

const submitAssign = async () => {
    assignError.value = "";
    if (!assignEmployeeIds.value.length) { assignError.value = "Chọn ít nhất 1 nhân viên."; return; }
    try {
        await axios.post(`/api/tasks/${props.taskId}/assign`, { employee_ids: assignEmployeeIds.value });
        showAssignModal.value = false;
        loadTask();
    } catch (e) {
        assignError.value = e.response?.data?.message || "Lỗi.";
    }
};

const markComplete = async () => {
    if (!confirm("Xác nhận hoàn thành công việc?")) return;
    try {
        await axios.post(`/api/tasks/${props.taskId}/complete`);
        loadTask();
    } catch (e) {
        alert(e.response?.data?.message || "Lỗi.");
    }
};

const cancelTask = async () => {
    if (!confirm("Xác nhận hủy công việc?")) return;
    try {
        await axios.delete(`/api/tasks/${props.taskId}`);
        loadTask();
    } catch (e) {
        alert(e.response?.data?.message || "Lỗi.");
    }
};

// Deadline
const editDeadline = ref("");
const showDeadlineEdit = ref(false);

const startEditDeadline = () => {
    editDeadline.value = task.value?.deadline || "";
    showDeadlineEdit.value = true;
};

const saveDeadline = async () => {
    try {
        await axios.put(`/api/tasks/${props.taskId}`, { deadline: editDeadline.value || null });
        showDeadlineEdit.value = false;
        loadTask();
    } catch (e) {
        alert(e.response?.data?.message || "Lỗi.");
    }
};

// Progress
const saveProgress = async () => {
    try {
        await axios.post(`/api/tasks/${props.taskId}/progress`, { progress: progressValue.value });
        editingProgress.value = false;
        loadTask();
    } catch (e) {
        alert(e.response?.data?.message || "Lỗi.");
    }
};

// Comments
const submitComment = async () => {
    if (!commentBody.value.trim()) return;
    commentLoading.value = true;
    try {
        await axios.post(`/api/tasks/${props.taskId}/comments`, { body: commentBody.value });
        commentBody.value = "";
        loadTask();
    } catch (e) {
        alert(e.response?.data?.message || "Lỗi.");
    } finally {
        commentLoading.value = false;
    }
};

const isActive = computed(() => task.value && !['completed', 'cancelled'].includes(task.value.status));

loadTask();
</script>

<template>
    <Head :title="task ? `${task.code} — ${task.title}` : 'Chi tiết công việc'" />
    <AppLayout>
        <div class="p-6">
            <div v-if="loading" class="text-center py-20 text-gray-400">Đang tải...</div>

            <template v-else-if="task">
                <!-- Header -->
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <a href="/tasks" class="text-sm text-blue-600 hover:underline">&larr; Danh sách công việc</a>
                        <h1 class="text-xl font-bold text-gray-800 mt-1">
                            {{ task.code }} — {{ task.title || task.code }}
                            <span :class="statusBadge(task.status).cls" class="ml-2 px-2 py-0.5 rounded-full text-xs font-semibold">{{ statusBadge(task.status).label }}</span>
                            <span :class="priorityBadge(task.priority).cls" class="ml-1 px-2 py-0.5 rounded-full text-xs font-semibold">{{ priorityBadge(task.priority).label }}</span>
                        </h1>
                    </div>
                    <div class="flex gap-2">
                        <button v-if="isActive" @click="openAssignModal" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700">Giao NV</button>
                        <button v-if="isActive && task.type === 'repair'" @click="openPartModal" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700">+ Lắp LK</button>
                        <button v-if="isActive && task.type === 'repair'" @click="openDisassembleModal" class="px-4 py-2 bg-orange-500 text-white rounded-lg text-sm font-semibold hover:bg-orange-600">↑ Bóc LK</button>
                        <button v-if="isActive" @click="markComplete" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-semibold hover:bg-green-700">Hoàn thành</button>
                        <button v-if="isActive" @click="cancelTask" class="px-4 py-2 bg-red-50 text-red-600 rounded-lg text-sm font-semibold hover:bg-red-100 border border-red-200">Hủy</button>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="flex gap-1 mb-4 border-b">
                    <button @click="activeTab = 'info'" :class="activeTab === 'info' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-gray-500'" class="px-4 py-2 text-sm font-semibold">Thông tin</button>
                    <button v-if="task.type === 'repair'" @click="activeTab = 'parts'" :class="activeTab === 'parts' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-gray-500'" class="px-4 py-2 text-sm font-semibold">
                        Linh kiện <span class="text-xs text-gray-400">({{ task.parts?.length || 0 }})</span>
                    </button>
                    <button @click="activeTab = 'comments'" :class="activeTab === 'comments' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-gray-500'" class="px-4 py-2 text-sm font-semibold">
                        Bình luận <span class="text-xs text-gray-400">({{ task.comments?.length || 0 }})</span>
                    </button>
                </div>

                <!-- TAB: Info -->
                <div v-if="activeTab === 'info'" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Main info -->
                    <div class="bg-white border rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-500 uppercase mb-3">Thông tin</h3>
                        <div class="space-y-2 text-sm">
                            <div v-if="task.type === 'repair'" class="flex justify-between">
                                <span class="text-gray-500">Sản phẩm:</span>
                                <span class="font-semibold">{{ task.product?.name || '-' }}</span>
                            </div>
                            <div v-if="task.type === 'repair'" class="flex justify-between">
                                <span class="text-gray-500">Serial:</span>
                                <span class="font-semibold">{{ task.serial_imei?.serial_number || '-' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Danh mục:</span>
                                <span v-if="task.category" class="font-semibold">
                                    <span class="inline-block w-2 h-2 rounded-full mr-1" :style="{ backgroundColor: task.category.color }"></span>
                                    {{ task.category.name }}
                                </span>
                                <span v-else class="text-gray-400">-</span>
                            </div>
                            <div>
                                <span class="text-gray-500">{{ task.type === 'repair' ? 'Mô tả lỗi' : 'Mô tả' }}:</span>
                                <p class="mt-1 text-gray-800">{{ task.issue_description || '-' }}</p>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-500">Deadline:</span>
                                <div class="flex items-center gap-2">
                                    <template v-if="showDeadlineEdit">
                                        <input v-model="editDeadline" type="date" class="border border-gray-300 rounded px-2 py-1 text-sm" />
                                        <button @click="saveDeadline" class="text-blue-600 text-xs font-semibold hover:underline">Lưu</button>
                                        <button @click="showDeadlineEdit = false" class="text-gray-400 text-xs hover:underline">Hủy</button>
                                    </template>
                                    <template v-else>
                                        <span :class="isActive && task.deadline && new Date(task.deadline) < new Date() ? 'text-red-600 font-bold' : 'font-semibold'">{{ task.deadline || 'Chưa đặt' }}</span>
                                        <button v-if="isActive" @click="startEditDeadline" class="text-blue-500 text-xs hover:underline">Sửa</button>
                                    </template>
                                </div>
                            </div>
                            <div class="flex justify-between"><span class="text-gray-500">Chi nhánh:</span><span>{{ task.branch?.name || '-' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Người tạo:</span><span>{{ task.creator?.name || '-' }}</span></div>
                            <div v-if="task.notes" class="mt-2">
                                <span class="text-gray-500">Ghi chú:</span>
                                <p class="mt-1 text-gray-700">{{ task.notes }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Cost (repair only) / Progress -->
                    <div class="bg-white border rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-500 uppercase mb-3">{{ task.type === 'repair' ? 'Chi phí' : 'Tiến độ' }}</h3>
                        <div v-if="task.type === 'repair'" class="space-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-gray-500">Giá gốc:</span><span>{{ formatCurrency(task.original_cost) }}đ</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Linh kiện:</span><span>{{ formatCurrency(task.parts_cost) }}đ</span></div>
                            <div class="flex justify-between text-base font-bold border-t pt-2 mt-2">
                                <span>Tổng giá vốn:</span><span class="text-blue-600">{{ formatCurrency(task.total_cost) }}đ</span>
                            </div>
                        </div>
                        <!-- Progress -->
                        <div class="mt-4">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm text-gray-500">Tiến độ</span>
                                <span class="text-sm font-bold">{{ task.progress || 0 }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-3">
                                <div class="h-3 rounded-full bg-blue-500 transition-all" :style="{ width: (task.progress || 0) + '%' }"></div>
                            </div>
                            <div v-if="isActive" class="mt-3">
                                <template v-if="editingProgress">
                                    <input type="range" v-model.number="progressValue" min="0" max="100" class="w-full" />
                                    <div class="flex justify-end gap-2 mt-1">
                                        <button @click="editingProgress = false" class="text-xs text-gray-400 hover:underline">Hủy</button>
                                        <button @click="saveProgress" class="text-xs text-blue-600 font-semibold hover:underline">Lưu ({{ progressValue }}%)</button>
                                    </div>
                                </template>
                                <button v-else @click="editingProgress = true; progressValue = task.progress || 0" class="text-xs text-blue-500 hover:underline">Cập nhật tiến độ</button>
                            </div>
                        </div>
                    </div>

                    <!-- Assigned employees -->
                    <div class="bg-white border rounded-lg p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold text-gray-500 uppercase">Nhân viên phụ trách</h3>
                            <button v-if="isActive" @click="openAssignModal" class="text-xs text-indigo-600 font-semibold hover:underline">+ Thêm</button>
                        </div>
                        <div v-if="!task.assignments?.length" class="text-sm text-gray-400">Chưa giao ai.</div>
                        <div v-else class="space-y-2">
                            <div v-for="a in task.assignments" :key="a.id" class="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2">
                                <div>
                                    <span class="text-sm font-semibold">{{ a.employee?.name }}</span>
                                    <div class="text-xs text-gray-400">Giao bởi {{ a.assigner?.name || '-' }}</div>
                                </div>
                                <span :class="assignmentStatusBadge(a.status).cls" class="px-2 py-0.5 rounded-full text-xs font-semibold">{{ assignmentStatusBadge(a.status).label }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB: Parts (repair only) -->
                <div v-if="activeTab === 'parts' && task.type === 'repair'" class="bg-white border rounded-lg shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-800">Linh kiện</h3>
                        <div class="flex gap-2 items-center">
                            <span class="text-sm text-gray-500">{{ task.parts?.length || 0 }} mục</span>
                            <button v-if="isActive" @click="openPartModal" class="text-xs text-blue-600 font-semibold hover:underline">+ Lắp LK</button>
                            <button v-if="isActive" @click="openDisassembleModal" class="text-xs text-orange-600 font-semibold hover:underline">↑ Bóc LK</button>
                        </div>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                            <tr>
                                <th class="px-4 py-3 text-left">Sản phẩm</th>
                                <th class="px-4 py-3 text-center">Loại</th>
                                <th class="px-4 py-3 text-center">SL</th>
                                <th class="px-4 py-3 text-right">Đơn giá vốn</th>
                                <th class="px-4 py-3 text-right">Thành tiền</th>
                                <th class="px-4 py-3 text-left">Ghi chú</th>
                                <th class="px-4 py-3 text-center" v-if="isActive"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!task.parts?.length"><td colspan="7" class="text-center py-6 text-gray-400">Chưa có linh kiện.</td></tr>
                            <tr v-for="part in task.parts" :key="part.id" class="border-t" :class="(part.direction || 'export') === 'import' ? 'bg-orange-50/50' : ''">
                                <td class="px-4 py-3">{{ part.product?.name || '-' }}</td>
                                <td class="px-4 py-3 text-center">
                                    <span v-if="(part.direction || 'export') === 'import'" class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-orange-100 text-orange-700">Bóc ra</span>
                                    <span v-else class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-100 text-blue-700">Lắp vào</span>
                                </td>
                                <td class="px-4 py-3 text-center">{{ part.quantity }}</td>
                                <td class="px-4 py-3 text-right">{{ formatCurrency(part.unit_cost) }}</td>
                                <td class="px-4 py-3 text-right font-semibold" :class="(part.direction || 'export') === 'import' ? 'text-orange-600' : ''">
                                    {{ (part.direction || 'export') === 'import' ? '-' : '' }}{{ formatCurrency(part.total_cost) }}
                                </td>
                                <td class="px-4 py-3 text-gray-500">{{ part.notes || '' }}</td>
                                <td class="px-4 py-3 text-center" v-if="isActive">
                                    <button @click="removePart(part.id)" class="text-red-500 hover:text-red-700 text-xs font-semibold">Gỡ</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <!-- Summary -->
                    <div class="px-4 py-3 border-t bg-gray-50 flex justify-end gap-6 text-sm">
                        <span class="text-gray-500">Chi phí LK ròng: <strong class="text-gray-800">{{ formatCurrency(task.parts_cost) }}</strong></span>
                        <span class="text-gray-500">Tổng giá vốn: <strong class="text-gray-800">{{ formatCurrency(task.total_cost) }}</strong></span>
                    </div>
                </div>

                <!-- TAB: Comments -->
                <div v-if="activeTab === 'comments'">
                    <!-- Comment form -->
                    <div v-if="isActive" class="bg-white border rounded-lg p-4 mb-4">
                        <textarea v-model="commentBody" rows="3" placeholder="Viết bình luận..." class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none"></textarea>
                        <div class="flex justify-end mt-2">
                            <button @click="submitComment" :disabled="!commentBody.trim() || commentLoading" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 disabled:opacity-50">
                                {{ commentLoading ? 'Đang gửi...' : 'Gửi bình luận' }}
                            </button>
                        </div>
                    </div>
                    <!-- Comment list -->
                    <div v-if="!task.comments?.length" class="text-center py-8 text-gray-400">Chưa có bình luận.</div>
                    <div v-else class="space-y-3">
                        <div v-for="c in task.comments" :key="c.id" class="bg-white border rounded-lg p-4">
                            <div class="flex items-center gap-2 mb-2">
                                <div class="w-7 h-7 bg-blue-500 text-white rounded-full flex items-center justify-center text-xs font-bold">{{ c.user?.name?.charAt(0)?.toUpperCase() || '?' }}</div>
                                <span class="font-semibold text-sm">{{ c.user?.name || 'Ai đó' }}</span>
                                <span class="text-xs text-gray-400">{{ new Date(c.created_at).toLocaleString('vi-VN') }}</span>
                            </div>
                            <p class="text-sm text-gray-800 whitespace-pre-wrap">{{ c.body }}</p>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Add Part Modal (Multi-line) -->
        <div v-if="showPartModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl mx-4">
                <div class="flex items-center justify-between px-6 py-4 border-b">
                    <h2 class="text-lg font-bold">Lắp linh kiện vào máy</h2>
                    <button @click="showPartModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <div class="px-6 py-4">
                    <div v-if="partError" class="text-red-500 text-sm bg-red-50 px-3 py-2 rounded mb-3 whitespace-pre-wrap">{{ partError }}</div>
                    <!-- Items table -->
                    <table class="w-full text-sm mb-3">
                        <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                            <tr>
                                <th class="px-3 py-2 text-left w-2/5">Sản phẩm</th>
                                <th class="px-3 py-2 text-center w-16">SL</th>
                                <th class="px-3 py-2 text-right">Giá vốn</th>
                                <th class="px-3 py-2 text-right">Tồn kho</th>
                                <th class="px-3 py-2 text-left">Ghi chú</th>
                                <th class="px-3 py-2 w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, idx) in partItems" :key="idx" class="border-t">
                                <td class="px-3 py-2">
                                    <div v-if="item.product_id" class="flex items-center gap-2">
                                        <span class="font-medium">{{ item.product_name }}</span>
                                        <button @click="item.product_id = null; item.product_name = ''" class="text-gray-400 hover:text-red-500 text-xs">✕</button>
                                    </div>
                                    <div v-else class="relative">
                                        <input v-model="partSearchQuery" @input="onPartSearch(idx)" @focus="onPartSearch(idx)" type="text" placeholder="Tìm sản phẩm..." class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm focus:border-blue-500 outline-none" />
                                        <div v-if="partSearchIdx === idx && partSearchResults.length" class="absolute z-20 w-full bg-white border rounded-lg shadow-lg mt-1 max-h-40 overflow-auto">
                                            <div v-for="p in partSearchResults" :key="p.id" @click="selectPartProduct(p, idx)" class="px-3 py-2 hover:bg-blue-50 cursor-pointer text-sm flex justify-between">
                                                <span>{{ p.name }}</span><span class="text-gray-400">{{ formatCurrency(p.cost_price) }}đ</span>
                                            </div>
                                        </div>
                                        <div v-if="partSearchIdx === idx && partSearchQuery.length >= 2 && !partSearchResults.length" class="absolute z-20 w-full bg-white border rounded-lg shadow-lg mt-1 px-3 py-2 text-sm text-gray-500">
                                            Không tìm thấy. <button @click="openQuickCreate('add')" class="text-blue-600 font-semibold hover:underline">+ Tạo SP mới</button>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <input v-model.number="item.quantity" type="number" min="1" class="w-14 border border-gray-300 rounded px-2 py-1 text-sm text-center" />
                                </td>
                                <td class="px-3 py-2 text-right text-gray-600">{{ formatCurrency(item.cost_price) }}</td>
                                <td class="px-3 py-2 text-right text-gray-500">{{ item.stock_quantity }}</td>
                                <td class="px-3 py-2">
                                    <input v-model="item.notes" type="text" placeholder="Ghi chú..." class="w-full border border-gray-200 rounded px-2 py-1 text-sm" />
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <button v-if="partItems.length > 1" @click="partItems.splice(idx, 1)" class="text-red-400 hover:text-red-600">✕</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="flex gap-2">
                        <button @click="partItems.push(newPartRow())" class="text-sm text-blue-600 font-semibold hover:underline">+ Thêm dòng</button>
                        <button @click="openQuickCreate('add')" class="text-sm text-green-600 font-semibold hover:underline">+ Tạo SP mới</button>
                    </div>
                </div>
                <div class="flex justify-end gap-3 px-6 py-4 border-t">
                    <button @click="showPartModal = false" class="px-5 py-2 border rounded-lg text-sm font-semibold">Hủy</button>
                    <button @click="submitAddParts" :disabled="!partItems.some(i => i.product_id && i.quantity > 0)" class="px-5 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 disabled:opacity-50">
                        Xuất {{ partItems.filter(i => i.product_id).length }} linh kiện
                    </button>
                </div>
            </div>
        </div>

        <!-- Disassemble Part Modal (Multi-line, Bóc máy) -->
        <div v-if="showDisassembleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl mx-4">
                <div class="flex items-center justify-between px-6 py-4 border-b bg-orange-50">
                    <h2 class="text-lg font-bold text-orange-700">↑ Bóc linh kiện từ máy</h2>
                    <button @click="showDisassembleModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <div class="px-6 py-4">
                    <div v-if="disError" class="text-red-500 text-sm bg-red-50 px-3 py-2 rounded mb-3 whitespace-pre-wrap">{{ disError }}</div>
                    <p class="text-xs text-gray-500 bg-gray-50 px-3 py-2 rounded mb-3">Linh kiện bóc ra sẽ được <strong>nhập vào tồn kho</strong>. Giá vốn máy sẽ giảm tương ứng.</p>
                    <!-- Items table -->
                    <table class="w-full text-sm mb-3">
                        <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                            <tr>
                                <th class="px-3 py-2 text-left w-2/5">Sản phẩm</th>
                                <th class="px-3 py-2 text-center w-14">SL</th>
                                <th class="px-3 py-2 text-right">Đơn giá</th>
                                <th class="px-3 py-2 text-right">Thành tiền</th>
                                <th class="px-3 py-2 text-left">Ghi chú</th>
                                <th class="px-3 py-2 w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, idx) in disItems" :key="idx" class="border-t">
                                <td class="px-3 py-2">
                                    <div v-if="item.product_id" class="flex items-center gap-2">
                                        <span class="font-medium">{{ item.product_name }}</span>
                                        <button @click="item.product_id = null; item.product_name = ''" class="text-gray-400 hover:text-red-500 text-xs">✕</button>
                                    </div>
                                    <div v-else class="relative">
                                        <input v-model="disSearchQuery" @input="onDisSearch(idx)" @focus="onDisSearch(idx)" type="text" placeholder="Tìm sản phẩm..." class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm focus:border-orange-500 outline-none" />
                                        <div v-if="disSearchIdx === idx && disSearchResults.length" class="absolute z-20 w-full bg-white border rounded-lg shadow-lg mt-1 max-h-40 overflow-auto">
                                            <div v-for="p in disSearchResults" :key="p.id" @click="selectDisProduct(p, idx)" class="px-3 py-2 hover:bg-orange-50 cursor-pointer text-sm flex justify-between">
                                                <span>{{ p.name }}</span><span class="text-gray-400">GV: {{ formatCurrency(p.cost_price) }}đ</span>
                                            </div>
                                        </div>
                                        <div v-if="disSearchIdx === idx && disSearchQuery.length >= 2 && !disSearchResults.length" class="absolute z-20 w-full bg-white border rounded-lg shadow-lg mt-1 px-3 py-2 text-sm text-gray-500">
                                            Không tìm thấy. <button @click="openQuickCreate('dis')" class="text-orange-600 font-semibold hover:underline">+ Tạo SP mới</button>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <input v-model.number="item.quantity" type="number" min="1" class="w-14 border border-gray-300 rounded px-2 py-1 text-sm text-center" />
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <input v-model.number="item.unit_cost" type="number" min="0" class="w-24 border border-gray-300 rounded px-2 py-1 text-sm text-right" />
                                </td>
                                <td class="px-3 py-2 text-right font-semibold text-orange-600">{{ formatCurrency(item.unit_cost * item.quantity) }}</td>
                                <td class="px-3 py-2">
                                    <input v-model="item.notes" type="text" placeholder="Ghi chú..." class="w-full border border-gray-200 rounded px-2 py-1 text-sm" />
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <button v-if="disItems.length > 1" @click="disItems.splice(idx, 1)" class="text-red-400 hover:text-red-600">✕</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="flex gap-2">
                        <button @click="disItems.push(newDisRow())" class="text-sm text-orange-600 font-semibold hover:underline">+ Thêm dòng</button>
                        <button @click="openQuickCreate('dis')" class="text-sm text-green-600 font-semibold hover:underline">+ Tạo SP mới</button>
                    </div>
                </div>
                <div class="flex justify-between items-center px-6 py-4 border-t">
                    <div class="text-sm text-gray-500">
                        Tổng bóc ra: <strong class="text-orange-700">{{ formatCurrency(disItems.reduce((s, i) => s + (i.unit_cost || 0) * (i.quantity || 0), 0)) }}đ</strong>
                    </div>
                    <div class="flex gap-3">
                        <button @click="showDisassembleModal = false" class="px-5 py-2 border rounded-lg text-sm font-semibold">Hủy</button>
                        <button @click="submitDisassembleParts" :disabled="!disItems.some(i => i.product_id && i.quantity > 0)" class="px-5 py-2 bg-orange-500 text-white rounded-lg text-sm font-semibold hover:bg-orange-600 disabled:opacity-50">
                            Bóc {{ disItems.filter(i => i.product_id).length }} linh kiện
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Create Product Popup -->
        <div v-if="showQuickCreate" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4">
                <div class="flex items-center justify-between px-6 py-4 border-b bg-green-50">
                    <h2 class="text-lg font-bold text-green-700">Tạo sản phẩm mới</h2>
                    <button @click="showQuickCreate = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div v-if="quickCreateError" class="text-red-500 text-sm bg-red-50 px-3 py-2 rounded">{{ quickCreateError }}</div>
                    <p class="text-xs text-gray-500 bg-gray-50 px-3 py-2 rounded">Sản phẩm mới sẽ được tạo trong danh mục <strong>Hàng hóa</strong> và tự động thêm vào dòng hiện tại.</p>
                    <div>
                        <label class="block font-semibold text-sm mb-1">Tên sản phẩm *</label>
                        <input v-model="quickProduct.name" type="text" placeholder="VD: RAM DDR4 16GB 3200MHz" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-green-500 outline-none" />
                    </div>
                    <div>
                        <label class="block font-semibold text-sm mb-1">Giá vốn</label>
                        <input v-model.number="quickProduct.cost_price" type="number" min="0" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                    </div>
                </div>
                <div class="flex justify-end gap-3 px-6 py-4 border-t">
                    <button @click="showQuickCreate = false" class="px-5 py-2 border rounded-lg text-sm font-semibold">Hủy</button>
                    <button @click="submitQuickProduct" :disabled="!quickProduct.name.trim()" class="px-5 py-2 bg-green-600 text-white rounded-lg text-sm font-semibold hover:bg-green-700 disabled:opacity-50">Tạo & Thêm</button>
                </div>
            </div>
        </div>

        <!-- Assign Modal -->
        <div v-if="showAssignModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4">
                <div class="flex items-center justify-between px-6 py-4 border-b">
                    <h2 class="text-lg font-bold">Giao nhân viên</h2>
                    <button @click="showAssignModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <div class="px-6 py-5">
                    <div v-if="assignError" class="text-red-500 text-sm bg-red-50 px-3 py-2 rounded mb-3">{{ assignError }}</div>
                    <p class="text-sm text-gray-500 mb-3">Chọn nhân viên (có thể chọn nhiều)</p>
                    <div class="space-y-1 max-h-64 overflow-y-auto">
                        <label v-for="e in employees" :key="e.id" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-50 cursor-pointer">
                            <input type="checkbox" :checked="assignEmployeeIds.includes(e.id)" @change="toggleAssignEmployee(e.id)" class="accent-blue-600" />
                            <span class="text-sm">{{ e.name }}</span>
                        </label>
                    </div>
                </div>
                <div class="flex justify-end gap-3 px-6 py-4 border-t">
                    <button @click="showAssignModal = false" class="px-5 py-2 border rounded-lg text-sm font-semibold">Hủy</button>
                    <button @click="submitAssign" :disabled="!assignEmployeeIds.length" class="px-5 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 disabled:opacity-50">Giao ({{ assignEmployeeIds.length }})</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
