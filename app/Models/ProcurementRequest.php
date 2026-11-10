<?php

namespace App\Models;

use App\Support\Uid;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcurementRequest extends RelationalDocumentModel
{
    protected $table = 'procurement_requests';

    /** @var list<array<string, mixed>>|null */
    public ?array $pendingItems = null;

    protected $fillable = [
        'id', 'payload', 'code', 'requester_id', 'warehouse_id', 'status', 'priority', 'service',
        'requested_at', 'submitted_at', 'decided_at', 'decision_note', 'note', 'assignee_id',
        'assigned_role', 'handed_off_at', 'taken_at', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'requested_at' => 'datetime',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'handed_off_at' => 'datetime',
            'taken_at' => 'datetime',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'code' => 'code',
            'requesterId' => 'requester_id',
            'warehouseId' => 'warehouse_id',
            'status' => 'status',
            'priority' => 'priority',
            'service' => 'service',
            'decisionNote' => 'decision_note',
            'note' => 'note',
            'assigneeId' => 'assignee_id',
            'assignedRole' => 'assigned_role',
            'companyId' => 'company_id',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProcurementRequestItem::class, 'procurement_request_id')->orderBy('sort_order');
    }

    public function document(): array
    {
        $doc = parent::document();
        foreach ([
            'createdAt' => 'requested_at',
            'submittedAt' => 'submitted_at',
            'decidedAt' => 'decided_at',
            'handedOffAt' => 'handed_off_at',
            'takenAt' => 'taken_at',
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
            $doc['items'] = $rows->map(fn (ProcurementRequestItem $item) => [
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
            'createdAt' => 'requested_at',
            'submittedAt' => 'submitted_at',
            'decidedAt' => 'decided_at',
            'handedOffAt' => 'handed_off_at',
            'takenAt' => 'taken_at',
        ] as $camel => $column) {
            if (array_key_exists($camel, $data)) {
                $this->setAttribute($column, $data[$camel] ? \Carbon\Carbon::parse((string) $data[$camel]) : null);
            }
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
                'id' => Uid::make('pri'),
                'product_id' => $item['productId'],
                'quantity' => (int) ($item['quantity'] ?? 0),
                'sort_order' => $index,
            ]);
        }
        $this->pendingItems = null;
        $this->unsetRelation('items');
    }
}
