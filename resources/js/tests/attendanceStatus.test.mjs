import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

// Exercise the actual Vue computed body without requiring a browser or live data.
const source = readFileSync(new URL('../Pages/Employees/Attendance.vue', import.meta.url), 'utf8');
const body = source.match(/const statusBadge = computed\(\(\) => \{([\s\S]*?)\n\}\)/)?.[1];
assert.ok(body, 'Attendance status computed must exist');
const badge = record => runInNewContext(`(() => {${body}})()`, {
    activeSchedule: { value: { timekeeping_record: record } },
});

test('confirmed unpaid leave is not mislabeled missing attendance', () => {
    assert.equal(badge({ attendance_type: 'leave_unpaid' }).text, 'Nghỉ không lương');
});
test('confirmed paid leave is not mislabeled missing attendance', () => {
    assert.equal(badge({ attendance_type: 'leave_paid' }).text, 'Nghỉ hưởng lương');
});
test('unresolved review still takes priority', () => {
    assert.equal(badge({ attendance_type: 'work', needs_review: true }).text, 'Cần xử lý');
});
test('empty work is not converted to leave', () => {
    assert.equal(badge({ attendance_type: 'work' }).text, 'Chưa chấm công');
    assert.equal(badge(null).text, 'Chưa chấm công');
});
test('one punch is incomplete and a complete pair is normal', () => {
    assert.equal(badge({ attendance_type: 'work', check_in_at: '08:00' }).text, 'Chấm công thiếu');
    assert.equal(badge({ attendance_type: 'work', check_in_at: '08:00', check_out_at: '12:00' }).text, 'Đúng giờ');
});

const estimateBody = source.match(/const estimatedWorkInfo = computed\(\(\) => \{([\s\S]*?)\n\}\)/)?.[1];
assert.ok(estimateBody, 'Attendance work estimate computed must exist');
const estimate = (checkIn, checkOut) => runInNewContext(`(() => {${estimateBody}})()`, {
    form: {
        attendance_type: 'work', check_in_time: checkIn, check_out_time: checkOut,
        intervals: [{ check_in_time: checkIn, check_out_time: checkOut }],
    },
    hasCheckIn: { value: !!checkIn },
    hasCheckOut: { value: !!checkOut },
    activeSchedule: { value: { shift: { start_time: '08:30', end_time: '18:00' } } },
});
test('checkout without entry clearly explains the missing actual entry and adds no work', () => {
    const result = estimate('', '18:13');
    assert.equal(result.workedMinutes, 0);
    assert.equal(result.workUnits, 0);
    assert.match(result.reason, /Thiếu giờ vào/);
});
test('entry without checkout clearly explains the missing actual checkout and adds no work', () => {
    const result = estimate('08:30', '');
    assert.equal(result.workedMinutes, 0);
    assert.equal(result.workUnits, 0);
    assert.match(result.reason, /Thiếu giờ ra/);
});
test('valid complete pair still has a work estimate', () => {
    assert.equal(estimate('08:30', '18:00').workedMinutes, 570);
});
test('reviewing an incomplete device record does not prefill a fictitious shift boundary', () => {
    for (const [flag, field] of [['hasCheckIn', 'check_in_time'], ['hasCheckOut', 'check_out_time']]) {
        const watchBody = source.match(new RegExp(`watch\\(${flag}, \\(checked\\) => \\{([\\s\\S]*?)\\n\\}\\)`))?.[1];
        assert.ok(watchBody, `${flag} watch body must exist`);
        const form = { [field]: '' };
        runInNewContext(`(() => {${watchBody}})()`, {
            checked: true, form,
            activeSchedule: { value: { shift: { start_time: '08:30', end_time: '18:00' }, timekeeping_record: { needs_review: true } } },
            isPrefilling: { value: false },
        });
        assert.equal(form[field], '');
    }
});
test('modal keeps an orphan first checkout instead of overwriting it with later day totals', () => {
    const openBody = source.match(/const openModal = \(schedule\) => \{([\s\S]*?)\n\}/)?.[1];
    assert.ok(openBody);
    const form = {};
    const hasCheckIn = { value: false };
    const hasCheckOut = { value: false };
    runInNewContext(`(() => {${openBody}})()`, {
        schedule: { id: 1, timekeeping_record: {
            check_in_at: '13:30', check_out_at: '18:00', needs_review: true,
            intervals: [{ check_in_at: null, check_out_at: '12:00' }, { check_in_at: '13:30', check_out_at: '18:00' }],
        } },
        form, hasCheckIn, hasCheckOut, activeSchedule: { value: null }, isPrefilling: { value: false },
        modalTab: { value: '' }, hasOT: { value: false }, otHours: { value: 0 }, otMinutes: { value: 0 },
        isModalOpen: { value: false }, saveError: { value: '' }, formatCheckTime: time => time, setTimeout: callback => callback(),
    });
    assert.equal(form.check_in_time, '');
    assert.equal(form.check_out_time, '12:00');
    assert.equal(hasCheckIn.value, false);
    assert.equal(hasCheckOut.value, true);
    assert.equal(form.intervals[1].check_in_time, '13:30');
});
test('estimate uses the edited first interval once a missing actual entry is confirmed', () => {
    const result = runInNewContext(`(() => {${estimateBody}})()`, {
        form: { attendance_type: 'work', check_in_time: '08:30', check_out_time: '12:00',
            intervals: [{ check_in_time: '', check_out_time: '12:00' }, { check_in_time: '13:30', check_out_time: '18:00' }],
        },
        hasCheckIn: { value: true }, hasCheckOut: { value: true }, activeSchedule: { value: {} },
    });
    assert.equal(result.workedMinutes, 480);
});
