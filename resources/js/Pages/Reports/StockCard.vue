<script setup>
import { formatVND as fmt } from '@/utils/money';
import { ref, computed } from "vue";
import { router, Link } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ReportSidebar from "@/Components/ReportSidebar.vue";

const props = defineProps({
    movements: Object, // paginator
    stats: Object,
    product: Object,
    products: Array,
    filters: Object,
});

const productId = ref(props.filters?.product_id || "");
const dateFrom = ref(props.filters?.date_from || "");
const dateTo = ref(props.filters?.date_to || "");
const type = ref(props.filters?.type || "");
const search = ref("");

const apply = () => {
    router.get(
        "/reports/stock-card",
        {
            product_id: productId.value || undefined,
            date_from: dateFrom.value || undefined,
            date_to: dateTo.value || undefined,
            type: type.value || undefined,
        },
        { preserveState: true, preserveScroll: true }
    );
};


const fmtDate = (s) =>
    s ? new Date(s).toLocaleString("vi-VN") : "";

const goPage = (url) => {
    if (url) router.get(url, {}, { preserveState: true, preserveScroll: true });
};

const typeLabels = {
    in_purchase: "Nhập hàng",
    out_invoice: "Xuất bán",
    in_invoice_return: "Khách trả",
    out_purchase_return: "Trả NCC",
    adjust_in: "Kiểm kê +",
    adjust_out: "Kiểm kê -",
    transfer_in: "Chuyển kho +",
    transfer_out: "Chuyển kho -",
    repair_in: "Sửa chữa nhập",
    repair_out: "Sửa chữa xuất",
};

const typeClass = (t) => {
    const inTypes = [
        "in_purchase",
        "in_invoice_return",
        "adjust_in",
        "transfer_in",
        "repair_in",
    ];
    return inTypes.includes(t)
        ? "bg-green-100 text-green-700"
        : "bg-red-100 text-red-700";
};

const refLink = (m) => {
    if (!m.ref_type) return null;
    const map = {
        "App\\Models\\Purchase": "/purchases/",
        "App\\Models\\Invoice": "/invoices/",
        "App\\Models\\OrderReturn": "/returns/",
        "App\\Models\\PurchaseReturn": "/purchase-returns/",
    };
    const base = map[m.ref_type];
    return base && m.ref_id ? `${base}${m.ref_id}` : null;
};

const filteredProducts = computed(() => {
    if (!search.value) return props.products?.slice(0, 50) || [];
    const s = search.value.toLowerCase();
    return (props.products || [])
        .filter(
            (p) =>
                p.name.toLowerCase().includes(s) ||
                p.sku.toLowerCase().includes(s)
        )
        .slice(0, 50);
});
</script>

<template>
    <AppLayout>
        <template #sidebar>
            <ReportSidebar currentPage="stock-card" />
        </template>

        <div class="max-w-[1400px] mx-auto">
            <div class="mb-5">
                <h1 class="text-xl font-bold text-gray-800">Thẻ kho</h1>
                <p class="text-xs text-gray-500 mt-1">
                    Sổ cái tồn kho theo từng SKU. Mỗi dòng là một dịch chuyển.
                </p>
            </div>

            <!-- Filters -->
            <div
                class="bg-white rounded-lg border border-gray-200 p-4 mb-5 flex flex-wrap items-end gap-3"
            >
                <div class="min-w-[260px]">
                    <label class="block text-xs text-gray-500 mb-1"
                        >Sản phẩm</label
                    >
                    <select
                        v-model="productId"
                        @change="apply"
                        class="border border-gray-300 rounded px-3 py-1.5 text-sm w-full"
                    >
                        <option value="">— Chọn sản phẩm —</option>
                        <option
                            v-for="p in filteredProducts"
                            :key="p.id"
                            :value="p.id"
                        >
                            {{ p.sku }} — {{ p.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Loại</label>
                    <select
                        v-model="type"
                        @change="apply"
                        class="border border-gray-300 rounded px-3 py-1.5 text-sm w-44"
                    >
                        <option value="">Tất cả</option>
                        <option
                            v-for="(label, key) in typeLabels"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Từ ngày</label>
                    <input
                        type="date"
                        v-model="dateFrom"
                        @change="apply"
                        class="border border-gray-300 rounded px-3 py-1.5 text-sm w-40"
                    />
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Đến ngày</label>
                    <input
                        type="date"
                        v-model="dateTo"
                        @change="apply"
                        class="border border-gray-300 rounded px-3 py-1.5 text-sm w-40"
                    />
                </div>
            </div>

            <!-- Product info card -->
            <div
                v-if="product"
                class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-5"
            >
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <div class="font-semibold text-gray-800">
                            {{ product.name }}
                            <span class="text-xs text-gray-500 ml-2 font-normal"
                                >({{ product.sku }})</span
                            >
                        </div>
                        <div class="text-xs text-gray-600 mt-1">
                            Tồn hiện tại:
                            <strong>{{ fmt(product.stock_quantity) }}</strong>
                            — Giá vốn:
                            <strong>{{ fmt(product.cost_price) }}</strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
                <div class="bg-white rounded-lg border border-green-200 p-4">
                    <div class="text-xs text-green-600 mb-1">Tổng nhập (qty)</div>
                    <div class="text-xl font-bold text-green-700">
                        {{ fmt(stats?.total_in_qty) }}
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-red-200 p-4">
                    <div class="text-xs text-red-600 mb-1">Tổng xuất (qty)</div>
                    <div class="text-xl font-bold text-red-700">
                        {{ fmt(stats?.total_out_qty) }}
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <div class="text-xs text-gray-500 mb-1">Tổng giá trị nhập</div>
                    <div class="text-lg font-semibold text-gray-800">
                        {{ fmt(stats?.total_in_value) }}
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <div class="text-xs text-gray-500 mb-1">Tổng giá trị xuất</div>
                    <div class="text-lg font-semibold text-gray-800">
                        {{ fmt(stats?.total_out_value) }}
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div
                class="bg-white rounded-lg border border-gray-200 overflow-hidden"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr class="text-xs text-gray-600 uppercase">
                                <th class="px-3 py-2 text-left">Thời gian</th>
                                <th class="px-3 py-2 text-left">Loại</th>
                                <th class="px-3 py-2 text-left">Chứng từ</th>
                                <th class="px-3 py-2 text-left">Serial</th>
                                <th class="px-3 py-2 text-right">SL</th>
                                <th class="px-3 py-2 text-right">Đơn giá vốn</th>
                                <th class="px-3 py-2 text-right">Giá trị</th>
                                <th class="px-3 py-2 text-right">Tồn sau</th>
                                <th class="px-3 py-2 text-right">GV BQ sau</th>
                                <th class="px-3 py-2 text-left">Người TH</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="!movements?.data?.length">
                                <td
                                    colspan="10"
                                    class="px-3 py-8 text-center text-gray-400"
                                >
                                    Không có dữ liệu
                                </td>
                            </tr>
                            <tr
                                v-for="m in movements?.data || []"
                                :key="m.id"
                                class="hover:bg-gray-50"
                            >
                                <td class="px-3 py-2 text-xs text-gray-600 whitespace-nowrap">
                                    {{ fmtDate(m.moved_at) }}
                                </td>
                                <td class="px-3 py-2">
                                    <span
                                        class="text-xs px-2 py-0.5 rounded font-semibold"
                                        :class="typeClass(m.type)"
                                    >
                                        {{ typeLabels[m.type] || m.type }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <Link
                                        v-if="refLink(m)"
                                        :href="refLink(m)"
                                        class="text-blue-600 hover:underline"
                                    >
                                        {{ m.ref_code || "—" }}
                                    </Link>
                                    <span v-else>{{ m.ref_code || "—" }}</span>
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">
                                    {{ m.serial_imei?.serial_number || "—" }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right font-semibold"
                                    :class="
                                        m.direction === 'in'
                                            ? 'text-green-700'
                                            : 'text-red-700'
                                    "
                                >
                                    {{ m.direction === "in" ? "+" : "-"
                                    }}{{ fmt(m.qty) }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ fmt(m.unit_cost) }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ fmt(m.total_cost) }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ fmt(m.balance_qty) }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ fmt(m.balance_cost) }}
                                </td>
                                <td class="px-3 py-2 text-xs text-gray-600">
                                    {{
                                        m.employee?.name ||
                                        m.user?.name ||
                                        "—"
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div
                    v-if="movements?.last_page > 1"
                    class="flex items-center justify-between px-4 py-3 border-t border-gray-200 bg-gray-50"
                >
                    <div class="text-xs text-gray-600">
                        Trang {{ movements.current_page }} /
                        {{ movements.last_page }} — Tổng
                        {{ movements.total }} dịch chuyển
                    </div>
                    <div class="flex gap-1">
                        <button
                            v-for="link in movements.links"
                            :key="link.label"
                            :disabled="!link.url"
                            @click="goPage(link.url)"
                            class="px-3 py-1 text-sm rounded border"
                            :class="
                                link.active
                                    ? 'bg-blue-600 text-white border-blue-600'
                                    : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-100'
                            "
                            v-html="link.label"
                        ></button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
