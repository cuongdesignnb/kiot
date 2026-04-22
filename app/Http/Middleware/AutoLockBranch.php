<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auto-lock the `branch_id` request parameter for users whose effective
 * branch access set is exactly one branch.
 *
 * Spec §3.4: non-admin single-branch users should never be able to view or
 * submit data for other branches. This middleware runs for every web GET
 * request and rewrites `branch_id` (and `from_branch_id`/`to_branch_id` for
 * stock-transfer screens) to the single accessible branch when the user
 * attempts to bypass it or leaves it empty.
 *
 * Admins and users with multi-branch access are left untouched — their
 * sidebar filter continues to work normally.
 */
class AutoLockBranch
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        // Admins bypass the lock entirely.
        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return $next($request);
        }

        // Only meaningful when the user has an accessible-branch helper.
        if (! method_exists($user, 'getAccessibleBranchIds')) {
            return $next($request);
        }

        $branchIds = $user->getAccessibleBranchIds();
        if (count($branchIds) !== 1) {
            return $next($request);
        }
        $lockedId = (int) $branchIds[0];

        // Rewrite query params so downstream filters honor the lock.
        $merged = [];
        foreach (['branch_id', 'from_branch_id', 'to_branch_id'] as $key) {
            if ($request->has($key) && (string) $request->input($key) !== (string) $lockedId) {
                $merged[$key] = $lockedId;
            }
        }
        if (! empty($merged)) {
            $request->merge($merged);
            // Keep the query bag consistent for controllers that read $request->query()
            $request->query->add($merged);
        }

        // Expose the locked branch + flag for Inertia shared props / controllers.
        $request->attributes->set('branch_locked', true);
        $request->attributes->set('locked_branch_id', $lockedId);

        return $next($request);
    }
}
