<?php

namespace App\Models;

class Product extends RelationalDocumentModel
{
    protected $table = 'products';

    protected $fillable = [
        'id', 'payload', 'sku', 'name', 'category_id', 'unit', 'min_stock', 'max_stock',
        'price', 'supplier_id', 'archived', 'daily_demand', 'barcode', 'track_lots', 'company_id',
        'safety_stock', 'service_level', 'abc_class', 'replenishment_policy', 'review_period_days',
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
            'safety_stock' => 'integer',
            'service_level' => 'float',
            'review_period_days' => 'integer',
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
            'safetyStock' => 'safety_stock',
            'serviceLevel' => 'service_level',
            'abcClass' => 'abc_class',
            'replenishmentPolicy' => 'replenishment_policy',
            'reviewPeriodDays' => 'review_period_days',
        ];
    }
}
