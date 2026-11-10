<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingEvent extends Model
{
    protected $table = 'tracking_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'subject_type', 'subject_id', 'route_id', 'vehicle_id', 'driver_id',
        'lat', 'lng', 'accuracy_m', 'speed_kmh', 'heading', 'progress', 'event_type', 'source',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'accuracy_m' => 'float',
            'speed_kmh' => 'float',
            'heading' => 'float',
            'progress' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function document(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->company_id,
            'subjectType' => $this->subject_type,
            'subjectId' => $this->subject_id,
            'routeId' => $this->route_id,
            'vehicleId' => $this->vehicle_id,
            'driverId' => $this->driver_id,
            'lat' => (float) $this->lat,
            'lng' => (float) $this->lng,
            'accuracyM' => $this->accuracy_m === null ? null : (float) $this->accuracy_m,
            'speedKmh' => $this->speed_kmh === null ? null : (float) $this->speed_kmh,
            'heading' => $this->heading === null ? null : (float) $this->heading,
            'progress' => $this->progress === null ? null : (int) $this->progress,
            'eventType' => $this->event_type,
            'source' => $this->source,
            'recordedAt' => $this->recorded_at?->toIso8601String(),
        ];
    }
}
