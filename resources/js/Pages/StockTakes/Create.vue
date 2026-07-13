<script setup>
import { formatVND as formatCurrency } from '@/utils/money';
import { ref, computed, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import DateTimePicker from '@/Components/DateTimePicker.vue';
import CategorySelectModal from '@/Components/StockTakes/CategorySelectModal.vue';
import { nowDatetimeLocal } from '@/utils/dateTime.js';

const props = defineProps({
    products: Array,
    branches: Array,
    categories: Array,
    stockTakeCode: String,
});

const page = usePage();
const currentUser = computed(() => page.props.auth?.user);

const transactionDate = ref(nowDatetimeLocal());

const selectedBranch = ref('');

const searchQuery = ref('');
const showSuggestions = ref(false);
const items = ref([]);
const note = ref("");
const submitRef = ref(false);
const filteredProducts = ref([]);
const isSearchingProduct = ref(false);
const activeTab = ref("all");
const showCategoryModal = ref(false);
const isLoadingCategoryProducts = ref(false);
let isRevertingBranch = false;

const toNumber = (value) => (Number.isFinite(Number(value)) ? Number(value) : 0);

const diffQty = (item) => toNumber(item.actual_stock) - toNumber(item.system_stock);
const diffValue = (item) => diffQty(item) * toNumber(item.cost_price_snapshot ?? item.cost_price);

const checkedItems = computed(() => items.value.filter((item) => item.checked));
const uncheckedItems = computed(() => items.value.filter((item) => !item.checked));
const matchedItems = computed(() => items.value.filter((item) => item.checked && diffQty(item) === 0));
const diffItems = computed(() => items.value.filter((item) => item.checked && diffQty(item) !== 0));

const visibleItems = computed(() => {
    if (activeTab.value === "matched") return matchedItems.value;
    if (activeTab.value === "diff") return diffItems.value;
    if (activeTab.value === "unchecked") return uncheckedItems.value;
    return items.value;
});

const totalActualQty = computed(() => checkedItems.value.reduce((sum, item) => sum + toNumber(item.actual_stock), 0));
const totalDiffQty = computed(() => checkedItems.value.reduce((sum, item) => sum + diffQty(item), 0));
const totalDiffIncrease = computed(() => checkedItems.value.filter((item) => diffQty(item) > 0).reduce((sum, item) => sum + diffQty(item), 0));
const totalDiffDecrease = computed(() => checkedItems.value.filter((item) => diffQty(item) < 0).reduce((sum, item) => sum + diffQty(item), 0));
const totalDiffValue = computed(() => checkedItems.value.reduce((sum, item) => sum + diffValue(item), 0));

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
            const response = await axios.get("/api/stock-takes/products", {
                params: {
                    search: val,
                    branch_id: selectedBranch.value || undefined,
                    active_only: 1,
                    inventory_only: 1,
                },
            });
            filteredProducts.value = response.data;
        } catch (error) {
            console.error("Product search failed:", error);
        } finally {
            isSearchingProduct.value = false;
        }
    }, 300);
});

watch(selectedBranch, (newBranch, oldBranch) => {
    if (isRevertingBranch) {
        isRevertingBranch = false;
        return;
    }
    if (oldBranch && newBranch !== oldBranch && items.value.length > 0) {
        const ok = confirm("Đổi chi nhánh khi phiếu đã có hàng có thể làm snapshot tồn kho khác đi. Tiếp tục đổi chi nhánh?");
        if (!ok) {
            isRevertingBranch = true;
            selectedBranch.value = oldBranch;
        }
    }
});

const normalizeProduct = (product) => ({
    product_id: product.product_id ?? product.id,
    sku: product.sku,
    barcode: product.barcode,
    name: product.name,
    unit_name: product.unit_name || product.unit || "Cái",
    category_id: product.category_id,
    system_stock: Number(product.system_stock ?? product.stock_quantity ?? 0),
    actual_stock: null,
    cost_price_snapshot: Number(product.cost_price_snapshot ?? product.cost_price ?? 0),
    checked: false,
    has_serial: Boolean(product.has_serial),
});

const addProducts = (products) => {
    let added = 0;
    products.forEach((product) => {
        const item = normalizeProduct(product);
        if (!items.value.some((existing) => existing.product_id === item.product_id)) {
            items.value.unshift(item);
            added += 1;
        }
    });
    return added;
};

const selectProduct = (product) => {
    addProducts([product]);
    searchQuery.value = "";
    showSuggestions.value = false;
};

const hideSuggestions = () => {
    setTimeout(() => {
        showSuggestions.value = false;
    }, 200);
};

const removeItem = (item) => {
    const index = items.value.findIndex((existing) => existing.product_id === item.product_id);
    if (index >= 0) items.value.splice(index, 1);
};

const markChecked = (item) => {
    item.checked = item.actual_stock !== null && item.actual_stock !== "";
};

const markMatched = (item) => {
    item.actual_stock = toNumber(item.system_stock);
    item.checked = true;
};

const markAllMatched = () => {
    if (!items.value.length) return;
    if (!confirm("Đánh dấu tất cả hàng trong phiếu là khớp tồn kho?")) return;
    items.value.forEach(markMatched);
};

const loadCategoryProducts = async (options) => {
    isLoadingCategoryProducts.value = true;
    try {
        const response = await axios.get("/api/stock-takes/products", {
            params: {
                category_ids: options.category_ids,
                include_children: options.include_children ? 1 : 0,
                branch_id: selectedBranch.value || undefined,
                active_only: options.active_only ? 1 : 0,
                inventory_only: options.inventory_only ? 1 : 0,
                only_in_stock: options.only_in_stock ? 1 : 0,
                limit: 500,
            },
        });
        addProducts(response.data);
        showCategoryModal.value = false;
    } catch (error) {
        alert(error.response?.data?.message || "Không tải được danh sách hàng theo nhóm.");
    } finally {
        isLoadingCategoryProducts.value = false;
    }
};

const payloadItems = computed(() => items.value.map((item) => ({
    product_id: item.product_id,
    actual_stock: item.checked ? item.actual_stock : null,
    checked: Boolean(item.checked),
})));

const save = async (status) => {
    if (!selectedBranch.value) {
        alert("Vui lòng chọn chi nhánh kiểm kho trước khi lưu phiếu.");
        return;
    }
    if (items.value.length === 0) {
        alert("Vui lòng chọn ít nhất 1 hàng hóa để kiểm kho.");
        return;
    }
    if (status === "balanced" && uncheckedItems.value.length > 0) {
        alert("Không thể hoàn thành khi còn hàng chưa kiểm.");
        return;
    }
    if (status === "balanced" && items.value.some((item) => item.checked && (item.actual_stock === null || item.actual_stock === ""))) {
        alert("Không thể hoàn thành khi còn dòng chưa nhập số thực tế.");
        return;
    }

    submitRef.value = true;
    router.post("/stock-takes", {
        code: props.stockTakeCode,
        status,
        branch_id: selectedBranch.value,
        action_date: transactionDate.value,
        note: note.value,
        items: payloadItems.value,
    }, {
        onError: () => {
            submitRef.value = false;
        },
    });
};
</script>

<template>
    <Head title="Phiếu Kiểm Kho - KiotViet Clone" />
    <div class="h-screen flex flex-col bg-[#eef1f5] text-[13px] overflow-hidden font-sans">
        <header class="bg-[#005bb5] text-white px-4 h-[50px] flex items-center justify-between shadow-sm flex-shrink-0">
            <div class="flex items-center gap-4 flex-1">
                <Link href="/stock-takes" class="text-white hover:text-blue-100 transition-colors flex items-center gap-2 font-medium">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    Kiểm kho
                </Link>

                <div class="flex w-full max-w-[760px] ml-4 gap-2">
                    <div class="relative flex-1 min-w-[320px]">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        </div>
                        <input v-model="searchQuery" @focus="showSuggestions = true" @blur="hideSuggestions" type="text" class="w-full pl-9 pr-3 py-[7px] border-none text-gray-800 rounded-sm focus:outline-none focus:ring-2 focus:ring-blue-300 bg-white" placeholder="Tìm hàng hóa theo mã, tên hoặc barcode">

                        <div v-if="showSuggestions" class="absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 shadow-xl rounded-sm z-50 max-h-[300px] overflow-auto text-black">
                            <div v-if="isSearchingProduct" class="p-3 text-sm text-gray-500 text-center">Đang tìm kiếm...</div>
                            <div v-else-if="filteredProducts.length === 0 && searchQuery" class="p-3 text-sm text-gray-500 text-center">Không tìm thấy sản phẩm hợp lệ</div>
                            <div v-for="product in filteredProducts" :key="product.product_id || product.id" @mousedown.prevent="selectProduct(product)" class="flex items-center gap-3 p-2 border-b border-gray-100 hover:bg-gray-50 cursor-pointer">
                                <div class="w-10 h-10 object-cover rounded border border-gray-200 bg-gray-100 flex items-center justify-center text-gray-500 font-bold">{{ (product.name || "?").charAt(0) }}</div>
                                <div class="flex-1">
                                    <div class="font-medium text-[13px] text-gray-800">{{ product.name }}</div>
                                    <div class="text-[12px] text-gray-500">{{ product.sku }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-blue-600 font-medium text-[13px]">{{ formatCurrency(product.cost_price_snapshot ?? product.cost_price) }}</div>
                                    <div class="text-[12px] text-gray-400">Tồn: {{ product.system_stock ?? product.stock_quantity ?? 0 }}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button @click="showCategoryModal = true" class="h-[34px] px-3 flex items-center gap-2 bg-white text-[#005bb5] hover:bg-blue-50 border border-blue-200 rounded-sm font-semibold shadow-sm whitespace-nowrap" title="Chọn nhóm hàng">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707L14 14v4l-4 3v-7L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>
                        <span>Chọn nhóm hàng</span>
                    </button>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button @click="markAllMatched" class="text-white hover:bg-[#00478f] px-3 py-1.5 rounded transition-colors font-medium">
                    Đánh dấu khớp
                </button>
            </div>
        </header>

        <div class="bg-gray-100 flex items-center px-4 py-1.5 text-gray-500 border-b border-gray-200">
            <button @click="activeTab = 'all'" class="mr-6 font-medium pb-1 -mb-1.5" :class="activeTab === 'all' ? 'text-blue-600 border-b-2 border-blue-600' : 'hover:text-gray-800'">Tất cả ({{ items.length }})</button>
            <button @click="activeTab = 'matched'" class="mr-6 pb-1 -mb-1.5" :class="activeTab === 'matched' ? 'text-blue-600 border-b-2 border-blue-600' : 'hover:text-gray-800'">Khớp ({{ matchedItems.length }})</button>
            <button @click="activeTab = 'diff'" class="mr-6 pb-1 -mb-1.5" :class="activeTab === 'diff' ? 'text-blue-600 border-b-2 border-blue-600' : 'hover:text-gray-800'">Lệch ({{ diffItems.length }})</button>
            <button @click="activeTab = 'unchecked'" class="pb-1 -mb-1.5" :class="activeTab === 'unchecked' ? 'text-blue-600 border-b-2 border-blue-600' : 'hover:text-gray-800'">Chưa kiểm ({{ uncheckedItems.length }})</button>
        </div>

        <div class="flex-1 flex overflow-hidden">
            <div class="flex-1 flex flex-col bg-white overflow-hidden shadow-[1px_0_0_rgba(0,0,0,0.05)] border-r border-gray-200">
                <div class="flex-1 overflow-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-[#f0f4f9] text-[#1a56bc] font-bold sticky top-0 z-10 shadow-[0_1px_0_rgba(200,200,200,0.5)]">
                            <tr>
                                <th class="p-3 w-12 text-center border-b border-[#dce3ec]">STT</th>
                                <th class="p-3 w-[120px] border-b border-[#dce3ec]">Mã hàng</th>
                                <th class="p-3 leading-tight border-b border-[#dce3ec]">Tên hàng</th>
                                <th class="p-3 w-16 text-center border-b border-[#dce3ec]">ĐVT</th>
                                <th class="p-3 w-24 text-right border-b border-[#dce3ec]">Tồn kho</th>
                                <th class="p-3 w-28 text-center border-b border-[#dce3ec]">Thực tế</th>
                                <th class="p-3 w-20 text-center border-b border-[#dce3ec]">Khớp</th>
                                <th class="p-3 w-24 text-right border-b border-[#dce3ec]">SL lệch</th>
                                <th class="p-3 w-[120px] text-right border-b border-[#dce3ec]">Giá trị lệch</th>
                            </tr>
                        </thead>
                        <tbody v-if="visibleItems.length > 0">
                            <tr v-for="(item, index) in visibleItems" :key="item.product_id" class="border-b border-gray-100 hover:bg-[#f0f9ff]/40 transition-colors">
                                <td class="p-3 text-center text-gray-500 group relative w-12">
                                    <span class="group-hover:hidden">{{ index + 1 }}</span>
                                    <button @click="removeItem(item)" class="hidden group-hover:flex items-center justify-center w-5 h-5 bg-red-500 hover:bg-red-600 text-white rounded-full mx-auto" title="Xóa">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                    </button>
                                </td>
                                <td class="p-3 text-blue-600 w-[120px] break-all">{{ item.sku }}</td>
                                <td class="p-3 font-medium text-gray-800">{{ item.name }}</td>
                                <td class="p-3 text-center w-16">{{ item.unit_name }}</td>
                                <td class="p-3 text-right w-24">{{ item.system_stock }}</td>
                                <td class="p-3 text-center w-28">
                                    <input type="number" v-model.number="item.actual_stock" @input="markChecked(item)" min="0" class="w-20 border border-gray-300 rounded-sm py-1.5 px-2 text-right outline-none focus:border-blue-500 text-[13px] transition-colors mx-auto block font-semibold shadow-inner bg-blue-50/30">
                                </td>
                                <td class="p-3 text-center">
                                    <button @click="markMatched(item)" class="px-2 py-1 rounded border border-gray-300 hover:bg-gray-50 text-gray-700">Khớp</button>
                                </td>
                                <td class="p-3 text-right font-medium w-24" :class="{'text-red-500': item.checked && diffQty(item) < 0, 'text-green-500': item.checked && diffQty(item) > 0}">
                                    {{ item.checked ? diffQty(item) : "-" }}
                                </td>
                                <td class="p-3 font-bold text-gray-800 text-right w-[120px]">
                                    {{ item.checked ? formatCurrency(diffValue(item)) : "-" }}
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="items.length === 0" class="h-full flex flex-col items-center justify-center min-h-[400px]">
                        <div class="text-center">
                            <h3 class="font-bold text-gray-800 text-[18px] mb-2">Thêm hàng hóa để kiểm kho</h3>
                            <p class="text-gray-500 mb-6">Tìm từng hàng hoặc chọn theo nhóm hàng.</p>
                            <button @click="showCategoryModal = true" class="bg-[#1a56bc] hover:bg-blue-800 text-white font-semibold py-2.5 px-6 rounded shadow-sm text-[14px]">
                                Chọn nhóm hàng
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="w-[340px] flex-shrink-0 flex flex-col bg-white shadow-[-1px_0_0_rgba(0,0,0,0.05)] z-20">
                <div class="flex-1 overflow-auto bg-gray-50 flex flex-col">
                    <div class="p-4 flex flex-col gap-4 border-b border-gray-200 bg-white">
                        <div class="flex flex-col gap-1.5">
                            <label class="font-medium text-gray-700">Người kiểm kho</label>
                            <input
                                type="text"
                                :value="currentUser?.name || 'Không xác định'"
                                readonly
                                class="w-full border border-gray-200 bg-gray-50 rounded px-2.5 py-1.5 text-gray-700 outline-none"
                            >
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="font-medium text-gray-700">Ngày kiểm</label>
                            <DateTimePicker v-model="transactionDate" naked compact placeholder="dd/MM/yyyy HH:mm" input-class="w-full text-gray-700 bg-white px-2.5 py-1.5 rounded border border-gray-300 outline-none focus:border-blue-500 hover:border-blue-400" />
                        </div>
                    </div>

                    <div class="p-4 flex flex-col gap-4 bg-white border-b border-gray-200">
                        <div class="flex items-center gap-3">
                            <label class="font-medium text-gray-700 w-[100px]">Chi nhánh</label>
                            <select v-model="selectedBranch" class="flex-1 border border-gray-300 rounded px-2.5 py-1.5 focus:border-blue-500 outline-none text-[13px] bg-white transition-colors cursor-pointer shadow-inner">
                                <option disabled value="">Chi nhánh kiểm</option>
                                <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="p-4 flex flex-col gap-4 bg-white border-b border-gray-200 flex-1">
                        <div class="flex items-center gap-3">
                            <label class="font-medium text-gray-700 w-[100px]">Mã kiểm kho</label>
                            <input type="text" :value="stockTakeCode" disabled class="flex-1 border border-gray-200 bg-gray-50 rounded px-2.5 py-1.5 text-gray-500">
                        </div>
                        <div class="flex items-center gap-3">
                            <label class="font-medium text-gray-700 w-[100px]">Trạng thái</label>
                            <div class="flex-1 font-medium text-gray-800">Phiếu tạm</div>
                        </div>
                        <div class="flex items-center gap-3 pt-2">
                            <label class="font-medium text-gray-700 w-[100px]">SL thực tế</label>
                            <div class="flex-1 font-bold text-gray-900 shadow-sm border border-gray-100 px-3 py-1.5 rounded">{{ totalActualQty }}</div>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-[12px]">
                            <div class="border rounded px-2 py-1.5 bg-gray-50">Lệch: <b>{{ totalDiffQty }}</b></div>
                            <div class="border rounded px-2 py-1.5 bg-gray-50">Tăng: <b class="text-green-600">{{ totalDiffIncrease }}</b></div>
                            <div class="border rounded px-2 py-1.5 bg-gray-50">Giảm: <b class="text-red-500">{{ totalDiffDecrease }}</b></div>
                            <div class="border rounded px-2 py-1.5 bg-gray-50">Giá trị: <b>{{ formatCurrency(totalDiffValue) }}</b></div>
                        </div>
                        <textarea v-model="note" placeholder="Ghi chú" class="w-full border border-gray-300 rounded p-2.5 h-20 outline-none focus:border-blue-500 shadow-sm transition-colors text-[13px]"></textarea>
                    </div>
                </div>

                <div class="p-4 bg-white border-t border-gray-200 flex gap-3 flex-shrink-0">
                    <button @click="save('draft')" :disabled="submitRef" class="flex-1 bg-[#005bb5] hover:bg-[#00478f] text-white font-semibold py-2.5 rounded flex items-center justify-center gap-2 transition-colors disabled:opacity-50">
                        Lưu tạm
                    </button>
                    <button @click="save('balanced')" :disabled="submitRef" class="flex-1 bg-[#10b981] hover:bg-[#059669] text-white font-semibold py-2.5 rounded flex items-center justify-center gap-2 transition-colors disabled:opacity-50">
                        Hoàn thành
                    </button>
                </div>
            </div>
        </div>

        <CategorySelectModal
            :show="showCategoryModal"
            :categories="props.categories || []"
            :loading="isLoadingCategoryProducts"
            @close="showCategoryModal = false"
            @confirm="loadCategoryProducts"
        />
    </div>
</template>
