<?php

namespace App\Models;

class StockMovement extends RelationalDocumentModel
{
    protected $table = 'stock_movements';

    protected $fillable = [
        'id', 'payload', 'product_id', 'type', 'quantity', 'from_warehouse_id',
        'to_warehouse_id', 'user_id', 'moved_at', 'note', 'reference',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'quantity' => 'integer',
            'moved_at' => 'datetime',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'productId' => 'product_id',
            'type' => 'type',
            'quantity' => 'quantity',
            'fromWarehouseId' => 'from_warehouse_id',
            'toWarehouseId' => 'to_warehouse_id',
            'userId' => 'user_id',
            'note' => 'note',
            'reference' => 'reference',
        ];
    }

    public function document(): array
    {
        $doc = parent::document();
        if ($this->moved_at) {
            $doc['date'] = $this->moved_at->toIso8601String();
        } elseif (! isset($doc['date']) && is_array($this->payload) && isset($this->payload['date'])) {
            $doc['date'] = $this->payload['date'];
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (isset($data['date']) && empty($data['moved_at'])) {
            try {
                $this->moved_at = \Carbon\Carbon::parse($data['date']);
            } catch (\Throwable) {
                // keep payload date only
            }
        }

        return parent::fillDocument($data);
    }
}
