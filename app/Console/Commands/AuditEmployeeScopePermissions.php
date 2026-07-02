<?php

namespace App\Console\Commands;

use App\Models\Role;
use Illuminate\Console\Command;

class AuditEmployeeScopePermissions extends Command
{
    protected $signature = 'permissions:audit-employee-scope {--role= : Role name, display name, or ID to audit}';

    protected $description = 'Read-only audit for employee roles that should only use self-service task permissions.';

    private const DANGEROUS_GLOBALS = [
        'tasks.view' => 'Global task list/detail',
        'tasks.assign' => 'Task assignment',
        'tasks.complete' => 'Global task completion/progress',
        'tasks.manage_parts' => 'Repair parts management',
        'tasks.performance' => 'Task performance report',
        'reports.view' => 'Reports and revenue/profit',
        'dashboard.view' => 'Dashboard',
        'invoices.view' => 'Invoices',
        'returns.view' => 'Returns',
        'cash_flows.view' => 'Cash book',
        'employees.view' => 'Employee directory',
        'payroll.view' => 'Payroll',
        'paysheets.view' => 'Legacy paysheets',
        'attendance.view' => 'Attendance',
        'schedules.view' => 'Schedules',
    ];

    private const RECOMMENDED_SELF_PERMISSIONS = [
        'tasks.self.view',
        'tasks.self.create',
        'tasks.self.progress',
    ];

    public function handle(): int
    {
        $roleFilter = $this->option('role');

        $query = Role::query()->orderBy('id');
        if ($roleFilter !== null && $roleFilter !== '') {
            $query->where(function ($q) use ($roleFilter) {
                $q->where('name', $roleFilter)
                    ->orWhere('display_name', $roleFilter);

                if (ctype_digit((string) $roleFilter)) {
                    $q->orWhere('id', (int) $roleFilter);
                }
            });
        }

        $roles = $query->get();
        if ($roles->isEmpty()) {
            $this->warn('No roles matched the audit filter.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($roles as $role) {
            $permissions = $role->permissions ?? [];
            $isWildcard = in_array('*', $permissions, true);
            $dangerous = $isWildcard
                ? []
                : array_values(array_intersect(array_keys(self::DANGEROUS_GLOBALS), $permissions));
            $missingSelf = $isWildcard
                ? []
                : array_values(array_diff(self::RECOMMENDED_SELF_PERMISSIONS, $permissions));

            $rows[] = [
                'role' => $role->display_name ?: $role->name,
                'permissions' => count($permissions),
                'dangerous_globals' => $dangerous ? implode(', ', $dangerous) : '-',
                'exposed_modules' => $dangerous ? implode(', ', array_map(fn ($p) => self::DANGEROUS_GLOBALS[$p], $dangerous)) : '-',
                'recommendation' => $isWildcard
                    ? 'Wildcard/admin role - skip employee scope audit'
                    : $this->recommendation($dangerous, $missingSelf),
            ];
        }

        $this->table(
            ['Role', 'Permission count', 'Dangerous globals', 'Exposed route/module', 'Recommended keep/remove'],
            $rows
        );

        $this->info('Read-only audit. Database was not changed.');

        return self::SUCCESS;
    }

    private function recommendation(array $dangerous, array $missingSelf): string
    {
        $parts = [];

        if ($dangerous) {
            $parts[] = 'Review/remove: ' . implode(', ', $dangerous);
        }

        if ($missingSelf) {
            $parts[] = 'Consider adding self perms: ' . implode(', ', $missingSelf);
        }

        return $parts ? implode(' | ', $parts) : 'OK for employee self-service scope';
    }
}
