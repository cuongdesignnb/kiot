<script setup>
import { formatVND as fmt } from '@/utils/money';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ref, computed, onMounted, onUnmounted } from 'vue';

import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  ArcElement,
  Title,
  Tooltip,
  Legend,
  Filler
} from 'chart.js';
import { Line, Bar, Doughnut } from 'vue-chartjs';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, ArcElement, Title, Tooltip, Legend, Filler);

const props = defineProps({
    todayRevenue: Number,
    yesterdayRevenue: Number,
    todayOrders: Number,
    yesterdayOrders: Number,
    thisMonthRevenue: Number,
    lastMonthRevenue: Number,
    thisMonthProfit: Number,
    thisMonthPurchase: Number,
    thisMonthReturn: Number,
    totalProductsInStock: Number,
    totalProductCount: Number,
    newCustomersThisMonth: Number,
    totalCustomers: Number,
    totalCustomerDebt: Number,
    totalSupplierDebt: Number,
    outOfStockCount: Number,
    totalStockValue: Number,
    revenueChart: Object,
    cashFlowChart: Object,
    topProducts: Array,
    topProductsByRevenue: Array,
    topProductsByProfit: Array,
    topCustomersByRevenue: Array,
    topCustomersByQty: Array,
    topEmployees: Array,
    inventoryProducts: Array,
    lowStockProducts: Array,
    recentInvoices: Array,
    recentPurchases: Array,
    recentReturns: Array,
    ordersByStatus: Object,
    branches: Array,
    // Step 24.1 — Operational control props
    serialControl: { type: Object, default: () => ({}) },
    stockTransferControl: { type: Object, default: () => ({}) },
    repairControl: { type: Object, default: () => ({}) },
    warrantyControl: { type: Object, default: () => ({}) },
    inventoryRisk: { type: Object, default: () => ({}) },
    financeControl: { type: Object, default: () => ({}) },
    highRiskActivities: { type: Object, default: () => ({}) },
    canViewAuditLog: { type: Boolean, default: false },
});

// Tab states
const productRankTab = ref('revenue'); // 'revenue' | 'profit' | 'qty'
const customerRankTab = ref('revenue'); // 'revenue' | 'qty'
const inventoryFilter = ref('all'); // 'all' | 'low' | 'out'

const fmtShort = (v) => {
    const n = Number(v || 0);
    const abs = Math.abs(n);
    const sign = n < 0 ? '-' : '';
    if (abs >= 1e9) return sign + (abs / 1e9).toFixed(1) + ' tỷ';
    if (abs >= 1e6) return sign + (abs / 1e6).toFixed(1) + ' tr';
    if (abs >= 1e3) return sign + (abs / 1e3).toFixed(0) + 'k';
    return sign + abs.toString();
};

const pctChange = (current, prev) => {
    if (!prev || prev === 0) return current > 0 ? 100 : 0;
    return Math.round(((current - prev) / prev) * 100);
};

const revenuePct = computed(() => pctChange(props.todayRevenue, props.yesterdayRevenue));
const ordersPct = computed(() => pctChange(props.todayOrders, props.yesterdayOrders));
const monthPct = computed(() => pctChange(props.thisMonthRevenue, props.lastMonthRevenue));

// Auto-refresh key metrics every 60 seconds
let pollTimer = null;
onMounted(() => {
    pollTimer = setInterval(() => {
        router.reload({
            only: ['todayRevenue', 'todayOrders', 'totalCustomerDebt', 'totalSupplierDebt', 'totalStockValue', 'outOfStockCount'],
            preserveScroll: true,
        });
    }, 60000);
});
onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer);
});

const timeAgo = (dateStr) => {
    const d = new Date(dateStr);
    const now = new Date();
    const diff = (now - d) / 1000;
    if (diff < 60) return 'Vừa xong';
    if (diff < 3600) return Math.floor(diff / 60) + ' phút trước';
    if (diff < 86400) return Math.floor(diff / 3600) + ' giờ trước';
    return Math.floor(diff / 86400) + ' ngày trước';
};

// ── CHART CONFIGS ──
const revenueChartData = computed(() => ({
    labels: props.revenueChart.labels,
    datasets: [
        {
            label: 'Doanh thu',
            data: props.revenueChart.revenue,
            borderColor: '#6366f1',
            backgroundColor: (ctx) => {
                const chart = ctx.chart;
                const { ctx: c, chartArea } = chart;
                if (!chartArea) return 'rgba(99,102,241,0.1)';
                const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                g.addColorStop(0, 'rgba(99,102,241,0.3)');
                g.addColorStop(1, 'rgba(99,102,241,0.02)');
                return g;
            },
            fill: true,
            tension: 0.4,
            borderWidth: 2.5,
            pointRadius: 0,
            pointHoverRadius: 6,
            pointHoverBackgroundColor: '#6366f1',
            pointHoverBorderColor: '#fff',
            pointHoverBorderWidth: 2,
        }
    ]
}));

const revenueChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: '#1e1b4b',
            titleFont: { size: 12, weight: '600' },
            bodyFont: { size: 13 },
            padding: 12,
            cornerRadius: 8,
            callbacks: {
                label: (ctx) => ' ' + fmt(ctx.parsed.y) + ' đ'
            }
        }
    },
    scales: {
        x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#94a3b8', maxRotation: 0 } },
        y: {
            grid: { color: '#f1f5f9', drawBorder: false },
            ticks: { font: { size: 10 }, color: '#94a3b8', callback: (v) => fmtShort(v) },
            border: { display: false }
        }
    }
};

const cashFlowChartData = computed(() => ({
    labels: props.cashFlowChart.labels,
    datasets: [
        {
            label: 'Thu',
            data: props.cashFlowChart.receipts,
            backgroundColor: '#22c55e',
            borderRadius: 6,
            barPercentage: 0.5,
        },
        {
            label: 'Chi',
            data: props.cashFlowChart.payments,
            backgroundColor: '#ef4444',
            borderRadius: 6,
            barPercentage: 0.5,
        }
    ]
}));

const cashFlowChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { position: 'top', labels: { usePointStyle: true, pointStyle: 'circle', padding: 16, font: { size: 12 } } },
        tooltip: {
            backgroundColor: '#1e1b4b',
            padding: 10,
            cornerRadius: 8,
            callbacks: { label: (ctx) => ' ' + ctx.dataset.label + ': ' + fmt(ctx.parsed.y) + ' đ' }
        }
    },
    scales: {
        x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#64748b' } },
        y: { grid: { color: '#f1f5f9', drawBorder: false }, ticks: { font: { size: 10 }, color: '#94a3b8', callback: (v) => fmtShort(v) }, border: { display: false } }
    }
};

// Top products bar chart
const topProductsChartData = computed(() => ({
    labels: (props.topProducts || []).map(p => p.name.length > 18 ? p.name.slice(0, 18) + '…' : p.name),
    datasets: [{
        label: 'Số lượng bán',
        data: (props.topProducts || []).map(p => p.qty),
        backgroundColor: ['#6366f1', '#8b5cf6', '#a78bfa', '#c4b5fd', '#818cf8', '#6366f1', '#8b5cf6', '#a78bfa', '#c4b5fd', '#818cf8'],
        borderRadius: 6,
        barPercentage: 0.6,
    }]
}));

const topProductsChartOptions = {
    indexAxis: 'y',
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: '#1e1b4b',
            padding: 10,
            cornerRadius: 8,
            callbacks: { label: (ctx) => ' SL: ' + ctx.parsed.x }
        }
    },
    scales: {
        x: { grid: { color: '#f1f5f9', drawBorder: false }, ticks: { font: { size: 10 }, color: '#94a3b8' }, border: { display: false } },
        y: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#475569' } }
    }
};

// Order status doughnut
const statusLabels = { draft: 'Nháp', confirmed: 'Xác nhận', processing: 'Đang xử lý', completed: 'Hoàn thành', cancelled: 'Đã hủy', return: 'Trả hàng' };
const statusColors = { draft: '#94a3b8', confirmed: '#3b82f6', processing: '#f59e0b', completed: '#22c55e', cancelled: '#ef4444', return: '#8b5cf6' };

const orderStatusChartData = computed(() => {
    const entries = Object.entries(props.ordersByStatus || {});
    return {
        labels: entries.map(([k]) => statusLabels[k] || k),
        datasets: [{
            data: entries.map(([, v]) => v),
            backgroundColor: entries.map(([k]) => statusColors[k] || '#cbd5e1'),
            borderWidth: 0,
            spacing: 2,
        }]
    };
});

const orderStatusOptions = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '65%',
    plugins: {
        legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', padding: 12, font: { size: 11 } } },
        tooltip: { backgroundColor: '#1e1b4b', padding: 10, cornerRadius: 8 }
    }
};
</script>

<template>
    <Head title="Tổng quan - KiotViet Clone" />
    <AppLayout>
        <template #sidebar>
            <div class="px-4 py-3 font-semibold text-gray-800 border-b border-gray-200 uppercase text-xs tracking-wider">Tổng quan nhanh</div>
            <div class="px-4 py-4 space-y-3">
               <div>
                   <div class="text-xs text-gray-500 mb-0.5">Doanh thu hôm nay</div>
                   <div class="font-bold text-lg text-indigo-600 font-mono">{{ fmt(todayRevenue) }}</div>
                   <div class="flex items-center gap-1 mt-0.5">
                       <span :class="revenuePct >= 0 ? 'text-green-600' : 'text-red-500'" class="text-xs font-semibold flex items-center">
                           <svg v-if="revenuePct >= 0" class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 9.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L11 7.414V15a1 1 0 11-2 0V7.414L6.707 9.707a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg>
                           <svg v-else class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M14.707 10.293a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L9 12.586V5a1 1 0 012 0v7.586l2.293-2.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>
                           {{ Math.abs(revenuePct) }}%
                       </span>
                       <span class="text-[10px] text-gray-400">vs hôm qua</span>
                   </div>
               </div>
               <div class="border-t border-gray-100 pt-2">
                   <div class="text-xs text-gray-500 mb-0.5">Đơn hôm nay</div>
                   <div class="font-bold text-lg text-gray-800">{{ todayOrders }} <span class="text-xs text-gray-400 font-normal">đơn</span></div>
               </div>
               <div class="border-t border-gray-100 pt-2">
                   <div class="text-xs text-gray-500 mb-0.5">Tồn kho</div>
                   <div class="font-bold text-gray-800">{{ fmt(totalProductsInStock) }} <span class="text-xs text-gray-400 font-normal">SP</span></div>
                   <div class="text-xs text-gray-500 mt-0.5">Giá trị: <span class="font-semibold text-gray-700">{{ fmt(totalStockValue) }}</span></div>
                   <div v-if="outOfStockCount > 0" class="text-xs text-red-500 mt-0.5">⚠ {{ outOfStockCount }} hết hàng</div>
               </div>
               <div class="border-t border-gray-100 pt-2">
                   <div class="text-xs text-gray-500 mb-0.5">Nợ phải thu</div>
                   <div class="font-bold text-orange-600 font-mono">{{ fmt(totalCustomerDebt) }}</div>
               </div>
               <div class="border-t border-gray-100 pt-2">
                   <div class="text-xs text-gray-500 mb-0.5">Nợ phải trả NCC</div>
                   <div class="font-bold text-red-500 font-mono">{{ fmt(totalSupplierDebt) }}</div>
               </div>
            </div>

            <div class="px-4 py-3 font-semibold text-gray-800 border-b border-t border-gray-200 uppercase text-xs tracking-wider bg-gray-50/50">Chi nhánh</div>
            <div class="px-4 py-3">
                <select class="w-full border border-gray-300 rounded p-1.5 text-[13px] outline-none text-gray-700 font-medium">
                    <option value="">Tất cả chi nhánh</option>
                    <option v-for="branch in branches" :key="branch.id">{{ branch.name }}</option>
                </select>
            </div>
        </template>

        <div class="p-5 space-y-5">

            <!-- ═══ HEADER ═══ -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-800">Tổng quan kinh doanh</h1>
                    <p class="text-sm text-gray-500">Cập nhật lúc {{ new Date().toLocaleString('vi-VN') }}</p>
                </div>
            </div>

            <!-- ═══ 6 METRIC CARDS ═══ -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                <!-- Card: Doanh thu tháng -->
                <div class="bg-gradient-to-br from-indigo-500 to-indigo-700 rounded-xl p-4 text-white shadow-lg shadow-indigo-200 relative overflow-hidden">
                    <div class="absolute -right-3 -top-3 w-16 h-16 bg-white/10 rounded-full"></div>
                    <div class="absolute -right-1 -bottom-5 w-12 h-12 bg-white/5 rounded-full"></div>
                    <p class="text-indigo-200 text-[11px] font-semibold uppercase tracking-wide mb-1">Doanh thu tháng</p>
                    <p class="text-xl font-bold font-mono tracking-tight">{{ fmtShort(thisMonthRevenue) }}</p>
                    <div class="flex items-center gap-1 mt-1.5">
                        <span :class="monthPct >= 0 ? 'bg-green-400/20 text-green-200' : 'bg-red-400/20 text-red-200'" class="text-[10px] font-bold px-1.5 py-0.5 rounded-full">
                            {{ monthPct >= 0 ? '+' : '' }}{{ monthPct }}%
                        </span>
                        <span class="text-[10px] text-indigo-300">vs tháng trước</span>
                    </div>
                </div>

                <!-- Card: Lợi nhuận gộp -->
                <div class="bg-gradient-to-br from-emerald-500 to-emerald-700 rounded-xl p-4 text-white shadow-lg shadow-emerald-200 relative overflow-hidden">
                    <div class="absolute -right-3 -top-3 w-16 h-16 bg-white/10 rounded-full"></div>
                    <p class="text-emerald-200 text-[11px] font-semibold uppercase tracking-wide mb-1">Lợi nhuận gộp</p>
                    <p class="text-xl font-bold font-mono tracking-tight">{{ fmtShort(thisMonthProfit) }}</p>
                    <p class="text-[10px] text-emerald-200 mt-1.5">Tháng này</p>
                </div>

                <!-- Card: Nhập hàng -->
                <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm hover:shadow-md transition">
                    <p class="text-gray-500 text-[11px] font-semibold uppercase tracking-wide mb-1">Nhập hàng tháng</p>
                    <p class="text-xl font-bold text-gray-800 font-mono tracking-tight">{{ fmtShort(thisMonthPurchase) }}</p>
                    <div class="flex items-center gap-1 mt-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                        <span class="text-[10px] text-gray-400">Tổng nhập</span>
                    </div>
                </div>

                <!-- Card: Trả hàng -->
                <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm hover:shadow-md transition">
                    <p class="text-gray-500 text-[11px] font-semibold uppercase tracking-wide mb-1">Trả hàng tháng</p>
                    <p class="text-xl font-bold text-red-500 font-mono tracking-tight">{{ fmtShort(thisMonthReturn) }}</p>
                    <div class="flex items-center gap-1 mt-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-400"></span>
                        <span class="text-[10px] text-gray-400">Tổng trả</span>
                    </div>
                </div>

                <!-- Card: Khách hàng -->
                <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm hover:shadow-md transition">
                    <p class="text-gray-500 text-[11px] font-semibold uppercase tracking-wide mb-1">Khách hàng</p>
                    <p class="text-xl font-bold text-gray-800">{{ totalCustomers }}</p>
                    <div class="flex items-center gap-1 mt-1.5">
                        <span class="text-[10px] text-green-600 font-semibold">+{{ newCustomersThisMonth }}</span>
                        <span class="text-[10px] text-gray-400">mới tháng này</span>
                    </div>
                </div>

                <!-- Card: Sản phẩm -->
                <div class="bg-white rounded-xl p-4 border border-gray-200 shadow-sm hover:shadow-md transition">
                    <p class="text-gray-500 text-[11px] font-semibold uppercase tracking-wide mb-1">Sản phẩm</p>
                    <p class="text-xl font-bold text-gray-800">{{ totalProductCount }}</p>
                    <div class="flex items-center gap-1 mt-1.5">
                        <span v-if="outOfStockCount > 0" class="text-[10px] text-red-500 font-semibold">{{ outOfStockCount }} hết hàng</span>
                        <span v-else class="text-[10px] text-green-600">Đủ hàng</span>
                    </div>
                </div>
            </div>

            <!-- ═══ CHARTS ROW 1 ═══ -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <!-- Revenue Chart (2/3) -->
                <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
                        <div>
                            <h2 class="font-bold text-gray-800 text-[15px]">Doanh thu 30 ngày</h2>
                            <p class="text-xs text-gray-400">Theo ngày</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="flex items-center gap-1">
                                <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                                <span class="text-xs text-gray-500">Doanh thu</span>
                            </div>
                        </div>
                    </div>
                    <div class="p-4" style="height: 320px;">
                        <Line :data="revenueChartData" :options="revenueChartOptions" />
                    </div>
                </div>

                <!-- Cash flow chart (1/3) -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100">
                        <h2 class="font-bold text-gray-800 text-[15px]">Thu - Chi tháng này</h2>
                        <p class="text-xs text-gray-400">Theo tuần</p>
                    </div>
                    <div class="p-4" style="height: 320px;">
                        <Bar :data="cashFlowChartData" :options="cashFlowChartOptions" />
                    </div>
                </div>
            </div>

            <!-- ═══ CHARTS ROW 2 ═══ -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <!-- Top Products (2/3) -->
                <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
                        <div>
                            <h2 class="font-bold text-gray-800 text-[15px]">Top sản phẩm bán chạy</h2>
                            <p class="text-xs text-gray-400">Tháng này</p>
                        </div>
                        <Link href="/products" class="text-xs text-indigo-600 hover:underline font-medium">Xem tất cả →</Link>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2">
                        <div class="p-4" style="height: 300px;">
                            <Bar v-if="topProducts && topProducts.length" :data="topProductsChartData" :options="topProductsChartOptions" />
                            <div v-else class="flex items-center justify-center h-full text-gray-400 text-sm">Chưa có dữ liệu</div>
                        </div>
                        <div class="p-4 border-l border-gray-100">
                            <div class="space-y-2">
                                <div v-for="(p, idx) in (topProducts || []).slice(0, 8)" :key="idx" class="flex items-center justify-between py-1.5 px-2 rounded-lg hover:bg-gray-50 transition">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="flex-shrink-0 w-5 h-5 rounded-full text-white text-[10px] font-bold flex items-center justify-center" :class="idx < 3 ? 'bg-indigo-500' : 'bg-gray-300'">{{ idx + 1 }}</span>
                                        <div class="min-w-0">
                                            <p class="text-sm text-gray-800 truncate font-medium">{{ p.name }}</p>
                                            <p class="text-[10px] text-gray-400">{{ p.sku }}</p>
                                        </div>
                                    </div>
                                    <div class="text-right flex-shrink-0 ml-2">
                                        <p class="text-sm font-bold text-gray-800">{{ p.qty }}</p>
                                        <p class="text-[10px] text-gray-400">{{ fmtShort(p.revenue) }}đ</p>
                                    </div>
                                </div>
                                <div v-if="!topProducts || topProducts.length === 0" class="text-center text-gray-400 text-sm py-6">Chưa có dữ liệu bán hàng</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order status doughnut (1/3) -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100">
                        <h2 class="font-bold text-gray-800 text-[15px]">Đơn hàng theo trạng thái</h2>
                        <p class="text-xs text-gray-400">Tất cả thời gian</p>
                    </div>
                    <div class="p-4 flex items-center justify-center" style="height: 300px;">
                        <Doughnut v-if="Object.keys(ordersByStatus || {}).length" :data="orderStatusChartData" :options="orderStatusOptions" />
                        <div v-else class="text-gray-400 text-sm">Chưa có đơn hàng</div>
                    </div>
                </div>
            </div>

            <!-- ═══ BOTTOM ROW ═══ -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <!-- Recent invoices -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
                        <h2 class="font-bold text-gray-800 text-[15px]">Hóa đơn gần đây</h2>
                        <Link href="/invoices" class="text-xs text-indigo-600 hover:underline font-medium">Xem tất cả →</Link>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="inv in recentInvoices" :key="inv.id" class="px-5 py-3 flex items-center justify-between hover:bg-gray-50/50 transition">
                            <div>
                                <p class="text-sm font-semibold text-gray-800">{{ inv.code }}</p>
                                <p class="text-[10px] text-gray-400">{{ timeAgo(inv.created_at) }} <span v-if="inv.employee">• {{ inv.employee.name }}</span></p>
                            </div>
                            <span class="text-sm font-bold text-indigo-600 font-mono">+{{ fmt(inv.total) }}</span>
                        </div>
                        <div v-if="!recentInvoices || recentInvoices.length === 0" class="px-5 py-8 text-center text-gray-400 text-sm">Chưa có hóa đơn</div>
                    </div>
                </div>

                <!-- Recent purchases -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
                        <h2 class="font-bold text-gray-800 text-[15px]">Nhập hàng gần đây</h2>
                        <Link href="/purchases" class="text-xs text-indigo-600 hover:underline font-medium">Xem tất cả →</Link>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="p in recentPurchases" :key="p.id" class="px-5 py-3 flex items-center justify-between hover:bg-gray-50/50 transition">
                            <div>
                                <p class="text-sm font-semibold text-gray-800">{{ p.code }}</p>
                                <p class="text-[10px] text-gray-400">{{ timeAgo(p.created_at) }} <span v-if="p.supplier">• {{ p.supplier.name }}</span></p>
                            </div>
                            <div class="text-right">
                                <span class="text-sm font-bold text-red-500 font-mono">-{{ fmt(p.total_amount) }}</span>
                                <span v-if="p.status === 'completed'" class="block text-[10px] text-green-600">Hoàn thành</span>
                                <span v-else class="block text-[10px] text-yellow-600">{{ p.status }}</span>
                            </div>
                        </div>
                        <div v-if="!recentPurchases || recentPurchases.length === 0" class="px-5 py-8 text-center text-gray-400 text-sm">Chưa nhập hàng</div>
                    </div>
                </div>

                <!-- Low stock alerts -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
                        <div class="flex items-center gap-2">
                            <h2 class="font-bold text-gray-800 text-[15px]">Cảnh báo tồn kho</h2>
                            <span v-if="outOfStockCount > 0" class="px-1.5 py-0.5 text-[10px] font-bold text-white bg-red-500 rounded-full">{{ outOfStockCount }}</span>
                        </div>
                        <Link href="/products" class="text-xs text-indigo-600 hover:underline font-medium">Xem kho →</Link>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="p in lowStockProducts" :key="p.id" class="px-5 py-3 flex items-center justify-between hover:bg-gray-50/50 transition">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-800 truncate">{{ p.name }}</p>
                                <p class="text-[10px] text-gray-400">{{ p.sku }}</p>
                            </div>
                            <div class="flex items-center gap-2 flex-shrink-0 ml-2">
                                <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="p.stock_quantity <= 2 ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'">
                                    Còn {{ p.stock_quantity }}
                                </span>
                            </div>
                        </div>
                        <div v-if="outOfStockCount > 0" class="px-5 py-3 bg-red-50 text-center">
                            <p class="text-xs text-red-600 font-semibold">🚨 {{ outOfStockCount }} sản phẩm đã hết hàng</p>
                        </div>
                        <div v-if="!lowStockProducts || lowStockProducts.length === 0" class="px-5 py-8 text-center text-gray-400 text-sm">Tồn kho ổn</div>
                    </div>
                </div>
            </div>

            <!-- ═══ RANKING SECTIONS ═══ -->

            <!-- Top sản phẩm bán chạy -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
                    <h2 class="font-bold text-gray-800 text-[15px]">🏆 Top sản phẩm bán chạy <span class="text-xs text-gray-400 font-normal">tháng này</span></h2>
                    <div class="flex gap-1 bg-gray-100 rounded-lg p-0.5">
                        <button @click="productRankTab = 'revenue'" :class="productRankTab === 'revenue' ? 'bg-white shadow text-indigo-700' : 'text-gray-500 hover:text-gray-700'" class="px-3 py-1 text-xs font-semibold rounded-md transition">Doanh thu</button>
                        <button @click="productRankTab = 'profit'" :class="productRankTab === 'profit' ? 'bg-white shadow text-indigo-700' : 'text-gray-500 hover:text-gray-700'" class="px-3 py-1 text-xs font-semibold rounded-md transition">Lợi nhuận</button>
                        <button @click="productRankTab = 'qty'" :class="productRankTab === 'qty' ? 'bg-white shadow text-indigo-700' : 'text-gray-500 hover:text-gray-700'" class="px-3 py-1 text-xs font-semibold rounded-md transition">Số lượng</button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600 text-xs uppercase tracking-wider">
                            <tr>
                                <th class="px-5 py-2.5 text-left w-10">#</th>
                                <th class="px-3 py-2.5 text-left">Sản phẩm</th>
                                <th class="px-3 py-2.5 text-left">Mã SKU</th>
                                <th class="px-3 py-2.5 text-right">SL bán</th>
                                <th class="px-3 py-2.5 text-right">Doanh thu</th>
                                <th v-if="productRankTab !== 'qty'" class="px-5 py-2.5 text-right">Lợi nhuận</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="(p, idx) in (productRankTab === 'profit' ? topProductsByProfit : (productRankTab === 'qty' ? topProducts : topProductsByRevenue)) || []" :key="idx" class="hover:bg-indigo-50/30 transition">
                                <td class="px-5 py-2.5">
                                    <span class="w-5 h-5 rounded-full text-white text-[10px] font-bold flex items-center justify-center" :class="idx < 3 ? ['bg-yellow-500','bg-gray-400','bg-amber-600'][idx] : 'bg-gray-200 text-gray-600'">{{ idx + 1 }}</span>
                                </td>
                                <td class="px-3 py-2.5 font-medium text-gray-800">{{ p.name }}</td>
                                <td class="px-3 py-2.5 text-gray-500 text-xs">{{ p.sku }}</td>
                                <td class="px-3 py-2.5 text-right font-semibold">{{ p.qty }}</td>
                                <td class="px-3 py-2.5 text-right font-mono text-indigo-600 font-semibold">{{ fmt(p.revenue) }}</td>
                                <td v-if="productRankTab !== 'qty'" class="px-5 py-2.5 text-right font-mono font-semibold" :class="p.profit >= 0 ? 'text-green-600' : 'text-red-500'">{{ fmt(p.profit) }}</td>
                            </tr>
                            <tr v-if="!(productRankTab === 'profit' ? topProductsByProfit : topProductsByRevenue)?.length"><td colspan="6" class="px-5 py-6 text-center text-gray-400">Chưa có dữ liệu</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top khách hàng + Top nhân viên -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <!-- Top khách hàng -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
                        <h2 class="font-bold text-gray-800 text-[15px]">👤 Top khách hàng <span class="text-xs text-gray-400 font-normal">tháng này</span></h2>
                        <div class="flex gap-1 bg-gray-100 rounded-lg p-0.5">
                            <button @click="customerRankTab = 'revenue'" :class="customerRankTab === 'revenue' ? 'bg-white shadow text-indigo-700' : 'text-gray-500'" class="px-3 py-1 text-xs font-semibold rounded-md transition">Doanh thu</button>
                            <button @click="customerRankTab = 'qty'" :class="customerRankTab === 'qty' ? 'bg-white shadow text-indigo-700' : 'text-gray-500'" class="px-3 py-1 text-xs font-semibold rounded-md transition">Số đơn</button>
                        </div>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="(c, idx) in (customerRankTab === 'qty' ? topCustomersByQty : topCustomersByRevenue) || []" :key="idx" class="px-5 py-3 flex items-center justify-between hover:bg-gray-50/50 transition">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="flex-shrink-0 w-6 h-6 rounded-full text-white text-[10px] font-bold flex items-center justify-center" :class="idx < 3 ? 'bg-indigo-500' : 'bg-gray-300'">{{ idx + 1 }}</span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-800 truncate">{{ c.name }}</p>
                                    <p class="text-[10px] text-gray-400">{{ c.phone || c.code }}</p>
                                </div>
                            </div>
                            <div class="text-right flex-shrink-0 ml-2">
                                <p class="text-sm font-bold text-indigo-600 font-mono">{{ fmt(c.revenue) }}</p>
                                <p class="text-[10px] text-gray-400">{{ c.orders }} đơn</p>
                            </div>
                        </div>
                        <div v-if="!(customerRankTab === 'qty' ? topCustomersByQty : topCustomersByRevenue)?.length" class="px-5 py-8 text-center text-gray-400 text-sm">Chưa có dữ liệu</div>
                    </div>
                </div>

                <!-- Top nhân viên -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100">
                        <h2 class="font-bold text-gray-800 text-[15px]">⭐ Top nhân viên bán hàng <span class="text-xs text-gray-400 font-normal">tháng này</span></h2>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="(e, idx) in topEmployees || []" :key="idx" class="px-5 py-3 flex items-center justify-between hover:bg-gray-50/50 transition">
                            <div class="flex items-center gap-3">
                                <span class="flex-shrink-0 w-6 h-6 rounded-full text-white text-[10px] font-bold flex items-center justify-center" :class="idx === 0 ? 'bg-yellow-500' : idx === 1 ? 'bg-gray-400' : idx === 2 ? 'bg-amber-600' : 'bg-gray-200 text-gray-600'">{{ idx + 1 }}</span>
                                <div>
                                    <p class="text-sm font-semibold text-gray-800">{{ e.name }}</p>
                                    <p class="text-[10px] text-gray-400">{{ e.invoices }} hóa đơn</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold text-emerald-600 font-mono">{{ fmt(e.revenue) }}</p>
                                <!-- Revenue bar -->
                                <div class="w-24 h-1.5 bg-gray-100 rounded-full mt-1">
                                    <div class="h-full rounded-full bg-gradient-to-r from-emerald-400 to-emerald-600" :style="{width: Math.min(100, (e.revenue / ((topEmployees || [])[0]?.revenue || 1)) * 100) + '%'}"></div>
                                </div>
                            </div>
                        </div>
                        <div v-if="!topEmployees?.length" class="px-5 py-8 text-center text-gray-400 text-sm">Chưa có dữ liệu</div>
                    </div>
                </div>
            </div>

            <!-- ═══ BẢNG TỒN KHO ═══ -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
                    <div>
                        <h2 class="font-bold text-gray-800 text-[15px]">📦 Tồn kho sản phẩm</h2>
                        <p class="text-xs text-gray-400">Giá trị tồn kho: <span class="font-semibold text-gray-600">{{ fmt(totalStockValue) }}</span></p>
                    </div>
                    <div class="flex gap-1 bg-gray-100 rounded-lg p-0.5">
                        <button @click="inventoryFilter = 'all'" :class="inventoryFilter === 'all' ? 'bg-white shadow text-indigo-700' : 'text-gray-500'" class="px-3 py-1 text-xs font-semibold rounded-md transition">
                            Tất cả
                        </button>
                        <button @click="inventoryFilter = 'low'" :class="inventoryFilter === 'low' ? 'bg-white shadow text-yellow-700' : 'text-gray-500'" class="px-3 py-1 text-xs font-semibold rounded-md transition">
                            ⚠ Sắp hết <span v-if="(inventoryProducts || []).filter(p => p.alert === 'low').length" class="ml-0.5 bg-yellow-500 text-white rounded-full px-1 text-[9px]">{{ (inventoryProducts || []).filter(p => p.alert === 'low').length }}</span>
                        </button>
                        <button @click="inventoryFilter = 'out'" :class="inventoryFilter === 'out' ? 'bg-white shadow text-red-700' : 'text-gray-500'" class="px-3 py-1 text-xs font-semibold rounded-md transition">
                            🚨 Hết hàng <span v-if="outOfStockCount" class="ml-0.5 bg-red-500 text-white rounded-full px-1 text-[9px]">{{ outOfStockCount }}</span>
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto max-h-[400px] overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600 text-xs uppercase tracking-wider sticky top-0">
                            <tr>
                                <th class="px-5 py-2.5 text-left">#</th>
                                <th class="px-3 py-2.5 text-left">Sản phẩm</th>
                                <th class="px-3 py-2.5 text-left">Mã SKU</th>
                                <th class="px-3 py-2.5 text-right">Tồn kho</th>
                                <th class="px-3 py-2.5 text-right">Giá vốn</th>
                                <th class="px-3 py-2.5 text-right">Giá bán</th>
                                <th class="px-5 py-2.5 text-right">Giá trị tồn</th>
                                <th class="px-3 py-2.5 text-center">Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <template v-for="(p, idx) in (inventoryProducts || []).filter(p => inventoryFilter === 'all' || p.alert === inventoryFilter)" :key="p.id">
                                <tr :class="p.alert === 'out' ? 'bg-red-50/50' : p.alert === 'low' ? 'bg-yellow-50/50' : 'hover:bg-gray-50/50'" class="transition">
                                    <td class="px-5 py-2.5 text-gray-400">{{ idx + 1 }}</td>
                                    <td class="px-3 py-2.5 font-medium text-gray-800">{{ p.name }}</td>
                                    <td class="px-3 py-2.5 text-gray-500 text-xs">{{ p.sku }}</td>
                                    <td class="px-3 py-2.5 text-right font-bold" :class="p.alert === 'out' ? 'text-red-600' : p.alert === 'low' ? 'text-yellow-600' : 'text-gray-800'">{{ p.stock }}</td>
                                    <td class="px-3 py-2.5 text-right text-gray-600 font-mono text-xs">{{ fmt(p.cost_price) }}</td>
                                    <td class="px-3 py-2.5 text-right text-gray-600 font-mono text-xs">{{ fmt(p.selling_price) }}</td>
                                    <td class="px-5 py-2.5 text-right font-mono text-xs font-semibold text-gray-700">{{ fmt(p.stock_value) }}</td>
                                    <td class="px-3 py-2.5 text-center">
                                        <span v-if="p.alert === 'out'" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-100 text-red-700">Hết hàng</span>
                                        <span v-else-if="p.alert === 'low'" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-yellow-100 text-yellow-700">Sắp hết</span>
                                        <span v-else class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-green-100 text-green-700">Còn hàng</span>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!(inventoryProducts || []).filter(p => inventoryFilter === 'all' || p.alert === inventoryFilter).length">
                                <td colspan="8" class="px-5 py-8 text-center text-gray-400">Không có sản phẩm nào</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Step 24.1 — KIỂM SOÁT VẬN HÀNH -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-5 mt-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-base font-bold text-gray-800">🛡 Kiểm soát vận hành</h2>
                    <div class="text-xs text-gray-400">Read-only — số liệu cập nhật mỗi lần tải dashboard</div>
                </div>

                <!-- Card cảnh báo -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
                    <Link href="/products" class="block border rounded-lg p-3 hover:shadow-md transition"
                        :class="(serialControl.in_transit_count || 0) > 0 ? 'border-yellow-300 bg-yellow-50' : 'border-gray-200'">
                        <div class="text-[11px] uppercase font-semibold text-gray-500">Serial đang chuyển kho</div>
                        <div class="text-2xl font-bold mt-1" :class="(serialControl.in_transit_count || 0) > 0 ? 'text-yellow-700' : 'text-gray-400'">
                            {{ fmt(serialControl.in_transit_count || 0) }}
                        </div>
                        <div class="text-[11px] text-gray-400 mt-0.5">in_transit</div>
                    </Link>
                    <Link href="/tasks" class="block border rounded-lg p-3 hover:shadow-md transition"
                        :class="(serialControl.used_for_repair_count || 0) > 0 ? 'border-blue-300 bg-blue-50' : 'border-gray-200'">
                        <div class="text-[11px] uppercase font-semibold text-gray-500">Serial đang dùng sửa chữa</div>
                        <div class="text-2xl font-bold mt-1" :class="(serialControl.used_for_repair_count || 0) > 0 ? 'text-blue-700' : 'text-gray-400'">
                            {{ fmt(serialControl.used_for_repair_count || 0) }}
                        </div>
                        <div class="text-[11px] text-gray-400 mt-0.5">used_for_repair</div>
                    </Link>
                    <Link href="/tasks" class="block border rounded-lg p-3 hover:shadow-md transition"
                        :class="(serialControl.dismantled_count || 0) > 0 ? 'border-orange-300 bg-orange-50' : 'border-gray-200'">
                        <div class="text-[11px] uppercase font-semibold text-gray-500">Serial đã bóc tách</div>
                        <div class="text-2xl font-bold mt-1" :class="(serialControl.dismantled_count || 0) > 0 ? 'text-orange-700' : 'text-gray-400'">
                            {{ fmt(serialControl.dismantled_count || 0) }}
                        </div>
                        <div class="text-[11px] text-gray-400 mt-0.5">dismantled</div>
                    </Link>
                    <Link href="/stock-transfers" class="block border rounded-lg p-3 hover:shadow-md transition"
                        :class="(stockTransferControl.transferring_over_24h_count || 0) > 0 ? 'border-red-300 bg-red-50' : (stockTransferControl.transferring_count || 0) > 0 ? 'border-yellow-300 bg-yellow-50' : 'border-gray-200'">
                        <div class="text-[11px] uppercase font-semibold text-gray-500">Chuyển kho chờ nhận</div>
                        <div class="text-2xl font-bold mt-1"
                            :class="(stockTransferControl.transferring_over_24h_count || 0) > 0 ? 'text-red-700' : (stockTransferControl.transferring_count || 0) > 0 ? 'text-yellow-700' : 'text-gray-400'">
                            {{ fmt(stockTransferControl.transferring_count || 0) }}
                        </div>
                        <div class="text-[11px] text-gray-400 mt-0.5">
                            {{ stockTransferControl.transferring_over_24h_count || 0 }} >24h ·
                            {{ stockTransferControl.transferring_over_72h_count || 0 }} >72h
                        </div>
                    </Link>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
                    <Link href="/tasks" class="block border rounded-lg p-3 hover:shadow-md transition"
                        :class="((repairControl.external_open_count || 0) + (repairControl.internal_open_count || 0)) > 0 ? 'border-blue-300 bg-blue-50' : 'border-gray-200'">
                        <div class="text-[11px] uppercase font-semibold text-gray-500">Repair đang mở</div>
                        <div class="text-2xl font-bold mt-1 text-blue-700">
                            {{ fmt((repairControl.external_open_count || 0) + (repairControl.internal_open_count || 0)) }}
                        </div>
                        <div class="text-[11px] text-gray-400 mt-0.5">
                            ngoài: {{ repairControl.external_open_count || 0 }} · nội bộ: {{ repairControl.internal_open_count || 0 }}
                        </div>
                    </Link>
                    <div class="block border border-orange-300 bg-orange-50 rounded-lg p-3">
                        <div class="text-[11px] uppercase font-semibold text-gray-500">Công nợ sửa chữa</div>
                        <div class="text-xl font-bold mt-1 text-orange-700">{{ fmt(repairControl.repair_debt_total || 0) }}</div>
                        <div class="text-[11px] text-gray-400 mt-0.5">External tasks chưa thanh toán đủ</div>
                    </div>
                    <Link href="/warranties" class="block border rounded-lg p-3 hover:shadow-md transition"
                        :class="(warrantyControl.expiring_7_days_count || 0) > 0 ? 'border-red-300 bg-red-50' : (warrantyControl.expiring_30_days_count || 0) > 0 ? 'border-yellow-300 bg-yellow-50' : 'border-gray-200'">
                        <div class="text-[11px] uppercase font-semibold text-gray-500">Bảo hành sắp hết hạn</div>
                        <div class="text-2xl font-bold mt-1"
                            :class="(warrantyControl.expiring_7_days_count || 0) > 0 ? 'text-red-700' : 'text-yellow-700'">
                            {{ fmt(warrantyControl.expiring_30_days_count || 0) }}
                        </div>
                        <div class="text-[11px] text-gray-400 mt-0.5">
                            {{ warrantyControl.expiring_7_days_count || 0 }} trong 7 ngày · {{ warrantyControl.expired_count || 0 }} đã hết hạn
                        </div>
                    </Link>
                    <Link href="/products" class="block border rounded-lg p-3 hover:shadow-md transition"
                        :class="(inventoryRisk.serial_stock_mismatch_count || 0) > 0 || (inventoryRisk.negative_stock_count || 0) > 0 ? 'border-red-300 bg-red-50' : 'border-gray-200'">
                        <div class="text-[11px] uppercase font-semibold text-gray-500">Sản phẩm rủi ro tồn</div>
                        <div class="text-2xl font-bold mt-1"
                            :class="(inventoryRisk.serial_stock_mismatch_count || 0) > 0 || (inventoryRisk.negative_stock_count || 0) > 0 ? 'text-red-700' : 'text-gray-400'">
                            {{ fmt((inventoryRisk.serial_stock_mismatch_count || 0) + (inventoryRisk.negative_stock_count || 0)) }}
                        </div>
                        <div class="text-[11px] text-gray-400 mt-0.5">
                            {{ inventoryRisk.serial_stock_mismatch_count || 0 }} lệch serial · {{ inventoryRisk.negative_stock_count || 0 }} tồn âm
                        </div>
                    </Link>
                </div>

                <!-- Danh sách ngắn -->
                <div class="grid md:grid-cols-2 gap-4">
                    <!-- Transferring -->
                    <div class="border border-gray-200 rounded-lg">
                        <div class="px-4 py-2 border-b font-semibold text-sm text-gray-700 bg-gray-50">
                            Chuyển kho đang chờ nhận
                            <Link href="/stock-transfers" class="text-xs text-blue-600 hover:underline ml-1">→ Xem</Link>
                        </div>
                        <div v-if="!(stockTransferControl.latest_transferring_transfers || []).length"
                            class="p-4 text-sm text-gray-400 text-center">Không có cảnh báo</div>
                        <table v-else class="w-full text-sm">
                            <thead class="bg-gray-50 text-gray-500 text-[11px] uppercase">
                                <tr>
                                    <th class="px-3 py-2 text-left">Mã</th>
                                    <th class="px-3 py-2 text-left">Từ → Đến</th>
                                    <th class="px-3 py-2 text-right">SL</th>
                                    <th class="px-3 py-2 text-right">Tuổi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="t in stockTransferControl.latest_transferring_transfers" :key="t.id" class="border-t hover:bg-gray-50">
                                    <td class="px-3 py-2 font-mono text-blue-600">{{ t.code }}</td>
                                    <td class="px-3 py-2 text-xs">{{ t.from_branch }} → {{ t.to_branch }}</td>
                                    <td class="px-3 py-2 text-right">{{ fmt(t.total_quantity) }}</td>
                                    <td class="px-3 py-2 text-right text-xs"
                                        :class="(t.age_hours || 0) >= 72 ? 'text-red-600 font-semibold' : (t.age_hours || 0) >= 24 ? 'text-yellow-600' : 'text-gray-500'">
                                        {{ t.age_hours !== null ? t.age_hours + 'h' : '-' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Open repairs -->
                    <div class="border border-gray-200 rounded-lg">
                        <div class="px-4 py-2 border-b font-semibold text-sm text-gray-700 bg-gray-50">
                            Phiếu sửa chữa đang mở
                            <Link href="/tasks" class="text-xs text-blue-600 hover:underline ml-1">→ Xem</Link>
                        </div>
                        <div v-if="!(repairControl.latest_open_repairs || []).length"
                            class="p-4 text-sm text-gray-400 text-center">Không có cảnh báo</div>
                        <table v-else class="w-full text-sm">
                            <thead class="bg-gray-50 text-gray-500 text-[11px] uppercase">
                                <tr>
                                    <th class="px-3 py-2 text-left">Mã</th>
                                    <th class="px-3 py-2 text-left">Khách</th>
                                    <th class="px-3 py-2 text-center">Loại</th>
                                    <th class="px-3 py-2 text-center">Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in repairControl.latest_open_repairs" :key="r.id" class="border-t hover:bg-gray-50">
                                    <td class="px-3 py-2 font-mono text-blue-600">
                                        <Link :href="`/tasks/${r.id}`">{{ r.code }}</Link>
                                    </td>
                                    <td class="px-3 py-2 text-xs">{{ r.customer_name || '-' }}</td>
                                    <td class="px-3 py-2 text-center">
                                        <span class="text-[11px] px-1.5 py-0.5 rounded"
                                            :class="r.external ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600'">
                                            {{ r.external ? 'Khách ngoài' : 'Nội bộ' }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-center text-xs">{{ r.status }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Expiring warranties -->
                    <div class="border border-gray-200 rounded-lg">
                        <div class="px-4 py-2 border-b font-semibold text-sm text-gray-700 bg-gray-50">
                            Bảo hành sắp hết hạn (≤30 ngày)
                            <Link href="/warranties" class="text-xs text-blue-600 hover:underline ml-1">→ Xem</Link>
                        </div>
                        <div v-if="!(warrantyControl.latest_expiring_warranties || []).length"
                            class="p-4 text-sm text-gray-400 text-center">Không có cảnh báo</div>
                        <table v-else class="w-full text-sm">
                            <thead class="bg-gray-50 text-gray-500 text-[11px] uppercase">
                                <tr>
                                    <th class="px-3 py-2 text-left">HĐ</th>
                                    <th class="px-3 py-2 text-left">Sản phẩm</th>
                                    <th class="px-3 py-2 text-left">Serial</th>
                                    <th class="px-3 py-2 text-right">Hết hạn</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="w in warrantyControl.latest_expiring_warranties" :key="w.id" class="border-t hover:bg-gray-50">
                                    <td class="px-3 py-2 font-mono text-xs">{{ w.invoice_code }}</td>
                                    <td class="px-3 py-2 text-xs">{{ w.product_name || '-' }}</td>
                                    <td class="px-3 py-2 text-xs font-mono">{{ w.serial_imei || '-' }}</td>
                                    <td class="px-3 py-2 text-right text-xs">
                                        {{ w.warranty_end_date ? new Date(w.warranty_end_date).toLocaleDateString('vi-VN') : '-' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Serial stock mismatch -->
                    <div class="border border-gray-200 rounded-lg">
                        <div class="px-4 py-2 border-b font-semibold text-sm text-gray-700 bg-gray-50">
                            Sản phẩm lệch serial / tồn
                            <Link href="/products" class="text-xs text-blue-600 hover:underline ml-1">→ Xem</Link>
                        </div>
                        <div v-if="!(inventoryRisk.serial_mismatch_products || []).length"
                            class="p-4 text-sm text-gray-400 text-center">Không có cảnh báo</div>
                        <table v-else class="w-full text-sm">
                            <thead class="bg-gray-50 text-gray-500 text-[11px] uppercase">
                                <tr>
                                    <th class="px-3 py-2 text-left">SKU</th>
                                    <th class="px-3 py-2 text-left">Tên</th>
                                    <th class="px-3 py-2 text-right">Tồn</th>
                                    <th class="px-3 py-2 text-right">Serial in_stock</th>
                                    <th class="px-3 py-2 text-right">Lệch</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in inventoryRisk.serial_mismatch_products" :key="p.id" class="border-t hover:bg-gray-50">
                                    <td class="px-3 py-2 text-xs font-mono">{{ p.sku }}</td>
                                    <td class="px-3 py-2 text-xs">{{ p.name }}</td>
                                    <td class="px-3 py-2 text-right">{{ fmt(p.stock_quantity) }}</td>
                                    <td class="px-3 py-2 text-right">{{ fmt(p.serial_count) }}</td>
                                    <td class="px-3 py-2 text-right font-semibold"
                                        :class="p.diff > 0 ? 'text-red-600' : 'text-orange-600'">
                                        {{ p.diff > 0 ? '+' : '' }}{{ p.diff }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- High-risk activities -->
                <div v-if="canViewAuditLog && highRiskActivities && highRiskActivities.visible" class="mt-5 border border-red-200 rounded-lg bg-red-50/30">
                    <div class="px-4 py-2 border-b border-red-200 font-semibold text-sm text-red-700 bg-red-50 flex items-center justify-between">
                        <span>⚠ Thao tác rủi ro cao gần đây</span>
                        <Link href="/activity-logs" class="text-xs text-red-600 hover:underline">→ Xem nhật ký đầy đủ</Link>
                    </div>
                    <div class="px-4 py-2 text-xs text-gray-600 border-b border-red-100">
                        Hôm nay: <strong class="text-red-600">{{ highRiskActivities.count_today || 0 }}</strong>
                        · 7 ngày: <strong class="text-red-600">{{ highRiskActivities.count_7_days || 0 }}</strong>
                    </div>
                    <div v-if="!(highRiskActivities.latest_logs || []).length" class="p-4 text-sm text-gray-400 text-center">Không có hoạt động rủi ro</div>
                    <table v-else class="w-full text-sm">
                        <thead class="bg-red-50 text-gray-600 text-[11px] uppercase">
                            <tr>
                                <th class="px-3 py-2 text-left">Thời gian</th>
                                <th class="px-3 py-2 text-left">Hành động</th>
                                <th class="px-3 py-2 text-left">Mô tả</th>
                                <th class="px-3 py-2 text-left">Người dùng</th>
                                <th class="px-3 py-2 text-left">IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="log in highRiskActivities.latest_logs" :key="log.id" class="border-t border-red-100 hover:bg-red-50/50">
                                <td class="px-3 py-2 text-xs whitespace-nowrap">
                                    {{ log.created_at ? new Date(log.created_at).toLocaleString('vi-VN') : '-' }}
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <span class="mr-1">{{ log.action_icon }}</span>{{ log.action_label }}
                                </td>
                                <td class="px-3 py-2 text-xs">{{ log.description }}</td>
                                <td class="px-3 py-2 text-xs">{{ log.user_name || '-' }}</td>
                                <td class="px-3 py-2 text-xs font-mono text-gray-500">{{ log.ip_address || '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else-if="highRiskActivities && !highRiskActivities.visible" class="mt-5 border border-gray-200 rounded-lg bg-gray-50 px-4 py-3 text-xs text-gray-500">
                    Có {{ highRiskActivities.count_today || 0 }} thao tác rủi ro cao hôm nay ({{ highRiskActivities.count_7_days || 0 }} trong 7 ngày).
                    Cần quyền <code class="bg-gray-200 px-1 rounded">system.audit.view</code> để xem chi tiết.
                </div>
            </div>

        </div>
    </AppLayout>
</template>
