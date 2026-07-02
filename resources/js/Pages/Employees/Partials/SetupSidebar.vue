<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    activeMain: {
        type: String,
        default: 'attendance',
    },
    activeUtility: {
        type: String,
        default: '',
    },
});

const mainItems = [
    { key: 'init', label: 'Khởi tạo', href: '/employees/settings', icon: '□' },
    { key: 'attendance', label: 'Chấm công', href: '/employees/attendance/settings', icon: '◫' },
    { key: 'payroll', label: 'Tính lương', href: '/employees/payroll/settings', icon: '◌' },
    { key: 'workdays', label: 'Ngày làm & Nghỉ', href: '/employees/workday/settings', icon: '◪' },
];

const utilityItems = [
    { key: 'zalo', label: 'Zalo mini app', href: '/settings', badge: 'Mới' },
    { key: 'devices', label: 'Máy chấm công', href: '/employees/attendance/settings/devices' },
];
</script>

<template>
    <div class="min-h-full bg-[#f3f4f6] px-4 py-4">
        <div class="mb-3 text-[15px] font-semibold text-slate-900">Thiết lập nhân viên</div>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-4 py-3 text-sm font-medium text-slate-600">Thiết lập</div>
            <div class="p-3">
                <Link
                    v-for="item in mainItems"
                    :key="item.key"
                    :href="item.href"
                    class="mb-1 flex items-center gap-3 rounded-md px-3 py-2.5 text-sm transition last:mb-0"
                    :class="activeMain === item.key ? 'bg-blue-100 text-blue-700' : 'text-slate-700 hover:bg-slate-50'"
                >
                    <span class="flex h-5 w-5 items-center justify-center text-[12px]">{{ item.icon }}</span>
                    <span class="font-medium">{{ item.label }}</span>
                </Link>
            </div>

            <div class="border-t border-slate-200 px-4 py-3 text-sm font-medium text-slate-600">Tiện ích</div>
            <div class="p-3">
                <Link
                    v-for="item in utilityItems"
                    :key="item.key"
                    :href="item.href"
                    class="mb-1 flex items-center justify-between gap-3 rounded-md px-3 py-2.5 text-sm transition last:mb-0"
                    :class="activeUtility === item.key ? 'bg-blue-100 text-blue-700' : 'text-slate-700 hover:bg-slate-50'"
                >
                    <span class="truncate font-medium">{{ item.label }}</span>
                    <span v-if="item.badge" class="rounded bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ item.badge }}</span>
                </Link>
            </div>
        </div>
    </div>
</template>