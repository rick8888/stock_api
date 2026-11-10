<?php

namespace App\Models;

class Warehouse extends RelationalDocumentModel
{
    protected $table = 'warehouses';

    protected $fillable = [
        'id', 'payload', 'name', 'city', 'region_id', 'address', 'manager_user_id',
        'capacity', 'active', 'company_id', 'lat', 'lng',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'active' => 'boolean',
            'capacity' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'name' => 'name',
            'city' => 'city',
            'regionId' => 'region_id',
            'address' => 'address',
            'managerUserId' => 'manager_user_id',
            'capacity' => 'capacity',
            'active' => 'active',
            'companyId' => 'company_id',
            'lat' => 'lat',
            'lng' => 'lng',
        ];
    }
}
