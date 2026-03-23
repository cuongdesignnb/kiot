<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    products: Array,
    branches: Array,
    suppliers: Array,
    defaultBranchId: Number,
    purchaseOrderCode: String
});

const searchQuery = ref('');
const showSuggestions = ref(false);
const items = ref([]);

// Sidebar refs (Vendor & Financials)
const selectedSupplierId = ref('');
const status = ref('draft');
const discount = ref(0);
const importFee = ref(0);
const otherImportFee = ref(0);
const expectedDate = ref('');
const note = ref('');
const submitRef = ref(false);

// Ngày đặt hàng
const pad = (n) => String(n).padStart(2, '0');
const nowInit = new Date();
const localNowStr = `${nowInit.getFullYear()}-${pad(nowInit.getMonth()+1)}-${pad(nowInit.getDate())}T${pad(nowInit.getHours())}:${pad(nowInit.getMinutes())}`;
const orderDate = ref(localNowStr);

// Serial search results from API
const serialSearchResults = ref([]);
let serialSearchTimeout;

const filteredProducts = computed(() => {
    if (!searchQuery.value) return [];
    const query = searchQuery.value.toLowerCase();
    const localResults = props.products.filter(p => 
        p.name.toLowerCase().includes(query) || 
        p.sku.toLowerCase().includes(query) ||
        (p.barcode && p.barcode.toLowerCase().includes(query))
    ).slice(0, 10);
    
    // Merge serial search results (avoid duplicates)
    const localIds = new Set(localResults.map(p => p.id));
    const serialProducts = serialSearchResults.value.filter(p => !localIds.has(p.id));
    return [...localResults, ...serialProducts].slice(0, 15);
});

// Watch search query to also search by serial number via API
watch(searchQuery, (val) => {
    clearTimeout(serialSearchTimeout);
    serialSearchResults.value = [];
    if (!val || val.length < 2) return;
    serialSearchTimeout = setTimeout(async () => {
        try {
            const res = await axios.get('/api/purchase-orders/search-by-serial', { params: { q: val } });
            serialSearchResults.value = res.data || [];
        } catch (e) {
            serialSearchResults.value = [];
        }
    }, 300);
});

const selectProduct = (product) => {
    const existing = items.value.find(i => i.product_id === product.id);
    if (!existing) {
        items.value.unshift({ 
            product_id: product.id,
            sku: product.sku,
            name: product.name,
            qty: 1,
            price: product.cost_price || 0, // Giá vốn / Giá nhập
            discount: 0,
            stock_quantity: product.stock_quantity || 0,
        });
    } else {
        existing.qty++;
    }
    searchQuery.value = '';
    showSuggestions.value = false;
};

const hideSuggestions = () => {
    setTimeout(() => { showSuggestions.value = false; }, 200);
};

const removeItem = (index) => {
    items.value.splice(index, 1);
};

const itemsComputed = computed(() => {
    return items.value.map(item => {
        const qty = parseInt(item.qty) || 0;
        const price = parseFloat(item.price) || 0;
        const itemDiscount = parseFloat(item.discount) || 0;
        return {
            ...item,
            total_value: (qty * price) - itemDiscount
        };
    });
});

const totalAmount = computed(() => itemsComputed.value.reduce((sum, item) => sum + item.total_value, 0));
const totalPayment = computed(() => totalAmount.value - Number(discount.value) + Number(importFee.value) + Number(otherImportFee.value));

const save = async (saveStatus) => {
    if (items.value.length === 0) {
        alert("Vui lòng chọn ít nhất 1 hàng hóa để đặt hàng.");
        return;
    }

    submitRef.value = true;
    
    try {
        await router.post('/purchase-orders', {
            code: props.purchaseOrderCode,
            status: saveStatus || status.value,
            branch_id: props.defaultBranchId,
            supplier_id: selectedSupplierId.value || null,
            expected_date: expectedDate.value || null,
            order_date: orderDate.value ? new Date(orderDate.value).toISOString() : null,
            note: note.value,
            discount: discount.value,
            import_fee: importFee.value,
            other_import_fee: otherImportFee.value,
            items: itemsComputed.value
        });
    } catch (e) {
        alert("Có lỗi xảy ra, vui lòng kiểm tra lại dữ liệu.");
        submitRef.value = false;
    }
};

const formatCurrency = (val) => Number(val).toLocaleString('vi-VN');

</script>

<template>
    <Head title="Đặt hàng nhập - KiotViet Clone" />
    <div class="h-screen flex flex-col bg-[#eef1f5] text-[13px] overflow-hidden font-sans">
        
        <!-- Header -->
        <header class="bg-white text-gray-800 px-4 h-[56px] flex items-center justify-between border-b border-gray-200 shadow-sm flex-shrink-0">
            <div class="flex items-center gap-4 flex-1">
                <Link href="/purchase-orders" class="text-gray-600 hover:text-blue-600 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                </Link>
                <div class="text-xl font-bold text-gray-800 mr-4">Đặt hàng nhập</div>
                
                <div class="relative w-full max-w-[500px]">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                    <input v-model="searchQuery" @focus="showSuggestions = true" @blur="hideSuggestions" type="text" class="w-full pl-9 pr-12 py-[9px] border border-gray-300 text-gray-800 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 bg-white" placeholder="Tìm hàng hóa theo tên, mã, barcode, serial (F3)">
                    
                    <!-- Suggestions Dropdown -->
                    <div v-if="showSuggestions && filteredProducts.length > 0" class="absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 shadow-xl rounded-sm z-50 max-h-[300px] overflow-auto">
                        <div v-for="product in filteredProducts" :key="product.id" @mousedown.prevent="selectProduct(product)" class="flex items-center gap-3 p-2 border-b border-gray-100 hover:bg-gray-50 cursor-pointer">
                            <img :src="product.image || 'https://ui-avatars.com/api/?name=' + product.name + '&background=random'" class="w-10 h-10 object-cover rounded border border-gray-200">
                            <div class="flex-1">
                                <div class="font-medium text-[13px] text-gray-800">{{ product.name }}</div>
                                <div class="text-[12px] text-gray-500">
                                    {{ product.sku }}
                                    <span v-if="product.barcode" class="ml-2 text-gray-400">BC: {{ product.barcode }}</span>
                                    <span v-if="product.matched_serial" class="ml-2 text-purple-500 font-semibold">SN: {{ product.matched_serial }}</span>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-blue-600 font-medium text-[13px]">{{ formatCurrency(product.cost_price) }}</div>
                                <div class="text-[12px] text-gray-400">Tồn: {{ product.stock_quantity || 0 }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="absolute inset-y-0 right-0 pr-2 flex items-center gap-1.5 text-gray-400">
                        <button class="hover:text-blue-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg></button>
                        <button class="hover:text-blue-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg></button>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3 text-gray-500">
                 <button class="hover:bg-gray-100 p-2 rounded"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg></button>
                 <button class="hover:bg-gray-100 p-2 rounded"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg></button>
                 <button class="hover:bg-gray-100 p-2 rounded"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg></button>
                 <button class="hover:bg-gray-100 p-2 rounded"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg></button>
            </div>
        </header>

        <!-- Main Content -->
        <div class="flex-1 flex overflow-hidden">
            <!-- Left Panel: Table/Items -->
            <div class="flex-1 flex flex-col bg-white overflow-hidden shadow-[1px_0_0_rgba(0,0,0,0.05)] border-r border-gray-200">
                <div class="flex-1 overflow-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-[#f4f7fe] text-gray-700 font-bold sticky top-0 z-10 shadow-sm border-b border-gray-200 mt-2">
                            <tr>
                                <th class="p-3 w-12 text-center">STT</th>
                                <th class="p-3 w-[120px]">Mã hàng</th>
                                <th class="p-3">Tên hàng</th>
                                <th class="p-3 w-[80px] text-center">ĐVT</th>
                                <th class="p-3 w-[100px] text-center">Số lượng</th>
                                <th class="p-3 w-[120px] text-right">Đơn giá</th>
                                <th class="p-3 w-[100px] text-right">Giảm giá</th>
                                <th class="p-3 w-[140px] text-right pr-6">Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody v-if="items.length > 0">
                            <tr v-for="(item, index) in itemsComputed" :key="item.product_id" class="border-b border-gray-100 hover:bg-[#f8fafc] transition-colors">
                                <td class="p-3 text-center text-gray-500 group relative w-12">
                                    <span class="group-hover:hidden">{{ index + 1 }}</span>
                                    <button @click="removeItem(index)" class="hidden group-hover:flex items-center justify-center w-5 h-5 bg-red-500 hover:bg-red-600 text-white rounded-full mx-auto" title="Xóa">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                    </button>
                                </td>
                                <td class="p-3 text-gray-700 w-[120px]">{{ item.sku }}</td>
                                <td class="p-3 font-medium text-blue-600">{{ item.name }}</td>
                                <td class="p-3 text-center w-[80px]">Cái</td>
                                <td class="p-3 text-center w-[100px]">
                                    <input type="number" v-model="item.qty" min="1" class="w-[70px] border-b border-dashed border-gray-400 py-1 text-center outline-none focus:border-blue-500 text-[13px] hover:bg-yellow-50 font-medium">
                                </td>
                                <td class="p-3 w-[120px]">
                                    <input type="number" v-model="item.price" class="w-full border-b border-dashed border-gray-400 py-1 text-right outline-none focus:border-blue-500 text-[13px] hover:bg-yellow-50 font-medium tracking-wide">
                                </td>
                                <td class="p-3 w-[100px]">
                                    <input type="number" v-model="item.discount" class="w-full border-b border-dashed border-gray-400 py-1 text-right outline-none focus:border-blue-500 text-[13px] hover:bg-yellow-50">
                                </td>
                                <td class="p-3 font-bold text-gray-800 text-right w-[140px] pr-6">{{ formatCurrency(item.total_value) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div v-if="items.length === 0" class="h-full flex flex-col items-center justify-center min-h-[400px]">
                        <div class="text-center">
                            <h3 class="font-bold text-gray-800 text-[16px] mb-2 mt-12">Thêm sản phẩm từ file excel</h3>
                            <p class="text-gray-500 mb-6 text-[13px]">(Tải về file mẫu: <a href="#" class="text-blue-600 hover:underline">File đặt hàng nhập</a>)</p>
                            <button class="bg-[#0060df] hover:bg-[#003ea1] text-white font-semibold py-2 px-6 rounded shadow-sm text-[14px] flex items-center justify-center w-full max-w-[180px] mx-auto transition-colors">
                                <svg class="w-5 h-5 mr-2 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg> Chọn file dữ liệu
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Info -->
            <div class="w-[340px] flex-shrink-0 flex flex-col bg-white z-20 shadow-[-1px_0_5px_rgba(0,0,0,0.03)] border-l border-gray-200 relative overflow-visible">
                <!-- Top Tabs / Info block -->
                <div class="flex items-center justify-between p-3 border-b border-gray-200 bg-white">
                    <div class="flex items-center gap-2 px-1">
                        <div class="w-6 h-6 bg-gray-200 rounded-full flex items-center justify-center">
                            <svg class="w-4 h-4 text-gray-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path></svg>
                        </div>
                        <span class="font-medium text-[13px] text-gray-700">Trần Văn Tiến <span class="text-gray-400 font-normal ml-1">▼</span></span>
                    </div>
                    <input type="datetime-local" v-model="orderDate" class="text-[13px] text-gray-500 bg-transparent border-b border-dashed border-gray-300 outline-none focus:border-blue-500 py-0.5 w-[170px]" />
                </div>

                <div class="flex-1 overflow-auto bg-white flex flex-col pt-2">
                    <!-- Right Sidebar Fields -->
                    <div class="px-3 pb-3">
                        
                        <!-- NCC field -->
                        <div class="relative mb-3 flex items-center border-b border-gray-300 pb-1">
                            <svg class="w-4 h-4 text-gray-400 absolute left-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                            <select v-model="selectedSupplierId" class="w-full pl-7 pr-8 py-1 outline-none text-[13px] text-gray-800 bg-transparent appearance-none">
                                <option value="">Tìm nhà cung cấp</option>
                                <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option>
                            </select>
                            <button class="absolute right-1 text-gray-400 hover:text-blue-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg></button>
                        </div>

                        <div class="space-y-3.5 mt-4">
                            <!-- Field row -->
                            <div class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium">Mã đặt hàng nhập</label>
                                <input type="text" :value="purchaseOrderCode" disabled class="w-[150px] text-right border-b border-transparent hover:border-gray-200 px-1 py-0.5 outline-none text-gray-500 bg-transparent" placeholder="Mã phiếu tự động">
                            </div>

                            <div class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium">Trạng thái</label>
                                <select v-model="status" class="w-[150px] border border-gray-300 rounded px-2 py-1 outline-none text-gray-700 focus:border-blue-500">
                                    <option value="draft">Phiếu tạm</option>
                                    <option value="confirmed">Đã xác nhận NCC</option>
                                    <option value="partial">Nhập một phần</option>
                                    <option value="completed">Hoàn thành</option>
                                </select>
                            </div>

                            <div class="flex justify-between items-center text-[13px] pt-1">
                                <label class="text-gray-700 font-medium flex items-center gap-1">Tổng tiền hàng <span class="bg-gray-100 text-gray-500 px-1.5 rounded text-[11px] font-bold border border-gray-200 leading-tight">0</span></label>
                                <div class="w-[150px] text-right font-bold text-gray-800 tracking-wide">{{ formatCurrency(totalAmount) }}</div>
                            </div>

                            <div class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium">Giảm giá</label>
                                <div class="relative w-[150px]">
                                    <input type="number" v-model="discount" class="w-full border-b border-dashed border-gray-300 text-right pr-2 py-0.5 outline-none focus:border-blue-500 hover:bg-yellow-50">
                                </div>
                            </div>

                            <div class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium">Chi phí nhập hàng</label>
                                <input type="number" v-model="importFee" class="w-[150px] border-b border-dashed border-gray-300 text-right pr-2 py-0.5 outline-none focus:border-blue-500 hover:bg-yellow-50">
                            </div>

                            <div class="flex justify-between items-center text-[13px] pt-2">
                                <label class="text-gray-800 font-bold">Cần trả nhà cung cấp</label>
                                <div class="w-[150px] text-right font-bold text-blue-600 tracking-wide">{{ formatCurrency(totalPayment) }}</div>
                            </div>

                            <div class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium">Tiền nhà cung cấp trả lại</label>
                                <div class="w-[150px] text-right font-bold text-gray-800 tracking-wide">0</div>
                            </div>

                             <div class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium text-blue-600 flex items-center gap-1 cursor-pointer">Chi phí nhập khác <span>→</span></label>
                                <input type="number" v-model="otherImportFee" class="w-[150px] border-b border-dashed border-gray-300 text-right pr-2 py-0.5 outline-none focus:border-blue-500 hover:bg-yellow-50">
                            </div>

                             <div class="flex justify-between items-center text-[13px]">
                                <label class="text-gray-700 font-medium">Dự kiến ngày nhập hàng</label>
                                <input type="date" v-model="expectedDate" class="w-[150px] border border-gray-300 rounded px-2 py-1 text-gray-600 outline-none focus:border-blue-500">
                            </div>

                            <div class="pt-2">
                                <div class="flex items-center border border-gray-300 rounded focus-within:border-blue-500 p-2 text-[13px] bg-white text-gray-600">
                                     <svg class="w-3.5 h-3.5 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                     <input type="text" v-model="note" placeholder="Ghi chú" class="w-full outline-none bg-transparent">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="p-4 bg-white border-t border-gray-200 flex gap-2 flex-shrink-0">
                    <button @click="save('draft')" :disabled="submitRef" class="flex-1 bg-[#0060df] hover:bg-[#003ea1] text-white font-medium py-2.5 rounded shadow-sm flex items-center justify-center gap-2 transition-colors disabled:opacity-50 text-[14px]">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg> Đặt và gửi email
                    </button>
                    <button @click="save('completed')" :disabled="submitRef" class="w-[130px] bg-[#00b214] hover:bg-[#009b11] text-white font-medium py-2.5 rounded shadow-sm flex items-center justify-center gap-2 transition-colors disabled:opacity-50 text-[14px]">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg> Đặt hàng nhập
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
