<?php

namespace App\Services;

use App\Models\Damage;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockTake;
use App\Models\StockTransfer;
use App\Models\Task;

/**
 * STEP 24.7 — Resolve a stock-card transaction (doc_type, doc_id) to the
 * source voucher's `open_url` / `print_url` and the permission needed to
 * open it.
 *
 * Frontend never builds these URLs itself — it consumes the structured
 * `source_document` envelope this service produces. That keeps URL
 * conventions in one place and lets us hide URLs the user can't open.
 */
class DocumentLinkResolver
{
    /**
     * @return array{
     *   doc_type: string,
     *   doc_id: int|null,
     *   code: string|null,
     *   title: string|null,
     *   open_url: string|null,
     *   print_url: string|null,
     *   can_open: bool,
     *   can_print: bool,
     *   permission: string|null,
     *   missing_reason: string|null
     * }
     */
    public function resolve(string $docType, int $docId): array
    {
        $base = [
            'doc_type'       => $docType,
            'doc_id'         => $docId,
            'code'           => null,
            'title'          => null,
            'open_url'       => null,
            'print_url'      => null,
            'can_open'       => false,
            'can_print'      => false,
            'permission'     => null,
            'missing_reason' => null,
        ];

        // Resolve the model and the canonical URL contract per doc_type.
        switch ($docType) {
            case 'invoice':
                $model = Invoice::find($docId);
                if (!$model) {
                    return $this->notFound($base, 'Hóa đơn');
                }
                return $this->withPermission($base, $model, 'invoices.view', [
                    'title'     => 'Hóa đơn ' . $model->code,
                    'code'      => $model->code,
                    'open_url'  => route('invoices.show', $model),
                    'print_url' => route('invoices.print', $model),
                ]);

            case 'purchase':
                $model = Purchase::find($docId);
                if (!$model) {
                    return $this->notFound($base, 'Phiếu nhập');
                }
                return $this->withPermission($base, $model, 'purchases.view', [
                    'title'     => 'Phiếu nhập ' . $model->code,
                    'code'      => $model->code,
                    'open_url'  => route('purchases.show', $model),
                    'print_url' => null,
                ]);

            case 'return':
                $model = OrderReturn::find($docId);
                if (!$model) {
                    return $this->notFound($base, 'Phiếu trả hàng');
                }
                return $this->withPermission($base, $model, 'returns.view', [
                    'title'     => 'Phiếu trả hàng ' . $model->code,
                    'code'      => $model->code,
                    'open_url'  => route('returns.show', $model),
                    'print_url' => route('returns.print', $model),
                ]);

            case 'purchase_return':
                $model = PurchaseReturn::find($docId);
                if (!$model) {
                    return $this->notFound($base, 'Phiếu trả nhập');
                }
                return $this->withPermission($base, $model, 'purchases.view', [
                    'title'     => 'Phiếu trả nhập ' . $model->code,
                    'code'      => $model->code,
                    'open_url'  => route('purchase-returns.show', $model),
                    'print_url' => null,
                ]);

            case 'stock_take':
                $model = StockTake::find($docId);
                if (!$model) {
                    return $this->notFound($base, 'Phiếu kiểm kho');
                }
                return $this->withPermission($base, $model, 'stock_takes.view', [
                    'title'     => 'Phiếu kiểm kho ' . $model->code,
                    'code'      => $model->code,
                    'open_url'  => route('stock-takes.show', $model),
                    'print_url' => route('stock_takes.print', $model),
                ]);

            case 'transfer':
                $model = StockTransfer::find($docId);
                if (!$model) {
                    return $this->notFound($base, 'Phiếu chuyển kho');
                }
                return $this->withPermission($base, $model, 'stock_transfers.view', [
                    'title'     => 'Phiếu chuyển kho ' . $model->code,
                    'code'      => $model->code,
                    'open_url'  => route('stock-transfers.show', $model),
                    'print_url' => route('stock_transfers.print', $model),
                ]);

            case 'damage':
                $model = Damage::find($docId);
                if (!$model) {
                    return $this->notFound($base, 'Phiếu xuất hủy');
                }
                return $this->withPermission($base, $model, 'damages.view', [
                    'title'     => 'Phiếu xuất hủy ' . $model->code,
                    'code'      => $model->code,
                    'open_url'  => route('damages.show', $model),
                    'print_url' => route('damages.print', $model),
                ]);

            case 'repair_part':
            case 'disassemble_part':
                $model = Task::find($docId);
                if (!$model) {
                    return $this->notFound($base, $docType === 'repair_part' ? 'Phiếu sửa chữa' : 'Phiếu bóc tách');
                }
                $titlePrefix = $docType === 'repair_part' ? 'Phiếu sửa chữa' : 'Phiếu bóc tách';
                return $this->withPermission($base, $model, 'tasks.view', [
                    'title'     => $titlePrefix . ' ' . ($model->code ?? '#' . $model->id),
                    'code'      => $model->code,
                    'open_url'  => route('tasks.show', $model->id),
                    'print_url' => null,
                ]);

            default:
                return array_merge($base, [
                    'missing_reason' => 'Loại chứng từ "' . $docType . '" không được hỗ trợ.',
                ]);
        }
    }

    /**
     * Apply per-user permission gating. Hides the URL entirely when the
     * user lacks permission so the FE cannot bypass it by inspecting JSON.
     */
    private function withPermission(array $base, $model, string $permission, array $resolved): array
    {
        $merged = array_merge($base, $resolved, [
            'permission' => $permission,
            'can_open'   => true,
            'can_print'  => !empty($resolved['print_url']),
        ]);

        $user = auth()->user();
        if ($user && !$user->hasPermission($permission)) {
            return array_merge($merged, [
                'open_url'       => null,
                'print_url'      => null,
                'can_open'       => false,
                'can_print'      => false,
                'missing_reason' => 'Bạn không có quyền mở phiếu này.',
            ]);
        }

        return $merged;
    }

    private function notFound(array $base, string $kind): array
    {
        return array_merge($base, [
            'missing_reason' => 'Không tìm thấy ' . $kind . ' (đã bị xóa hoặc id không hợp lệ).',
        ]);
    }
}
