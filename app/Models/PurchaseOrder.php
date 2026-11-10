<?php

namespace App\Models;

use App\Support\Uid;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends RelationalDocumentModel
{
    protected $table = 'purchase_orders';

    /** @var list<array<string, mixed>>|null */
    public ?array $pendingItems = null;

    protected $fillable = [
        'id', 'payload', 'code', 'supplier_id', 'warehouse_id', 'status', 'expected_date', 'ordered_at',
        'amount', 'procurement_request_id', 'procurement_request_ids', 'consultation_id', 'quote_id', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expected_date' => 'date',
            'ordered_at' => 'datetime',
            'amount' => 'float',
            'procurement_request_ids' => 'array',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'code' => 'code',
            'supplierId' => 'supplier_id',
            'warehouseId' => 'warehouse_id',
            'status' => 'status',
            'amount' => 'amount',
            'procurementRequestId' => 'procurement_request_id',
            'consultationId' => 'consultation_id',
            'quoteId' => 'quote_id',
            'companyId' => 'company_id',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id')->orderBy('sort_order');
    }

    public function document(): array
    {
        $doc = parent::document();
        if ($this->expected_date) {
            $doc['expectedDate'] = $this->expected_date->toDateString();
        }
        if ($this->ordered_at) {
            $doc['createdAt'] = $this->ordered_at->toIso8601String();
        } elseif (is_array($this->payload) && isset($this->payload['createdAt'])) {
            $doc['createdAt'] = $this->payload['createdAt'];
        }
        if (is_array($this->procurement_request_ids)) {
            $doc['procurementRequestIds'] = $this->procurement_request_ids;
        }
        $rows = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        if ($rows->isNotEmpty()) {
            $doc['items'] = $rows->map(fn (PurchaseOrderItem $item) => [
                'productId' => $item->product_id,
                'quantity' => (int) $item->quantity,
            ])->values()->all();
        } elseif (! isset($doc['items'])) {
            $doc['items'] = [];
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('expectedDate', $data)) {
            $this->expected_date = $data['expectedDate']
                ? \Carbon\Carbon::parse((string) $data['expectedDate'])->toDateString()
                : null;
        }
        if (array_key_exists('createdAt', $data)) {
            $this->ordered_at = $data['createdAt'] ? \Carbon\Carbon::parse((string) $data['createdAt']) : null;
        }
        if (array_key_exists('procurementRequestIds', $data)) {
            $this->procurement_request_ids = is_array($data['procurementRequestIds'])
                ? array_values($data['procurementRequestIds'])
                : [];
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
                'id' => Uid::make('poi'),
                'product_id' => $item['productId'],
                'quantity' => (int) ($item['quantity'] ?? 0),
                'sort_order' => $index,
            ]);
        }
        $this->pendingItems = null;
        $this->unsetRelation('items');
    }
}
