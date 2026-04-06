<script setup>
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    products: Array,
    branches: Array,
    transferCode: String
});

const items = ref([]);
const fromBranchId = ref(props.branches.length > 0 ? props.branches[0].id : '');
const toBranchId = ref('');
const note = ref('');
const status = ref('draft');

const submitRef = ref(false);

const pad = (n) => String(n).padStart(2, '0');
const nowInit = new Date();
const localNowStr = `${nowInit.getFullYear()}-${pad(nowInit.getMonth()+1)}-${pad(nowInit.getDate())}T${pad(nowInit.getHours())}:${pad(nowInit.getMinutes())}`;
const transactionDate = ref(localNowStr);

const searchQuery = ref('');
const showSuggestions = ref(false);

const filteredProducts = ref([]);
const isSearchingProduct = ref(false);

let searchTimeout = null;
watch(searchQuery, (val) => {
    if (!val) {
        filteredProducts.value = [];
        showSuggestions.value = false;
        return;
    }
    showSuggestions.value = true;
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(async () => {
        isSearchingProduct.value = true;
        try {
            const response = await axios.get('/api/products/search', {
                params: { search: val }
            });
            filteredProducts.value = response.data;
        } catch (error) {
            console.error("Lỗi tìm kiếm sản phẩm:", error);
        } finally {
            isSearchingProduct.value = false;
        }
    }, 300);
});

const selectProduct = (product) => {
    const existing = items.value.find(i => i.id === product.id);
    if (existing) {
        existing.quantity++;
    } else {
        items.value.unshift({ ...product, quantity: 1, transfer_price: product.cost_price || 0 });
    }
    searchQuery.value = '';
    showSuggestions.value = false;
};

const hideSuggestions = () => {
    setTimeout(() => {
        showSuggestions.value = false;
    }, 200);
};

const removeItem = (index) => {
    items.value.splice(index, 1);
};

const totalQuantity = computed(() => {
    return items.value.reduce((sum, item) => sum + (Number(item.quantity) || 0), 0);
});

const totalPrice = computed(() => {
    return items.value.reduce((sum, item) => sum + ((Number(item.quantity) || 0) * (Number(item.transfer_price) || 0)), 0);
});

const save = async (actionStatus) => {
    if (items.value.length === 0) {
        alert("Vui lòng chọn ít nhất 1 hàng hóa để chuyển.");
        return;
    }
    status.value = actionStatus;
    submitRef.value = true;
    try {
        await axios.post('/stock-transfers', {
            code: props.transferCode,
            from_branch_id: fromBranchId.value,
            to_branch_id: toBranchId.value,
            status: status.value,
            action_date: transactionDate.value,
            note: note.value,
            items: items.value.map(i => ({
                product_id: i.id,
                quantity: i.quantity,
                price: i.transfer_price || 0
            }))
        });
        router.visit('/stock-transfers');
    } catch (e) {
        alert("Có lỗi xảy ra, vui lòng kiểm tra lại dữ liệu.");
        submitRef.value = false;
    }
};

const formatCurrency = (val) => Number(val).toLocaleString('vi-VN');

</script>

<template>
    <Head title="Tạo Phiếu Chuyển Hàng - KiotViet Clone" />
    <div class="h-screen flex flex-col bg-[#eef1f5] text-[13px] overflow-hidden font-sans">
        
        <!-- Header -->
        <header class="bg-white px-4 h-14 flex items-center justify-between border-b border-gray-200 shadow-sm flex-shrink-0">
            <div class="flex items-center w-2/3 gap-4">
                <Link href="/stock-transfers" class="text-gray-600 hover:text-gray-900 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                </Link>
                <h1 class="text-[18px] font-bold text-gray-800">Chuyển hàng</h1>
                
                <div class="relative flex-1 max-w-[500px]">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                    <input v-model="searchQuery" @focus="showSuggestions = true" @blur="hideSuggestions" type="text" class="w-full pl-9 pr-12 py-1.5 border-2 border-blue-400 rounded-sm focus:outline-none focus:border-blue-500 shadow-inner" placeholder="Tìm hàng hóa theo mã hoặc tên (F3)">
                    
                    <!-- Suggestions Dropdown -->
                    <div v-if="showSuggestions" class="absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 shadow-lg rounded-sm z-50 max-h-[300px] overflow-auto">
                        <div v-if="isSearchingProduct" class="p-3 text-sm text-gray-500 text-center">
                            Đang tìm kiếm...
                        </div>
                        <div v-else-if="filteredProducts.length === 0 && searchQuery" class="p-3 text-sm text-gray-500 text-center">
                            Không tìm thấy sản phẩm hợp lệ
                        </div>
                        <div v-for="product in filteredProducts" :key="product.id" @mousedown.prevent="selectProduct(product)" class="flex items-center gap-3 p-2 border-b border-gray-100 hover:bg-gray-50 cursor-pointer">
                            <img :src="product.image || 'https://ui-avatars.com/api/?name=' + product.name + '&background=random'" class="w-10 h-10 object-cover rounded border border-gray-200">
                            <div class="flex-1">
                                <div class="font-medium text-[13px] text-gray-800">{{ product.name }}</div>
                                <div class="text-[12px] text-gray-500">{{ product.sku }}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-blue-600 font-medium text-[13px]">{{ formatCurrency(product.cost_price) }}</div>
                                <div class="text-[12px] text-gray-400">Tồn: {{ product.stock_quantity || 0 }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="absolute inset-y-0 right-0 pr-2 flex items-center gap-1.5 text-gray-400">
                        <svg class="w-4 h-4 hover:text-gray-600 cursor-pointer" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                        <svg class="w-4 h-4 hover:text-gray-600 cursor-pointer" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    </div>
                </div>
            </div>

            <div class="flex gap-2">
                <button class="px-3 py-1.5 border border-gray-300 rounded bg-white hover:bg-gray-50 text-gray-700 shadow-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg></button>
                <button class="px-3 py-1.5 border border-gray-300 rounded bg-white hover:bg-gray-50 text-gray-700 shadow-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg></button>
                <button class="px-3 py-1.5 border border-gray-300 rounded bg-white hover:bg-gray-50 text-gray-700 shadow-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg></button>
                <button class="px-3 py-1.5 border border-gray-300 rounded bg-white hover:bg-gray-50 text-gray-700 shadow-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg></button>
            </div>
        </header>

        <!-- Main Content -->
        <div class="flex-1 flex overflow-hidden">
            <!-- Left Panel: Table/Items -->
            <div class="flex-1 flex flex-col bg-white overflow-hidden shadow-[1px_0_0_rgba(0,0,0,0.05)] border-r border-gray-200">
                <div class="flex-1 overflow-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-[#f0f4f9] text-[#1a56bc] font-bold sticky top-0 z-10 shadow-[0_1px_0_rgba(200,200,200,0.5)]">
                            <tr>
                                <th class="p-3 w-12 text-center border-b border-[#dce3ec]">STT</th>
                                <th class="p-3 w-[120px] border-b border-[#dce3ec]">Mã hàng</th>
                                <th class="p-3 leading-tight border-b border-[#dce3ec]">Tên hàng</th>
                                <th class="p-3 w-16 text-center border-b border-[#dce3ec]">ĐVT</th>
                                <th class="p-3 w-20 text-right border-b border-[#dce3ec]">Tồn kho</th>
                                <th class="p-3 w-24 text-right border-b border-[#dce3ec]">Tồn kho nhận</th>
                                <th class="p-3 w-28 text-center border-b border-[#dce3ec]">SL chuyển</th>
                                <th class="p-3 w-[120px] text-right border-b border-[#dce3ec]">Giá chuyển</th>
                                <th class="p-3 w-[120px] text-right border-b border-[#dce3ec]">Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody v-if="items.length > 0">
                            <tr v-for="(item, index) in items" :key="item.id" class="border-b border-gray-100 hover:bg-[#f0f9ff]/40 transition-colors">
                                <td class="p-3 text-center text-gray-500 group relative w-12">
                                    <span class="group-hover:hidden">{{ index + 1 }}</span>
                                    <button @click="removeItem(index)" class="hidden group-hover:flex items-center justify-center w-5 h-5 bg-red-500 hover:bg-red-600 text-white rounded-full mx-auto" title="Xóa">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                    </button>
                                </td>
                                <td class="p-3 text-blue-600 w-[120px] break-all">{{ item.sku }}</td>
                                <td class="p-3 font-medium text-gray-800">{{ item.name }}</td>
                                <td class="p-3 text-center w-16">Cái</td>
                                <td class="p-3 text-right w-20" :class="(item.stock_quantity || 0) <= 0 ? 'text-red-500 font-bold' : (item.stock_quantity || 0) < (item.quantity || 0) ? 'text-orange-500 font-semibold' : ''">
                                    {{ item.stock_quantity || 0 }}
                                    <div v-if="(item.stock_quantity || 0) <= 0" class="text-[10px]">Hết hàng!</div>
                                    <div v-else-if="(item.stock_quantity || 0) < (item.quantity || 0)" class="text-[10px]">Không đủ!</div>
                                </td>
                                <td class="p-3 text-right w-24">0</td>
                                <td class="p-3 text-center w-28">
                                    <input type="number" v-model="item.quantity" min="1" class="w-16 border border-gray-300 rounded p-1 text-center outline-none focus:border-blue-500 text-[13px] transition-colors mx-auto block">
                                </td>
                                <td class="p-3 text-right w-[120px]">
                                    <input type="number" v-model="item.transfer_price" min="0" class="w-full border border-gray-300 rounded p-1 inline-block text-right outline-none focus:border-blue-500 text-[13px] transition-colors">
                                </td>
                                <td class="p-3 font-bold text-gray-800 text-right w-[120px]">{{ formatCurrency((item.quantity || 0) * (item.transfer_price || 0)) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div v-if="items.length === 0" class="h-full flex flex-col items-center justify-center min-h-[400px]">
                        <div class="text-center">
                            <h3 class="font-bold text-gray-800 text-[18px] mb-2">Thêm sản phẩm từ file excel</h3>
                            <p class="text-gray-500 mb-6">(Tải về file mẫu: <a href="#" class="text-blue-600 hover:underline">Excel file</a>)</p>
                            <button class="bg-[#2470e8] hover:bg-blue-700 text-white font-semibold py-2.5 px-6 rounded shadow-sm text-[14px] flex items-center justify-center w-full max-w-[200px] mx-auto transition-colors">
                                <svg class="w-5 h-5 mr-2 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg> Chọn file dữ liệu
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Info -->
            <div class="w-[340px] flex-shrink-0 flex flex-col bg-white shadow-[-1px_0_0_rgba(0,0,0,0.05)]">
                <div class="flex-1 overflow-auto p-4 flex flex-col gap-5">
                    
                    <div class="flex justify-between items-center text-gray-700 pb-2 border-b border-gray-100">
                        <div class="flex items-center gap-2 font-medium">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                            Trần Văn Tiến
                        </div>
                        <input type="datetime-local" v-model="transactionDate" class="text-gray-500 text-[12px] bg-gray-50 px-2 py-0.5 rounded border border-gray-200 outline-none focus:border-blue-500 hover:border-blue-400 font-medium">
                    </div>

                    <div class="grid grid-cols-[100px_1fr] items-center gap-y-5 gap-x-2">
                        <div class="text-gray-600 font-medium">Mã chuyển hàng</div>
                        <div>
                            <input type="text" class="w-full border border-gray-300 rounded p-1.5 focus:border-blue-500 outline-none text-[13px] hover:border-blue-400 placeholder:text-gray-400" placeholder="Mã phiếu tự động">
                        </div>

                        <div class="text-gray-600 font-medium">Trạng thái</div>
                        <div class="font-medium text-gray-800">
                            Phiếu tạm
                        </div>

                        <div class="text-gray-600 font-medium">Tổng số lượng</div>
                        <div class="font-bold text-gray-800 text-[15px]">
                            {{ formatCurrency(totalQuantity) }}
                        </div>

                        <div class="text-gray-600 font-medium">Tổng giá trị</div>
                        <div class="font-bold text-gray-800 text-[15px]">
                            {{ formatCurrency(totalPrice) }}
                        </div>

                        <div class="text-gray-600 font-medium">Chuyển tới</div>
                        <div>
                            <select v-model="toBranchId" class="w-full border border-gray-300 rounded p-1.5 focus:border-blue-500 outline-none text-[13px] hover:border-blue-400 appearance-none bg-white bg-no-repeat bg-right text-gray-700" style="background-image: url('data:image/svg+xml;charset=UTF-8,%3csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3e%3cpolyline points=\'6 9 12 15 18 9\'%3e%3c/polyline%3e%3c/svg%3e'); background-position: right 0.5rem center; background-size: 1em;">
                                <option value="">Chọn chi nhánh</option>
                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-2 text-gray-600">
                        <div class="relative">
                            <svg class="w-4 h-4 absolute left-2 top-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                            <textarea v-model="note" class="w-full border border-gray-300 rounded p-2 pl-8 focus:border-blue-500 outline-none transition-colors text-[13px] resize-none h-24 hover:border-blue-400" placeholder="Ghi chú"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Footer buttons -->
                <div class="flex gap-2 p-4 border-t border-gray-200">
                    <button @click="save('draft')" :disabled="submitRef" class="flex-1 bg-[#2470e8] hover:bg-blue-700 text-white font-bold py-3 rounded-sm text-[14px] flex flex-col items-center justify-center leading-tight transition-colors shadow-sm disabled:opacity-75">
                        <svg class="w-5 h-5 mb-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path></svg>
                        Lưu tạm
                    </button>
                    <button @click="save('transferring')" :disabled="submitRef" class="flex-1 bg-[#0eb45e] hover:bg-green-600 text-white font-bold py-3 rounded-sm text-[14px] flex flex-col items-center justify-center leading-tight transition-colors shadow-sm disabled:opacity-75">
                        <svg class="w-5 h-5 mb-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        Hoàn thành
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
/* Remove standard layout wrappers if necessary by overriding body */
</style>
