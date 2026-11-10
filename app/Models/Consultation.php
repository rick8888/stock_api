<?php

namespace App\Models;

use App\Support\Uid;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consultation extends RelationalDocumentModel
{
    protected $table = 'consultations';

    /** @var list<array<string, mixed>>|null */
    public ?array $pendingItems = null;

    protected $fillable = [
        'id', 'payload', 'code', 'status', 'warehouse_id', 'procurement_request_ids', 'supplier_ids',
        'quotes', 'consulted_at', 'sent_at', 'quoted_at', 'awarded_at', 'awarded_quote_id', 'note', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'procurement_request_ids' => 'array',
            'supplier_ids' => 'array',
            'quotes' => 'array',
            'consulted_at' => 'datetime',
            'sent_at' => 'datetime',
            'quoted_at' => 'datetime',
            'awarded_at' => 'datetime',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'code' => 'code',
            'status' => 'status',
            'warehouseId' => 'warehouse_id',
            'awardedQuoteId' => 'awarded_quote_id',
            'note' => 'note',
            'companyId' => 'company_id',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ConsultationItem::class, 'consultation_id')->orderBy('sort_order');
    }

    public function document(): array
    {
        $doc = parent::document();
        if (is_array($this->procurement_request_ids)) {
            $doc['procurementRequestIds'] = $this->procurement_request_ids;
        }
        if (is_array($this->supplier_ids)) {
            $doc['supplierIds'] = $this->supplier_ids;
        }
        if (is_array($this->quotes)) {
            $doc['quotes'] = $this->quotes;
        }
        foreach ([
            'createdAt' => 'consulted_at',
            'sentAt' => 'sent_at',
            'quotedAt' => 'quoted_at',
            'awardedAt' => 'awarded_at',
        ] as $camel => $column) {
            $value = $this->getAttribute($column);
            if ($value) {
                $doc[$camel] = $value instanceof \DateTimeInterface
                    ? \Carbon\Carbon::instance($value)->toIso8601String()
                    : (string) $value;
            }
        }
        $rows = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        if ($rows->isNotEmpty()) {
            $doc['items'] = $rows->map(fn (ConsultationItem $item) => [
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
        foreach ([
            'createdAt' => 'consulted_at',
            'sentAt' => 'sent_at',
            'quotedAt' => 'quoted_at',
            'awardedAt' => 'awarded_at',
        ] as $camel => $column) {
            if (array_key_exists($camel, $data)) {
                $this->setAttribute($column, $data[$camel] ? \Carbon\Carbon::parse((string) $data[$camel]) : null);
            }
        }
        if (array_key_exists('procurementRequestIds', $data)) {
            $this->procurement_request_ids = is_array($data['procurementRequestIds']) ? array_values($data['procurementRequestIds']) : [];
        }
        if (array_key_exists('supplierIds', $data)) {
            $this->supplier_ids = is_array($data['supplierIds']) ? array_values($data['supplierIds']) : [];
        }
        if (array_key_exists('quotes', $data)) {
            $this->quotes = is_array($data['quotes']) ? array_values($data['quotes']) : [];
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
                'id' => Uid::make('csi'),
                'product_id' => $item['productId'],
                'quantity' => (int) ($item['quantity'] ?? 0),
                'sort_order' => $index,
            ]);
        }
        $this->pendingItems = null;
        $this->unsetRelation('items');
    }
}
