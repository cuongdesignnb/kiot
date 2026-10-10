const types = {
    allowance: { field: 'allowances', label: 'Phụ cấp' },
    bonus: { field: 'bonus', label: 'Thưởng' },
};

const money = value => {
    const number = Number(value ?? 0);
    return Number.isFinite(number) ? Math.max(0, Math.round(number)) : 0;
};

/** Read only the saved payslip; never re-apply current employee settings. */
export function getPayslipBreakdown(slip, type) {
    const config = types[type];
    if (!slip || !config) return [];
    const total = money(slip[config.field]);
    if (total === 0) return [];

    const details = slip.details || {};
    const aggregate = name => [{ name, amount: total, source: 'saved_total' }];
    if (Object.hasOwn(details.direct_overrides || {}, config.field)) {
        return aggregate(`${config.label} điều chỉnh trực tiếp`);
    }

    const adjustments = (slip.adjustments || []).filter(row => row.type === type);
    const isManual = !!details.manual_overrides?.[type] || adjustments.length > 0;
    const source = isManual ? adjustments : (details.details?.[config.field] || []);
    const rows = source.map(row => ({
        name: row.name || (type === 'bonus' && row.role_type
            ? `${row.role_type}: ${money(row.revenue_from).toLocaleString('vi-VN')}đ+`
            : config.label),
        // Zero is an actual calculation result, not a request to use the rate.
        amount: money(isManual ? row.amount : (row.calculated ?? row.amount)),
        notes: row.notes || '',
        meta: row.meta || {},
        source: isManual ? 'adjustment' : 'calculation',
    })).filter(row => row.amount > 0);

    if (rows.reduce((sum, row) => sum + row.amount, 0) !== total) {
        // Old snapshots may have only a manually edited total. Do not invent
        // which configured item it belongs to, or silently change that total.
        return aggregate(`${config.label} theo tổng đã lưu (chưa có chi tiết khớp)`);
    }
    return rows;
}
