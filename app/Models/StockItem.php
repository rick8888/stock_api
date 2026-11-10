<?php

namespace App\Models;

class StockItem extends RelationalDocumentModel
{
    protected $table = 'stock_items';

    protected $fillable = [
        'id', 'payload', 'product_id', 'warehouse_id', 'quantity', 'reserved', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'quantity' => 'integer',
            'reserved' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'productId' => 'product_id',
            'warehouseId' => 'warehouse_id',
            'quantity' => 'quantity',
            'reserved' => 'reserved',
            'enabled' => 'enabled',
        ];
    }
}
