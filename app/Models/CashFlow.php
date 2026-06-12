<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashFlow extends Model
{
    use SoftDeletes;

    /**
     * Override soft-delete cho single model: tự động set status='cancelled'.
     * Bắt khi gọi $cashFlow->delete() trên 1 instance.
     */
    protected function runSoftDelete()
    {
        $query = $this->setKeysForSaveQuery($this->newModelQuery());

        $time = $this->freshTimestamp();
        $columns = [$this->getDeletedAtColumn() => $this->fromDateTime($time)];

        // Tự động set status='cancelled' khi soft-delete
        $columns['status'] = 'cancelled';

        $this->{$this->getDeletedAtColumn()} = $time;
        $this->status = 'cancelled';

        if ($this->usesTimestamps() && ! is_null($this->getUpdatedAtColumn())) {
            $this->{$this->getUpdatedAtColumn()} = $time;
            $columns[$this->getUpdatedAtColumn()] = $this->fromDateTime($time);
        }

        $query->update($columns);

        $this->syncOriginalAttributes(array_keys($columns));

        $this->fireModelEvent('trashed', false);
    }

    /**
     * Override Eloquent builder để mass soft-delete cũng set status='cancelled'.
     * Bắt khi gọi CashFlow::where(...)->delete() (mass operation).
     */
    public function newEloquentBuilder($query)
    {
        return new class($query) extends Builder {
            public function delete()
            {
                if ($this->model && method_exists($this->model, 'getDeletedAtColumn')) {
                    // Mass soft-delete: cập nhật status='cancelled' cùng lúc với deleted_at
                    $column = $this->model->getDeletedAtColumn();
                    return $this->toBase()->update([
                        $column   => $this->model->freshTimestampString(),
                        'status'  => 'cancelled',
                    ]);
                }

                return parent::delete();
            }
        };
    }

    /**
     * Scope: chỉ lấy phiếu thu/chi hợp lệ (loại trừ status = 'cancelled').
     *
     * Dùng trong báo cáo chi phí/lợi nhuận để không tính phiếu đã hủy.
     * KHÔNG dùng global scope vì CashFlowController cần xem cả phiếu hủy.
     * An toàn với withTrashed(): lọc cả status và deleted_at.
     */
    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'cancelled')
                     ->whereNull('deleted_at');
    }

    protected $fillable = [
        'code',
        'type',
        'amount',
        'time',
        'category',
        'target_type',
        'target_id',
        'target_name',
        'accounting_result',
        'payment_method',
        'bank_account_id',
        'reference_type',
        'reference_code',
        'description',
        'status',
    ];

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function customerPaymentAllocations()
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }
}
