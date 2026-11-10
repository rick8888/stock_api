<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventorySessionLine extends Model
{
    protected $table = 'inventory_session_lines';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'inventory_session_id', 'product_id', 'expected', 'counted', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'expected' => 'integer',
            'counted' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
