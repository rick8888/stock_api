<?php

namespace App\Models;

use App\Support\Uid;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Receipt extends RelationalDocumentModel
{
    protected $table = 'receipts';

    /** @var list<array<string, mixed>>|null */
    public ?array $pendingItems = null;

    protected $fillable = [
        'id', 'payload', 'code', 'purchase_order_id', 'warehouse_id', 'expected', 'received',
        'received_at', 'status', 'user_id', 'note', 'checked_at', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expected' => 'integer',
            'received' => 'integer',
            'received_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'code' => 'code',
            'purchaseOrderId' => 'purchase_order_id',
            'warehouseId' => 'warehouse_id',
            'expected' => 'expected',
            'received' => 'received',
            'status' => 'status',
            'userId' => 'user_id',
            'note' => 'note',
            'companyId' => 'company_id',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceiptItem::class, 'receipt_id')->orderBy('sort_order');
    }

    public function document(): array
    {
        $doc = parent::document();
        if ($this->received_at) {
            $doc['date'] = $this->received_at->toIso8601String();
        } elseif (is_array($this->payload) && isset($this->payload['date'])) {
            $doc['date'] = $this->payload['date'];
        }
        if ($this->checked_at) {
            $doc['checkedAt'] = $this->checked_at->toIso8601String();
        }
        $rows = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        if ($rows->isNotEmpty()) {
            $doc['items'] = $rows->map(function (ReceiptItem $item) {
                $row = [
                    'productId' => $item->product_id,
                    'expected' => (int) $item->expected,
                    'received' => (int) $item->received,
                ];
                if ($item->lot_code) {
                    $row['lotCode'] = $item->lot_code;
                }
                if ($item->expiry_date) {
                    $row['expiryDate'] = $item->expiry_date->toDateString();
                }
                if ($item->location_id) {
                    $row['locationId'] = $item->location_id;
                }

                return $row;
            })->values()->all();
        } elseif (! isset($doc['items'])) {
            $doc['items'] = [];
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('date', $data)) {
            $this->received_at = $data['date'] ? \Carbon\Carbon::parse((string) $data['date']) : null;
        }
        if (array_key_exists('checkedAt', $data)) {
            $this->checked_at = $data['checkedAt'] ? \Carbon\Carbon::parse((string) $data['checkedAt']) : null;
        }
        parent::fillDocument($data);
        if (array_key_exists('items', $data) && is_array($data['items'])) {
            $this->pendingItems = array_values($data['items']);
        }

        return $this;
    }

    public function flushPendingItems(): void
    {
        if ($this->pendingItems === null || ! $this->id) {
            return;
        }
        $this->items()->delete();
        foreach ($this->pendingItems as $index => $item) {
            if (empty($item['productId'])) {
                continue;
            }
            $this->items()->create([
                'id' => Uid::make('rci'),
                'product_id' => $item['productId'],
                'expected' => (int) ($item['expected'] ?? 0),
                'received' => (int) ($item['received'] ?? 0),
                'lot_code' => $item['lotCode'] ?? null,
                'expiry_date' => ! empty($item['expiryDate'])
                    ? \Carbon\Carbon::parse((string) $item['expiryDate'])->toDateString()
                    : null,
                'location_id' => $item['locationId'] ?? null,
                'sort_order' => $index,
            ]);
        }
        $this->pendingItems = null;
        $this->unsetRelation('items');
    }
}
