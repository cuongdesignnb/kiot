<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected Task $task,
        protected string $oldStatus,
        protected string $newStatus,
        protected string $changedByName
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $oldLabel = Task::STATUS_MAP[$this->oldStatus] ?? $this->oldStatus;
        $newLabel = Task::STATUS_MAP[$this->newStatus] ?? $this->newStatus;

        return [
            'task_id'    => $this->task->id,
            'task_code'  => $this->task->code,
            'task_title' => $this->task->title,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'changed_by' => $this->changedByName,
            'message'    => "Công việc \"{$this->task->title}\" chuyển trạng thái: {$oldLabel} → {$newLabel}",
        ];
    }
}
