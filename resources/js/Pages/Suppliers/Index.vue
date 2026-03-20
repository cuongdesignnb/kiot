<script setup>
import { ref, watch, reactive } from "vue";
import { Head, router, Link, useForm } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import axios from "axios";

const props = defineProps({
    suppliers: Object,
    groups: Array,
    filters: Object,
    summary: Object,
});

const search = ref(props.filters?.search || "");
const customerGroup = ref(props.filters?.customer_group || "");
const dateFilter = ref(props.filters?.date_filter || "all");
const partnerType = ref(props.filters?.partner_type || "all");
const expandedRows = ref([]); // array of expanded supplier IDs

let searchTimeout;
const updateFilters = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            "/suppliers",
            {
                search: search.value,
                customer_group: customerGroup.value,
                date_filter: dateFilter.value,
                partner_type: partnerType.value,
            },
            {
                preserveState: true,
                replace: true,
            },
        );
    }, 500);
};

watch([search, customerGroup, dateFilter, partnerType], updateFilters);

const toggleExpand = (supplierId) => {
    const index = expandedRows.value.indexOf(supplierId);
    if (index > -1) {
        expandedRows.value.splice(index, 1);
    } else {
        expandedRows.value.push(supplierId);
    }
};

const isExpanded = (supplierId) => {
    return expandedRows.value.includes(supplierId);
};

// Modal for CREATE CUSTOMER
const showCreateModal = ref(false);
const form = useForm({
    name: "",
    code: "",
    phone: "",
    phone2: "",
    birthday: "",
    gender: "none",
    email: "",
    facebook: "",

    address: "",
    city: "",
    district: "",
    ward: "",

    customer_group: "",
    note: "",

    type: "individual",
    invoice_name: "",
    id_card: "",
    passport: "",
    tax_code: "",

    invoice_address: "",
    invoice_city: "",
    invoice_district: "",
    invoice_ward: "",

    invoice_email: "",
    invoice_phone: "",
    bank_name: "",
    bank_account: "",

    is_supplier: false,
});

const submit = () => {
    form.post("/suppliers", {
        onSuccess: () => {
            showCreateModal.value = false;
            form.reset();
        },
    });
};

// ====== TAB MANAGEMENT ======
const supplierTabs = reactive({}); // { supplierId: 'info' | 'history' | 'debt' }
const supplierHistory = reactive({}); // { supplierId: [] }
const supplierDebt = reactive({}); // { supplierId: [] }
const supplierDataLoading = reactive({}); // { supplierId: bool }
const debtFilter = ref('all');

const getSupplierTab = (id) => supplierTabs[id] || 'info';

const setSupplierTab = async (id, tab) => {
    supplierTabs[id] = tab;
    if (tab === 'history' && !supplierHistory[id]) {
        supplierDataLoading[id] = true;
        try {
            const res = await axios.get(`/api/suppliers/${id}/purchase-history`);
            supplierHistory[id] = res.data;
        } catch (e) { supplierHistory[id] = []; }
        supplierDataLoading[id] = false;
    }
    if (tab === 'debt' && !supplierDebt[id]) {
        supplierDataLoading[id] = true;
        try {
            const res = await axios.get(`/api/suppliers/${id}/debt-transactions`);
            supplierDebt[id] = res.data;
        } catch (e) { supplierDebt[id] = []; }
        supplierDataLoading[id] = false;
    }
};

const filteredDebt = (id) => {
    const data = supplierDebt[id] || [];
    if (debtFilter.value === 'all') return data;
    return data.filter(d => d.type === debtFilter.value);
};

// ====== DEBT ACTION MODAL ======
const showDebtModal = ref(false);
const debtActionType = ref('payment'); // 'payment', 'adjustment', 'discount'
const debtActionSupplier = ref(null);
const debtAmount = ref(0);
const debtNote = ref('');
const debtSubmitting = ref(false);

const debtActionLabels = {
    payment: 'Thanh toán công nợ',
    adjustment: 'Điều chỉnh công nợ',
    discount: 'Chiết khấu thanh toán',
};

const openDebtAction = (supplier, type) => {
    debtActionSupplier.value = supplier;
    debtActionType.value = type;
    debtAmount.value = 0;
    debtNote.value = '';
    showDebtModal.value = true;
};

const submitDebtAction = async () => {
    if (!debtAmount.value) return;
    debtSubmitting.value = true;
    const id = debtActionSupplier.value.id;
    try {
        if (debtActionType.value === 'payment') {
            await axios.post(`/api/suppliers/${id}/payment`, {
                amount: debtAmount.value,
                note: debtNote.value,
            });
        } else {
            await axios.post(`/api/suppliers/${id}/adjust-debt`, {
                amount: debtAmount.value,
                note: debtNote.value,
                type: debtActionType.value,
            });
        }
        // Reload debt data
        delete supplierDebt[id];
        await setSupplierTab(id, 'debt');
        showDebtModal.value = false;
        // Refresh page to update summary
        router.reload({ only: ['suppliers', 'summary'] });
    } catch (e) {
        alert(e.response?.data?.message || 'Lỗi xử lý.');
    } finally {
        debtSubmitting.value = false;
    }
};
</script>

<template>
    <Head title="Nhà cung cấp - KiotViet Clone" />
    <AppLayout>
        <!-- Sidebar slot -->
        <template #sidebar>
            <!-- Lọc NHÓM NHÀ CUNG CẤP -->
            <div class="px-3 py-4 border-b border-gray-200">
                <div class="flex justify-between items-center mb-2">
                    <label class="block text-sm font-bold text-gray-800"
                        >Nhóm nhà cung cấp</label
                    >
                    <button class="text-blue-600 hover:underline text-xs">
                        Tạo mới
                    </button>
                </div>
                <select
                    v-model="customerGroup"
                    class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none focus:border-blue-500"
                >
                    <option value="">Tất cả các nhóm</option>
                    <option v-for="group in groups" :key="group" :value="group">
                        {{ group }}
                    </option>
                </select>
            </div>

            <!-- Lọc CHI NHÁNH TẠO -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Chi nhánh tạo</label
                >
                <div class="flex flex-wrap gap-2">
                    <div
                        class="bg-blue-600 text-white px-2 py-1 rounded text-xs flex items-center gap-1 cursor-pointer"
                    >
                        Laptopplus.vn
                        <span
                            class="pl-1 border-l border-blue-400 font-bold hover:text-gray-200"
                            >&times;</span
                        >
                    </div>
                </div>
            </div>

            <!-- Lọc NGÀY TẠO -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Ngày tạo</label
                >
                <div class="space-y-2 text-sm text-gray-700">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="radio"
                            v-model="dateFilter"
                            value="all"
                            name="create_date"
                            class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Toàn thời gian
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="radio"
                            v-model="dateFilter"
                            value="today"
                            name="create_date"
                            class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Hôm nay
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="radio"
                            v-model="dateFilter"
                            value="this_month"
                            name="create_date"
                            class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Tháng này
                    </label>
                </div>
            </div>

            <!-- Lọc NGƯỜI TẠO -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Người tạo</label
                >
                <select
                    class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none text-gray-500"
                >
                    <option>Chọn người tạo</option>
                </select>
            </div>

            <!-- LOẠI ĐỐI TÁC -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Loại đối tác</label
                >
                <div class="flex gap-2 text-sm flex-wrap">
                    <button
                        @click="partnerType = 'all'"
                        :class="{
                            'bg-blue-600 text-white border-blue-600':
                                partnerType === 'all',
                            'bg-white text-gray-600 border-gray-300':
                                partnerType !== 'all',
                        }"
                        class="border rounded-full px-3 py-1"
                    >
                        Tất cả
                    </button>
                    <button
                        @click="partnerType = 'supplier_only'"
                        :class="{
                            'bg-blue-600 text-white border-blue-600':
                                partnerType === 'supplier_only',
                            'bg-white text-gray-600 border-gray-300':
                                partnerType !== 'supplier_only',
                        }"
                        class="border rounded-full px-3 py-1"
                    >
                        Chỉ là NCC
                    </button>
                    <button
                        @click="partnerType = 'both'"
                        :class="{
                            'bg-blue-600 text-white border-blue-600':
                                partnerType === 'both',
                            'bg-white text-gray-600 border-gray-300':
                                partnerType !== 'both',
                        }"
                        class="border rounded-full px-3 py-1"
                    >
                        Vừa là NCC, Khách hàng
                    </button>
                </div>
            </div>

            <!-- GIỚI TÍNH -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Giới tính</label
                >
                <div class="flex gap-2 text-sm">
                    <button
                        class="bg-blue-600 text-white border border-blue-600 rounded-full px-3 py-1"
                    >
                        Tất cả
                    </button>
                    <button
                        class="bg-white text-gray-600 border border-gray-300 rounded-full px-3 py-1 hover:border-gray-400"
                    >
                        Nam
                    </button>
                    <button
                        class="bg-white text-gray-600 border border-gray-300 rounded-full px-3 py-1 hover:border-gray-400"
                    >
                        Nữ
                    </button>
                </div>
            </div>

            <!-- SINH NHẬT -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Sinh nhật</label
                >
                <div class="space-y-2 text-sm text-gray-700">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="radio"
                            name="birthday"
                            checked
                            class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Toàn thời gian
                        <svg
                            class="w-3 h-3 ml-auto text-gray-400"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 5l7 7-7 7"
                            ></path>
                        </svg>
                    </label>
                    <label
                        class="flex items-center gap-2 cursor-pointer text-gray-500"
                    >
                        <input
                            type="radio"
                            name="birthday"
                            class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Tùy chỉnh
                        <svg
                            class="w-4 h-4 ml-auto"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                            ></path>
                        </svg>
                    </label>
                </div>
            </div>

            <div class="px-3 py-4">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Ngày giao dịch cuối</label
                >
            </div>
        </template>

        <!-- Main content -->
        <div class="bg-white h-full flex flex-col pt-3">
            <!-- Header Toolbar -->
            <div
                class="flex items-center justify-between px-4 pb-3 border-b border-gray-200"
            >
                <div
                    class="flex items-center gap-4 flex-1 max-w-2xl text-2xl font-bold text-gray-800"
                >
                    Nhà cung cấp
                </div>

                <div
                    class="relative w-80 ml-auto mr-4 border-b border-gray-300"
                >
                    <svg
                        class="w-4 h-4 absolute left-1 top-1/2 -translate-y-1/2 text-gray-400"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                        ></path>
                    </svg>
                    <input
                        type="text"
                        v-model="search"
                        placeholder="Theo mã, tên, số điện thoại"
                        class="w-full pl-7 pr-8 py-1.5 focus:outline-none text-sm placeholder-gray-400 bg-transparent"
                    />
                    <svg
                        class="w-4 h-4 absolute right-1 top-1/2 -translate-y-1/2 text-gray-400"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"
                        ></path>
                    </svg>
                </div>

                <div class="flex gap-2 ml-2">
                    <button
                        @click="showCreateModal = true"
                        class="bg-white text-blue-600 border border-blue-600 px-3 py-1.5 text-sm font-medium rounded flex items-center gap-1 hover:bg-blue-50 transition"
                    >
                        <svg
                            class="w-4 h-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 4v16m8-8H4"
                            ></path></svg
                        >Nhà cung cấp
                    </button>
                    <ExcelButtons
                        export-url="/suppliers/export"
                        import-url="/suppliers/import"
                    />
                    <button
                        class="bg-white text-gray-600 border border-gray-300 px-2.5 py-1.5 rounded hover:bg-gray-50"
                    >
                        <svg
                            class="w-4 h-4 text-gray-500"
                            fill="currentColor"
                            viewBox="0 0 16 16"
                        >
                            <path
                                d="M3 9.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3m5 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3m5 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3"
                            />
                        </svg>
                    </button>
                    <!-- Settings layout fake -->
                    <div
                        class="flex items-center gap-1 ml-2 border-l border-gray-200 pl-2"
                    >
                        <button class="text-gray-400 hover:text-gray-600 p-1">
                            <svg
                                class="w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M4 6h16M4 10h16M4 14h16M4 18h16"
                                ></path>
                            </svg>
                        </button>
                        <button class="text-gray-400 hover:text-gray-600 p-1">
                            <svg
                                class="w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
                                ></path>
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                ></path>
                            </svg>
                        </button>
                        <button class="text-gray-400 hover:text-gray-600 p-1">
                            <svg
                                class="w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                ></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-auto bg-gray-50/20">
                <table class="w-full text-sm text-left whitespace-nowrap">
                    <thead
                        class="text-[13px] font-bold text-gray-700 bg-white border-b border-gray-200 sticky top-0 z-10 shadow-sm"
                    >
                        <tr>
                            <th class="px-4 py-3 w-10 text-center">
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-300"
                                />
                            </th>
                            <th class="px-4 py-3">Mã nhà cung cấp</th>
                            <th class="px-4 py-3">Tên nhà cung cấp</th>
                            <th class="px-4 py-3">Điện thoại</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3 text-right">
                                Nợ cần trả hiện tại
                            </th>
                            <th class="px-4 py-3 text-right">Tổng mua</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-gray-800">
                        <!-- Summary row -->
                        <tr class="bg-gray-50 border-b border-gray-200 font-semibold text-sm">
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td class="px-4 py-3 text-right text-red-600">{{ Number(summary?.total_debt || 0).toLocaleString() }}</td>
                            <td class="px-4 py-3 text-right text-gray-700">{{ Number(summary?.total_bought || 0).toLocaleString() }}</td>
                        </tr>
                        <tr v-if="suppliers.data.length === 0">
                            <td
                                colspan="7"
                                class="px-6 py-12 text-center text-gray-500"
                            >
                                Không tìm thấy nhà cung cấp nào.
                            </td>
                        </tr>
                        <template
                            v-for="supplier in suppliers.data"
                            :key="supplier.id"
                        >
                            <!-- Main Row -->
                            <tr
                                @click="toggleExpand(supplier.id)"
                                class="hover:bg-blue-50/50 transition-colors cursor-pointer bg-white"
                                :class="{
                                    'bg-[#f4f7fe]': isExpanded(supplier.id),
                                    'border-l-2 border-l-blue-500': isExpanded(
                                        supplier.id,
                                    ),
                                }"
                            >
                                <td class="px-4 py-3 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300 text-blue-500 focus:ring-blue-500"
                                    />
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <svg
                                            v-if="supplier.is_customer"
                                            class="w-4 h-4 text-blue-500"
                                            fill="currentColor"
                                            viewBox="0 0 20 20"
                                            xmlns="http://www.w3.org/2000/svg"
                                        >
                                            <path
                                                d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"
                                            ></path>
                                        </svg>
                                        {{ supplier.code }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">{{ supplier.name }}</td>
                                <td class="px-4 py-3">{{ supplier.phone }}</td>
                                <td class="px-4 py-3">{{ supplier.email }}</td>
                                <td class="px-4 py-3 text-right">
                                    {{
                                        Number(
                                            supplier.supplier_debt_amount,
                                        ).toLocaleString()
                                    }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{
                                        Number(
                                            supplier.total_bought,
                                        ).toLocaleString()
                                    }}
                                </td>
                            </tr>

                            <!-- Expanded Detail Row -->
                            <tr
                                v-if="isExpanded(supplier.id)"
                                class="border-b-4 border-blue-50"
                            >
                                <td colspan="7" class="p-0 border-0 bg-white">
                                    <div
                                        class="px-4 py-4 w-full shadow-inner border-t border-blue-100 flex flex-col pt-0"
                                    >
                                        <!-- Tabs within Detail -->
                                        <div
                                            class="flex text-[13.5px] font-semibold text-gray-600 border-b border-gray-200 sticky top-0 bg-white z-0 pt-2 mb-4"
                                        >
                                            <button
                                                @click="setSupplierTab(supplier.id, 'info')"
                                                :class="getSupplierTab(supplier.id) === 'info' ? 'border-b-2 border-blue-600 text-blue-600' : ''"
                                                class="px-4 pb-2 hover:text-blue-500 transition"
                                            >
                                                Thông tin
                                            </button>
                                            <button
                                                @click="setSupplierTab(supplier.id, 'history')"
                                                :class="getSupplierTab(supplier.id) === 'history' ? 'border-b-2 border-blue-600 text-blue-600' : ''"
                                                class="px-4 pb-2 hover:text-blue-500 transition"
                                            >
                                                Lịch sử nhập/trả hàng
                                            </button>
                                            <button
                                                @click="setSupplierTab(supplier.id, 'debt')"
                                                :class="getSupplierTab(supplier.id) === 'debt' ? 'border-b-2 border-blue-600 text-blue-600' : ''"
                                                class="px-4 pb-2 hover:text-blue-500 transition"
                                            >
                                                Nợ cần trả nhà cung cấp
                                            </button>
                                        </div>

                                        <!-- Tab: Thông tin -->
                                        <template v-if="getSupplierTab(supplier.id) === 'info'">
                                            <div class="flex items-start gap-4 mb-6">
                                                <div class="w-24 h-24 bg-blue-100 text-blue-500 rounded-full flex items-center justify-center flex-shrink-0 relative overflow-hidden">
                                                    <svg class="w-16 h-16 mt-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path></svg>
                                                </div>
                                                <div class="flex-1 mt-1">
                                                    <div class="flex gap-2 items-end mb-2">
                                                        <h2 class="text-xl font-bold text-gray-800">{{ supplier.name }}</h2>
                                                        <span class="text-gray-500 font-medium mb-0.5">{{ supplier.code }}</span>
                                                    </div>
                                                    <div class="text-[13px] text-gray-500 space-y-1 mb-2">
                                                        <div class="flex items-center gap-1 border-r border-gray-300 pr-3 mr-2 inline-block">Người tạo: <span class="text-gray-700">Admin</span></div>
                                                        <div class="flex items-center gap-1 border-r border-gray-300 pr-3 mr-2 inline-block">Ngày tạo: <span class="text-gray-700">{{ new Date(supplier.created_at).toLocaleDateString('vi-VN') }}</span></div>
                                                        <div class="flex items-center gap-1 inline-block">Nhóm nhà cung cấp: <span class="text-gray-700">{{ supplier.customer_group || 'Chưa có' }}</span></div>
                                                    </div>
                                                </div>
                                                <div class="text-[13px] text-gray-500 font-medium mt-1 pr-4">Laptopplus.vn</div>
                                            </div>
                                            <div class="grid grid-cols-2 gap-y-4 gap-x-8 text-[13.5px] border-b border-gray-200 pb-4 mb-4">
                                                <div><div class="text-gray-500 mb-0.5 font-medium">Điện thoại</div><div class="text-gray-800 font-medium">{{ supplier.phone || 'Chưa có' }}</div></div>
                                                <div><div class="text-gray-500 mb-0.5 font-medium">Email</div><div class="text-gray-400">{{ supplier.email || 'Chưa có' }}</div></div>
                                                <div class="col-span-2"><div class="text-gray-500 mb-0.5 font-medium">Địa chỉ</div><div class="text-gray-400">{{ supplier.address || 'Chưa có' }}</div></div>
                                            </div>
                                            <div class="bg-gray-50/50 rounded p-4 border border-gray-200 mb-4 text-[13.5px]">
                                                <div class="font-bold text-blue-600 mb-1 cursor-pointer hover:underline">Thêm thông tin xuất hóa đơn</div>
                                                <div class="flex items-center gap-2 text-gray-600 mt-2">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                                    {{ supplier.note || 'Chưa có ghi chú' }}
                                                </div>
                                            </div>
                                            <div class="flex items-center justify-between">
                                                <button class="text-gray-600 bg-white border border-gray-300 rounded px-3 py-1.5 text-[13.5px] font-semibold hover:bg-gray-50 flex items-center gap-1 shadow-sm">
                                                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>Xóa
                                                </button>
                                                <div class="flex gap-2 text-[13.5px]">
                                                    <button class="text-white bg-blue-600 rounded px-4 py-1.5 font-bold hover:bg-blue-700 flex items-center gap-1 shadow-sm">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>Cập nhật
                                                    </button>
                                                    <button class="text-gray-700 bg-white border border-gray-300 rounded px-4 py-1.5 font-bold hover:bg-gray-50 shadow-sm">Ngừng hoạt động</button>
                                                </div>
                                            </div>
                                        </template>

                                        <!-- Tab: Lịch sử nhập/trả hàng -->
                                        <template v-if="getSupplierTab(supplier.id) === 'history'">
                                            <div v-if="supplierDataLoading[supplier.id]" class="text-center py-8 text-gray-400">Đang tải...</div>
                                            <template v-else>
                                                <table class="w-full text-sm text-left mb-4">
                                                    <thead class="bg-gray-50 text-gray-600 text-xs uppercase">
                                                        <tr>
                                                            <th class="px-3 py-2">Mã phiếu</th>
                                                            <th class="px-3 py-2">Thời gian</th>
                                                            <th class="px-3 py-2">Người tạo</th>
                                                            <th class="px-3 py-2">Chi nhánh</th>
                                                            <th class="px-3 py-2 text-right">Tổng cộng</th>
                                                            <th class="px-3 py-2">Trạng thái</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y">
                                                        <tr v-if="!supplierHistory[supplier.id]?.length"><td colspan="6" class="px-3 py-6 text-center text-gray-400">Chưa có phiếu nhập/trả hàng.</td></tr>
                                                        <tr v-for="h in supplierHistory[supplier.id]" :key="h.id" class="hover:bg-gray-50">
                                                            <td class="px-3 py-2 text-blue-600 font-semibold cursor-pointer hover:underline">{{ h.code }}</td>
                                                            <td class="px-3 py-2">{{ h.date }}</td>
                                                            <td class="px-3 py-2">{{ h.user_name }}</td>
                                                            <td class="px-3 py-2">{{ h.branch }}</td>
                                                            <td class="px-3 py-2 text-right font-semibold">{{ Number(h.total).toLocaleString() }}</td>
                                                            <td class="px-3 py-2">
                                                                <span :class="h.status === 'completed' ? 'text-green-600' : 'text-orange-600'" class="font-semibold text-xs">{{ h.status_label }}</span>
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                                <div class="flex gap-2">
                                                    <button class="text-gray-600 bg-white border border-gray-300 rounded px-3 py-1.5 text-[13px] font-semibold hover:bg-gray-50 flex items-center gap-1">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                                        Xuất file
                                                    </button>
                                                </div>
                                            </template>
                                        </template>

                                        <!-- Tab: Nợ cần trả NCC -->
                                        <template v-if="getSupplierTab(supplier.id) === 'debt'">
                                            <div v-if="supplierDataLoading[supplier.id]" class="text-center py-8 text-gray-400">Đang tải...</div>
                                            <template v-else>
                                                <div class="flex justify-end mb-3">
                                                    <select v-model="debtFilter" class="border border-gray-300 rounded px-3 py-1.5 text-sm outline-none">
                                                        <option value="all">Tất cả giao dịch</option>
                                                        <option value="purchase">Nhập hàng</option>
                                                        <option value="payment">Thanh toán</option>
                                                        <option value="adjustment">Điều chỉnh</option>
                                                        <option value="discount">Chiết khấu</option>
                                                    </select>
                                                </div>
                                                <table class="w-full text-sm text-left mb-4">
                                                    <thead class="bg-gray-50 text-gray-600 text-xs uppercase">
                                                        <tr>
                                                            <th class="px-3 py-2">Mã phiếu</th>
                                                            <th class="px-3 py-2">Thời gian</th>
                                                            <th class="px-3 py-2">Loại</th>
                                                            <th class="px-3 py-2 text-right">Giá trị</th>
                                                            <th class="px-3 py-2 text-right">Nợ cần trả NCC</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y">
                                                        <tr v-if="!filteredDebt(supplier.id)?.length"><td colspan="5" class="px-3 py-6 text-center text-gray-400">Chưa có giao dịch công nợ.</td></tr>
                                                        <tr v-for="d in filteredDebt(supplier.id)" :key="d.id" class="hover:bg-gray-50">
                                                            <td class="px-3 py-2 text-blue-600 font-semibold">{{ d.code }}</td>
                                                            <td class="px-3 py-2">{{ d.date }}</td>
                                                            <td class="px-3 py-2">{{ d.type_label }}</td>
                                                            <td class="px-3 py-2 text-right font-semibold" :class="d.amount < 0 ? 'text-green-600' : ''">{{ Number(d.amount).toLocaleString() }}</td>
                                                            <td class="px-3 py-2 text-right font-semibold">{{ Number(d.debt_remain).toLocaleString() }}</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                                <div class="flex items-center justify-between">
                                                    <div class="flex gap-2">
                                                        <button class="text-gray-600 bg-white border border-gray-300 rounded px-3 py-1.5 text-[13px] font-semibold hover:bg-gray-50 flex items-center gap-1">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                                            Xuất file công nợ
                                                        </button>
                                                    </div>
                                                    <div class="flex gap-2 text-[13px]">
                                                        <button @click="openDebtAction(supplier, 'adjustment')" class="text-white bg-blue-600 rounded px-4 py-1.5 font-bold hover:bg-blue-700 shadow-sm flex items-center gap-1">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                                            Điều chỉnh
                                                        </button>
                                                        <button @click="openDebtAction(supplier, 'payment')" class="text-white bg-green-600 rounded px-4 py-1.5 font-bold hover:bg-green-700 shadow-sm flex items-center gap-1">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z"></path></svg>
                                                            Thanh toán
                                                        </button>
                                                        <button @click="openDebtAction(supplier, 'discount')" class="text-gray-700 bg-white border border-gray-300 rounded px-4 py-1.5 font-bold hover:bg-gray-50 shadow-sm flex items-center gap-1">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path></svg>
                                                            Chiết khấu TT
                                                        </button>
                                                    </div>
                                                </div>
                                            </template>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Footer Pagination -->
            <div
                class="flex items-center justify-between px-4 py-2 border-t border-gray-200 bg-white text-sm"
            >
                <div class="text-gray-600">
                    Hiển thị từ
                    <span class="font-bold">{{ suppliers.from || 0 }}</span> đến
                    <span class="font-bold">{{ suppliers.to || 0 }}</span> trong
                    tổng số
                    <span class="font-bold">{{ suppliers.total || 0 }}</span>
                    đối tác
                </div>
                <!-- Pagination -->
                <div
                    class="flex gap-1"
                    v-if="suppliers.links && suppliers.links.length > 3"
                >
                    <template
                        v-for="(link, index) in suppliers.links"
                        :key="index"
                    >
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="px-2.5 py-1 text-sm border rounded"
                            :class="
                                link.active
                                    ? 'bg-blue-600 text-white border-blue-600'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300'
                            "
                            v-html="link.label"
                        ></Link>
                        <span
                            v-else
                            class="px-2.5 py-1 text-sm border rounded bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed"
                            v-html="link.label"
                        ></span>
                    </template>
                </div>
            </div>
        </div>

        <!-- CREATE CUSTOMER MODAL -->
        <div
            v-if="showCreateModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 pt-10 pb-10"
        >
            <div
                class="bg-white rounded shadow-xl w-full max-w-4xl max-h-full overflow-hidden flex flex-col relative text-[13px] text-gray-800"
            >
                <div
                    class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gray-50"
                >
                    <h2 class="text-xl font-bold text-gray-800">
                        Thêm nhà cung cấp
                    </h2>
                    <button
                        @click="showCreateModal = false"
                        class="text-gray-400 hover:text-gray-600"
                    >
                        <svg
                            class="w-6 h-6"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            ></path>
                        </svg>
                    </button>
                </div>

                <div
                    class="flex-1 overflow-y-auto px-6 py-6 custom-scrollbar text-[13.5px]"
                >
                    <form @submit.prevent="submit" class="space-y-6">
                        <!-- Basic Info Master -->
                        <div
                            class="flex gap-8 items-start pb-4 border-b border-gray-100"
                        >
                            <div
                                class="flex-1 grid grid-cols-2 gap-x-6 gap-y-4"
                            >
                                <!-- Row 1 -->
                                <div>
                                    <label class="block font-semibold mb-1"
                                        >Tên nhà cung cấp
                                        <span class="text-red-500"
                                            >*</span
                                        ></label
                                    >
                                    <input
                                        v-model="form.name"
                                        type="text"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
                                        placeholder="Bắt buộc"
                                        required
                                    />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1"
                                        >Mã nhà cung cấp</label
                                    >
                                    <input
                                        v-model="form.code"
                                        type="text"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
                                        placeholder="Tự động"
                                    />
                                </div>

                                <!-- Row 2 -->
                                <div class="flex gap-2">
                                    <div class="w-1/2">
                                        <label class="block font-semibold mb-1"
                                            >Điện thoại</label
                                        >
                                        <input
                                            v-model="form.phone"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        />
                                    </div>
                                    <div class="w-1/2">
                                        <label class="block font-semibold mb-1"
                                            >Điện thoại 2</label
                                        >
                                        <input
                                            v-model="form.phone2"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        />
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <div class="w-1/2">
                                        <label class="block font-semibold mb-1"
                                            >Sinh nhật</label
                                        >
                                        <input
                                            v-model="form.birthday"
                                            type="date"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        />
                                    </div>
                                    <div class="w-1/2">
                                        <label class="block font-semibold mb-1"
                                            >Giới tính</label
                                        >
                                        <select
                                            v-model="form.gender"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        >
                                            <option value="none">
                                                Chọn giới tính
                                            </option>
                                            <option value="male">Nam</option>
                                            <option value="female">Nữ</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Row 3 -->
                                <div>
                                    <label class="block font-semibold mb-1"
                                        >Email</label
                                    >
                                    <input
                                        v-model="form.email"
                                        type="email"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        placeholder="email@gmail.com"
                                    />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1"
                                        >Facebook</label
                                    >
                                    <input
                                        v-model="form.facebook"
                                        type="text"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        placeholder="facebook.com/username"
                                    />
                                </div>
                            </div>

                            <!-- Avatar Circle Upload -->
                            <div class="w-32 flex flex-col items-center mt-2">
                                <div
                                    class="w-28 h-28 rounded-full border border-dashed border-gray-400 bg-gray-50 flex items-center justify-center flex-col text-gray-500 cursor-pointer hover:bg-gray-100 transition"
                                >
                                    <div
                                        class="bg-white border shadow-sm px-3 py-1 rounded text-[12px] font-bold text-gray-700"
                                    >
                                        Thêm ảnh
                                    </div>
                                </div>
                                <p
                                    class="text-[11px] text-gray-400 text-center mt-2"
                                >
                                    Ảnh không được vượt quá 2MB
                                </p>
                            </div>
                        </div>

                        <!-- Accordion 1: Địa chỉ -->
                        <div class="border border-gray-200 rounded">
                            <div
                                class="px-4 py-3 bg-gray-50 flex justify-between items-center cursor-pointer"
                            >
                                <h3 class="font-bold text-gray-800">Địa chỉ</h3>
                                <svg
                                    class="w-4 h-4 text-gray-500"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M5 15l7-7 7 7"
                                    ></path>
                                </svg>
                            </div>
                            <div class="p-4 grid grid-cols-2 gap-x-6 gap-y-4">
                                <div class="col-span-2">
                                    <label class="block font-semibold mb-1"
                                        >Địa chỉ</label
                                    >
                                    <input
                                        v-model="form.address"
                                        type="text"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        placeholder="Nhập địa chỉ"
                                    />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1"
                                        >Khu vực</label
                                    >
                                    <input
                                        v-model="form.city"
                                        type="text"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        placeholder="Chọn Tỉnh/Thành phố"
                                    />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1"
                                        >Phường/Xã</label
                                    >
                                    <input
                                        v-model="form.ward"
                                        type="text"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        placeholder="Chọn Phường/Xã"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Accordion 2: Nhóm khách hàng, ghi chú -->
                        <div class="border border-gray-200 rounded">
                            <div
                                class="px-4 py-3 bg-gray-50 flex justify-between items-center cursor-pointer"
                            >
                                <h3 class="font-bold text-gray-800">
                                    Nhóm khách hàng, ghi chú
                                </h3>
                                <svg
                                    class="w-4 h-4 text-gray-500"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M5 15l7-7 7 7"
                                    ></path>
                                </svg>
                            </div>
                            <div class="p-4 space-y-4">
                                <div>
                                    <label class="block font-semibold mb-1"
                                        >Nhóm khách hàng</label
                                    >
                                    <input
                                        v-model="form.customer_group"
                                        type="text"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                        placeholder="Chọn nhóm khách hàng"
                                    />
                                </div>
                                <div>
                                    <label class="block font-semibold mb-1"
                                        >Ghi chú</label
                                    >
                                    <textarea
                                        v-model="form.note"
                                        class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none resize-none h-16"
                                        placeholder="Nhập ghi chú"
                                    ></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Accordion 3: Thông tin xuất hóa đơn -->
                        <div class="border border-gray-200 rounded">
                            <div
                                class="px-4 py-3 bg-gray-50 flex justify-between items-center cursor-pointer"
                            >
                                <h3 class="font-bold text-gray-800">
                                    Thông tin xuất hóa đơn
                                </h3>
                                <svg
                                    class="w-4 h-4 text-gray-500"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M5 15l7-7 7 7"
                                    ></path>
                                </svg>
                            </div>
                            <div class="p-4">
                                <div class="flex items-center gap-6 mb-4">
                                    <label class="font-semibold text-gray-800"
                                        >Loại khách hàng</label
                                    >
                                    <label
                                        class="flex items-center gap-2 cursor-pointer"
                                    >
                                        <input
                                            type="radio"
                                            v-model="form.type"
                                            value="individual"
                                            class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                                        />
                                        Cá nhân
                                    </label>
                                    <label
                                        class="flex items-center gap-2 cursor-pointer"
                                    >
                                        <input
                                            type="radio"
                                            v-model="form.type"
                                            value="company"
                                            class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                                        />
                                        Tổ chức/ Hộ kinh doanh
                                    </label>
                                </div>
                                <div class="grid grid-cols-2 gap-x-6 gap-y-4">
                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Tên người mua</label
                                        >
                                        <input
                                            v-model="form.invoice_name"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="Nhập tên người mua"
                                        />
                                    </div>
                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Mã số thuế</label
                                        >
                                        <input
                                            v-model="form.tax_code"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="Nhập mã số thuế"
                                        />
                                    </div>

                                    <div class="col-span-2">
                                        <label class="block font-semibold mb-1"
                                            >Địa chỉ</label
                                        >
                                        <input
                                            v-model="form.invoice_address"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="Nhập địa chỉ"
                                        />
                                    </div>

                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Tỉnh/Thành phố</label
                                        >
                                        <input
                                            v-model="form.invoice_city"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="Tìm Tỉnh/Thành phố"
                                        />
                                    </div>
                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Phường/Xã</label
                                        >
                                        <input
                                            v-model="form.invoice_ward"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="Tìm Phường/Xã"
                                        />
                                    </div>

                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Số CCCD/CMND</label
                                        >
                                        <input
                                            v-model="form.id_card"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="Nhập số CCCD/CMND"
                                        />
                                    </div>
                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Số hộ chiếu</label
                                        >
                                        <input
                                            v-model="form.passport"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="Nhập số hộ chiếu"
                                        />
                                    </div>

                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Email</label
                                        >
                                        <input
                                            v-model="form.invoice_email"
                                            type="email"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="email@gmail.com"
                                        />
                                    </div>
                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Số điện thoại</label
                                        >
                                        <input
                                            v-model="form.invoice_phone"
                                            type="text"
                                            class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            placeholder="Nhập số điện thoại"
                                        />
                                    </div>

                                    <div class="flex gap-2 col-span-2">
                                        <div class="w-1/2">
                                            <label
                                                class="block font-semibold mb-1"
                                                >Ngân hàng</label
                                            >
                                            <select
                                                v-model="form.bank_name"
                                                class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                            >
                                                <option value="">
                                                    Chọn ngân hàng
                                                </option>
                                                <option value="vcb">
                                                    Vietcombank
                                                </option>
                                                <option value="tcb">
                                                    Techcombank
                                                </option>
                                            </select>
                                        </div>
                                        <div class="w-1/2">
                                            <label
                                                class="block font-semibold mb-1"
                                                >Số tài khoản ngân hàng</label
                                            >
                                            <input
                                                v-model="form.bank_account"
                                                type="text"
                                                class="w-full border border-gray-300 rounded px-3 py-1.5 focus:border-blue-500 outline-none"
                                                placeholder="Nhập số tài khoản ngân hàng"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Switch Is Customer -->
                        <div
                            class="bg-gray-50 border border-gray-200 rounded px-4 py-4 flex items-center justify-between"
                        >
                            <div>
                                <h3 class="font-bold text-[14px] text-gray-800">
                                    Là khách hàng
                                </h3>
                                <p class="text-[12px] text-gray-500 mt-0.5">
                                    Nhà cung cấp này đồng thời là một khách hàng
                                    trên hệ thống
                                </p>
                            </div>
                            <label
                                class="relative inline-flex items-center cursor-pointer"
                            >
                                <input
                                    type="checkbox"
                                    v-model="form.is_customer"
                                    class="sr-only peer"
                                />
                                <div
                                    class="w-11 h-6 bg-gray-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"
                                ></div>
                            </label>
                        </div>
                    </form>
                </div>

                <div
                    class="px-6 py-4 border-t border-gray-200 bg-white flex justify-end gap-3 rounded-b"
                >
                    <button
                        @click="showCreateModal = false"
                        class="px-6 py-2 border border-gray-300 rounded text-gray-700 bg-white font-bold hover:bg-gray-50 transition shadow-sm"
                    >
                        Bỏ qua
                    </button>
                    <button
                        @click="submit"
                        class="px-8 py-2 border border-transparent rounded text-white bg-blue-600 font-bold hover:bg-blue-700 transition shadow-sm"
                        :class="{
                            'opacity-50 cursor-not-allowed': form.processing,
                        }"
                    >
                        Lưu
                    </button>
                </div>
            </div>
        </div>

        <!-- Debt Action Modal (Thanh toan / Dieu chinh / Chiet khau) -->
        <div v-if="showDebtModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="showDebtModal = false">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4">
                <div class="px-6 py-4 border-b flex items-center justify-between" :class="debtActionType === 'payment' ? 'bg-green-50' : debtActionType === 'discount' ? 'bg-orange-50' : 'bg-blue-50'">
                    <h3 class="text-lg font-bold" :class="debtActionType === 'payment' ? 'text-green-700' : debtActionType === 'discount' ? 'text-orange-700' : 'text-blue-700'">
                        {{ debtActionLabels[debtActionType] }}
                    </h3>
                    <button @click="showDebtModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div class="text-sm text-gray-600 bg-gray-50 rounded p-3 border">
                        <div class="flex justify-between">
                            <span>Nhà cung cấp:</span>
                            <span class="font-semibold text-gray-800">{{ debtActionSupplier?.name }}</span>
                        </div>
                        <div class="flex justify-between mt-1">
                            <span>Nợ hiện tại:</span>
                            <span class="font-bold text-red-600">{{ Number(debtActionSupplier?.supplier_debt_amount || 0).toLocaleString() }}₫</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">
                            {{ debtActionType === 'payment' ? 'Số tiền thanh toán' : debtActionType === 'discount' ? 'Số tiền chiết khấu' : 'Số tiền điều chỉnh' }}
                            <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <input v-model.number="debtAmount" type="number" min="0" class="w-full border border-gray-300 rounded-lg px-3 py-2 pr-8 text-sm text-right font-semibold focus:border-blue-500 outline-none" placeholder="0" />
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">₫</span>
                        </div>
                        <p v-if="debtActionType === 'adjustment'" class="text-xs text-gray-400 mt-1">Nhập số dương để tăng nợ, số âm để giảm nợ.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Ghi chú</label>
                        <input v-model="debtNote" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" :placeholder="debtActionLabels[debtActionType]" />
                    </div>
                </div>
                <div class="flex justify-end gap-3 px-6 py-4 border-t">
                    <button @click="showDebtModal = false" class="px-5 py-2 border rounded-lg text-sm font-semibold text-gray-600 hover:bg-gray-50">Hủy</button>
                    <button @click="submitDebtAction" :disabled="!debtAmount || debtSubmitting"
                        class="px-6 py-2 rounded-lg text-sm font-bold text-white disabled:opacity-50 flex items-center gap-2"
                        :class="debtActionType === 'payment' ? 'bg-green-600 hover:bg-green-700' : debtActionType === 'discount' ? 'bg-orange-600 hover:bg-orange-700' : 'bg-blue-600 hover:bg-blue-700'">
                        <svg v-if="debtSubmitting" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        {{ debtSubmitting ? 'Đang xử lý...' : 'Xác nhận' }}
                    </button>
                </div>
            </div>
        </div>

    </AppLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: #d1d5db;
    border-radius: 10px;
}
</style>
