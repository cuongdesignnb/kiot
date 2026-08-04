<script setup>
import { formatVND as fmt } from '@/utils/money';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    returnOrder: Object,
    salesAttributionEmployees: { type: Array, default: () => [] },
});

const salesAttributionModalOpen = ref(false);
const cancelError = ref('');
const salesAttributionForm = useForm({
    sales_attribution_employee_id: null,
    reason: '',
});

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
    cancelError.value = '';

    if (!confirm('Bạn chắc chắn muốn hủy phiếu trả hàng này? Hệ thống sẽ rollback tồn kho, công nợ và serial đã trả.')) return;
    router.post(`/returns/${props.returnOrder.id}/cancel`, {}, {
        preserveScroll: true,
        onError: (errors) => {
            cancelError.value = errors.serial_ids || errors.return || 'Không thể hủy phiếu trả hàng. Hệ thống chưa thay đổi dữ liệu.';
        },
    });
};

const isCancelled = () => ['Đã hủy', 'cancelled', 'canceled'].includes(props.returnOrder.status);

const openSalesAttributionModal = () => {
    salesAttributionForm.clearErrors();
    salesAttributionForm.sales_attribution_employee_id = props.returnOrder.sales_attribution_employee_id ?? null;
    salesAttributionForm.reason = props.returnOrder.sales_attribution_reason || '';
    salesAttributionModalOpen.value = true;
};

const saveSalesAttribution = () => {
    if (salesAttributionForm.sales_attribution_employee_id !== null
        && salesAttributionForm.sales_attribution_employee_id !== '') {
        salesAttributionForm.sales_attribution_employee_id = Number(salesAttributionForm.sales_attribution_employee_id);
    } else {
        salesAttributionForm.sales_attribution_employee_id = null;
    }

    salesAttributionForm.patch(`/returns/${props.returnOrder.id}/sales-attribution`, {
        preserveScroll: true,
        onSuccess: () => {
            salesAttributionModalOpen.value = false;
            salesAttributionForm.clearErrors();
        },
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
                        v-if="!isCancelled()"
                        @click="cancelReturn"
                        class="bg-white border border-red-300 text-red-600 rounded px-3 py-1.5 text-sm font-semibold hover:bg-red-50"
                    >
                        Hủy phiếu trả hàng
                    </button>
                    <button
                        v-if="returnOrder.can_edit_sales_attribution && !isCancelled()"
                        type="button"
                        @click="openSalesAttributionModal"
                        class="bg-white border border-indigo-300 text-indigo-700 rounded px-3 py-1.5 text-sm font-semibold hover:bg-indigo-50"
                    >
                        Điều chỉnh người chịu doanh số
                    </button>
                    <a :href="`/returns/${returnOrder.id}/print`" target="_blank" class="bg-white border border-gray-300 rounded px-3 py-1.5 text-sm font-semibold hover:bg-gray-50">
                        🖨 In
                    </a>
                </div>
            </div>

            <div
                v-if="cancelError"
                role="alert"
                class="mb-6 whitespace-pre-line rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
            >
                {{ cancelError }}
            </div>

            <!-- Info grid -->
            <div class="bg-white rounded-lg border border-gray-200 p-5 mb-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <div class="text-gray-500">Thời gian giao dịch</div>
                        <div class="font-semibold">{{ returnOrder.business_time }}</div>
                    </div>
                    <div title="Thời gian giao dịch được dùng trong báo cáo và công nợ. Thời điểm ghi nhận là lúc chứng từ được nhập hoặc ghi nhận vào hệ thống.">
                        <div class="text-gray-500">Thời điểm ghi nhận trên hệ thống</div>
                        <div class="font-semibold">{{ returnOrder.recorded_at || '—' }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Người bán hóa đơn gốc</div>
                        <div class="font-semibold">{{ returnOrder.original_seller_name || 'Chưa xác định người bán' }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Người chịu doanh số trả hàng</div>
                        <div class="font-semibold">
                            {{ returnOrder.effective_sales_attribution_name || returnOrder.original_seller_name || 'Chưa xác định người bán' }}
                        </div>
                        <div class="text-xs mt-0.5" :class="returnOrder.is_sales_attribution_overridden ? 'text-indigo-600' : 'text-gray-500'">
                            {{ returnOrder.is_sales_attribution_overridden ? 'Đã điều chỉnh' : 'Theo người bán hóa đơn gốc' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-gray-500">Người nhận trả</div>
                        <div class="font-semibold">{{ returnOrder.received_by_name || 'Chưa chọn' }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Người tạo phiếu</div>
                        <div class="font-semibold">{{ returnOrder.created_by_name || 'Chưa xác định' }}</div>
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
                <div v-if="returnOrder.sales_attribution_updated_at" class="mt-3 pt-3 border-t text-sm">
                    <div><span class="text-gray-500">Lý do điều chỉnh doanh số:</span> {{ returnOrder.sales_attribution_reason || '—' }}</div>
                    <div class="mt-1 text-gray-500">
                        Cập nhật bởi {{ returnOrder.sales_attribution_updated_by_name || '—' }} lúc {{ returnOrder.sales_attribution_updated_at }}
                    </div>
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

            <div v-if="salesAttributionModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/40" @click="!salesAttributionForm.processing && (salesAttributionModalOpen = false)"></div>
                <form class="relative w-full max-w-lg rounded-lg bg-white shadow-xl" @submit.prevent="saveSalesAttribution">
                    <div class="border-b px-5 py-4">
                        <h2 class="text-lg font-bold text-gray-900">Điều chỉnh người chịu doanh số trả hàng</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Thao tác này chỉ điều chỉnh báo cáo doanh số nhân viên; không thay đổi hóa đơn gốc, công nợ, tồn kho, giá vốn hoặc Serial/IMEI.
                        </p>
                    </div>
                    <div class="space-y-4 px-5 py-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700" for="sales-attribution-employee">Người chịu doanh số</label>
                            <select id="sales-attribution-employee" v-model="salesAttributionForm.sales_attribution_employee_id" class="w-full rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option :value="null">Theo người bán hóa đơn gốc</option>
                                <option v-for="employee in salesAttributionEmployees" :key="employee.id" :value="employee.id">
                                    {{ employee.name }}{{ employee.code ? ` — ${employee.code}` : '' }}
                                </option>
                            </select>
                            <p v-if="salesAttributionForm.errors.sales_attribution_employee_id" class="mt-1 text-sm text-red-600">{{ salesAttributionForm.errors.sales_attribution_employee_id }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700" for="sales-attribution-reason">Lý do điều chỉnh</label>
                            <textarea id="sales-attribution-reason" v-model="salesAttributionForm.reason" rows="4" maxlength="500" required class="w-full rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Nêu rõ lý do, tối thiểu 5 ký tự"></textarea>
                            <p v-if="salesAttributionForm.errors.reason" class="mt-1 text-sm text-red-600">{{ salesAttributionForm.errors.reason }}</p>
                        </div>
                        <div class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                            Hãy xác nhận người được chọn là người chịu phần doanh số giảm của phiếu trả này.
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t px-5 py-4">
                        <button type="button" :disabled="salesAttributionForm.processing" @click="salesAttributionModalOpen = false" class="rounded border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">Hủy</button>
                        <button type="submit" :disabled="salesAttributionForm.processing" class="rounded bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                            {{ salesAttributionForm.processing ? 'Đang lưu…' : 'Lưu điều chỉnh' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
