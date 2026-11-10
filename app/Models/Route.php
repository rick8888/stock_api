<?php

namespace App\Models;

class Route extends RelationalDocumentModel
{
    protected $table = 'routes';

    protected $fillable = [
        'id', 'payload', 'name', 'route_date', 'driver_id', 'vehicle_id', 'warehouse_id',
        'status', 'distance_km', 'progress', 'company_id',
        'last_lat', 'last_lng', 'last_located_at', 'eta_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'route_date' => 'date',
            'distance_km' => 'float',
            'progress' => 'integer',
            'last_lat' => 'float',
            'last_lng' => 'float',
            'last_located_at' => 'datetime',
            'eta_at' => 'datetime',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'name' => 'name',
            'driverId' => 'driver_id',
            'vehicleId' => 'vehicle_id',
            'warehouseId' => 'warehouse_id',
            'status' => 'status',
            'distanceKm' => 'distance_km',
            'progress' => 'progress',
            'companyId' => 'company_id',
            'lastLat' => 'last_lat',
            'lastLng' => 'last_lng',
            'lastLocatedAt' => 'last_located_at',
            'etaAt' => 'eta_at',
        ];
    }

    public function document(): array
    {
        $doc = parent::document();
        if ($this->route_date) {
            $doc['date'] = $this->route_date->toDateString();
        } elseif (is_array($this->payload) && isset($this->payload['date'])) {
            $doc['date'] = $this->payload['date'];
        }
        if ($this->last_located_at) {
            $doc['lastLocatedAt'] = $this->last_located_at->toIso8601String();
        }
        if ($this->eta_at) {
            $doc['eta'] = $this->eta_at->toIso8601String();
            $doc['etaAt'] = $this->eta_at->toIso8601String();
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('date', $data)) {
            $this->route_date = $data['date'] ? \Carbon\Carbon::parse((string) $data['date'])->toDateString() : null;
        }
        if (array_key_exists('eta', $data) && ! array_key_exists('etaAt', $data)) {
            $data['etaAt'] = $data['eta'];
        }
        if (array_key_exists('etaAt', $data)) {
            $this->eta_at = $data['etaAt']
                ? \Carbon\Carbon::parse((string) $data['etaAt'])
                : null;
        }
        if (array_key_exists('lastLocatedAt', $data)) {
            $this->last_located_at = $data['lastLocatedAt']
                ? \Carbon\Carbon::parse((string) $data['lastLocatedAt'])
                : null;
        }

        return parent::fillDocument($data);
    }
}
