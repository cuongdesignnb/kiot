<script setup>
import axios from "axios";
import { computed, reactive } from "vue";
import { usePermission } from "@/composables/usePermission";
import { formatVND } from "@/utils/money";

const props = defineProps({
    offset: { type: Object, required: true },
    writeMode: { type: String, required: true },
});
const emit = defineEmits(["updated", "reload"]);
const { can } = usePermission();

const dialog = reactive({ open: false, action: "", reason: "", amount: "", note: "", processing: false, error: "", errorCode: "", key: null });
const newKey = () => globalThis.crypto?.randomUUID?.() || `debt-offset-${Date.now()}-${Math.random()}`;

const actions = computed(() => {
    if (props.writeMode !== "workflow") return [];
    const state = props.offset.workflow_status;
    const result = [];
    if (state === "draft" && can("debt_offsets.create")) result.push(["edit", "Sửa"]);
    if (state === "draft" && can("debt_offsets.submit")) result.push(["submit", "Gửi duyệt"]);
    if (state === "draft" && can("debt_offsets.void")) result.push(["void", "Hủy"]);
    if (state === "pending_approval" && can("debt_offsets.approve")) result.push(["approve", "Duyệt"]);
    if (state === "pending_approval" && can("debt_offsets.reject")) result.push(["reject", "Từ chối"]);
    if (state === "approved" && can("debt_offsets.apply")) result.push(["apply", "Áp dụng"]);
    if ((state === "applied" || (props.offset.is_legacy && props.offset.status === "active")) && can("debt_offsets.reverse")) result.push(["reverse", "Đảo phiếu"]);
    return result;
});

const labels = {
    edit: "Sửa bản nháp",
    submit: "Gửi yêu cầu để duyệt",
    approve: "Duyệt yêu cầu cấn trừ",
    reject: "Từ chối yêu cầu cấn trừ",
    apply: "Áp dụng vào công nợ",
    reverse: "Đảo phiếu đã áp dụng",
    void: "Hủy bản nháp",
};

const balancePreview = computed(() => {
    const receivable = Number(props.offset.partner?.debt_amount ?? props.offset.receivable_before ?? 0);
    const payable = Number(props.offset.partner?.supplier_debt_amount ?? props.offset.payable_before ?? 0);
    const amount = Number(props.offset.amount || 0);

    if (dialog.action === "apply") {
        return {
            receivableBefore: receivable,
            payableBefore: payable,
            receivableAfter: Math.max(0, receivable - amount),
            payableAfter: Math.max(0, payable - amount),
        };
    }
    if (dialog.action === "reverse") {
        return {
            receivableBefore: receivable,
            payableBefore: payable,
            receivableAfter: receivable + amount,
            payableAfter: payable + amount,
        };
    }

    return {
        receivableBefore: props.offset.receivable_before,
        payableBefore: props.offset.payable_before,
        receivableAfter: props.offset.receivable_after,
        payableAfter: props.offset.payable_after,
    };
});

const open = (action) => {
    dialog.open = true;
    dialog.action = action;
    dialog.reason = "";
    dialog.amount = String(Math.trunc(Number(props.offset.amount || 0)));
    dialog.note = props.offset.note || "";
    dialog.processing = false;
    dialog.error = "";
    dialog.errorCode = "";
    dialog.key = newKey();
};

const close = () => {
    if (!dialog.processing) dialog.open = false;
};

const execute = async () => {
    if (dialog.processing) return;
    if (["reject", "reverse"].includes(dialog.action) && !dialog.reason.trim()) {
        dialog.error = "Vui lòng nhập lý do.";
        return;
    }
    dialog.processing = true;
    dialog.error = "";
    dialog.errorCode = "";
    try {
        let response;
        if (dialog.action === "edit") {
            response = await axios.patch(`/debt-offsets/${props.offset.id}`, {
                amount: dialog.amount,
                note: dialog.note || null,
                version_token: props.offset.version_token,
            }, { headers: { "Idempotency-Key": dialog.key } });
        } else {
            const payload = { version_token: props.offset.version_token };
            if (dialog.action === "reject") payload.rejection_reason = dialog.reason;
            if (["reverse", "void"].includes(dialog.action)) payload.reason = dialog.reason || null;
            response = await axios.post(`/debt-offsets/${props.offset.id}/${dialog.action}`, payload, {
                headers: { "Idempotency-Key": dialog.key },
            });
        }
        emit("updated", response.data.data);
        dialog.open = false;
    } catch (error) {
        dialog.error = error.response?.data?.message || "Không thể thực hiện thao tác.";
        dialog.errorCode = error.response?.data?.error_code || "";
    } finally {
        dialog.processing = false;
    }
};
</script>

<template>
    <div class="flex flex-wrap justify-end gap-1.5">
        <button v-for="action in actions" :key="action[0]" class="rounded border border-blue-200 bg-white px-2.5 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-50" @click="open(action[0])">
            {{ action[1] }}
        </button>
    </div>

    <div v-if="dialog.open" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/40" @click.self="close">
        <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
            <div class="border-b px-5 py-4 text-lg font-bold text-gray-800">{{ labels[dialog.action] }}</div>
            <div class="space-y-4 px-5 py-5 text-sm">
                <div class="rounded bg-gray-50 p-3">
                    <div class="flex justify-between"><span>Phiếu</span><strong>{{ offset.code }}</strong></div>
                    <div class="mt-1 flex justify-between"><span>Số tiền</span><strong>{{ formatVND(offset.amount) }}</strong></div>
                    <template v-if="[&quot;apply&quot;, &quot;reverse&quot;].includes(dialog.action)">
                        <div class="mt-1 flex justify-between"><span>Phải thu trước/sau</span><strong>{{ formatVND(balancePreview.receivableBefore) }} / {{ formatVND(balancePreview.receivableAfter) }}</strong></div>
                        <div class="mt-1 flex justify-between"><span>Phải trả trước/sau</span><strong>{{ formatVND(balancePreview.payableBefore) }} / {{ formatVND(balancePreview.payableAfter) }}</strong></div>
                    </template>
                </div>
                <template v-if="dialog.action === 'edit'">
                    <label class="block">Số tiền
                        <input v-model="dialog.amount" inputmode="decimal" class="mt-1 w-full rounded border border-gray-300 px-3 py-2" />
                    </label>
                    <label class="block">Ghi chú
                        <textarea v-model="dialog.note" maxlength="1000" rows="3" class="mt-1 w-full rounded border border-gray-300 px-3 py-2" />
                    </label>
                </template>
                <label v-if="['reject', 'reverse', 'void'].includes(dialog.action)" class="block">
                    Lý do <span v-if="['reject', 'reverse'].includes(dialog.action)" class="text-red-600">*</span>
                    <textarea v-model="dialog.reason" maxlength="2000" rows="3" class="mt-1 w-full rounded border border-gray-300 px-3 py-2" />
                </label>
                <p v-if="dialog.error" class="rounded border border-red-200 bg-red-50 p-3 text-red-700">{{ dialog.error }}</p>
                <button v-if="dialog.errorCode === 'STALE_DEBT_OFFSET_VERSION'" class="text-blue-700 underline" @click="emit('reload')">Tải lại dữ liệu mới nhất</button>
            </div>
            <div class="flex justify-end gap-2 border-t px-5 py-4">
                <button class="rounded border px-4 py-2" :disabled="dialog.processing" @click="close">Bỏ qua</button>
                <button class="rounded bg-blue-600 px-4 py-2 font-semibold text-white disabled:opacity-50" :disabled="dialog.processing" @click="execute">
                    {{ dialog.processing ? "Đang xử lý..." : "Xác nhận" }}
                </button>
            </div>
        </div>
    </div>
</template>
