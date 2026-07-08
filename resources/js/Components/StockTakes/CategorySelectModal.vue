<script setup>
import { computed, ref } from "vue";

const props = defineProps({
    show: Boolean,
    categories: {
        type: Array,
        default: () => [],
    },
    loading: Boolean,
});

const emit = defineEmits(["close", "confirm"]);

const search = ref("");
const selectedIds = ref([]);
const allCategories = ref(false);
const includeChildren = ref(true);
const onlyInStock = ref(false);
const activeOnly = ref(true);
const inventoryOnly = ref(true);

const normalize = (value) => String(value || "").toLowerCase();

const matchesTree = (category) => {
    const term = normalize(search.value);
    if (!term) return true;
    if (normalize(category.name).includes(term)) return true;
    return (category.children || []).some(matchesTree);
};

const filteredCategories = computed(() => props.categories.filter(matchesTree));

const isSelected = (id) => selectedIds.value.includes(id);

const toggleCategory = (id) => {
    selectedIds.value = isSelected(id)
        ? selectedIds.value.filter((item) => item !== id)
        : [...selectedIds.value, id];
    if (selectedIds.value.length > 0) {
        allCategories.value = false;
    }
};

const toggleAllCategories = () => {
    allCategories.value = !allCategories.value;
    if (allCategories.value) {
        selectedIds.value = [];
    }
};

const confirm = () => {
    emit("confirm", {
        all_categories: allCategories.value,
        category_ids: allCategories.value ? [] : selectedIds.value,
        include_children: includeChildren.value,
        only_in_stock: onlyInStock.value,
        active_only: activeOnly.value,
        inventory_only: inventoryOnly.value,
    });
};
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-[560px] bg-white rounded shadow-2xl overflow-hidden">
            <div class="px-5 py-3 border-b flex items-center justify-between">
                <h2 class="font-bold text-[16px] text-gray-800">Chọn nhóm hàng</h2>
                <button @click="$emit('close')" class="w-8 h-8 flex items-center justify-center rounded hover:bg-gray-100 text-gray-500" title="Đóng">
                    <span class="text-xl leading-none">&times;</span>
                </button>
            </div>

            <div class="p-5 space-y-4">
                <input
                    v-model="search"
                    type="text"
                    class="w-full border border-gray-300 rounded px-3 py-2 outline-none focus:border-blue-500 text-[13px]"
                    placeholder="Tìm nhóm hàng"
                />

                <div class="grid grid-cols-2 gap-3 text-[13px] text-gray-700">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" :checked="allCategories" @change="toggleAllCategories" class="rounded border-gray-300 text-blue-600">
                        <span>Tất cả nhóm hàng</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input v-model="includeChildren" type="checkbox" class="rounded border-gray-300 text-blue-600">
                        <span>Bao gồm nhóm con</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input v-model="onlyInStock" type="checkbox" class="rounded border-gray-300 text-blue-600">
                        <span>Chỉ kiểm hàng còn tồn kho</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input v-model="activeOnly" type="checkbox" class="rounded border-gray-300 text-blue-600">
                        <span>Chỉ kiểm hàng đang kinh doanh</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer col-span-2">
                        <input v-model="inventoryOnly" type="checkbox" class="rounded border-gray-300 text-blue-600">
                        <span>Chỉ kiểm hàng quản lý tồn kho</span>
                    </label>
                </div>

                <div class="border rounded max-h-[320px] overflow-auto text-[13px]">
                    <div v-if="filteredCategories.length === 0" class="p-4 text-gray-500 text-center">
                        Không tìm thấy nhóm hàng
                    </div>
                    <template v-for="category in filteredCategories" :key="category.id">
                        <CategoryNode :category="category" :level="0" :selected-ids="selectedIds" :disabled="allCategories" @toggle="toggleCategory" />
                    </template>
                </div>
            </div>

            <div class="px-5 py-3 border-t bg-gray-50 flex justify-end gap-3">
                <button @click="$emit('close')" class="px-4 py-2 rounded border border-gray-300 bg-white hover:bg-gray-50 font-medium">
                    Bỏ qua
                </button>
                <button
                    @click="confirm"
                    :disabled="loading || (!allCategories && selectedIds.length === 0)"
                    class="px-5 py-2 rounded bg-[#005bb5] hover:bg-[#00478f] text-white font-semibold disabled:opacity-50"
                >
                    {{ loading ? "Đang tải..." : "Xong" }}
                </button>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    components: {
        CategoryNode: {
            name: "CategoryNode",
            props: {
                category: Object,
                level: Number,
                selectedIds: Array,
                disabled: Boolean,
            },
            emits: ["toggle"],
            computed: {
                checked() {
                    return this.selectedIds.includes(this.category.id);
                },
                indent() {
                    return `${12 + this.level * 20}px`;
                },
            },
            template: `
                <div>
                    <label class="flex items-center gap-2 py-2 pr-3 hover:bg-gray-50 cursor-pointer" :style="{ paddingLeft: indent }">
                        <input type="checkbox" :checked="checked" :disabled="disabled" @change="$emit('toggle', category.id)" class="rounded border-gray-300 text-blue-600 disabled:opacity-50">
                        <span>{{ category.name }}</span>
                    </label>
                    <CategoryNode
                        v-for="child in category.children || []"
                        :key="child.id"
                        :category="child"
                        :level="level + 1"
                        :selected-ids="selectedIds"
                        :disabled="disabled"
                        @toggle="$emit('toggle', $event)"
                    />
                </div>
            `,
        },
    },
};
</script>
