<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceiptItem extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'receipt_items';

    protected $fillable = [
        'id', 'receipt_id', 'product_id', 'expected', 'received', 'lot_code', 'expiry_date', 'location_id', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'expected' => 'integer',
            'received' => 'integer',
            'sort_order' => 'integer',
            'expiry_date' => 'date',
        ];
    }
}
