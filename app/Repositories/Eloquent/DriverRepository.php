<?php

namespace App\Repositories\Eloquent;

use App\Models\Driver;

class DriverRepository extends DocumentRepository
{
    protected string $modelClass = Driver::class;

    protected string $idPrefix = 'drv';
}
