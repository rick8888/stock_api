<?php

namespace App\Models;

class WarehouseLocation extends RelationalDocumentModel
{
    protected $table = 'warehouse_locations';

    protected $fillable = [
        'id', 'payload', 'warehouse_id', 'code', 'aisle', 'rack', 'bin', 'type', 'active', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'active' => 'boolean',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'warehouseId' => 'warehouse_id',
            'code' => 'code',
            'aisle' => 'aisle',
            'rack' => 'rack',
            'bin' => 'bin',
            'type' => 'type',
            'active' => 'active',
            'companyId' => 'company_id',
        ];
    }
}
