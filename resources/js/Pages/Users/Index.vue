<script setup>
import { ref, watch, computed } from "vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import SortableHeader from "@/Components/SortableHeader.vue";

const props = defineProps({
    users: Object,
    roles: Array,
    branches: Array,
    employees: Array,
    filters: Object,
});

const search = ref(props.filters?.search || "");
const filterStatus = ref(props.filters?.status || "");
const filterRole = ref(props.filters?.role_id || "");
const filterBranch = ref(props.filters?.branch_id || "");
const sortBy = ref(props.filters?.sort_by || "");
const sortDirection = ref(props.filters?.sort_direction || "");

let searchTimeout;
watch(search, (value) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => applyFilters(), 400);
});
watch([filterStatus, filterRole, filterBranch], () => applyFilters());

const applyFilters = () => {
    const params = {};
    if (search.value) params.search = search.value;
    if (filterStatus.value) params.status = filterStatus.value;
    if (filterRole.value) params.role_id = filterRole.value;
    if (filterBranch.value) params.branch_id = filterBranch.value;
    if (sortBy.value) params.sort_by = sortBy.value;
    if (sortDirection.value) params.sort_direction = sortDirection.value;
    router.get("/users", params, { preserveState: true, replace: true });
};

const handleSort = (field, direction) => {
    sortBy.value = field;
    sortDirection.value = direction;
    applyFilters();
};

// ── Selected user (detail view) ──
const selectedUser = ref(null);
const activeTab = ref("info"); // info | permissions

const selectUser = (user) => {
    selectedUser.value = { ...user };
    activeTab.value = "info";
};

const closeDetail = () => {
    selectedUser.value = null;
};

// ── Create modal ──
const showCreateModal = ref(false);
const createForm = useForm({
    name: "",
    email: "",
    phone: "",
    password: "",
    role_id: null,
    branch_id: null,
    employee_id: null,
    status: "active",
});

const openCreateModal = () => {
    createForm.reset();
    createForm.clearErrors();
    showCreateModal.value = true;
};

const submitCreate = () => {
    createForm.post("/users", {
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.reset();
        },
    });
};

// ── Edit modal ──
const showEditModal = ref(false);
const editForm = useForm({
    name: "",
    email: "",
    phone: "",
    role_id: null,
    branch_id: null,
    employee_id: null,
    status: "active",
});

const openEditModal = () => {
    if (!selectedUser.value) return;
    const u = selectedUser.value;
    editForm.name = u.name;
    editForm.email = u.email;
    editForm.phone = u.phone || "";
    editForm.role_id = u.role_id;
    editForm.branch_id = u.branch_id;
    editForm.employee_id = u.employee?.id || null;
    editForm.status = u.status || "active";
    editForm.clearErrors();
    showEditModal.value = true;
};

const submitEdit = () => {
    editForm.put(`/users/${selectedUser.value.id}`, {
        onSuccess: () => {
            showEditModal.value = false;
            selectedUser.value = null;
        },
    });
};

// ── Change password modal ──
const showPasswordModal = ref(false);
const passwordForm = useForm({
    password: "",
    password_confirmation: "",
});

const openPasswordModal = () => {
    passwordForm.reset();
    passwordForm.clearErrors();
    showPasswordModal.value = true;
};

const submitPassword = () => {
    passwordForm.post(`/users/${selectedUser.value.id}/change-password`, {
        onSuccess: () => {
            showPasswordModal.value = false;
            passwordForm.reset();
        },
    });
};

// ── Actions ──
const toggleStatus = () => {
    if (!selectedUser.value) return;
    const u = selectedUser.value;
    const action = u.status === "active" ? "ngừng hoạt động" : "kích hoạt";
    if (!confirm(`Bạn có chắc muốn ${action} tài khoản "${u.name}"?`)) return;
    router.post(`/users/${u.id}/toggle-status`, {}, {
        onSuccess: () => { selectedUser.value = null; },
    });
};

const deleteUser = () => {
    if (!selectedUser.value) return;
    if (!confirm(`Xóa tài khoản "${selectedUser.value.name}"? Thao tác này không thể hoàn tác.`)) return;
    router.delete(`/users/${selectedUser.value.id}`, {
        onSuccess: () => { selectedUser.value = null; },
    });
};

// ── Employees available for linking ──
const linkableEmployees = computed(() => {
    const list = [...(props.employees || [])];
    if (selectedUser.value?.employee) {
        const emp = selectedUser.value.employee;
        if (!list.find(e => e.id === emp.id)) {
            list.unshift({ id: emp.id, name: emp.name, code: emp.code });
        }
    }
    return list;
});

const statusBadge = (status) => {
    if (status === "active") return { label: "Đang hoạt động", cls: "bg-green-100 text-green-700" };
    return { label: "Ngừng hoạt động", cls: "bg-red-100 text-red-600" };
};
</script>

<template>
    <Head title="Quản lý người dùng" />
    <AppLayout>
        <div class="p-6">
            <!-- Header -->
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-xl font-bold text-gray-800">Quản lý người dùng</h1>
                <button
                    @click="openCreateModal"
                    class="flex items-center gap-2 bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow transition"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Thêm người dùng
                </button>
            </div>

            <!-- Filters -->
            <div class="flex items-center gap-3 mb-4 flex-wrap">
                <div class="relative flex-1 max-w-xs">
                    <svg class="absolute left-3 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    <input v-model="search" type="text" placeholder="Tìm tên, email, SĐT..." class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:border-blue-500" />
                </div>
                <select v-model="filterStatus" class="border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-500">
                    <option value="">Tất cả trạng thái</option>
                    <option value="active">Đang hoạt động</option>
                    <option value="inactive">Ngừng hoạt động</option>
                </select>
                <select v-model="filterRole" class="border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-500">
                    <option value="">Tất cả vai trò</option>
                    <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select>
                <select v-model="filterBranch" class="border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:border-blue-500">
                    <option value="">Tất cả chi nhánh</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>

            <div class="flex gap-0 h-[calc(100vh-200px)]">
                <!-- LEFT: User List -->
                <div class="flex-1 bg-white rounded-lg shadow overflow-auto border">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 sticky top-0 z-10">
                            <tr class="text-left text-gray-600 font-semibold border-b">
                                <SortableHeader label="Tên hiển thị" field="name" :current-sort="sortBy" :current-direction="sortDirection" class="px-4 py-3" @sort="handleSort" />
                                <SortableHeader label="Tên đăng nhập" field="email" :current-sort="sortBy" :current-direction="sortDirection" class="px-4 py-3" @sort="handleSort" />
                                <SortableHeader label="Điện thoại" field="phone" :current-sort="sortBy" :current-direction="sortDirection" class="px-4 py-3" @sort="handleSort" />
                                <th class="px-4 py-3">Vai trò</th>
                                <SortableHeader label="Trạng thái" field="status" :current-sort="sortBy" :current-direction="sortDirection" align="center" class="px-4 py-3 text-center" @sort="handleSort" />
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-if="users.data.length === 0">
                                <td colspan="5" class="px-6 py-12 text-center text-gray-400">Không có người dùng nào.</td>
                            </tr>
                            <tr
                                v-for="u in users.data"
                                :key="u.id"
                                @click="selectUser(u)"
                                class="hover:bg-blue-50 cursor-pointer transition"
                                :class="selectedUser?.id === u.id ? 'bg-blue-50 border-l-4 border-l-blue-500' : ''"
                            >
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 font-bold text-sm">
                                            {{ u.name?.charAt(0)?.toUpperCase() }}
                                        </div>
                                        <div>
                                            <div class="font-semibold text-gray-800">{{ u.name }}</div>
                                            <div v-if="u.employee" class="text-[11px] text-gray-400">NV: {{ u.employee.code }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ u.email }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ u.phone || '-' }}</td>
                                <td class="px-4 py-3">
                                    <span v-if="u.role" class="text-gray-700">{{ u.role.name }}</span>
                                    <span v-else class="text-orange-500 font-semibold">Admin</span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span :class="statusBadge(u.status || 'active').cls" class="px-2 py-0.5 rounded-full text-xs font-bold">
                                        {{ statusBadge(u.status || 'active').label }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <div v-if="users.last_page > 1" class="flex items-center justify-between px-4 py-3 border-t text-sm">
                        <span class="text-gray-500">Tổng: {{ users.total }}</span>
                        <div class="flex gap-1">
                            <template v-for="(link, i) in users.links" :key="i">
                                <Link
                                    v-if="link.url"
                                    :href="link.url"
                                    class="px-2.5 py-1 text-sm border rounded"
                                    :class="link.active ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300'"
                                    v-html="link.label"
                                ></Link>
                                <span v-else class="px-2.5 py-1 text-sm border rounded bg-gray-100 text-gray-400 cursor-not-allowed" v-html="link.label"></span>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: User Detail Panel (like KiotViet) -->
                <div v-if="selectedUser" class="w-[480px] bg-white border-l shadow-lg overflow-y-auto ml-0">
                    <!-- Header bar -->
                    <div class="sticky top-0 bg-white z-10 border-b px-5 py-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 font-bold text-lg">
                                    {{ selectedUser.name?.charAt(0)?.toUpperCase() }}
                                </div>
                                <div>
                                    <div class="font-bold text-gray-800">{{ selectedUser.name }}</div>
                                    <div class="text-xs text-gray-400">{{ selectedUser.email }}</div>
                                </div>
                            </div>
                            <button @click="closeDetail" class="text-gray-400 hover:text-gray-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <div class="flex border-b px-5">
                        <button @click="activeTab = 'info'" :class="activeTab === 'info' ? 'text-blue-600 border-b-2 border-blue-600' : 'text-gray-500'" class="px-3 py-2.5 text-sm font-semibold">Thông tin</button>
                        <button @click="activeTab = 'permissions'" :class="activeTab === 'permissions' ? 'text-blue-600 border-b-2 border-blue-600' : 'text-gray-500'" class="px-3 py-2.5 text-sm font-semibold">Phân quyền</button>
                    </div>

                    <!-- Tab: Info -->
                    <div v-if="activeTab === 'info'" class="px-5 py-4">
                        <div class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                            <div>
                                <div class="text-gray-400 text-xs mb-0.5">Tên hiển thị</div>
                                <div class="font-medium text-gray-800">{{ selectedUser.name }}</div>
                            </div>
                            <div>
                                <div class="text-gray-400 text-xs mb-0.5">Tên đăng nhập</div>
                                <div class="font-medium text-gray-800">{{ selectedUser.email }}</div>
                            </div>
                            <div>
                                <div class="text-gray-400 text-xs mb-0.5">Email</div>
                                <div class="font-medium text-gray-800">{{ selectedUser.email || 'Chưa có' }}</div>
                            </div>
                            <div>
                                <div class="text-gray-400 text-xs mb-0.5">Điện thoại</div>
                                <div class="font-medium text-gray-800">{{ selectedUser.phone || 'Chưa có' }}</div>
                            </div>
                            <div>
                                <div class="text-gray-400 text-xs mb-0.5">Vai trò</div>
                                <div class="font-medium text-gray-800">{{ selectedUser.role?.name || 'Admin (Toàn quyền)' }}</div>
                            </div>
                            <div>
                                <div class="text-gray-400 text-xs mb-0.5">Trạng thái</div>
                                <div>
                                    <span :class="statusBadge(selectedUser.status || 'active').cls" class="px-2 py-0.5 rounded-full text-xs font-bold">
                                        {{ statusBadge(selectedUser.status || 'active').label }}
                                    </span>
                                </div>
                            </div>
                            <div>
                                <div class="text-gray-400 text-xs mb-0.5">Chi nhánh</div>
                                <div class="font-medium text-gray-800">{{ selectedUser.branch?.name || 'Tất cả' }}</div>
                            </div>
                            <div>
                                <div class="text-gray-400 text-xs mb-0.5">Nhân viên liên kết</div>
                                <div class="font-medium text-gray-800">
                                    <span v-if="selectedUser.employee" class="text-indigo-600">{{ selectedUser.employee.name }} ({{ selectedUser.employee.code }})</span>
                                    <span v-else class="text-gray-400">Chưa liên kết</span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 pt-4 border-t text-sm text-gray-400">
                            <div class="flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                Chưa có ghi chú
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Permissions -->
                    <div v-if="activeTab === 'permissions'" class="px-5 py-4 text-sm">
                        <div v-if="!selectedUser.role" class="bg-orange-50 border border-orange-200 rounded-lg p-4 text-orange-700">
                            <div class="font-bold mb-1">🔑 Admin - Toàn quyền</div>
                            <p>Tài khoản này không gán vai trò cụ thể, mặc định được toàn quyền truy cập tất cả chức năng.</p>
                        </div>
                        <div v-else class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-blue-700">
                            <div class="font-bold mb-1">Vai trò: {{ selectedUser.role.name }}</div>
                            <p class="text-xs text-blue-500">Quyền hạn được quản lý trong phần Thiết lập vai trò.</p>
                        </div>
                    </div>

                    <!-- Action buttons at bottom -->
                    <div class="sticky bottom-0 bg-white border-t px-5 py-3 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <button @click="deleteUser" class="flex items-center gap-1 text-gray-500 hover:text-red-600 text-sm transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                Xóa
                            </button>
                        </div>
                        <div class="flex items-center gap-2">
                            <button @click="openEditModal" class="flex items-center gap-1.5 bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-bold shadow transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                Chỉnh sửa
                            </button>
                            <button @click="openPasswordModal" class="flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2 rounded-lg text-sm font-bold transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                                Đổi mật khẩu
                            </button>
                            <button @click="toggleStatus" class="flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2 rounded-lg text-sm font-bold transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path></svg>
                                {{ (selectedUser.status || 'active') === 'active' ? 'Ngừng hoạt động' : 'Kích hoạt' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CREATE USER MODAL -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between px-6 py-4 border-b">
                    <h2 class="text-lg font-bold">Thêm người dùng mới</h2>
                    <button @click="showCreateModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <form @submit.prevent="submitCreate" class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block font-semibold text-sm mb-1">Tên hiển thị *</label>
                        <input v-model="createForm.name" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" required />
                        <div v-if="createForm.errors.name" class="text-red-500 text-xs mt-1">{{ createForm.errors.name }}</div>
                    </div>
                    <div>
                        <label class="block font-semibold text-sm mb-1">Email (tên đăng nhập) *</label>
                        <input v-model="createForm.email" type="email" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" required />
                        <div v-if="createForm.errors.email" class="text-red-500 text-xs mt-1">{{ createForm.errors.email }}</div>
                    </div>
                    <div>
                        <label class="block font-semibold text-sm mb-1">Số điện thoại</label>
                        <input v-model="createForm.phone" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" />
                    </div>
                    <div>
                        <label class="block font-semibold text-sm mb-1">Mật khẩu *</label>
                        <input v-model="createForm.password" type="password" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" required />
                        <div v-if="createForm.errors.password" class="text-red-500 text-xs mt-1">{{ createForm.errors.password }}</div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block font-semibold text-sm mb-1">Vai trò</label>
                            <select v-model="createForm.role_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none">
                                <option :value="null">Admin (Toàn quyền)</option>
                                <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-semibold text-sm mb-1">Chi nhánh</label>
                            <select v-model="createForm.branch_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none">
                                <option :value="null">Tất cả</option>
                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block font-semibold text-sm mb-1">Liên kết nhân viên</label>
                        <select v-model="createForm.employee_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none">
                            <option :value="null">-- Không liên kết --</option>
                            <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.code }} - {{ emp.name }}</option>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">Gán tài khoản cho nhân viên để nhận công việc, chấm công.</p>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-gray-600 hover:text-gray-800 text-sm font-semibold">Hủy</button>
                        <button type="submit" :disabled="createForm.processing" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg text-sm font-bold shadow disabled:opacity-50">
                            {{ createForm.processing ? 'Đang tạo...' : 'Tạo người dùng' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- EDIT USER MODAL -->
        <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between px-6 py-4 border-b">
                    <h2 class="text-lg font-bold">Chỉnh sửa người dùng</h2>
                    <button @click="showEditModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <form @submit.prevent="submitEdit" class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block font-semibold text-sm mb-1">Tên hiển thị *</label>
                        <input v-model="editForm.name" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" required />
                        <div v-if="editForm.errors.name" class="text-red-500 text-xs mt-1">{{ editForm.errors.name }}</div>
                    </div>
                    <div>
                        <label class="block font-semibold text-sm mb-1">Email (tên đăng nhập) *</label>
                        <input v-model="editForm.email" type="email" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" required />
                        <div v-if="editForm.errors.email" class="text-red-500 text-xs mt-1">{{ editForm.errors.email }}</div>
                    </div>
                    <div>
                        <label class="block font-semibold text-sm mb-1">Số điện thoại</label>
                        <input v-model="editForm.phone" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block font-semibold text-sm mb-1">Vai trò</label>
                            <select v-model="editForm.role_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none">
                                <option :value="null">Admin (Toàn quyền)</option>
                                <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-semibold text-sm mb-1">Chi nhánh</label>
                            <select v-model="editForm.branch_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none">
                                <option :value="null">Tất cả</option>
                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block font-semibold text-sm mb-1">Liên kết nhân viên</label>
                        <select v-model="editForm.employee_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none">
                            <option :value="null">-- Không liên kết --</option>
                            <option v-for="emp in linkableEmployees" :key="emp.id" :value="emp.id">{{ emp.code }} - {{ emp.name }}</option>
                        </select>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="showEditModal = false" class="px-4 py-2 text-gray-600 hover:text-gray-800 text-sm font-semibold">Hủy</button>
                        <button type="submit" :disabled="editForm.processing" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg text-sm font-bold shadow disabled:opacity-50">
                            {{ editForm.processing ? 'Đang lưu...' : 'Lưu thay đổi' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- CHANGE PASSWORD MODAL -->
        <div v-if="showPasswordModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4">
                <div class="flex items-center justify-between px-6 py-4 border-b">
                    <h2 class="text-lg font-bold">Đổi mật khẩu</h2>
                    <button @click="showPasswordModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <form @submit.prevent="submitPassword" class="px-6 py-5 space-y-4">
                    <div class="text-sm text-gray-500 mb-2">Đổi mật khẩu cho: <span class="font-bold text-gray-800">{{ selectedUser?.name }}</span></div>
                    <div>
                        <label class="block font-semibold text-sm mb-1">Mật khẩu mới *</label>
                        <input v-model="passwordForm.password" type="password" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" required />
                        <div v-if="passwordForm.errors.password" class="text-red-500 text-xs mt-1">{{ passwordForm.errors.password }}</div>
                    </div>
                    <div>
                        <label class="block font-semibold text-sm mb-1">Xác nhận mật khẩu *</label>
                        <input v-model="passwordForm.password_confirmation" type="password" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" required />
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="showPasswordModal = false" class="px-4 py-2 text-gray-600 hover:text-gray-800 text-sm font-semibold">Hủy</button>
                        <button type="submit" :disabled="passwordForm.processing" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg text-sm font-bold shadow disabled:opacity-50">
                            {{ passwordForm.processing ? 'Đang đổi...' : 'Đổi mật khẩu' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
