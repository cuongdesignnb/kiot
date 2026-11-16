<?php

namespace App\Http\Middleware;

use App\Support\Security\CostVisibility;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Batch 10 đợt 4 — item 30. Server-side enforcement of `cost_profit.view`.
 *
 * For a signed-in user WITHOUT the permission, every JSON response and every
 * Inertia page (XHR visits and the first full-page load) has its cost/profit
 * values blanked before it leaves the server (see CostVisibility::sanitize).
 * Hiding a column in Vue is only cosmetic; this is the actual guard.
 *
 * Not applied to the purchasing / stock-count / repair / payroll / settings
 * screens: their forms post cost figures back (purchase price, stock-take
 * snapshot, parts cost) and blanking them would corrupt data. Those screens
 * are protected by their own module permissions (purchases.*, stock_takes.*,
 * tasks.*, paysheets.*, settings.*), which a sales role should not have.
 */
class HideCostProfitData
{
    /** Path prefixes (no leading slash) that are never sanitized. */
    public const EXCLUDED_PREFIXES = [
        'purchases',
        'purchase-returns',
        'purchase-orders',
        'stock-takes',
        'api/stock-takes',
        'tasks',
        'api/tasks',
        'my-tasks',
        'api/my-tasks',
        'repairs',
        'device-repairs',
        'api/device-repairs',
        'employees',
        'paysheets',
        'paysheet-payments',
        'payroll',
        'payroll-cycles',
        'salary',
        'salary-advances',
        'salary-ledger',
        'salary-payments',
        'employee-salary-ledger-entries',
        'settings',
        'api/roles',
        'api/users',
    ];

    /** Inertia props that are framework/app plumbing, never figures. */
    private const UNTOUCHED_PROPS = ['auth', 'app_settings', 'flash', 'errors', 'branch_lock', 'debt_offsets'];

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $response = $next($request);

        $user = $request->user() ?? auth()->user();
        if (! $user || CostVisibility::canView($user) || $this->isExcluded($request)) {
            return $response;
        }

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            $response->setData($this->sanitizePayload($data));

            return $response;
        }

        if ($response instanceof Response && $response->original instanceof View) {
            $view = $response->original;
            $page = $view->getData()['page'] ?? null;
            if (is_array($page) && isset($page['component'])) {
                // Passing the View keeps $response->original pointing at it.
                $response->setContent($view->with('page', $this->sanitizePage($page)));
            }
        }

        return $response;
    }

    private function isExcluded(Request $request): bool
    {
        $path = trim($request->path(), '/');
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function sanitizePayload(mixed $data): mixed
    {
        if (is_array($data) && isset($data['component'], $data['props']) && is_array($data['props'])) {
            return $this->sanitizePage($data);
        }

        return CostVisibility::sanitize($data);
    }

    private function sanitizePage(array $page): array
    {
        foreach ($page['props'] as $key => $value) {
            if (in_array($key, self::UNTOUCHED_PROPS, true)) {
                continue;
            }
            $page['props'][$key] = CostVisibility::isSensitiveKey($key)
                ? CostVisibility::sanitize([$key => $value])[$key]
                : CostVisibility::sanitize($value);
        }

        return $page;
    }
}
