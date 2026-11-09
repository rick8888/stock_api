<?php

namespace App\Repositories\Eloquent;

use App\Models\Alert;

class AlertRepository extends DocumentRepository
{
    protected string $modelClass = Alert::class;

    protected string $idPrefix = 'al';
}
