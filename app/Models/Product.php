<?php

namespace App\Models;

class Product extends RelationalDocumentModel
{
    protected $table = 'products';

    protected $fillable = [
        'id', 'payload', 'sku', 'name', 'category_id', 'unit', 'min_stock', 'max_stock',
        'price', 'supplier_id', 'archived', 'daily_demand', 'barcode', 'track_lots', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'archived' => 'boolean',
            'track_lots' => 'boolean',
            'min_stock' => 'integer',
            'max_stock' => 'integer',
            'price' => 'float',
            'daily_demand' => 'float',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'sku' => 'sku',
            'name' => 'name',
            'categoryId' => 'category_id',
            'unit' => 'unit',
            'minStock' => 'min_stock',
            'maxStock' => 'max_stock',
            'price' => 'price',
            'supplierId' => 'supplier_id',
            'archived' => 'archived',
            'dailyDemand' => 'daily_demand',
            'barcode' => 'barcode',
            'trackLots' => 'track_lots',
            'companyId' => 'company_id',
        ];
    }
}
