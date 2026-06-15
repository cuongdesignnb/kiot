<script setup>
import { computed } from "vue";
import { formatVND } from "@/utils/money";

const props = defineProps({
    employee: { type: Object, required: true },
    ledger: { type: Object, required: true },
    canExport: { type: Boolean, default: false },
});

const emit = defineEmits(["retry", "page", "export"]);

const rows = computed(() => props.ledger.data?.data || []);
const summary = computed(() => props.ledger.summary || {
    current_balance: 0,
    total_increase: 0,
    total_decrease: 0,
    entry_count: 0,
});

const typeLabels = {
    opening_balance: "Số dư đầu kỳ",
    payroll_accrual: "Ghi nhận lương phải trả",
    salary_payment: "Thanh toán lương",
    salary_advance: "Tạm ứng lương",
    advance_application: "Cấn trừ tạm ứng",
    manual_adjustment: "Điều chỉnh thủ công",
    adjustment_increase: "Điều chỉnh tăng",
    adjustment_decrease: "Điều chỉnh giảm",
    cancel_reverse: "Đảo/hủy phát sinh",
};

const typeLabel = (entry) => typeLabels[entry.type] || "Khác";
const statusLabel = (entry) => {
    if (entry.type === "cancel_reverse") return "Dòng đảo";
    if (entry.status === "reversed") return "Đã đảo";
    if (entry.status === "cancelled") return "Đã hủy";
    return "Hợp lệ";
};
const eventDate = (value) => value
    ? new Intl.DateTimeFormat("vi-VN", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    }).format(new Date(value))
    : "-";
</script>

<template>
    <div class="border-y-2 border-blue-500 bg-white">
        <div class="flex items-center gap-8 border-b border-gray-200 px-5 text-[13px] text-gray-600">
            <span class="py-3">Thông tin</span>
            <span class="py-3">Lịch làm việc</span>
            <span class="py-3">Thiết lập lương</span>
            <span class="py-3">Phiếu lương</span>
            <span class="border-b-2 border-blue-500 py-3 font-medium text-blue-600">Nợ và tạm ứng</span>
        </div>

        <div v-if="ledger.loading" class="space-y-3 px-5 py-5" aria-label="Đang tải lịch sử nợ và tạm ứng">
            <div class="h-8 w-full animate-pulse rounded bg-gray-100"></div>
            <div class="h-32 w-full animate-pulse rounded bg-gray-100"></div>
        </div>

        <div v-else-if="ledger.error" class="m-5 rounded border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            Không tải được lịch sử nợ & tạm ứng. Vui lòng thử lại.
            <button type="button" class="ml-2 font-semibold underline" @click="emit('retry')">Thử lại</button>
        </div>

        <div v-else class="px-5 py-5">
            <div class="mb-4 flex flex-wrap items-center gap-x-8 gap-y-2 text-xs text-gray-600">
                <span>Số dư hiện tại: <strong class="text-gray-900">{{ formatVND(summary.current_balance) }}</strong></span>
                <span>Phát sinh tăng: <strong class="text-emerald-600">{{ formatVND(summary.total_increase) }}</strong></span>
                <span>Phát sinh giảm: <strong class="text-orange-600">{{ formatVND(summary.total_decrease) }}</strong></span>
            </div>

            <div class="overflow-hidden">
                <div v-if="!rows.length" class="px-4 py-10 text-center text-sm text-gray-500">
                    Chưa có phát sinh nợ & tạm ứng
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-[13px]">
                        <thead class="bg-[#eef0f3] text-xs font-bold text-gray-800">
                            <tr>
                                <th class="px-3 py-2.5 text-left">Mã phiếu</th>
                                <th class="px-3 py-2.5 text-left">Thời gian</th>
                                <th class="px-3 py-2.5 text-left">Loại phiếu</th>
                                <th class="px-3 py-2.5 text-right">Giá trị</th>
                                <th class="px-3 py-2.5 text-right">Nợ và tạm ứng</th>
                                <th class="px-3 py-2.5 text-left">Ghi chú</th>
                                <th class="px-3 py-2.5 text-left">Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="entry in rows" :key="entry.id" class="hover:bg-blue-50/40">
                                <td class="px-3 py-2.5 font-medium text-blue-600">{{ entry.code || "-" }}</td>
                                <td class="px-3 py-2.5">{{ eventDate(entry.event_at) }}</td>
                                <td class="px-3 py-2.5">
                                    <div>{{ typeLabel(entry) }}</div>
                                    <div v-if="!typeLabels[entry.type]" class="text-[11px] text-gray-400">{{ entry.type }}</div>
                                </td>
                                <td class="px-3 py-2.5 text-right" :class="Number(entry.amount) < 0 ? 'text-orange-600' : 'text-emerald-600'">
                                    {{ Number(entry.amount) > 0 ? `+${formatVND(entry.amount)}` : formatVND(entry.amount) }}
                                </td>
                                <td class="px-3 py-2.5 text-right font-medium text-gray-900">{{ formatVND(entry.balance_after) }}</td>
                                <td class="max-w-xs whitespace-normal px-3 py-2.5 text-gray-600">{{ entry.note || entry.reason || "-" }}</td>
                                <td class="px-3 py-2.5">
                                    <span class="text-xs font-medium text-gray-700">
                                        {{ statusLabel(entry) }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-3 text-xs">
                <div class="flex items-center gap-2 text-gray-600">
                    <button
                        type="button"
                        class="px-1 disabled:text-gray-300"
                        :disabled="(ledger.data?.current_page || 1) <= 1"
                        @click="emit('page', (ledger.data.current_page || 1) - 1)"
                    >‹</button>
                    <span class="rounded border px-3 py-1">{{ ledger.data?.current_page || 1 }}</span>
                    <button
                        type="button"
                        class="px-1 disabled:text-gray-300"
                        :disabled="(ledger.data?.current_page || 1) >= (ledger.data?.last_page || 1)"
                        @click="emit('page', (ledger.data.current_page || 1) + 1)"
                    >›</button>
                    <strong>{{ ledger.data?.from || 0 }} - {{ ledger.data?.to || 0 }} trong {{ ledger.data?.total || 0 }} phiếu thanh toán</strong>
                </div>
                <button
                    v-if="canExport"
                    type="button"
                    class="rounded border border-gray-300 bg-white px-3 py-2 font-medium text-gray-700 hover:bg-gray-50"
                    @click="emit('export')"
                >
                    Xuất file nợ lương
                </button>
            </div>
        </div>
    </div>
</template>
