<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'employee_id',
        'action',
        'description',
        'subject_type',
        'subject_id',
        'properties',
        'ip_address',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    // ── Action constants ──

    // Nhập hàng
    const ACTION_PURCHASE_CREATE = 'purchase_create';
    const ACTION_PURCHASE_UPDATE = 'purchase_update';
    const ACTION_PURCHASE_DELETE = 'purchase_delete';

    // Công việc
    const ACTION_TASK_CREATE = 'task_create';
    const ACTION_TASK_ASSIGN = 'task_assign';
    const ACTION_TASK_ACCEPT = 'task_accept';
    const ACTION_TASK_REJECT = 'task_reject';
    const ACTION_TASK_COMPLETE = 'task_complete';
    const ACTION_TASK_CANCEL = 'task_cancel';
    const ACTION_TASK_PROGRESS = 'task_progress';

    // Linh kiện
    const ACTION_PART_INSTALL = 'part_install';
    const ACTION_PART_REMOVE = 'part_remove';
    const ACTION_PART_DISASSEMBLE = 'part_disassemble';

    // Bình luận / Ghi chú
    const ACTION_COMMENT_ADD = 'comment_add';

    // Đăng nhập
    const ACTION_LOGIN = 'login';
    const ACTION_LOGOUT = 'logout';

    // ── Label map (Vietnamese) ──
    const ACTION_LABELS = [
        'purchase_create'   => 'Tạo phiếu nhập hàng',
        'purchase_update'   => 'Cập nhật phiếu nhập',
        'purchase_delete'   => 'Xoá phiếu nhập',
        'task_create'       => 'Tạo công việc',
        'task_assign'       => 'Giao việc',
        'task_accept'       => 'Nhận việc',
        'task_reject'       => 'Từ chối việc',
        'task_complete'     => 'Hoàn thành công việc',
        'task_cancel'       => 'Huỷ công việc',
        'task_progress'     => 'Cập nhật tiến độ',
        'part_install'      => 'Lắp linh kiện',
        'part_remove'       => 'Gỡ linh kiện',
        'part_disassemble'  => 'Bóc linh kiện từ máy',
        'comment_add'       => 'Thêm ghi chú',
        'login'             => 'Đăng nhập',
        'logout'            => 'Đăng xuất',
    ];

    // ── Icon map (emoji) ──
    const ACTION_ICONS = [
        'purchase_create'   => '📦',
        'purchase_update'   => '✏️',
        'purchase_delete'   => '🗑️',
        'task_create'       => '📋',
        'task_assign'       => '👤',
        'task_accept'       => '✅',
        'task_reject'       => '❌',
        'task_complete'     => '🎉',
        'task_cancel'       => '🚫',
        'task_progress'     => '📊',
        'part_install'      => '🔧',
        'part_remove'       => '↩️',
        'part_disassemble'  => '🔩',
        'comment_add'       => '💬',
        'login'             => '🔑',
        'logout'            => '🚪',
    ];

    // ── Relationships ──

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function subject()
    {
        return $this->morphTo();
    }

    // ── Helpers ──

    public function getActionLabelAttribute(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    public function getActionIconAttribute(): string
    {
        return self::ACTION_ICONS[$this->action] ?? '📝';
    }

    // ── Static factory ──

    public static function log(
        string $action,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?int $userId = null,
        ?int $employeeId = null
    ): self {
        $user = $userId ? null : auth()->user();
        $resolvedUserId = $userId ?? $user?->id;
        $resolvedEmployeeId = $employeeId ?? $user?->employee?->id;

        return self::create([
            'user_id'      => $resolvedUserId,
            'employee_id'  => $resolvedEmployeeId,
            'action'       => $action,
            'description'  => $description,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id'   => $subject?->id,
            'properties'   => $properties ?: null,
            'ip_address'   => request()?->ip(),
        ]);
    }
}
