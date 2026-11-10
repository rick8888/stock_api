<?php

namespace App\Models;

class StockPlacement extends RelationalDocumentModel
{
    protected $table = 'stock_placements';

    protected $fillable = [
        'id', 'payload', 'product_id', 'warehouse_id', 'location_id', 'lot_id', 'quantity', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'quantity' => 'integer',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'productId' => 'product_id',
            'warehouseId' => 'warehouse_id',
            'locationId' => 'location_id',
            'lotId' => 'lot_id',
            'quantity' => 'quantity',
            'companyId' => 'company_id',
        ];
    }
}
