<script setup>
import { Head, Link, router } from "@inertiajs/vue3";
import { reactive } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import DebtOffsetActionPanel from "@/Components/DebtOffsets/DebtOffsetActionPanel.vue";
import DebtOffsetStatusBadge from "@/Components/DebtOffsets/DebtOffsetStatusBadge.vue";
import { formatVND } from "@/utils/money";

const props = defineProps({
    offsets: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    write_mode: { type: String, required: true },
});

const form = reactive({
    status: props.filters.status || "",
    search: props.filters.search || "",
    date_from: props.filters.date_from || "",
    date_to: props.filters.date_to || "",
    per_page: props.filters.per_page || 25,
});

const statuses = [
    ["", "Tất cả"], ["draft", "Nháp"], ["pending_approval", "Chờ duyệt"],
    ["approved", "Đã duyệt"], ["applied", "Đã áp dụng"], ["rejected", "Từ chối"],
    ["void", "Đã hủy"], ["reversed", "Đã đảo"], ["legacy", "Legacy"],
];

const applyFilters = () => router.get("/debt-offsets", form, { preserveState: true, replace: true });
const selectStatus = (status) => { form.status = status; applyFilters(); };
const reload = () => router.reload({ preserveScroll: true });
</script>

<template>
    <Head title="Yêu cầu cấn trừ công nợ" />
    <AppLayout>
        <div class="mx-auto max-w-[1500px] p-5">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">Yêu cầu cấn trừ công nợ</h1>
                    <p class="mt-1 text-sm text-gray-500">Quy trình duyệt và bằng chứng thay đổi công nợ đối tác hai vai trò.</p>
                </div>
                <span v-if="write_mode !== 'workflow'" class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    Chế độ hiện tại: {{ write_mode }}. Trang chỉ đọc.
                </span>
            </div>

            <div class="rounded-lg bg-white shadow-sm">
                <div class="flex flex-wrap gap-2 border-b px-4 pt-4">
                    <button v-for="status in statuses" :key="status[0]" class="border-b-2 px-3 py-2 text-sm font-semibold" :class="form.status === status[0] ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500'" @click="selectStatus(status[0])">
                        {{ status[1] }}
                    </button>
                </div>
                <form class="grid grid-cols-1 gap-3 border-b p-4 md:grid-cols-5" @submit.prevent="applyFilters">
                    <input v-model="form.search" class="rounded border border-gray-300 px-3 py-2 text-sm md:col-span-2" placeholder="Tìm mã phiếu, mã hoặc tên đối tác" />
                    <input v-model="form.date_from" type="date" class="rounded border border-gray-300 px-3 py-2 text-sm" />
                    <input v-model="form.date_to" type="date" class="rounded border border-gray-300 px-3 py-2 text-sm" />
                    <button class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Lọc</button>
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-3 py-3 text-left">Mã phiếu</th>
                                <th class="px-3 py-3 text-left">Đối tác</th>
                                <th class="px-3 py-3 text-right">Phải thu</th>
                                <th class="px-3 py-3 text-right">Phải trả</th>
                                <th class="px-3 py-3 text-right">Số tiền cấn</th>
                                <th class="px-3 py-3 text-center">Trạng thái</th>
                                <th class="px-3 py-3 text-left">Người yêu cầu</th>
                                <th class="px-3 py-3 text-left">Người duyệt</th>
                                <th class="px-3 py-3 text-left">Ngày yêu cầu</th>
                                <th class="px-3 py-3 text-left">Ngày áp dụng</th>
                                <th class="px-3 py-3 text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="offset in offsets.data" :key="offset.id" class="hover:bg-gray-50">
                                <td class="px-3 py-3 font-semibold text-blue-700">{{ offset.code }}</td>
                                <td class="px-3 py-3"><div class="font-medium">{{ offset.partner?.name }}</div><div class="text-xs text-gray-400">{{ offset.partner?.code }}</div></td>
                                <td class="px-3 py-3 text-right">{{ formatVND(offset.receivable_before) }}</td>
                                <td class="px-3 py-3 text-right">{{ formatVND(offset.payable_before) }}</td>
                                <td class="px-3 py-3 text-right font-bold">{{ formatVND(offset.amount) }}</td>
                                <td class="px-3 py-3 text-center"><DebtOffsetStatusBadge :status="offset.workflow_status" /></td>
                                <td class="px-3 py-3">{{ offset.requester?.name || '-' }}</td>
                                <td class="px-3 py-3">{{ offset.approver?.name || '-' }}</td>
                                <td class="px-3 py-3 text-xs">{{ offset.requested_at ? new Date(offset.requested_at).toLocaleString('vi-VN') : '-' }}</td>
                                <td class="px-3 py-3 text-xs">{{ offset.applied_at ? new Date(offset.applied_at).toLocaleString('vi-VN') : '-' }}</td>
                                <td class="px-3 py-3"><DebtOffsetActionPanel :offset="offset" :write-mode="write_mode" @updated="reload" @reload="reload" /></td>
                            </tr>
                            <tr v-if="!offsets.data.length"><td colspan="11" class="px-4 py-12 text-center text-gray-400">Không có yêu cầu phù hợp.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm">
                    <span class="text-gray-500">Hiển thị {{ offsets.from || 0 }}-{{ offsets.to || 0 }} / {{ offsets.total || 0 }}</span>
                    <div class="flex gap-1">
                        <Link v-for="link in offsets.links" :key="link.label" :href="link.url || '#'" class="rounded border px-3 py-1.5" :class="[link.active ? 'border-blue-600 bg-blue-600 text-white' : 'text-gray-600', !link.url ? 'pointer-events-none opacity-40' : '']" preserve-state v-html="link.label" />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
