<?php

namespace Tests\Feature\Tasks;

use App\Models\Employee;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatorSelfAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function userWithEmployee(string $name = 'Tech'): array
    {
        $user = User::factory()->create(['name' => $name]);
        $employee = Employee::create([
            'name' => $name,
            'phone' => '09' . random_int(10000000, 99999999),
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        return [$user, $employee];
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Repair Device ' . uniqid(),
            'sku' => 'DEV-' . uniqid(),
            'cost_price' => 1000000,
            'retail_price' => 1500000,
            'stock_quantity' => 0,
            'inventory_total_cost' => 1000000,
            'has_serial' => true,
        ], $overrides));
    }

    private function serial(Product $product, array $overrides = []): SerialImei
    {
        return SerialImei::create(array_merge([
            'product_id' => $product->id,
            'serial_number' => 'SN-' . uniqid(),
            'status' => 'in_stock',
            'repair_status' => 'repairing',
            'cost_price' => 1000000,
        ], $overrides));
    }

    private function repairTask(Product $product, SerialImei $serial, Employee $employee, string $assignmentStatus = TaskAssignment::STATUS_ACCEPTED): Task
    {
        $task = Task::create([
            'code' => 'SC-' . random_int(1000, 9999) . uniqid(),
            'type' => Task::TYPE_REPAIR,
            'title' => 'Internal repair',
            'product_id' => $product->id,
            'serial_imei_id' => $serial->id,
            'status' => Task::STATUS_IN_PROGRESS,
            'progress' => 100,
            'priority' => Task::PRIORITY_NORMAL,
            'original_cost' => $serial->cost_price,
            'parts_cost' => 0,
            'total_cost' => $serial->cost_price,
        ]);

        TaskAssignment::create([
            'task_id' => $task->id,
            'employee_id' => $employee->id,
            'status' => $assignmentStatus,
            'assigned_at' => now(),
            'responded_at' => $assignmentStatus === TaskAssignment::STATUS_ACCEPTED ? now() : null,
        ]);

        return $task;
    }

    public function test_assigned_employee_can_complete_in_progress_task_from_my_tasks(): void
    {
        [$user, $employee] = $this->userWithEmployee();
        $product = $this->product(['stock_quantity' => 1]);
        $serial = $this->serial($product, ['status' => 'in_stock']);
        $task = $this->repairTask($product, $serial, $employee);

        $this->actingAs($user)
            ->postJson("/api/my-tasks/{$task->id}/complete")
            ->assertOk()
            ->assertJsonPath('status', Task::STATUS_COMPLETED);

        $freshTask = $task->fresh();
        $this->assertSame(Task::STATUS_COMPLETED, $freshTask->status);
        $this->assertNotNull($freshTask->completed_at);
    }

    public function test_my_tasks_complete_restores_dismantled_repair_serial(): void
    {
        [$user, $employee] = $this->userWithEmployee();
        $product = $this->product(['stock_quantity' => 0]);
        $serial = $this->serial($product, [
            'status' => 'dismantled',
            'repair_status' => 'repairing',
            'invoice_id' => null,
            'sold_at' => null,
            'purchase_return_id' => null,
        ]);
        $task = $this->repairTask($product, $serial, $employee);

        $this->actingAs($user)
            ->postJson("/api/my-tasks/{$task->id}/complete")
            ->assertOk();

        $freshSerial = $serial->fresh();
        $this->assertSame(Task::STATUS_COMPLETED, $task->fresh()->status);
        $this->assertSame('in_stock', $freshSerial->status);
        $this->assertSame('ready', $freshSerial->repair_status);
        $this->assertSame(1, (int) $product->fresh()->stock_quantity);
    }

    public function test_my_tasks_complete_rejects_already_completed_task(): void
    {
        [$user, $employee] = $this->userWithEmployee();
        $product = $this->product(['stock_quantity' => 1]);
        $serial = $this->serial($product, ['status' => 'in_stock']);
        $task = $this->repairTask($product, $serial, $employee);
        $task->update([
            'status' => Task::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson("/api/my-tasks/{$task->id}/complete")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Công việc đã hoàn thành.');
    }

    public function test_my_tasks_complete_does_not_restore_serial_that_left_stock(): void
    {
        [$user, $employee] = $this->userWithEmployee();
        $product = $this->product(['stock_quantity' => 0]);
        $sold = $this->serial($product, [
            'status' => 'dismantled',
            'invoice_id' => 123,
            'sold_at' => now(),
        ]);
        $returned = $this->serial($product, [
            'status' => 'dismantled',
            'purchase_return_id' => 456,
        ]);

        foreach ([$sold, $returned] as $serial) {
            $task = $this->repairTask($product, $serial, $employee);

            $this->actingAs($user)
                ->postJson("/api/my-tasks/{$task->id}/complete")
                ->assertOk();

            $this->assertSame('dismantled', $serial->fresh()->status);
        }
    }

    public function test_unassigned_user_cannot_complete_task_from_my_tasks(): void
    {
        [$assignedUser, $assignedEmployee] = $this->userWithEmployee('Assigned Tech');
        [$otherUser] = $this->userWithEmployee('Other Tech');
        $product = $this->product(['stock_quantity' => 0]);
        $serial = $this->serial($product, ['status' => 'dismantled']);
        $task = $this->repairTask($product, $serial, $assignedEmployee);

        $this->actingAs($otherUser)
            ->postJson("/api/my-tasks/{$task->id}/complete")
            ->assertStatus(403);

        $this->assertSame(Task::STATUS_IN_PROGRESS, $task->fresh()->status);
        $this->assertSame('dismantled', $serial->fresh()->status);
        $this->assertNotNull($assignedUser->id);
    }
}
