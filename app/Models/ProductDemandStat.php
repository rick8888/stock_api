<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Demande moyenne et variabilité d'un article sur un entrepôt, dérivées de stock_movements.
 */
class ProductDemandStat extends Model
{
    protected $table = 'product_demand_stats';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'product_id', 'warehouse_id', 'avg_daily_demand', 'demand_std_dev',
        'sample_days', 'nonzero_days', 'total_issued', 'method',
        'window_start', 'window_end', 'computed_at', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'avg_daily_demand' => 'float',
            'demand_std_dev' => 'float',
            'sample_days' => 'integer',
            'nonzero_days' => 'integer',
            'total_issued' => 'integer',
            'window_start' => 'date',
            'window_end' => 'date',
            'computed_at' => 'datetime',
        ];
    }

    public function document(): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'warehouseId' => $this->warehouse_id,
            'avgDailyDemand' => (float) $this->avg_daily_demand,
            'demandStdDev' => (float) $this->demand_std_dev,
            'sampleDays' => (int) $this->sample_days,
            'nonzeroDays' => (int) $this->nonzero_days,
            'totalIssued' => (int) $this->total_issued,
            'method' => $this->method,
            'windowStart' => $this->window_start?->toDateString(),
            'windowEnd' => $this->window_end?->toDateString(),
            'computedAt' => $this->computed_at?->toIso8601String(),
            'companyId' => $this->company_id,
        ];
    }
}
