<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import SetupSidebar from '@/Pages/Employees/Partials/SetupSidebar.vue';
import { formatVND as formatMoney } from '@/utils/money';
import MoneyInput from '@/Components/MoneyInput.vue';

const props = defineProps({
    overview: {
        type: Object,
        default: () => ({
            employees_total: 0,
            shifts_total: 0,
            schedules_total: 0,
            devices_total: 0,
            salary_configs_total: 0,
            payroll_sheets_total: 0,
            employees_scheduled_distinct: 0,
        }),
    },
    employees: {
        type: Array,
        default: () => [],
    },
    shifts: {
        type: Array,
        default: () => [],
    },
    branches: {
        type: Array,
        default: () => [],
    },
    departments: {
        type: Array,
        default: () => [],
    },
    jobTitles: {
        type: Array,
        default: () => [],
    },
});

const overview = ref({ ...props.overview });
watch(
    () => props.overview,
    (value) => {
        overview.value = { ...value };
    },
    { deep: true }
);


// ========== Thiết lập lương Modal ==========
const showSalarySetup = ref(false);
const salarySetupLoading = ref(false);
const salaryEmployees = ref([]);
const salaryBranches = ref([]);
const salarySearch = ref('');
const salaryBranchFilter = ref('');
const salaryTypeFilter = ref('');
const editingCell = reactive({ employeeId: null, section: null });
const cellSaving = ref(false);
const salaryToast = reactive({ show: false, type: 'success', message: '' });

const cellForm = reactive({
    salary_type: 'by_workday',
    base_salary: 0,
    has_overtime: false,
    overtime_rate: 150,
    custom_bonuses: [],
    custom_commissions: [],
    custom_allowances: [],
    custom_deductions: [],
});

const salaryTypeLabels = { fixed: 'Cố định', by_workday: 'Theo ngày công', hourly: 'Theo giờ' };

const roleTypes = [
    { value: 'sales', label: 'Tư vấn bán hàng' },
    { value: 'cashier', label: 'Thu ngân' },
    { value: 'technician', label: 'Kỹ thuật viên' },
    { value: 'manager', label: 'Quản lý' },
    { value: 'other', label: 'Khác' },
];

const allowanceTypeOptions = [
    { value: 'fixed_per_day', label: 'Cố định theo ngày' },
    { value: 'fixed_per_month', label: 'Cố định theo tháng' },
    { value: 'percentage', label: 'Theo % lương cơ bản' },
];

const allowanceNames = [
    { value: 'Ăn trưa', label: 'Ăn trưa' },
    { value: 'Đi lại', label: 'Đi lại' },
    { value: 'Điện thoại', label: 'Điện thoại' },
    { value: 'Nhà ở', label: 'Nhà ở' },
    { value: 'Xăng xe', label: 'Xăng xe' },
    { value: 'Chuyên cần', label: 'Chuyên cần' },
];

const setSalaryToast = (message, type = 'success') => {
    salaryToast.show = true;
    salaryToast.type = type;
    salaryToast.message = message;
    clearTimeout(setSalaryToast._t);
    setSalaryToast._t = setTimeout(() => { salaryToast.show = false; }, 2500);
};

const openSalarySetup = async () => {
    showSalarySetup.value = true;
    await fetchSalaryOverview();
};

const fetchSalaryOverview = async () => {
    salarySetupLoading.value = true;
    try {
        const params = {};
        if (salarySearch.value) params.search = salarySearch.value;
        if (salaryBranchFilter.value) params.branch_id = salaryBranchFilter.value;
        if (salaryTypeFilter.value) params.salary_type = salaryTypeFilter.value;
        const res = await axios.get('/api/employee-salary-settings', { params });
        salaryEmployees.value = res.data?.data ?? [];
        if (res.data?.branches) salaryBranches.value = res.data.branches;
    } catch {
        setSalaryToast('Không thể tải dữ liệu lương nhân viên.', 'error');
    } finally {
        salarySetupLoading.value = false;
    }
};

let salarySearchTimeout = null;
const onSalarySearchInput = () => {
    clearTimeout(salarySearchTimeout);
    salarySearchTimeout = setTimeout(fetchSalaryOverview, 400);
};

const getSalarySummary = (emp) => { const s = emp.salary_setting; if (!s) return null; return `${formatMoney(s.base_salary)} / ${s.salary_type === 'hourly' ? 'giờ' : 'tháng'}`; };
const getSalarySubtext = (emp) => { const s = emp.salary_setting; if (!s) return ''; return salaryTypeLabels[s.salary_type] || ''; };
const getOvertimeSummary = (emp) => { const s = emp.salary_setting; if (!s || !s.has_overtime) return null; return `${s.overtime_rate || 150}%`; };
const getBonusSummary = (emp) => { const s = emp.salary_setting; if (!s) return null; const items = s.custom_bonuses || []; return items.length ? `${items.length} mức` : null; };
const getCommissionSummary = (emp) => { const s = emp.salary_setting; if (!s) return null; const items = s.custom_commissions || []; return items.length ? `${items.length} mức` : null; };
const getAllowanceSummary = (emp) => { const s = emp.salary_setting; if (!s) return null; const items = s.custom_allowances || []; if (!items.length) return null; return formatMoney(items.reduce((sum, a) => sum + Number(a.amount || 0), 0)); };
const getDeductionSummary = (emp) => { const s = emp.salary_setting; if (!s) return null; const items = s.custom_deductions || []; if (!items.length) return null; return formatMoney(items.reduce((sum, d) => sum + Number(d.amount || 0), 0)); };

const openCellEditor = (emp, section) => {
    const s = emp.salary_setting || {};
    editingCell.employeeId = emp.id;
    editingCell.section = section;
    cellForm.salary_type = s.salary_type || 'by_workday';
    cellForm.base_salary = s.base_salary || 0;
    cellForm.has_overtime = Boolean(s.has_overtime);
    cellForm.overtime_rate = s.overtime_rate || 150;
    cellForm.custom_bonuses = (s.custom_bonuses || []).map(b => ({ ...b }));
    cellForm.custom_commissions = (s.custom_commissions || []).map(c => ({ ...c }));
    cellForm.custom_allowances = (s.custom_allowances || []).map(a => ({ ...a }));
    cellForm.custom_deductions = (s.custom_deductions || []).map(d => ({ ...d }));
    if (section === 'bonus' && !cellForm.custom_bonuses.length) cellForm.custom_bonuses.push({ role_type: 'sales', revenue_from: 0, bonus_value: 0, bonus_is_percentage: true });
    if (section === 'commission' && !cellForm.custom_commissions.length) cellForm.custom_commissions.push({ role_type: 'sales', revenue_from: 0, commission_table_id: null, commission_value: 0, commission_is_percentage: false });
    if (section === 'allowance' && !cellForm.custom_allowances.length) cellForm.custom_allowances.push({ name: 'Ăn trưa', allowance_type: 'fixed_per_day', amount: 0 });
    if (section === 'deduction' && !cellForm.custom_deductions.length) cellForm.custom_deductions.push({ name: 'Giảm trừ', amount: 0 });
};

const closeCellEditor = () => { editingCell.employeeId = null; editingCell.section = null; };

const saveCellEdit = async () => {
    cellSaving.value = true;
    try {
        const emp = salaryEmployees.value.find(e => e.id === editingCell.employeeId);
        const existing = emp?.salary_setting || {};
        const payload = {
            salary_type: cellForm.salary_type,
            base_salary: Number(cellForm.base_salary || 0),
            has_overtime: cellForm.has_overtime,
            overtime_rate: Number(cellForm.overtime_rate || 150),
            has_bonus: cellForm.custom_bonuses.length > 0,
            has_commission: cellForm.custom_commissions.length > 0,
            has_allowance: cellForm.custom_allowances.length > 0,
            has_deduction: cellForm.custom_deductions.length > 0,
            custom_bonuses: cellForm.custom_bonuses.map(b => ({
                ...b,
                revenue_from: Number(b.revenue_from) || 0,
                bonus_value: Number(b.bonus_value) || 0,
            })),
            custom_commissions: cellForm.custom_commissions.map(c => ({
                ...c,
                revenue_from: Number(c.revenue_from) || 0,
                commission_value: Number(c.commission_value) || 0,
            })),
            custom_allowances: cellForm.custom_allowances.filter(a => a.name).map(a => ({
                ...a,
                amount: Number(a.amount) || 0,
            })),
            custom_deductions: cellForm.custom_deductions.filter(d => d.name).map(d => ({
                ...d,
                amount: Number(d.amount) || 0,
            })),
            salary_template_id: existing.salary_template_id || null,
            advanced_salary: existing.advanced_salary || false,
            holiday_rate: existing.holiday_rate || 200,
            tet_rate: existing.tet_rate || 300,
            saturday_ot_rate: existing.saturday_ot_rate || 150,
            sunday_ot_rate: existing.sunday_ot_rate || 150,
            rest_day_ot_rate: existing.rest_day_ot_rate || 150,
            holiday_ot_rate: existing.holiday_ot_rate || 150,
            bonus_type: existing.bonus_type || null,
            bonus_calculation: existing.bonus_calculation || null,
        };
        const res = await axios.post(`/api/employee-salary-settings/${editingCell.employeeId}`, payload);
        if (emp) emp.salary_setting = res.data?.data ?? { ...payload, employee_id: editingCell.employeeId };
        setSalaryToast('Đã lưu thiết lập lương.');
        closeCellEditor();
        router.reload({ only: ['overview'], preserveScroll: true, preserveState: true });
    } catch (error) {
        setSalaryToast(error?.response?.data?.message || 'Không thể lưu.', 'error');
    } finally {
        cellSaving.value = false;
    }
};

const localShifts = ref([...(props.shifts || [])]);
watch(
    () => props.shifts,
    (value) => {
        localShifts.value = [...(value || [])];
    },
    { deep: true }
);

const showEmployeeModal = ref(false);
const employeeRows = ref([]);
const employeeSubmitting = ref(false);
const employeeError = ref('');

const defaultBranchId = computed(() => props.branches?.[0]?.id || null);

const createEmployeeRow = () => ({
    code: '',
    attendance_code: '',
    name: '',
    phone: '',
    email: '',
    cccd: '',
    branch_id: defaultBranchId.value,
    department_id: null,
    job_title_id: null,
    notes: '',
    is_active: true,
});

const resetEmployeeRows = () => {
    employeeRows.value = [createEmployeeRow()];
    employeeError.value = '';
};

const openEmployeeModal = () => {
    resetEmployeeRows();
    showEmployeeModal.value = true;
};

const addEmployeeRow = () => {
    employeeRows.value.push(createEmployeeRow());
};

const removeEmployeeRow = (index) => {
    if (employeeRows.value.length === 1) {
        return;
    }
    employeeRows.value.splice(index, 1);
};

const submitEmployees = () => {
    const payload = employeeRows.value
        .map((row) => ({
            ...row,
            name: (row.name || '').trim(),
            phone: (row.phone || '').trim(),
            email: (row.email || '').trim(),
            code: (row.code || '').trim(),
            attendance_code: (row.attendance_code || '').trim(),
        }))
        .filter((row) => row.name.length > 0);

    if (payload.length === 0) {
        employeeError.value = 'Vui lòng nhập ít nhất 1 nhân viên.';
        return;
    }

    employeeSubmitting.value = true;
    employeeError.value = '';

    router.post(
        '/employees/bulk',
        { employees: payload },
        {
            preserveScroll: true,
            onSuccess: () => {
                showEmployeeModal.value = false;
                resetEmployeeRows();
            },
            onError: () => {
                employeeError.value = 'Không thể thêm nhân viên. Vui lòng kiểm tra dữ liệu.';
            },
            onFinish: () => {
                employeeSubmitting.value = false;
            },
        }
    );
};

const showShiftModal = ref(false);
const shiftForm = reactive({
    name: '',
    start_time: '',
    end_time: '',
    allow_late_minutes: 0,
    allow_early_minutes: 0,
    notes: '',
    status: 'active',
});
const shiftSubmitting = ref(false);
const shiftError = ref('');

const resetShiftForm = () => {
    shiftForm.name = '';
    shiftForm.start_time = '';
    shiftForm.end_time = '';
    shiftForm.allow_late_minutes = 0;
    shiftForm.allow_early_minutes = 0;
    shiftForm.notes = '';
    shiftForm.status = 'active';
    shiftError.value = '';
};

const openShiftModal = () => {
    resetShiftForm();
    showShiftModal.value = true;
};

const submitShift = async () => {
    if (!shiftForm.name || !shiftForm.start_time || !shiftForm.end_time) {
        shiftError.value = 'Vui lòng nhập tên ca và giờ bắt đầu/kết thúc.';
        return;
    }

    shiftSubmitting.value = true;
    shiftError.value = '';

    try {
        const response = await axios.post('/api/shifts', {
            name: shiftForm.name,
            start_time: shiftForm.start_time,
            end_time: shiftForm.end_time,
            allow_late_minutes: shiftForm.allow_late_minutes,
            allow_early_minutes: shiftForm.allow_early_minutes,
            notes: shiftForm.notes || null,
            status: shiftForm.status,
        });

        const createdShift = response?.data?.data;
        if (createdShift) {
            localShifts.value.unshift(createdShift);
        }

        showShiftModal.value = false;
        router.reload({ only: ['overview', 'shifts'], preserveScroll: true, preserveState: true });
    } catch (error) {
        shiftError.value = error?.response?.data?.message || 'Không thể tạo ca làm việc.';
    } finally {
        shiftSubmitting.value = false;
    }
};

const showScheduleModal = ref(false);
const scheduleLoading = ref(false);
const scheduleSavingKey = ref('');
const selectedShiftId = ref(null);
const employeeSearch = ref('');
const weekBaseDate = ref(new Date());
const schedulesMap = ref({});

watch(localShifts, (value) => {
    if (!selectedShiftId.value && value.length > 0) {
        selectedShiftId.value = value[0].id;
    }
}, { immediate: true, deep: true });

const toDateString = (date) => {
    const y = date.getFullYear();
    const m = `${date.getMonth() + 1}`.padStart(2, '0');
    const d = `${date.getDate()}`.padStart(2, '0');
    return `${y}-${m}-${d}`;
};

const startOfWeek = (dateValue) => {
    const date = new Date(dateValue);
    const day = date.getDay();
    const diff = day === 0 ? -6 : 1 - day;
    date.setDate(date.getDate() + diff);
    date.setHours(0, 0, 0, 0);
    return date;
};

const weekStart = computed(() => startOfWeek(weekBaseDate.value));

const weekDays = computed(() => {
    const start = weekStart.value;
    const dayNames = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];

    return Array.from({ length: 7 }).map((_, index) => {
        const date = new Date(start);
        date.setDate(start.getDate() + index);

        return {
            date: toDateString(date),
            label: dayNames[index],
            display: new Intl.DateTimeFormat('vi-VN', { day: '2-digit', month: '2-digit' }).format(date),
        };
    });
});

const weekLabel = computed(() => {
    const first = weekDays.value[0];
    const last = weekDays.value[6];
    if (!first || !last) {
        return '';
    }
    return `${first.display} - ${last.display}`;
});

const getScheduleKey = (employeeId, date) => `${employeeId}_${date}`;

const scheduleForCell = (employeeId, date) => {
    return schedulesMap.value[getScheduleKey(employeeId, date)] || null;
};

const filteredEmployees = computed(() => {
    const keyword = (employeeSearch.value || '').trim().toLowerCase();
    if (!keyword) {
        return props.employees;
    }

    return props.employees.filter((employee) => {
        const name = (employee.name || '').toLowerCase();
        const code = (employee.code || '').toLowerCase();
        const phone = (employee.phone || '').toLowerCase();
        return name.includes(keyword) || code.includes(keyword) || phone.includes(keyword);
    });
});

const formatShiftTime = (shift) => {
    const start = (shift?.start_time || '').slice(0, 5);
    const end = (shift?.end_time || '').slice(0, 5);
    if (!start || !end) {
        return '';
    }
    return `${start} - ${end}`;
};

const loadSchedules = async () => {
    if (!showScheduleModal.value || weekDays.value.length === 0) {
        return;
    }

    scheduleLoading.value = true;
    try {
        const response = await axios.get('/api/employee-schedules', {
            params: {
                from: weekDays.value[0].date,
                to: weekDays.value[6].date,
            },
        });

        const rows = response?.data?.data || [];
        const nextMap = {};

        rows.forEach((item) => {
            const employeeId = item.employee_id || item.employee?.id;
            if (!employeeId || !item.work_date) {
                return;
            }
            nextMap[getScheduleKey(employeeId, item.work_date)] = item;
        });

        schedulesMap.value = nextMap;
    } finally {
        scheduleLoading.value = false;
    }
};

const changeWeek = (offset) => {
    const next = new Date(weekBaseDate.value);
    next.setDate(next.getDate() + offset * 7);
    weekBaseDate.value = next;
    loadSchedules();
};

const openScheduleModal = () => {
    showScheduleModal.value = true;
    loadSchedules();
};

const toggleSchedule = async (employee, date) => {
    if (!selectedShiftId.value) {
        return;
    }

    const key = getScheduleKey(employee.id, date);
    scheduleSavingKey.value = key;

    try {
        const current = schedulesMap.value[key];
        if (current?.id) {
            await axios.delete(`/api/employee-schedules/${current.id}`);
            delete schedulesMap.value[key];
        } else {
            const response = await axios.post('/api/employee-schedules', {
                employee_id: employee.id,
                work_date: date,
                shift_id: selectedShiftId.value,
                slot: 1,
            });

            const created = response?.data?.data;
            if (created) {
                schedulesMap.value[key] = created;
            }
        }

        router.reload({ only: ['overview'], preserveScroll: true, preserveState: true });
    } finally {
        scheduleSavingKey.value = '';
    }
};

const quickItems = computed(() => [
    {
        key: 'employees',
        title: 'Thêm nhân viên',
        subtitle: `Cửa hàng đang có ${overview.value.employees_total} nhân viên.`,
        done: overview.value.employees_total > 0,
        linkText: 'Xem danh sách',
        actionText: 'Thêm nhân viên',
    },
    {
        key: 'shifts',
        title: 'Tạo ca làm việc',
        subtitle: `Cửa hàng đang có ${overview.value.shifts_total} ca làm việc.`,
        done: overview.value.shifts_total > 0,
        linkText: 'Xem danh sách',
        actionText: 'Tạo ca',
    },
    {
        key: 'schedules',
        title: 'Xếp lịch làm việc',
        subtitle: `Đã xếp lịch cho ${overview.value.employees_scheduled_distinct}/${overview.value.employees_total} nhân viên trong cửa hàng.`,
        done: overview.value.employees_total > 0 && overview.value.employees_scheduled_distinct > 0,
        linkText: 'Xem lịch',
        actionText: 'Xếp lịch',
    },
    {
        key: 'attendance',
        title: 'Hình thức chấm công',
        subtitle: `Cửa hàng đã thiết lập ${overview.value.devices_total} máy chấm công.`,
        done: overview.value.devices_total > 0,
        linkText: 'Xem chi tiết',
        actionText: 'Thiết lập',
    },
    {
        key: 'salary',
        title: 'Thiết lập lương',
        subtitle: `Đã thiết lập lương cho ${overview.value.salary_configs_total}/${overview.value.employees_total} nhân viên.`,
        done: overview.value.employees_total > 0 && overview.value.salary_configs_total > 0,
        linkText: 'Xem chi tiết',
        actionText: 'Thiết lập',
    },
    {
        key: 'paysheets',
        title: 'Thiết lập tính lương',
        subtitle: 'Cấu hình ngày tính lương, mẫu lương và tự động tạo bảng lương.',
        done: overview.value.payroll_sheets_total > 0,
        linkText: 'Xem chi tiết',
        actionText: 'Thiết lập',
    },
]);

const handleItemAction = (key) => {
    if (key === 'employees') {
        openEmployeeModal();
        return;
    }

    if (key === 'shifts') {
        openShiftModal();
        return;
    }

    if (key === 'schedules') {
        openScheduleModal();
        return;
    }

    if (key === 'attendance') {
        router.visit('/employees/attendance/settings');
        return;
    }

    if (key === 'salary') {
        openSalarySetup();
        return;
    }

    if (key === 'paysheets') {
        router.visit('/employees/payroll/settings');
    }
};

const handleItemLink = (key) => {
    if (key === 'employees') {
        router.visit('/employees');
        return;
    }

    if (key === 'shifts') {
        openShiftModal();
        return;
    }

    if (key === 'schedules') {
        router.visit('/employees/schedules');
        return;
    }

    if (key === 'attendance') {
        router.visit('/employees/attendance/settings');
        return;
    }

    if (key === 'salary') {
        openSalarySetup();
        return;
    }

    if (key === 'paysheets') {
        router.visit('/employees/payroll/settings');
    }
};
</script>

<template>
    <Head title="Thiết lập nhân viên - KiotViet Clone" />

    <AppLayout>
        <template #sidebar>
            <SetupSidebar active-main="init" />
        </template>

        <div class="space-y-4">
                <section class="rounded-lg border border-gray-200 bg-white">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h1 class="text-lg font-semibold text-gray-900">Thiết lập nhanh</h1>
                        <p class="mt-1 text-sm text-gray-500">
                            Chỉ vài bước cài đặt để quản lý nhân viên hiệu quả, tối ưu vận hành và tính lương chính xác.
                        </p>
                    </div>

                    <div class="divide-y divide-gray-100">
                        <div
                            v-for="item in quickItems"
                            :key="item.key"
                            class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="flex items-start gap-3">
                                <div
                                    class="mt-0.5 flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold"
                                    :class="item.done ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                                >
                                    <span v-if="item.done">✓</span>
                                    <span v-else>•</span>
                                </div>

                                <div>
                                    <div class="font-medium text-gray-900">{{ item.title }}</div>
                                    <div class="text-sm text-gray-500">{{ item.subtitle }}</div>
                                    <button
                                        type="button"
                                        class="mt-1 text-sm text-blue-600 hover:text-blue-700"
                                        @click="handleItemLink(item.key)"
                                    >
                                        {{ item.linkText }}
                                    </button>
                                </div>
                            </div>

                            <button
                                type="button"
                                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                @click="handleItemAction(item.key)"
                            >
                                {{ item.actionText }}
                            </button>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                    Mục "Khởi tạo" đang được bật. Bạn có thể thao tác trực tiếp trên các nút bên trên để hoàn tất thiết lập nhân viên.
                </section>
        </div>

        <div v-if="showEmployeeModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-6">
            <div class="w-full max-w-5xl overflow-hidden rounded-lg bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                    <h3 class="text-lg font-semibold text-gray-900">Thêm nhân viên</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-600" @click="showEmployeeModal = false">✕</button>
                </div>

                <div class="max-h-[65vh] overflow-auto px-6 py-5">
                    <div class="mb-3 flex justify-end">
                        <button
                            type="button"
                            class="rounded-md border border-blue-200 bg-blue-50 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-100"
                            @click="addEmployeeRow"
                        >
                            + Dòng nhân viên
                        </button>
                    </div>

                    <div class="overflow-auto rounded-md border border-gray-200">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="px-3 py-2 text-left">Tên nhân viên</th>
                                    <th class="px-3 py-2 text-left">Điện thoại</th>
                                    <th class="px-3 py-2 text-left">Chi nhánh</th>
                                    <th class="px-3 py-2 text-left">Phòng ban</th>
                                    <th class="px-3 py-2 text-left">Chức danh</th>
                                    <th class="px-3 py-2 text-center">Xóa</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in employeeRows" :key="index" class="border-t border-gray-100">
                                    <td class="px-3 py-2">
                                        <input
                                            v-model="row.name"
                                            type="text"
                                            placeholder="Nhập tên"
                                            class="w-full rounded border border-gray-300 px-2.5 py-1.5 outline-none focus:border-blue-500"
                                        >
                                    </td>
                                    <td class="px-3 py-2">
                                        <input
                                            v-model="row.phone"
                                            type="text"
                                            placeholder="Số điện thoại"
                                            class="w-full rounded border border-gray-300 px-2.5 py-1.5 outline-none focus:border-blue-500"
                                        >
                                    </td>
                                    <td class="px-3 py-2">
                                        <select
                                            v-model="row.branch_id"
                                            class="w-full rounded border border-gray-300 px-2.5 py-1.5 outline-none focus:border-blue-500"
                                        >
                                            <option :value="null">Chọn chi nhánh</option>
                                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-2">
                                        <select
                                            v-model="row.department_id"
                                            class="w-full rounded border border-gray-300 px-2.5 py-1.5 outline-none focus:border-blue-500"
                                        >
                                            <option :value="null">Chọn phòng ban</option>
                                            <option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-2">
                                        <select
                                            v-model="row.job_title_id"
                                            class="w-full rounded border border-gray-300 px-2.5 py-1.5 outline-none focus:border-blue-500"
                                        >
                                            <option :value="null">Chọn chức danh</option>
                                            <option v-for="jobTitle in jobTitles" :key="jobTitle.id" :value="jobTitle.id">{{ jobTitle.name }}</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        <button type="button" class="text-red-600 hover:text-red-700" @click="removeEmployeeRow(index)">Xóa</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-if="employeeError" class="mt-3 text-sm text-red-600">{{ employeeError }}</p>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-gray-200 bg-gray-50 px-6 py-4">
                    <button type="button" class="rounded border border-gray-300 px-4 py-2 text-sm hover:bg-gray-100" @click="showEmployeeModal = false">Bỏ qua</button>
                    <button
                        type="button"
                        class="rounded bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="employeeSubmitting"
                        @click="submitEmployees"
                    >
                        Lưu
                    </button>
                </div>
            </div>
        </div>

        <div v-if="showShiftModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-6">
            <div class="w-full max-w-lg rounded-lg bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                    <h3 class="text-lg font-semibold text-gray-900">Tạo ca làm việc</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-600" @click="showShiftModal = false">✕</button>
                </div>

                <div class="space-y-4 px-6 py-5">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Tên ca</label>
                        <input
                            v-model="shiftForm.name"
                            type="text"
                            class="w-full rounded border border-gray-300 px-3 py-2 outline-none focus:border-blue-500"
                            placeholder="Ví dụ: Ca hành chính"
                        >
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Bắt đầu</label>
                            <input v-model="shiftForm.start_time" type="time" class="w-full rounded border border-gray-300 px-3 py-2 outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Kết thúc</label>
                            <input v-model="shiftForm.end_time" type="time" class="w-full rounded border border-gray-300 px-3 py-2 outline-none focus:border-blue-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Cho phép đi muộn (phút)</label>
                            <input v-model.number="shiftForm.allow_late_minutes" type="number" min="0" class="w-full rounded border border-gray-300 px-3 py-2 outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Cho phép về sớm (phút)</label>
                            <input v-model.number="shiftForm.allow_early_minutes" type="number" min="0" class="w-full rounded border border-gray-300 px-3 py-2 outline-none focus:border-blue-500">
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Ghi chú</label>
                        <textarea
                            v-model="shiftForm.notes"
                            rows="3"
                            class="w-full rounded border border-gray-300 px-3 py-2 outline-none focus:border-blue-500"
                            placeholder="Nội dung thêm"
                        ></textarea>
                    </div>

                    <p v-if="shiftError" class="text-sm text-red-600">{{ shiftError }}</p>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-gray-200 bg-gray-50 px-6 py-4">
                    <button type="button" class="rounded border border-gray-300 px-4 py-2 text-sm hover:bg-gray-100" @click="showShiftModal = false">Bỏ qua</button>
                    <button
                        type="button"
                        class="rounded bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="shiftSubmitting"
                        @click="submitShift"
                    >
                        Lưu
                    </button>
                </div>
            </div>
        </div>

        <div v-if="showScheduleModal" class="fixed inset-0 z-50 bg-black/40 px-4 py-6">
            <div class="mx-auto flex h-full w-full max-w-7xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Xếp lịch làm việc</h3>
                        <p class="text-sm text-gray-500">Gán ca cho nhân viên theo từng ngày trong tuần.</p>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button" class="rounded border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50" @click="changeWeek(-1)">Tuần trước</button>
                        <div class="rounded border border-gray-200 bg-gray-50 px-3 py-1.5 text-sm font-medium text-gray-700">{{ weekLabel }}</div>
                        <button type="button" class="rounded border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50" @click="changeWeek(1)">Tuần sau</button>
                    </div>
                </div>

                <div class="border-b border-gray-200 px-5 py-3">
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-[260px_minmax(0,1fr)]">
                        <input
                            v-model="employeeSearch"
                            type="text"
                            placeholder="Tìm nhân viên theo tên, mã, số điện thoại"
                            class="rounded border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500"
                        >

                        <select
                            v-model="selectedShiftId"
                            class="rounded border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500"
                        >
                            <option :value="null">Chọn ca làm việc để xếp lịch</option>
                            <option v-for="shift in localShifts" :key="shift.id" :value="shift.id">
                                {{ shift.name }} - {{ formatShiftTime(shift) }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="flex-1 overflow-auto">
                    <div v-if="scheduleLoading" class="px-5 py-6 text-sm text-gray-600">Đang tải dữ liệu lịch...</div>

                    <table v-else class="min-w-full text-sm">
                        <thead class="sticky top-0 bg-gray-50 text-gray-600">
                            <tr>
                                <th class="min-w-[220px] border-b border-r border-gray-200 px-4 py-3 text-left">Nhân viên</th>
                                <th v-for="day in weekDays" :key="day.date" class="min-w-[140px] border-b border-r border-gray-200 px-3 py-3 text-center last:border-r-0">
                                    <div class="font-semibold">{{ day.label }}</div>
                                    <div class="text-xs text-gray-500">{{ day.display }}</div>
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr v-if="filteredEmployees.length === 0">
                                <td :colspan="8" class="px-6 py-8 text-center text-gray-500">Không có nhân viên phù hợp.</td>
                            </tr>

                            <tr v-for="employee in filteredEmployees" :key="employee.id" class="hover:bg-gray-50">
                                <td class="border-b border-r border-gray-100 px-4 py-3 align-top">
                                    <div class="font-medium text-gray-900">{{ employee.name }}</div>
                                    <div class="text-xs text-gray-500">{{ employee.code }}</div>
                                </td>

                                <td
                                    v-for="day in weekDays"
                                    :key="`${employee.id}_${day.date}`"
                                    class="border-b border-r border-gray-100 px-2 py-2 text-center align-top last:border-r-0"
                                >
                                    <button
                                        type="button"
                                        class="w-full rounded-md border px-2 py-2 text-xs transition"
                                        :class="scheduleForCell(employee.id, day.date) ? 'border-blue-300 bg-blue-50 text-blue-700' : 'border-gray-200 bg-white text-gray-600 hover:border-blue-200 hover:bg-blue-50'"
                                        @click="toggleSchedule(employee, day.date)"
                                    >
                                        <template v-if="scheduleSavingKey === `${employee.id}_${day.date}`">
                                            Đang xử lý...
                                        </template>
                                        <template v-else-if="scheduleForCell(employee.id, day.date)">
                                            <div class="font-semibold">{{ scheduleForCell(employee.id, day.date)?.shift?.name || 'Đã xếp ca' }}</div>
                                            <div class="mt-0.5 text-[11px]">{{ formatShiftTime(scheduleForCell(employee.id, day.date)?.shift) }}</div>
                                            <div class="mt-1 text-[11px] font-medium">Bỏ xếp</div>
                                        </template>
                                        <template v-else>
                                            <div class="font-medium">Xếp ca</div>
                                        </template>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-between border-t border-gray-200 bg-gray-50 px-5 py-3">
                    <p class="text-xs text-gray-500">Nhấn vào ô để bật/tắt lịch cho nhân viên trong ngày đã chọn.</p>
                    <div class="flex items-center gap-2">
                        <Link href="/employees/schedules" class="rounded border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-100">
                            Mở trang lịch đầy đủ
                        </Link>
                        <button type="button" class="rounded bg-blue-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-blue-700" @click="showScheduleModal = false">
                            Xong
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== THIẾT LẬP LƯƠNG MODAL ===== -->
        <div v-if="showSalarySetup" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="flex max-h-[92vh] w-full max-w-7xl flex-col rounded-xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">Thiết lập lương</h2>
                    <button type="button" class="text-gray-400 transition hover:text-gray-600" @click="showSalarySetup = false">✕</button>
                </div>
                <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-6 py-3">
                    <div class="relative flex-1 min-w-[200px]">
                        <svg class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                        <input v-model="salarySearch" type="text" placeholder="Tìm theo tên, mã nhân viên" class="w-full rounded-md border border-gray-300 py-2 pl-10 pr-3 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500" @input="onSalarySearchInput" />
                    </div>
                    <select v-model="salaryBranchFilter" class="rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500" @change="fetchSalaryOverview">
                        <option value="">Tất cả chi nhánh</option>
                        <option v-for="b in salaryBranches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                    <select v-model="salaryTypeFilter" class="rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500" @change="fetchSalaryOverview">
                        <option value="">Tất cả loại lương</option>
                        <option value="fixed">Cố định</option>
                        <option value="by_workday">Theo ngày công</option>
                        <option value="hourly">Theo giờ</option>
                    </select>
                </div>
                <div class="flex-1 overflow-auto px-6 py-3">
                    <div v-if="salarySetupLoading" class="flex items-center justify-center py-16">
                        <svg class="h-8 w-8 animate-spin text-blue-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                    </div>
                    <div v-else class="overflow-hidden rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="w-12 px-3 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">STT</th>
                                    <th class="min-w-[180px] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Nhân viên</th>
                                    <th class="min-w-[150px] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Lương chính</th>
                                    <th class="min-w-[100px] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Làm thêm</th>
                                    <th class="min-w-[100px] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Thưởng</th>
                                    <th class="min-w-[100px] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Hoa hồng</th>
                                    <th class="min-w-[100px] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Phụ cấp</th>
                                    <th class="min-w-[100px] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Giảm trừ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr v-for="(emp, idx) in salaryEmployees" :key="emp.id" class="hover:bg-gray-50">
                                    <td class="px-3 py-3 text-center text-sm text-gray-500">{{ idx + 1 }}</td>
                                    <td class="px-3 py-3">
                                        <div class="text-sm font-medium text-gray-900">{{ emp.name }}</div>
                                        <div class="text-xs text-gray-500">{{ emp.code }}<span v-if="emp.branch"> · {{ emp.branch.name }}</span></div>
                                    </td>
                                    <td class="px-3 py-3">
                                        <button v-if="getSalarySummary(emp)" type="button" class="text-left text-sm text-blue-600 hover:text-blue-700 hover:underline" @click="openCellEditor(emp, 'salary')">
                                            <div>{{ getSalarySummary(emp) }}</div>
                                            <div class="text-xs text-gray-500">{{ getSalarySubtext(emp) }}</div>
                                        </button>
                                        <button v-else type="button" class="text-xl text-gray-400 hover:text-blue-500" @click="openCellEditor(emp, 'salary')">+</button>
                                    </td>
                                    <td class="px-3 py-3">
                                        <button v-if="getOvertimeSummary(emp)" type="button" class="text-left text-sm text-blue-600 hover:underline" @click="openCellEditor(emp, 'overtime')">{{ getOvertimeSummary(emp) }}</button>
                                        <button v-else type="button" class="text-xl text-gray-400 hover:text-blue-500" @click="openCellEditor(emp, 'overtime')">+</button>
                                    </td>
                                    <td class="px-3 py-3">
                                        <button v-if="getBonusSummary(emp)" type="button" class="text-left text-sm text-blue-600 hover:underline" @click="openCellEditor(emp, 'bonus')">{{ getBonusSummary(emp) }}</button>
                                        <button v-else type="button" class="text-xl text-gray-400 hover:text-blue-500" @click="openCellEditor(emp, 'bonus')">+</button>
                                    </td>
                                    <td class="px-3 py-3">
                                        <button v-if="getCommissionSummary(emp)" type="button" class="text-left text-sm text-blue-600 hover:underline" @click="openCellEditor(emp, 'commission')">{{ getCommissionSummary(emp) }}</button>
                                        <button v-else type="button" class="text-xl text-gray-400 hover:text-blue-500" @click="openCellEditor(emp, 'commission')">+</button>
                                    </td>
                                    <td class="px-3 py-3">
                                        <button v-if="getAllowanceSummary(emp)" type="button" class="text-left text-sm text-blue-600 hover:underline" @click="openCellEditor(emp, 'allowance')">{{ getAllowanceSummary(emp) }}</button>
                                        <button v-else type="button" class="text-xl text-gray-400 hover:text-blue-500" @click="openCellEditor(emp, 'allowance')">+</button>
                                    </td>
                                    <td class="px-3 py-3">
                                        <button v-if="getDeductionSummary(emp)" type="button" class="text-left text-sm text-blue-600 hover:underline" @click="openCellEditor(emp, 'deduction')">{{ getDeductionSummary(emp) }}</button>
                                        <button v-else type="button" class="text-xl text-gray-400 hover:text-blue-500" @click="openCellEditor(emp, 'deduction')">+</button>
                                    </td>
                                </tr>
                                <tr v-if="!salaryEmployees.length && !salarySetupLoading">
                                    <td colspan="8" class="px-4 py-10 text-center text-sm text-gray-500">Không tìm thấy nhân viên nào.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="border-t border-gray-100 bg-gray-50 px-6 py-4 text-right">
                    <button type="button" class="rounded-md bg-blue-600 px-6 py-2 text-sm font-medium text-white transition hover:bg-blue-700" @click="showSalarySetup = false">Xong</button>
                </div>
            </div>
        </div>

        <!-- ===== CELL EDITOR MODAL ===== -->
        <div v-if="editingCell.employeeId" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-lg rounded-xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h3 class="text-base font-semibold text-gray-900">
                        {{ editingCell.section === 'salary' ? 'Lương chính' : editingCell.section === 'overtime' ? 'Làm thêm' : editingCell.section === 'bonus' ? 'Thưởng' : editingCell.section === 'commission' ? 'Hoa hồng' : editingCell.section === 'allowance' ? 'Phụ cấp' : 'Giảm trừ' }}
                        — {{ salaryEmployees.find(e => e.id === editingCell.employeeId)?.name }}
                    </h3>
                    <button type="button" class="text-gray-400 hover:text-gray-600" @click="closeCellEditor">✕</button>
                </div>
                <div class="max-h-[60vh] overflow-y-auto px-6 py-5 space-y-4">
                    <template v-if="editingCell.section === 'salary'">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Loại lương</label>
                            <select v-model="cellForm.salary_type" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500">
                                <option value="fixed">Cố định (không tính theo công)</option>
                                <option value="by_workday">Theo ngày công chuẩn</option>
                                <option value="hourly">Theo giờ</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ cellForm.salary_type === 'hourly' ? 'Đơn giá / giờ' : 'Lương cơ bản / tháng' }}</label>
                            <MoneyInput v-model="cellForm.base_salary" :min="0" input-class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500" />
                        </div>
                    </template>
                    <template v-if="editingCell.section === 'overtime'">
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input v-model="cellForm.has_overtime" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                            Bật tính lương làm thêm
                        </label>
                        <div v-if="cellForm.has_overtime">
                            <label class="mb-1 block text-sm font-medium text-gray-700">Hệ số lương OT (%)</label>
                            <input v-model.number="cellForm.overtime_rate" type="number" min="100" max="500" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500" />
                        </div>
                    </template>
                    <template v-if="editingCell.section === 'bonus'">
                        <div v-for="(b, i) in cellForm.custom_bonuses" :key="i" class="flex items-start gap-2 rounded-lg border border-gray-200 p-3">
                            <div class="flex-1 space-y-2">
                                <div class="grid grid-cols-2 gap-2">
                                    <div><label class="text-xs text-gray-500">Loại hình</label><select v-model="b.role_type" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm"><option v-for="r in roleTypes" :key="r.value" :value="r.value">{{ r.label }}</option></select></div>
                                    <div><label class="text-xs text-gray-500">Doanh thu từ</label><MoneyInput v-model="b.revenue_from" :min="0" input-class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm text-right" /></div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <MoneyInput v-if="!b.bonus_is_percentage" v-model="b.bonus_value" :min="0" input-class="w-24 rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                    <input v-else v-model.number="b.bonus_value" type="number" min="0" class="w-24 rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                    <select v-model="b.bonus_is_percentage" class="rounded border border-gray-300 px-2 py-1.5 text-xs"><option :value="true">%</option><option :value="false">Cố định</option></select>
                                </div>
                            </div>
                            <button type="button" class="mt-1 text-gray-400 hover:text-red-500" @click="cellForm.custom_bonuses.splice(i, 1)">✕</button>
                        </div>
                        <button type="button" class="text-sm text-blue-600 hover:text-blue-700" @click="cellForm.custom_bonuses.push({ role_type: 'sales', revenue_from: 0, bonus_value: 0, bonus_is_percentage: true })">+ Thêm mức thưởng</button>
                    </template>
                    <template v-if="editingCell.section === 'commission'">
                        <div v-for="(c, i) in cellForm.custom_commissions" :key="i" class="flex items-start gap-2 rounded-lg border border-gray-200 p-3">
                            <div class="flex-1 space-y-2">
                                <div class="grid grid-cols-2 gap-2">
                                    <div><label class="text-xs text-gray-500">Loại hình</label><select v-model="c.role_type" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm"><option v-for="r in roleTypes" :key="r.value" :value="r.value">{{ r.label }}</option></select></div>
                                    <div><label class="text-xs text-gray-500">Doanh thu từ</label><MoneyInput v-model="c.revenue_from" :min="0" input-class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm text-right" /></div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <MoneyInput v-if="!c.commission_is_percentage" v-model="c.commission_value" :min="0" input-class="w-24 rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                    <input v-else v-model.number="c.commission_value" type="number" min="0" class="w-24 rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                    <select v-model="c.commission_is_percentage" class="rounded border border-gray-300 px-2 py-1.5 text-xs"><option :value="true">%</option><option :value="false">Cố định</option></select>
                                </div>
                            </div>
                            <button type="button" class="mt-1 text-gray-400 hover:text-red-500" @click="cellForm.custom_commissions.splice(i, 1)">✕</button>
                        </div>
                        <button type="button" class="text-sm text-blue-600 hover:text-blue-700" @click="cellForm.custom_commissions.push({ role_type: 'sales', revenue_from: 0, commission_table_id: null, commission_value: 0, commission_is_percentage: false })">+ Thêm hoa hồng</button>
                    </template>
                    <template v-if="editingCell.section === 'allowance'">
                        <div v-for="(a, i) in cellForm.custom_allowances" :key="i" class="flex items-start gap-2 rounded-lg border border-gray-200 p-3">
                            <div class="flex-1 space-y-2">
                                <div class="grid grid-cols-2 gap-2">
                                    <div><label class="text-xs text-gray-500">Tên phụ cấp</label><select v-model="a.name" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm"><option value="">Chọn loại</option><option v-for="n in allowanceNames" :key="n.value" :value="n.value">{{ n.label }}</option></select></div>
                                    <div><label class="text-xs text-gray-500">Loại phụ cấp</label><select v-model="a.allowance_type" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm"><option v-for="opt in allowanceTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option></select></div>
                                </div>
                                <div><label class="text-xs text-gray-500">Số tiền</label><MoneyInput v-model="a.amount" :min="0" input-class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm text-right" /></div>
                            </div>
                            <button type="button" class="mt-1 text-gray-400 hover:text-red-500" @click="cellForm.custom_allowances.splice(i, 1)">✕</button>
                        </div>
                        <button type="button" class="text-sm text-blue-600 hover:text-blue-700" @click="cellForm.custom_allowances.push({ name: '', allowance_type: 'fixed_per_day', amount: 0 })">+ Thêm phụ cấp</button>
                    </template>
                    <template v-if="editingCell.section === 'deduction'">
                        <div v-for="(d, i) in cellForm.custom_deductions" :key="i" class="flex items-start gap-2 rounded-lg border border-gray-200 p-3">
                            <div class="flex-1 space-y-2">
                                <div><label class="text-xs text-gray-500">Tên giảm trừ</label><input v-model="d.name" type="text" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" placeholder="VD: BHXH, thuế TNCN" /></div>
                                <div><label class="text-xs text-gray-500">Số tiền</label><MoneyInput v-model="d.amount" :min="0" input-class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm text-right" /></div>
                            </div>
                            <button type="button" class="mt-1 text-gray-400 hover:text-red-500" @click="cellForm.custom_deductions.splice(i, 1)">✕</button>
                        </div>
                        <button type="button" class="text-sm text-blue-600 hover:text-blue-700" @click="cellForm.custom_deductions.push({ name: '', amount: 0 })">+ Thêm giảm trừ</button>
                    </template>
                </div>
                <div class="flex items-center justify-end gap-3 border-t border-gray-100 bg-gray-50 px-6 py-4">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-white" @click="closeCellEditor">Bỏ qua</button>
                    <button type="button" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60" :disabled="cellSaving" @click="saveCellEdit">{{ cellSaving ? 'Đang lưu...' : 'Lưu' }}</button>
                </div>
            </div>
        </div>

        <!-- Salary Toast -->
        <div v-if="salaryToast.show" class="fixed right-4 top-4 z-[70]">
            <div class="max-w-sm rounded-lg border px-4 py-3 text-sm shadow-lg" :class="salaryToast.type === 'success' ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700'">{{ salaryToast.message }}</div>
        </div>
    </AppLayout>
</template>
