<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

const page = usePage();
const props = defineProps({
    purchase: Object,
    returnCode: String,
    bankAccounts: Array,
    employees: Array,
});

const items = ref(
    (props.purchase.items || [])
        .filter(item => item.max_returnable > 0)
        .map(item => ({
            product_id: item.product_id,
            product_name: item.product_name || item.product?.name || '',
            product_code: item.product_code || item.product?.sku || '',
            has_serial: !!item.product?.has_serial,
            price: item.price,
            max_returnable: item.max_returnable,
            quantity: 0,
            selected: false,
            serials: (item.serials || []).map(s => ({ ...s, selected: false })),
            serial_ids: [],
        }))
);

const toggleItem = (item) => {
    item.selected = !item.selected;
    if (!item.selected) {
        item.quantity = 0;
        item.serials.forEach(s => s.selected = false);
        item.serial_ids = [];
    } else if (!item.has_serial) {
        item.quantity = item.max_returnable;
    }
};

const toggleSerial = (item, serial) => {
    serial.selected = !serial.selected;
    item.serial_ids = item.serials.filter(s => s.selected).map(s => s.id);
    item.quantity = item.serial_ids.length;
    item.selected = item.quantity > 0;
};

const selectedItems = computed(() => items.value.filter(i => i.selected && i.quantity > 0));
const totalAmount = computed(() => selectedItems.value.reduce((sum, i) => sum + i.quantity * i.price, 0));

const refundAmount = ref(0);
const note = ref('');
const employeeId = ref('');
const paymentMethod = ref('cash');
const bankAccountInfo = ref('');
const submitting = ref(false);

// Keep refund synced with total
const syncRefund = computed(() => {
    refundAmount.value = totalAmount.value;
    return totalAmount.value;
});

const formatCurrency = (val) => Number(val || 0).toLocaleString('vi-VN');

const save = () => {
    if (selectedItems.value.length === 0) {
        alert('Vui lòng chọn ít nhất 1 sản phẩm để trả.');
        return;
    }

    submitting.value = true;

    router.post('/purchase-returns', {
        code: props.returnCode,
        purchase_id: props.purchase.id,
        employee_id: employeeId.value || null,
        refund_amount: refundAmount.value,
        note: note.value,
        payment_method: paymentMethod.value,
        bank_account_info: paymentMethod.value === 'transfer' ? bankAccountInfo.value : null,
        items: selectedItems.value.map(item => ({
            product_id: item.product_id,
            quantity: item.quantity,
            price: item.price,
            serial_ids: item.serial_ids || [],
        })),
    }, {
        onError: (errors) => {
            const firstError = Object.values(errors)[0];
            if (firstError) alert(firstError);
        },
        onFinish: () => {
            submitting.value = false;
        },
    });
};
</script>

<template>
    <Head :title="'Trả hàng nhập - ' + purchase.code" />
    <div class="h-screen flex flex-col bg-[#eef1f5] text-[13px] overflow-hidden font-sans">

        <!-- Flash Messages -->
        <div v-if="page.props.flash?.error" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 text-sm flex items-center gap-2">
            <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
            {{ page.props.flash.error }}
        </div>

        <!-- Header -->
        <header class="bg-white text-gray-800 px-4 h-[56px] flex items-center justify-between border-b border-gray-200 shadow-sm flex-shrink-0">
            <div class="flex items-center gap-4">
                <Link :href="'/purchases/' + purchase.id" class="text-gray-600 hover:text-green-600 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                </Link>
                <div>
                    <div class="text-xl font-bold text-gray-800">Trả hàng nhập</div>
                    <div class="text-xs text-gray-500">Phiếu nhập: <span class="text-blue-600 font-medium">{{ purchase.code }}</span> - NCC: <span class="font-medium">{{ purchase.supplier?.name }}</span></div>
                </div>
            </div>
            <div class="text-sm text-gray-500">Mã phiếu trả: <span class="font-bold text-gray-800">{{ returnCode }}</span></div>
        </header>

        <!-- Content -->
        <div class="flex flex-1 overflow-hidden">
            <!-- Left: Items -->
            <div class="flex-1 overflow-auto p-4">
                <div class="bg-white rounded shadow-sm border border-gray-200">
                    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50 font-bold text-gray-700">
                        Chọn sản phẩm trả lại
                    </div>
                    <table class="w-full text-left">
                        <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 text-[12px] uppercase">
                            <tr>
                                <th class="p-3 w-[40px] text-center">
                                    <svg class="w-4 h-4 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                </th>
                                <th class="p-3">Mã hàng</th>
                                <th class="p-3">Tên hàng</th>
                                <th class="p-3 text-center w-[100px]">Đã nhập</th>
                                <th class="p-3 text-center w-[100px]">Có thể trả</th>
                                <th class="p-3 text-center w-[100px]">SL trả</th>
                                <th class="p-3 text-right w-[120px]">Đơn giá</th>
                                <th class="p-3 text-right w-[140px]">Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template v-for="item in items" :key="item.product_id">
                                <tr class="hover:bg-blue-50/30 cursor-pointer" :class="{ 'bg-blue-50/50': item.selected }" @click="toggleItem(item)">
                                    <td class="p-3 text-center" @click.stop>
                                        <input type="checkbox" :checked="item.selected" @change="toggleItem(item)" class="rounded border-gray-300 text-green-600 focus:ring-green-500 w-4 h-4">
                                    </td>
                                    <td class="p-3 text-blue-600 font-medium">{{ item.product_code }}</td>
                                    <td class="p-3">
                                        <div class="font-medium">{{ item.product_name }}</div>
                                        <div v-if="item.has_serial" class="text-[11px] text-orange-500 mt-0.5">Serial/IMEI</div>
                                    </td>
                                    <td class="p-3 text-center text-gray-500">{{ item.max_returnable + (items.find(i => i.product_id === item.product_id)?.quantity || 0) }}</td>
                                    <td class="p-3 text-center font-bold text-green-600">{{ item.max_returnable }}</td>
                                    <td class="p-3 text-center" @click.stop>
                                        <input v-if="!item.has_serial && item.selected" type="number" v-model.number="item.quantity" :min="1" :max="item.max_returnable" class="w-[70px] border border-gray-300 rounded py-1 text-center outline-none focus:border-green-500 text-[13px]">
                                        <span v-else-if="item.has_serial" class="font-medium">{{ item.quantity }}</span>
                                        <span v-else class="text-gray-400">0</span>
                                    </td>
                                    <td class="p-3 text-right">{{ formatCurrency(item.price) }}</td>
                                    <td class="p-3 text-right font-bold" :class="item.selected && item.quantity > 0 ? 'text-red-600' : 'text-gray-400'">
                                        {{ formatCurrency(item.quantity * item.price) }}
                                    </td>
                                </tr>
                                <!-- Serial selection row -->
                                <tr v-if="item.has_serial && item.selected && item.serials.length > 0" class="bg-gray-50/50">
                                    <td :colspan="8" class="px-6 py-2">
                                        <div class="text-[12px] text-gray-500 mb-1.5">Chọn Serial/IMEI cần trả:</div>
                                        <div class="flex flex-wrap gap-1.5">
                                            <span v-for="s in item.serials" :key="s.id"
                                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded border text-[12px] cursor-pointer transition-colors"
                                                :class="s.selected ? 'bg-red-50 border-red-300 text-red-700' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50'"
                                                @click.stop="toggleSerial(item, s)">
                                                {{ s.serial_number }}
                                                <svg v-if="s.selected" class="w-3 h-3 text-red-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                            </span>
                                        </div>
                                        <div v-if="item.serials.length === 0" class="text-[12px] text-gray-400 italic">Không có serial nào trong kho để trả</div>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="items.length === 0">
                                <td colspan="8" class="p-8 text-center text-gray-500">Không có sản phẩm nào có thể trả từ phiếu nhập này.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right: Summary -->
            <div class="w-[320px] bg-white border-l border-gray-200 flex flex-col">
                <div class="flex-1 overflow-auto p-4 space-y-4">
                    <!-- Employee -->
                    <div>
                        <label class="block text-[12px] text-gray-500 mb-1">Người trả hàng</label>
                        <select v-model="employeeId" class="w-full border border-gray-300 rounded px-2 py-1.5 text-[13px] outline-none focus:border-green-500 bg-white">
                            <option value="">-- Chọn nhân viên --</option>
                            <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.name }}</option>
                        </select>
                    </div>

                    <!-- Summary -->
                    <div class="space-y-2 pt-2 border-t border-gray-200">
                        <div class="flex justify-between text-[13px]">
                            <span class="text-gray-500">Tổng SL trả:</span>
                            <span class="font-bold">{{ selectedItems.reduce((s, i) => s + i.quantity, 0) }}</span>
                        </div>
                        <div class="flex justify-between text-[13px]">
                            <span class="text-gray-500">Số mặt hàng:</span>
                            <span class="font-bold">{{ selectedItems.length }}</span>
                        </div>
                        <div class="flex justify-between text-[15px] pt-2 border-t border-gray-200">
                            <span class="text-gray-800 font-bold">Tổng tiền trả:</span>
                            <span class="font-bold text-red-600">{{ formatCurrency(syncRefund) }}</span>
                        </div>
                    </div>

                    <!-- Refund Amount -->
                    <div>
                        <label class="block text-[12px] text-gray-500 mb-1">NCC hoàn tiền</label>
                        <input type="number" v-model.number="refundAmount" :max="totalAmount" min="0" class="w-full border border-gray-300 rounded px-2 py-1.5 text-[13px] text-right outline-none focus:border-green-500 font-bold text-blue-600">
                    </div>

                    <!-- Payment Method -->
                    <div>
                        <label class="block text-[12px] text-gray-500 mb-2">Phương thức</label>
                        <div class="flex items-center gap-3 text-[13px]">
                            <label class="flex items-center gap-1.5 cursor-pointer">
                                <input type="radio" v-model="paymentMethod" value="cash" class="text-green-600 w-4 h-4" />
                                <span>Tiền mặt</span>
                            </label>
                            <label class="flex items-center gap-1.5 cursor-pointer">
                                <input type="radio" v-model="paymentMethod" value="transfer" class="text-blue-600 w-4 h-4" />
                                <span>Chuyển khoản</span>
                            </label>
                        </div>
                        <select v-if="paymentMethod === 'transfer'" v-model="bankAccountInfo" class="w-full border border-gray-300 rounded px-2 py-1.5 text-[13px] mt-2 outline-none focus:border-blue-500 bg-white">
                            <option value="">-- Chọn TK ngân hàng --</option>
                            <option v-for="ba in bankAccounts" :key="ba.id" :value="ba.bank_name + ' - ' + ba.account_number + ' - ' + ba.account_holder">
                                {{ ba.bank_name }} - {{ ba.account_number }}
                            </option>
                        </select>
                    </div>

                    <!-- Note -->
                    <div>
                        <input type="text" v-model="note" placeholder="Ghi chú trả hàng..." class="w-full border border-gray-300 rounded px-2 py-1.5 text-[13px] outline-none focus:border-green-500">
                    </div>
                </div>

                <!-- Action -->
                <div class="p-4 border-t border-gray-200">
                    <button @click="save" :disabled="submitting || selectedItems.length === 0"
                        class="w-full bg-red-500 hover:bg-red-600 text-white font-bold py-3 rounded text-[15px] uppercase tracking-wide transition-colors flex justify-center items-center gap-2 disabled:opacity-50">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path></svg>
                        Trả hàng nhập
                    </button>
                    <div class="mt-2 text-center">
                        <Link :href="'/purchases/' + purchase.id" class="text-gray-500 hover:text-gray-800 text-[13px] underline">Quay lại phiếu nhập</Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
