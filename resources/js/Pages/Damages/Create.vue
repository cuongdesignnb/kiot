<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    products: Array,
    branches: Array,
    defaultBranchId: Number,
    damageCode: String
});

const pad = (n) => String(n).padStart(2, '0');
const nowInit = new Date();
const localNowStr = `${nowInit.getFullYear()}-${pad(nowInit.getMonth()+1)}-${pad(nowInit.getDate())}T${pad(nowInit.getHours())}:${pad(nowInit.getMinutes())}`;
const transactionDate = ref(localNowStr);

const searchQuery = ref('');
const showSuggestions = ref(false);
const items = ref([]);
const note = ref('');
const submitRef = ref(false);
const selectedBranch = ref(props.defaultBranchId || '');

const filteredProducts = computed(() => {
    if (!searchQuery.value) return [];
    const query = searchQuery.value.toLowerCase();
    return props.products.filter(p => 
        p.name.toLowerCase().includes(query) || 
        p.sku.toLowerCase().includes(query)
    ).slice(0, 10);
});

const selectProduct = (product) => {
    const existing = items.value.find(i => i.product_id === product.id);
    if (!existing) {
        items.value.unshift({ 
            product_id: product.id,
            sku: product.sku,
            name: product.name,
            qty: 1,
            cost_price: product.cost_price || 0,
            stock_quantity: product.stock_quantity || 0,
        });
    } else {
        existing.qty++;
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

const itemsComputed = computed(() => {
    return items.value.map(item => {
        const qty = parseInt(item.qty) || 0;
        return {
            ...item,
            total_value: qty * item.cost_price
        };
    });
});

const totalQty = computed(() => itemsComputed.value.reduce((sum, item) => sum + (parseInt(item.qty) || 0), 0));
const totalValue = computed(() => itemsComputed.value.reduce((sum, item) => sum + item.total_value, 0));

const save = async (status) => {
    if (items.value.length === 0) {
        alert("Vui lòng chọn ít nhất 1 hàng hóa để xuất hủy.");
        return;
    }

    if (!selectedBranch.value) {
        alert("Vui lòng chọn chi nhánh xuất hủy.");
        return;
    }

    submitRef.value = true;
    
    try {
        await router.post('/damages', {
            code: props.damageCode,
            status: status, // 'draft' | 'completed'
            branch_id: selectedBranch.value,
            action_date: transactionDate.value,
            note: note.value,
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
    <Head title="Tạo Phiếu Xuất Hủy - KiotViet Clone" />
    <div class="h-screen flex flex-col bg-[#eef1f5] text-[13px] overflow-hidden font-sans">
        
        <!-- Header -->
        <header class="bg-[#005bb5] text-white px-4 h-[50px] flex items-center justify-between shadow-sm flex-shrink-0">
            <div class="flex items-center gap-4 flex-1">
                <Link href="/damages" class="text-white hover:text-blue-100 transition-colors flex items-center gap-2 font-medium">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg> Xuất hủy
                </Link>
                
                <div class="relative w-full max-w-[600px] ml-4">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                    <input v-model="searchQuery" @focus="showSuggestions = true" @blur="hideSuggestions" type="text" class="w-full pl-9 pr-12 py-[7px] border-none text-gray-800 rounded-sm focus:outline-none focus:ring-2 focus:ring-blue-300 bg-white shadow-inner" placeholder="Tìm hàng hóa theo mã hoặc tên (F3)">
                    
                    <!-- Suggestions Dropdown -->
                    <div v-if="showSuggestions && filteredProducts.length > 0" class="absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 shadow-xl rounded-sm z-50 max-h-[300px] overflow-auto text-black">
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
                        <svg class="w-4 h-4 hover:text-gray-600 cursor-pointer" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                 <button class="text-white hover:bg-[#00478f] px-2 py-1.5 rounded transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg></button>
                 <button class="text-white hover:bg-[#00478f] px-2 py-1.5 rounded transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg></button>
                 <button class="text-white hover:bg-[#00478f] px-2 py-1.5 rounded transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg></button>
                 <button class="text-white hover:bg-[#00478f] px-2 py-1.5 rounded transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg></button>
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
                                <th class="p-3 border-b border-[#dce3ec]">Tên hàng</th>
                                <th class="p-3 w-[100px] text-center border-b border-[#dce3ec]">ĐVT</th>
                                <th class="p-3 w-[100px] text-center border-b border-[#dce3ec]">Tồn kho</th>
                                <th class="p-3 w-[120px] text-center border-b border-[#dce3ec]">SL hủy</th>
                                <th class="p-3 w-32 text-right border-b border-[#dce3ec]">Giá vốn</th>
                                <th class="p-3 w-[140px] text-right border-b border-[#dce3ec] pr-6">Giá trị hủy</th>
                            </tr>
                        </thead>
                        <tbody v-if="items.length > 0">
                            <tr v-for="(item, index) in itemsComputed" :key="item.product_id" class="border-b border-gray-100 hover:bg-[#f0f9ff]/40 transition-colors">
                                <td class="p-3 text-center text-gray-500 group relative w-12">
                                    <span class="group-hover:hidden">{{ index + 1 }}</span>
                                    <button @click="removeItem(index)" class="hidden group-hover:flex items-center justify-center w-5 h-5 bg-red-500 hover:bg-red-600 text-white rounded-full mx-auto" title="Xóa">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                    </button>
                                </td>
                                <td class="p-3 text-blue-600 w-[120px] break-all">{{ item.sku }}</td>
                                <td class="p-3 font-medium text-gray-800">{{ item.name }}</td>
                                <td class="p-3 text-center w-[100px]">Cái</td>
                                <td class="p-3 text-center w-[100px] text-gray-500">{{ item.stock_quantity }}</td>
                                <td class="p-3 text-center w-[120px]">
                                    <input type="number" v-model="item.qty" min="1" class="w-20 border border-gray-300 rounded-sm py-1.5 px-2 text-right outline-none focus:border-blue-500 text-[13px] transition-colors mx-auto block font-semibold shadow-inner bg-blue-50/30">
                                </td>
                                <td class="p-3 font-bold text-gray-600 text-right w-32">{{ formatCurrency(item.cost_price) }}</td>
                                <td class="p-3 font-bold text-blue-700 text-right w-[140px] pr-6">{{ formatCurrency(item.total_value) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div v-if="items.length === 0" class="h-full flex flex-col items-center justify-center min-h-[400px]">
                        <div class="text-center">
                            <h3 class="font-bold text-gray-800 text-[18px] mb-2">Thêm sản phẩm từ file excel</h3>
                            <p class="text-gray-500 mb-6">(Tải về file mẫu: <a href="#" class="text-blue-600 hover:underline">Excel file</a>)</p>
                            <button class="bg-[#1a56bc] hover:bg-blue-800 text-white font-semibold py-2.5 px-6 rounded shadow-sm text-[14px] flex items-center justify-center w-full max-w-[200px] mx-auto transition-colors">
                                <svg class="w-5 h-5 mr-2 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg> Chọn file dữ liệu
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Info -->
            <div class="w-[340px] flex-shrink-0 flex flex-col bg-white shadow-[-1px_0_0_rgba(0,0,0,0.05)] z-20">
                <div class="flex-1 overflow-auto bg-gray-50 flex flex-col">
                    
                    <div class="p-4 flex items-center gap-2 border-b border-gray-200 bg-white justify-between">
                        <div class="flex items-center gap-2">
                             <div class="w-7 h-7 bg-gray-200 rounded-full flex items-center justify-center border border-gray-300 shadow-inner">
                                <svg class="w-4 h-4 text-gray-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path></svg>
                            </div>
                            <span class="font-medium text-gray-800">Trần Văn Tiến</span>
                        </div>
                        <input type="datetime-local" v-model="transactionDate" class="text-gray-500 text-[12px] bg-gray-100 px-2 py-0.5 rounded border border-gray-200 outline-none focus:border-blue-500 hover:border-blue-400">
                    </div>

                    <div class="p-4 flex flex-col gap-4 bg-white border-b border-gray-200 flex-1">
                        
                         <div class="flex items-center gap-3">
                            <label class="font-medium text-gray-700 w-[100px]">Chi nhánh</label>
                            <select v-model="selectedBranch" class="flex-1 border border-gray-300 rounded px-2.5 py-1.5 focus:border-blue-500 outline-none text-[13px] bg-white transition-colors cursor-pointer shadow-inner">
                                <option disabled value="">Chi nhánh hủy</option>
                                <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                            </select>
                        </div>

                        <div class="flex items-center gap-3">
                            <label class="font-medium text-gray-700 w-[100px]">Mã xuất hủy</label>
                            <input type="text" :value="damageCode" disabled class="flex-1 border border-gray-200 bg-gray-50 rounded px-2.5 py-1.5 text-gray-500">
                        </div>
                        
                        <div class="flex items-center gap-3">
                            <label class="font-medium text-gray-700 w-[100px]">Trạng thái</label>
                            <div class="flex-1 font-medium text-gray-800">Phiếu tạm</div>
                        </div>

                        <div class="flex items-center gap-3 pt-2 items-center flex-wrap">
                            <label class="font-medium text-gray-700 w-full mb-1">Tổng giá trị hủy <span class="text-gray-400 font-normal">({{ totalQty }})</span></label>
                            <div class="w-full font-bold text-blue-600 text-[18px] text-right bg-blue-50/50 shadow-inner border border-blue-100 px-3 py-2 rounded">{{ formatCurrency(totalValue) }}</div>
                        </div>

                        <div class="flex flex-col gap-2 mt-1 flex-1">
                            <textarea v-model="note" placeholder="Ghi chú" class="w-full border border-gray-300 rounded p-2.5 h-full min-h-[120px] outline-none focus:border-blue-500 shadow-sm transition-colors text-[13px] resize-none"></textarea>
                        </div>
                    </div>

                </div>

                <!-- Action Buttons -->
                <div class="p-4 bg-white border-t border-gray-200 flex gap-3 flex-shrink-0">
                    <button @click="save('draft')" :disabled="submitRef" class="flex-1 bg-[#1a56bc] hover:bg-blue-800 text-white font-semibold py-2.5 rounded flex items-center justify-center gap-2 transition-colors disabled:opacity-50">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path></svg> Lưu tạm
                    </button>
                    <button @click="save('completed')" :disabled="submitRef" class="flex-1 bg-[#10b981] hover:bg-[#059669] text-white font-semibold py-2.5 rounded flex items-center justify-center gap-2 transition-colors disabled:opacity-50">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg> Hoàn thành
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
