<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    tokens: Array,
    access_logs: Array,
    base_url: String,
    rate_limit_per_minute: Number,
});

const page = usePage();
const permissions = computed(() => page.props.auth?.permissions || []);
const can = (permission) => permissions.value.includes('*') || permissions.value.includes(permission);
const canManage = computed(() => can('integrations.manage'));

const tokens = ref((props.tokens || []).map((token) => ({ ...token })));
const activeCount = computed(() => tokens.value.filter((token) => !token.revoked_at).length);
const busy = ref(false);
const showCreate = ref(false);
const form = reactive({ name: '', account_label: '' });
const secretOnce = reactive({ visible: false, value: '', name: '' });
const confirmTarget = ref(null);
const toast = reactive({ visible: false, type: 'success', message: '' });

const outcomeLabels = {
    ok: 'Thành công',
    missing_token: 'Thiếu token',
    invalid_token: 'Token sai',
    revoked: 'Token đã thu hồi',
    rate_limited: 'Vượt giới hạn',
    forbidden: 'Không có quyền',
    method_not_allowed: 'Sai phương thức',
    disabled: 'API tắt',
    error: 'Lỗi',
};

function notify(message, type = 'success') {
    toast.message = message;
    toast.type = type;
    toast.visible = true;
    window.setTimeout(() => { toast.visible = false; }, 3500);
}

function errorMessage(error) {
    return error?.response?.data?.error?.message
        || Object.values(error?.response?.data?.errors || {})?.flat()?.[0]
        || 'Không thể hoàn tất thao tác.';
}

async function createToken() {
    busy.value = true;
    try {
        const { data } = await axios.post('/settings/integrations/inventory-api/tokens', form);
        tokens.value.unshift({ ...data.token });
        secretOnce.value = data.plain_text_token;
        secretOnce.name = data.token.name;
        secretOnce.visible = true;
        showCreate.value = false;
        form.name = '';
        form.account_label = '';
    } catch (error) {
        notify(errorMessage(error), 'error');
    } finally {
        busy.value = false;
    }
}

async function revoke() {
    const target = confirmTarget.value;
    confirmTarget.value = null;
    if (!target) return;
    busy.value = true;
    try {
        const { data } = await axios.post(`/settings/integrations/inventory-api/tokens/${target.id}/revoke`);
        const index = tokens.value.findIndex((token) => token.id === data.token.id);
        if (index >= 0) tokens.value[index] = { ...data.token };
        notify('Đã thu hồi token. Mọi request dùng token này sẽ bị từ chối 401.');
    } catch (error) {
        notify(errorMessage(error), 'error');
    } finally {
        busy.value = false;
    }
}

function closeSecret() {
    secretOnce.visible = false;
    secretOnce.value = '';
    secretOnce.name = '';
}

async function copy(value, label) {
    try {
        await navigator.clipboard.writeText(value);
        notify(`Đã sao chép ${label}.`);
    } catch {
        notify(`Không thể sao chép ${label}.`, 'error');
    }
}

function formatDate(value) {
    if (!value) return 'Chưa có';
    return new Intl.DateTimeFormat('vi-VN', { dateStyle: 'short', timeStyle: 'medium' }).format(new Date(value));
}
</script>

<template>
    <Head title="API tồn kho (chỉ đọc)" />
    <AppLayout>
        <div class="mx-auto max-w-7xl space-y-5 p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="mb-2 flex items-center gap-2 text-sm text-slate-500">
                        <Link href="/settings" class="hover:text-blue-600">Cài đặt</Link>
                        <span>/</span><span>Tích hợp</span><span>/</span><span>API tồn kho</span>
                    </div>
                    <h1 class="text-2xl font-bold text-slate-900">API tồn kho (chỉ đọc)</h1>
                    <p class="mt-1 text-sm text-slate-600">Cấp token cho đối tác tự động lấy tồn kho, serial và trạng thái sửa chữa. Token chỉ đọc được 2 endpoint tồn kho, không tạo/sửa/xóa dữ liệu, không xem giá vốn.</p>
                </div>
                <button :disabled="!canManage || busy" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="showCreate = true">Tạo token mới</button>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border bg-white p-4"><div class="text-xs font-semibold uppercase text-slate-500">Token đang hoạt động</div><div class="mt-2 text-lg font-bold text-slate-900">{{ activeCount }}</div></div>
                <div class="rounded-xl border bg-white p-4"><div class="text-xs font-semibold uppercase text-slate-500">Giới hạn</div><div class="mt-2 text-lg font-bold text-slate-900">{{ rate_limit_per_minute }} request/phút/token</div></div>
                <div class="rounded-xl border bg-white p-4"><div class="text-xs font-semibold uppercase text-slate-500">Base URL</div><div class="mt-2 break-all font-mono text-sm font-semibold text-slate-900">{{ base_url }}</div></div>
            </div>

            <section class="rounded-xl border bg-white p-5">
                <h2 class="text-lg font-bold text-slate-900">Danh sách token</h2>
                <div v-if="tokens.length" class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs uppercase text-slate-500">
                            <tr><th class="py-2 pr-4">Tên</th><th class="py-2 pr-4">Tài khoản</th><th class="py-2 pr-4">Mã nhận diện</th><th class="py-2 pr-4">Dùng gần nhất</th><th class="py-2 pr-4">Số request</th><th class="py-2 pr-4">Trạng thái</th><th class="py-2"></th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="token in tokens" :key="token.id">
                                <td class="py-3 pr-4"><div class="font-semibold text-slate-900">{{ token.name }}</div><div class="text-xs text-slate-500">Tạo {{ formatDate(token.created_at) }}<span v-if="token.created_by"> bởi {{ token.created_by }}</span></div></td>
                                <td class="py-3 pr-4">{{ token.account_label || '—' }}</td>
                                <td class="py-3 pr-4 font-mono text-xs">{{ token.token_prefix }}…</td>
                                <td class="py-3 pr-4">{{ formatDate(token.last_used_at) }}<div v-if="token.last_used_ip" class="text-xs text-slate-500">IP {{ token.last_used_ip }}</div></td>
                                <td class="py-3 pr-4">{{ token.request_count }}</td>
                                <td class="py-3 pr-4">
                                    <span v-if="token.revoked_at" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">Đã thu hồi {{ formatDate(token.revoked_at) }}</span>
                                    <span v-else class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Hoạt động</span>
                                </td>
                                <td class="py-3 text-right"><button v-if="!token.revoked_at" :disabled="!canManage || busy" class="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-medium text-red-700 disabled:opacity-50" @click="confirmTarget = token">Thu hồi</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="mt-3 text-sm text-slate-500">Chưa có token nào.</p>
            </section>

            <section class="rounded-xl border bg-white p-5">
                <h2 class="text-lg font-bold text-slate-900">Nhật ký truy cập (50 lượt gần nhất)</h2>
                <div v-if="access_logs.length" class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs uppercase text-slate-500">
                            <tr><th class="py-2 pr-4">Thời gian</th><th class="py-2 pr-4">Token</th><th class="py-2 pr-4">Endpoint</th><th class="py-2 pr-4">Kết quả</th><th class="py-2 pr-4">Số dòng</th><th class="py-2 pr-4">Thời gian xử lý</th><th class="py-2">IP</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="log in access_logs" :key="log.id">
                                <td class="py-2 pr-4 whitespace-nowrap">{{ formatDate(log.created_at) }}</td>
                                <td class="py-2 pr-4">{{ log.token?.name || '—' }}</td>
                                <td class="py-2 pr-4 font-mono text-xs">{{ log.method }} {{ log.path }}</td>
                                <td class="py-2 pr-4"><span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="log.outcome === 'ok' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800'">{{ log.status_code }} · {{ outcomeLabels[log.outcome] || log.outcome }}</span></td>
                                <td class="py-2 pr-4">{{ log.result_count ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ log.duration_ms ?? '—' }} ms</td>
                                <td class="py-2">{{ log.ip_address || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="mt-3 text-sm text-slate-500">Chưa có lượt gọi API nào.</p>
            </section>
        </div>

        <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <form class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl" @submit.prevent="createToken">
                <h2 class="text-xl font-bold">Tạo token API tồn kho</h2>
                <p class="mt-1 text-sm text-slate-600">Token chỉ có quyền đọc tồn kho và serial.</p>
                <div class="mt-5 space-y-4">
                    <label class="block text-sm font-medium">Tên token<input v-model="form.name" required maxlength="120" placeholder="VD: Laptop Plus - Google Sheet" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                    <label class="block text-sm font-medium">Tài khoản / đối tác sử dụng<input v-model="form.account_label" maxlength="120" placeholder="VD: laptopplus.vn - bot tồn kho" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                </div>
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="rounded-lg border px-4 py-2" @click="showCreate = false">Hủy</button><button :disabled="busy" class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white disabled:opacity-50">Tạo token</button></div>
            </form>
        </div>

        <div v-if="secretOnce.visible" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-xl rounded-xl bg-white p-6 shadow-xl">
                <h2 class="text-xl font-bold">Lưu token ngay bây giờ</h2>
                <p class="mt-2 text-sm text-amber-700">Token "{{ secretOnce.name }}" chỉ hiển thị đúng một lần. KIOT chỉ lưu bản băm, không thể xem lại. Nếu làm mất, hãy thu hồi và tạo token mới.</p>
                <div class="mt-4 break-all rounded-lg bg-slate-950 p-4 font-mono text-sm text-emerald-300">{{ secretOnce.value }}</div>
                <p class="mt-2 text-xs text-slate-500">Dùng trong header: <code>Authorization: Bearer &lt;token&gt;</code></p>
                <div class="mt-5 flex justify-end gap-2"><button class="rounded-lg border px-4 py-2" @click="copy(secretOnce.value, 'token')">Sao chép token</button><button class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white" @click="closeSecret">Tôi đã lưu, đóng</button></div>
            </div>
        </div>

        <div v-if="confirmTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h2 class="text-xl font-bold">Thu hồi token</h2>
                <p class="mt-3 text-sm text-slate-600">Token "{{ confirmTarget.name }}" sẽ bị từ chối ngay lập tức. Không thể khôi phục.</p>
                <div class="mt-6 flex justify-end gap-2"><button class="rounded-lg border px-4 py-2" @click="confirmTarget = null">Hủy</button><button class="rounded-lg bg-red-600 px-4 py-2 font-semibold text-white" @click="revoke">Thu hồi</button></div>
            </div>
        </div>

        <div v-if="toast.visible" class="fixed bottom-5 right-5 z-[60] max-w-md rounded-lg px-4 py-3 text-sm font-semibold text-white shadow-lg" :class="toast.type === 'error' ? 'bg-red-600' : 'bg-emerald-600'">{{ toast.message }}</div>
    </AppLayout>
</template>
