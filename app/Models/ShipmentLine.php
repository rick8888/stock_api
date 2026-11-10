<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentLine extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'shipment_lines';

    protected $fillable = [
        'id', 'shipment_id', 'product_id', 'product_name', 'sku', 'quantity', 'unit', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
