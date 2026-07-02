<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import SetupSidebar from '@/Pages/Employees/Partials/SetupSidebar.vue';

const props = defineProps({
    timekeeping: {
        type: Object,
        default: () => ({
            standard_hours_per_day: 8,
            late_grace_minutes: 10,
            early_grace_minutes: 0,
            allow_multiple_shifts_one_inout: false,
            ot_after_minutes: 1,
        }),
    },
    preferences: {
        type: Object,
        default: () => ({
            half_work_enabled: true,
            half_work_max_minutes: 480,
            half_work_min_minutes: 0,
            late_enabled: true,
            early_enabled: false,
            overtime_before_enabled: true,
            overtime_after_enabled: true,
            overtime_before_minutes: 1,
            auto_attendance: false,
            mobile_attendance: true,
            mobile_gps_required: true,
            mobile_qr_enabled: true,
            device_attendance: true,
        }),
    },
});

const saving = ref(false);
const activeModal = ref('');
const toast = reactive({ show: false, type: 'success', message: '' });

const timekeeping = reactive({
    standard_hours_per_day: Number(props.timekeeping?.standard_hours_per_day ?? 8),
    late_grace_minutes: Number(props.timekeeping?.late_grace_minutes ?? 10),
    early_grace_minutes: Number(props.timekeeping?.early_grace_minutes ?? 0),
    allow_multiple_shifts_one_inout: Boolean(props.timekeeping?.allow_multiple_shifts_one_inout ?? false),
    ot_after_minutes: Number(props.timekeeping?.ot_after_minutes ?? 1),
});

const preferences = reactive({
    half_work_enabled: Boolean(props.preferences?.half_work_enabled ?? true),
    half_work_max_minutes: Number(props.preferences?.half_work_max_minutes ?? 480),
    half_work_min_minutes: Number(props.preferences?.half_work_min_minutes ?? 0),
    late_enabled: Boolean(props.preferences?.late_enabled ?? true),
    early_enabled: Boolean(props.preferences?.early_enabled ?? false),
    overtime_before_enabled: Boolean(props.preferences?.overtime_before_enabled ?? true),
    overtime_after_enabled: Boolean(props.preferences?.overtime_after_enabled ?? true),
    overtime_before_minutes: Number(props.preferences?.overtime_before_minutes ?? 1),
    auto_attendance: Boolean(props.preferences?.auto_attendance ?? false),
    mobile_attendance: Boolean(props.preferences?.mobile_attendance ?? true),
    mobile_gps_required: Boolean(props.preferences?.mobile_gps_required ?? true),
    mobile_qr_enabled: Boolean(props.preferences?.mobile_qr_enabled ?? true),
    device_attendance: Boolean(props.preferences?.device_attendance ?? true),
});

const standardForm = reactive({
    hours: Math.floor(Number(props.timekeeping?.standard_hours_per_day ?? 8)),
    minutes: Math.round((Number(props.timekeeping?.standard_hours_per_day ?? 8) % 1) * 60),
    halfEnabled: Boolean(props.preferences?.half_work_enabled ?? true),
    halfMaxHours: Math.floor(Number(props.preferences?.half_work_max_minutes ?? 480) / 60),
    halfMaxMinutes: Number(props.preferences?.half_work_max_minutes ?? 480) % 60,
    halfMinHours: Math.floor(Number(props.preferences?.half_work_min_minutes ?? 0) / 60),
    halfMinMinutes: Number(props.preferences?.half_work_min_minutes ?? 0) % 60,
});

const lateEarlyForm = reactive({
    lateEnabled: Boolean(props.preferences?.late_enabled ?? true),
    lateMinutes: Number(props.timekeeping?.late_grace_minutes ?? 10),
    earlyEnabled: Boolean(props.preferences?.early_enabled ?? false),
    earlyMinutes: Number(props.timekeeping?.early_grace_minutes ?? 0),
});

const overtimeForm = reactive({
    beforeEnabled: Boolean(props.preferences?.overtime_before_enabled ?? true),
    beforeMinutes: Number(props.preferences?.overtime_before_minutes ?? 1),
    afterEnabled: Boolean(props.preferences?.overtime_after_enabled ?? true),
    afterMinutes: Number(props.timekeeping?.ot_after_minutes ?? 1),
});

const mobileForm = reactive({
    gpsRequired: Boolean(props.preferences?.mobile_gps_required ?? true),
    qrEnabled: Boolean(props.preferences?.mobile_qr_enabled ?? true),
});

const setToast = (message, type = 'success') => {
    toast.show = true;
    toast.type = type;
    toast.message = message;
    window.clearTimeout(setToast.timeoutId);
    setToast.timeoutId = window.setTimeout(() => {
        toast.show = false;
    }, 2500);
};

const saveSettings = async () => {
    saving.value = true;

    try {
        await axios.post('/employees/attendance/settings/preferences', {
            standard_hours_per_day: Number(timekeeping.standard_hours_per_day || 0),
            late_grace_minutes: Number(timekeeping.late_grace_minutes || 0),
            early_grace_minutes: Number(timekeeping.early_grace_minutes || 0),
            allow_multiple_shifts_one_inout: Boolean(timekeeping.allow_multiple_shifts_one_inout),
            ot_after_minutes: Number(timekeeping.ot_after_minutes || 0),
            preferences: {
                half_work_enabled: Boolean(preferences.half_work_enabled),
                half_work_max_minutes: Number(preferences.half_work_max_minutes || 0),
                half_work_min_minutes: Number(preferences.half_work_min_minutes || 0),
                late_enabled: Boolean(preferences.late_enabled),
                early_enabled: Boolean(preferences.early_enabled),
                overtime_before_enabled: Boolean(preferences.overtime_before_enabled),
                overtime_after_enabled: Boolean(preferences.overtime_after_enabled),
                overtime_before_minutes: Number(preferences.overtime_before_minutes || 0),
                auto_attendance: Boolean(preferences.auto_attendance),
                mobile_attendance: Boolean(preferences.mobile_attendance),
                mobile_gps_required: Boolean(preferences.mobile_gps_required),
                mobile_qr_enabled: Boolean(preferences.mobile_qr_enabled),
                device_attendance: Boolean(preferences.device_attendance),
            },
        });

        setToast('Đã lưu thiết lập chấm công.');
    } catch (error) {
        setToast(error?.response?.data?.message || 'Không thể lưu thiết lập.', 'error');
        throw error;
    } finally {
        saving.value = false;
    }
};

const openStandardModal = () => {
    standardForm.hours = Math.floor(Number(timekeeping.standard_hours_per_day || 0));
    standardForm.minutes = Math.round((Number(timekeeping.standard_hours_per_day || 0) % 1) * 60);
    standardForm.halfEnabled = preferences.half_work_enabled;
    standardForm.halfMaxHours = Math.floor(Number(preferences.half_work_max_minutes || 0) / 60);
    standardForm.halfMaxMinutes = Number(preferences.half_work_max_minutes || 0) % 60;
    standardForm.halfMinHours = Math.floor(Number(preferences.half_work_min_minutes || 0) / 60);
    standardForm.halfMinMinutes = Number(preferences.half_work_min_minutes || 0) % 60;
    activeModal.value = 'standard';
};

const saveStandardModal = async () => {
    timekeeping.standard_hours_per_day = Number(standardForm.hours || 0) + (Number(standardForm.minutes || 0) / 60);
    preferences.half_work_enabled = Boolean(standardForm.halfEnabled);
    preferences.half_work_max_minutes = (Number(standardForm.halfMaxHours || 0) * 60) + Number(standardForm.halfMaxMinutes || 0);
    preferences.half_work_min_minutes = (Number(standardForm.halfMinHours || 0) * 60) + Number(standardForm.halfMinMinutes || 0);
    await saveSettings();
    activeModal.value = '';
};

const openLateEarlyModal = () => {
    lateEarlyForm.lateEnabled = preferences.late_enabled;
    lateEarlyForm.lateMinutes = Number(timekeeping.late_grace_minutes || 0);
    lateEarlyForm.earlyEnabled = preferences.early_enabled;
    lateEarlyForm.earlyMinutes = Number(timekeeping.early_grace_minutes || 0);
    activeModal.value = 'lateEarly';
};

const saveLateEarlyModal = async () => {
    preferences.late_enabled = Boolean(lateEarlyForm.lateEnabled);
    preferences.early_enabled = Boolean(lateEarlyForm.earlyEnabled);
    timekeeping.late_grace_minutes = Number(lateEarlyForm.lateMinutes || 0);
    timekeeping.early_grace_minutes = Number(lateEarlyForm.earlyMinutes || 0);
    await saveSettings();
    activeModal.value = '';
};

const openOvertimeModal = () => {
    overtimeForm.beforeEnabled = preferences.overtime_before_enabled;
    overtimeForm.beforeMinutes = Number(preferences.overtime_before_minutes || 0);
    overtimeForm.afterEnabled = preferences.overtime_after_enabled;
    overtimeForm.afterMinutes = Number(timekeeping.ot_after_minutes || 0);
    activeModal.value = 'overtime';
};

const saveOvertimeModal = async () => {
    preferences.overtime_before_enabled = Boolean(overtimeForm.beforeEnabled);
    preferences.overtime_after_enabled = Boolean(overtimeForm.afterEnabled);
    preferences.overtime_before_minutes = Number(overtimeForm.beforeMinutes || 0);
    timekeeping.ot_after_minutes = Number(overtimeForm.afterMinutes || 0);
    await saveSettings();
    activeModal.value = '';
};

const openMobileModal = () => {
    mobileForm.gpsRequired = preferences.mobile_gps_required;
    mobileForm.qrEnabled = preferences.mobile_qr_enabled;
    activeModal.value = 'mobile';
};

const saveMobileModal = async () => {
    preferences.mobile_gps_required = Boolean(mobileForm.gpsRequired);
    preferences.mobile_qr_enabled = Boolean(mobileForm.qrEnabled);
    await saveSettings();
    activeModal.value = '';
};

const toggleSetting = async (key) => {
    if (key === 'multiple') {
        timekeeping.allow_multiple_shifts_one_inout = !timekeeping.allow_multiple_shifts_one_inout;
    }

    if (key === 'auto') {
        preferences.auto_attendance = !preferences.auto_attendance;
    }

    if (key === 'mobile') {
        preferences.mobile_attendance = !preferences.mobile_attendance;
    }

    if (key === 'device') {
        preferences.device_attendance = !preferences.device_attendance;
    }

    await saveSettings();
};

const setupRows = computed(() => [
    {
        key: 'shift',
        title: 'Thiết lập ca làm việc',
        description: 'Quản lý các ca làm việc của cửa hàng',
        href: '/employees/attendance/settings/shifts',
    },
    {
        key: 'standard',
        title: 'Số giờ của ngày công chuẩn',
        description: 'Thiết lập số giờ tính 1 công hay 0,5 công của loại lương Theo ngày công chuẩn',
        action: openStandardModal,
    },
    {
        key: 'late-early',
        title: 'Cài đặt đi muộn - về sớm',
        description: 'Cài đặt thời gian tối đa được đi muộn hoặc về sớm',
        action: openLateEarlyModal,
    },
    {
        key: 'overtime',
        title: 'Cài đặt làm thêm giờ',
        description: 'Tính làm thêm giờ cho nhân viên khi vào ca sớm hoặc tan ca muộn',
        action: openOvertimeModal,
    },
]);
</script>

<template>
    <Head title="Thiết lập chấm công - KiotViet Clone" />

    <AppLayout>
        <template #sidebar>
            <SetupSidebar active-main="attendance" />
        </template>

        <div class="space-y-4">
            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h1 class="text-[24px] font-semibold text-slate-900">Thiết lập chấm công</h1>
                </div>

                <div class="divide-y divide-slate-200">
                    <div v-for="row in setupRows" :key="row.key" class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="flex min-w-0 items-start gap-4">
                            <div class="mt-1 flex h-8 w-8 items-center justify-center rounded text-blue-600">
                                <span class="text-lg">◫</span>
                            </div>
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-900">{{ row.title }}</div>
                                <div class="text-sm text-slate-500">{{ row.description }}</div>
                            </div>
                        </div>

                        <Link v-if="row.href" :href="row.href" class="shrink-0 rounded-md bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                            Chi tiết
                        </Link>
                        <button v-else type="button" class="shrink-0 rounded-md bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700" @click="row.action()">
                            Chi tiết
                        </button>
                    </div>

                    <div class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="flex min-w-0 items-start gap-4">
                            <div class="mt-1 flex h-8 w-8 items-center justify-center rounded text-blue-600">
                                <span class="text-lg">◫</span>
                            </div>
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-900">Cho phép chấm 1 lượt Vào - Ra khi làm nhiều ca liên tục</div>
                                <div class="text-sm text-slate-500">Ví dụ: Ca 1 (7:00 - 12:00), Ca 2 (13:00 - 18:00). Bạn chỉ cần chấm công Vào ca 1, chấm công Ra ca 2 (bằng mã QR hoặc chấm vân tay), hệ thống sẽ tự động ghi nhận Ra ca 1 lúc 12:00, Vào ca 2 lúc 13:00</div>
                            </div>
                        </div>

                        <button type="button" class="shrink-0" @click="toggleSetting('multiple')">
                            <div class="relative inline-flex h-6 w-11 items-center rounded-full transition" :class="timekeeping.allow_multiple_shifts_one_inout ? 'bg-blue-600' : 'bg-slate-300'">
                                <span class="inline-block h-4 w-4 transform rounded-full bg-white transition" :class="timekeeping.allow_multiple_shifts_one_inout ? 'translate-x-6' : 'translate-x-1'" />
                            </div>
                        </button>
                    </div>
                </div>
            </section>

            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-[24px] font-semibold text-slate-900">Hình thức chấm công</h2>
                </div>

                <div class="divide-y divide-slate-200">
                    <div class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="flex min-w-0 items-start gap-4">
                            <div class="mt-1 flex h-8 w-8 items-center justify-center rounded text-blue-600">
                                <span class="text-lg">◫</span>
                            </div>
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-900">Tự động chấm công</div>
                                <div class="text-sm text-slate-500">Nhân viên không phải chủ động chấm công. Hệ thống sẽ tự động chấm công thay nhân viên</div>
                            </div>
                        </div>

                        <button type="button" class="shrink-0" @click="toggleSetting('auto')">
                            <div class="relative inline-flex h-6 w-11 items-center rounded-full transition" :class="preferences.auto_attendance ? 'bg-blue-600' : 'bg-slate-300'">
                                <span class="inline-block h-4 w-4 transform rounded-full bg-white transition" :class="preferences.auto_attendance ? 'translate-x-6' : 'translate-x-1'" />
                            </div>
                        </button>
                    </div>

                    <div class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="flex min-w-0 items-start gap-4">
                            <div class="mt-1 flex h-8 w-8 items-center justify-center rounded text-blue-600">
                                <span class="text-lg">◫</span>
                            </div>
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-900">Chấm công trên điện thoại di động</div>
                                <div class="text-sm text-slate-500">Sử dụng định vị GPS và mã QR để chấm công nhanh</div>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-5">
                            <button type="button" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700" @click="openMobileModal">
                                Chi tiết
                            </button>
                            <button type="button" @click="toggleSetting('mobile')">
                                <div class="relative inline-flex h-6 w-11 items-center rounded-full transition" :class="preferences.mobile_attendance ? 'bg-blue-600' : 'bg-slate-300'">
                                    <span class="inline-block h-4 w-4 transform rounded-full bg-white transition" :class="preferences.mobile_attendance ? 'translate-x-6' : 'translate-x-1'" />
                                </div>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="flex min-w-0 items-start gap-4">
                            <div class="mt-1 flex h-8 w-8 items-center justify-center rounded text-blue-600">
                                <span class="text-lg">◫</span>
                            </div>
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-900">Chấm công bằng máy chấm công</div>
                                <div class="text-sm text-slate-500">KiotViet chấm công vân tay</div>
                            </div>
                        </div>

                        <Link href="/employees/attendance/settings/devices" class="shrink-0 rounded-md bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                            Chi tiết
                        </Link>
                    </div>
                </div>
            </section>
        </div>

        <div v-if="activeModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/35 px-4 py-6">
            <div v-if="activeModal === 'standard'" class="w-full max-w-[476px] rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between px-5 py-4">
                    <div class="text-[18px] font-semibold text-slate-900">Số giờ của ngày công chuẩn</div>
                    <button type="button" class="text-2xl leading-none text-slate-500 hover:text-slate-700" @click="activeModal = ''">×</button>
                </div>

                <div class="px-5 pb-5 text-sm text-slate-700">
                    <div class="mb-3 font-medium">Ngày công chuẩn ⓘ</div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span>Số giờ của 1 ngày công chuẩn là</span>
                        <input v-model.number="standardForm.hours" type="number" min="0" class="w-14 rounded-lg border border-slate-300 px-3 py-2 text-center">
                        <span class="text-slate-500">giờ</span>
                        <input v-model.number="standardForm.minutes" type="number" min="0" max="59" class="w-14 rounded-lg border border-slate-300 px-3 py-2 text-center">
                        <span class="text-slate-500">phút</span>
                    </div>

                    <div class="my-4 border-t border-slate-200"></div>

                    <label class="mb-3 flex items-center gap-2 text-[15px] text-slate-700">
                        <input v-model="standardForm.halfEnabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span>Tính nửa công nếu nhân viên làm dưới ⓘ</span>
                    </label>

                    <div class="mb-4 flex flex-wrap items-center gap-2">
                        <span class="min-w-[62px]">Làm tối đa</span>
                        <input v-model.number="standardForm.halfMaxHours" type="number" min="0" class="w-14 rounded-lg border border-slate-300 px-3 py-2 text-center">
                        <span class="text-slate-500">giờ</span>
                        <input v-model.number="standardForm.halfMaxMinutes" type="number" min="0" max="59" class="w-14 rounded-lg border border-slate-300 px-3 py-2 text-center">
                        <span class="text-slate-500">phút</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="min-w-[62px]">Làm tối thiểu</span>
                        <input v-model.number="standardForm.halfMinHours" type="number" min="0" class="w-14 rounded-lg border border-slate-300 px-3 py-2 text-center">
                        <span class="text-slate-500">giờ</span>
                        <input v-model.number="standardForm.halfMinMinutes" type="number" min="0" max="59" class="w-14 rounded-lg border border-slate-300 px-3 py-2 text-center">
                        <span class="text-slate-500">phút</span>
                    </div>
                </div>

                <div class="flex justify-end gap-3 px-5 pb-5">
                    <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="activeModal = ''">Bỏ qua</button>
                    <button type="button" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60" :disabled="saving" @click="saveStandardModal">Lưu</button>
                </div>
            </div>

            <div v-else-if="activeModal === 'lateEarly'" class="w-full max-w-[374px] rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between px-5 py-4">
                    <div class="text-[18px] font-semibold text-slate-900">Cài đặt đi muộn - về sớm</div>
                    <button type="button" class="text-2xl leading-none text-slate-500 hover:text-slate-700" @click="activeModal = ''">×</button>
                </div>

                <div class="space-y-4 px-5 pb-5 text-[15px] text-slate-700">
                    <label class="flex items-center gap-2">
                        <input v-model="lateEarlyForm.lateEnabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span>Tính đi muộn sau</span>
                        <input v-model.number="lateEarlyForm.lateMinutes" type="number" min="0" class="w-12 rounded-lg border border-slate-300 px-2 py-1.5 text-center">
                        <span>phút ⓘ</span>
                    </label>

                    <label class="flex items-center gap-2">
                        <input v-model="lateEarlyForm.earlyEnabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span>Tính về sớm trước</span>
                        <input v-model.number="lateEarlyForm.earlyMinutes" type="number" min="0" class="w-12 rounded-lg border border-slate-300 px-2 py-1.5 text-center">
                        <span>phút ⓘ</span>
                    </label>
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-4">
                    <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="activeModal = ''">Bỏ qua</button>
                    <button type="button" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60" :disabled="saving" @click="saveLateEarlyModal">Lưu</button>
                </div>
            </div>

            <div v-else-if="activeModal === 'overtime'" class="w-full max-w-[376px] rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between px-5 py-4">
                    <div class="text-[18px] font-semibold text-slate-900">Cài đặt làm thêm giờ</div>
                    <button type="button" class="text-2xl leading-none text-slate-500 hover:text-slate-700" @click="activeModal = ''">×</button>
                </div>

                <div class="space-y-4 px-5 pb-5 text-[15px] text-slate-700">
                    <label class="flex items-center gap-2">
                        <input v-model="overtimeForm.beforeEnabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span>Tính làm thêm giờ trước ca</span>
                        <input v-model.number="overtimeForm.beforeMinutes" type="number" min="0" class="w-12 rounded-lg border border-slate-300 px-2 py-1.5 text-center">
                        <span>phút ⓘ</span>
                    </label>

                    <label class="flex items-center gap-2">
                        <input v-model="overtimeForm.afterEnabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span>Tính làm thêm giờ sau ca</span>
                        <input v-model.number="overtimeForm.afterMinutes" type="number" min="0" class="w-12 rounded-lg border border-slate-300 px-2 py-1.5 text-center">
                        <span>phút ⓘ</span>
                    </label>
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-4">
                    <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="activeModal = ''">Bỏ qua</button>
                    <button type="button" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60" :disabled="saving" @click="saveOvertimeModal">Lưu</button>
                </div>
            </div>

            <div v-else class="w-full max-w-[420px] rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between px-5 py-4">
                    <div class="text-[18px] font-semibold text-slate-900">Chấm công trên điện thoại di động</div>
                    <button type="button" class="text-2xl leading-none text-slate-500 hover:text-slate-700" @click="activeModal = ''">×</button>
                </div>

                <div class="space-y-4 px-5 pb-5 text-[15px] text-slate-700">
                    <label class="flex items-center gap-2">
                        <input v-model="mobileForm.gpsRequired" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span>Yêu cầu bật định vị GPS khi chấm công</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="mobileForm.qrEnabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span>Cho phép chấm công bằng mã QR</span>
                    </label>
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-4">
                    <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="activeModal = ''">Bỏ qua</button>
                    <button type="button" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60" :disabled="saving" @click="saveMobileModal">Lưu</button>
                </div>
            </div>
        </div>

        <transition
            enter-active-class="transform transition ease-out duration-300"
            enter-from-class="translate-y-2 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="toast.show" class="fixed right-4 top-20 z-[60] rounded-lg border px-4 py-3 text-sm shadow-lg" :class="toast.type === 'success' ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700'">
                {{ toast.message }}
            </div>
        </transition>
    </AppLayout>
</template>