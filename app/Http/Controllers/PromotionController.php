<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\LockPeriodService;

class PromotionController extends Controller
{
    public function index(Request $request)
    {
        $query = Promotion::withCount('usages')
            ->when($request->search, fn($q, $s) => $q->where('name', 'LIKE', "%{$s}%")->orWhere('code', 'LIKE', "%{$s}%"))
            ->when($request->status, fn($q, $s) => $q->whereIn('status', (array) $s))
            ->when($request->type, fn($q, $t) => $q->where('type', $t))
            ->orderBy('created_at', 'desc');

        return Inertia::render('Promotions/Index', [
            'promotions' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only(['search', 'status', 'type']),
        ]);
    }

    public function show(Promotion $promotion)
    {
        $promotion->load(['targetProduct', 'giftProduct', 'usages.invoice', 'usages.order', 'usages.customer']);
        return Inertia::render('Promotions/Show', ['promotion' => $promotion]);
    }

    public function store(Request $request)
    {
        if (!Setting::get('promotion_enabled', true)) {
            return back()->with('error', 'Chức năng khuyến mại đã bị tắt.');
        }

        $validated = $request->validate([
            'code' => 'required|string|unique:promotions,code',
            'name' => 'required|string',
            'type' => 'required|in:invoice_discount,product_discount,gift_item',
            'status' => 'nullable|in:draft,active,expired,disabled',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'condition_type' => 'nullable|in:none,min_amount,min_qty',
            'condition_value' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:percent,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'target_product_id' => 'nullable|exists:products,id',
            'gift_product_id' => 'nullable|exists:products,id',
            'max_usage' => 'nullable|integer|min:1',
            'allow_stacking' => 'nullable|boolean',
            'branch_scope' => 'nullable|array',
            'customer_group_scope' => 'nullable|array',
            'note' => 'nullable|string',
        ]);

        $promotion = Promotion::create($validated);

        ActivityLog::log('promo_create', "Tạo CTKM {$promotion->code}: {$promotion->name}", $promotion);

        if ($request->wantsJson()) {
            return response()->json(['id' => $promotion->id, 'code' => $promotion->code]);
        }
        return redirect()->route('promotions.index')->with('success', "Tạo CTKM {$promotion->code} thành công.");
    }

    public function update(Request $request, Promotion $promotion)
    {
        $hasTransactions = $promotion->hasTransactions();

        $validated = $request->validate([
            'name' => 'sometimes|string',
            'status' => 'sometimes|in:draft,active,expired,disabled',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'note' => 'nullable|string',
            'branch_scope' => 'nullable|array',
            'customer_group_scope' => 'nullable|array',
            // Business fields — blocked if has transactions
            'condition_type' => 'sometimes|in:none,min_amount,min_qty',
            'condition_value' => 'sometimes|numeric|min:0',
            'discount_type' => 'sometimes|in:percent,fixed',
            'discount_value' => 'sometimes|numeric|min:0',
            'type' => 'sometimes|in:invoice_discount,product_discount,gift_item',
        ]);

        // If has transactions, block business-rule changes
        if ($hasTransactions) {
            $businessFields = ['condition_type', 'condition_value', 'discount_type', 'discount_value', 'type', 'target_product_id', 'gift_product_id'];
            foreach ($businessFields as $f) {
                unset($validated[$f]);
            }
        }

        $promotion->update($validated);
        ActivityLog::log('promo_update', "Cập nhật CTKM {$promotion->code}", $promotion);

        return back()->with('success', 'Cập nhật thành công.');
    }

    public function destroy(Promotion $promotion)
    {
        if ($promotion->hasTransactions()) {
            return back()->with('error', 'Không thể xóa CTKM đã có giao dịch.');
        }

        $code = $promotion->code;
        $promotion->delete();
        ActivityLog::log('promo_delete', "Xóa CTKM {$code}");

        return back()->with('success', "Đã xóa CTKM {$code}.");
    }

    public function copy(Promotion $promotion)
    {
        $newPromo = $promotion->replicate(['usage_count']);
        $newPromo->code = 'KM' . time() . rand(10, 99);
        $newPromo->usage_count = 0;
        $newPromo->status = 'draft';
        $newPromo->save();

        ActivityLog::log('promo_copy', "Sao chép CTKM {$promotion->code} → {$newPromo->code}", $newPromo);

        return back()->with('success', "Đã sao chép → {$newPromo->code}.");
    }

    /**
     * Check eligible promotions for invoice context.
     */
    public function checkEligibility(Request $request)
    {
        $subtotal = (float) $request->input('subtotal', 0);
        $qty = (int) $request->input('qty', 0);
        $branchId = $request->input('branch_id');
        $customerGroup = $request->input('customer_group');

        $promos = Promotion::currentlyValid()->get()->filter(function ($p) use ($subtotal, $qty, $branchId, $customerGroup) {
            return $p->isEligible($subtotal, $qty, $branchId, $customerGroup);
        })->values();

        return response()->json(['eligible' => $promos]);
    }

    /**
     * Apply promotion to invoice/order.
     */
    public function apply(Request $request, Promotion $promotion)
    {
        $invoiceId = $request->input('invoice_id');
        $orderId = $request->input('order_id');
        $customerId = $request->input('customer_id');
        $subtotal = (float) $request->input('subtotal', 0);

        $discountAmount = $promotion->calculateDiscount($subtotal);

        // Check stacking
        $allowStacking = Setting::get('promotion_allow_stacking', false);
        if (!$allowStacking && $invoiceId) {
            $existingUsage = PromotionUsage::where('invoice_id', $invoiceId)->exists();
            if ($existingUsage) {
                return response()->json(['error' => 'Không cho phép áp dụng nhiều CTKM cùng lúc.'], 422);
            }
        }

        $usage = PromotionUsage::create([
            'promotion_id' => $promotion->id,
            'invoice_id' => $invoiceId,
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'discount_amount' => $discountAmount,
        ]);

        $promotion->increment('usage_count');

        ActivityLog::log('promo_apply', "Áp dụng CTKM {$promotion->code}, giảm {$discountAmount}", $promotion);

        return response()->json([
            'usage_id' => $usage->id,
            'discount_amount' => $discountAmount,
        ]);
    }

    public function export(Request $request)
    {
        $promos = Promotion::withCount('usages')
            ->when($request->status, fn($q, $s) => $q->whereIn('status', (array) $s))
            ->orderBy('created_at', 'desc')->get();

        return \App\Services\CsvService::export(
            ['Mã', 'Tên', 'Loại', 'Trạng thái', 'Giảm giá', 'Đã dùng', 'Bắt đầu', 'Kết thúc'],
            $promos->map(fn($p) => [
                $p->code, $p->name, $p->type, $p->status,
                ($p->discount_type === 'percent' ? $p->discount_value . '%' : number_format($p->discount_value)),
                $p->usages_count, $p->start_date?->format('d/m/Y'), $p->end_date?->format('d/m/Y'),
            ]),
            'khuyen_mai.csv'
        );
    }
}
