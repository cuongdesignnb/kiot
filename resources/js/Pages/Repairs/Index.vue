<script setup>
import { ref, watch, computed } from "vue";
import { Head, Link, usePage } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import SortableHeader from "@/Components/SortableHeader.vue";
import axios from "axios";

const props = defineProps({
    branches: Array,
    employees: Array,
});

const repairs = ref({ data: [], total: 0 });
const loading = ref(false);
const filters = ref({
    search: "",
    status: "",
    assigned_employee_id: "",
    branch_id: "",
    from: "",
    to: "",
    per_page: 20,
    page: 1,
    sort_by: "",
    sort_direction: "",
});
const showCreateModal = ref(false);
const createForm = ref({
    serial_imei_id: null,
    issue_description: "",
    branch_id: null,
    notes: "",
    deadline: "",
});
const serialSearch = ref("");
const serialResults = ref([]);
const selectedSerial = ref(null);
const createError = ref("");

const loadRepairs = async () => {
    loading.value = true;
    try {
        const params = {};
        Object.entries(filters.value).forEach(([k, v]) => {
            if (v !== "" && v !== null) params[k] = v;
        });
        const res = await axios.get("/api/device-repairs", { params });
        repairs.value = res.data;
    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
};

let searchTimeout;
watch(() => filters.value.search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        filters.value.page = 1;
        loadRepairs();
    }, 400);
});
watch(() => [filters.value.status, filters.value.assigned_employee_id, filters.value.branch_id], () => {
    filters.value.page = 1;
    loadRepairs();
});

const handleSort = (field, direction) => {
    filters.value.sort_by = field;
    filters.value.sort_direction = direction;
    filters.value.page = 1;
    loadRepairs();
};

// Serial search for create modal
let serialTimeout;
watch(serialSearch, (val) => {
    clearTimeout(serialTimeout);
    if (!val || val.length < 2) { serialResults.value = []; return; }
    serialTimeout = setTimeout(async () => {
        try {
            const res = await axios.get("/api/device-repairs/search-serials", {
                params: { q: val }
            });
            serialResults.value = res.data || [];
        } catch (e) {
            serialResults.value = [];
        }
    }, 300);
});

const selectSerial = (serial) => {
    selectedSerial.value = serial;
    createForm.value.serial_imei_id = serial.id;
    serialSearch.value = serial.serial_number;
    serialResults.value = [];
};

const submitCreate = async () => {
    createError.value = "";
    try {
        await axios.post("/api/device-repairs", createForm.value);
        showCreateModal.value = false;
        createForm.value = { serial_imei_id: null, issue_description: "", branch_id: null, notes: "", deadline: "" };
        selectedSerial.value = null;
        serialSearch.value = "";
        loadRepairs();
    } catch (e) {
        createError.value = e.response?.data?.message || "Lỗi khi tạo phiếu.";
    }
};

const goPage = (page) => {
    filters.value.page = page;
    loadRepairs();
};

const formatCurrency = (v) => v ? Number(v).toLocaleString("vi-VN") : "0";

const statusBadge = (status) => {
    const map = {
        pending: { label: "Chờ xử lý", cls: "bg-yellow-100 text-yellow-700" },
        in_progress: { label: "Đang sửa", cls: "bg-blue-100 text-blue-700" },
        completed: { label: "Hoàn thành", cls: "bg-green-100 text-green-700" },
    };
    return map[status] || { label: status, cls: "bg-gray-100 text-gray-600" };
};

const repairStatusBadge = (rs) => {
    const map = {
        not_started: { label: "Chưa làm", cls: "bg-red-100 text-red-600" },
        repairing: { label: "Đang xử lý", cls: "bg-yellow-100 text-yellow-600" },
        ready: { label: "Sẵn bán", cls: "bg-green-100 text-green-600" },
    };
    return map[rs] || { label: "", cls: "" };
};

loadRepairs();
</script>

<template>
    <Head title="Phiếu sửa chữa" />
    <AppLayout>
        <div class="p-6">
            <!-- Header -->
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-xl font-bold text-gray-800">Phiếu sửa chữa</h1>
                <button
                    @click="showCreateModal = true; createError = ''; selectedSerial = null; serialSearch = ''; createForm = { serial_imei_id: null, issue_description: '', branch_id: null, notes: '', deadline: '' }"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition"
                >
                    + Tạo phiếu sửa
                </button>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap gap-3 mb-4">
                <input
                    v-model="filters.search"
                    type="text"
                    placeholder="Tìm mã phiếu, serial, sản phẩm..."
                    class="border border-gray-300 rounded-lg px-3 py-2 w-64 text-sm focus:border-blue-500 outline-none"
                />
                <select v-model="filters.status" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">Tất cả trạng thái</option>
                    <option value="pending">Chờ xử lý</option>
                    <option value="in_progress">Đang sửa</option>
                    <option value="completed">Hoàn thành</option>
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

            <!-- Table -->
            <div class="bg-white border rounded-lg shadow-sm overflow-hidden">
                <div v-if="loading" class="text-center py-10 text-gray-400">Đang tải...</div>
                <table v-else class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <SortableHeader label="Mã phiếu" field="code" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" class="px-4 py-3 text-left" @sort="handleSort" />
                            <th class="px-4 py-3 text-left">Serial</th>
                            <th class="px-4 py-3 text-left">Sản phẩm</th>
                            <th class="px-4 py-3 text-left">NV phụ trách</th>
                            <SortableHeader label="Trạng thái" field="status" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="center" class="px-4 py-3 text-center" @sort="handleSort" />
                            <th class="px-4 py-3 text-center">Serial ST</th>
                            <SortableHeader label="Deadline" field="deadline" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="center" class="px-4 py-3 text-center" @sort="handleSort" />
                            <SortableHeader label="Giá gốc" field="original_cost" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="right" class="px-4 py-3 text-right" @sort="handleSort" />
                            <SortableHeader label="Giá LK" field="parts_cost" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="right" class="px-4 py-3 text-right" @sort="handleSort" />
                            <SortableHeader label="Tổng giá vốn" field="total_cost" default-direction="desc" :current-sort="filters.sort_by" :current-direction="filters.sort_direction" align="right" class="px-4 py-3 text-right" @sort="handleSort" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!repairs.data?.length">
                            <td colspan="10" class="text-center py-8 text-gray-400">Chưa có phiếu sửa chữa nào.</td>
                        </tr>
                        <tr
                            v-for="r in repairs.data"
                            :key="r.id"
                            class="border-t hover:bg-gray-50 cursor-pointer"
                            @click="$inertia.visit(`/repairs/${r.id}`)"
                        >
                            <td class="px-4 py-3 font-semibold text-blue-600">{{ r.code }}</td>
                            <td class="px-4 py-3">{{ r.serial_imei?.serial_number || '-' }}</td>
                            <td class="px-4 py-3">{{ r.product?.name || '-' }}</td>
                            <td class="px-4 py-3">{{ r.assigned_employee?.name || '-' }}</td>
                            <td class="px-4 py-3 text-center">
                                <span :class="statusBadge(r.status).cls" class="px-2 py-0.5 rounded-full text-xs font-semibold">
                                    {{ statusBadge(r.status).label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span
                                    v-if="r.serial_imei?.repair_status"
                                    :class="repairStatusBadge(r.serial_imei.repair_status).cls"
                                    class="px-2 py-0.5 rounded-full text-xs font-semibold"
                                >
                                    {{ repairStatusBadge(r.serial_imei.repair_status).label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span v-if="r.deadline" :class="r.status !== 'completed' && new Date(r.deadline) < new Date() ? 'text-red-600 font-bold' : 'text-gray-600'">{{ r.deadline }}</span>
                                <span v-else class="text-gray-300">-</span>
                            </td>
                            <td class="px-4 py-3 text-right">{{ formatCurrency(r.original_cost) }}</td>
                            <td class="px-4 py-3 text-right">{{ formatCurrency(r.parts_cost) }}</td>
                            <td class="px-4 py-3 text-right font-semibold">{{ formatCurrency(r.total_cost) }}</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="repairs.last_page > 1" class="flex items-center justify-between px-4 py-3 border-t text-sm">
                    <span class="text-gray-500">Tổng: {{ repairs.total }} phiếu</span>
                    <div class="flex gap-1">
                        <button
                            v-for="p in repairs.last_page" :key="p"
                            @click="goPage(p)"
                            class="px-3 py-1 rounded"
                            :class="p === repairs.current_page ? 'bg-blue-600 text-white' : 'bg-gray-100 hover:bg-gray-200'"
                        >{{ p }}</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4">
                <div class="flex items-center justify-between px-6 py-4 border-b">
                    <h2 class="text-lg font-bold">Tạo phiếu sửa chữa</h2>
                    <button @click="showCreateModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div v-if="createError" class="text-red-500 text-sm bg-red-50 px-3 py-2 rounded">{{ createError }}</div>

                    <!-- Serial search -->
                    <div>
                        <label class="block font-semibold text-sm mb-1">Serial/IMEI *</label>
                        <div class="relative">
                            <input
                                v-model="serialSearch"
                                type="text"
                                placeholder="Nhập serial để tìm..."
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none"
                            />
                            <div
                                v-if="serialResults.length"
                                class="absolute z-10 w-full bg-white border rounded-lg shadow-lg mt-1 max-h-40 overflow-auto"
                            >
                                <div
                                    v-for="s in serialResults" :key="s.id"
                                    @click="selectSerial(s)"
                                    class="px-3 py-2 hover:bg-blue-50 cursor-pointer text-sm flex justify-between"
                                >
                                    <span>{{ s.serial_number }}</span>
                                    <span class="text-gray-400">{{ s.product?.name }}</span>
                                </div>
                            </div>
                        </div>
                        <div v-if="selectedSerial" class="mt-2 text-sm text-gray-600 bg-gray-50 px-3 py-2 rounded">
                            <strong>{{ selectedSerial.serial_number }}</strong> — {{ selectedSerial.product?.name }} — Giá vốn: {{ formatCurrency(selectedSerial.cost_price || selectedSerial.product?.cost_price) }}đ
                        </div>
                    </div>

                    <!-- Issue -->
                    <div>
                        <label class="block font-semibold text-sm mb-1">Mô tả lỗi</label>
                        <textarea
                            v-model="createForm.issue_description"
                            rows="3"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none"
                            placeholder="VD: lỗi màn, pin, phím..."
                        ></textarea>
                    </div>

                    <!-- Branch -->
                    <div>
                        <label class="block font-semibold text-sm mb-1">Chi nhánh</label>
                        <select v-model="createForm.branch_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            <option :value="null">-- Chọn chi nhánh --</option>
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>

                    <!-- Deadline -->
                    <div>
                        <label class="block font-semibold text-sm mb-1">Deadline</label>
                        <input v-model="createForm.deadline" type="date" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                    </div>

                    <!-- Notes -->
                    <div>
                        <label class="block font-semibold text-sm mb-1">Ghi chú</label>
                        <input v-model="createForm.notes" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                    </div>
                </div>
                <div class="flex justify-end gap-3 px-6 py-4 border-t">
                    <button @click="showCreateModal = false" class="px-5 py-2 border rounded-lg text-sm font-semibold">Hủy</button>
                    <button
                        @click="submitCreate"
                        :disabled="!createForm.serial_imei_id"
                        class="px-5 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
                    >Tạo phiếu</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
