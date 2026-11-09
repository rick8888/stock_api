<?php

namespace App\Repositories\Eloquent;

use App\Models\ProcurementRequest;

class ProcurementRequestRepository extends DocumentRepository
{
    protected string $modelClass = ProcurementRequest::class;

    protected string $idPrefix = 'pr';
}
