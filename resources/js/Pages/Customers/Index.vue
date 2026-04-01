<script setup>
import { ref, watch, reactive } from "vue";
import { Head, router, Link, useForm } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import axios from "axios";

const props = defineProps({
    customers: Object,
    filters: Object,
    summary: Object,
});

const search = ref(props.filters?.search || "");
const filterType = ref(props.filters?.type || "");
const filterGender = ref(props.filters?.gender || "");
const expandedRows = ref([]); // array of expanded customer IDs

// Tab state per customer
const customerTabs = reactive({}); // { customerId: 'info' | 'address' | 'history' | 'debt' | 'points' }
const getActiveTab = (id) => customerTabs[id] || "info";
const setActiveTab = (id, tab) => {
    customerTabs[id] = tab;
    if (tab === "history" && !salesHistoryData[id]) loadSalesHistory(id);
    if (tab === "debt" && !debtHistoryData[id]) loadDebtHistory(id);
};

// Lazy-loaded tab data
const salesHistoryData = reactive({});
const debtHistoryData = reactive({});
const tabLoading = reactive({});

const loadSalesHistory = async (customerId) => {
    tabLoading[customerId] = true;
    try {
        const { data } = await axios.get(
            `/customers/${customerId}/sales-history`,
        );
        salesHistoryData[customerId] = data;
    } catch (e) {
        salesHistoryData[customerId] = { invoices: [], returns: [] };
    }
    tabLoading[customerId] = false;
};

const loadDebtHistory = async (customerId) => {
    tabLoading[customerId] = true;
    try {
        const { data } = await axios.get(
            `/customers/${customerId}/debt-history`,
        );
        debtHistoryData[customerId] = data;
    } catch (e) {
        debtHistoryData[customerId] = { entries: [] };
    }
    tabLoading[customerId] = false;
};

let searchTimeout;
const applyFilters = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            "/customers",
            {
                search: search.value || undefined,
                type: filterType.value || undefined,
                gender: filterGender.value || undefined,
            },
            {
                preserveState: true,
                replace: true,
            },
        );
    }, 300);
};
watch([search, filterType, filterGender], applyFilters);

const toggleExpand = (customerId) => {
    const index = expandedRows.value.indexOf(customerId);
    if (index > -1) {
        expandedRows.value.splice(index, 1);
    } else {
        expandedRows.value.push(customerId);
    }
};

const isExpanded = (customerId) => {
    return expandedRows.value.includes(customerId);
};

const formatCurrency = (val) => Number(val || 0).toLocaleString("vi-VN");
const formatDate = (val) =>
    val ? new Date(val).toLocaleDateString("vi-VN") : "Chưa có";
const formatDateTime = (val) => {
    if (!val) return "";
    const d = new Date(val);
    return (
        d.toLocaleDateString("vi-VN") +
        " " +
        d.toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" })
    );
};
const formatGender = (val) => {
    if (val === "male") return "Nam";
    if (val === "female") return "Nữ";
    return "Chưa có";
};
const buildFullAddress = (c) =>
    [c.address, c.ward, c.district, c.city].filter(Boolean).join(", ");

const deleteCustomer = (customer) => {
    if (!confirm(`Bạn có chắc muốn xóa khách hàng "${customer.name}"?`)) return;
    router.delete(`/customers/${customer.id}`);
};

const toggleStatus = (customer) => {
    const newStatus = customer.status === "active" ? "inactive" : "active";
    router.put(
        `/customers/${customer.id}`,
        { status: newStatus },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
};

// Edit customer modal
const showEditModal = ref(false);
const editingCustomer = ref(null);
const editForm = useForm({
    name: "", code: "", phone: "", phone2: "", birthday: "", gender: "none",
    email: "", facebook: "", address: "", city: "", district: "", ward: "",
    customer_group: "", note: "", type: "individual",
    invoice_name: "", tax_code: "", invoice_address: "",
});

const openEditModal = (customer) => {
    editingCustomer.value = customer;
    Object.keys(editForm.data()).forEach(key => {
        editForm[key] = customer[key] ?? (key === 'gender' ? 'none' : key === 'type' ? 'individual' : '');
    });
    showEditModal.value = true;
};

const submitEdit = () => {
    editForm.put(`/customers/${editingCustomer.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            editingCustomer.value = null;
            editForm.reset();
        },
    });
};

// Debt payment / adjustment modals
const debtModal = ref({
    show: false,
    type: "",
    customerId: null,
    customerName: "",
    currentDebt: 0,
});
const debtForm = reactive({ amount: 0, note: "" });

const openDebtModal = (customer, type) => {
    debtModal.value = {
        show: true,
        type,
        customerId: customer.id,
        customerName: customer.name,
        currentDebt: customer.debt_amount || 0,
    };
    debtForm.amount = 0;
    debtForm.note = "";
};

const submitDebtModal = async () => {
    const { type, customerId } = debtModal.value;
    if (!debtForm.amount || debtForm.amount <= 0) {
        alert("Vui lòng nhập số tiền hợp lệ");
        return;
    }
    try {
        const url =
            type === "payment"
                ? `/customers/${customerId}/debt-payment`
                : `/customers/${customerId}/debt-adjust`;
        await axios.post(url, { amount: debtForm.amount, note: debtForm.note });
        debtModal.value.show = false;
        // Reload debt history
        await loadDebtHistory(customerId);
        // Refresh page to update debt_amount in list
        router.reload({ only: ["customers"], preserveScroll: true });
    } catch (e) {
        alert(e.response?.data?.message || "Có lỗi xảy ra");
    }
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
    form.post("/customers", {
        onSuccess: () => {
            showCreateModal.value = false;
            form.reset();
        },
    });
};
</script>

<template>
    <Head title="Khách hàng - KiotViet Clone" />
    <AppLayout>
        <!-- Sidebar slot -->
        <template #sidebar>
            <!-- Lọc NHÓM KHÁCH HÀNG -->
            <div class="px-3 py-4 border-b border-gray-200">
                <div class="flex justify-between items-center mb-2">
                    <label class="block text-sm font-bold text-gray-800"
                        >Nhóm khách hàng</label
                    >
                    <button class="text-blue-600 hover:underline text-xs">
                        Tạo mới
                    </button>
                </div>
                <select
                    class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none focus:border-blue-500"
                >
                    <option>Tất cả các nhóm</option>
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
                            name="create_date"
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
                            name="create_date"
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

            <!-- LOẠI KHÁCH HÀNG -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Loại khách hàng</label
                >
                <div class="flex gap-2 text-sm">
                    <button
                        @click="filterType = ''"
                        :class="
                            !filterType
                                ? 'bg-blue-600 text-white border-blue-600'
                                : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'
                        "
                        class="border rounded-full px-3 py-1"
                    >
                        Tất cả
                    </button>
                    <button
                        @click="filterType = 'individual'"
                        :class="
                            filterType === 'individual'
                                ? 'bg-blue-600 text-white border-blue-600'
                                : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'
                        "
                        class="border rounded-full px-3 py-1"
                    >
                        Cá nhân
                    </button>
                    <button
                        @click="filterType = 'company'"
                        :class="
                            filterType === 'company'
                                ? 'bg-blue-600 text-white border-blue-600'
                                : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'
                        "
                        class="border rounded-full px-3 py-1"
                    >
                        Công ty
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
                        @click="filterGender = ''"
                        :class="
                            !filterGender
                                ? 'bg-blue-600 text-white border-blue-600'
                                : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'
                        "
                        class="border rounded-full px-3 py-1"
                    >
                        Tất cả
                    </button>
                    <button
                        @click="filterGender = 'male'"
                        :class="
                            filterGender === 'male'
                                ? 'bg-blue-600 text-white border-blue-600'
                                : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'
                        "
                        class="border rounded-full px-3 py-1"
                    >
                        Nam
                    </button>
                    <button
                        @click="filterGender = 'female'"
                        :class="
                            filterGender === 'female'
                                ? 'bg-blue-600 text-white border-blue-600'
                                : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'
                        "
                        class="border rounded-full px-3 py-1"
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
                    Khách hàng
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
                        >Khách hàng
                    </button>
                    <ExcelButtons
                        export-url="/customers/export"
                        import-url="/customers/import"
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
                            <th class="px-4 py-3">Mã khách hàng</th>
                            <th class="px-4 py-3">Tên khách hàng</th>
                            <th class="px-4 py-3">Điện thoại</th>
                            <th class="px-4 py-3 text-right">Nợ hiện tại</th>
                            <th class="px-4 py-3 text-right">Số ngày nợ</th>
                            <th class="px-4 py-3 text-right">Tổng bán</th>
                            <th class="px-4 py-3 text-right">
                                Tổng bán trừ trả hàng
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-gray-800">
                        <!-- Summary row -->
                        <tr class="bg-gray-50 border-b border-gray-200 font-semibold text-sm">
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td class="px-4 py-3 text-right text-red-600">{{ Number(summary?.total_debt || 0).toLocaleString() }}</td>
                            <td></td>
                            <td class="px-4 py-3 text-right text-gray-700">{{ Number(summary?.total_spent || 0).toLocaleString() }}</td>
                            <td class="px-4 py-3 text-right text-gray-700">{{ Number((summary?.total_spent || 0) - (summary?.total_returns || 0)).toLocaleString() }}</td>
                        </tr>
                        <tr v-if="customers.data.length === 0">
                            <td
                                colspan="8"
                                class="px-6 py-12 text-center text-gray-500"
                            >
                                Không tìm thấy khách hàng nào.
                            </td>
                        </tr>
                        <template
                            v-for="customer in customers.data"
                            :key="customer.id"
                        >
                            <!-- Main Row -->
                            <tr
                                @click="toggleExpand(customer.id)"
                                class="hover:bg-blue-50/50 transition-colors cursor-pointer bg-white"
                                :class="{
                                    'bg-[#f4f7fe]': isExpanded(customer.id),
                                    'border-l-2 border-l-blue-500': isExpanded(
                                        customer.id,
                                    ),
                                }"
                            >
                                <td class="px-4 py-3 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300 text-blue-500 focus:ring-blue-500"
                                    />
                                </td>
                                <td class="px-4 py-3">{{ customer.code }}</td>
                                <td class="px-4 py-3">{{ customer.name }}</td>
                                <td class="px-4 py-3">{{ customer.phone }}</td>
                                <td class="px-4 py-3 text-right">
                                    {{
                                        Number(
                                            customer.debt_amount,
                                        ).toLocaleString()
                                    }}
                                </td>
                                <td class="px-4 py-3 text-right text-gray-400">
                                    ---
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{
                                        Number(
                                            customer.total_spent,
                                        ).toLocaleString()
                                    }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{
                                        Number(
                                            customer.total_spent -
                                                customer.total_returns,
                                        ).toLocaleString()
                                    }}
                                </td>
                            </tr>

                            <!-- Expanded Detail Row -->
                            <tr
                                v-if="isExpanded(customer.id)"
                                class="border-b-4 border-blue-50"
                            >
                                <td colspan="8" class="p-0 border-0 bg-white">
                                    <div
                                        class="px-4 py-4 w-full shadow-inner border-t border-blue-100 flex flex-col pt-0"
                                    >
                                        <!-- Tabs within Detail -->
                                        <div
                                            class="flex text-[13.5px] font-semibold text-gray-600 border-b border-gray-200 sticky top-0 bg-white z-0 pt-2 mb-4"
                                        >
                                            <button
                                                @click="
                                                    setActiveTab(
                                                        customer.id,
                                                        'info',
                                                    )
                                                "
                                                class="px-4 pb-2 transition"
                                                :class="
                                                    getActiveTab(
                                                        customer.id,
                                                    ) === 'info'
                                                        ? 'border-b-2 border-blue-600 text-blue-600'
                                                        : 'hover:text-blue-500'
                                                "
                                            >
                                                Thông tin
                                            </button>
                                            <button
                                                @click="
                                                    setActiveTab(
                                                        customer.id,
                                                        'address',
                                                    )
                                                "
                                                class="px-4 pb-2 transition"
                                                :class="
                                                    getActiveTab(
                                                        customer.id,
                                                    ) === 'address'
                                                        ? 'border-b-2 border-blue-600 text-blue-600'
                                                        : 'hover:text-blue-500'
                                                "
                                            >
                                                Địa chỉ nhận hàng
                                            </button>
                                            <button
                                                @click="
                                                    setActiveTab(
                                                        customer.id,
                                                        'history',
                                                    )
                                                "
                                                class="px-4 pb-2 transition"
                                                :class="
                                                    getActiveTab(
                                                        customer.id,
                                                    ) === 'history'
                                                        ? 'border-b-2 border-blue-600 text-blue-600'
                                                        : 'hover:text-blue-500'
                                                "
                                            >
                                                Lịch sử bán/trả hàng
                                            </button>
                                            <button
                                                @click="
                                                    setActiveTab(
                                                        customer.id,
                                                        'debt',
                                                    )
                                                "
                                                class="px-4 pb-2 transition"
                                                :class="
                                                    getActiveTab(
                                                        customer.id,
                                                    ) === 'debt'
                                                        ? 'border-b-2 border-blue-600 text-blue-600'
                                                        : 'hover:text-blue-500'
                                                "
                                            >
                                                Nợ cần thu từ khách
                                            </button>
                                            <button
                                                @click="
                                                    setActiveTab(
                                                        customer.id,
                                                        'points',
                                                    )
                                                "
                                                class="px-4 pb-2 transition"
                                                :class="
                                                    getActiveTab(
                                                        customer.id,
                                                    ) === 'points'
                                                        ? 'border-b-2 border-blue-600 text-blue-600'
                                                        : 'hover:text-blue-500'
                                                "
                                            >
                                                Lịch sử tích điểm
                                            </button>
                                        </div>

                                        <!-- ===== TAB: Thông tin ===== -->
                                        <div
                                            v-if="
                                                getActiveTab(customer.id) ===
                                                'info'
                                            "
                                        >
                                            <div
                                                class="flex items-start gap-4 mb-6"
                                            >
                                                <div
                                                    class="w-24 h-24 bg-blue-100 text-blue-500 rounded-full flex items-center justify-center flex-shrink-0 relative overflow-hidden"
                                                >
                                                    <svg
                                                        class="w-16 h-16 mt-4"
                                                        fill="currentColor"
                                                        viewBox="0 0 20 20"
                                                    >
                                                        <path
                                                            fill-rule="evenodd"
                                                            d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"
                                                            clip-rule="evenodd"
                                                        ></path>
                                                    </svg>
                                                </div>
                                                <div class="flex-1 mt-1">
                                                    <div
                                                        class="flex gap-2 items-end mb-2"
                                                    >
                                                        <h2
                                                            class="text-xl font-bold text-gray-800"
                                                        >
                                                            {{ customer.name }}
                                                        </h2>
                                                        <span
                                                            class="text-gray-500 font-medium mb-0.5"
                                                            >{{
                                                                customer.code
                                                            }}</span
                                                        >
                                                    </div>
                                                    <div
                                                        class="text-[13px] text-gray-500 space-y-1 mb-2"
                                                    >
                                                        <span
                                                            class="border-r border-gray-300 pr-3 mr-2"
                                                            >Người tạo:
                                                            <span
                                                                class="text-gray-700"
                                                                >{{
                                                                    customer.created_by_name ||
                                                                    "—"
                                                                }}</span
                                                            ></span
                                                        >
                                                        <span
                                                            class="border-r border-gray-300 pr-3 mr-2"
                                                            >Ngày tạo:
                                                            <span
                                                                class="text-gray-700"
                                                                >{{
                                                                    formatDate(
                                                                        customer.created_at,
                                                                    )
                                                                }}</span
                                                            ></span
                                                        >
                                                        <span
                                                            >Nhóm khách:
                                                            <span
                                                                class="text-gray-700"
                                                                >{{
                                                                    customer.customer_group ||
                                                                    "Chưa có"
                                                                }}</span
                                                            ></span
                                                        >
                                                    </div>
                                                    <button
                                                        class="text-blue-600 hover:text-blue-800 text-sm font-semibold flex items-center gap-1"
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
                                                                d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"
                                                            ></path>
                                                        </svg>
                                                        Xem phân tích
                                                    </button>
                                                </div>
                                                <div
                                                    class="text-[13px] text-gray-500 font-medium mt-1 pr-4"
                                                >
                                                    {{
                                                        customer.branch?.name ||
                                                        "—"
                                                    }}
                                                </div>
                                            </div>

                                            <div
                                                class="grid grid-cols-3 gap-y-4 gap-x-8 text-[13.5px] border-b border-gray-200 pb-4 mb-4"
                                            >
                                                <div>
                                                    <div
                                                        class="text-gray-500 mb-0.5 font-medium"
                                                    >
                                                        Điện thoại
                                                    </div>
                                                    <div
                                                        class="text-gray-800 font-medium"
                                                    >
                                                        {{
                                                            customer.phone ||
                                                            "Chưa có"
                                                        }}
                                                    </div>
                                                </div>
                                                <div>
                                                    <div
                                                        class="text-gray-500 mb-0.5 font-medium"
                                                    >
                                                        Sinh nhật
                                                    </div>
                                                    <div
                                                        :class="
                                                            customer.birthday
                                                                ? 'text-gray-800'
                                                                : 'text-gray-400'
                                                        "
                                                    >
                                                        {{
                                                            customer.birthday
                                                                ? formatDate(
                                                                      customer.birthday,
                                                                  )
                                                                : "Chưa có"
                                                        }}
                                                    </div>
                                                </div>
                                                <div>
                                                    <div
                                                        class="text-gray-500 mb-0.5 font-medium"
                                                    >
                                                        Giới tính
                                                    </div>
                                                    <div
                                                        :class="
                                                            customer.gender !==
                                                            'none'
                                                                ? 'text-gray-800'
                                                                : 'text-gray-400'
                                                        "
                                                    >
                                                        {{
                                                            formatGender(
                                                                customer.gender,
                                                            )
                                                        }}
                                                    </div>
                                                </div>
                                                <div>
                                                    <div
                                                        class="text-gray-500 mb-0.5 font-medium"
                                                    >
                                                        Email
                                                    </div>
                                                    <div
                                                        :class="
                                                            customer.email
                                                                ? 'text-gray-800'
                                                                : 'text-gray-400'
                                                        "
                                                    >
                                                        {{
                                                            customer.email ||
                                                            "Chưa có"
                                                        }}
                                                    </div>
                                                </div>
                                                <div>
                                                    <div
                                                        class="text-gray-500 mb-0.5 font-medium"
                                                    >
                                                        Facebook
                                                    </div>
                                                    <div
                                                        :class="
                                                            customer.facebook
                                                                ? 'text-gray-800'
                                                                : 'text-gray-400'
                                                        "
                                                    >
                                                        {{
                                                            customer.facebook ||
                                                            "Chưa có"
                                                        }}
                                                    </div>
                                                </div>
                                                <div></div>
                                                <div class="col-span-3">
                                                    <div
                                                        class="text-gray-500 mb-0.5 font-medium"
                                                    >
                                                        Địa chỉ
                                                    </div>
                                                    <div
                                                        :class="
                                                            buildFullAddress(
                                                                customer,
                                                            )
                                                                ? 'text-gray-800'
                                                                : 'text-gray-400'
                                                        "
                                                    >
                                                        {{
                                                            buildFullAddress(
                                                                customer,
                                                            ) || "Chưa có"
                                                        }}
                                                    </div>
                                                </div>
                                            </div>

                                            <div
                                                class="bg-gray-50/50 rounded p-4 border border-gray-200 mb-4 text-[13.5px]"
                                            >
                                                <div
                                                    class="font-bold text-gray-700 mb-1"
                                                >
                                                    Thông tin xuất hóa đơn
                                                </div>
                                                <div class="text-gray-600 mb-4">
                                                    {{
                                                        customer.invoice_name ||
                                                        customer.name
                                                    }}
                                                    /
                                                    {{
                                                        customer.invoice_phone ||
                                                        customer.phone
                                                    }}
                                                </div>
                                                <div
                                                    class="flex items-center gap-2 text-gray-600 font-bold mt-2"
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
                                                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                                                        ></path>
                                                    </svg>
                                                    {{
                                                        customer.note ||
                                                        "Chưa có ghi chú"
                                                    }}
                                                </div>
                                            </div>
                                        </div>

                                        <!-- ===== TAB: Địa chỉ nhận hàng ===== -->
                                        <div
                                            v-if="
                                                getActiveTab(customer.id) ===
                                                'address'
                                            "
                                        >
                                            <div class="text-[13.5px]">
                                                <div
                                                    class="bg-gray-50 rounded border border-gray-200 p-4 mb-4"
                                                    v-if="
                                                        buildFullAddress(
                                                            customer,
                                                        )
                                                    "
                                                >
                                                    <div
                                                        class="flex items-center gap-2 mb-2"
                                                    >
                                                        <svg
                                                            class="w-4 h-4 text-blue-500"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            viewBox="0 0 24 24"
                                                        >
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"
                                                            ></path>
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"
                                                            ></path>
                                                        </svg>
                                                        <span
                                                            class="font-bold text-gray-700"
                                                            >Địa chỉ mặc
                                                            định</span
                                                        >
                                                        <span
                                                            class="text-xs bg-blue-100 text-blue-600 px-2 py-0.5 rounded"
                                                            >Mặc định</span
                                                        >
                                                    </div>
                                                    <div
                                                        class="pl-6 text-gray-600 space-y-1"
                                                    >
                                                        <div>
                                                            {{
                                                                customer.name
                                                            }}
                                                            -
                                                            {{ customer.phone }}
                                                        </div>
                                                        <div>
                                                            {{
                                                                buildFullAddress(
                                                                    customer,
                                                                )
                                                            }}
                                                        </div>
                                                    </div>
                                                </div>
                                                <div
                                                    v-else
                                                    class="text-center py-12 text-gray-400"
                                                >
                                                    <svg
                                                        class="w-12 h-12 mx-auto mb-3 text-gray-300"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        viewBox="0 0 24 24"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"
                                                        ></path>
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"
                                                        ></path>
                                                    </svg>
                                                    Chưa có địa chỉ nhận hàng
                                                </div>
                                            </div>
                                        </div>

                                        <!-- ===== TAB: Lịch sử bán/trả hàng ===== -->
                                        <div
                                            v-if="
                                                getActiveTab(customer.id) ===
                                                'history'
                                            "
                                        >
                                            <div
                                                v-if="tabLoading[customer.id]"
                                                class="text-center py-8 text-gray-400"
                                            >
                                                Đang tải...
                                            </div>
                                            <div
                                                v-else-if="
                                                    salesHistoryData[
                                                        customer.id
                                                    ]
                                                "
                                            >
                                                <div
                                                    v-if="
                                                        salesHistoryData[
                                                            customer.id
                                                        ].invoices.length ===
                                                            0 &&
                                                        salesHistoryData[
                                                            customer.id
                                                        ].returns.length === 0
                                                    "
                                                    class="text-center py-12 text-gray-400"
                                                >
                                                    <svg
                                                        class="w-12 h-12 mx-auto mb-3 text-gray-300"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        viewBox="0 0 24 24"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                                        ></path>
                                                    </svg>
                                                    Khách hàng chưa có giao dịch
                                                    nào
                                                </div>
                                                <table
                                                    v-else
                                                    class="w-full text-[13px]"
                                                >
                                                    <thead
                                                        class="bg-gray-50 text-gray-600 font-semibold"
                                                    >
                                                        <tr>
                                                            <th
                                                                class="px-3 py-2 text-left"
                                                            >
                                                                Mã chứng từ
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-left"
                                                            >
                                                                Loại
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-left"
                                                            >
                                                                Thời gian
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-right"
                                                            >
                                                                Giá trị
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-left"
                                                            >
                                                                Trạng thái
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody
                                                        class="divide-y divide-gray-100"
                                                    >
                                                        <tr
                                                            v-for="inv in salesHistoryData[
                                                                customer.id
                                                            ].invoices"
                                                            :key="
                                                                'inv-' + inv.id
                                                            "
                                                            class="hover:bg-blue-50/30"
                                                        >
                                                            <td
                                                                class="px-3 py-2 text-blue-600 font-medium"
                                                            >
                                                                {{ inv.code }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2"
                                                            >
                                                                <span
                                                                    class="bg-green-100 text-green-700 px-2 py-0.5 rounded text-xs"
                                                                    >Bán
                                                                    hàng</span
                                                                >
                                                            </td>
                                                            <td
                                                                class="px-3 py-2"
                                                            >
                                                                {{
                                                                    formatDate(
                                                                        inv.created_at,
                                                                    )
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2 text-right font-medium"
                                                            >
                                                                {{
                                                                    formatCurrency(
                                                                        inv.total,
                                                                    )
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2"
                                                            >
                                                                {{ inv.status }}
                                                            </td>
                                                        </tr>
                                                        <tr
                                                            v-for="ret in salesHistoryData[
                                                                customer.id
                                                            ].returns"
                                                            :key="
                                                                'ret-' + ret.id
                                                            "
                                                            class="hover:bg-blue-50/30"
                                                        >
                                                            <td
                                                                class="px-3 py-2 text-blue-600 font-medium"
                                                            >
                                                                {{ ret.code }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2"
                                                            >
                                                                <span
                                                                    class="bg-red-100 text-red-700 px-2 py-0.5 rounded text-xs"
                                                                    >Trả
                                                                    hàng</span
                                                                >
                                                            </td>
                                                            <td
                                                                class="px-3 py-2"
                                                            >
                                                                {{
                                                                    formatDate(
                                                                        ret.created_at,
                                                                    )
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2 text-right font-medium text-red-500"
                                                            >
                                                                -{{
                                                                    formatCurrency(
                                                                        ret.total,
                                                                    )
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2"
                                                            >
                                                                {{ ret.status }}
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>

                                        <!-- ===== TAB: Nợ cần thu từ khách ===== -->
                                        <div
                                            v-if="
                                                getActiveTab(customer.id) ===
                                                'debt'
                                            "
                                        >
                                            <div
                                                v-if="tabLoading[customer.id]"
                                                class="text-center py-8 text-gray-400"
                                            >
                                                Đang tải...
                                            </div>
                                            <div
                                                v-else-if="
                                                    debtHistoryData[customer.id]
                                                "
                                            >
                                                <!-- Filter dropdown -->
                                                <div
                                                    class="flex items-center justify-end mb-3"
                                                >
                                                    <select
                                                        class="border border-gray-300 rounded px-3 py-1.5 text-[13px] text-gray-600 focus:outline-none focus:border-blue-400"
                                                    >
                                                        <option>
                                                            Tất cả giao dịch
                                                        </option>
                                                        <option>
                                                            Bán hàng
                                                        </option>
                                                        <option>
                                                            Thanh toán
                                                        </option>
                                                    </select>
                                                </div>

                                                <div
                                                    v-if="
                                                        debtHistoryData[
                                                            customer.id
                                                        ].entries.length === 0
                                                    "
                                                    class="text-center py-12 text-gray-400"
                                                >
                                                    <svg
                                                        class="w-12 h-12 mx-auto mb-3 text-gray-300"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        viewBox="0 0 24 24"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                                        ></path>
                                                    </svg>
                                                    Không có giao dịch công nợ
                                                    nào
                                                </div>
                                                <table
                                                    v-else
                                                    class="w-full text-[13px]"
                                                >
                                                    <thead
                                                        class="bg-gray-50 text-gray-600 font-semibold"
                                                    >
                                                        <tr>
                                                            <th
                                                                class="px-3 py-2 text-left"
                                                            >
                                                                Mã phiếu
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-left"
                                                            >
                                                                Thời gian
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-left"
                                                            >
                                                                Loại
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-right"
                                                            >
                                                                Giá trị
                                                            </th>
                                                            <th
                                                                class="px-3 py-2 text-right"
                                                            >
                                                                Dư nợ khách hàng
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody
                                                        class="divide-y divide-gray-100"
                                                    >
                                                        <tr
                                                            v-for="entry in debtHistoryData[
                                                                customer.id
                                                            ].entries"
                                                            :key="entry.id"
                                                            class="hover:bg-blue-50/30"
                                                        >
                                                            <td
                                                                class="px-3 py-2 text-blue-600 font-medium"
                                                            >
                                                                {{ entry.code }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2"
                                                            >
                                                                {{
                                                                    formatDateTime(
                                                                        entry.created_at,
                                                                    )
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2"
                                                            >
                                                                {{ entry.type }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2 text-right font-medium"
                                                                :class="
                                                                    entry.amount <
                                                                    0
                                                                        ? 'text-red-500'
                                                                        : ''
                                                                "
                                                            >
                                                                {{
                                                                    formatCurrency(
                                                                        entry.amount,
                                                                    )
                                                                }}
                                                            </td>
                                                            <td
                                                                class="px-3 py-2 text-right font-medium"
                                                                :class="
                                                                    entry.balance <
                                                                    0
                                                                        ? 'text-red-500'
                                                                        : ''
                                                                "
                                                            >
                                                                {{
                                                                    formatCurrency(
                                                                        entry.balance,
                                                                    )
                                                                }}
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>

                                                <!-- Bottom actions -->
                                                <div
                                                    class="flex items-center justify-between mt-4 border-t border-gray-200 pt-3"
                                                >
                                                    <div
                                                        class="flex gap-3 text-[13px]"
                                                    >
                                                        <button
                                                            class="text-blue-600 hover:text-blue-800 flex items-center gap-1 font-medium"
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
                                                                    d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                                                ></path>
                                                            </svg>
                                                            Xuất file công nợ
                                                        </button>
                                                        <button
                                                            class="text-blue-600 hover:text-blue-800 flex items-center gap-1 font-medium"
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
                                                                    d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                                                ></path>
                                                            </svg>
                                                            Xuất file
                                                        </button>
                                                    </div>
                                                    <div
                                                        class="flex gap-2 text-[13px]"
                                                    >
                                                        <button
                                                            @click="
                                                                openDebtModal(
                                                                    customer,
                                                                    'payment',
                                                                )
                                                            "
                                                            class="bg-green-600 text-white rounded px-3 py-1.5 font-semibold hover:bg-green-700 flex items-center gap-1"
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
                                                                    d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                                                ></path>
                                                            </svg>
                                                            Thanh toán
                                                        </button>
                                                        <button
                                                            @click="
                                                                openDebtModal(
                                                                    customer,
                                                                    'adjust',
                                                                )
                                                            "
                                                            class="bg-white border border-gray-300 text-gray-700 rounded px-3 py-1.5 font-semibold hover:bg-gray-50 flex items-center gap-1"
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
                                                                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                                                                ></path>
                                                            </svg>
                                                            Điều chỉnh
                                                        </button>
                                                        <button
                                                            class="bg-white border border-gray-300 text-gray-700 rounded px-3 py-1.5 font-semibold hover:bg-gray-50 flex items-center gap-1"
                                                        >
                                                            Chiết khấu thanh
                                                            toán
                                                        </button>
                                                        <button
                                                            class="bg-white border border-gray-300 text-gray-700 rounded px-3 py-1.5 font-semibold hover:bg-gray-50 flex items-center gap-1"
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
                                                                    d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"
                                                                ></path>
                                                            </svg>
                                                            Tạo QR
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- ===== TAB: Lịch sử tích điểm ===== -->
                                        <div
                                            v-if="
                                                getActiveTab(customer.id) ===
                                                'points'
                                            "
                                        >
                                            <div
                                                class="text-center py-12 text-gray-400"
                                            >
                                                <svg
                                                    class="w-12 h-12 mx-auto mb-3 text-gray-300"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    viewBox="0 0 24 24"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"
                                                    ></path>
                                                </svg>
                                                Chưa thiết lập chương trình tích
                                                điểm
                                            </div>
                                        </div>

                                        <!-- Footer Actions -->
                                        <div
                                            class="flex items-center justify-between mt-4"
                                        >
                                            <button
                                                @click.stop="
                                                    deleteCustomer(customer)
                                                "
                                                class="text-gray-600 bg-white border border-gray-300 rounded px-3 py-1.5 text-[13.5px] font-semibold hover:bg-gray-50 flex items-center gap-1 shadow-sm"
                                            >
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
                                                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                                    ></path></svg
                                                >Xóa
                                            </button>
                                            <div
                                                class="flex gap-2 text-[13.5px]"
                                            >
                                                <button
                                                    @click.stop="openEditModal(customer)"
                                                    class="text-white bg-blue-600 rounded px-4 py-1.5 font-bold hover:bg-blue-700 flex items-center gap-1 shadow-sm"
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
                                                            d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                                                        ></path></svg
                                                    >Chỉnh sửa
                                                </button>
                                                <button
                                                    @click.stop="
                                                        toggleStatus(customer)
                                                    "
                                                    class="text-gray-700 bg-white border border-gray-300 rounded px-4 py-1.5 font-bold hover:bg-gray-50 shadow-sm"
                                                >
                                                    {{
                                                        customer.status ===
                                                        "active"
                                                            ? "Ngừng hoạt động"
                                                            : "Kích hoạt lại"
                                                    }}
                                                </button>
                                            </div>
                                        </div>
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
                    <span class="font-bold">{{ customers.from || 0 }}</span> đến
                    <span class="font-bold">{{ customers.to || 0 }}</span> trong
                    tổng số
                    <span class="font-bold">{{ customers.total || 0 }}</span>
                    đối tác
                </div>
                <!-- Pagination -->
                <div
                    class="flex gap-1"
                    v-if="customers.links && customers.links.length > 3"
                >
                    <template
                        v-for="(link, index) in customers.links"
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
                        Tạo khách hàng
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
                                        >Tên khách hàng
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
                                        >Mã khách hàng</label
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

                        <!-- Switch Supplier -->
                        <div
                            class="bg-gray-50 border border-gray-200 rounded px-4 py-4 flex items-center justify-between"
                        >
                            <div>
                                <h3 class="font-bold text-[14px] text-gray-800">
                                    Khách hàng là nhà cung cấp
                                </h3>
                                <p class="text-[12px] text-gray-500 mt-0.5">
                                    Công nợ của khách hàng và nhà cung cấp sẽ
                                    được gộp với nhau
                                </p>
                            </div>
                            <label
                                class="relative inline-flex items-center cursor-pointer"
                            >
                                <input
                                    type="checkbox"
                                    v-model="form.is_supplier"
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

        <!-- EDIT Customer Modal -->
        <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 pt-10 pb-10">
            <div class="bg-white rounded shadow-xl w-full max-w-3xl max-h-full overflow-hidden flex flex-col relative text-[13px] text-gray-800">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h2 class="text-xl font-bold text-gray-800">Chỉnh sửa khách hàng</h2>
                    <button @click="showEditModal = false" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-6 custom-scrollbar text-[13.5px]">
                    <form @submit.prevent="submitEdit" class="space-y-5">
                        <div class="grid grid-cols-2 gap-x-6 gap-y-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Tên khách hàng <span class="text-red-500">*</span></label>
                                <input v-model="editForm.name" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Mã khách hàng</label>
                                <input v-model="editForm.code" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm bg-gray-50" disabled />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Điện thoại</label>
                                <input v-model="editForm.phone" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Điện thoại 2</label>
                                <input v-model="editForm.phone2" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Email</label>
                                <input v-model="editForm.email" type="email" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Facebook</label>
                                <input v-model="editForm.facebook" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Sinh nhật</label>
                                <input v-model="editForm.birthday" type="date" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Giới tính</label>
                                <select v-model="editForm.gender" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                                    <option value="none">Chưa có</option>
                                    <option value="male">Nam</option>
                                    <option value="female">Nữ</option>
                                </select>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Địa chỉ</label>
                                <input v-model="editForm.address" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Tỉnh/Thành phố</label>
                                <input v-model="editForm.city" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Quận/Huyện</label>
                                <input v-model="editForm.district" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Phường/Xã</label>
                                <input v-model="editForm.ward" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Nhóm khách hàng</label>
                                <input v-model="editForm.customer_group" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Ghi chú</label>
                            <textarea v-model="editForm.note" rows="2" class="w-full border border-gray-300 rounded px-3 py-2 text-sm"></textarea>
                        </div>
                        <details class="border border-gray-200 rounded p-3">
                            <summary class="font-semibold text-sm text-gray-700 cursor-pointer">Thông tin xuất hóa đơn</summary>
                            <div class="grid grid-cols-2 gap-x-6 gap-y-4 mt-3">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1">Loại</label>
                                    <select v-model="editForm.type" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                                        <option value="individual">Cá nhân</option>
                                        <option value="company">Công ty</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1">Mã số thuế</label>
                                    <input v-model="editForm.tax_code" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1">Tên xuất hóa đơn</label>
                                    <input v-model="editForm.invoice_name" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1">Địa chỉ hóa đơn</label>
                                    <input v-model="editForm.invoice_address" type="text" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" />
                                </div>
                            </div>
                        </details>
                    </form>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 bg-white flex justify-end gap-3 rounded-b">
                    <button @click="showEditModal = false" class="px-6 py-2 border border-gray-300 rounded text-gray-700 bg-white font-bold hover:bg-gray-50 transition shadow-sm">Bỏ qua</button>
                    <button @click="submitEdit" :disabled="editForm.processing" class="px-8 py-2 border border-transparent rounded text-white bg-blue-600 font-bold hover:bg-blue-700 transition shadow-sm" :class="{ 'opacity-50 cursor-not-allowed': editForm.processing }">Lưu</button>
                </div>
            </div>
        </div>

        <!-- Debt Payment/Adjustment Modal -->
        <div
            v-if="debtModal.show"
            class="fixed inset-0 z-[60] flex items-center justify-center"
        >
            <div
                class="fixed inset-0 bg-black/40"
                @click="debtModal.show = false"
            ></div>
            <div class="bg-white rounded-lg shadow-xl w-[440px] relative z-10">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800">
                        {{
                            debtModal.type === "payment"
                                ? "Thanh toán công nợ"
                                : "Điều chỉnh công nợ"
                        }}
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">
                        {{ debtModal.customerName }}
                    </p>
                </div>
                <div class="px-6 py-4 space-y-4">
                    <div>
                        <label
                            class="block text-sm font-medium text-gray-700 mb-1"
                            >Dư nợ hiện tại</label
                        >
                        <div class="text-lg font-bold text-red-600">
                            {{ formatCurrency(debtModal.currentDebt) }}
                        </div>
                    </div>
                    <div>
                        <label
                            class="block text-sm font-medium text-gray-700 mb-1"
                        >
                            {{
                                debtModal.type === "payment"
                                    ? "Số tiền thanh toán"
                                    : "Số tiền điều chỉnh"
                            }}
                        </label>
                        <input
                            v-model.number="debtForm.amount"
                            type="number"
                            min="0"
                            class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
                            :placeholder="
                                debtModal.type === 'payment'
                                    ? 'Nhập số tiền thu'
                                    : 'Nhập số tiền điều chỉnh (giảm nợ)'
                            "
                        />
                    </div>
                    <div>
                        <label
                            class="block text-sm font-medium text-gray-700 mb-1"
                            >Ghi chú</label
                        >
                        <textarea
                            v-model="debtForm.note"
                            rows="2"
                            class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Ghi chú (không bắt buộc)"
                        ></textarea>
                    </div>
                </div>
                <div
                    class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3"
                >
                    <button
                        @click="debtModal.show = false"
                        class="px-5 py-2 border border-gray-300 rounded text-gray-700 bg-white font-medium hover:bg-gray-50"
                    >
                        Bỏ qua
                    </button>
                    <button
                        @click="submitDebtModal"
                        class="px-5 py-2 rounded text-white font-medium"
                        :class="
                            debtModal.type === 'payment'
                                ? 'bg-green-600 hover:bg-green-700'
                                : 'bg-blue-600 hover:bg-blue-700'
                        "
                    >
                        {{
                            debtModal.type === "payment"
                                ? "Thanh toán"
                                : "Điều chỉnh"
                        }}
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
