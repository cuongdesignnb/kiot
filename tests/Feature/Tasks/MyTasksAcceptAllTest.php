<?php

namespace Tests\Feature\Tasks;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TaskAssignment;

class MyTasksAcceptAllTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;
    private User $userB;
    private Employee $empA;
    private Employee $empB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create(['name' => 'Nhân viên A']);
        $this->userB = User::factory()->create(['name' => 'Nhân viên B']);

        $this->empA = Employee::create([
            'name' => 'Nhân viên A', 'phone' => '0901000001',
            'user_id' => $this->userA->id, 'status' => 'active',
        ]);
        $this->empB = Employee::create([
            'name' => 'Nhân viên B', 'phone' => '0901000002',
            'user_id' => $this->userB->id, 'status' => 'active',
        ]);
    }

    private function createTaskWithAssignment(Employee $emp, string $assignStatus = 'pending', string $taskStatus = 'pending'): array
    {
        $task = Task::create([
            'code' => 'TASK-' . uniqid(),
            'type' => 'general',
            'title' => 'Test task',
            'status' => $taskStatus,
            'priority' => 'normal',
        ]);

        $assignment = TaskAssignment::create([
            'task_id' => $task->id,
            'employee_id' => $emp->id,
            'status' => $assignStatus,
            'assigned_at' => now(),
        ]);

        return [$task, $assignment];
    }

    /** @test */
    public function test_my_tasks_accept_all_route_exists()
    {
        $response = $this->actingAs($this->userA)
            ->postJson('/api/my-tasks/accept-all');

        $this->assertNotEquals(404, $response->status(), 'Route /api/my-tasks/accept-all should exist');
    }

    /** @test */
    public function test_accept_all_accepts_only_current_user_pending_assignments()
    {
        // User A: 2 pending
        $this->createTaskWithAssignment($this->empA, 'pending', 'pending');
        $this->createTaskWithAssignment($this->empA, 'pending', 'pending');
        // User B: 1 pending
        $this->createTaskWithAssignment($this->empB, 'pending', 'pending');

        $response = $this->actingAs($this->userA)
            ->postJson('/api/my-tasks/accept-all');

        $response->assertOk();
        $this->assertEquals(2, $response->json('accepted'));

        // A's assignments should be accepted
        $aAssignments = TaskAssignment::where('employee_id', $this->empA->id)->get();
        $this->assertTrue($aAssignments->every(fn($a) => $a->status === 'accepted'));

        // B's assignment should still be pending
        $bAssignment = TaskAssignment::where('employee_id', $this->empB->id)->first();
        $this->assertEquals('pending', $bAssignment->status);
    }

    /** @test */
    public function test_accept_all_ignores_completed_or_cancelled_tasks()
    {
        $this->createTaskWithAssignment($this->empA, 'pending', 'completed');
        $this->createTaskWithAssignment($this->empA, 'pending', 'cancelled');

        $response = $this->actingAs($this->userA)
            ->postJson('/api/my-tasks/accept-all');

        $response->assertOk();
        $this->assertEquals(0, $response->json('accepted'));
    }

    /** @test */
    public function test_accept_all_ignores_already_accepted_or_rejected_assignments()
    {
        $this->createTaskWithAssignment($this->empA, 'accepted', 'in_progress');
        $this->createTaskWithAssignment($this->empA, 'rejected', 'pending');

        $response = $this->actingAs($this->userA)
            ->postJson('/api/my-tasks/accept-all');

        $response->assertOk();
        $this->assertEquals(0, $response->json('accepted'));
    }

    /** @test */
    public function test_accept_all_returns_zero_when_no_pending()
    {
        $response = $this->actingAs($this->userA)
            ->postJson('/api/my-tasks/accept-all');

        $response->assertOk();
        $this->assertEquals(0, $response->json('accepted'));
        $this->assertStringContainsString('0', $response->json('message'));
    }

    /** @test */
    public function test_single_respond_accept_still_works()
    {
        [$task, $assignment] = $this->createTaskWithAssignment($this->empA, 'pending', 'pending');

        $response = $this->actingAs($this->userA)
            ->postJson("/api/my-tasks/{$assignment->id}/respond", ['status' => 'accepted']);

        $response->assertOk();
        $this->assertEquals('accepted', $assignment->fresh()->status);
    }

    /** @test */
    public function test_single_respond_reject_still_works()
    {
        [$task, $assignment] = $this->createTaskWithAssignment($this->empA, 'pending', 'pending');

        $response = $this->actingAs($this->userA)
            ->postJson("/api/my-tasks/{$assignment->id}/respond", ['status' => 'rejected']);

        $response->assertOk();
        $this->assertEquals('rejected', $assignment->fresh()->status);
    }

    /** @test */
    public function test_single_respond_cannot_modify_other_users_assignment()
    {
        [$task, $bAssignment] = $this->createTaskWithAssignment($this->empB, 'pending', 'pending');

        $response = $this->actingAs($this->userA)
            ->postJson("/api/my-tasks/{$bAssignment->id}/respond", ['status' => 'accepted']);

        $response->assertStatus(403);
        $this->assertEquals('pending', $bAssignment->fresh()->status);
    }

    /** @test */
    public function test_my_tasks_index_still_returns_assignments()
    {
        $this->createTaskWithAssignment($this->empA, 'pending', 'pending');

        $response = $this->actingAs($this->userA)
            ->getJson('/api/my-tasks');

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }
}
