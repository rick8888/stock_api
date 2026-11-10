<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Proposition de réapprovisionnement figée : conserve les paramètres d'entrée du calcul
 * pour rester explicable et auditable après coup.
 */
class ReplenishmentSuggestion extends Model
{
    protected $table = 'replenishment_suggestions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'product_id', 'warehouse_id', 'supplier_id', 'policy',
        'avg_daily_demand', 'demand_std_dev', 'lead_time_days', 'lead_time_std_dev', 'service_level',
        'safety_stock', 'reorder_point', 'target_level',
        'available', 'in_transit', 'pending_need',
        'raw_quantity', 'suggested_quantity', 'min_order_qty', 'order_multiple',
        'unit_price', 'estimated_cost', 'risk', 'reasons', 'computed_at', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'avg_daily_demand' => 'float',
            'demand_std_dev' => 'float',
            'lead_time_days' => 'float',
            'lead_time_std_dev' => 'float',
            'service_level' => 'float',
            'safety_stock' => 'integer',
            'reorder_point' => 'integer',
            'target_level' => 'integer',
            'available' => 'integer',
            'in_transit' => 'integer',
            'pending_need' => 'integer',
            'raw_quantity' => 'integer',
            'suggested_quantity' => 'integer',
            'min_order_qty' => 'integer',
            'order_multiple' => 'integer',
            'unit_price' => 'float',
            'estimated_cost' => 'float',
            'reasons' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function document(): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'warehouseId' => $this->warehouse_id,
            'supplierId' => $this->supplier_id,
            'policy' => $this->policy,
            'avgDailyDemand' => (float) $this->avg_daily_demand,
            'demandStdDev' => (float) $this->demand_std_dev,
            'leadTimeDays' => (float) $this->lead_time_days,
            'leadTimeStdDev' => (float) $this->lead_time_std_dev,
            'serviceLevel' => $this->service_level === null ? null : (float) $this->service_level,
            'safetyStock' => (int) $this->safety_stock,
            'reorderPoint' => (int) $this->reorder_point,
            'targetLevel' => (int) $this->target_level,
            'available' => (int) $this->available,
            'inTransit' => (int) $this->in_transit,
            'pendingNeed' => (int) $this->pending_need,
            'rawQuantity' => (int) $this->raw_quantity,
            'suggestedQuantity' => (int) $this->suggested_quantity,
            'minOrderQty' => $this->min_order_qty === null ? null : (int) $this->min_order_qty,
            'orderMultiple' => $this->order_multiple === null ? null : (int) $this->order_multiple,
            'unitPrice' => $this->unit_price === null ? null : (float) $this->unit_price,
            'estimatedCost' => $this->estimated_cost === null ? null : (float) $this->estimated_cost,
            // Dérivée plutôt que stockée : elle ne peut pas diverger de ses deux termes.
            'coverDays' => $this->avg_daily_demand > 0
                ? round($this->available / $this->avg_daily_demand, 1)
                : null,
            'risk' => $this->risk,
            'reasons' => $this->reasons ?? [],
            'computedAt' => $this->computed_at?->toIso8601String(),
            'companyId' => $this->company_id,
        ];
    }
}
