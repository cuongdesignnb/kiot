<?php

namespace Tests\Feature\Tasks;

use App\Models\Employee;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CreatorSelfAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function userWithEmployee(bool $active = true): array
    {
        $user = User::factory()->create();
        $employee = Employee::create([
            'name' => 'Creator Employee ' . uniqid(),
            'phone' => '09' . random_int(10000000, 99999999),
            'user_id' => $user->id,
            'is_active' => $active,
        ]);

        return [$user, $employee];
    }

    private function createSerial(): SerialImei
    {
        $product = Product::create([
            'name' => 'Repair Device',
            'sku' => 'DEV-' . uniqid(),
            'cost_price' => 1000000,
            'retail_price' => 1500000,
            'stock_quantity' => 1,
            'inventory_total_cost' => 1000000,
            'has_serial' => true,
        ]);

        return SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-' . uniqid(),
            'status' => 'in_stock',
            'cost_price' => 1000000,
        ]);
    }

    public function test_active_employee_creating_general_task_gets_pending_self_assignment(): void
    {
        Notification::fake();
        [$user, $employee] = $this->userWithEmployee();

        $response = $this->actingAs($user)->postJson('/api/tasks', [
            'type' => Task::TYPE_GENERAL,
            'title' => 'Self assigned general task',
        ]);

        $response->assertCreated();
        $task = Task::findOrFail($response->json('id'));

        $this->assertSame(Task::STATUS_PENDING, $task->status);
        $this->assertSame($employee->id, $task->assigned_employee_id);
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'employee_id' => $employee->id,
            'assigned_by' => $user->id,
            'status' => TaskAssignment::STATUS_PENDING,
        ]);
        Notification::assertNothingSent();

        $myTasks = $this->actingAs($user)->getJson('/api/my-tasks');
        $myTasks->assertOk();
        $this->assertTrue(collect($myTasks->json('data'))->contains('id', $task->id));
        $this->assertSame(TaskAssignment::STATUS_PENDING, collect($myTasks->json('data'))->firstWhere('id', $task->id)['assignment_status']);
    }

    public function test_active_employee_creating_external_repair_gets_pending_self_assignment(): void
    {
        [$user, $employee] = $this->userWithEmployee();

        $response = $this->actingAs($user)->postJson('/api/tasks', [
            'type' => Task::TYPE_REPAIR,
            'external' => true,
            'customer_name' => 'Walk-in customer',
            'issue_description' => 'External repair issue',
        ]);

        $response->assertCreated();
        $task = Task::findOrFail($response->json('id'));

        $this->assertTrue($task->external);
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'employee_id' => $employee->id,
            'status' => TaskAssignment::STATUS_PENDING,
        ]);
    }

    public function test_active_employee_creating_internal_repair_gets_pending_self_assignment(): void
    {
        [$user, $employee] = $this->userWithEmployee();
        $serial = $this->createSerial();

        $response = $this->actingAs($user)->postJson('/api/tasks', [
            'type' => Task::TYPE_REPAIR,
            'serial_imei_id' => $serial->id,
            'issue_description' => 'Internal repair issue',
        ]);

        $response->assertCreated();
        $task = Task::findOrFail($response->json('id'));

        $this->assertFalse($task->external);
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'employee_id' => $employee->id,
            'status' => TaskAssignment::STATUS_PENDING,
        ]);
    }

    public function test_creator_can_accept_then_update_progress(): void
    {
        [$user, $employee] = $this->userWithEmployee();

        $task = app(TaskService::class)->createTask([
            'type' => Task::TYPE_GENERAL,
            'title' => 'Accept then progress',
            'created_by' => $user->id,
            'creator_employee_id' => $employee->id,
        ]);
        $assignment = $task->assignments()->where('employee_id', $employee->id)->firstOrFail();

        $this->actingAs($user)
            ->postJson("/api/my-tasks/{$assignment->id}/respond", ['status' => TaskAssignment::STATUS_ACCEPTED])
            ->assertOk();

        $this->assertSame(TaskAssignment::STATUS_ACCEPTED, $assignment->fresh()->status);
        $this->assertSame(Task::STATUS_IN_PROGRESS, $task->fresh()->status);

        $this->actingAs($user)
            ->postJson("/api/my-tasks/{$task->id}/progress", ['progress' => 50])
            ->assertOk();

        $this->assertSame(50, $task->fresh()->progress);
    }

    public function test_other_employee_does_not_see_unassigned_creator_task(): void
    {
        [$creatorUser, $creatorEmployee] = $this->userWithEmployee();
        [$otherUser] = $this->userWithEmployee();

        $task = app(TaskService::class)->createTask([
            'type' => Task::TYPE_GENERAL,
            'title' => 'Private creator task',
            'created_by' => $creatorUser->id,
            'creator_employee_id' => $creatorEmployee->id,
        ]);

        $response = $this->actingAs($otherUser)->getJson('/api/my-tasks');

        $response->assertOk();
        $this->assertFalse(collect($response->json('data'))->contains('id', $task->id));
    }

    public function test_user_without_active_linked_employee_does_not_get_assignment(): void
    {
        [$inactiveUser] = $this->userWithEmployee(false);
        $unlinkedUser = User::factory()->create();

        foreach ([$inactiveUser, $unlinkedUser] as $user) {
            $response = $this->actingAs($user)->postJson('/api/tasks', [
                'type' => Task::TYPE_GENERAL,
                'title' => 'No active employee ' . uniqid(),
            ]);

            $response->assertCreated();
            $task = Task::findOrFail($response->json('id'));
            $this->assertNull($task->assigned_employee_id);
            $this->assertSame(0, $task->assignments()->count());
        }
    }

    public function test_creator_assignment_is_not_duplicated(): void
    {
        [$user, $employee] = $this->userWithEmployee();
        $service = app(TaskService::class);

        $task = $service->createTask([
            'type' => Task::TYPE_GENERAL,
            'title' => 'No duplicate self assignment',
            'created_by' => $user->id,
            'creator_employee_id' => $employee->id,
        ]);

        $service->ensureCreatorAssignment($task, $employee->id, $user->id);

        $this->assertSame(1, $task->assignments()->where('employee_id', $employee->id)->count());
    }

    public function test_backfill_dry_run_does_not_write_assignments(): void
    {
        [$user] = $this->userWithEmployee();
        $task = Task::create([
            'code' => 'TASK-' . uniqid(),
            'type' => Task::TYPE_GENERAL,
            'title' => 'Backfill dry run',
            'status' => Task::STATUS_PENDING,
            'priority' => Task::PRIORITY_NORMAL,
            'created_by' => $user->id,
        ]);

        $this->artisan('tasks:backfill-creator-assignments')
            ->expectsOutputToContain('Dry-run only')
            ->assertExitCode(0);

        $this->assertSame(0, $task->assignments()->count());
    }

    public function test_backfill_commit_creates_only_safe_creator_assignments(): void
    {
        [$user, $employee] = $this->userWithEmployee();
        [$otherUser, $otherEmployee] = $this->userWithEmployee();

        $eligible = Task::create([
            'code' => 'TASK-' . uniqid(),
            'type' => Task::TYPE_GENERAL,
            'title' => 'Eligible',
            'status' => Task::STATUS_PENDING,
            'priority' => Task::PRIORITY_NORMAL,
            'created_by' => $user->id,
        ]);
        $assignedToOther = Task::create([
            'code' => 'TASK-' . uniqid(),
            'type' => Task::TYPE_GENERAL,
            'title' => 'Assigned to other',
            'status' => Task::STATUS_PENDING,
            'priority' => Task::PRIORITY_NORMAL,
            'created_by' => $user->id,
            'assigned_employee_id' => $otherEmployee->id,
        ]);
        $completed = Task::create([
            'code' => 'TASK-' . uniqid(),
            'type' => Task::TYPE_GENERAL,
            'title' => 'Completed',
            'status' => Task::STATUS_COMPLETED,
            'priority' => Task::PRIORITY_NORMAL,
            'created_by' => $otherUser->id,
        ]);

        $this->artisan('tasks:backfill-creator-assignments --commit')
            ->assertExitCode(0);

        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $eligible->id,
            'employee_id' => $employee->id,
            'status' => TaskAssignment::STATUS_PENDING,
        ]);
        $this->assertSame(0, $assignedToOther->assignments()->count());
        $this->assertSame(0, $completed->assignments()->count());
    }
}
