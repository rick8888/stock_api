<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'purchase_order_items';

    protected $fillable = ['id', 'purchase_order_id', 'product_id', 'quantity', 'sort_order'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'sort_order' => 'integer'];
    }
}
