<?php

namespace App\Models;

class StockLot extends RelationalDocumentModel
{
    protected $table = 'stock_lots';

    protected $fillable = [
        'id', 'payload', 'product_id', 'warehouse_id', 'lot_code', 'expiry_date',
        'location_id', 'quantity', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'quantity' => 'integer',
            'expiry_date' => 'date',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'productId' => 'product_id',
            'warehouseId' => 'warehouse_id',
            'lotCode' => 'lot_code',
            'locationId' => 'location_id',
            'quantity' => 'quantity',
            'companyId' => 'company_id',
        ];
    }

    public function document(): array
    {
        $doc = parent::document();
        if ($this->expiry_date) {
            $doc['expiryDate'] = $this->expiry_date->toDateString();
        } elseif (is_array($this->payload) && isset($this->payload['expiryDate'])) {
            $doc['expiryDate'] = $this->payload['expiryDate'];
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('expiryDate', $data)) {
            $value = $data['expiryDate'];
            $this->expiry_date = $value ? \Carbon\Carbon::parse((string) $value)->toDateString() : null;
        }

        return parent::fillDocument($data);
    }
}
