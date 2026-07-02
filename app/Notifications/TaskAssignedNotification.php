<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected Task $task,
        protected string $assignerName
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'task_id'    => $this->task->id,
            'task_code'  => $this->task->code,
            'task_title' => $this->task->title,
            'task_type'  => $this->task->type,
            'assigned_by' => $this->assignerName,
            'message'    => "Bạn được giao công việc: {$this->task->title}",
        ];
    }
}
