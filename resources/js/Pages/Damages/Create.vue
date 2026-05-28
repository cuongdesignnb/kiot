<script setup>
import { formatVND as formatCurrency } from '@/utils/money';
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import DateTimePicker from '@/Components/DateTimePicker.vue';

const props = defineProps({
    products: Array,
    branches: Array,
    employees: Array,
    currentDamageActor: Object,
    damageActorOptions: Array,
    currentDamageActorKey: String,
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
const selectedActorKey = ref(props.currentDamageActorKey || '');

const filteredProducts = ref([]);
const isSearchingProduct = ref(false);

const toNumber = (value) => Number(value) || 0;
const toInt = (value) => parseInt(value, 10) || 0;
const toBool = (value) => value === true || value === 1 || value === '1';
const lineTotal = (item) => Math.max(0, toInt(item.qty)) * toNumber(item.cost_price);
const isSerialProduct = (item) => Boolean(item?.has_serial);
const selectedSerialSet = (item) => new Set(Array.isArray(item?.serial_ids) ? item.serial_ids : []);

const visibleSerialsForItem = (item) => {
    if (!item || !Array.isArray(item.serials)) return [];

    const q = String(item.serial_search || '').trim().toLowerCase();
    const selected = selectedSerialSet(item);

    const matched = item.serials.filter((serial) => {
        const label = serialLabel(serial).toLowerCase();
        return !q || label.includes(q);
    });

    const selectedRows = item.serials.filter((serial) => selected.has(serial.id));
    const selectedIds = new Set(selectedRows.map((serial) => serial.id));

    const limited = matched
        .filter((serial) => !selectedIds.has(serial.id))
        .slice(0, 50);

    return [...selectedRows, ...limited];
};

const serialLoadControllers = new WeakMap();
const serialLoadTimeoutMs = 8000;

const loadSerialsForItem = async (item, force = false) => {
    if (!item || !item.product_id || !item.has_serial || (item.serial_loading && !force)) {
        return;
    }

    if (force) {
        serialLoadControllers.get(item)?.abort();
    }

    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => controller.abort(), serialLoadTimeoutMs);

    serialLoadControllers.set(item, controller);
    item.serial_loading = true;
    item.serial_error = '';

    try {
        const startedAt = performance.now();
        console.info('[Damage serial] loading product', item.product_id);

        const response = await axios.get(`/api/products/${item.product_id}/serials`, {
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        console.info('[Damage serial] response', response.status, response.data);

        const serials = Array.isArray(response.data) ? response.data : [];

        console.info('[Damage serial] loaded', {
            product_id: item.product_id,
            count: serials.length,
            ms: Math.round(performance.now() - startedAt),
        });

        item.serials = serials;

        if (serials.length === 0) {
            item.serial_error = 'Không có serial/IMEI khả dụng để xuất hủy. Vui lòng kiểm tra trạng thái serial trong hàng hóa.';
        }
    } catch (error) {
        console.error('[Damage serial] error', error);

        if (error.code === 'ERR_CANCELED' || error.name === 'CanceledError' || error.name === 'AbortError') {
            item.serial_error = 'Tải serial/IMEI quá thời gian. Vui lòng bấm Tải lại.';
        } else if (error.response?.status === 403) {
            item.serial_error = 'Bạn không có quyền tải serial/IMEI.';
        } else if (error.response?.status === 404) {
            item.serial_error = 'Không tìm thấy sản phẩm để tải serial/IMEI.';
        } else if (error.response?.status >= 500) {
            item.serial_error = 'Máy chủ lỗi khi tải serial/IMEI. Vui lòng kiểm tra log Laravel.';
        } else {
            item.serial_error = 'Không tải được serial/IMEI. Vui lòng thử bấm Tải lại.';
        }

        item.serials = [];
    } finally {
        window.clearTimeout(timeoutId);
        if (serialLoadControllers.get(item) === controller) {
            serialLoadControllers.delete(item);
        }
        item.serial_loading = false;
    }
};

const normalizeQty = (item) => {
    if (!item) return;

    if (item.has_serial) {
        const selectedCount = Array.isArray(item.serial_ids) ? item.serial_ids.length : 0;
        if (selectedCount > 0) {
            item.qty = selectedCount;
            return;
        }
    }

    const stock = Math.max(0, toInt(item.stock_quantity));
    let qty = toInt(item.qty);

    if (qty < 1) qty = 1;
    if (stock > 0 && qty > stock) qty = stock;

    item.qty = qty;
};

const isSerialSelected = (item, serial) => {
    return Array.isArray(item?.serial_ids) && item.serial_ids.includes(serial.id);
};

const toggleSerial = (item, serial) => {
    if (!item || !serial) return;

    if (!Array.isArray(item.serial_ids)) {
        item.serial_ids = [];
    }

    const index = item.serial_ids.indexOf(serial.id);
    if (index >= 0) {
        item.serial_ids.splice(index, 1);
    } else {
        item.serial_ids.push(serial.id);
    }

    item.qty = item.serial_ids.length || 1;
};

const serialLabel = (serial) =>
    serial.label || serial.serial_number || serial.imei || serial.code || `#${serial.id}`;

const actorOptions = computed(() => {
    if (Array.isArray(props.damageActorOptions) && props.damageActorOptions.length > 0) {
        return props.damageActorOptions.map((actor) => ({
            ...actor,
            is_current_user: actor.value === props.currentDamageActorKey,
        }));
    }

    return (props.employees || []).map((employee) => ({
        value: `employee:${employee.id}`,
        label: employee.name,
        code: employee.code,
        type: 'employee',
        is_current_user: props.currentDamageActor?.employee_id === employee.id,
    }));
});

const selectedActor = computed(() => {
    return actorOptions.value.find((actor) => actor.value === selectedActorKey.value) || null;
});

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
    const existing = items.value.find(i => i.product_id === product.id);
    if (!existing) {
        const item = {
            product_id: product.id,
            sku: product.sku,
            name: product.name,
            qty: 1,
            cost_price: product.cost_price || 0,
            stock_quantity: product.stock_quantity || 0,
            has_serial: toBool(product.has_serial),
            serial_ids: [],
            serials: [],
            serial_search: '',
            serial_loading: false,
            serial_error: '',
        };

        items.value.unshift(item);

        if (item.has_serial) {
            loadSerialsForItem(item);
        }
    } else {
        if (existing.has_serial) {
            loadSerialsForItem(existing);
        } else {
            existing.qty++;
            normalizeQty(existing);
        }
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
        const qty = toInt(item.qty);
        return {
            ...item,
            total_value: lineTotal(item)
        };
    });
});

const totalQty = computed(() => itemsComputed.value.reduce((sum, item) => sum + toInt(item.qty), 0));
const totalValue = computed(() => itemsComputed.value.reduce((sum, item) => sum + item.total_value, 0));

const validateBeforeSave = (status) => {
    if (items.value.length === 0) {
        return 'Vui lòng chọn ít nhất 1 hàng hóa để xuất hủy.';
    }

    if (!selectedBranch.value) {
        return 'Vui lòng chọn chi nhánh xuất hủy.';
    }

    if (!selectedActor.value) {
        return 'Vui lòng chọn người xuất hủy.';
    }

    for (const item of items.value) {
        normalizeQty(item);

        const qty = toInt(item.qty);
        const stock = toInt(item.stock_quantity);

        if (qty <= 0) {
            return `Số lượng hủy của "${item.name}" phải lớn hơn 0.`;
        }

        if (qty > stock) {
            return `Số lượng hủy của "${item.name}" vượt tồn kho hiện tại.`;
        }

        if (status === 'completed' && item.has_serial) {
            const selectedCount = Array.isArray(item.serial_ids) ? item.serial_ids.length : 0;
            if (selectedCount !== qty) {
                return `Hàng "${item.name}" cần chọn đúng ${qty} serial/IMEI trước khi hoàn thành.`;
            }
        }
    }

    return '';
};

const save = (status) => {
    const validationMessage = validateBeforeSave(status);
    if (validationMessage) {
        alert(validationMessage);
        return;
    }

    submitRef.value = true;

    router.post('/damages', {
            code: props.damageCode,
            status: status, // 'draft' | 'completed'
            branch_id: selectedBranch.value,
            damage_actor_key: selectedActorKey.value,
            action_date: transactionDate.value,
            note: note.value,
            items: items.value.map((item) => ({
                product_id: item.product_id,
                qty: toInt(item.qty),
                serial_ids: Array.isArray(item.serial_ids) ? item.serial_ids : [],
            })),
        },
        {
            onError: (errors) => {
                const first = Object.values(errors || {}).flat()[0];
                alert(first || 'Có lỗi xảy ra, vui lòng kiểm tra lại dữ liệu.');
            },
            onFinish: () => {
                submitRef.value = false;
            },
        }
    );
};


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
                    <div v-if="showSuggestions" class="absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 shadow-xl rounded-sm z-50 max-h-[300px] overflow-auto text-black">
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
                            <tr v-for="(item, index) in items" :key="item.product_id" class="border-b border-gray-100 hover:bg-[#f0f9ff]/40 transition-colors align-top">
                                <td class="p-3 text-center text-gray-500 relative w-12">
                                    <div class="flex items-center justify-center gap-1">
                                        <span>{{ index + 1 }}</span>
                                    </div>
                                    <button
                                        type="button"
                                        @click="removeItem(index)"
                                        class="mt-2 inline-flex items-center justify-center w-5 h-5 bg-red-500 hover:bg-red-600 text-white rounded-full"
                                        title="Xóa sản phẩm khỏi phiếu"
                                    >
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                    </button>
                                </td>
                                <td class="p-3 text-blue-600 w-[120px] break-all">{{ item.sku }}</td>
                                <td class="p-3 font-medium text-gray-800">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div>{{ item.name }}</div>
                                            <div
                                                v-if="isSerialProduct(item)"
                                                class="mt-1 inline-flex items-center rounded border border-blue-200 bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700"
                                            >
                                                Serial/IMEI
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            class="shrink-0 rounded border border-red-200 bg-red-50 px-2 py-1 text-[11px] font-semibold text-red-600 hover:bg-red-100"
                                            @click="removeItem(index)"
                                        >
                                            Xóa
                                        </button>
                                    </div>

                                    <div
                                        v-if="isSerialProduct(item)"
                                        class="mt-3 rounded border border-gray-200 bg-gray-50 p-3"
                                    >
                                        <div class="mb-2 flex items-center justify-between gap-2 text-[12px]">
                                            <span class="font-semibold text-gray-700">Chọn serial/IMEI hủy</span>
                                            <div class="flex items-center gap-2">
                                                <span class="text-gray-500">
                                                    Đã chọn {{ item.serial_ids?.length || 0 }}/{{ item.qty || 0 }}
                                                </span>
                                                <button
                                                    type="button"
                                                    class="rounded border border-gray-300 bg-white px-2 py-0.5 text-[11px] font-medium text-blue-600 hover:border-blue-400 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                    :disabled="item.serial_loading"
                                                    @click="loadSerialsForItem(item, true)"
                                                >
                                                    Tải lại
                                                </button>
                                            </div>
                                        </div>

                                        <div v-if="item.serial_loading" class="text-[12px] text-gray-500">
                                            Đang tải serial/IMEI khả dụng...
                                        </div>
                                        <div v-else-if="item.serial_error" class="text-[12px] text-red-600">
                                            {{ item.serial_error }}
                                        </div>
                                        <div v-else>
                                            <input
                                                v-model="item.serial_search"
                                                type="text"
                                                class="mb-2 w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-[12px] outline-none focus:border-blue-500"
                                                placeholder="T?m serial/IMEI..."
                                            />

                                            <div v-if="!item.serials || item.serials.length === 0" class="text-[12px] text-red-600">
                                                Kh?ng c? serial/IMEI kh? d?ng ?? xu?t h?y.
                                            </div>

                                            <div v-else>
                                                <div class="mb-2 text-[11px] text-green-600">
                                                    T?m th?y {{ item.serials.length }} serial/IMEI kh? d?ng
                                                    <span v-if="visibleSerialsForItem(item).length < item.serials.length" class="text-gray-500">
                                                        ? ?ang hi?n th? {{ visibleSerialsForItem(item).length }} k?t qu?, h?y g? ?? l?c nhanh.
                                                    </span>
                                                </div>
                                                <div class="flex max-h-40 flex-wrap gap-2 overflow-auto">
                                                    <button
                                                        v-for="serial in visibleSerialsForItem(item)"
                                                        :key="serial.id"
                                                        type="button"
                                                        class="rounded border px-2 py-1 text-[12px] font-medium transition-colors"
                                                        :class="isSerialSelected(item, serial)
                                                            ? 'border-blue-500 bg-blue-600 text-white'
                                                            : 'border-gray-300 bg-white text-gray-700 hover:border-blue-400 hover:text-blue-700'"
                                                        @click="toggleSerial(item, serial)"
                                                    >
                                                        {{ serialLabel(serial) }}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mt-2 text-[11px] text-gray-500">
                                            Phiếu hoàn thành yêu cầu số serial/IMEI được chọn đúng bằng số lượng hủy.
                                        </div>
                                    </div>
                                </td>
                                <td class="p-3 text-center w-[100px]">Cái</td>
                                <td class="p-3 text-center w-[100px]" :class="item.stock_quantity <= 0 ? 'text-red-500 font-bold' : item.stock_quantity < item.qty ? 'text-orange-500 font-semibold' : 'text-gray-500'">
                                    {{ item.stock_quantity }}
                                    <div v-if="item.stock_quantity <= 0" class="text-[10px]">Hết hàng!</div>
                                    <div v-else-if="item.stock_quantity < item.qty" class="text-[10px]">Không đủ!</div>
                                </td>
                                <td class="p-3 text-center w-[120px]">
                                    <input
                                        type="number"
                                        v-model="item.qty"
                                        min="1"
                                        :readonly="isSerialProduct(item)"
                                        :title="isSerialProduct(item) ? 'Số lượng hàng serial được tính theo serial/IMEI đã chọn' : ''"
                                        class="w-20 border border-gray-300 rounded-sm py-1.5 px-2 text-right outline-none focus:border-blue-500 text-[13px] transition-colors mx-auto block font-semibold shadow-inner"
                                        :class="isSerialProduct(item) ? 'bg-gray-100 text-gray-500 cursor-not-allowed' : 'bg-blue-50/30'"
                                        @change="normalizeQty(item)"
                                        @blur="normalizeQty(item)"
                                    >
                                </td>
                                <td class="p-3 font-bold text-gray-600 text-right w-32">{{ formatCurrency(item.cost_price) }}</td>
                                <td class="p-3 font-bold text-blue-700 text-right w-[140px] pr-6">{{ formatCurrency(lineTotal(item)) }}</td>
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
            <div class="w-[380px] max-w-[380px] flex-shrink-0 flex flex-col bg-white shadow-[-1px_0_0_rgba(0,0,0,0.05)] z-20 overflow-hidden">
                <div class="flex-1 overflow-auto bg-gray-50 flex flex-col">

                    <div class="border-b border-gray-200 bg-white p-4 space-y-3">
                        <div class="space-y-1">
                            <label class="block text-[13px] font-medium text-gray-700">
                                Người xuất hủy
                            </label>

                            <div class="flex min-w-0 items-center gap-2">
                                <div class="w-7 h-7 bg-gray-200 rounded-full flex items-center justify-center border border-gray-300 shadow-inner shrink-0">
                                    <svg class="w-4 h-4 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>

                                <select
                                    v-model="selectedActorKey"
                                    class="min-w-0 flex-1 rounded border border-gray-300 bg-white px-2.5 py-1.5 text-[13px] text-gray-800 shadow-sm hover:border-blue-400 focus:border-blue-500 focus:outline-none"
                                >
                                    <option value="">Chọn người xuất hủy</option>
                                    <option
                                        v-for="actor in actorOptions"
                                        :key="actor.value"
                                        :value="actor.value"
                                    >
                                        {{ actor.label }}{{ actor.is_current_user ? ' (hiện tại)' : '' }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="space-y-1">
                            <label class="block text-[13px] font-medium text-gray-700">
                                Ngày hủy
                            </label>

                            <DateTimePicker
                                v-model="transactionDate"
                                compact
                                wrapper-class="w-[190px] shrink-0"
                                input-class="text-[12px] py-1.5 px-2"
                                placeholder="dd/MM/yyyy HH:mm"
                            />
                        </div>
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
