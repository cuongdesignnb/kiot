<script setup>
import { ref, computed, watch } from "vue";
import { router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";

const props = defineProps({
    filters: Object, dateFromDisplay: String, dateToDisplay: String,
    branchName: String, branchAddress: String, branches: Array, report: Object,
});

const branchId = ref(props.filters.branch_id || "");
const year = ref(props.filters.year || new Date().getFullYear());
const timeMode = ref(props.filters.time_mode || "custom");
const month = ref(props.filters.month || "");
const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);

const months = Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: `Tháng ${i + 1}` }));

const applyFilter = () => {
    const params = {
        branch_id: branchId.value || undefined,
        year: year.value,
        time_mode: timeMode.value,
    };
    if (timeMode.value === 'month' && month.value) {
        params.month = month.value;
    } else {
        params.date_from = dateFrom.value;
        params.date_to = dateTo.value;
    }
    router.get("/reports/financial-report", params, { preserveState: true });
};

watch([branchId, year], () => applyFilter());

const selectMonth = (m) => { month.value = m; timeMode.value = 'month'; applyFilter(); };
const applyCustom = () => { timeMode.value = 'custom'; applyFilter(); };

const formatCurrency = (n) => new Intl.NumberFormat("vi-VN").format(Math.round(n || 0));

// PDF viewer
const zoom = ref(100);
const now = new Date();
const reportDate = `${String(now.getDate()).padStart(2,'0')}/${String(now.getMonth()+1).padStart(2,'0')}/${now.getFullYear()} ${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`;

const r = computed(() => props.report || {});

const printReport = () => {
    window.print();
};
</script>

<template>
    <AppLayout>
        <div class="flex h-full min-h-[calc(100vh-56px)]">
            <aside class="w-[200px] bg-white border-r border-gray-200 p-4 flex-shrink-0 overflow-y-auto print:hidden">
                <h2 class="text-sm font-bold text-gray-800 mb-4">Báo cáo tài chính</h2>
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Kiểu hiển thị</label>
                    <div class="flex gap-1">
                        <button class="px-3 py-1.5 text-xs rounded bg-blue-600 text-white">Báo cáo</button>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Chi nhánh</label>
                    <select v-model="branchId" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5">
                        <option value="">Tất cả chi nhánh</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="text-xs text-gray-500 font-medium mb-1.5 block">Thời gian</label>
                    <select v-model="year" class="w-full text-sm border border-gray-300 rounded px-2 py-1.5 mb-2">
                        <option v-for="y in [2024,2025,2026,2027]" :key="y" :value="y">{{ y }}</option>
                    </select>
                    <div class="space-y-1.5">
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700">
                            <input type="radio" v-model="timeMode" value="month" class="accent-blue-600" />
                            <span>Theo Tháng</span>
                        </label>
                        <div v-if="timeMode === 'month'" class="ml-4">
                            <select v-model="month" @change="selectMonth(month)" class="w-full text-xs border border-gray-300 rounded px-2 py-1.5">
                                <option value="">Chọn tháng</option>
                                <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
                            </select>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700">
                            <input type="radio" v-model="timeMode" value="custom" class="accent-blue-600" />
                            <span>Tùy chỉnh</span>
                        </label>
                        <div v-if="timeMode === 'custom'" class="ml-4 space-y-1.5">
                            <input type="date" v-model="dateFrom" class="w-full text-xs border border-gray-300 rounded px-2 py-1.5" />
                            <span class="text-xs text-gray-400">đến</span>
                            <input type="date" v-model="dateTo" class="w-full text-xs border border-gray-300 rounded px-2 py-1.5" />
                            <button @click="applyCustom" class="w-full text-xs bg-blue-50 text-blue-600 border border-blue-200 rounded py-1.5 hover:bg-blue-100">Áp dụng</button>
                        </div>
                    </div>
                </div>
            </aside>

            <main class="flex-1 bg-gray-50 overflow-auto">
                <!-- PDF TOOLBAR -->
                <div class="bg-[#404040] px-3 py-1.5 flex items-center justify-between print:hidden sticky top-0 z-10">
                    <div class="flex items-center gap-1">
                        <div class="flex items-center gap-1 mx-1">
                            <input type="number" value="1" readonly class="w-10 text-center text-xs bg-white text-gray-800 border border-gray-400 rounded px-1 py-0.5" />
                            <span class="text-white text-xs">/ 1</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1">
                        <button @click="applyFilter" class="pdf-btn" title="Làm mới">🔄</button>
                        <span class="w-px h-5 bg-gray-500 mx-1"></span>
                        <button @click="printReport" class="pdf-btn" title="In">🖨️</button>
                        <span class="w-px h-5 bg-gray-500 mx-1"></span>
                        <button @click="zoom > 60 && (zoom -= 10)" class="pdf-btn">🔍−</button>
                        <span class="text-white text-xs mx-1">{{ zoom }}%</span>
                        <button @click="zoom < 150 && (zoom += 10)" class="pdf-btn">🔍+</button>
                    </div>
                </div>

                <!-- PDF DOCUMENT -->
                <div class="p-6 flex justify-center bg-[#e8e8e8] min-h-[calc(100vh-120px)]">
                    <div class="bg-white shadow-lg border border-gray-300 w-full p-10 print:shadow-none print:border-none print:p-0 print:max-w-full"
                        :style="{ maxWidth: (900 * zoom / 100) + 'px', fontSize: (zoom / 100) + 'em' }">
                        <p class="text-xs text-gray-400 mb-3" style="font-size:0.75em">Ngày lập: {{ reportDate }}</p>
                        <h1 class="text-lg font-bold text-center mb-1">BÁO CÁO KẾT QUẢ HOẠT ĐỘNG KINH DOANH</h1>
                        <p class="text-sm text-gray-500 text-center">Từ ngày {{ dateFromDisplay }} đến ngày {{ dateToDisplay }}</p>
                        <p class="text-sm text-gray-500 text-center mb-6">Chi nhánh: {{ branchName }}</p>

                        <table class="w-full border-collapse" style="font-size:0.85em">
                            <thead>
                                <tr class="bg-blue-600 text-white">
                                    <th class="px-3 py-2 text-left font-semibold border border-blue-700" style="width:60%">Chỉ tiêu</th>
                                    <th class="px-3 py-2 text-right font-semibold border border-blue-700" style="width:40%">Số tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- REVENUE SECTION -->
                                <tr class="bg-yellow-50 font-bold">
                                    <td class="px-3 py-2 border border-gray-200 text-gray-900">Doanh thu (1)</td>
                                    <td class="px-3 py-2 text-right border border-gray-200">{{ formatCurrency(r.totalSales) }}</td>
                                </tr>
                                <tr class="bg-yellow-50 font-bold">
                                    <td class="px-3 py-2 border border-gray-200 text-gray-900">Giá vốn hàng bán (2)</td>
                                    <td class="px-3 py-2 text-right border border-gray-200">{{ formatCurrency(r.cogs) }}</td>
                                </tr>
                                <tr>
                                    <td class="px-3 py-1.5 border border-gray-200 pl-6 text-blue-600">Trả hàng bán</td>
                                    <td class="px-3 py-1.5 text-right border border-gray-200">{{ formatCurrency(r.salesReturns) }}</td>
                                </tr>
                                <tr>
                                    <td class="px-3 py-1.5 border border-gray-200 pl-6 text-blue-600">Giảm giá hóa đơn</td>
                                    <td class="px-3 py-1.5 text-right border border-gray-200">{{ formatCurrency(r.invoiceDiscounts) }}</td>
                                </tr>
                                <tr class="bg-yellow-50 font-bold">
                                    <td class="px-3 py-2 border border-gray-200 text-gray-900">Lợi nhuận gộp (5=1-2-3-4)</td>
                                    <td class="px-3 py-2 text-right border border-gray-200">{{ formatCurrency(r.grossProfit) }}</td>
                                </tr>

                                <!-- EXPENSES SECTION -->
                                <tr class="bg-yellow-50 font-bold">
                                    <td class="px-3 py-2 border border-gray-200 text-gray-900">Chi phí (6)</td>
                                    <td class="px-3 py-2 text-right border border-gray-200">{{ formatCurrency(r.totalExpenses) }}</td>
                                </tr>
                                <tr v-for="(exp, idx) in r.expensesByCategory" :key="'exp'+idx">
                                    <td class="px-3 py-1.5 border border-gray-200 pl-6 text-blue-600">{{ exp.name }}</td>
                                    <td class="px-3 py-1.5 text-right border border-gray-200">{{ formatCurrency(exp.amount) }}</td>
                                </tr>

                                <!-- OPERATING PROFIT -->
                                <tr class="bg-yellow-100 font-bold">
                                    <td class="px-3 py-2 border border-gray-200 text-gray-900">Lợi nhuận từ hoạt động kinh doanh (7=5-6)</td>
                                    <td class="px-3 py-2 text-right border border-gray-200">{{ formatCurrency(r.operatingProfit) }}</td>
                                </tr>

                                <!-- OTHER INCOME -->
                                <tr class="bg-yellow-50 font-bold">
                                    <td class="px-3 py-2 border border-gray-200 text-gray-900">Thu nhập khác (8)</td>
                                    <td class="px-3 py-2 text-right border border-gray-200">{{ formatCurrency(r.totalOtherIncome) }}</td>
                                </tr>
                                <tr v-for="(inc, idx) in r.otherIncomeItems" :key="'inc'+idx">
                                    <td class="px-3 py-1.5 border border-gray-200 pl-6 text-blue-600">{{ inc.name }}</td>
                                    <td class="px-3 py-1.5 text-right border border-gray-200">{{ formatCurrency(inc.amount) }}</td>
                                </tr>

                                <!-- OTHER EXPENSES -->
                                <tr class="bg-yellow-50 font-bold">
                                    <td class="px-3 py-2 border border-gray-200 text-gray-900">Chi phí khác (9)</td>
                                    <td class="px-3 py-2 text-right border border-gray-200">{{ formatCurrency(r.totalOtherExpenses) }}</td>
                                </tr>

                                <!-- NET PROFIT -->
                                <tr class="bg-blue-50 font-bold text-lg">
                                    <td class="px-3 py-3 border border-gray-200 text-gray-900">Lợi nhuận thuần (10=(7+8)-9)</td>
                                    <td class="px-3 py-3 text-right border border-gray-200" :class="r.netProfit >= 0 ? 'text-green-700' : 'text-red-600'">{{ formatCurrency(r.netProfit) }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Footer with branch address -->
                        <div v-if="branchAddress" class="mt-8 pt-4 border-t border-gray-200 text-center text-xs text-gray-400">
                            {{ branchName }}: {{ branchAddress }}
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </AppLayout>
</template>

<style scoped>
.pdf-btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
    color: #d1d5db;
    border-radius: 0.25rem;
    cursor: pointer;
    transition: color 0.15s, background-color 0.15s;
    background: none;
    border: none;
}
.pdf-btn:hover { color: #fff; background-color: #4b5563; }
@media print {
    aside { display: none !important; }
    main { padding: 0 !important; }
}
</style>
