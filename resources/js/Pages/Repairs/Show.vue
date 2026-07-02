<script setup>
import { formatVND as formatCurrency } from '@/utils/money';
import { ref, computed, watch } from "vue";
import { Head, usePage } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import axios from "axios";

const props = defineProps({
    repairId: [Number, String],
    employees: Array,
});

const repair = ref(null);
const loading = ref(true);
const showPartModal = ref(false);
const showAssignModal = ref(false);
const partForm = ref({ product_id: null, quantity: 1, notes: "" });
const assignForm = ref({ assigned_employee_id: null });
const partError = ref("");
const assignError = ref("");
const productSearch = ref("");
const productResults = ref([]);
const selectedProduct = ref(null);
const editDeadline = ref("");
const showDeadlineEdit = ref(false);

const loadRepair = async () => {
    loading.value = true;
    try {
        const res = await axios.get(`/api/device-repairs/${props.repairId}`);
        repair.value = res.data;
    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
};

const statusBadge = (status) => {
    const map = {
        pending: { label: "Chờ xử lý", cls: "bg-yellow-100 text-yellow-700" },
        in_progress: { label: "Đang sửa", cls: "bg-blue-100 text-blue-700" },
        completed: { label: "Hoàn thành", cls: "bg-green-100 text-green-700" },
    };
    return map[status] || { label: status, cls: "bg-gray-100 text-gray-600" };
};

// HOTFIX 24.16C — accept the serial object so "Sẵn bán" can be downgraded
// to "Đã bóc tách" when the physical serial.status is dismantled (e.g. a
// disassembly that was never put back together). Falls back to the raw
// repair_status string for older callers.
const repairStatusBadge = (serialOrRs) => {
    const rs = typeof serialOrRs === "string" ? serialOrRs : serialOrRs?.repair_status;
    const status = typeof serialOrRs === "object" ? serialOrRs?.status : null;
    if (rs === "ready" && status === "dismantled") {
        return { label: "⚠ Đã bóc tách", cls: "bg-red-100 text-red-700" };
    }
    const map = {
        not_started: { label: "Chưa làm", cls: "bg-red-100 text-red-600" },
        repairing: { label: "Đang xử lý", cls: "bg-yellow-100 text-yellow-600" },
        ready: { label: "Sẵn bán", cls: "bg-green-100 text-green-600" },
    };
    return map[rs] || { label: "", cls: "" };
};

// Product search for add part
let prodTimeout;
watch(productSearch, (val) => {
    clearTimeout(prodTimeout);
    if (!val || val.length < 2) { productResults.value = []; return; }
    prodTimeout = setTimeout(async () => {
        try {
            const res = await axios.get("/api/device-repairs/search-products", { params: { q: val } });
            productResults.value = res.data || [];
        } catch (e) {
            productResults.value = [];
        }
    }, 300);
});

const selectProduct = (p) => {
    selectedProduct.value = p;
    partForm.value.product_id = p.id;
    productSearch.value = p.name;
    productResults.value = [];
};

const submitAddPart = async () => {
    partError.value = "";
    try {
        await axios.post(`/api/device-repairs/${props.repairId}/parts`, partForm.value);
        showPartModal.value = false;
        partForm.value = { product_id: null, quantity: 1, notes: "" };
        selectedProduct.value = null;
        productSearch.value = "";
        loadRepair();
    } catch (e) {
        partError.value = e.response?.data?.message || "Lỗi khi thêm linh kiện.";
    }
};

const removePart = async (partId) => {
    if (!confirm("Xác nhận gỡ linh kiện này?")) return;
    try {
        await axios.delete(`/api/device-repairs/${props.repairId}/parts/${partId}`);
        loadRepair();
    } catch (e) {
        alert(e.response?.data?.message || "Lỗi.");
    }
};

const submitAssign = async () => {
    assignError.value = "";
    try {
        await axios.post(`/api/device-repairs/${props.repairId}/assign`, assignForm.value);
        showAssignModal.value = false;
        assignForm.value = { assigned_employee_id: null };
        loadRepair();
    } catch (e) {
        assignError.value = e.response?.data?.message || "Lỗi.";
    }
};

const markComplete = async () => {
    if (!confirm("Xác nhận hoàn thành sửa chữa?")) return;
    try {
        await axios.post(`/api/device-repairs/${props.repairId}/complete`);
        loadRepair();
    } catch (e) {
        alert(e.response?.data?.message || "Lỗi.");
    }
};

const startEditDeadline = () => {
    editDeadline.value = repair.value?.deadline || "";
    showDeadlineEdit.value = true;
};

const saveDeadline = async () => {
    try {
        await axios.put(`/api/device-repairs/${props.repairId}`, {
            issue_description: repair.value.issue_description,
            notes: repair.value.notes,
            deadline: editDeadline.value || null,
        });
        showDeadlineEdit.value = false;
        loadRepair();
    } catch (e) {
        alert(e.response?.data?.message || "Lỗi.");
    }
};

loadRepair();
</script>

<template>
    <Head :title="repair ? `Phiếu ${repair.code}` : 'Chi tiết phiếu sửa chữa'" />
    <AppLayout>
        <div class="p-6">
            <div v-if="loading" class="text-center py-20 text-gray-400">Đang tải...</div>

            <template v-else-if="repair">
                <!-- Header -->
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <a href="/repairs" class="text-sm text-blue-600 hover:underline">&larr; Danh sách phiếu</a>
                        <h1 class="text-xl font-bold text-gray-800 mt-1">
                            Phiếu {{ repair.code }}
                            <span :class="statusBadge(repair.status).cls" class="ml-2 px-2 py-0.5 rounded-full text-xs font-semibold">
                                {{ statusBadge(repair.status).label }}
                            </span>
                        </h1>
                    </div>
                    <div class="flex gap-2">
                        <button
                            v-if="repair.status !== 'completed'"
                            @click="showAssignModal = true; assignError = ''"
                            class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700"
                        >Giao NV</button>
                        <button
                            v-if="repair.status !== 'completed'"
                            @click="showPartModal = true; partError = ''; selectedProduct = null; productSearch = ''; partForm = { product_id: null, quantity: 1, notes: '' }"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700"
                        >+ Thêm linh kiện</button>
                        <button
                            v-if="repair.status === 'in_progress'"
                            @click="markComplete"
                            class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-semibold hover:bg-green-700"
                        >✓ Hoàn thành</button>
                    </div>
                </div>

                <!-- Info grid -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <!-- Device info -->
                    <div class="bg-white border rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-500 uppercase mb-3">Thiết bị</h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Sản phẩm:</span>
                                <span class="font-semibold">{{ repair.product?.name }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Serial:</span>
                                <span class="font-semibold">{{ repair.serial_imei?.serial_number }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Trạng thái serial:</span>
                                <span
                                    v-if="repair.serial_imei?.repair_status"
                                    :class="repairStatusBadge(repair.serial_imei).cls"
                                    class="px-2 py-0.5 rounded-full text-xs font-semibold"
                                >
                                    {{ repairStatusBadge(repair.serial_imei).label }}
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">NV phụ trách:</span>
                                <span class="font-semibold">{{ repair.assigned_employee?.name || 'Chưa giao' }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Cost summary -->
                    <div class="bg-white border rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-500 uppercase mb-3">Chi phí</h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Giá gốc:</span>
                                <span>{{ formatCurrency(repair.original_cost) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Linh kiện:</span>
                                <span>{{ formatCurrency(repair.parts_cost) }}</span>
                            </div>
                            <div class="flex justify-between text-base font-bold border-t pt-2 mt-2">
                                <span>Tổng giá vốn:</span>
                                <span class="text-blue-600">{{ formatCurrency(repair.total_cost) }}</span>
                            </div>
                            <div class="flex justify-between text-xs text-gray-400 mt-1">
                                <span>Serial cost_price hiện tại:</span>
                                <span>{{ formatCurrency(repair.serial_imei?.cost_price) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Details -->
                    <div class="bg-white border rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-500 uppercase mb-3">Thông tin</h3>
                        <div class="space-y-2 text-sm">
                            <div>
                                <span class="text-gray-500">Mô tả lỗi:</span>
                                <p class="mt-1 text-gray-800">{{ repair.issue_description || '-' }}</p>
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
                                        <span :class="repair.status !== 'completed' && repair.deadline && new Date(repair.deadline) < new Date() ? 'text-red-600 font-bold' : 'font-semibold'">
                                            {{ repair.deadline || 'Chưa đặt' }}
                                        </span>
                                        <button v-if="repair.status !== 'completed'" @click="startEditDeadline" class="text-blue-500 text-xs hover:underline">Sửa</button>
                                    </template>
                                </div>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Chi nhánh:</span>
                                <span>{{ repair.branch?.name || '-' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Người tạo:</span>
                                <span>{{ repair.creator?.name || '-' }}</span>
                            </div>
                            <div v-if="repair.notes" class="mt-2">
                                <span class="text-gray-500">Ghi chú:</span>
                                <p class="mt-1 text-gray-700">{{ repair.notes }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Parts table -->
                <div class="bg-white border rounded-lg shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b flex justify-between items-center">
                        <h3 class="font-bold text-gray-800">Linh kiện đã xuất</h3>
                        <span class="text-sm text-gray-500">{{ repair.parts?.length || 0 }} linh kiện</span>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                            <tr>
                                <th class="px-4 py-3 text-left">Sản phẩm</th>
                                <th class="px-4 py-3 text-center">SL</th>
                                <th class="px-4 py-3 text-right">Đơn giá vốn</th>
                                <th class="px-4 py-3 text-right">Thành tiền</th>
                                <th class="px-4 py-3 text-left">Ghi chú</th>
                                <th class="px-4 py-3 text-center" v-if="repair.status !== 'completed'"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!repair.parts?.length">
                                <td colspan="6" class="text-center py-6 text-gray-400">Chưa có linh kiện.</td>
                            </tr>
                            <tr v-for="part in repair.parts" :key="part.id" class="border-t">
                                <td class="px-4 py-3">{{ part.product?.name || '-' }}</td>
                                <td class="px-4 py-3 text-center">{{ part.quantity }}</td>
                                <td class="px-4 py-3 text-right">{{ formatCurrency(part.unit_cost) }}</td>
                                <td class="px-4 py-3 text-right font-semibold">{{ formatCurrency(part.total_cost) }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ part.notes || '' }}</td>
                                <td class="px-4 py-3 text-center" v-if="repair.status !== 'completed'">
                                    <button @click="removePart(part.id)" class="text-red-500 hover:text-red-700 text-xs font-semibold">Gỡ</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>

        <!-- Add Part Modal -->
        <div v-if="showPartModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4">
                <div class="flex items-center justify-between px-6 py-4 border-b">
                    <h2 class="text-lg font-bold">Thêm linh kiện</h2>
                    <button @click="showPartModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div v-if="partError" class="text-red-500 text-sm bg-red-50 px-3 py-2 rounded">{{ partError }}</div>

                    <div>
                        <label class="block font-semibold text-sm mb-1">Sản phẩm (linh kiện) *</label>
                        <div class="relative">
                            <input
                                v-model="productSearch"
                                type="text"
                                placeholder="Nhập tên sản phẩm..."
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none"
                            />
                            <div
                                v-if="productResults.length"
                                class="absolute z-10 w-full bg-white border rounded-lg shadow-lg mt-1 max-h-48 overflow-auto"
                            >
                                <div
                                    v-for="p in productResults" :key="p.id"
                                    @click="selectProduct(p)"
                                    class="px-3 py-2 hover:bg-blue-50 cursor-pointer text-sm flex justify-between"
                                >
                                    <span>{{ p.name }}</span>
                                    <span class="text-gray-400">{{ formatCurrency(p.cost_price) }}</span>
                                </div>
                            </div>
                        </div>
                        <div v-if="selectedProduct" class="mt-2 text-sm text-gray-600 bg-gray-50 px-3 py-2 rounded">
                            <strong>{{ selectedProduct.name }}</strong> — Giá vốn: {{ formatCurrency(selectedProduct.cost_price) }} — Tồn: {{ selectedProduct.stock_quantity ?? '?' }}
                        </div>
                    </div>

                    <div>
                        <label class="block font-semibold text-sm mb-1">Số lượng *</label>
                        <input v-model.number="partForm.quantity" type="number" min="1" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                    </div>

                    <div>
                        <label class="block font-semibold text-sm mb-1">Ghi chú</label>
                        <input v-model="partForm.notes" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                    </div>
                </div>
                <div class="flex justify-end gap-3 px-6 py-4 border-t">
                    <button @click="showPartModal = false" class="px-5 py-2 border rounded-lg text-sm font-semibold">Hủy</button>
                    <button
                        @click="submitAddPart"
                        :disabled="!partForm.product_id || !partForm.quantity"
                        class="px-5 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
                    >Xuất linh kiện</button>
                </div>
            </div>
        </div>

        <!-- Assign Employee Modal -->
        <div v-if="showAssignModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4">
                <div class="flex items-center justify-between px-6 py-4 border-b">
                    <h2 class="text-lg font-bold">Giao nhân viên</h2>
                    <button @click="showAssignModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div v-if="assignError" class="text-red-500 text-sm bg-red-50 px-3 py-2 rounded">{{ assignError }}</div>
                    <div>
                        <label class="block font-semibold text-sm mb-1">Nhân viên *</label>
                        <select v-model="assignForm.assigned_employee_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            <option :value="null">-- Chọn NV --</option>
                            <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-3 px-6 py-4 border-t">
                    <button @click="showAssignModal = false" class="px-5 py-2 border rounded-lg text-sm font-semibold">Hủy</button>
                    <button
                        @click="submitAssign"
                        :disabled="!assignForm.assigned_employee_id"
                        class="px-5 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 disabled:opacity-50"
                    >Giao NV</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
