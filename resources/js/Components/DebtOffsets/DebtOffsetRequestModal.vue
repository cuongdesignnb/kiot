<script setup>
import axios from "axios";
import { computed, reactive, watch } from "vue";
import MoneyInput from "@/Components/MoneyInput.vue";
import { formatVND } from "@/utils/money";

const props = defineProps({
    show: { type: Boolean, default: false },
    partner: { type: Object, default: null },
    canSubmit: { type: Boolean, default: false },
});
const emit = defineEmits(["close", "success"]);

const form = reactive({
    amount: 0,
    note: "",
    processing: false,
    error: "",
    idempotencyKey: null,
    submitIdempotencyKey: null,
});
const receivable = computed(() => Number(props.partner?.debt_amount || 0));
const payable = computed(() => Number(props.partner?.supplier_debt_amount || 0));
const maximum = computed(() => Math.max(0, Math.min(receivable.value, payable.value)));
const valid = computed(() => Number(form.amount) > 0 && Number(form.amount) <= maximum.value);

const newKey = () => globalThis.crypto?.randomUUID?.() || `debt-offset-${Date.now()}-${Math.random()}`;

watch(() => props.show, (visible) => {
    if (!visible) return;
    form.amount = maximum.value;
    form.note = "";
    form.error = "";
    form.processing = false;
    form.idempotencyKey = newKey();
    form.submitIdempotencyKey = newKey();
});

const close = () => {
    if (!form.processing) emit("close");
};

const submit = async (submitForApproval) => {
    if (!valid.value || form.processing) return;
    form.processing = true;
    form.error = "";
    try {
        const created = await axios.post(`/customers/${props.partner.id}/debt-offsets`, {
            amount: String(Math.trunc(Number(form.amount))),
            note: form.note || null,
        }, { headers: { "Idempotency-Key": form.idempotencyKey } });
        let result = created.data.data;
        if (submitForApproval) {
            const offset = result.debt_offset;
            const submitted = await axios.post(`/debt-offsets/${offset.id}/submit`, {
                version_token: offset.version_token,
            }, { headers: { "Idempotency-Key": form.submitIdempotencyKey } });
            result = submitted.data.data;
        }
        emit("success", result);
        emit("close");
    } catch (error) {
        form.error = error.response?.data?.message || "Không thể lưu yêu cầu cấn trừ công nợ.";
    } finally {
        form.processing = false;
    }
};
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-[70] flex items-center justify-center bg-black/40" @click.self="close">
        <div class="w-full max-w-lg rounded-lg bg-white shadow-xl">
            <div class="flex items-center justify-between border-b px-6 py-4">
                <h3 class="text-lg font-bold text-gray-800">Tạo yêu cầu cấn trừ công nợ</h3>
                <button class="text-xl text-gray-400 hover:text-gray-700" :disabled="form.processing" @click="close">×</button>
            </div>
            <div class="space-y-4 px-6 py-5">
                <div class="text-sm text-gray-700">
                    <strong>{{ partner?.name }}</strong>
                    <span class="ml-2 text-gray-400">{{ partner?.code }}</span>
                </div>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded border border-blue-200 bg-blue-50 p-3">
                        <div class="text-gray-500">Nợ phải thu hiện tại</div>
                        <div class="mt-1 font-bold text-blue-700">{{ formatVND(receivable) }}</div>
                    </div>
                    <div class="rounded border border-red-200 bg-red-50 p-3">
                        <div class="text-gray-500">Nợ phải trả hiện tại</div>
                        <div class="mt-1 font-bold text-red-700">{{ formatVND(payable) }}</div>
                    </div>
                </div>
                <div class="rounded border bg-gray-50 p-3 text-sm">
                    Mức cấn trừ tối đa: <strong>{{ formatVND(maximum) }}</strong>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Số tiền yêu cầu</label>
                    <MoneyInput v-model="form.amount" :min="1" class="w-full" />
                    <p v-if="form.amount && !valid" class="mt-1 text-xs text-red-600">Số tiền phải lớn hơn 0 và không vượt mức tối đa.</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Ghi chú</label>
                    <textarea v-model="form.note" maxlength="1000" rows="3" class="w-full rounded border border-gray-300 px-3 py-2 text-sm" />
                </div>
                <div class="rounded bg-gray-50 p-3 text-sm">
                    <div class="flex justify-between"><span>Phải thu sau cấn (preview)</span><strong>{{ formatVND(Math.max(0, receivable - Number(form.amount || 0))) }}</strong></div>
                    <div class="mt-1 flex justify-between"><span>Phải trả sau cấn (preview)</span><strong>{{ formatVND(Math.max(0, payable - Number(form.amount || 0))) }}</strong></div>
                </div>
                <p v-if="form.error" class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ form.error }}</p>
            </div>
            <div class="flex justify-end gap-2 border-t px-6 py-4">
                <button class="rounded border px-4 py-2 text-sm" :disabled="form.processing" @click="close">Bỏ qua</button>
                <button class="rounded bg-gray-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="!valid || form.processing" @click="submit(false)">
                    {{ form.processing ? "Đang lưu..." : "Lưu nháp" }}
                </button>
                <button v-if="canSubmit" class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="!valid || form.processing" @click="submit(true)">
                    Lưu và gửi duyệt
                </button>
            </div>
        </div>
    </div>
</template>
