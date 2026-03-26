<script setup>
import { ref, computed, watch } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";

const props = defineProps({
    filters: Object,
    rows: Array,
    totals: Object,
    branchName: String,
    branches: Array,
    employees: Array,
    paymentMethods: Array,
    salesChannels: Array,
});

const page = usePage();

// Filter state
const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const branchId = ref(props.filters.branch_id || "");
const employeeId = ref(props.filters.employee_id || "");
const createdBy = ref(props.filters.created_by || "");
const paymentMethod = ref(props.filters.payment_method || "");
const salesChannel = ref(props.filters.sales_channel || "");
const concern = ref("sales"); // Mối quan tâm

const now = new Date();

const applyFilter = () => {
    const params = {
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        branch_id: branchId.value || undefined,
        employee_id: employeeId.value || undefined,
        created_by: createdBy.value || undefined,
        payment_method: paymentMethod.value || undefined,
        sales_channel: salesChannel.value || undefined,
    };
    router.get("/reports/end-of-day", params, { preserveState: true });
};

const formatCurrency = (n) => {
    if (n === null || n === undefined || isNaN(n)) return "0";
    return new Intl.NumberFormat("vi-VN").format(Math.round(n));
};

const formatDate = (d) => {
    const date = new Date(d);
    return date.toLocaleDateString("vi-VN", { day: "2-digit", month: "2-digit", year: "numeric" });
};

const reportTitle = computed(() => {
    if (concern.value === "sales") return "Báo cáo cuối ngày về bán hàng";
    return "Báo cáo cuối ngày";
});

const printReport = () => {
    window.print();
};
</script>

<template>
    <AppLayout>
        <div class="flex h-full min-h-[calc(100vh-56px)]">
            <!-- Left Sidebar Filters (hidden on print) -->
            <aside class="w-[220px] bg-white border-r border-gray-200 p-4 flex-shrink-0 overflow-y-auto print:hidden">
                <h2 class="text-sm font-bold text-gray-800 mb-4">Báo cáo cuối ngày</h2>

                <!-- Kiểu hiển thị -->
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1 block">Kiểu hiển thị</label>
                    <button class="bg-blue-600 text-white text-xs px-3 py-1.5 rounded">Báo cáo</button>
                </div>

                <!-- Hiển thị -->
                <div class="mb-4">
                    <select class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option>Hiển thị dọc</option>
                        <option>Hiển thị ngang</option>
                    </select>
                </div>

                <!-- Mối quan tâm -->
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1 block">Mối quan tâm</label>
                    <select v-model="concern" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option value="sales">Bán hàng</option>
                    </select>
                </div>

                <!-- Chi nhánh -->
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1 block">Chi nhánh</label>
                    <select v-model="branchId" @change="applyFilter" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option value="">Tất cả chi nhánh</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </div>

                <!-- Thời gian -->
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1 block">Thời gian •</label>
                    <div class="space-y-2">
                        <div class="flex gap-1">
                            <input type="date" v-model="dateFrom" class="flex-1 text-xs border border-gray-300 rounded px-2 py-1.5" />
                            <input type="date" v-model="dateTo" class="flex-1 text-xs border border-gray-300 rounded px-2 py-1.5" />
                        </div>
                        <button @click="applyFilter" class="w-full text-xs bg-blue-50 text-blue-600 border border-blue-200 rounded py-1.5 hover:bg-blue-100">
                            Áp dụng
                        </button>
                    </div>
                </div>

                <!-- Khách hàng -->
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1 block">Khách hàng</label>
                    <input type="text" placeholder="Theo mã, tên, số điện thoại" class="w-full text-xs border border-gray-300 rounded px-2 py-1.5" />
                </div>

                <!-- Nhân viên -->
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1 block">Nhân viên</label>
                    <select v-model="employeeId" @change="applyFilter" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option value="">Chọn nhân viên</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                    </select>
                </div>

                <!-- Người tạo -->
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1 block">Người tạo</label>
                    <select v-model="createdBy" @change="applyFilter" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option value="">Chọn người tạo</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                    </select>
                </div>

                <!-- Phương thức thanh toán -->
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1 block">Phương thức thanh toán</label>
                    <select v-model="paymentMethod" @change="applyFilter" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option value="">Chọn phương thức</option>
                        <option v-for="pm in paymentMethods" :key="pm" :value="pm">{{ pm }}</option>
                    </select>
                </div>

                <!-- Phương thức bán hàng -->
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1 block">Phương thức bán hàng</label>
                    <select v-model="salesChannel" @change="applyFilter" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option value="">Chọn phương thức</option>
                        <option v-for="sc in salesChannels" :key="sc" :value="sc">{{ sc }}</option>
                    </select>
                </div>
            </aside>

            <!-- Main: Report Document -->
            <main class="flex-1 bg-gray-200 p-6 overflow-auto">
                <!-- Toolbar (hidden on print) -->
                <div class="bg-gray-600 rounded-t-lg px-4 py-2 flex items-center justify-between print:hidden">
                    <div class="flex items-center gap-2">
                        <span class="text-white text-sm">1 / 1</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="printReport" class="text-white hover:text-gray-200 transition-colors" title="In báo cáo">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                            </svg>
                        </button>
                        <button class="text-white hover:text-gray-200 transition-colors" title="Tải xuống">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </button>
                        <button class="text-white hover:text-gray-200 transition-colors" title="Phóng to">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Paper Document -->
                <div class="bg-white shadow-lg mx-auto max-w-[900px] p-8 print:shadow-none print:p-0 print:max-w-none" id="report-paper">
                    <!-- Report Header -->
                    <div class="text-xs text-gray-400 mb-2">
                        Ngày lập: {{ now.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' }) }}
                        {{ now.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }) }}
                    </div>

                    <div class="text-center mb-6">
                        <h2 class="text-xl font-bold text-gray-800 mb-1">{{ reportTitle }}</h2>
                        <p class="text-sm text-gray-600">
                            Ngày bán: Từ ngày {{ formatDate(dateFrom) }} đến ngày {{ formatDate(dateTo) }}
                        </p>
                        <p class="text-sm text-gray-600">
                            Ngày thanh toán: Từ ngày {{ formatDate(dateFrom) }} đến ngày {{ formatDate(dateTo) }}
                        </p>
                        <p class="text-sm text-gray-600">Chi nhánh: {{ branchName }}</p>
                    </div>

                    <!-- Report Table -->
                    <table class="w-full text-sm border-collapse">
                        <thead>
                            <tr class="bg-blue-600 text-white">
                                <th class="border border-blue-700 px-3 py-2 text-left font-medium">Thời gian</th>
                                <th class="border border-blue-700 px-3 py-2 text-right font-medium">SL sản phẩm</th>
                                <th class="border border-blue-700 px-3 py-2 text-right font-medium">Doanh thu</th>
                                <th class="border border-blue-700 px-3 py-2 text-right font-medium">Thu khác</th>
                                <th class="border border-blue-700 px-3 py-2 text-right font-medium">Thuế VAT</th>
                                <th class="border border-blue-700 px-3 py-2 text-right font-medium">Làm tròn</th>
                                <th class="border border-blue-700 px-3 py-2 text-right font-medium">Phí trả hàng</th>
                                <th class="border border-blue-700 px-3 py-2 text-right font-medium">Thực thu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, idx) in rows" :key="idx" class="hover:bg-gray-50">
                                <td class="border border-gray-200 px-3 py-2 text-gray-700">{{ row.date }}</td>
                                <td class="border border-gray-200 px-3 py-2 text-right text-gray-700">{{ formatCurrency(row.productQty) }}</td>
                                <td class="border border-gray-200 px-3 py-2 text-right text-gray-700">{{ formatCurrency(row.revenue) }}</td>
                                <td class="border border-gray-200 px-3 py-2 text-right text-gray-700">{{ formatCurrency(row.otherIncome) }}</td>
                                <td class="border border-gray-200 px-3 py-2 text-right text-gray-700">{{ formatCurrency(row.vat) }}</td>
                                <td class="border border-gray-200 px-3 py-2 text-right text-gray-700">{{ formatCurrency(row.rounding) }}</td>
                                <td class="border border-gray-200 px-3 py-2 text-right text-gray-700">{{ formatCurrency(row.returnFee) }}</td>
                                <td class="border border-gray-200 px-3 py-2 text-right font-semibold text-gray-800">{{ formatCurrency(row.netReceived) }}</td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="!rows || rows.length === 0">
                                <td colspan="8" class="border border-gray-200 px-3 py-8 text-center text-gray-400">
                                    Báo cáo không có dữ liệu
                                </td>
                            </tr>

                            <!-- Totals row -->
                            <tr v-if="rows && rows.length > 0" class="bg-blue-50 font-bold">
                                <td class="border border-gray-200 px-3 py-2 text-gray-800">Tổng cộng</td>
                                <td class="border border-gray-200 px-3 py-2 text-right text-gray-800">{{ formatCurrency(totals.productQty) }}</td>
                                <td class="border border-gray-200 px-3 py-2 text-right text-gray-800">{{ formatCurrency(totals.revenue) }}</td>
                                <td class="border border-gray-200 px-3 py-2 text-right text-gray-800">{{ formatCurrency(totals.otherIncome) }}</td>
                                <td class="border border-gray-200 px-3 py-2 text-right text-gray-800">{{ formatCurrency(totals.vat) }}</td>
                                <td class="border border-gray-200 px-3 py-2 text-right text-gray-800">{{ formatCurrency(totals.rounding) }}</td>
                                <td class="border border-gray-200 px-3 py-2 text-right text-gray-800">{{ formatCurrency(totals.returnFee) }}</td>
                                <td class="border border-gray-200 px-3 py-2 text-right text-blue-700">{{ formatCurrency(totals.netReceived) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    </AppLayout>
</template>

<style scoped>
@media print {
    /* Hide everything except the report paper */
    :deep(header), :deep(nav), :deep(.print\\:hidden) {
        display: none !important;
    }
    main {
        background: white !important;
        padding: 0 !important;
    }
}
</style>
