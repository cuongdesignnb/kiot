# HOTFIX — My Tasks accept-all 404

## 1. Error

* URL: `POST https://kiot.cuongdesign.net/api/my-tasks/accept-all`
* Browser error: `404 Not Found`
* Frontend call: `axios.post("/api/my-tasks/accept-all")` in `MyTasks.vue:75`
* Missing backend route: `POST /api/my-tasks/accept-all`

## 2. Root cause

Route `POST /api/my-tasks/accept-all` was never registered in `routes/api.php`.

The controller method `MyTasksController::acceptAll()` already existed (line 109) and was fully implemented — it correctly:
- Gets the current user's employee
- Finds all pending assignments for that employee
- Filters out completed/cancelled tasks
- Reuses `TaskService::respondToAssignment()` for each one
- Returns JSON with `accepted` count and `message`

The ONLY missing piece was the route registration.

## 3. Fix

| File | Nội dung |
|------|----------|
| `routes/api.php` | Thêm `Route::post('/accept-all', ...)` **trước** route dynamic `/{assignment}/respond` để tránh route conflict |
| `MyTasksController.php` | Không sửa — method `acceptAll()` đã đúng |
| `MyTasks.vue` | Không sửa — frontend logic đã đúng |
| `tests/Feature/Tasks/MyTasksAcceptAllTest.php` | 9 tests mới |

## 4. Security

| Rule | Result |
|------|--------|
| Chỉ nhận assignment của user hiện tại | ✅ `where('employee_id', $employee->id)` |
| Không nhận task completed/cancelled | ✅ `whereNotIn('status', ['completed', 'cancelled'])` |
| Không nhận assignment user khác | ✅ Test `test_single_respond_cannot_modify_other_users_assignment` → 403 |
| Route auth:sanctum | ✅ `middleware('auth:sanctum')` |

## 5. Tests

| Test | Result |
|------|--------|
| `test_my_tasks_accept_all_route_exists` | ✅ PASS (20.18s) |
| `test_accept_all_accepts_only_current_user_pending_assignments` | ✅ PASS (0.19s) |
| `test_accept_all_ignores_completed_or_cancelled_tasks` | ✅ PASS (0.05s) |
| `test_accept_all_ignores_already_accepted_or_rejected_assignments` | ✅ PASS (0.05s) |
| `test_accept_all_returns_zero_when_no_pending` | ✅ PASS (0.04s) |
| `test_single_respond_accept_still_works` | ✅ PASS (0.06s) |
| `test_single_respond_reject_still_works` | ✅ PASS (0.05s) |
| `test_single_respond_cannot_modify_other_users_assignment` | ✅ PASS (0.04s) |
| `test_my_tasks_index_still_returns_assignments` | ✅ PASS (0.05s) |
| POS regression (Step246C) | ✅ 7/7 PASS |

All tests on real MySQL Docker DB (`sales_test` on port 3319). 9 passed, 20 assertions.

## 6. Production safety

| Mục | Trạng thái |
|-----|------------|
| Có migration không? | ❌ Không |
| Có sửa POS không? | ❌ Không |
| Có sửa invoices/customers không? | ❌ Không |
| Có ảnh hưởng stock/debt/serial/cost không? | ❌ Không |

## 7. Manual QA

- [ ] Nhận việc một task OK.
- [ ] Từ chối task OK.
- [ ] Nhận tất cả OK.
- [ ] Không 404.
- [ ] Không nhận hộ user khác.
- [ ] Console clean.

## 8. Conclusion

- Đã hết 404: **CÓ** — route đã đăng ký.
- Có thể deploy: **CÓ** — chỉ thêm 1 dòng route, không migration, không sửa business logic.
