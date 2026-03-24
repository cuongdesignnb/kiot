<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    purchase: Object,
    bankAccounts: Array,
    employees: Array,
});

const activeTab = ref('info');
const formatCurrency = (val) => Number(val || 0).toLocaleString('vi-VN');
const formatDate = (val) => val ? new Date(val).toLocaleString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';

const totalQty = props.purchase.items?.reduce((s, i) => s + (Number(i.quantity) || 0), 0) || 0;
const totalProducts = props.purchase.items?.length || 0;
const calcTotal = props.purchase.items?.reduce((s, i) => s + (Number(i.subtotal) || 0), 0) || 0;
const totalAmount = calcTotal || Number(props.purchase.total_amount) || 0;
const needToPay = totalAmount - (Number(props.purchase.discount) || 0);

const printPurchase = () => {
    window.open(`/purchases/${props.purchase.id}/print`, '_blank', 'width=400,height=600');
};

const cancelPurchase = () => {
    if (!confirm('Bạn có chắc muốn hủy bỏ phiếu nhập hàng này?')) return;
    router.delete(`/purchases/${props.purchase.id}`, {
        onSuccess: () => {},
    });
};



const paymentMethodLabel = (method) => {
    return method === 'transfer' ? 'Chuyển khoản' : 'Tiền mặt';
};
</script>

<template>
    <Head :title="`Chi tiết phiếu nhập ${purchase.code}`" />
    <AppLayout>
        <div class="bg-white h-full flex flex-col">
            <!-- Header -->
            <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-200 bg-gray-50/50">
                <Link href="/purchases" class="text-gray-500 hover:text-gray-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                </Link>
                <h1 class="text-lg font-bold text-gray-800">{{ purchase.code }}</h1>
                <span class="ml-2 inline-block px-2 py-0.5 rounded text-[11px] font-medium border"
                    :class="purchase.status === 'completed' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-yellow-50 text-yellow-700 border-yellow-200'">
                    {{ purchase.status === 'completed' ? 'Đã nhập hàng' : 'Phiếu tạm' }}
                </span>
            </div>

            <!-- Tabs -->
            <div class="flex border-b border-gray-200 px-4">
                <button @click="activeTab = 'info'"
                    class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors"
                    :class="activeTab === 'info' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'">
                    Thông tin
                </button>
                <button @click="activeTab = 'payments'"
                    class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors"
                    :class="activeTab === 'payments' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'">
                    Lịch sử thanh toán
                </button>
            </div>

            <!-- Content -->
            <div class="flex-1 overflow-auto">
                <!-- Info Tab -->
                <div v-if="activeTab === 'info'" class="flex h-full">
                <!-- Left: Main content -->
                <div class="flex-1 p-5 overflow-auto">
                    <!-- Header Info Grid -->
                    <div class="grid grid-cols-3 gap-x-8 gap-y-3 mb-6 text-[13px]">
                        <div class="flex gap-2">
                            <span class="text-gray-500 w-28 flex-shrink-0">Mã nhập hàng:</span>
                            <span class="font-bold text-gray-800">{{ purchase.code }}</span>
                        </div>
                        <div class="flex gap-2">
                            <span class="text-gray-500 w-32 flex-shrink-0">Mã đặt hàng nhập:</span>
                            <span class="text-gray-700">{{ purchase.purchase_order_code || '' }}</span>
                        </div>
                        <div class="row-span-3">
                            <div class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-gray-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                <span class="text-gray-400 italic text-[13px]">{{ purchase.note || 'Ghi chú...' }}</span>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <span class="text-gray-500 w-28 flex-shrink-0">Thời gian:</span>
                            <span class="text-gray-700">{{ formatDate(purchase.purchase_date || purchase.created_at) }}</span>
                        </div>
                        <div class="flex gap-2">
                            <span class="text-gray-500 w-32 flex-shrink-0">Trạng thái:</span>
                            <span class="font-medium" :class="purchase.status === 'completed' ? 'text-green-600' : 'text-yellow-600'">
                                {{ purchase.status === 'completed' ? 'Đã nhập hàng' : 'Phiếu tạm' }}
                            </span>
                        </div>
                        <div class="flex gap-2">
                            <span class="text-gray-500 w-28 flex-shrink-0">Nhà cung cấp:</span>
                            <span class="text-blue-600 font-medium">{{ purchase.supplier?.name || 'Không có' }}</span>
                        </div>
                        <div class="flex gap-2">
                            <span class="text-gray-500 w-32 flex-shrink-0">Người nhập:</span>
                            <span class="text-gray-700">{{ purchase.employee?.name || purchase.user?.name || 'N/A' }}</span>
                        </div>
                        <div class="flex gap-2">
                            <span class="text-gray-500 w-28 flex-shrink-0">Người tạo:</span>
                            <span class="text-gray-700">{{ purchase.user?.name || 'N/A' }}</span>
                        </div>
                        <div class="flex gap-2">
                            <span class="text-gray-500 w-32 flex-shrink-0">Thanh toán:</span>
                            <span class="text-gray-700">
                                {{ paymentMethodLabel(purchase.payment_method) }}
                                <span v-if="purchase.payment_method === 'transfer' && purchase.bank_account_info" class="text-blue-600 ml-1">({{ purchase.bank_account_info }})</span>
                            </span>
                        </div>
                    </div>

                    <!-- Items Table -->
                    <div class="border border-gray-200 rounded overflow-hidden">
                        <table class="w-full text-[13px] text-left">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-3 py-2 font-medium text-gray-600 w-24">Mã hàng</th>
                                    <th class="px-3 py-2 font-medium text-gray-600">Tên hàng</th>
                                    <th class="px-3 py-2 font-medium text-gray-600 text-center w-24">Số lượng</th>
                                    <th class="px-3 py-2 font-medium text-gray-600 text-right w-28">Đơn giá</th>
                                    <th class="px-3 py-2 font-medium text-gray-600 text-right w-24">Giảm giá</th>
                                    <th class="px-3 py-2 font-medium text-gray-600 text-right w-28">Giá nhập</th>
                                    <th class="px-3 py-2 font-medium text-gray-600 text-center w-24">BH (tháng)</th>
                                    <th class="px-3 py-2 font-medium text-gray-600 text-right w-28 pr-4">Thành tiền</th>
                                </tr>
                            </thead>
                            <thead class="bg-white border-b border-gray-100">
                                <tr>
                                    <th class="px-3 py-1.5">
                                        <input type="text" placeholder="Tìm mã hàng" class="w-full text-[12px] text-gray-400 outline-none font-normal" disabled>
                                    </th>
                                    <th class="px-3 py-1.5">
                                        <input type="text" placeholder="Tìm tên hàng" class="w-full text-[12px] text-gray-400 outline-none font-normal" disabled>
                                    </th>
                                    <th colspan="6"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <template v-for="item in purchase.items" :key="item.id">
                                    <tr class="hover:bg-gray-50/50">
                                        <td class="px-3 py-2 text-blue-600 font-medium">{{ item.product_code }}</td>
                                        <td class="px-3 py-2 text-gray-800">{{ item.product_name }}</td>
                                        <td class="px-3 py-2 text-center font-medium">{{ item.quantity }}</td>
                                        <td class="px-3 py-2 text-right">{{ formatCurrency(item.price) }}</td>
                                        <td class="px-3 py-2 text-right">{{ formatCurrency(item.discount) }}</td>
                                        <td class="px-3 py-2 text-right">{{ formatCurrency(item.price) }}</td>
                                        <td class="px-3 py-2 text-center">
                                            <span v-if="item.warranty_months > 0" class="text-orange-600 font-medium">{{ item.warranty_months }}</span>
                                            <span v-else class="text-gray-400">-</span>
                                            <div v-if="item.warranty_expires_at" class="text-[10px] text-gray-400">đến {{ new Date(item.warranty_expires_at).toLocaleDateString('vi-VN') }}</div>
                                        </td>
                                        <td class="px-3 py-2 text-right font-medium pr-4">{{ formatCurrency(item.subtotal) }}</td>
                                    </tr>
                                    <!-- Serial/IMEI -->
                                    <tr v-if="item.serials && item.serials.length > 0">
                                        <td colspan="8" class="px-6 py-1.5 bg-gray-50/50">
                                            <div class="flex flex-wrap gap-1.5">
                                                <span v-for="serial in item.serials" :key="serial.id"
                                                    class="inline-flex items-center bg-blue-50 border border-blue-200 text-blue-700 text-[11px] font-medium px-2 py-0.5 rounded">
                                                    {{ serial.serial_number }}
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- Summary -->
                    <div class="flex justify-end mt-4">
                        <div class="w-80 space-y-2 text-[13px]">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Tổng số lượng:</span>
                                <span class="font-medium">{{ totalQty }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Tổng số mặt hàng:</span>
                                <span class="font-medium">{{ totalProducts }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Tổng tiền hàng:</span>
                                <span class="font-medium">{{ formatCurrency(totalAmount) }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-500 flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    Giảm giá :
                                </span>
                                <span class="font-medium">{{ formatCurrency(purchase.discount) }}</span>
                            </div>
                            <div class="flex justify-between border-t border-gray-200 pt-2">
                                <span class="text-gray-700 font-medium">Cần trả NCC:</span>
                                <span class="font-bold text-gray-800">{{ formatCurrency(needToPay) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-700 font-medium">Tiền đã trả NCC:</span>
                                <span class="font-bold text-blue-600">{{ formatCurrency(purchase.paid_amount) }}</span>
                            </div>
                            <div class="flex justify-between" v-if="purchase.debt_amount > 0">
                                <span class="text-red-500 font-medium">Còn nợ NCC:</span>
                                <span class="font-bold text-red-600">{{ formatCurrency(purchase.debt_amount) }}</span>
                            </div>
                            <div class="flex justify-between pt-1" v-if="purchase.payment_method">
                                <span class="text-gray-500">Thanh toán:</span>
                                <span class="font-medium">{{ paymentMethodLabel(purchase.payment_method) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Supplier Info Sidebar -->
                <div class="w-[300px] flex-shrink-0 border-l border-gray-200 bg-gray-50/30 p-4 overflow-auto">
                    <div v-if="purchase.supplier" class="space-y-4">
                        <!-- Supplier Name -->
                        <div>
                            <div class="text-[11px] text-gray-400 uppercase tracking-wider mb-1">Nhà cung cấp</div>
                            <div class="font-bold text-blue-600 text-[14px]">{{ purchase.supplier.name }}</div>
                        </div>
                        <!-- Supplier Code -->
                        <div v-if="purchase.supplier.code">
                            <div class="text-[11px] text-gray-400 uppercase tracking-wider mb-1">Mã NCC</div>
                            <div class="text-[13px] text-gray-700">{{ purchase.supplier.code }}</div>
                        </div>
                        <!-- Phone -->
                        <div v-if="purchase.supplier.phone">
                            <div class="text-[11px] text-gray-400 uppercase tracking-wider mb-1">Điện thoại</div>
                            <div class="text-[13px] text-gray-700">{{ purchase.supplier.phone }}</div>
                        </div>
                        <!-- Email -->
                        <div v-if="purchase.supplier.email">
                            <div class="text-[11px] text-gray-400 uppercase tracking-wider mb-1">Email</div>
                            <div class="text-[13px] text-gray-700">{{ purchase.supplier.email }}</div>
                        </div>
                        <!-- Address -->
                        <div v-if="purchase.supplier.address">
                            <div class="text-[11px] text-gray-400 uppercase tracking-wider mb-1">Địa chỉ</div>
                            <div class="text-[13px] text-gray-700">{{ purchase.supplier.address }}</div>
                        </div>
                        <!-- Debt -->
                        <div class="border-t border-gray-200 pt-3 mt-3">
                            <div class="text-[11px] text-gray-400 uppercase tracking-wider mb-2">Công nợ NCC</div>
                            <div class="flex justify-between text-[13px]">
                                <span class="text-gray-500">Nợ hiện tại:</span>
                                <span class="font-bold" :class="purchase.supplier.supplier_debt_amount > 0 ? 'text-red-600' : 'text-green-600'">{{ formatCurrency(purchase.supplier.supplier_debt_amount || 0) }}</span>
                            </div>
                            <div class="flex justify-between text-[13px] mt-1">
                                <span class="text-gray-500">Tổng mua:</span>
                                <span class="font-medium text-gray-700">{{ formatCurrency(purchase.supplier.total_bought || 0) }}</span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-gray-400 text-[13px] italic">Không có thông tin nhà cung cấp</div>
                </div>
                </div>

                <!-- Payments Tab -->
                <div v-if="activeTab === 'payments'" class="p-5">
                    <div v-if="purchase.cash_flows && purchase.cash_flows.length > 0">
                        <table class="w-full text-[13px] text-left border border-gray-200 rounded overflow-hidden">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-3 py-2 font-medium text-gray-600">Mã phiếu chi</th>
                                    <th class="px-3 py-2 font-medium text-gray-600">Thời gian</th>
                                    <th class="px-3 py-2 font-medium text-gray-600 text-right">Số tiền</th>
                                    <th class="px-3 py-2 font-medium text-gray-600">Ghi chú</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="cf in purchase.cash_flows" :key="cf.id" class="hover:bg-gray-50/50">
                                    <td class="px-3 py-2 text-blue-600 font-medium">{{ cf.code }}</td>
                                    <td class="px-3 py-2">{{ formatDate(cf.time || cf.created_at) }}</td>
                                    <td class="px-3 py-2 text-right font-medium text-green-600">{{ formatCurrency(cf.amount) }}</td>
                                    <td class="px-3 py-2 text-gray-500">{{ cf.description }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="text-center py-12 text-gray-400">
                        <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        Chưa có lịch sử thanh toán
                    </div>
                </div>
            </div>

            <!-- Footer Actions -->
            <div class="flex items-center justify-center gap-3 px-4 py-3 border-t border-gray-200 bg-gray-50/50">
                <Link :href="`/purchases/${purchase.id}/edit`"
                    class="bg-blue-500 hover:bg-blue-600 text-white px-5 py-2 rounded text-sm font-medium flex items-center gap-1.5 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                    Sửa phiếu nhập
                </Link>
                <button @click="printPurchase"
                    class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 px-5 py-2 rounded text-sm font-medium flex items-center gap-1.5 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                    In tem mã
                </button>
                <button v-if="purchase.status === 'completed'" @click="cancelPurchase"
                    class="bg-red-500 hover:bg-red-600 text-white px-5 py-2 rounded text-sm font-medium flex items-center gap-1.5 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    Hủy bỏ
                </button>
            </div>
        </div>

    </AppLayout>
</template>
