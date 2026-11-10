<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Conditions d'achat d'un article chez un fournisseur : prix, MOQ, colisage, délai.
 */
class SupplierProduct extends Model
{
    protected $table = 'supplier_products';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'supplier_id', 'product_id', 'supplier_sku', 'unit_price', 'currency',
        'min_order_qty', 'order_multiple', 'lead_time_days', 'is_preferred', 'active', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'float',
            'min_order_qty' => 'integer',
            'order_multiple' => 'integer',
            'lead_time_days' => 'integer',
            'is_preferred' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function document(): array
    {
        return [
            'id' => $this->id,
            'supplierId' => $this->supplier_id,
            'productId' => $this->product_id,
            'supplierSku' => $this->supplier_sku,
            'unitPrice' => $this->unit_price === null ? null : (float) $this->unit_price,
            'currency' => $this->currency,
            'minOrderQty' => $this->min_order_qty === null ? null : (int) $this->min_order_qty,
            'orderMultiple' => $this->order_multiple === null ? null : (int) $this->order_multiple,
            'leadTimeDays' => $this->lead_time_days === null ? null : (int) $this->lead_time_days,
            'isPreferred' => (bool) $this->is_preferred,
            'active' => (bool) $this->active,
            'companyId' => $this->company_id,
        ];
    }
}
