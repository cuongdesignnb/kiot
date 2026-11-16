<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Item 33 — công việc tạo không nhập hạn thì hạn mặc định = ngày tạo + 10 ngày.
 */
class TaskDefaultDeadlineTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_task_without_deadline_defaults_to_created_date_plus_ten_days(): void
    {
        Carbon::setTestNow('2026-11-15 09:00:00');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/tasks', ['title' => 'Kiểm tra máy trưng bày']);
        $response->assertCreated();

        $task = Task::findOrFail($response->json('id'));
        $this->assertSame('2026-11-25', $task->deadline->toDateString());
        $this->assertSame(10, Task::DEFAULT_DEADLINE_DAYS);

        $empty = $this->actingAs($user)->postJson('/api/tasks', ['title' => 'Không nhập hạn', 'deadline' => null]);
        $empty->assertCreated();
        $this->assertSame('2026-11-25', Task::findOrFail($empty->json('id'))->deadline->toDateString());
    }

    public function test_explicit_deadline_is_kept(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/tasks', [
            'title' => 'Có hạn riêng',
            'deadline' => '2026-12-01',
        ]);

        $response->assertCreated();
        $this->assertSame('2026-12-01', Task::findOrFail($response->json('id'))->deadline->toDateString());
    }
}
