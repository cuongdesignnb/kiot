import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const listSource = readFileSync(new URL('../Pages/Employees/Paysheets.vue', import.meta.url), 'utf8');
const editSource = readFileSync(new URL('../Pages/Employees/PaysheetEdit.vue', import.meta.url), 'utf8');
const utilityUrl = new URL('../utils/payslipBreakdown.js', import.meta.url);
const getPayslipBreakdown = existsSync(utilityUrl) ? (await import(utilityUrl)).getPayslipBreakdown : undefined;

// Evaluate the actual row/amount expressions used by the expanded payslip.
function displayed(slip, type = 'allowance') {
    const variable = type === 'allowance' ? 'alw' : 'bon';
    const block = type === 'allowance'
        ? listSource.match(/<!-- Chi tiết phụ cấp -->([\s\S]*?)<!-- Chi tiết thưởng -->/)?.[1]
        : listSource.match(/<!-- Chi tiết thưởng -->([\s\S]*?)<!-- Chi tiết ngày lễ\/nghỉ -->/)?.[1];
    assert.ok(block, `${type} breakdown block must exist`);
    const loop = block.match(new RegExp(`v-for="\\(${variable}, i\\) in ([^"]+)"`));
    const money = block.match(new RegExp(`formatMoney\\((${variable}\\.[^)]*)\\)`));
    assert.ok(loop && money, `${type} breakdown must be rendered`);
    const rows = runInNewContext(loop[1], { slip, getPayslipBreakdown });
    return Array.from(rows, row => ({
        name: row.name,
        amount: runInNewContext(money[1], { [variable]: row }),
    }));
}

function openPopup(slip, type = 'allowance') {
    const settingsBody = editSource.match(/function getSettingsItems\(type, slip\) \{([\s\S]*?)\n\}/)?.[1];
    const openBody = editSource.match(/function openPopup\(type, slip\) \{([\s\S]*?)\n\}/)?.[1];
    assert.ok(settingsBody && openBody, 'Paysheet popup functions must exist');
    const context = {
        type, slip, getPayslipBreakdown, tempIdCounter: -1,
        popup: {}, pendingDeletes: { value: [] }, popupAdjustments: { value: [] },
        props: { salarySettings: { 1: {
            custom_allowances: [
                { name: 'Synthetic office allowance', amount: 120000 },
                { name: 'Synthetic travel allowance', amount: 30000 },
            ],
        } } },
    };
    runInNewContext(`function getSettingsItems(type, slip) {${settingsBody}}\n(() => {${openBody}})()`, context);
    return Array.from(context.popupAdjustments.value, row => ({ name: row.name, amount: row.amount }));
}

function slip(extra = {}) {
    return {
        employee_id: 1, allowances: 150000, bonus: 0, adjustments: [],
        details: { details: { allowances: [
            { name: 'Synthetic office allowance', amount: 120000, calculated: 120000 },
            { name: 'Synthetic travel allowance', amount: 30000, calculated: 30000 },
        ], bonus: [] } },
        ...extra,
    };
}

test('removed allowance is absent from the expanded payslip, not just from its total', () => {
    const value = slip({ allowances: 30000, adjustments: [{ type: 'allowance', name: 'Synthetic travel allowance', amount: 30000 }] });
    value.details.manual_overrides = { allowance: true };
    assert.deepEqual(displayed(value), [{ name: 'Synthetic travel allowance', amount: 30000 }]);
    assert.deepEqual(openPopup(value), displayed(value));
});

test('removing every allowance stays empty on the list and when the popup is reopened', () => {
    const value = slip({ allowances: 0 });
    value.details.manual_overrides = { allowance: true };
    assert.deepEqual(displayed(value), []);
    assert.deepEqual(openPopup(value), []);
});

test('removing every bonus stays empty even if employee settings still contain old tiers', () => {
    const value = slip({ bonus: 0 });
    value.details.details.bonus = [{ role_type: 'employee', calculated: 80000 }];
    value.details.manual_overrides = { bonus: true };
    assert.deepEqual(displayed(value, 'bonus'), []);
    assert.deepEqual(openPopup(value, 'bonus'), []);
});

test('a directly edited total is not explained using the old automatic item names', () => {
    const value = slip({ allowances: 45000, adjustments: [{ type: 'allowance', name: 'Synthetic travel allowance', amount: 30000 }] });
    value.details.direct_overrides = { allowances: 45000 };
    const rows = displayed(value);
    assert.equal(rows.length, 1);
    assert.equal(rows[0].amount, 45000);
    assert.match(rows[0].name, /điều chỉnh/);
    assert.deepEqual(openPopup(value), rows);
});

test('a calculated zero never falls back to the configured positive amount', () => {
    const value = slip({ allowances: 30000 });
    value.details.details.allowances[0].calculated = 0;
    assert.deepEqual(displayed(value), [{ name: 'Synthetic travel allowance', amount: 30000 }]);
});

test('removing a bonus uses the same effective item selection as allowances', () => {
    const value = slip({ bonus: 20000, adjustments: [{ type: 'bonus', name: 'Synthetic approved bonus', amount: 20000 }] });
    value.details.details.bonus = [{ role_type: 'employee', revenue_from: 0, calculated: 80000 }];
    value.details.manual_overrides = { bonus: true };
    assert.deepEqual(displayed(value, 'bonus'), [{ name: 'Synthetic approved bonus', amount: 20000 }]);
    assert.deepEqual(openPopup(value, 'bonus'), displayed(value, 'bonus'));
});

test('automatic breakdown remains unchanged when it agrees with the saved total', () => {
    assert.deepEqual(displayed(slip()), [
        { name: 'Synthetic office allowance', amount: 120000 },
        { name: 'Synthetic travel allowance', amount: 30000 },
    ]);
});

test('legacy inconsistent details show only the saved total, without invented allocation', () => {
    const value = slip({ allowances: 60000 });
    const rows = displayed(value);
    assert.equal(rows.length, 1);
    assert.equal(rows[0].amount, 60000);
    assert.match(rows[0].name, /đã lưu/);
});

test('viewing historical snapshots never changes payroll, adjustments, or source details', () => {
    const value = slip({ status: 'locked', allowances: 30000, adjustments: [{ type: 'allowance', name: 'Synthetic travel allowance', amount: 30000 }] });
    value.details.manual_overrides = { allowance: true };
    const before = JSON.stringify(value);
    displayed(value);
    openPopup(value);
    assert.equal(JSON.stringify(value), before);
});

test('both screens import the same pure saved-payslip breakdown resolver', () => {
    assert.equal(typeof getPayslipBreakdown, 'function');
    for (const source of [listSource, editSource]) {
        assert.match(source, /import \{ getPayslipBreakdown \} from ['"]@\/utils\/payslipBreakdown['"]/);
    }
});

test('inline save adopts server overrides and settlement totals immediately', async () => {
    const body = listSource.match(/const updateSlipField = async \(slip, field, event\) => \{([\s\S]*?)\n\}/)?.[1];
    assert.ok(body);
    const value = slip({ id: 5, allowances: 150000, base_salary: 1000000, bonus: 0,
        commission: 0, ot_pay: 0, deductions: 0, paid_amount: 10000, applied_advance: 20000 });
    await runInNewContext(`(async () => {${body}})()`, {
        slip: value, field: 'allowances', event: { target: { value: '45000' } },
        parseNumber: Number, expandedPaysheet: { value: { id: 1 } },
        axios: { put: async () => ({ data: { success: true, data: {
            allowances: 45000, total_salary: 1045000, remaining: 1015000,
            details: { ...value.details, direct_overrides: { allowances: 45000 } },
        } } }) },
    });
    assert.equal(value.remaining, 1015000);
    assert.equal(value.details.direct_overrides.allowances, 45000);
    assert.equal(displayed(value).reduce((sum, row) => sum + row.amount, 0), 45000);
});

test('amount and notes from confirmed adjustment rows survive reopening', () => {
    const value = slip({ allowances: 30000, adjustments: [
        { type: 'allowance', name: 'Synthetic travel allowance', amount: 30000, notes: 'Synthetic approval', meta: { basis: 'confirmed' } },
    ] });
    const rows = getPayslipBreakdown(value, 'allowance');
    assert.equal(rows[0].notes, 'Synthetic approval');
    assert.equal(rows[0].meta.basis, 'confirmed');
    assert.deepEqual(openPopup(value), displayed(value));
});
