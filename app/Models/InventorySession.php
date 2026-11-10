<?php

namespace App\Models;

use App\Support\Uid;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventorySession extends RelationalDocumentModel
{
    protected $table = 'inventory_sessions';

    /** @var list<array<string, mixed>>|null */
    public ?array $pendingLines = null;

    protected $fillable = [
        'id', 'payload', 'warehouse_id', 'status', 'mode', 'scope', 'counted', 'expected',
        'started_at', 'completed_at', 'user_id', 'counted_by_user_id', 'approved_by_user_id', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'scope' => 'array',
            'counted' => 'integer',
            'expected' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'warehouseId' => 'warehouse_id',
            'status' => 'status',
            'mode' => 'mode',
            'counted' => 'counted',
            'expected' => 'expected',
            'userId' => 'user_id',
            'countedByUserId' => 'counted_by_user_id',
            'approvedByUserId' => 'approved_by_user_id',
            'companyId' => 'company_id',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventorySessionLine::class, 'inventory_session_id')->orderBy('sort_order');
    }

    public function document(): array
    {
        $doc = parent::document();
        if (is_array($this->scope)) {
            $doc['scope'] = $this->scope;
        }
        foreach (['startedAt' => 'started_at', 'completedAt' => 'completed_at'] as $camel => $column) {
            $value = $this->getAttribute($column);
            if ($value) {
                $doc[$camel] = $value instanceof \DateTimeInterface
                    ? \Carbon\Carbon::instance($value)->toIso8601String()
                    : (string) $value;
            }
        }
        $rows = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();
        if ($rows->isNotEmpty()) {
            $doc['lines'] = $rows->map(fn (InventorySessionLine $line) => [
                'productId' => $line->product_id,
                'expected' => (int) $line->expected,
                'counted' => (int) $line->counted,
            ])->values()->all();
        } elseif (! isset($doc['lines'])) {
            $doc['lines'] = [];
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        foreach (['startedAt' => 'started_at', 'completedAt' => 'completed_at'] as $camel => $column) {
            if (array_key_exists($camel, $data)) {
                $this->setAttribute($column, $data[$camel] ? \Carbon\Carbon::parse((string) $data[$camel]) : null);
            }
        }
        if (array_key_exists('scope', $data)) {
            $this->scope = is_array($data['scope']) ? $data['scope'] : null;
        }
        parent::fillDocument($data);
        if (array_key_exists('lines', $data) && is_array($data['lines'])) {
            $this->pendingLines = array_values($data['lines']);
        }

        return $this;
    }

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
                'id' => Uid::make('inl'),
                'product_id' => $line['productId'],
                'expected' => (int) ($line['expected'] ?? 0),
                'counted' => (int) ($line['counted'] ?? 0),
                'sort_order' => $index,
            ]);
        }
        $this->pendingLines = null;
        $this->unsetRelation('lines');
    }
}
