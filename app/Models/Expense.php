<?php

namespace App\Models;

class Expense extends RelationalDocumentModel
{
    protected $table = 'expenses';

    protected $fillable = ['id', 'payload', 'label', 'amount', 'spent_at', 'category', 'company_id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'amount' => 'float',
            'spent_at' => 'datetime',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'label' => 'label',
            'amount' => 'amount',
            'category' => 'category',
            'companyId' => 'company_id',
        ];
    }

    public function document(): array
    {
        $doc = parent::document();
        if ($this->spent_at) {
            $doc['date'] = $this->spent_at->toIso8601String();
        } elseif (is_array($this->payload) && isset($this->payload['date'])) {
            $doc['date'] = $this->payload['date'];
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('date', $data)) {
            $this->spent_at = $data['date'] ? \Carbon\Carbon::parse((string) $data['date']) : null;
        }

        return parent::fillDocument($data);
    }
}
