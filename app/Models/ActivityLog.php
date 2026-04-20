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

    // Bán hàng
    const ACTION_INVOICE_CREATE = 'invoice_create';
    const ACTION_INVOICE_CANCEL = 'invoice_cancel';
    const ACTION_RETURN_CREATE = 'return_create';

    // Sổ quỹ
    const ACTION_CASHFLOW_CREATE = 'cashflow_create';
    const ACTION_CASHFLOW_CANCEL = 'cashflow_cancel';
    const ACTION_CASHFLOW_TRANSFER = 'cashflow_transfer';

    // Chuyển kho
    const ACTION_TRANSFER_CREATE = 'transfer_create';
    const ACTION_TRANSFER_RECEIVE = 'transfer_receive';
    const ACTION_TRANSFER_CANCEL = 'transfer_cancel';

    // Kiểm kho
    const ACTION_STOCKTAKE_CREATE = 'stocktake_create';
    const ACTION_STOCKTAKE_COMPLETE = 'stocktake_complete';
    const ACTION_STOCKTAKE_CANCEL = 'stocktake_cancel';

    // Dữ liệu chính
    const ACTION_PRODUCT_UPDATE = 'product_update';
    const ACTION_CUSTOMER_UPDATE = 'customer_update';
    const ACTION_SUPPLIER_UPDATE = 'supplier_update';

    // Khóa sổ
    const ACTION_LOCK_PERIOD_CHANGE = 'lock_period_change';

    // Đặt hàng
    const ACTION_ORDER_CREATE = 'order_create';
    const ACTION_ORDER_UPDATE = 'order_update';
    const ACTION_ORDER_CANCEL = 'order_cancel';
    const ACTION_ORDER_END = 'order_end';
    const ACTION_ORDER_CONVERT = 'order_convert';
    const ACTION_ORDER_MERGE = 'order_merge';

    // Đặt hàng nhập
    const ACTION_PO_CREATE = 'po_create';
    const ACTION_PO_UPDATE = 'po_update';
    const ACTION_PO_CANCEL = 'po_cancel';
    const ACTION_PO_FINISH = 'po_finish';
    const ACTION_PO_COPY = 'po_copy';
    const ACTION_PO_CONVERT = 'po_convert';

    // Vận đơn
    const ACTION_WAYBILL_CREATE = 'waybill_create';
    const ACTION_WAYBILL_STATUS = 'waybill_status';
    const ACTION_WAYBILL_CANCEL = 'waybill_cancel';
    const ACTION_WAYBILL_REBOOK = 'waybill_rebook';
    const ACTION_WAYBILL_BULK = 'waybill_bulk';
    const ACTION_WAYBILL_CARRIER_BOOK = 'waybill_carrier_book';
    const ACTION_WAYBILL_CARRIER_CANCEL = 'waybill_carrier_cancel';
    const ACTION_WAYBILL_RTS = 'waybill_rts';
    const ACTION_WAYBILL_RTS_PENDING = 'waybill_rts_pending';

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
        'invoice_create'    => 'Tạo hóa đơn',
        'invoice_cancel'    => 'Hủy hóa đơn',
        'return_create'     => 'Tạo phiếu trả hàng',
        'cashflow_create'   => 'Tạo phiếu thu/chi',
        'cashflow_cancel'   => 'Hủy phiếu thu/chi',
        'cashflow_transfer' => 'Chuyển quỹ',
        'transfer_create'   => 'Tạo phiếu chuyển kho',
        'transfer_receive'  => 'Nhận chuyển kho',
        'transfer_cancel'   => 'Hủy chuyển kho',
        'stocktake_create'  => 'Tạo phiếu kiểm kho',
        'stocktake_complete'=> 'Cân bằng kho',
        'stocktake_cancel'  => 'Hủy kiểm kho',
        'product_update'    => 'Cập nhật hàng hóa',
        'customer_update'   => 'Cập nhật khách hàng',
        'supplier_update'   => 'Cập nhật nhà cung cấp',
        'lock_period_change'=> 'Thay đổi khóa sổ',
        'order_create'      => 'Tạo đơn hàng',
        'order_update'      => 'Cập nhật đơn hàng',
        'order_cancel'      => 'Hủy đơn hàng',
        'order_end'         => 'Kết thúc đơn hàng',
        'order_convert'     => 'Chuyển đơn → hóa đơn',
        'order_merge'       => 'Gộp đơn hàng',
        'po_create'         => 'Tạo đặt hàng nhập',
        'po_update'         => 'Cập nhật đặt hàng nhập',
        'po_cancel'         => 'Hủy đặt hàng nhập',
        'po_finish'         => 'Kết thúc đặt hàng nhập',
        'po_copy'           => 'Sao chép đặt hàng nhập',
        'po_convert'        => 'Tạo phiếu nhập từ đặt hàng',
        'waybill_create'    => 'Tạo vận đơn',
        'waybill_status'    => 'Cập nhật trạng thái vận đơn',
        'waybill_cancel'    => 'Hủy vận đơn',
        'waybill_rebook'    => 'Tạo lại vận đơn',
        'waybill_bulk'      => 'Cập nhật hàng loạt vận đơn',
        'waybill_carrier_book'   => 'Đặt vận đơn qua đối tác',
        'waybill_carrier_cancel' => 'Hủy vận đơn qua đối tác',
        'waybill_rts'       => 'Chuyển hoàn tự động',
        'waybill_rts_pending'=> 'Chuyển hoàn chờ xác nhận',
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
        'invoice_create'    => '🧾',
        'invoice_cancel'    => '🚫',
        'return_create'     => '↩️',
        'cashflow_create'   => '💰',
        'cashflow_cancel'   => '🚫',
        'cashflow_transfer' => '🔄',
        'transfer_create'   => '🚚',
        'transfer_receive'  => '📥',
        'transfer_cancel'   => '🚫',
        'stocktake_create'  => '📋',
        'stocktake_complete'=> '✅',
        'stocktake_cancel'  => '🚫',
        'product_update'    => '📦',
        'customer_update'   => '👤',
        'supplier_update'   => '🏭',
        'lock_period_change'=> '🔒',
        'order_create'      => '📝',
        'order_update'      => '✏️',
        'order_cancel'      => '❌',
        'order_end'         => '🏁',
        'order_convert'     => '➡️',
        'order_merge'       => '🔀',
        'po_create'         => '📦',
        'po_update'         => '✏️',
        'po_cancel'         => '❌',
        'po_finish'         => '🏁',
        'po_copy'           => '📋',
        'po_convert'        => '➡️',
        'waybill_create'    => '🚚',
        'waybill_status'    => '📊',
        'waybill_cancel'    => '❌',
        'waybill_rebook'    => '🔄',
        'waybill_bulk'      => '📋',
        'waybill_carrier_book'   => '📡',
        'waybill_carrier_cancel' => '📡',
        'waybill_rts'       => '↩️',
        'waybill_rts_pending'=> '⏳',
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
