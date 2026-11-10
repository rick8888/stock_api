<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcurementRequestItem extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'procurement_request_items';

    protected $fillable = ['id', 'procurement_request_id', 'product_id', 'quantity', 'sort_order'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'sort_order' => 'integer'];
    }
}
