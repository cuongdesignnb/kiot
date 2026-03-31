<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    purchase: Object,
    purchaseReturns: Array,
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
    const msg = props.purchase.status === 'completed'
        ? 'Bạn có chắc muốn hủy bỏ phiếu nhập hàng này? Tồn kho và công nợ sẽ được hoàn lại.'
        : 'Bạn có chắc muốn xóa phiếu tạm này?';
    if (!confirm(msg)) return;
    router.delete(`/purchases/${props.purchase.id}`, {
        onSuccess: () => {},
    });
};

// === Update Modal ===
const showUpdateModal = ref(false);
const pad = (n) => String(n).padStart(2, '0');

const getLocalDatetime = (val) => {
    if (!val) return '';
    const d = new Date(val);
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

const editForm = ref({
    note: props.purchase.note || '',
    purchase_date: getLocalDatetime(props.purchase.purchase_date || props.purchase.created_at),
    discount: Number(props.purchase.discount) || 0,
    paid_amount: Number(props.purchase.paid_amount) || 0,
    payment_method: props.purchase.payment_method || 'cash',
    bank_account_info: props.purchase.bank_account_info || '',
    employee_id: props.purchase.employee_id || '',
});

const editPayAmount = computed(() => totalAmount - (Number(editForm.value.discount) || 0));
const editDebt = computed(() => Math.max(0, editPayAmount.value - (Number(editForm.value.paid_amount) || 0)));
const isSubmitting = ref(false);

const openUpdateModal = () => {
    editForm.value = {
        note: props.purchase.note || '',
        purchase_date: getLocalDatetime(props.purchase.purchase_date || props.purchase.created_at),
        discount: Number(props.purchase.discount) || 0,
        paid_amount: Number(props.purchase.paid_amount) || 0,
        payment_method: props.purchase.payment_method || 'cash',
        bank_account_info: props.purchase.bank_account_info || '',
        employee_id: props.purchase.employee_id || '',
    };
    showUpdateModal.value = true;
};

const submitUpdate = () => {
    isSubmitting.value = true;
    router.put(`/purchases/${props.purchase.id}`, editForm.value, {
        onSuccess: () => {
            showUpdateModal.value = false;
            isSubmitting.value = false;
        },
        onError: () => {
            isSubmitting.value = false;
        },
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
                    :class="{
                        'bg-green-50 text-green-700 border-green-200': purchase.status === 'completed',
                        'bg-yellow-50 text-yellow-700 border-yellow-200': purchase.status === 'draft',
                        'bg-orange-50 text-orange-600 border-orange-200': purchase.status === 'returned',
                        'bg-red-50 text-red-600 border-red-200': purchase.status === 'cancelled',
                    }">
                    {{ purchase.status === 'completed' ? 'Đã nhập hàng' : purchase.status === 'returned' ? 'Đã trả hàng' : purchase.status === 'cancelled' ? 'Đã hủy' : 'Phiếu tạm' }}
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
                            <span class="font-medium" :class="{
                                'text-green-600': purchase.status === 'completed',
                                'text-yellow-600': purchase.status === 'draft',
                                'text-orange-600': purchase.status === 'returned',
                                'text-red-600': purchase.status === 'cancelled',
                            }">
                                {{ purchase.status === 'completed' ? 'Đã nhập hàng' : purchase.status === 'returned' ? 'Đã trả hàng' : purchase.status === 'cancelled' ? 'Đã hủy' : 'Phiếu tạm' }}
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
                                    <th class="px-3 py-2 font-medium text-gray-600 text-center w-20">Đã trả</th>
                                    <th class="px-3 py-2 font-medium text-gray-600 text-right w-28">Đơn giá</th>
                                    <th class="px-3 py-2 font-medium text-gray-600 text-right w-24">Giảm giá</th>
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
                                        <td class="px-3 py-2 text-center">
                                            <span v-if="item.returned_qty > 0" class="text-orange-600 font-bold">{{ item.returned_qty }}</span>
                                            <span v-else class="text-gray-300">0</span>
                                        </td>
                                        <td class="px-3 py-2 text-right">{{ formatCurrency(item.price) }}</td>
                                        <td class="px-3 py-2 text-right">{{ formatCurrency(item.discount) }}</td>
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
                                                    class="inline-flex items-center text-[11px] font-medium px-2 py-0.5 rounded border"
                                                    :class="serial.status === 'returned' ? 'bg-orange-50 border-orange-200 text-orange-600 line-through' : 'bg-blue-50 border-blue-200 text-blue-700'">
                                                    {{ serial.serial_number }}
                                                    <span v-if="serial.status === 'returned'" class="ml-1 text-[9px] no-underline">đã trả</span>
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- Purchase Returns Section -->
                    <div v-if="purchaseReturns && purchaseReturns.length > 0" class="mt-4 border border-orange-200 rounded overflow-hidden">
                        <div class="bg-orange-50 px-4 py-2 border-b border-orange-200 flex items-center gap-2">
                            <svg class="w-4 h-4 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path></svg>
                            <span class="font-bold text-orange-700 text-[13px]">Phiếu trả hàng nhập ({{ purchaseReturns.length }})</span>
                        </div>
                        <div v-for="ret in purchaseReturns" :key="ret.id" class="px-4 py-3 border-b border-gray-100 last:border-b-0 hover:bg-orange-50/30">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <Link :href="'/purchase-returns/' + ret.id" class="text-blue-600 font-medium hover:underline text-[13px]">{{ ret.code }}</Link>
                                    <span class="text-[12px] text-gray-500">{{ new Date(ret.return_date || ret.created_at).toLocaleString('vi-VN') }}</span>
                                    <span class="text-[12px] text-gray-400">NV: {{ ret.employee?.name || ret.user?.name || '—' }}</span>
                                </div>
                                <span class="font-bold text-orange-600 text-[13px]">-{{ formatCurrency(ret.total_amount) }}</span>
                            </div>
                            <div class="mt-1 flex flex-wrap gap-2 text-[12px] text-gray-500">
                                <span v-for="item in ret.items" :key="item.id" class="bg-gray-100 px-2 py-0.5 rounded">
                                    {{ item.product_name }} x{{ item.quantity }}
                                </span>
                            </div>
                        </div>
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
            <div class="flex items-center justify-between px-4 py-3 border-t border-gray-200 bg-gray-50/50">
                <div class="flex items-center gap-2">
                    <button v-if="purchase.status !== 'cancelled' && purchase.status !== 'returned'" @click="cancelPurchase"
                        class="bg-red-500 hover:bg-red-600 text-white px-5 py-2 rounded text-sm font-medium flex items-center gap-1.5 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        {{ purchase.status === 'completed' ? 'Hủy bỏ' : 'Xóa phiếu' }}
                    </button>
                </div>
                <div class="flex items-center gap-2">
                    <button v-if="purchase.status !== 'cancelled'" @click="openUpdateModal"
                        class="bg-blue-500 hover:bg-blue-600 text-white px-5 py-2 rounded text-sm font-medium flex items-center gap-1.5 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                        Sửa phiếu nhập
                    </button>
                    <Link v-if="purchase.status === 'completed' || purchase.status === 'returned'" :href="'/purchase-returns/create?purchase_id=' + purchase.id"
                        class="bg-orange-500 hover:bg-orange-600 text-white px-5 py-2 rounded text-sm font-medium flex items-center gap-1.5 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path></svg>
                        Trả hàng nhập
                    </Link>
                    <button @click="printPurchase"
                        class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 px-5 py-2 rounded text-sm font-medium flex items-center gap-1.5 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                        In tem mã
                    </button>
                </div>
            </div>
        </div>

        <!-- Update Modal -->
        <div v-if="showUpdateModal" class="fixed inset-0 bg-black bg-opacity-50 z-[100] flex items-center justify-center p-4" @click.self="showUpdateModal = false">
            <div class="bg-white rounded-lg shadow-2xl w-full max-w-lg">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-bold text-gray-800">Cập nhật phiếu nhập {{ purchase.code }}</h2>
                    <button @click="showUpdateModal = false" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
                </div>
                <form @submit.prevent="submitUpdate" class="p-6 space-y-4">
                    <!-- Thời gian -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Thời gian</label>
                        <input type="datetime-local" v-model="editForm.purchase_date" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none" />
                    </div>

                    <!-- Nhân viên -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Nhân viên nhập</label>
                        <select v-model="editForm.employee_id" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none bg-white">
                            <option value="">-- Không chọn --</option>
                            <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.name }}</option>
                        </select>
                    </div>

                    <!-- Giảm giá -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Giảm giá</label>
                        <input type="number" v-model.number="editForm.discount" min="0" class="w-full border border-gray-300 rounded px-3 py-2 text-sm text-right focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none" />
                    </div>

                    <!-- Tiền trả NCC -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Tiền trả NCC</label>
                        <input type="number" v-model.number="editForm.paid_amount" min="0" class="w-full border border-gray-300 rounded px-3 py-2 text-sm text-right focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none font-bold text-blue-600" />
                        <div class="flex justify-between mt-1 text-[12px]">
                            <span class="text-gray-500">Cần trả: {{ formatCurrency(editPayAmount) }}</span>
                            <span class="text-red-500" v-if="editDebt > 0">Còn nợ: {{ formatCurrency(editDebt) }}</span>
                        </div>
                    </div>

                    <!-- Payment Method -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Phương thức thanh toán</label>
                        <div class="flex items-center gap-4 text-sm">
                            <label class="flex items-center gap-1.5 cursor-pointer">
                                <input type="radio" v-model="editForm.payment_method" value="cash" class="text-green-600 focus:ring-green-500 w-4 h-4" />
                                <span>Tiền mặt</span>
                            </label>
                            <label class="flex items-center gap-1.5 cursor-pointer">
                                <input type="radio" v-model="editForm.payment_method" value="transfer" class="text-blue-600 focus:ring-blue-500 w-4 h-4" />
                                <span>Chuyển khoản</span>
                            </label>
                        </div>
                        <div v-if="editForm.payment_method === 'transfer'" class="mt-2">
                            <select v-model="editForm.bank_account_info" class="w-full border border-gray-300 rounded px-3 py-2 text-sm outline-none focus:border-blue-500 bg-white">
                                <option value="">-- Chọn tài khoản --</option>
                                <option v-for="ba in bankAccounts" :key="ba.id" :value="ba.bank_name + ' - ' + ba.account_number + ' - ' + ba.account_holder">
                                    {{ ba.bank_name }} - {{ ba.account_number }} ({{ ba.account_holder }})
                                </option>
                            </select>
                            <input v-if="!bankAccounts?.length" type="text" v-model="editForm.bank_account_info" class="w-full border border-gray-300 rounded px-3 py-2 text-sm outline-none focus:border-blue-500 mt-1" placeholder="Số tài khoản ngân hàng" />
                        </div>
                    </div>

                    <!-- Ghi chú -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Ghi chú</label>
                        <textarea v-model="editForm.note" rows="2" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="Ghi chú..."></textarea>
                    </div>

                    <!-- Footer -->
                    <div class="flex justify-end gap-3 pt-2 border-t border-gray-200">
                        <button type="button" @click="showUpdateModal = false" class="px-5 py-2 border border-gray-300 rounded text-sm font-medium text-gray-700 hover:bg-gray-50">Hủy</button>
                        <button type="submit" :disabled="isSubmitting" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-medium disabled:opacity-50 flex items-center gap-2">
                            <svg v-if="isSubmitting" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            {{ isSubmitting ? 'Đang lưu...' : 'Cập nhật' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
