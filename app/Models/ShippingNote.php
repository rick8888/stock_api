<?php

namespace App\Models;

use App\Support\Uid;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingNote extends RelationalDocumentModel
{
    protected $table = 'shipping_notes';

    protected $fillable = [
        'id', 'payload', 'code', 'warehouse_id', 'customer_id', 'address_id', 'client', 'address', 'city',
        'status', 'delivery_id', 'shipment_id', 'carrier_id', 'carrier_name', 'tracking_number',
        'label_printed_at', 'booked_at', 'loaded_at', 'loaded_by_user_id', 'stock_reserved',
        'in_transit_to_warehouse_id', 'stock_received_at', 'created_by_user_id', 'company_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'stock_reserved' => 'boolean',
            'label_printed_at' => 'datetime',
            'booked_at' => 'datetime',
            'loaded_at' => 'datetime',
            'stock_received_at' => 'datetime',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'code' => 'code',
            'warehouseId' => 'warehouse_id',
            'customerId' => 'customer_id',
            'addressId' => 'address_id',
            'client' => 'client',
            'address' => 'address',
            'city' => 'city',
            'status' => 'status',
            'deliveryId' => 'delivery_id',
            'shipmentId' => 'shipment_id',
            'carrierId' => 'carrier_id',
            'carrierName' => 'carrier_name',
            'trackingNumber' => 'tracking_number',
            'loadedByUserId' => 'loaded_by_user_id',
            'stockReserved' => 'stock_reserved',
            'inTransitToWarehouseId' => 'in_transit_to_warehouse_id',
            'createdByUserId' => 'created_by_user_id',
            'companyId' => 'company_id',
            'notes' => 'notes',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ShippingNoteLine::class, 'shipping_note_id')->orderBy('sort_order');
    }

    public function document(): array
    {
        $doc = parent::document();
        foreach ([
            'labelPrintedAt' => 'label_printed_at',
            'bookedAt' => 'booked_at',
            'loadedAt' => 'loaded_at',
            'stockReceivedAt' => 'stock_received_at',
            'createdAt' => 'created_at',
        ] as $camel => $column) {
            $value = $this->getAttribute($column);
            if ($value) {
                $doc[$camel] = $value instanceof \DateTimeInterface
                    ? \Carbon\Carbon::instance($value)->toIso8601String()
                    : (string) $value;
            }
        }

        $lineRows = $this->relationLoaded('lines')
            ? $this->lines
            : $this->lines()->get();

        if ($lineRows->isNotEmpty()) {
            $doc['lines'] = $lineRows->map(fn (ShippingNoteLine $line) => [
                'productId' => $line->product_id,
                'productName' => $line->product_name,
                'sku' => $line->sku,
                'quantity' => (int) $line->quantity,
                'unit' => $line->unit,
            ])->values()->all();
        } elseif (! isset($doc['lines'])) {
            $doc['lines'] = [];
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        foreach (['labelPrintedAt' => 'label_printed_at', 'bookedAt' => 'booked_at', 'loadedAt' => 'loaded_at', 'stockReceivedAt' => 'stock_received_at'] as $camel => $column) {
            if (! array_key_exists($camel, $data)) {
                continue;
            }
            $this->setAttribute($column, $data[$camel] ? \Carbon\Carbon::parse((string) $data[$camel]) : null);
        }

        parent::fillDocument($data);
        $this->syncLines(is_array($data['lines'] ?? null) ? $data['lines'] : []);

        return $this;
    }

    /** @param list<array<string, mixed>> $lines */
    private function syncLines(array $lines): void
    {
        if (! $this->id) {
            return;
        }

        // Persist child rows after parent save — called from repository after save as well.
        $this->pendingLines = array_values($lines);
    }

    /** @var list<array<string, mixed>>|null */
    public ?array $pendingLines = null;

    public function flushPendingLines(): void
    {
        if ($this->pendingLines === null || ! $this->id) {
            return;
        }

        $this->lines()->delete();
        foreach ($this->pendingLines as $index => $line) {
            if (empty($line['productId'])) {
                continue;
            }
            $this->lines()->create([
                'id' => Uid::make('bnl'),
                'product_id' => $line['productId'],
                'product_name' => $line['productName'] ?? null,
                'sku' => $line['sku'] ?? null,
                'quantity' => (int) ($line['quantity'] ?? 0),
                'unit' => $line['unit'] ?? null,
                'sort_order' => $index,
            ]);
        }
        $this->pendingLines = null;
        $this->unsetRelation('lines');
    }
}
