<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultationItem extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'consultation_items';

    protected $fillable = ['id', 'consultation_id', 'product_id', 'quantity', 'sort_order'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'sort_order' => 'integer'];
    }
}
