<?php

namespace App\Models;

class Route extends RelationalDocumentModel
{
    protected $table = 'routes';

    protected $fillable = [
        'id', 'payload', 'name', 'route_date', 'driver_id', 'vehicle_id', 'warehouse_id',
        'status', 'distance_km', 'progress', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'route_date' => 'date',
            'distance_km' => 'float',
            'progress' => 'integer',
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

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('date', $data)) {
            $this->route_date = $data['date'] ? \Carbon\Carbon::parse((string) $data['date'])->toDateString() : null;
        }

        return parent::fillDocument($data);
    }
}
