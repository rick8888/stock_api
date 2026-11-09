<?php

namespace App\Repositories\Eloquent;

use App\Models\Shipment;

class ShipmentRepository extends DocumentRepository
{
    protected string $modelClass = Shipment::class;

    protected string $idPrefix = 'shp';
}
