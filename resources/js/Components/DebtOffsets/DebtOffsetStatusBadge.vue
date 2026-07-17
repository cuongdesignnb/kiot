<script setup>
import { computed } from "vue";

const props = defineProps({ status: { type: String, default: null } });

const statuses = {
    draft: ["Nháp", "bg-gray-100 text-gray-700"],
    pending_approval: ["Chờ duyệt", "bg-amber-100 text-amber-800"],
    approved: ["Đã duyệt", "bg-blue-100 text-blue-800"],
    applied: ["Đã áp dụng", "bg-green-100 text-green-800"],
    rejected: ["Từ chối", "bg-red-100 text-red-800"],
    void: ["Đã hủy", "bg-gray-200 text-gray-700"],
    reversed: ["Đã đảo", "bg-purple-100 text-purple-800"],
};

const badge = computed(() => statuses[props.status] || ["Legacy", "bg-slate-100 text-slate-700"]);
</script>

<template>
    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="badge[1]">
        {{ badge[0] }}
    </span>
</template>
