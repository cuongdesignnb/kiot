<script setup>
import { formatVND as fmt } from '@/utils/money';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ returnOrder: Object });

const statusLabels = {
    completed: 'Hoàn thành',
    cancelled: 'Đã hủy',
    pending: 'Chờ xử lý',
};
const statusColors = {
    completed: 'bg-green-100 text-green-700',
    cancelled: 'bg-red-100 text-red-700',
    pending: 'bg-yellow-100 text-yellow-700',
};

const cancelReturn = () => {
    if (!confirm('Bạn chắc chắn muốn hủy phiếu trả hàng này? Hệ thống sẽ rollback tồn kho, công nợ và serial đã trả.')) return;
    router.post(`/returns/${props.returnOrder.id}/cancel`, {}, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="`Trả hàng ${returnOrder.code}`" />
    <AppLayout>
        <div class="max-w-4xl mx-auto py-6 px-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <Link href="/returns" class="text-blue-600 hover:underline text-sm">&larr; Danh sách trả hàng</Link>
                    <h1 class="text-2xl font-bold text-gray-800 mt-1">{{ returnOrder.code }}</h1>
                </div>
                <div class="flex items-center gap-3">
                    <span :class="statusColors[returnOrder.status] || 'bg-gray-100 text-gray-700'" class="px-3 py-1 rounded-full text-sm font-semibold">
                        {{ statusLabels[returnOrder.status] || returnOrder.status }}
                    </span>
                    <button
                        v-if="returnOrder.status !== 'Đã hủy' && returnOrder.status !== 'cancelled'"
                        @click="cancelReturn"
                        class="bg-white border border-red-300 text-red-600 rounded px-3 py-1.5 text-sm font-semibold hover:bg-red-50"
                    >
                        Hủy phiếu trả hàng
                    </button>
                    <a :href="`/returns/${returnOrder.id}/print`" target="_blank" class="bg-white border border-gray-300 rounded px-3 py-1.5 text-sm font-semibold hover:bg-gray-50">
                        🖨 In
                    </a>
                </div>
            </div>

            <!-- Info grid -->
            <div class="bg-white rounded-lg border border-gray-200 p-5 mb-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <div class="text-gray-500">Ngày tạo</div>
                        <div class="font-semibold">{{ returnOrder.created_at }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Người tạo</div>
                        <div class="font-semibold">{{ returnOrder.created_by_name }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Khách hàng</div>
                        <div class="font-semibold">
                            <Link v-if="returnOrder.customer" :href="`/customers?search=${returnOrder.customer.code}`" class="text-blue-600 hover:underline">
                                {{ returnOrder.customer.name }} ({{ returnOrder.customer.code }})
                            </Link>
                            <span v-else>Khách lẻ</span>
                        </div>
                    </div>
                    <div v-if="returnOrder.invoice_code">
                        <div class="text-gray-500">Hóa đơn gốc</div>
                        <div class="font-semibold">
                            <Link :href="`/invoices/${returnOrder.invoice_id}/show`" class="text-blue-600 hover:underline">
                                {{ returnOrder.invoice_code }}
                            </Link>
                        </div>
                    </div>
                </div>
                <div v-if="returnOrder.note" class="mt-3 pt-3 border-t text-sm">
                    <span class="text-gray-500">Ghi chú:</span> {{ returnOrder.note }}
                </div>
            </div>

            <!-- Items table -->
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-6">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 text-xs uppercase">
                        <tr>
                            <th class="px-4 py-3 text-left">Mã hàng</th>
                            <th class="px-4 py-3 text-left">Tên hàng</th>
                            <th class="px-4 py-3 text-right">SL</th>
                            <th class="px-4 py-3 text-right">Đơn giá</th>
                            <th class="px-4 py-3 text-right">Giảm giá</th>
                            <th class="px-4 py-3 text-right">Thành tiền</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(item, idx) in returnOrder.items" :key="idx" class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-blue-600 font-semibold">{{ item.product_code }}</td>
                            <td class="px-4 py-3">
                                <div>{{ item.product_name }}</div>
                                <div v-if="item.returned_serials && item.returned_serials.length" class="mt-1 flex flex-wrap gap-1">
                                    <span class="text-gray-500 text-xs mr-1">Serial/IMEI đã trả:</span>
                                    <span
                                        v-for="s in item.returned_serials"
                                        :key="s.id"
                                        class="text-[11px] bg-blue-50 text-blue-700 border border-blue-100 px-1.5 py-0.5 rounded"
                                    >{{ s.serial_number || ('#' + s.id) }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right">{{ item.quantity }}</td>
                            <td class="px-4 py-3 text-right">{{ fmt(item.price) }}</td>
                            <td class="px-4 py-3 text-right text-red-500">{{ item.discount ? fmt(item.discount) : '---' }}</td>
                            <td class="px-4 py-3 text-right font-semibold">{{ fmt(item.subtotal) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Totals -->
            <div class="bg-white rounded-lg border border-gray-200 p-5">
                <div class="space-y-2 text-sm max-w-xs ml-auto">
                    <div class="flex justify-between"><span class="text-gray-500">Tổng tiền hàng</span><span class="font-semibold">{{ fmt(returnOrder.subtotal) }}</span></div>
                    <div v-if="returnOrder.discount" class="flex justify-between"><span class="text-gray-500">Giảm giá</span><span class="font-semibold text-red-500">-{{ fmt(returnOrder.discount) }}</span></div>
                    <div v-if="returnOrder.fee" class="flex justify-between"><span class="text-gray-500">Phí trả hàng</span><span class="font-semibold">{{ fmt(returnOrder.fee) }}</span></div>
                    <div class="flex justify-between border-t pt-2 text-base"><span class="font-bold">Cần trả khách</span><span class="font-bold text-orange-600">{{ fmt(returnOrder.total) }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">Đã trả khách</span><span class="font-semibold text-green-600">{{ fmt(returnOrder.paid_to_customer) }}</span></div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
