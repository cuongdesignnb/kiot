<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CategoryManager from '@/Components/CategoryManager.vue';
import BrandManager from '@/Components/BrandManager.vue';
import UnitManager from '@/Components/UnitManager.vue';
import AttributeManager from '@/Components/AttributeManager.vue';
import LocationManager from '@/Components/LocationManager.vue';
import OtherFeeManager from '@/Components/OtherFeeManager.vue';
import BankAccountManager from '@/Components/BankAccountManager.vue';
import { ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    settings: Object,
    groups: Object,
    metadata: Object,
    categories: Array,
    brands: Array,
    units: Array,
    attributes: Array,
    locations: Array,
    otherFees: Array,
    bankAccounts: Array,
    branches: Array,
});

const showCategoryManager = ref(false);
const showBrandManager = ref(false);
const showUnitManager = ref(false);
const showAttributeManager = ref(false);
const showLocationManager = ref(false);
const showOtherFeeManager = ref(false);
const showBankAccountManager = ref(false);

const repairTiers = ref([]);
const tierForm = ref({ label: '', min_percent: 0, max_percent: 100, salary_percent: 100, sort_order: 0 });
const editingTierId = ref(null);
const tierError = ref('');

const loadRepairTiers = async () => {
    try {
        const res = await axios.get('/api/repair-performance-tiers');
        repairTiers.value = res.data || [];
    } catch (e) {
        // ignore
    }
};

const resetTierForm = () => {
    tierForm.value = { label: '', min_percent: 0, max_percent: 100, salary_percent: 100, sort_order: 0 };
    editingTierId.value = null;
    tierError.value = '';
};

const saveTier = async () => {
    tierError.value = '';
    try {
        if (editingTierId.value) {
            await axios.put(`/api/repair-performance-tiers/${editingTierId.value}`, tierForm.value);
        } else {
            await axios.post('/api/repair-performance-tiers', tierForm.value);
        }
        resetTierForm();
        loadRepairTiers();
    } catch (e) {
        tierError.value = e.response?.data?.message || 'Lỗi khi lưu bậc.';
    }
};

const editTier = (tier) => {
    editingTierId.value = tier.id;
    tierForm.value = { label: tier.label, min_percent: tier.min_percent, max_percent: tier.max_percent, salary_percent: tier.salary_percent, sort_order: tier.sort_order };
};

const deleteTier = async (tier) => {
    if (!confirm(`Xóa bậc "${tier.label}"?`)) return;
    try {
        await axios.delete(`/api/repair-performance-tiers/${tier.id}`);
        loadRepairTiers();
    } catch (e) {
        alert(e.response?.data?.message || 'Lỗi khi xóa.');
    }
};

// ── Roles & Users management ──
const roles = ref([]);
const users = ref({ data: [], total: 0 });
const permissionsMap = ref({});
const userSubTab = ref('roles'); // 'roles' | 'users'
const loadingRoles = ref(false);
const loadingUsers = ref(false);

// Role editor state
const showRoleEditor = ref(false);
const editingRole = ref(null);
const roleForm = ref({ display_name: '', name: '', description: '', permissions: [] });
const roleError = ref('');

// User editor state
const showUserModal = ref(false);
const editingUser = ref(null);
const userForm = ref({ name: '', email: '', password: '', phone: '', role_id: null, branch_id: null, status: 'active', branch_ids: [] });
const userError = ref('');

const loadRoles = async () => {
    loadingRoles.value = true;
    try {
        const res = await axios.get('/api/roles');
        roles.value = res.data || [];
    } catch (e) { /* ignore */ }
    loadingRoles.value = false;
};

const loadUsers = async () => {
    loadingUsers.value = true;
    try {
        const res = await axios.get('/api/users', { params: { per_page: 100 } });
        users.value = res.data || { data: [], total: 0 };
    } catch (e) { /* ignore */ }
    loadingUsers.value = false;
};

const loadPermissionsMap = async () => {
    if (Object.keys(permissionsMap.value).length > 0) return;
    try {
        const res = await axios.get('/api/roles/permissions-map');
        permissionsMap.value = res.data || {};
    } catch (e) { /* ignore */ }
};

const loadRolesAndUsers = () => {
    loadRoles();
    loadUsers();
    loadPermissionsMap();
};

// Role CRUD
const openRoleEditor = (role = null) => {
    editingRole.value = role;
    roleError.value = '';
    if (role) {
        roleForm.value = { display_name: role.display_name, name: role.name, description: role.description || '', permissions: [...(role.permissions || [])] };
    } else {
        roleForm.value = { display_name: '', name: '', description: '', permissions: [] };
    }
    showRoleEditor.value = true;
};

const togglePermission = (key) => {
    const idx = roleForm.value.permissions.indexOf(key);
    if (idx >= 0) roleForm.value.permissions.splice(idx, 1);
    else roleForm.value.permissions.push(key);
};

const isGroupChecked = (perms) => {
    const keys = Object.keys(perms);
    return keys.length > 0 && keys.every(k => roleForm.value.permissions.includes(k));
};

const isGroupPartial = (perms) => {
    const keys = Object.keys(perms);
    const checked = keys.filter(k => roleForm.value.permissions.includes(k));
    return checked.length > 0 && checked.length < keys.length;
};

const toggleGroup = (perms) => {
    const keys = Object.keys(perms);
    if (isGroupChecked(perms)) {
        roleForm.value.permissions = roleForm.value.permissions.filter(p => !keys.includes(p));
    } else {
        keys.forEach(k => { if (!roleForm.value.permissions.includes(k)) roleForm.value.permissions.push(k); });
    }
};

const saveRole = async () => {
    roleError.value = '';
    try {
        if (editingRole.value) {
            await axios.put(`/api/roles/${editingRole.value.id}`, roleForm.value);
        } else {
            // Auto-generate name from display_name
            const autoName = roleForm.value.display_name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'role_' + Date.now();
            await axios.post('/api/roles', { ...roleForm.value, name: autoName });
        }
        showRoleEditor.value = false;
        loadRoles();
    } catch (e) {
        roleError.value = e.response?.data?.message || 'Lỗi khi lưu vai trò.';
    }
};

const duplicateRole = async (role) => {
    try {
        await axios.post(`/api/roles/${role.id}/duplicate`);
        loadRoles();
    } catch (e) {
        alert(e.response?.data?.message || 'Lỗi.');
    }
};

const deleteRole = async (role) => {
    if (!confirm(`Xóa vai trò "${role.display_name}"?`)) return;
    try {
        await axios.delete(`/api/roles/${role.id}`);
        loadRoles();
    } catch (e) {
        alert(e.response?.data?.message || 'Lỗi khi xóa.');
    }
};

// User CRUD
const openUserModal = (user = null) => {
    editingUser.value = user;
    userError.value = '';
    if (user) {
        userForm.value = { name: user.name, email: user.email, password: '', phone: user.phone || '', role_id: user.role_id, branch_id: user.branch_id, status: user.status || 'active', branch_ids: [] };
    } else {
        userForm.value = { name: '', email: '', password: '', phone: '', role_id: null, branch_id: null, status: 'active', branch_ids: [] };
    }
    showUserModal.value = true;
};

const saveUser = async () => {
    userError.value = '';
    try {
        const payload = { ...userForm.value };
        if (!payload.password) delete payload.password;
        if (editingUser.value) {
            await axios.put(`/api/users/${editingUser.value.id}`, payload);
        } else {
            await axios.post('/api/users', payload);
        }
        showUserModal.value = false;
        loadUsers();
    } catch (e) {
        userError.value = e.response?.data?.message || Object.values(e.response?.data?.errors || {}).flat().join(', ') || 'Lỗi.';
    }
};

const deleteUser = async (user) => {
    if (!confirm(`Xóa tài khoản "${user.name}"?`)) return;
    try {
        await axios.delete(`/api/users/${user.id}`);
        loadUsers();
    } catch (e) {
        alert(e.response?.data?.message || 'Lỗi khi xóa.');
    }
};

const toggleUserStatus = async (user) => {
    const newStatus = user.status === 'active' ? 'locked' : 'active';
    try {
        await axios.put(`/api/users/${user.id}`, { name: user.name, email: user.email, status: newStatus, role_id: user.role_id, branch_id: user.branch_id });
        loadUsers();
    } catch (e) {
        alert(e.response?.data?.message || 'Lỗi.');
    }
};

const form = useForm({
    settings: { ...props.settings }
});

const activeCategory = ref('hang-hoa');
watch(activeCategory, (val) => {
    if (val === 'sua-chua') loadRepairTiers();
    if (val === 'nguoi-dung') loadRolesAndUsers();
});

const categories = [
    { id: 'hang-hoa', name: 'Hàng hóa', icon: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4' },
    { id: 'don-hang', name: 'Đơn hàng', icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01' },
    { id: 'khach-hang', name: 'Khách hàng', icon: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z' },
    { id: 'so-quy', name: 'Sổ quỹ', icon: 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z' },
    { id: 'nguoi-dung', name: 'Người dùng', icon: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z' },
    { id: 'sua-chua', name: 'Sửa chữa', icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z' },
];

const submit = () => {
    form.post('/settings', {
        preserveScroll: true,
    });
};

const scrollToSection = (id) => {
    const el = document.getElementById(id);
    if (el) el.scrollIntoView({ behavior: 'smooth' });
};

</script>

<template>
    <Head title="Thiết lập - KiotViet Clone" />
    <AppLayout>
        <template #sidebar>
            <div class="p-4 border-b">
                <h2 class="font-bold text-gray-700 uppercase text-xs tracking-wider">Quản lý</h2>
            </div>
            <div class="py-1">
                <button 
                    v-for="cat in categories" 
                    :key="cat.id"
                    @click="activeCategory = cat.id"
                    class="w-full flex items-center gap-3 px-4 py-2.5 text-[13px] font-medium transition-colors"
                    :class="activeCategory === cat.id ? 'bg-blue-50 text-blue-600 border-r-4 border-blue-600' : 'text-gray-600 hover:bg-gray-50'"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="cat.icon"></path>
                    </svg>
                    {{ cat.name }}
                </button>
            </div>

            <div class="mt-4 p-4 border-b border-t bg-gray-50/50">
                <h2 class="font-bold text-gray-700 uppercase text-xs tracking-wider">Tiện ích</h2>
            </div>
            <div class="py-1">
                <button class="w-full flex items-center gap-3 px-4 py-2.5 text-[13px] font-medium text-gray-600 hover:bg-gray-50">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                    Giao hàng
                </button>
                <button class="w-full flex items-center gap-3 px-4 py-2.5 text-[13px] font-medium text-gray-600 hover:bg-gray-50">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    Thanh toán
                </button>
            </div>

            <div class="mt-4 p-4 border-b border-t bg-gray-50/50">
                <h2 class="font-bold text-gray-700 uppercase text-xs tracking-wider">Cửa hàng</h2>
            </div>
            <div class="py-1">
                <button class="w-full flex items-center gap-3 px-4 py-2.5 text-[13px] font-medium text-gray-600 hover:bg-gray-50">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Thông tin cửa hàng
                </button>
                <button class="w-full flex items-center gap-3 px-4 py-2.5 text-[13px] font-medium text-gray-600 hover:bg-gray-50">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    Quản lý người dùng
                </button>
            </div>
        </template>

        <div class="flex gap-6 max-w-[1200px] mx-auto">
            <!-- Content Area -->
            <div class="flex-1 overflow-y-auto pr-2 pb-20">
                <div class="mb-6 flex justify-between items-center">
                    <h1 class="text-2xl font-bold text-gray-800">Thiết lập</h1>
                    <div class="relative w-72">
                        <input type="text" placeholder="Tìm kiếm thiết lập" class="w-full pl-10 pr-4 py-1.5 border border-gray-300 rounded-full text-sm outline-none focus:ring-2 focus:ring-blue-500/20 shadow-sm">
                        <svg class="w-4 h-4 absolute left-3 top-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                </div>

                <!-- Hang Hoa Settings -->
                <div v-show="activeCategory === 'hang-hoa'" class="space-y-4">
                    <!-- Section: Thong tin hang hoa -->
                    <div id="info-section" class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-3 border-b bg-gray-50/50">
                            <h3 class="font-bold text-[14px] text-gray-800">Thông tin hàng hóa</h3>
                        </div>
                        <div class="divide-y divide-gray-100">
                            <!-- Ma vach -->
                            <div class="px-5 py-4 flex justify-between items-center">
                                <div>
                                    <h4 class="text-[13.5px] font-medium text-gray-900">Mã vạch hàng hóa</h4>
                                    <p class="text-[12.5px] text-gray-500 mt-0.5">Quản lý hàng hóa bằng mã vạch chuẩn hoặc mã vạch do cửa hàng tự tạo ra.</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" v-model="form.settings.product_barcode_auto" @change="submit" class="sr-only peer">
                                    <div class="w-10 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                </label>
                            </div>

                            <!-- Goi y -->
                            <div class="px-5 py-4 flex justify-between items-center">
                                <div>
                                    <h4 class="text-[13.5px] font-medium text-gray-900">Tự động gợi ý thông tin hàng hóa</h4>
                                    <p class="text-[12.5px] text-gray-500 mt-0.5">KiotViet sẽ tự động gợi ý tên, mã, mô tả, hình ảnh hàng hóa khi tạo hàng hóa.</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" v-model="form.settings.product_suggest_info" @change="submit" class="sr-only peer">
                                    <div class="w-10 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                </label>
                            </div>

                            <!-- Đơn vị tính - clickable -->
                            <div @click="showUnitManager = true" class="px-5 py-4 flex justify-between items-center hover:bg-purple-50 cursor-pointer group">
                                <div class="flex-1">
                                    <h4 class="text-[13.5px] font-medium text-gray-900 group-hover:text-purple-600 transition-colors">Đơn vị tính</h4>
                                    <p class="text-[12.5px] text-gray-500 mt-0.5">Quản lý hàng hóa theo đơn vị tính khác nhau như chiếc, lốc, thùng.</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-[12.5px] text-purple-600 font-bold">{{ props.units?.length || 0 }} đơn vị tính</span>
                                    <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                                </div>
                            </div>

                            <!-- Thuộc tính - clickable -->
                            <div @click="showAttributeManager = true" class="px-5 py-4 flex justify-between items-center hover:bg-orange-50 cursor-pointer group">
                                <div class="flex-1">
                                    <h4 class="text-[13.5px] font-medium text-gray-900 group-hover:text-orange-600 transition-colors">Thuộc tính</h4>
                                    <p class="text-[12.5px] text-gray-500 mt-0.5">Quản lý hàng hóa theo đặc điểm riêng như màu sắc, kích cỡ, chất liệu.</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-[12.5px] text-orange-600 font-bold">{{ props.attributes?.length || 0 }} thuộc tính</span>
                                    <svg class="w-4 h-4 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                                </div>
                            </div>

                            <!-- Nhóm hàng - clickable opens popup -->
                            <div @click="showCategoryManager = true" class="px-5 py-4 flex justify-between items-center hover:bg-blue-50 cursor-pointer group">
                                <div class="flex-1">
                                    <h4 class="text-[13.5px] font-medium text-gray-900 group-hover:text-blue-600 transition-colors">Nhóm hàng</h4>
                                    <p class="text-[12.5px] text-gray-500 mt-0.5">Quản lý hàng hóa theo chủng loại, đặc tính, công năng.</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-[12.5px] text-blue-600 font-bold">{{ props.categories?.length || 0 }} nhóm hàng</span>
                                    <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                                </div>
                            </div>

                            <!-- Thương hiệu - clickable opens popup -->
                            <div @click="showBrandManager = true" class="px-5 py-4 flex justify-between items-center hover:bg-green-50 cursor-pointer group">
                                <div class="flex-1">
                                    <h4 class="text-[13.5px] font-medium text-gray-900 group-hover:text-green-600 transition-colors">Thương hiệu</h4>
                                    <p class="text-[12.5px] text-gray-500 mt-0.5">Quản lý hàng hóa theo thương hiệu nhà sản xuất hoặc dòng sản phẩm.</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-[12.5px] text-green-600 font-bold">{{ props.brands?.length || 0 }} thương hiệu</span>
                                    <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                                </div>
                            </div>

                            <!-- Vị trí - clickable -->
                            <div @click="showLocationManager = true" class="px-5 py-4 flex justify-between items-center hover:bg-teal-50 cursor-pointer group">
                                <div class="flex-1">
                                    <h4 class="text-[13.5px] font-medium text-gray-900 group-hover:text-teal-600 transition-colors">Vị trí</h4>
                                    <p class="text-[12.5px] text-gray-500 mt-0.5">Quản lý hàng hóa theo vị trí bán hàng hoặc lưu trữ như giá, kệ, tủ.</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-[12.5px] text-teal-600 font-bold">{{ props.locations?.length || 0 }} vị trí</span>
                                    <svg class="w-4 h-4 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section: Gia von, ton kho -->
                    <div id="cost-section" class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-3 border-b bg-gray-50/50">
                            <h3 class="font-bold text-[14px] text-gray-800">Giá vốn, tồn kho</h3>
                        </div>
                        <div class="px-5 py-5 space-y-6">
                            <div>
                                <h4 class="text-[13.5px] font-semibold text-gray-900 mb-3">Phương pháp tính giá vốn</h4>
                                <div class="space-y-4">
                                    <label class="flex items-start gap-3 cursor-pointer group">
                                        <input type="radio" v-model="form.settings.inventory_costing_method" value="fixed" @change="submit" class="mt-1 text-blue-600 focus:ring-blue-500 border-gray-300">
                                        <div class="flex-1">
                                            <p class="text-[13.5px] font-medium text-gray-900 group-hover:text-blue-600">Giá vốn cố định</p>
                                            <p class="text-[12px] text-gray-500 leading-relaxed">Giá vốn được xác định theo <span class="font-bold">giá nhập đầu tiên</span> hoặc do người dùng tự nhập.</p>
                                        </div>
                                    </label>
                                    <label class="flex items-start gap-3 cursor-pointer group">
                                        <input type="radio" v-model="form.settings.inventory_costing_method" value="average" @change="submit" class="mt-1 text-blue-600 focus:ring-blue-500 border-gray-300">
                                        <div class="flex-1">
                                            <p class="text-[13.5px] font-medium text-gray-900 group-hover:text-blue-600">Giá vốn trung bình</p>
                                            <p class="text-[12px] text-gray-500 leading-relaxed">Giá vốn được tính theo phương pháp trung bình dựa trên giao dịch nhập hàng và trả hàng nhập.</p>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <div class="pt-5 border-t flex justify-between items-center">
                                <div>
                                    <h4 class="text-[13.5px] font-medium text-gray-900">Quản lý tồn kho theo Serial/IMEI</h4>
                                    <p class="text-[12.5px] text-gray-500 mt-0.5">Theo dõi thiết bị điện từ và các hàng hóa có bảo hành khác bằng Serial/IMEI trong mọi giao dịch <a href="#" class="text-blue-600 hover:underline">Xem hướng dẫn</a></p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" v-model="form.settings.product_use_serial" @change="submit" class="sr-only peer">
                                    <div class="w-10 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Section: Bao hanh, bao tri -->
                    <div id="warranty-section" class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-3 border-b bg-gray-50/50 flex justify-between items-center">
                            <h3 class="font-bold text-[14px] text-gray-800">Bảo hành, bảo trì</h3>
                            <div class="flex items-center gap-2 text-[12.5px] text-gray-500 font-medium cursor-pointer hover:text-blue-600">
                                Đã thiết lập <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </div>
                        </div>
                        <div class="px-5 py-4">
                            <h4 class="text-[13.5px] font-medium text-gray-900">Bảo hành, bảo trì, yêu cầu sửa chữa</h4>
                            <p class="text-[12.5px] text-gray-500 mt-0.5">Quản lý bảo hành và sửa chữa hàng hóa với tính năng nhắc lịch và hẹn trả khách. <a href="#" class="text-blue-600 hover:underline">Xem hướng dẫn</a></p>
                        </div>
                    </div>

                    <!-- Section: San xuat -->
                    <div id="production-section" class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-3 border-b bg-gray-50/50">
                            <h3 class="font-bold text-[14px] text-gray-800">Sản xuất</h3>
                        </div>
                        <div class="px-5 py-4 flex justify-between items-center">
                            <div>
                                <h4 class="text-[13.5px] font-medium text-gray-900">Sản xuất hàng hóa</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Cho phép thiết lập nguyên liệu thành phần và ghi nhận hàng hóa thành phẩm. <a href="#" class="text-blue-600 hover:underline">Quản lý sản xuất</a></p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" v-model="form.settings.production_enabled" @change="submit" class="sr-only peer">
                                <div class="w-10 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                    </div>

                    <!-- Section: Nha cung cap -->
                    <div id="vendor-section" class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-3 border-b bg-gray-50/50">
                            <h3 class="font-bold text-[14px] text-gray-800">Nhà cung cấp</h3>
                        </div>
                        <div class="px-5 py-4 flex justify-between items-center">
                            <div>
                                <h4 class="text-[13.5px] font-medium text-gray-900">Quản lý nhà cung cấp theo chi nhánh</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Quản lý danh sách nhà cung cấp và công nợ theo từng chi nhánh thay vì trên toàn hệ thống. <a href="#" class="text-blue-600 hover:underline">Xem hướng dẫn</a></p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" v-model="form.settings.supplier_by_branch" @change="submit" class="sr-only peer">
                                <div class="w-10 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                    </div>

                    <!-- Section: Nhan vien -->
                    <div id="user-section" class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-3 border-b bg-gray-50/50">
                            <h3 class="font-bold text-[14px] text-gray-800">Người dùng</h3>
                        </div>
                        <div class="px-5 py-4 flex justify-between items-center">
                            <div>
                                <h4 class="text-[13.5px] font-medium text-gray-900">Phân quyền người dùng theo nhóm hàng</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Cho phép phân quyền nhân viên chỉ quản lý các nhóm hàng cụ thể.</p>
                                <p class="text-[11px] text-gray-400 mt-0.5 italic">Khi bật, bạn có thể thiết lập quyền truy cập chi tiết cho từng nhân viên trong "Quản lý người dùng". <a href="#" class="text-blue-600 hover:underline">Xem hướng dẫn</a></p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" v-model="form.settings.user_permission_by_category" @change="submit" class="sr-only peer">
                                <div class="w-10 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                    </div>

                    <!-- Section: Nhap hang -->
                    <div id="purchase-section" class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-3 border-b bg-gray-50/50">
                            <h3 class="font-bold text-[14px] text-gray-800">Nhập hàng, đặt hàng nhập</h3>
                        </div>
                        <div class="divide-y divide-gray-100">
                            <div class="px-5 py-4 flex justify-between items-center">
                                <div>
                                    <h4 class="text-[13.5px] font-medium text-gray-900">Đặt hàng nhập</h4>
                                    <p class="text-[12.5px] text-gray-500 mt-0.5">Cho phép tạo và quản lý đơn đặt hàng nhập từ nhà cung cấp. <a href="#" class="text-blue-600 hover:underline">Xem hướng dẫn</a></p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" v-model="form.settings.purchase_order_enabled" @change="submit" class="sr-only peer">
                                    <div class="w-10 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                </label>
                            </div>
                            <div class="px-5 py-4 flex justify-between items-center hover:bg-gray-50/80 cursor-pointer group">
                                <div>
                                    <h4 class="text-[13.5px] font-medium text-gray-900 group-hover:text-blue-600 transition-colors">Quản lý chi phí nhập hàng</h4>
                                    <p class="text-[12.5px] text-gray-500 mt-0.5">Theo dõi các loại chi phí nhập hàng phát sinh như phí dịch vụ, phí lưu kho.</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-[12.5px] text-gray-400 font-medium">1 chi phí nhập hàng</span>
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section: Khac -->
                    <div id="other-section" class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-3 border-b bg-gray-50/50">
                            <h3 class="font-bold text-[14px] text-gray-800">Khác</h3>
                        </div>
                        <div class="divide-y divide-gray-100">
                             <div class="px-5 py-4 flex justify-between items-center">
                                <div>
                                    <h4 class="text-[13.5px] font-medium text-gray-900">Cho phép thay đổi thời gian giao dịch</h4>
                                    <p class="text-[12.5px] text-gray-500 mt-0.5">Đã áp dụng cho Đặt hàng nhập, Nhập hàng, Trả hàng nhập, Chuyển hàng, Xuất hủy, Kiểm kho, Sản xuất. Để thiết lập các giao dịch khác, hãy đi đến <a href="#" class="text-blue-600 hover:underline">Đơn hàng</a></p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" v-model="form.settings.transaction_allow_change_time" @change="submit" class="sr-only peer">
                                    <div class="w-10 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                </label>
                            </div>
                            <div class="px-5 py-4 flex justify-between items-center">
                                <div>
                                    <h4 class="text-[13.5px] font-medium text-gray-900">Cho phép giao dịch khi hết tồn kho</h4>
                                    <p class="text-[12.5px] text-gray-500 mt-0.5">Để thiết lập cho các giao dịch Chuyển hàng, Sản xuất, Xuất hủy và Trả hàng nhập, hãy đi đến <a href="#" class="text-blue-600 hover:underline">Đơn hàng</a></p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" v-model="form.settings.inventory_allow_oversell" @change="submit" class="sr-only peer">
                                    <div class="w-10 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-show="activeCategory !== 'hang-hoa' && activeCategory !== 'don-hang' && activeCategory !== 'khach-hang' && activeCategory !== 'so-quy' && activeCategory !== 'sua-chua' && activeCategory !== 'nguoi-dung'" class="flex flex-col items-center justify-center py-20 bg-white rounded border border-dashed border-gray-300">
                    <svg class="w-12 h-12 text-gray-200 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg>
                    <p class="text-gray-400 font-medium text-[14px]">Tính năng thiết lập {{ categories.find(c => c.id === activeCategory)?.name }} đang phát triển</p>
                    <button @click="activeCategory = 'hang-hoa'" class="mt-4 text-blue-600 text-sm font-semibold hover:underline underline-offset-4">Quay lại Thiết lập Hàng hóa</button>
                </div>

                <!-- Khach Hang Settings -->
                <div v-show="activeCategory === 'khach-hang'" class="space-y-4">
                    <h2 id="cust-manage" class="text-xl font-bold text-gray-800 mb-4">Quản lý khách hàng</h2>

                    <!-- 1. Quản lý KH theo chi nhánh -->
                    <div class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Quản lý khách hàng theo chi nhánh</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Quản lý danh sách khách hàng, công nợ và điểm theo từng chi nhánh thay vì trên toàn hệ thống. <a href="#" class="text-blue-600 hover:underline">Xem hướng dẫn</a></p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" v-model="form.settings.customer_manage_by_branch" @change="submit" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                    </div>

                    <!-- 2. Quản lý KH theo người phụ trách -->
                    <div class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Quản lý khách hàng theo người phụ trách</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Thiết lập một hoặc nhiều người phụ trách khách hàng, nhóm khách hàng. <a href="#" class="text-blue-600 hover:underline">Xem hướng dẫn</a></p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" v-model="form.settings.customer_manage_by_staff" @change="submit" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                    </div>

                    <!-- 3. KH đồng thời là NCC -->
                    <div class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Quản lý khách hàng đồng thời là nhà cung cấp</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Theo dõi công nợ và giao dịch của đối tác vừa là khách hàng, vừa là nhà cung cấp. <a href="#" class="text-blue-600 hover:underline">Xem hướng dẫn</a></p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" v-model="form.settings.customer_is_vendor" @change="submit" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                    </div>

                    <!-- 4. Cảnh báo công nợ KH -->
                    <div class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Cảnh báo công nợ khách hàng</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Hiển thị cảnh báo khi khách hàng nợ vượt mức hoặc quá hạn. <a href="#" class="text-blue-600 hover:underline">Xem hướng dẫn</a></p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" v-model="form.settings.customer_debt_warning" @change="submit" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                        <div v-if="form.settings.customer_debt_warning" class="px-5 pb-4 flex items-center justify-between">
                            <p class="text-[12.5px] text-gray-600 italic">Đã bật cảnh báo công nợ khách hàng</p>
                            <span class="text-[12.5px] text-blue-600 font-medium cursor-pointer hover:underline border border-gray-300 rounded px-3 py-1">Xem chi tiết</span>
                        </div>
                    </div>

                    <!-- 5. Thiết lập thông tin bắt buộc -->
                    <div class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4">
                            <h4 class="text-[14px] font-bold text-gray-900">Thiết lập thông tin bắt buộc</h4>
                            <p class="text-[12.5px] text-gray-500 mt-0.5 mb-4">Thiết lập tính bắt buộc cho các trường thông tin khách hàng.</p>
                            <div class="grid grid-cols-2 gap-x-8 gap-y-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-[13px] text-gray-700">Tên khách hàng</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" v-model="form.settings.customer_required_name" @change="submit" class="sr-only peer">
                                        <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-[13px] text-gray-700">Ngày sinh</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" v-model="form.settings.customer_required_birthday" @change="submit" class="sr-only peer">
                                        <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-[13px] text-gray-700">Số điện thoại</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" v-model="form.settings.customer_required_phone" @change="submit" class="sr-only peer">
                                        <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-[13px] text-gray-700">Địa chỉ</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" v-model="form.settings.customer_required_address" @change="submit" class="sr-only peer">
                                        <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-[13px] text-gray-700">Email</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" v-model="form.settings.customer_required_email" @change="submit" class="sr-only peer">
                                        <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-[13px] text-gray-700">Facebook</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" v-model="form.settings.customer_required_facebook" @change="submit" class="sr-only peer">
                                        <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-[13px] text-gray-700">Giới tính</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" v-model="form.settings.customer_required_gender" @change="submit" class="sr-only peer">
                                        <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Chăm sóc khách hàng -->
                    <h2 id="cust-loyalty" class="text-xl font-bold text-gray-800 mt-8 mb-4">Chăm sóc khách hàng</h2>

                    <!-- 6. Tích điểm -->
                    <div class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Tích điểm</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Cho phép khách hàng tích điểm khi mua hàng. Điểm tích lũy có thể quy đổi để thanh toán đơn hàng. <a href="#" class="text-blue-600 hover:underline">Xem hướng dẫn</a></p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" v-model="form.settings.customer_loyalty_enabled" @change="submit" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                        <div v-if="form.settings.customer_loyalty_enabled" class="px-5 pb-4 space-y-3">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-[13px] font-semibold text-gray-700">Hình thức tích điểm</p>
                                    <p class="text-[12.5px] text-gray-500">Tích điểm theo hóa đơn: Mua 0 đ tích 1 điểm</p>
                                </div>
                                <span class="text-[12.5px] text-blue-600 font-medium cursor-pointer hover:underline border border-gray-300 rounded px-3 py-1">Xem chi tiết</span>
                            </div>
                        </div>
                    </div>

                    <!-- 7. Khuyến mại -->
                    <div class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Khuyến mại</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Cho phép quản lý và áp dụng khuyến mại theo hàng hóa hoặc giá trị đơn hàng. <a href="#" class="text-blue-600 hover:underline">Quản lý khuyến mại</a></p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" v-model="form.settings.customer_promotion_enabled" @change="submit" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                        <div v-if="form.settings.customer_promotion_enabled" class="px-5 pb-4 space-y-2.5 -mt-1">
                            <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                                <input type="checkbox" v-model="form.settings.customer_promotion_combine" @change="submit" class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                Áp dụng gộp các chương trình khuyến mại
                            </label>
                            <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                                <input type="checkbox" v-model="form.settings.customer_promotion_on_order" @change="submit" class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                Áp dụng khuyến mại khi đặt hàng
                            </label>
                            <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                                <input type="checkbox" v-model="form.settings.customer_promotion_auto_invoice" @change="submit" class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                Áp dụng tự động khuyến mại cho hóa đơn
                            </label>
                        </div>
                    </div>

                    <!-- 8. Voucher -->
                    <div class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Voucher</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Cho phép quản lý, phát hành và áp dụng phiếu mua hàng. <a href="#" class="text-blue-600 hover:underline">Quản lý voucher</a></p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" v-model="form.settings.customer_voucher_enabled" @change="submit" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                        <div v-if="form.settings.customer_voucher_enabled" class="px-5 pb-4 space-y-2.5 -mt-1">
                            <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                                <input type="checkbox" v-model="form.settings.customer_voucher_combine_points" @change="submit" class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                Áp dụng đồng thời với thanh toán bằng điểm và các chương trình khuyến mại khác
                                <svg class="w-3.5 h-3.5 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            </label>
                            <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                                <input type="checkbox" v-model="form.settings.customer_voucher_refund" @change="submit" class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                Cho phép trả hàng với hóa đơn có thanh toán bằng voucher
                            </label>
                        </div>
                    </div>

                    <!-- 9. Coupon -->
                    <div class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Coupon</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Cho phép quản lý, phát hành và áp dụng mã giảm giá. <a href="#" class="text-blue-600 hover:underline">Quản lý coupon</a></p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" v-model="form.settings.customer_coupon_enabled" @change="submit" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                        <div v-if="form.settings.customer_coupon_enabled" class="px-5 pb-4 space-y-2.5 -mt-1">
                            <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                                <input type="checkbox" v-model="form.settings.customer_coupon_combine" @change="submit" class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                Áp dụng đồng thời với các chương trình khuyến mại khác
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Don Hang Settings -->
                <div v-show="activeCategory === 'don-hang'" class="space-y-4">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Quản lý đơn hàng</h2>

                    <!-- 1. Đặt hàng -->
                    <div id="order-dat-hang" class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Đặt hàng</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Cho phép tạo và quản lý đơn đặt hàng. <a href="#" class="text-blue-600 hover:underline">Xem hướng dẫn</a></p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" v-model="form.settings.order_enabled" @change="submit" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                        <div v-if="form.settings.order_enabled" class="px-5 pb-4 space-y-2.5 -mt-1">
                            <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                                <input type="checkbox" v-model="form.settings.order_allow_when_out_of_stock" @change="submit" class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                Cho phép đặt hàng khi hết tồn kho
                                <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            </label>
                            <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                                <input type="checkbox" v-model="form.settings.order_allow_sell_reserved" @change="submit" class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                Cho phép bán hàng, chuyển hàng khi hàng hóa đã được đặt trước
                                <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            </label>
                            <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                                <input type="checkbox" v-model="form.settings.order_allow_cross_branch" @change="submit" class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                Cho phép chuyển đơn đặt hàng sang chi nhánh khác
                            </label>
                        </div>
                    </div>

                    <!-- 2. Cho phép giao dịch khi hết tồn kho -->
                    <div id="order-out-of-stock" class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Cho phép giao dịch khi hết tồn kho</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Ngay cả khi không đủ tồn kho, bạn vẫn có thể bán hàng, chuyển hàng, trả hàng nhập, sản xuất và xuất hủy. Tồn kho âm sẽ được điều chỉnh khi nhận hàng.</p>
                                <p class="text-[11.5px] text-gray-400 mt-1 italic">Lưu ý: Không áp dụng cho hàng hóa được quản lý theo lô, hạn sử dụng.</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" v-model="form.settings.allow_transaction_when_out_of_stock" @change="submit" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                    </div>

                    <!-- 3. Cho phép in báo giá khi bán hàng -->
                    <div id="order-print-quote" class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Cho phép in báo giá khi bán hàng</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Chỉ hiển thị nút In trên màn hình bán hàng sau khi giao dịch đã hoàn thành</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" v-model="form.settings.allow_print_quote" @change="submit" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                    </div>

                    <!-- 4. Hiển thị xác nhận trước khi hoàn thành đơn hàng -->
                    <div id="order-confirm-complete" class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Hiển thị xác nhận trước khi hoàn thành đơn hàng</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Hệ thống sẽ thêm bước xác nhận cuối cùng trước khi hoàn thành đơn hàng, giúp thu ngân kiểm tra kỹ và tránh trường hợp khách hàng chưa thanh toán.</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" v-model="form.settings.order_confirm_before_complete" @change="submit" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                    </div>

                    <!-- 5. Giới hạn thời gian trả hàng -->
                    <div id="order-return-limit" class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Giới hạn thời gian trả hàng</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Cho phép thiết lập khoảng thời gian mà khách hàng được phép trả hàng sau khi mua</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" v-model="form.settings.return_time_limit_enabled" @change="submit" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                        <div v-if="form.settings.return_time_limit_enabled" class="px-5 pb-4 space-y-4">
                            <div class="flex items-center gap-3 text-[13px] text-gray-700">
                                <span>Kể từ ngày mua, khách hàng được trả hàng trong vòng</span>
                                <input type="number" v-model.number="form.settings.return_time_limit_days" @change="submit" min="1" max="365" class="w-16 border border-gray-300 rounded px-2 py-1 text-center text-sm focus:ring-blue-500 focus:border-blue-500">
                                <span>ngày</span>
                                <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[13px] font-bold text-gray-800">Xử lý khi trả hàng quá hạn:</p>
                                <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                                    <input type="radio" v-model="form.settings.return_overdue_action" value="warn" @change="submit" name="return_overdue" class="w-4 h-4 text-blue-600 focus:ring-blue-500">
                                    Hiển thị cảnh báo khi trả hàng
                                    <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </label>
                                <label class="flex items-center gap-2.5 cursor-pointer text-[13px] text-gray-700">
                                    <input type="radio" v-model="form.settings.return_overdue_action" value="block" @change="submit" name="return_overdue" class="w-4 h-4 text-blue-600 focus:ring-blue-500">
                                    Không cho phép trả hàng
                                    <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- 6. Không cho phép thay đổi thời gian giao dịch -->
                    <div id="order-block-time" class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Không cho phép thay đổi thời gian giao dịch</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Tùy chỉnh việc thay đổi thời gian cho mỗi loại giao dịch</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" v-model="form.settings.block_change_transaction_time" @change="submit" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                    </div>

                    <!-- 7. Chặn chỉnh sửa/hủy hóa đơn đã phát hành HĐĐT -->
                    <div id="order-block-einvoice" class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Chặn chỉnh sửa/hủy hóa đơn đã phát hành HĐĐT</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Khi bật, hệ thống sẽ không cho phép chỉnh sửa hoặc hủy các hóa đơn đã phát hành hóa đơn điện tử</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" v-model="form.settings.block_edit_cancel_einvoice" @change="submit" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                    </div>

                    <!-- 8. Quản lý thu khác -->
                    <div id="order-other-fees" class="bg-white rounded shadow-sm border overflow-hidden cursor-pointer hover:shadow-md transition-shadow" @click="showOtherFeeManager = true">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Quản lý thu khác</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Theo dõi các loại thu khác khi bán hàng như phí dịch vụ, phí giao hàng</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-[12.5px] text-blue-600 font-medium cursor-pointer hover:underline">{{ otherFees?.length || 0 }} loại thu khác</span>
                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== SỔ QUỸ TAB ==================== -->
                <div v-show="activeCategory === 'so-quy'" class="space-y-4">
                    <h2 id="sq-bank-accounts" class="text-xl font-bold text-gray-800 mb-4">Quản lý tài khoản thu chi</h2>

                    <!-- Bank Accounts Manager Card -->
                    <div class="bg-white rounded shadow-sm border overflow-hidden cursor-pointer hover:shadow-md transition-shadow" @click="showBankAccountManager = true">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Quản lý tài khoản ngân hàng & ví điện tử</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Thiết lập tài khoản ngân hàng, ví điện tử dùng cho phiếu thu chi và thanh toán đơn hàng</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-[12.5px] text-blue-600 font-medium cursor-pointer hover:underline">{{ bankAccounts?.length || 0 }} tài khoản</span>
                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== SỬA CHỮA TAB ==================== -->
                <div v-show="activeCategory === 'sua-chua'" class="space-y-4">
                    <h2 id="repair-toggle" class="text-xl font-bold text-gray-800 mb-4">Thiết lập sửa chữa</h2>

                    <!-- Toggle: repair_tracking_enabled -->
                    <div class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Bật module sửa chữa / lắp ráp</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Khi bật, menu Sửa chữa sẽ xuất hiện. Bạn có thể tạo phiếu sửa, xuất linh kiện, theo dõi trạng thái serial.</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" class="sr-only peer" v-model="form.settings.repair_tracking_enabled" @change="submit">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                    </div>

                    <!-- Toggle: repair_performance_salary_enabled -->
                    <div class="bg-white rounded shadow-sm border overflow-hidden">
                        <div class="px-5 py-4 flex justify-between items-start">
                            <div class="flex-1">
                                <h4 class="text-[14px] font-bold text-gray-900">Tính lương theo năng suất sửa chữa</h4>
                                <p class="text-[12.5px] text-gray-500 mt-0.5">Khi bật, lương cơ bản sẽ được nhân với hệ số năng suất dựa trên tỷ lệ hoàn thành sửa chữa của nhân viên.</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer ml-4">
                                <input type="checkbox" class="sr-only peer" v-model="form.settings.repair_performance_salary_enabled" @change="submit">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                    </div>

                    <h2 id="repair-tiers" class="text-xl font-bold text-gray-800 mb-4 mt-8">Bậc năng suất sửa chữa</h2>
                    <p class="text-[12.5px] text-gray-500 mb-3">Cấu hình các bậc tỷ lệ hoàn thành (%) và hệ số lương tương ứng. Xem chi tiết tại <a href="/repairs/performance" class="text-blue-600 hover:underline">Báo cáo năng suất</a>.</p>

                    <!-- Tier form -->
                    <div class="bg-blue-50 border border-blue-200 rounded p-4 mb-3">
                        <div v-if="tierError" class="text-red-500 text-sm mb-2">{{ tierError }}</div>
                        <div class="grid grid-cols-5 gap-2 items-end">
                            <div>
                                <label class="text-xs font-semibold text-gray-600">Xếp loại</label>
                                <input v-model="tierForm.label" type="text" placeholder="VD: Tốt" class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-600">Từ %</label>
                                <input v-model.number="tierForm.min_percent" type="number" min="0" max="100" class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-600">Đến %</label>
                                <input v-model.number="tierForm.max_percent" type="number" min="0" max="100" class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-600">Hệ số lương %</label>
                                <input v-model.number="tierForm.salary_percent" type="number" min="0" max="200" class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm" />
                            </div>
                            <div class="flex gap-1">
                                <button @click="saveTier" class="px-3 py-1.5 bg-blue-600 text-white rounded text-sm font-semibold hover:bg-blue-700">
                                    {{ editingTierId ? 'Cập nhật' : 'Thêm' }}
                                </button>
                                <button v-if="editingTierId" @click="resetTierForm" class="px-3 py-1.5 border border-gray-300 rounded text-sm font-semibold hover:bg-gray-50">Hủy</button>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded shadow-sm border overflow-hidden">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                                <tr>
                                    <th class="px-4 py-3 text-left">Xếp loại</th>
                                    <th class="px-4 py-3 text-center">Từ %</th>
                                    <th class="px-4 py-3 text-center">Đến %</th>
                                    <th class="px-4 py-3 text-center">Hệ số lương %</th>
                                    <th class="px-4 py-3 text-center w-28"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="tier in repairTiers" :key="tier.id" class="border-t">
                                    <td class="px-4 py-3 font-semibold">{{ tier.label }}</td>
                                    <td class="px-4 py-3 text-center">{{ tier.min_percent }}%</td>
                                    <td class="px-4 py-3 text-center">{{ tier.max_percent }}%</td>
                                    <td class="px-4 py-3 text-center font-bold">{{ tier.salary_percent }}%</td>
                                    <td class="px-4 py-3 text-center">
                                        <button @click="editTier(tier)" class="text-blue-600 text-xs font-semibold mr-2 hover:underline">Sửa</button>
                                        <button @click="deleteTier(tier)" class="text-red-500 text-xs font-semibold hover:underline">Xóa</button>
                                    </td>
                                </tr>
                                <tr v-if="!repairTiers.length">
                                    <td colspan="5" class="text-center py-6 text-gray-400">Chưa có bậc nào.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ==================== NGUOI DUNG TAB ==================== -->
            <div v-show="activeCategory === 'nguoi-dung'" class="space-y-4">
                <!-- Sub-tabs -->
                <div class="flex gap-4 border-b border-gray-200 mb-2">
                    <button @click="userSubTab = 'roles'" :class="userSubTab === 'roles' ? 'border-blue-600 text-blue-600 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'" class="pb-2 border-b-2 text-[13.5px] transition-all px-1">Quản lý vai trò</button>
                    <button @click="userSubTab = 'users'" :class="userSubTab === 'users' ? 'border-blue-600 text-blue-600 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'" class="pb-2 border-b-2 text-[13.5px] transition-all px-1">Tài khoản người dùng</button>
                </div>

                <!-- === ROLES SUB-TAB === -->
                <div v-show="userSubTab === 'roles'">
                    <div class="flex justify-between items-center mb-3">
                        <h2 class="text-lg font-bold text-gray-800">Danh sách vai trò</h2>
                        <button @click="openRoleEditor()" class="bg-blue-600 text-white px-4 py-1.5 rounded text-[13px] font-semibold hover:bg-blue-700 transition-colors">+ Tạo vai trò</button>
                    </div>
                    <div v-if="loadingRoles" class="py-12 text-center text-gray-400">Đang tải...</div>
                    <div v-else class="bg-white rounded shadow-sm border overflow-hidden">
                        <table class="w-full text-[13px]">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="text-left px-4 py-2.5 font-semibold">Tên vai trò</th>
                                    <th class="text-left px-4 py-2.5 font-semibold">Mô tả</th>
                                    <th class="text-center px-4 py-2.5 font-semibold">Người dùng</th>
                                    <th class="text-center px-4 py-2.5 font-semibold w-32"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="role in roles" :key="role.id" class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <span class="font-semibold text-gray-800">{{ role.display_name }}</span>
                                        <span v-if="role.is_system" class="ml-2 text-[11px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-medium">Hệ thống</span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-500">{{ role.description || '—' }}</td>
                                    <td class="px-4 py-3 text-center text-gray-600">{{ role.users_count }}</td>
                                    <td class="px-4 py-3 text-center">
                                        <div class="flex justify-center gap-2">
                                            <button @click="openRoleEditor(role)" class="text-blue-600 hover:text-blue-800 text-[12px] font-medium">Sửa</button>
                                            <button @click="duplicateRole(role)" class="text-gray-500 hover:text-gray-700 text-[12px] font-medium">Nhân bản</button>
                                            <button v-if="!role.is_system" @click="deleteRole(role)" class="text-red-500 hover:text-red-700 text-[12px] font-medium">Xóa</button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!roles.length">
                                    <td colspan="4" class="text-center py-8 text-gray-400">Chưa có vai trò nào.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- === USERS SUB-TAB === -->
                <div v-show="userSubTab === 'users'">
                    <div class="flex justify-between items-center mb-3">
                        <h2 class="text-lg font-bold text-gray-800">Tài khoản người dùng</h2>
                        <button @click="openUserModal()" class="bg-blue-600 text-white px-4 py-1.5 rounded text-[13px] font-semibold hover:bg-blue-700 transition-colors">+ Tạo tài khoản</button>
                    </div>
                    <div v-if="loadingUsers" class="py-12 text-center text-gray-400">Đang tải...</div>
                    <div v-else class="bg-white rounded shadow-sm border overflow-hidden">
                        <table class="w-full text-[13px]">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="text-left px-4 py-2.5 font-semibold">Họ tên</th>
                                    <th class="text-left px-4 py-2.5 font-semibold">Email</th>
                                    <th class="text-left px-4 py-2.5 font-semibold">Vai trò</th>
                                    <th class="text-left px-4 py-2.5 font-semibold">Chi nhánh</th>
                                    <th class="text-center px-4 py-2.5 font-semibold">Trạng thái</th>
                                    <th class="text-center px-4 py-2.5 font-semibold w-32"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="user in users.data" :key="user.id" class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-medium text-gray-800">{{ user.name }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ user.email }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ user.role?.display_name || 'Quản trị viên' }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ user.branch?.name || '—' }}</td>
                                    <td class="px-4 py-3 text-center">
                                        <span :class="user.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-600'" class="text-[11px] px-2 py-0.5 rounded-full font-medium">{{ user.status === 'active' ? 'Hoạt động' : 'Khóa' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <div class="flex justify-center gap-2">
                                            <button @click="openUserModal(user)" class="text-blue-600 hover:text-blue-800 text-[12px] font-medium">Sửa</button>
                                            <button @click="toggleUserStatus(user)" class="text-[12px] font-medium" :class="user.status === 'active' ? 'text-orange-500 hover:text-orange-700' : 'text-green-600 hover:text-green-800'">{{ user.status === 'active' ? 'Khóa' : 'Mở khóa' }}</button>
                                            <button @click="deleteUser(user)" class="text-red-500 hover:text-red-700 text-[12px] font-medium">Xóa</button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!users.data?.length">
                                    <td colspan="6" class="text-center py-8 text-gray-400">Chưa có tài khoản nào.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div v-show="activeCategory === 'hang-hoa'" class="w-56 shrink-0 pt-12 sticky top-12 h-fit">
                <nav class="space-y-1 border-l border-gray-100 ml-4">
                    <button @click="scrollToSection('info-section')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 hover:border-l-2 hover:border-blue-600 transition-all font-medium">Thông tin hàng hóa</button>
                    <button @click="scrollToSection('cost-section')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 border-l-2 border-transparent transition-all font-medium">Giá vốn, tồn kho</button>
                    <button @click="scrollToSection('warranty-section')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 border-l-2 border-transparent transition-all font-medium">Bảo hành, bảo trì</button>
                    <button @click="scrollToSection('production-section')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 border-l-2 border-transparent transition-all font-medium">Sản xuất</button>
                    <button @click="scrollToSection('vendor-section')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 border-l-2 border-transparent transition-all font-medium">Nhà cung cấp</button>
                    <button @click="scrollToSection('user-section')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 border-l-2 border-transparent transition-all font-medium">Người dùng</button>
                    <button @click="scrollToSection('purchase-section')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 border-l-2 border-transparent transition-all font-medium">Nhập hàng, đặt hàng nhập</button>
                    <button @click="scrollToSection('other-section')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 border-l-2 border-transparent transition-all font-medium">Khác</button>
                </nav>
            </div>

            <!-- Jump Sidebar (Right) - Don Hang -->
            <div v-show="activeCategory === 'don-hang'" class="w-56 shrink-0 pt-12 sticky top-12 h-fit">
                <nav class="space-y-1 border-l border-gray-100 ml-4">
                    <button @click="scrollToSection('order-dat-hang')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 hover:border-l-2 hover:border-blue-600 transition-all font-medium">Đặt hàng</button>
                    <button @click="scrollToSection('order-out-of-stock')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 border-l-2 border-transparent transition-all font-medium">Giao dịch hết tồn kho</button>
                    <button @click="scrollToSection('order-print-quote')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 border-l-2 border-transparent transition-all font-medium">In báo giá</button>
                    <button @click="scrollToSection('order-confirm-complete')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 border-l-2 border-transparent transition-all font-medium">Xác nhận hoàn thành</button>
                    <button @click="scrollToSection('order-return-limit')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 border-l-2 border-transparent transition-all font-medium">Giới hạn trả hàng</button>
                    <button @click="scrollToSection('order-block-time')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 border-l-2 border-transparent transition-all font-medium">Thời gian giao dịch</button>
                    <button @click="scrollToSection('order-block-einvoice')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 border-l-2 border-transparent transition-all font-medium">HĐĐT</button>
                    <button @click="scrollToSection('order-other-fees')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 border-l-2 border-transparent transition-all font-medium">Thu khác</button>
                </nav>
            </div>

            <!-- Jump Sidebar (Right) - Khach Hang -->
            <div v-show="activeCategory === 'khach-hang'" class="w-56 shrink-0 pt-12 sticky top-12 h-fit">
                <nav class="space-y-1 border-l border-gray-100 ml-4">
                    <button @click="scrollToSection('cust-manage')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 hover:border-l-2 hover:border-blue-600 transition-all font-medium">Quản lý khách hàng</button>
                    <button @click="scrollToSection('cust-loyalty')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 border-l-2 border-transparent transition-all font-medium">Chăm sóc khách hàng</button>
                </nav>
            </div>

            <!-- Jump Sidebar (Right) - So Quy -->
            <div v-show="activeCategory === 'so-quy'" class="w-56 shrink-0 pt-12 sticky top-12 h-fit">
                <nav class="space-y-1 border-l border-gray-100 ml-4">
                    <button @click="scrollToSection('sq-bank-accounts')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 hover:border-l-2 hover:border-blue-600 transition-all font-medium">Quản lý tài khoản thu chi</button>
                </nav>
            </div>

            <!-- Jump Sidebar (Right) - Sua Chua -->
            <div v-show="activeCategory === 'sua-chua'" class="w-56 shrink-0 pt-12 sticky top-12 h-fit">
                <nav class="space-y-1 border-l border-gray-100 ml-4">
                    <button @click="scrollToSection('repair-toggle')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 hover:border-l-2 hover:border-blue-600 transition-all font-medium">Thiết lập sửa chữa</button>
                    <button @click="scrollToSection('repair-tiers')" class="block w-full text-left px-4 py-1.5 text-[12.5px] text-gray-500 hover:text-blue-600 border-l-2 border-transparent transition-all font-medium">Bậc năng suất</button>
                </nav>
            </div>
        </div>

        <!-- ==================== ROLE EDITOR (Full-page overlay) ==================== -->
        <div v-if="showRoleEditor" class="fixed inset-0 z-50 bg-gray-100 overflow-y-auto">
            <div class="sticky top-0 z-10 bg-white border-b shadow-sm px-6 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <button @click="showRoleEditor = false" class="text-gray-500 hover:text-gray-700">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <h2 class="text-lg font-bold text-gray-800">{{ editingRole ? 'Chỉnh sửa vai trò' : 'Tạo vai trò mới' }}</h2>
                </div>
                <button @click="saveRole" class="bg-blue-600 text-white px-6 py-1.5 rounded text-[13px] font-semibold hover:bg-blue-700">Lưu</button>
            </div>

            <div class="max-w-5xl mx-auto py-6 px-4 flex gap-6">
                <!-- Left: Form + Permissions -->
                <div class="flex-1 min-w-0">
                    <!-- Role info -->
                    <div class="bg-white rounded shadow-sm border p-5 mb-4">
                        <div v-if="roleError" class="mb-3 text-red-600 text-[13px] bg-red-50 p-2 rounded">{{ roleError }}</div>
                        <div class="grid grid-cols-2 gap-4 mb-3">
                            <div>
                                <label class="block text-[12.5px] font-semibold text-gray-600 mb-1">Tên vai trò <span class="text-red-500">*</span></label>
                                <input v-model="roleForm.display_name" type="text" class="w-full border rounded px-3 py-1.5 text-[13px] focus:ring-1 focus:ring-blue-500 focus:border-blue-500" placeholder="VD: Nhân viên bán hàng">
                            </div>
                            <div>
                                <label class="block text-[12.5px] font-semibold text-gray-600 mb-1">Mã vai trò</label>
                                <input v-model="roleForm.name" type="text" class="w-full border rounded px-3 py-1.5 text-[13px] focus:ring-1 focus:ring-blue-500 focus:border-blue-500 bg-gray-50" placeholder="Tự tạo nếu để trống">
                            </div>
                        </div>
                        <div>
                            <label class="block text-[12.5px] font-semibold text-gray-600 mb-1">Mô tả</label>
                            <input v-model="roleForm.description" type="text" class="w-full border rounded px-3 py-1.5 text-[13px] focus:ring-1 focus:ring-blue-500 focus:border-blue-500" placeholder="Mô tả ngắn gọn về vai trò này">
                        </div>
                    </div>

                    <!-- Permission groups -->
                    <div v-for="(catData, catKey) in permissionsMap" :key="catKey" :id="'perm-' + catKey" class="bg-white rounded shadow-sm border mb-3 overflow-hidden">
                        <div class="px-5 py-3 bg-gray-50 border-b flex items-center justify-between">
                            <h3 class="font-bold text-[14px] text-gray-800">{{ catData._label || catKey }}</h3>
                        </div>
                        <div class="p-5">
                            <!-- Category with sub-groups -->
                            <template v-if="catData._sub">
                                <div v-for="(subPerms, subKey) in catData._sub" :key="subKey" class="mb-4 last:mb-0">
                                    <div class="flex items-center gap-2 mb-2 pb-1 border-b border-gray-100">
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <input type="checkbox" :checked="isGroupChecked(subPerms)" :indeterminate.prop="isGroupPartial(subPerms)" @change="toggleGroup(subPerms)" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                            <span class="text-[13px] font-semibold text-gray-700">{{ subKey }}</span>
                                        </label>
                                    </div>
                                    <div class="grid grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-2 pl-1">
                                        <label v-for="(label, permKey) in subPerms" :key="permKey" class="flex items-center gap-2 cursor-pointer">
                                            <input type="checkbox" :checked="roleForm.permissions.includes(permKey)" @change="togglePermission(permKey)" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                            <span class="text-[13px] text-gray-600">{{ label }}</span>
                                        </label>
                                    </div>
                                </div>
                            </template>
                            <!-- Flat category (no sub-groups) -->
                            <template v-else>
                                <div class="grid grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-2">
                                    <template v-for="(val, key) in catData" :key="key">
                                        <label v-if="key !== '_label'" class="flex items-center gap-2 cursor-pointer">
                                            <input type="checkbox" :checked="roleForm.permissions.includes(key)" @change="togglePermission(key)" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                            <span class="text-[13px] text-gray-600">{{ val }}</span>
                                        </label>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Right: Jump links sidebar -->
                <div class="w-48 shrink-0 sticky top-20 h-fit hidden lg:block">
                    <nav class="space-y-1 border-l border-gray-200 ml-2">
                        <button v-for="(catData, catKey) in permissionsMap" :key="catKey" @click="scrollToSection('perm-' + catKey)" class="block w-full text-left px-3 py-1.5 text-[12px] text-gray-500 hover:text-blue-600 hover:border-l-2 hover:border-blue-600 transition-all font-medium">{{ catData._label || catKey }}</button>
                    </nav>
                </div>
            </div>
        </div>

        <!-- ==================== USER MODAL ==================== -->
        <div v-if="showUserModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4">
                <div class="flex items-center justify-between px-5 py-3 border-b">
                    <h3 class="font-bold text-[15px] text-gray-800">{{ editingUser ? 'Chỉnh sửa tài khoản' : 'Tạo tài khoản mới' }}</h3>
                    <button @click="showUserModal = false" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="p-5 space-y-3">
                    <div v-if="userError" class="text-red-600 text-[13px] bg-red-50 p-2 rounded">{{ userError }}</div>
                    <div>
                        <label class="block text-[12.5px] font-semibold text-gray-600 mb-1">Họ tên <span class="text-red-500">*</span></label>
                        <input v-model="userForm.name" type="text" class="w-full border rounded px-3 py-1.5 text-[13px] focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-[12.5px] font-semibold text-gray-600 mb-1">Email <span class="text-red-500">*</span></label>
                        <input v-model="userForm.email" type="email" class="w-full border rounded px-3 py-1.5 text-[13px] focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-[12.5px] font-semibold text-gray-600 mb-1">Mật khẩu {{ editingUser ? '(để trống nếu không đổi)' : '' }} <span v-if="!editingUser" class="text-red-500">*</span></label>
                        <input v-model="userForm.password" type="password" class="w-full border rounded px-3 py-1.5 text-[13px] focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-[12.5px] font-semibold text-gray-600 mb-1">Số điện thoại</label>
                        <input v-model="userForm.phone" type="text" class="w-full border rounded px-3 py-1.5 text-[13px] focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[12.5px] font-semibold text-gray-600 mb-1">Vai trò</label>
                            <select v-model="userForm.role_id" class="w-full border rounded px-3 py-1.5 text-[13px] focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                <option :value="null">Quản trị viên (Admin)</option>
                                <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.display_name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[12.5px] font-semibold text-gray-600 mb-1">Chi nhánh chính</label>
                            <select v-model="userForm.branch_id" class="w-full border rounded px-3 py-1.5 text-[13px] focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                <option :value="null">— Không chọn —</option>
                                <option v-for="b in props.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[12.5px] font-semibold text-gray-600 mb-1">Trạng thái</label>
                        <select v-model="userForm.status" class="w-full border rounded px-3 py-1.5 text-[13px] focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                            <option value="active">Hoạt động</option>
                            <option value="locked">Khóa</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-2 px-5 py-3 border-t bg-gray-50 rounded-b-lg">
                    <button @click="showUserModal = false" class="px-4 py-1.5 text-[13px] rounded border text-gray-600 hover:bg-gray-100 font-medium">Hủy</button>
                    <button @click="saveUser" class="px-5 py-1.5 text-[13px] rounded bg-blue-600 text-white font-semibold hover:bg-blue-700">Lưu</button>
                </div>
            </div>
        </div>

        <!-- Popup Managers -->
        <CategoryManager :categories="props.categories" :show="showCategoryManager" @close="showCategoryManager = false" />
        <BrandManager :brands="props.brands" :show="showBrandManager" @close="showBrandManager = false" />
        <UnitManager :units="props.units" :show="showUnitManager" @close="showUnitManager = false" />
        <AttributeManager :attributes="props.attributes" :show="showAttributeManager" @close="showAttributeManager = false" />
        <LocationManager :locations="props.locations" :show="showLocationManager" @close="showLocationManager = false" />
        <OtherFeeManager :otherFees="props.otherFees" :branches="props.branches" :show="showOtherFeeManager" @close="showOtherFeeManager = false" />
        <BankAccountManager :bankAccounts="props.bankAccounts" :branches="props.branches" :show="showBankAccountManager" @close="showBankAccountManager = false" />
    </AppLayout>
</template>

<style scoped>
/* Custom switch sizes if needed */
</style>
