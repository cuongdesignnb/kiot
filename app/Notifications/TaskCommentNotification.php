<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskCommentNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected Task $task,
        protected string $commentByName,
        protected string $commentPreview
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'task_id'         => $this->task->id,
            'task_code'       => $this->task->code,
            'task_title'      => $this->task->title,
            'comment_by'      => $this->commentByName,
            'comment_preview' => mb_substr($this->commentPreview, 0, 100),
            'message'         => "{$this->commentByName} bình luận trong \"{$this->task->title}\"",
        ];
    }
}
