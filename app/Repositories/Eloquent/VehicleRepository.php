<?php

namespace App\Repositories\Eloquent;

use App\Models\Vehicle;

class VehicleRepository extends DocumentRepository
{
    protected string $modelClass = Vehicle::class;

    protected string $idPrefix = 'veh';
}
