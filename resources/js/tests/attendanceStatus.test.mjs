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
