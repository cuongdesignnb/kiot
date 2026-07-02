<script setup>
/**
 * DateRangeFilter — KiotViet-style sidebar date range picker.
 *
 * UI:
 *   - Two radio rows: "<current preset>" (opens popover) and "Tùy chỉnh" (date inputs)
 *   - Popover groups presets into: Theo ngày / Theo tuần / Theo tháng / Theo quý / Theo năm
 *
 * v-model: { filter, from, to }
 *   filter ∈ {
 *     all, today, yesterday,
 *     this_week, last_week, last_7_days,
 *     this_month, last_month, last_30_days,
 *     this_quarter, last_quarter,
 *     this_year, last_year, custom
 *   }
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from "vue";

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({ filter: "all", from: "", to: "" }),
    },
    label: { type: String, default: "Thời gian" },
    /** Optional custom preset list (advanced use). When null, use built-in groups. */
    presets: { type: Array, default: null },
    /** When true, drop the card wrapper styling so it blends into a flat sidebar. */
    flat: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue"]);

// Built-in preset groups, matching the KiotViet filter pop-up.
const GROUPS = [
    {
        label: "Theo ngày",
        presets: [
            { value: "today", label: "Hôm nay" },
            { value: "yesterday", label: "Hôm qua" },
        ],
    },
    {
        label: "Theo tuần",
        presets: [
            { value: "this_week", label: "Tuần này" },
            { value: "last_week", label: "Tuần trước" },
            { value: "last_7_days", label: "7 ngày qua" },
        ],
    },
    {
        label: "Theo tháng",
        presets: [
            { value: "this_month", label: "Tháng này" },
            { value: "last_month", label: "Tháng trước" },
            { value: "last_30_days", label: "30 ngày qua" },
        ],
    },
    {
        label: "Theo quý",
        presets: [
            { value: "this_quarter", label: "Quý này" },
            { value: "last_quarter", label: "Quý trước" },
        ],
    },
    {
        label: "Theo năm",
        presets: [
            { value: "this_year", label: "Năm này" },
            { value: "last_year", label: "Năm trước" },
        ],
    },
];

// Flat label lookup. If a custom `presets` prop is given, prefer its labels.
const labelMap = computed(() => {
    const m = { all: "Toàn thời gian", custom: "Tùy chỉnh" };
    for (const g of GROUPS) for (const p of g.presets) m[p.value] = p.label;
    if (Array.isArray(props.presets)) for (const p of props.presets) m[p.value] = p.label;
    return m;
});

const filter = computed(() => props.modelValue?.filter || "all");
const from = computed(() => props.modelValue?.from || "");
const to = computed(() => props.modelValue?.to || "");

const isCustom = computed(() => filter.value === "custom");
const isPreset = computed(() => !isCustom.value);

const currentLabel = computed(() => labelMap.value[filter.value] || "Toàn thời gian");

const setPreset = (v) => {
    emit("update:modelValue", { filter: v, from: "", to: "" });
    popoverOpen.value = false;
};

const chooseCustom = () => {
    if (!isCustom.value) {
        emit("update:modelValue", { filter: "custom", from: from.value, to: to.value });
    }
};

const clearPreset = () => {
    emit("update:modelValue", { filter: "all", from: "", to: "" });
    popoverOpen.value = false;
};

const onFromInput = (e) =>
    emit("update:modelValue", { filter: "custom", from: e.target.value, to: to.value });
const onToInput = (e) =>
    emit("update:modelValue", { filter: "custom", from: from.value, to: e.target.value });

// Popover state
const popoverOpen = ref(false);
const rootEl = ref(null);
const triggerEl = ref(null);
const popoverEl = ref(null);
const popoverStyle = ref({ top: "0px", left: "0px" });

const updatePosition = () => {
    const btn = triggerEl.value;
    if (!btn) return;
    const rect = btn.getBoundingClientRect();
    const popoverWidth = 640;
    const margin = 8;
    // Prefer opening to the right of the trigger (like KiotViet overlapping content)
    let left = rect.right + margin;
    // If that overflows viewport, clamp inside.
    const maxLeft = window.innerWidth - popoverWidth - 8;
    if (left > maxLeft) left = Math.max(8, maxLeft);
    let top = rect.top;
    // Clamp vertically so it stays in view.
    const estHeight = 280;
    if (top + estHeight > window.innerHeight - 8) {
        top = Math.max(8, window.innerHeight - estHeight - 8);
    }
    popoverStyle.value = { top: `${top}px`, left: `${left}px` };
};

const togglePopover = async () => {
    popoverOpen.value = !popoverOpen.value;
    if (popoverOpen.value) {
        await nextTick();
        updatePosition();
    }
};

const onDocClick = (e) => {
    if (!popoverOpen.value) return;
    const insideRoot = rootEl.value && rootEl.value.contains(e.target);
    const insidePopover = popoverEl.value && popoverEl.value.contains(e.target);
    if (!insideRoot && !insidePopover) popoverOpen.value = false;
};
const onKey = (e) => {
    if (e.key === "Escape") popoverOpen.value = false;
};
const onScrollOrResize = () => {
    if (popoverOpen.value) updatePosition();
};
onMounted(() => {
    document.addEventListener("mousedown", onDocClick);
    document.addEventListener("keydown", onKey);
    window.addEventListener("resize", onScrollOrResize);
    window.addEventListener("scroll", onScrollOrResize, true);
});
onBeforeUnmount(() => {
    document.removeEventListener("mousedown", onDocClick);
    document.removeEventListener("keydown", onKey);
    window.removeEventListener("resize", onScrollOrResize);
    window.removeEventListener("scroll", onScrollOrResize, true);
});
</script>

<template>
    <div ref="rootEl" :class="['relative', flat ? '' : 'bg-white rounded-lg shadow-sm p-4']">
        <label :class="['block', flat ? 'text-sm font-bold text-gray-800 mb-2' : 'text-sm font-semibold text-gray-700 mb-2']">{{ label }}</label>

        <div class="space-y-2">
            <!-- Preset row: radio + pill that opens popover -->
            <label class="flex items-center gap-2 cursor-pointer">
                <input
                    type="radio"
                    :checked="isPreset"
                    @change="filter === 'all' ? togglePopover() : setPreset(filter)"
                    class="text-blue-600 focus:ring-blue-500"
                />
                <button
                    type="button"
                    ref="triggerEl"
                    @click="togglePopover"
                    class="flex-1 flex items-center justify-between px-2.5 py-1.5 text-[13px] rounded border bg-white text-left hover:border-blue-400 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    :class="isPreset && filter !== 'all' ? 'border-blue-400 text-blue-700 font-medium' : 'border-gray-300 text-gray-700'"
                >
                    <span class="truncate">{{ currentLabel }}</span>
                    <svg class="w-3.5 h-3.5 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            </label>

            <!-- Custom row -->
            <label class="flex items-center gap-2 cursor-pointer">
                <input
                    type="radio"
                    :checked="isCustom"
                    @change="chooseCustom"
                    class="text-blue-600 focus:ring-blue-500"
                />
                <span
                    class="flex-1 flex items-center justify-between px-2.5 py-1.5 text-[13px] rounded border bg-white"
                    :class="isCustom ? 'border-blue-400 text-blue-700 font-medium' : 'border-gray-300 text-gray-700'"
                >
                    <span>Tùy chỉnh</span>
                    <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                </span>
            </label>
        </div>

        <!-- Custom date inputs -->
        <div v-if="isCustom" class="mt-3 grid grid-cols-1 gap-2">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Từ ngày</label>
                <input
                    :value="from"
                    @input="onFromInput"
                    type="date"
                    class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Đến ngày</label>
                <input
                    :value="to"
                    @input="onToInput"
                    type="date"
                    class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
            </div>
        </div>

        <!-- Grouped preset popover (teleported to body to escape sidebar clipping) -->
        <Teleport to="body">
            <div
                v-if="popoverOpen"
                ref="popoverEl"
                :style="popoverStyle"
                class="fixed z-[9999] bg-white rounded-lg shadow-xl border border-gray-200 p-4 w-[640px] max-w-[90vw]"
                role="dialog"
            >
                <div class="grid grid-cols-5 gap-x-6 gap-y-3">
                    <div v-for="group in GROUPS" :key="group.label" class="space-y-2">
                        <div class="text-[13px] font-semibold text-gray-700">{{ group.label }}</div>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="p in group.presets"
                                :key="p.value"
                                type="button"
                                @click="setPreset(p.value)"
                                class="px-2.5 py-1 text-[12px] rounded-full border transition-colors focus:outline-none"
                                :class="filter === p.value
                                    ? 'bg-blue-600 border-blue-600 text-white hover:bg-blue-700'
                                    : 'bg-white border-gray-300 text-gray-700 hover:border-blue-400 hover:text-blue-600'"
                            >
                                {{ p.label }}
                            </button>
                        </div>
                    </div>
                </div>
                <div class="mt-3 pt-3 border-t border-gray-100 flex items-center justify-between">
                    <button
                        type="button"
                        @click="clearPreset"
                        class="text-[12px] text-gray-500 hover:text-blue-600"
                    >
                        Toàn thời gian
                    </button>
                    <button
                        type="button"
                        @click="popoverOpen = false"
                        class="text-[12px] px-3 py-1 rounded bg-gray-100 text-gray-700 hover:bg-gray-200"
                    >
                        Đóng
                    </button>
                </div>
            </div>
        </Teleport>
    </div>
</template>