<script setup>
import { formatVND as formatCurrency } from '@/utils/money';
import { ref, watch, reactive, computed } from "vue";
import { Head, router, Link, useForm } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import SortableHeader from "@/Components/SortableHeader.vue";
import MoneyInput from "@/Components/MoneyInput.vue";
import EmployeeSalaryLedgerPanel from "@/Components/EmployeeSalaryLedgerPanel.vue";
import { usePermission } from "@/composables/usePermission";
import axios from "axios";

const props = defineProps({
    employees: Object,
    branches: Array,
    departments: Array,
    jobTitles: Array,
    salaryTemplates: { type: Array, default: () => [] },
    filters: Object,
});
const { can } = usePermission();

const search = ref(props.filters?.search || "");
const sortBy = ref(props.filters?.sort_by || "");
const sortDirection = ref(props.filters?.sort_direction || "");
const expandedRows = ref([]);
const expandedLedgers = reactive({});

let searchTimeout;
watch(search, (value) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            "/employees",
            { search: value, sort_by: sortBy.value || undefined, sort_direction: sortDirection.value || undefined },
            {
                preserveState: true,
                replace: true,
            },
        );
    }, 500);
});

const handleSort = (field, direction) => {
    sortBy.value = field;
    sortDirection.value = direction;
    router.get(
        "/employees",
        { search: search.value || undefined, sort_by: field || undefined, sort_direction: direction || undefined },
        { preserveState: true, replace: true },
    );
};

const loadExpandedLedger = async (employee, force = false, page = 1) => {
    if (!can("payroll.ledger.view")) return;

    const current = expandedLedgers[employee.id];
    if (current?.loading || (current?.loaded && !force)) return;

    expandedLedgers[employee.id] = {
        data: current?.data || { data: [] },
        summary: current?.summary || {
            current_balance: Number(employee.salary_balance_cache || 0),
            total_increase: 0,
            total_decrease: 0,
            entry_count: 0,
        },
        loading: true,
        loaded: false,
        error: false,
    };

    try {
        const response = await axios.get(`/api/employees/${employee.id}/salary-ledger`, {
            params: { per_page: 20, page },
        });
        expandedLedgers[employee.id] = {
            data: response.data.data,
            summary: response.data.summary,
            loading: false,
            loaded: true,
            error: false,
        };
    } catch {
        expandedLedgers[employee.id] = {
            ...expandedLedgers[employee.id],
            loading: false,
            loaded: false,
            error: true,
        };
    }
};

const toggleExpand = (employee) => {
    const employeeId = employee.id;
    const index = expandedRows.value.indexOf(employeeId);
    if (index > -1) {
        expandedRows.value.splice(index, 1);
    } else {
        expandedRows.value.push(employeeId);
        loadExpandedLedger(employee);
    }
};

const isExpanded = (employeeId) => {
    return expandedRows.value.includes(employeeId);
};

const retryExpandedLedger = (employee) => loadExpandedLedger(employee, true);
const changeExpandedLedgerPage = (employee, page) => loadExpandedLedger(employee, true, page);
const exportExpandedLedger = (employee) => {
    window.location.href = `/api/employees/${employee.id}/salary-ledger/export`;
};

// Modal form state
const showCreateModal = ref(false);
const activeTab = ref("info"); // info | salary | ledger

const form = useForm({
    id: null,
    code: "",
    attendance_code: "",
    name: "",
    phone: "",
    email: "",
    cccd: "",
    branch_id: null,
    department_id: null,
    job_title_id: null,
    notes: "",
    is_active: true,
});

const openCreateModal = () => {
    form.reset();
    form.clearErrors();
    form.id = null;
    activeTab.value = "info";
    showCreateModal.value = true;
};

const openEditModal = (employee) => {
    form.reset();
    form.clearErrors();
    form.id = employee.id;
    form.code = employee.code;
    form.attendance_code = employee.attendance_code || "";
    form.name = employee.name;
    form.phone = employee.phone;
    form.email = employee.email;
    form.cccd = employee.cccd;
    form.branch_id = employee.branch_id;
    form.department_id = employee.department_id;
    form.job_title_id = employee.job_title_id;
    form.notes = employee.notes;
    form.is_active = employee.is_active;

    activeTab.value = "info";
    showCreateModal.value = true;

    // Load salary settings
    loadSalarySetting(employee.id);
};

const submit = () => {
    if (form.id) {
        form.put(`/employees/${form.id}`, {
            onSuccess: () => {
                // Also save salary settings if editing
                saveSalarySetting(form.id);
                showCreateModal.value = false;
                form.reset();
            },
        });
    } else {
        form.post("/employees", {
            onSuccess: (page) => {
                const newId = page.props?.flash?.new_employee_id;
                if (newId) saveSalarySetting(newId);
                showCreateModal.value = false;
                form.reset();
            },
        });
    }
};

const deleteEmployee = () => {
    if (!form.id) return;
    if (!confirm('Bạn có chắc muốn xóa nhân viên này? Thao tác này không thể hoàn tác.')) return;
    router.delete(`/employees/${form.id}`, {
        onSuccess: () => {
            showCreateModal.value = false;
            form.reset();
        },
    });
};

// Employee salary debt/advance ledger
const ledgerLoading = ref(false);
const ledgerData = ref({ data: [], current_page: 1, last_page: 1 });
const ledgerSummary = ref({
    opening_balance: 0,
    total_increase: 0,
    total_decrease: 0,
    net_change: 0,
    current_balance: 0,
});
const filteredLedgerSummary = ref({ filtered_increase: 0, filtered_decrease: 0, filtered_net_change: 0 });
const ledgerFilters = reactive({ from_date: "", to_date: "", type: "", status: "", branch_id: "", keyword: "" });
const ledgerDetail = ref(null);
const showLedgerDetail = ref(false);
const advances = ref({ data: [], current_page: 1, last_page: 1 });
const salaryPaymentFlow = reactive({
    show: false,
    loading: false,
    submitting: false,
    mode: "salary_payment",
    preview: null,
    rows: [],
    amount: 0,
    paid_at: new Date().toISOString().slice(0, 16),
    advanced_at: new Date().toISOString().slice(0, 16),
    payment_method: "cash",
    note: "",
    idempotency_key: "",
});
const showAdjustmentModal = ref(false);
const adjustmentSaving = ref(false);
const adjustmentForm = reactive({
    type: "adjustment_increase",
    amount: 0,
    event_at: new Date().toISOString().slice(0, 16),
    reason: "",
    note: "",
    override_reason: "",
});
const advanceCancel = reactive({ show: false, target: null, reason: "", saving: false });
const advanceForm = reactive({
    amount: 0,
    advance_date: new Date().toISOString().slice(0, 16),
    payment_method: "cash",
    branch_id: null,
    note: "",
});
const advanceSaving = ref(false);

const ledgerRows = computed(() => ledgerData.value?.data || []);
const ledgerTypeLabels = {
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
const ledgerTypeLabel = (entry) => ledgerTypeLabels[entry.type] || "Khác";
const ledgerStatusLabel = (entry) => {
    if (entry.type === "cancel_reverse") return "Dòng đảo";
    if (entry.status === "reversed") return "Đã đảo";
    return "Hợp lệ";
};

const loadLedger = async (page = 1) => {
    if (!form.id || !can("payroll.ledger.view")) return;
    ledgerLoading.value = true;
    try {
        const response = await axios.get(`/api/employees/${form.id}/salary-ledger`, {
            params: {
                page,
                from_date: ledgerFilters.from_date || undefined,
                to_date: ledgerFilters.to_date || undefined,
                type: ledgerFilters.type || undefined,
                status: ledgerFilters.status || undefined,
                branch_id: ledgerFilters.branch_id || undefined,
                keyword: ledgerFilters.keyword || undefined,
            },
        });
        ledgerData.value = response.data.data;
        ledgerSummary.value = response.data.summary;
        filteredLedgerSummary.value = response.data.filtered_summary;
    } finally {
        ledgerLoading.value = false;
    }
};

const loadAdvances = async (page = 1) => {
    if (!form.id || !can("payroll.ledger.view")) return;
    const response = await axios.get(`/api/employees/${form.id}/salary-advances`, { params: { page } });
    advances.value = response.data;
};

const newIdempotencyKey = (prefix) => `${prefix}:${form.id}:${Date.now()}:${Math.random().toString(16).slice(2)}`;

const openSalaryPaymentFlow = async () => {
    if (!form.id || salaryPaymentFlow.loading || salaryPaymentFlow.submitting) return;
    salaryPaymentFlow.loading = true;
    try {
        const response = await axios.get(`/api/employees/${form.id}/salary-payment-preview`);
        const preview = response.data;
        salaryPaymentFlow.preview = preview;
        salaryPaymentFlow.mode = preview.mode;
        salaryPaymentFlow.payment_method = "cash";
        salaryPaymentFlow.paid_at = new Date().toISOString().slice(0, 16);
        salaryPaymentFlow.advanced_at = new Date().toISOString().slice(0, 16);
        salaryPaymentFlow.note = preview.mode === "salary_payment"
            ? "Thanh toán từ chi tiết nhân viên"
            : "Tạm ứng từ chi tiết nhân viên";
        salaryPaymentFlow.idempotency_key = newIdempotencyKey(preview.mode === "salary_payment" ? "employee-payment-ui" : "employee-advance-ui");
        salaryPaymentFlow.rows = (preview.payslips || []).map((slip) => ({
            ...slip,
            selected: true,
            amount: Number(slip.remaining_amount || 0),
        }));
        salaryPaymentFlow.amount = 0;
        salaryPaymentFlow.show = true;
    } catch (error) {
        alert(error.response?.data?.message || "Không thể tải thông tin thanh toán lương.");
    } finally {
        salaryPaymentFlow.loading = false;
    }
};

const selectedSalaryPaymentRows = computed(() => salaryPaymentFlow.rows.filter((row) => row.selected));
const salaryPaymentTotal = computed(() => selectedSalaryPaymentRows.value.reduce((sum, row) => sum + Number(row.amount || 0), 0));
const salaryPaymentInvalid = computed(() => {
    if (salaryPaymentFlow.mode === "salary_advance") {
        return Number(salaryPaymentFlow.amount) <= 0;
    }
    return selectedSalaryPaymentRows.value.length === 0
        || selectedSalaryPaymentRows.value.some((row) => Number(row.amount) <= 0 || Number(row.amount) > Number(row.remaining_amount))
        || salaryPaymentTotal.value <= 0;
});

const submitSalaryPaymentFlow = async () => {
    if (!form.id || salaryPaymentFlow.submitting || salaryPaymentInvalid.value) return;
    salaryPaymentFlow.submitting = true;
    try {
        const payload = salaryPaymentFlow.mode === "salary_payment"
            ? {
                mode: "salary_payment",
                payment_method: salaryPaymentFlow.payment_method,
                paid_at: salaryPaymentFlow.paid_at,
                note: salaryPaymentFlow.note,
                items: selectedSalaryPaymentRows.value.map((row) => ({
                    payslip_id: row.id,
                    amount: Number(row.amount),
                })),
            }
            : {
                mode: "salary_advance",
                payment_method: salaryPaymentFlow.payment_method,
                advanced_at: salaryPaymentFlow.advanced_at,
                note: salaryPaymentFlow.note,
                amount: Number(salaryPaymentFlow.amount),
            };
        await axios.post(`/api/employees/${form.id}/salary-payments`, payload, {
            headers: { "Idempotency-Key": salaryPaymentFlow.idempotency_key },
        });
        salaryPaymentFlow.show = false;
        await Promise.all([loadLedger(), loadAdvances()]);
        router.reload({ only: ["employees"] });
    } catch (error) {
        alert(error.response?.data?.message || "Không thể tạo phiếu chi.");
    } finally {
        salaryPaymentFlow.submitting = false;
    }
};

const openLedgerEntry = async (entry) => {
    if (entry.type === "payroll_accrual" && entry.paysheet_id) {
        window.open(`/employees/paysheets/${entry.paysheet_id}/edit?payslip_id=${entry.payslip_id || ""}`, "_blank");
        return;
    }
    const response = await axios.get(`/api/employee-salary-ledger-entries/${entry.id}`);
    ledgerDetail.value = response.data;
    showLedgerDetail.value = true;
};

const exportLedger = () => {
    const params = new URLSearchParams(Object.fromEntries(
        Object.entries(ledgerFilters).filter(([, value]) => value !== "")
    ));
    window.location.href = `/api/employees/${form.id}/salary-ledger/export?${params}`;
};

const submitAdjustment = async () => {
    if (adjustmentSaving.value) return;
    adjustmentSaving.value = true;
    try {
        await axios.post(`/api/employees/${form.id}/salary-ledger/adjust`, {
            type: adjustmentForm.type,
            amount: Number(adjustmentForm.amount),
            event_at: adjustmentForm.event_at,
            reason: adjustmentForm.reason.trim(),
            note: adjustmentForm.note,
            override_reason: adjustmentForm.override_reason || undefined,
        }, { headers: { "Idempotency-Key": `adjustment-ui:${form.id}:${Date.now()}` } });
        showAdjustmentModal.value = false;
        adjustmentForm.amount = 0;
        adjustmentForm.reason = "";
        adjustmentForm.note = "";
        await loadLedger();
        router.reload({ only: ["employees"] });
    } catch (error) {
        alert(error.response?.data?.message || "Không thể tạo điều chỉnh.");
    } finally {
        adjustmentSaving.value = false;
    }
};

const openAdvanceCancel = (advance) => {
    advanceCancel.target = advance;
    advanceCancel.reason = "";
    advanceCancel.show = true;
};

const cancelAdvance = async () => {
    if (advanceCancel.reason.trim().length < 10) return;
    advanceCancel.saving = true;
    try {
        await axios.post(`/api/salary-advances/${advanceCancel.target.id}/cancel`, {
            reason: advanceCancel.reason.trim(),
            cancel_date: new Date().toISOString().slice(0, 16),
        });
        advanceCancel.show = false;
        await Promise.all([loadLedger(), loadAdvances()]);
        router.reload({ only: ["employees"] });
    } catch (error) {
        alert(error.response?.data?.message || "Không thể hủy tạm ứng.");
    } finally {
        advanceCancel.saving = false;
    }
};

const createAdvance = async () => {
    if (!form.id || advanceSaving.value) return;
    advanceSaving.value = true;
    try {
        await axios.post(`/api/employees/${form.id}/salary-advances`, {
            ...advanceForm,
            amount: Number(advanceForm.amount),
            branch_id: Number(advanceForm.branch_id || form.branch_id),
        }, {
            headers: { "Idempotency-Key": `advance-ui:${form.id}:${Date.now()}` },
        });
        advanceForm.amount = 0;
        advanceForm.note = "";
        await loadLedger();
        await loadAdvances();
        router.reload({ only: ["employees"] });
    } catch (error) {
        alert(error.response?.data?.message || "Không thể tạo tạm ứng.");
    } finally {
        advanceSaving.value = false;
    }
};

watch(activeTab, (tab) => {
    if (tab === "ledger") {
        advanceForm.branch_id = form.branch_id;
        loadLedger();
        loadAdvances();
    }
});

// ─── Salary tab state ───
const salaryForm = reactive({
    salary_type: 'fixed',
    base_salary: 0,
    salary_template_id: null,
    advanced_salary: false,
    holiday_rate: 200,
    tet_rate: 300,
    has_overtime: false,
    overtime_rate: 150,
    saturday_ot_rate: 150,
    sunday_ot_rate: 150,
    rest_day_ot_rate: 150,
    holiday_ot_rate: 150,
    // Per-employee overrides
    has_bonus: false,
    has_commission: false,
    has_allowance: false,
    has_deduction: false,
    bonus_type: 'personal_revenue',
    bonus_calculation: 'total_revenue',
    custom_bonuses: [],
    custom_commissions: [],
    custom_allowances: [],
    custom_deductions: [],
});
const selectedTemplate = ref(null);
const commissionTables = ref([]);
const salaryLoading = ref(false);
const expandedSections = reactive({
    bonus: false,
    commission: false,
    allowance: false,
    deduction: false,
});

// Auto-expand when checkbox is checked, collapse when unchecked
watch(() => salaryForm.has_bonus, v => { expandedSections.bonus = v });
watch(() => salaryForm.has_commission, v => { expandedSections.commission = v });
watch(() => salaryForm.has_allowance, v => { expandedSections.allowance = v });
watch(() => salaryForm.has_deduction, v => { expandedSections.deduction = v });

const resetSalaryForm = () => {
    salaryForm.salary_type = 'fixed';
    salaryForm.base_salary = 0;
    salaryForm.salary_template_id = null;
    salaryForm.advanced_salary = false;
    salaryForm.holiday_rate = 200;
    salaryForm.tet_rate = 300;
    salaryForm.has_overtime = false;
    salaryForm.overtime_rate = 150;
    salaryForm.saturday_ot_rate = 150;
    salaryForm.sunday_ot_rate = 150;
    salaryForm.rest_day_ot_rate = 150;
    salaryForm.holiday_ot_rate = 150;
    salaryForm.has_bonus = false;
    salaryForm.has_commission = false;
    salaryForm.has_allowance = false;
    salaryForm.has_deduction = false;
    salaryForm.bonus_type = 'personal_revenue';
    salaryForm.bonus_calculation = 'total_revenue';
    salaryForm.custom_bonuses = [];
    salaryForm.custom_commissions = [];
    salaryForm.custom_allowances = [];
    salaryForm.custom_deductions = [];
    selectedTemplate.value = null;
    Object.keys(expandedSections).forEach(k => expandedSections[k] = false);
};

const copyTemplateToForm = (tpl) => {
    salaryForm.has_bonus = Boolean(tpl.has_bonus);
    salaryForm.has_commission = Boolean(tpl.has_commission);
    salaryForm.has_allowance = Boolean(tpl.has_allowance);
    salaryForm.has_deduction = Boolean(tpl.has_deduction);
    salaryForm.bonus_type = tpl.bonus_type || 'personal_revenue';
    salaryForm.bonus_calculation = tpl.bonus_calculation || 'total_revenue';
    salaryForm.custom_bonuses = (tpl.bonuses || []).map(b => ({
        role_type: b.role_type || 'employee',
        revenue_from: b.revenue_from || 0,
        bonus_value: b.bonus_value || 0,
        bonus_is_percentage: Boolean(b.bonus_is_percentage),
    }));
    salaryForm.custom_commissions = (tpl.commissions || []).map(c => ({
        role_type: c.role_type || 'employee',
        revenue_from: c.revenue_from || 0,
        commission_table_id: c.commission_table_id || null,
        commission_value: c.commission_value || 0,
        commission_is_percentage: Boolean(c.commission_is_percentage),
    }));
    salaryForm.custom_allowances = (tpl.allowances || []).map(a => ({
        name: a.name || '',
        allowance_type: a.allowance_type || 'fixed_per_month',
        amount: a.amount || 0,
    }));
    salaryForm.custom_deductions = (tpl.deductions || []).map(d => ({
        name: d.name || '',
        deduction_category: d.deduction_category || '',
        calculation_type: d.calculation_type || 'fixed_per_month',
        amount: d.amount || 0,
    }));
    if (salaryForm.has_bonus) expandedSections.bonus = true;
    if (salaryForm.has_commission) expandedSections.commission = true;
    if (salaryForm.has_allowance) expandedSections.allowance = true;
    if (salaryForm.has_deduction) expandedSections.deduction = true;
};

const loadCommissionTables = async () => {
    try {
        const res = await axios.get('/api/salary-templates/commission-tables');
        commissionTables.value = res.data?.data || res.data || [];
    } catch (e) { commissionTables.value = []; }
};

const loadSalarySetting = async (employeeId) => {
    salaryLoading.value = true;
    resetSalaryForm();
    loadCommissionTables();
    try {
        const res = await axios.get(`/api/employee-salary-settings/${employeeId}`);
        const setting = res.data?.data;
        if (setting) {
            salaryForm.salary_type = setting.salary_type || 'fixed';
            salaryForm.base_salary = setting.base_salary || 0;
            salaryForm.salary_template_id = setting.salary_template_id;
            salaryForm.advanced_salary = Boolean(setting.advanced_salary);
            salaryForm.holiday_rate = setting.holiday_rate ?? 200;
            salaryForm.tet_rate = setting.tet_rate ?? 300;
            salaryForm.has_overtime = Boolean(setting.has_overtime);
            salaryForm.overtime_rate = setting.overtime_rate ?? 150;
            salaryForm.saturday_ot_rate = setting.saturday_ot_rate ?? 150;
            salaryForm.sunday_ot_rate = setting.sunday_ot_rate ?? 150;
            salaryForm.rest_day_ot_rate = setting.rest_day_ot_rate ?? 150;
            salaryForm.holiday_ot_rate = setting.holiday_ot_rate ?? 150;

            // Per-employee overrides take priority, else copy from template
            const hasCustom = setting.custom_bonuses || setting.custom_commissions || setting.custom_allowances || setting.custom_deductions;
            if (hasCustom) {
                salaryForm.has_bonus = Boolean(setting.has_bonus);
                salaryForm.has_commission = Boolean(setting.has_commission);
                salaryForm.has_allowance = Boolean(setting.has_allowance);
                salaryForm.has_deduction = Boolean(setting.has_deduction);
                salaryForm.bonus_type = setting.bonus_type || 'personal_revenue';
                salaryForm.bonus_calculation = setting.bonus_calculation || 'total_revenue';
                salaryForm.custom_bonuses = (setting.custom_bonuses || []).map(b => ({ ...b }));
                salaryForm.custom_commissions = (setting.custom_commissions || []).map(c => ({ ...c }));
                salaryForm.custom_allowances = (setting.custom_allowances || []).map(a => ({ ...a }));
                salaryForm.custom_deductions = (setting.custom_deductions || []).map(d => ({ name: d.name || '', deduction_category: d.deduction_category || '', calculation_type: d.calculation_type || 'fixed_per_month', amount: d.amount || 0 }));
                if (salaryForm.has_bonus) expandedSections.bonus = true;
                if (salaryForm.has_commission) expandedSections.commission = true;
                if (salaryForm.has_allowance) expandedSections.allowance = true;
                if (salaryForm.has_deduction) expandedSections.deduction = true;
            } else if (setting.template) {
                copyTemplateToForm(setting.template);
            }
            if (setting.template) {
                selectedTemplate.value = setting.template;
            }
        }
    } catch (e) {
        // No settings yet — keep defaults
    } finally {
        salaryLoading.value = false;
    }
};

const onTemplateChange = async (templateId) => {
    salaryForm.salary_template_id = templateId || null;
    selectedTemplate.value = null;
    // Reset per-employee sections
    salaryForm.has_bonus = false;
    salaryForm.has_commission = false;
    salaryForm.has_allowance = false;
    salaryForm.has_deduction = false;
    salaryForm.custom_bonuses = [];
    salaryForm.custom_commissions = [];
    salaryForm.custom_allowances = [];
    salaryForm.custom_deductions = [];
    Object.keys(expandedSections).forEach(k => expandedSections[k] = false);
    if (!templateId) return;
    try {
        const res = await axios.get(`/api/salary-templates/${templateId}`);
        const tpl = res.data?.data || res.data;
        if (tpl) {
            selectedTemplate.value = tpl;
            copyTemplateToForm(tpl);
        }
    } catch (e) {
        // ignore
    }
};

const saveSalarySetting = async (employeeId) => {
    try {
        await axios.post(`/api/employee-salary-settings/${employeeId}`, {
            salary_type: salaryForm.salary_type,
            base_salary: Number(salaryForm.base_salary) || 0,
            salary_template_id: salaryForm.salary_template_id,
            advanced_salary: salaryForm.advanced_salary,
            holiday_rate: salaryForm.holiday_rate,
            tet_rate: salaryForm.tet_rate,
            has_overtime: salaryForm.has_overtime,
            overtime_rate: salaryForm.overtime_rate,
            saturday_ot_rate: salaryForm.saturday_ot_rate,
            sunday_ot_rate: salaryForm.sunday_ot_rate,
            rest_day_ot_rate: salaryForm.rest_day_ot_rate,
            holiday_ot_rate: salaryForm.holiday_ot_rate,
            has_bonus: salaryForm.has_bonus,
            has_commission: salaryForm.has_commission,
            has_allowance: salaryForm.has_allowance,
            has_deduction: salaryForm.has_deduction,
            bonus_type: salaryForm.bonus_type,
            bonus_calculation: salaryForm.bonus_calculation,
            custom_bonuses: salaryForm.custom_bonuses.map(b => ({
                ...b,
                revenue_from: Number(b.revenue_from) || 0,
                bonus_value: Number(b.bonus_value) || 0,
            })),
            custom_commissions: salaryForm.custom_commissions.map(c => ({
                ...c,
                revenue_from: Number(c.revenue_from) || 0,
                commission_value: Number(c.commission_value) || 0,
            })),
            custom_allowances: salaryForm.custom_allowances.map(a => ({
                ...a,
                amount: Number(a.amount) || 0,
            })),
            custom_deductions: salaryForm.custom_deductions.map(d => ({
                ...d,
                amount: Number(d.amount) || 0,
            })),
        });
    } catch (e) {
        console.error('Failed to save salary settings', e);
    }
};



const bonusTypeLabel = (type) => {
    const map = {
        personal_revenue: 'Theo doanh thu cá nhân',
        branch_revenue: 'Theo doanh thu chi nhánh',
        personal_gross_profit: 'Theo lợi nhuận gộp cá nhân',
    };
    return map[type] || type;
};

const bonusCalcLabel = (calc) => {
    const map = {
        percent: 'Phần trăm (%)',
        fixed: 'Số tiền cố định',
    };
    return map[calc] || calc;
};
</script>

<template>
    <Head title="Nhân viên - KiotViet Clone" />
    <AppLayout>
        <!-- Sidebar slot -->
        <template #sidebar>
            <!-- Lọc TRẠNG THÁI NHÂN VIÊN -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Trạng thái nhân viên</label
                >
                <div class="space-y-2 text-sm text-gray-700">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="radio"
                            name="is_active"
                            checked
                            class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Đang làm việc
                    </label>
                    <label
                        class="flex items-center gap-2 cursor-pointer text-gray-500"
                    >
                        <input
                            type="radio"
                            name="is_active"
                            class="text-blue-600 focus:ring-blue-500 w-4 h-4"
                        />
                        Đã nghỉ
                    </label>
                </div>
            </div>

            <!-- Lọc CHI NHÁNH LÀM VIỆC -->
            <div class="px-3 py-4 border-b border-gray-200">
                <label class="block text-sm font-bold text-gray-800 mb-2"
                    >Chi nhánh làm việc</label
                >
                <select
                    class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none text-gray-700"
                >
                    <option value="">Chọn chi nhánh</option>
                    <option v-for="br in branches" :key="br.id" :value="br.id">
                        {{ br.name }}
                    </option>
                </select>
            </div>

            <!-- Lọc PHÒNG BAN -->
            <div class="px-3 py-4 border-b border-gray-200">
                <div class="flex justify-between items-center mb-2">
                    <label class="block text-sm font-bold text-gray-800"
                        >Phòng ban</label
                    >
                    <button class="text-gray-400 hover:text-blue-600">
                        <svg
                            class="w-4 h-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 4v16m8-8H4"
                            ></path>
                        </svg>
                    </button>
                </div>
                <select
                    class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none text-gray-500"
                >
                    <option value="">Chọn phòng ban</option>
                    <option
                        v-for="dept in departments"
                        :key="dept.id"
                        :value="dept.id"
                    >
                        {{ dept.name }}
                    </option>
                </select>
            </div>

            <!-- Lọc CHỨC DANH -->
            <div class="px-3 py-4 border-b border-gray-200">
                <div class="flex justify-between items-center mb-2">
                    <label class="block text-sm font-bold text-gray-800"
                        >Chức danh</label
                    >
                    <button class="text-gray-400 hover:text-blue-600">
                        <svg
                            class="w-4 h-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 4v16m8-8H4"
                            ></path>
                        </svg>
                    </button>
                </div>
                <select
                    class="w-full border border-gray-300 rounded p-1.5 text-sm outline-none text-gray-500"
                >
                    <option value="">Chọn chức danh</option>
                    <option v-for="jt in jobTitles" :key="jt.id" :value="jt.id">
                        {{ jt.name }}
                    </option>
                </select>
            </div>
        </template>

        <!-- Main content -->
        <div class="bg-white h-full flex flex-col pt-3">
            <!-- Header Toolbar -->
            <div
                class="flex items-center justify-between px-4 pb-3 border-b border-gray-200"
            >
                <div
                    class="flex items-center gap-4 flex-1 max-w-2xl text-2xl font-bold text-gray-800"
                >
                    Danh sách nhân viên
                </div>

                <div
                    class="relative w-80 ml-auto mr-4 border-b border-gray-300"
                >
                    <svg
                        class="w-4 h-4 absolute left-1 top-1/2 -translate-y-1/2 text-gray-400"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                        ></path>
                    </svg>
                    <input
                        type="text"
                        v-model="search"
                        placeholder="Theo mã, tên nhân viên"
                        class="w-full pl-7 pr-8 py-1.5 focus:outline-none text-sm placeholder-gray-400 bg-transparent"
                    />
                    <svg
                        class="w-4 h-4 absolute right-1 top-1/2 -translate-y-1/2 text-gray-400"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"
                        ></path>
                    </svg>
                </div>

                <div class="flex gap-2 ml-2">
                    <button
                        @click="openCreateModal"
                        class="bg-white text-blue-600 border border-blue-600 px-3 py-1.5 text-sm font-medium rounded flex items-center gap-1 hover:bg-blue-50 transition"
                    >
                        <svg
                            class="w-4 h-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 4v16m8-8H4"
                            ></path></svg
                        >Nhân viên
                    </button>
                    <ExcelButtons
                        export-url="/employees/export"
                        import-url="/employees/import"
                    />
                    <button
                        class="bg-white text-gray-600 border border-gray-300 px-2.5 py-1.5 rounded hover:bg-gray-50"
                    >
                        <svg
                            class="w-4 h-4 text-gray-500"
                            fill="currentColor"
                            viewBox="0 0 16 16"
                        >
                            <path
                                d="M1 2.5A1.5 1.5 0 0 1 2.5 1h3A1.5 1.5 0 0 1 7 2.5v3A1.5 1.5 0 0 1 5.5 7h-3A1.5 1.5 0 0 1 1 5.5zM2.5 2a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5zm6.5.5A1.5 1.5 0 0 1 10.5 1h3A1.5 1.5 0 0 1 15 2.5v3A1.5 1.5 0 0 1 13.5 7h-3A1.5 1.5 0 0 1 9 5.5zm1.5-.5a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5zM1 10.5A1.5 1.5 0 0 1 2.5 9h3A1.5 1.5 0 0 1 7 10.5v3A1.5 1.5 0 0 1 5.5 15h-3A1.5 1.5 0 0 1 1 13.5zm1.5-.5a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5zm6.5.5A1.5 1.5 0 0 1 10.5 9h3a1.5 1.5 0 0 1 1.5 1.5v3a1.5 1.5 0 0 1-1.5 1.5h-3A1.5 1.5 0 0 1 9 13.5zm1.5-.5a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5z"
                            />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="flex-1 overflow-auto bg-[#f8fbff]">
                <table class="w-full text-sm text-left whitespace-nowrap">
                    <thead
                        class="text-[13px] font-bold text-gray-700 bg-[#eef1f8] border-b border-gray-200 sticky top-0 z-10 shadow-sm"
                    >
                        <tr>
                            <th v-if="can('payroll.ledger.view')" class="w-10 px-2 py-3"></th>
                            <th class="px-4 py-3 w-10 text-center">
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-300"
                                />
                            </th>
                            <th class="px-4 py-3 w-12">Ảnh</th>
                            <SortableHeader label="Mã nhân viên" field="code" :current-sort="sortBy" :current-direction="sortDirection" class="px-4 py-3" @sort="handleSort" />
                            <SortableHeader label="Mã chấm công" field="attendance_code" :current-sort="sortBy" :current-direction="sortDirection" class="px-4 py-3" @sort="handleSort" />
                            <SortableHeader label="Tên nhân viên" field="name" :current-sort="sortBy" :current-direction="sortDirection" class="px-4 py-3" @sort="handleSort" />
                            <SortableHeader label="Số điện thoại" field="phone" :current-sort="sortBy" :current-direction="sortDirection" class="px-4 py-3" @sort="handleSort" />
                            <SortableHeader label="Số CMND/CCCD" field="cccd" :current-sort="sortBy" :current-direction="sortDirection" class="px-4 py-3" @sort="handleSort" />
                            <th v-if="can('employee.view_salary_balance')" class="px-4 py-3 text-right">Nợ và tạm ứng</th>
                            <th class="px-4 py-3">Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-gray-800">
                        <tr v-if="employees.data.length === 0">
                            <td
                                :colspan="8 + (can('payroll.ledger.view') ? 1 : 0) + (can('employee.view_salary_balance') ? 1 : 0)"
                                class="px-6 py-12 text-center text-gray-500"
                            >
                                Không tìm thấy nhân viên nào.
                            </td>
                        </tr>
                        <template
                            v-for="employee in employees.data"
                            :key="employee.id"
                        >
                            <!-- Main Row -->
                            <tr
                                @click="openEditModal(employee)"
                                class="hover:bg-blue-50/50 transition-colors cursor-pointer bg-white"
                            >
                                <td v-if="can('payroll.ledger.view')" class="px-2 py-3 text-center" @click.stop>
                                    <button
                                        type="button"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded text-gray-500 hover:bg-blue-50 hover:text-blue-700"
                                        :aria-expanded="isExpanded(employee.id)"
                                        :aria-label="isExpanded(employee.id) ? 'Thu gọn lịch sử nợ và tạm ứng' : 'Mở lịch sử nợ và tạm ứng'"
                                        @click="toggleExpand(employee)"
                                    >
                                        <svg
                                            class="h-4 w-4 transition-transform"
                                            :class="{ 'rotate-90': isExpanded(employee.id) }"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                    </button>
                                </td>
                                <td class="px-4 py-3 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300 text-blue-500 focus:ring-blue-500"
                                    />
                                </td>
                                <td class="px-4 py-3">
                                    <!-- Avatar placeholder -->
                                    <div
                                        class="w-8 h-8 bg-gray-200 rounded text-gray-400 flex items-center justify-center"
                                    >
                                        <svg
                                            class="w-5 h-5"
                                            fill="currentColor"
                                            viewBox="0 0 20 20"
                                        >
                                            <path
                                                fill-rule="evenodd"
                                                d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"
                                                clip-rule="evenodd"
                                            ></path>
                                        </svg>
                                    </div>
                                </td>
                                <td class="px-4 py-3">{{ employee.code }}</td>
                                <td class="px-4 py-3">
                                    {{ employee.attendance_code || "" }}
                                </td>
                                <td
                                    class="px-4 py-3 font-semibold text-gray-800"
                                >
                                    {{ employee.name }}
                                </td>
                                <td class="px-4 py-3">{{ employee.phone }}</td>
                                <td class="px-4 py-3">{{ employee.cccd }}</td>
                                <td v-if="can('employee.view_salary_balance')" class="px-4 py-3 text-right">
                                    <button
                                        v-if="can('payroll.ledger.view')"
                                        type="button"
                                        class="font-semibold text-blue-700 hover:underline"
                                        @click.stop="toggleExpand(employee)"
                                    >
                                        {{ formatCurrency(employee.salary_balance_cache || 0) }}
                                    </button>
                                    <span v-else :class="Number(employee.salary_balance_cache || 0) !== 0 ? 'font-semibold text-gray-800' : 'text-gray-500'">
                                        {{ formatCurrency(employee.salary_balance_cache || 0) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-500">
                                    {{ employee.notes || "" }}
                                </td>
                            </tr>
                            <tr v-if="isExpanded(employee.id)" class="bg-slate-50">
                                <td :colspan="can('employee.view_salary_balance') ? 10 : 9" class="p-0">
                                    <EmployeeSalaryLedgerPanel
                                        :employee="employee"
                                        :ledger="expandedLedgers[employee.id] || { loading: true, data: { data: [] }, summary: {} }"
                                        :can-export="can('payroll.ledger.export')"
                                        @retry="retryExpandedLedger(employee)"
                                        @page="changeExpandedLedgerPage(employee, $event)"
                                        @export="exportExpandedLedger(employee)"
                                    />
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Footer Pagination -->
            <div
                class="flex items-center justify-between px-4 py-2 border-t border-gray-200 bg-white text-sm"
            >
                <div class="text-gray-600">
                    Hiển thị từ
                    <span class="font-bold">{{ employees.from || 0 }}</span> đến
                    <span class="font-bold">{{ employees.to || 0 }}</span> trong
                    tổng số
                    <span class="font-bold">{{ employees.total || 0 }}</span>
                    bản ghi
                </div>
                <!-- Pagination -->
                <div
                    class="flex gap-1"
                    v-if="employees.links && employees.links.length > 3"
                >
                    <template
                        v-for="(link, index) in employees.links"
                        :key="index"
                    >
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="px-2.5 py-1 text-sm border rounded"
                            :class="
                                link.active
                                    ? 'bg-blue-600 text-white border-blue-600'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300'
                            "
                            v-html="link.label"
                        ></Link>
                        <span
                            v-else
                            class="px-2.5 py-1 text-sm border rounded bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed"
                            v-html="link.label"
                        ></span>
                    </template>
                </div>
            </div>
        </div>

        <!-- CREATE/EDIT EMPLOYEE MODAL -->
        <div
            v-if="showCreateModal"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40 pt-10 pb-10"
        >
            <div
                class="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-full overflow-hidden flex flex-col relative text-[13px] text-gray-800"
            >
                <div
                    class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-white shadow-sm z-10 relative"
                >
                    <h2 class="text-xl font-bold text-gray-800">
                        {{
                            form.id
                                ? "Cập nhật nhân viên"
                                : "Thêm mới nhân viên"
                        }}
                    </h2>
                    <button
                        @click="showCreateModal = false"
                        class="text-gray-400 hover:text-gray-600"
                    >
                        <svg
                            class="w-6 h-6"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            ></path>
                        </svg>
                    </button>
                </div>

                <!-- Tabs Control -->
                <div
                    class="flex px-6 border-b border-gray-200 pt-3 relative bg-white z-10"
                >
                    <button
                        @click="activeTab = 'info'"
                        class="px-4 py-2 font-bold text-[14px]"
                        :class="
                            activeTab === 'info'
                                ? 'text-blue-600 border-b-2 border-blue-600'
                                : 'text-gray-500 hover:text-gray-700'
                        "
                    >
                        Thông tin
                    </button>
                    <button
                        @click="activeTab = 'salary'"
                        class="px-4 py-2 font-bold text-[14px]"
                        :class="
                            activeTab === 'salary'
                                ? 'text-blue-600 border-b-2 border-blue-600'
                                : 'text-gray-500 hover:text-gray-700'
                        "
                    >
                        Thiết lập lương
                    </button>
                    <button
                        v-if="form.id && can('payroll.ledger.view')"
                        type="button"
                        @click="activeTab = 'ledger'"
                        class="px-4 py-2 font-bold text-[14px]"
                        :class="activeTab === 'ledger'
                            ? 'text-blue-600 border-b-2 border-blue-600'
                            : 'text-gray-500 hover:text-gray-700'"
                    >
                        Nợ & Tạm ứng
                    </button>
                </div>

                <div
                    class="flex-1 overflow-y-auto px-6 py-6 custom-scrollbar text-[13.5px] bg-[#f8fbff]"
                >
                    <form @submit.prevent="submit" class="space-y-6">
                        <!-- TAB THÔNG TIN -->
                        <div
                            v-show="activeTab === 'info'"
                            class="bg-white border border-gray-200 shadow-sm rounded-lg p-5"
                        >
                            <div
                                class="font-bold text-[15px] mb-4 text-gray-800"
                            >
                                Thông tin khởi tạo
                            </div>
                            <div class="flex gap-8 items-start">
                                <!-- Avatar Circle Upload -->
                                <div
                                    class="w-32 flex flex-col items-center mt-2"
                                >
                                    <div
                                        class="w-28 h-28 rounded border border-dashed border-gray-400 bg-gray-50 flex items-center justify-center flex-col text-gray-500 cursor-pointer hover:bg-gray-100 transition"
                                    >
                                        <svg
                                            class="w-6 h-6 mb-1 text-gray-400"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"
                                            ></path>
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"
                                            ></path>
                                        </svg>
                                    </div>
                                    <div class="font-bold mb-2 mt-2">
                                        Chọn ảnh
                                    </div>
                                </div>

                                <!-- Form Fields -->
                                <div
                                    class="flex-1 grid grid-cols-2 gap-x-6 gap-y-4"
                                >
                                    <!-- Row 1 -->
                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Mã nhân viên</label
                                        >
                                        <input
                                            v-model="form.code"
                                            type="text"
                                            class="w-full border border-gray-300 rounded-md px-3 py-1.5 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none placeholder-gray-400"
                                            placeholder="Mã nhân viên tự động"
                                        />
                                    </div>
                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Tên nhân viên</label
                                        >
                                        <input
                                            v-model="form.name"
                                            type="text"
                                            class="w-full border border-gray-300 rounded-md px-3 py-1.5 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
                                            required
                                        />
                                    </div>

                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Mã chấm công</label
                                        >
                                        <input
                                            v-model="form.attendance_code"
                                            type="text"
                                            class="w-full border border-gray-300 rounded-md px-3 py-1.5 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
                                            placeholder="Từ máy chấm"
                                        />
                                    </div>

                                    <!-- Row 2 -->
                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Số điện thoại</label
                                        >
                                        <input
                                            v-model="form.phone"
                                            type="text"
                                            class="w-full border border-gray-300 rounded-md px-3 py-1.5 focus:border-blue-500 outline-none"
                                        />
                                    </div>
                                    <div>
                                        <label class="block font-semibold mb-1"
                                            >Số CMND/CCCD</label
                                        >
                                        <input
                                            v-model="form.cccd"
                                            type="text"
                                            class="w-full border border-gray-300 rounded-md px-3 py-1.5 focus:border-blue-500 outline-none"
                                        />
                                    </div>

                                    <!-- Row 3 -->
                                    <div class="col-span-2">
                                        <label class="block font-semibold mb-1"
                                            >Chi nhánh làm việc</label
                                        >
                                        <select
                                            v-model="form.branch_id"
                                            class="w-full border border-gray-300 rounded-md px-3 py-2 bg-blue-600 text-white focus:outline-none"
                                        >
                                            <option
                                                v-for="br in branches"
                                                :key="br.id"
                                                :value="br.id"
                                            >
                                                {{ br.name }}
                                                <span v-if="br.id">x</span>
                                            </option>
                                        </select>
                                    </div>

                                    <div
                                        class="col-span-2 pt-2 border-t border-gray-100 mt-2 text-center"
                                    >
                                        <button
                                            type="button"
                                            class="text-blue-600 font-bold hover:underline flex items-center justify-center gap-1 mx-auto"
                                        >
                                            Thêm thông tin
                                            <svg
                                                class="w-4 h-4"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="2"
                                                    d="M19 9l-7 7-7-7"
                                                ></path>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB THIẾT LẬP LƯƠNG -->
                        <div v-show="activeTab === 'salary'" class="space-y-4">
                            <!-- Loading -->
                            <div v-if="salaryLoading" class="text-center py-8 text-gray-400">Đang tải...</div>

                            <template v-else>
                            <!-- Lương chính -->
                            <div class="bg-white border border-gray-200 shadow-sm rounded-lg p-5">
                                <div class="font-bold text-[15px] mb-4 text-gray-800">Lương chính</div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block font-semibold mb-1 text-gray-500">Loại lương</label>
                                        <select
                                            v-model="salaryForm.salary_type"
                                            class="w-full border border-blue-400 text-blue-600 font-medium rounded-md px-3 py-1.5 focus:outline-none"
                                        >
                                            <option value="fixed">Cố định</option>
                                            <option value="by_workday">Theo ngày công chuẩn</option>
                                            <option value="hourly">Theo giờ</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block font-semibold mb-1 text-gray-500">Mức lương</label>
                                        <div class="flex items-center gap-2">
                                            <MoneyInput
                                                v-model="salaryForm.base_salary"
                                                :min="0"
                                                placeholder="0"
                                                input-class="w-full border border-gray-300 rounded-md px-3 py-1.5 focus:border-blue-500 outline-none"
                                            />
                                            <span class="text-gray-500 whitespace-nowrap text-sm">/ {{ salaryForm.salary_type === 'hourly' ? 'giờ' : 'tháng' }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Thiết lập nâng cao -->
                                <div class="mt-4">
                                    <label class="flex items-center gap-2 cursor-pointer select-none">
                                        <input type="checkbox" v-model="salaryForm.advanced_salary" class="accent-blue-600 w-4 h-4" />
                                        <span class="font-semibold text-gray-700 text-sm">Thiết lập nâng cao</span>
                                    </label>
                                </div>
                                <div v-if="salaryForm.advanced_salary" class="mt-3 border border-gray-200 rounded-lg overflow-hidden">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="text-left px-3 py-2 font-semibold text-gray-600">Mức lương</th>
                                                <th class="text-right px-3 py-2 font-semibold text-gray-600">Lương/kỳ lương</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr class="border-t">
                                                <td class="px-3 py-2 text-gray-700">Mặc định</td>
                                                <td class="px-3 py-2 text-right font-medium text-gray-800">{{ formatCurrency(salaryForm.base_salary) }}</td>
                                            </tr>
                                            <tr class="border-t">
                                                <td class="px-3 py-2 text-gray-700">Ngày nghỉ</td>
                                                <td class="px-3 py-2 text-right">
                                                    <div class="flex items-center justify-end gap-1">
                                                        <input v-model.number="salaryForm.holiday_rate" type="number" min="0" max="999" class="w-20 border border-gray-300 rounded px-2 py-1 text-right focus:border-blue-500 outline-none" />
                                                        <span class="text-gray-500">%</span>
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr class="border-t">
                                                <td class="px-3 py-2 text-gray-700">Ngày lễ, tết</td>
                                                <td class="px-3 py-2 text-right">
                                                    <div class="flex items-center justify-end gap-1">
                                                        <input v-model.number="salaryForm.tet_rate" type="number" min="0" max="999" class="w-20 border border-gray-300 rounded px-2 py-1 text-right focus:border-blue-500 outline-none" />
                                                        <span class="text-gray-500">%</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Lương làm thêm giờ -->
                                <div class="mt-4 border border-gray-200 rounded-lg p-4">
                                    <label class="flex items-center gap-2 cursor-pointer select-none">
                                        <input type="checkbox" v-model="salaryForm.has_overtime" class="accent-blue-600 w-4 h-4" />
                                        <span class="font-semibold text-gray-700 text-sm">Lương làm thêm giờ</span>
                                    </label>
                                    <div v-if="salaryForm.has_overtime" class="mt-3">
                                        <table class="w-full text-sm">
                                            <thead>
                                                <tr class="text-gray-500 text-xs">
                                                    <th class="text-left py-1 font-medium"></th>
                                                    <th class="text-center py-1 font-medium">Ngày thường</th>
                                                    <th class="text-center py-1 font-medium">Thứ 7</th>
                                                    <th class="text-center py-1 font-medium">Chủ nhật</th>
                                                    <th class="text-center py-1 font-medium">Ngày nghỉ</th>
                                                    <th class="text-center py-1 font-medium">Ngày lễ tết</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td class="py-1 text-gray-600 text-xs">Hệ số lương trên giờ</td>
                                                    <td class="py-1 text-center"><input v-model.number="salaryForm.overtime_rate" type="number" min="0" max="999" class="w-16 border border-gray-300 rounded px-1.5 py-0.5 text-center text-sm focus:border-blue-500 outline-none" />%</td>
                                                    <td class="py-1 text-center"><input v-model.number="salaryForm.saturday_ot_rate" type="number" min="0" max="999" class="w-16 border border-gray-300 rounded px-1.5 py-0.5 text-center text-sm focus:border-blue-500 outline-none" />%</td>
                                                    <td class="py-1 text-center"><input v-model.number="salaryForm.sunday_ot_rate" type="number" min="0" max="999" class="w-16 border border-gray-300 rounded px-1.5 py-0.5 text-center text-sm focus:border-blue-500 outline-none" />%</td>
                                                    <td class="py-1 text-center"><input v-model.number="salaryForm.rest_day_ot_rate" type="number" min="0" max="999" class="w-16 border border-gray-300 rounded px-1.5 py-0.5 text-center text-sm focus:border-blue-500 outline-none" />%</td>
                                                    <td class="py-1 text-center"><input v-model.number="salaryForm.holiday_ot_rate" type="number" min="0" max="999" class="w-16 border border-gray-300 rounded px-1.5 py-0.5 text-center text-sm focus:border-blue-500 outline-none" />%</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <label class="block font-semibold mb-1 text-gray-500 flex items-center gap-1">
                                        Mẫu lương
                                        <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </label>
                                    <select
                                        :value="salaryForm.salary_template_id"
                                        @change="onTemplateChange($event.target.value ? Number($event.target.value) : null)"
                                        class="w-full border border-gray-300 rounded-md px-3 py-1.5 focus:outline-none text-gray-700"
                                    >
                                        <option :value="null">-- Chọn mẫu lương có sẵn --</option>
                                        <option v-for="t in salaryTemplates" :key="t.id" :value="t.id">{{ t.name }}</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Thưởng -->
                            <div class="bg-white border border-gray-200 shadow-sm rounded-lg overflow-hidden">
                                <div class="p-4 flex items-center justify-between cursor-pointer" @click="expandedSections.bonus = !expandedSections.bonus">
                                    <div class="flex items-center gap-3">
                                        <input type="checkbox" v-model="salaryForm.has_bonus" @click.stop class="accent-blue-600 w-4 h-4" />
                                        <div>
                                            <div class="font-bold text-[14px] text-gray-800">Thưởng</div>
                                            <div class="text-[12px] text-gray-500 mt-0.5">Thiết lập thưởng theo doanh thu cho nhân viên</div>
                                        </div>
                                    </div>
                                    <svg class="w-4 h-4 text-gray-400 transition-transform" :class="{ 'rotate-180': expandedSections.bonus }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </div>
                                <div v-show="expandedSections.bonus && salaryForm.has_bonus" class="border-t px-4 py-3 bg-gray-50 text-sm space-y-3">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-500 mb-1">Loại thưởng</label>
                                            <select v-model="salaryForm.bonus_type" class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm focus:border-blue-500 outline-none">
                                                <option value="personal_revenue">Theo doanh thu cá nhân</option>
                                                <option value="branch_revenue">Theo doanh thu chi nhánh</option>
                                                <option value="personal_gross_profit">Theo lợi nhuận gộp cá nhân</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-500 mb-1">Hình thức</label>
                                            <select v-model="salaryForm.bonus_calculation" class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm focus:border-blue-500 outline-none">
                                                <option value="total_revenue">{{ salaryForm.bonus_type === 'personal_gross_profit' ? 'Theo mức lợi nhuận tổng' : 'Theo mức doanh thu tổng' }}</option>
                                                <option value="progressive">Lũy tiến</option>
                                            </select>
                                        </div>
                                    </div>
                                    <!-- Bonus tiers table -->
                                    <div v-if="salaryForm.custom_bonuses.length" class="border rounded overflow-hidden">
                                        <table class="w-full text-sm">
                                            <thead class="bg-gray-100">
                                                <tr>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">{{ salaryForm.bonus_type === 'personal_gross_profit' ? 'Lợi nhuận từ' : 'Doanh thu từ' }}</th>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">Thưởng</th>
                                                    <th class="text-center px-2 py-1.5 font-semibold text-gray-600">%</th>
                                                    <th class="w-8"></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="(b, i) in salaryForm.custom_bonuses" :key="i" class="border-t">
                                                    <td class="px-2 py-1"><MoneyInput v-model="b.revenue_from" :min="0" input-class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" /></td>
                                                    <td class="px-2 py-1"><MoneyInput v-if="!b.bonus_is_percentage" v-model="b.bonus_value" :min="0" input-class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" />
                                                        <input v-else v-model.number="b.bonus_value" type="number" min="0" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" /></td>
                                                    <td class="px-2 py-1 text-center"><input type="checkbox" v-model="b.bonus_is_percentage" class="accent-blue-600" /></td>
                                                    <td class="px-1 py-1"><button type="button" @click="salaryForm.custom_bonuses.splice(i, 1)" class="text-red-400 hover:text-red-600">&times;</button></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <button type="button" @click="salaryForm.custom_bonuses.push({ role_type: 'employee', revenue_from: 0, bonus_value: 0, bonus_is_percentage: false })" class="text-blue-600 text-sm font-semibold hover:underline">+ Thêm mức thưởng</button>
                                </div>
                            </div>

                            <!-- Hoa hồng -->
                            <div class="bg-white border border-gray-200 shadow-sm rounded-lg overflow-hidden">
                                <div class="p-4 flex items-center justify-between cursor-pointer" @click="expandedSections.commission = !expandedSections.commission">
                                    <div class="flex items-center gap-3">
                                        <input type="checkbox" v-model="salaryForm.has_commission" @click.stop class="accent-blue-600 w-4 h-4" />
                                        <div>
                                            <div class="font-bold text-[14px] text-gray-800">Hoa hồng</div>
                                            <div class="text-[12px] text-gray-500 mt-0.5">Thiết lập mức hoa hồng theo sản phẩm hoặc dịch vụ</div>
                                        </div>
                                    </div>
                                    <svg class="w-4 h-4 text-gray-400 transition-transform" :class="{ 'rotate-180': expandedSections.commission }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </div>
                                <div v-show="expandedSections.commission && salaryForm.has_commission" class="border-t px-4 py-3 bg-gray-50 text-sm space-y-3">
                                    <div v-if="salaryForm.custom_commissions.length" class="border rounded overflow-hidden">
                                        <table class="w-full text-sm">
                                            <thead class="bg-gray-100">
                                                <tr>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">DT từ</th>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">Bảng hoa hồng</th>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">Giá trị</th>
                                                    <th class="text-center px-2 py-1.5 font-semibold text-gray-600">%</th>
                                                    <th class="w-8"></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="(c, i) in salaryForm.custom_commissions" :key="i" class="border-t">
                                                    <td class="px-2 py-1"><MoneyInput v-model="c.revenue_from" :min="0" input-class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" /></td>
                                                    <td class="px-2 py-1">
                                                        <select v-model="c.commission_table_id" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none">
                                                            <option :value="null">-- Không --</option>
                                                            <option v-for="ct in commissionTables" :key="ct.id" :value="ct.id">{{ ct.name }}</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-2 py-1"><MoneyInput v-if="!c.commission_is_percentage" v-model="c.commission_value" :min="0" :disabled="!!c.commission_table_id" input-class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" />
                                                        <input v-else v-model.number="c.commission_value" type="number" min="0" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" :disabled="!!c.commission_table_id" /></td>
                                                    <td class="px-2 py-1 text-center"><input type="checkbox" v-model="c.commission_is_percentage" class="accent-blue-600" :disabled="!!c.commission_table_id" /></td>
                                                    <td class="px-1 py-1"><button type="button" @click="salaryForm.custom_commissions.splice(i, 1)" class="text-red-400 hover:text-red-600">&times;</button></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <button type="button" @click="salaryForm.custom_commissions.push({ role_type: 'employee', revenue_from: 0, commission_table_id: null, commission_value: 0, commission_is_percentage: false })" class="text-blue-600 text-sm font-semibold hover:underline">+ Thêm hoa hồng</button>
                                </div>
                            </div>

                            <!-- Phụ cấp -->
                            <div class="bg-white border border-gray-200 shadow-sm rounded-lg overflow-hidden">
                                <div class="p-4 flex items-center justify-between cursor-pointer" @click="expandedSections.allowance = !expandedSections.allowance">
                                    <div class="flex items-center gap-3">
                                        <input type="checkbox" v-model="salaryForm.has_allowance" @click.stop class="accent-blue-600 w-4 h-4" />
                                        <div>
                                            <div class="font-bold text-[14px] text-gray-800">Phụ cấp</div>
                                            <div class="text-[12px] text-gray-500 mt-0.5">Thiết lập khoản hỗ trợ làm việc như ăn trưa, đi lại, điện thoại, ...</div>
                                        </div>
                                    </div>
                                    <svg class="w-4 h-4 text-gray-400 transition-transform" :class="{ 'rotate-180': expandedSections.allowance }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </div>
                                <div v-show="expandedSections.allowance && salaryForm.has_allowance" class="border-t px-4 py-3 bg-gray-50 text-sm space-y-3">
                                    <div v-if="salaryForm.custom_allowances.length" class="border rounded overflow-hidden">
                                        <table class="w-full text-sm">
                                            <thead class="bg-gray-100">
                                                <tr>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">Tên phụ cấp</th>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">Loại phụ cấp</th>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">Phụ cấp thụ hưởng</th>
                                                    <th class="w-8"></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="(a, i) in salaryForm.custom_allowances" :key="i" class="border-t">
                                                    <td class="px-2 py-1"><input v-model="a.name" type="text" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" placeholder="Ăn trưa, đi lại..." /></td>
                                                    <td class="px-2 py-1">
                                                        <select v-model="a.allowance_type" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none">
                                                            <option value="fixed_per_month">Cố định/tháng</option>
                                                            <option value="fixed_per_day">Theo ngày công</option>
                                                            <option value="percentage">% lương</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-2 py-1"><MoneyInput v-model="a.amount" :min="0" input-class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" /></td>
                                                    <td class="px-1 py-1"><button type="button" @click="salaryForm.custom_allowances.splice(i, 1)" class="text-red-400 hover:text-red-600">&times;</button></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <button type="button" @click="salaryForm.custom_allowances.push({ name: '', allowance_type: 'fixed_per_month', amount: 0 })" class="text-blue-600 text-sm font-semibold hover:underline">+ Thêm phụ cấp</button>
                                </div>
                            </div>

                            <!-- Giảm trừ -->
                            <div class="bg-white border border-gray-200 shadow-sm rounded-lg overflow-hidden">
                                <div class="p-4 flex items-center justify-between cursor-pointer" @click="expandedSections.deduction = !expandedSections.deduction">
                                    <div class="flex items-center gap-3">
                                        <input type="checkbox" v-model="salaryForm.has_deduction" @click.stop class="accent-blue-600 w-4 h-4" />
                                        <div>
                                            <div class="font-bold text-[14px] text-gray-800">Giảm trừ</div>
                                            <div class="text-[12px] text-gray-500 mt-0.5">Thiết lập khoản giảm trừ như đi muộn, về sớm, vi phạm nội quy, ...</div>
                                        </div>
                                    </div>
                                    <svg class="w-4 h-4 text-gray-400 transition-transform" :class="{ 'rotate-180': expandedSections.deduction }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </div>
                                <div v-show="expandedSections.deduction && salaryForm.has_deduction" class="border-t px-4 py-3 bg-gray-50 text-sm space-y-3">
                                    <p class="text-xs text-gray-400 italic">Khoản giảm trừ cố định hàng tháng (BHXH, thuế...) hoặc giảm trừ theo chấm công (đi muộn, về sớm).</p>
                                    <div v-if="salaryForm.custom_deductions.length" class="border rounded overflow-hidden">
                                        <table class="w-full text-sm">
                                            <thead class="bg-gray-100">
                                                <tr>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">Tên giảm trừ</th>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600 w-[140px]">Loại giảm trừ</th>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600 w-[140px]">Loại tính</th>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600 w-[90px]">Mỗi (phút)</th>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600 w-[120px]">Số tiền</th>
                                                    <th class="w-8"></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="(d, i) in salaryForm.custom_deductions" :key="i" class="border-t">
                                                    <td class="px-2 py-1"><input v-model="d.name" type="text" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" placeholder="Đi muộn, BHXH..." /></td>
                                                    <td class="px-2 py-1">
                                                        <select v-model="d.deduction_category" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none text-[13px]">
                                                            <option value="">Cố định</option>
                                                            <option value="late">Đi muộn</option>
                                                            <option value="early_leave">Về sớm</option>
                                                            <option value="absence">Vắng mặt</option>
                                                            <option value="violation">Vi phạm nội quy</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-2 py-1">
                                                        <select v-model="d.calculation_type" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none text-[13px]"
                                                            :disabled="!d.deduction_category || d.deduction_category === 'absence' || d.deduction_category === 'violation'">
                                                            <option value="fixed_per_month">Cố định/tháng</option>
                                                            <option v-if="d.deduction_category === 'late' || d.deduction_category === 'early_leave'" value="per_minute">Theo số phút</option>
                                                            <option v-if="d.deduction_category === 'late' || d.deduction_category === 'early_leave'" value="per_occurrence">Theo số lần</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-2 py-1">
                                                        <input
                                                            v-if="d.calculation_type === 'per_minute'"
                                                            v-model.number="d.per_minutes"
                                                            type="number"
                                                            min="1"
                                                            class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none"
                                                            placeholder="15"
                                                        />
                                                        <span v-else class="text-gray-300 text-xs">—</span>
                                                    </td>
                                                    <td class="px-2 py-1">
                                                        <div class="relative">
                                                            <MoneyInput v-model="d.amount" :min="0" input-class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" />
                                                        </div>
                                                        <div v-if="d.calculation_type === 'per_minute' && d.per_minutes" class="text-[10px] text-gray-400 mt-0.5">
                                                            Trừ {{ formatCurrency(d.amount || 0) }}đ / {{ d.per_minutes }} phút
                                                        </div>
                                                    </td>
                                                    <td class="px-1 py-1"><button type="button" @click="salaryForm.custom_deductions.splice(i, 1)" class="text-red-400 hover:text-red-600">&times;</button></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <button type="button" @click="salaryForm.custom_deductions.push({ name: '', deduction_category: '', calculation_type: 'fixed_per_month', amount: 0, per_minutes: 15 })" class="text-blue-600 text-sm font-semibold hover:underline">+ Thêm giảm trừ</button>
                                </div>
                            </div>
                            </template>
                        </div>

                        <!-- TAB NỢ VÀ TẠM ỨNG -->
                        <div v-show="activeTab === 'ledger'" class="space-y-4">
                            <div class="rounded border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
                                Số dư dương: công ty còn phải trả nhân viên. Số dư 0: đã tất toán. Số dư âm: nhân viên đã tạm ứng vượt hoặc công ty còn phải thu.
                            </div>
                            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
                                <div class="bg-white border rounded-lg p-3">
                                    <div class="text-xs text-gray-500">Số dư đầu kỳ</div>
                                    <div class="font-bold mt-1">{{ formatCurrency(ledgerSummary.opening_balance) }}</div>
                                </div>
                                <div class="bg-white border rounded-lg p-3">
                                    <div class="text-xs text-gray-500">Tăng phải trả</div>
                                    <div class="font-bold text-blue-600 mt-1">{{ formatCurrency(ledgerSummary.total_increase) }}</div>
                                </div>
                                <div class="bg-white border rounded-lg p-3">
                                    <div class="text-xs text-gray-500">Giảm phải trả</div>
                                    <div class="font-bold text-red-600 mt-1">-{{ formatCurrency(ledgerSummary.total_decrease) }}</div>
                                </div>
                                <div class="bg-white border rounded-lg p-3">
                                    <div class="text-xs text-gray-500">Biến động ròng</div>
                                    <div class="font-bold mt-1">{{ formatCurrency(ledgerSummary.net_change) }}</div>
                                </div>
                                <div class="bg-white border rounded-lg p-3">
                                    <div class="text-xs text-gray-500">Số dư hiện tại</div>
                                    <div class="font-bold text-lg mt-1">{{ formatCurrency(ledgerSummary.current_balance) }}</div>
                                </div>
                            </div>

                            <div class="flex justify-end gap-2">
                                <button v-if="can('payroll.pay')" type="button" :disabled="salaryPaymentFlow.loading" @click="openSalaryPaymentFlow" class="rounded bg-blue-600 px-4 py-2 font-bold text-white disabled:opacity-50">
                                    Thanh toán lương
                                </button>
                                <button v-if="can('payroll.adjust')" type="button" @click="showAdjustmentModal = true" class="rounded bg-amber-600 px-4 py-2 font-bold text-white">+ Điều chỉnh</button>
                                <button v-if="can('payroll.ledger.export')" type="button" @click="exportLedger" class="rounded bg-green-600 px-4 py-2 font-bold text-white">Xuất Excel/CSV</button>
                            </div>

                            <div v-if="can('payroll.advance.create')" class="bg-white border rounded-lg p-4">
                                <div class="font-bold text-gray-800 mb-3">Tạo tạm ứng</div>
                                <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 items-end">
                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">Số tiền</label>
                                        <MoneyInput v-model="advanceForm.amount" :min="1" input-class="w-full border rounded px-3 py-2" />
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">Ngày tạm ứng</label>
                                        <input v-model="advanceForm.advance_date" type="datetime-local" class="w-full border rounded px-3 py-2" />
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">Phương thức</label>
                                        <select v-model="advanceForm.payment_method" class="w-full border rounded px-3 py-2">
                                            <option value="cash">Tiền mặt</option>
                                            <option value="bank_transfer">Chuyển khoản</option>
                                            <option value="ewallet">Ví điện tử</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">Lý do</label>
                                        <input v-model="advanceForm.note" type="text" class="w-full border rounded px-3 py-2" placeholder="Tạm ứng lương..." />
                                    </div>
                                    <button type="button" :disabled="advanceSaving" @click="createAdvance" class="bg-blue-600 text-white font-bold rounded px-4 py-2 disabled:opacity-50">
                                        Tạo tạm ứng
                                    </button>
                                </div>
                            </div>

                            <div class="bg-white border rounded-lg overflow-hidden">
                                <div class="p-3 border-b flex flex-wrap gap-2 items-end">
                                    <input v-model="ledgerFilters.from_date" type="date" class="border rounded px-3 py-2" />
                                    <input v-model="ledgerFilters.to_date" type="date" class="border rounded px-3 py-2" />
                                    <select v-model="ledgerFilters.type" class="border rounded px-3 py-2">
                                        <option value="">Tất cả loại phiếu</option>
                                        <option value="opening_balance">Số dư đầu kỳ</option>
                                        <option value="payroll_accrual">Phiếu lương</option>
                                        <option value="salary_payment">Thanh toán lương</option>
                                        <option value="salary_advance">Tạm ứng</option>
                                        <option value="adjustment_increase">Điều chỉnh tăng</option>
                                        <option value="adjustment_decrease">Điều chỉnh giảm</option>
                                        <option value="cancel_reverse">Dòng đảo</option>
                                    </select>
                                    <select v-model="ledgerFilters.status" class="border rounded px-3 py-2">
                                        <option value="">Tất cả trạng thái</option>
                                        <option value="valid">Hợp lệ</option>
                                        <option value="reversed">Đã đảo</option>
                                        <option value="cancelled">Đã hủy</option>
                                    </select>
                                    <select v-model="ledgerFilters.branch_id" class="border rounded px-3 py-2">
                                        <option value="">Tất cả chi nhánh</option>
                                        <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                                    </select>
                                    <input v-model="ledgerFilters.keyword" class="border rounded px-3 py-2" placeholder="Mã phiếu, ghi chú, người tạo" />
                                    <button type="button" @click="loadLedger()" class="bg-gray-800 text-white rounded px-4 py-2">Lọc</button>
                                </div>
                                <div class="border-b bg-gray-50 px-3 py-2 text-xs text-gray-600">
                                    Tổng hợp số dư tính theo khoảng ngày và chi nhánh, không phụ thuộc loại, trạng thái hoặc từ khóa.
                                    Kết quả đang lọc: tăng {{ formatCurrency(filteredLedgerSummary.filtered_increase) }},
                                    giảm {{ formatCurrency(filteredLedgerSummary.filtered_decrease) }}.
                                </div>
                                <div v-if="ledgerLoading" class="p-8 text-center text-gray-400">Đang tải...</div>
                                <div v-else class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="text-left px-3 py-2">Mã phiếu</th>
                                                <th class="text-left px-3 py-2">Thời gian</th>
                                                <th class="text-left px-3 py-2">Loại phiếu</th>
                                                <th class="text-right px-3 py-2">Phát sinh tăng</th>
                                                <th class="text-right px-3 py-2">Phát sinh giảm</th>
                                                <th class="text-right px-3 py-2">Nợ và tạm ứng</th>
                                                <th class="text-left px-3 py-2">Ghi chú</th>
                                                <th class="text-left px-3 py-2">Người tạo</th>
                                                <th class="text-left px-3 py-2">Trạng thái</th>
                                                <th class="text-left px-3 py-2">Thao tác</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="entry in ledgerRows" :key="entry.id" class="border-t">
                                                <td class="px-3 py-2 font-medium text-blue-600">
                                                    <button type="button" class="hover:underline" @click="openLedgerEntry(entry)">{{ entry.code || "-" }}</button>
                                                </td>
                                                <td class="px-3 py-2">{{ new Date(entry.event_at).toLocaleString("vi-VN") }}</td>
                                                <td class="px-3 py-2">
                                                    <div>{{ ledgerTypeLabel(entry) }}</div>
                                                    <div v-if="!ledgerTypeLabels[entry.type]" class="text-[11px] text-gray-400">{{ entry.type }}</div>
                                                </td>
                                                <td class="px-3 py-2 text-right font-semibold text-emerald-600">{{ Number(entry.amount) > 0 ? `+${formatCurrency(entry.amount)}` : "0đ" }}</td>
                                                <td class="px-3 py-2 text-right font-semibold text-orange-600">{{ Number(entry.amount) < 0 ? formatCurrency(entry.amount) : "0đ" }}</td>
                                                <td class="px-3 py-2 text-right font-semibold">{{ formatCurrency(entry.balance_after) }}</td>
                                                <td class="px-3 py-2">{{ entry.note || "-" }}</td>
                                                <td class="px-3 py-2">{{ entry.creator?.name || "-" }}</td>
                                                <td class="px-3 py-2">{{ ledgerStatusLabel(entry) }}</td>
                                                <td class="px-3 py-2"><button type="button" class="text-blue-600 hover:underline" @click="openLedgerEntry(entry)">Chi tiết</button></td>
                                            </tr>
                                            <tr v-if="!ledgerRows.length">
                                                <td colspan="10" class="px-3 py-8 text-center text-gray-400">Chưa có phát sinh nợ & tạm ứng.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="flex items-center justify-between border-t p-3 text-sm">
                                    <span>Trang {{ ledgerData.current_page || 1 }} / {{ ledgerData.last_page || 1 }}</span>
                                    <div class="flex gap-2">
                                        <button type="button" class="rounded border px-3 py-1 disabled:opacity-40" :disabled="ledgerData.current_page <= 1" @click="loadLedger(ledgerData.current_page - 1)">Trước</button>
                                        <button type="button" class="rounded border px-3 py-1 disabled:opacity-40" :disabled="ledgerData.current_page >= ledgerData.last_page" @click="loadLedger(ledgerData.current_page + 1)">Sau</button>
                                    </div>
                                </div>
                            </div>

                            <div class="overflow-hidden rounded-lg border bg-white">
                                <div class="border-b p-3 font-bold">Danh sách tạm ứng</div>
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50"><tr>
                                        <th class="p-2 text-left">Mã</th><th class="p-2 text-left">Ngày</th>
                                        <th class="p-2 text-right">Số tiền</th><th class="p-2 text-right">Đã phân bổ</th>
                                        <th class="p-2 text-right">Còn lại</th><th class="p-2 text-left">Phương thức</th>
                                        <th class="p-2 text-left">Trạng thái</th><th class="p-2 text-left">Người tạo</th><th class="p-2"></th>
                                    </tr></thead>
                                    <tbody>
                                        <tr v-for="advance in advances.data" :key="advance.id" class="border-t">
                                            <td class="p-2 font-medium">{{ advance.code }}</td>
                                            <td class="p-2">{{ new Date(advance.advance_date).toLocaleString('vi-VN') }}</td>
                                            <td class="p-2 text-right">{{ formatCurrency(advance.amount) }}</td>
                                            <td class="p-2 text-right">{{ formatCurrency(advance.applied_amount) }}</td>
                                            <td class="p-2 text-right">{{ formatCurrency(advance.remaining_amount) }}</td>
                                            <td class="p-2">{{ advance.payment_method }}</td>
                                            <td class="p-2">{{ advance.status }}</td>
                                            <td class="p-2">{{ advance.creator?.name || '-' }}</td>
                                            <td class="p-2 text-right">
                                                <button v-if="can('payroll.advance.cancel') && advance.status !== 'cancelled'" type="button"
                                                    class="text-red-600 disabled:text-gray-400" :disabled="Number(advance.applied_amount) > 0"
                                                    :title="Number(advance.applied_amount) > 0 ? 'Khoản tạm ứng đã được cấn trừ vào phiếu lương.' : 'Hủy tạm ứng'"
                                                    @click="openAdvanceCancel(advance)">Hủy</button>
                                            </td>
                                        </tr>
                                        <tr v-if="!advances.data?.length"><td colspan="9" class="p-6 text-center text-gray-400">Chưa có tạm ứng.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Modal Footer Actions -->
                <div
                    class="px-6 py-4 border-t border-gray-200 bg-white flex items-center rounded-b shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)] z-10"
                >
                    <button
                        v-if="form.id"
                        @click="deleteEmployee"
                        class="px-4 py-2 border border-red-300 rounded text-red-600 bg-white font-bold hover:bg-red-50 transition shadow-sm text-sm"
                    >
                        Xóa nhân viên
                    </button>
                    <div class="flex-1"></div>
                    <div class="flex gap-3">
                    <button
                        @click="showCreateModal = false"
                        class="px-6 py-2 border border-gray-300 rounded text-gray-700 bg-white font-bold hover:bg-gray-50 transition shadow-sm"
                    >
                        Bỏ qua
                    </button>
                    <button
                        v-show="activeTab === 'salary'"
                        @click="submit"
                        class="px-6 py-2 border border-gray-300 rounded text-gray-700 bg-white font-bold hover:bg-gray-50 transition shadow-sm"
                    >
                        Lưu và tạo mẫu lương mới
                    </button>
                    <button
                        v-show="activeTab !== 'ledger'"
                        @click="submit"
                        class="px-8 py-2 border border-transparent rounded text-white bg-blue-600 font-bold hover:bg-blue-700 transition shadow-sm"
                        :class="{
                            'opacity-50 cursor-not-allowed': form.processing,
                        }"
                    >
                        Lưu
                    </button>
                    </div>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="salaryPaymentFlow.show" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/40" @click.self="salaryPaymentFlow.show = false">
                <div class="max-h-[90vh] w-full max-w-5xl overflow-auto rounded-lg bg-white shadow-xl">
                    <div class="flex items-start justify-between border-b p-4">
                        <div>
                            <div class="text-lg font-bold">
                                {{ salaryPaymentFlow.mode === 'salary_payment' ? 'Thanh toán lương' : 'Tạo tạm ứng lương' }}
                            </div>
                            <div class="text-sm text-gray-500">
                                {{ form.code }} - {{ form.name }}
                            </div>
                        </div>
                        <button type="button" class="text-2xl text-gray-400" @click="salaryPaymentFlow.show = false">&times;</button>
                    </div>

                    <div class="space-y-4 p-5">
                        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                            <div class="rounded border bg-gray-50 p-3">
                                <div class="text-xs text-gray-500">Số dư Nợ & Tạm ứng</div>
                                <div class="mt-1 font-bold">{{ formatCurrency(salaryPaymentFlow.preview?.current_balance || 0) }}</div>
                            </div>
                            <div class="rounded border bg-gray-50 p-3">
                                <div class="text-xs text-gray-500">Tổng còn cần trả</div>
                                <div class="mt-1 font-bold text-orange-600">{{ formatCurrency(salaryPaymentFlow.preview?.total_remaining || 0) }}</div>
                            </div>
                            <div class="rounded border bg-gray-50 p-3">
                                <div class="text-xs text-gray-500">Chế độ</div>
                                <div class="mt-1 font-bold">{{ salaryPaymentFlow.mode === 'salary_payment' ? 'Thanh toán lương' : 'Tạm ứng lương' }}</div>
                            </div>
                            <div class="rounded border bg-gray-50 p-3">
                                <div class="text-xs text-gray-500">Tổng tiền chi</div>
                                <div class="mt-1 font-bold text-blue-600">{{ formatCurrency(salaryPaymentFlow.mode === 'salary_payment' ? salaryPaymentTotal : salaryPaymentFlow.amount) }}</div>
                            </div>
                        </div>

                        <div v-if="salaryPaymentFlow.mode === 'salary_advance'" class="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                            Nhân viên không còn phiếu lương cần thanh toán. Khoản chi này sẽ được ghi nhận là tạm ứng lương và tự cấn trừ vào kỳ lương tiếp theo.
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-sm text-gray-600">Thời gian</label>
                                <input v-if="salaryPaymentFlow.mode === 'salary_payment'" v-model="salaryPaymentFlow.paid_at" type="datetime-local" class="w-full rounded border px-3 py-2" />
                                <input v-else v-model="salaryPaymentFlow.advanced_at" type="datetime-local" class="w-full rounded border px-3 py-2" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm text-gray-600">Phương thức</label>
                                <select v-model="salaryPaymentFlow.payment_method" class="w-full rounded border px-3 py-2">
                                    <option value="cash">Tiền mặt</option>
                                    <option value="bank_transfer">Chuyển khoản</option>
                                    <option value="ewallet">Ví điện tử</option>
                                </select>
                            </div>
                            <div v-if="salaryPaymentFlow.mode === 'salary_advance'">
                                <label class="mb-1 block text-sm text-gray-600">Số tiền tạm ứng</label>
                                <MoneyInput v-model="salaryPaymentFlow.amount" :min="1" input-class="w-full border rounded px-3 py-2" />
                            </div>
                        </div>

                        <div v-if="salaryPaymentFlow.mode === 'salary_payment'" class="overflow-hidden rounded border">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Chọn</th>
                                        <th class="px-3 py-2 text-left">Mã phiếu lương</th>
                                        <th class="px-3 py-2 text-left">Bảng lương / Kỳ lương</th>
                                        <th class="px-3 py-2 text-right">Thành tiền</th>
                                        <th class="px-3 py-2 text-right">Đã trả</th>
                                        <th class="px-3 py-2 text-right">Còn cần trả</th>
                                        <th class="px-3 py-2 text-right">Tiền trả</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in salaryPaymentFlow.rows" :key="row.id" class="border-t">
                                        <td class="px-3 py-2">
                                            <input v-model="row.selected" type="checkbox" class="rounded border-gray-300 text-blue-600" />
                                        </td>
                                        <td class="px-3 py-2 font-medium text-blue-600">{{ row.code }}</td>
                                        <td class="px-3 py-2">
                                            <div>{{ row.paysheet_name || row.paysheet_code }}</div>
                                            <div class="text-xs text-gray-500">{{ row.period_label }}</div>
                                        </td>
                                        <td class="px-3 py-2 text-right">{{ formatCurrency(row.total_salary) }}</td>
                                        <td class="px-3 py-2 text-right">{{ formatCurrency(row.paid_amount) }}</td>
                                        <td class="px-3 py-2 text-right font-semibold text-orange-600">{{ formatCurrency(row.remaining_amount) }}</td>
                                        <td class="px-3 py-2">
                                            <MoneyInput v-model="row.amount" :min="1" :max="row.remaining_amount" input-class="w-full border rounded px-2 py-1 text-right" />
                                            <div v-if="Number(row.amount) > Number(row.remaining_amount)" class="mt-1 text-xs text-red-600">Không được vượt còn cần trả.</div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm text-gray-600">Ghi chú</label>
                            <textarea v-model="salaryPaymentFlow.note" rows="2" class="w-full rounded border px-3 py-2"></textarea>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 border-t bg-gray-50 p-4">
                        <button type="button" class="rounded border px-4 py-2" @click="salaryPaymentFlow.show = false">Bỏ qua</button>
                        <button type="button" :disabled="salaryPaymentFlow.submitting || salaryPaymentInvalid" class="rounded bg-blue-600 px-4 py-2 font-bold text-white disabled:opacity-50" @click="submitSalaryPaymentFlow">
                            {{ salaryPaymentFlow.mode === 'salary_payment' ? 'Tạo phiếu chi' : 'Tạo phiếu chi tạm ứng' }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="showAdjustmentModal" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/40" @click.self="showAdjustmentModal = false">
                <div class="w-full max-w-xl rounded-lg bg-white shadow-xl">
                    <div class="border-b p-4 text-lg font-bold">Điều chỉnh nợ và tạm ứng</div>
                    <div class="grid grid-cols-2 gap-4 p-5">
                        <select v-model="adjustmentForm.type" class="col-span-2 rounded border px-3 py-2">
                            <option value="adjustment_increase">Tăng số công ty phải trả nhân viên</option>
                            <option value="adjustment_decrease">Giảm số công ty phải trả nhân viên</option>
                        </select>
                        <MoneyInput v-model="adjustmentForm.amount" :min="1" input-class="w-full border rounded px-3 py-2" />
                        <input v-model="adjustmentForm.event_at" type="datetime-local" class="rounded border px-3 py-2" />
                        <input :value="branches.find(b => b.id === form.branch_id)?.name || 'Không xác định chi nhánh'" disabled class="rounded border bg-gray-100 px-3 py-2" />
                        <input v-model="adjustmentForm.reason" class="rounded border px-3 py-2" placeholder="Lý do, tối thiểu 10 ký tự" />
                        <textarea v-model="adjustmentForm.note" class="col-span-2 rounded border px-3 py-2" placeholder="Ghi chú"></textarea>
                        <input v-model="adjustmentForm.override_reason" class="col-span-2 rounded border px-3 py-2" placeholder="Lý do override kỳ khóa/backdate nếu được yêu cầu" />
                    </div>
                    <div class="flex justify-end gap-3 border-t p-4">
                        <button type="button" class="rounded border px-4 py-2" @click="showAdjustmentModal = false">Bỏ qua</button>
                        <button type="button" class="rounded bg-amber-600 px-4 py-2 text-white disabled:opacity-50"
                            :disabled="adjustmentSaving || Number(adjustmentForm.amount) <= 0 || adjustmentForm.reason.trim().length < 10"
                            @click="submitAdjustment">Ghi điều chỉnh</button>
                    </div>
                </div>
            </div>

            <div v-if="advanceCancel.show" class="fixed inset-0 z-[101] flex items-center justify-center bg-black/40" @click.self="advanceCancel.show = false">
                <div class="w-full max-w-lg rounded-lg bg-white shadow-xl">
                    <div class="border-b p-4 text-lg font-bold">Hủy tạm ứng {{ advanceCancel.target?.code }}</div>
                    <div class="space-y-3 p-5">
                        <div class="rounded bg-amber-50 p-3 text-sm text-amber-800">CashFlow liên quan sẽ bị hủy và ledger sẽ tạo dòng đảo. Lịch sử không bị xóa.</div>
                        <textarea v-model="advanceCancel.reason" rows="4" class="w-full rounded border px-3 py-2" placeholder="Lý do hủy, tối thiểu 10 ký tự"></textarea>
                    </div>
                    <div class="flex justify-end gap-3 border-t p-4">
                        <button type="button" class="rounded border px-4 py-2" @click="advanceCancel.show = false">Bỏ qua</button>
                        <button type="button" class="rounded bg-red-600 px-4 py-2 text-white disabled:opacity-50"
                            :disabled="advanceCancel.saving || advanceCancel.reason.trim().length < 10" @click="cancelAdvance">Xác nhận hủy</button>
                    </div>
                </div>
            </div>

            <div v-if="showLedgerDetail" class="fixed inset-0 z-[102] flex items-center justify-center bg-black/40" @click.self="showLedgerDetail = false">
                <div class="max-h-[85vh] w-full max-w-3xl overflow-auto rounded-lg bg-white shadow-xl">
                    <div class="flex justify-between border-b p-4">
                        <div class="text-lg font-bold">Chi tiết {{ ledgerDetail?.ledger_entry?.code }}</div>
                        <button type="button" @click="showLedgerDetail = false">&times;</button>
                    </div>
                    <div class="grid grid-cols-2 gap-3 p-5 text-sm">
                        <div><b>Loại:</b> {{ ledgerDetail?.ledger_entry?.type }}</div>
                        <div><b>Trạng thái:</b> {{ ledgerStatusLabel(ledgerDetail?.ledger_entry || {}) }}</div>
                        <div><b>Nhân viên:</b> {{ ledgerDetail?.employee?.name }}</div>
                        <div><b>Chi nhánh:</b> {{ ledgerDetail?.branch?.name || 'Không xác định' }}</div>
                        <div><b>Số tiền:</b> {{ formatCurrency(ledgerDetail?.ledger_entry?.amount) }}</div>
                        <div><b>Số dư sau:</b> {{ formatCurrency(ledgerDetail?.ledger_entry?.balance_after) }}</div>
                        <div><b>Ngày nghiệp vụ:</b> {{ ledgerDetail?.ledger_entry?.event_at }}</div>
                        <div><b>Ngày tạo:</b> {{ ledgerDetail?.ledger_entry?.created_at }}</div>
                        <div class="col-span-2"><b>Ghi chú:</b> {{ ledgerDetail?.ledger_entry?.note || '-' }}</div>
                        <div class="col-span-2"><b>Lý do:</b> {{ ledgerDetail?.ledger_entry?.reason || ledgerDetail?.ledger_entry?.cancel_reason || '-' }}</div>
                        <div><b>CashFlow:</b> {{ ledgerDetail?.cash_flow?.code || '-' }}</div>
                        <div><b>Payment/Advance:</b> {{ ledgerDetail?.payment?.code || ledgerDetail?.advance?.code || '-' }}</div>
                        <div class="col-span-2" v-if="ledgerDetail?.original_entry"><b>Chứng từ gốc:</b> {{ ledgerDetail.original_entry.code }}</div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: #d1d5db;
    border-radius: 10px;
}
</style>
