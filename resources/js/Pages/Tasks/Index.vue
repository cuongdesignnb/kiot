<script setup>
import { ref, watch, computed } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import axios from "axios";

const props = defineProps({
    branches: Array,
    employees: Array,
    categories: Array,
});

const tasks = ref({ data: [], total: 0 });
const loading = ref(false);
const viewMode = ref("list"); // list | kanban
const filters = ref({
    search: "",
    type: "",
    status: "",
    priority: "",
    category_id: "",
    assigned_employee_id: "",
    branch_id: "",
    per_page: 20,
    page: 1,
});

// ── Create modal ──
const showCreateModal = ref(false);
const createType = ref("general");
const createForm = ref({
    title: "",
    description: "",
    serial_imei_id: null,
    issue_description: "",
    category_id: null,
    priority: "normal",
    branch_id: null,
    deadline: "",
    notes: "",
    employee_ids: [],
});
const createError = ref("");
const serialSearch = ref("");
const serialResults = ref([]);
const selectedSerial = ref(null);
const batchMode = ref(false);
const productSearch = ref("");
const productResults = ref([]);
const selectedProduct = ref(null);
const productSerials = ref([]);
const selectedSerialIds = ref([]); // serials được tick trong batch mode

// ── Assign modal ──
const showAssignModal = ref(false);
const assignTaskId = ref(null);
const assignEmployeeIds = ref([]);
const assignError = ref("");

// ── Load tasks ──
const loadTasks = async () => {
    loading.value = true;
    try {
        const params = {};
        Object.entries(filters.value).forEach(([k, v]) => {
            if (v !== "" && v !== null) params[k] = v;
        });
        const res = await axios.get("/api/tasks", { params });
        tasks.value = res.data;
    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
};

let searchTimeout;
watch(() => filters.value.search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => { filters.value.page = 1; loadTasks(); }, 400);
});
watch(() => [filters.value.type, filters.value.status, filters.value.priority, filters.value.category_id, filters.value.assigned_employee_id, filters.value.branch_id], () => {
    filters.value.page = 1;
    loadTasks();
});

// ── Serial search (for repair) ──
let serialTimeout;
watch(serialSearch, (val) => {
    clearTimeout(serialTimeout);
    if (!val || val.length < 2) { serialResults.value = []; return; }
    serialTimeout = setTimeout(async () => {
        try {
            const res = await axios.get("/api/tasks/search-serials", { params: { q: val } });
            serialResults.value = res.data || [];
        } catch (e) { serialResults.value = []; }
    }, 300);
});

const selectSerial = (serial) => {
    selectedSerial.value = serial;
    createForm.value.serial_imei_id = serial.id;
    serialSearch.value = serial.serial_number;
    serialResults.value = [];
};

// ── Product search (for batch repair) ──
let productTimeout;
watch(productSearch, (val) => {
    clearTimeout(productTimeout);
    if (!val || val.length < 2) { productResults.value = []; return; }
    productTimeout = setTimeout(async () => {
        try {
            const res = await axios.get("/api/tasks/search-products", { params: { q: val } });
            productResults.value = res.data || [];
        } catch (e) { productResults.value = []; }
    }, 300);
});

const selectProduct = async (product) => {
    selectedProduct.value = product;
    productSearch.value = product.sku + ' - ' + product.name;
    productResults.value = [];
    selectedSerialIds.value = [];
    try {
        const res = await axios.get("/api/tasks/product-serials", { params: { product_id: product.id } });
        productSerials.value = res.data || [];
        // Nếu không có serial → chọn tất cả (tức là product-level)
        if (productSerials.value.length === 0) selectedSerialIds.value = [];
    } catch (e) { productSerials.value = []; }
};

const toggleAllSerials = (e) => {
    selectedSerialIds.value = e.target.checked
        ? productSerials.value.map(s => s.id)
        : [];
};

// ── Create ──
const openCreateModal = (type = "general") => {
    createType.value = type;
    createError.value = "";
    selectedSerial.value = null;
    serialSearch.value = "";
    batchMode.value = false;
    selectedProduct.value = null;
    productSearch.value = "";
    productSerials.value = [];
    selectedSerialIds.value = [];
    createForm.value = { title: "", description: "", serial_imei_id: null, issue_description: "", category_id: null, priority: "normal", branch_id: null, deadline: "", notes: "", employee_ids: [] };
    showCreateModal.value = true;
};

const submitCreate = async () => {
    createError.value = "";
    try {
        // Batch mode: create repair tasks for SELECTED serials
        if (createType.value === "repair" && batchMode.value && selectedSerialIds.value.length > 0) {
            const payload = {
                serial_imei_ids: selectedSerialIds.value,
                issue_description: createForm.value.issue_description,
                title: createForm.value.title,
                category_id: createForm.value.category_id,
                priority: createForm.value.priority,
                branch_id: createForm.value.branch_id,
                deadline: createForm.value.deadline || null,
                notes: createForm.value.notes,
                employee_ids: createForm.value.employee_ids,
            };
            await axios.post("/api/tasks/batch-repair", payload);
            showCreateModal.value = false;
            loadTasks();
            return;
        }

        const payload = { type: createType.value };
        if (createType.value === "repair") {
            payload.serial_imei_id = createForm.value.serial_imei_id;
            payload.issue_description = createForm.value.issue_description;
            payload.title = createForm.value.title;
        } else {
            payload.title = createForm.value.title;
            payload.description = createForm.value.description;
        }
        payload.category_id = createForm.value.category_id;
        payload.priority = createForm.value.priority;
        payload.branch_id = createForm.value.branch_id;
        payload.deadline = createForm.value.deadline || null;
        payload.notes = createForm.value.notes;

        const res = await axios.post("/api/tasks", payload);
        // Auto-assign employees if selected
        if (createForm.value.employee_ids.length > 0 && res.data?.id) {
            try {
                await axios.post(`/api/tasks/${res.data.id}/assign`, { employee_ids: createForm.value.employee_ids });
            } catch (assignErr) {
                console.warn('Auto-assign failed:', assignErr);
            }
        }
        showCreateModal.value = false;
        loadTasks();
    } catch (e) {
        createError.value = e.response?.data?.message || Object.values(e.response?.data?.errors || {}).flat().join(", ") || "Lỗi khi tạo.";
    }
};

// ── Assign ──
const openAssignModal = (taskId) => {
    assignTaskId.value = taskId;
    assignEmployeeIds.value = [];
    assignError.value = "";
    showAssignModal.value = true;
};

const submitAssign = async () => {
    assignError.value = "";
    if (!assignEmployeeIds.value.length) { assignError.value = "Chọn ít nhất 1 nhân viên."; return; }
    try {
        await axios.post(`/api/tasks/${assignTaskId.value}/assign`, { employee_ids: assignEmployeeIds.value });
        showAssignModal.value = false;
        loadTasks();
    } catch (e) {
        assignError.value = e.response?.data?.message || "Lỗi.";
    }
};

const toggleAssignEmployee = (empId) => {
    const idx = assignEmployeeIds.value.indexOf(empId);
    if (idx >= 0) assignEmployeeIds.value.splice(idx, 1);
    else assignEmployeeIds.value.push(empId);
};

// ── Helpers ──
const goPage = (page) => { filters.value.page = page; loadTasks(); };
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

const typeBadge = (type) => {
    return type === "repair"
        ? { label: "Sửa chữa", cls: "bg-purple-100 text-purple-700" }
        : { label: "Công việc", cls: "bg-teal-100 text-teal-700" };
};

// ── Kanban data ──
const kanbanColumns = computed(() => {
    const cols = [
        { status: "pending", label: "Chờ xử lý", color: "border-yellow-400", items: [] },
        { status: "in_progress", label: "Đang thực hiện", color: "border-blue-400", items: [] },
        { status: "completed", label: "Hoàn thành", color: "border-green-400", items: [] },
        { status: "cancelled", label: "Đã hủy", color: "border-red-400", items: [] },
    ];
    (tasks.value.data || []).forEach((t) => {
        const col = cols.find((c) => c.status === t.status);
        if (col) col.items.push(t);
    });
    return cols;
});

const onKanbanDrop = async (taskId, newStatus) => {
    try {
        if (newStatus === "completed") {
            await axios.post(`/api/tasks/${taskId}/complete`);
        } else {
            await axios.put(`/api/tasks/${taskId}`, {}); // status change handled via dedicated endpoints in future
        }
        loadTasks();
    } catch (e) {
        console.error(e);
    }
};

// Drag & drop helpers
const dragTask = ref(null);
const onDragStart = (task) => { dragTask.value = task; };
const onDrop = async (status) => {
    if (!dragTask.value || dragTask.value.status === status) return;
    try {
        if (status === "completed") {
            await axios.post(`/api/tasks/${dragTask.value.id}/complete`);
        } else if (status === "cancelled") {
            await axios.delete(`/api/tasks/${dragTask.value.id}`);
        } else {
            // For other status changes, use progress/update
            await axios.put(`/api/tasks/${dragTask.value.id}`, {});
        }
        loadTasks();
    } catch (e) {
        console.error(e);
    }
    dragTask.value = null;
};

const filteredCategories = computed(() => {
    if (!createType.value) return props.categories;
    return (props.categories || []).filter(c => c.type === createType.value || c.type === 'general');
});

const adminComplete = async (taskId) => {
    if (!confirm("Xác nhận hoàn thành công việc này?")) return;
    try {
        await axios.post(`/api/tasks/${taskId}/complete`);
        loadTasks();
    } catch (e) {
        alert(e.response?.data?.message || "Lỗi khi hoàn thành.");
    }
};

loadTasks();
</script>

<template>
    <Head title="Công việc" />
    <AppLayout>
        <div class="p-6">
            <!-- Header -->
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-xl font-bold text-gray-800">Công việc</h1>
                <div class="flex gap-2">
                    <!-- View mode toggle -->
                    <div class="flex bg-gray-100 rounded-lg p-0.5">
                        <button @click="viewMode = 'list'" :class="viewMode === 'list' ? 'bg-white shadow text-blue-600' : 'text-gray-500'" class="px-3 py-1.5 text-sm font-semibold rounded-md transition">
                            <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg>
                            Danh sách
                        </button>
                        <button @click="viewMode = 'kanban'" :class="viewMode === 'kanban' ? 'bg-white shadow text-blue-600' : 'text-gray-500'" class="px-3 py-1.5 text-sm font-semibold rounded-md transition">
                            <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7"></path></svg>
                            Kanban
                        </button>
                    </div>
                    <div class="relative group">
                        <button class="px-4 py-2 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition">+ Tạo mới</button>
                        <div class="absolute right-0 mt-1 w-48 bg-white rounded-lg shadow-lg border opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-50">
                            <button @click="openCreateModal('general')" class="block w-full text-left px-4 py-2 text-sm hover:bg-gray-50">Công việc chung</button>
                            <button @click="openCreateModal('repair')" class="block w-full text-left px-4 py-2 text-sm hover:bg-gray-50">Phiếu sửa chữa</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap gap-3 mb-4">
                <input v-model="filters.search" type="text" placeholder="Tìm mã, tiêu đề, serial, sản phẩm..." class="border border-gray-300 rounded-lg px-3 py-2 w-64 text-sm focus:border-blue-500 outline-none" />
                <select v-model="filters.type" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">Tất cả loại</option>
                    <option value="general">Công việc chung</option>
                    <option value="repair">Sửa chữa</option>
                </select>
                <select v-model="filters.status" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">Tất cả trạng thái</option>
                    <option value="pending">Chờ xử lý</option>
                    <option value="in_progress">Đang thực hiện</option>
                    <option value="completed">Hoàn thành</option>
                    <option value="cancelled">Đã hủy</option>
                </select>
                <select v-model="filters.priority" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">Tất cả ưu tiên</option>
                    <option value="urgent">Khẩn cấp</option>
                    <option value="high">Cao</option>
                    <option value="normal">Bình thường</option>
                    <option value="low">Thấp</option>
                </select>
                <select v-model="filters.category_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">Tất cả danh mục</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <select v-model="filters.assigned_employee_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">Tất cả NV</option>
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                </select>
                <select v-model="filters.branch_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">Tất cả chi nhánh</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>

            <!-- LIST VIEW -->
            <div v-if="viewMode === 'list'" class="bg-white border rounded-lg shadow-sm overflow-hidden">
                <div v-if="loading" class="text-center py-10 text-gray-400">Đang tải...</div>
                <table v-else class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-3 text-left">Mã</th>
                            <th class="px-4 py-3 text-left">Tiêu đề</th>
                            <th class="px-4 py-3 text-center">Loại</th>
                            <th class="px-4 py-3 text-center">Ưu tiên</th>
                            <th class="px-4 py-3 text-left">NV phụ trách</th>
                            <th class="px-4 py-3 text-center">Trạng thái</th>
                            <th class="px-4 py-3 text-center">Tiến độ</th>
                            <th class="px-4 py-3 text-center">Deadline</th>
                            <th class="px-4 py-3 text-center"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!tasks.data?.length">
                            <td colspan="9" class="text-center py-8 text-gray-400">Chưa có công việc nào.</td>
                        </tr>
                        <tr v-for="t in tasks.data" :key="t.id" class="border-t hover:bg-gray-50 cursor-pointer" @click="router.visit(`/tasks/${t.id}`)">
                            <td class="px-4 py-3 font-semibold text-blue-600">{{ t.code }}</td>
                            <td class="px-4 py-3">
                                <div>{{ t.title || t.code }}</div>
                                <div v-if="t.category" class="text-xs mt-0.5">
                                    <span class="inline-block w-2 h-2 rounded-full mr-1" :style="{ backgroundColor: t.category.color }"></span>
                                    {{ t.category.name }}
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span :class="typeBadge(t.type).cls" class="px-2 py-0.5 rounded-full text-xs font-semibold">{{ typeBadge(t.type).label }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span :class="priorityBadge(t.priority).cls" class="px-2 py-0.5 rounded-full text-xs font-semibold">{{ priorityBadge(t.priority).label }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <template v-if="t.assignments?.length">
                                    <span v-for="(a, i) in t.assignments" :key="a.id">{{ a.employee?.name }}<span v-if="i < t.assignments.length - 1">, </span></span>
                                </template>
                                <span v-else-if="t.assigned_employee" class="text-gray-600">{{ t.assigned_employee.name }}</span>
                                <span v-else class="text-gray-400">-</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span :class="statusBadge(t.status).cls" class="px-2 py-0.5 rounded-full text-xs font-semibold">{{ statusBadge(t.status).label }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex items-center justify-center gap-1">
                                    <div class="w-16 bg-gray-200 rounded-full h-1.5">
                                        <div class="h-1.5 rounded-full bg-blue-500" :style="{ width: (t.progress || 0) + '%' }"></div>
                                    </div>
                                    <span class="text-xs text-gray-500">{{ t.progress || 0 }}%</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span v-if="t.deadline" :class="t.status !== 'completed' && new Date(t.deadline) < new Date() ? 'text-red-600 font-bold' : 'text-gray-600'">{{ t.deadline }}</span>
                                <span v-else class="text-gray-300">-</span>
                            </td>
                            <td class="px-4 py-3 text-center" @click.stop>
                                <div class="flex items-center justify-center gap-2">
                                    <button v-if="t.status !== 'completed' && t.status !== 'cancelled'" @click.stop="openAssignModal(t.id)" class="text-indigo-600 hover:text-indigo-800 text-xs font-semibold">Giao NV</button>
                                    <button v-if="t.status !== 'completed' && t.status !== 'cancelled'" @click.stop="adminComplete(t.id)" class="text-green-600 hover:text-green-800 text-xs font-semibold">Hoàn thành</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="tasks.last_page > 1" class="flex items-center justify-between px-4 py-3 border-t text-sm">
                    <span class="text-gray-500">Tổng: {{ tasks.total }} công việc</span>
                    <div class="flex gap-1">
                        <button v-for="p in tasks.last_page" :key="p" @click="goPage(p)" class="px-3 py-1 rounded" :class="p === tasks.current_page ? 'bg-blue-600 text-white' : 'bg-gray-100 hover:bg-gray-200'">{{ p }}</button>
                    </div>
                </div>
            </div>

            <!-- KANBAN VIEW -->
            <div v-if="viewMode === 'kanban'" class="flex gap-4 overflow-x-auto pb-4">
                <div v-if="loading" class="text-center py-10 text-gray-400 w-full">Đang tải...</div>
                <div
                    v-else
                    v-for="col in kanbanColumns"
                    :key="col.status"
                    class="flex-shrink-0 w-72 bg-gray-50 rounded-lg border-t-4"
                    :class="col.color"
                    @dragover.prevent
                    @drop="onDrop(col.status)"
                >
                    <div class="px-3 py-2 flex items-center justify-between">
                        <h3 class="font-bold text-sm text-gray-700">{{ col.label }}</h3>
                        <span class="text-xs text-gray-400 bg-white px-1.5 py-0.5 rounded-full">{{ col.items.length }}</span>
                    </div>
                    <div class="px-2 pb-2 space-y-2 min-h-[100px]">
                        <div
                            v-for="t in col.items"
                            :key="t.id"
                            draggable="true"
                            @dragstart="onDragStart(t)"
                            @click="router.visit(`/tasks/${t.id}`)"
                            class="bg-white rounded-lg border p-3 cursor-pointer hover:shadow-md transition group"
                        >
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-xs font-mono text-blue-600">{{ t.code }}</span>
                                <span :class="typeBadge(t.type).cls" class="px-1.5 py-0.5 rounded text-[10px] font-semibold">{{ typeBadge(t.type).label }}</span>
                            </div>
                            <div class="font-semibold text-sm text-gray-800 mb-2 line-clamp-2">{{ t.title || t.code }}</div>
                            <div class="flex items-center gap-2 text-xs text-gray-400">
                                <span :class="priorityBadge(t.priority).cls" class="px-1.5 py-0.5 rounded text-[10px] font-semibold">{{ priorityBadge(t.priority).label }}</span>
                                <span v-if="t.deadline" :class="t.status !== 'completed' && new Date(t.deadline) < new Date() ? 'text-red-500' : ''">{{ t.deadline }}</span>
                            </div>
                            <div v-if="t.progress > 0" class="mt-2">
                                <div class="w-full bg-gray-200 rounded-full h-1">
                                    <div class="h-1 rounded-full bg-blue-500" :style="{ width: t.progress + '%' }"></div>
                                </div>
                            </div>
                            <div v-if="t.assignments?.length" class="mt-2 flex flex-wrap gap-1">
                                <span v-for="a in t.assignments" :key="a.id" class="text-[10px] bg-gray-100 px-1.5 py-0.5 rounded">{{ a.employee?.name }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between px-6 py-4 border-b sticky top-0 bg-white z-10">
                    <h2 class="text-lg font-bold">{{ createType === 'repair' ? 'Tạo phiếu sửa chữa' : 'Tạo công việc mới' }}</h2>
                    <button @click="showCreateModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div v-if="createError" class="text-red-500 text-sm bg-red-50 px-3 py-2 rounded">{{ createError }}</div>

                    <!-- Type tabs -->
                    <div class="flex bg-gray-100 rounded-lg p-0.5">
                        <button @click="createType = 'general'" :class="createType === 'general' ? 'bg-white shadow' : ''" class="flex-1 py-2 text-sm font-semibold rounded-md">Công việc chung</button>
                        <button @click="createType = 'repair'" :class="createType === 'repair' ? 'bg-white shadow' : ''" class="flex-1 py-2 text-sm font-semibold rounded-md">Sửa chữa</button>
                    </div>

                    <!-- Title (general) -->
                    <div v-if="createType === 'general'">
                        <label class="block font-semibold text-sm mb-1">Tiêu đề *</label>
                        <input v-model="createForm.title" type="text" placeholder="VD: Lắp đặt máy tính cho khách" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" />
                    </div>

                    <!-- Serial search (repair) -->
                    <div v-if="createType === 'repair'">
                        <!-- Toggle: single vs batch -->
                        <div class="flex items-center gap-3 mb-3">
                            <label class="flex items-center gap-2 cursor-pointer text-sm">
                                <input type="radio" :value="false" v-model="batchMode" class="accent-blue-600" />
                                <span>Từng máy (Serial/IMEI)</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer text-sm">
                                <input type="radio" :value="true" v-model="batchMode" class="accent-blue-600" />
                                <span>Theo mã hàng (Batch)</span>
                            </label>
                        </div>

                        <!-- Single mode: search serial -->
                        <div v-if="!batchMode">
                            <label class="block font-semibold text-sm mb-1">Serial/IMEI *</label>
                            <div class="relative">
                                <input v-model="serialSearch" type="text" placeholder="Nhập serial để tìm..." class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" />
                                <div v-if="serialResults.length" class="absolute z-10 w-full bg-white border rounded-lg shadow-lg mt-1 max-h-40 overflow-auto">
                                    <div v-for="s in serialResults" :key="s.id" @click="selectSerial(s)" class="px-3 py-2 hover:bg-blue-50 cursor-pointer text-sm flex justify-between">
                                        <span>{{ s.serial_number }}</span>
                                        <span class="text-gray-400">{{ s.product?.name }}</span>
                                    </div>
                                </div>
                            </div>
                            <div v-if="selectedSerial" class="mt-2 text-sm text-gray-600 bg-gray-50 px-3 py-2 rounded">
                                <strong>{{ selectedSerial.serial_number }}</strong> — {{ selectedSerial.product?.name }} — Giá vốn: {{ formatCurrency(selectedSerial.cost_price || selectedSerial.product?.cost_price) }}đ
                            </div>
                        </div>

                        <!-- Batch mode: search product then pick serials -->
                        <div v-if="batchMode">
                            <label class="block font-semibold text-sm mb-1">Mã hàng / Tên hàng *</label>
                            <div class="relative">
                                <input v-model="productSearch" type="text" placeholder="Nhập mã hàng hoặc tên..." class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" />
                                <div v-if="productResults.length" class="absolute z-10 w-full bg-white border rounded-lg shadow-lg mt-1 max-h-40 overflow-auto">
                                    <div v-for="p in productResults" :key="p.id" @click="selectProduct(p)" class="px-3 py-2 hover:bg-blue-50 cursor-pointer text-sm flex justify-between">
                                        <span>{{ p.sku }} — {{ p.name }}</span>
                                        <span class="text-gray-400">Tồn: {{ p.stock_quantity }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Serial list to pick after selecting product -->
                            <div v-if="selectedProduct && productSerials.length > 0" class="mt-3">
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block font-semibold text-sm">Chọn Serial/IMEI cần sửa *</label>
                                    <label class="flex items-center gap-1 text-xs text-blue-600 cursor-pointer">
                                        <input type="checkbox"
                                            :checked="selectedSerialIds.length === productSerials.length"
                                            @change="toggleAllSerials"
                                            class="accent-blue-600"
                                        />
                                        Chọn tất cả
                                    </label>
                                </div>
                                <div class="border border-gray-200 rounded-lg max-h-44 overflow-y-auto">
                                    <label
                                        v-for="s in productSerials"
                                        :key="s.id"
                                        class="flex items-center gap-3 px-3 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0"
                                    >
                                        <input
                                            type="checkbox"
                                            :value="s.id"
                                            v-model="selectedSerialIds"
                                            class="accent-blue-600"
                                        />
                                        <span class="text-sm font-medium text-blue-700">{{ s.serial_number }}</span>
                                        <span class="text-xs text-gray-400 ml-auto">GV: {{ Number(s.cost_price || 0).toLocaleString() }}đ</span>
                                    </label>
                                </div>
                                <p v-if="selectedSerialIds.length" class="text-xs text-blue-600 mt-1">Đã chọn {{ selectedSerialIds.length }} serial</p>
                            </div>

                            <div v-if="selectedProduct && productSerials.length === 0" class="mt-2 text-sm text-orange-600 bg-orange-50 border border-orange-200 px-3 py-2 rounded">
                                Không có serial nào đang tồn kho cho sản phẩm này.
                            </div>
                        </div>
                    </div>

                    <!-- Title (repair optional) -->
                    <div v-if="createType === 'repair'">
                        <label class="block font-semibold text-sm mb-1">Tiêu đề (tuỳ chọn)</label>
                        <input v-model="createForm.title" type="text" placeholder="VD: Sửa lỗi màn hình" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" />
                    </div>

                    <!-- Description / Issue -->
                    <div>
                        <label class="block font-semibold text-sm mb-1">{{ createType === 'repair' ? 'Mô tả lỗi' : 'Mô tả' }}</label>
                        <textarea
                            v-if="createType === 'repair'"
                            v-model="createForm.issue_description"
                            rows="3"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none"
                            placeholder="VD: lỗi màn, pin, phím..."
                        ></textarea>
                        <textarea
                            v-else
                            v-model="createForm.description"
                            rows="3"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none"
                            placeholder="Mô tả chi tiết công việc..."
                        ></textarea>
                    </div>

                    <!-- Category + Priority row -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-sm mb-1">Danh mục</label>
                            <select v-model="createForm.category_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                                <option :value="null">-- Chọn --</option>
                                <option v-for="c in filteredCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-semibold text-sm mb-1">Ưu tiên</label>
                            <select v-model="createForm.priority" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                                <option value="low">Thấp</option>
                                <option value="normal">Bình thường</option>
                                <option value="high">Cao</option>
                                <option value="urgent">Khẩn cấp</option>
                            </select>
                        </div>
                    </div>

                    <!-- Branch + Deadline row -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-sm mb-1">Chi nhánh</label>
                            <select v-model="createForm.branch_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                                <option :value="null">-- Chọn --</option>
                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-semibold text-sm mb-1">Deadline</label>
                            <input v-model="createForm.deadline" type="date" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                        </div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label class="block font-semibold text-sm mb-1">Ghi chú</label>
                        <input v-model="createForm.notes" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                    </div>

                    <!-- Giao nhân viên -->
                    <div>
                        <label class="block font-semibold text-sm mb-1">Giao cho nhân viên</label>
                        <div class="border border-gray-300 rounded-lg max-h-40 overflow-y-auto">
                            <label v-for="e in employees" :key="e.id" class="flex items-center gap-3 px-3 py-2 hover:bg-gray-50 cursor-pointer">
                                <input type="checkbox" :value="e.id" v-model="createForm.employee_ids" class="accent-blue-600" />
                                <span class="text-sm">{{ e.name }}</span>
                            </label>
                            <div v-if="!employees?.length" class="px-3 py-3 text-sm text-gray-400">Chưa có nhân viên</div>
                        </div>
                        <p v-if="createForm.employee_ids.length" class="text-xs text-blue-600 mt-1">Đã chọn {{ createForm.employee_ids.length }} nhân viên</p>
                    </div>
                </div>
                <div class="flex justify-end gap-3 px-6 py-4 border-t sticky bottom-0 bg-white">
                    <button @click="showCreateModal = false" class="px-5 py-2 border rounded-lg text-sm font-semibold">Hủy</button>
                    <button
                        @click="submitCreate"
                        :disabled="createType === 'repair' ? (batchMode ? selectedSerialIds.length === 0 : !createForm.serial_imei_id) : !createForm.title"
                        class="px-5 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
                    >{{ batchMode && createType === 'repair' ? `Tạo ${selectedSerialIds.length} phiếu` : 'Tạo' }}</button>
                </div>
            </div>
        </div>

        <!-- Assign Modal (multi-select) -->
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
                    <button
                        @click="submitAssign"
                        :disabled="!assignEmployeeIds.length"
                        class="px-5 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 disabled:opacity-50"
                    >Giao ({{ assignEmployeeIds.length }})</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
