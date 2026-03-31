<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';

const page = usePage();
const props = defineProps({
    purchaseReturn: Object,
});

const ret = props.purchaseReturn;

const formatCurrency = (val) => Number(val || 0).toLocaleString('vi-VN');
const formatStatus = (status) => {
    const map = { completed: 'Đã trả hàng', draft: 'Phiếu tạm', cancelled: 'Đã hủy' };
    return map[status] || status;
};
const statusClass = (status) => ({
    'bg-green-50 text-green-700 border-green-200': status === 'completed',
    'bg-gray-50 text-gray-500 border-gray-200': status === 'draft',
    'bg-red-50 text-red-600 border-red-200': status === 'cancelled',
});

const cancelReturn = () => {
    if (!confirm(`Bạn có chắc muốn hủy phiếu trả hàng ${ret.code}? Tồn kho và công nợ sẽ được hoàn lại.`)) return;
    router.delete(`/purchase-returns/${ret.id}`);
};
</script>

<template>
    <Head :title="'Trả hàng nhập - ' + ret.code" />
    <div class="h-screen flex flex-col bg-[#eef1f5] text-[13px] overflow-hidden font-sans">
        <!-- Flash Messages -->
        <div v-if="page.props.flash?.success" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 text-sm">{{ page.props.flash.success }}</div>
        <div v-if="page.props.flash?.error" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 text-sm">{{ page.props.flash.error }}</div>

        <!-- Header -->
        <header class="bg-white text-gray-800 px-4 h-[56px] flex items-center justify-between border-b border-gray-200 shadow-sm flex-shrink-0">
            <div class="flex items-center gap-4">
                <Link href="/purchase-returns" class="text-gray-600 hover:text-orange-600 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                </Link>
                <div>
                    <div class="flex items-center gap-3">
                        <span class="text-xl font-bold text-gray-800">{{ ret.code }}</span>
                        <span class="inline-block px-2 text-[11px] py-0.5 rounded border font-medium" :class="statusClass(ret.status)">
                            {{ formatStatus(ret.status) }}
                        </span>
                    </div>
                    <div class="text-xs text-gray-500">
                        Phiếu nhập: <Link v-if="ret.purchase" :href="'/purchases/' + ret.purchase.id" class="text-blue-600 font-medium hover:underline">{{ ret.purchase.code }}</Link>
                        - NCC: <span class="font-medium">{{ ret.supplier?.name }}</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-auto p-6">
            <div class="max-w-5xl mx-auto">
                <!-- Meta info -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5 mb-4">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-[13px]">
                        <div>
                            <div class="text-gray-400 mb-1">Người tạo</div>
                            <div class="font-medium">{{ ret.user?.name || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-gray-400 mb-1">Nhân viên trả</div>
                            <div class="font-medium">{{ ret.employee?.name || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-gray-400 mb-1">Nhà cung cấp</div>
                            <div class="font-medium text-blue-600">{{ ret.supplier?.name || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-gray-400 mb-1">Ngày trả</div>
                            <div class="font-medium">{{ new Date(ret.return_date || ret.created_at).toLocaleString('vi-VN') }}</div>
                        </div>
                        <div>
                            <div class="text-gray-400 mb-1">Phương thức</div>
                            <div class="font-medium">{{ ret.payment_method === 'transfer' ? 'Chuyển khoản' : 'Tiền mặt' }}</div>
                        </div>
                        <div v-if="ret.bank_account_info">
                            <div class="text-gray-400 mb-1">Tài khoản NH</div>
                            <div class="font-medium">{{ ret.bank_account_info }}</div>
                        </div>
                    </div>
                </div>

                <!-- Items -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-4">
                    <div class="px-5 py-3 border-b border-gray-200 font-bold text-gray-700 bg-gray-50 rounded-t-lg">
                        Danh sách hàng trả
                    </div>
                    <table class="w-full text-left text-[13px]">
                        <thead class="bg-gray-50 border-b border-gray-200 text-gray-500">
                            <tr>
                                <th class="p-3 w-12 text-center">STT</th>
                                <th class="p-3">Mã hàng</th>
                                <th class="p-3">Tên hàng</th>
                                <th class="p-3 text-center">Số lượng</th>
                                <th class="p-3 text-right">Đơn giá</th>
                                <th class="p-3 text-right pr-5">Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(item, i) in ret.items" :key="item.id" class="hover:bg-gray-50/50">
                                <td class="p-3 text-center text-gray-400">{{ i + 1 }}</td>
                                <td class="p-3 text-blue-600 font-medium">{{ item.product_code }}</td>
                                <td class="p-3 font-medium">{{ item.product_name }}</td>
                                <td class="p-3 text-center font-bold">{{ item.quantity }}</td>
                                <td class="p-3 text-right">{{ formatCurrency(item.price) }}</td>
                                <td class="p-3 text-right pr-5 font-bold">{{ formatCurrency(item.subtotal) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Summary -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5 mb-4">
                    <div class="flex justify-end">
                        <div class="w-80 space-y-2 text-[13.5px]">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Số lượng mặt hàng</span>
                                <span class="font-bold text-blue-600">{{ ret.items?.length || 0 }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Tổng tiền hàng</span>
                                <span class="font-bold">{{ formatCurrency(ret.total_amount) }}</span>
                            </div>
                            <div class="border-t border-gray-200 pt-2 flex justify-between text-[15px]">
                                <span class="font-bold text-gray-800">NCC cần trả</span>
                                <span class="font-bold text-red-600">{{ formatCurrency(ret.refund_amount) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">NCC đã trả</span>
                                <span class="font-bold text-green-600">{{ ret.status === 'completed' ? formatCurrency(ret.refund_amount) : '0' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Note -->
                <div v-if="ret.note" class="bg-white rounded-lg shadow-sm border border-gray-200 p-5 mb-4">
                    <div class="text-gray-400 text-[12px] mb-1">Ghi chú</div>
                    <div class="text-[13px]">{{ ret.note }}</div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="bg-white border-t border-gray-200 px-6 py-3 flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-2">
                <button v-if="ret.status === 'completed'" @click="cancelReturn"
                    class="bg-white border border-gray-300 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded text-sm font-medium flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    Hủy phiếu trả
                </button>
            </div>
            <div class="flex items-center gap-2">
                <Link href="/purchase-returns" class="bg-white border border-gray-300 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded text-sm font-medium">
                    Danh sách
                </Link>
            </div>
        </div>
    </div>
</template>
