<script setup>
/**
 * SidebarFilter — config-driven container that composes the smaller
 * filter widgets. Emits `update:modelValue` with a merged filter object.
 *
 * Example config:
 *   [
 *     { key: 'search',      type: 'search',    label: 'Tìm kiếm', placeholder: 'Mã / tên NCC',
 *       zone: 'quick' },
 *     { key: 'status',      type: 'checkbox',  label: 'Trạng thái', options: statuses,
 *       zone: 'main' },
 *     { key: 'branch_id',   type: 'select',    label: 'Chi nhánh',  options: branches,
 *       zone: 'main' },
 *     { key: 'date',        type: 'dateRange', label: 'Thời gian',
 *       fields: { filter: 'date_filter', from: 'date_from', to: 'date_to' },
 *       zone: 'quick' },
 *     { key: 'city',        type: 'select',    label: 'Tỉnh/TP',  options: cities,
 *       zone: 'advanced' },
 *   ]
 *
 * Zones (optional, spec §6.1):
 *   - quick    : always visible, rendered first (search + date typically).
 *   - main     : always visible, default zone.
 *   - advanced : collapsed by default, revealed by a toggle.
 * If no field declares a zone, all fields render flat (backward compatible).
 */
import { computed, ref } from "vue";
import { usePage } from "@inertiajs/vue3";
import SearchFilter from "./SearchFilter.vue";
import SelectFilter from "./SelectFilter.vue";
import CheckboxFilter from "./CheckboxFilter.vue";
import DateRangeFilter from "./DateRangeFilter.vue";

const props = defineProps({
    modelValue: { type: Object, required: true },
    config: { type: Array, required: true },
    /**
     * Branch auto-lock state from Inertia shared props.
     * When not supplied, falls back to `$page.props.branch_lock`.
     */
    branchLock: { type: Object, default: null },
});
const emit = defineEmits(["update:modelValue", "reset"]);

/** Resolve branch_lock: explicit prop wins, otherwise fall back to shared Inertia props. */
const page = usePage();
const effectiveBranchLock = computed(() => {
    if (props.branchLock) return props.branchLock;
    return page.props?.branch_lock ?? { locked: false, branch_id: null };
});

const setField = (key, value) => {
    // Mutate the reactive proxy in place so parents using `v-model="filters"`
    // on a `reactive()` (useFilters) trigger their watchers. Also emit for
    // traditional ref-based v-model consumers.
    if (props.modelValue && typeof props.modelValue === "object") {
        props.modelValue[key] = value;
    }
    emit("update:modelValue", { ...props.modelValue, [key]: value });
};
const setFields = (patch) => {
    if (props.modelValue && typeof props.modelValue === "object") {
        for (const [k, v] of Object.entries(patch)) props.modelValue[k] = v;
    }
    emit("update:modelValue", { ...props.modelValue, ...patch });
};

// For dateRange: expose an object { filter, from, to } bound to 3 keys.
const dateValue = (field) => ({
    filter: props.modelValue[field.fields.filter] ?? "all",
    from: props.modelValue[field.fields.from] ?? "",
    to: props.modelValue[field.fields.to] ?? "",
});
const onDateUpdate = (field, next) => {
    setFields({
        [field.fields.filter]: next.filter,
        [field.fields.from]: next.filter === "custom" ? (next.from || "") : "",
        [field.fields.to]: next.filter === "custom" ? (next.to || "") : "",
    });
};

const isFieldActive = (field) => {
    if (field.type === "search") {
        const v = props.modelValue[field.key];
        return typeof v === "string" && v.trim() !== "";
    }
    if (field.type === "checkbox") {
        const v = props.modelValue[field.key];
        return Array.isArray(v) && v.length > 0;
    }
    if (field.type === "select") {
        const v = props.modelValue[field.key];
        return v !== null && v !== undefined && v !== "";
    }
    if (field.type === "dateRange") {
        const preset = props.modelValue[field.fields.filter];
        const from = props.modelValue[field.fields.from];
        const to = props.modelValue[field.fields.to];
        return (preset && preset !== "all") || !!from || !!to;
    }
    return false;
};

/** Count of filters currently active across all zones. */
const activeCount = computed(() => props.config.reduce((n, f) => n + (isFieldActive(f) ? 1 : 0), 0));

/** Group fields by zone. */
const hasZones = computed(() => props.config.some((f) => f.zone));
const quickFields = computed(() => props.config.filter((f) => f.zone === "quick"));
const mainFields = computed(() => props.config.filter((f) => !f.zone || f.zone === "main"));
const advancedFields = computed(() => props.config.filter((f) => f.zone === "advanced"));

/** Advanced zone — expanded automatically when any of its filters are active. */
const advancedOpen = ref(false);
const advancedActiveCount = computed(() => advancedFields.value.reduce((n, f) => n + (isFieldActive(f) ? 1 : 0), 0));
const isAdvancedOpen = computed(() => advancedOpen.value || advancedActiveCount.value > 0);

/** Determine whether a field's branch input should be disabled (spec §3.4). */
const isBranchLocked = (field) =>
    effectiveBranchLock.value?.locked === true &&
    ["branch_id", "from_branch_id", "to_branch_id"].includes(field.key);
</script>

<template>
    <aside class="w-64 shrink-0 space-y-3">
        <div
            v-if="activeCount > 0"
            class="flex items-center justify-between px-2 py-1.5 bg-blue-50 border border-blue-200 rounded text-[13px]"
        >
            <span class="text-blue-700 font-medium">
                Bộ lọc ({{ activeCount }})
            </span>
            <button
                type="button"
                @click="emit('reset')"
                class="text-[12px] text-blue-600 hover:text-blue-800 underline"
            >
                Xoá tất cả
            </button>
        </div>

        <!-- Flat rendering (no zones declared) — backward compatible -->
        <template v-if="!hasZones">
            <template v-for="field in config" :key="field.key">
                <SearchFilter
                    v-if="field.type === 'search'"
                    :model-value="modelValue[field.key]"
                    @update:model-value="(v) => setField(field.key, v)"
                    :label="field.label"
                    :placeholder="field.placeholder"
                />
                <SelectFilter
                    v-else-if="field.type === 'select'"
                    :model-value="modelValue[field.key]"
                    @update:model-value="(v) => setField(field.key, v)"
                    :label="field.label"
                    :options="field.options || []"
                    :placeholder="field.placeholder"
                    :disabled="isBranchLocked(field)"
                />
                <CheckboxFilter
                    v-else-if="field.type === 'checkbox'"
                    :model-value="modelValue[field.key] || []"
                    @update:model-value="(v) => setField(field.key, v)"
                    :label="field.label"
                    :options="field.options || []"
                />
                <DateRangeFilter
                    v-else-if="field.type === 'dateRange'"
                    :model-value="dateValue(field)"
                    @update:model-value="(v) => onDateUpdate(field, v)"
                    :label="field.label"
                    :presets="field.presets"
                />
            </template>
        </template>

        <!-- 3-zone rendering (spec §6.1) -->
        <template v-else>
            <!-- QUICK zone -->
            <template v-for="field in quickFields" :key="'q-' + field.key">
                <SearchFilter
                    v-if="field.type === 'search'"
                    :model-value="modelValue[field.key]"
                    @update:model-value="(v) => setField(field.key, v)"
                    :label="field.label"
                    :placeholder="field.placeholder"
                />
                <SelectFilter
                    v-else-if="field.type === 'select'"
                    :model-value="modelValue[field.key]"
                    @update:model-value="(v) => setField(field.key, v)"
                    :label="field.label"
                    :options="field.options || []"
                    :placeholder="field.placeholder"
                    :disabled="isBranchLocked(field)"
                />
                <CheckboxFilter
                    v-else-if="field.type === 'checkbox'"
                    :model-value="modelValue[field.key] || []"
                    @update:model-value="(v) => setField(field.key, v)"
                    :label="field.label"
                    :options="field.options || []"
                />
                <DateRangeFilter
                    v-else-if="field.type === 'dateRange'"
                    :model-value="dateValue(field)"
                    @update:model-value="(v) => onDateUpdate(field, v)"
                    :label="field.label"
                    :presets="field.presets"
                />
            </template>

            <!-- MAIN zone (separator only when quick exists) -->
            <div v-if="quickFields.length && mainFields.length" class="h-px bg-gray-200 my-1"></div>
            <template v-for="field in mainFields" :key="'m-' + field.key">
                <SearchFilter
                    v-if="field.type === 'search'"
                    :model-value="modelValue[field.key]"
                    @update:model-value="(v) => setField(field.key, v)"
                    :label="field.label"
                    :placeholder="field.placeholder"
                />
                <SelectFilter
                    v-else-if="field.type === 'select'"
                    :model-value="modelValue[field.key]"
                    @update:model-value="(v) => setField(field.key, v)"
                    :label="field.label"
                    :options="field.options || []"
                    :placeholder="field.placeholder"
                    :disabled="isBranchLocked(field)"
                />
                <CheckboxFilter
                    v-else-if="field.type === 'checkbox'"
                    :model-value="modelValue[field.key] || []"
                    @update:model-value="(v) => setField(field.key, v)"
                    :label="field.label"
                    :options="field.options || []"
                />
                <DateRangeFilter
                    v-else-if="field.type === 'dateRange'"
                    :model-value="dateValue(field)"
                    @update:model-value="(v) => onDateUpdate(field, v)"
                    :label="field.label"
                    :presets="field.presets"
                />
            </template>

            <!-- ADVANCED zone — collapsible -->
            <template v-if="advancedFields.length">
                <div class="pt-2 border-t border-gray-200">
                    <button
                        type="button"
                        @click="advancedOpen = !isAdvancedOpen"
                        class="w-full flex items-center justify-between px-2 py-1.5 text-[13px] font-medium text-gray-600 hover:text-blue-600 focus:outline-none"
                    >
                        <span class="inline-flex items-center gap-1">
                            Bộ lọc nâng cao
                            <span
                                v-if="advancedActiveCount > 0"
                                class="inline-flex items-center justify-center w-4 h-4 text-[10px] font-bold text-white bg-blue-500 rounded-full"
                            >
                                {{ advancedActiveCount }}
                            </span>
                        </span>
                        <svg
                            class="w-4 h-4 transition-transform"
                            :class="{ 'rotate-180': isAdvancedOpen }"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>
                    <div v-show="isAdvancedOpen" class="mt-2 space-y-3">
                        <template v-for="field in advancedFields" :key="'a-' + field.key">
                            <SearchFilter
                                v-if="field.type === 'search'"
                                :model-value="modelValue[field.key]"
                                @update:model-value="(v) => setField(field.key, v)"
                                :label="field.label"
                                :placeholder="field.placeholder"
                            />
                            <SelectFilter
                                v-else-if="field.type === 'select'"
                                :model-value="modelValue[field.key]"
                                @update:model-value="(v) => setField(field.key, v)"
                                :label="field.label"
                                :options="field.options || []"
                                :placeholder="field.placeholder"
                                :disabled="isBranchLocked(field)"
                            />
                            <CheckboxFilter
                                v-else-if="field.type === 'checkbox'"
                                :model-value="modelValue[field.key] || []"
                                @update:model-value="(v) => setField(field.key, v)"
                                :label="field.label"
                                :options="field.options || []"
                            />
                            <DateRangeFilter
                                v-else-if="field.type === 'dateRange'"
                                :model-value="dateValue(field)"
                                @update:model-value="(v) => onDateUpdate(field, v)"
                                :label="field.label"
                                :presets="field.presets"
                            />
                        </template>
                    </div>
                </div>
            </template>
        </template>

        <div v-if="activeCount === 0" class="flex justify-end">
            <button
                type="button"
                @click="emit('reset')"
                class="text-xs text-gray-500 hover:text-blue-600 underline"
            >
                Đặt lại bộ lọc
            </button>
        </div>
    </aside>
</template>
