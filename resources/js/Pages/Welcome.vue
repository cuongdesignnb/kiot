<script setup>
import { ref, watch, computed } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AppLayout from "../Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import axios from "axios";

const props = defineProps({
    products: Object,
    categories: Array,
    brands: Array,
    filters: Object,
    canViewCostPrice: { type: Boolean, default: false },
});

const search = ref(props.filters?.search || "");

// Bulk selection state
const selectedProductIds = ref([]);
const showTransferModal = ref(false);
const transferCategoryId = ref('');
const transferLoading = ref(false);
const showNewCategoryInTransfer = ref(false);
const newTransferCategoryName = ref('');
const creatingTransferCategory = ref(false);

const allSelected = computed(() => {
    const data = props.products?.data || [];
    return data.length > 0 && data.every(p => selectedProductIds.value.includes(p.id));
});

const toggleSelectAll = () => {
    const data = props.products?.data || [];
    if (allSelected.value) {
        selectedProductIds.value = [];
    } else {
        selectedProductIds.value = data.map(p => p.id);
    }
};

const toggleProductSelect = (id) => {
    const idx = selectedProductIds.value.indexOf(id);
    if (idx >= 0) {
        selectedProductIds.value.splice(idx, 1);
    } else {
        selectedProductIds.value.push(id);
    }
};

const openTransferModal = () => {
    transferCategoryId.value = '';
    showTransferModal.value = true;
};

const localCategories = ref([...(props.categories || [])]);

const flatCategories = computed(() => {
    const result = [];
    const flatten = (cats, prefix = '') => {
        (cats || []).forEach(c => {
            result.push({ id: c.id, name: prefix + c.name });
            if (c.children?.length) flatten(c.children, prefix + c.name + ' > ');
        });
    };
    flatten(localCategories.value);
    return result;
});

const quickCreateTransferCategory = async () => {
    if (!newTransferCategoryName.value.trim()) return;
    creatingTransferCategory.value = true;
    try {
        const res = await axios.post('/categories/quick-store', { name: newTransferCategoryName.value.trim() });
        if (res.data.success) {
            const cat = res.data.category;
            localCategories.value.push({ ...cat, children: [] });
            transferCategoryId.value = cat.id;
            newTransferCategoryName.value = '';
            showNewCategoryInTransfer.value = false;
        }
    } catch (e) {
        alert(e.response?.data?.message || 'Lỗi tạo nhóm hàng');
    }
    creatingTransferCategory.value = false;
};

const submitTransfer = async () => {
    if (!transferCategoryId.value || selectedProductIds.value.length === 0) return;
    transferLoading.value = true;
    try {
        const res = await axios.post('/products/bulk-update-category', {
            product_ids: selectedProductIds.value,
            category_id: transferCategoryId.value,
        });
        alert(res.data.message);
        showTransferModal.value = false;
        selectedProductIds.value = [];
        router.reload();
    } catch (e) {
        alert(e.response?.data?.message || 'Lỗi khi chuyển nhóm.');
    } finally {
        transferLoading.value = false;
    }
};

let searchTimeout;
watch(search, (value) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            "/products",
            { search: value },
            {
                preserveState: true,
                replace: true,
            },
        );
    }, 500);
});

const dropdownOpen = ref(false);
const toggleDropdown = () => {
    dropdownOpen.value = !dropdownOpen.value;
};
const closeDropdown = () => {
    dropdownOpen.value = false;
};

// Document detail popup
const showDocPopup = ref(false);
const docLoading = ref(false);
const docDetail = ref(null);

const openDocPopup = async (docType, docId) => {
    showDocPopup.value = true;
    docLoading.value = true;
    docDetail.value = null;
    try {
        const res = await axios.get("/products/document-detail", {
            params: { type: docType, id: docId },
        });
        docDetail.value = res.data;
    } catch (e) {
        console.error(e);
    } finally {
        docLoading.value = false;
    }
};

const closeDocPopup = () => {
    showDocPopup.value = false;
    docDetail.value = null;
};

const toggleExpand = async (product) => {
    product.expanded = !product.expanded;
    if (product.expanded && !product.activeTab) {
        product.activeTab = "info";
    }
};

const setTab = async (product, tab) => {
    product.activeTab = tab;
    if (tab === "inventory" && !product.inventoryData) {
        product.loadingInventory = true;
        try {
            const res = await axios.get(
                `/products/${product.id}/inventory-card`,
            );
            product.inventoryData = res.data;
        } catch (e) {
            console.error(e);
        } finally {
            product.loadingInventory = false;
        }
    }
    if (tab === "serials" && !product.serialsData) {
        product.loadingSerials = true;
        product.serialFilter = "all";
        product.serialSearch = "";
        try {
            const res = await axios.get(`/products/${product.id}/serials`);
            product.serialsData = res.data;
        } catch (e) {
            console.error(e);
        } finally {
            product.loadingSerials = false;
        }
    }
    if (tab === "warranties" && !product.warrantiesData) {
        product.loadingWarranties = true;
        try {
            const res = await axios.get(`/products/${product.id}/warranties`);
            product.warrantiesData = res.data;
        } catch (e) {
            console.error(e);
        } finally {
            product.loadingWarranties = false;
        }
    }
};

const reloadSerials = async (product) => {
    product.loadingSerials = true;
    try {
        const params = {};
        if (product.serialFilter && product.serialFilter !== "all")
            params.status = product.serialFilter;
        if (product.serialSearch) params.search = product.serialSearch;
        const res = await axios.get(`/products/${product.id}/serials`, {
            params,
        });
        product.serialsData = res.data;
    } catch (e) {
        console.error(e);
    } finally {
        product.loadingSerials = false;
    }
};

const serialStatusLabel = (status) => {
    const map = {
        in_stock: "Còn hàng",
        sold: "Đã bán",
        returning: "Đang trả",
        warranty: "Bảo hành",
        defective: "Lỗi",
    };
    return map[status] || status;
};

const formatCurrency = (val) => Number(val || 0).toLocaleString();
const formatDate = (val) => {
    if (!val) return "";
    return new Date(val).toLocaleString("vi-VN");
};
</script>

<template>
    <Head title="Danh sách hàng hóa" />
    <AppLayout>
        <template #sidebar>
            <div
                class="p-4 border-b border-gray-100 flex flex-col justify-center bg-gray-50/50 min-h-[50px]"
            >
                <h3 class="font-bold text-gray-700">Bộ lọc tìm kiếm</h3>
            </div>
            <div class="p-4 space-y-6">
                <div>
                    <label
                        class="block text-sm font-semibold text-gray-700 mb-2"
                        >Nhóm hàng</label
                    >
                    <select
                        class="w-full border border-gray-300 rounded p-2 text-sm focus:ring-1 focus:ring-blue-500 outline-none bg-white"
                    >
                        <option value="">Tất cả nhóm</option>
                        <option
                            v-for="cat in categories"
                            :key="cat.id"
                            :value="cat.id"
                        >
                            {{ cat.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label
                        class="block text-sm font-semibold text-gray-700 mb-2"
                        >Trạng thái</label
                    >
                    <div class="space-y-2">
                        <label
                            class="flex items-center gap-2 text-gray-600 hover:text-gray-900 cursor-pointer"
                        >
                            <input
                                type="checkbox"
                                checked
                                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4"
                            />
                            Đang kinh doanh
                        </label>
                        <label
                            class="flex items-center gap-2 text-gray-600 hover:text-gray-900 cursor-pointer"
                        >
                            <input
                                type="checkbox"
                                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4"
                            />
                            Ngừng kinh doanh
                        </label>
                    </div>
                </div>
                <div>
                    <label
                        class="block text-sm font-semibold text-gray-700 mb-2"
                        >Tồn kho</label
                    >
                    <select
                        class="w-full border border-gray-300 rounded p-2 text-sm focus:ring-1 focus:ring-blue-500 outline-none bg-white"
                    >
                        <option>Tất cả</option>
                        <option>Còn hàng trong kho</option>
                        <option>Hết hàng</option>
                    </select>
                </div>
            </div>
        </template>

        <div
            class="bg-white rounded border border-gray-200 shadow-sm overflow-hidden"
            @click="closeDropdown"
        >
            <div
                class="p-3 border-b border-gray-200 flex items-center justify-between bg-gray-50/30"
            >
                <div class="relative w-96">
                    <input
                        type="text"
                        v-model="search"
                        placeholder="Tìm kiếm theo mã, tên hàng, barcode..."
                        class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 text-sm outline-none bg-white"
                    />
                    <div
                        class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"
                    >
                        <svg
                            class="w-4 h-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                            ></path>
                        </svg>
                    </div>
                </div>

                <div class="flex gap-2">
                    <!-- Chuyển nhóm hàng button -->
                    <button
                        v-if="selectedProductIds.length > 0"
                        @click="openTransferModal"
                        class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded text-sm font-medium flex items-center gap-2 transition-colors shadow-sm"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                        Chuyển nhóm ({{ selectedProductIds.length }})
                    </button>
                    <div class="relative items-center" @click.stop>
                        <button
                            @click="toggleDropdown"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-medium flex items-center gap-2 transition-colors shadow-sm"
                        >
                            <svg
                                class="w-4 h-4"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 4v16m8-8H4"
                                ></path>
                            </svg>
                            Thêm mới
                            <svg
                                class="w-3 h-3 ml-1 opacity-70"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M19 9l-7 7-7-7"
                                ></path>
                            </svg>
                        </button>
                        <div
                            v-if="dropdownOpen"
                            class="absolute right-0 mt-2 w-48 bg-white rounded shadow-lg ring-1 ring-black ring-opacity-5 z-50 divide-y divide-gray-100"
                        >
                            <div class="py-1">
                                <Link
                                    href="/products/create/standard"
                                    class="group flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-700"
                                    >Hàng hóa</Link
                                >
                                <Link
                                    href="/products/create/service"
                                    class="group flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-700"
                                    >Dịch vụ</Link
                                >
                            </div>
                            <div class="py-1">
                                <Link
                                    href="/products/create/combo"
                                    class="group flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-700"
                                    >Combo - đóng gói</Link
                                >
                                <Link
                                    href="/products/create/manufactured"
                                    class="group flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-700"
                                    >Hàng sản xuất</Link
                                >
                            </div>
                        </div>
                    </div>
                    <ExcelButtons
                        export-url="/products/export"
                        import-url="/products/import"
                    />
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr
                            class="border-b border-gray-200 bg-gray-50/80 text-gray-500 font-semibold text-xs tracking-wider uppercase"
                        >
                            <th class="p-3 w-10 text-center">
                                <input
                                    type="checkbox"
                                    :checked="allSelected"
                                    @change="toggleSelectAll"
                                    class="rounded border-gray-300"
                                />
                            </th>
                            <th class="p-3 w-16">Ảnh</th>
                            <th class="p-3">Mã hàng</th>
                            <th class="p-3">Tên hàng</th>
                            <th class="p-3">Nhóm hàng</th>
                            <th class="p-3 text-right">Giá bán</th>
                            <th v-if="canViewCostPrice" class="p-3 text-right">Giá vốn</th>
                            <th class="p-3 text-right">Tồn kho</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm divide-y divide-gray-100">
                        <template
                            v-for="product in props.products.data"
                            :key="product.id"
                        >
                            <tr
                                @click="toggleExpand(product)"
                                class="hover:bg-blue-50/50 cursor-pointer group transition-colors"
                            >
                                <td class="p-3 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        :checked="selectedProductIds.includes(product.id)"
                                        @change="toggleProductSelect(product.id)"
                                        class="rounded border-gray-300"
                                    />
                                </td>
                                <td class="p-3">
                                    <div
                                        class="w-10 h-10 bg-gray-200 rounded flex items-center justify-center text-gray-400"
                                    >
                                        <svg
                                            class="w-5 h-5"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
                                            ></path>
                                        </svg>
                                    </div>
                                </td>
                                <td
                                    class="p-3 text-blue-600 font-medium group-hover:underline"
                                >
                                    {{ product.sku }}
                                </td>
                                <td class="p-3 font-medium text-gray-800">
                                    {{ product.name }}
                                </td>
                                <td class="p-3 text-gray-600 text-sm">
                                    {{ product.category?.name || '---' }}
                                </td>
                                <td class="p-3 text-right">
                                    {{
                                        Number(
                                            product.retail_price || 0,
                                        ).toLocaleString()
                                    }}
                                </td>
                                <td v-if="canViewCostPrice" class="p-3 text-right text-gray-500">
                                    {{
                                        Number(
                                            product.cost_price || 0,
                                        ).toLocaleString()
                                    }}
                                </td>
                                <td class="p-3 text-right">
                                    <span class="font-bold text-gray-800">{{
                                        product.stock_quantity || 0
                                    }}</span>
                                    <div v-if="product.has_serial && (product.ready_count > 0 || product.repairing_count > 0)" class="flex items-center justify-end gap-1.5 mt-0.5">
                                        <span v-if="product.ready_count > 0" class="text-[10px] px-1.5 py-0.5 rounded bg-green-100 text-green-700 font-semibold" title="Sẵn bán">✓ {{ product.ready_count }}</span>
                                        <span v-if="product.repairing_count > 0" class="text-[10px] px-1.5 py-0.5 rounded bg-yellow-100 text-yellow-700 font-semibold" title="Đang sửa">🔧 {{ product.repairing_count }}</span>
                                    </div>
                                </td>
                            </tr>
                            <tr
                                v-if="product.expanded"
                                class="group transition-colors bg-gray-50/30"
                            >
                                <td :colspan="canViewCostPrice ? 8 : 7" class="p-0">
                                    <div
                                        class="px-6 py-4 bg-[#f8fbff] shadow-inner border-y border-blue-100"
                                    >
                                        <!-- Tab Headers -->
                                        <div
                                            class="flex border-b border-gray-200 mb-4 gap-6"
                                        >
                                            <button
                                                @click="setTab(product, 'info')"
                                                :class="[
                                                    'pb-2 text-sm font-bold transition-all border-b-2',
                                                    product.activeTab === 'info'
                                                        ? 'border-blue-600 text-blue-600'
                                                        : 'border-transparent text-gray-400 hover:text-gray-600',
                                                ]"
                                            >
                                                Thông tin
                                            </button>
                                            <button
                                                @click="
                                                    setTab(
                                                        product,
                                                        'description',
                                                    )
                                                "
                                                :class="[
                                                    'pb-2 text-sm font-bold transition-all border-b-2',
                                                    product.activeTab ===
                                                    'description'
                                                        ? 'border-blue-600 text-blue-600'
                                                        : 'border-transparent text-gray-400 hover:text-gray-600',
                                                ]"
                                            >
                                                Mô tả, ghi chú
                                            </button>
                                            <button
                                                @click="
                                                    setTab(product, 'inventory')
                                                "
                                                :class="[
                                                    'pb-2 text-sm font-bold transition-all border-b-2',
                                                    product.activeTab ===
                                                    'inventory'
                                                        ? 'border-blue-600 text-blue-600'
                                                        : 'border-transparent text-gray-400 hover:text-gray-600',
                                                ]"
                                            >
                                                Thẻ kho
                                            </button>
                                            <button
                                                @click="
                                                    setTab(product, 'stock')
                                                "
                                                :class="[
                                                    'pb-2 text-sm font-bold transition-all border-b-2',
                                                    product.activeTab ===
                                                    'stock'
                                                        ? 'border-blue-600 text-blue-600'
                                                        : 'border-transparent text-gray-400 hover:text-gray-600',
                                                ]"
                                            >
                                                Tồn kho
                                            </button>
                                            <button
                                                v-if="
                                                    product.has_serial &&
                                                    ($page.props.app_settings
                                                        ?.product_use_serial ??
                                                        true)
                                                "
                                                @click="
                                                    setTab(product, 'serials')
                                                "
                                                :class="[
                                                    'pb-2 text-sm font-bold transition-all border-b-2',
                                                    product.activeTab ===
                                                    'serials'
                                                        ? 'border-blue-600 text-blue-600'
                                                        : 'border-transparent text-gray-400 hover:text-gray-600',
                                                ]"
                                            >
                                                Serial/IMEI
                                            </button>
                                            <button
                                                @click="
                                                    setTab(
                                                        product,
                                                        'warranties',
                                                    )
                                                "
                                                :class="[
                                                    'pb-2 text-sm font-bold transition-all border-b-2',
                                                    product.activeTab ===
                                                    'warranties'
                                                        ? 'border-blue-600 text-blue-600'
                                                        : 'border-transparent text-gray-400 hover:text-gray-600',
                                                ]"
                                            >
                                                Bảo hành, bảo trì
                                            </button>
                                        </div>

                                        <!-- Info Tab -->
                                        <div
                                            v-if="product.activeTab === 'info'"
                                            class="grid grid-cols-4 gap-8 py-2"
                                        >
                                            <div class="space-y-4">
                                                <div>
                                                    <div
                                                        class="text-xs text-gray-400 uppercase tracking-wider mb-1"
                                                    >
                                                        Mã hàng
                                                    </div>
                                                    <div
                                                        class="font-bold text-gray-800"
                                                    >
                                                        {{ product.sku }}
                                                    </div>
                                                </div>
                                                <div>
                                                    <div
                                                        class="text-xs text-gray-400 uppercase tracking-wider mb-1"
                                                    >
                                                        Barcode
                                                    </div>
                                                    <div
                                                        class="font-medium text-gray-800"
                                                    >
                                                        {{
                                                            product.barcode ||
                                                            "---"
                                                        }}
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="space-y-4">
                                                <div>
                                                    <div
                                                        class="text-xs text-gray-400 uppercase tracking-wider mb-1"
                                                    >
                                                        Nhóm hàng
                                                    </div>
                                                    <div
                                                        class="font-medium text-gray-800"
                                                    >
                                                        {{
                                                            product.category
                                                                ?.name || "---"
                                                        }}
                                                    </div>
                                                </div>
                                                <div>
                                                    <div
                                                        class="text-xs text-gray-400 uppercase tracking-wider mb-1"
                                                    >
                                                        Thương hiệu
                                                    </div>
                                                    <div
                                                        class="font-medium text-gray-800"
                                                    >
                                                        {{
                                                            product.brand
                                                                ?.name || "---"
                                                        }}
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="space-y-4">
                                                <div>
                                                    <div
                                                        class="text-xs text-gray-400 uppercase tracking-wider mb-1"
                                                    >
                                                        Giá bán
                                                    </div>
                                                    <div
                                                        class="font-bold text-lg text-blue-700"
                                                    >
                                                        {{
                                                            formatCurrency(
                                                                product.retail_price,
                                                            )
                                                        }}
                                                    </div>
                                                </div>
                                                <div>
                                                    <div
                                                        class="text-xs text-gray-400 uppercase tracking-wider mb-1"
                                                    >
                                                        Giá vốn
                                                    </div>
                                                    <div
                                                        class="font-medium text-gray-600"
                                                    >
                                                        {{
                                                            formatCurrency(
                                                                product.cost_price,
                                                            )
                                                        }}
                                                    </div>
                                                </div>
                                            </div>
                                            <div
                                                class="flex flex-col justify-end items-end gap-2 pb-1"
                                            >
                                                <Link
                                                    :href="`/products/${product.id}/edit`"
                                                    class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded text-sm font-bold flex items-center gap-2 transition-all shadow-sm"
                                                >
                                                    <i class="fas fa-edit"></i>
                                                    Cập nhật
                                                </Link>
                                                <Link
                                                    :href="`/products/${product.id}`"
                                                    method="delete"
                                                    as="button"
                                                    preserve-scroll
                                                    class="bg-white hover:bg-red-50 text-red-600 border border-red-200 px-5 py-2 rounded text-sm font-bold flex items-center gap-2 transition-all shadow-sm"
                                                >
                                                    <i class="fas fa-trash"></i>
                                                    Xóa hàng
                                                </Link>
                                            </div>
                                        </div>

                                        <!-- Inventory Tab (Thẻ kho) -->
                                        <div
                                            v-if="
                                                product.activeTab ===
                                                'inventory'
                                            "
                                            class="py-2 min-h-[100px]"
                                        >
                                            <div
                                                v-if="product.loadingInventory"
                                                class="flex justify-center py-6"
                                            >
                                                <i
                                                    class="fas fa-circle-notch fa-spin text-blue-500 text-xl"
                                                ></i>
                                            </div>
                                            <div
                                                v-else-if="
                                                    product.inventoryData &&
                                                    product.inventoryData
                                                        .length > 0
                                                "
                                            >
                                                <div
                                                    class="overflow-hidden border border-gray-200 rounded"
                                                >
                                                    <table
                                                        class="w-full text-left border-collapse text-xs"
                                                    >
                                                        <thead
                                                            class="bg-gray-100/80 text-gray-600 font-bold uppercase tracking-tight"
                                                        >
                                                            <tr>
                                                                <th
                                                                    class="p-2.5"
                                                                >
                                                                    Chứng từ
                                                                </th>
                                                                <th
                                                                    class="p-2.5"
                                                                >
                                                                    Thời gian
                                                                </th>
                                                                <th
                                                                    class="p-2.5"
                                                                >
                                                                    Loại giao
                                                                    dịch
                                                                </th>
                                                                <th
                                                                    class="p-2.5"
                                                                >
                                                                    Đối tác
                                                                </th>
                                                                <th
                                                                    class="p-2.5 text-right"
                                                                >
                                                                    Giá GD
                                                                </th>
                                                                <th
                                                                    class="p-2.5 text-right"
                                                                >
                                                                    Giá vốn
                                                                </th>
                                                                <th
                                                                    class="p-2.5 text-right"
                                                                >
                                                                    Số lượng
                                                                </th>
                                                                <th
                                                                    class="p-2.5 text-right"
                                                                >
                                                                    Tồn cuối
                                                                </th>
                                                            </tr>
                                                        </thead>
                                                        <tbody
                                                            class="divide-y divide-gray-100 bg-white"
                                                        >
                                                            <tr
                                                                v-for="item in product.inventoryData"
                                                                :key="
                                                                    item.code +
                                                                    item.date
                                                                "
                                                                class="hover:bg-gray-50/50"
                                                            >
                                                                <td
                                                                    class="p-2.5"
                                                                >
                                                                    <button
                                                                        v-if="
                                                                            item.doc_type &&
                                                                            item.doc_id
                                                                        "
                                                                        @click.stop="
                                                                            openDocPopup(
                                                                                item.doc_type,
                                                                                item.doc_id,
                                                                            )
                                                                        "
                                                                        class="font-bold text-blue-600 hover:text-blue-800 hover:underline cursor-pointer"
                                                                    >
                                                                        {{
                                                                            item.code
                                                                        }}
                                                                    </button>
                                                                    <span
                                                                        v-else
                                                                        class="font-bold text-gray-700"
                                                                        >{{
                                                                            item.code
                                                                        }}</span
                                                                    >
                                                                </td>
                                                                <td
                                                                    class="p-2.5 text-gray-600"
                                                                >
                                                                    {{
                                                                        formatDate(
                                                                            item.date,
                                                                        )
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="p-2.5"
                                                                >
                                                                    {{
                                                                        item.type
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="p-2.5 font-medium text-gray-700"
                                                                >
                                                                    {{
                                                                        item.partner
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="p-2.5 text-right"
                                                                >
                                                                    {{
                                                                        formatCurrency(
                                                                            item.sell_price,
                                                                        )
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="p-2.5 text-right"
                                                                >
                                                                    {{
                                                                        formatCurrency(
                                                                            item.cost_price,
                                                                        )
                                                                    }}
                                                                </td>
                                                                <td
                                                                    :class="[
                                                                        'p-2.5 text-right font-bold',
                                                                        item.change >
                                                                        0
                                                                            ? 'text-green-600'
                                                                            : 'text-red-600',
                                                                    ]"
                                                                >
                                                                    {{
                                                                        item.change >
                                                                        0
                                                                            ? "+"
                                                                            : ""
                                                                    }}{{
                                                                        item.change
                                                                    }}
                                                                </td>
                                                                <td
                                                                    class="p-2.5 text-right font-bold text-gray-800"
                                                                >
                                                                    {{
                                                                        item.balance
                                                                    }}
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <div class="mt-3">
                                                    <button
                                                        class="text-blue-600 hover:text-blue-800 text-sm font-medium flex items-center gap-1"
                                                    >
                                                        <svg
                                                            class="w-4 h-4"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            viewBox="0 0 24 24"
                                                        >
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                                            ></path>
                                                        </svg>
                                                        Xuất file
                                                    </button>
                                                </div>
                                            </div>
                                            <div
                                                v-else
                                                class="text-center py-8 text-gray-400 italic"
                                            >
                                                Chưa có giao dịch kho nào cho
                                                sản phẩm này.
                                            </div>
                                        </div>

                                        <!-- Description Tab (Mô tả, ghi chú) -->
                                        <div
                                            v-if="
                                                product.activeTab ===
                                                'description'
                                            "
                                            class="py-2 min-h-[100px]"
                                        >
                                            <div
                                                class="bg-white border border-gray-200 rounded p-4"
                                            >
                                                <div
                                                    v-if="product.description"
                                                    class="prose prose-sm max-w-none text-gray-700 whitespace-pre-line"
                                                >
                                                    {{ product.description }}
                                                </div>
                                                <div
                                                    v-else
                                                    class="text-center py-8 text-gray-400 italic"
                                                >
                                                    Chưa có mô tả nào cho sản
                                                    phẩm này.
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Stock Tab (Tồn kho) -->
                                        <div
                                            v-if="product.activeTab === 'stock'"
                                            class="py-2 min-h-[100px]"
                                        >
                                            <div
                                                class="overflow-hidden border border-gray-200 rounded"
                                            >
                                                <table
                                                    class="w-full text-left border-collapse text-xs"
                                                >
                                                    <thead
                                                        class="bg-gray-100/80 text-gray-600 font-bold uppercase tracking-tight"
                                                    >
                                                        <tr>
                                                            <th class="p-2.5">
                                                                Chi nhánh
                                                            </th>
                                                            <th
                                                                class="p-2.5 text-right"
                                                            >
                                                                Tồn kho
                                                            </th>
                                                            <th
                                                                class="p-2.5 text-right"
                                                            >
                                                                Giá vốn
                                                            </th>
                                                            <th
                                                                class="p-2.5 text-right"
                                                            >
                                                                Giá trị tồn
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody
                                                        class="divide-y divide-gray-100 bg-white"
                                                    >
                                                        <tr
                                                            class="hover:bg-gray-50/50"
                                                        >
                                                            <td
                                                                class="p-2.5 font-medium text-gray-700"
                                                            >
                                                                Chi nhánh mặc
                                                                định
                                                            </td>
                                                            <td
                                                                class="p-2.5 text-right font-bold text-gray-800"
                                                            >
                                                                {{
                                                                    product.stock_quantity ||
                                                                    0
                                                                }}
                                                            </td>
                                                            <td
                                                                class="p-2.5 text-right"
                                                            >
                                                                {{
                                                                    formatCurrency(
                                                                        product.cost_price,
                                                                    )
                                                                }}
                                                            </td>
                                                            <td
                                                                class="p-2.5 text-right font-bold text-blue-700"
                                                            >
                                                                {{
                                                                    formatCurrency(
                                                                        (product.stock_quantity ||
                                                                            0) *
                                                                            (product.cost_price ||
                                                                                0),
                                                                    )
                                                                }}
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                    <tfoot
                                                        class="bg-gray-50 font-bold text-xs"
                                                    >
                                                        <tr>
                                                            <td
                                                                class="p-2.5 text-gray-600"
                                                            >
                                                                Tổng cộng
                                                            </td>
                                                            <td
                                                                class="p-2.5 text-right text-gray-800"
                                                            >
                                                                {{
                                                                    product.stock_quantity ||
                                                                    0
                                                                }}
                                                            </td>
                                                            <td
                                                                class="p-2.5"
                                                            ></td>
                                                            <td
                                                                class="p-2.5 text-right text-blue-700"
                                                            >
                                                                {{
                                                                    formatCurrency(
                                                                        (product.stock_quantity ||
                                                                            0) *
                                                                            (product.cost_price ||
                                                                                0),
                                                                    )
                                                                }}
                                                            </td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>

                                        <!-- Serials Tab -->
                                        <div
                                            v-if="
                                                product.activeTab === 'serials'
                                            "
                                            class="py-2 min-h-[100px]"
                                        >
                                            <div
                                                v-if="product.loadingSerials"
                                                class="flex justify-center py-6"
                                            >
                                                <i
                                                    class="fas fa-circle-notch fa-spin text-blue-500 text-xl"
                                                ></i>
                                            </div>
                                            <div v-else>
                                                <!-- Header: Danh sách Serial/IMEI -->
                                                <div
                                                    class="flex items-center justify-between mb-3 bg-[#e8f4f8] rounded px-4 py-2.5"
                                                >
                                                    <span
                                                        class="font-bold text-[13px] text-gray-700"
                                                        >Danh sách
                                                        Serial/IMEI</span
                                                    >
                                                    <span
                                                        class="text-[13px] text-gray-500 font-medium"
                                                        >Trạng thái</span
                                                    >
                                                </div>
                                                <!-- Search + Filter -->
                                                <div
                                                    class="flex items-center gap-3 mb-3"
                                                >
                                                    <div
                                                        class="relative flex-1 max-w-[350px]"
                                                    >
                                                        <svg
                                                            class="w-4 h-4 text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            viewBox="0 0 24 24"
                                                        >
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                                                            ></path>
                                                        </svg>
                                                        <input
                                                            v-model="
                                                                product.serialSearch
                                                            "
                                                            @input="
                                                                reloadSerials(
                                                                    product,
                                                                )
                                                            "
                                                            type="text"
                                                            class="w-full pl-8 pr-3 py-1.5 border border-gray-300 rounded text-[13px] outline-none focus:border-blue-500"
                                                            placeholder="Tìm serial/IMEI"
                                                        />
                                                    </div>
                                                    <div class="ml-auto">
                                                        <select
                                                            v-model="
                                                                product.serialFilter
                                                            "
                                                            @change="
                                                                reloadSerials(
                                                                    product,
                                                                )
                                                            "
                                                            class="border border-gray-300 rounded px-2.5 py-1.5 text-[13px] outline-none focus:border-blue-500"
                                                        >
                                                            <option value="all">
                                                                Tất cả
                                                            </option>
                                                            <option
                                                                value="in_stock"
                                                            >
                                                                Còn hàng
                                                            </option>
                                                            <option
                                                                value="sold"
                                                            >
                                                                Đã bán
                                                            </option>
                                                            <option
                                                                value="warranty"
                                                            >
                                                                Bảo hành
                                                            </option>
                                                            <option
                                                                value="defective"
                                                            >
                                                                Lỗi
                                                            </option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <!-- Serial List -->
                                                <div
                                                    v-if="
                                                        product.serialsData &&
                                                        product.serialsData
                                                            .length > 0
                                                    "
                                                    class="border border-gray-200 rounded overflow-hidden"
                                                >
                                                    <div
                                                        v-for="s in product.serialsData"
                                                        :key="s.id"
                                                        class="flex items-center justify-between px-4 py-2.5 border-b border-gray-100 last:border-b-0 hover:bg-gray-50/50 text-[13px]"
                                                        :class="s.repair_status === 'repairing' || s.repair_status === 'not_started' ? 'bg-yellow-50/50' : ''"
                                                    >
                                                        <div class="flex items-center gap-2">
                                                            <span
                                                                class="font-medium"
                                                                :class="s.repair_status === 'repairing' || s.repair_status === 'not_started' ? 'text-orange-700' : 'text-gray-800'"
                                                            >{{ s.serial_number }}</span>
                                                            <span v-if="s.repair_status === 'repairing'" class="text-[10px] px-1.5 py-0.5 rounded bg-yellow-100 text-yellow-700 font-bold">🔧 Đang sửa</span>
                                                            <span v-else-if="s.repair_status === 'not_started'" class="text-[10px] px-1.5 py-0.5 rounded bg-red-100 text-red-600 font-bold">⏳ Chờ sửa</span>
                                                            <span v-else-if="s.repair_status === 'ready'" class="text-[10px] px-1.5 py-0.5 rounded bg-green-100 text-green-600 font-bold">✓ Sẵn bán</span>
                                                        </div>
                                                        <span
                                                            :class="[
                                                                'text-[12px] font-medium',
                                                                s.status ===
                                                                'in_stock'
                                                                    ? 'text-green-600'
                                                                    : s.status ===
                                                                        'sold'
                                                                      ? 'text-gray-400'
                                                                      : s.status ===
                                                                          'warranty'
                                                                        ? 'text-orange-500'
                                                                        : 'text-red-500',
                                                            ]"
                                                            >{{
                                                                serialStatusLabel(
                                                                    s.status,
                                                                )
                                                            }}</span
                                                        >
                                                    </div>
                                                </div>
                                                <div
                                                    v-else
                                                    class="text-center py-8 text-gray-400 italic text-[13px]"
                                                >
                                                    Hiện không có số Serial/IMEI
                                                    nào.
                                                </div>
                                                <!-- Footer -->
                                                <div class="mt-3">
                                                    <button
                                                        class="text-blue-600 hover:text-blue-800 text-sm font-medium flex items-center gap-1"
                                                    >
                                                        <svg
                                                            class="w-4 h-4"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            viewBox="0 0 24 24"
                                                        >
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                                            ></path>
                                                        </svg>
                                                        Xuất file
                                                    </button>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Warranties Tab (Bảo hành, bảo trì) -->
                                        <div
                                            v-if="
                                                product.activeTab ===
                                                'warranties'
                                            "
                                            class="py-2 min-h-[100px]"
                                        >
                                            <div
                                                v-if="product.loadingWarranties"
                                                class="flex justify-center py-6"
                                            >
                                                <i
                                                    class="fas fa-circle-notch fa-spin text-blue-500 text-xl"
                                                ></i>
                                            </div>
                                            <div
                                                v-else-if="
                                                    product.warrantiesData &&
                                                    product.warrantiesData
                                                        .length > 0
                                                "
                                                class="overflow-hidden border border-gray-200 rounded"
                                            >
                                                <table
                                                    class="w-full text-left border-collapse text-xs"
                                                >
                                                    <thead
                                                        class="bg-gray-100/80 text-gray-600 font-bold uppercase tracking-tight"
                                                    >
                                                        <tr>
                                                            <th class="p-2.5">
                                                                Mã hóa đơn
                                                            </th>
                                                            <th class="p-2.5">
                                                                Khách hàng
                                                            </th>
                                                            <th class="p-2.5">
                                                                Serial/IMEI
                                                            </th>
                                                            <th class="p-2.5">
                                                                Thời hạn BH
                                                            </th>
                                                            <th class="p-2.5">
                                                                Ngày mua
                                                            </th>
                                                            <th class="p-2.5">
                                                                Hết hạn BH
                                                            </th>
                                                            <th
                                                                class="p-2.5 text-center"
                                                            >
                                                                Trạng thái
                                                            </th>
                                                            <th class="p-2.5">
                                                                Ghi chú bảo trì
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody
                                                        class="divide-y divide-gray-100 bg-white"
                                                    >
                                                        <tr
                                                            v-for="w in product.warrantiesData"
                                                            :key="w.id"
                                                            class="hover:bg-gray-50/50"
                                                        >
                                                            <td
                                                                class="p-2.5 font-bold text-blue-600"
                                                            >
                                                                {{
                                                                    w.invoice_code ||
                                                                    "---"
                                                                }}
                                                            </td>
                                                            <td
                                                                class="p-2.5 font-medium text-gray-700"
                                                            >
                                                                {{
                                                                    w.customer_name ||
                                                                    "---"
                                                                }}
                                                            </td>
                                                            <td class="p-2.5">
                                                                {{
                                                                    w.serial_imei ||
                                                                    "---"
                                                                }}
                                                            </td>
                                                            <td class="p-2.5">
                                                                {{
                                                                    w.warranty_period ||
                                                                    "---"
                                                                }}
                                                            </td>
                                                            <td class="p-2.5">
                                                                {{
                                                                    w.purchase_date ||
                                                                    "---"
                                                                }}
                                                            </td>
                                                            <td class="p-2.5">
                                                                {{
                                                                    w.warranty_end_date ||
                                                                    "---"
                                                                }}
                                                            </td>
                                                            <td
                                                                class="p-2.5 text-center"
                                                            >
                                                                <span
                                                                    :class="[
                                                                        'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase',
                                                                        w.status ===
                                                                        'active'
                                                                            ? 'bg-green-100 text-green-700'
                                                                            : 'bg-red-100 text-red-700',
                                                                    ]"
                                                                >
                                                                    {{
                                                                        w.status ===
                                                                        "active"
                                                                            ? "Còn BH"
                                                                            : "Hết BH"
                                                                    }}
                                                                </span>
                                                            </td>
                                                            <td
                                                                class="p-2.5 text-gray-600 max-w-[200px] truncate"
                                                            >
                                                                {{
                                                                    w.maintenance_note ||
                                                                    "---"
                                                                }}
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div
                                                v-else
                                                class="text-center py-8 text-gray-400 italic"
                                            >
                                                Chưa có thông tin bảo hành nào
                                                cho sản phẩm này.
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div
                class="p-3 border-t border-gray-200 flex items-center justify-between bg-gray-50/50 text-sm"
            >
                <div class="text-gray-600">
                    Hiển thị từ
                    <span class="font-bold">{{
                        $props.products.from || 0
                    }}</span>
                    đến
                    <span class="font-bold">{{ $props.products.to || 0 }}</span>
                    trong tổng số
                    <span class="font-bold">{{
                        $props.products.total || 0
                    }}</span>
                    hàng hóa
                </div>
                <div
                    class="flex gap-1"
                    v-if="
                        $props.products.links &&
                        $props.products.links.length > 3
                    "
                >
                    <template
                        v-for="(link, index) in $props.products.links"
                        :key="index"
                    >
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="px-2.5 py-1 text-sm border rounded"
                            :class="
                                link.active
                                    ? 'bg-blue-600 text-white border-blue-600'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300'
                            "
                            v-html="link.label"
                        ></Link>
                        <span
                            v-else
                            class="px-2.5 py-1 text-sm border rounded bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed"
                            v-html="link.label"
                        ></span>
                    </template>
                </div>
            </div>
        </div>

        <!-- Document Detail Popup Modal -->
        <Teleport to="body">
            <div
                v-if="showDocPopup"
                class="fixed inset-0 z-[9999] flex items-center justify-center"
            >
                <!-- Backdrop -->
                <div
                    class="absolute inset-0 bg-black/40"
                    @click="closeDocPopup"
                ></div>
                <!-- Modal -->
                <div
                    class="relative bg-white rounded-lg shadow-2xl w-full max-w-[900px] max-h-[85vh] overflow-hidden flex flex-col mx-4"
                >
                    <!-- Header -->
                    <div
                        class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-white"
                    >
                        <div class="flex items-center gap-3">
                            <h3 class="text-lg font-bold text-gray-800">
                                {{ docDetail?.title || "Chi tiết chứng từ" }}
                            </h3>
                        </div>
                        <button
                            @click="closeDocPopup"
                            class="text-gray-400 hover:text-gray-600 transition-colors p-1"
                        >
                            <svg
                                class="w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"
                                ></path>
                            </svg>
                        </button>
                    </div>

                    <!-- Loading -->
                    <div
                        v-if="docLoading"
                        class="flex justify-center items-center py-20"
                    >
                        <i
                            class="fas fa-circle-notch fa-spin text-blue-500 text-2xl"
                        ></i>
                    </div>

                    <!-- Content -->
                    <div
                        v-else-if="docDetail"
                        class="overflow-y-auto flex-1 px-6 py-4"
                    >
                        <!-- Doc Info Header -->
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <span class="font-medium text-gray-700">{{
                                    docDetail.partner_name
                                }}</span>
                                <svg
                                    class="w-4 h-4 text-gray-300"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
                                    ></path>
                                </svg>
                                <span class="font-bold text-gray-800">{{
                                    docDetail.code
                                }}</span>
                                <span
                                    class="px-2 py-0.5 rounded text-xs font-bold"
                                    :class="
                                        docDetail.status === 'Hoàn thành' ||
                                        docDetail.status === 'completed'
                                            ? 'bg-green-100 text-green-700'
                                            : 'bg-yellow-100 text-yellow-700'
                                    "
                                >
                                    {{ docDetail.status }}
                                </span>
                            </div>
                        </div>

                        <!-- Doc Meta -->
                        <div
                            class="grid grid-cols-3 gap-4 text-sm mb-5 text-gray-600"
                        >
                            <div v-if="docDetail.created_by">
                                <span class="text-gray-400">Người tạo:</span>
                                <span class="ml-2 font-medium text-gray-700">{{
                                    docDetail.created_by
                                }}</span>
                            </div>
                            <div v-if="docDetail.seller">
                                <span class="text-gray-400">Người bán:</span>
                                <span class="ml-2 font-medium text-gray-700">{{
                                    docDetail.seller
                                }}</span>
                            </div>
                            <div v-if="docDetail.date">
                                <span class="text-gray-400"
                                    >Ngày
                                    {{
                                        docDetail.type === "purchase"
                                            ? "nhập"
                                            : "bán"
                                    }}:</span
                                >
                                <span class="ml-2 font-medium text-gray-700">{{
                                    docDetail.date
                                }}</span>
                            </div>
                            <div v-if="docDetail.sales_channel">
                                <span class="text-gray-400">Kênh bán:</span>
                                <span class="ml-2 font-medium text-gray-700">{{
                                    docDetail.sales_channel
                                }}</span>
                            </div>
                            <div v-if="docDetail.price_book">
                                <span class="text-gray-400">Bảng giá:</span>
                                <span class="ml-2 font-medium text-gray-700">{{
                                    docDetail.price_book
                                }}</span>
                            </div>
                        </div>

                        <!-- Items Table -->
                        <div
                            class="overflow-hidden border border-gray-200 rounded mb-4"
                        >
                            <table
                                class="w-full text-left border-collapse text-sm"
                            >
                                <thead
                                    class="bg-gray-50 text-gray-500 font-semibold text-xs uppercase tracking-wider"
                                >
                                    <tr>
                                        <th class="p-3">Mã hàng</th>
                                        <th class="p-3">Tên hàng</th>
                                        <th class="p-3 text-right">Số lượng</th>
                                        <th class="p-3 text-right">Đơn giá</th>
                                        <th class="p-3 text-right">Giảm giá</th>
                                        <th class="p-3 text-right">Giá bán</th>
                                        <th class="p-3 text-right font-bold">
                                            Thành tiền
                                        </th>
                                    </tr>
                                </thead>
                                <tbody
                                    class="divide-y divide-gray-100 bg-white"
                                >
                                    <template
                                        v-for="(item, idx) in docDetail.items"
                                        :key="idx"
                                    >
                                        <tr class="hover:bg-gray-50/50">
                                            <td
                                                class="p-3 text-blue-600 font-medium"
                                            >
                                                {{ item.product_code }}
                                            </td>
                                            <td class="p-3">
                                                <div
                                                    class="font-medium text-gray-800"
                                                >
                                                    {{ item.product_name }}
                                                </div>
                                            </td>
                                            <td class="p-3 text-right">
                                                {{ item.quantity }}
                                            </td>
                                            <td class="p-3 text-right">
                                                {{ formatCurrency(item.price) }}
                                            </td>
                                            <td class="p-3 text-right">
                                                {{
                                                    formatCurrency(
                                                        item.discount,
                                                    )
                                                }}
                                            </td>
                                            <td class="p-3 text-right">
                                                {{
                                                    formatCurrency(
                                                        item.sell_price,
                                                    )
                                                }}
                                            </td>
                                            <td
                                                class="p-3 text-right font-bold"
                                            >
                                                {{
                                                    formatCurrency(
                                                        item.subtotal,
                                                    )
                                                }}
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <!-- Totals -->
                        <div class="flex justify-end">
                            <div class="w-80 space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-500"
                                        >Tổng tiền hàng ({{
                                            docDetail.items?.length || 0
                                        }})</span
                                    >
                                    <span class="font-bold">{{
                                        formatCurrency(docDetail.subtotal)
                                    }}</span>
                                </div>
                                <div
                                    v-if="docDetail.discount"
                                    class="flex justify-between"
                                >
                                    <span class="text-gray-500"
                                        >Giảm giá hóa đơn</span
                                    >
                                    <span class="font-medium text-red-600">{{
                                        formatCurrency(docDetail.discount)
                                    }}</span>
                                </div>
                                <div class="flex justify-between border-t pt-2">
                                    <span class="text-gray-700 font-semibold">{{
                                        docDetail.type === "purchase"
                                            ? "Cần trả NCC"
                                            : "Khách cần trả"
                                    }}</span>
                                    <span class="font-bold text-lg">{{
                                        formatCurrency(docDetail.total)
                                    }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">{{
                                        docDetail.type === "purchase"
                                            ? "Đã trả NCC"
                                            : "Khách đã trả"
                                    }}</span>
                                    <span class="font-bold">{{
                                        formatCurrency(docDetail.customer_paid)
                                    }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Note -->
                        <div
                            v-if="docDetail.note"
                            class="mt-4 pt-3 border-t border-gray-100"
                        >
                            <div
                                class="flex items-center gap-2 text-sm text-gray-500"
                            >
                                <svg
                                    class="w-4 h-4"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                                    ></path>
                                </svg>
                                {{ docDetail.note }}
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div
                        v-if="docDetail"
                        class="px-6 py-3 border-t border-gray-200 bg-gray-50 flex justify-end"
                    >
                        <button
                            @click="closeDocPopup"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded text-sm font-bold flex items-center gap-2 transition-all shadow-sm"
                        >
                            <svg
                                class="w-4 h-4"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                                ></path>
                            </svg>
                            Mở phiếu
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Bulk Category Transfer Modal -->
        <Teleport to="body">
            <div v-if="showTransferModal" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 backdrop-blur-sm">
                <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4">
                    <div class="flex items-center justify-between px-6 py-4 border-b">
                        <h2 class="text-lg font-bold text-gray-800">Chuyển nhóm hàng</h2>
                        <button @click="showTransferModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                    </div>
                    <div class="px-6 py-5 space-y-4">
                        <p class="text-sm text-gray-600">
                            Chuyển <strong>{{ selectedProductIds.length }}</strong> sản phẩm đã chọn sang nhóm hàng mới.
                        </p>
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <label class="block font-semibold text-sm">Nhóm hàng đích *</label>
                                <button @click="showNewCategoryInTransfer = !showNewCategoryInTransfer" type="button" class="text-blue-600 hover:text-blue-700 text-sm font-medium flex items-center gap-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                                    Thêm nhóm mới
                                </button>
                            </div>
                            <select v-model="transferCategoryId" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:border-blue-500 outline-none bg-white">
                                <option value="">-- Chọn nhóm hàng --</option>
                                <option v-for="c in flatCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                            <!-- Inline quick create category -->
                            <div v-if="showNewCategoryInTransfer" class="mt-2 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                <div class="flex gap-2">
                                    <input v-model="newTransferCategoryName" @keyup.enter="quickCreateTransferCategory" type="text" placeholder="Tên nhóm hàng mới" class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 outline-none" />
                                    <button @click="quickCreateTransferCategory" :disabled="creatingTransferCategory || !newTransferCategoryName.trim()" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 whitespace-nowrap">Tạo</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 px-6 py-4 border-t bg-gray-50 rounded-b-xl">
                        <button @click="showTransferModal = false" class="px-5 py-2 border rounded-lg text-sm font-semibold hover:bg-gray-50">Hủy</button>
                        <button
                            @click="submitTransfer"
                            :disabled="!transferCategoryId || transferLoading"
                            class="px-5 py-2 bg-orange-500 text-white rounded-lg text-sm font-semibold hover:bg-orange-600 disabled:opacity-50 flex items-center gap-2"
                        >
                            <svg v-if="transferLoading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            {{ transferLoading ? 'Đang chuyển...' : 'Chuyển nhóm' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

