<script setup>
/**
 * CustomerGroupCombobox — KiotViet-style group picker.
 *
 * v-model contract: a string (the group's `value`/name). Plain string so it
 * stays drop-in compatible with the legacy `customer_group` text column.
 *
 * @create event fires with the typed query when the user clicks
 * "+ Tạo nhóm khách hàng mới" or "+ Tạo mới: {query}". The parent owns
 * the actual creation (so the existing reloadCustomerGroups + groupModal
 * flow stays intact).
 */
import { computed, ref, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    groups: { type: Array, default: () => [] }, // [{ value, label, id?, source? }]
    placeholder: { type: String, default: 'Chọn nhóm' },
    allowCreate: { type: Boolean, default: true },
    disabled: { type: Boolean, default: false },
    /** Tailwind class applied to the trigger input. */
    inputClass: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue', 'create']);

const open = ref(false);
const query = ref('');
const rootEl = ref(null);
const inputEl = ref(null);
const dropdownStyle = ref({});

// Selected label (for display when popover is closed).
const selectedLabel = computed(() => {
    if (!props.modelValue) return '';
    const found = props.groups.find((g) => g.value === props.modelValue);
    return found ? found.label : props.modelValue;
});

// Show selected label in the input when closed; show typed query when open.
const displayValue = computed({
    get: () => (open.value ? query.value : selectedLabel.value),
    set: (v) => { query.value = v; },
});

const filtered = computed(() => {
    const q = (query.value || '').trim().toLowerCase();
    if (!q) return props.groups;
    return props.groups.filter((g) => (g.label || '').toLowerCase().includes(q));
});

const showCreateForQuery = computed(() => {
    if (!props.allowCreate) return false;
    const q = (query.value || '').trim();
    if (!q) return false;
    // Hide the inline "create for query" hint if the query exactly matches an
    // existing group — user can just pick it from the list.
    return !props.groups.some((g) => (g.label || '').toLowerCase() === q.toLowerCase());
});

const openDropdown = async () => {
    if (props.disabled) return;
    open.value = true;
    query.value = '';
    await nextTick();
    updateDropdownPosition();
    inputEl.value?.focus();
};

const updateDropdownPosition = () => {
    if (!rootEl.value) return;
    const rect = rootEl.value.getBoundingClientRect();
    dropdownStyle.value = {
        position: 'fixed',
        top: `${rect.bottom + 4}px`,
        left: `${rect.left}px`,
        width: `${rect.width}px`,
    };
};

const closeDropdown = () => {
    open.value = false;
    query.value = '';
};

const onSelect = (group) => {
    emit('update:modelValue', group.value);
    closeDropdown();
};

const clearSelection = () => {
    emit('update:modelValue', '');
    closeDropdown();
};

const onCreateNew = () => {
    emit('create', (query.value || '').trim());
    // Parent decides what to do — usually it'll set modelValue itself once
    // the new group is created. We close the dropdown either way.
    closeDropdown();
};

// Document-level click + Esc handling.
const onDocClick = (e) => {
    if (!open.value) return;
    if (rootEl.value && !rootEl.value.contains(e.target)) {
        closeDropdown();
    }
};
const onViewportChange = () => {
    if (open.value) updateDropdownPosition();
};
const onKey = (e) => {
    if (!open.value) return;
    if (e.key === 'Escape') {
        e.preventDefault();
        closeDropdown();
    }
};

onMounted(() => {
    document.addEventListener('mousedown', onDocClick);
    document.addEventListener('keydown', onKey);
    window.addEventListener('resize', onViewportChange);
    window.addEventListener('scroll', onViewportChange, true);
});
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocClick);
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('resize', onViewportChange);
    window.removeEventListener('scroll', onViewportChange, true);
});

// If the parent updates modelValue while the popover is open (e.g. after
// quick-create), reflect it and close.
watch(() => props.modelValue, () => {
    if (open.value) closeDropdown();
});
</script>

<template>
    <div ref="rootEl" class="relative w-full">
        <!-- Trigger / search input -->
        <div
            class="flex items-center w-full border border-gray-300 rounded bg-white focus-within:ring-1 focus-within:ring-blue-500 focus-within:border-blue-500 transition-colors"
            :class="disabled ? 'bg-gray-100 cursor-not-allowed' : 'cursor-text'"
            @click="open ? null : openDropdown()"
        >
            <input
                ref="inputEl"
                type="text"
                :value="displayValue"
                @input="(e) => { query = e.target.value; if (!open) openDropdown(); }"
                @focus="!open && openDropdown()"
                :placeholder="open ? 'Tìm hoặc tạo nhóm...' : placeholder"
                :disabled="disabled"
                :class="['flex-1 min-w-0 px-3 py-1.5 text-sm bg-transparent outline-none', inputClass]"
                autocomplete="off"
            />
            <button
                v-if="modelValue && !open && !disabled"
                type="button"
                @click.stop="clearSelection"
                class="px-2 text-gray-400 hover:text-gray-600"
                title="Bỏ chọn"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <span class="px-2 text-gray-400 pointer-events-none">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </span>
        </div>

        <!-- Dropdown -->
        <div
            v-if="open"
            class="bg-white border border-gray-200 rounded shadow-lg z-[120] max-h-64 overflow-y-auto"
            :style="dropdownStyle"
            data-customer-group-dropdown
        >
            <ul v-if="filtered.length" class="py-1 text-sm">
                <li
                    v-for="g in filtered"
                    :key="g.value"
                    @mousedown.prevent="onSelect(g)"
                    :class="[
                        'px-3 py-1.5 cursor-pointer flex items-center justify-between hover:bg-blue-50',
                        g.value === modelValue ? 'bg-blue-50 text-blue-700 font-semibold' : 'text-gray-700'
                    ]"
                >
                    <span>{{ g.label }}</span>
                    <svg v-if="g.value === modelValue" class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </li>
            </ul>
            <div v-else class="px-3 py-2 text-sm text-gray-500">Không tìm thấy nhóm phù hợp.</div>

            <div v-if="allowCreate" class="border-t border-gray-100">
                <button
                    v-if="showCreateForQuery"
                    type="button"
                    @mousedown.prevent="onCreateNew"
                    class="w-full text-left px-3 py-2 text-sm text-blue-600 hover:bg-blue-50 flex items-center gap-2 font-medium"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Tạo mới: <span class="truncate">"{{ query.trim() }}"</span>
                </button>
                <button
                    v-else
                    type="button"
                    @mousedown.prevent="onCreateNew"
                    class="w-full text-left px-3 py-2 text-sm text-blue-600 hover:bg-blue-50 flex items-center gap-2"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Tạo nhóm khách hàng mới
                </button>
            </div>
        </div>
    </div>
</template>
