<?php

namespace App\Models;

class Payment extends RelationalDocumentModel
{
    protected $table = 'payments';

    protected $fillable = ['id', 'payload', 'invoice_id', 'amount', 'paid_at', 'company_id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'amount' => 'float',
            'paid_at' => 'datetime',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'invoiceId' => 'invoice_id',
            'amount' => 'amount',
            'companyId' => 'company_id',
        ];
    }

    public function document(): array
    {
        $doc = parent::document();
        if ($this->paid_at) {
            $doc['date'] = $this->paid_at->toIso8601String();
        } elseif (is_array($this->payload) && isset($this->payload['date'])) {
            $doc['date'] = $this->payload['date'];
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('date', $data)) {
            $this->paid_at = $data['date'] ? \Carbon\Carbon::parse((string) $data['date']) : null;
        }

        return parent::fillDocument($data);
    }
}
