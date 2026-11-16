<?php

namespace App\Support\Reports;

use App\Models\Employee;
use App\Models\User;

/**
 * Report-level staff aliases (config reports.staff_aliases, user_id => user_id).
 *
 * Translates the configured users into seller keys understood by
 * SellerResolver so a staff report can merge the source user's documents
 * into the target user. Nothing is written to the database.
 */
class StaffAliases
{
    /**
     * @return array<string, string> source seller key => target seller key
     */
    public function sellerKeyMap(?array $aliases = null): array
    {
        $aliases ??= (array) config('reports.staff_aliases', []);
        if ($aliases === []) {
            return [];
        }

        $userIds = array_unique(array_merge(array_keys($aliases), array_values($aliases)));
        $users = User::whereIn('id', $userIds)->pluck('name', 'id')->all();
        $employeesByUser = Employee::whereIn('user_id', $userIds)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get(['id', 'user_id'])
            ->groupBy('user_id');

        $map = [];
        foreach ($aliases as $sourceUserId => $targetUserId) {
            $sourceUserId = (int) $sourceUserId;
            $targetKey = $this->targetKey((int) $targetUserId, $users, $employeesByUser);
            if ($targetKey === null || ! isset($users[$sourceUserId])) {
                continue;
            }

            foreach ($this->sourceKeys($sourceUserId, $users, $employeesByUser) as $sourceKey) {
                if ($sourceKey !== $targetKey) {
                    $map[$sourceKey] = $targetKey;
                }
            }
        }

        return $map;
    }

    /**
     * Every seller key the source user's documents can appear under: its
     * linked employee(s) and the name snapshot written when it sold as an
     * admin without an employee record.
     */
    private function sourceKeys(int $userId, array $users, $employeesByUser): array
    {
        $keys = ($employeesByUser[$userId] ?? collect())
            ->map(fn (Employee $employee) => "employee:{$employee->id}")
            ->all();
        $keys[] = 'snapshot:'.$users[$userId];
        // SellerResolver maps a name snapshot to the only active employee
        // carrying that name, so cover that key as well.
        $byName = Employee::where('is_active', true)->where('name', $users[$userId])->pluck('id');
        if ($byName->count() === 1) {
            $keys[] = "employee:{$byName->first()}";
        }

        return array_values(array_unique($keys));
    }

    private function targetKey(int $userId, array $users, $employeesByUser): ?string
    {
        $linked = ($employeesByUser[$userId] ?? collect())->first();
        if ($linked) {
            return "employee:{$linked->id}";
        }
        if (! isset($users[$userId])) {
            return null;
        }

        $byName = Employee::where('is_active', true)->where('name', $users[$userId])->pluck('id');
        if ($byName->count() === 1) {
            return "employee:{$byName->first()}";
        }

        return 'snapshot:'.$users[$userId];
    }
}
