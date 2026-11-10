<?php

namespace App\Models;

class Vehicle extends RelationalDocumentModel
{
    protected $table = 'vehicles';

    protected $fillable = ['id', 'payload', 'name', 'plate', 'status', 'warehouse_id', 'company_id'];

    public static function documentMap(): array
    {
        return [
            'name' => 'name',
            'plate' => 'plate',
            'status' => 'status',
            'warehouseId' => 'warehouse_id',
            'companyId' => 'company_id',
        ];
    }
}
