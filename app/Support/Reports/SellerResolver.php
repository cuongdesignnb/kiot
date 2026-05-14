<?php

namespace App\Support\Reports;

use App\Models\Employee;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HOTFIX 24.26 — Centralised seller resolution for all report controllers.
 *
 * Seller keys use prefixed strings to avoid ambiguity between
 * employees.id and users.id:
 *
 *   employee:<id>   — invoice created by an employee
 *   user:<id>       — invoice created by a user (admin/staff without employee row)
 *   orphan:<name>   — created_by IS NULL, created_by_name doesn't match any user
 *   unknown         — no creator info at all
 */
class SellerResolver
{
    // ═══════════════════════════════════════
    // Key generation
    // ═══════════════════════════════════════

    /**
     * Build a seller-key map for every invoice in $query.
     * Returns: array<invoice_id, seller_key>
     */
    public function invoiceSellerMap($query): array
    {
        $invoices = (clone $query)
            ->select('id', 'created_by', 'created_by_name', 'employee_id')
            ->get();

        if ($invoices->isEmpty()) return [];

        // Preload lookup tables
        $createdByIds = $invoices->pluck('created_by')->filter()->unique()->values()->all();
        $employeeIds  = $invoices->pluck('employee_id')->filter()->unique()->values()->all();
        $allIds       = array_unique(array_merge($createdByIds, $employeeIds));

        $employeeIdSet = !empty($allIds)
            ? Employee::whereIn('id', $allIds)->pluck('id')->flip()->all()
            : [];
        $userIdSet = !empty($allIds)
            ? User::whereIn('id', $allIds)->pluck('id')->flip()->all()
            : [];

        $orphanNames = $invoices->whereNull('created_by')
            ->pluck('created_by_name')->filter()->unique()->values()->all();
        $userByName = !empty($orphanNames)
            ? User::whereIn('name', $orphanNames)->pluck('id', 'name')->all()
            : [];

        $map = [];
        foreach ($invoices as $inv) {
            $map[$inv->id] = $this->resolveKey(
                $inv->created_by,
                $inv->created_by_name,
                $inv->employee_id,
                $employeeIdSet,
                $userIdSet,
                $userByName
            );
        }
        return $map;
    }

    /**
     * Determine seller key for a single invoice record.
     */
    private function resolveKey($createdBy, $createdByName, $employeeId, $empSet, $userSet, $userByName): string
    {
        // Priority 1: created_by present
        if ($createdBy !== null && $createdBy !== '') {
            $id = (int) $createdBy;
            if (isset($empSet[$id])) return "employee:{$id}";
            if (isset($userSet[$id])) return "user:{$id}";
            return "orphan:User #{$id}";
        }

        // Priority 2: orphan with created_by_name
        if ($createdByName !== null && $createdByName !== '') {
            $userId = $userByName[$createdByName] ?? null;
            if ($userId) return "user:{$userId}";
            return "orphan:{$createdByName}";
        }

        return 'unknown';
    }

    // ═══════════════════════════════════════
    // Aggregation helpers
    // ═══════════════════════════════════════

    /**
     * Aggregate a per-invoice expression grouped by seller key.
     * Returns: array<seller_key, float>
     */
    public function aggregateBySeller($invoiceQuery, string $valueExpr): array
    {
        $sellerMap = $this->invoiceSellerMap($invoiceQuery);
        if (empty($sellerMap)) return [];

        $invoiceIds = array_keys($sellerMap);
        $rows = DB::table('invoices')
            ->whereIn('id', $invoiceIds)
            ->select('id', DB::raw("({$valueExpr}) as val"))
            ->groupBy('id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $key = $sellerMap[$row->id] ?? 'unknown';
            $result[$key] = ($result[$key] ?? 0) + (float) $row->val;
        }
        return $result;
    }

    /**
     * Aggregate invoice_items expression grouped by seller key.
     */
    public function aggregateItemsBySeller($invoiceQuery, string $itemExpr): array
    {
        $sellerMap = $this->invoiceSellerMap($invoiceQuery);
        if (empty($sellerMap)) return [];

        $invoiceIds = array_keys($sellerMap);
        $rows = DB::table('invoice_items')
            ->whereIn('invoice_id', $invoiceIds)
            ->select('invoice_id', DB::raw("({$itemExpr}) as val"))
            ->groupBy('invoice_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $key = $sellerMap[$row->invoice_id] ?? 'unknown';
            $result[$key] = ($result[$key] ?? 0) + (float) $row->val;
        }
        return $result;
    }

    /**
     * Aggregate return-level expression grouped by original invoice's seller.
     */
    public function aggregateReturnsBySeller($returnQuery, string $valueExpr): array
    {
        $returnRows = (clone $returnQuery)->select('id', 'invoice_id')->get();
        if ($returnRows->isEmpty()) return [];

        $invoiceIds = $returnRows->pluck('invoice_id')->filter()->unique()->values()->all();
        if (empty($invoiceIds)) return [];

        // Build seller map for the original invoices
        $invoices = Invoice::whereIn('id', $invoiceIds)
            ->select('id', 'created_by', 'created_by_name', 'employee_id')
            ->get();

        $createdByIds = $invoices->pluck('created_by')->filter()->unique()->values()->all();
        $employeeIds  = $invoices->pluck('employee_id')->filter()->unique()->values()->all();
        $allIds       = array_unique(array_merge($createdByIds, $employeeIds));

        $empSet    = !empty($allIds) ? Employee::whereIn('id', $allIds)->pluck('id')->flip()->all() : [];
        $userSet   = !empty($allIds) ? User::whereIn('id', $allIds)->pluck('id')->flip()->all() : [];
        $orphanNames = $invoices->whereNull('created_by')->pluck('created_by_name')->filter()->unique()->values()->all();
        $userByName  = !empty($orphanNames) ? User::whereIn('name', $orphanNames)->pluck('id', 'name')->all() : [];

        $invoiceSellerMap = [];
        foreach ($invoices as $inv) {
            $invoiceSellerMap[$inv->id] = $this->resolveKey(
                $inv->created_by, $inv->created_by_name, $inv->employee_id,
                $empSet, $userSet, $userByName
            );
        }

        // Map return_id → seller_key via invoice_id
        $returnSellerMap = [];
        foreach ($returnRows as $ret) {
            $returnSellerMap[$ret->id] = $invoiceSellerMap[$ret->invoice_id] ?? 'unknown';
        }

        $rows = DB::table('returns')
            ->whereIn('id', array_keys($returnSellerMap))
            ->select('id', DB::raw("({$valueExpr}) as val"))
            ->groupBy('id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $key = $returnSellerMap[$row->id] ?? 'unknown';
            $result[$key] = ($result[$key] ?? 0) + (float) $row->val;
        }
        return $result;
    }

    /**
     * Aggregate return_items expression grouped by original invoice's seller.
     */
    public function aggregateReturnItemsBySeller($returnQuery, string $itemExpr): array
    {
        $returnRows = (clone $returnQuery)->select('id', 'invoice_id')->get();
        if ($returnRows->isEmpty()) return [];

        $invoiceIds = $returnRows->pluck('invoice_id')->filter()->unique()->values()->all();
        if (empty($invoiceIds)) return [];

        $invoices = Invoice::whereIn('id', $invoiceIds)
            ->select('id', 'created_by', 'created_by_name', 'employee_id')
            ->get();

        $createdByIds = $invoices->pluck('created_by')->filter()->unique()->values()->all();
        $employeeIds  = $invoices->pluck('employee_id')->filter()->unique()->values()->all();
        $allIds       = array_unique(array_merge($createdByIds, $employeeIds));

        $empSet    = !empty($allIds) ? Employee::whereIn('id', $allIds)->pluck('id')->flip()->all() : [];
        $userSet   = !empty($allIds) ? User::whereIn('id', $allIds)->pluck('id')->flip()->all() : [];
        $orphanNames = $invoices->whereNull('created_by')->pluck('created_by_name')->filter()->unique()->values()->all();
        $userByName  = !empty($orphanNames) ? User::whereIn('name', $orphanNames)->pluck('id', 'name')->all() : [];

        $invoiceSellerMap = [];
        foreach ($invoices as $inv) {
            $invoiceSellerMap[$inv->id] = $this->resolveKey(
                $inv->created_by, $inv->created_by_name, $inv->employee_id,
                $empSet, $userSet, $userByName
            );
        }

        $returnSellerMap = [];
        foreach ($returnRows as $ret) {
            $returnSellerMap[$ret->id] = $invoiceSellerMap[$ret->invoice_id] ?? 'unknown';
        }

        $returnIds = array_keys($returnSellerMap);
        $rows = DB::table('return_items')
            ->whereIn('return_id', $returnIds)
            ->select('return_id', DB::raw("({$itemExpr}) as val"))
            ->groupBy('return_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $key = $returnSellerMap[$row->return_id] ?? 'unknown';
            $result[$key] = ($result[$key] ?? 0) + (float) $row->val;
        }
        return $result;
    }

    /**
     * COGS sold by seller (invoice_items.cost_price or fallback products.cost_price).
     */
    public function cogsSoldBySeller($invoiceQuery): array
    {
        $sellerMap = $this->invoiceSellerMap($invoiceQuery);
        if (empty($sellerMap)) return [];

        $invoiceIds = array_keys($sellerMap);
        $hasItemCost = Schema::hasColumn('invoice_items', 'cost_price');
        $costExpr = $hasItemCost
            ? 'invoice_items.quantity * COALESCE(NULLIF(invoice_items.cost_price, 0), products.cost_price, 0)'
            : 'invoice_items.quantity * COALESCE(products.cost_price, 0)';

        $rows = DB::table('invoice_items')
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->whereIn('invoice_items.invoice_id', $invoiceIds)
            ->select('invoice_items.invoice_id', DB::raw("SUM({$costExpr}) as val"))
            ->groupBy('invoice_items.invoice_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $key = $sellerMap[$row->invoice_id] ?? 'unknown';
            $result[$key] = ($result[$key] ?? 0) + (float) $row->val;
        }
        return $result;
    }

    /**
     * COGS returned by seller (return_items.cost_price or import_price).
     */
    public function cogsReturnedBySeller($returnQuery): array
    {
        $hasCostPrice = Schema::hasColumn('return_items', 'cost_price');
        $costExpr = $hasCostPrice
            ? 'SUM(return_items.quantity * COALESCE(NULLIF(return_items.cost_price, 0), return_items.import_price, 0))'
            : 'SUM(return_items.quantity * COALESCE(return_items.import_price, 0))';

        return $this->aggregateReturnItemsBySeller($returnQuery, $costExpr);
    }

    // ═══════════════════════════════════════
    // Seller meta resolution
    // ═══════════════════════════════════════

    /**
     * Resolve seller keys to display meta.
     * Returns: array<seller_key, {id, key, raw_id, name, code, type}>
     */
    public function sellerMeta(array $sellerKeys): array
    {
        $sellerKeys = array_values(array_unique(array_filter($sellerKeys)));
        if (empty($sellerKeys)) return [];

        // Extract numeric ids by prefix
        $empIds = [];
        $userIds = [];
        $orphanNames = [];

        foreach ($sellerKeys as $key) {
            if (str_starts_with($key, 'employee:')) {
                $empIds[] = (int) substr($key, 9);
            } elseif (str_starts_with($key, 'user:')) {
                $userIds[] = (int) substr($key, 5);
            } elseif (str_starts_with($key, 'orphan:')) {
                $orphanNames[] = substr($key, 7);
            }
        }

        $employees = !empty($empIds)
            ? Employee::whereIn('id', $empIds)->get(['id', 'name', 'code'])->keyBy('id')
            : collect();
        $users = !empty($userIds)
            ? User::whereIn('id', $userIds)->get(['id', 'name', 'email', 'role_id'])->keyBy('id')
            : collect();

        $meta = [];
        foreach ($sellerKeys as $key) {
            if (str_starts_with($key, 'employee:')) {
                $id  = (int) substr($key, 9);
                $emp = $employees[$id] ?? null;
                $meta[$key] = [
                    'id'     => $key,
                    'key'    => $key,
                    'raw_id' => $id,
                    'name'   => $emp->name ?? "Nhân viên #{$id}",
                    'code'   => $emp->code ?? "NV{$id}",
                    'type'   => 'employee',
                ];
            } elseif (str_starts_with($key, 'user:')) {
                $id   = (int) substr($key, 5);
                $user = $users[$id] ?? null;
                $isAdmin = $user && method_exists($user, 'isAdmin') ? $user->isAdmin() : false;
                $meta[$key] = [
                    'id'     => $key,
                    'key'    => $key,
                    'raw_id' => $id,
                    'name'   => $user->name ?? ($isAdmin ? 'Admin' : "User #{$id}"),
                    'code'   => $isAdmin ? 'ADMIN' : "U{$id}",
                    'type'   => $isAdmin ? 'admin' : 'user',
                ];
            } elseif (str_starts_with($key, 'orphan:')) {
                $name = substr($key, 7);
                $meta[$key] = [
                    'id'     => $key,
                    'key'    => $key,
                    'raw_id' => null,
                    'name'   => $name,
                    'code'   => 'ORPHAN',
                    'type'   => 'orphan',
                ];
            } else {
                $meta[$key] = [
                    'id'     => $key,
                    'key'    => $key,
                    'raw_id' => null,
                    'name'   => 'Không xác định',
                    'code'   => 'UNK',
                    'type'   => 'unknown',
                ];
            }
        }
        return $meta;
    }

    // ═══════════════════════════════════════
    // Filter options
    // ═══════════════════════════════════════

    /**
     * Build seller filter options: all employees + all admin/user/orphan sellers
     * found in invoice data.
     */
    public function buildSellerFilterOptions(): array
    {
        // All active employees
        $employees = Employee::orderBy('name')->get(['id', 'name', 'code', 'user_id']);

        // Sellers from invoice data
        $directIds = Invoice::whereNotNull('created_by')
            ->distinct()->pluck('created_by')
            ->map(fn ($id) => (int) $id)->filter()->values()->all();

        $orphanNames = Invoice::whereNull('created_by')
            ->whereNotNull('created_by_name')
            ->distinct()->pluck('created_by_name')
            ->filter()->values()->all();

        $userByName = !empty($orphanNames)
            ? User::whereIn('name', $orphanNames)->pluck('id', 'name')->all()
            : [];

        $empIdSet  = Employee::pluck('id')->flip()->all();
        $userIdSet = User::pluck('id')->flip()->all();

        $options = [];
        $seen    = [];

        // Add employees
        foreach ($employees as $emp) {
            $key = "employee:{$emp->id}";
            $options[] = [
                'id'   => $key,
                'key'  => $key,
                'name' => $emp->name,
                'code' => $emp->code ?: "NV{$emp->id}",
                'type' => 'employee',
            ];
            $seen[$key] = true;
            if ($emp->user_id) {
                $seen["user:{$emp->user_id}"] = true;
            }
        }

        // Add direct sellers not already represented
        foreach ($directIds as $id) {
            if (isset($empIdSet[$id]) && isset($seen["employee:{$id}"])) continue;
            if (isset($userIdSet[$id])) {
                $key = "user:{$id}";
                if (isset($seen[$key])) continue;
                $user = User::find($id);
                $isAdmin = $user && method_exists($user, 'isAdmin') ? $user->isAdmin() : false;
                $options[] = [
                    'id'   => $key,
                    'key'  => $key,
                    'name' => $user->name ?? "User #{$id}",
                    'code' => $isAdmin ? 'ADMIN' : "U{$id}",
                    'type' => $isAdmin ? 'admin' : 'user',
                ];
                $seen[$key] = true;
            }
        }

        // Add orphan names
        foreach ($orphanNames as $name) {
            $userId = $userByName[$name] ?? null;
            if ($userId && isset($seen["user:{$userId}"])) continue;
            if ($userId) {
                $key = "user:{$userId}";
                $user = User::find($userId);
                $isAdmin = $user && method_exists($user, 'isAdmin') ? $user->isAdmin() : false;
                $options[] = [
                    'id'   => $key,
                    'key'  => $key,
                    'name' => $user->name ?? $name,
                    'code' => $isAdmin ? 'ADMIN' : "U{$userId}",
                    'type' => $isAdmin ? 'admin' : 'user',
                ];
                $seen[$key] = true;
            } else {
                $key = "orphan:{$name}";
                if (isset($seen[$key])) continue;
                $options[] = [
                    'id'   => $key,
                    'key'  => $key,
                    'name' => $name,
                    'code' => 'ORPHAN',
                    'type' => 'orphan',
                ];
                $seen[$key] = true;
            }
        }

        return collect($options)->sortBy('name')->values()->all();
    }

    /**
     * Normalize a seller filter value from the frontend request.
     * Supports new prefixed keys and legacy numeric ids.
     * Returns array of seller keys to match against.
     */
    public function normalizeRequestedSellerKey($value): array
    {
        if (!$value) return [];

        $value = (string) $value;

        // Already prefixed
        if (str_starts_with($value, 'employee:') ||
            str_starts_with($value, 'user:') ||
            str_starts_with($value, 'orphan:')) {
            return [$value];
        }

        // Legacy numeric id — match both employee and user
        if (ctype_digit($value)) {
            return ["employee:{$value}", "user:{$value}"];
        }

        return [];
    }

    /**
     * Filter an invoice query to only include invoices from a specific seller.
     */
    public function filterBySeller($invoiceQuery, string $employeeIdParam)
    {
        $matchKeys = $this->normalizeRequestedSellerKey($employeeIdParam);
        if (empty($matchKeys)) return $invoiceQuery;

        $sellerMap = $this->invoiceSellerMap($invoiceQuery);
        $matchingIds = [];
        foreach ($sellerMap as $invoiceId => $key) {
            if (in_array($key, $matchKeys)) {
                $matchingIds[] = $invoiceId;
            }
        }

        return $invoiceQuery->whereIn('id', $matchingIds);
    }
}
