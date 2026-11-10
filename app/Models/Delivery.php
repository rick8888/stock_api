<?php

namespace App\Models;

use App\Models\Concerns\SyncsDocumentLines;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends RelationalDocumentModel
{
    use SyncsDocumentLines;

    protected $table = 'deliveries';

    protected $fillable = [
        'id', 'payload', 'code', 'client', 'address', 'city', 'scheduled_at', 'status', 'driver_id',
        'vehicle_id', 'route_id', 'sequence', 'warehouse_id', 'shipping_note_id', 'progress',
        'signed_by', 'signed_at', 'notes', 'company_id', 'eta_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'scheduled_at' => 'datetime',
            'signed_at' => 'datetime',
            'eta_at' => 'datetime',
            'sequence' => 'integer',
            'progress' => 'integer',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'code' => 'code',
            'client' => 'client',
            'address' => 'address',
            'city' => 'city',
            'status' => 'status',
            'driverId' => 'driver_id',
            'vehicleId' => 'vehicle_id',
            'routeId' => 'route_id',
            'sequence' => 'sequence',
            'warehouseId' => 'warehouse_id',
            'shippingNoteId' => 'shipping_note_id',
            'progress' => 'progress',
            'signedBy' => 'signed_by',
            'notes' => 'notes',
            'companyId' => 'company_id',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class, 'delivery_id')->orderBy('sort_order');
    }

    protected function documentLineForeignKey(): string
    {
        return 'delivery_id';
    }

    protected function documentLineIdPrefix(): string
    {
        return 'dln';
    }

    public function document(): array
    {
        $doc = parent::document();
        foreach ([
            'scheduledAt' => 'scheduled_at',
            'signedAt' => 'signed_at',
            'eta' => 'eta_at',
        ] as $camel => $column) {
            $value = $this->getAttribute($column);
            if ($value) {
                $doc[$camel] = $value instanceof \DateTimeInterface
                    ? \Carbon\Carbon::instance($value)->toIso8601String()
                    : (string) $value;
            }
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
        foreach (['scheduledAt' => 'scheduled_at', 'signedAt' => 'signed_at', 'eta' => 'eta_at'] as $camel => $column) {
            if (! array_key_exists($camel, $data)) {
                continue;
            }
            $this->setAttribute($column, $data[$camel] ? \Carbon\Carbon::parse((string) $data[$camel]) : null);
        }
        parent::fillDocument($data);
        if (array_key_exists('lines', $data) && is_array($data['lines'])) {
            $this->queueDocumentLines($data['lines']);
        }

        return $this;
    }
}
