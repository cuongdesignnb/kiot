<script setup>
import { formatVND as formatCurrency } from '@/utils/money';
import { ref, computed, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import MoneyInput from "@/Components/MoneyInput.vue";
import axios from "axios";

const props = defineProps({
    taskId: [Number, String],
    employees: Array,
    categories: Array,
});

const task = ref(null);
const loading = ref(true);
const activeTab = ref("info");

// Part modal (add)
const showPartModal = ref(false);
const partForm = ref({ product_id: null, quantity: 1, notes: "" });
const partError = ref("");
const productSearch = ref("");
const productResults = ref([]);
const selectedProduct = ref(null);

// Disassemble modal (bóc máy)
const showDisassembleModal = ref(false);
const disForm = ref({ product_id: null, quantity: 1, unit_cost: 0, notes: "", serial_numbers: [] });
const disError = ref("");
const disProductSearch = ref("");
const disProductResults = ref([]);
const disSelectedProduct = ref(null);

// Step 23.8F — Serial selector for addPart (has_serial product)
const partProductSerials = ref([]);
const partSelectedSerialIds = ref([]);
const partLoadingSerials = ref(false);

// Step 23.8F — Complete external repair modal
const showCompleteModal = ref(false);
const completeForm = ref({
    labor_fee: 0,
    paid_amount: 0,
    payment_method: "cash",
    note: "",
    warranty_policy: "none",
    part_prices: {},
});
const completeError = ref("");
const completeLoading = ref(false);

// Step 23.8F — Warranty lookup/attach
const showWarrantyLookup = ref(false);
const warrantyLookupSerial = ref("");
const warrantyLookupInvoice = ref("");
const warrantyResults = ref([]);
const warrantyLookupError = ref("");
const warrantyLookupLoading = ref(false);

// Quick create product (from Bóc LK only)
const showQuickCreate = ref(false);
const quickProduct = ref({ name: "", sku: "", barcode: "", category_id: "", brand_id: "", cost_price: 0, retail_price: 0, location: "" });
const quickCreateError = ref("");
const quickCreateLoading = ref(false);
const brandsList = ref([]);

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

// Product search for parts (add)
let prodTimeout;
watch(productSearch, (val) => {
    clearTimeout(prodTimeout);
    if (!val || val.length < 2) { productResults.value = []; return; }
    prodTimeout = setTimeout(async () => {
        try {
            const res = await axios.get("/api/tasks/search-products", { params: { q: val } });
            productResults.value = res.data || [];
        } catch (e) { productResults.value = []; }
    }, 300);
});

const selectProduct = async (p) => {
    selectedProduct.value = p;
    partForm.value.product_id = p.id;
    productSearch.value = p.name;
    productResults.value = [];
    partSelectedSerialIds.value = [];
    partProductSerials.value = [];
    // Step 23.8F: nếu has_serial → load serials in_stock để user chọn
    if (p.has_serial) {
        partLoadingSerials.value = true;
        try {
            const res = await axios.get("/api/tasks/product-serials", { params: { product_id: p.id } });
            partProductSerials.value = res.data || [];
        } catch (e) {
            partProductSerials.value = [];
        } finally {
            partLoadingSerials.value = false;
        }
    }
};

const togglePartSerial = (id) => {
    const idx = partSelectedSerialIds.value.indexOf(id);
    if (idx >= 0) partSelectedSerialIds.value.splice(idx, 1);
    else partSelectedSerialIds.value.push(id);
};

// Product search for disassemble (bóc máy)
let disProdTimeout;
watch(disProductSearch, (val) => {
    clearTimeout(disProdTimeout);
    if (!val || val.length < 2) { disProductResults.value = []; return; }
    disProdTimeout = setTimeout(async () => {
        try {
            const res = await axios.get("/api/tasks/search-products", { params: { q: val } });
            disProductResults.value = res.data || [];
        } catch (e) { disProductResults.value = []; }
    }, 300);
});

const selectDisProduct = (p) => {
    disSelectedProduct.value = p;
    disForm.value.product_id = p.id;
    disForm.value.unit_cost = p.cost_price || 0;
    disProductSearch.value = p.name;
    disProductResults.value = [];
    // Step 23.8F: nếu output has_serial → init serial_numbers theo qty
    if (p.has_serial) {
        const target = Math.max(1, parseInt(disForm.value.quantity) || 1);
        disForm.value.serial_numbers = Array(target).fill("");
    } else {
        disForm.value.serial_numbers = [];
    }
};

const submitAddPart = async () => {
    partError.value = "";
    // Step 23.8F: nếu product has_serial, bắt buộc chọn đủ serial_ids ở frontend
    if (selectedProduct.value?.has_serial) {
        if (partSelectedSerialIds.value.length !== partForm.value.quantity) {
            partError.value = `Sản phẩm có Serial/IMEI — cần chọn đủ ${partForm.value.quantity} serial (đã chọn ${partSelectedSerialIds.value.length}).`;
            return;
        }
    }
    try {
        const payload = { ...partForm.value };
        if (selectedProduct.value?.has_serial) {
            payload.serial_ids = partSelectedSerialIds.value;
        }
        await axios.post(`/api/tasks/${props.taskId}/parts`, payload);
        showPartModal.value = false;
        partForm.value = { product_id: null, quantity: 1, notes: "" };
        selectedProduct.value = null;
        productSearch.value = "";
        partProductSerials.value = [];
        partSelectedSerialIds.value = [];
        loadTask();
    } catch (e) {
        partError.value = e.response?.data?.message || "Lỗi khi thêm linh kiện.";
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

// HOTFIX 24.11B — Rollback a disassembled part (direction='import').
// Separate handler so removePart's UX (instant Gỡ) and the rollback's UX
// (longer warning + 422 surfaces) don't get muddled.
const rollbackDisassemblyPart = async (partId) => {
    if (!confirm("Hoàn tác bóc linh kiện này?\nTồn kho linh kiện sẽ giảm lại, giá vốn máy gốc sẽ được cộng lại, và nếu không còn linh kiện bóc nào khác thì serial máy gốc sẽ trở về trạng thái cũ.")) return;
    try {
        await axios.post(`/api/tasks/${props.taskId}/parts/${partId}/rollback-disassembly`);
        loadTask();
    } catch (e) {
        alert(e.response?.data?.message || "Không thể hoàn tác bóc linh kiện.");
    }
};

// Step 23.8F — sync serial_numbers length với quantity khi user đổi qty cho output has_serial
watch(() => disForm.value.quantity, (qty) => {
    if (!disSelectedProduct.value?.has_serial) {
        disForm.value.serial_numbers = [];
        return;
    }
    const target = Math.max(1, parseInt(qty) || 1);
    const cur = disForm.value.serial_numbers || [];
    if (cur.length === target) return;
    if (cur.length < target) {
        disForm.value.serial_numbers = [...cur, ...Array(target - cur.length).fill("")];
    } else {
        disForm.value.serial_numbers = cur.slice(0, target);
    }
});

// Disassemble submit
const submitDisassemble = async () => {
    disError.value = "";
    // Step 23.8F: validate serial_numbers nếu output has_serial
    if (disSelectedProduct.value?.has_serial) {
        const sns = (disForm.value.serial_numbers || []).map(s => (s || "").trim()).filter(Boolean);
        if (sns.length !== disForm.value.quantity) {
            disError.value = `Linh kiện có Serial/IMEI — cần nhập đủ ${disForm.value.quantity} serial (đã nhập ${sns.length}).`;
            return;
        }
        if (new Set(sns).size !== sns.length) {
            disError.value = "Serial output không được trùng nhau.";
            return;
        }
    }
    try {
        const payload = {
            product_id: disForm.value.product_id,
            quantity: disForm.value.quantity,
            unit_cost: Number(disForm.value.unit_cost) || 0,
            notes: disForm.value.notes,
        };
        if (disSelectedProduct.value?.has_serial) {
            payload.serial_numbers = (disForm.value.serial_numbers || []).map(s => s.trim());
        }
        await axios.post(`/api/tasks/${props.taskId}/disassemble-part`, payload);
        showDisassembleModal.value = false;
        disForm.value = { product_id: null, quantity: 1, unit_cost: 0, notes: "", serial_numbers: [] };
        disSelectedProduct.value = null;
        disProductSearch.value = "";
        loadTask();
    } catch (e) {
        disError.value = e.response?.data?.message || "Lỗi khi bóc linh kiện.";
    }
};

const openDisassembleModal = () => {
    disError.value = '';
    disSelectedProduct.value = null;
    disProductSearch.value = '';
    disForm.value = { product_id: null, quantity: 1, unit_cost: 0, notes: '', serial_numbers: [] };
    showDisassembleModal.value = true;
};

// Quick Create Product
const openQuickCreate = async () => {
    quickProduct.value = { name: "", sku: "", barcode: "", category_id: "", brand_id: "", cost_price: 0, retail_price: 0, location: "" };
    quickCreateError.value = "";
    quickCreateLoading.value = false;
    showQuickCreate.value = true;
    if (!brandsList.value.length) {
        try {
            const res = await axios.get("/api/brands");
            brandsList.value = res.data || [];
        } catch (e) { brandsList.value = []; }
    }
};
const submitQuickProduct = async () => {
    quickCreateError.value = "";
    if (!quickProduct.value.name.trim()) { quickCreateError.value = "Nhập tên sản phẩm."; return; }
    quickCreateLoading.value = true;
    try {
        const payload = {
            ...quickProduct.value,
            cost_price: Number(quickProduct.value.cost_price) || 0,
            retail_price: Number(quickProduct.value.retail_price) || 0,
        };
        if (!payload.category_id) delete payload.category_id;
        if (!payload.brand_id) delete payload.brand_id;
        if (!payload.sku) delete payload.sku;
        if (!payload.barcode) delete payload.barcode;
        if (!payload.location) delete payload.location;
        const res = await axios.post("/api/tasks/quick-create-product", payload);
        const p = res.data;
        // Auto-select in disassemble modal
        disSelectedProduct.value = p;
        disForm.value.product_id = p.id;
        disForm.value.unit_cost = p.cost_price || 0;
        disProductSearch.value = p.name;
        showQuickCreate.value = false;
    } catch (e) {
        quickCreateError.value = e.response?.data?.message || "Lỗi tạo sản phẩm.";
    } finally {
        quickCreateLoading.value = false;
    }
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
    // Step 23.8F: external repair → mở modal complete với labor/parts/policy
    if (task.value?.external && task.value?.type === 'repair') {
        openCompleteModal();
        return;
    }
    if (!confirm("Xác nhận hoàn thành công việc?")) return;
    try {
        await axios.post(`/api/tasks/${props.taskId}/complete`);
        loadTask();
    } catch (e) {
        alert(e.response?.data?.message || "Lỗi.");
    }
};

// Step 23.8F — Complete external repair modal
const openCompleteModal = () => {
    completeError.value = "";
    completeLoading.value = false;
    const partPrices = {};
    const exportParts = (task.value?.parts || []).filter(p => (p.direction || 'export') === 'export');
    for (const p of exportParts) {
        partPrices[p.id] = Number(p.sale_price) || Number(p.product?.retail_price) || 0;
    }
    completeForm.value = {
        labor_fee: Number(task.value?.labor_fee) || 0,
        paid_amount: 0,
        payment_method: "cash",
        note: "",
        warranty_policy: "none",
        part_prices: partPrices,
    };
    showCompleteModal.value = true;
};

const exportPartsList = computed(() =>
    (task.value?.parts || []).filter(p => (p.direction || 'export') === 'export')
);

const completePartsTotal = computed(() => {
    let sum = 0;
    for (const p of exportPartsList.value) {
        const price = Number(completeForm.value.part_prices[p.id]) || 0;
        sum += price * Number(p.quantity || 0);
    }
    return sum;
});
const completeGrossTotal = computed(() => Number(completeForm.value.labor_fee || 0) + completePartsTotal.value);
const completeCovered = computed(() => {
    const policy = completeForm.value.warranty_policy;
    if (policy === 'free_labor') return Number(completeForm.value.labor_fee || 0);
    if (policy === 'free_parts') return completePartsTotal.value;
    if (policy === 'full_free') return completeGrossTotal.value;
    return 0;
});
const completePayable = computed(() => Math.max(0, completeGrossTotal.value - completeCovered.value));
const completeDebt = computed(() => Math.max(0, completePayable.value - Number(completeForm.value.paid_amount || 0)));
const warrantyValid = computed(() => task.value?.warranty_valid === true);
const allowedPolicies = computed(() => {
    if (!task.value?.warranty_id || !warrantyValid.value) return ['none'];
    return ['none', 'free_labor', 'free_parts', 'full_free'];
});

const submitComplete = async () => {
    completeError.value = "";
    // Frontend guards
    if (Number(completeForm.value.paid_amount) > completePayable.value) {
        completeError.value = "Số tiền đã trả không được lớn hơn khách phải trả.";
        return;
    }
    if (completeDebt.value > 0.01 && !task.value?.customer_id) {
        completeError.value = "Phiếu còn công nợ — phải chọn khách hàng (customer_id).";
        return;
    }
    if (completeForm.value.warranty_policy !== 'none' && !allowedPolicies.value.includes(completeForm.value.warranty_policy)) {
        completeError.value = "Chính sách bảo hành không khả dụng (chưa attach hoặc bảo hành đã hết hạn).";
        return;
    }
    completeLoading.value = true;
    try {
        const payload = {
            labor_fee: Number(completeForm.value.labor_fee || 0),
            paid_amount: Number(completeForm.value.paid_amount || 0),
            payment_method: completeForm.value.payment_method || 'cash',
            note: completeForm.value.note || "",
            part_prices: Object.fromEntries(
                Object.entries(completeForm.value.part_prices || {}).map(([id, value]) => [id, Number(value) || 0])
            ),
            warranty_policy: completeForm.value.warranty_policy,
        };
        await axios.post(`/api/tasks/${props.taskId}/complete`, payload);
        showCompleteModal.value = false;
        loadTask();
    } catch (e) {
        completeError.value = e.response?.data?.message || "Lỗi khi hoàn thành phiếu.";
    } finally {
        completeLoading.value = false;
    }
};

// Step 23.8F — Warranty lookup/attach
const openWarrantyLookup = () => {
    warrantyLookupError.value = "";
    warrantyResults.value = [];
    warrantyLookupSerial.value = "";
    warrantyLookupInvoice.value = "";
    showWarrantyLookup.value = true;
};

const lookupWarranty = async () => {
    warrantyLookupError.value = "";
    warrantyResults.value = [];
    if (!warrantyLookupSerial.value && !warrantyLookupInvoice.value) {
        warrantyLookupError.value = "Cần serial_imei hoặc invoice_code.";
        return;
    }
    warrantyLookupLoading.value = true;
    try {
        const params = {};
        if (warrantyLookupSerial.value) params.serial_imei = warrantyLookupSerial.value;
        if (warrantyLookupInvoice.value) params.invoice_code = warrantyLookupInvoice.value;
        const res = await axios.get("/api/tasks/lookup-warranty", { params });
        warrantyResults.value = res.data || [];
        if (!warrantyResults.value.length) {
            warrantyLookupError.value = "Không tìm thấy bảo hành phù hợp.";
        }
    } catch (e) {
        warrantyLookupError.value = e.response?.data?.message || "Lỗi tra cứu.";
    } finally {
        warrantyLookupLoading.value = false;
    }
};

const attachWarranty = async (warrantyId) => {
    try {
        await axios.post(`/api/tasks/${props.taskId}/attach-warranty`, { warranty_id: warrantyId });
        showWarrantyLookup.value = false;
        loadTask();
    } catch (e) {
        warrantyLookupError.value = e.response?.data?.message || "Lỗi gắn bảo hành.";
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
                        <button v-if="isActive && task.type === 'repair'" @click="showPartModal = true; partError = ''; selectedProduct = null; productSearch = ''; partForm = { product_id: null, quantity: 1, notes: '' }" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700">+ Lắp LK</button>
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

                <!-- Step 23.8F — External repair extra blocks -->
                <div v-if="task.external && task.type === 'repair'" class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <!-- Customer block -->
                    <div class="bg-white border rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-500 uppercase mb-3">Khách hàng</h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-gray-500">Tên:</span><span class="font-semibold">{{ task.customer?.name || task.customer_name || '-' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">SĐT:</span><span>{{ task.customer?.phone || task.customer_phone || '-' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Mã KH:</span><span>{{ task.customer?.code || '-' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Tiếp nhận:</span><span>{{ task.received_at ? new Date(task.received_at).toLocaleString('vi-VN') : '-' }}</span></div>
                            <div v-if="task.sub_status" class="flex justify-between"><span class="text-gray-500">Trạng thái phụ:</span><span>{{ task.sub_status }}</span></div>
                        </div>
                    </div>
                    <!-- Financial block -->
                    <div class="bg-white border rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-500 uppercase mb-3">Tài chính sửa chữa</h3>
                        <div class="space-y-1 text-sm">
                            <div class="flex justify-between"><span class="text-gray-500">Tiền công:</span><span>{{ formatCurrency(task.labor_fee) }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Tổng linh kiện (gross):</span><span>{{ formatCurrency(task.parts_total) }}</span></div>
                            <div v-if="task.warranty_policy && task.warranty_policy !== 'none'" class="flex justify-between text-green-600">
                                <span>Miễn theo BH ({{ task.warranty_policy }}):</span><span>-{{ formatCurrency(task.warranty_covered_amount) }}</span>
                            </div>
                            <div class="flex justify-between border-t pt-1 mt-1 font-semibold">
                                <span>Khách phải trả:</span><span class="text-blue-700">{{ formatCurrency(task.total_amount) }}</span>
                            </div>
                            <div class="flex justify-between"><span class="text-gray-500">Đã trả:</span><span class="text-green-700">{{ formatCurrency(task.paid_amount) }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Còn nợ:</span><span :class="Number(task.debt_amount) > 0 ? 'text-red-600 font-semibold' : ''">{{ formatCurrency(task.debt_amount) }}</span></div>
                            <div v-if="task.invoice_id" class="flex justify-between border-t pt-1 mt-1">
                                <span class="text-gray-500">Hóa đơn SC:</span>
                                <a :href="`/invoices/${task.invoice_id}/show`" class="text-blue-600 hover:underline">{{ task.invoice?.code || `#${task.invoice_id}` }}</a>
                            </div>
                        </div>
                    </div>
                    <!-- Warranty block -->
                    <div class="bg-white border rounded-lg p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold text-gray-500 uppercase">Bảo hành</h3>
                            <button
                                v-if="isActive && !task.invoice_id"
                                @click="openWarrantyLookup"
                                class="text-xs text-purple-600 font-semibold hover:underline"
                            >Tra cứu / Gắn</button>
                        </div>
                        <div v-if="!task.warranty_id" class="text-sm text-gray-400">Chưa gắn bảo hành.</div>
                        <div v-else class="space-y-1 text-sm">
                            <div class="flex justify-between"><span class="text-gray-500">Mã HĐ gốc:</span><span class="font-mono">{{ task.warranty?.invoice_code || '-' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Sản phẩm:</span><span>{{ task.warranty?.product?.name || '-' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Serial:</span><span class="font-mono">{{ task.warranty?.serial_imei || '-' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Mua:</span><span>{{ task.warranty?.purchase_date ? new Date(task.warranty.purchase_date).toLocaleDateString('vi-VN') : '-' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Hết hạn:</span><span>{{ task.warranty?.warranty_end_date ? new Date(task.warranty.warranty_end_date).toLocaleDateString('vi-VN') : '-' }}</span></div>
                            <div class="flex justify-between font-semibold">
                                <span>Trạng thái:</span>
                                <span :class="task.warranty_valid ? 'text-green-600' : 'text-red-500'">
                                    {{ task.warranty_valid ? 'Còn hạn' : 'Hết hạn' }}
                                </span>
                            </div>
                        </div>
                    </div>
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
                            <div class="flex justify-between"><span class="text-gray-500">Giá gốc:</span><span>{{ formatCurrency(task.original_cost) }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Linh kiện:</span><span>{{ formatCurrency(task.parts_cost) }}</span></div>
                            <div class="flex justify-between text-base font-bold border-t pt-2 mt-2">
                                <span>Tổng giá vốn:</span><span class="text-blue-600">{{ formatCurrency(task.total_cost) }}</span>
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
                            <button v-if="isActive" @click="showPartModal = true; partError = ''; selectedProduct = null; productSearch = ''; partForm = { product_id: null, quantity: 1, notes: '' }" class="text-xs text-blue-600 font-semibold hover:underline">+ Lắp LK</button>
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
                                <td class="px-4 py-3 text-gray-500">
                                    <div v-if="part.serial_ids?.length" class="text-[11px] text-blue-600 mb-0.5">
                                        Serial: {{ part.serial_ids.length }} cái
                                    </div>
                                    <div v-if="part.sale_price" class="text-[11px] text-green-600 mb-0.5">
                                        Giá bán: {{ formatCurrency(part.sale_price) }}
                                    </div>
                                    <div>{{ part.notes || '' }}</div>
                                </td>
                                <td class="px-4 py-3 text-center" v-if="isActive">
                                    <button
                                        v-if="(part.direction || 'export') === 'import'"
                                        @click="rollbackDisassemblyPart(part.id)"
                                        class="text-orange-600 hover:text-orange-800 text-xs font-semibold"
                                        title="Hoàn tác bóc — rollback tồn kho + giá vốn máy"
                                    >
                                        Hoàn tác
                                    </button>
                                    <button
                                        v-else
                                        @click="removePart(part.id)"
                                        class="text-red-500 hover:text-red-700 text-xs font-semibold"
                                        title="Gỡ linh kiện khỏi phiếu sửa chữa"
                                    >
                                        Gỡ
                                    </button>
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
                        <label class="block font-semibold text-sm mb-1">Sản phẩm *</label>
                        <div class="relative">
                            <input v-model="productSearch" type="text" placeholder="Nhập tên sản phẩm..." class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" />
                            <div v-if="productResults.length" class="absolute z-10 w-full bg-white border rounded-lg shadow-lg mt-1 max-h-48 overflow-auto">
                                <div v-for="p in productResults" :key="p.id" @click="selectProduct(p)" class="px-3 py-2 hover:bg-blue-50 cursor-pointer text-sm flex justify-between">
                                    <span>{{ p.name }}</span><span class="text-gray-400">{{ formatCurrency(p.cost_price) }}</span>
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
                    <!-- Step 23.8F: Serial selector for has_serial parts -->
                    <div v-if="selectedProduct?.has_serial">
                        <label class="block font-semibold text-sm mb-1">Chọn Serial/IMEI ({{ partSelectedSerialIds.length }}/{{ partForm.quantity }})</label>
                        <div v-if="partLoadingSerials" class="text-sm text-gray-400">Đang tải...</div>
                        <div v-else-if="!partProductSerials.length" class="text-sm text-orange-600 bg-orange-50 border border-orange-200 px-3 py-2 rounded">
                            Không có serial in_stock cho linh kiện này — không thể xuất.
                        </div>
                        <div v-else class="border border-gray-200 rounded-lg max-h-44 overflow-y-auto">
                            <label v-for="s in partProductSerials" :key="s.id" class="flex items-center gap-3 px-3 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0">
                                <input type="checkbox" :checked="partSelectedSerialIds.includes(s.id)" @change="togglePartSerial(s.id)" class="accent-blue-600" />
                                <span class="text-sm font-mono text-blue-700">{{ s.serial_number }}</span>
                                <span class="text-xs text-gray-400 ml-auto">GV: {{ formatCurrency(s.cost_price) }}</span>
                            </label>
                        </div>
                    </div>
                    <div>
                        <label class="block font-semibold text-sm mb-1">Ghi chú</label>
                        <input v-model="partForm.notes" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                    </div>
                </div>
                <div class="flex justify-end gap-3 px-6 py-4 border-t">
                    <button @click="showPartModal = false" class="px-5 py-2 border rounded-lg text-sm font-semibold">Hủy</button>
                    <button @click="submitAddPart"
                        :disabled="!partForm.product_id || !partForm.quantity || (selectedProduct?.has_serial && partSelectedSerialIds.length !== partForm.quantity)"
                        class="px-5 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 disabled:opacity-50">
                        Xuất linh kiện
                    </button>
                </div>
            </div>
        </div>

        <!-- Assign Modal -->

        <!-- Disassemble Part Modal (Bóc máy) -->
        <div v-if="showDisassembleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4">
                <div class="flex items-center justify-between px-6 py-4 border-b bg-orange-50">
                    <h2 class="text-lg font-bold text-orange-700">↑ Bóc linh kiện từ máy</h2>
                    <button @click="showDisassembleModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div v-if="disError" class="text-red-500 text-sm bg-red-50 px-3 py-2 rounded">{{ disError }}</div>
                    <p class="text-xs text-gray-500 bg-gray-50 px-3 py-2 rounded">Linh kiện bóc ra sẽ được <strong>nhập vào tồn kho</strong>. Giá vốn máy sẽ giảm tương ứng.</p>
                    <!-- Step 23.8F: hiển thị giá vốn còn khả dụng -->
                    <p v-if="task?.available_for_disassembly !== null && task?.available_for_disassembly !== undefined" class="text-xs px-3 py-2 rounded"
                       :class="task.available_for_disassembly > 0 ? 'bg-blue-50 text-blue-700 border border-blue-200' : 'bg-red-50 text-red-700 border border-red-200'">
                        Giá vốn còn khả dụng để bóc: <strong>{{ formatCurrency(task.available_for_disassembly) }}</strong>
                    </p>
                    <div>
                        <label class="block font-semibold text-sm mb-1">Sản phẩm (linh kiện bóc ra) *</label>
                        <div class="relative">
                            <input v-model="disProductSearch" type="text" placeholder="Nhập tên linh kiện..." class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-orange-500 outline-none" />
                            <div v-if="disProductResults.length" class="absolute z-10 w-full bg-white border rounded-lg shadow-lg mt-1 max-h-48 overflow-auto">
                                <div v-for="p in disProductResults" :key="p.id" @click="selectDisProduct(p)" class="px-3 py-2 hover:bg-orange-50 cursor-pointer text-sm flex justify-between">
                                    <span>{{ p.name }}</span><span class="text-gray-400">GV: {{ formatCurrency(p.cost_price) }}</span>
                                </div>
                            </div>
                            <div v-if="disProductSearch.length >= 2 && !disProductResults.length && !disSelectedProduct" class="absolute z-10 w-full bg-white border rounded-lg shadow-lg mt-1 px-3 py-2 text-sm text-gray-500">
                                Không tìm thấy. <button @click="openQuickCreate" class="text-green-600 font-semibold hover:underline">+ Tạo SP mới</button>
                            </div>
                        </div>
                        <div v-if="disSelectedProduct" class="mt-2 text-sm text-gray-600 bg-orange-50 px-3 py-2 rounded border border-orange-200">
                            <strong>{{ disSelectedProduct.name }}</strong> — Giá vốn BQ: {{ formatCurrency(disSelectedProduct.cost_price) }} — Tồn hiện tại: {{ disSelectedProduct.stock_quantity ?? '?' }}
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block font-semibold text-sm mb-1">Số lượng *</label>
                            <input v-model.number="disForm.quantity" type="number" min="1" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block font-semibold text-sm mb-1">Đơn giá nhập kho</label>
                            <MoneyInput v-model="disForm.unit_cost" :min="0" input-class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-right" />
                            <p class="text-[11px] text-gray-400 mt-1">Mặc định = giá vốn BQ, có thể sửa</p>
                        </div>
                    </div>
                    <div v-if="disForm.product_id && disForm.quantity && disForm.unit_cost" class="text-sm bg-orange-50 px-3 py-2 rounded border border-orange-200">
                        Tổng giá trị bóc ra: <strong class="text-orange-700">{{ formatCurrency(disForm.unit_cost * disForm.quantity) }}</strong>
                        — Giá vốn máy sẽ giảm tương ứng
                    </div>
                    <!-- Step 23.8F: serial_numbers input cho output has_serial -->
                    <div v-if="disSelectedProduct?.has_serial">
                        <label class="block font-semibold text-sm mb-1">Serial/IMEI cho output ({{ disForm.quantity }} cái) *</label>
                        <div class="space-y-2">
                            <input
                                v-for="(_, idx) in disForm.serial_numbers"
                                :key="idx"
                                v-model="disForm.serial_numbers[idx]"
                                type="text"
                                :placeholder="`Serial ${idx + 1}`"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:border-orange-500 outline-none"
                            />
                        </div>
                        <p class="text-[11px] text-gray-400 mt-1">Mỗi serial phải duy nhất, chưa tồn tại trong hệ thống. Backend sẽ validate.</p>
                    </div>
                    <div>
                        <label class="block font-semibold text-sm mb-1">Ghi chú</label>
                        <input v-model="disForm.notes" type="text" placeholder="VD: Bóc RAM 16GB để lắp RAM 8GB" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                    </div>
                </div>
                <div class="flex justify-between gap-3 px-6 py-4 border-t">
                    <button @click="openQuickCreate" class="text-sm text-green-600 font-semibold hover:underline">+ Tạo SP mới</button>
                    <div class="flex gap-3">
                        <button @click="showDisassembleModal = false" class="px-5 py-2 border rounded-lg text-sm font-semibold">Hủy</button>
                        <button @click="submitDisassemble" :disabled="!disForm.product_id || !disForm.quantity" class="px-5 py-2 bg-orange-500 text-white rounded-lg text-sm font-semibold hover:bg-orange-600 disabled:opacity-50">Bóc linh kiện</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Create Product Popup (Full form like Thêm hàng hóa) -->
        <div v-if="showQuickCreate" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between px-6 py-4 border-b bg-green-50 sticky top-0 z-10">
                    <div class="flex items-center gap-3">
                        <button @click="showQuickCreate = false" class="text-green-600 hover:text-green-800 text-sm font-semibold flex items-center gap-1">
                            ← Quay lại bóc LK
                        </button>
                        <span class="text-gray-300">|</span>
                        <h2 class="text-lg font-bold text-green-700">Thêm mới hàng hóa</h2>
                    </div>
                    <button @click="showQuickCreate = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <div class="px-6 py-5 space-y-5">
                    <div v-if="quickCreateError" class="text-red-500 text-sm bg-red-50 px-3 py-2 rounded">{{ quickCreateError }}</div>
                    <p class="text-xs text-gray-500 bg-green-50 px-3 py-2 rounded border border-green-200">Sản phẩm mới sẽ được tạo trong mục <strong>Hàng hóa</strong> và tự động chọn trong modal bóc linh kiện.</p>

                    <!-- Tên hàng -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Tên hàng <span class="text-red-500">*</span></label>
                        <input v-model="quickProduct.name" type="text" placeholder="VD: RAM DDR4 16GB 3200MHz" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-green-500 focus:ring-1 focus:ring-green-500 outline-none" />
                    </div>

                    <!-- Mã hàng & Mã vạch -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Mã hàng hóa</label>
                            <input v-model="quickProduct.sku" type="text" placeholder="Tự động nếu bỏ trống" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-green-500 outline-none" />
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Mã vạch</label>
                            <input v-model="quickProduct.barcode" type="text" placeholder="Tự động = mã hàng" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-green-500 outline-none" />
                        </div>
                    </div>

                    <!-- Nhóm hàng & Thương hiệu -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Nhóm hàng</label>
                            <select v-model="quickProduct.category_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-green-500 outline-none bg-white">
                                <option value="">--- Chọn nhóm hàng ---</option>
                                <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Thương hiệu</label>
                            <select v-model="quickProduct.brand_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-green-500 outline-none bg-white">
                                <option value="">--- Chọn thương hiệu ---</option>
                                <option v-for="b in brandsList" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 my-1"></div>

                    <!-- Giá vốn & Giá bán -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Giá vốn</label>
                            <div class="relative">
                                <MoneyInput v-model="quickProduct.cost_price" :min="0" input-class="w-full border border-gray-300 rounded-lg px-3 py-2 pr-8 text-sm text-right font-semibold focus:border-green-500 outline-none" />
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">₫</span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Giá bán</label>
                            <div class="relative">
                                <MoneyInput v-model="quickProduct.retail_price" :min="0" input-class="w-full border border-gray-300 rounded-lg px-3 py-2 pr-8 text-sm text-right font-semibold text-blue-700 focus:border-green-500 outline-none" />
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">₫</span>
                            </div>
                        </div>
                    </div>

                    <!-- Vị trí -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Vị trí lưu kho</label>
                        <input v-model="quickProduct.location" type="text" placeholder="VD: Kệ A3, Tầng 2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-green-500 outline-none" />
                    </div>
                </div>
                <div class="flex justify-between items-center px-6 py-4 border-t sticky bottom-0 bg-white">
                    <button @click="showQuickCreate = false" class="px-5 py-2 border rounded-lg text-sm font-semibold text-gray-600 hover:bg-gray-50">← Quay lại</button>
                    <button @click="submitQuickProduct" :disabled="!quickProduct.name.trim() || quickCreateLoading" class="px-6 py-2 bg-green-600 text-white rounded-lg text-sm font-semibold hover:bg-green-700 disabled:opacity-50 flex items-center gap-2">
                        <svg v-if="quickCreateLoading" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        {{ quickCreateLoading ? 'Đang tạo...' : 'Lưu & Chọn trong bóc LK' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 23.8F — Complete external repair modal -->
        <div v-if="showCompleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl mx-4 max-h-[92vh] overflow-y-auto">
                <div class="flex items-center justify-between px-6 py-4 border-b bg-green-50 sticky top-0 z-10">
                    <h2 class="text-lg font-bold text-green-700">Hoàn thành phiếu sửa chữa</h2>
                    <button @click="showCompleteModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div v-if="completeError" class="text-red-500 text-sm bg-red-50 px-3 py-2 rounded">{{ completeError }}</div>

                    <!-- Labor fee -->
                    <div>
                        <label class="block font-semibold text-sm mb-1">Tiền công (labor_fee)</label>
                        <MoneyInput v-model="completeForm.labor_fee" :min="0" input-class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-right font-semibold" />
                    </div>

                    <!-- Part prices table -->
                    <div v-if="exportPartsList.length">
                        <label class="block font-semibold text-sm mb-2">Giá bán linh kiện đã xuất</label>
                        <table class="w-full text-sm border rounded">
                            <thead class="bg-gray-50 text-xs">
                                <tr>
                                    <th class="px-2 py-2 text-left">Linh kiện</th>
                                    <th class="px-2 py-2 text-center">SL</th>
                                    <th class="px-2 py-2 text-right">Đơn giá vốn</th>
                                    <th class="px-2 py-2 text-right">Đơn giá bán</th>
                                    <th class="px-2 py-2 text-right">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in exportPartsList" :key="p.id" class="border-t">
                                    <td class="px-2 py-2">{{ p.product?.name || '-' }}</td>
                                    <td class="px-2 py-2 text-center">{{ p.quantity }}</td>
                                    <td class="px-2 py-2 text-right text-gray-500">{{ formatCurrency(p.unit_cost) }}</td>
                                    <td class="px-2 py-2 text-right">
                                        <MoneyInput v-model="completeForm.part_prices[p.id]" :min="0"
                                            input-class="w-28 border border-gray-300 rounded px-2 py-1 text-sm text-right" />
                                    </td>
                                    <td class="px-2 py-2 text-right font-semibold">
                                        {{ formatCurrency((Number(completeForm.part_prices[p.id]) || 0) * Number(p.quantity)) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Warranty policy -->
                    <div>
                        <label class="block font-semibold text-sm mb-1">Chính sách bảo hành</label>
                        <select v-model="completeForm.warranty_policy" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            <option value="none">Không áp dụng</option>
                            <option value="free_labor" :disabled="!allowedPolicies.includes('free_labor')">Miễn công (free_labor)</option>
                            <option value="free_parts" :disabled="!allowedPolicies.includes('free_parts')">Miễn linh kiện (free_parts)</option>
                            <option value="full_free" :disabled="!allowedPolicies.includes('full_free')">Miễn toàn bộ (full_free)</option>
                        </select>
                        <p v-if="!task.warranty_id" class="text-[11px] text-orange-600 mt-1">Chưa gắn bảo hành → chỉ cho phép "Không áp dụng".</p>
                        <p v-else-if="!warrantyValid" class="text-[11px] text-orange-600 mt-1">Bảo hành đã hết hạn → chỉ cho phép "Không áp dụng".</p>
                    </div>

                    <!-- Summary -->
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm space-y-1">
                        <div class="flex justify-between"><span class="text-gray-500">Tổng linh kiện:</span><span>{{ formatCurrency(completePartsTotal) }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Tiền công:</span><span>{{ formatCurrency(completeForm.labor_fee) }}</span></div>
                        <div class="flex justify-between font-semibold"><span>Gross total:</span><span>{{ formatCurrency(completeGrossTotal) }}</span></div>
                        <div v-if="completeCovered > 0" class="flex justify-between text-green-600 font-semibold">
                            <span>Miễn theo BH:</span><span>-{{ formatCurrency(completeCovered) }}</span>
                        </div>
                        <div class="flex justify-between border-t pt-1 mt-1 font-bold text-blue-700">
                            <span>Khách phải trả:</span><span>{{ formatCurrency(completePayable) }}</span>
                        </div>
                    </div>

                    <!-- Paid + payment method -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-sm mb-1">Số tiền đã thu</label>
                            <MoneyInput v-model="completeForm.paid_amount" :min="0"
                                input-class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-right font-semibold" />
                        </div>
                        <div>
                            <label class="block font-semibold text-sm mb-1">Phương thức</label>
                            <select v-model="completeForm.payment_method" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                                <option value="cash">Tiền mặt</option>
                                <option value="bank_transfer">Chuyển khoản</option>
                                <option value="card">Thẻ</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Còn nợ:</span>
                        <span :class="completeDebt > 0 ? 'text-red-600 font-semibold' : 'text-green-600 font-semibold'">
                            {{ formatCurrency(completeDebt) }}
                        </span>
                    </div>
                    <p v-if="completeDebt > 0 && !task.customer_id" class="text-xs text-red-600">
                        Còn công nợ → cần chọn khách hàng (customer_id) cho phiếu.
                    </p>

                    <!-- Note -->
                    <div>
                        <label class="block font-semibold text-sm mb-1">Ghi chú</label>
                        <input v-model="completeForm.note" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                    </div>
                </div>
                <div class="flex justify-end gap-3 px-6 py-4 border-t sticky bottom-0 bg-white">
                    <button @click="showCompleteModal = false" class="px-5 py-2 border rounded-lg text-sm font-semibold">Hủy</button>
                    <button @click="submitComplete" :disabled="completeLoading"
                        class="px-5 py-2 bg-green-600 text-white rounded-lg text-sm font-semibold hover:bg-green-700 disabled:opacity-50">
                        {{ completeLoading ? 'Đang xử lý...' : 'Hoàn thành & Tạo hóa đơn' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 23.8F — Warranty lookup/attach modal -->
        <div v-if="showWarrantyLookup" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between px-6 py-4 border-b bg-purple-50 sticky top-0 z-10">
                    <h2 class="text-lg font-bold text-purple-700">Tra cứu / Gắn bảo hành</h2>
                    <button @click="showWarrantyLookup = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div v-if="warrantyLookupError" class="text-red-500 text-sm bg-red-50 px-3 py-2 rounded">{{ warrantyLookupError }}</div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-sm mb-1">Serial/IMEI</label>
                            <input v-model="warrantyLookupSerial" type="text" placeholder="VD: SN-ABC123" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" />
                        </div>
                        <div>
                            <label class="block font-semibold text-sm mb-1">Mã hóa đơn gốc</label>
                            <input v-model="warrantyLookupInvoice" type="text" placeholder="VD: HD-12345" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" />
                        </div>
                    </div>
                    <button @click="lookupWarranty" :disabled="warrantyLookupLoading"
                        class="w-full px-5 py-2 bg-purple-600 text-white rounded-lg text-sm font-semibold hover:bg-purple-700 disabled:opacity-50">
                        {{ warrantyLookupLoading ? 'Đang tìm...' : 'Tra cứu' }}
                    </button>
                    <div v-if="warrantyResults.length" class="border rounded-lg divide-y">
                        <div v-for="w in warrantyResults" :key="w.id" class="p-3 hover:bg-purple-50">
                            <div class="flex justify-between items-start gap-2">
                                <div class="flex-1 text-sm space-y-0.5">
                                    <div><span class="text-gray-500">HĐ:</span> <span class="font-mono">{{ w.invoice_code }}</span></div>
                                    <div><span class="text-gray-500">SP:</span> {{ w.product_name }}</div>
                                    <div><span class="text-gray-500">Serial:</span> <span class="font-mono">{{ w.serial_imei || '-' }}</span></div>
                                    <div><span class="text-gray-500">Mua:</span> {{ w.purchase_date ? new Date(w.purchase_date).toLocaleDateString('vi-VN') : '-' }} — <span class="text-gray-500">Hết hạn:</span> {{ w.warranty_end_date ? new Date(w.warranty_end_date).toLocaleDateString('vi-VN') : '-' }}</div>
                                    <div><span :class="w.valid ? 'text-green-600' : 'text-red-500'" class="font-semibold">{{ w.status_label }}</span></div>
                                </div>
                                <button @click="attachWarranty(w.id)"
                                    class="px-3 py-1.5 bg-purple-600 text-white rounded text-xs font-semibold hover:bg-purple-700">
                                    Gắn
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-3 px-6 py-4 border-t">
                    <button @click="showWarrantyLookup = false" class="px-5 py-2 border rounded-lg text-sm font-semibold">Đóng</button>
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
