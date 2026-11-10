<?php

namespace App\Models;

class Vehicle extends RelationalDocumentModel
{
    protected $table = 'vehicles';

    protected $fillable = [
        'id', 'payload', 'name', 'plate', 'status', 'warehouse_id', 'company_id',
        'last_lat', 'last_lng', 'last_located_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'last_lat' => 'float',
            'last_lng' => 'float',
            'last_located_at' => 'datetime',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'name' => 'name',
            'plate' => 'plate',
            'status' => 'status',
            'warehouseId' => 'warehouse_id',
            'companyId' => 'company_id',
            'lastLat' => 'last_lat',
            'lastLng' => 'last_lng',
            'lastLocatedAt' => 'last_located_at',
        ];
    }

    public function document(): array
    {
        $doc = parent::document();
        if ($this->last_located_at) {
            $doc['lastLocatedAt'] = $this->last_located_at->toIso8601String();
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('lastLocatedAt', $data)) {
            $this->last_located_at = $data['lastLocatedAt']
                ? \Carbon\Carbon::parse((string) $data['lastLocatedAt'])
                : null;
        }

        return parent::fillDocument($data);
    }
}
