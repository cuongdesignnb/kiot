<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import SetupSidebar from '@/Pages/Employees/Partials/SetupSidebar.vue';
import CurrencyInput from '@/Components/CurrencyInput.vue';

const props = defineProps({
    payrollSetting: {
        type: Object,
        default: () => ({
            pay_cycle: 'monthly',
            start_day: 26,
            end_day: 25,
            start_in_prev_month: true,
            pay_day: 5,
            default_recalculate_timekeeping: true,
            auto_generate_enabled: false,
        }),
    },
    salaryTemplates: {
        type: Array,
        default: () => [],
    },
});

const saving = ref(false);
const templateSaving = ref(false);
const deletingTemplateId = ref(null);
const showPaydayModal = ref(false);
const showTemplatesModal = ref(false);
const showTemplateEditor = ref(false);
const toast = reactive({ show: false, type: 'success', message: '' });
const commissionTables = ref([]);

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

// Cell edit form
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

const salaryTypeLabels = {
    fixed: 'Cố định',
    by_workday: 'Theo ngày công',
    hourly: 'Theo giờ',
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
        setToast('Không thể tải dữ liệu lương nhân viên.', 'error');
    } finally {
        salarySetupLoading.value = false;
    }
};

let searchTimeout = null;
const onSalarySearchInput = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(fetchSalaryOverview, 400);
};

const getSalarySummary = (emp) => {
    const s = emp.salary_setting;
    if (!s) return null;
    return `${formatMoney(s.base_salary)} / ${s.salary_type === 'hourly' ? 'giờ' : 'tháng'}`;
};

const getSalarySubtext = (emp) => {
    const s = emp.salary_setting;
    if (!s) return '';
    return salaryTypeLabels[s.salary_type] || '';
};

const getOvertimeSummary = (emp) => {
    const s = emp.salary_setting;
    if (!s || !s.has_overtime) return null;
    return `${s.overtime_rate || 150}%`;
};

const getBonusSummary = (emp) => {
    const s = emp.salary_setting;
    if (!s) return null;
    const items = s.custom_bonuses || [];
    if (!items.length) return null;
    return `${items.length} mức`;
};

const getCommissionSummary = (emp) => {
    const s = emp.salary_setting;
    if (!s) return null;
    const items = s.custom_commissions || [];
    if (!items.length) return null;
    return `${items.length} mức`;
};

const getAllowanceSummary = (emp) => {
    const s = emp.salary_setting;
    if (!s) return null;
    const items = s.custom_allowances || [];
    if (!items.length) return null;
    const total = items.reduce((sum, a) => sum + Number(a.amount || 0), 0);
    return formatMoney(total);
};

const getDeductionSummary = (emp) => {
    const s = emp.salary_setting;
    if (!s) return null;
    const items = s.custom_deductions || [];
    if (!items.length) return null;
    const total = items.reduce((sum, d) => sum + Number(d.amount || 0), 0);
    return formatMoney(total);
};

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

    if (section === 'bonus' && !cellForm.custom_bonuses.length) {
        cellForm.custom_bonuses.push({ role_type: 'sales', revenue_from: 0, bonus_value: 0, bonus_is_percentage: true });
    }
    if (section === 'commission' && !cellForm.custom_commissions.length) {
        cellForm.custom_commissions.push({ role_type: 'sales', revenue_from: 0, commission_table_id: null, commission_value: 0, commission_is_percentage: false });
    }
    if (section === 'allowance' && !cellForm.custom_allowances.length) {
        cellForm.custom_allowances.push({ name: 'Ăn trưa', allowance_type: 'fixed_per_day', amount: 0 });
    }
    if (section === 'deduction' && !cellForm.custom_deductions.length) {
        cellForm.custom_deductions.push({ name: 'Giảm trừ', amount: 0 });
    }
};

const closeCellEditor = () => {
    editingCell.employeeId = null;
    editingCell.section = null;
};

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
            custom_bonuses: cellForm.custom_bonuses,
            custom_commissions: cellForm.custom_commissions,
            custom_allowances: cellForm.custom_allowances.filter(a => a.name),
            custom_deductions: cellForm.custom_deductions.filter(d => d.name),
            salary_template_id: existing.salary_template_id || null,
            advanced_salary: existing.advanced_salary || false,
            holiday_rate: existing.holiday_rate || 200,
            tet_rate: existing.tet_rate || 300,
            bonus_type: existing.bonus_type || null,
            bonus_calculation: existing.bonus_calculation || null,
        };

        const res = await axios.post(`/api/employee-salary-settings/${editingCell.employeeId}`, payload);
        if (emp) {
            emp.salary_setting = res.data?.data ?? { ...payload, employee_id: editingCell.employeeId };
        }
        setToast('Đã lưu thiết lập lương.');
        closeCellEditor();
    } catch (error) {
        setToast(error?.response?.data?.message || 'Không thể lưu thiết lập lương.', 'error');
    } finally {
        cellSaving.value = false;
    }
};

const payroll = reactive({
    pay_cycle: props.payrollSetting?.pay_cycle ?? 'monthly',
    start_day: Number(props.payrollSetting?.start_day ?? 26),
    end_day: Number(props.payrollSetting?.end_day ?? 25),
    start_in_prev_month: Boolean(props.payrollSetting?.start_in_prev_month ?? true),
    pay_day: Number(props.payrollSetting?.pay_day ?? 5),
    default_recalculate_timekeeping: Boolean(props.payrollSetting?.default_recalculate_timekeeping ?? true),
    auto_generate_enabled: Boolean(props.payrollSetting?.auto_generate_enabled ?? false),
    late_half_day_enabled: Boolean(props.payrollSetting?.late_half_day_enabled ?? false),
    late_half_day_threshold: Number(props.payrollSetting?.late_half_day_threshold ?? 120),
    late_penalty_enabled: Boolean(props.payrollSetting?.late_penalty_enabled ?? false),
    late_penalty_tiers: (props.payrollSetting?.late_penalty_tiers ?? []).map(t => ({ minutes: Number(t.minutes), amount: Number(t.amount) })),
});

const paydayForm = reactive({
    start_day: Number(props.payrollSetting?.start_day ?? 26),
    end_day: Number(props.payrollSetting?.end_day ?? 25),
    start_in_prev_month: Boolean(props.payrollSetting?.start_in_prev_month ?? true),
    pay_day: Number(props.payrollSetting?.pay_day ?? 5),
});

// ========== Template Form ==========
const emptyTemplate = () => ({
    id: null,
    name: '',
    has_bonus: true,
    has_commission: true,
    has_allowance: true,
    has_deduction: true,
    bonus_type: 'personal_revenue',
    bonus_calculation: 'total_revenue',
    bonuses: [{ role_type: 'sales', revenue_from: 0, bonus_value: 0, bonus_is_percentage: true }],
    commissions: [{ role_type: 'sales', revenue_from: 0, commission_table_id: null, commission_value: 0, commission_is_percentage: false }],
    allowances: [{ name: '', allowance_type: 'fixed_per_day', amount: 0 }],
    deductions: [{ name: '', deduction_category: 'late', calculation_type: 'per_occurrence', amount: 0 }],
});

const normalizeTemplate = (t) => ({
    id: t.id,
    name: t.name ?? '',
    has_bonus: Boolean(t.has_bonus),
    has_commission: Boolean(t.has_commission),
    has_allowance: Boolean(t.has_allowance),
    has_deduction: Boolean(t.has_deduction),
    bonus_type: t.bonus_type ?? 'personal_revenue',
    bonus_calculation: t.bonus_calculation ?? 'total_revenue',
    bonuses: (t.bonuses ?? []).map(b => ({
        role_type: b.role_type,
        revenue_from: Number(b.revenue_from),
        bonus_value: Number(b.bonus_value),
        bonus_is_percentage: Boolean(b.bonus_is_percentage),
    })),
    commissions: (t.commissions ?? []).map(c => ({
        role_type: c.role_type,
        revenue_from: Number(c.revenue_from),
        commission_table_id: c.commission_table_id,
        commission_value: Number(c.commission_value ?? 0),
        commission_is_percentage: Boolean(c.commission_is_percentage),
    })),
    allowances: (t.allowances ?? []).map(a => ({
        name: a.name,
        allowance_type: a.allowance_type,
        amount: Number(a.amount),
    })),
    deductions: (t.deductions ?? []).map(d => ({
        name: d.name,
        deduction_category: d.deduction_category,
        calculation_type: d.calculation_type,
        amount: Number(d.amount),
    })),
    employee_count: t.employee_count ?? 0,
    created_at: t.created_at ?? null,
});

const templates = ref((props.salaryTemplates ?? []).map(normalizeTemplate));
const templateForm = reactive(emptyTemplate());

const roleTypes = [
    { value: 'sales', label: 'Tư vấn bán hàng' },
    { value: 'cashier', label: 'Thu ngân' },
    { value: 'technician', label: 'Kỹ thuật viên' },
    { value: 'manager', label: 'Quản lý' },
    { value: 'other', label: 'Khác' },
];

const bonusTypeOptions = [
    { value: 'personal_revenue', label: 'Theo doanh thu cá nhân' },
    { value: 'branch_revenue', label: 'Theo doanh thu chi nhánh/Cửa hàng' },
    { value: 'personal_gross_profit', label: 'Theo lợi nhuận gộp cá nhân' },
];

const bonusCalculationLabels = {
    personal_revenue: { total: 'Tính theo mức tổng doanh thu', progressive: 'Tính lũy tiến' },
    branch_revenue: { total: 'Tính theo mức tổng doanh thu', progressive: 'Tính lũy tiến' },
    personal_gross_profit: { total: 'Tính theo tổng lợi nhuận gộp', progressive: 'Tính lũy tiến' },
};
const bonusCalculationOptions = computed(() => {
    const labels = bonusCalculationLabels[templateForm.bonus_type] || bonusCalculationLabels.personal_revenue;
    return [
        { value: 'total_revenue', label: labels.total },
        { value: 'progressive', label: labels.progressive },
    ];
});
const bonusRevenueLabel = computed(() => templateForm.bonus_type === 'personal_gross_profit' ? 'Lợi nhuận gộp' : 'Doanh thu');

const allowanceTypeOptions = [
    { value: 'fixed_per_day', label: 'Phụ cấp cố định theo ngày' },
    { value: 'fixed_per_month', label: 'Phụ cấp cố định theo tháng' },
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

const deductionCategoryOptions = [
    { value: 'late', label: 'Đi muộn' },
    { value: 'early_leave', label: 'Về sớm' },
    { value: 'absence', label: 'Vắng mặt' },
    { value: 'violation', label: 'Vi phạm nội quy' },
];

const deductionCalcOptions = [
    { value: 'per_occurrence', label: 'Theo số lần' },
    { value: 'per_minute', label: 'Theo số phút' },
    { value: 'fixed_per_month', label: 'Cố định/tháng' },
];

// ========== Toast ==========
const setToast = (message, type = 'success') => {
    toast.show = true;
    toast.type = type;
    toast.message = message;
    window.clearTimeout(setToast.timeoutId);
    setToast.timeoutId = window.setTimeout(() => { toast.show = false; }, 2500);
};

const formatMoney = (value) => Number(value || 0).toLocaleString('vi-VN');

// ========== Payroll Settings Sidebar Rows ==========
const cycleSummary = computed(() => {
    if (payroll.pay_cycle === 'biweekly') {
        return `Kỳ lương 2 tuần, trả lương vào ngày ${payroll.pay_day} hằng tháng.`;
    }
    const startLabel = payroll.start_in_prev_month
        ? `từ ngày ${payroll.start_day} tháng trước`
        : `từ ngày ${payroll.start_day} trong tháng`;
    return `${startLabel} đến ngày ${payroll.end_day} hằng tháng, trả lương vào ngày ${payroll.pay_day}.`;
});

const templateSummary = computed(() => {
    if (!templates.value.length) return 'Chưa có mẫu lương nào được thiết lập.';
    return `Đang có ${templates.value.length} mẫu lương sẵn sàng để áp dụng cho nhân viên.`;
});

const salarySetupDescription = computed(() => {
    const count = salaryEmployees.value.length;
    if (count > 0) return `Đang có ${count} nhân viên được thiết lập lương.`;
    return 'Thiết lập lương chính, làm thêm, thưởng, hoa hồng, phụ cấp, giảm trừ cho từng nhân viên.';
});

const payrollRows = computed(() => [
    {
        key: 'salary-setup',
        title: 'Thiết lập lương nhân viên',
        description: salarySetupDescription.value,
        kind: 'action',
        action: () => { openSalarySetup(); },
    },
    {
        key: 'payday',
        title: 'Ngày tính lương',
        description: cycleSummary.value,
        kind: 'action',
        action: () => {
            paydayForm.start_day = payroll.start_day;
            paydayForm.end_day = payroll.end_day;
            paydayForm.start_in_prev_month = payroll.start_in_prev_month;
            paydayForm.pay_day = payroll.pay_day;
            showPaydayModal.value = true;
        },
    },
    {
        key: 'recalculate',
        title: 'Tự động cập nhật dữ liệu công khi tạo bảng lương',
        description: 'Khi bật, bảng lương mới sẽ mặc định lấy dữ liệu chấm công đã tính lại.',
        kind: 'toggle',
        field: 'default_recalculate_timekeeping',
    },
    {
        key: 'auto-generate',
        title: 'Tự động tạo bảng tính lương',
        description: 'Lưu thiết lập để sẵn sàng nối với cron hoặc job tự động tạo bảng lương theo kỳ.',
        kind: 'toggle',
        field: 'auto_generate_enabled',
    },
    {
        key: 'late-half-day',
        title: 'Đi muộn quá giờ tính nửa ngày công',
        description: `Khi nhân viên đi muộn quá ${payroll.late_half_day_threshold} phút (${Math.round(payroll.late_half_day_threshold / 60 * 10) / 10}h) sẽ chỉ tính 0.5 ngày công.`,
        kind: 'toggle',
        field: 'late_half_day_enabled',
    },
    {
        key: 'late-penalty',
        title: 'Trừ tiền khi đi muộn',
        description: 'Đi muộn X phút sẽ bị trừ Y đồng vào lương. Áp dụng cho tất cả nhân viên, tính theo từng ngày.',
        kind: 'toggle',
        field: 'late_penalty_enabled',
    },
    {
        key: 'templates',
        title: 'Thiết lập Mẫu lương',
        description: 'Thưởng, Hoa hồng, Phụ cấp, Giảm trừ',
        kind: 'action',
        action: () => { showTemplatesModal.value = true; },
    },
]);

// ========== Payroll Settings Persistence ==========
const persistPayrollSettings = async () => {
    saving.value = true;
    try {
        await axios.post('/api/payroll-settings', {
            pay_cycle: payroll.pay_cycle,
            start_day: Number(payroll.start_day || 1),
            end_day: Number(payroll.end_day || 1),
            start_in_prev_month: Boolean(payroll.start_in_prev_month),
            pay_day: Number(payroll.pay_day || 1),
            default_recalculate_timekeeping: Boolean(payroll.default_recalculate_timekeeping),
            auto_generate_enabled: Boolean(payroll.auto_generate_enabled),
            late_half_day_enabled: Boolean(payroll.late_half_day_enabled),
            late_half_day_threshold: Number(payroll.late_half_day_threshold || 120),
            late_penalty_enabled: Boolean(payroll.late_penalty_enabled),
            late_penalty_tiers: (payroll.late_penalty_tiers || []).filter(t => t.minutes > 0).map(t => ({ minutes: Number(t.minutes), amount: Number(t.amount || 0) })),
            status: 'active',
        });
        setToast('Đã lưu thiết lập tính lương.');
    } catch (error) {
        setToast(error?.response?.data?.message || 'Không thể lưu thiết lập tính lương.', 'error');
        throw error;
    } finally {
        saving.value = false;
    }
};

const togglePayrollField = async (field) => {
    const previous = payroll[field];
    payroll[field] = !previous;
    try { await persistPayrollSettings(); } catch { payroll[field] = previous; }
};

const savePaydayModal = async () => {
    payroll.pay_cycle = 'monthly';
    payroll.start_day = Number(paydayForm.start_day || 1);
    payroll.end_day = Number(paydayForm.end_day || 1);
    payroll.start_in_prev_month = Boolean(paydayForm.start_in_prev_month);
    payroll.pay_day = Number(paydayForm.pay_day || 1);
    await persistPayrollSettings();
    showPaydayModal.value = false;
};

// ========== Template CRUD ==========
const loadCommissionTables = async () => {
    try {
        const res = await axios.get('/api/salary-templates/commission-tables');
        commissionTables.value = res.data?.data ?? [];
    } catch { /* ignore */ }
};

const resetTemplateForm = () => { Object.assign(templateForm, emptyTemplate()); };

const openTemplateEditor = (template = null) => {
    resetTemplateForm();
    if (template) {
        Object.assign(templateForm, {
            id: template.id,
            name: template.name,
            has_bonus: template.has_bonus,
            has_commission: template.has_commission,
            has_allowance: template.has_allowance,
            has_deduction: template.has_deduction,
            bonus_type: template.bonus_type,
            bonus_calculation: template.bonus_calculation,
            bonuses: template.bonuses?.length
                ? template.bonuses.map(b => ({ ...b }))
                : [{ role_type: 'sales', revenue_from: 0, bonus_value: 0, bonus_is_percentage: true }],
            commissions: template.commissions?.length
                ? template.commissions.map(c => ({ ...c }))
                : [{ role_type: 'sales', revenue_from: 0, commission_table_id: null, commission_value: 0, commission_is_percentage: false }],
            allowances: template.allowances?.length
                ? template.allowances.map(a => ({ ...a }))
                : [{ name: '', allowance_type: 'fixed_per_day', amount: 0 }],
            deductions: template.deductions?.length
                ? template.deductions.map(d => ({ ...d }))
                : [{ name: '', deduction_category: 'late', calculation_type: 'per_occurrence', amount: 0 }],
        });
    }
    loadCommissionTables();
    showTemplateEditor.value = true;
};

const closeTemplateEditor = () => { showTemplateEditor.value = false; resetTemplateForm(); };

const addBonus = () => templateForm.bonuses.push({ role_type: 'sales', revenue_from: 0, bonus_value: 0, bonus_is_percentage: true });
const removeBonus = (i) => templateForm.bonuses.splice(i, 1);
const addCommission = () => templateForm.commissions.push({ role_type: 'sales', revenue_from: 0, commission_table_id: null, commission_value: 0, commission_is_percentage: false });
const removeCommission = (i) => templateForm.commissions.splice(i, 1);
const addAllowance = () => templateForm.allowances.push({ name: '', allowance_type: 'fixed_per_day', amount: 0 });
const removeAllowance = (i) => templateForm.allowances.splice(i, 1);
const addDeduction = () => templateForm.deductions.push({ name: '', deduction_category: 'late', calculation_type: 'per_occurrence', amount: 0 });
const removeDeduction = (i) => templateForm.deductions.splice(i, 1);
const onDeductionCategoryChange = (ded) => {
    if (!ded.name) {
        const opt = deductionCategoryOptions.find(o => o.value === ded.deduction_category);
        if (opt) ded.name = opt.label;
    }
};

const saveTemplate = async () => {
    if (!templateForm.name.trim()) {
        setToast('Tên mẫu lương là bắt buộc.', 'error');
        return;
    }
    templateSaving.value = true;
    try {
        const payload = {
            name: templateForm.name.trim(),
            has_bonus: templateForm.has_bonus,
            has_commission: templateForm.has_commission,
            has_allowance: templateForm.has_allowance,
            has_deduction: templateForm.has_deduction,
            bonus_type: templateForm.bonus_type,
            bonus_calculation: templateForm.bonus_calculation,
            bonuses: templateForm.has_bonus ? templateForm.bonuses : [],
            commissions: templateForm.has_commission ? templateForm.commissions : [],
            allowances: templateForm.has_allowance ? templateForm.allowances.filter(a => a.name) : [],
            deductions: templateForm.has_deduction ? templateForm.deductions.filter(d => d.deduction_category).map(d => ({
                ...d,
                name: d.name || (deductionCategoryOptions.find(o => o.value === d.deduction_category)?.label || d.deduction_category),
            })) : [],
        };

        const response = templateForm.id
            ? await axios.put(`/api/salary-templates/${templateForm.id}`, payload)
            : await axios.post('/api/salary-templates', payload);

        const nextTemplate = normalizeTemplate(response.data?.data ?? payload);
        const currentIndex = templates.value.findIndex((item) => item.id === nextTemplate.id);
        if (currentIndex >= 0) {
            templates.value.splice(currentIndex, 1, nextTemplate);
        } else {
            templates.value.unshift(nextTemplate);
        }
        templates.value.sort((left, right) => right.id - left.id);
        setToast(response.data?.message || 'Đã lưu mẫu lương.');
        closeTemplateEditor();
    } catch (error) {
        setToast(error?.response?.data?.message || 'Không thể lưu mẫu lương.', 'error');
    } finally {
        templateSaving.value = false;
    }
};

const removeTemplate = async (template) => {
    if (!window.confirm(`Xóa mẫu lương "${template.name}"?`)) return;
    deletingTemplateId.value = template.id;
    try {
        await axios.delete(`/api/salary-templates/${template.id}`);
        templates.value = templates.value.filter((item) => item.id !== template.id);
        setToast('Xóa mẫu lương thành công.');
    } catch (error) {
        setToast(error?.response?.data?.message || 'Không thể xóa mẫu lương.', 'error');
    } finally {
        deletingTemplateId.value = null;
    }
};
</script>

<template>
    <Head title="Thiết lập tính lương - KiotViet Clone" />

    <AppLayout>
        <template #sidebar>
            <SetupSidebar active-main="payroll" />
        </template>

        <div class="space-y-4">
            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-lg font-semibold text-gray-900">Thiết lập tính lương</h1>
                        <p class="mt-1 text-sm text-gray-500">
                            Cấu hình ngày tính lương, tự động tạo bảng lương và danh sách mẫu lương áp dụng cho nhân viên.
                        </p>
                    </div>
                    <Link href="/employees/paysheets" class="inline-flex items-center justify-center rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                        Mở bảng lương
                    </Link>
                </div>

                <div class="divide-y divide-gray-100">
                    <div v-for="row in payrollRows" :key="row.key">
                        <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div class="font-medium text-gray-900">{{ row.title }}</div>
                                <div class="mt-1 text-sm text-gray-500">{{ row.description }}</div>
                            </div>
                            <button v-if="row.kind === 'action'" type="button" class="inline-flex items-center justify-center rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50" @click="row.action()">
                                Chi tiết
                            </button>
                            <button v-else type="button" class="inline-flex h-7 w-12 items-center rounded-full transition" :class="payroll[row.field] ? 'bg-blue-600' : 'bg-gray-300'" :disabled="saving" @click="togglePayrollField(row.field)">
                                <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition" :class="payroll[row.field] ? 'translate-x-6' : 'translate-x-1'" />
                            </button>
                        </div>
                        <!-- Ngưỡng thời gian đi muộn -->
                        <div v-if="row.key === 'late-half-day' && payroll.late_half_day_enabled" class="px-5 pb-4 flex items-center gap-3">
                            <label class="text-sm text-gray-600 whitespace-nowrap">Ngưỡng đi muộn:</label>
                            <input
                                v-model.number="payroll.late_half_day_threshold"
                                type="number" min="1" max="480"
                                class="w-20 border border-gray-300 rounded-md px-2 py-1 text-center text-sm focus:border-blue-500 outline-none"
                                @change="persistPayrollSettings()"
                            />
                            <span class="text-sm text-gray-500">phút ({{ Math.round(payroll.late_half_day_threshold / 60 * 10) / 10 }}h)</span>
                        </div>
                        <!-- Bảng mức trừ tiền đi muộn -->
                        <div v-if="row.key === 'late-penalty' && payroll.late_penalty_enabled" class="px-5 pb-4 space-y-3">
                            <div class="text-sm text-gray-600">Thiết lập mức trừ tiền theo số phút đi muộn (mỗi ngày):</div>
                            <div v-for="(tier, ti) in payroll.late_penalty_tiers" :key="ti" class="flex items-center gap-2">
                                <span class="text-sm text-gray-500">Đi muộn ≥</span>
                                <input v-model.number="tier.minutes" type="number" min="1" max="480" class="w-20 border border-gray-300 rounded-md px-2 py-1 text-center text-sm focus:border-blue-500 outline-none" @change="persistPayrollSettings()" />
                                <span class="text-sm text-gray-500">phút → trừ</span>
                                <input v-model.number="tier.amount" type="number" min="0" step="1000" class="w-28 border border-gray-300 rounded-md px-2 py-1 text-right text-sm focus:border-blue-500 outline-none" @change="persistPayrollSettings()" />
                                <span class="text-sm text-gray-500">đ</span>
                                <button type="button" class="text-red-400 hover:text-red-600 text-sm px-1" @click="payroll.late_penalty_tiers.splice(ti, 1); persistPayrollSettings()">✕</button>
                            </div>
                            <button type="button" class="text-sm text-blue-600 hover:text-blue-700" @click="payroll.late_penalty_tiers.push({ minutes: 0, amount: 0 })">+ Thêm mức</button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- Toast -->
        <div v-if="toast.show" class="fixed right-4 top-4 z-50">
            <div class="max-w-sm rounded-lg border px-4 py-3 text-sm shadow-lg" :class="toast.type === 'success' ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700'">
                {{ toast.message }}
            </div>
        </div>

        <!-- Payday Modal -->
        <div v-if="showPaydayModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-2xl rounded-xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Ngày tính lương</h2>
                        <p class="mt-1 text-sm text-gray-500">Thiết lập kỳ lương mặc định để gợi ý khi tạo bảng lương.</p>
                    </div>
                    <button type="button" class="text-gray-400 transition hover:text-gray-600" @click="showPaydayModal = false">✕</button>
                </div>
                <div class="grid grid-cols-1 gap-4 px-6 py-5 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Từ ngày</label>
                        <input v-model.number="paydayForm.start_day" type="number" min="1" max="31" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Đến ngày</label>
                        <input v-model.number="paydayForm.end_day" type="number" min="1" max="31" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Ngày trả lương</label>
                        <input v-model.number="paydayForm.pay_day" type="number" min="1" max="31" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500" />
                    </div>
                    <div class="flex items-end">
                        <label class="flex items-center gap-3 rounded-md border border-gray-200 px-3 py-2.5 text-sm text-gray-700">
                            <input v-model="paydayForm.start_in_prev_month" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                            <span>Bắt đầu từ tháng trước</span>
                        </label>
                    </div>
                </div>
                <div class="border-t border-gray-100 bg-gray-50 px-6 py-4">
                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                        <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-white" @click="showPaydayModal = false">Bỏ qua</button>
                        <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-60" :disabled="saving" @click="savePaydayModal">
                            {{ saving ? 'Đang lưu...' : 'Lưu' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Templates List Modal (KiotViet-style: STT, Tên, NV áp dụng, icons) -->
        <div v-if="showTemplatesModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="flex max-h-[85vh] w-full max-w-3xl flex-col rounded-xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">Danh sách mẫu lương</h2>
                    <button type="button" class="text-gray-400 transition hover:text-gray-600" @click="showTemplatesModal = false">✕</button>
                </div>
                <div class="flex-1 overflow-auto px-6 py-5">
                    <div class="overflow-hidden rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">STT</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Tên mẫu lương</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Nhân viên áp dụng</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr v-for="(template, index) in templates" :key="template.id">
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ index + 1 }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ template.name }}</td>
                                    <td class="px-4 py-3 text-sm">
                                        <span class="cursor-pointer text-blue-600 hover:underline">{{ template.employee_count ?? 0 }} nhân viên</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-end gap-2">
                                            <button type="button" class="text-gray-400 hover:text-blue-600" title="Sửa" @click="openTemplateEditor(template)">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                            </button>
                                            <button type="button" class="text-gray-400 hover:text-red-600" title="Xóa" :disabled="deletingTemplateId === template.id" @click="removeTemplate(template)">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!templates.length">
                                    <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">Chưa có mẫu lương nào.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="flex items-center justify-between border-t border-gray-100 bg-gray-50 px-6 py-4">
                    <button type="button" class="text-sm font-medium text-blue-600 hover:text-blue-700" @click="openTemplateEditor()">
                        + Thêm mẫu lương
                    </button>
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-white" @click="showTemplatesModal = false">
                        Xong
                    </button>
                </div>
            </div>
        </div>

        <!-- Template Editor Modal (Full KiotViet-style with 4 sections) -->
        <div v-if="showTemplateEditor" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40 px-4">
            <div class="flex max-h-[92vh] w-full max-w-4xl flex-col rounded-xl bg-white shadow-2xl">
                <!-- Header -->
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">{{ templateForm.id ? 'Chỉnh sửa mẫu áp dụng' : 'Thêm mới mẫu áp dụng' }}</h2>
                    <button type="button" class="text-gray-400 transition hover:text-gray-600" @click="closeTemplateEditor">✕</button>
                </div>

                <!-- Body (scrollable) -->
                <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5">
                    <!-- Template Name -->
                    <div class="rounded-lg border border-gray-200 bg-white p-5">
                        <div class="flex items-center gap-4">
                            <label class="whitespace-nowrap text-sm font-medium text-gray-700">Mẫu áp dụng</label>
                            <input v-model="templateForm.name" type="text" class="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500" placeholder="VD: Mẫu lương nhân viên hành chính" />
                        </div>
                    </div>

                    <!-- ===== THƯỞNG (Bonus) ===== -->
                    <div class="rounded-lg border border-gray-200 bg-white p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-base font-bold text-gray-900">Thưởng</h3>
                                <p class="mt-0.5 text-sm text-gray-500">Thiết lập thưởng theo {{ templateForm.bonus_type === 'personal_gross_profit' ? 'lợi nhuận gộp' : 'doanh thu' }} cho nhân viên</p>
                            </div>
                            <button type="button" class="inline-flex h-7 w-12 items-center rounded-full transition" :class="templateForm.has_bonus ? 'bg-blue-600' : 'bg-gray-300'" @click="templateForm.has_bonus = !templateForm.has_bonus">
                                <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition" :class="templateForm.has_bonus ? 'translate-x-6' : 'translate-x-1'" />
                            </button>
                        </div>

                        <div v-if="templateForm.has_bonus" class="mt-4 space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-600">Loại thưởng</label>
                                    <select v-model="templateForm.bonus_type" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                        <option v-for="opt in bonusTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-600">Hình thức <span class="cursor-help text-gray-400" title="Tính theo mức tổng doanh thu: thưởng theo bậc đạt. Lũy tiến: thưởng theo từng mức.">&#9432;</span></label>
                                    <select v-model="templateForm.bonus_calculation" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                        <option v-for="opt in bonusCalculationOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                    </select>
                                </div>
                            </div>

                            <div class="overflow-hidden rounded-lg border border-gray-200">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500">Loại hình</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500" colspan="2">{{ bonusRevenueLabel }} <span class="cursor-help text-gray-400" :title="'Mức ' + bonusRevenueLabel.toLowerCase() + ' tối thiểu'">&#9432;</span></th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500">Thưởng thụ hưởng</th>
                                            <th class="w-10"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        <tr v-for="(bonus, i) in templateForm.bonuses" :key="i">
                                            <td class="px-3 py-2">
                                                <select v-model="bonus.role_type" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                                                    <option v-for="r in roleTypes" :key="r.value" :value="r.value">{{ r.label }}</option>
                                                </select>
                                            </td>
                                            <td class="px-1 py-2 text-sm text-gray-500 text-right">Từ</td>
                                            <td class="px-3 py-2">
                                                <CurrencyInput v-model="bonus.revenue_from" class="w-28 rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                            </td>
                                            <td class="px-3 py-2">
                                                <div class="flex items-center gap-1">
                                                    <CurrencyInput v-if="!bonus.bonus_is_percentage" v-model="bonus.bonus_value" class="w-20 rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                                    <input v-else v-model.number="bonus.bonus_value" type="number" min="0" class="w-20 rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                                    <select v-model="bonus.bonus_is_percentage" class="rounded border border-gray-300 px-1 py-1.5 text-xs">
                                                        <option :value="true">% {{ bonusRevenueLabel }}</option>
                                                        <option :value="false">Cố định</option>
                                                    </select>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <button type="button" class="text-gray-400 hover:text-red-500" @click="removeBonus(i)">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="text-sm font-medium text-blue-600 hover:text-blue-700" @click="addBonus">Thêm thưởng</button>
                        </div>
                    </div>

                    <!-- ===== HOA HỒNG (Commission) ===== -->
                    <div class="rounded-lg border border-gray-200 bg-white p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-base font-bold text-gray-900">Hoa hồng</h3>
                                <p class="mt-0.5 text-sm text-gray-500">Thiết lập mức hoa hồng theo sản phẩm hoặc dịch vụ</p>
                            </div>
                            <button type="button" class="inline-flex h-7 w-12 items-center rounded-full transition" :class="templateForm.has_commission ? 'bg-blue-600' : 'bg-gray-300'" @click="templateForm.has_commission = !templateForm.has_commission">
                                <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition" :class="templateForm.has_commission ? 'translate-x-6' : 'translate-x-1'" />
                            </button>
                        </div>

                        <div v-if="templateForm.has_commission" class="mt-4 space-y-4">
                            <div class="overflow-hidden rounded-lg border border-gray-200">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500">Loại hình</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500" colspan="2">Doanh thu <span class="cursor-help text-gray-400" title="Mức doanh thu tối thiểu">&#9432;</span></th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500">Hoa hồng thụ hưởng</th>
                                            <th class="w-10"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        <tr v-for="(com, i) in templateForm.commissions" :key="i">
                                            <td class="px-3 py-2">
                                                <select v-model="com.role_type" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                                                    <option v-for="r in roleTypes" :key="r.value" :value="r.value">{{ r.label }}</option>
                                                </select>
                                            </td>
                                            <td class="px-1 py-2 text-sm text-gray-500 text-right">Từ</td>
                                            <td class="px-3 py-2">
                                                <CurrencyInput v-model="com.revenue_from" class="w-28 rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                            </td>
                                            <td class="px-3 py-2">
                                                <select v-model="com.commission_table_id" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                                                    <option :value="null">Chọn Bảng hoa hồng</option>
                                                    <option v-for="ct in commissionTables" :key="ct.id" :value="ct.id">{{ ct.name }}</option>
                                                </select>
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <button type="button" class="text-gray-400 hover:text-red-500" @click="removeCommission(i)">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="text-sm font-medium text-blue-600 hover:text-blue-700" @click="addCommission">Thêm hoa hồng</button>
                        </div>
                    </div>

                    <!-- ===== PHỤ CẤP (Allowance) ===== -->
                    <div class="rounded-lg border border-gray-200 bg-white p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-base font-bold text-gray-900">Phụ cấp</h3>
                                <p class="mt-0.5 text-sm text-gray-500">Thiết lập khoản hỗ trợ làm việc như ăn trưa, đi lại, điện thoại, ...</p>
                            </div>
                            <button type="button" class="inline-flex h-7 w-12 items-center rounded-full transition" :class="templateForm.has_allowance ? 'bg-blue-600' : 'bg-gray-300'" @click="templateForm.has_allowance = !templateForm.has_allowance">
                                <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition" :class="templateForm.has_allowance ? 'translate-x-6' : 'translate-x-1'" />
                            </button>
                        </div>

                        <div v-if="templateForm.has_allowance" class="mt-4 space-y-4">
                            <div class="overflow-hidden rounded-lg border border-gray-200">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500">Tên phụ cấp</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500">Loại phụ cấp</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500">Phụ cấp thụ hưởng</th>
                                            <th class="w-10"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        <tr v-for="(al, i) in templateForm.allowances" :key="i">
                                            <td class="px-3 py-2">
                                                <select v-model="al.name" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                                                    <option value="">Chọn Loại phụ cấp</option>
                                                    <option v-for="a in allowanceNames" :key="a.value" :value="a.value">{{ a.label }}</option>
                                                </select>
                                            </td>
                                            <td class="px-3 py-2">
                                                <div class="flex items-center gap-1">
                                                    <select v-model="al.allowance_type" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                                                        <option v-for="opt in allowanceTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                                    </select>
                                                    <span class="cursor-help text-gray-400" title="Phụ cấp cố định theo ngày: nhân với số ngày công. Theo tháng: cố định. Theo %: tính trên lương cơ bản.">&#9432;</span>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2">
                                                <CurrencyInput v-if="al.allowance_type !== 'percentage'" v-model="al.amount" class="w-32 rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                                <input v-else v-model.number="al.amount" type="number" min="0" class="w-32 rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <button type="button" class="text-gray-400 hover:text-red-500" @click="removeAllowance(i)">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="text-sm font-medium text-blue-600 hover:text-blue-700" @click="addAllowance">Thêm phụ cấp</button>
                        </div>
                    </div>

                    <!-- ===== GIẢM TRỪ (Deduction) ===== -->
                    <div class="rounded-lg border border-gray-200 bg-white p-5">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-base font-bold text-gray-900">Giảm trừ</h3>
                                <p class="mt-0.5 text-sm text-gray-500">Thiết lập khoản giảm trừ như đi muộn, về sớm, vi phạm nội quy, ...</p>
                            </div>
                            <button type="button" class="inline-flex h-7 w-12 items-center rounded-full transition" :class="templateForm.has_deduction ? 'bg-blue-600' : 'bg-gray-300'" @click="templateForm.has_deduction = !templateForm.has_deduction">
                                <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition" :class="templateForm.has_deduction ? 'translate-x-6' : 'translate-x-1'" />
                            </button>
                        </div>

                        <div v-if="templateForm.has_deduction" class="mt-4 space-y-4">
                            <div class="overflow-hidden rounded-lg border border-gray-200">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500">Tên giảm trừ</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500">Loại giảm trừ</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500">Loại tính</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500">Số tiền</th>
                                            <th class="w-10"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        <tr v-for="(ded, i) in templateForm.deductions" :key="i">
                                            <td class="px-3 py-2">
                                                <input v-model="ded.name" type="text" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" placeholder="Đi muộn, BHXH..." />
                                            </td>
                                            <td class="px-3 py-2">
                                                <select v-model="ded.deduction_category" @change="onDeductionCategoryChange(ded)" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                                                    <option value="">Chọn loại</option>
                                                    <option v-for="opt in deductionCategoryOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                                </select>
                                            </td>
                                            <td class="px-3 py-2">
                                                <select v-model="ded.calculation_type" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                                                    <option v-for="opt in deductionCalcOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                                </select>
                                            </td>
                                            <td class="px-3 py-2">
                                                <div class="flex items-center gap-1">
                                                    <CurrencyInput v-model="ded.amount" class="w-32 rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                                    <span class="text-xs text-gray-400 whitespace-nowrap">
                                                        {{ ded.calculation_type === 'per_minute' ? '/phút' : ded.calculation_type === 'per_occurrence' ? '/lần' : '/tháng' }}
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <button type="button" class="text-gray-400 hover:text-red-500" @click="removeDeduction(i)">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="text-sm font-medium text-blue-600 hover:text-blue-700" @click="addDeduction">+ Thêm giảm trừ</button>
                            <p class="text-xs text-gray-400 mt-2">
                                <strong>Theo số phút:</strong> Số phút đi muộn/về sớm được tính tự động từ chấm công. Giảm trừ = Số tiền × Tổng số phút.<br>
                                <strong>Theo lần:</strong> Giảm trừ = Số tiền × Số lần đi muộn/về sớm trong kỳ.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="border-t border-gray-100 bg-gray-50 px-6 py-4">
                    <div class="flex items-center justify-end gap-3">
                        <button type="button" class="rounded-md border border-gray-300 px-5 py-2 text-sm font-medium text-gray-700 transition hover:bg-white" @click="closeTemplateEditor">
                            Bỏ qua
                        </button>
                        <button type="button" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-60" :disabled="templateSaving" @click="saveTemplate">
                            {{ templateSaving ? 'Đang lưu...' : 'Lưu' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== THIẾT LẬP LƯƠNG MODAL ===== -->
        <div v-if="showSalarySetup" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="flex max-h-[92vh] w-full max-w-7xl flex-col rounded-xl bg-white shadow-2xl">
                <!-- Header -->
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">Thiết lập lương</h2>
                    <button type="button" class="text-gray-400 transition hover:text-gray-600" @click="showSalarySetup = false">✕</button>
                </div>

                <!-- Filters -->
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

                <!-- Table -->
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
                                    <!-- Lương chính -->
                                    <td class="px-3 py-3">
                                        <button v-if="getSalarySummary(emp)" type="button" class="text-left text-sm text-blue-600 hover:text-blue-700 hover:underline" @click="openCellEditor(emp, 'salary')">
                                            <div>{{ getSalarySummary(emp) }}</div>
                                            <div class="text-xs text-gray-500">{{ getSalarySubtext(emp) }}</div>
                                        </button>
                                        <button v-else type="button" class="text-xl text-gray-400 hover:text-blue-500" @click="openCellEditor(emp, 'salary')">+</button>
                                    </td>
                                    <!-- Làm thêm -->
                                    <td class="px-3 py-3">
                                        <button v-if="getOvertimeSummary(emp)" type="button" class="text-left text-sm text-blue-600 hover:text-blue-700 hover:underline" @click="openCellEditor(emp, 'overtime')">
                                            {{ getOvertimeSummary(emp) }}
                                        </button>
                                        <button v-else type="button" class="text-xl text-gray-400 hover:text-blue-500" @click="openCellEditor(emp, 'overtime')">+</button>
                                    </td>
                                    <!-- Thưởng -->
                                    <td class="px-3 py-3">
                                        <button v-if="getBonusSummary(emp)" type="button" class="text-left text-sm text-blue-600 hover:text-blue-700 hover:underline" @click="openCellEditor(emp, 'bonus')">
                                            {{ getBonusSummary(emp) }}
                                        </button>
                                        <button v-else type="button" class="text-xl text-gray-400 hover:text-blue-500" @click="openCellEditor(emp, 'bonus')">+</button>
                                    </td>
                                    <!-- Hoa hồng -->
                                    <td class="px-3 py-3">
                                        <button v-if="getCommissionSummary(emp)" type="button" class="text-left text-sm text-blue-600 hover:text-blue-700 hover:underline" @click="openCellEditor(emp, 'commission')">
                                            {{ getCommissionSummary(emp) }}
                                        </button>
                                        <button v-else type="button" class="text-xl text-gray-400 hover:text-blue-500" @click="openCellEditor(emp, 'commission')">+</button>
                                    </td>
                                    <!-- Phụ cấp -->
                                    <td class="px-3 py-3">
                                        <button v-if="getAllowanceSummary(emp)" type="button" class="text-left text-sm text-blue-600 hover:text-blue-700 hover:underline" @click="openCellEditor(emp, 'allowance')">
                                            {{ getAllowanceSummary(emp) }}
                                        </button>
                                        <button v-else type="button" class="text-xl text-gray-400 hover:text-blue-500" @click="openCellEditor(emp, 'allowance')">+</button>
                                    </td>
                                    <!-- Giảm trừ -->
                                    <td class="px-3 py-3">
                                        <button v-if="getDeductionSummary(emp)" type="button" class="text-left text-sm text-blue-600 hover:text-blue-700 hover:underline" @click="openCellEditor(emp, 'deduction')">
                                            {{ getDeductionSummary(emp) }}
                                        </button>
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

                <!-- Footer -->
                <div class="border-t border-gray-100 bg-gray-50 px-6 py-4 text-right">
                    <button type="button" class="rounded-md bg-blue-600 px-6 py-2 text-sm font-medium text-white transition hover:bg-blue-700" @click="showSalarySetup = false">
                        Xong
                    </button>
                </div>
            </div>
        </div>

        <!-- ===== CELL EDITOR MODAL (overlay on top of salary setup) ===== -->
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
                    <!-- Salary section -->
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
                            <CurrencyInput v-model="cellForm.base_salary" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500" />
                        </div>
                    </template>

                    <!-- Overtime section -->
                    <template v-if="editingCell.section === 'overtime'">
                        <div class="flex items-center gap-3">
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input v-model="cellForm.has_overtime" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                                Bật tính lương làm thêm
                            </label>
                        </div>
                        <div v-if="cellForm.has_overtime">
                            <label class="mb-1 block text-sm font-medium text-gray-700">Hệ số lương OT (%)</label>
                            <input v-model.number="cellForm.overtime_rate" type="number" min="100" max="500" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500" />
                        </div>
                    </template>

                    <!-- Bonus section -->
                    <template v-if="editingCell.section === 'bonus'">
                        <div v-for="(b, i) in cellForm.custom_bonuses" :key="i" class="flex items-start gap-2 rounded-lg border border-gray-200 p-3">
                            <div class="flex-1 space-y-2">
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="text-xs text-gray-500">Loại hình</label>
                                        <select v-model="b.role_type" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                                            <option v-for="r in roleTypes" :key="r.value" :value="r.value">{{ r.label }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500">Doanh thu từ</label>
                                        <CurrencyInput v-model="b.revenue_from" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <CurrencyInput v-if="!b.bonus_is_percentage" v-model="b.bonus_value" class="w-24 rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                    <input v-else v-model.number="b.bonus_value" type="number" min="0" class="w-24 rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                    <select v-model="b.bonus_is_percentage" class="rounded border border-gray-300 px-2 py-1.5 text-xs">
                                        <option :value="true">%</option>
                                        <option :value="false">Cố định</option>
                                    </select>
                                </div>
                            </div>
                            <button type="button" class="mt-1 text-gray-400 hover:text-red-500" @click="cellForm.custom_bonuses.splice(i, 1)">✕</button>
                        </div>
                        <button type="button" class="text-sm text-blue-600 hover:text-blue-700" @click="cellForm.custom_bonuses.push({ role_type: 'sales', revenue_from: 0, bonus_value: 0, bonus_is_percentage: true })">+ Thêm mức thưởng</button>
                    </template>

                    <!-- Commission section -->
                    <template v-if="editingCell.section === 'commission'">
                        <div v-for="(c, i) in cellForm.custom_commissions" :key="i" class="flex items-start gap-2 rounded-lg border border-gray-200 p-3">
                            <div class="flex-1 space-y-2">
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="text-xs text-gray-500">Loại hình</label>
                                        <select v-model="c.role_type" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                                            <option v-for="r in roleTypes" :key="r.value" :value="r.value">{{ r.label }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500">Doanh thu từ</label>
                                        <CurrencyInput v-model="c.revenue_from" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <CurrencyInput v-if="!c.commission_is_percentage" v-model="c.commission_value" class="w-24 rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                    <input v-else v-model.number="c.commission_value" type="number" min="0" class="w-24 rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                    <select v-model="c.commission_is_percentage" class="rounded border border-gray-300 px-2 py-1.5 text-xs">
                                        <option :value="true">%</option>
                                        <option :value="false">Cố định</option>
                                    </select>
                                </div>
                            </div>
                            <button type="button" class="mt-1 text-gray-400 hover:text-red-500" @click="cellForm.custom_commissions.splice(i, 1)">✕</button>
                        </div>
                        <button type="button" class="text-sm text-blue-600 hover:text-blue-700" @click="cellForm.custom_commissions.push({ role_type: 'sales', revenue_from: 0, commission_table_id: null, commission_value: 0, commission_is_percentage: false })">+ Thêm hoa hồng</button>
                    </template>

                    <!-- Allowance section -->
                    <template v-if="editingCell.section === 'allowance'">
                        <div v-for="(a, i) in cellForm.custom_allowances" :key="i" class="flex items-start gap-2 rounded-lg border border-gray-200 p-3">
                            <div class="flex-1 space-y-2">
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="text-xs text-gray-500">Tên phụ cấp</label>
                                        <select v-model="a.name" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                                            <option value="">Chọn loại</option>
                                            <option v-for="n in allowanceNames" :key="n.value" :value="n.value">{{ n.label }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500">Loại phụ cấp</label>
                                        <select v-model="a.allowance_type" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                                            <option v-for="opt in allowanceTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="text-xs text-gray-500">Số tiền</label>
                                    <CurrencyInput v-if="a.allowance_type !== 'percentage'" v-model="a.amount" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                    <input v-else v-model.number="a.amount" type="number" min="0" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                </div>
                            </div>
                            <button type="button" class="mt-1 text-gray-400 hover:text-red-500" @click="cellForm.custom_allowances.splice(i, 1)">✕</button>
                        </div>
                        <button type="button" class="text-sm text-blue-600 hover:text-blue-700" @click="cellForm.custom_allowances.push({ name: '', allowance_type: 'fixed_per_day', amount: 0 })">+ Thêm phụ cấp</button>
                    </template>

                    <!-- Deduction section -->
                    <template v-if="editingCell.section === 'deduction'">
                        <div v-for="(d, i) in cellForm.custom_deductions" :key="i" class="flex items-start gap-2 rounded-lg border border-gray-200 p-3">
                            <div class="flex-1 space-y-2">
                                <div>
                                    <label class="text-xs text-gray-500">Tên giảm trừ</label>
                                    <input v-model="d.name" type="text" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" placeholder="VD: BHXH, thuế TNCN" />
                                </div>
                                <div>
                                    <label class="text-xs text-gray-500">Số tiền</label>
                                    <CurrencyInput v-model="d.amount" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm text-right" />
                                </div>
                            </div>
                            <button type="button" class="mt-1 text-gray-400 hover:text-red-500" @click="cellForm.custom_deductions.splice(i, 1)">✕</button>
                        </div>
                        <button type="button" class="text-sm text-blue-600 hover:text-blue-700" @click="cellForm.custom_deductions.push({ name: '', amount: 0 })">+ Thêm giảm trừ</button>
                    </template>
                </div>

                <!-- Footer -->
                <div class="flex items-center justify-end gap-3 border-t border-gray-100 bg-gray-50 px-6 py-4">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-white" @click="closeCellEditor">Bỏ qua</button>
                    <button type="button" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60" :disabled="cellSaving" @click="saveCellEdit">
                        {{ cellSaving ? 'Đang lưu...' : 'Lưu' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
