<?php

namespace App\Repositories\Eloquent;

use App\Models\TaskItem;

class TaskItemRepository extends DocumentRepository
{
    protected string $modelClass = TaskItem::class;

    protected string $idPrefix = 'tsk';
}
