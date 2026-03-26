<script setup>
import { ref, computed } from "vue";
import { router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ReportSidebar from "@/Components/ReportSidebar.vue";

const props = defineProps({
    filters: Object,
    productsInStock: Number,
    totalStock: Number,
    totalStockValue: Number,
    outOfStockToday: Number,
    lowStock7Days: Number,
    lowStock30Days: Number,
    deadStockCount: Number,
    deadStockValue: Number,
    overstockCount: Number,
    overstockValue: Number,
    topGroupsByStock: Array,
    topGroupsByValue: Array,
    topProductsByStock: Array,
    topProductsByValue: Array,
    branches: Array,
});

const branchId = ref(props.filters?.branch_id || "");
const groupTab = ref("stock");
const productTab = ref("stock");

const applyFilter = () => {
    router.get("/reports/inventory", { branch_id: branchId.value || undefined }, { preserveState: true });
};

const formatNumber = (n) => {
    if (n === null || n === undefined || isNaN(n) || n === 0) return "---";
    if (Math.abs(n) >= 1e9) return (n / 1e9).toFixed(2).replace(/\.?0+$/, "") + " tỷ";
    if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(2).replace(/\.?0+$/, "") + " triệu";
    if (Math.abs(n) >= 1e3) return (n / 1e3).toFixed(2).replace(/\.?0+$/, "") + " nghìn";
    return new Intl.NumberFormat("vi-VN").format(n);
};

const formatCurrency = (n) => new Intl.NumberFormat("vi-VN").format(n || 0);

const activeGroups = computed(() => groupTab.value === "stock" ? props.topGroupsByStock : props.topGroupsByValue);
const activeProducts = computed(() => productTab.value === "stock" ? props.topProductsByStock : props.topProductsByValue);
</script>

<template>
    <AppLayout>
        <template #sidebar>
            <ReportSidebar currentPage="inventory" />
        </template>

        <div class="max-w-[1200px] mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-xl font-bold text-gray-800">Tồn kho</h1>
                <select v-model="branchId" @change="applyFilter" class="bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
                    <option value="">Tất cả chi nhánh</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="text-sm text-gray-500 mb-2">Số mặt hàng còn tồn</div>
                    <div class="text-2xl font-bold text-gray-800">{{ formatNumber(productsInStock) }}</div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="text-sm text-gray-500 mb-2">Tồn kho</div>
                    <div class="text-2xl font-bold text-gray-800">{{ formatNumber(totalStock) }}</div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="text-sm text-gray-500 mb-2">Giá trị tồn kho</div>
                    <div class="text-2xl font-bold text-gray-800">{{ formatNumber(totalStockValue) }}</div>
                </div>
            </div>

            <!-- Cảnh báo hết hàng -->
            <h3 class="text-base font-semibold text-gray-700 mb-3">Cảnh báo hết hàng</h3>
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4 flex items-center gap-3">
                    <div class="w-3 h-3 rounded-full bg-red-500"></div>
                    <div>
                        <div class="text-xs text-gray-500">Hết hàng hôm nay</div>
                        <div class="text-lg font-bold text-gray-800">{{ outOfStockToday }} <span class="text-sm font-normal text-gray-500">hàng hóa</span></div>
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4 flex items-center gap-3">
                    <div class="w-3 h-3 rounded-full bg-orange-500"></div>
                    <div>
                        <div class="text-xs text-gray-500">Hết hàng trong 7 ngày tới</div>
                        <div class="text-lg font-bold text-gray-800">{{ lowStock7Days }} <span class="text-sm font-normal text-gray-500">hàng hóa</span></div>
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4 flex items-center gap-3">
                    <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                    <div>
                        <div class="text-xs text-gray-500">Hết hàng trong 30 ngày tới</div>
                        <div class="text-lg font-bold text-gray-800">{{ lowStock30Days }} <span class="text-sm font-normal text-gray-500">hàng hóa</span></div>
                    </div>
                </div>
            </div>

            <!-- Cảnh báo tồn kho -->
            <h3 class="text-base font-semibold text-gray-700 mb-3">Cảnh báo tồn kho</h3>
            <div class="grid grid-cols-2 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center gap-2 mb-2">
                        <div class="w-3 h-3 rounded-full bg-red-500"></div>
                        <span class="text-sm text-red-600 font-medium">Không bán được</span>
                    </div>
                    <div class="text-2xl font-bold text-gray-800">{{ deadStockCount }} <span class="text-sm font-normal text-gray-500">hàng hóa</span></div>
                    <div class="text-xs text-gray-400 mt-1">Dự kiến giá trị hàng lưu kho</div>
                    <div class="text-sm font-semibold text-gray-700">{{ formatNumber(deadStockValue) }}</div>
                    <div class="text-xs text-blue-600 cursor-pointer mt-2">Chi tiết</div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <div class="flex items-center gap-2 mb-2">
                        <div class="w-3 h-3 rounded-full bg-orange-500"></div>
                        <span class="text-sm text-orange-600 font-medium">Tồn kho cao vượt mức tiêu thụ</span>
                    </div>
                    <div class="text-2xl font-bold text-gray-800">{{ overstockCount }} <span class="text-sm font-normal text-gray-500">hàng hóa</span></div>
                    <div class="text-xs text-gray-400 mt-1">Dự kiến giá trị hàng lưu kho</div>
                    <div class="text-sm font-semibold text-gray-700">{{ formatNumber(overstockValue) }}</div>
                </div>
            </div>

            <!-- Top 10% nhóm hàng -->
            <div class="bg-white rounded-lg border border-gray-200 mb-6">
                <div class="flex items-center justify-between px-5 py-3 border-b border-gray-200">
                    <h3 class="text-base font-semibold text-gray-700">Top 10% nhóm hàng</h3>
                    <span class="text-sm text-blue-600 cursor-pointer">Chi tiết</span>
                </div>
                <div class="px-5 py-2 flex gap-1">
                    <button @click="groupTab = 'stock'" class="px-4 py-1.5 text-sm rounded-md transition-colors"
                        :class="groupTab === 'stock' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'">Theo tồn kho</button>
                    <button @click="groupTab = 'value'" class="px-4 py-1.5 text-sm rounded-md transition-colors"
                        :class="groupTab === 'value' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'">Theo giá trị tồn kho</button>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Tên nhóm hàng</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Tồn kho ▼</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Giá trị tồn kho</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="g in activeGroups" :key="g.name" class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-5 py-3 text-gray-700">{{ g.name }}</td>
                            <td class="px-5 py-3 text-right text-gray-600">{{ formatCurrency(g.total_stock) }}</td>
                            <td class="px-5 py-3 text-right text-gray-600">{{ formatCurrency(g.stock_value) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Top 10% hàng hóa -->
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="flex items-center justify-between px-5 py-3 border-b border-gray-200">
                    <h3 class="text-base font-semibold text-gray-700">Top 10% hàng hóa</h3>
                    <span class="text-sm text-blue-600 cursor-pointer">Chi tiết</span>
                </div>
                <div class="px-5 py-2 flex gap-1">
                    <button @click="productTab = 'stock'" class="px-4 py-1.5 text-sm rounded-md transition-colors"
                        :class="productTab === 'stock' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'">Theo tồn kho</button>
                    <button @click="productTab = 'value'" class="px-4 py-1.5 text-sm rounded-md transition-colors"
                        :class="productTab === 'value' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'">Theo giá trị tồn kho</button>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Tên hàng</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Tồn kho ▼</th>
                            <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase">Giá trị tồn kho</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in activeProducts" :key="p.name" class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-5 py-3 text-gray-700">{{ p.name }}</td>
                            <td class="px-5 py-3 text-right text-gray-600">{{ formatCurrency(p.stock) }}</td>
                            <td class="px-5 py-3 text-right text-gray-600">{{ formatCurrency(p.value) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
