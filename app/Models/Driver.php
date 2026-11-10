<?php

namespace App\Models;

class Driver extends RelationalDocumentModel
{
    protected $table = 'drivers';

    protected $fillable = [
        'id', 'payload', 'user_id', 'vehicle_id', 'phone', 'status', 'warehouse_id', 'company_id',
    ];

    public static function documentMap(): array
    {
        return [
            'userId' => 'user_id',
            'vehicleId' => 'vehicle_id',
            'phone' => 'phone',
            'status' => 'status',
            'warehouseId' => 'warehouse_id',
            'companyId' => 'company_id',
        ];
    }
}
