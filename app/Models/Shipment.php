<?php

namespace App\Models;

use App\Models\Concerns\SyncsDocumentLines;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends RelationalDocumentModel
{
    use SyncsDocumentLines;

    protected $table = 'shipments';

    protected $fillable = [
        'id', 'payload', 'code', 'origin_warehouse_id', 'destination', 'destination_warehouse_id',
        'vehicle_id', 'driver_id', 'route_id', 'shipped_at', 'status', 'progress',
        'shipping_note_ids', 'notes', 'company_id', 'eta_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'shipped_at' => 'datetime',
            'eta_at' => 'datetime',
            'progress' => 'integer',
            'shipping_note_ids' => 'array',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'code' => 'code',
            'originWarehouseId' => 'origin_warehouse_id',
            'destination' => 'destination',
            'destinationWarehouseId' => 'destination_warehouse_id',
            'vehicleId' => 'vehicle_id',
            'driverId' => 'driver_id',
            'routeId' => 'route_id',
            'status' => 'status',
            'progress' => 'progress',
            'notes' => 'notes',
            'companyId' => 'company_id',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentLine::class, 'shipment_id')->orderBy('sort_order');
    }

    protected function documentLineForeignKey(): string
    {
        return 'shipment_id';
    }

    protected function documentLineIdPrefix(): string
    {
        return 'sln';
    }

    public function document(): array
    {
        $doc = parent::document();
        if ($this->shipped_at) {
            $doc['date'] = $this->shipped_at->toIso8601String();
        } elseif (is_array($this->payload) && isset($this->payload['date'])) {
            $doc['date'] = $this->payload['date'];
        }
        if ($this->eta_at) {
            $doc['eta'] = $this->eta_at->toIso8601String();
        }
        if (is_array($this->shipping_note_ids)) {
            $doc['shippingNoteIds'] = $this->shipping_note_ids;
        }
        $lines = $this->documentLinesArray();
        if ($lines) {
            $doc['lines'] = $lines;
        } elseif (! isset($doc['lines'])) {
            $doc['lines'] = [];
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('date', $data)) {
            $this->shipped_at = $data['date'] ? \Carbon\Carbon::parse((string) $data['date']) : null;
        }
        if (array_key_exists('eta', $data)) {
            $this->eta_at = $data['eta'] ? \Carbon\Carbon::parse((string) $data['eta']) : null;
        }
        if (array_key_exists('shippingNoteIds', $data)) {
            $this->shipping_note_ids = is_array($data['shippingNoteIds']) ? array_values($data['shippingNoteIds']) : [];
        }
        parent::fillDocument($data);
        if (array_key_exists('lines', $data) && is_array($data['lines'])) {
            $this->queueDocumentLines($data['lines']);
        }

        return $this;
    }
}
