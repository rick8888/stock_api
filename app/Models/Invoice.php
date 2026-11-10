<?php

namespace App\Models;

class Invoice extends RelationalDocumentModel
{
    protected $table = 'invoices';

    protected $fillable = [
        'id', 'payload', 'supplier_id', 'purchase_order_id', 'amount', 'status', 'due_date', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'amount' => 'float',
            'due_date' => 'date',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'supplierId' => 'supplier_id',
            'purchaseOrderId' => 'purchase_order_id',
            'amount' => 'amount',
            'status' => 'status',
            'companyId' => 'company_id',
        ];
    }

    public function document(): array
    {
        $doc = parent::document();
        if ($this->due_date) {
            $doc['dueDate'] = $this->due_date->toDateString();
        } elseif (is_array($this->payload) && isset($this->payload['dueDate'])) {
            $doc['dueDate'] = $this->payload['dueDate'];
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('dueDate', $data)) {
            $this->due_date = $data['dueDate']
                ? \Carbon\Carbon::parse((string) $data['dueDate'])->toDateString()
                : null;
        }

        return parent::fillDocument($data);
    }
}
