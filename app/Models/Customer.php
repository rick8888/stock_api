<?php

namespace App\Models;

class Customer extends RelationalDocumentModel
{
    protected $table = 'customers';

    protected $fillable = [
        'id', 'payload', 'name', 'phone', 'email', 'ice', 'notes', 'active', 'addresses', 'company_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'active' => 'boolean',
            'addresses' => 'array',
        ];
    }

    public static function documentMap(): array
    {
        return [
            'name' => 'name',
            'phone' => 'phone',
            'email' => 'email',
            'ice' => 'ice',
            'notes' => 'notes',
            'active' => 'active',
            'companyId' => 'company_id',
        ];
    }

    public function document(): array
    {
        $doc = parent::document();
        if (is_array($this->addresses)) {
            $doc['addresses'] = $this->addresses;
        } elseif (is_array($this->payload) && isset($this->payload['addresses'])) {
            $doc['addresses'] = $this->payload['addresses'];
        }

        return $doc;
    }

    public function fillDocument(array $data): static
    {
        if (array_key_exists('addresses', $data)) {
            $this->addresses = is_array($data['addresses']) ? array_values($data['addresses']) : [];
        }

        return parent::fillDocument($data);
    }
}
