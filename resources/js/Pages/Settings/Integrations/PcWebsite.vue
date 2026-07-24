<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    management_enabled: Boolean,
    configuration_source: String,
    environment_import_available: Boolean,
    clients: Array,
    branches: Array,
    history: Array,
    defaults: Object,
});

const page = usePage();
const permissions = computed(() => page.props.auth?.permissions || []);
const can = (permission) => permissions.value.includes('*') || permissions.value.includes(permission);
const canManage = computed(() => props.management_enabled && can('integrations.manage'));
const canRotate = computed(() => props.management_enabled && can('integrations.rotate-secret'));

const clients = ref((props.clients || []).map((client) => ({ ...client })));
const selectedId = ref(clients.value[0]?.id || null);
const selected = computed(() => clients.value.find((client) => client.id === selectedId.value) || null);
const busy = ref(false);
const showCreate = ref(false);
const showSecret = ref(false);
const showPairing = ref(false);
const showConfirm = ref(false);
const confirmAction = ref(null);
const confirmTitle = ref('');
const confirmMessage = ref('');
const secretOnce = reactive({ value: '', fingerprint: '' });
const pairingOnce = reactive({ reference: '', code: '', expires_at: '' });
const toast = reactive({ visible: false, type: 'success', message: '' });

const form = reactive({
    name: '',
    website_url: '',
    default_branch_id: props.branches?.[0]?.id || '',
    sales_channel: props.defaults?.sales_channel || 'Website PC',
    timestamp_tolerance_seconds: props.defaults?.timestamp_tolerance_seconds || 300,
    nonce_ttl_seconds: props.defaults?.nonce_ttl_seconds || 600,
    rate_limit_per_minute: props.defaults?.rate_limit_per_minute || 60,
    reservation_ttl_minutes: props.defaults?.reservation_ttl_minutes || 1440,
});

const sourceLabel = computed(() => ({
    database: 'Database',
    environment: 'Environment fallback',
    none: 'Chưa cấu hình',
})[props.configuration_source] || props.configuration_source);

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

function replaceClient(client) {
    const index = clients.value.findIndex((item) => item.id === client.id);
    if (index >= 0) clients.value[index] = { ...client };
    else clients.value.push({ ...client });
    selectedId.value = client.id;
}

function resetCreateForm() {
    Object.assign(form, {
        name: '',
        website_url: '',
        default_branch_id: props.branches?.[0]?.id || '',
        sales_channel: props.defaults?.sales_channel || 'Website PC',
        timestamp_tolerance_seconds: props.defaults?.timestamp_tolerance_seconds || 300,
        nonce_ttl_seconds: props.defaults?.nonce_ttl_seconds || 600,
        rate_limit_per_minute: props.defaults?.rate_limit_per_minute || 60,
        reservation_ttl_minutes: props.defaults?.reservation_ttl_minutes || 1440,
    });
}

async function createClient() {
    busy.value = true;
    try {
        const { data } = await axios.post('/settings/integrations/website-pc/clients', form);
        replaceClient(data.client);
        showCreate.value = false;
        secretOnce.value = data.secret;
        secretOnce.fingerprint = data.client.secret_fingerprint;
        showSecret.value = true;
        resetCreateForm();
        notify('Đã tạo kết nối. Secret chỉ hiển thị trong lần này.');
    } catch (error) {
        notify(errorMessage(error), 'error');
    } finally {
        busy.value = false;
    }
}

async function saveClient() {
    if (!selected.value) return;
    busy.value = true;
    try {
        const payload = {
            name: selected.value.name,
            website_url: selected.value.website_url,
            default_branch_id: selected.value.default_branch_id,
            sales_channel: selected.value.sales_channel,
            timestamp_tolerance_seconds: selected.value.timestamp_tolerance_seconds,
            nonce_ttl_seconds: selected.value.nonce_ttl_seconds,
            rate_limit_per_minute: selected.value.rate_limit_per_minute,
            reservation_ttl_minutes: selected.value.reservation_ttl_minutes,
        };
        const { data } = await axios.patch(`/settings/integrations/website-pc/clients/${selected.value.id}`, payload);
        replaceClient(data.client);
        notify('Đã lưu cấu hình kết nối. Không cần restart dịch vụ.');
    } catch (error) {
        notify(errorMessage(error), 'error');
    } finally {
        busy.value = false;
    }
}

async function postAction(action) {
    if (!selected.value) return;
    busy.value = true;
    try {
        const { data } = await axios.post(`/settings/integrations/website-pc/clients/${selected.value.id}/${action}`);
        if (data.client) replaceClient(data.client);
        if (data.secret) {
            secretOnce.value = data.secret;
            secretOnce.fingerprint = data.client.secret_fingerprint;
            showSecret.value = true;
        }
        notify(data.message || 'Thao tác đã hoàn tất.');
    } catch (error) {
        notify(errorMessage(error), 'error');
    } finally {
        busy.value = false;
    }
}

async function createPairing() {
    if (!selected.value) return;
    busy.value = true;
    try {
        const { data } = await axios.post(`/settings/integrations/website-pc/clients/${selected.value.id}/pairing-token`);
        pairingOnce.reference = data.reference;
        pairingOnce.code = data.pairing_code;
        pairingOnce.expires_at = data.expires_at;
        showPairing.value = true;
        notify('Mã ghép nối đã được tạo và có hiệu lực tối đa 10 phút.');
    } catch (error) {
        notify(errorMessage(error), 'error');
    } finally {
        busy.value = false;
    }
}

async function importEnvironment() {
    busy.value = true;
    try {
        const { data } = await axios.post('/settings/integrations/website-pc/import-environment');
        replaceClient(data.client);
        notify('Đã import cấu hình hiện tại từ môi trường. Secret không được hiển thị.');
    } catch (error) {
        notify(errorMessage(error), 'error');
    } finally {
        busy.value = false;
    }
}

function ask(title, message, action) {
    confirmTitle.value = title;
    confirmMessage.value = message;
    confirmAction.value = action;
    showConfirm.value = true;
}

async function confirm() {
    showConfirm.value = false;
    const action = confirmAction.value;
    confirmAction.value = null;
    if (action) await action();
}

function closeSecret() {
    showSecret.value = false;
    secretOnce.value = '';
    secretOnce.fingerprint = '';
}

function closePairing() {
    showPairing.value = false;
    pairingOnce.reference = '';
    pairingOnce.code = '';
    pairingOnce.expires_at = '';
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
    <Head title="Tích hợp Website PC" />
    <AppLayout>
        <div class="mx-auto max-w-7xl space-y-5 p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="mb-2 flex items-center gap-2 text-sm text-slate-500">
                        <Link href="/settings" class="hover:text-blue-600">Cài đặt</Link>
                        <span>/</span><span>Tích hợp</span><span>/</span><span>Website PC</span>
                    </div>
                    <h1 class="text-2xl font-bold text-slate-900">Kết nối Website PC</h1>
                    <p class="mt-1 text-sm text-slate-600">Quản lý credential, pairing và trạng thái kết nối mà không cần sửa .env hoặc restart dịch vụ.</p>
                </div>
                <div class="flex gap-2">
                    <button v-if="environment_import_available" :disabled="!canManage || busy" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium disabled:opacity-50" @click="importEnvironment">Import cấu hình từ .env</button>
                    <button :disabled="!canManage || busy" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="showCreate = true">Tạo kết nối mới</button>
                </div>
            </div>

            <div v-if="!management_enabled" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                Management UI đang ở chế độ chỉ đọc. Bật <code>PC_INTEGRATION_MANAGEMENT_UI_ENABLED</code> tại checkpoint triển khai được phê duyệt để cho phép thay đổi.
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border bg-white p-4"><div class="text-xs font-semibold uppercase text-slate-500">Nguồn cấu hình</div><div class="mt-2 text-lg font-bold text-slate-900">{{ sourceLabel }}</div></div>
                <div class="rounded-xl border bg-white p-4"><div class="text-xs font-semibold uppercase text-slate-500">Kết nối</div><div class="mt-2 text-lg font-bold text-slate-900">{{ clients.length }}</div></div>
                <div class="rounded-xl border bg-white p-4"><div class="text-xs font-semibold uppercase text-slate-500">Trạng thái</div><div class="mt-2 text-lg font-bold" :class="selected?.is_enabled ? 'text-emerald-600' : 'text-slate-500'">{{ selected?.is_enabled ? 'Connected / Enabled' : 'Disabled' }}</div></div>
            </div>

            <div v-if="clients.length" class="grid gap-5 lg:grid-cols-[280px_1fr]">
                <aside class="rounded-xl border bg-white p-3">
                    <button v-for="client in clients" :key="client.id" class="mb-2 w-full rounded-lg border p-3 text-left" :class="selectedId === client.id ? 'border-blue-500 bg-blue-50' : 'border-transparent hover:bg-slate-50'" @click="selectedId = client.id">
                        <div class="font-semibold text-slate-900">{{ client.name }}</div>
                        <div class="mt-1 truncate text-xs text-slate-500">{{ client.website_url || 'Chưa có URL' }}</div>
                        <span class="mt-2 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="client.is_enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'">{{ client.is_enabled ? 'Connected' : 'Disabled' }}</span>
                    </button>
                </aside>

                <main v-if="selected" class="space-y-5">
                    <section class="rounded-xl border bg-white p-5">
                        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                            <div><h2 class="text-lg font-bold text-slate-900">Thông tin kết nối</h2><p class="text-sm text-slate-500">API {{ selected.api_version }} · Client {{ selected.client_id }}</p></div>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="selected.secret_status === 'revoked' ? 'bg-red-100 text-red-700' : selected.secret_status === 'rotation_grace' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'">Secret: {{ selected.secret_status }}</span>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="text-sm font-medium text-slate-700">Tên kết nối<input v-model="selected.name" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                            <label class="text-sm font-medium text-slate-700">Website URL<input v-model="selected.website_url" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                            <label class="text-sm font-medium text-slate-700">Chi nhánh mặc định<select v-model="selected.default_branch_id" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300"><option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select></label>
                            <label class="text-sm font-medium text-slate-700">Kênh bán hàng<input v-model="selected.sales_channel" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                            <label class="text-sm font-medium text-slate-700">Timestamp tolerance (giây)<input v-model.number="selected.timestamp_tolerance_seconds" type="number" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                            <label class="text-sm font-medium text-slate-700">Nonce TTL (giây)<input v-model.number="selected.nonce_ttl_seconds" type="number" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                            <label class="text-sm font-medium text-slate-700">Rate limit / phút<input v-model.number="selected.rate_limit_per_minute" type="number" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                            <label class="text-sm font-medium text-slate-700">Reservation TTL (phút)<input v-model.number="selected.reservation_ttl_minutes" type="number" :disabled="!canManage" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                        </div>
                        <div class="mt-5 grid gap-3 text-sm md:grid-cols-2 xl:grid-cols-3">
                            <div class="rounded-lg bg-slate-50 p-3"><div class="text-slate-500">Secret fingerprint</div><div class="mt-1 font-mono font-semibold">SHA256: {{ selected.secret_fingerprint || '—' }}</div></div>
                            <div class="rounded-lg bg-slate-50 p-3"><div class="text-slate-500">Secret được tạo</div><div class="mt-1 font-semibold">{{ formatDate(selected.secret_created_at) }}</div></div>
                            <div class="rounded-lg bg-slate-50 p-3"><div class="text-slate-500">Xoay secret gần nhất</div><div class="mt-1 font-semibold">{{ formatDate(selected.secret_rotated_at) }}</div><div v-if="selected.previous_secret_expires_at" class="mt-1 text-xs text-amber-700">Grace đến {{ formatDate(selected.previous_secret_expires_at) }}</div></div>
                            <div class="rounded-lg bg-slate-50 p-3"><div class="text-slate-500">Handshake gần nhất</div><div class="mt-1 font-semibold">{{ formatDate(selected.last_connected_at) }}</div></div>
                            <div class="rounded-lg bg-slate-50 p-3"><div class="text-slate-500">Request gần nhất</div><div class="mt-1 font-semibold">{{ formatDate(selected.last_request_at) }}</div></div>
                            <div class="rounded-lg bg-slate-50 p-3"><div class="text-slate-500">IP gần nhất</div><div class="mt-1 font-semibold">{{ selected.last_request_ip || 'Chưa có' }}</div></div>
                        </div>
                        <div class="mt-5 flex flex-wrap gap-2">
                            <button :disabled="!canManage || busy" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="saveClient">Lưu</button>
                            <button :disabled="!canManage || busy || selected.secret_status === 'revoked'" class="rounded-lg border px-4 py-2 text-sm font-medium disabled:opacity-50" @click="createPairing">Tạo mã ghép nối</button>
                            <button :disabled="!canManage || busy || selected.secret_status === 'revoked'" class="rounded-lg border px-4 py-2 text-sm font-medium disabled:opacity-50" @click="postAction('test')">Test Connection</button>
                            <button v-if="!selected.is_enabled" :disabled="!canManage || busy || selected.secret_status === 'revoked'" class="rounded-lg border border-emerald-300 px-4 py-2 text-sm font-medium text-emerald-700 disabled:opacity-50" @click="ask('Bật kết nối', 'Website PC sẽ có thể gọi API sau khi bật. Tiếp tục?', () => postAction('enable'))">Bật kết nối</button>
                            <button v-else :disabled="!canManage || busy" class="rounded-lg border border-amber-300 px-4 py-2 text-sm font-medium text-amber-700 disabled:opacity-50" @click="ask('Tắt kết nối', 'Mọi request Website PC sẽ bị từ chối 503 ngay lập tức.', () => postAction('disable'))">Tắt kết nối</button>
                            <button :disabled="!canRotate || busy || selected.secret_status === 'revoked'" class="rounded-lg border px-4 py-2 text-sm font-medium disabled:opacity-50" @click="ask('Rotate Secret', 'Secret cũ chỉ còn hiệu lực tối đa 15 phút. Secret mới chỉ hiển thị một lần.', () => postAction('rotate-secret'))">Rotate Secret</button>
                            <button :disabled="!canManage || busy || selected.secret_status === 'revoked'" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-700 disabled:opacity-50" @click="ask('Thu hồi kết nối', 'Thao tác này vô hiệu hóa và xóa cả current/previous secret. Không thể khôi phục plaintext.', () => postAction('revoke'))">Thu hồi kết nối</button>
                            <a href="#integration-history" class="rounded-lg border px-4 py-2 text-sm font-medium">Xem lịch sử</a>
                        </div>
                    </section>

                    <section id="integration-history" class="rounded-xl border bg-white p-5">
                        <h2 class="text-lg font-bold text-slate-900">Lịch sử tích hợp</h2>
                        <div v-if="history.length" class="mt-4 divide-y">
                            <div v-for="item in history" :key="item.id" class="py-3">
                                <div class="flex justify-between gap-4"><span class="font-semibold text-slate-800">{{ item.label }}</span><span class="text-xs text-slate-500">{{ formatDate(item.created_at) }}</span></div>
                                <p class="mt-1 text-sm text-slate-600">{{ item.description }}</p>
                            </div>
                        </div>
                        <p v-else class="mt-3 text-sm text-slate-500">Chưa có hoạt động.</p>
                    </section>
                </main>
            </div>

            <div v-else class="rounded-xl border border-dashed bg-white p-10 text-center"><h2 class="text-lg font-bold">Chưa có kết nối Website PC</h2><p class="mt-2 text-sm text-slate-500">Tạo kết nối mới hoặc import cấu hình bootstrap hiện tại từ môi trường.</p></div>
        </div>

        <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <form class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl" @submit.prevent="createClient">
                <h2 class="text-xl font-bold">Tạo kết nối Website PC</h2>
                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    <label class="text-sm font-medium">Tên kết nối<input v-model="form.name" required class="mt-1 w-full rounded-lg border-slate-300" /></label>
                    <label class="text-sm font-medium">Website URL<input v-model="form.website_url" required placeholder="https://admin.example.vn" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                    <label class="text-sm font-medium">Chi nhánh mặc định<select v-model="form.default_branch_id" required class="mt-1 w-full rounded-lg border-slate-300"><option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select></label>
                    <label class="text-sm font-medium">Kênh bán hàng<input v-model="form.sales_channel" required class="mt-1 w-full rounded-lg border-slate-300" /></label>
                    <label class="text-sm font-medium">Timestamp tolerance<input v-model.number="form.timestamp_tolerance_seconds" type="number" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                    <label class="text-sm font-medium">Nonce TTL<input v-model.number="form.nonce_ttl_seconds" type="number" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                    <label class="text-sm font-medium">Rate limit / phút<input v-model.number="form.rate_limit_per_minute" type="number" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                    <label class="text-sm font-medium">Reservation TTL (phút)<input v-model.number="form.reservation_ttl_minutes" type="number" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                </div>
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="rounded-lg border px-4 py-2" @click="showCreate = false">Hủy</button><button :disabled="busy" class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white disabled:opacity-50">Tạo kết nối</button></div>
            </form>
        </div>

        <div v-if="showSecret" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-xl rounded-xl bg-white p-6 shadow-xl"><h2 class="text-xl font-bold">Lưu secret ngay bây giờ</h2><p class="mt-2 text-sm text-amber-700">Secret chỉ hiển thị đúng một lần. Sau khi đóng, KIOT chỉ hiển thị fingerprint.</p><div class="mt-4 break-all rounded-lg bg-slate-950 p-4 font-mono text-sm text-emerald-300">{{ secretOnce.value }}</div><div class="mt-2 text-xs text-slate-500">SHA256 prefix: {{ secretOnce.fingerprint }}</div><div class="mt-5 flex justify-end gap-2"><button class="rounded-lg border px-4 py-2" @click="copy(secretOnce.value, 'secret')">Sao chép secret</button><button class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white" @click="closeSecret">Tôi đã lưu, đóng</button></div></div>
        </div>

        <div v-if="showPairing" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-xl rounded-xl bg-white p-6 shadow-xl"><h2 class="text-xl font-bold">Mã ghép nối một lần</h2><p class="mt-2 text-sm text-slate-600">Hết hạn: {{ formatDate(pairingOnce.expires_at) }}</p><div class="mt-4 space-y-3"><div><div class="text-xs font-semibold uppercase text-slate-500">Connection reference</div><div class="mt-1 break-all rounded-lg bg-slate-100 p-3 font-mono text-sm">{{ pairingOnce.reference }}</div></div><div><div class="text-xs font-semibold uppercase text-slate-500">Pairing code</div><div class="mt-1 break-all rounded-lg bg-slate-950 p-3 font-mono text-sm text-emerald-300">{{ pairingOnce.code }}</div></div></div><div class="mt-5 flex flex-wrap justify-end gap-2"><button class="rounded-lg border px-4 py-2" @click="copy(pairingOnce.reference, 'reference')">Sao chép reference</button><button class="rounded-lg border px-4 py-2" @click="copy(pairingOnce.code, 'mã ghép nối')">Sao chép mã ghép nối</button><button class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white" @click="closePairing">Đóng</button></div></div>
        </div>

        <div v-if="showConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"><div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl"><h2 class="text-xl font-bold">{{ confirmTitle }}</h2><p class="mt-3 text-sm text-slate-600">{{ confirmMessage }}</p><div class="mt-6 flex justify-end gap-2"><button class="rounded-lg border px-4 py-2" @click="showConfirm = false">Hủy</button><button class="rounded-lg bg-red-600 px-4 py-2 font-semibold text-white" @click="confirm">Xác nhận</button></div></div></div>

        <div v-if="toast.visible" class="fixed bottom-5 right-5 z-[60] max-w-md rounded-lg px-4 py-3 text-sm font-semibold text-white shadow-lg" :class="toast.type === 'error' ? 'bg-red-600' : 'bg-emerald-600'">{{ toast.message }}</div>
    </AppLayout>
</template>
