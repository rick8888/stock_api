<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingNoteLine extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'shipping_note_lines';

    protected $fillable = [
        'id', 'shipping_note_id', 'product_id', 'product_name', 'sku', 'quantity', 'unit', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
