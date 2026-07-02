<?php

namespace App\Support\Reports;

use App\Models\Employee;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HOTFIX 24.28B — Seller resolution using correct data contract.
 *
 * Data contract (from InvoiceSaleService::buildInvoiceAttributes):
 *   invoices.created_by      = seller employee id (from context['seller_id'])
 *   invoices.seller_name     = seller name snapshot (from context['seller_name'])
 *   invoices.created_by_name = creator name snapshot (auth user name — NOT seller)
 *
 * Seller keys:
 *   employee:<id>           — seller has a known employee record
 *   snapshot:<seller_name>  — seller_name present but no employee id
 *   unknown                 — no seller info (created_by NULL + seller_name NULL)
 *
 * CRITICAL: created_by_name is NEVER used for seller resolution.
 * It is the login user's name at invoice creation time (creator snapshot).
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
        $selectCols = ['id', 'created_by', 'seller_name'];

        $invoices = (clone $query)
            ->select($selectCols)
            ->get();

        if ($invoices->isEmpty()) return [];

        // Preload employee lookup
        $createdByIds = $invoices->pluck('created_by')->filter()->unique()->values()->all();
        $employeeIdSet = !empty($createdByIds)
            ? Employee::whereIn('id', $createdByIds)->pluck('name', 'id')->all()
            : [];

        // For seller_name → employee match (orphan resolution)
        $sellerNames = $invoices->whereNull('created_by')
            ->pluck('seller_name')->filter()->unique()->values()->all();
        $employeeBySellerName = [];
        if (!empty($sellerNames)) {
            $empsByName = Employee::where('is_active', true)
                ->whereIn('name', $sellerNames)
                ->get(['id', 'name', 'code']);
            $grouped = $empsByName->groupBy('name');
            foreach ($grouped as $name => $emps) {
                if ($emps->count() === 1) {
                    $employeeBySellerName[$name] = $emps->first();
                }
                // Multiple employees with same name → stay snapshot
            }
        }

        $map = [];
        foreach ($invoices as $inv) {
            $map[$inv->id] = $this->resolveKey(
                $inv->created_by,
                $inv->seller_name,
                $employeeIdSet,
                $employeeBySellerName
            );
        }
        return $map;
    }

    /**
     * HOTFIX 24.28B — Determine seller key for a single invoice.
     *
     * Resolution:
     * 1. created_by present → employee:<id> if employee exists, else snapshot or unknown
     * 2. created_by NULL + seller_name present → match employee or snapshot
     * 3. Both NULL → unknown
     *
     * NEVER uses created_by_name (that's the creator, not the seller).
     */
    private function resolveKey(
        $createdBy,
        $sellerName,
        array $empNameById,
        array $employeeBySellerName
    ): string {
        // Priority 1: created_by has a value (seller employee id)
        if ($createdBy !== null && $createdBy !== '') {
            $id = (int) $createdBy;

            // Check if this employee exists
            if (array_key_exists($id, $empNameById)) {
                return "employee:{$id}";
            }

            // Employee ID doesn't exist in DB — use seller_name if available
            if ($sellerName !== null && $sellerName !== '') {
                return "snapshot:{$sellerName}";
            }

            // No seller info at all despite having created_by
            return 'unknown';
        }

        // Priority 2: created_by NULL, seller_name present
        if ($sellerName !== null && $sellerName !== '') {
            // Try to match to exactly one active employee
            if (isset($employeeBySellerName[$sellerName])) {
                return "employee:{$employeeBySellerName[$sellerName]->id}";
            }
            return "snapshot:{$sellerName}";
        }

        // Priority 3: No seller info at all
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

        $invoiceSellerMap = $this->invoiceSellerMap(
            Invoice::whereIn('id', $invoiceIds)
        );

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

        $invoiceSellerMap = $this->invoiceSellerMap(
            Invoice::whereIn('id', $invoiceIds)
        );

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
     *
     * Returns: array<seller_key, {id, key, raw_id, name, code, type, display_name}>
     */
    public function sellerMeta(array $sellerKeys): array
    {
        $sellerKeys = array_values(array_unique(array_filter($sellerKeys)));
        if (empty($sellerKeys)) return [];

        // Extract numeric ids by prefix
        $empIds = [];
        $snapshotNames = [];

        foreach ($sellerKeys as $key) {
            if (str_starts_with($key, 'employee:')) {
                $empIds[] = (int) substr($key, 9);
            } elseif (str_starts_with($key, 'snapshot:')) {
                $snapshotNames[] = substr($key, 9);
            }
        }

        $employees = !empty($empIds)
            ? Employee::whereIn('id', $empIds)->get(['id', 'name', 'code', 'user_id'])->keyBy('id')
            : collect();

        // HOTFIX 24.30 Hướng A: resolve linked user names
        $linkedUserIds = $employees->pluck('user_id')->filter()->unique()->values()->all();
        $linkedUsers = !empty($linkedUserIds)
            ? User::whereIn('id', $linkedUserIds)->where('status', 'active')->pluck('name', 'id')->all()
            : [];

        // Build raw meta
        $meta = [];
        foreach ($sellerKeys as $key) {
            if (str_starts_with($key, 'employee:')) {
                $id  = (int) substr($key, 9);
                $emp = $employees[$id] ?? null;
                // Hướng A: if employee has linked active user, display user's current name
                $displayName = $emp->name ?? "Nhân viên #{$id}";
                if ($emp && $emp->user_id && isset($linkedUsers[$emp->user_id])) {
                    $displayName = $linkedUsers[$emp->user_id];
                }
                $meta[$key] = [
                    'id'     => $key,
                    'key'    => $key,
                    'raw_id' => $id,
                    'name'   => $displayName,
                    'code'   => $emp->code ?? "NV{$id}",
                    'type'   => 'employee',
                ];
            } elseif (str_starts_with($key, 'snapshot:')) {
                $name = substr($key, 9);
                $meta[$key] = [
                    'id'     => $key,
                    'key'    => $key,
                    'raw_id' => null,
                    'name'   => $name,
                    'code'   => 'SNAPSHOT',
                    'type'   => 'seller_snapshot',
                ];
            } elseif ($key === 'unknown') {
                $meta[$key] = [
                    'id'     => 'unknown',
                    'key'    => 'unknown',
                    'raw_id' => null,
                    'name'   => 'Chưa xác định người bán',
                    'code'   => 'UNKNOWN',
                    'type'   => 'unknown_seller',
                ];
            } else {
                // Catch-all for any legacy keys
                $meta[$key] = [
                    'id'     => $key,
                    'key'    => $key,
                    'raw_id' => null,
                    'name'   => $key,
                    'code'   => 'LEGACY',
                    'type'   => 'legacy',
                ];
            }
        }

        // Disambiguate duplicate display names
        $nameCount = [];
        foreach ($meta as $m) {
            $nameCount[$m['name']] = ($nameCount[$m['name']] ?? 0) + 1;
        }
        foreach ($meta as $key => &$m) {
            if (($nameCount[$m['name']] ?? 0) > 1) {
                if ($m['type'] === 'seller_snapshot') {
                    $m['display_name'] = "{$m['name']} — snapshot người bán";
                } else {
                    $suffix = $m['code'] ?? strtoupper($m['type']);
                    $m['display_name'] = "{$m['name']} — {$suffix}";
                }
            } else {
                $m['display_name'] = $m['name'];
            }
        }
        unset($m);

        return $meta;
    }

    // ═══════════════════════════════════════
    // Filter options
    // ═══════════════════════════════════════

    /**
     * HOTFIX 24.28B — Build seller filter options.
     *
     * Sources:
     * 1. All active employees (canonical sellers)
     * 2. Snapshot seller names from invoices that don't map to an employee
     * 3. 'unknown' bucket if any invoices have no seller
     *
     * NEVER includes created_by_name (creator snapshot).
     */
    public function buildSellerFilterOptions(): array
    {
        $options = [];
        $seen = [];

        // 1. All active employees
        $employees = Employee::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'user_id']);

        // HOTFIX 24.30 Hướng A: resolve linked user names for display
        $linkedUserIds = $employees->pluck('user_id')->filter()->unique()->values()->all();
        $linkedUsers = !empty($linkedUserIds)
            ? User::whereIn('id', $linkedUserIds)->where('status', 'active')->pluck('name', 'id')->all()
            : [];

        foreach ($employees as $emp) {
            $key = "employee:{$emp->id}";
            $displayName = $emp->name;
            if ($emp->user_id && isset($linkedUsers[$emp->user_id])) {
                $displayName = $linkedUsers[$emp->user_id];
            }
            $options[] = [
                'id'   => $key,
                'key'  => $key,
                'name' => $displayName,
                'code' => $emp->code ?: "NV{$emp->id}",
                'type' => 'employee',
            ];
            $seen[$key] = true;
        }

        // 2. Direct created_by values that are employee IDs not already in active employees
        $directIds = Invoice::whereNotNull('created_by')
            ->where('status', '!=', 'Đã hủy')
            ->distinct()->pluck('created_by')
            ->map(fn($id) => (int) $id)->filter()->values()->all();

        $empIdSet = Employee::pluck('id')->flip()->all();
        foreach ($directIds as $id) {
            if (isset($empIdSet[$id]) && !isset($seen["employee:{$id}"])) {
                $emp = Employee::find($id);
                if ($emp) {
                    $key = "employee:{$emp->id}";
                    $options[] = [
                        'id'   => $key,
                        'key'  => $key,
                        'name' => $emp->name,
                        'code' => $emp->code ?: "NV{$emp->id}",
                        'type' => 'employee',
                    ];
                    $seen[$key] = true;
                }
            }
        }

        // 3. Snapshot seller names (created_by NULL but seller_name present)
        $snapshotNames = Invoice::whereNull('created_by')
            ->where('status', '!=', 'Đã hủy')
            ->whereNotNull('seller_name')
            ->where('seller_name', '!=', '')
            ->distinct()->pluck('seller_name')
            ->filter()->values()->all();

        foreach ($snapshotNames as $name) {
            // Check if this name matches exactly one active employee → already covered
            $matchCount = Employee::where('is_active', true)->where('name', $name)->count();
            if ($matchCount === 1) {
                continue; // Already in employee list
            }
            $key = "snapshot:{$name}";
            if (isset($seen[$key])) continue;
            $options[] = [
                'id'   => $key,
                'key'  => $key,
                'name' => $name,
                'code' => 'SNAPSHOT',
                'type' => 'seller_snapshot',
            ];
            $seen[$key] = true;
        }

        // 4. Unknown bucket if any invoices have no seller
        $unknownCount = Invoice::whereNull('created_by')
            ->where('status', '!=', 'Đã hủy')
            ->where(function ($q) {
                $q->whereNull('seller_name')
                  ->orWhere('seller_name', '');
            })
            ->count();

        if ($unknownCount > 0) {
            $options[] = [
                'id'   => 'unknown',
                'key'  => 'unknown',
                'name' => 'Chưa xác định người bán',
                'code' => 'UNKNOWN',
                'type' => 'unknown_seller',
            ];
        }

        // HOTFIX 24.32 — append virtual admin sellers (users only, no employee)
        $employeeLinkedUserIds = Employee::where('is_active', true)
            ->whereNotNull('user_id')->pluck('user_id')->all();
        foreach ($this->virtualAdminSellerOptions($employeeLinkedUserIds) as $opt) {
            if (isset($seen[$opt['key']])) continue;
            $options[] = $opt;
            $seen[$opt['key']] = true;
        }

        // Disambiguate duplicate display names
        $nameCount = [];
        foreach ($options as $opt) {
            $nameCount[$opt['name']] = ($nameCount[$opt['name']] ?? 0) + 1;
        }
        foreach ($options as &$opt) {
            if (($nameCount[$opt['name']] ?? 0) > 1) {
                if ($opt['type'] === 'seller_snapshot') {
                    $opt['display_name'] = "{$opt['name']} — snapshot người bán";
                } elseif ($opt['type'] === 'admin_user') {
                    $opt['display_name'] = "{$opt['name']} — Admin";
                } else {
                    $suffix = $opt['code'] ?? strtoupper($opt['type']);
                    $opt['display_name'] = "{$opt['name']} — {$suffix}";
                }
            } else {
                $opt['display_name'] = $opt['type'] === 'admin_user'
                    ? "{$opt['name']} — Admin"
                    : $opt['name'];
            }
        }
        unset($opt);

        return collect($options)->sortBy('name')->values()->all();
    }

    /**
     * Build creator filter options from distinct created_by_name snapshots.
     * These are purely for "Người tạo" filter — NOT seller.
     */
    public function buildCreatorFilterOptions(): array
    {
        $names = Invoice::where('status', '!=', 'Đã hủy')
            ->whereNotNull('created_by_name')
            ->where('created_by_name', '!=', '')
            ->distinct()
            ->pluck('created_by_name')
            ->sort()
            ->values();

        return $names->map(fn($name) => [
            'id'           => "creator_snapshot:{$name}",
            'key'          => "creator_snapshot:{$name}",
            'name'         => $name,
            'type'         => 'creator_snapshot',
            'display_name' => "{$name}",
        ])->all();
    }

    // ═══════════════════════════════════════
    // Filter by seller
    // ═══════════════════════════════════════

    /**
     * Normalize a seller filter value from the frontend request.
     */
    public function normalizeRequestedSellerKey($value): array
    {
        if (!$value) return [];

        $value = (string) $value;

        // Already prefixed
        if (str_starts_with($value, 'employee:') ||
            str_starts_with($value, 'admin_user:') ||
            str_starts_with($value, 'snapshot:') ||
            $value === 'unknown') {
            return [$value];
        }

        // Legacy numeric id — treat as employee id
        if (ctype_digit($value)) {
            return ["employee:{$value}"];
        }

        return [];
    }

    /**
     * Filter an invoice query to only include invoices from a specific seller.
     *
     * HOTFIX 24.28B — Direct SQL filtering instead of loading all invoices.
     */
    public function filterBySeller($invoiceQuery, string $sellerKeyParam)
    {
        $value = (string) $sellerKeyParam;

        // HOTFIX 24.32 — admin_user:<id> → super admin virtual seller.
        // Maps to invoices with NULL created_by + seller_name = user.name
        // (the snapshot we write when this option is chosen). Falsy guard:
        // user must exist, be active, and be admin — otherwise return an
        // empty result rather than risk matching arbitrary snapshots.
        if (preg_match('/^admin_user:(\d+)$/', $value, $m)) {
            $userId = (int) $m[1];
            $user = User::find($userId);
            if (!$user || ($user->status ?? 'active') !== 'active' || !$user->isAdmin()) {
                return $invoiceQuery->whereRaw('1=0');
            }
            return $invoiceQuery->whereNull('created_by')
                ->where('seller_name', $user->name);
        }

        // employee:<id> → where created_by = <id>
        if (preg_match('/^employee:(\d+)$/', $value, $m)) {
            $empId = (int) $m[1];
            return $invoiceQuery->where(function ($q) use ($empId) {
                $emp = Employee::find($empId);
                $q->where('created_by', $empId);
                // Also match seller_name if employee exists (for invoices where created_by is NULL)
                if ($emp) {
                    $empName = $emp->name;
                    // Only match seller_name if this employee is the ONLY one with this name
                    $nameCount = Employee::where('is_active', true)->where('name', $empName)->count();
                    if ($nameCount === 1) {
                        $q->orWhere(function ($sq) use ($empName) {
                            $sq->whereNull('created_by')
                               ->where('seller_name', $empName);
                        });
                    }
                }
            });
        }

        // snapshot:<name> → where created_by IS NULL AND seller_name = <name>
        if (str_starts_with($value, 'snapshot:')) {
            $name = substr($value, 9);
            return $invoiceQuery->whereNull('created_by')->where('seller_name', $name);
        }

        // unknown → where created_by IS NULL AND (seller_name IS NULL OR seller_name = '')
        if ($value === 'unknown') {
            return $invoiceQuery->whereNull('created_by')
                ->where(function ($q) {
                    $q->whereNull('seller_name')
                      ->orWhere('seller_name', '');
                });
        }

        // Legacy numeric id
        if (ctype_digit($value)) {
            return $invoiceQuery->where('created_by', (int) $value);
        }

        return $invoiceQuery;
    }

    /**
     * HOTFIX 24.31 — Scope a returns query to only include returns whose
     * original invoice matches the given seller key.
     *
     * Without this, a report filtered by seller A still pulls in returns
     * of seller B (because returnQ has no seller scope), so seller B
     * appears in rows with a negative net.
     */
    public function filterReturnsBySeller($returnQuery, string $sellerKey)
    {
        return $returnQuery->whereHas('invoice', function ($q) use ($sellerKey) {
            $this->filterBySeller($q, $sellerKey);
        });
    }

    /**
     * HOTFIX 24.31 — Scope a returns query to only include returns whose
     * original invoice has the given sales_channel. The returns table has
     * its own sales_channel column but the canonical value lives on the
     * invoice (set at sale time), so we filter via the relation.
     */
    public function filterReturnsByInvoiceSalesChannel($returnQuery, string $channel)
    {
        return $returnQuery->whereHas('invoice', function ($q) use ($channel) {
            $q->where('sales_channel', $channel);
        });
    }

    /**
     * Filter by creator snapshot (created_by_name).
     */
    public function filterByCreator($invoiceQuery, string $creatorKey)
    {
        $value = (string) $creatorKey;

        if (str_starts_with($value, 'creator_snapshot:')) {
            $name = substr($value, 17);
            return $invoiceQuery->where('created_by_name', $name);
        }

        // Plain name
        return $invoiceQuery->where('created_by_name', $value);
    }

    // ═══════════════════════════════════════
    // Invoice detail seller options
    // ═══════════════════════════════════════

    /**
     * HOTFIX 24.30 — Build seller options for invoice detail dropdown.
     * HOTFIX 24.32 — Also surface super-admin users without a linked
     * employee as virtual sellers (admin_user:<id>).
     *
     * Returns ALL active employees with display_name. Plus, for each
     * active super-admin user that has no active linked employee, a
     * virtual option keyed admin_user:<id>. Does NOT include snapshots,
     * unknown, or creator.
     */
    public function buildInvoiceSellerOptions(): array
    {
        $employees = Employee::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'user_id']);

        $linkedUserIds = $employees->pluck('user_id')->filter()->unique()->values()->all();
        $linkedUsers = !empty($linkedUserIds)
            ? User::whereIn('id', $linkedUserIds)->where('status', 'active')->pluck('name', 'id')->all()
            : [];

        $options = [];
        foreach ($employees as $emp) {
            $displayName = $emp->name;
            if ($emp->user_id && isset($linkedUsers[$emp->user_id])) {
                $displayName = $linkedUsers[$emp->user_id];
            }
            $options[] = [
                'id'           => "employee:{$emp->id}",
                'key'          => "employee:{$emp->id}",
                'raw_id'       => $emp->id,
                'name'         => $displayName,
                'code'         => $emp->code ?: "NV{$emp->id}",
                'type'         => 'employee',
                'display_name' => $displayName . ' — ' . ($emp->code ?: "NV{$emp->id}"),
            ];
        }

        // HOTFIX 24.32 — append virtual admin sellers
        foreach ($this->virtualAdminSellerOptions($linkedUserIds) as $opt) {
            $options[] = $opt;
        }

        // Disambiguate duplicate display names
        $nameCount = [];
        foreach ($options as $opt) {
            $nameCount[$opt['name']] = ($nameCount[$opt['name']] ?? 0) + 1;
        }
        foreach ($options as &$opt) {
            if ($opt['type'] === 'admin_user') {
                $opt['display_name'] = "{$opt['name']} — Admin";
            } elseif (($nameCount[$opt['name']] ?? 0) > 1) {
                $opt['display_name'] = "{$opt['name']} — {$opt['code']}";
            } else {
                $opt['display_name'] = $opt['name'];
            }
        }
        unset($opt);

        return collect($options)->sortBy('name')->values()->all();
    }

    /**
     * HOTFIX 24.32 — Active super-admin users that do not have an active
     * linked employee. Exposed so they can be chosen as seller for an
     * invoice without forcing an Employee record to exist.
     *
     * @param  array $excludeUserIds  user_ids already covered by an active employee option
     * @return array<int,array>
     */
    private function virtualAdminSellerOptions(array $excludeUserIds = []): array
    {
        $exclude = array_flip(array_map('intval', $excludeUserIds));

        // Filter at the PHP layer via User::isAdmin() to reuse the auth
        // model's exact admin definition (role_id NULL OR role.* permission).
        $admins = User::with('role')
            ->where('status', 'active')
            ->get(['id', 'name', 'role_id', 'status'])
            ->filter(fn ($u) => $u->isAdmin() && !isset($exclude[$u->id]));

        $options = [];
        foreach ($admins as $u) {
            $options[] = [
                'id'           => "admin_user:{$u->id}",
                'key'          => "admin_user:{$u->id}",
                'raw_id'       => $u->id,
                'name'         => $u->name,
                'code'         => 'ADMIN',
                'type'         => 'admin_user',
                'display_name' => $u->name . ' — Admin',
            ];
        }
        return $options;
    }
}
