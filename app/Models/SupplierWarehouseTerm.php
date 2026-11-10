<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Surcharge des conditions d'un fournisseur pour un point de livraison donné.
 */
class SupplierWarehouseTerm extends Model
{
    protected $table = 'supplier_warehouse_terms';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'supplier_id', 'warehouse_id', 'lead_time_days', 'lead_time_std_dev',
        'min_order_value', 'active', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'lead_time_days' => 'integer',
            'lead_time_std_dev' => 'float',
            'min_order_value' => 'float',
            'active' => 'boolean',
        ];
    }

    public function document(): array
    {
        return [
            'id' => $this->id,
            'supplierId' => $this->supplier_id,
            'warehouseId' => $this->warehouse_id,
            'leadTimeDays' => $this->lead_time_days === null ? null : (int) $this->lead_time_days,
            'leadTimeStdDev' => $this->lead_time_std_dev === null ? null : (float) $this->lead_time_std_dev,
            'minOrderValue' => $this->min_order_value === null ? null : (float) $this->min_order_value,
            'active' => (bool) $this->active,
            'companyId' => $this->company_id,
        ];
    }
}
