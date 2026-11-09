<?php

namespace App\Repositories\Eloquent;

use App\Models\PlanningEvent;

class PlanningEventRepository extends DocumentRepository
{
    protected string $modelClass = PlanningEvent::class;

    protected string $idPrefix = 'pl';
}
