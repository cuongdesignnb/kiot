<script setup>
import { ref, watch, reactive, computed } from "vue";
import { Head, router, Link, useForm, usePage } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import ExcelButtons from "@/Components/ExcelButtons.vue";
import axios from "axios";

const props = defineProps({
    employees: Object,
    branches: Array,
    departments: Array,
    jobTitles: Array,
    salaryTemplates: { type: Array, default: () => [] },
    filters: Object,
});

const search = ref(props.filters?.search || "");
const expandedRows = ref([]);

let searchTimeout;
watch(search, (value) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            "/employees",
            { search: value },
            {
                preserveState: true,
                replace: true,
            },
        );
    }, 500);
});

const toggleExpand = (employeeId) => {
    const index = expandedRows.value.indexOf(employeeId);
    if (index > -1) {
        expandedRows.value.splice(index, 1);
    } else {
        expandedRows.value.push(employeeId);
    }
};

const isExpanded = (employeeId) => {
    return expandedRows.value.includes(employeeId);
};

// Modal form state
const showCreateModal = ref(false);
const activeTab = ref("info"); // info | salary

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
                showCreateModal.value = false;
                form.reset();
            },
        });
    }
};

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

const resetSalaryForm = () => {
    salaryForm.salary_type = 'fixed';
    salaryForm.base_salary = 0;
    salaryForm.salary_template_id = null;
    salaryForm.advanced_salary = false;
    salaryForm.holiday_rate = 200;
    salaryForm.tet_rate = 300;
    salaryForm.has_overtime = false;
    salaryForm.overtime_rate = 150;
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
        deduction_category: d.deduction_category || 'late',
        calculation_type: d.calculation_type || 'per_occurrence',
        amount: d.amount || 0,
    }));
    if (salaryForm.has_bonus) expandedSections.bonus = true;
    if (salaryForm.has_commission) expandedSections.commission = true;
    if (salaryForm.has_allowance) expandedSections.allowance = true;
    if (salaryForm.has_deduction) expandedSections.deduction = true;
};

const loadCommissionTables = async () => {
    try {
        const res = await axios.get('/api/commission-tables');
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
                salaryForm.custom_deductions = (setting.custom_deductions || []).map(d => ({ ...d }));
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
            base_salary: salaryForm.base_salary,
            salary_template_id: salaryForm.salary_template_id,
            advanced_salary: salaryForm.advanced_salary,
            holiday_rate: salaryForm.holiday_rate,
            tet_rate: salaryForm.tet_rate,
            has_overtime: salaryForm.has_overtime,
            overtime_rate: salaryForm.overtime_rate,
            has_bonus: salaryForm.has_bonus,
            has_commission: salaryForm.has_commission,
            has_allowance: salaryForm.has_allowance,
            has_deduction: salaryForm.has_deduction,
            bonus_type: salaryForm.bonus_type,
            bonus_calculation: salaryForm.bonus_calculation,
            custom_bonuses: salaryForm.custom_bonuses,
            custom_commissions: salaryForm.custom_commissions,
            custom_allowances: salaryForm.custom_allowances,
            custom_deductions: salaryForm.custom_deductions,
        });
    } catch (e) {
        console.error('Failed to save salary settings', e);
    }
};

const formatCurrency = (val) => {
    if (!val && val !== 0) return '0';
    return Number(val).toLocaleString('vi-VN');
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
                            <th class="px-4 py-3 w-10 text-center">
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-300"
                                />
                            </th>
                            <th class="px-4 py-3 w-12">Ảnh</th>
                            <th class="px-4 py-3">Mã nhân viên</th>
                            <th class="px-4 py-3">Mã chấm công</th>
                            <th class="px-4 py-3">Tên nhân viên</th>
                            <th class="px-4 py-3">Số điện thoại</th>
                            <th class="px-4 py-3">Số CMND/CCCD</th>
                            <th class="px-4 py-3 text-right">Nợ và tạm ứng</th>
                            <th class="px-4 py-3">Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-gray-800">
                        <tr v-if="employees.data.length === 0">
                            <td
                                colspan="9"
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
                                <td class="px-4 py-3 text-right">
                                    {{
                                        Number(
                                            employee.balance,
                                        ).toLocaleString()
                                    }}
                                </td>
                                <td class="px-4 py-3 text-gray-500">
                                    {{ employee.notes || "" }}
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
                                            <option value="hourly">Theo giờ</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block font-semibold mb-1 text-gray-500">Mức lương</label>
                                        <div class="flex items-center gap-2">
                                            <input
                                                v-model.number="salaryForm.base_salary"
                                                type="number"
                                                min="0"
                                                class="w-full border border-gray-300 rounded-md px-3 py-1.5 focus:border-blue-500 outline-none"
                                                placeholder="0"
                                            />
                                            <span class="text-gray-500 whitespace-nowrap text-sm">/ {{ salaryForm.salary_type === 'hourly' ? 'giờ' : 'kỳ lương' }}</span>
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
                                <div class="mt-4">
                                    <label class="flex items-center gap-2 cursor-pointer select-none">
                                        <input type="checkbox" v-model="salaryForm.has_overtime" class="accent-blue-600 w-4 h-4" />
                                        <span class="font-semibold text-gray-700 text-sm">Lương làm thêm giờ</span>
                                    </label>
                                </div>
                                <div v-if="salaryForm.has_overtime" class="mt-2 flex items-center gap-2 pl-6">
                                    <span class="text-sm text-gray-600">Hệ số:</span>
                                    <input v-model.number="salaryForm.overtime_rate" type="number" min="0" max="999" class="w-20 border border-gray-300 rounded px-2 py-1 text-right focus:border-blue-500 outline-none" />
                                    <span class="text-gray-500 text-sm">%</span>
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
                                                <option value="total_revenue">Theo mức doanh thu tổng</option>
                                                <option value="progressive">Lũy tiến</option>
                                            </select>
                                        </div>
                                    </div>
                                    <!-- Bonus tiers table -->
                                    <div v-if="salaryForm.custom_bonuses.length" class="border rounded overflow-hidden">
                                        <table class="w-full text-sm">
                                            <thead class="bg-gray-100">
                                                <tr>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">Doanh thu từ</th>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">Thưởng</th>
                                                    <th class="text-center px-2 py-1.5 font-semibold text-gray-600">%</th>
                                                    <th class="w-8"></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="(b, i) in salaryForm.custom_bonuses" :key="i" class="border-t">
                                                    <td class="px-2 py-1"><input v-model.number="b.revenue_from" type="number" min="0" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" /></td>
                                                    <td class="px-2 py-1"><input v-model.number="b.bonus_value" type="number" min="0" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" /></td>
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
                                                    <td class="px-2 py-1"><input v-model.number="c.revenue_from" type="number" min="0" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" /></td>
                                                    <td class="px-2 py-1">
                                                        <select v-model="c.commission_table_id" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none">
                                                            <option :value="null">-- Không --</option>
                                                            <option v-for="ct in commissionTables" :key="ct.id" :value="ct.id">{{ ct.name }}</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-2 py-1"><input v-model.number="c.commission_value" type="number" min="0" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" :disabled="!!c.commission_table_id" /></td>
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
                                                    <td class="px-2 py-1"><input v-model.number="a.amount" type="number" min="0" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" /></td>
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
                                    <div v-if="salaryForm.custom_deductions.length" class="border rounded overflow-hidden">
                                        <table class="w-full text-sm">
                                            <thead class="bg-gray-100">
                                                <tr>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">Tên giảm trừ</th>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">Loại giảm trừ</th>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">Khoản giảm trừ</th>
                                                    <th class="text-left px-2 py-1.5 font-semibold text-gray-600">Số tiền</th>
                                                    <th class="w-8"></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="(d, i) in salaryForm.custom_deductions" :key="i" class="border-t">
                                                    <td class="px-2 py-1"><input v-model="d.name" type="text" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" placeholder="Đi muộn, về sớm..." /></td>
                                                    <td class="px-2 py-1">
                                                        <select v-model="d.deduction_category" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none">
                                                            <option value="late">Đi muộn</option>
                                                            <option value="early_leave">Về sớm</option>
                                                            <option value="absence">Vắng mặt</option>
                                                            <option value="violation">Vi phạm</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-2 py-1">
                                                        <select v-model="d.calculation_type" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none">
                                                            <option value="per_occurrence">Theo số lần</option>
                                                            <option value="per_minute">Theo số phút</option>
                                                            <option value="fixed_per_month">Cố định/tháng</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-2 py-1"><input v-model.number="d.amount" type="number" min="0" class="w-full border border-gray-300 rounded px-2 py-1 focus:border-blue-500 outline-none" /></td>
                                                    <td class="px-1 py-1"><button type="button" @click="salaryForm.custom_deductions.splice(i, 1)" class="text-red-400 hover:text-red-600">&times;</button></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <button type="button" @click="salaryForm.custom_deductions.push({ name: '', deduction_category: 'late', calculation_type: 'per_occurrence', amount: 0 })" class="text-blue-600 text-sm font-semibold hover:underline">+ Thêm giảm trừ</button>
                                </div>
                            </div>
                            </template>
                        </div>
                    </form>
                </div>

                <!-- Modal Footer Actions -->
                <div
                    class="px-6 py-4 border-t border-gray-200 bg-white flex justify-end gap-3 rounded-b shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)] z-10"
                >
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
