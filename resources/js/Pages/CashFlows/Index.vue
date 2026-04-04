<script setup>
import { ref, watch } from "vue";
import { Head, router, Link, useForm } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import SortableHeader from "@/Components/SortableHeader.vue";

const props = defineProps({
    cashFlows: Object,
    filters: Object,
    metrics: Object,
    subjects: Object,
    bankAccounts: Array,
    savedReceiptCategories: Array,
    savedPaymentCategories: Array,
});

const mergeUnique = (defaults, saved) => {
    const set = new Set(defaults);
    (saved || []).forEach(c => set.add(c));
    return [...set];
};

const search = ref(props.filters?.search || "");
const sortBy = ref(props.filters?.sort_by || "");
const sortDirection = ref(props.filters?.sort_direction || "");

let searchTimeout;
watch(search, (value) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            "/cash-flows",
            { search: value, sort_by: sortBy.value || undefined, sort_direction: sortDirection.value || undefined },
            {
                preserveState: true,
                replace: true,
            },
        );
    }, 500);
});

const handleSort = (field, direction) => {
    sortBy.value = field;
    sortDirection.value = direction;
    router.get(
        "/cash-flows",
        { search: search.value || undefined, sort_by: field || undefined, sort_direction: direction || undefined },
        { preserveState: true, replace: true },
    );
};

const isModalOpen = ref(false);
const modalType = ref("receipt"); // receipt or payment

const form = useForm({
    id: null,
    type: "receipt",
    time: new Date().toISOString().slice(0, 16),
    category: "",
    target_type: "Khác",
    target_name: "",
    amount: "",
    description: "",
    accounting_result: true,
    payment_method: "cash",
    bank_account_id: null,
});

const selectedFlowObj = ref(null);
const getSelectedFlow = () => selectedFlowObj.value;

const receiptCategories = ref(mergeUnique(
    ["Thu tiền khách trả", "Thu nhập khác", "Chuyển/Rút", "Bán đồng nát"],
    props.savedReceiptCategories
));
const paymentCategories = ref(mergeUnique(
    ["Chi trả lương NV", "Chi tiền trả NCC", "Chi phí điện nước", "Chi khác"],
    props.savedPaymentCategories
));
const targetTypes = [
    "Khách hàng",
    "Nhà cung cấp",
    "Nhân viên",
    "Đối tác giao hàng",
    "Khác",
];

const getTargetLabel = () => {
    switch (form.target_type) {
        case "Khách hàng":
            return "khách hàng";
        case "Nhà cung cấp":
            return "nhà cung cấp";
        case "Nhân viên":
            return "nhân viên";
        case "Đối tác giao hàng":
            return "đối tác";
        default:
            return modalType.value === "receipt" ? "người nộp" : "người nhận";
    }
};

const expandedRow = ref(null);

const isCategoryModalOpen = ref(false);
const newCategoryName = ref("");

const openCategoryModal = () => {
    newCategoryName.value = "";
    isCategoryModalOpen.value = true;
};

const submitCategory = () => {
    if (!newCategoryName.value) return;
    if (modalType.value === "receipt") {
        receiptCategories.value.push(newCategoryName.value);
    } else {
        paymentCategories.value.push(newCategoryName.value);
    }
    form.category = newCategoryName.value;
    isCategoryModalOpen.value = false;
};

watch(
    () => form.category,
    (newVal) => {
        if (newVal === "new") {
            form.category = "";
            openCategoryModal();
        }
    },
);

const isSubjectModalOpen = ref(false);
const subjectForm = useForm({
    name: "",
    phone: "",
    is_supplier: false,
});

const openSubjectModal = () => {
    subjectForm.name = form.target_name || "";
    subjectForm.phone = "";
    subjectForm.is_supplier = form.target_type === "Nhà cung cấp";
    isSubjectModalOpen.value = true;
};

const submitSubject = () => {
    subjectForm.post("/cash-flows/subject", {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            form.target_name = subjectForm.name;
            isSubjectModalOpen.value = false;
        },
    });
};

const openModal = (type, flow = null) => {
    modalType.value = type;
    form.clearErrors();

    if (flow) {
        selectedFlowObj.value = flow;
        form.id = flow.id;
        form.type = flow.type;
        form.time = flow.time
            ? new Date(flow.time).toISOString().slice(0, 16)
            : new Date(flow.created_at).toISOString().slice(0, 16);
        form.category = flow.category || "";
        form.target_type = flow.target_type || "Khác";
        form.target_name = flow.target_name || "";
        form.amount = flow.amount || "";
        form.description = flow.description || "";
        form.accounting_result =
            flow.accounting_result === 1 || flow.accounting_result === true;
        form.payment_method = flow.payment_method || "cash";
        form.bank_account_id = flow.bank_account_id || null;
    } else {
        form.id = null;
        form.type = type;
        form.time = new Date().toISOString().slice(0, 16);
        form.category = "";
        form.target_type = "Khác";
        form.target_name = "";
        form.amount = "";
        form.description = "";
        form.accounting_result = true;
        form.payment_method = "cash";
        form.bank_account_id = null;
    }

    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
};

const submitForm = () => {
    if (form.id) {
        form.put(`/cash-flows/${form.id}`, {
            onSuccess: () => {
                closeModal();
                expandedRow.value = null;
            },
        });
    } else {
        form.post("/cash-flows", {
            onSuccess: () => {
                closeModal();
            },
        });
    }
};

const submitFormAndPrint = () => {
    if (form.id) {
        const printId = form.id;
        form.put(`/cash-flows/${form.id}`, {
            onSuccess: () => {
                closeModal();
                expandedRow.value = null;
                window.open(
                    `/cash-flows/${printId}/print`,
                    "_blank",
                    "width=400,height=600",
                );
            },
        });
    } else {
        form.transform((data) => ({ ...data, _print: true }));
        form.post("/cash-flows", {
            onSuccess: (page) => {
                closeModal();
                const printId = page.props.flash?.print_id;
                if (printId) {
                    window.open(
                        `/cash-flows/${printId}/print`,
                        "_blank",
                        "width=400,height=600",
                    );
                }
            },
        });
    }
};

const deleteFlow = (id) => {
    if (
        confirm(
            "Bạn có chắc chắn muốn huỷ phiếu này? Hành động này không thể hoàn tác.",
        )
    ) {
        router.delete(`/cash-flows/${id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                expandedRow.value = null;
            },
        });
    }
};

const printFlow = (flow) => {
    window.open(
        `/cash-flows/${flow.id}/print`,
        "_blank",
        "width=400,height=600",
    );
};
</script>

<template>
    <Head title="Sổ Kế Toán (Quỹ)" />
    <AppLayout>
        <!-- Sidebar slot -->
        <template #sidebar>
            <div
                class="px-4 py-3 font-semibold text-gray-800 border-b border-gray-200 uppercase text-xs tracking-wider"
            >
                Từ khóa tìm kiếm
            </div>
            <div class="p-4 space-y-4">
                <div>
                    <label class="block text-sm text-gray-600 mb-1 font-medium"
                        >Tìm mã phiếu, nội dung</label
                    >
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Tìm theo mã, nội dung..."
                        class="w-full text-sm py-1.5 px-2 border border-gray-300 rounded focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    />
                </div>
            </div>

            <!-- Dashboard Mini Sidebar -->
            <div
                class="px-4 py-3 font-semibold text-gray-800 border-b border-t border-gray-200 uppercase text-xs tracking-wider mt-2 bg-gray-50/50"
            >
                Tổng Quỹ
            </div>
            <div class="p-4 space-y-3">
                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-500">Tổng thu:</span>
                    <span class="font-semibold text-blue-600 font-mono">{{
                        Number(metrics.totalReceipts).toLocaleString()
                    }}</span>
                </div>
                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-500">Tổng chi:</span>
                    <span class="font-semibold text-red-600 font-mono">{{
                        Number(metrics.totalPayments).toLocaleString()
                    }}</span>
                </div>
                <div
                    class="border-t border-gray-200 pt-2 flex justify-between items-center"
                >
                    <span class="font-bold text-gray-700">Tồn quỹ:</span>
                    <span
                        class="font-bold text-lg text-gray-900 tracking-tight font-mono"
                        >{{
                            Number(metrics.fundBalance).toLocaleString()
                        }}</span
                    >
                </div>
            </div>
        </template>

        <!-- Main content -->
        <div
            class="bg-white rounded shadow-sm flex flex-col h-full border border-gray-200"
        >
            <!-- Header -->
            <div
                class="flex items-center justify-between p-3 border-b border-gray-200 bg-gray-50/50"
            >
                <h1 class="font-bold text-gray-800 text-lg">
                    Sổ quỹ tiền mặt và CĐNH
                </h1>
                <div class="flex gap-2">
                    <button
                        @click="openModal('receipt')"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded transition shadow-sm text-sm font-semibold flex items-center gap-1"
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
                                d="M12 6v6m0 0v6m0-6h6m-6 0H6"
                            ></path>
                        </svg>
                        Lập phiếu thu
                    </button>
                    <button
                        @click="openModal('payment')"
                        class="bg-red-600 hover:bg-red-700 text-white px-4 py-1.5 rounded transition shadow-sm text-sm font-semibold flex items-center gap-1"
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
                                d="M20 12H4"
                            ></path>
                        </svg>
                        Lập phiếu chi
                    </button>
                    <ExcelButtons
                        export-url="/cash-flows/export"
                        import-url="/cash-flows/import"
                    />
                </div>
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-auto">
                <table class="w-full text-sm text-left">
                    <thead
                        class="text-xs text-gray-500 uppercase bg-gray-50 border-b border-gray-200 sticky top-0 z-10 shadow-sm"
                    >
                        <tr>
                            <SortableHeader label="Mã Phiếu" field="code" :current-sort="sortBy" :current-direction="sortDirection" class="px-4 py-3 font-semibold" @sort="handleSort" />
                            <SortableHeader label="Thời gian" field="time" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" class="px-4 py-3 font-semibold" @sort="handleSort" />
                            <SortableHeader label="Loại thu chi" field="type" :current-sort="sortBy" :current-direction="sortDirection" class="px-4 py-3 font-semibold" @sort="handleSort" />
                            <th class="px-4 py-3 font-semibold">
                                Người nộp/nhận
                            </th>
                            <SortableHeader label="Giá trị" field="amount" default-direction="desc" :current-sort="sortBy" :current-direction="sortDirection" align="right" class="px-4 py-3 font-semibold text-right" @sort="handleSort" />
                            <th class="px-4 py-3 font-semibold">Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="cashFlows.data.length === 0">
                            <td
                                colspan="6"
                                class="px-6 py-12 text-center text-gray-500"
                            >
                                <svg
                                    class="w-12 h-12 mx-auto text-gray-300 mb-3"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="1.5"
                                        d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"
                                    ></path>
                                </svg>
                                Sổ quỹ chưa có dữ liệu giao dịch.
                            </td>
                        </tr>
                        <template v-for="flow in cashFlows.data" :key="flow.id">
                            <tr
                                class="hover:bg-blue-50/30 transition-colors cursor-pointer"
                                :class="{
                                    'bg-blue-50/20': expandedRow === flow.id,
                                }"
                                @click="
                                    expandedRow =
                                        expandedRow === flow.id ? null : flow.id
                                "
                            >
                                <td
                                    class="px-4 py-3 font-medium text-blue-600 hover:underline"
                                >
                                    {{ flow.code }}
                                </td>
                                <td class="px-4 py-3 text-gray-600">
                                    {{
                                        new Date(
                                            flow.time || flow.created_at,
                                        ).toLocaleString("vi-VN", {
                                            day: "2-digit",
                                            month: "2-digit",
                                            year: "numeric",
                                            hour: "2-digit",
                                            minute: "2-digit",
                                        })
                                    }}
                                </td>
                                <td class="px-4 py-3 text-gray-700">
                                    {{
                                        flow.category ||
                                        (flow.type === "receipt"
                                            ? "Thu nhập khác"
                                            : "Chi khác")
                                    }}
                                </td>
                                <td class="px-4 py-3 text-gray-700 font-medium">
                                    {{
                                        flow.target_name ||
                                        (flow.type === "receipt"
                                            ? "Khách lẻ"
                                            : "Nhà cung cấp/Khác")
                                    }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-bold text-gray-800"
                                    :class="{
                                        'text-green-600':
                                            flow.type === 'receipt',
                                        'text-red-600': flow.type === 'payment',
                                    }"
                                >
                                    <span v-if="flow.type === 'receipt'"></span>
                                    <span v-else>-</span
                                    >{{ Number(flow.amount).toLocaleString() }}
                                </td>
                                <td class="px-4 py-3 text-gray-600 italic">
                                    {{ flow.description || "Không có" }}
                                </td>
                            </tr>
                            <tr v-if="expandedRow === flow.id">
                                <td
                                    colspan="6"
                                    class="p-0 border-b border-gray-200"
                                >
                                    <div
                                        class="p-6 border-t border-blue-200 bg-white shadow-[inset_0_4px_6px_-4px_rgba(0,0,0,0.1)] border-l-4 border-l-blue-500"
                                    >
                                        <div
                                            class="flex gap-6 border-b border-gray-200 mb-5"
                                        >
                                            <button
                                                class="pb-2 font-semibold text-blue-600 border-b-2 border-blue-600 text-[14px]"
                                            >
                                                Thông tin
                                            </button>
                                        </div>

                                        <div class="mb-6">
                                            <div
                                                class="flex items-center gap-3 mb-2"
                                            >
                                                <h3
                                                    class="font-bold text-gray-800 text-lg"
                                                >
                                                    Phiếu
                                                    {{
                                                        flow.type === "receipt"
                                                            ? "thu"
                                                            : "chi"
                                                    }}
                                                    {{ flow.code }}
                                                </h3>
                                                <span
                                                    class="px-2 py-0.5 rounded text-xs font-semibold bg-green-100 text-green-700"
                                                    >Đã thanh toán</span
                                                >
                                                <span
                                                    v-if="
                                                        !flow.accounting_result
                                                    "
                                                    class="px-2 py-0.5 rounded text-xs font-semibold bg-red-100 text-red-700"
                                                    >Không hạch toán</span
                                                >
                                            </div>
                                            <div
                                                class="text-[13px] text-gray-500 flex gap-4"
                                            >
                                                <span
                                                    >Hệ thống tạo
                                                    <span
                                                        class="text-gray-800 font-medium"
                                                        >Trần Văn Tiến</span
                                                    ></span
                                                >
                                                <span
                                                    class="border-l border-gray-300 pl-4"
                                                    >Người
                                                    {{
                                                        flow.type === "receipt"
                                                            ? "thu"
                                                            : "chi"
                                                    }}:
                                                    <span
                                                        class="text-gray-800 font-medium"
                                                        >Trần Văn Tiến</span
                                                    ></span
                                                >
                                                <span
                                                    class="border-l border-gray-300 pl-4"
                                                    >Thời gian:
                                                    <span
                                                        class="text-gray-800"
                                                        >{{
                                                            new Date(
                                                                flow.time ||
                                                                    flow.created_at,
                                                            ).toLocaleString(
                                                                "vi-VN",
                                                                {
                                                                    day: "2-digit",
                                                                    month: "2-digit",
                                                                    year: "numeric",
                                                                    hour: "2-digit",
                                                                    minute: "2-digit",
                                                                },
                                                            )
                                                        }}</span
                                                    ></span
                                                >
                                            </div>
                                        </div>

                                        <div
                                            class="grid grid-cols-4 gap-6 mb-6 pb-6 border-b border-gray-200"
                                        >
                                            <div>
                                                <div
                                                    class="text-[13px] text-gray-500 mb-1"
                                                >
                                                    Số tiền
                                                </div>
                                                <div
                                                    class="font-semibold text-gray-800"
                                                >
                                                    {{
                                                        Number(
                                                            flow.amount,
                                                        ).toLocaleString()
                                                    }}
                                                    ₫
                                                </div>
                                            </div>
                                            <div>
                                                <div
                                                    class="text-[13px] text-gray-500 mb-1"
                                                >
                                                    Loại
                                                    {{
                                                        flow.type === "receipt"
                                                            ? "thu"
                                                            : "chi"
                                                    }}
                                                </div>
                                                <div
                                                    class="font-medium text-gray-800"
                                                >
                                                    {{
                                                        flow.category ||
                                                        (flow.type === "receipt"
                                                            ? "Thu nhập khác"
                                                            : "Chi khác")
                                                    }}
                                                </div>
                                            </div>
                                            <div>
                                                <div
                                                    class="text-[13px] text-gray-500 mb-1"
                                                >
                                                    Đối tượng
                                                    {{
                                                        flow.type === "receipt"
                                                            ? "nộp"
                                                            : "nhận"
                                                    }}
                                                </div>
                                                <div
                                                    class="font-medium text-gray-800"
                                                >
                                                    {{
                                                        flow.target_type ||
                                                        "Khác"
                                                    }}
                                                </div>
                                            </div>
                                            <div>
                                                <div
                                                    class="text-[13px] text-gray-500 mb-1"
                                                >
                                                    Phương thức thanh toán
                                                </div>
                                                <div
                                                    class="font-medium text-gray-800"
                                                >
                                                    {{
                                                        flow.payment_method ===
                                                        "bank"
                                                            ? "Chuyển khoản"
                                                            : flow.payment_method ===
                                                                "ewallet"
                                                              ? "Ví điện tử"
                                                              : "Tiền mặt"
                                                    }}
                                                </div>
                                            </div>
                                        </div>

                                        <div
                                            class="space-y-4 mb-6 pb-6 border-b border-gray-200"
                                        >
                                            <div>
                                                <div
                                                    class="text-[13px] text-gray-500 mb-1"
                                                >
                                                    Người
                                                    {{
                                                        flow.type === "receipt"
                                                            ? "nộp"
                                                            : "nhận"
                                                    }}
                                                </div>
                                                <div
                                                    class="font-medium text-gray-800"
                                                >
                                                    {{
                                                        flow.target_name ||
                                                        "Khách lẻ"
                                                    }}
                                                </div>
                                            </div>
                                            <div>
                                                <div
                                                    class="text-gray-700 text-[13px] italic border-l-2 border-gray-300 pl-3"
                                                >
                                                    {{
                                                        flow.description ||
                                                        "Chưa có ghi chú"
                                                    }}
                                                </div>
                                            </div>
                                        </div>

                                        <div
                                            class="flex justify-between items-center"
                                        >
                                            <button
                                                @click="deleteFlow(flow.id)"
                                                class="px-5 py-1.5 border border-red-500 rounded text-red-500 hover:bg-red-50 font-medium text-[14px] transition-colors"
                                            >
                                                Hủy phiếu
                                            </button>
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <button
                                                    @click="
                                                        openModal(
                                                            flow.type,
                                                            flow,
                                                        )
                                                    "
                                                    class="px-5 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded text-[14px] font-medium flex items-center gap-2 transition-colors"
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
                                                        ></path>
                                                    </svg>
                                                    Chỉnh sửa
                                                </button>
                                                <button
                                                    @click="printFlow(flow)"
                                                    class="px-4 py-1.5 border border-gray-300 rounded text-gray-700 font-medium hover:bg-gray-50 text-[14px] flex items-center gap-2 transition-colors"
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
                                                            d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"
                                                        ></path>
                                                    </svg>
                                                    In
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
                class="flex items-center justify-between p-3 border-t border-gray-200 bg-gray-50/50 text-sm flex-shrink-0"
            >
                <div class="text-gray-600">
                    Hiển thị từ
                    <span class="font-bold">{{ cashFlows.from || 0 }}</span> đến
                    <span class="font-bold">{{ cashFlows.to || 0 }}</span> trong
                    tổng số
                    <span class="font-bold">{{ cashFlows.total || 0 }}</span>
                    phiếu
                </div>
                <!-- Pagination -->
                <div
                    class="flex gap-1"
                    v-if="cashFlows.links && cashFlows.links.length > 3"
                >
                    <template
                        v-for="(link, index) in cashFlows.links"
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

        <!-- Create CashFlow Modal -->
        <div
            v-if="isModalOpen && !form.id"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-gray-900/60 transition-opacity"
        >
            <div
                class="bg-white rounded-md shadow-2xl w-full max-w-[800px] flex flex-col max-h-[90vh] mx-4"
                @click.stop
            >
                <div
                    class="flex items-center justify-between px-6 py-4 border-b border-gray-200"
                >
                    <h3 class="text-[17px] font-bold text-gray-800">
                        {{
                            modalType === "receipt"
                                ? "Tạo phiếu thu tiền mặt"
                                : "Tạo phiếu chi tiền mặt"
                        }}
                    </h3>
                    <button
                        @click="closeModal"
                        class="text-gray-400 hover:text-gray-600 transition focus:outline-none"
                    >
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
                                d="M6 18L18 6M6 6l12 12"
                            ></path>
                        </svg>
                    </button>
                </div>

                <form
                    @submit.prevent="submitForm"
                    class="p-6 space-y-4 overflow-y-auto"
                >
                    <div class="grid grid-cols-2 gap-x-6 gap-y-5">
                        <div class="space-y-1">
                            <label
                                class="block text-[13px] font-semibold text-gray-700"
                                >Mã phiếu</label
                            >
                            <input
                                disabled
                                type="text"
                                class="w-full bg-white border-b border-gray-300 py-1.5 focus:outline-none text-[13px] text-gray-400 placeholder-gray-400"
                                placeholder="Tự động"
                            />
                        </div>
                        <div class="space-y-1 relative">
                            <label
                                class="block text-[13px] font-semibold text-gray-700"
                                >Thời gian</label
                            >
                            <input
                                type="datetime-local"
                                v-model="form.time"
                                class="w-full border-b border-gray-300 py-1.5 focus:outline-none focus:border-blue-500 text-[13px] text-gray-800"
                            />
                        </div>

                        <div class="space-y-1 relative group">
                            <div class="flex justify-between items-end">
                                <label
                                    class="block text-[13px] font-semibold text-gray-700"
                                    >Loại
                                    {{
                                        modalType === "receipt" ? "thu" : "chi"
                                    }}</label
                                >
                            </div>
                            <div class="relative">
                                <select
                                    v-model="form.category"
                                    class="w-full border-b border-gray-300 py-1.5 focus:outline-none focus:border-blue-500 bg-white text-[13px] appearance-none cursor-pointer pr-6 text-gray-800"
                                >
                                    <option value="" disabled>
                                        Chọn loại
                                        {{
                                            modalType === "receipt"
                                                ? "thu"
                                                : "chi"
                                        }}
                                    </option>
                                    <option
                                        v-for="cat in modalType === 'receipt'
                                            ? receiptCategories
                                            : paymentCategories"
                                        :key="cat"
                                        :value="cat"
                                    >
                                        {{ cat }}
                                    </option>
                                    <option
                                        value="new"
                                        class="text-blue-600 font-semibold"
                                    >
                                        + Tạo mới
                                    </option>
                                </select>
                                <div
                                    class="absolute inset-y-0 right-0 flex items-center pointer-events-none"
                                >
                                    <svg
                                        class="w-4 h-4 text-gray-500 group-hover:text-blue-500 transition-colors"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M19 9l-7 7-7-7"
                                        ></path>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-1 relative group">
                            <label
                                class="block text-[13px] font-semibold text-gray-700"
                                >Người
                                {{
                                    modalType === "receipt" ? "thu" : "chi"
                                }}</label
                            >
                            <div class="relative">
                                <select
                                    class="w-full border-b border-gray-300 py-1.5 focus:outline-none focus:border-blue-500 bg-white text-[13px] appearance-none cursor-pointer pr-6 text-gray-800"
                                >
                                    <option>Trần Văn Tiến</option>
                                </select>
                                <div
                                    class="absolute inset-y-0 right-0 flex items-center pointer-events-none"
                                >
                                    <svg
                                        class="w-4 h-4 text-gray-500 group-hover:text-blue-500 transition-colors"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M19 9l-7 7-7-7"
                                        ></path>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-1 relative group">
                            <label
                                class="block text-[13px] font-semibold text-gray-700"
                                >Đối tượng
                                {{
                                    modalType === "receipt" ? "nộp" : "nhận"
                                }}</label
                            >
                            <div class="relative">
                                <select
                                    v-model="form.target_type"
                                    class="w-full border-b border-gray-300 py-1.5 focus:outline-none focus:border-blue-500 bg-white text-[13px] appearance-none cursor-pointer pr-6 text-gray-800"
                                >
                                    <option
                                        v-for="t in targetTypes"
                                        :key="t"
                                        :value="t"
                                    >
                                        {{ t }}
                                    </option>
                                </select>
                                <div
                                    class="absolute inset-y-0 right-0 flex items-center pointer-events-none"
                                >
                                    <svg
                                        class="w-4 h-4 text-gray-500 group-hover:text-blue-500 transition-colors"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M19 9l-7 7-7-7"
                                        ></path>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-1 relative">
                            <div class="flex justify-between items-end">
                                <label
                                    class="block text-[13px] font-semibold text-gray-700 capitalize"
                                    >Tên {{ getTargetLabel() }}</label
                                >
                                <button
                                    type="button"
                                    @click="openSubjectModal"
                                    class="text-[13px] text-blue-600 hover:text-blue-800 font-medium whitespace-nowrap bg-blue-50 px-2 py-0.5 rounded cursor-pointer"
                                >
                                    Tạo mới
                                </button>
                            </div>
                            <input
                                type="text"
                                v-model="form.target_name"
                                :list="
                                    form.target_type === 'Khách hàng'
                                        ? 'customers-list'
                                        : form.target_type === 'Nhà cung cấp'
                                          ? 'suppliers-list'
                                          : null
                                "
                                class="w-full border-b border-gray-300 py-1.5 focus:outline-none focus:border-blue-500 text-[13px] placeholder-gray-400"
                                :placeholder="'Tìm ' + getTargetLabel()"
                            />
                            <datalist id="customers-list">
                                <option
                                    v-for="c in subjects?.customers"
                                    :key="'c' + c.id"
                                    :value="c.name"
                                >
                                    {{ c.phone }}
                                </option>
                            </datalist>
                            <datalist id="suppliers-list">
                                <option
                                    v-for="s in subjects?.suppliers"
                                    :key="'s' + s.id"
                                    :value="s.name"
                                >
                                    {{ s.phone }}
                                </option>
                            </datalist>
                        </div>
                    </div>

                    <div class="mt-4 space-y-1">
                        <label
                            class="block text-[13px] font-semibold text-gray-700"
                            >Số tiền <span class="text-red-500">*</span></label
                        >
                        <div class="relative">
                            <input
                                type="number"
                                v-model="form.amount"
                                required
                                class="w-full border-b border-gray-300 py-1.5 focus:outline-none focus:border-blue-500 text-right pr-6 font-bold text-lg text-gray-800 placeholder-gray-400"
                                placeholder="0"
                            />
                        </div>
                    </div>
                    <div class="mt-4 space-y-1">
                        <label
                            class="block text-[13px] font-semibold text-gray-700"
                            >Ghi chú</label
                        >
                        <input
                            type="text"
                            v-model="form.description"
                            class="w-full border-b border-gray-300 py-1.5 focus:outline-none focus:border-blue-500 text-[13px] placeholder-gray-400"
                            placeholder="Nhập ghi chú"
                        />
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <input
                            type="checkbox"
                            id="accounting"
                            v-model="form.accounting_result"
                            class="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500 rounded-sm"
                        />
                        <label
                            for="accounting"
                            class="text-[13px] text-gray-800 flex items-center gap-1 cursor-pointer select-none"
                        >
                            Hạch toán kết quả kinh doanh
                            <svg
                                class="w-4 h-4 text-gray-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                ></path>
                            </svg>
                        </label>
                    </div>

                    <div class="pt-6 flex justify-end gap-3 rounded-b">
                        <button
                            type="button"
                            @click="closeModal"
                            class="px-6 py-2 border border-gray-300 text-gray-700 hover:bg-gray-50 rounded text-[14px] font-medium transition shadow-sm"
                        >
                            Bỏ qua
                        </button>
                        <button
                            type="button"
                            @click="submitFormAndPrint"
                            :disabled="form.processing"
                            class="px-6 py-2 border border-blue-600 text-blue-600 hover:bg-blue-50 rounded text-[14px] font-medium transition shadow-sm bg-white"
                        >
                            Lưu & In
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="px-8 py-2 text-white font-medium rounded text-[14px] transition focus:outline-none shadow-sm flex items-center gap-2"
                            :class="
                                modalType === 'receipt'
                                    ? 'bg-[#0070f4] hover:bg-blue-700'
                                    : 'bg-[#0070f4] hover:bg-blue-700'
                            "
                        >
                            <svg
                                v-if="form.processing"
                                class="animate-spin w-4 h-4 ml-1"
                                fill="none"
                                viewBox="0 0 24 24"
                            >
                                <circle
                                    class="opacity-25"
                                    cx="12"
                                    cy="12"
                                    r="10"
                                    stroke="currentColor"
                                    stroke-width="4"
                                ></circle>
                                <path
                                    class="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                ></path>
                            </svg>
                            Lưu
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <!-- Edit/View CashFlow Modal -->
        <div
            v-if="isModalOpen && form.id"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-gray-900/60 transition-opacity"
        >
            <div
                class="bg-white rounded-md shadow-2xl w-full max-w-[900px] flex flex-col max-h-[90vh] mx-4"
                @click.stop
            >
                <div
                    class="flex items-center justify-between px-6 py-4 border-b border-gray-200"
                >
                    <h3 class="text-[17px] font-bold text-gray-800">
                        {{
                            form.type === "receipt" ? "Phiếu thu" : "Phiếu chi"
                        }}
                    </h3>
                    <button
                        @click="closeModal"
                        class="text-gray-400 hover:text-gray-600 transition focus:outline-none"
                    >
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
                                d="M6 18L18 6M6 6l12 12"
                            ></path>
                        </svg>
                    </button>
                </div>

                <form
                    @submit.prevent="submitForm"
                    class="p-6 space-y-4 overflow-y-auto"
                >
                    <div class="flex flex-col md:flex-row gap-8">
                        <!-- Left cols -->
                        <div class="flex-1 grid grid-cols-2 gap-x-8 gap-y-5">
                            <div class="flex items-center">
                                <span
                                    class="w-32 text-gray-700 font-medium text-[13px]"
                                    >Mã phiếu:</span
                                >
                                <span
                                    class="text-gray-800 font-medium text-[13px]"
                                    >{{ getSelectedFlow()?.code }}</span
                                >
                            </div>
                            <div class="flex items-center">
                                <span
                                    class="w-32 text-gray-700 font-medium text-[13px]"
                                    >Nhân viên tạo:</span
                                >
                                <select
                                    disabled
                                    class="flex-1 border border-gray-300 rounded px-2.5 py-1.5 focus:outline-none text-[13px] bg-gray-50 text-gray-600"
                                >
                                    <option>Trần Văn Tiến</option>
                                </select>
                            </div>

                            <div class="flex items-center">
                                <span
                                    class="w-32 text-gray-700 font-medium text-[13px]"
                                    >Thời gian:</span
                                >
                                <input
                                    type="datetime-local"
                                    v-model="form.time"
                                    class="flex-1 border border-gray-300 rounded px-2.5 py-1.5 focus:outline-none focus:border-blue-500 text-[13px]"
                                />
                            </div>
                            <div class="flex items-center">
                                <span
                                    class="w-32 text-gray-700 font-medium text-[13px]"
                                    >Phương thức:</span
                                >
                                <select
                                    v-model="form.payment_method"
                                    class="flex-1 border border-gray-300 rounded px-2.5 py-1.5 focus:outline-none focus:border-blue-500 text-[13px]"
                                >
                                    <option value="cash">Tiền mặt</option>
                                    <option value="bank">Chuyển khoản</option>
                                    <option value="ewallet">Ví điện tử</option>
                                </select>
                            </div>
                            <div
                                v-if="form.payment_method !== 'cash'"
                                class="flex items-center"
                            >
                                <span
                                    class="w-32 text-gray-700 font-medium text-[13px]"
                                    >Tài khoản:</span
                                >
                                <select
                                    v-model="form.bank_account_id"
                                    class="flex-1 border border-gray-300 rounded px-2.5 py-1.5 focus:outline-none focus:border-blue-500 text-[13px]"
                                >
                                    <option :value="null">
                                        -- Chọn tài khoản --
                                    </option>
                                    <option
                                        v-for="ba in bankAccounts?.filter(
                                            (a) =>
                                                a.type === form.payment_method,
                                        )"
                                        :key="ba.id"
                                        :value="ba.id"
                                    >
                                        {{ ba.bank_name }} -
                                        {{ ba.account_number }} ({{
                                            ba.account_holder
                                        }})
                                    </option>
                                </select>
                            </div>

                            <div class="flex items-center">
                                <span
                                    class="w-32 text-gray-700 font-medium text-[13px]"
                                    >Tài khoản tạo:</span
                                >
                                <span class="text-gray-800 text-[13px]"
                                    >Trần Văn Tiến</span
                                >
                            </div>
                            <div class="col-span-1"></div>

                            <div class="flex items-center">
                                <span
                                    class="w-32 text-gray-700 font-medium text-[13px]"
                                    >Nhân viên:</span
                                >
                                <a
                                    href="#"
                                    class="text-blue-600 hover:text-blue-800 text-[13px] font-medium"
                                    >Trần Văn Tiến</a
                                >
                            </div>
                        </div>

                        <!-- Right col for Notes -->
                        <div
                            class="flex-1 md:max-w-xs border-l border-gray-200 pl-8"
                        >
                            <textarea
                                v-model="form.description"
                                rows="5"
                                class="w-full border-none focus:ring-0 text-[13px] resize-none placeholder-gray-400 italic"
                                placeholder="Ghi chú..."
                            ></textarea>
                        </div>
                    </div>

                    <!-- Table showing linked item if applicable -->
                    <div
                        class="mt-8 border border-gray-200 rounded overflow-hidden"
                    >
                        <table class="w-full text-left border-collapse">
                            <thead
                                class="bg-gray-100/50 border-b border-gray-200"
                            >
                                <tr>
                                    <th
                                        class="px-4 py-2.5 text-[13px] font-semibold text-gray-700"
                                    >
                                        Mã phiếu
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-[13px] font-semibold text-gray-700"
                                    >
                                        Thời gian
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-[13px] font-semibold text-gray-700 text-right"
                                    >
                                        Giá trị phiếu
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-[13px] font-semibold text-gray-700 text-right"
                                    >
                                        Đã
                                        {{
                                            form.type === "receipt"
                                                ? "thu"
                                                : "chi"
                                        }}
                                        trước
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-[13px] font-semibold text-gray-700 text-right"
                                    >
                                        Tiền
                                        {{
                                            form.type === "receipt"
                                                ? "thu"
                                                : "chi"
                                        }}
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-[13px] font-semibold text-gray-700 text-center"
                                    >
                                        Trạng thái
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr class="bg-white">
                                    <td
                                        class="px-4 py-3 text-[13px] text-gray-800"
                                    >
                                        {{
                                            getSelectedFlow()
                                                ?.code?.replace("PC", "PL")
                                                .replace("PT", "HD")
                                        }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-[13px] text-gray-800"
                                    >
                                        {{
                                            new Date(
                                                getSelectedFlow()?.created_at ||
                                                    new Date(),
                                            ).toLocaleString("vi-VN", {
                                                day: "2-digit",
                                                month: "2-digit",
                                                year: "numeric",
                                                hour: "2-digit",
                                                minute: "2-digit",
                                            })
                                        }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-[13px] text-gray-800 text-right"
                                    >
                                        {{
                                            Number(
                                                getSelectedFlow()?.amount || 0,
                                            ).toLocaleString()
                                        }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-[13px] text-gray-800 text-right"
                                    >
                                        0
                                    </td>
                                    <td
                                        class="px-4 py-3 text-[13px] font-medium text-gray-800 text-right"
                                    >
                                        {{
                                            Number(
                                                getSelectedFlow()?.amount || 0,
                                            ).toLocaleString()
                                        }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-[13px] text-gray-600 text-center"
                                    >
                                        Đã thanh toán
                                    </td>
                                </tr>
                                <tr class="bg-white">
                                    <td
                                        colspan="4"
                                        class="px-4 py-4 text-[13px] text-right text-gray-600"
                                    >
                                        Tổng tiền
                                        {{
                                            form.type === "receipt"
                                                ? "thu"
                                                : "chi"
                                        }}:
                                    </td>
                                    <td
                                        class="px-4 py-4 text-[14px] font-bold text-gray-900 text-right"
                                    >
                                        {{
                                            Number(
                                                getSelectedFlow()?.amount || 0,
                                            ).toLocaleString()
                                        }}
                                    </td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        class="pt-6 flex justify-between items-center rounded-b mt-4"
                    >
                        <button
                            type="button"
                            @click="closeModal"
                            class="px-6 py-2 border border-gray-300 text-gray-700 hover:bg-gray-50 rounded text-[14px] font-medium transition shadow-sm bg-white"
                        >
                            Hủy bỏ
                        </button>
                        <div class="flex gap-3">
                            <button
                                type="button"
                                @click="printFlow(getSelectedFlow())"
                                class="px-6 py-2 border border-gray-300 text-gray-700 hover:bg-gray-50 rounded text-[14px] font-medium transition shadow-sm bg-white"
                            >
                                In
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="px-8 py-2 bg-[#0070f4] hover:bg-blue-700 text-white font-medium rounded text-[14px] transition focus:outline-none shadow-sm flex items-center gap-2"
                            >
                                <svg
                                    v-if="form.processing"
                                    class="animate-spin w-4 h-4 ml-1"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle
                                        class="opacity-25"
                                        cx="12"
                                        cy="12"
                                        r="10"
                                        stroke="currentColor"
                                        stroke-width="4"
                                    ></circle>
                                    <path
                                        class="opacity-75"
                                        fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                    ></path>
                                </svg>
                                Cập nhật
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <!-- Category Modal (Nested) -->
        <div
            v-if="isCategoryModalOpen"
            class="fixed inset-0 z-[110] flex items-center justify-center bg-gray-900/40 backdrop-blur-sm transition-opacity"
        >
            <div
                class="bg-white rounded shadow-xl w-full max-w-sm mx-4 overflow-hidden"
                @click.stop
            >
                <div
                    class="flex items-center justify-between px-4 py-3 border-b border-gray-100 bg-gray-50"
                >
                    <h3 class="font-bold text-gray-800 text-[15px]">
                        Tạo loại {{ modalType === "receipt" ? "thu" : "chi" }}
                    </h3>
                    <button
                        @click="isCategoryModalOpen = false"
                        class="text-gray-400 hover:text-gray-600 transition"
                    >
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
                                d="M6 18L18 6M6 6l12 12"
                            ></path>
                        </svg>
                    </button>
                </div>
                <div class="p-4">
                    <label
                        class="block text-[13px] font-semibold text-gray-700 mb-1"
                        >Tên loại
                        {{ modalType === "receipt" ? "thu" : "chi" }} mới</label
                    >
                    <input
                        type="text"
                        v-model="newCategoryName"
                        @keyup.enter="submitCategory"
                        ref="categoryInput"
                        class="w-full border border-gray-300 rounded p-2 text-sm focus:outline-none focus:border-blue-500"
                        placeholder="Khai báo loại mới"
                        autofocus
                    />
                    <div class="flex justify-end gap-2 mt-4">
                        <button
                            type="button"
                            @click="isCategoryModalOpen = false"
                            class="px-4 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-[13px] font-medium rounded transition"
                        >
                            Bỏ qua
                        </button>
                        <button
                            type="button"
                            @click="submitCategory"
                            class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-[13px] font-medium rounded transition"
                        >
                            Lưu
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Subject/Customer Modal (Nested) -->
        <div
            v-if="isSubjectModalOpen"
            class="fixed inset-0 z-[110] flex items-center justify-center bg-gray-900/40 backdrop-blur-sm transition-opacity"
        >
            <div
                class="bg-white rounded shadow-xl w-full max-w-sm mx-4 overflow-hidden"
                @click.stop
            >
                <div
                    class="flex items-center justify-between px-4 py-3 border-b border-gray-100 bg-gray-50"
                >
                    <h3 class="font-bold text-gray-800 text-[15px]">
                        Tạo {{ getTargetLabel() }} mới
                    </h3>
                    <button
                        @click="isSubjectModalOpen = false"
                        class="text-gray-400 hover:text-gray-600 transition"
                    >
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
                                d="M6 18L18 6M6 6l12 12"
                            ></path>
                        </svg>
                    </button>
                </div>
                <form @submit.prevent="submitSubject" class="p-4 space-y-4">
                    <div>
                        <label
                            class="block text-[13px] font-semibold text-gray-700 mb-1"
                            >Tên <span class="text-red-500">*</span></label
                        >
                        <input
                            type="text"
                            required
                            v-model="subjectForm.name"
                            class="w-full border border-gray-300 rounded p-2 text-sm focus:outline-none focus:border-blue-500"
                            placeholder="Tên đối tượng"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-[13px] font-semibold text-gray-700 mb-1"
                            >Số điện thoại</label
                        >
                        <input
                            type="text"
                            v-model="subjectForm.phone"
                            class="w-full border border-gray-300 rounded p-2 text-sm focus:outline-none focus:border-blue-500"
                            placeholder="09xxxx"
                        />
                    </div>
                    <div class="flex justify-end gap-2 mt-2">
                        <button
                            type="button"
                            @click="isSubjectModalOpen = false"
                            class="px-4 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-[13px] font-medium rounded transition"
                        >
                            Bỏ qua
                        </button>
                        <button
                            type="submit"
                            :disabled="subjectForm.processing"
                            class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-[13px] font-medium rounded transition flex items-center"
                        >
                            Lưu thao tác
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
