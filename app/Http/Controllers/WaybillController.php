<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Waybill;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Customer;
use App\Models\Branch;
use App\Models\Setting;
use App\Models\ActivityLog;
use App\Models\CustomerDeliveryAddress;
use App\Services\LockPeriodService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class WaybillController extends Controller
{
    /**
     * List waybills with filters.
     */
    public function index(Request $request)
    {
        $query = Waybill::with(['invoice', 'order', 'customer', 'branch'])
            ->when($request->filled('sort_by'), function ($q) use ($request) {
                $allowed = ['code', 'created_at', 'status', 'delivery_fee', 'cod_amount'];
                $sortBy = in_array($request->sort_by, $allowed) ? $request->sort_by : 'created_at';
                $dir = $request->sort_direction === 'asc' ? 'asc' : 'desc';
                $q->orderBy($sortBy, $dir);
            }, fn($q) => $q->orderBy('created_at', 'desc'));

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('code', 'like', "%{$s}%")
                  ->orWhere('tracking_code', 'like', "%{$s}%")
                  ->orWhereHas('invoice', fn($q2) => $q2->where('code', 'like', "%{$s}%"));
            });
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->status);
        }

        if ($request->filled('partner_type')) {
            $query->where('partner_type', $request->partner_type);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $waybills = $query->paginate(20)->withQueryString();

        return Inertia::render('Waybills/Index', [
            'waybills' => $waybills,
            'branches' => Branch::all(),
            'filters' => $request->only(['search', 'status', 'partner_type', 'customer_id', 'branch_id', 'sort_by', 'sort_direction']),
        ]);
    }

    /**
     * Show waybill detail.
     */
    public function show(Waybill $waybill)
    {
        $waybill->load(['invoice.items.product', 'order.items.product', 'customer', 'branch']);

        // Get all waybills for this invoice (history)
        $history = [];
        if ($waybill->invoice_id) {
            $history = Waybill::where('invoice_id', $waybill->invoice_id)->orderBy('created_at')->get();
        } elseif ($waybill->order_id) {
            $history = Waybill::where('order_id', $waybill->order_id)->orderBy('created_at')->get();
        }

        return Inertia::render('Waybills/Show', [
            'waybill' => $waybill,
            'history' => $history,
        ]);
    }

    /**
     * Create waybill from invoice or order.
     */
    public function store(Request $request)
    {
        if (!Setting::get('delivery_enabled', true)) {
            return back()->with('error', 'Chức năng giao hàng đã bị tắt.');
        }

        $validated = $request->validate([
            'invoice_id' => 'nullable|exists:invoices,id',
            'order_id' => 'nullable|exists:orders,id',
            'customer_id' => 'nullable|exists:customers,id',
            'branch_id' => 'nullable|exists:branches,id',
            'partner_type' => 'required|in:self_delivery,integrated',
            'partner_name' => 'nullable|string',
            'carrier_service' => 'nullable|string',
            'receiver_name' => 'required|string',
            'receiver_phone' => 'required|string',
            'receiver_address' => 'required|string',
            'receiver_ward' => 'nullable|string',
            'receiver_district' => 'nullable|string',
            'receiver_city' => 'nullable|string',
            'pickup_address' => 'nullable|string',
            'weight' => 'nullable|integer|min:1',
            'length' => 'nullable|integer|min:1',
            'width' => 'nullable|integer|min:1',
            'height' => 'nullable|integer|min:1',
            'delivery_fee' => 'nullable|numeric|min:0',
            'cod_amount' => 'nullable|numeric|min:0',
            'declared_value' => 'nullable|numeric|min:0',
            'delivery_note' => 'nullable|string',
        ]);

        // Lock period check
        app(LockPeriodService::class)->assertNotLocked(now(), 'waybill_create');

        // Deactivate existing active waybills for same invoice/order
        if (!empty($validated['invoice_id'])) {
            Waybill::where('invoice_id', $validated['invoice_id'])->active()->update(['is_active' => false]);
        }
        if (!empty($validated['order_id'])) {
            Waybill::where('order_id', $validated['order_id'])->active()->update(['is_active' => false]);
        }

        // Set defaults
        $branch = Branch::find($validated['branch_id'] ?? null);

        $waybill = Waybill::create([
            'code' => 'VD' . time() . rand(10, 99),
            'invoice_id' => $validated['invoice_id'] ?? null,
            'order_id' => $validated['order_id'] ?? null,
            'customer_id' => $validated['customer_id'] ?? null,
            'branch_id' => $validated['branch_id'] ?? null,
            'partner_type' => $validated['partner_type'],
            'partner_name' => $validated['partner_name'] ?? null,
            'carrier_service' => $validated['carrier_service'] ?? null,
            'tracking_code' => $validated['partner_type'] === 'integrated' ? 'TK' . time() : null,
            'status' => Waybill::STATUS_PENDING,
            'receiver_name' => $validated['receiver_name'],
            'receiver_phone' => $validated['receiver_phone'],
            'receiver_address' => $validated['receiver_address'],
            'receiver_ward' => $validated['receiver_ward'] ?? null,
            'receiver_district' => $validated['receiver_district'] ?? null,
            'receiver_city' => $validated['receiver_city'] ?? null,
            'pickup_address' => $validated['pickup_address'] ?? ($branch->address ?? null),
            'weight' => $validated['weight'] ?? 500,
            'length' => $validated['length'] ?? 10,
            'width' => $validated['width'] ?? 10,
            'height' => $validated['height'] ?? 10,
            'delivery_fee' => $validated['delivery_fee'] ?? 0,
            'cod_amount' => $validated['cod_amount'] ?? 0,
            'declared_value' => $validated['declared_value'] ?? 0,
            'delivery_note' => $validated['delivery_note'] ?? null,
            'is_active' => true,
        ]);

        // For integrated carriers, log the booking request (stub)
        if ($validated['partner_type'] === 'integrated') {
            ActivityLog::log('waybill_carrier_book', "Gửi yêu cầu đặt vận đơn {$waybill->code} qua đối tác {$validated['carrier_service']}", $waybill);
        }

        ActivityLog::log('waybill_create', "Tạo vận đơn {$waybill->code}", $waybill);

        if ($request->wantsJson()) {
            return response()->json(['id' => $waybill->id, 'code' => $waybill->code]);
        }

        return back()->with('success', "Tạo vận đơn {$waybill->code} thành công.");
    }

    /**
     * Manual status update (self-delivery only).
     */
    public function updateStatus(Request $request, Waybill $waybill)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,waiting_pickup,in_transit,delivered,returning,returned,canceled,failed',
            'tracking_code' => 'nullable|string',
            'delivery_fee' => 'nullable|numeric|min:0',
            'delivery_note' => 'nullable|string',
        ]);

        if ($waybill->isTerminal() && $validated['status'] !== $waybill->status) {
            return back()->with('error', 'Vận đơn đã ở trạng thái cuối, không thể cập nhật.');
        }

        $oldStatus = $waybill->status;
        $waybill->update(array_filter($validated, fn($v) => $v !== null));

        // Handle RTS
        if ($validated['status'] === Waybill::STATUS_RETURNED) {
            $autoUpdate = Setting::get('delivery_rts_auto_update', true);
            if ($autoUpdate) {
                // Auto-update: mark as returned immediately
                ActivityLog::log('waybill_rts', "Vận đơn {$waybill->code} đã chuyển hoàn — tự động cập nhật", $waybill);
            } else {
                ActivityLog::log('waybill_rts_pending', "Vận đơn {$waybill->code} đã chuyển hoàn — chờ xác nhận", $waybill);
            }
        }

        ActivityLog::log('waybill_status', "Cập nhật vận đơn {$waybill->code}: {$oldStatus} → {$validated['status']}", $waybill);

        return back()->with('success', 'Cập nhật vận đơn thành công.');
    }

    /**
     * Cancel waybill.
     */
    public function cancel(Request $request, Waybill $waybill)
    {
        if ($waybill->status === Waybill::STATUS_CANCELED) {
            return back()->with('error', 'Vận đơn đã bị hủy trước đó.');
        }

        if ($waybill->status === Waybill::STATUS_DELIVERED) {
            return back()->with('error', 'Không thể hủy vận đơn đã giao thành công.');
        }

        $waybill->update([
            'status' => Waybill::STATUS_CANCELED,
            'is_active' => false,
            'cancel_reason' => $request->reason ?? null,
        ]);

        // For integrated carriers, log the cancellation request
        if ($waybill->partner_type === Waybill::PARTNER_INTEGRATED) {
            ActivityLog::log('waybill_carrier_cancel', "Gửi yêu cầu hủy vận đơn {$waybill->code} qua đối tác", $waybill);
        }

        ActivityLog::log('waybill_cancel', "Hủy vận đơn {$waybill->code}", $waybill);

        return back()->with('success', 'Đã hủy vận đơn.');
    }

    /**
     * Rebook — create new waybill for same invoice after cancel/failure.
     */
    public function rebook(Request $request, Waybill $waybill)
    {
        if ($waybill->is_active && !in_array($waybill->status, [Waybill::STATUS_CANCELED, Waybill::STATUS_FAILED])) {
            return back()->with('error', 'Cần hủy vận đơn hiện tại trước khi tạo mới.');
        }

        // Deactivate old
        $waybill->update(['is_active' => false]);

        // Create new with same receiver info
        $newWaybill = Waybill::create([
            'code' => 'VD' . time() . rand(10, 99),
            'invoice_id' => $waybill->invoice_id,
            'order_id' => $waybill->order_id,
            'customer_id' => $waybill->customer_id,
            'branch_id' => $waybill->branch_id,
            'partner_type' => $request->input('partner_type', $waybill->partner_type),
            'partner_name' => $request->input('partner_name', $waybill->partner_name),
            'carrier_service' => $request->input('carrier_service', $waybill->carrier_service),
            'status' => Waybill::STATUS_PENDING,
            'receiver_name' => $waybill->receiver_name,
            'receiver_phone' => $waybill->receiver_phone,
            'receiver_address' => $waybill->receiver_address,
            'receiver_ward' => $waybill->receiver_ward,
            'receiver_district' => $waybill->receiver_district,
            'receiver_city' => $waybill->receiver_city,
            'pickup_address' => $waybill->pickup_address,
            'weight' => $waybill->weight,
            'length' => $waybill->length,
            'width' => $waybill->width,
            'height' => $waybill->height,
            'delivery_fee' => $request->input('delivery_fee', $waybill->delivery_fee),
            'cod_amount' => $waybill->cod_amount,
            'declared_value' => $waybill->declared_value,
            'delivery_note' => $request->input('delivery_note', $waybill->delivery_note),
            'is_active' => true,
        ]);

        ActivityLog::log('waybill_rebook', "Tạo lại vận đơn {$newWaybill->code} thay thế {$waybill->code}", $newWaybill);

        return back()->with('success', "Đã tạo vận đơn mới {$newWaybill->code}.");
    }

    /**
     * Bulk update status for self-delivery waybills.
     */
    public function bulkUpdate(Request $request)
    {
        $request->validate([
            'waybill_ids' => 'required|array|min:1',
            'waybill_ids.*' => 'exists:waybills,id',
            'status' => 'required|in:pending,waiting_pickup,in_transit,delivered,returning,returned,canceled,failed',
        ]);

        $waybills = Waybill::whereIn('id', $request->waybill_ids)->get();
        $updated = 0;

        foreach ($waybills as $wb) {
            // Only allow bulk update on self-delivery, non-terminal waybills
            if ($wb->isSelfDelivery() && !$wb->isTerminal()) {
                $wb->update(['status' => $request->status]);
                $updated++;
            }
        }

        ActivityLog::log('waybill_bulk', "Cập nhật hàng loạt {$updated} vận đơn → {$request->status}");

        return back()->with('success', "Đã cập nhật {$updated} vận đơn.");
    }

    /**
     * Export waybills to CSV.
     */
    public function export(Request $request)
    {
        $waybills = Waybill::with(['invoice', 'customer'])
            ->when($request->search, fn($q, $s) => $q->where('code', 'LIKE', "%{$s}%"))
            ->when($request->status, fn($q, $s) => $q->whereIn('status', (array) $s))
            ->orderBy('created_at', 'desc')->get();

        return \App\Services\CsvService::export(
            ['Mã vận đơn', 'Mã hóa đơn', 'Khách hàng', 'Loại đối tác', 'Đối tác', 'Trạng thái', 'Mã vận chuyển', 'Phí ship', 'COD', 'Người nhận', 'SĐT', 'Địa chỉ', 'Ngày tạo'],
            $waybills->map(fn($w) => [
                $w->code, $w->invoice?->code, $w->customer?->name,
                $w->partner_type, $w->partner_name, $w->status,
                $w->tracking_code, $w->delivery_fee, $w->cod_amount,
                $w->receiver_name, $w->receiver_phone, $w->receiver_address,
                $w->created_at?->format('d/m/Y H:i'),
            ]),
            'van_don.csv'
        );
    }

    /**
     * Print shipping slip.
     */
    public function print(Waybill $waybill)
    {
        $waybill->load(['invoice.items.product', 'customer', 'branch']);
        return view('prints.waybill', compact('waybill'));
    }
}
