<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Task;
use App\Models\User;

class TaskAccessService
{
    public function activeEmployeeFor(?User $user): ?Employee
    {
        if (!$user) {
            return null;
        }

        return Employee::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();
    }

    public function canViewTask(?User $user, Task $task): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->hasPermission('tasks.view')) {
            return true;
        }

        $employee = $this->activeEmployeeFor($user);
        if (!$employee || !$user->hasPermission('tasks.self.view')) {
            return false;
        }

        return $task->assignments()
            ->where('employee_id', $employee->id)
            ->exists();
    }
}
