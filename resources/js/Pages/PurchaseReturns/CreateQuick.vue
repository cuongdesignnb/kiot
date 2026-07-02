<script setup>
import { formatVND as formatCurrency } from '@/utils/money';
import MoneyInput from '@/Components/MoneyInput.vue';
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';

const page = usePage();
const props = defineProps({
    returnCode: String,
    suppliers: Array,
    products: Array,
    bankAccounts: Array,
    employees: Array,
    currentReturner: Object,
});

// ====== STATE ======
const supplierId = ref('');
const supplierSearch = ref('');
const showSupplierList = ref(false);

const productSearch = ref('');
const showProductList = ref(false);
const items = ref([]); // {product_id, name, sku, price, quantity, max_stock}

const refundAmount = ref(0);
const note = ref('');
const paymentMethod = ref('cash');
const bankAccountInfo = ref('');
const submitting = ref(false);

const returnerOptions = computed(() => {
    const options = (props.employees || []).map(emp => ({
        value: String(emp.id),
        label: emp.name,
        code: emp.code,
        is_current_user: props.currentReturner?.employee_id === emp.id,
    }));

    if (props.currentReturner && !props.currentReturner.employee_id) {
        options.unshift({
            value: 'current_user',
            label: props.currentReturner.name,
            code: props.currentReturner.code,
            is_current_user: true,
        });
    }

    return options;
});

const employeeId = ref(props.currentReturner?.employee_id
    ? String(props.currentReturner.employee_id)
    : (props.currentReturner ? 'current_user' : '')
);

const selectedEmployeeId = () => employeeId.value === 'current_user'
    ? null
    : (employeeId.value || null);

// ====== COMPUTED ======
const selectedSupplier = computed(() =>
    props.suppliers.find(s => s.id === supplierId.value)
);

const filteredSuppliers = computed(() => {
    const q = supplierSearch.value.toLowerCase().trim();
    if (!q) return props.suppliers.slice(0, 20);
    return props.suppliers
        .filter(s => s.name?.toLowerCase().includes(q) || s.code?.toLowerCase().includes(q) || s.phone?.includes(q))
        .slice(0, 20);
});

const filteredProducts = computed(() => {
    const q = productSearch.value.toLowerCase().trim();
    if (!q) return props.products.slice(0, 20);
    return props.products
        .filter(p => p.name?.toLowerCase().includes(q) || p.sku?.toLowerCase().includes(q))
        .slice(0, 20);
});

const totalAmount = computed(() =>
    items.value.reduce((sum, i) => sum + (i.quantity || 0) * (i.price || 0), 0)
);

const debtChange = computed(() => totalAmount.value - (refundAmount.value || 0));

// ====== ACTIONS ======
const pickSupplier = (s) => {
    supplierId.value = s.id;
    supplierSearch.value = s.name;
    showSupplierList.value = false;
};

const clearSupplier = () => {
    supplierId.value = '';
    supplierSearch.value = '';
};

const addProduct = (p) => {
    if (p.has_serial) {
        alert(`Sản phẩm "${p.name}" có Serial/IMEI. Vui lòng dùng luồng trả theo phiếu nhập để chọn đúng serial.`);
        return;
    }
    if ((Number(p.stock_quantity) || 0) <= 0) {
        alert(`Sản phẩm "${p.name}" đã hết tồn kho, không thể trả nhanh.`);
        return;
    }
    const existing = items.value.find(i => i.product_id === p.id);
    if (existing) {
        existing.quantity = Math.min(existing.quantity + 1, existing.max_stock);
    } else {
        items.value.push({
            product_id: p.id,
            name: p.name,
            sku: p.sku,
            price: Number(p.cost_price) || 0,
            quantity: 1,
            max_stock: Number(p.stock_quantity) || 0,
            has_serial: !!p.has_serial,
        });
    }
    productSearch.value = '';
    showProductList.value = false;
    // auto sync refund
    refundAmount.value = totalAmount.value;
};

const removeItem = (idx) => {
    items.value.splice(idx, 1);
    refundAmount.value = totalAmount.value;
};

const onQtyOrPriceChange = () => {
    refundAmount.value = totalAmount.value;
};

// ====== SERIAL LOOKUP ======
const serialLookupLoading = ref(false);
const serialLookupResults = ref([]);
const serialLookupBlockedResults = ref([]);
const serialLookupMessage = ref('');
const serialLookupError = ref('');

const resetSerialLookup = () => {
    serialLookupResults.value = [];
    serialLookupBlockedResults.value = [];
    serialLookupMessage.value = '';
    serialLookupError.value = '';
};

const onProductSearchInput = () => {
    resetSerialLookup();
};

const lookupSerial = async () => {
    const q = productSearch.value.trim();
    if (q.length < 2) {
        serialLookupError.value = 'Vui lòng nhập ít nhất 2 ký tự Serial/IMEI.';
        return;
    }
    serialLookupLoading.value = true;
    resetSerialLookup();
    try {
        const params = { serial: q };
        if (supplierId.value) params.supplier_id = supplierId.value;
        const res = await axios.get('/purchase-returns/serial-lookup', { params });
        const data = res.data;
        serialLookupResults.value = data.matches || [];
        serialLookupBlockedResults.value = data.blocked_matches || [];
        serialLookupMessage.value = data.message || '';
    } catch (e) {
        if (e.response?.data?.message) {
            serialLookupError.value = e.response.data.message;
        } else {
            serialLookupError.value = 'Không tra cứu được Serial/IMEI.';
        }
    } finally {
        serialLookupLoading.value = false;
    }
};

const save = () => {
    if (!supplierId.value) {
        alert('Vui lòng chọn nhà cung cấp.');
        return;
    }
    if (items.value.length === 0) {
        alert('Vui lòng chọn ít nhất 1 sản phẩm.');
        return;
    }
    // validate stock
    for (const i of items.value) {
        if (i.quantity <= 0) {
            alert(`Số lượng của "${i.name}" phải > 0.`);
            return;
        }
        if (i.has_serial) {
            alert(`Sản phẩm "${i.name}" có Serial/IMEI. Vui lòng dùng luồng trả theo phiếu nhập để chọn đúng serial.`);
            return;
        }
        if (i.quantity > i.max_stock) {
            alert(`"${i.name}" chỉ còn ${i.max_stock} trong kho, không thể trả ${i.quantity}.`);
            return;
        }
    }

    submitting.value = true;
    router.post('/purchase-returns/quick', {
        code: props.returnCode,
        supplier_id: supplierId.value,
        employee_id: selectedEmployeeId(),
        refund_amount: Number(refundAmount.value) || 0,
        note: note.value,
        payment_method: paymentMethod.value,
        bank_account_info: paymentMethod.value === 'transfer' ? bankAccountInfo.value : null,
        items: items.value.map(i => ({
            product_id: i.product_id,
            quantity: i.quantity,
            price: Number(i.price) || 0,
        })),
    }, {
        onError: (errors) => {
            const firstError = Object.values(errors)[0];
            if (firstError) alert(firstError);
        },
        onFinish: () => { submitting.value = false; },
    });
};
</script>

<template>
    <Head title="Trả hàng nhập nhanh" />
    <div class="h-screen flex flex-col bg-[#eef1f5] text-[13px] overflow-hidden font-sans">

        <div v-if="page.props.flash?.error" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 text-sm">
            {{ page.props.flash.error }}
        </div>

        <!-- Header -->
        <header class="bg-white text-gray-800 px-4 h-[56px] flex items-center justify-between border-b border-gray-200 shadow-sm flex-shrink-0">
            <div class="flex items-center gap-4">
                <Link href="/purchase-returns" class="text-gray-600 hover:text-green-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                </Link>
                <div>
                    <div class="text-xl font-bold">Trả hàng nhập nhanh</div>
                    <div class="text-xs text-gray-500">Không cần phiếu nhập gốc — chọn NCC và sản phẩm để trả</div>
                </div>
            </div>
            <div class="text-sm text-gray-500">Mã phiếu: <span class="font-bold text-gray-800">{{ returnCode }}</span></div>
        </header>

        <div class="flex flex-1 overflow-hidden">
            <!-- LEFT: items -->
            <div class="flex-1 overflow-auto p-4 space-y-4">
                <!-- Supplier picker -->
                <div class="bg-white rounded shadow-sm border border-gray-200 p-4">
                    <label class="block text-[12px] text-gray-500 mb-1.5 font-medium">Nhà cung cấp <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <div v-if="selectedSupplier" class="flex items-center justify-between border border-green-300 bg-green-50 rounded px-3 py-2">
                            <div>
                                <div class="font-bold text-gray-800">{{ selectedSupplier.name }}</div>
                                <div class="text-[12px] text-gray-500">
                                    {{ selectedSupplier.code }}<span v-if="selectedSupplier.phone"> · {{ selectedSupplier.phone }}</span>
                                    · Công nợ hiện tại: <span class="font-bold" :class="selectedSupplier.supplier_debt_amount > 0 ? 'text-red-600' : 'text-green-600'">{{ formatCurrency(selectedSupplier.supplier_debt_amount) }}</span>
                                </div>
                            </div>
                            <button @click="clearSupplier" class="text-red-500 hover:text-red-700 text-sm">Đổi NCC</button>
                        </div>
                        <div v-else>
                            <input v-model="supplierSearch" @focus="showSupplierList = true"
                                placeholder="Tìm NCC theo tên, mã, số điện thoại..."
                                class="w-full border border-gray-300 rounded px-3 py-2 outline-none focus:border-green-500" />
                            <div v-if="showSupplierList && filteredSuppliers.length > 0"
                                class="absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded shadow-lg z-30 max-h-[280px] overflow-auto">
                                <div v-for="s in filteredSuppliers" :key="s.id"
                                    @click="pickSupplier(s)"
                                    class="px-3 py-2 hover:bg-green-50 cursor-pointer border-b border-gray-100 last:border-0">
                                    <div class="font-medium">{{ s.name }}</div>
                                    <div class="text-[12px] text-gray-500">{{ s.code }}<span v-if="s.phone"> · {{ s.phone }}</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Product search + items table -->
                <div class="bg-white rounded shadow-sm border border-gray-200">
                    <div class="p-4 border-b border-gray-200 relative">
                        <div class="flex gap-2">
                            <input v-model="productSearch" @focus="showProductList = true"
                                @input="onProductSearchInput"
                                @keydown.enter.prevent="lookupSerial"
                                placeholder="Tìm sản phẩm theo tên, mã SKU hoặc nhập Serial/IMEI để tra cứu phiếu nhập..."
                                class="flex-1 border border-gray-300 rounded px-3 py-2 outline-none focus:border-green-500" />
                            <button type="button" @click="lookupSerial"
                                class="px-3 py-2 rounded bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 disabled:opacity-50 whitespace-nowrap"
                                :disabled="serialLookupLoading">
                                {{ serialLookupLoading ? 'Đang tìm...' : '🔍 Tìm serial' }}
                            </button>
                        </div>
                        <div v-if="showProductList && filteredProducts.length > 0"
                            class="absolute top-full left-4 right-4 mt-1 bg-white border border-gray-200 rounded shadow-lg z-20 max-h-[320px] overflow-auto">
                            <div v-for="p in filteredProducts" :key="p.id"
                                @click="addProduct(p)"
                                class="px-3 py-2 border-b border-gray-100 last:border-0 flex items-center justify-between"
                                :class="p.has_serial || Number(p.stock_quantity || 0) <= 0 ? 'bg-gray-50 text-gray-400 cursor-not-allowed' : 'hover:bg-blue-50 cursor-pointer'">
                                <div>
                                    <div class="font-medium">{{ p.name }}</div>
                                    <div class="text-[12px] text-gray-500">{{ p.sku }} · Tồn: {{ p.stock_quantity }} · Giá vốn: {{ formatCurrency(p.cost_price) }}</div>
                                    <div v-if="p.has_serial" class="text-[11px] text-orange-600 mt-0.5">
                                        Serial/IMEI: trả theo phiếu nhập để chọn serial
                                    </div>
                                </div>
                                <button
                                    class="text-sm font-medium"
                                    :class="p.has_serial || Number(p.stock_quantity || 0) <= 0 ? 'text-gray-400' : 'text-green-600'"
                                >
                                    {{ p.has_serial ? 'Không hỗ trợ trả nhanh' : '+ Thêm' }}
                                </button>
                            </div>
                        </div>

                        <!-- Serial lookup results -->
                        <div v-if="serialLookupResults.length > 0" class="mt-3 bg-blue-50 border border-blue-200 rounded p-3 space-y-2">
                            <div class="font-bold text-blue-700 text-sm">Tìm thấy Serial/IMEI có thể trả theo phiếu nhập</div>
                            <div v-for="result in serialLookupResults" :key="result.serial_id"
                                class="bg-white rounded border border-blue-100 p-3 flex items-center justify-between gap-3">
                                <div class="text-sm">
                                    <div class="font-bold text-gray-800">Serial/IMEI: {{ result.serial_number }}</div>
                                    <div class="text-gray-600">{{ result.product_sku }} — {{ result.product_name }}</div>
                                    <div class="text-gray-500 text-xs mt-1">
                                        Phiếu nhập: {{ result.purchase_code }}
                                        <span v-if="result.supplier_name"> · NCC: {{ result.supplier_name }}</span>
                                    </div>
                                </div>
                                <Link :href="result.return_url"
                                    class="px-3 py-2 rounded bg-orange-500 text-white text-sm font-medium hover:bg-orange-600 whitespace-nowrap">
                                    Mở phiếu nhập để trả serial này
                                </Link>
                            </div>
                        </div>

                        <!-- Serial lookup blocked results -->
                        <div v-if="serialLookupBlockedResults.length > 0" class="mt-3 bg-yellow-50 border border-yellow-200 rounded p-3 space-y-2">
                            <div class="font-bold text-yellow-700 text-sm">Tìm thấy Serial/IMEI nhưng không thể trả NCC</div>
                            <div v-for="result in serialLookupBlockedResults" :key="'blocked-' + result.serial_id"
                                class="bg-white rounded border border-yellow-100 p-3">
                                <div class="font-bold text-gray-800">Serial/IMEI: {{ result.serial_number }}</div>
                                <div class="text-gray-600 text-sm">{{ result.product_sku }} — {{ result.product_name }}</div>
                                <div class="text-red-600 text-xs mt-1">{{ result.reason }}</div>
                            </div>
                        </div>

                        <!-- Serial lookup message -->
                        <div v-if="serialLookupMessage && serialLookupResults.length === 0 && serialLookupBlockedResults.length === 0" class="mt-2 text-sm text-gray-500 italic">
                            {{ serialLookupMessage }}
                        </div>

                        <!-- Serial lookup error -->
                        <div v-if="serialLookupError" class="mt-2 text-sm text-red-600">
                            {{ serialLookupError }}
                        </div>
                    </div>

                    <table class="w-full text-left">
                        <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 text-[12px] uppercase">
                            <tr>
                                <th class="p-3 w-[50px]">STT</th>
                                <th class="p-3">Mã hàng</th>
                                <th class="p-3">Tên hàng</th>
                                <th class="p-3 text-center w-[100px]">Tồn</th>
                                <th class="p-3 text-center w-[120px]">SL trả</th>
                                <th class="p-3 text-right w-[140px]">Đơn giá</th>
                                <th class="p-3 text-right w-[140px]">Thành tiền</th>
                                <th class="p-3 w-[40px]"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="items.length === 0">
                                <td colspan="8" class="p-8 text-center text-gray-400 italic">Chưa có sản phẩm nào — dùng ô tìm kiếm bên trên để thêm</td>
                            </tr>
                            <tr v-for="(item, idx) in items" :key="item.product_id" class="hover:bg-blue-50/30">
                                <td class="p-3 text-center text-gray-500">{{ idx + 1 }}</td>
                                <td class="p-3 text-blue-600 font-medium">{{ item.sku }}</td>
                                <td class="p-3">
                                    <div class="font-medium">{{ item.name }}</div>
                                    <div v-if="item.has_serial" class="text-[11px] text-orange-500 mt-0.5">Có serial (dùng luồng trả theo phiếu nhập để chọn chính xác)</div>
                                </td>
                                <td class="p-3 text-center text-gray-500">{{ item.max_stock }}</td>
                                <td class="p-3 text-center">
                                    <input type="number" v-model.number="item.quantity" @input="onQtyOrPriceChange"
                                        min="1" :max="item.max_stock"
                                        class="w-[80px] border border-gray-300 rounded py-1 text-center outline-none focus:border-green-500" />
                                </td>
                                <td class="p-3 text-right">
                                    <MoneyInput v-model="item.price" :min="0" @input="onQtyOrPriceChange"
                                        input-class="w-[110px] border border-gray-300 rounded py-1 text-right outline-none focus:border-green-500" />
                                </td>
                                <td class="p-3 text-right font-bold text-red-600">{{ formatCurrency(item.quantity * item.price) }}</td>
                                <td class="p-3 text-center">
                                    <button @click="removeItem(idx)" class="text-red-500 hover:text-red-700">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- RIGHT: summary -->
            <div class="w-[340px] bg-white border-l border-gray-200 flex flex-col">
                <div class="flex-1 overflow-auto p-4 space-y-4">
                    <div>
                        <label class="block text-[12px] text-gray-500 mb-1">Người trả hàng</label>
                        <select v-model="employeeId" class="w-full border border-gray-300 rounded px-2 py-1.5 bg-white">
                            <option value="">-- Chọn nhân viên --</option>
                            <option v-for="emp in returnerOptions" :key="emp.value" :value="emp.value">
                                {{ emp.label }}{{ emp.is_current_user ? ' (hiện tại)' : '' }}
                            </option>
                        </select>
                    </div>

                    <div class="space-y-2 pt-2 border-t border-gray-200">
                        <div class="flex justify-between"><span class="text-gray-500">Số mặt hàng:</span><span class="font-bold">{{ items.length }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Tổng SL trả:</span><span class="font-bold">{{ items.reduce((s, i) => s + (i.quantity || 0), 0) }}</span></div>
                        <div class="flex justify-between pt-2 border-t border-gray-200">
                            <span class="font-bold">NCC cần trả:</span>
                            <span class="font-bold text-red-600 text-[15px]">{{ formatCurrency(totalAmount) }}</span>
                        </div>
                    </div>

                    <div>
                        <label class="block text-[12px] text-gray-500 mb-1">Tiền NCC trả thực tế</label>
                        <MoneyInput v-model="refundAmount" :min="0"
                            input-class="w-full border border-gray-300 rounded px-2 py-1.5 text-right font-bold text-blue-600" />
                    </div>

                    <div class="flex justify-between pt-2 border-t border-gray-200">
                        <span class="text-gray-600">Tính vào công nợ:</span>
                        <span class="font-bold" :class="debtChange > 0 ? 'text-orange-600' : 'text-green-600'">{{ formatCurrency(debtChange) }}</span>
                    </div>

                    <div>
                        <label class="block text-[12px] text-gray-500 mb-2">Phương thức</label>
                        <div class="flex gap-3">
                            <label class="flex items-center gap-1.5 cursor-pointer"><input type="radio" v-model="paymentMethod" value="cash" /><span>Tiền mặt</span></label>
                            <label class="flex items-center gap-1.5 cursor-pointer"><input type="radio" v-model="paymentMethod" value="transfer" /><span>Chuyển khoản</span></label>
                        </div>
                        <select v-if="paymentMethod === 'transfer'" v-model="bankAccountInfo"
                            class="w-full border border-gray-300 rounded px-2 py-1.5 mt-2 bg-white">
                            <option value="">-- Chọn TK ngân hàng --</option>
                            <option v-for="ba in bankAccounts" :key="ba.id" :value="ba.bank_name + ' - ' + ba.account_number + ' - ' + ba.account_holder">
                                {{ ba.bank_name }} - {{ ba.account_number }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <input type="text" v-model="note" placeholder="Ghi chú trả hàng..."
                            class="w-full border border-gray-300 rounded px-2 py-1.5 outline-none focus:border-green-500" />
                    </div>
                </div>

                <div class="p-4 border-t border-gray-200">
                    <button @click="save" :disabled="submitting || items.length === 0 || !supplierId"
                        class="w-full bg-red-500 hover:bg-red-600 text-white font-bold py-3 rounded uppercase tracking-wide transition disabled:opacity-50">
                        {{ submitting ? 'Đang lưu...' : 'Trả hàng nhập' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- click outside to close dropdowns -->
        <div v-if="showSupplierList || showProductList" class="fixed inset-0 z-10" @click="showSupplierList = false; showProductList = false"></div>
    </div>
</template>
