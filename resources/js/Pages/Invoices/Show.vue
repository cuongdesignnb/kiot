<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ invoice: Object });
const fmt = (v) => Number(v || 0).toLocaleString('vi-VN');

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
</script>

<template>
    <Head :title="`Hóa đơn ${invoice.code}`" />
    <AppLayout>
        <div class="max-w-4xl mx-auto py-6 px-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <Link href="/invoices" class="text-blue-600 hover:underline text-sm">&larr; Danh sách hóa đơn</Link>
                    <h1 class="text-2xl font-bold text-gray-800 mt-1">{{ invoice.code }}</h1>
                </div>
                <div class="flex items-center gap-3">
                    <span :class="statusColors[invoice.status] || 'bg-gray-100 text-gray-700'" class="px-3 py-1 rounded-full text-sm font-semibold">
                        {{ statusLabels[invoice.status] || invoice.status }}
                    </span>
                    <a :href="`/invoices/${invoice.id}/print`" target="_blank" class="bg-white border border-gray-300 rounded px-3 py-1.5 text-sm font-semibold hover:bg-gray-50">
                        🖨 In
                    </a>
                </div>
            </div>

            <!-- Info grid -->
            <div class="bg-white rounded-lg border border-gray-200 p-5 mb-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <div class="text-gray-500">Ngày tạo</div>
                        <div class="font-semibold">{{ invoice.created_at }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Người tạo</div>
                        <div class="font-semibold">{{ invoice.created_by_name }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Người bán</div>
                        <div class="font-semibold">{{ invoice.seller_name || '---' }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Chi nhánh</div>
                        <div class="font-semibold">{{ invoice.branch_name }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Khách hàng</div>
                        <div class="font-semibold">
                            <Link v-if="invoice.customer" :href="`/customers?search=${invoice.customer.code}`" class="text-blue-600 hover:underline">
                                {{ invoice.customer.name }} ({{ invoice.customer.code }})
                            </Link>
                            <span v-else>Khách lẻ</span>
                        </div>
                    </div>
                    <div>
                        <div class="text-gray-500">Thanh toán</div>
                        <div class="font-semibold">{{ invoice.payment_method || 'Tiền mặt' }}</div>
                    </div>
                    <div v-if="invoice.is_delivery">
                        <div class="text-gray-500">Đối tác vận chuyển</div>
                        <div class="font-semibold">{{ invoice.delivery_partner || '---' }}</div>
                    </div>
                </div>
                <div v-if="invoice.note" class="mt-3 pt-3 border-t text-sm">
                    <span class="text-gray-500">Ghi chú:</span> {{ invoice.note }}
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
                        <tr v-for="(item, idx) in invoice.items" :key="idx" class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-blue-600 font-semibold">{{ item.product_code }}</td>
                            <td class="px-4 py-3">{{ item.product_name }}</td>
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
                    <div class="flex justify-between"><span class="text-gray-500">Tổng tiền hàng</span><span class="font-semibold">{{ fmt(invoice.subtotal) }}</span></div>
                    <div v-if="invoice.discount" class="flex justify-between"><span class="text-gray-500">Giảm giá</span><span class="font-semibold text-red-500">-{{ fmt(invoice.discount) }}</span></div>
                    <div v-if="invoice.delivery_fee" class="flex justify-between"><span class="text-gray-500">Phí giao hàng</span><span class="font-semibold">{{ fmt(invoice.delivery_fee) }}</span></div>
                    <div class="flex justify-between border-t pt-2 text-base"><span class="font-bold">Tổng cộng</span><span class="font-bold text-blue-600">{{ fmt(invoice.total) }}₫</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">Khách đã trả</span><span class="font-semibold text-green-600">{{ fmt(invoice.customer_paid) }}</span></div>
                    <div v-if="invoice.debt_amount > 0" class="flex justify-between"><span class="text-gray-500">Còn nợ</span><span class="font-semibold text-red-600">{{ fmt(invoice.debt_amount) }}</span></div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
